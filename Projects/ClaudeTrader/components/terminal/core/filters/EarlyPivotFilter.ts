import type { DayCandle, RawCandle } from '../models/Candle';
import type { Trendline } from '../models/Trendline';
import type { EarlyPivot, EarlyPivotConfig } from '../models/EarlyPivot';

// ─── EarlyPivotFilter ─────────────────────────────────────────────────────────

/**
 * Pure, stateless filter. Scans the last two candles for early pivot signals
 * using minute-resolution data to determine the order of events within a day.
 *
 * Detection rules:
 *   Provisional — candle's high/low enters a trendline touch zone.
 *   Confirmed   — after the touch, price recoils `recoilPct`% back through the
 *                 trendline price in the opposite direction.
 *                 Confirmation may happen on the SAME day or the NEXT day
 *                 (cross-day confirmation).
 *   Superseded  — confirmed pivot where the candle later made a new extreme
 *                 beyond `touchPrice` (e.g. new high after a resistance touch).
 *
 * A single candle can simultaneously trigger a HIGH (from resistance) and a LOW
 * (from support) pivot if it enters both zones.
 *
 * Threshold calculation:
 *   recoilThreshold is anchored to the ACTUAL running extreme reached since the
 *   touch (the worst high for resistance, the worst low for support), NOT to the
 *   fixed trendline price.  This means:
 *     • The line is always exactly recoilPct% from the real touch extreme
 *       (fixes "threshold appears at next cent" when recoilPct ≈ zonePct).
 *     • As price extends further into the zone the threshold follows it
 *       (fixes "threshold doesn't move when low gets lower").
 *   Confirmation uses the same per-minute trailing extreme so the logic and
 *   the display line are always consistent.
 */
export class EarlyPivotFilter {

    /**
     * @param days         Full or playback-sliced DayCandle[].
     * @param trendlines   Active trendlines (must have touchZonePct > 0 to detect).
     * @param config       EarlyPivotConfig (recoilPct etc.).
     * @param zonePct      trendlineConfig.touchZonePct / 100 (e.g. 0.005 for 0.5%).
     * @param axisX        Index of the current (last) candle in `days`.
     * @param playbackTime Current playback timestamp (null = no playback; uses all minutes).
     */
    compute(
        days:                 DayCandle[],
        trendlines:           Trendline[],
        config:               EarlyPivotConfig,
        zonePct:              number,
        axisX:                number,
        playbackTime:         number | null,
        prevDayObservedTime:  number | null = null,
    ): EarlyPivot[] {

        if (!config.enabled || days.length === 0 || trendlines.length === 0 || zonePct <= 0) {
            return [];
        }

        const results: EarlyPivot[] = [];

        // Scan the last 2 candles only
        const startIdx = Math.max(0, axisX - 1);
        for (let dayIdx = startIdx; dayIdx <= axisX; dayIdx++) {
            const day = days[dayIdx];
            if (!day) continue;

            // For the active day: filter minutes to those ≤ playbackTime.
            // For the previous day (axisX-1): filter to ≤ prevDayObservedTime so
            // we only see minutes the user actually stepped through.  Without this
            // limit, jumping forward a day would retroactively confirm pivots using
            // minutes from D-1 that were never observed during playback.
            const isActiveDay = dayIdx === axisX;
            let mins: RawCandle[];
            if (isActiveDay && playbackTime !== null) {
                mins = day.minutes.filter(m => m.time <= playbackTime);
            } else if (!isActiveDay && prevDayObservedTime !== null) {
                mins = day.minutes.filter(m => m.time <= prevDayObservedTime);
            } else {
                mins = day.minutes;
            }

            // Guard: skip evaluation when no trading minutes have been observed
            // yet for this day — falling through to OHLC would expose future data.
            // Days with genuinely no minute data (pre-history) are exempt.
            if (isActiveDay && playbackTime !== null && mins.length === 0 && day.minutes.length > 0) continue;
            if (!isActiveDay && prevDayObservedTime !== null && mins.length === 0 && day.minutes.length > 0) continue;

            // Next-day minutes for cross-day confirmation.
            // Only available when the next day is still within the scan window
            // (i.e. dayIdx = axisX-1 and axisX is the active day).
            // If the next day is the active day, honour playbackTime.
            const nextDayIdx = dayIdx + 1;
            let nextDayMins: RawCandle[] = [];
            if (nextDayIdx <= axisX) {
                const nextDay = days[nextDayIdx];
                if (nextDay) {
                    const isNextActive = nextDayIdx === axisX;
                    nextDayMins = isNextActive && playbackTime !== null
                        ? nextDay.minutes.filter(m => m.time <= playbackTime)
                        : nextDay.minutes;
                }
            }

            for (const tl of trendlines) {
                // Only consider lines active at this day index
                if (dayIdx < tl.start_index || dayIdx > tl.end_index) continue;

                const linePrice = tl.slope * dayIdx + tl.intercept;
                if (linePrice <= 0) continue;

                const zoneBot = linePrice * (1 - zonePct);
                const zoneTop = linePrice * (1 + zonePct);

                if (tl.type === 'resistance') {
                    // HIGH: candle entered the resistance zone from below
                    if (day.high >= zoneBot) {
                        const ep = this._evalHigh(
                            day, mins, nextDayMins, dayIdx, tl.id, linePrice,
                            zoneBot, config.recoilPct,
                        );
                        results.push(ep);
                    }
                } else {
                    // LOW: candle entered the support zone from above
                    if (day.low <= zoneTop) {
                        const ep = this._evalLow(
                            day, mins, nextDayMins, dayIdx, tl.id, linePrice,
                            zoneTop, config.recoilPct,
                        );
                        results.push(ep);
                    }
                }
            }
        }

        return results;
    }

