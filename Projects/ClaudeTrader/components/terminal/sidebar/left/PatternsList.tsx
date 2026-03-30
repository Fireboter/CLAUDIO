"use client";

import React, { useCallback } from 'react';
import { useTerminal } from '../../TerminalContext';
import { ToggleSwitch } from '@/components/ui/ToggleSwitch';
import { PATTERNS_FEATURES } from './indicators';

export function PatternsList() {
    const {
        state,
        toggleIndicator,
        setSelectedItem,
        setPivotsEnabled,
        setTrendlinesEnabled,
        setOrderBlockConfig,
    } = useTerminal();

    const handleSelect = useCallback((key: string) => {
        if (key === 'pivots')       return setSelectedItem('pivots',       key);
        if (key === 'trendlines')   return setSelectedItem('trendlines',   key);
        if (key === 'zones')        return setSelectedItem('trendlines',   'trendlines');
        if (key === 'orderblocks')  return setSelectedItem('orderblocks',  key);
        if (key === 'patterns')     return setSelectedItem('pattern',      key);
        return setSelectedItem('tradeaxis', key);
    }, [setSelectedItem]);

    const handleToggle = useCallback((key: string, currentValue: boolean) => {
        if (key === 'pivots') {
            setPivotsEnabled(!currentValue);
        } else if (key === 'trendlines') {
            setTrendlinesEnabled(!currentValue);
        } else if (key === 'orderblocks') {
            setOrderBlockConfig({ enabled: !currentValue });
        } else {
            toggleIndicator(key);
        }
    }, [toggleIndicator, setPivotsEnabled, setTrendlinesEnabled, setOrderBlockConfig]);

    const isEnabled = useCallback((key: string): boolean => {
        if (key === 'pivots')       return state.pivotsEnabled;
        if (key === 'trendlines')   return state.trendlinesEnabled;
        if (key === 'orderblocks')  return state.orderBlockConfig.enabled;
        return state.enabledIndicators[key] || false;
    }, [state.pivotsEnabled, state.trendlinesEnabled, state.orderBlockConfig.enabled, state.enabledIndicators]);

    const isSelected = useCallback((key: string): boolean => {
        const sel = state.selectedItem;
        if (key === 'pivots')       return sel.type === 'pivots'       && sel.key === key;
        if (key === 'trendlines')   return sel.type === 'trendlines'   && sel.key === key;
        if (key === 'zones')        return sel.type === 'trendlines';
        if (key === 'orderblocks')  return sel.type === 'orderblocks'  && sel.key === key;
        if (key === 'patterns')     return sel.type === 'pattern'      && sel.key === key;
        return sel.type === 'tradeaxis' && sel.key === key;
    }, [state.selectedItem]);

    return (
        <div className="space-y-1">
            {PATTERNS_FEATURES.map(feat => {
                const enabled  = isEnabled(feat.key);
                const selected = isSelected(feat.key);

                return (
                    <div
                        key={feat.key}
                        onClick={() => handleSelect(feat.key)}
                        className={`flex items-center justify-between px-3 py-2 rounded cursor-pointer transition-colors ${
                            selected ? 'bg-slate-700' : 'hover:bg-slate-800/50'
                        }`}
                    >
                        <div className="flex flex-col">
                            <span className={`text-sm ${enabled ? 'text-slate-200' : 'text-slate-400'}`}>
                                {feat.name}
                            </span>
                            <span className="text-xs text-slate-600">{feat.desc}</span>
                        </div>
                        <ToggleSwitch
                            enabled={enabled}
                            onChange={() => handleToggle(feat.key, enabled)}
                            color="#10b981"
                        />
                    </div>
                );
            })}
        </div>
    );
}
