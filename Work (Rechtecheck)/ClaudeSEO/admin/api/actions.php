<?php
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../lib/Database.php';

$db = Database::getInstance();

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['action'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing required field: action']);
        exit;
    }

    $action = $input['action'];
    $cronDir = __DIR__ . '/../../cron';

    $allowedActions = [
        'generate_all',
        'publish_all',
        'sync_gsc',
        'run_analyzer',
        'generate_sitemap',
        'recalculate_scores',
    ];

    if (!in_array($action, $allowedActions, true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid action. Allowed: ' . implode(', ', $allowedActions)]);
        exit;
    }

    // Handle recalculate_scores inline (no cron script)
    if ($action === 'recalculate_scores') {
        $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));

        // Recalculate performance scores for rechtsgebiete
        $rechtsgebiete = $db->fetchAll('SELECT id FROM rechtsgebiete');
        foreach ($rechtsgebiete as $rg) {
            $analytics = $db->fetchOne(
                "SELECT
                    COALESCE(SUM(clicks), 0) AS total_clicks,
                    COALESCE(SUM(impressions), 0) AS total_impressions,
                    COALESCE(AVG(position), 0) AS avg_position,
                    COALESCE(AVG(ctr), 0) AS avg_ctr
                FROM page_analytics pa
                INNER JOIN rechtsgebiet_pages rp ON pa.page_id = rp.id AND pa.page_type = 'rechtsgebiet'
                WHERE rp.rechtsgebiet_id = ? AND pa.date >= ?",
                [$rg['id'], $thirtyDaysAgo]
            );

            $score = 0;
            if ($analytics) {
                // Score formula: weighted combination of clicks, CTR, and position
                $score = (int) round(
                    ($analytics['total_clicks'] * 2)
                    + ($analytics['avg_ctr'] * 1000)
                    + (max(0, 100 - $analytics['avg_position']) * 0.5)
                );
            }

            $db->update('rechtsgebiete', [
                'total_clicks'      => $analytics['total_clicks'] ?? 0,
                'performance_score' => $score,
            ], 'id = ?', [$rg['id']]);
        }

        // Recalculate performance scores for rechtsfragen
        $rechtsfragen = $db->fetchAll('SELECT id FROM rechtsfragen');
        foreach ($rechtsfragen as $rf) {
            $analytics = $db->fetchOne(
                "SELECT
                    COALESCE(SUM(clicks), 0) AS total_clicks,
                    COALESCE(SUM(impressions), 0) AS total_impressions,
                    COALESCE(AVG(position), 0) AS avg_position,
                    COALESCE(AVG(ctr), 0) AS avg_ctr
                FROM page_analytics pa
                INNER JOIN rechtsfrage_pages rfp ON pa.page_id = rfp.id AND pa.page_type = 'rechtsfrage'
                WHERE rfp.rechtsfrage_id = ? AND pa.date >= ?",
                [$rf['id'], $thirtyDaysAgo]
            );

            $score = 0;
            if ($analytics) {
                $score = (int) round(
                    ($analytics['total_clicks'] * 2)
                    + ($analytics['avg_ctr'] * 1000)
                    + (max(0, 100 - $analytics['avg_position']) * 0.5)
                );
            }

            $db->update('rechtsfragen', [
                'total_clicks'      => $analytics['total_clicks'] ?? 0,
                'performance_score' => $score,
            ], 'id = ?', [$rf['id']]);
        }

        echo json_encode(['status' => 'success', 'message' => 'Task completed: recalculate_scores']);
        exit;
    }

    // Map action to cron script
    switch ($action) {
        case 'generate_all':
            $cmd = 'php ' . escapeshellarg($cronDir . '/phase1_generate.php');
            break;
        case 'publish_all':
            $cmd = 'php ' . escapeshellarg($cronDir . '/phase2_publish.php');
            break;
        case 'sync_gsc':
            $cmd = 'php ' . escapeshellarg($cronDir . '/phase3_analytics.php');
            break;
        case 'run_analyzer':
            $cmd = 'php ' . escapeshellarg($cronDir . '/phase4_optimize.php');
            break;
        case 'generate_sitemap':
            $cmd = 'php ' . escapeshellarg($cronDir . '/phase5_sitemap.php');
            break;
    }

    // Run in background (platform-aware)
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        pclose(popen("start /B $cmd > NUL 2>&1", 'r'));
    } else {
        exec("$cmd > /dev/null 2>&1 &");
    }

    echo json_encode(['status' => 'success', 'message' => "Task started: {$action}"]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
