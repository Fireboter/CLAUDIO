import { Observable } from '../Observable';
import { EarlyPivotFilter } from '../filters/EarlyPivotFilter';
import { DEFAULT_ORDER_BLOCK_CONFIG } from '../models/OrderBlock';
import type { OrderBlock, OrderBlockConfig } from '../models/OrderBlock';
import type { EarlyPivot, EarlyPivotConfig } from '../models/EarlyPivot';
import type { Trendline } from '../models/Trendline';
import type { DayCandle } from '../models/Candle';
import type { MarketDataStore } from '../store/MarketDataStore';
import type { LayoutManager } from './LayoutManager';
import type { PivotManager } from './PivotManager';

/**
 * Manages Order Block detection.
 *
 * Each confirmed window-size pivot creates a horizontal level (slope = 0).
 * These levels are passed to EarlyPivotFilter for touch-zone detection — the
 * same recoil-confirmation logic used by trendlines — so confirmed OB touches
 * generate EarlyPivot objects that SignalManager can turn into entry signals.
 *
 * Architecture mirrors EarlyPivotManager: scan-window logic, _dayLastSeen map,
 * and confirmed-touch accumulation are identical.  The key difference is that
 * "trendlines" are synthesised directly from PivotManager.pivots rather than
 * computed by TrendlineFilter.
 */
export class OrderBlockManager extends Observable {

    // ─── Public state ─────────────────────────────────────────────────────────
    orderBlocks:          OrderBlock[] = [];
    confirmedOBTouches:   EarlyPivot[] = [];
    provisionalOBTouches: EarlyPivot[] = [];
    /**
     * OBs removed by breakout in the latest recompute cycle.
     * SignalManager reads this once per cycle to generate breakout entries.
     */
    brokenOrderBlocks: Map<string, { type: 'resistance' | 'support'; slope: number; intercept: number }> = new Map();
    config: OrderBlockConfig = { ...DEFAULT_ORDER_BLOCK_CONFIG };

    // ─── Private ──────────────────────────────────────────────────────────────
    private market:   MarketDataStore;
    private layout:   LayoutManager;
    private pivotMgr: PivotManager;
    private filter:   EarlyPivotFilter = new EarlyPivotFilter();

    /**
     * Tracks the latest playback time observed for each dayIndex.
     * Prevents retroactive confirmation when the user jumps forward past a day
     * without stepping through it minute-by-minute — same guard as
     * EarlyPivotManager._dayLastSeen.
     */
    private _dayLastSeen: Map<number, number> = new Map();
    private _prevOBLines: Trendline[] = [];

    private _unsubMarket: (() => void) | null = null;
    private _unsubLayout: (() => void) | null = null;
    private _unsubPivots: (() => void) | null = null;

    constructor(
        market:   MarketDataStore,
        layout:   LayoutManager,
        pivotMgr: PivotManager,
    ) {
        super();
        this.market   = market;
        this.layout   = layout;
        this.pivotMgr = pivotMgr;

        this.config = { ...layout.orderBlockConfig };

        this._unsubMarket = market.subscribe(() => this._recompute());
        this._unsubLayout = layout.subscribe(() => this._recompute());
        this._unsubPivots = pivotMgr.subscribe(() => this._recompute());
    }

    // ─── Public mutators ──────────────────────────────────────────────────────

    setConfig(patch: Partial<OrderBlockConfig>): void {
        this.config = { ...this.config, ...patch };
        this.layout.saveOrderBlockState(this.config);
        this._recompute();
    }

    dispose(): void {
        this._unsubMarket?.();
        this._unsubLayout?.();
        this._unsubPivots?.();
    }

    // ─── Confirmed OB touches as plain EarlyPivot objects ─────────────────────

    /**
     * All confirmed OB touches (including superseded).
     * Filtered to pivots that have been confirmed (confirmedAt defined).
     */
    get confirmedTouchesWithConfirmation(): EarlyPivot[] {
        return this.confirmedOBTouches.filter(ep => ep.confirmedAt !== undefined);
    }

    // ─── Recompute ────────────────────────────────────────────────────────────

