/**
 * backtestEngine.ts
 *
 * Pure, framework-free batch backtest simulation.
 *
 * Pipeline:
 *  1. Accept the chart's own DayCandle[] (including pre-history bars)
 *  2. PivotFilter on full set   → all confirmed swing pivots
 *  3. Day-by-day loop starting at preHistoryCount:
 *       a. TrendlineFilter at each step   → current trendlines (stored per day)
 *       b. EarlyPivotFilter at each step  → accumulate confirmed early pivots
 *  4. Generate entry signals from confirmed early pivots (actual range only)
 *  5. Simulate trades using per-day trendlines for zone exits (accurate)
 *  6. Equity curve + stats
 *
 * Using the chart's DayCandle[] directly ensures the backtest sees exactly
 * the same trendlines as the live chart — pre-history pivot anchors included —
 * so every confirmed early pivot triangle has a matching signal arrow.
 */

import type { DayCandle } from '../models/Candle';
import type { EarlyPivot, EarlyPivotConfig } from '../models/EarlyPivot';
import type { Trendline, TrendlineConfig } from '../models/Trendline';
import type { OrderBlock, OrderBlockConfig } from '../models/OrderBlock';
import type { Signal } from '../models/Signal';
import type { TradeAxisConfig, StrategyConfig, SignalConfig } from '../store/types';
import { PivotFilter } from '../filters/PivotFilter';
import { TrendlineFilter } from '../filters/TrendlineFilter';
import { EarlyPivotFilter } from '../filters/EarlyPivotFilter';
import { ZoneEntryFilter } from '../filters/ZoneEntryFilter';
import type { Time } from 'lightweight-charts';

// ─── Public output types ──────────────────────────────────────────────────────

export type TradeExitReason = 'stop_loss' | 'take_profit' | 'zone_exit';

export interface BacktestTrade {
    id:           string;
    type:         'long' | 'short';
    entryPrice:   number;
    exitPrice?:   number;
    entryTime:    number;
    exitTime?:    number;
    entryReason:  'pivot_confirmation' | 'breakout' | 'zone_entry';
    exitReason?:  TradeExitReason;
    /** Fraction: positive = gain. e.g. 0.05 = +5% move on the instrument. */
    pnl?:         number;
    status:       'active' | 'closed';
}

export interface BacktestStats {
    total_return:  number;   // percent
    win_rate:      number;   // 0–1
    total_trades:  number;
    max_drawdown:  number;   // percent (positive = drawdown)
    sharpe_ratio:  number;
    profit_factor: number;
}

export interface BacktestResult {
    trades:          BacktestTrade[];
    equityCurve:     { time: Time; value: number }[];
    stats:           BacktestStats;
    allEntrySignals: EntrySignal[];
}

// ─── Entry signal type ───────────────────────────────────────────────

export interface EntrySignal {
    kind:     'long' | 'short';
    price:    number;
    time:     number;   // confirmMinuteTime (or day time for no-minute fallback)
    /** Engine-internal day index (indexes engine's DayCandle[]). Do NOT use as chart coordinate — recompute from `time` against the chart's day list instead. */
    dayIndex:    number;
    entryReason: 'pivot_confirmation' | 'breakout' | 'zone_entry';
}

// ─── Main simulation ──────────────────────────────────────────────────────────

