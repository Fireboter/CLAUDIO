<?php
/**
 * Cron Phase 3 - Analytics / Google Search Console
 *
 * Fetches page-level and query-level performance data from Google Search
 * Console, stores it in page_analytics, and updates aggregate metrics on
 * rechtsgebiete and rechtsfragen.
 *
 * The script fetches the last 3 days of data to account for GSC reporting
 * delays (data is typically available with a 2-day lag).
 *
 * Usage:
 *   php cron/phase3_analytics.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/CronLock.php';
require_once __DIR__ . '/../lib/SearchConsole.php';

// ---------------------------------------------------------------------------
// Acquire lock
// ---------------------------------------------------------------------------

$lock = new CronLock('phase3_analytics');
if (!$lock->acquire()) {
    echo "[phase3_analytics] Another instance is already running. Exiting.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Initialize
// ---------------------------------------------------------------------------

$db = Database::getInstance();
$processed = 0;
$errors    = 0;
$notes     = [];
$cronLogId = null;

try {
    // Log start
    $cronLogId = $db->insert('cron_log', [
        'phase'           => 'phase3_analytics',
        'started_at'      => date('Y-m-d H:i:s'),
        'items_processed' => 0,
        'errors'          => 0,
        'notes'           => '',
    ]);

    echo "[phase3_analytics] Started at " . date('Y-m-d H:i:s') . "\n";

    // -----------------------------------------------------------------------
    // Check GSC configuration
    // -----------------------------------------------------------------------

    $gsc = new SearchConsole();

    if (!$gsc->isConfigured()) {
        $msg = 'GSC not configured -- credentials missing or incomplete. Exiting gracefully.';
        echo "[phase3_analytics] {$msg}\n";
        $notes[] = $msg;

        $db->update('cron_log', [
            'ended_at' => date('Y-m-d H:i:s'),
            'notes'    => implode('; ', $notes),
        ], 'id = ?', [$cronLogId]);

        $lock->release();
        exit(0);
    }

    // -----------------------------------------------------------------------
    // Determine date range (last 3 days to catch GSC delays)
    // -----------------------------------------------------------------------

    $endDate   = date('Y-m-d', strtotime('-1 day'));
    $startDate = date('Y-m-d', strtotime('-3 days'));

    echo "[phase3_analytics] Fetching data from {$startDate} to {$endDate}\n";

    // -----------------------------------------------------------------------
    // Fetch and store page performance data day-by-day
    // -----------------------------------------------------------------------

    $currentDate = $startDate;
    while ($currentDate <= $endDate) {
        echo "  Fetching performance data for {$currentDate}... ";

        try {
            $data  = $gsc->fetchPerformanceData($currentDate, $currentDate);
            $count = $gsc->storePerformanceData($data, $currentDate);
            $processed += $count;
            echo "stored {$count} rows (of " . count($data) . " URLs)\n";
        } catch (\Exception $e) {
            echo "FAILED: {$e->getMessage()}\n";
            $errors++;
            $notes[] = "Performance fetch failed for {$currentDate}: {$e->getMessage()}";
        }

        $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
    }

    // -----------------------------------------------------------------------
    // Fetch query data (full range) for opportunity discovery
    // -----------------------------------------------------------------------

    echo "  Fetching query data for {$startDate} to {$endDate}... ";

    try {
        $queryData = $gsc->fetchQueryData($startDate, $endDate);
        $queryCount = count($queryData);
        echo "received {$queryCount} queries\n";

        // Log top queries with high impressions but low CTR (ranking opportunities)
        if ($queryCount > 0) {
            $opportunities = [];
            foreach ($queryData as $query => $metrics) {
                // Position 5-20 with decent impressions = improvement opportunity
                if ($metrics['position'] >= 5.0
                    && $metrics['position'] <= 20.0
                    && $metrics['impressions'] >= 10
                ) {
                    $opportunities[] = [
                        'query'       => $query,
                        'position'    => round($metrics['position'], 1),
                        'impressions' => $metrics['impressions'],
                        'clicks'      => $metrics['clicks'],
                    ];
                }
            }

            // Sort opportunities by impressions descending
            usort($opportunities, fn($a, $b) => $b['impressions'] <=> $a['impressions']);
            $topOpportunities = array_slice($opportunities, 0, 10);

            if (count($topOpportunities) > 0) {
                echo "  Top ranking opportunities:\n";
                foreach ($topOpportunities as $opp) {
                    echo "    - \"{$opp['query']}\" pos:{$opp['position']} imp:{$opp['impressions']} clicks:{$opp['clicks']}\n";
                }
                $notes[] = count($opportunities) . ' ranking opportunities found';
            }
        }
    } catch (\Exception $e) {
        echo "FAILED: {$e->getMessage()}\n";
        $errors++;
        $notes[] = "Query fetch failed: {$e->getMessage()}";
    }

    // -----------------------------------------------------------------------
    // Update aggregate metrics on rechtsgebiete and rechtsfragen
    // -----------------------------------------------------------------------

    echo "  Updating aggregate metrics... ";

    try {
        $gsc->updateAggregateMetrics();
        echo "OK\n";
    } catch (\Exception $e) {
        echo "FAILED: {$e->getMessage()}\n";
        $errors++;
        $notes[] = "Aggregate update failed: {$e->getMessage()}";
    }

} catch (\Exception $e) {
    // Catch any unexpected fatal error
    echo "[phase3_analytics] FATAL ERROR: {$e->getMessage()}\n";
    $errors++;
    $notes[] = "FATAL: {$e->getMessage()}";
} finally {
    // -----------------------------------------------------------------------
    // Log completion
    // -----------------------------------------------------------------------
    if ($cronLogId) {
        $db->update('cron_log', [
            'ended_at'        => date('Y-m-d H:i:s'),
            'items_processed' => $processed,
            'errors'          => $errors,
            'notes'           => implode('; ', $notes),
        ], 'id = ?', [$cronLogId]);
    }

    // Release lock
    $lock->release();
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo "\n========================================\n";
echo "[phase3_analytics] Completed at " . date('Y-m-d H:i:s') . "\n";
echo "[phase3_analytics] Pages stored: {$processed}\n";
echo "[phase3_analytics] Errors: {$errors}\n";
echo "========================================\n";
