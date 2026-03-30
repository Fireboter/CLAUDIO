<?php
/**
 * Cron Phase 1 - Content Generation
 *
 * Generates content for rechtsgebiet, rechtsfrage, and variation pages
 * using the Claude API. Processes items in batches with budget and limit controls.
 *
 * Usage:
 *   php cron/phase1_generate.php [--limit=N]
 *
 * Options:
 *   --limit=N  Process at most N items total across all page types (default: unlimited)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/CronLock.php';
require_once __DIR__ . '/../lib/ContentGenerator.php';
require_once __DIR__ . '/../lib/AIProvider.php';
require_once __DIR__ . '/../lib/ProviderFactory.php';
require_once __DIR__ . '/../lib/Providers/ClaudeProvider.php';
require_once __DIR__ . '/../lib/Providers/OpenAIProvider.php';

// ---------------------------------------------------------------------------
// Parse CLI arguments
// ---------------------------------------------------------------------------

$limit = null;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

// ---------------------------------------------------------------------------
// Acquire lock
// ---------------------------------------------------------------------------

$lock = new CronLock('phase1_generate');
if (!$lock->acquire()) {
    echo "[phase1_generate] Another instance is already running. Exiting.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Initialize
// ---------------------------------------------------------------------------

$db  = Database::getInstance();
$gen = new ContentGenerator(ProviderFactory::make());

$processed = 0;
$errors    = 0;
$cronLogId = null;

try {
    // Log start
    $cronLogId = $db->insert('cron_log', [
        'phase'      => 'phase1_generate',
        'started_at' => date('Y-m-d H:i:s'),
        'items_processed' => 0,
        'errors'     => 0,
        'notes'      => '',
    ]);

    echo "[phase1_generate] Started at " . date('Y-m-d H:i:s') . "\n";
    if ($limit !== null) {
        echo "[phase1_generate] Limit: {$limit} items\n";
    }

    // Check daily budget before starting
    if (!$gen->checkDailyBudget()) {
        echo "[phase1_generate] Daily API budget exceeded. Exiting gracefully.\n";
        $db->update('cron_log', [
            'ended_at' => date('Y-m-d H:i:s'),
            'notes'    => 'Budget exceeded before start',
        ], 'id = ?', [$cronLogId]);
        $lock->release();
        exit(0);
    }

    // -----------------------------------------------------------------------
    // Helper: check whether we should continue processing
    // -----------------------------------------------------------------------
    $shouldContinue = function () use (&$processed, $limit, $gen): bool {
        if ($limit !== null && $processed >= $limit) {
            return false;
        }
        if (!$gen->checkDailyBudget()) {
            echo "[phase1_generate] Daily API budget exceeded. Stopping.\n";
            return false;
        }
        return true;
    };

    // =======================================================================
    // 1. RECHTSGEBIET PAGES (batch of 5)
    // =======================================================================

    if ($shouldContinue()) {
        echo "\n--- Rechtsgebiet Pages ---\n";

        $rechtsgebiete = $db->fetchAll(
            'SELECT rg.* FROM rechtsgebiete rg
             LEFT JOIN rechtsgebiet_pages rp ON rg.id = rp.rechtsgebiet_id
             WHERE rp.id IS NULL
             LIMIT 5'
        );

        foreach ($rechtsgebiete as $rg) {
            if (!$shouldContinue()) {
                break;
            }

            echo "  Generating rechtsgebiet: {$rg['name']} (ID: {$rg['id']})... ";

            // Insert placeholder row
            $pageId = $db->insert('rechtsgebiet_pages', [
                'rechtsgebiet_id'   => $rg['id'],
                'generation_status' => 'generating',
                'generated_by'      => 'cron_phase1',
                'created_at'        => date('Y-m-d H:i:s'),
            ]);

            try {
                // Fetch all rechtsfragen for this rechtsgebiet
                $rechtsfragen = $db->fetchAll(
                    'SELECT * FROM rechtsfragen WHERE rechtsgebiet_id = ?',
                    [$rg['id']]
                );

                $content = $gen->generateRechtsgebietContent($rg, $rechtsfragen);

                $db->update('rechtsgebiet_pages', [
                    'title'             => $content['title'],
                    'meta_description'  => $content['meta_description'],
                    'meta_keywords'     => $content['meta_keywords'],
                    'html_content'      => $content['html_content'],
                    'og_title'          => $content['og_title'],
                    'og_description'    => $content['og_description'],
                    'generation_status' => 'generated',
                    'updated_at'        => date('Y-m-d H:i:s'),
                ], 'id = ?', [$pageId]);

                echo "OK\n";
            } catch (Exception $e) {
                $db->update('rechtsgebiet_pages', [
                    'generation_status' => 'failed',
                    'updated_at'        => date('Y-m-d H:i:s'),
                ], 'id = ?', [$pageId]);

                echo "FAILED: {$e->getMessage()}\n";
                $errors++;
            }

            $processed++;
        }
    }

    // =======================================================================
    // 2. RECHTSFRAGE PAGES (batch of 10)
    // =======================================================================

    if ($shouldContinue()) {
        echo "\n--- Rechtsfrage Pages ---\n";

        $rechtsfragen = $db->fetchAll(
            'SELECT rf.* FROM rechtsfragen rf
             LEFT JOIN rechtsfrage_pages rfp ON rf.id = rfp.rechtsfrage_id
             WHERE rfp.id IS NULL
             LIMIT 10'
        );

        foreach ($rechtsfragen as $rf) {
            if (!$shouldContinue()) {
                break;
            }

            echo "  Generating rechtsfrage: {$rf['name']} (ID: {$rf['id']})... ";

            // Insert placeholder row
            $pageId = $db->insert('rechtsfrage_pages', [
                'rechtsfrage_id'    => $rf['id'],
                'generation_status' => 'generating',
                'generated_by'      => 'cron_phase1',
                'created_at'        => date('Y-m-d H:i:s'),
            ]);

            try {
                // Fetch parent rechtsgebiet
                $rg = $db->fetchOne(
                    'SELECT * FROM rechtsgebiete WHERE id = ?',
                    [$rf['rechtsgebiet_id']]
                );

                if (!$rg) {
                    throw new RuntimeException("Parent rechtsgebiet not found for rechtsfrage ID {$rf['id']}");
                }

                $content = $gen->generateRechtsfragContent($rf, $rg);

                $db->update('rechtsfrage_pages', [
                    'title'             => $content['title'],
                    'meta_description'  => $content['meta_description'],
                    'meta_keywords'     => $content['meta_keywords'],
                    'html_content'      => $content['html_content'],
                    'og_title'          => $content['og_title'],
                    'og_description'    => $content['og_description'],
                    'generation_status' => 'generated',
                    'updated_at'        => date('Y-m-d H:i:s'),
                ], 'id = ?', [$pageId]);

                echo "OK\n";
            } catch (Exception $e) {
                $db->update('rechtsfrage_pages', [
                    'generation_status' => 'failed',
                    'updated_at'        => date('Y-m-d H:i:s'),
                ], 'id = ?', [$pageId]);

                echo "FAILED: {$e->getMessage()}\n";
                $errors++;
            }

            $processed++;
        }
    }

} catch (Exception $e) {
    // Catch any unexpected fatal error
    echo "[phase1_generate] FATAL ERROR: {$e->getMessage()}\n";
    $errors++;
} finally {
    // -----------------------------------------------------------------------
    // Log completion
    // -----------------------------------------------------------------------
    if ($cronLogId) {
        $db->update('cron_log', [
            'ended_at'        => date('Y-m-d H:i:s'),
            'items_processed' => $processed,
            'errors'          => $errors,
            'notes'           => $limit !== null ? "Limit: {$limit}" : '',
        ], 'id = ?', [$cronLogId]);
    }

    // Release lock
    $lock->release();
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo "\n========================================\n";
echo "[phase1_generate] Completed at " . date('Y-m-d H:i:s') . "\n";
echo "[phase1_generate] Items processed: {$processed}\n";
echo "[phase1_generate] Errors: {$errors}\n";
echo "========================================\n";
