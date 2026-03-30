import { Observable } from '../Observable';
import { SignalFilter } from '../filters/SignalFilter';
import { ZoneEntryFilter } from '../filters/ZoneEntryFilter';
import type { Signal, ActiveTrade } from '../models/Signal';
import type { DayCandle } from '../models/Candle';
import type { MarketDataStore } from '../store/MarketDataStore';
import type { LayoutManager } from './LayoutManager';
import type { EarlyPivotManager } from './EarlyPivotManager';
import type { TrendlineManager } from './TrendlineManager';
import type { OrderBlockManager } from './OrderBlockManager';
import type { SignalConfig } from '../store/types';

const DEFAULT_SIGNAL_CONFIG: SignalConfig = {
    enabled:              true,
    usePivotConfirmation: true,
    useBreakoutDetection: true,
    useOrderBlockSignals: true,
    useZoneEntryStrategy: false,
};

/**
 * Manages signal detection and state.
 *
 * Pivot-confirmation entry signals are recomputed each cycle (stable: their
 * source — confirmedEarlyPivots — persists across cycles).
 *
 * Breakout entry signals are ACCUMULATED: TrendlineManager.brokenTrendlines
 * is only non-empty for the one cycle when a line transitions active→broken,
 * so breakout signals must be persisted here on first detection.
 *
 * Exit signals (zone-exit, SL, TP) are also ACCUMULATED: once detected they
 * are persisted in `_persistedExits` and never removed simply because the
 * source trendline later disappeared.
 *
 * Both breakout entries and exits are only pruned when playback rewinds past
 * their timestamp or when a configuration change forces a full reset.
 *
 * `activeTrade` is derived from the combined signal stream via a simple
 * open/close state machine.
 */
export class SignalManager extends Observable {

    // ─── Public state ─────────────────────────────────────────────────────────
    signals:     Signal[]          = [];
    activeTrade: ActiveTrade | null = null;
    config:      SignalConfig      = { ...DEFAULT_SIGNAL_CONFIG };

    // ─── Private ──────────────────────────────────────────────────────────────
    private market:         MarketDataStore;
    private layout:         LayoutManager;
    private earlyPivotMgr:  EarlyPivotManager;
    private trendlineMgr:   TrendlineManager;
    private orderBlockMgr:  OrderBlockManager | null = null;
    private filter:          SignalFilter     = new SignalFilter();
    private zoneEntryFilter: ZoneEntryFilter  = new ZoneEntryFilter();

    /** Exit signals that have been confirmed and must survive trendline changes. */
    private _persistedExits:   Signal[]    = [];
    private _persistedExitIds: Set<string> = new Set();

    /**
     * Pivot confirmation entry signals persisted on first detection.
     * When a trendline moves or is pruned, its associated confirmedEarlyPivot
     * disappears from EarlyPivotManager, which would cause the entry signal
     * (a "done order") to vanish from the chart.  Persisting here keeps the
     * arrow visible for the lifetime of the playback session, exactly like
     * a real filled order that cannot be un-filled.
     */
    private _persistedPivotConfirmations:   Signal[]    = [];
    private _persistedPivotConfirmationIds: Set<string> = new Set();

    /**
     * Breakout entry signals persisted on first detection.
     * brokenTrendlines is only set for one cycle (active→broken transition),
     * so these must be accumulated here to survive subsequent recomputes.
     */
    private _persistedBreakouts:   Signal[]    = [];
    private _persistedBreakoutIds: Set<string> = new Set();

    /**
     * The largest "effective now" timestamp seen in a previous recompute.
     * Used to detect playback rewinds so stale exits can be pruned.
     */
    private _lastScanTime: number = -Infinity;

    /**
     * Hash of exit-relevant config options + dataset identity.
     * When it changes, persisted exits are cleared and the scan restarts.
     */
    private _configHash: string = '';

    private _unsubMarket: (() => void) | null = null;
    private _unsubLayout: (() => void) | null = null;

    constructor(
        market:        MarketDataStore,
        layout:        LayoutManager,
        earlyPivotMgr: EarlyPivotManager,
        trendlineMgr:  TrendlineManager,
    ) {
        super();
        this.market        = market;
        this.layout        = layout;
        this.earlyPivotMgr = earlyPivotMgr;
        this.trendlineMgr  = trendlineMgr;

        this._unsubMarket = market.subscribe(() => this._recompute());
        this._unsubLayout = layout.subscribe(() => this._recompute());
    }

    // ─── Public mutators ──────────────────────────────────────────────────────

    setOrderBlockManager(mgr: OrderBlockManager): void {
        this.orderBlockMgr = mgr;
    }

