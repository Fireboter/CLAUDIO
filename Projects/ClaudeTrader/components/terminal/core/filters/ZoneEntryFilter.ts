import type { DayCandle } from '../models/Candle';
import type { Trendline } from '../models/Trendline';
import type { OrderBlock } from '../models/OrderBlock';
import type { Signal, SignalKind } from '../models/Signal';

// ─── Internal zone representation ────────────────────────────────────────────

/**
 * Unified zone record for trendlines (sloped) and order blocks (horizontal).
 *
 * Trendlines: bounds are computed per day from slope/intercept + halfFrac.
 * Order blocks: bounds are fixed (fixedLow/fixedHigh pre-computed by OrderBlockManager).
 */
interface Zone {
    id:        string;
    type:      'resistance' | 'support';
    slope:     number;
    intercept: number;
    halfFrac:  number;    // relative half-width; 0 for OBs (use fixedLow/fixedHigh instead)
    fixedLow:  number;    // > 0 → use fixed bounds (OB zones)
    fixedHigh: number;
    startDay:  number;
    endDay:    number;    // -1 = extends to end of dataset
}

type ZonePos = 'below' | 'inside' | 'above';

function getBounds(zone: Zone, di: number): [number, number] | null {
    if (zone.fixedLow > 0) return [zone.fixedLow, zone.fixedHigh];
    const lineY = zone.slope * di + zone.intercept;
    if (lineY <= 0) return null;
    return [lineY * (1 - zone.halfFrac), lineY * (1 + zone.halfFrac)];
}

function getPos(close: number, lo: number, hi: number): ZonePos {
    if (close > hi) return 'above';
    if (close < lo) return 'below';
    return 'inside';
}

// ─── ZoneEntryFilter ─────────────────────────────────────────────────────────

/**
 * Pure, stateless zone-entry/exit signal generator.
 *
 * ENTRY signals (source='zone_entry') fire when price EXITS a zone:
 *   - Resistance, exit downward (inside→below): Short  (bounce off resistance)
 *   - Resistance, exit upward   (inside→above): Long   (breakout above resistance)
 *   - Support,    exit upward   (inside→above): Long   (bounce off support)
 *   - Support,    exit downward (inside→below): Short  (breakdown below support)
 *
 * EXIT signals fire when:
 *   - Price enters an opposing zone from the correct direction:
 *       Long + resistance zone entered from below  → close Long
 *       Short + support zone entered from above    → close Short
 *   - Stop-loss / take-profit threshold hit (if configured)
 *
 * One trade is open at a time.
 */
export class ZoneEntryFilter {