export function runBacktestSimulation(
    chartDays:        DayCandle[],
    tradeAxisConfig:  TradeAxisConfig,
    strategyConfig:   StrategyConfig,
    trendlineConfig:  TrendlineConfig,
    signalConfig:     SignalConfig,
    earlyPivotConfig: EarlyPivotConfig,
    preHistoryCount:  number = 0,
    orderBlockConfig?: OrderBlockConfig,
): BacktestResult {

    // Use the chart's own DayCandle[] directly so trendlines, pivots and
    // early pivot confirmations are computed from exactly the same data that
    // the live chart uses — including pre-history pivot anchors.
    const days = chartDays;
    if (days.length < 10) return _empty();

    // ── Step 2: pivot detection on full dataset ────────────────────────────────
    const pivotFilter = new PivotFilter(tradeAxisConfig.windowSize);
    const allPivots   = pivotFilter.compute(days);

    // ── Step 3: day-by-day walk ───────────────────────────────────────────────
    const trendlineFilter  = new TrendlineFilter();
    const earlyPivotFilter = new EarlyPivotFilter();

    // Force early pivot detection enabled for backtest regardless of UI toggle.
    const epConfig: EarlyPivotConfig = { ...earlyPivotConfig, enabled: true };

    const confirmedEarlyPivots:   EarlyPivot[] = [];
    const confirmedEarlyPivotIds: Set<string>  = new Set();

    const zonePct = (trendlineConfig.touchZonePct ?? 0) / 100;

    // OB detection setup
    const useOBSignals   = !!signalConfig.useOrderBlockSignals && !!orderBlockConfig?.enabled;
    // Zone entry needs the alive OB map even when useOrderBlockSignals is off
    const needAliveOBMap = useOBSignals || (!!signalConfig.useZoneEntryStrategy && !!orderBlockConfig?.enabled);
    const obZonePct      = !!orderBlockConfig?.enabled ? (orderBlockConfig!.touchZonePct / 100) : 0;
    const obBreakFrac    = !!orderBlockConfig?.enabled ? (orderBlockConfig!.breakoutTolerance / 100) : 0;
    const obEpConfig: EarlyPivotConfig = {
        enabled:            true,
        provisionalEnabled: false,
        recoilEnabled:      true,
        recoilPct:          orderBlockConfig?.recoilPct ?? 0.5,
    };
    const obFilter            = useOBSignals ? new EarlyPivotFilter() : null;
    const confirmedOBPivots   : EarlyPivot[] = [];
    const confirmedOBPivotIds = new Set<string>();
    const brokenOBsByDay      = new Map<number, Trendline[]>();
    let   prevAliveOBMap      = new Map<string, Trendline>();

    // We need at least 2×windowSize + 1 bars before pivots can be confirmed.
    // Also respect the pre-history boundary — signals only fire in the actual
    // trading range (index >= preHistoryCount), but trendlines benefit from
    // pre-history data so the loop still starts at minDays (not preHistoryCount).
    const minDays   = tradeAxisConfig.windowSize * 2 + 3;
    const loopStart = Math.max(minDays, preHistoryCount);

    // Store trendlines at each day so zone exits use the correct lines.
    // This is the critical difference from using only finalTrendlines: a trade
    // that opens against a trendline active at day 50 will also exit against
    // that day's trendlines, even if those lines are pruned by the final step.
    const trendlinesByDay       = new Map<number, Trendline[]>();
    const brokenTrendlinesByDay = new Map<number, Map<string, Trendline>>();
    let prevTrendlines: Trendline[] = [];

    for (let i = loopStart; i < days.length; i++) {
        // Sliced prefix of days visible at step i
        const slicedDays   = days.slice(0, i + 1);
        const slicedPivots = allPivots.filter(p => p.dayIndex <= i);

        const trendlines = trendlineFilter.compute(slicedDays, slicedPivots, i, trendlineConfig);

        trendlinesByDay.set(i, trendlines);

        // Accumulate confirmed trendline early pivots (gated by usePivotConfirmation)
        if (zonePct > 0 && signalConfig.usePivotConfirmation && trendlines.length > 0) {
            const earlyPivots = earlyPivotFilter.compute(
                slicedDays, trendlines, epConfig, zonePct, i, null,
            );
            for (const ep of earlyPivots) {
                if (ep.status !== 'confirmed') continue;
                const epId = `${ep.trendlineId}|${ep.dayIndex}|${ep.type}`;
                if (!confirmedEarlyPivotIds.has(epId)) {
                    confirmedEarlyPivotIds.add(epId);
                    confirmedEarlyPivots.push(ep);
                }
            }
        }

        // ── Detect trendline breakouts (gated by useBreakoutDetection) ────────
        if (zonePct > 0 && signalConfig.useBreakoutDetection) {
            const newIds    = new Set(trendlines.map(l => l.id));
            const brokenMap = new Map<string, Trendline>();
            const curBar    = slicedDays[i];

            for (const prev of prevTrendlines) {
                if (newIds.has(prev.id)) continue;
                const lineY = prev.slope * i + prev.intercept;
                if (lineY <= 0) continue;
                const isBreakout = prev.type === "resistance"
                    ? curBar.close > lineY * (1 + zonePct)
                    : curBar.close < lineY * (1 - zonePct);
                if (isBreakout) brokenMap.set(prev.id, prev);
            }
            brokenTrendlinesByDay.set(i, brokenMap);
        }

        prevTrendlines = trendlines;

        // ── Order Block alive map (always built when OBs enabled; needed for zone entry) ──
        if (needAliveOBMap) {
            // Rebuild alive OB lines from all confirmed pivots before step i.
            // Full-rescan alive check mirrors OrderBlockManager logic exactly.
            const obCandidates: Trendline[] = [];
            for (const p of allPivots) {
                if (p.dayIndex >= i) continue;
                if (p.type === 'high' && !orderBlockConfig!.showResistance) continue;
                if (p.type === 'low'  && !orderBlockConfig!.showSupport)    continue;
                const type = p.type === 'high' ? 'resistance' as const : 'support' as const;
                const threshold = type === 'resistance'
                    ? p.price * (1 + obBreakFrac)
                    : p.price * (1 - obBreakFrac);
                let alive = true;
                for (let j = p.dayIndex + 1; j <= i; j++) {
                    const bar = days[j];
                    if (!bar) continue;
                    if (type === 'resistance' && bar.close > threshold) { alive = false; break; }
                    if (type === 'support'    && bar.close < threshold) { alive = false; break; }
                }
                if (!alive) continue;
                obCandidates.push({
                    id: `ob|${p.dayIndex}|${p.type}`, type,
                    slope: 0, intercept: p.price,
                    start_index: p.dayIndex + 1, end_index: i,
                    start_price: p.price,   end_price: p.price,
                    touches: 1, pivotIndices: [p.dayIndex], score: 0,
                });
            }

            if (useOBSignals) {
                // Detect newly broken OBs vs previous step (for breakout signals).
                // Use wick (high/low) to match the alive-check above.
                const aliveOBIds = new Set(obCandidates.map(l => l.id));
                const brokenNow: Trendline[] = [];
                for (const [id, ob] of prevAliveOBMap) {
                    if (aliveOBIds.has(id)) continue;
                    const curBar     = days[i];
                    const isBreakout = ob.type === 'resistance'
                        ? curBar.close > ob.intercept * (1 + obBreakFrac)
                        : curBar.close < ob.intercept * (1 - obBreakFrac);
                    if (isBreakout) brokenNow.push(ob);
                }
                if (brokenNow.length > 0) brokenOBsByDay.set(i, brokenNow);

                // Run touch detection on alive OBs
                if (obCandidates.length > 0 && obZonePct > 0) {
                    const obTouches = obFilter!.compute(
                        slicedDays, obCandidates, obEpConfig, obZonePct, i, null,
                    );
                    for (const ep of obTouches) {
                        if (ep.status !== 'confirmed') continue;
                        const id = `${ep.trendlineId}|${ep.dayIndex}|${ep.type}`;
                        if (!confirmedOBPivotIds.has(id)) {
                            confirmedOBPivotIds.add(id);
                            confirmedOBPivots.push(ep);
                        }
                    }
                }
            }

            prevAliveOBMap = new Map(obCandidates.map(l => [l.id, l]));
        }
    }

    // ── Step 4: build entry signals ────────────────────────────────────────────────
    if (!signalConfig.enabled) {
        return _empty();
    }

    // ── Pivot-confirmation entry signals ────────────────────────────────────────────
    // Exclude pivots whose touch day is in the pre-history zone — those are only
    // used for trendline anchoring, not as trade entry signals.
    const pivotEntrySignals: EntrySignal[] = signalConfig.usePivotConfirmation
        ? confirmedEarlyPivots
            .filter(ep => ep.confirmedAt !== undefined && ep.dayIndex >= preHistoryCount)
            .map(ep => ({
                kind:     ep.type === "high" ? "short" as const : "long" as const,
                price:    ep.confirmedAt!,
                time:     ep.confirmMinuteTime ?? ep.time,
                dayIndex:    ep.dayIndex,
                entryReason: 'pivot_confirmation' as const,
            }))
        : [];

    // ── Trendline breakout entry signals ──────────────────────────────────────
    const breakoutEntrySignals: EntrySignal[] = [];
    if (signalConfig.useBreakoutDetection) {
        for (let di = loopStart; di < days.length; di++) {
            const brokenMap = brokenTrendlinesByDay.get(di);
            if (!brokenMap || brokenMap.size === 0) continue;
            const curBar = days[di];

            for (const [, tl] of brokenMap) {
                const lineY = tl.slope * di + tl.intercept;
                if (lineY <= 0) continue;

                if (tl.type === "resistance") {
                    const threshold = lineY * (1 + zonePct);
                    const breakMin  = curBar.minutes.find(m => m.close > threshold);
                    if (breakMin) {
                        breakoutEntrySignals.push({
                            kind: "long", price: breakMin.close, time: breakMin.time,
                            dayIndex: di, entryReason: 'breakout' as const,
                        });
                    }
                } else {
                    const threshold = lineY * (1 - zonePct);
                    const breakMin  = curBar.minutes.find(m => m.close < threshold);
                    if (breakMin) {
                        breakoutEntrySignals.push({
                            kind: "short", price: breakMin.close, time: breakMin.time,
                            dayIndex: di, entryReason: 'breakout' as const,
                        });
                    }
                }
            }
        }
    }

    // ── OB touch entry signals ─────────────────────────────────────────────────
    const obTouchSignals: EntrySignal[] = useOBSignals
        ? confirmedOBPivots
            .filter(ep => ep.confirmedAt !== undefined && ep.dayIndex >= preHistoryCount)
            .map(ep => ({
                kind:        ep.type === 'high' ? 'short' as const : 'long' as const,
                price:       ep.confirmedAt!,
                time:        ep.confirmMinuteTime ?? ep.time,
                dayIndex:    ep.dayIndex,
                entryReason: 'pivot_confirmation' as const,
            }))
        : [];

    // ── OB breakout entry signals ──────────────────────────────────────────────
    const obBreakoutSignals: EntrySignal[] = [];
    if (useOBSignals) {
        for (let di = loopStart; di < days.length; di++) {
            const brokenOBs = brokenOBsByDay.get(di);
            if (!brokenOBs) continue;
            const curBar = days[di];
            for (const ob of brokenOBs) {
                const lineY = ob.intercept;
                if (lineY <= 0) continue;
                if (ob.type === 'resistance') {
                    const threshold = lineY * (1 + obBreakFrac);
                    // Close-based: first minute whose CLOSE confirms the breakout
                    const breakMin  = curBar.minutes.find(m => m.close > threshold);
                    if (breakMin) obBreakoutSignals.push({
                        kind: 'long', price: threshold, time: breakMin.time,
                        dayIndex: di, entryReason: 'breakout' as const,
                    });
                } else {
                    const threshold = lineY * (1 - obBreakFrac);
                    // Close-based: first minute whose CLOSE confirms the breakout
                    const breakMin  = curBar.minutes.find(m => m.close < threshold);
                    if (breakMin) obBreakoutSignals.push({
                        kind: 'short', price: threshold, time: breakMin.time,
                        dayIndex: di, entryReason: 'breakout' as const,
                    });
                }
            }
        }
    }

    const entrySignals: EntrySignal[] = [
        ...pivotEntrySignals,
        ...breakoutEntrySignals,
        ...obTouchSignals,
        ...obBreakoutSignals,
    ].sort((a, b) => a.time - b.time);

    // ── Zone Entry Strategy ────────────────────────────────────────────────────
    // Uses the final trendlines and alive OBs (same as the live chart).
    // Runs a self-contained simulation: zone-exit entries + zone-entry exits.
    let zoneEntrySignals:   Signal[]         = [];
    let zoneEntryBtTrades:  BacktestTrade[]  = [];
    let zoneEntryChartSigs: EntrySignal[]    = [];

    if (signalConfig.useZoneEntryStrategy) {
        const finalTrendlines = trendlinesByDay.get(days.length - 1) ?? [];

        const finalOBs: OrderBlock[] = !!orderBlockConfig?.enabled
            ? [...prevAliveOBMap.values()].map(tl => ({
                id:            tl.id,
                type:          tl.type,
                price:         tl.intercept,
                pivotDayIndex: tl.start_index,
                endIndex:      days.length - 1,
                score:         0,
                zoneHigh:      tl.intercept * (1 + obZonePct),
                zoneLow:       tl.intercept * (1 - obZonePct),
                mergedCount:   1,
            } as OrderBlock))
            : [];

        const zef = new ZoneEntryFilter();
        zoneEntrySignals = zef.compute(
            days,
            finalTrendlines,
            trendlineConfig.touchZonePct,
            finalOBs,
            {
                useStopLoss:   tradeAxisConfig.useStopLoss,
                useTakeProfit: tradeAxisConfig.useTakeProfit,
                stopLossPct:   strategyConfig.stopLoss,
                takeProfitPct: strategyConfig.takeProfit,
            },
            null,
        );

        // Chart overlay: include zone entry ENTRY signals in the signal markers
        zoneEntryChartSigs = zoneEntrySignals
            .filter(s => s.source === 'zone_entry' &&
                         (s.kind === 'long' || s.kind === 'short') &&
                         s.dayIndex >= preHistoryCount)
            .map(s => ({
                kind:        s.kind as 'long' | 'short',
                price:       s.price,
                time:        s.time,
                dayIndex:    s.dayIndex,
                entryReason: 'zone_entry' as const,
            }));

        // Backtest trades: simulate using ZoneEntryFilter's own signal stream
        zoneEntryBtTrades = _simulateZoneEntryTrades(zoneEntrySignals, preHistoryCount);
    }

    // Keep the full signal list BEFORE trade filtering for chart overlay
    const allEntrySignals = [...entrySignals, ...zoneEntryChartSigs];

    // ── Steps 5–6: simulate trades with per-day trendlines, then build stats ──
    const trades = _simulateTrades(entrySignals, days, trendlinesByDay, tradeAxisConfig, strategyConfig, zonePct);
    const allTrades = [...trades, ...zoneEntryBtTrades].sort((a, b) => a.entryTime - b.entryTime);
    return _buildResult(allTrades, strategyConfig, days, allEntrySignals);
}