    setConfig(patch: Partial<SignalConfig>): void {
        this.config = { ...this.config, ...patch };
        this._recompute();
    }

    dispose(): void {
        this._unsubMarket?.();
        this._unsubLayout?.();
    }

    // ─── Recompute ────────────────────────────────────────────────────────────

    _recompute(): void {
        const tac          = this.layout.tradeAxisConfig;
        const strat        = this.layout.strategyConfig;
        const days         = this._buildVisibleDays();
        const playbackTime = this.layout.playbackTime;

        // "effective now" = playback cursor, or the last minute in the dataset
        const effectiveNow = playbackTime !== null
            ? playbackTime
            : this._lastMinuteTime(days);

        if (!this.config.enabled || days.length === 0) {
            if (this.signals.length > 0 || this.activeTrade !== null) {
                this.signals                         = [];
                this.activeTrade                     = null;
                this._persistedExits                 = [];
                this._persistedExitIds               = new Set();
                this._persistedBreakouts             = [];
                this._persistedBreakoutIds           = new Set();
                this._persistedPivotConfirmations    = [];
                this._persistedPivotConfirmationIds  = new Set();
                this._lastScanTime                   = -Infinity;
                this.notify();
            }
            return;
        }

        // ── Config / dataset change → full reset ──────────────────────────────
        const newHash = this._makeConfigHash(tac, strat);
        if (newHash !== this._configHash) {
            this._configHash                        = newHash;
            this._persistedExits                    = [];
            this._persistedExitIds                  = new Set();
            this._persistedBreakouts                = [];
            this._persistedBreakoutIds              = new Set();
            this._persistedPivotConfirmations       = [];
            this._persistedPivotConfirmationIds     = new Set();
            this._lastScanTime                      = -Infinity;
        }

        // ── Playback rewind → prune persisted signals now in the future ───────
        if (effectiveNow < this._lastScanTime) {
            this._persistedExits                    = this._persistedExits.filter(s => s.time <= effectiveNow);
            this._persistedExitIds                  = new Set(this._persistedExits.map(s => s.id));
            this._persistedBreakouts                = this._persistedBreakouts.filter(s => s.time <= effectiveNow);
            this._persistedBreakoutIds              = new Set(this._persistedBreakouts.map(s => s.id));
            this._persistedPivotConfirmations       = this._persistedPivotConfirmations.filter(s => s.time <= effectiveNow);
            this._persistedPivotConfirmationIds     = new Set(this._persistedPivotConfirmations.map(s => s.id));
        }
        this._lastScanTime = effectiveNow;

        // ── Build brokenTrendlines map for the filter ─────────────────────────
        // Each source is gated independently:
        //   • Trendline breakouts  → only when trendlines are enabled
        //   • OB breakouts         → only when useOrderBlockSignals is on
        // We pre-filter here so SignalFilter can run with useBreakoutDetection:true.
        const brokenMap = new Map<string, {
            type: 'resistance' | 'support';
            slope: number;
            intercept: number;
        }>();
        if (this.trendlineMgr.enabled) {
            for (const [id, tl] of this.trendlineMgr.brokenTrendlines.entries()) {
                brokenMap.set(id, { type: tl.type, slope: tl.slope, intercept: tl.intercept });
            }
        }
        // OB breakouts are handled separately below (wick-based, exact threshold price).
        // Do NOT add them to brokenMap — that path uses touchZonePct, which is wrong for OBs.

        // ── Build confirmed pivot list for the filter ─────────────────────────
        // Each source is gated independently:
        //   • Trendline pivots  → trendlines enabled + usePivotConfirmation
        //   • OB pivots         → useOrderBlockSignals (OB enabled implied by non-empty list)
        // We pre-filter here so SignalFilter can run with usePivotConfirmation:true
        // and OB signals are never blocked by the usePivotConfirmation toggle.
        const preHistoryCount   = this.market.preHistoryCount ?? 0;
        const actualRangePivots: typeof this.earlyPivotMgr.confirmedEarlyPivots = [];

        if (this.trendlineMgr.enabled && this.config.usePivotConfirmation) {
            actualRangePivots.push(
                ...this.earlyPivotMgr.confirmedEarlyPivots
                    .filter(p => p.dayIndex >= preHistoryCount),
            );
        }
        if (this.config.useOrderBlockSignals && this.orderBlockMgr) {
            actualRangePivots.push(
                ...this.orderBlockMgr.confirmedOBTouches
                    .filter(p => p.dayIndex >= preHistoryCount && p.confirmedAt !== undefined),
            );
        }

        // ── Run SignalFilter (stateless) ──────────────────────────────────────
        // usePivotConfirmation and useBreakoutDetection are forced true because
        // the per-source gating already happened above.
        const { signals: freshSignals } = this.filter.compute(
            actualRangePivots,
            this.trendlineMgr.trendlines,
            brokenMap,
            days,
            { ...this.config, usePivotConfirmation: true, useBreakoutDetection: true },
            {
                useZoneExit:   tac.useZoneExit,
                useStopLoss:   tac.useStopLoss,
                useTakeProfit: tac.useTakeProfit,
                stopLossPct:   strat.stopLoss,
                takeProfitPct: strat.takeProfit,
                touchZonePct:  this.trendlineMgr.config.touchZonePct,
            },
            playbackTime,
        );

        // ── Separate fresh signals by kind and source ─────────────────────────
        const freshBreakouts    = freshSignals.filter(s => (s.kind === 'long' || s.kind === 'short') && s.source === 'breakout');
        const freshOtherEntries = freshSignals.filter(s => (s.kind === 'long' || s.kind === 'short') && s.source !== 'breakout');
        const freshExits        = freshSignals.filter(s => s.kind === 'win' || s.kind === 'loss');

        // ── Accumulate pivot confirmation entries ─────────────────────────────
        // Once a pivot confirmation arrow appears it must never vanish — it
        // represents a filled order.  When the underlying trendline later moves
        // or is pruned, confirmedEarlyPivots shrinks and freshOtherEntries
        // loses the corresponding signal.  Persisting here keeps it alive for
        // the entire playback session, matching "done order" semantics.
        for (const pc of freshOtherEntries) {
            if (!this._persistedPivotConfirmationIds.has(pc.id)) {
                this._persistedPivotConfirmations.push(pc);
                this._persistedPivotConfirmationIds.add(pc.id);
            }
        }

        // ── Accumulate breakout entries (brokenTrendlines is only set for one cycle) ──
        for (const bo of freshBreakouts) {
            if (!this._persistedBreakoutIds.has(bo.id)) {
                this._persistedBreakouts.push(bo);
                this._persistedBreakoutIds.add(bo.id);
            }
        }

        // ── OB breakout signals: wick-based, price = exact breakout threshold ─
        // Bypass SignalFilter (which uses touchZonePct). Use breakoutTolerance and
        // detect the first minute whose HIGH (resistance) or LOW (support) crosses
        // the threshold — matching the alive-check logic in OrderBlockManager.
        if (this.config.useBreakoutDetection && this.config.useOrderBlockSignals && this.orderBlockMgr) {
            const obBreakFrac = this.orderBlockMgr.config.breakoutTolerance / 100;
            const dayIdx      = days.length - 1;
            const curDay      = days[dayIdx];
            if (curDay) {
                const mins = playbackTime !== null
                    ? curDay.minutes.filter(m => m.time <= playbackTime)
                    : curDay.minutes;

                for (const [id, ob] of this.orderBlockMgr.brokenOrderBlocks.entries()) {
                    const threshold = ob.type === 'resistance'
                        ? ob.intercept * (1 + obBreakFrac)
                        : ob.intercept * (1 - obBreakFrac);

                    const breakMin = ob.type === 'resistance'
                        ? mins.find(m => m.close > threshold)
                        : mins.find(m => m.close < threshold);

                    if (!breakMin) continue;

                    const sigId = `bo|${id}|${dayIdx}`;
                    if (!this._persistedBreakoutIds.has(sigId)) {
                        this._persistedBreakouts.push({
                            id:       sigId,
                            kind:     ob.type === 'resistance' ? 'long' : 'short',
                            source:   'breakout',
                            price:    threshold,
                            time:     breakMin.time,
                            dayIndex: dayIdx,
                        });
                        this._persistedBreakoutIds.add(sigId);
                    }
                }
            }
        }

        // ── Accumulate exits: add newly detected ones, keep all previous ones ──
        for (const exit of freshExits) {
            if (!this._persistedExitIds.has(exit.id)) {
                this._persistedExits.push(exit);
                this._persistedExitIds.add(exit.id);
            }
        }

        // ── Zone Entry Strategy (fresh compute each cycle — deterministic) ────
        const zoneEntrySignals: Signal[] = this.config.useZoneEntryStrategy
            ? this.zoneEntryFilter.compute(
                days,
                this.trendlineMgr.enabled ? this.trendlineMgr.trendlines : [],
                this.trendlineMgr.enabled ? this.trendlineMgr.config.touchZonePct : 0,
                this.orderBlockMgr && this.orderBlockMgr.config.enabled
                    ? this.orderBlockMgr.orderBlocks
                    : [],
                {
                    useStopLoss:   tac.useStopLoss,
                    useTakeProfit: tac.useTakeProfit,
                    stopLossPct:   strat.stopLoss,
                    takeProfitPct: strat.takeProfit,
                },
                playbackTime,
            )
            : [];

        // ── Build the final combined signal list ──────────────────────────────
        const allSignals = [
            ...this._persistedPivotConfirmations,
            ...this._persistedBreakouts,
            ...this._persistedExits,
            ...zoneEntrySignals,
        ].sort((a, b) => a.time - b.time);

        // ── Derive activeTrade from the full signal stream (state machine) ────
        // Walk every signal chronologically.  Entry with no open trade → open.
        // Exit with open trade → close.  The final open trade (if any) is the
        // currently active position whose SL/TP lines we should display.
        const activeTrade = this._deriveActiveTrade(allSignals, tac, strat);

        const changed =
            allSignals.length !== this.signals.length     ||
            activeTrade?.kind       !== this.activeTrade?.kind       ||
            activeTrade?.entryPrice !== this.activeTrade?.entryPrice ||
            activeTrade?.slPrice    !== this.activeTrade?.slPrice    ||
            activeTrade?.tpPrice    !== this.activeTrade?.tpPrice;

        this.signals     = allSignals;
        this.activeTrade = activeTrade;
        if (changed) this.notify();
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    /**
     * Walk signals in chronological order and track the open/close state of
     * one trade at a time.  Returns the last trade that was opened but never
     * closed, or null if all trades have been exited.
     */
    private _deriveActiveTrade(
        signals: Signal[],
        tac:     typeof this.layout.tradeAxisConfig,
        strat:   typeof this.layout.strategyConfig,
    ): ActiveTrade | null {
        let trade: ActiveTrade | null = null;

        for (const sig of signals) {
            if ((sig.kind === 'long' || sig.kind === 'short') && trade === null) {
                const isLong = sig.kind === 'long';
                trade = {
                    kind:       sig.kind,
                    entryPrice: sig.price,
                    slPrice: tac.useStopLoss
                        ? (isLong
                            ? sig.price * (1 - strat.stopLoss / 100)
                            : sig.price * (1 + strat.stopLoss / 100))
                        : null,
                    tpPrice: tac.useTakeProfit
                        ? (isLong
                            ? sig.price * (1 + strat.takeProfit / 100)
                            : sig.price * (1 - strat.takeProfit / 100))
                        : null,
                    entryTime:   sig.time,
                    entryDayIdx: sig.dayIndex,
                };
            } else if ((sig.kind === 'win' || sig.kind === 'loss') && trade !== null) {
                trade = null;
            }
        }

        return trade;
    }

    /**
     * A hash of all config values that affect exit-signal generation plus a
     * dataset identity fingerprint.  When it changes, persisted exits must
     * be cleared and the scan restarted from scratch.
     *
     * IMPORTANT: dataset identity uses this.market.days (the FULL raw array),
     * NOT the visibleDays slice — visibleDays grows on every playback step and
     * must never be used here or exits would reset on every minute advance.
     */
    private _makeConfigHash(
        tac:  typeof this.layout.tradeAxisConfig,
        strat: typeof this.layout.strategyConfig,
    ): string {
        const raw = this.market.days;
        return [
            this.config.enabled,
            this.config.usePivotConfirmation,
            this.config.useBreakoutDetection,
            this.config.useOrderBlockSignals,
            this.trendlineMgr.enabled,
            this.orderBlockMgr?.config.touchZonePct,
            this.orderBlockMgr?.config.recoilPct,
            tac.useZoneExit,
            tac.useStopLoss,
            tac.useTakeProfit,
            strat.stopLoss,
            strat.takeProfit,
            this.trendlineMgr.config.touchZonePct,
            // Dataset identity: anchored to full raw dataset (stable across playback)
            raw.length > 0 ? raw[0].time : 0,
            raw.length > 0 ? raw[raw.length - 1].time : 0,
            raw.length,
        ].join('|');
    }

    /** Latest minute timestamp visible in the dataset (used when not in playback). */
    private _lastMinuteTime(days: DayCandle[]): number {
        if (days.length === 0) return 0;
        const last = days[days.length - 1];
        if (last.minutes.length > 0) return last.minutes[last.minutes.length - 1].time;
        return last.time;
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

        const activeDay = allDays[activeDayIdx];
        const seenMins  = activeDay.minutes.filter(m => m.time <= pt);
        let liveDay     = activeDay;
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
