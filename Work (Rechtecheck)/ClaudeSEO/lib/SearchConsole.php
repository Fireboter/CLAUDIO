<?php

/**
 * Google Search Console API integration.
 *
 * Uses the OAuth2 refresh-token flow (via Guzzle) to fetch performance data
 * from the GSC Search Analytics API and stores it in the page_analytics table.
 *
 * Credentials file (gsc_credentials.json) must contain:
 *   {"client_id": "...", "client_secret": "...", "refresh_token": "..."}
 */

class SearchConsole
{
    private string $propertyUrl;
    private ?array $credentials = null;
    private ?string $accessToken = null;
    private Database $db;
    private \GuzzleHttp\Client $httpClient;

    /** @var array<string, array{type: string, id: int}> Resolved URL cache */
    private array $urlCache = [];

    public function __construct()
    {
        $config = (require __DIR__ . '/../config/api_keys.php')['gsc'];
        $this->propertyUrl = $config['property_url'];
        $this->db = Database::getInstance();
        $this->httpClient = new \GuzzleHttp\Client(['timeout' => 60]);

        $credPath = $config['credentials_path'];
        if (file_exists($credPath)) {
            $this->credentials = json_decode(file_get_contents($credPath), true);
        }
    }

    /**
     * Whether GSC credentials are available and contain the required fields.
     */
    public function isConfigured(): bool
    {
        return $this->credentials !== null
            && !empty($this->credentials['client_id'])
            && !empty($this->credentials['client_secret'])
            && !empty($this->credentials['refresh_token']);
    }

    // -------------------------------------------------------------------------
    // OAuth2 token management
    // -------------------------------------------------------------------------

    /**
     * Obtain a fresh access token using the stored refresh token.
     *
     * The token is cached in-memory for the lifetime of this object so that
     * multiple API calls within the same cron run reuse it.
     */
    private function getAccessToken(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $response = $this->httpClient->post('https://oauth2.googleapis.com/token', [
            'form_params' => [
                'client_id'     => $this->credentials['client_id'],
                'client_secret' => $this->credentials['client_secret'],
                'refresh_token' => $this->credentials['refresh_token'],
                'grant_type'    => 'refresh_token',
            ],
        ]);

        $body = json_decode($response->getBody()->getContents(), true);
        $this->accessToken = $body['access_token'];
        return $this->accessToken;
    }

    // -------------------------------------------------------------------------
    // API data fetching
    // -------------------------------------------------------------------------

    /**
     * Fetch page-level performance data from GSC for a date range.
     *
     * @return array<string, array{clicks: int, impressions: int, ctr: float, position: float}>
     *         Keyed by page URL.
     */
    public function fetchPerformanceData(string $startDate, string $endDate, int $rowLimit = 5000): array
    {
        $token = $this->getAccessToken();
        $encodedUrl = urlencode($this->propertyUrl);

        $response = $this->httpClient->post(
            "https://www.googleapis.com/webmasters/v3/sites/{$encodedUrl}/searchAnalytics/query",
            [
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'startDate'  => $startDate,
                    'endDate'    => $endDate,
                    'dimensions' => ['page'],
                    'rowLimit'   => $rowLimit,
                ],
            ]
        );

