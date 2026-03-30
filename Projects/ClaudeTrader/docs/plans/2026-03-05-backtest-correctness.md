# Backtest Correctness & Visualization Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix four outstanding backtest issues: (1) trendline display filters not applied to backtest trading logic, (2) backtest data not wiped when timeline is shuffled, (3) breakout-detection signals missing from backtest chart, (4) confirm price-change pivot entry signals appear at correct time/price.

**Architecture:** All fixes are confined to the client-side TypeScript/React stack. No API changes needed. The backtest engine (`backtestEngine.ts`) runs a pure simulation; `BottomBar.tsx` drives it; `TerminalContext.tsx` converts trades → chart signals; `SignalOverlay` renders them.

**Tech Stack:** TypeScript, React 18, Next.js App Router, lightweight-charts, Axios.

---

## Key findings (read these before touching code)

### Finding 1 — Display filters already live in `TrendlineConfig`
`TrendlineFilter._applyDisplayFilters()` reads from `TrendlineConfig`:
- `useClosestFilter / closestFilterCount / useMostValuableFilter / mostValuableCount`

`BottomBar.tsx` already passes `state.trendlineConfig` into `runBacktestSimulation`, which passes it to `trendlineFilter.compute()`. So **display filters ARE being applied in the backtest as long as they are set in `TrendlineConfig`**.

`StrategyConfig` also has `useClosestFilter / closestFilterCount / useMostValuableFilter / mostValuableFilterCount` (note: different field suffix). These are NOT read by `TrendlineFilter` and may be dead code or used by a separate UI panel.

**Before Task 1 — verify in the right sidebar which config controls the UI display-filter toggles.** If the sidebar writes to `StrategyConfig`, Task 1 must apply those values on top of the raw trendlines in the backtest. If it writes to `TrendlineConfig`, Task 1 is a no-op (already works).

### Finding 2 — `_simulateTrades` does not use `EntrySignal.dayIndex`
The `dayIndex` stored on `EntrySignal` objects is the **touch day** (`ep.dayIndex`). However `_simulateTrades()` only uses `.kind`, `.price`, and `.time`. Chart rendering of entry signals uses `trade.entryTime` (which equals `ep.confirmMinuteTime`) from which `TerminalContext.setBacktestSignals` recomputes `dayIndex` correctly. So **there is no visual bug here** — the entry marker already lands on the confirmation minute's day.

### Finding 3 — Breakout detection disabled in backtest
`BottomBar.tsx` hardcodes `{ enabled: true, usePivotConfirmation: true, useBreakoutDetection: false }` when calling `runBacktestSimulation`. To enable backtest breakout signals, this should be `useBreakoutDetection: state.signalConfig.useBreakoutDetection` (or simply `true`). The `backtestEngine` does not currently implement breakout detection — it only generates entry signals from `confirmedEarlyPivots`. Breakout detection requires an additional pass through `brokenTrendlineIds` per day.

---

## Task 1 — Verify & connect trendline display filters

**Files:**
- Read: `C:\ClaudeTrader\components\terminal\panels\RightSidebar.tsx` (or wherever strategy/trendline settings are rendered)
- Possibly modify: `C:\ClaudeTrader\components\terminal\core\backtest\backtestEngine.ts`
- Possibly modify: `C:\ClaudeTrader\components\terminal\panels\BottomBar.tsx`

**Step 1: Find which config the UI trendline display-filter toggles write to**

Search the codebase:
```bash
grep -r "useClosestFilter\|useMostValuableFilter" C:/ClaudeTrader/components --include="*.tsx" --include="*.ts" -l
```

Then read the settings panel component. You're looking for where `setStrategyConfig` or `setTrendlineConfig` is called for these fields.

**Step 2: If UI writes to `StrategyConfig` (not `TrendlineConfig`)**

