<?php
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../lib/Database.php';

$db = Database::getInstance();
$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        case 'summary':
            // Total pages across all 3 page tables
            $totalPages = $db->fetchOne("
                SELECT
                    (SELECT COUNT(*) FROM rechtsgebiet_pages) +
                    (SELECT COUNT(*) FROM rechtsfrage_pages) +
                    (SELECT COUNT(*) FROM variation_pages) AS total
            ")['total'] ?? 0;

            // Published pages
            $publishedPages = $db->fetchOne("
                SELECT
                    (SELECT COUNT(*) FROM rechtsgebiet_pages WHERE generation_status = 'published') +
                    (SELECT COUNT(*) FROM rechtsfrage_pages WHERE generation_status = 'published') +
                    (SELECT COUNT(*) FROM variation_pages WHERE generation_status = 'published') AS total
            ")['total'] ?? 0;

            // 30-day analytics
            $analytics30d = $db->fetchOne("
                SELECT
                    COALESCE(SUM(clicks), 0) AS total_clicks,
                    COALESCE(SUM(impressions), 0) AS total_impressions,
                    COALESCE(AVG(ctr), 0) AS avg_ctr,
                    COALESCE(AVG(avg_position), 0) AS avg_position
                FROM page_analytics
                WHERE date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ");

            // Pages generated today
            $generatedToday = $db->fetchOne("
                SELECT
                    (SELECT COUNT(*) FROM rechtsgebiet_pages WHERE DATE(created_at) = CURDATE()) +
                    (SELECT COUNT(*) FROM rechtsfrage_pages WHERE DATE(created_at) = CURDATE()) +
                    (SELECT COUNT(*) FROM variation_pages WHERE DATE(created_at) = CURDATE()) AS total
            ")['total'] ?? 0;

            // API usage today
            $apiUsage = $db->fetchOne("
                SELECT COALESCE(SUM(calls_count), 0) AS calls, COALESCE(SUM(cost_cents), 0) AS cost
                FROM api_usage
                WHERE date = CURDATE()
            ");

            echo json_encode([
                'total_pages'          => (int) $totalPages,
                'published_pages'      => (int) $publishedPages,
                'total_clicks_30d'     => (int) ($analytics30d['total_clicks'] ?? 0),
                'total_impressions_30d'=> (int) ($analytics30d['total_impressions'] ?? 0),
                'avg_ctr'              => (float) ($analytics30d['avg_ctr'] ?? 0),
                'avg_position'         => (float) ($analytics30d['avg_position'] ?? 0),
                'pages_generated_today'=> (int) $generatedToday,
                'api_calls_today'      => (int) ($apiUsage['calls'] ?? 0),
                'api_cost_today'       => (float) ($apiUsage['cost'] ?? 0),
            ]);
            break;

        case 'trends':
            $days = (int) ($_GET['days'] ?? 30);
            if (!in_array($days, [30, 60, 90])) {
                $days = 30;
            }

            $trends = $db->fetchAll("
                SELECT date, SUM(clicks) AS clicks, SUM(impressions) AS impressions
                FROM page_analytics
                WHERE date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                GROUP BY date
                ORDER BY date ASC
            ", [$days]);

            echo json_encode($trends);
            break;

        case 'recommendations':
            $recommendations = $db->fetchAll("
                SELECT pd.*,
                    CASE pd.page_type
                        WHEN 'rechtsfrage' THEN (SELECT name FROM rechtsfragen WHERE id = pd.page_id)
                        WHEN 'rechtsgebiet' THEN (SELECT name FROM rechtsgebiete WHERE id = pd.page_id)
                    END AS page_name
                FROM page_decisions pd
                ORDER BY pd.priority_score DESC, pd.decided_at DESC
                LIMIT 50
            ");

            // Map action to color
            $colorMap = [
                'keep'   => 'green',
                'update' => 'yellow',
                'delete' => 'red',
                'create' => 'blue',
                'expand' => 'purple',
            ];

            foreach ($recommendations as &$rec) {
                $rec['color'] = $colorMap[$rec['action'] ?? ''] ?? 'blue';
            }
            unset($rec);

            echo json_encode($recommendations);
            break;

        case 'top':
            $topPerformers = $db->fetchAll("
                SELECT pa.page_type, pa.page_id, pa.url,
                    SUM(pa.clicks) AS total_clicks, SUM(pa.impressions) AS total_impressions,
                    AVG(pa.ctr) AS avg_ctr, AVG(pa.avg_position) AS avg_position,
                    CASE pa.page_type
                        WHEN 'rechtsfrage' THEN (SELECT name FROM rechtsfragen WHERE id = pa.page_id)
                        WHEN 'rechtsgebiet' THEN (SELECT name FROM rechtsgebiete WHERE id = pa.page_id)
                    END AS page_name
                FROM page_analytics pa
                WHERE pa.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY pa.page_type, pa.page_id, pa.url
                ORDER BY total_clicks DESC
                LIMIT 20
            ");

            echo json_encode($topPerformers);
            break;

        case 'opportunities':
            // This will be populated when query data storage is added.
            // For now, return an empty array.
            echo json_encode([]);
            break;

        case 'api_costs':
            $days = (int) ($_GET['days'] ?? 30);
            if ($days < 1 || $days > 90) $days = 30;

            $rows = $db->fetchAll("
                SELECT date, api_name, calls_count, tokens_used, cost_cents
                FROM api_usage
                WHERE date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                ORDER BY date DESC
            ", [$days]);

            $totals = $db->fetchOne("
                SELECT
                    COALESCE(SUM(calls_count), 0) AS total_calls,
                    COALESCE(SUM(tokens_used), 0) AS total_tokens,
                    COALESCE(SUM(cost_cents), 0) AS total_cost_cents
                FROM api_usage
                WHERE date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            ", [$days]);

            echo json_encode([
                'rows'   => $rows,
                'totals' => $totals,
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action: ' . $action]);
            break;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}
