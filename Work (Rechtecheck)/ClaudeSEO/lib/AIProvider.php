<?php

interface AIProvider {
    /**
     * Send a prompt and return the text response.
     *
     * @param string $prompt
     * @param int    $maxTokens
     * @return string
     * @throws RuntimeException
     */
    public function call(string $prompt, int $maxTokens = 2048): string;

    /**
     * Return the provider name for usage tracking (e.g. 'claude', 'openai').
     */
    public function getApiName(): string;

    /**
     * Return the model identifier string (e.g. 'claude-haiku-4-5-20251001').
     */
    public function getModel(): string;
}