        $body = json_decode($response->getBody()->getContents(), true);
        $results = [];
        foreach ($body['rows'] ?? [] as $row) {
            $url = $row['keys'][0];
            $results[$url] = [
                'clicks'      => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr'         => (float) ($row['ctr'] ?? 0.0),
                'position'    => (float) ($row['position'] ?? 0.0),
            ];
        }
        return $results;
    }

    /**
     * Fetch query-level performance data from GSC for a date range.
     *
     * @return array<string, array{clicks: int, impressions: int, ctr: float, position: float}>
     *         Keyed by search query.
     */
    public function fetchQueryData(string $startDate, string $endDate, int $rowLimit = 5000): array
    {
        $token = $this->getAccessToken();
        $encodedUrl = urlencode($this->propertyUrl);

        $response = $this->httpClient->post(
            "https://www.googleapis.com/webmasters/v3/sites/{$encodedUrl}/searchAnalytics/query",
            [
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'startDate'  => $startDate,
                    'endDate'    => $endDate,
                    'dimensions' => ['query'],
                    'rowLimit'   => $rowLimit,
                ],
            ]
        );

        $body = json_decode($response->getBody()->getContents(), true);
        $results = [];
        foreach ($body['rows'] ?? [] as $row) {
            $query = $row['keys'][0];
            $results[$query] = [
                'clicks'      => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr'         => (float) ($row['ctr'] ?? 0.0),
                'position'    => (float) ($row['position'] ?? 0.0),
            ];
        }
        return $results;
    }

    // -------------------------------------------------------------------------
    // Data storage
    // -------------------------------------------------------------------------

    /**
     * Store page performance data in the page_analytics table.
     *
     * Each URL is resolved to a page_type + page_id via {@see resolveUrlToPage}.
     * Uses INSERT ... ON DUPLICATE KEY UPDATE so re-runs for the same date are
     * idempotent.
     *
     * @return int Number of rows stored / updated.
     */
    public function storePerformanceData(array $data, string $date): int
    {
        $pdo = $this->db->getPdo();
        $stored = 0;

        $sql = 'INSERT INTO page_analytics (page_type, page_id, url, clicks, impressions, ctr, avg_position, date, fetched_at)
                VALUES (:page_type, :page_id, :url, :clicks, :impressions, :ctr, :avg_position, :date, :fetched_at)
                ON DUPLICATE KEY UPDATE
                    clicks       = VALUES(clicks),
                    impressions  = VALUES(impressions),
                    ctr          = VALUES(ctr),
                    avg_position = VALUES(avg_position),
                    fetched_at   = VALUES(fetched_at)';

        $stmt = $pdo->prepare($sql);

        foreach ($data as $url => $metrics) {
            $page = $this->resolveUrlToPage($url);
            if ($page === null) {
                continue; // URL does not belong to an SEO page we track
            }

            $stmt->execute([
                ':page_type'    => $page['type'],
                ':page_id'      => $page['id'],
                ':url'          => $url,
                ':clicks'       => $metrics['clicks'],
                ':impressions'  => $metrics['impressions'],
                ':ctr'          => $metrics['ctr'],
                ':avg_position' => $metrics['position'],
                ':date'         => $date,
                ':fetched_at'   => date('Y-m-d H:i:s'),
            ]);

            $stored++;
        }

        return $stored;
    }

    /**
     * Update aggregate analytics on rechtsgebiete and rechtsfragen tables.
     *
     * For each entity the method computes the totals for the last 30 days:
     *   - total_clicks, total_impressions (SUM)
     *   - avg_position (impression-weighted average)
     *   - performance_score (clicks * 10 + impressions, simple scoring)
     */
    public function updateAggregateMetrics(): void
    {
        $since = date('Y-m-d', strtotime('-30 days'));

        // --- Rechtsgebiete ---------------------------------------------------
        $this->db->query(
            "UPDATE rechtsgebiete rg
             LEFT JOIN (
                 SELECT page_id,
                        SUM(clicks) AS total_clicks,
                        SUM(impressions) AS total_impressions,
                        CASE WHEN SUM(impressions) > 0
                             THEN SUM(avg_position * impressions) / SUM(impressions)
                             ELSE 0 END AS weighted_position
                 FROM page_analytics
                 WHERE page_type = 'rechtsgebiet' AND date >= ?
                 GROUP BY page_id
             ) pa ON pa.page_id = rg.id
             SET rg.total_clicks      = COALESCE(pa.total_clicks, 0),
                 rg.total_impressions  = COALESCE(pa.total_impressions, 0),
                 rg.avg_position       = COALESCE(pa.weighted_position, 0),
                 rg.performance_score  = COALESCE(pa.total_clicks, 0) * 10 + COALESCE(pa.total_impressions, 0)",
            [$since]
        );

        // --- Rechtsfragen ----------------------------------------------------
        $this->db->query(
            "UPDATE rechtsfragen rf
             LEFT JOIN (
                 SELECT page_id,
                        SUM(clicks) AS total_clicks,
                        SUM(impressions) AS total_impressions,
                        CASE WHEN SUM(impressions) > 0
                             THEN SUM(avg_position * impressions) / SUM(impressions)
                             ELSE 0 END AS weighted_position
                 FROM page_analytics
                 WHERE page_type IN ('rechtsfrage', 'variation') AND date >= ?
                 GROUP BY page_id
             ) pa ON pa.page_id = rf.id
             SET rf.total_clicks      = COALESCE(pa.total_clicks, 0),
                 rf.total_impressions  = COALESCE(pa.total_impressions, 0),
                 rf.avg_position       = COALESCE(pa.weighted_position, 0),
                 rf.performance_score  = COALESCE(pa.total_clicks, 0) * 10 + COALESCE(pa.total_impressions, 0)",
            [$since]
        );
    }

    // -------------------------------------------------------------------------
    // URL resolution
    // -------------------------------------------------------------------------

    /**
     * Parse a GSC URL into a page_type and page_id.
     *
     * Matching rules (mirroring the Router):
     *   /experten-service/unsere-services/  -> skip (overview)
     *   /experten-service/{slug}/           -> rechtsgebiet
     *   /{slug}/                            -> rechtsfrage (direct match)
     *   /{slug-with-city}/                  -> variation  (split from right)
     *
     * @return array{type: string, id: int}|null
     */
    private function resolveUrlToPage(string $url): ?array
    {
        // Return cached result if available
        if (isset($this->urlCache[$url])) {
            return $this->urlCache[$url];
        }

        $parsed = parse_url($url, PHP_URL_PATH);
        if ($parsed === false || $parsed === null) {
            return null;
        }

        $path = trim($parsed, '/');
        if ($path === '' || $path === 'experten-service/unsere-services') {
            return null; // Home page or overview page -- skip
        }

        $result = null;

        // 1. Rechtsgebiet: /experten-service/{slug}
        if (preg_match('#^experten-service/([a-z0-9\-]+)$#', $path, $m)) {
            $slug = $m[1];
            $row = $this->db->fetchOne('SELECT id FROM rechtsgebiete WHERE slug = ?', [$slug]);
            if ($row) {
                $result = ['type' => 'rechtsgebiet', 'id' => (int) $row['id']];
            }
        }

        // 2. Rechtsfrage or Variation: /{slug}
        if ($result === null && preg_match('#^([a-z0-9\-]+)$#', $path, $m)) {
            $slug = $m[1];

            // Try direct rechtsfrage match first
            $row = $this->db->fetchOne('SELECT id FROM rechtsfragen WHERE slug = ?', [$slug]);
            if ($row) {
                $result = ['type' => 'rechtsfrage', 'id' => (int) $row['id']];
            } else {
                // Try variation: split from the right (same logic as Router::tryVariation)
                $result = $this->resolveVariation($slug);
            }
        }

        $this->urlCache[$url] = $result;
        return $result;
    }

    /**
     * Attempt to split a slug into {rechtsfrage-slug}-{city-slug} from the right.
     *
     * For variations the page_id stored in page_analytics is the rechtsfrage_id
     * (matching the page_analytics.page_type = 'variation' convention used by
     * the rest of the system).
     *
     * @return array{type: string, id: int}|null
     */
    private function resolveVariation(string $fullSlug): ?array
    {
        $lastHyphen = strrpos($fullSlug, '-');
        while ($lastHyphen !== false) {
            $rfSlug   = substr($fullSlug, 0, $lastHyphen);
            $citySlug = substr($fullSlug, $lastHyphen + 1);

            $rf = $this->db->fetchOne('SELECT id FROM rechtsfragen WHERE slug = ?', [$rfSlug]);
            $vv = $this->db->fetchOne('SELECT id FROM variation_values WHERE slug = ?', [$citySlug]);

            if ($rf && $vv) {
                return ['type' => 'variation', 'id' => (int) $rf['id']];
            }

            // Move the split point further left
            $lastHyphen = strrpos($rfSlug, '-');
        }

        return null;
    }
}