    _recompute(): void {
        if (
            !this.config.enabled ||
            this.market.days.length === 0 ||
            this.pivotMgr.pivots.length === 0
        ) {
            const changed =
                this.orderBlocks.length > 0 ||
                this.confirmedOBTouches.length > 0 ||
                this.provisionalOBTouches.length > 0;
            this.orderBlocks          = [];
            this.confirmedOBTouches   = [];
            this.provisionalOBTouches = [];
            this.brokenOrderBlocks    = new Map();
            this._prevOBLines         = [];
            this._dayLastSeen.clear();
            if (changed) this.notify();
            return;
        }

        const days = this._buildVisibleDays();
        if (days.length === 0) return;

        const axisX        = days.length - 1;
        const playbackTime = this.layout.playbackTime;
        const zonePct          = this.config.touchZonePct      / 100;
        const breakoutFrac     = this.config.breakoutTolerance  / 100;

        // ── _dayLastSeen tracking (mirrors EarlyPivotManager) ─────────────────
        if (playbackTime === null) {
            this._dayLastSeen.clear();
        }
        if (playbackTime !== null) {
            const prev = this._dayLastSeen.get(axisX);
            if (prev === undefined || playbackTime > prev) {
                this._dayLastSeen.set(axisX, playbackTime);
            }
        }
        const prevDayObservedTime = playbackTime !== null
            ? (this._dayLastSeen.get(axisX - 1) ?? (days[axisX - 1]?.time ?? null))
            : null;

        // ── Build horizontal Trendline objects from confirmed pivots ──────────
        // Each confirmed pivot → slope=0, intercept=price horizontal line.
        // Only pivots strictly in the past (dayIndex < axisX) generate levels.
        const pivots = this.pivotMgr.pivots;
        const candidateLines: Trendline[] = pivots
            .filter(p =>
                p.dayIndex < axisX &&
                ((p.type === 'high' && this.config.showResistance) ||
                 (p.type === 'low'  && this.config.showSupport))
            )
            .map(p => ({
                id:           `ob|${p.dayIndex}|${p.type}`,
                type:         p.type === 'high' ? 'resistance' as const : 'support' as const,
                slope:        0,
                intercept:    p.price,
                // OB becomes active on the NEXT bar after the pivot — it didn't
                // exist on the pivot day so no touch/signal can fire there.
                start_index:  p.dayIndex + 1,
                end_index:    axisX,
                start_price:  p.price,
                end_price:    p.price,
                touches:      1,
                pivotIndices: [p.dayIndex],
                score:        0,
            }));

        // ── Keep only "alive" OBs ─────────────────────────────────────────────
        // Scan every bar from (pivotDayIndex + 1) to axisX.
        // An OB dies when a bar's CLOSE crosses the level by more than
        // breakoutTolerance — close-based so alive-check and signal detection
        // are consistent (both fire at the same bar/minute).
        // breakoutFrac controls how far past the level the close must go:
        //   0%   = any close touching the exact level kills the OB
        //   0.5% = close must penetrate 0.5% past the level to kill it
        let allOBLines = candidateLines.filter(ob => {
            const lineY = ob.intercept;
            if (lineY <= 0) return false;
            const breakoutThreshold = ob.type === 'resistance'
                ? lineY * (1 + breakoutFrac)
                : lineY * (1 - breakoutFrac);
            // start_index is already p.dayIndex+1, so scan from start_index (not +1)
            for (let i = ob.start_index; i <= axisX; i++) {
                const bar = days[i];
                if (!bar) continue;
                if (ob.type === 'resistance' && bar.close > breakoutThreshold) return false;
                if (ob.type === 'support'    && bar.close < breakoutThreshold) return false;
            }
            return true;
        });

        // ── Proximity filter: hide OBs far from current price ────────────────
        if (this.config.proximity > 0 && days[axisX]) {
            const curPrice = days[axisX].close;
            if (curPrice > 0) {
                allOBLines = allOBLines.filter(ob =>
                    Math.abs(ob.intercept - curPrice) / curPrice <= this.config.proximity,
                );
            }
        }

        // ── Detect newly broken OBs this cycle (for signal generation) ────────
        const newIds    = new Set(allOBLines.map(l => l.id));
        const brokenMap = new Map<string, { type: 'resistance' | 'support'; slope: number; intercept: number }>();

        for (const prev of this._prevOBLines) {
            if (newIds.has(prev.id)) continue;
            const lineY = prev.intercept;
            if (lineY <= 0) continue;
            // Confirm it's a genuine breakout (not just pivots becoming alive again)
            if (days[axisX]) {
                const curBar     = days[axisX];
                // Close-based: signal fires when close confirms the breakout
                const isBreakout = prev.type === 'resistance'
                    ? curBar.close > lineY * (1 + breakoutFrac)
                    : curBar.close < lineY * (1 - breakoutFrac);
                if (isBreakout) brokenMap.set(prev.id, { type: prev.type, slope: 0, intercept: lineY });
            }
        }

        this._prevOBLines      = allOBLines;
        this.brokenOrderBlocks = brokenMap;

        // ── Build base blocks (score / zone / merge applied after touch detection)
        type BaseBlock = { id: string; type: 'resistance' | 'support'; price: number; pivotDayIndex: number; endIndex: number };
        const baseBlocks: BaseBlock[] = allOBLines.map(l => ({
            id:            l.id,
            type:          l.type as 'resistance' | 'support',
            price:         l.intercept,
            pivotDayIndex: l.start_index,
            endIndex:      axisX,
        }));

        // ── Touch-zone detection via EarlyPivotFilter ─────────────────────────
        if (zonePct <= 0 || allOBLines.length === 0) {
            const finalBlocks   = this._scoreAndMerge(baseBlocks, [], zonePct);
            const blocksChanged =
                finalBlocks.length !== this.orderBlocks.length ||
                finalBlocks.some((ob, i) => ob.id !== this.orderBlocks[i]?.id);
            this.orderBlocks = finalBlocks;
            const changed = this.confirmedOBTouches.length > 0 || this.provisionalOBTouches.length > 0;
            this.confirmedOBTouches   = [];
            this.provisionalOBTouches = [];
            if (blocksChanged || changed) this.notify();
            return;
        }

        const epConfig: EarlyPivotConfig = {
            enabled:            true,
            provisionalEnabled: true,
            recoilEnabled:      true,
            recoilPct:          this.config.recoilPct,
        };

        const fresh = this.filter.compute(
            days, allOBLines, epConfig, zonePct, axisX, playbackTime, prevDayObservedTime,
        );

        // ── Separate provisional from confirmed ───────────────────────────────
        const freshConfirmed = fresh.filter(ep => ep.status === 'confirmed');

        // Deduplicate provisional: max one per (dayIndex, type)
        const provisionalMap = new Map<string, EarlyPivot>();
        for (const ep of fresh) {
            if (ep.status !== 'provisional') continue;
            const key      = `${ep.dayIndex}|${ep.type}`;
            const existing = provisionalMap.get(key);
            if (!existing) {
                provisionalMap.set(key, ep);
            } else {
                const preferNew = ep.type === 'high'
                    ? ep.touchPrice > existing.touchPrice
                    : ep.touchPrice < existing.touchPrice;
                if (preferNew) provisionalMap.set(key, ep);
            }
        }
        const freshProvisional = Array.from(provisionalMap.values());

        // ── Scan-window-aware confirmed list management ───────────────────────
        const scanWindowDays = new Set<number>([axisX - 1, axisX]);
        const baseConfirmed  = this.confirmedOBTouches.filter(c => {
            if (!scanWindowDays.has(c.dayIndex)) return true;
            if (playbackTime === null)           return false;
            const confirmTime = c.confirmMinuteTime ?? c.time;
            return confirmTime <= playbackTime;
        });
        let confirmedChanged = baseConfirmed.length !== this.confirmedOBTouches.length;

        const newConfirmed = [...baseConfirmed];
        for (const ep of freshConfirmed) {
            const key = `${ep.trendlineId}|${ep.dayIndex}|${ep.type}`;
            const existingIdx = newConfirmed.findIndex(
                c => `${c.trendlineId}|${c.dayIndex}|${c.type}` === key,
            );
            if (existingIdx < 0) {
                newConfirmed.push(ep);
                confirmedChanged = true;
            } else {
                const existing = newConfirmed[existingIdx];
                if (
                    existing.superseded           !== ep.superseded ||
                    existing.confirmedAt          !== ep.confirmedAt ||
                    existing.supersededMinuteTime !== ep.supersededMinuteTime
                ) {
                    newConfirmed[existingIdx] = ep;
                    confirmedChanged = true;
                }
            }
        }

        // ── Score + merge final OrderBlock list ───────────────────────────────
        const finalBlocks   = this._scoreAndMerge(baseBlocks, newConfirmed, zonePct);
        const blocksChanged =
            finalBlocks.length !== this.orderBlocks.length ||
            finalBlocks.some((ob, i) =>
                ob.id !== this.orderBlocks[i]?.id || ob.score !== this.orderBlocks[i]?.score,
            );
        this.orderBlocks = finalBlocks;

        // ── Check provisional changed ─────────────────────────────────────────
        const pKey = (e: EarlyPivot) =>
            `${e.trendlineId}|${e.dayIndex}|${e.type}|${e.recoilThreshold}`;
        const oldPKeys = this.provisionalOBTouches.map(pKey).sort().join(',');
        const newPKeys = freshProvisional.map(pKey).sort().join(',');
        const provisionalChanged = oldPKeys !== newPKeys;

        this.provisionalOBTouches = freshProvisional;
        this.confirmedOBTouches   = newConfirmed;

        if (blocksChanged || confirmedChanged || provisionalChanged) this.notify();
    }

