"use client";

import { useEffect, useRef, useCallback } from 'react';
import type { ISeriesApi, IChartApi, Time, IPriceLine } from 'lightweight-charts';
import type { Signal, ActiveTrade } from '../core/models/Signal';
import type { DayCandle } from '../core/models/Candle';
import type { HoveredBacktestEntry } from '../TerminalContext';

interface SignalOverlayProps {
    chart:               IChartApi | null;
    series:              ISeriesApi<'Candlestick'> | null;
    days:                DayCandle[];
    signals:             Signal[];
    activeTrade:         ActiveTrade | null;
    enabled:             boolean;
    useStopLoss:         boolean;
    useTakeProfit:       boolean;
    hoveredEntry?:       HoveredBacktestEntry | null;
}

/**
 * SignalOverlay — SVG layer drawn over the lightweight-charts canvas,
 * plus native chart price lines for Stop Loss / Take Profit levels.
 *
 * Renders four visual elements:
 *
 *  A. Entry arrows (SVG triangles)
 *     - Long  (blue  ↑): bullish entry — pivot recoil from support OR breakout above resistance
 *     - Short (yellow ↓): bearish entry — pivot recoil from resistance OR breakout below support
 *
 *  B. SL / TP price lines (native chart price lines, like recoil threshold lines)
 *     - Stop Loss  : red dashed   at activeTrade.slPrice
 *     - Take Profit: green dashed at activeTrade.tpPrice
 *
 *  C. Exit markers (SVG text)
 *     - Win  (light-green "$"): take-profit hit or profitable zone exit
 *     - Loss (yellow      "✕"): stop-loss hit or losing zone exit
 */
export function SignalOverlay({
    chart,
    series,
    days,
    signals,
    activeTrade,
    enabled,
    useStopLoss,
    useTakeProfit,
    hoveredEntry = null,
}: SignalOverlayProps) {

    const svgRef           = useRef<SVGSVGElement | null>(null);
    const containerRef     = useRef<HTMLDivElement | null>(null);
    const rafRef           = useRef<number | null>(null);
    const slLineRef        = useRef<IPriceLine | null>(null);
    const tpLineRef        = useRef<IPriceLine | null>(null);

    // ── Create SVG element once ───────────────────────────────────────────────
    useEffect(() => {
        if (!chart) return;
        const parent = chart.chartElement().parentElement as HTMLElement | null;
        if (!parent) return;

        if (getComputedStyle(parent).position === 'static') {
            parent.style.position = 'relative';
        }

        const container = document.createElement('div');
        container.style.cssText = 'position:absolute;inset:0;pointer-events:none;z-index:12;';
        parent.appendChild(container);
        containerRef.current = container;

        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;overflow:visible;';
        svg.setAttribute('pointer-events', 'none');
        container.appendChild(svg);
        svgRef.current = svg;

        return () => {
            container.remove();
            svgRef.current       = null;
            containerRef.current = null;
        };
    }, [chart]);

    // ── Section B: SL / TP native price lines ────────────────────────────────
    useEffect(() => {
        if (!series) return;

        const wantSL = enabled && useStopLoss  && activeTrade?.slPrice != null;
        const wantTP = enabled && useTakeProfit && activeTrade?.tpPrice != null;

        // Stop Loss line
        if (wantSL && activeTrade!.slPrice !== null) {
            if (!slLineRef.current) {
                slLineRef.current = series.createPriceLine({
                    price:             activeTrade!.slPrice,
                    color:             '#ef4444',
                    lineWidth:         1,
                    lineStyle:         2,   // Dashed
                    axisLabelVisible:  true,
                    title:             'SL',
                    axisLabelColor:    '#ef4444',
                    axisLabelTextColor:'#0f172a',
                });
            } else if (slLineRef.current.options().price !== activeTrade!.slPrice) {
                slLineRef.current.applyOptions({ price: activeTrade!.slPrice });
            }
        } else if (slLineRef.current) {
            try { series.removePriceLine(slLineRef.current); } catch (_) { /* ignore */ }
            slLineRef.current = null;
        }

        // Take Profit line
        if (wantTP && activeTrade!.tpPrice !== null) {
            if (!tpLineRef.current) {
                tpLineRef.current = series.createPriceLine({
                    price:             activeTrade!.tpPrice,
                    color:             '#22c55e',
                    lineWidth:         1,
                    lineStyle:         2,   // Dashed
                    axisLabelVisible:  true,
                    title:             'TP',
                    axisLabelColor:    '#22c55e',
                    axisLabelTextColor:'#0f172a',
                });
            } else if (tpLineRef.current.options().price !== activeTrade!.tpPrice) {
                tpLineRef.current.applyOptions({ price: activeTrade!.tpPrice });
            }
        } else if (tpLineRef.current) {
            try { series.removePriceLine(tpLineRef.current); } catch (_) { /* ignore */ }
            tpLineRef.current = null;
        }
    }, [series, activeTrade, enabled, useStopLoss, useTakeProfit]);

    // ── Clean up price lines when series changes or unmounts ─────────────────
    useEffect(() => {
        const s = series;
        return () => {
            if (!s) return;
            if (slLineRef.current) {
                try { s.removePriceLine(slLineRef.current); } catch (_) { /* ignore */ }
                slLineRef.current = null;
            }
            if (tpLineRef.current) {
                try { s.removePriceLine(tpLineRef.current); } catch (_) { /* ignore */ }
                tpLineRef.current = null;
            }
        };
    }, [series]);

    // ── Main draw function (SVG: entry arrows + exit markers) ─────────────────
    const draw = useCallback(() => {
        const svg = svgRef.current;
        if (!svg || !chart || !series) return;

        while (svg.firstChild) svg.removeChild(svg.firstChild);

        if (!enabled || signals.length === 0 || days.length === 0) return;

        const timeScale  = chart.timeScale();
        const fragment   = document.createDocumentFragment();
        const hasHover   = hoveredEntry !== null;

        for (const sig of signals) {
            const day = days[sig.dayIndex];
            if (!day) continue;

            const x = timeScale.timeToCoordinate(day.time as Time);
            if (x === null) continue;

            const y = series.priceToCoordinate(sig.price);
            if (y === null) continue;

            // A signal is highlighted if it belongs to the hovered trade:
            //   entry: time matches trade entry time + is a directional signal
            //   exit:  signal id matches bt_exit_<tradeId>
            const isHighlighted = hasHover && (
                (sig.time === hoveredEntry!.entryTime &&
                    (sig.kind === 'long' || sig.kind === 'short')) ||
                sig.id === `bt_exit_${hoveredEntry!.exitTradeId}`
            );
            // Dim non-highlighted signals when a trade is hovered
            const opacity = hasHover ? (isHighlighted ? 1.0 : 0.2) : 1.0;
            // Scale up highlighted signals
            const scale   = isHighlighted ? 1.6 : 1.0;

            if (sig.kind === 'long') {
                const yArrow = y + 18 * scale;
                _appendArrow(fragment, x, yArrow, false, '#7dd3fc', opacity, 10 * scale, 8 * scale);
            } else if (sig.kind === 'short') {
                const yArrow = y - 18 * scale;
                _appendArrow(fragment, x, yArrow, true, '#eab308', opacity, 10 * scale, 8 * scale);
            } else if (sig.kind === 'win') {
                _appendText(fragment, x, y, '$', '#86efac', 11 * scale, opacity);
            } else if (sig.kind === 'loss') {
                _appendText(fragment, x, y, '\u00D7', '#fde047', 11 * scale, opacity);
            }
        }

        svg.appendChild(fragment);
    }, [chart, series, days, signals, enabled, hoveredEntry]);

    // ── Trigger draw on every prop change ────────────────────────────────────
    useEffect(() => {
        if (rafRef.current) cancelAnimationFrame(rafRef.current);
        rafRef.current = requestAnimationFrame(draw);
        return () => {
            if (rafRef.current) cancelAnimationFrame(rafRef.current);
        };
    }, [draw]);

    // ── Redraw on chart time-scale scroll/zoom ────────────────────────────────
    useEffect(() => {
        if (!chart) return;
        const handler = () => {
            if (rafRef.current) cancelAnimationFrame(rafRef.current);
            rafRef.current = requestAnimationFrame(draw);
        };
        chart.timeScale().subscribeVisibleLogicalRangeChange(handler);
        return () => chart.timeScale().unsubscribeVisibleLogicalRangeChange(handler);
    }, [chart, draw]);

    return null;
}

