<?php

class SeoAnalyzer {
    private Database $db;
    private array $seoConfig;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->seoConfig = require __DIR__ . '/../config/seo.php';
    }

    /**
     * Calculate performance score from analytics metrics.
     * Weighted formula: clicks 40% + impressions 30% + CTR 20% + position inverse 10%
     * Returns 0-100 score.
     */
    public function calculatePerformanceScore(int $clicks, int $impressions, float $ctr, float $avgPosition): int {
        // Normalize each metric to 0-100 range
        // clicks: log scale, cap at 100 clicks = 100 score
        $clickScore = min(100, $clicks > 0 ? (log10($clicks + 1) / log10(101)) * 100 : 0);
        // impressions: log scale, cap at 10000
        $impScore = min(100, $impressions > 0 ? (log10($impressions + 1) / log10(10001)) * 100 : 0);
        // CTR: 0-1 mapped to 0-100
        $ctrScore = min(100, $ctr * 100);
        // Position: 1=100, 100=0 (inverse)
        $posScore = max(0, 100 - $avgPosition);

        $score = ($clickScore * 0.4) + ($impScore * 0.3) + ($ctrScore * 0.2) + ($posScore * 0.1);
        return (int) round(min(100, max(0, $score)));
    }

    /**
     * Analyze a Rechtsfrage and determine what action to take.
     * Implements the decision tree from the design doc.
     *
     * @param array $rf Rechtsfrage row (with performance fields)
     * @param array $analytics Aggregated analytics: [clicks, impressions, ctr, avg_position] for last 30d
     * @return array ['action' => 'keep|update|delete|create|expand', 'reason' => '...', 'priority' => N]
     */
    public function analyzeRechtsfrage(array $rf, array $analytics): array {
        $safeguards = $this->seoConfig['safeguards'];

        $clicks = $analytics['clicks'] ?? 0;
        $impressions = $analytics['impressions'] ?? 0;
        $ctr = $analytics['ctr'] ?? 0.0;
        $position = $analytics['avg_position'] ?? 0.0;

        // Check if page exists and how old it is
        $page = $this->db->fetchOne(
            'SELECT generation_status, published_at, updated_at FROM rechtsfrage_pages WHERE rechtsfrage_id = ?',
            [$rf['id']]
        );

        // No page exists -> CREATE
        if (!$page || $page['generation_status'] === 'failed') {
            return ['action' => 'create', 'reason' => 'Keine Seite vorhanden', 'priority' => 100];
        }

        // Page exists but not published
        if ($page['generation_status'] !== 'published') {
            return ['action' => 'keep', 'reason' => 'Seite noch nicht veröffentlicht', 'priority' => 0];
        }

        // Calculate days since published
        $daysLive = (int) ((time() - strtotime($page['published_at'])) / 86400);

        // Too young to judge
        if ($daysLive < $safeguards['min_days_before_judging']) {
            return ['action' => 'keep', 'reason' => "Seite erst {$daysLive} Tage live (min {$safeguards['min_days_before_judging']})", 'priority' => 0];
        }

        // Check cooldowns (updated_at)
        if ($page['updated_at']) {
            $daysSinceUpdate = (int) ((time() - strtotime($page['updated_at'])) / 86400);
            if ($daysSinceUpdate < $safeguards['cooldown_after_optimize']) {
                return ['action' => 'keep', 'reason' => "Kürzlich optimiert (vor {$daysSinceUpdate} Tagen)", 'priority' => 0];
            }
        }

        // Count existing variation pages
        $varCount = (int) $this->db->fetchOne(
            'SELECT COUNT(*) as c FROM variation_pages WHERE rechtsfrage_id = ?',
            [$rf['id']]
        )['c'];

        // DECISION TREE
        if ($position > 0 && $position <= 3 && $ctr > 0.05) {
            return ['action' => 'keep', 'reason' => "Saturiert: Position {$position}, CTR " . round($ctr * 100, 1) . "%", 'priority' => 0];
        }

        if ($position > 0 && $position <= 3 && $ctr <= 0.05) {
            return ['action' => 'update', 'reason' => "Gute Position ({$position}) aber niedrige CTR - Title/Meta optimieren", 'priority' => 70];
        }

        if ($position > 3 && $position <= 10 && $varCount < 5) {
            return ['action' => 'expand', 'reason' => "Position {$position}, nur {$varCount} Variationen - Tier 2 Städte hinzufügen", 'priority' => 60];
        }

        if ($position > 3 && $position <= 10) {
            return ['action' => 'update', 'reason' => "Position {$position} mit {$varCount} Variationen - Content optimieren", 'priority' => 50];
        }

        if ($position > 10 && $position <= 30) {
            return ['action' => 'expand', 'reason' => "Position {$position} - Aggressiv expandieren mit mehr Variationen", 'priority' => 40];
        }

        if ($position > 30 && $impressions > 0) {
            return ['action' => 'update', 'reason' => "Schlechte Position ({$position}) trotz {$impressions} Impressionen - Content regenerieren", 'priority' => 30];
        }

        // No impressions after min_days_before_delete
        if ($impressions === 0 && $daysLive >= $safeguards['min_days_before_delete']) {
            return ['action' => 'delete', 'reason' => "0 Impressionen nach {$daysLive} Tagen", 'priority' => 10];
        }

        return ['action' => 'keep', 'reason' => 'Keine Aktion erforderlich', 'priority' => 0];
    }

    /**
     * Get cities that should be added as new variations for a Rechtsfrage.
     * Based on the Rechtsfrage's position performance.
     */
    public function getExpansionCities(array $rf): array {
        $position = $rf['avg_position'] ?? 999;
        $rfId = $rf['id'];

        // Get rechtsgebiet_id for this rechtsfrage
        $rgId = $rf['rechtsgebiet_id'];

        // Get existing variation city slugs for this rechtsfrage
        $existing = $this->db->fetchAll(
            'SELECT vv.slug FROM variation_pages vp
             INNER JOIN variation_values vv ON vp.variation_value_id = vv.id
             WHERE vp.rechtsfrage_id = ?',
            [$rfId]
        );
        $existingSlugs = array_column($existing, 'slug');

        $citiesToAdd = [];

        if ($position < 20) {
            // Add Tier 2 cities not yet created
            $tier2 = $this->db->fetchAll(
                'SELECT vv.* FROM variation_values vv
                 INNER JOIN variation_types vt ON vv.variation_type_id = vt.id
                 WHERE vt.rechtsgebiet_id = ? AND vv.tier = 2',
                [$rgId]
            );
            foreach ($tier2 as $v) {
                if (!in_array($v['slug'], $existingSlugs)) {
                    $citiesToAdd[] = $v;
                }
            }
        }

        if ($position < 10) {
            // Also add Tier 3
            $tier3 = $this->db->fetchAll(
                'SELECT vv.* FROM variation_values vv
                 INNER JOIN variation_types vt ON vv.variation_type_id = vt.id
                 WHERE vt.rechtsgebiet_id = ? AND vv.tier = 3',
                [$rgId]
            );
            foreach ($tier3 as $v) {
                if (!in_array($v['slug'], $existingSlugs)) {
                    $citiesToAdd[] = $v;
                }
            }
        }

        return $citiesToAdd;
    }
}