// ─── 5. Trade simulation with per-day trendlines ─────────────────────────────

/**
 * Walk all minutes chronologically.  Open one trade at a time on the next
 * available entry signal; close it via SL / TP / zone-exit using the
 * trendlines that were active on THAT day (not the final snapshot).
 */
function _simulateTrades(
    entrySignals:    EntrySignal[],
    days:            DayCandle[],
    trendlinesByDay: Map<number, Trendline[]>,
    tac:             TradeAxisConfig,
    strat:           StrategyConfig,
    zonePct:         number,
): BacktestTrade[] {
    const trades: BacktestTrade[] = [];

    interface OpenTrade {
        kind:       'long' | 'short';
        entryPrice: number;
        slPrice:    number | null;
        tpPrice:    number | null;
        entryTime:   number;
        entryReason: 'pivot_confirmation' | 'breakout' | 'zone_entry';
    }

    let openTrade: OpenTrade | null = null;
    let entryIdx = 0;
    let tradeIdx = 0;

    for (let di = 0; di < days.length; di++) {
        const day        = days[di];
        const trendlines = trendlinesByDay.get(di) ?? [];

        for (const min of day.minutes) {

            // Process all entry signals at or before this minute.
            // If a trade is already open, force-close it at the new signal's
            // price before opening the new trade — so every signal maps to a trade.
            while (
                entryIdx < entrySignals.length &&
                entrySignals[entryIdx].time <= min.time
            ) {
                const entry = entrySignals[entryIdx];

                if (openTrade !== null) {
                    // Force-close the existing trade at the new signal's price.
                    const { kind, entryPrice } = openTrade;
                    const isLong = kind === 'long';
                    const exitPrice = entry.price;
                    const pnl = isLong
                        ? (exitPrice - entryPrice) / entryPrice
                        : (entryPrice - exitPrice) / entryPrice;
                    trades.push({
                        id:          `trade_${tradeIdx++}`,
                        type:        kind,
                        entryPrice,
                        exitPrice,
                        entryTime:   openTrade.entryTime,
                        exitTime:    entry.time,
                        entryReason: openTrade.entryReason,
                        exitReason:  'zone_exit',
                        pnl,
                        status:      'closed',
                    });
                    openTrade = null;
                }

                const isLong = entry.kind === 'long';
                openTrade = {
                    kind:       entry.kind,
                    entryPrice: entry.price,
                    slPrice:    tac.useStopLoss
                        ? (isLong
                            ? entry.price * (1 - strat.stopLoss / 100)
                            : entry.price * (1 + strat.stopLoss / 100))
                        : null,
                    tpPrice:    tac.useTakeProfit
                        ? (isLong
                            ? entry.price * (1 + strat.takeProfit / 100)
                            : entry.price * (1 - strat.takeProfit / 100))
                        : null,
                    entryTime:   entry.time,
                    entryReason: entry.entryReason,
                };
                entryIdx++;
            }

            if (!openTrade) continue;

            const { kind, entryPrice, slPrice, tpPrice } = openTrade;
            const isLong = kind === 'long';
            let exitPrice  = 0;
            let exitReason: TradeExitReason | null = null;

            // Stop Loss
            if (slPrice !== null) {
                const hit = isLong ? min.low <= slPrice : min.high >= slPrice;
                if (hit) { exitPrice = slPrice; exitReason = 'stop_loss'; }
            }

            // Take Profit (only if SL not triggered)
            if (exitReason === null && tpPrice !== null) {
                const hit = isLong ? min.high >= tpPrice : min.low <= tpPrice;
                if (hit) { exitPrice = tpPrice; exitReason = 'take_profit'; }
            }

            // Zone Exit using the trendlines active on THIS day
            if (exitReason === null && tac.useZoneExit && zonePct > 0) {
                for (const tl of trendlines) {
                    if (di < tl.start_index || di > tl.end_index) continue;
                    const lineY = tl.slope * di + tl.intercept;
                    if (lineY <= 0) continue;

                    const zoneBot = lineY * (1 - zonePct);
                    const zoneTop = lineY * (1 + zonePct);

                    if (isLong && tl.type === 'resistance' && min.high >= zoneBot) {
                        exitPrice  = lineY;
                        exitReason = 'zone_exit';
                        break;
                    }
                    if (!isLong && tl.type === 'support' && min.low <= zoneTop) {
                        exitPrice  = lineY;
                        exitReason = 'zone_exit';
                        break;
                    }
                }
            }

            if (exitReason !== null) {
                const pnl = isLong
                    ? (exitPrice - entryPrice) / entryPrice
                    : (entryPrice - exitPrice) / entryPrice;

                trades.push({
                    id:           `trade_${tradeIdx++}`,
                    type:         kind,
                    entryPrice,
                    exitPrice,
                    entryTime:    openTrade.entryTime,
                    exitTime:     min.time,
                    entryReason:  openTrade.entryReason,
                    exitReason,
                    pnl,
                    status:       'closed',
                });
                openTrade = null;
            }
        }
    }

    // Open trade still running at end of dataset
    if (openTrade !== null) {
        trades.push({
            id:         `trade_${tradeIdx}`,
            type:       openTrade.kind,
            entryPrice:  openTrade.entryPrice,
            entryTime:   openTrade.entryTime,
            entryReason: openTrade.entryReason,
            status:      'active',
        });
    }

    return trades;
}

