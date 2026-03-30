import { Observable } from '../Observable';
import { EarlyPivotFilter } from '../filters/EarlyPivotFilter';
import { DEFAULT_EARLY_PIVOT_CONFIG } from '../models/EarlyPivot';
import type { EarlyPivot, EarlyPivotConfig } from '../models/EarlyPivot';
import type { Pivot } from '../filters/PivotFilter';
import type { DayCandle } from '../models/Candle';
import type { MarketDataStore } from '../store/MarketDataStore';
import type { LayoutManager } from './LayoutManager';
import type { TrendlineManager } from './TrendlineManager';

/**
 * Manages early pivot detection based on trendline touch zones.
 *
 * Subscribes to MarketData and Layout for recompute triggers. Also receives
 * explicit recompute calls from the store-level bridge after TrendlineManager
 * fires, avoiding a circular subscription chain.
 *
 * State:
 *   provisionalPivots    — last 2 candles only; transparent markers.
 *   confirmedEarlyPivots — permanent outside the scan window; re-evaluated for
 *                          the two most-recent days so playback rewinds correctly.
 */
export class EarlyPivotManager extends Observable {

    // ─── Public state ─────────────────────────────────────────────────────────
    provisionalPivots:    EarlyPivot[] = [];
    confirmedEarlyPivots: EarlyPivot[] = [];
    config:               EarlyPivotConfig = { ...DEFAULT_EARLY_PIVOT_CONFIG };

    // ─── Private ──────────────────────────────────────────────────────────────
    private market:          MarketDataStore;
    private layout:          LayoutManager;
    private trendlineMgr:    TrendlineManager;
    private filter:          EarlyPivotFilter = new EarlyPivotFilter();

    private _unsubMarket: (() => void) | null = null;
    private _unsubLayout: (() => void) | null = null;
    private _recomputeDepth = 0;

    /**
     * Records the last playbackTime we saw for each dayIndex.
     * Used to prevent retroactive confirmation: when the user jumps from day D
     * to day D+1, EarlyPivotFilter evaluates D as axisX-1 and would normally
     * use ALL of D's minutes — confirming pivots that were never observed.
     * By capping D's minutes at _dayLastSeen.get(D), only events the user
     * actually stepped through are considered.
     */
    private _dayLastSeen: Map<number, number> = new Map();

    constructor(
        market:       MarketDataStore,
        layout:       LayoutManager,
        trendlineMgr: TrendlineManager,
    ) {
        super();
        this.market       = market;
        this.layout       = layout;
        this.trendlineMgr = trendlineMgr;

        // Restore persisted config
        this.config = { ...layout.earlyPivotConfig };

        this._unsubMarket = market.subscribe(() => this._recompute());
        this._unsubLayout = layout.subscribe(() => this._recompute());
    }

    // ─── Public mutators ──────────────────────────────────────────────────────

    setConfig(patch: Partial<EarlyPivotConfig>): void {
        this.config = { ...this.config, ...patch };
        this.layout.saveEarlyPivotState(this.config);
        this._recompute();
    }

    dispose(): void {
        this._unsubMarket?.();
        this._unsubLayout?.();
    }

    // ─── Confirmed pivots as standard Pivot objects ───────────────────────────

    /**
     * All confirmed early pivots (including superseded) expressed as plain
     * Pivot objects at their `confirmedAt` price. Used by TrendlineManager to
     * augment the trendline touch-point pool.
     */
    get confirmedAsPivots(): Pivot[] {
        return this.confirmedEarlyPivots
            .filter(ep => ep.confirmedAt !== undefined)
            .map(ep => ({
                time:     ep.time,
                price:    ep.confirmedAt!,
                type:     ep.type,
                dayIndex: ep.dayIndex,
            }));
    }

    // ─── Recompute (called by market/layout subscriptions + store bridge) ─────

    _recompute(): void {
        // Guard against deep re-entry (safety net for the store bridge pattern)
        if (this._recomputeDepth > 1) return;
        this._recomputeDepth++;
        try {
            this._doRecompute();
        } finally {
            this._recomputeDepth--;
        }
    }