The backtest must apply `strategyConfig.useClosestFilter` / `strategyConfig.closestFilterCount` / `strategyConfig.useMostValuableFilter` / `strategyConfig.mostValuableFilterCount` as a post-processing filter on trendlines AFTER `trendlineFilter.compute()`.

Add a helper in `backtestEngine.ts`:

```typescript
/**
 * Apply the same closest/most-valuable display filters that the UI shows.
 * Mirrors TrendlineFilter._applyDisplayFilters but reads from StrategyConfig.
 */
function _applyStrategyDisplayFilters(
    lines:    Trendline[],
    curPrice: number,
    strat:    StrategyConfig,
): Trendline[] {
    if (!strat.useClosestFilter && !strat.useMostValuableFilter) return lines;
    if (curPrice <= 0) return lines;

    const withDist = lines.map(l => ({
        line:    l,
        distPct: Math.abs(l.end_price - curPrice) / curPrice,
    }));

    const supports    = withDist.filter(x => x.line.type === 'support');
    const resistances = withDist.filter(x => x.line.type === 'resistance');

    const picked = new Set<string>();
    const result: Trendline[] = [];
    const add = (l: Trendline) => { if (!picked.has(l.id)) { picked.add(l.id); result.push(l); } };

    if (strat.useClosestFilter) {
        const n = Math.max(1, strat.closestFilterCount);
        supports.slice().sort((a, b) => a.distPct - b.distPct).slice(0, n).forEach(x => add(x.line));
        resistances.slice().sort((a, b) => a.distPct - b.distPct).slice(0, n).forEach(x => add(x.line));
    }

    if (strat.useMostValuableFilter) {
        const n = Math.max(1, strat.mostValuableFilterCount);
        supports.slice().sort((a, b) => b.line.score - a.line.score).slice(0, n).forEach(x => add(x.line));
        resistances.slice().sort((a, b) => b.line.score - a.line.score).slice(0, n).forEach(x => add(x.line));
    }

    return result;
}
```

Apply this after `trendlineFilter.compute()` in the day loop:
```typescript
let trendlines = trendlineFilter.compute(slicedDays, slicedPivots, i, trendlineConfig);
// Apply strategy display filters so backtest only trades visible trendlines
const curPrice = slicedDays[i].close;
trendlines = _applyStrategyDisplayFilters(trendlines, curPrice, strategyConfig);
trendlinesByDay.set(i, trendlines);
```

Also update `runBacktestSimulation` signature to accept `strategyConfig` (it already receives it as `strat` in `_simulateTrades` — just pass it down).

**Step 3: If UI writes to `TrendlineConfig` (not `StrategyConfig`)**

No code change needed. The backtest already applies them. Document this finding.

**Step 4: Commit**
```bash
git add components/terminal/core/backtest/backtestEngine.ts
git commit -m "fix: apply trendline display filters in backtest simulation"
```

---

## Task 2 — Wipe backtest data on timeline shuffle / config change

**Files:**
- Modify: `C:\ClaudeTrader\components\terminal\panels\BottomBar.tsx`

**Step 1: Add a clearing effect in `BottomBar`**

Inside `BottomBar()`, after the existing state declarations, add:

```typescript
// Clear backtest results whenever the underlying dataset changes
useEffect(() => {
    setTrades([]);
    setEquityCurve([]);
    setStats(null);
    setBacktestError(null);
    setBacktestSignals([]);
}, [
    state.randomSeed,
    state.config.symbol,
    state.config.mode,
    state.config.startDate,
    state.config.endDate,
    state.config.years,
    state.config.months,
    state.config.days,
    setBacktestSignals,
]);
```

**Step 2: Test manually**
1. Run a backtest → signals appear on chart
2. Click "Shuffle" / "Regenerate" → signals and table should clear immediately
3. Run backtest again → signals reappear

**Step 3: Commit**
```bash
git add components/terminal/panels/BottomBar.tsx
git commit -m "fix: clear backtest results when timeline is shuffled or config changes"
```

---

## Task 3 — Implement breakout entry signals in backtest