// ─── SVG helpers ──────────────────────────────────────────────────────────────

/**
 * Appends a filled triangle arrow to the fragment.
 *
 * @param pointDown  true = ↓ (tip at bottom, base at top), false = ↑
 */
function _appendArrow(
    fragment:  DocumentFragment,
    cx:        number,
    cy:        number,
    pointDown: boolean,
    color:     string,
    opacity:   number,
    w:         number,
    h:         number,
): void {
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    let d: string;
    if (pointDown) {
        d = `M ${cx},${cy} L ${cx - w / 2},${cy - h} L ${cx + w / 2},${cy - h} Z`;
    } else {
        d = `M ${cx},${cy} L ${cx - w / 2},${cy + h} L ${cx + w / 2},${cy + h} Z`;
    }
    path.setAttribute('d',            d);
    path.setAttribute('fill',         color);
    path.setAttribute('fill-opacity', String(opacity));
    path.setAttribute('stroke',       'none');
    fragment.appendChild(path);
}

/**
 * Appends a centred text element to the fragment.
 */
function _appendText(
    fragment: DocumentFragment,
    cx:       number,
    cy:       number,
    text:     string,
    color:    string,
    fontSize: number,
    opacity:  number = 1.0,
): void {
    const el = document.createElementNS('http://www.w3.org/2000/svg', 'text');
    el.setAttribute('x',            String(cx));
    el.setAttribute('y',            String(cy + fontSize / 3));
    el.setAttribute('text-anchor',  'middle');
    el.setAttribute('fill',         color);
    el.setAttribute('fill-opacity', String(opacity));
    el.setAttribute('font-size',    String(fontSize));
    el.setAttribute('font-weight',  'bold');
    el.setAttribute('font-family',  'monospace');
    el.textContent = text;
    fragment.appendChild(el);
}
