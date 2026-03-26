"use client";

import { useEffect, useRef, useCallback } from 'react';
import type { ISeriesApi, IChartApi, Time, Coordinate } from 'lightweight-charts';
import type { OrderBlock, OrderBlockConfig } from '../core/models/OrderBlock';
import type { DayCandle } from '../core/models/Candle';

interface OrderBlockOverlayProps {
    chart:       IChartApi | null;
    series:      ISeriesApi<'Candlestick'> | null;
    days:        DayCandle[];
    orderBlocks: OrderBlock[];
    config:      OrderBlockConfig;
    enabled:     boolean;
}

/**
 * OrderBlockOverlay — SVG layer drawn over the chart canvas.
 *
 * Renders each order block as a filled zone band using pre-computed
 * ob.zoneHigh / ob.zoneLow.  Merged clusters (mergedCount > 1) receive
 * slightly higher fill-opacity to distinguish them from individual levels.
 *
 * Resistance OBs: orange  (#f97316)
 * Support    OBs: teal    (#14b8a6)
 *
 * Touch triangles are rendered by EarlyPivotOverlay (OB touches are passed
 * to it as EarlyPivot objects in MainChart).
 */
export function OrderBlockOverlay({
    chart,
    series,
    days,
    orderBlocks,
    config,
    enabled,
}: OrderBlockOverlayProps) {

    const svgRef       = useRef<SVGSVGElement | null>(null);
    const containerRef = useRef<HTMLDivElement | null>(null);
    const rafRef       = useRef<number | null>(null);

    // ── Create SVG element once ───────────────────────────────────────────────
    useEffect(() => {
        if (!chart) return;
        const parent = chart.chartElement().parentElement as HTMLElement | null;
        if (!parent) return;

        if (getComputedStyle(parent).position === 'static') {
            parent.style.position = 'relative';
        }

        const container = document.createElement('div');
        container.style.cssText = 'position:absolute;inset:0;pointer-events:none;z-index:9;';
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

    // ── Main draw ─────────────────────────────────────────────────────────────
    const draw = useCallback(() => {
        const svg = svgRef.current;
        if (!svg || !chart || !series) return;

        while (svg.firstChild) svg.removeChild(svg.firstChild);

        if (!enabled || orderBlocks.length === 0 || days.length === 0) return;

        const timeScale = chart.timeScale();
        const fragment  = document.createDocumentFragment();

        for (const ob of orderBlocks) {
            const prevDay  = days[ob.pivotDayIndex - 1];
            const startDay = days[ob.pivotDayIndex];
            const endDay   = days[ob.endIndex] ?? days[days.length - 1];
            if (!startDay || !endDay) continue;

            // Place the left edge at the boundary between bar N and bar N+1
            // (left edge of startDay = midpoint between prev bar centre and startDay centre).
            // Falls back to startDay centre when there is no previous bar.
            const xStart = timeScale.timeToCoordinate(startDay.time as Time);
            if (xStart === null) continue;
            let x1 = xStart;
            if (prevDay) {
                const xPrev = timeScale.timeToCoordinate(prevDay.time as Time);
                if (xPrev !== null) x1 = ((xPrev + xStart) / 2) as Coordinate;
            }

            const x2 = timeScale.timeToCoordinate(endDay.time as Time);
            if (x2 === null) continue;

            const yTop = series.priceToCoordinate(ob.zoneHigh);
            const yBot = series.priceToCoordinate(ob.zoneLow);
            if (yTop === null || yBot === null) continue;

            const color = ob.type === 'resistance' ? '#f97316' : '#14b8a6';

            // ── Zone band ─────────────────────────────────────────────────────
            const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            rect.setAttribute('x',              String(Math.min(x1, x2)));
            rect.setAttribute('y',              String(Math.min(yTop, yBot)));
            rect.setAttribute('width',          String(Math.abs(x2 - x1)));
            rect.setAttribute('height',         String(Math.abs(yBot - yTop)));
            rect.setAttribute('fill',           color);
            rect.setAttribute('fill-opacity',   ob.mergedCount > 1 ? '0.14' : '0.10');
            rect.setAttribute('stroke',         color);
            rect.setAttribute('stroke-width',   '1');
            rect.setAttribute('stroke-opacity', '0.35');
            fragment.appendChild(rect);
        }

        svg.appendChild(fragment);
    }, [chart, series, days, orderBlocks, config, enabled]);

    // ── Trigger draw on prop change ───────────────────────────────────────────
    useEffect(() => {
        if (rafRef.current) cancelAnimationFrame(rafRef.current);
        rafRef.current = requestAnimationFrame(draw);
        return () => { if (rafRef.current) cancelAnimationFrame(rafRef.current); };
    }, [draw]);

    // ── Redraw on chart scroll/zoom ───────────────────────────────────────────
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
