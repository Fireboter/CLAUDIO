<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class ClaudeProvider implements AIProvider {
    private Client $http;
    private array  $config;
    private Database $db;

    public function __construct(?string $modelOverride = null) {
        $this->config = (require __DIR__ . '/../../config/api_keys.php')['claude'];
        if ($modelOverride !== null) {
            $this->config['model'] = $modelOverride;
        }
        $this->db     = Database::getInstance();

        $caBundle = ini_get('curl.cainfo');
        $caBundle = $caBundle ? str_replace('\\', '/', $caBundle) : 'C:/php/cacert.pem';

        $this->http = new Client([
            'base_uri' => 'https://api.anthropic.com/v1/',
            'timeout'  => 120,
            'verify'   => file_exists($caBundle) ? $caBundle : false,
        ]);
    }

    public function call(string $prompt, int $maxTokens = 2048): string {
        try {
            $response = $this->http->post('messages', [
                'headers' => [
                    'x-api-key'         => $this->config['api_key'],
                    'anthropic-version'  => '2023-06-01',
                    'content-type'       => 'application/json',
                ],
                'json' => [
                    'model'      => $this->config['model'],
                    'max_tokens' => $maxTokens,
                    'messages'   => [['role' => 'user', 'content' => $prompt]],
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Claude API error: ' . $e->getMessage(), 0, $e);
        }

        $body = json_decode($response->getBody()->getContents(), true);
        if (!isset($body['content'][0]['text'])) {
            throw new RuntimeException('Unexpected Claude response format');
        }

        $this->trackUsage($body['usage'] ?? []);
        return $body['content'][0]['text'];
    }

    public function getApiName(): string { return 'claude'; }

    public function getModel(): string { return $this->config['model']; }

    private function trackUsage(array $usage): void {
        $input  = $usage['input_tokens']  ?? 0;
        $output = $usage['output_tokens'] ?? 0;
        $cost   = ($input * 0.000025) + ($output * 0.000125);

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
