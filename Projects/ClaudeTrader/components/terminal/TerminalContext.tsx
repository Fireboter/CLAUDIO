"use client";

import React, { createContext, useContext, useEffect, useState, useMemo, useCallback } from 'react';
import { terminalStore } from './core/store/TerminalStore';
import type { TerminalConfig, IndicatorConfig, TradeAxisConfig, StrategyConfig, SelectedItemType, SignalConfig } from './core/store/types';
import type { TrendlineConfig } from './core/models/Trendline';
import type { EarlyPivotConfig } from './core/models/EarlyPivot';
import type { OrderBlockConfig } from './core/models/OrderBlock';
import type { Signal } from './core/models/Signal';
import type { BacktestTrade, EntrySignal } from './core/backtest/backtestEngine';

// ─── Context ────────────────────────────────────────────────────

const TerminalContext = createContext<ReturnType<typeof buildApi> | null>(null);

export interface HoveredBacktestEntry {
    entryTime:   number;
    exitTradeId: string;
}

function buildApi(
    tick:                     number,
    backtestSignals:          Signal[],
    setBacktestSignals:       (trades: BacktestTrade[], allEntrySignals?: EntrySignal[]) => void,
    hoveredBacktestEntry:     HoveredBacktestEntry | null,
    setHoveredBacktestEntry:  (v: HoveredBacktestEntry | null) => void,
) {
    const layout = terminalStore.layout;
    const fm     = terminalStore.filterManager;
    const pb     = terminalStore.playbackManager;
    const pm     = terminalStore.pivotManager;
    const tm     = terminalStore.trendlineManager;
    const epm    = terminalStore.earlyPivotManager;
    const obm    = terminalStore.orderBlockManager;
    const sm     = terminalStore.signalManager;

    // Stable method references (closures over singletons — references never change)
    const methods = {
        setConfig:           (c: Partial<TerminalConfig>) => layout.setConfig(c),
        setPreviewConfig:    (c: Partial<TerminalConfig> | null) => layout.setPreviewConfig(c),
        setIndicatorConfig:  (c: Partial<IndicatorConfig>) => layout.setIndicatorConfig(c),
        setTradeAxisConfig:  (c: Partial<TradeAxisConfig>) => layout.setTradeAxisConfig(c),
        setStrategyConfig:   (c: Partial<StrategyConfig>) => layout.setStrategyConfig(c),
        setSelectedItem:     (t: SelectedItemType, k: string | null) => layout.setSelectedItem(t, k),
        toggleIndicator:     (k: string) => layout.toggleIndicator(k),
        setTradeAxisPivots:  (p: unknown[]) => fm.setTradeAxisPivots(p),
        setTradeAxisData:    (d: unknown[]) => fm.setTradeAxisData(d),
        setHoveredTime:      (t: number | null) => layout.setHoveredTime(t),
        setActiveAsset:      (a: unknown) => layout.setActiveAsset(a),
        playTimeline:        () => layout.playTimeline(),
        pauseTimeline:       () => layout.pauseTimeline(),
        stopTimeline:        () => layout.stopTimeline(),
        regenerateRandom:    () => layout.regenerateRandomSeed(),
        setPlaybackTime:     (t: number | null) => layout.setPlaybackTime(t),
        setPlaybackSpeed:    (s: number) => layout.setPlaybackSpeed(s),
        setIndicatorAreaRatio: (r: number) => layout.setIndicatorAreaRatio(r),
        setLeftSidebarWidth:   (w: number) => layout.setLeftSidebarWidth(w),
        setRightSidebarWidth:  (w: number) => layout.setRightSidebarWidth(w),
        setTopBarHeight:       (h: number) => layout.setTopBarHeight(h),
        setBottomBarHeight:    (h: number) => layout.setBottomBarHeight(h),
        toggleLeftSidebar:  () => layout.toggleLeftSidebar(),
        toggleRightSidebar: () => layout.toggleRightSidebar(),
        toggleTopBar:       () => layout.toggleTopBar(),
        toggleBottomBar:    () => layout.toggleBottomBar(),
        setVisibleTimeRange: (r: { from: number; to: number } | null) => layout.setVisibleTimeRange(r),
        setDataTimeRange: (r: { from: number; to: number; boundaryTime?: number } | null) => {
            layout.dataTimeRange = r;
            layout.notify();
        },
        PlaybackNextMinute: () => pb.nextMinute(),
        PlaybackPrevMinute: () => pb.prevMinute(),
        PlaybackNextDay:    () => pb.nextDay(),
        PlaybackPrevDay:    () => pb.prevDay(),

        // ─── Pivot controls ───────────────────────────────────────────────────
        setPivotsEnabled:   (on: boolean) => pm.setEnabled(on),
        setPivotWindowSize: (w: number)   => pm.setWindowSize(w),

        // ─── Trendline controls ───────────────────────────────────────────────
        setTrendlinesEnabled: (on: boolean)                  => tm.setEnabled(on),
        setTrendlineConfig:   (p: Partial<TrendlineConfig>)  => tm.setConfig(p),

        // ─── Early pivot controls ─────────────────────────────────────────────
        setEarlyPivotConfig: (p: Partial<EarlyPivotConfig>) => epm.setConfig(p),

        // ─── Order block controls ─────────────────────────────────────────────
        setOrderBlockConfig: (p: Partial<OrderBlockConfig>) => obm.setConfig(p),

        // ─── Signal controls ──────────────────────────────────────────────────
        setSignalConfig:     (p: Partial<SignalConfig>) => sm.setConfig(p),
        setBacktestSignals,
        setHoveredBacktestEntry,
    };

    return {
        store: terminalStore,
        state: {
            ...layout,
            enabledIndicators: layout.enabledIndicators,
            tradeAxisData:     fm.tradeAxisData,
            tradeAxisPivots:   fm.tradeAxisPivots,

            // ── Pivot state ──────────────────────────────────────────────────
            pivots:          pm.pivots,
            pivotsEnabled:   pm.enabled,
            pivotWindowSize: pm.windowSize,

            // ── Trendline state ──────────────────────────────────────────────
            trendlines:        tm.trendlines,
            trendlinesEnabled: tm.enabled,
            trendlineConfig:   tm.config,

            // ── Early pivot state ────────────────────────────────────────────
            earlyPivots:           epm.provisionalPivots,
            earlyConfirmedPivots:  epm.confirmedEarlyPivots,
            earlyPivotConfig:      epm.config,

            // ── Order block state ─────────────────────────────────────────────
            orderBlocks:          obm.orderBlocks,
            confirmedOBTouches:   obm.confirmedOBTouches,
            provisionalOBTouches: obm.provisionalOBTouches,
            orderBlockConfig:     obm.config,

            // ── Signal state (live) ───────────────────────────────────────────
            signals:          sm.signals,
            activeTrade:      sm.activeTrade,
            signalConfig:     sm.config,

            // ── Backtest overlay signals (shown on chart after backtest run) ──
            backtestSignals,
            hoveredBacktestEntry,
        },
        ...methods,
    };
}