// ─── Zone Entry trade simulation ─────────────────────────────────────────────

/**
 * Convert the Signal[] output of ZoneEntryFilter into BacktestTrade[] by
 * walking the stream in chronological order.  Entry signals open a trade;
 * exit signals (win/loss) close it.
 */
function _simulateZoneEntryTrades(
    signals:         Signal[],
    preHistoryCount: number,
): BacktestTrade[] {
    const sorted = [...signals].sort((a, b) => a.time - b.time);
    const trades: BacktestTrade[] = [];
    let tradeIdx = 0;
    let open: { type: 'long' | 'short'; entryPrice: number; entryTime: number } | null = null;

    for (const sig of sorted) {
        if ((sig.kind === 'long' || sig.kind === 'short') &&
             sig.source === 'zone_entry' &&
             sig.dayIndex >= preHistoryCount) {
            if (open !== null) {
                // Force-close existing trade at the new signal's price.
                const pnl = open.type === 'long'
                    ? (sig.price - open.entryPrice) / open.entryPrice
                    : (open.entryPrice - sig.price) / open.entryPrice;
                trades.push({
                    id:          `ze_${tradeIdx++}`,
                    type:        open.type,
                    entryPrice:  open.entryPrice,
                    exitPrice:   sig.price,
                    entryTime:   open.entryTime,
                    exitTime:    sig.time,
                    entryReason: 'zone_entry',
                    exitReason:  'zone_exit',
                    pnl,
                    status: 'closed',
                });
                open = null;
            }
            open = { type: sig.kind as 'long' | 'short', entryPrice: sig.price, entryTime: sig.time };
        } else if ((sig.kind === 'win' || sig.kind === 'loss') && open !== null) {
            const pnl = open.type === 'long'
                ? (sig.price - open.entryPrice) / open.entryPrice
                : (open.entryPrice - sig.price) / open.entryPrice;
            const exitReason: TradeExitReason =
                sig.source === 'stop_loss'   ? 'stop_loss'   :
                sig.source === 'take_profit' ? 'take_profit' : 'zone_exit';
            trades.push({
                id:          `ze_${tradeIdx++}`,
                type:        open.type,
                entryPrice:  open.entryPrice,
                exitPrice:   sig.price,
                entryTime:   open.entryTime,
                exitTime:    sig.time,
                entryReason: 'zone_entry',
                exitReason,
                pnl,
                status: 'closed',
            });
            open = null;
        }
    }

    if (open !== null) {
        trades.push({
            id:          `ze_${tradeIdx}`,
            type:        open.type,
            entryPrice:  open.entryPrice,
            entryTime:   open.entryTime,
            entryReason: 'zone_entry',
            status:      'active',
        });
    }

    return trades;
}

