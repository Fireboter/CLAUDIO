<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class OpenAIProvider implements AIProvider {
    private Client $http;
    private array  $config;
    private Database $db;

    public function __construct(?string $modelOverride = null) {
        $this->config = (require __DIR__ . '/../../config/api_keys.php')['openai'];
        if ($modelOverride !== null) {
            $this->config['model'] = $modelOverride;
        }
        $this->db     = Database::getInstance();

        $caBundle = ini_get('curl.cainfo');
        $caBundle = $caBundle ? str_replace('\\', '/', $caBundle) : 'C:/php/cacert.pem';

        $this->http = new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'timeout'  => 300,
            'verify'   => file_exists($caBundle) ? $caBundle : false,
        ]);
    }

    public function call(string $prompt, int $maxTokens = 2048): string {
        try {
            $response = $this->http->post('chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['api_key'],
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model'                 => $this->config['model'],
                    // gpt-5-nano is a reasoning model that spends tokens on internal thinking.
                    // 4x multiplier + 4000-token floor gives room for reasoning + actual output.
                    'max_completion_tokens' => $maxTokens * 4 + 4000,
                    // Limit reasoning depth so the model doesn't exhaust the budget before writing.
                    'reasoning_effort'      => 'low',
                    'messages'              => [['role' => 'user', 'content' => $prompt]],
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('OpenAI API error: ' . $e->getMessage(), 0, $e);
        }

        $body = json_decode($response->getBody()->getContents(), true);
        if (!isset($body['choices'][0]['message']['content'])) {
            throw new RuntimeException('Unexpected OpenAI response format');
        }

        $this->trackUsage($body['usage'] ?? []);
        return $body['choices'][0]['message']['content'];
    }

    public function getApiName(): string { return 'openai'; }

    public function getModel(): string { return $this->config['model']; }

    private function trackUsage(array $usage): void {
        $input   = $usage['prompt_tokens']                              ?? 0;
        $output  = $usage['completion_tokens']                          ?? 0;
        $cached  = $usage['prompt_tokens_details']['cached_tokens']     ?? 0;
        $nonCached = $input - $cached;
        // gpt-5-nano pricing: $0.05/MTok input, $0.40/MTok output, $0.005/MTok cached input
        // → all converted to cents per token
        $cost   = ($nonCached * 0.000005) + ($cached * 0.0000005) + ($output * 0.00004);

        $this->db->query(
            "INSERT INTO api_usage (date, api_name, calls_count, tokens_used, cost_cents)
             VALUES (?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE
                 calls_count = calls_count + 1,
                 tokens_used = tokens_used + VALUES(tokens_used),
                 cost_cents  = cost_cents  + VALUES(cost_cents)",
            [date('Y-m-d'), $this->getApiName(), $input + $output, $cost]
        );
    }
}