    compute(
        days:        DayCandle[],
        trendlines:  Trendline[],
        tlZonePct:   number,       // trendline zone half-width (%)
        orderBlocks: OrderBlock[],
        exitConfig: {
            useStopLoss:   boolean;
            useTakeProfit: boolean;
            stopLossPct:   number;
            takeProfitPct: number;
        },
        playbackTime: number | null,
    ): Signal[] {
        if (days.length === 0) return [];

        const axisX  = days.length - 1;
        const tlFrac = tlZonePct / 100;

        // ── Build unified zone list ───────────────────────────────────────────
        const zones: Zone[] = [];

        if (tlFrac > 0) {
            for (const tl of trendlines) {
                zones.push({
                    id:        tl.id,
                    type:      tl.type,
                    slope:     tl.slope,
                    intercept: tl.intercept,
                    halfFrac:  tlFrac,
                    fixedLow:  0,
                    fixedHigh: 0,
                    startDay:  tl.start_index,
                    endDay:    tl.end_index,
                });
            }
        }

        for (const ob of orderBlocks) {
            zones.push({
                id:        ob.id,
                type:      ob.type,
                slope:     0,
                intercept: ob.price,
                halfFrac:  0,
                fixedLow:  ob.zoneLow,
                fixedHigh: ob.zoneHigh,
                startDay:  ob.pivotDayIndex,
                endDay:    ob.endIndex,
            });
        }

        if (zones.length === 0) return [];

        const zoneById = new Map(zones.map(z => [z.id, z]));

        // ── Phase 1: detect entry signals (price exiting a zone) ─────────────
        const entrySignals: Signal[] = [];
        const prevPos = new Map<string, ZonePos>();

        for (let di = 0; di < days.length; di++) {
            const day     = days[di];
            const minutes = di === axisX && playbackTime !== null
                ? day.minutes.filter(m => m.time <= playbackTime)
                : day.minutes;

            for (const min of minutes) {
                for (const zone of zones) {
                    if (di < zone.startDay) continue;
                    if (zone.endDay >= 0 && di > zone.endDay) continue;

                    const bounds = getBounds(zone, di);
                    if (!bounds) continue;
                    const [lo, hi] = bounds;

                    const cur  = getPos(min.close, lo, hi);
                    const prev = prevPos.get(zone.id);
                    prevPos.set(zone.id, cur);

                    // First observation for this zone: record state, no signal.
                    if (prev === undefined) continue;
                    // No exit transition.
                    if (prev !== 'inside' || cur === 'inside') continue;

                    // Price just exited the zone → entry signal.
                    // Upward exit (inside→above): Long (resistance breakout or support bounce).
                    // Downward exit (inside→below): Short (resistance bounce or support breakdown).
                    const kind: SignalKind = cur === 'above' ? 'long' : 'short';
                    entrySignals.push({
                        id:       `ze|${zone.id}|${min.time}`,
                        kind,
                        source:   'zone_entry',
                        price:    cur === 'above' ? hi : lo,
                        time:     min.time,
                        dayIndex: di,
                    });
                }
            }
        }

        if (entrySignals.length === 0) return [];

        entrySignals.sort((a, b) => a.time - b.time);

        // ── Phase 2: trade simulation with zone-entry exits + SL/TP ──────────
        interface OpenTrade {
            kind:       'long' | 'short';
            entryPrice: number;
            entryTime:  number;
            slPrice:    number | null;
            tpPrice:    number | null;
        }

        const exitSignals: Signal[] = [];
        let   openTrade: OpenTrade | null = null;
        let   entryIdx = 0;
        const tzPos = new Map<string, ZonePos>();  // zone state at end of previous minute

        for (let di = 0; di < days.length; di++) {
            const day     = days[di];
            const minutes = di === axisX && playbackTime !== null
                ? day.minutes.filter(m => m.time <= playbackTime)
                : day.minutes;

            for (const min of minutes) {
                // ── Open a new trade on the next entry signal ─────────────────
                while (
                    entryIdx < entrySignals.length &&
                    entrySignals[entryIdx].time <= min.time &&
                    openTrade === null
                ) {
                    const e      = entrySignals[entryIdx];
                    const isLong = e.kind === 'long';
                    openTrade = {
                        kind:       e.kind as 'long' | 'short',
                        entryPrice: e.price,
                        entryTime:  e.time,
                        slPrice:    exitConfig.useStopLoss
                            ? isLong
                                ? e.price * (1 - exitConfig.stopLossPct / 100)
                                : e.price * (1 + exitConfig.stopLossPct / 100)
                            : null,
                        tpPrice: exitConfig.useTakeProfit
                            ? isLong
                                ? e.price * (1 + exitConfig.takeProfitPct / 100)
                                : e.price * (1 - exitConfig.takeProfitPct / 100)
                            : null,
                    };
                    entryIdx++;
                }

                // ── Compute current zone positions ────────────────────────────
                // Must happen every minute so tzPos is accurate for zone entry detection.
                const curPos = new Map<string, ZonePos>();
                for (const zone of zones) {
                    if (di < zone.startDay) continue;
                    if (zone.endDay >= 0 && di > zone.endDay) continue;
                    const bounds = getBounds(zone, di);
                    if (!bounds) continue;
                    curPos.set(zone.id, getPos(min.close, bounds[0], bounds[1]));
                }

                if (openTrade) {
                    const { kind, entryPrice, slPrice, tpPrice } = openTrade;
                    const isLong = kind === 'long';
                    let exitKind: SignalKind | null      = null;
                    let exitSrc:  Signal['source'] | null = null;
                    let exitPrice = 0;

                    // Stop Loss
                    if (slPrice !== null && (isLong ? min.low <= slPrice : min.high >= slPrice)) {
                        exitKind = 'loss'; exitSrc = 'stop_loss'; exitPrice = slPrice;
                    }

                    // Take Profit
                    if (exitKind === null && tpPrice !== null &&
                        (isLong ? min.high >= tpPrice : min.low <= tpPrice)) {
                        exitKind = 'win'; exitSrc = 'take_profit'; exitPrice = tpPrice;
                    }

                    // Zone entry exit (uses previous-minute zone states as prev)
                    if (exitKind === null) {
                        for (const [zoneId, cur] of curPos) {
                            const prev = tzPos.get(zoneId) ?? cur;
                            if (prev === 'inside' || cur !== 'inside') continue;

                            const zone = zoneById.get(zoneId)!;
                            // Long exits when price enters resistance FROM below
                            if (isLong && zone.type === 'resistance' && prev === 'below') {
                                exitKind  = min.close >= entryPrice ? 'win' : 'loss';
                                exitSrc   = 'zone_exit';
                                exitPrice = min.close;
                                break;
                            }
                            // Short exits when price enters support FROM above
                            if (!isLong && zone.type === 'support' && prev === 'above') {
                                exitKind  = min.close <= entryPrice ? 'win' : 'loss';
                                exitSrc   = 'zone_exit';
                                exitPrice = min.close;
                                break;
                            }
                        }
                    }

                    if (exitKind !== null && exitSrc !== null) {
                        exitSignals.push({
                            id:       `ze_x|${openTrade.entryTime}|${min.time}`,
                            kind:     exitKind,
                            source:   exitSrc,
                            price:    exitPrice,
                            time:     min.time,
                            dayIndex: di,
                        });
                        openTrade = null;
                        // Skip entry signals that fell inside the closed trade's window
                        while (entryIdx < entrySignals.length &&
                               entrySignals[entryIdx].time <= min.time) {
                            entryIdx++;
                        }
                    }
                }

                // ── Update zone state for next minute ─────────────────────────
                for (const [id, pos] of curPos) tzPos.set(id, pos);
            }
        }

        return [...entrySignals, ...exitSignals];
    }
}