// ─── 6. Equity curve + stats ─────────────────────────────────────────────────

function _buildResult(
    trades:          BacktestTrade[],
    strategyConfig:  StrategyConfig,
    days:            DayCandle[],
    allEntrySignals: EntrySignal[] = [],
): BacktestResult {
    const { initialEquity, riskPerTrade, leverage } = strategyConfig;
    const closed = trades.filter(t => t.status === 'closed' && t.pnl !== undefined);

    const startTime = (days[0]?.time ?? 0) as Time;

    if (closed.length === 0) {
        return {
            trades,
            equityCurve: [{ time: startTime, value: initialEquity }],
            stats: _emptyStats(),
            allEntrySignals,
        };
    }

    let equity      = initialEquity;
    let peakEquity  = equity;
    let maxDdPct    = 0;
    const wins:   number[] = [];
    const losses: number[] = [];
    const tradeReturns: number[] = [];

    const equityCurve: { time: Time; value: number }[] = [
        { time: startTime, value: equity },
    ];

    for (const t of closed) {
        const betSize  = equity * (riskPerTrade / 100) * leverage;
        const pnlAmt   = betSize * t.pnl!;
        equity        += pnlAmt;
        equity         = Math.max(equity, 0.01);

        equityCurve.push({ time: t.exitTime! as Time, value: Math.round(equity * 100) / 100 });

        if (equity > peakEquity) peakEquity = equity;
        const dd = (peakEquity - equity) / peakEquity * 100;
        if (dd > maxDdPct) maxDdPct = dd;

        if (pnlAmt >= 0) wins.push(pnlAmt);
        else             losses.push(Math.abs(pnlAmt));

        tradeReturns.push(t.pnl! * (riskPerTrade / 100) * leverage);
    }

    const grossProfit  = wins.reduce((s, v) => s + v, 0);
    const grossLoss    = losses.reduce((s, v) => s + v, 0);
    const totalReturn  = (equity - initialEquity) / initialEquity * 100;
    const winRate      = closed.filter(t => (t.pnl ?? 0) > 0).length / closed.length;
    const profitFactor = grossLoss > 0 ? grossProfit / grossLoss : grossProfit > 0 ? 999 : 0;

    // Approximate Sharpe from trade returns, annualised assuming ~252 trades/year
    const meanR  = tradeReturns.reduce((s, v) => s + v, 0) / tradeReturns.length;
    const stdR   = Math.sqrt(
        tradeReturns.map(r => (r - meanR) ** 2).reduce((s, v) => s + v, 0) / tradeReturns.length,
    );
    const sharpe = stdR > 0 ? (meanR / stdR) * Math.sqrt(252) : 0;

    // Deduplicate equity curve: lightweight-charts requires strictly ascending time.
    // When two trades close at the same timestamp (e.g. force-close + immediate
    // re-entry), keep only the last point for that timestamp.
    const dedupedCurve = equityCurve.reduce<typeof equityCurve>((acc, pt) => {
        if (acc.length > 0 && acc[acc.length - 1].time === pt.time) {
            acc[acc.length - 1] = pt;
        } else {
            acc.push(pt);
        }
        return acc;
    }, []);

    return {
        trades,
        equityCurve: dedupedCurve,
        stats: {
            total_return:  +totalReturn.toFixed(2),
            win_rate:      +winRate.toFixed(3),
            total_trades:  closed.length,
            max_drawdown:  +maxDdPct.toFixed(2),
            sharpe_ratio:  +sharpe.toFixed(2),
            profit_factor: +profitFactor.toFixed(2),
        },
        allEntrySignals,
    };
}

// ─── Helpers ──────────────────────────────────────────────────────────────────


function _emptyStats(): BacktestStats {
    return { total_return: 0, win_rate: 0, total_trades: 0, max_drawdown: 0, sharpe_ratio: 0, profit_factor: 0 };
}

function _empty(): BacktestResult {
    return { trades: [], equityCurve: [], stats: _emptyStats(), allEntrySignals: [] };
}