**Files:**
- Modify: `C:\ClaudeTrader\components\terminal\core\backtest\backtestEngine.ts`
- Modify: `C:\ClaudeTrader\components\terminal\panels\BottomBar.tsx`

**Step 1: Read `EarlyPivotFilter.ts` and understand what data is available per day**

The day loop in `backtestEngine.ts` already tracks `trendlinesByDay`. For breakout detection, we need to know which trendlines were REMOVED between step `i-1` and step `i`.

**Step 2: Track broken trendlines per day in the backtest loop**

In `runBacktestSimulation`, after the trendlinesByDay accumulation, add broken trendline tracking:

```typescript
// Track trendlines per day (already exists)
const trendlinesByDay = new Map<number, Trendline[]>();
// NEW: track broken trendlines per day
const brokenTrendlinesByDay = new Map<number, Map<string, Trendline>>();

let prevTrendlineIds = new Set<string>();

for (let i = minDays; i < days.length; i++) {
    // ... existing code ...
    const trendlines = trendlineFilter.compute(slicedDays, slicedPivots, i, trendlineConfig);
    // ... apply display filters ...
    trendlinesByDay.set(i, trendlines);

    // NEW: detect broken lines (were in prev set, absent from new set)
    const newIds = new Set(trendlines.map(l => l.id));
    const brokenMap = new Map<string, Trendline>();
    const zoneFrac  = zonePct;
    const curBar    = slicedDays[i];

    if (curBar && zoneFrac > 0) {
        const prevTrendlines = trendlinesByDay.get(i - 1) ?? [];
        for (const prev of prevTrendlines) {
            if (newIds.has(prev.id)) continue;
            const lineY = prev.slope * i + prev.intercept;
            if (lineY <= 0) continue;
            const isBreakout = prev.type === 'resistance'
                ? curBar.close > lineY * (1 + zoneFrac)
                : curBar.close < lineY * (1 - zoneFrac);
            if (isBreakout) brokenMap.set(prev.id, prev);
        }
    }
    brokenTrendlinesByDay.set(i, brokenMap);
    prevTrendlineIds = newIds;

    // ... existing earlyPivot accumulation code ...
}
```

**Step 3: Generate breakout entry signals from `brokenTrendlinesByDay`**

After the entry signals from confirmed pivots are built, add breakout signals:

```typescript
// Existing: signals from confirmed early pivots
const pivotEntrySignals: EntrySignal[] = confirmedEarlyPivots
    .filter(ep => ep.confirmedAt !== undefined)
    .map(ep => ({ ... }));

// NEW: signals from trendline breakouts
const breakoutEntrySignals: EntrySignal[] = [];
if (signalConfig.useBreakoutDetection) {
    for (let di = minDays; di < days.length; di++) {
        const brokenMap = brokenTrendlinesByDay.get(di);
        if (!brokenMap || brokenMap.size === 0) continue;
        const curBar    = days[di];
        const zoneFrac  = zonePct;

        for (const [, tl] of brokenMap) {
            const lineY = tl.slope * di + tl.intercept;
            if (lineY <= 0) continue;

            if (tl.type === 'resistance') {
                // Resistance breakout → Long
                const threshold = lineY * (1 + zoneFrac);
                const breakMin  = curBar.minutes.find(m => m.close > threshold);
                if (breakMin) {
                    breakoutEntrySignals.push({
                        kind:     'long',
                        price:    breakMin.close,
                        time:     breakMin.time,
                        dayIndex: di,
                    });
                }
            } else {
                // Support breakdown → Short
                const threshold = lineY * (1 - zoneFrac);
                const breakMin  = curBar.minutes.find(m => m.close < threshold);
                if (breakMin) {
                    breakoutEntrySignals.push({
                        kind:     'short',
                        price:    breakMin.close,
                        time:     breakMin.time,
                        dayIndex: di,
                    });
                }
            }
        }
    }
}

const entrySignals: EntrySignal[] = [
    ...pivotEntrySignals,
    ...breakoutEntrySignals,
].sort((a, b) => a.time - b.time);
```

