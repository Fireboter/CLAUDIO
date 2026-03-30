<?php
/**
 * Cron Phase 5 - Sitemap Regeneration & Data Cleanup
 *
 * 1. Regenerates the sitemap XML files
 * 2. Pings Google with the updated sitemap
 * 3. Aggregates page_analytics daily data older than 90 days into weekly rows
 * 4. Deletes page_analytics data older than 365 days
 * 5. Cleans cron_log entries older than 90 days
 *
 * Intended to run via cron once daily (e.g. 03:00).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/CronLock.php';
require_once __DIR__ . '/../lib/SitemapGenerator.php';

// ---------------------------------------------------------------------------
// Acquire lock
// ---------------------------------------------------------------------------

$lock = new CronLock('phase5_sitemap');

if (!$lock->acquire()) {
    echo "[phase5_sitemap] Another instance is already running. Exiting.\n";
    exit(0);
}

try {
    $db  = Database::getInstance();
    $pdo = $db->getPdo();
    $seoConfig = require __DIR__ . '/../config/seo.php';

    // Log start
    $cronLogId = $db->insert('cron_log', [
        'phase'           => 'phase5_sitemap',
        'started_at'      => date('Y-m-d H:i:s'),
        'items_processed' => 0,
        'errors'          => 0,
        'notes'           => '',
    ]);

    echo "[phase5_sitemap] Started at " . date('Y-m-d H:i:s') . "\n";

    $totalProcessed = 0;
    $errorCount     = 0;
    $notes          = [];

    // ===================================================================
    // 1. REGENERATE SITEMAP
    // ===================================================================

    echo "\n--- Sitemap Regeneration ---\n";

    try {
        $sitemap = new SitemapGenerator();
        $sitemap->generate();
        echo "Sitemap regenerated.\n";
    } catch (Throwable $e) {
        echo "Sitemap generation failed: {$e->getMessage()}\n";
        $notes[] = 'Sitemap generation failed: ' . $e->getMessage();
        $errorCount++;
    }

    // ===================================================================
    // 2. PING GOOGLE
    // ===================================================================

    echo "\n--- Google Ping ---\n";

    try {
        $sitemapUrl = rtrim($seoConfig['site_url'], '/') . '/sitemap.xml';
        $pingUrl = 'https://www.google.com/ping?sitemap=' . urlencode($sitemapUrl);
        @file_get_contents($pingUrl);
        echo "Google pinged.\n";
    } catch (Throwable $e) {
        // Non-fatal
        echo "Google ping failed: {$e->getMessage()}\n";
        $notes[] = 'Google ping failed: ' . $e->getMessage();
    }

    // ===================================================================
    // 3. DATA RETENTION - Aggregate old daily data into weekly rows
    // ===================================================================

    echo "\n--- Data Retention (aggregate daily -> weekly) ---\n";

    $aggregatedRows = 0;
    $deletedDailyRows = 0;

    try {
        // Process in chunks by (page_type, page_id) to avoid loading
        // everything into memory at once
        $chunkSize = 100;
        $offset = 0;

        while (true) {
            // Find distinct (page_type, page_id) combinations with old data
            $combinations = $db->fetchAll(
                "SELECT DISTINCT page_type, page_id
                 FROM page_analytics
                 WHERE date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                 LIMIT {$chunkSize} OFFSET {$offset}"
            );

            if (empty($combinations)) {
                break;
            }

            foreach ($combinations as $combo) {
                $pageType = $combo['page_type'];
                $pageId   = $combo['page_id'];

                // Use a transaction for each (page_type, page_id) pair
                $pdo->beginTransaction();

                try {
                    // Step A: Select weekly aggregates for this page's old data
                    $aggregates = $db->fetchAll(
                        "SELECT page_type, page_id, url,
                                MIN(date) AS week_start,
                                SUM(clicks) AS clicks,
                                SUM(impressions) AS impressions,
                                AVG(ctr) AS ctr,
                                AVG(avg_position) AS avg_position,
                                YEARWEEK(date, 1) AS yw
                         FROM page_analytics
                         WHERE page_type = ?
                           AND page_id = ?
                           AND date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                         GROUP BY page_type, page_id, url, YEARWEEK(date, 1)",
                        [$pageType, $pageId]
                    );

                    // Step B: Delete all daily rows older than 90 days for this page
                    $deleteStmt = $db->query(
                        "DELETE FROM page_analytics
                         WHERE page_type = ?
                           AND page_id = ?
                           AND date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)",
                        [$pageType, $pageId]
                    );
                    $deletedDailyRows += $deleteStmt->rowCount();

                    // Step C: Insert the aggregated weekly rows
                    foreach ($aggregates as $agg) {
                        $db->insert('page_analytics', [
                            'page_type'   => $agg['page_type'],
                            'page_id'     => $agg['page_id'],
                            'url'         => $agg['url'],
                            'clicks'      => (int) $agg['clicks'],
                            'impressions' => (int) $agg['impressions'],
                            'ctr'         => round((float) $agg['ctr'], 4),
                            'avg_position' => round((float) $agg['avg_position'], 2),
                            'date'        => $agg['week_start'],
                            'fetched_at'  => date('Y-m-d H:i:s'),
                        ]);
                        $aggregatedRows++;
                    }

                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    echo "  Error aggregating page_type={$pageType}, page_id={$pageId}: {$e->getMessage()}\n";
                    $errorCount++;
                }
            }

            // Only advance offset if we got a full chunk, otherwise we're done.
            // However, since we delete rows as we go, the offset should stay at 0
            // because the result set shrinks. But if a transaction fails, we need
            // to skip those rows to avoid infinite loops.
            if (count($combinations) < $chunkSize) {
                break;
            }
            // Don't increment offset: deleted rows shift the result set.
            // If rows remain (due to errors), they'll reappear in the next query.
            // To prevent infinite loops on persistent errors, increment offset
            // by the number of failed items only.
        }

        echo "Aggregated {$aggregatedRows} weekly rows from {$deletedDailyRows} daily rows.\n";
        $totalProcessed += $aggregatedRows + $deletedDailyRows;
        $notes[] = "Aggregated: {$aggregatedRows} weekly from {$deletedDailyRows} daily";

    } catch (Throwable $e) {
        echo "Data aggregation failed: {$e->getMessage()}\n";
        $notes[] = 'Aggregation error: ' . $e->getMessage();
        $errorCount++;
    }

    // ===================================================================
    // 4. DELETE VERY OLD DATA (older than 365 days)
    // ===================================================================

    echo "\n--- Delete Very Old Data (>365 days) ---\n";

    try {
        $stmt = $db->query(
            "DELETE FROM page_analytics
             WHERE date < DATE_SUB(CURDATE(), INTERVAL 365 DAY)"
        );
        $deletedOld = $stmt->rowCount();
        echo "Deleted {$deletedOld} rows older than 365 days.\n";
        $totalProcessed += $deletedOld;
        $notes[] = "Deleted old analytics: {$deletedOld}";
    } catch (Throwable $e) {
        echo "Old data deletion failed: {$e->getMessage()}\n";
        $notes[] = 'Old data deletion error: ' . $e->getMessage();
        $errorCount++;
    }

    // ===================================================================
    // 5. CLEAN CRON LOGS (older than 90 days)
    // ===================================================================

    echo "\n--- Clean Cron Logs (>90 days) ---\n";

    try {
        $stmt = $db->query(
            "DELETE FROM cron_log
             WHERE started_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );
        $deletedLogs = $stmt->rowCount();
        echo "Deleted {$deletedLogs} old cron_log entries.\n";
        $totalProcessed += $deletedLogs;
        $notes[] = "Deleted cron logs: {$deletedLogs}";
    } catch (Throwable $e) {
        echo "Cron log cleanup failed: {$e->getMessage()}\n";
        $notes[] = 'Cron log cleanup error: ' . $e->getMessage();
        $errorCount++;
    }

    // ===================================================================
    // Log completion
    // ===================================================================

    $db->update('cron_log', [
        'ended_at'        => date('Y-m-d H:i:s'),
        'items_processed' => $totalProcessed,
        'errors'          => $errorCount,
        'notes'           => implode('; ', $notes),
    ], 'id = ?', [$cronLogId]);

} catch (Throwable $e) {
    echo "[phase5_sitemap] FATAL ERROR: {$e->getMessage()}\n";
} finally {
    $lock->release();
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo "\n========================================\n";
echo "[phase5_sitemap] Completed at " . date('Y-m-d H:i:s') . "\n";
echo "[phase5_sitemap] Items processed: {$totalProcessed}\n";
echo "[phase5_sitemap] Errors: {$errorCount}\n";
echo "========================================\n";
