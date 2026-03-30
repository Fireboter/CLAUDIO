# Backtest Full Signal Replay Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** After running a backtest, the chart shows a signal marker for every confirmed Price Change (EarlyPivot/recoil) pivot — not just those that became trades.

**Architecture:** Extend `BacktestResult` with `allEntrySignals: EntrySignal[]` (the full pivot-signal list before trade filtering). `TerminalContext.setBacktestSignals()` uses this list to generate chart markers for all confirmed pivots, and the existing trade array for exit markers only. No changes to trade simulation or live playback.

**Tech Stack:** TypeScript, React, lightweight-charts. No new libraries needed.

---

## Task 1: Export `EntrySignal` from `backtestEngine.ts` and add `allEntrySignals` to `BacktestResult`

**Files:**
- Modify: `components/terminal/core/backtest/backtestEngine.ts`

### Step 1: Export the `EntrySignal` interface

Currently at line 63 it is `interface EntrySignal { ... }` (unexported). Change it to `export`:

```typescript
// Before (line 63):
interface EntrySignal {

// After:
export interface EntrySignal {
```

### Step 2: Add `allEntrySignals` field to `BacktestResult`

Currently (lines 57–61):
```typescript
export interface BacktestResult {
    trades:       BacktestTrade[];
    equityCurve:  { time: Time; value: number }[];
    stats:        BacktestStats;
}
```

Change to:
```typescript
export interface BacktestResult {
    trades:          BacktestTrade[];
    equityCurve:     { time: Time; value: number }[];
    stats:           BacktestStats;
    allEntrySignals: EntrySignal[];
}
```

### Step 3: Update `_empty()` to include the new field

Currently (line ~487):
```typescript
function _empty(): BacktestResult {
    return { trades: [], equityCurve: [], stats: _emptyStats() };
}
```

Change to:
```typescript
function _empty(): BacktestResult {
    return { trades: [], equityCurve: [], stats: _emptyStats(), allEntrySignals: [] };
}
```

### Step 4: Populate `allEntrySignals` before `_simulateTrades`

Currently the final section of `runBacktestSimulation` (lines ~194–256) ends with:
```typescript
    const entrySignals: EntrySignal[] = [
        ...pivotEntrySignals,
        ...breakoutEntrySignals,
    ].sort((a, b) => a.time - b.time);

    const trades = _simulateTrades(entrySignals, days, trendlinesByDay, tradeAxisConfig, strategyConfig, zonePct);
    return _buildResult(trades, strategyConfig, days);
```

Change to:
```typescript
    const entrySignals: EntrySignal[] = [
        ...pivotEntrySignals,
        ...breakoutEntrySignals,
    ].sort((a, b) => a.time - b.time);

    // Keep the full signal list BEFORE trade filtering for chart overlay
    const allEntrySignals = entrySignals;

    const trades = _simulateTrades(entrySignals, days, trendlinesByDay, tradeAxisConfig, strategyConfig, zonePct);
    return _buildResult(trades, strategyConfig, days, allEntrySignals);
```

### Step 5: Update `_buildResult` signature to accept and forward `allEntrySignals`

Currently (line ~406):
```typescript
function _buildResult(
    trades:         BacktestTrade[],
    strategyConfig: StrategyConfig,
    days:           DayCandle[],
): BacktestResult {
```

Change to:
```typescript
function _buildResult(
    trades:          BacktestTrade[],
    strategyConfig:  StrategyConfig,
    days:            DayCandle[],
    allEntrySignals: EntrySignal[] = [],
): BacktestResult {
```

And update both return statements inside `_buildResult` to include `allEntrySignals`:

First return (early exit when no closed trades, line ~427):
```typescript
        return {
            trades,
            equityCurve: [{ time: startTime, value: initialEquity }],
            stats: _emptyStats(),
            allEntrySignals,
        };
```

Final return (line ~476):
```typescript
    return {
        trades,
        equityCurve,
        stats: { ... },
        allEntrySignals,
    };
```

### Step 6: Verify TypeScript compiles

Run: `cd C:/ClaudeTrader && npx tsc --noEmit 2>&1 | head -30`

Expected: no errors related to `EntrySignal` or `BacktestResult`.

### Step 7: Commit

```bash
git -C C:/ClaudeTrader add components/terminal/core/backtest/backtestEngine.ts
git -C C:/ClaudeTrader commit -m "feat: export EntrySignal and add allEntrySignals to BacktestResult"
```

---

## Task 2: Update `TerminalContext.setBacktestSignals()` to use `allEntrySignals`

**Files:**
- Modify: `components/terminal/TerminalContext.tsx`

### Step 1: Add import for `EntrySignal`

Find the existing import line for `BacktestTrade` (near top of file):
```typescript
import type { BacktestTrade } from './core/backtest/backtestEngine';
```

Change to:
```typescript
import type { BacktestTrade, EntrySignal } from './core/backtest/backtestEngine';
```

### Step 2: Update `setBacktestSignals` signature

Currently (line 144):
```typescript
    const setBacktestSignals = useCallback((trades: BacktestTrade[]) => {
```

Change to:
```typescript
    const setBacktestSignals = useCallback((trades: BacktestTrade[], allEntrySignals: EntrySignal[] = []) => {
```

