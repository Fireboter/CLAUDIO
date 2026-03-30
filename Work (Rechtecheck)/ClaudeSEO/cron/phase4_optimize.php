<?php
/**
 * Cron Phase 4 - SEO Optimization
 *
 * Builds a priority queue by analyzing all rechtsfragen against their
 * analytics data, then executes the top-priority decisions (expand, update,
 * delete, create, keep).
 *
 * - 'expand'  : inserts new variation_pages with generation_status='pending'
 *               so Phase 1 picks them up for generation.
 * - 'update'  : resets rechtsfrage_pages generation_status to 'pending'
 *               so Phase 1 regenerates the content.
 * - 'delete'  : sets rechtsfrage status='unpublished' and resets the page.
 * - 'create'  : no-op here -- Phase 1 will pick up rechtsfragen without pages.
 * - 'keep'    : no-op, just marks the decision executed.
 *
 * Usage:
 *   php cron/phase4_optimize.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/CronLock.php';
require_once __DIR__ . '/../lib/SeoAnalyzer.php';
require_once __DIR__ . '/../lib/PriorityQueue.php';

// ---------------------------------------------------------------------------
// Acquire lock
// ---------------------------------------------------------------------------

$lock = new CronLock('phase4_optimize');
if (!$lock->acquire()) {
    echo "[phase4_optimize] Another instance is already running. Exiting.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Initialize
// ---------------------------------------------------------------------------

$db        = Database::getInstance();
$analyzer  = new SeoAnalyzer();
$queue     = new PriorityQueue();
$processed = 0;
$errors    = 0;
$notes     = [];
$cronLogId = null;

try {
    // Log start
    $cronLogId = $db->insert('cron_log', [
        'phase'           => 'phase4_optimize',
        'started_at'      => date('Y-m-d H:i:s'),
        'items_processed' => 0,
        'errors'          => 0,
        'notes'           => '',
    ]);

    echo "[phase4_optimize] Started at " . date('Y-m-d H:i:s') . "\n";

    // -----------------------------------------------------------------------
    // Build priority queue
    // -----------------------------------------------------------------------

    echo "[phase4_optimize] Building priority queue...\n";
    $total = $queue->buildQueue();
    echo "[phase4_optimize] Built queue with {$total} decisions\n";

    // -----------------------------------------------------------------------
    // Process top 10 items
    // -----------------------------------------------------------------------

    $items = $queue->getNextItems(10);
    echo "[phase4_optimize] Processing " . count($items) . " items\n\n";

    foreach ($items as $item) {
        $label = "[{$item['action']}] {$item['rf_name']} (ID:{$item['page_id']}, priority:{$item['priority_score']})";
        echo "  {$label}\n";
        echo "    Reason: {$item['reason']}\n";

        try {
            switch ($item['action']) {

                case 'expand':
                    // Get the full rechtsfrage row for expansion analysis
                    $rf = $db->fetchOne(
                        'SELECT * FROM rechtsfragen WHERE id = ?',
                        [$item['page_id']]
                    );

                    if (!$rf) {
                        throw new RuntimeException("Rechtsfrage ID {$item['page_id']} not found");
                    }

                    $cities = $analyzer->getExpansionCities($rf);
                    $cityCount = 0;

                    foreach ($cities as $city) {
                        // Check if variation_page already exists
                        $existing = $db->fetchOne(
                            'SELECT id FROM variation_pages WHERE rechtsfrage_id = ? AND variation_value_id = ?',
                            [$rf['id'], $city['id']]
                        );

                        if (!$existing) {
                            $db->insert('variation_pages', [
                                'rechtsfrage_id'    => $rf['id'],
                                'variation_value_id' => $city['id'],
                                'generation_status' => 'pending',
                                'created_at'        => date('Y-m-d H:i:s'),
                            ]);
                            $cityCount++;
                        }
                    }

                    echo "    -> Created {$cityCount} new variation pages (pending generation)\n";
                    $notes[] = "expand:{$item['rf_name']}={$cityCount} cities";
                    $queue->markExecuted($item['id']);
                    break;

                case 'update':
                    // Reset generation_status to 'pending' so Phase 1 regenerates
                    $updated = $db->update('rechtsfrage_pages',
                        [
                            'generation_status' => 'pending',
                            'updated_at'        => date('Y-m-d H:i:s'),
                        ],
                        'rechtsfrage_id = ?',
                        [$item['page_id']]
                    );

                    echo "    -> Set {$updated} page(s) to pending for regeneration\n";
                    $notes[] = "update:{$item['rf_name']}";
                    $queue->markExecuted($item['id']);
                    break;

                case 'delete':
                    // Set rechtsfrage status to unpublished
                    $db->update('rechtsfragen',
                        ['status' => 'unpublished'],
                        'id = ?',
                        [$item['page_id']]
                    );

                    // Reset page generation_status
                    $db->update('rechtsfrage_pages',
                        [
                            'generation_status' => 'unpublished',
                            'updated_at'        => date('Y-m-d H:i:s'),
                        ],
                        'rechtsfrage_id = ?',
                        [$item['page_id']]
                    );

                    echo "    -> Unpublished rechtsfrage and page\n";
                    $notes[] = "delete:{$item['rf_name']}";
                    $queue->markExecuted($item['id']);
                    break;

                case 'create':
                    // No action needed -- Phase 1 picks up rechtsfragen without pages
                    echo "    -> No action (Phase 1 will create page automatically)\n";
                    $notes[] = "create:{$item['rf_name']}";
                    $queue->markExecuted($item['id']);
                    break;

                case 'keep':
                    echo "    -> No action needed\n";
                    $queue->markExecuted($item['id']);
                    break;

                default:
                    echo "    -> Unknown action '{$item['action']}', skipping\n";
                    $notes[] = "unknown_action:{$item['action']}";
                    break;
            }

            $processed++;
        } catch (Exception $e) {
            echo "    -> FAILED: {$e->getMessage()}\n";
            $errors++;
            $notes[] = "error:{$item['rf_name']}={$e->getMessage()}";
        }

        echo "\n";
    }

} catch (Exception $e) {
    // Catch any unexpected fatal error
    echo "[phase4_optimize] FATAL ERROR: {$e->getMessage()}\n";
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

echo "========================================\n";
echo "[phase4_optimize] Completed at " . date('Y-m-d H:i:s') . "\n";
echo "[phase4_optimize] Queue size: {$total}\n";
echo "[phase4_optimize] Items processed: {$processed}\n";
echo "[phase4_optimize] Errors: {$errors}\n";
echo "========================================\n";
