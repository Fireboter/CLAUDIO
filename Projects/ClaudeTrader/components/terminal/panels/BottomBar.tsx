"use client";

import React, { useState, useEffect, useRef } from 'react';
import { useTerminal } from '../TerminalContext';
import { Play, TrendingUp, TrendingDown, DollarSign, Percent, Activity } from 'lucide-react';
import { createChart, IChartApi, LineSeries, Time } from 'lightweight-charts';
import { runBacktestSimulation, type BacktestTrade, type BacktestStats, type TradeExitReason } from '../core/backtest/backtestEngine';
import { terminalStore } from '../core/store/TerminalStore';


// ─── Sub-components ─────────────────────────────────────────────

function StatCard({ label, value, icon, color = 'text-slate-200' }: { label: string; value: string | number; icon: React.ReactNode; color?: string }) {
    return (
        <div className="bg-slate-800/50 rounded-lg p-3">
            <div className="flex items-center gap-2 text-slate-500 text-xs uppercase font-bold mb-1">{icon}{label}</div>
            <div className={`text-lg font-bold ${color}`}>{value}</div>
        </div>
    );
}

function EquityChart({ data }: { data: { time: Time; value: number }[] }) {
    const containerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!containerRef.current || data.length === 0) return;

        const chart = createChart(containerRef.current, {
            layout: { background: { color: 'transparent' }, textColor: '#64748b' },
            grid: { vertLines: { visible: false }, horzLines: { color: '#1e293b' } },
            rightPriceScale: { borderVisible: false },
            timeScale: { borderVisible: false, visible: false },
            crosshair: { vertLine: { visible: false }, horzLine: { visible: false } },
        });

        const series = chart.addSeries(LineSeries, { color: '#10b981', lineWidth: 2, priceLineVisible: false, lastValueVisible: false });
        series.setData(data);

        const ro = new ResizeObserver(() => {
            if (containerRef.current) chart.applyOptions({ width: containerRef.current.clientWidth, height: containerRef.current.clientHeight });
        });
        ro.observe(containerRef.current);
        chart.timeScale().fitContent();

        return () => { ro.disconnect(); chart.remove(); };
    }, [data]);

    return <div ref={containerRef} className="h-full w-full" />;
}

function fmtTime(ts: number): string {
    const d = new Date(ts * 1000);
    const mo = String(d.getUTCMonth() + 1).padStart(2, '0');
    const dd = String(d.getUTCDate()).padStart(2, '0');
    const hh = String(d.getUTCHours()).padStart(2, '0');
    const mm = String(d.getUTCMinutes()).padStart(2, '0');
    return `${mo}/${dd} ${hh}:${mm}`;
}

const EXIT_REASON_LABEL: Record<TradeExitReason, string> = {
    stop_loss:   'SL',
    take_profit: 'TP',
    zone_exit:   'Zone',
};
const EXIT_REASON_COLOR: Record<TradeExitReason, string> = {
    stop_loss:   'text-red-400',
    take_profit: 'text-emerald-400',
    zone_exit:   'text-blue-400',
};