// ─── Provider ───────────────────────────────────────────────────

export function TerminalProvider({ children }: { children: React.ReactNode }) {
    const [tick, setTick] = useState(0);
    const [backtestSignals, setBacktestSignalsState] = useState<Signal[]>([]);
    const [hoveredBacktestEntry, setHoveredBacktestEntryState] = useState<HoveredBacktestEntry | null>(null);

    useEffect(() => {
        const bump = () => setTick(t => t + 1);
        const unsubs = [
            terminalStore.layout.subscribe(bump),
            terminalStore.marketData.subscribe(bump),
            terminalStore.filterManager.subscribe(bump),
            terminalStore.backtestManager.subscribe(bump),
            terminalStore.playbackManager.subscribe(bump),
            terminalStore.pivotManager.subscribe(bump),
            terminalStore.trendlineManager.subscribe(bump),
            terminalStore.earlyPivotManager.subscribe(bump),
            terminalStore.orderBlockManager.subscribe(bump),
            terminalStore.signalManager.subscribe(bump),
        ];
        return () => unsubs.forEach(u => u());
    }, []);

    /**
     * Convert BacktestTrade[] → Signal[] using the main chart's day array so
     * that dayIndex values match what SignalOverlay expects.
     * Called by BottomBar after a successful backtest run.
     */
    const setBacktestSignals = useCallback((trades: BacktestTrade[], allEntrySignals: EntrySignal[] = []) => {
        const chartDays = terminalStore.marketData.days;
        const dayTsToIdx = new Map<number, number>();
        for (let i = 0; i < chartDays.length; i++) {
            dayTsToIdx.set(chartDays[i].time, i);
        }

        const signals: Signal[] = [];

        // Entry signals — one per confirmed pivot (all of them, not just trade entries)
        for (let i = 0; i < allEntrySignals.length; i++) {
            const es = allEntrySignals[i];
            // Note: es.dayIndex indexes the engine's DayCandle[]; recompute against the
            // chart's day list (dayTsToIdx) to get the correct coordinate for SignalOverlay.
            const dayTs  = Math.floor(es.time / 86400) * 86400;
            const dayIdx = dayTsToIdx.get(dayTs);
            if (dayIdx !== undefined) {
                signals.push({
                    id:       `bt_signal_${es.time}_${es.kind}_${es.price}_${i}`,
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

    const api = useMemo(
        () => buildApi(tick, backtestSignals, setBacktestSignals, hoveredBacktestEntry, setHoveredBacktestEntryState),
        [tick, backtestSignals, setBacktestSignals, hoveredBacktestEntry],
    );

    return <TerminalContext.Provider value={api}>{children}</TerminalContext.Provider>;
}

// ─── Hooks ──────────────────────────────────────────────────────

export function useTerminal() {
    const ctx = useContext(TerminalContext);
    if (!ctx) throw new Error('useTerminal must be used within TerminalProvider');
    return ctx;
}

export function useLayout()     { return useTerminal().store.layout; }
export function useMarketData() { return useTerminal().store.marketData; }
export function useFilters()    { return useTerminal().store.filterManager; }
export function useBacktest()   { return useTerminal().store.backtestManager; }
export function usePlayback()   { return useTerminal().store.playbackManager; }
export function usePivots()     { return useTerminal().store.pivotManager; }