    // ─── HIGH (resistance touch) ──────────────────────────────────────────────

    private _evalHigh(
        day:         DayCandle,
        mins:        RawCandle[],
        nextDayMins: RawCandle[],
        dayIdx:      number,
        tlId:        string,
        linePrice:   number,
        zoneBot:     number,
        recoilPct:   number,
    ): EarlyPivot {

        // ── No minute data: use daily OHLC, anchored to day.high ─────────────
        if (mins.length === 0) {
            const recoilThreshold = day.high * (1 - recoilPct / 100);
            const base = this._highBase(day.time, dayIdx, tlId, linePrice, recoilThreshold);
            if (day.low <= recoilThreshold) {
                const superseded = day.high > linePrice;
                return { ...base, touchMinuteTime: day.time, confirmMinuteTime: day.time, status: 'confirmed', confirmedAt: Math.min(day.low, recoilThreshold), superseded };
            }
            return { ...base, touchMinuteTime: day.time, status: 'provisional', superseded: false };
        }

        // Find first minute where high entered the zone
        const touchMinIdx = mins.findIndex(m => m.high >= zoneBot);
        if (touchMinIdx < 0) {
            // Daily bar touched zone but no minute shows it yet — use day.high
            const recoilThreshold = day.high * (1 - recoilPct / 100);
            const base = this._highBase(day.time, dayIdx, tlId, linePrice, recoilThreshold);
            return { ...base, touchMinuteTime: day.time, status: 'provisional', superseded: false };
        }

        const touchMinuteTime = mins[touchMinIdx].time;

        // ── Sequential scan: check confirmation BEFORE updating running high ──
        //
        // At each minute we first check whether the current low has crossed
        // the threshold that was in effect for the running high so far.  Only
        // then do we advance the running high if this minute set a new extreme.
        // This preserves the correct intra-day order of events and prevents
        // a new extreme from retroactively raising the bar for an earlier dip.
        let scanHigh           = mins[touchMinIdx].high;
        let confirmIdx         = -1;
        let confirmedThreshold = 0;

        for (let i = touchMinIdx + 1; i < mins.length; i++) {
            const m         = mins[i];
            const threshold = scanHigh * (1 - recoilPct / 100);
            if (m.low <= threshold) {
                confirmIdx         = i;
                confirmedThreshold = threshold;
                break;
            }
            // Advance running high AFTER the threshold check
            if (m.high > scanHigh) scanHigh = m.high;
        }

        // recoilThreshold for display: always 1% below the running high seen
        // so far.  If confirmed, scanHigh is the high up to the confirmation
        // minute (line is removed anyway); if still provisional, scanHigh is
        // the overall running maximum — exactly what the user sees on screen.
        const recoilThreshold = scanHigh * (1 - recoilPct / 100);
        const base = this._highBase(day.time, dayIdx, tlId, linePrice, recoilThreshold);

        if (confirmIdx < 0) {
            // No same-day confirmation — try cross-day, carrying the running high
            if (nextDayMins.length > 0) {
                let crossScanHigh        = scanHigh;
                let nextConfirmIdx       = -1;
                let nextConfirmedThreshold = 0;

                for (let i = 0; i < nextDayMins.length; i++) {
                    const m         = nextDayMins[i];
                    const threshold = crossScanHigh * (1 - recoilPct / 100);
                    if (m.low <= threshold) {
                        nextConfirmIdx         = i;
                        nextConfirmedThreshold = threshold;
                        break;
                    }
                    if (m.high > crossScanHigh) crossScanHigh = m.high;
                }

                if (nextConfirmIdx >= 0) {
                    const confirmMin           = nextDayMins[nextConfirmIdx];
                    const confirmMinuteTime    = confirmMin.time;
                    const confirmedAt          = Math.min(confirmMin.low, nextConfirmedThreshold);
                    const afterConfirm         = nextDayMins.slice(nextConfirmIdx + 1);
                    const supersededMin        = afterConfirm.find(m => m.high > linePrice);
                    const superseded           = supersededMin !== undefined;
                    const supersededMinuteTime = supersededMin?.time;
                    return { ...base, touchMinuteTime, confirmMinuteTime, supersededMinuteTime, status: 'confirmed', confirmedAt, superseded };
                }
            }
            return { ...base, touchMinuteTime, status: 'provisional', superseded: false };
        }

        const confirmMin           = mins[confirmIdx];
        const confirmMinuteTime    = confirmMin.time;
        const confirmedAt          = Math.min(confirmMin.low, confirmedThreshold);
        const afterConfirm         = mins.slice(confirmIdx + 1);
        const supersededMin        = afterConfirm.find(m => m.high > linePrice);
        const superseded           = supersededMin !== undefined;
        const supersededMinuteTime = supersededMin?.time;

        return { ...base, touchMinuteTime, confirmMinuteTime, supersededMinuteTime, status: 'confirmed', confirmedAt, superseded };
    }