function TradesTable({ trades, onHover }: { trades: BacktestTrade[]; onHover: (trade: BacktestTrade | null) => void }) {
    if (trades.length === 0) {
        return <div className="flex items-center justify-center h-full text-slate-500 text-sm">No trades yet. Run a backtest to see results.</div>;
    }

    return (
        <div className="overflow-auto h-full">
            <table className="w-full text-sm">
                <thead className="text-xs uppercase text-slate-500 bg-slate-800/50 sticky top-0">
                    <tr>
                        <th className="px-3 py-2 text-left">Type</th>
                        <th className="px-3 py-2 text-left">Entry Time</th>
                        <th className="px-3 py-2 text-right">Entry $</th>
                        <th className="px-3 py-2 text-left">Exit Time</th>
                        <th className="px-3 py-2 text-right">Exit $</th>
                        <th className="px-3 py-2 text-left">Reason</th>
                        <th className="px-3 py-2 text-right">PnL</th>
                    </tr>
                </thead>
                <tbody>
                    {trades.map(trade => (
                        <tr key={trade.id}
                            className="border-t border-slate-800 hover:bg-slate-800/30 cursor-pointer"
                            onMouseEnter={() => onHover(trade)}
                            onMouseLeave={() => onHover(null)}
                        >
                            <td className="px-3 py-2">
                                <span className={`flex items-center gap-1 ${trade.type === 'long' ? 'text-emerald-400' : 'text-red-400'}`}>
                                    {trade.type === 'long' ? <TrendingUp className="w-3 h-3" /> : <TrendingDown className="w-3 h-3" />}
                                    {trade.type.toUpperCase()}
                                </span>
                            </td>
                            <td className="px-3 py-2 text-slate-400 text-xs font-mono">{fmtTime(trade.entryTime)}</td>
                            <td className="px-3 py-2 text-right text-slate-300">${trade.entryPrice.toFixed(2)}</td>
                            <td className="px-3 py-2 text-slate-400 text-xs font-mono">{trade.exitTime ? fmtTime(trade.exitTime) : '-'}</td>
                            <td className="px-3 py-2 text-right text-slate-300">{trade.exitPrice ? `$${trade.exitPrice.toFixed(2)}` : '-'}</td>
                            <td className="px-3 py-2">
                                {trade.exitReason ? (
                                    <span className={`text-xs font-medium ${EXIT_REASON_COLOR[trade.exitReason]}`}>
                                        {EXIT_REASON_LABEL[trade.exitReason]}
                                    </span>
                                ) : (
                                    <span className="text-xs text-amber-400">Open</span>
                                )}
                            </td>
                            <td className={`px-3 py-2 text-right font-medium ${(trade.pnl || 0) >= 0 ? 'text-emerald-400' : 'text-red-400'}`}>
                                {trade.pnl !== undefined ? `${trade.pnl >= 0 ? '+' : ''}${(trade.pnl * 100).toFixed(2)}%` : '-'}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

// ─── Main BottomBar ─────────────────────────────────────────────

export default function BottomBar() {
    const { state, setBacktestSignals, setHoveredBacktestEntry } = useTerminal();
    const {
        config, tradeAxisConfig, strategyConfig, enabledIndicators,
        trendlineConfig, earlyPivotConfig, signalConfig,
        trendlinesEnabled, orderBlockConfig,
    } = state;

    const [isRunning, setIsRunning] = useState(false);
    const [trades, setTrades] = useState<BacktestTrade[]>([]);
    const [equityCurve, setEquityCurve] = useState<{ time: Time; value: number }[]>([]);
    const [stats, setStats] = useState<BacktestStats | null>(null);
    const [backtestError, setBacktestError] = useState<string | null>(null);

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

    const runBacktest = () => {
        setIsRunning(true);
        setBacktestError(null);

        try {
            if (!enabledIndicators['signals']) {
                throw new Error('Enable Signals in Strategy to run a signals backtest.');
            }

            // Use the chart's already-loaded data so the backtest runs with
            // exactly the same DayCandle[] (including pre-history) as the live
            // chart.  This guarantees trendline computation is identical, so
            // every confirmed early pivot on the chart has a matching signal arrow.
            const chartDays      = terminalStore.marketData.days;
            const preHistoryCount = terminalStore.marketData.preHistoryCount ?? 0;

            if (chartDays.length === 0) {
                throw new Error('No chart data loaded. Load a symbol before running the backtest.');
            }

            // Only use signals from features that are actually enabled on the chart.
            // This ensures backtest results match what the live chart shows.
            const backtestSignalConfig = {
                ...signalConfig,
                enabled:              true,
                usePivotConfirmation: trendlinesEnabled && signalConfig.usePivotConfirmation,
                useBreakoutDetection: trendlinesEnabled && signalConfig.useBreakoutDetection,
                useOrderBlockSignals: orderBlockConfig.enabled && signalConfig.useOrderBlockSignals,
                useZoneEntryStrategy: signalConfig.useZoneEntryStrategy,
            };

            const result = runBacktestSimulation(
                chartDays,
                tradeAxisConfig,
                strategyConfig,
                trendlineConfig,
                backtestSignalConfig,
                earlyPivotConfig,
                preHistoryCount,
                orderBlockConfig,
            );

            if (result.stats.total_trades === 0 && result.allEntrySignals.length === 0) {
                const active = [
                    trendlinesEnabled && 'Trendlines',
                    orderBlockConfig.enabled && 'Order Blocks',
                ].filter(Boolean).join(' / ');
                const hint = signalConfig.useZoneEntryStrategy
                    ? 'Check that Order Blocks or Trendlines are enabled and price is crossing zone boundaries.'
                    : 'Check that Touch Zone > 0 and the date range has enough pivot touches.';
                setBacktestError(`No signals generated. Active patterns: ${active || 'none'}. ${hint}`);
            }

            setTrades(result.trades);
            setEquityCurve(result.equityCurve);
            setStats(result.stats);
            setBacktestSignals(result.trades, result.allEntrySignals);

        } catch (err) {
            const message = err instanceof Error ? err.message : 'Backtest failed.';
            setBacktestError(message);
            setTrades([]);
            setEquityCurve([]);
            setStats(null);
            setBacktestSignals([]);
        } finally {
            setIsRunning(false);
        }
    };

    return (
        <div className="h-full flex">
            {/* Stats Panel */}
            <div className="w-[280px] flex-shrink-0 border-r border-slate-800 p-3 flex flex-col">
                <button onClick={runBacktest} disabled={isRunning}
                    className="w-full py-2.5 bg-emerald-600 hover:bg-emerald-500 disabled:bg-slate-700 rounded-lg font-medium text-sm transition-colors flex items-center justify-center gap-2 mb-4">
                    {isRunning ? <><Activity className="w-4 h-4 animate-spin" />Running...</> : <><Play className="w-4 h-4" />Run Backtest</>}
                </button>
                {backtestError && (
                    <div className="mb-3 text-xs text-yellow-300 bg-yellow-500/10 border border-yellow-500/20 rounded px-2 py-1">{backtestError}</div>
                )}
                <div className="grid grid-cols-2 gap-2 flex-1">
                    <StatCard label="Return" value={stats ? `${stats.total_return >= 0 ? '+' : ''}${stats.total_return.toFixed(2)}%` : '-'} icon={<Percent className="w-3 h-3" />} color={stats && stats.total_return >= 0 ? 'text-emerald-400' : 'text-red-400'} />
                    <StatCard label="Win Rate" value={stats ? `${(stats.win_rate * 100).toFixed(1)}%` : '-'} icon={<TrendingUp className="w-3 h-3" />} color="text-blue-400" />
                    <StatCard label="Trades" value={stats ? stats.total_trades : '-'} icon={<Activity className="w-3 h-3" />} />
                    <StatCard label="Max DD" value={stats ? `${stats.max_drawdown.toFixed(2)}%` : '-'} icon={<TrendingDown className="w-3 h-3" />} color="text-red-400" />
                    <StatCard label="Sharpe" value={stats ? stats.sharpe_ratio.toFixed(2) : '-'} icon={<DollarSign className="w-3 h-3" />} />
                    <StatCard label="Profit Factor" value={stats ? stats.profit_factor.toFixed(2) : '-'} icon={<DollarSign className="w-3 h-3" />} />
                </div>
            </div>

            {/* Equity Chart */}
            <div className="w-[350px] flex-shrink-0 border-r border-slate-800">
                <div className="p-2 text-xs uppercase text-slate-500 font-bold border-b border-slate-800">Equity Curve</div>
                <div className="h-[calc(100%-28px)]">
                    {equityCurve.length > 0 ? <EquityChart data={equityCurve} /> : (
                        <div className="flex items-center justify-center h-full text-slate-500 text-sm">Run backtest to see equity curve</div>
                    )}
                </div>
            </div>

            {/* Trades Table */}
            <div className="flex-1 flex flex-col min-w-0">
                <div className="p-2 text-xs uppercase text-slate-500 font-bold border-b border-slate-800">Trades ({trades.length})</div>
                <div className="flex-1 min-h-0">
                    <TradesTable
                        trades={trades}
                        onHover={trade => setHoveredBacktestEntry(
                            trade ? { entryTime: trade.entryTime, exitTradeId: trade.id } : null
                        )}
                    />
                </div>
            </div>
        </div>
    );
}