### Step 3: Replace entry signal generation with `allEntrySignals`-based rendering

Currently the callback (lines 144–181) builds entry signals from `trades`. Replace the entry-signal portion so it uses `allEntrySignals` instead, but keep the exit-signal portion unchanged.

Full updated `setBacktestSignals`:
```typescript
    const setBacktestSignals = useCallback((trades: BacktestTrade[], allEntrySignals: EntrySignal[] = []) => {
        const chartDays = terminalStore.marketData.days;
        const dayTsToIdx = new Map<number, number>();
        for (let i = 0; i < chartDays.length; i++) {
            dayTsToIdx.set(chartDays[i].time, i);
        }

        const signals: Signal[] = [];

        // Entry signals — one per confirmed pivot (all of them, not just trade entries)
        for (const es of allEntrySignals) {
            const dayTs  = Math.floor(es.time / 86400) * 86400;
            const dayIdx = dayTsToIdx.get(dayTs);
            if (dayIdx !== undefined) {
                signals.push({
                    id:       `bt_signal_${es.time}_${es.kind}`,
                    kind:     es.kind,
                    source:   es.entryReason,
                    price:    es.price,
                    time:     es.time,
                    dayIndex: dayIdx,
                });
            }
        }

        // Exit signals — from actual trades only
        for (const trade of trades) {
            if (trade.status === 'closed' && trade.exitTime !== undefined && trade.exitPrice !== undefined) {
                const exitDayTs  = Math.floor(trade.exitTime / 86400) * 86400;
                const exitDayIdx = dayTsToIdx.get(exitDayTs);
                if (exitDayIdx !== undefined) {
                    signals.push({
                        id:       `bt_exit_${trade.id}`,
                        kind:     (trade.pnl ?? 0) >= 0 ? 'win' : 'loss',
                        source:   trade.exitReason ?? 'zone_exit',
                        price:    trade.exitPrice,
                        time:     trade.exitTime,
                        dayIndex: exitDayIdx,
                    });
                }
            }
        }

        setBacktestSignalsState(signals);
    }, []);
```

### Step 4: Verify TypeScript compiles

Run: `cd C:/ClaudeTrader && npx tsc --noEmit 2>&1 | head -30`

Expected: no type errors.

### Step 5: Commit

```bash
git -C C:/ClaudeTrader add components/terminal/TerminalContext.tsx
git -C C:/ClaudeTrader commit -m "feat: render all confirmed pivot signals (not just trade entries) on backtest chart"
```

---

## Task 3: Update `BottomBar.tsx` to pass `allEntrySignals`

**Files:**
- Modify: `components/terminal/panels/BottomBar.tsx`

### Step 1: Pass `allEntrySignals` in the success path

Currently (line 207):
```typescript
            setBacktestSignals(result.trades);
```

Change to:
```typescript
            setBacktestSignals(result.trades, result.allEntrySignals);
```

### Step 2: The error/reset path already passes `[]` implicitly

The error path (line 215) calls `setBacktestSignals([])` — with the new default parameter `allEntrySignals = []`, this still works correctly. No change needed here.

### Step 3: Remove the "No trades generated" guard that blocks signal display

Currently (lines 198–202):
```typescript
            if (result.stats.total_trades === 0) {
                throw new Error(
                    'No trades generated. Check that Touch Zone is enabled...',
                );
            }
```

This guard was correct when signals = trades, but now signals come from `allEntrySignals`. If there are confirmed pivots but no trades (e.g., zone exit disabled), the user should still see the signals.

**Update the guard** to only throw if there are ALSO no entry signals:
```typescript
            if (result.stats.total_trades === 0 && result.allEntrySignals.length === 0) {
                throw new Error(
                    'No signals generated. Check that Touch Zone is enabled in Trendline settings and that the date range has enough trendline touches.',
                );
            }
```

### Step 4: Verify TypeScript compiles

Run: `cd C:/ClaudeTrader && npx tsc --noEmit 2>&1 | head -30`

Expected: no errors.

### Step 5: Commit

```bash
git -C C:/ClaudeTrader add components/terminal/panels/BottomBar.tsx
git -C C:/ClaudeTrader commit -m "feat: pass allEntrySignals to setBacktestSignals; update no-signal guard"
```

---

## Task 4: Manual verification

### Step 1: Start both dev servers if not running

```bash
# In two separate terminals (or use launch.json):
# Terminal 1:
cd C:/ClaudeTrader && npm run dev
# Terminal 2:
cd C:/ClaudeTrader && python -m api.server
```

### Step 2: Open http://localhost:9000

Load a symbol (e.g. GLD) with a 1-year random range.

### Step 3: Run the backtest

Click "Run Backtest".

**Expected result:**
- Trades table shows N trades (same count as before)
- Chart shows signal arrows for ALL confirmed pivots — significantly MORE arrows than just the trade entries
- Entry arrows (↑ long, ↓ short) appear at the exact recoil price/time
- Exit markers ($ and ✕) appear at each trade close price

### Step 4: Cross-check signal count vs trade count

- Signal arrow count should be ≥ trade count (more signals than trades)
- Previously signals matched trade count — now they should be more
- Exit markers should still match trade count exactly

### Step 5: Final commit if any cleanup needed

```bash
git -C C:/ClaudeTrader log --oneline -5
```
