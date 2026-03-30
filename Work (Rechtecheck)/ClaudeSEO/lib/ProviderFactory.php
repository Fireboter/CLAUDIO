<?php

class ProviderFactory {
    /**
     * Return the active AIProvider based on the settings table.
     * Falls back to ClaudeProvider if setting is missing.
     */
    public static function make(): AIProvider {
        $db      = Database::getInstance();
        $setting = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'active_model'");
        $model   = $setting['value'] ?? 'claude-3-5-haiku-20241022';

        if (str_starts_with($model, 'gpt-')) {
            return new OpenAIProvider();
        }

        return new ClaudeProvider();
    }

    /**
     * Instantiate the correct provider for the given model string.
     * Falls back to the DB-active model when $model is null.
     */
    public static function makeWithModel(?string $model): AIProvider {
        if ($model === null) {
            return static::make();
        }
        if (str_starts_with($model, 'gpt-')) {
            return new OpenAIProvider($model);
        }
        return new ClaudeProvider($model);
    }

    /**
     * Save the active model to the settings table.
     */
    public static function setModel(string $model): void {
        $db = Database::getInstance();
        $db->query(
            "INSERT INTO settings (`key`, `value`) VALUES ('active_model', ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()",
            [$model]
        );
    }

    /**
     * Return list of available models for the UI.
     */
    public static function availableModels(): array {
        return [
            'claude-3-5-haiku-20241022' => 'Claude Haiku 3.5',
            'gpt-5-nano'               => 'GPT-5 nano',
        ];
    }
}
