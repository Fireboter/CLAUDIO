"use client";

import React from 'react';
import { useTerminal } from '../../TerminalContext';
import { ToggleSwitch } from '@/components/ui/ToggleSwitch';

function Slider({
    label, sublabel, min, max, step, value, onChange, display,
    disabled = false,
}: {
    label: string; sublabel?: string;
    min: number; max: number; step: number;
    value: number;
    onChange: (v: number) => void;
    display: string;
    disabled?: boolean;
}) {
    return (
        <div className={disabled ? 'opacity-40 pointer-events-none' : ''}>
            <label className="block text-xs text-slate-500 mb-1">
                {label}
                {sublabel && <span className="text-slate-600 ml-1">{sublabel}</span>}
            </label>
            <input
                type="range" min={min} max={max} step={step} value={value}
                onChange={e => onChange(parseFloat(e.target.value))}
                className="w-full accent-orange-500"
                disabled={disabled}
            />
            <div className="flex justify-between text-[10px] text-slate-600 mt-0.5">
                <span>{min}</span>
                <span className="text-orange-400 font-medium">{display}</span>
                <span>{max}</span>
            </div>
        </div>
    );
}

export function OrderBlockConfigPanel() {
    const { state, setOrderBlockConfig } = useTerminal();
    const cfg = state.orderBlockConfig;

    const supports    = state.orderBlocks.filter(ob => ob.type === 'support').length;
    const resistances = state.orderBlocks.filter(ob => ob.type === 'resistance').length;

    const patch = (p: Parameters<typeof setOrderBlockConfig>[0]) => setOrderBlockConfig(p);

    return (
        <div className="space-y-5 text-sm">

            {/* ── ZONE SETTINGS ─────────────────────────────────────────── */}
            <div className="space-y-3">
                <label className="block text-xs uppercase text-slate-500 font-bold">
                    Touch Zone
                </label>
                <p className="text-[10px] text-slate-600">
                    Transparent band around each pivot level. Entry signal fires when
                    price enters the zone and recoils by the configured percentage.
                </p>

                <Slider
                    label="Zone Width"
                    min={0.1} max={5.0} step={0.1}
                    value={cfg.touchZonePct}
                    onChange={v => patch({ touchZonePct: v })}
                    display={`${cfg.touchZonePct.toFixed(1)}%`}
                />

                <Slider
                    label="Recoil %" sublabel="(confirmation threshold)"
                    min={0.1} max={5.0} step={0.1}
                    value={cfg.recoilPct}
                    onChange={v => patch({ recoilPct: v })}
                    display={`${cfg.recoilPct.toFixed(1)}%`}
                />

                <Slider
                    label="Breakout Tolerance" sublabel="(closes this far past level → OB dies)"
                    min={0} max={5.0} step={0.1}
                    value={cfg.breakoutTolerance}
                    onChange={v => patch({ breakoutTolerance: v })}
                    display={`${cfg.breakoutTolerance.toFixed(1)}%`}
                />

                <Slider
                    label="Proximity to Price" sublabel="(0 = off)"
                    min={0} max={1} step={0.01}
                    value={cfg.proximity}
                    onChange={v => patch({ proximity: v })}
                    display={cfg.proximity === 0 ? 'Off' : `${(cfg.proximity * 100).toFixed(0)}% band`}
                />
            </div>

            {/* ── DISPLAY FILTERS ───────────────────────────────────────── */}
            <div className="border-t border-slate-800 pt-4 space-y-3">
                <label className="block text-xs uppercase text-slate-500 font-bold">
                    Display
                </label>

                <div className="flex items-center justify-between">
                    <div>
                        <div className="text-sm text-orange-400">Resistance Levels</div>
                        <div className="text-xs text-slate-500">High pivot horizontal lines</div>
                    </div>
                    <ToggleSwitch
                        enabled={cfg.showResistance}
                        onChange={() => patch({ showResistance: !cfg.showResistance })}
                        color="#f97316"
                    />
                </div>

                <div className="flex items-center justify-between">
                    <div>
                        <div className="text-sm text-teal-400">Support Levels</div>
                        <div className="text-xs text-slate-500">Low pivot horizontal lines</div>
                    </div>
                    <ToggleSwitch
                        enabled={cfg.showSupport}
                        onChange={() => patch({ showSupport: !cfg.showSupport })}
                        color="#14b8a6"
                    />
                </div>
            </div>

            {/* ── MERGE ─────────────────────────────────────────────────── */}
            <div className="border-t border-slate-800 pt-4 space-y-3">
                <div className="flex items-center justify-between">
                    <div>
                        <div className="text-sm font-medium text-slate-200">Merge Zones</div>
                        <div className="text-xs text-slate-500">Collapse nearby levels into one band</div>
                    </div>
                    <ToggleSwitch
                        enabled={cfg.mergeEnabled}
                        onChange={() => patch({ mergeEnabled: !cfg.mergeEnabled })}
                        color="#f97316"
                    />
                </div>

                <div className={cfg.mergeEnabled ? '' : 'opacity-40 pointer-events-none'}>
                    <Slider
                        label="Merge Gap"
                        sublabel="(levels within this % merge)"
                        min={0} max={5.0} step={0.1}
                        value={cfg.mergeGapPct}
                        onChange={v => patch({ mergeGapPct: v })}
                        display={cfg.mergeGapPct === 0 ? 'Off' : `${cfg.mergeGapPct.toFixed(1)}%`}
                    />
                    <p className="text-[9px] text-slate-600 mt-1">
                        Levels whose prices differ by less than this % are drawn as one combined zone.
                    </p>
                </div>
            </div>

            {/* ── STATS ─────────────────────────────────────────────────── */}
            <div className="border-t border-slate-800 pt-4">
                <label className="block text-xs uppercase text-slate-500 font-bold mb-3">
                    Active Levels
                </label>
                <div className="grid grid-cols-2 gap-2">
                    <div className="bg-slate-800/40 rounded-lg p-2.5 text-center">
                        <div className="text-lg font-bold text-orange-400">{resistances}</div>
                        <div className="text-[10px] text-slate-500">Resistance</div>
                    </div>
                    <div className="bg-slate-800/40 rounded-lg p-2.5 text-center">
                        <div className="text-lg font-bold text-teal-400">{supports}</div>
                        <div className="text-[10px] text-slate-500">Support</div>
                    </div>
                </div>
                <div className="mt-2 text-[10px] text-slate-600 text-center">
                    {state.confirmedOBTouches.length} confirmed touch{state.confirmedOBTouches.length !== 1 ? 'es' : ''}
                    {cfg.mergeEnabled && state.orderBlocks.some(ob => ob.mergedCount > 1) && (
                        <span className="ml-1">
                            · {state.orderBlocks.filter(ob => ob.mergedCount > 1).length} merged
                        </span>
                    )}
                </div>
                {state.orderBlocks.length > 0 && (
                    <div className="mt-1 text-[10px] text-slate-600 text-center">
                        Score range: {Math.min(...state.orderBlocks.map(o => o.score))} – {Math.max(...state.orderBlocks.map(o => o.score))}
                    </div>
                )}
            </div>

        </div>
    );
}