    // ─── Score + merge ────────────────────────────────────────────────────────

    /**
     * Attaches score and zone boundaries to raw base blocks, then optionally
     * merges adjacent levels of the same type whose prices are within
     * `config.mergeGapPct` of each other into a single wider zone.
     *
     * Score formula: (confirmedTouches × 100) + longevity  — same as trendlines.
     */
    private _scoreAndMerge(
        blocks:    Array<{ id: string; type: 'resistance' | 'support'; price: number; pivotDayIndex: number; endIndex: number }>,
        confirmed: EarlyPivot[],
        zonePct:   number,
    ): OrderBlock[] {
        const scored: OrderBlock[] = blocks.map(b => {
            const touches   = confirmed.filter(ep => ep.trendlineId === b.id).length;
            const longevity = b.endIndex - b.pivotDayIndex;
            return {
                ...b,
                score:       touches * 100 + longevity,
                zoneHigh:    b.price * (1 + zonePct),
                zoneLow:     b.price * (1 - zonePct),
                mergedCount: 1,
            };
        });

        if (!this.config.mergeEnabled) return scored;

        const gapFrac = this.config.mergeGapPct / 100;
        const result: OrderBlock[] = [];

        for (const type of ['resistance', 'support'] as const) {
            const group = scored
                .filter(o => o.type === type)
                .sort((a, b) => a.price - b.price);

            let cluster: OrderBlock[] = [];

            const flush = () => {
                if (cluster.length === 0) return;
                if (cluster.length === 1) {
                    result.push(cluster[0]);
                } else {
                    const best     = cluster.reduce((a, b) => b.score > a.score ? b : a);
                    const minPrice = cluster[0].price;
                    const maxPrice = cluster[cluster.length - 1].price;
                    result.push({
                        ...best,
                        score:         cluster.reduce((s, o) => s + o.score, 0),
                        zoneHigh:      maxPrice * (1 + zonePct),
                        zoneLow:       minPrice * (1 - zonePct),
                        mergedCount:   cluster.length,
                        pivotDayIndex: Math.min(...cluster.map(o => o.pivotDayIndex)),
                    });
                }
                cluster = [];
            };

            for (const ob of group) {
                if (cluster.length === 0) {
                    cluster.push(ob);
                } else {
                    const lastPrice = cluster[cluster.length - 1].price;
                    if ((ob.price - lastPrice) / lastPrice <= gapFrac) {
                        cluster.push(ob);
                    } else {
                        flush();
                        cluster = [ob];
                    }
                }
            }
            flush();
        }

        return result;
    }

    // ─── visibleDays builder (mirrors TrendlineManager._buildVisibleDays) ─────

    private _buildVisibleDays(): DayCandle[] {
        const allDays = this.market.days;
        if (allDays.length === 0) return [];

        const pt = this.layout.playbackTime;
        if (pt === null) return allDays;

        let activeDayIdx = -1;
        for (let i = 0; i < allDays.length; i++) {
            if (allDays[i].time <= pt) activeDayIdx = i;
            else break;
        }
        if (activeDayIdx < 0) return [];

        const activeDay  = allDays[activeDayIdx];
        let liveDay: DayCandle = activeDay;
        const seenMins = activeDay.minutes.filter(m => m.time <= pt);
        if (seenMins.length > 0) {
            liveDay = {
                ...activeDay,
                open:   seenMins[0].open,
                high:   Math.max(...seenMins.map(m => m.high)),
                low:    Math.min(...seenMins.map(m => m.low)),
                close:  seenMins[seenMins.length - 1].close,
                volume: seenMins.reduce((s, m) => s + m.volume, 0),
            };
        }

        return [...allDays.slice(0, activeDayIdx), liveDay];
    }
}
