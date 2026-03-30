# Design: Backtest Full Signal Replay

**Date:** 2026-03-05
**Status:** Approved

## Problem

After running a backtest, the chart only shows signal markers for confirmed pivots that **became trades**. Every other confirmed Price Change pivot — fully valid, correctly detected — produces no visible signal. This makes the backtest chart inconsistent with live playback, where every confirmed pivot generates a signal at the exact confirmation price/time.

## Goal

After a backtest completes, the chart must show **every** signal that would have appeared during minute-by-minute live playback — one signal marker per confirmed Price Change (recoil) pivot, at the exact confirmation price and confirmation minute time. Trades and their exit markers remain unchanged.

## Scope

**In scope:**
- Price Change (EarlyPivot / recoil) pivot signals — all confirmed pivots get a chart marker
- `BacktestResult` extended to carry all entry signals (not just trade entries)
- `setBacktestSignals()` updated to render all entry signals
- `SignalOverlay` renders the full set identically to existing trade-entry arrows

**Out of scope:**
- Window Size pivot signals (trendlines only — no change)
- Trade simulation logic — no changes to when/how trades open
- Live playback signal generation — no changes
- Breakout signals — unchanged (still gated by `useBreakoutDetection`)

## Architecture

### Data Flow

```
runBacktestSimulation()
  ├── Day loop: EarlyPivotFilter confirms pivots → confirmedEarlyPivots[]
  ├── Maps confirmedEarlyPivots → pivotEntrySignals[] (ALL, pre-trade-filter)
  ├── _simulateTrades(pivotEntrySignals) → trades[] (subset that became trades)
  └── Returns BacktestResult {
        trades,
        equityCurve,
        stats,
        allEntrySignals: pivotEntrySignals  ← NEW
      }

BottomBar.runBacktest()
  └── Calls setBacktestSignals(result.trades, result.allEntrySignals)  ← UPDATED

TerminalContext.setBacktestSignals(trades, allEntrySignals)
  ├── For each signal in allEntrySignals → Signal { kind: long/short, price, time, dayIndex }
  └── For each trade (closed) → Signal { kind: win/loss, price, time, dayIndex }

SignalOverlay renders all Signal[] — no changes needed
```

### Files Changed

| File | Change |
|------|--------|
| `backtestEngine.ts` | Add `allEntrySignals: EntrySignal[]` to `BacktestResult`; populate from `pivotEntrySignals` before `_simulateTrades` |
| `TerminalContext.tsx` | Update `setBacktestSignals()` signature to also accept `allEntrySignals`; render all of them |
| `BottomBar.tsx` | Pass `result.allEntrySignals` to `setBacktestSignals()` |

### No Changes Needed

- `Signal.ts` — existing `SignalKind` (`long`/`short`/`win`/`loss`) covers all cases
- `SignalOverlay.tsx` — already renders `Signal[]` correctly
- `EarlyPivotFilter.ts` — unchanged
- Trade simulation — unchanged

## Key Invariants

1. Config is always followed — `signalConfig.usePivotConfirmation` still gates ALL pivot signals
2. Signal price = `ep.confirmedAt` (exact recoil price)
3. Signal time = `ep.confirmMinuteTime ?? ep.time` (exact confirmation minute)
4. Signal direction: HIGH pivot → `short`, LOW pivot → `long`
5. Breakout entry signals pass through unchanged alongside pivot signals