**Step 4: Update `BottomBar` to pass `signalConfig.useBreakoutDetection`**

Change:
```typescript
{ enabled: true, usePivotConfirmation: true, useBreakoutDetection: false }
```
To:
```typescript
{ enabled: true, usePivotConfirmation: true, useBreakoutDetection: state.signalConfig?.useBreakoutDetection ?? false }
```
*(Or simply `true` if you always want breakouts — confirm with user.)*

**Step 5: Verify exit signals for zone touches already work**

The `BacktestTrade.exitReason: 'zone_exit'` is already tracked, and `TerminalContext.setBacktestSignals` maps it to a `Signal` with `source: 'zone_exit'`. Confirm `SignalOverlay` renders `source === 'zone_exit'` correctly (it renders win/loss based on `kind`, so zone exits are visualized).

**Step 6: Commit**
```bash
git add components/terminal/core/backtest/backtestEngine.ts components/terminal/panels/BottomBar.tsx
git commit -m "feat: add breakout entry signals to backtest simulation"
```

---

## Task 4 — Verify price-change pivot entry signal timing (diagnostic)

**Files:**
- Read: `C:\ClaudeTrader\components\terminal\core\filters\EarlyPivotFilter.ts`

**Step 1: Trace the flow for a Price Change pivot confirmation**

In `EarlyPivotFilter.compute()`, look for where:
- `confirmedAt` is set (should be the `recoilThreshold` price)
- `confirmMinuteTime` is set (should be the exact minute when price crossed the recoil threshold)

If `confirmMinuteTime` is null on a confirmed pivot, the entry signal falls back to `ep.time` (the touch day timestamp = midnight). This would place the entry at midnight, not at the actual confirmation minute.

**Step 2: Check for null `confirmMinuteTime` cases**

In `backtestEngine.ts`, the entry signal time:
```typescript
time: ep.confirmMinuteTime ?? ep.time,
```

If `ep.confirmMinuteTime` is frequently null for price-change confirmations, signals will have incorrect timing. Add a console log temporarily:
```typescript
if (ep.confirmMinuteTime === null || ep.confirmMinuteTime === undefined) {
    console.warn('[BT] Pivot missing confirmMinuteTime:', ep);
}
```

**Step 3: Fix if `confirmMinuteTime` is null**

Look at `EarlyPivotFilter` to see when `confirmMinuteTime` is not set. If price-change confirmations don't set `confirmMinuteTime`, add the logic there.

**Step 4: Commit if changes were needed**
```bash
git add components/terminal/core/filters/EarlyPivotFilter.ts
git commit -m "fix: ensure confirmMinuteTime is set for price-change pivot confirmations"
```

---

## Task 5 — End-to-end smoke test

**Step 1: Start dev server**
```bash
npm run dev
```

**Step 2: Verify display filter behavior**
1. Enable "Closest" display filter for trendlines in the UI settings panel (set count = 2)
2. Run a backtest
3. Verify the backtest table shows fewer trades (only against the 2 closest trendlines per side)
4. If trades count does NOT decrease, the display filter is not being applied → re-check Task 1

**Step 3: Verify shuffle wipe**
1. Run a backtest → signals on chart, trades in table
2. Click the shuffle/regenerate button in the top bar
3. Expected: signals disappear from chart, table clears immediately

**Step 4: Verify zone exit signals on chart**
1. Run a backtest with zone exit enabled (default)
2. Confirm chart shows both entry markers (long/short arrows) AND exit markers (win/loss markers) for zone-exit trades

**Step 5: Verify breakout signals (if Task 3 completed)**
1. Enable "Breakout Detection" in the Signals settings
2. Run a backtest
3. Confirm breakout entry signals appear on the chart (different color/icon from pivot confirmation signals)