    private _doRecompute(): void {
        if (!this.config.enabled || this.market.days.length === 0) {
            const changed = this.provisionalPivots.length > 0 || this.confirmedEarlyPivots.length > 0;
            this.provisionalPivots    = [];
            this.confirmedEarlyPivots = [];
            this._dayLastSeen.clear();
            if (changed) this.notify();
            return;
        }

        const trendlines = this.trendlineMgr.trendlines;
        const zonePct    = this.trendlineMgr.config.touchZonePct / 100;

        if (trendlines.length === 0 || zonePct <= 0) {
            const changed = this.provisionalPivots.length > 0;
            this.provisionalPivots = [];
            // Keep already-confirmed pivots (they're permanent)
            if (changed) this.notify();
            return;
        }

        const days  = this._buildVisibleDays();
        if (days.length === 0) return;

        const axisX        = days.length - 1;
        const playbackTime = this.layout.playbackTime;

        // When playback is reset to null (stop/free-scroll) clear the history
        // so a fresh playback session starts clean.
        if (playbackTime === null) {
            this._dayLastSeen.clear();
        }

        // Record the latest observed playback position for the active day so
        // that, when this day becomes axisX-1 on the next recompute, we can
        // limit its minute scan to what the user actually stepped through.
        if (playbackTime !== null) {
            const prev = this._dayLastSeen.get(axisX);
            if (prev === undefined || playbackTime > prev) {
                this._dayLastSeen.set(axisX, playbackTime);
            }
        }

        // Determine the observation ceiling for the previous day (axisX-1).
        // Three cases:
        //   a) free-scroll (playbackTime = null) → pass null; filter uses all minutes.
        //   b) D-1 was previously active → use the max playbackTime recorded for it.
        //   c) D-1 was NEVER the active day (user jumped/dragged slider over it) →
        //      fall back to days[axisX-1].time (UTC midnight, before any market open).
        //      This makes day.minutes.filter(m => m.time <= midnight) = [] so the
        //      guard in EarlyPivotFilter skips the evaluation entirely, preventing
        //      retroactive confirmation for days the user never stepped through.
        const prevDayObservedTime = playbackTime !== null
            ? (this._dayLastSeen.get(axisX - 1) ?? (days[axisX - 1]?.time ?? null))
            : null;

        // Run the pure filter on the last 2 candles
        const fresh = this.filter.compute(
            days, trendlines, this.config, zonePct, axisX, playbackTime, prevDayObservedTime,
        );

        // Separate provisional from confirmed
        const freshConfirmed = fresh.filter(ep => ep.status === 'confirmed');

        // Deduplicate provisional pivots: max one per (dayIndex, type).
        // Multiple trendlines can fire for the same candle and type; we keep
        // only the most immediate one — highest touchPrice for highs (the
        // tightest resistance being tested), lowest for lows (tightest support).
        // This guarantees at most 4 provisional pivots at any time:
        //   2 types (high/low) × 2 days (current + previous).
        const provisionalMap = new Map<string, EarlyPivot>();
        for (const ep of fresh) {
            if (ep.status !== 'provisional') continue;
            const key      = `${ep.dayIndex}|${ep.type}`;
            const existing = provisionalMap.get(key);
            if (!existing) {
                provisionalMap.set(key, ep);
            } else {
                const preferNew = ep.type === 'high'
                    ? ep.touchPrice > existing.touchPrice   // highest resistance
                    : ep.touchPrice < existing.touchPrice;  // lowest support
                if (preferNew) provisionalMap.set(key, ep);
            }
        }
        const freshProvisional = Array.from(provisionalMap.values());

        // ── Scan-window-aware confirmed list management ───────────────────────
        //
        // Scan-window management for confirmedEarlyPivots:
        //
        // Pivots OUTSIDE {axisX-1, axisX} are permanent — the filter can never
        // revisit them, so we keep them as-is.
        //
        // Pivots INSIDE the scan window need careful handling:
        //   • If confirmation is still in the future  (confirmTime > playbackTime)
        //     → discard and let the fresh filter re-evaluate.  This is the ONLY
        //       legitimate reason to remove a scan-window pivot: the user rewound
        //       before the confirmation minute.
        //   • If confirmation already happened       (confirmTime ≤ playbackTime)
        //     → keep permanently.  Trendline changes, new trendline IDs, or
        //       missing touches in the new trendline set must NOT delete a pivot
        //       that the user already observed as confirmed.  A confirmed pivot is
        //       a "done event" — like a filled order, it cannot be un-filled.
        //   • Free-scroll (playbackTime = null)
        //     → discard all scan-window entries and re-evaluate from scratch
        //       (no playback concept, full accuracy is preferred).
        const scanWindowDays = new Set<number>([axisX - 1, axisX]);

        const baseConfirmed = this.confirmedEarlyPivots.filter(c => {
            if (!scanWindowDays.has(c.dayIndex)) return true;   // outside window → keep
            if (playbackTime === null)           return false;   // free-scroll → re-evaluate
            const confirmTime = c.confirmMinuteTime ?? c.time;
            return confirmTime <= playbackTime;                  // past event → keep permanently
        });
        let confirmedChanged = baseConfirmed.length !== this.confirmedEarlyPivots.length;

        // Merge fresh confirmed pivots (scan-window results)
        const newConfirmed = [...baseConfirmed];

        for (const ep of freshConfirmed) {
            const key = `${ep.trendlineId}|${ep.dayIndex}|${ep.type}`;
            const existingIdx = newConfirmed.findIndex(
                c => `${c.trendlineId}|${c.dayIndex}|${c.type}` === key,
            );

            if (existingIdx < 0) {
                // New confirmed pivot
                newConfirmed.push(ep);
                confirmedChanged = true;
            } else {
                // Update superseded state if it changed
                const existing = newConfirmed[existingIdx];
                if (existing.superseded !== ep.superseded || existing.confirmedAt !== ep.confirmedAt || existing.supersededMinuteTime !== ep.supersededMinuteTime) {
                    newConfirmed[existingIdx] = ep;
                    confirmedChanged = true;
                }
            }
        }

        // Check if provisional list actually changed (include recoilThreshold so
        // that a moving threshold — e.g. as the running low extends deeper —
        // triggers a re-render of the price line even when the pivot set is stable).
        const provisionalKey = (e: EarlyPivot) => `${e.trendlineId}|${e.dayIndex}|${e.type}|${e.recoilThreshold}`;
        const oldProvisionalKeys = this.provisionalPivots.map(provisionalKey).sort().join(',');
        const newProvisionalKeys = freshProvisional.map(provisionalKey).sort().join(',');
        const provisionalChanged = oldProvisionalKeys !== newProvisionalKeys;

        this.provisionalPivots    = freshProvisional;
        this.confirmedEarlyPivots = newConfirmed;

        if (confirmedChanged || provisionalChanged) {
            this.notify();
        }
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