    // ─── LOW (support touch) ──────────────────────────────────────────────────

    private _evalLow(
        day:         DayCandle,
        mins:        RawCandle[],
        nextDayMins: RawCandle[],
        dayIdx:      number,
        tlId:        string,
        linePrice:   number,
        zoneTop:     number,
        recoilPct:   number,
    ): EarlyPivot {

        // ── No minute data: use daily OHLC, anchored to day.low ──────────────
        if (mins.length === 0) {
            const recoilThreshold = day.low * (1 + recoilPct / 100);
            const base = this._lowBase(day.time, dayIdx, tlId, linePrice, recoilThreshold);
            if (day.high >= recoilThreshold) {
                const superseded = day.low < linePrice;
                return { ...base, touchMinuteTime: day.time, confirmMinuteTime: day.time, status: 'confirmed', confirmedAt: Math.max(day.high, recoilThreshold), superseded };
            }
            return { ...base, touchMinuteTime: day.time, status: 'provisional', superseded: false };
        }

        const touchMinIdx = mins.findIndex(m => m.low <= zoneTop);
        if (touchMinIdx < 0) {
            // Daily bar touched zone but no minute shows it yet — use day.low
            const recoilThreshold = day.low * (1 + recoilPct / 100);
            const base = this._lowBase(day.time, dayIdx, tlId, linePrice, recoilThreshold);
            return { ...base, touchMinuteTime: day.time, status: 'provisional', superseded: false };
        }

        const touchMinuteTime = mins[touchMinIdx].time;

        // ── Sequential scan: check confirmation BEFORE updating running low ───
        //
        // At each minute we first check whether the current high has crossed
        // the threshold derived from the running low so far.  Only then do we
        // advance the running low if this minute set a new extreme.
        // If the low gets lower, the threshold follows it downward — the line
        // will update on the next recompute because recoilThreshold is re-derived
        // from scanLow at the end of the (non-confirming) scan.
        let scanLow          = mins[touchMinIdx].low;
        let confirmIdx       = -1;
        let confirmedThreshold = 0;

        for (let i = touchMinIdx + 1; i < mins.length; i++) {
            const m         = mins[i];
            const threshold = scanLow * (1 + recoilPct / 100);
            if (m.high >= threshold) {
                confirmIdx         = i;
                confirmedThreshold = threshold;
                break;
            }
            // Advance running low AFTER the threshold check
            if (m.low < scanLow) scanLow = m.low;
        }

        // recoilThreshold for display: always recoilPct% above the running low
        // seen so far.  scanLow after a non-confirming loop is the overall
        // minimum, so the displayed line tracks exactly recoilPct% above the
        // deepest touch.
        const recoilThreshold = scanLow * (1 + recoilPct / 100);
        const base = this._lowBase(day.time, dayIdx, tlId, linePrice, recoilThreshold);

        if (confirmIdx < 0) {
            // No same-day confirmation — try cross-day, carrying the running low
            if (nextDayMins.length > 0) {
                let crossScanLow         = scanLow;
                let nextConfirmIdx       = -1;
                let nextConfirmedThreshold = 0;

                for (let i = 0; i < nextDayMins.length; i++) {
                    const m         = nextDayMins[i];
                    const threshold = crossScanLow * (1 + recoilPct / 100);
                    if (m.high >= threshold) {
                        nextConfirmIdx         = i;
                        nextConfirmedThreshold = threshold;
                        break;
                    }
                    if (m.low < crossScanLow) crossScanLow = m.low;
                }

                if (nextConfirmIdx >= 0) {
                    const confirmMin           = nextDayMins[nextConfirmIdx];
                    const confirmMinuteTime    = confirmMin.time;
                    const confirmedAt          = Math.max(confirmMin.high, nextConfirmedThreshold);
                    const afterConfirm         = nextDayMins.slice(nextConfirmIdx + 1);
                    const supersededMin        = afterConfirm.find(m => m.low < linePrice);
                    const superseded           = supersededMin !== undefined;
                    const supersededMinuteTime = supersededMin?.time;
                    return { ...base, touchMinuteTime, confirmMinuteTime, supersededMinuteTime, status: 'confirmed', confirmedAt, superseded };
                }
            }
            return { ...base, touchMinuteTime, status: 'provisional', superseded: false };
        }

        const confirmMin           = mins[confirmIdx];
        const confirmMinuteTime    = confirmMin.time;
        const confirmedAt          = Math.max(confirmMin.high, confirmedThreshold);
        const afterConfirm         = mins.slice(confirmIdx + 1);
        const supersededMin        = afterConfirm.find(m => m.low < linePrice);
        const superseded           = supersededMin !== undefined;
        const supersededMinuteTime = supersededMin?.time;

        return { ...base, touchMinuteTime, confirmMinuteTime, supersededMinuteTime, status: 'confirmed', confirmedAt, superseded };
    }

    // ─── Base-object helpers ──────────────────────────────────────────────────

    private _highBase(
        time: number, dayIdx: number, tlId: string, linePrice: number, recoilThreshold: number,
    ): Omit<EarlyPivot, 'status' | 'confirmedAt' | 'superseded' | 'touchMinuteTime' | 'confirmMinuteTime' | 'supersededMinuteTime'> {
        return { time, dayIndex: dayIdx, type: 'high', touchPrice: linePrice, trendlineId: tlId, recoilThreshold };
    }

    private _lowBase(
        time: number, dayIdx: number, tlId: string, linePrice: number, recoilThreshold: number,
    ): Omit<EarlyPivot, 'status' | 'confirmedAt' | 'superseded' | 'touchMinuteTime' | 'confirmMinuteTime' | 'supersededMinuteTime'> {
        return { time, dayIndex: dayIdx, type: 'low', touchPrice: linePrice, trendlineId: tlId, recoilThreshold };
    }
}
