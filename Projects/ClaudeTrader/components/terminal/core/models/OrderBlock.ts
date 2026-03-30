// ─── Order Block Model ─────────────────────────────────────────────────────────

/**
 * A horizontal support/resistance level created at each confirmed window-size
 * pivot.  Extends forward from the pivot bar to the current bar.
 *
 * Modelled internally as a degenerate Trendline (slope = 0, intercept = price)
 * so EarlyPivotFilter can be reused directly for touch-zone detection.
 *
 * id: `ob|${pivotDayIndex}|${type}`
 *
 * When merging is enabled, adjacent OBs of the same type whose levels are
 * within mergeGapPct of each other are collapsed into a single wider zone.
 * mergedCount > 1 indicates a merged zone; zoneHigh/zoneLow span all members.
 */
export interface OrderBlock {
    id:            string;
    type:          'resistance' | 'support';
    price:         number;       // pivot price of representative level
    pivotDayIndex: number;       // earliest pivot day index in the cluster
    endIndex:      number;       // current axisX — zone extends to live bar
    /** (confirmedTouches × 100) + longevity — mirrors trendline scoring */
    score:         number;
    /** Top of display zone (pre-computed from zoneHigh = max(price) × (1 + zonePct)) */
    zoneHigh:      number;
    /** Bottom of display zone (pre-computed from zoneLow = min(price) × (1 − zonePct)) */
    zoneLow:       number;
    /** 1 = individual level; >1 = merged cluster */
    mergedCount:   number;
}

// ─── Config ───────────────────────────────────────────────────────────────────

export interface OrderBlockConfig {
    enabled:           boolean;
    touchZonePct:      number;   // 0–5 (percent band around the level for entry detection)
    recoilPct:         number;   // 0.1–5 (percent recoil needed to confirm entry)
    /** How far price must close through the OB level to invalidate it. */
    breakoutTolerance: number;   // 0–5 (percent; 0 = at the level exactly)
    /** Hide OBs whose level is more than this % away from current price. 0 = off. */
    proximity:         number;   // 0–1 (fraction of current price; 0 = off)
    showResistance:    boolean;
    showSupport:       boolean;
    /** Merge OB levels whose prices are within mergeGapPct of each other into one zone. */
    mergeEnabled:      boolean;
    /** Percent distance between two levels that triggers a merge (0 = no auto-merge). */
    mergeGapPct:       number;   // 0–5
}

export const DEFAULT_ORDER_BLOCK_CONFIG: OrderBlockConfig = {
    enabled:           false,
    touchZonePct:      0.5,
    recoilPct:         0.5,
    breakoutTolerance: 0.5,
    proximity:         0.1,
    showResistance:    true,
    showSupport:       true,
    mergeEnabled:      true,
    mergeGapPct:       1.0,
};
