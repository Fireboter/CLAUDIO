<?php
/**
 * Phase 2: Publish generated pages and regenerate sitemap.
 *
 * Moves all pages with generation_status='generated' to 'published',
 * updates parent entity statuses, regenerates the sitemap, and pings Google.
 *
 * Intended to run via cron every 5-10 minutes.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/CronLock.php';
require_once __DIR__ . '/../lib/SitemapGenerator.php';

$lock = new CronLock('phase2_publish');

if (!$lock->acquire()) {
    echo "Phase 2 publish already running. Exiting.\n";
    exit(0);
}

try {
    $db = Database::getInstance();
    $pdo = $db->getPdo();
    $seoConfig = require __DIR__ . '/../config/seo.php';

    // Log start
    $db->insert('cron_log', [
        'phase'      => 'phase2_publish',
        'started_at' => date('Y-m-d H:i:s'),
    ]);
    $cronLogId = $pdo->lastInsertId();

    $published = 0;
    $errors = [];

    // ---------------------------------------------------------------
    // 1. Publish Rechtsgebiet pages
    // ---------------------------------------------------------------
    $stmt = $db->query(
        "UPDATE rechtsgebiet_pages
         SET generation_status = 'published',
             published_at = NOW(),
             updated_at = NOW()
         WHERE generation_status = 'generated'"
    );
    $rgCount = $stmt->rowCount();
    $published += $rgCount;

    // Update parent rechtsgebiete status to 'published'
    if ($rgCount > 0) {
        $db->query(
            "UPDATE rechtsgebiete rg
             INNER JOIN rechtsgebiet_pages rp ON rg.id = rp.rechtsgebiet_id
             SET rg.status = 'published'
             WHERE rp.generation_status = 'published'
               AND rg.status != 'published'"
        );
    }

    echo "Published {$rgCount} Rechtsgebiet page(s).\n";

    // ---------------------------------------------------------------
    // 2. Publish Rechtsfrage pages
    // ---------------------------------------------------------------
    $stmt = $db->query(
        "UPDATE rechtsfrage_pages
         SET generation_status = 'published',
             published_at = NOW(),
             updated_at = NOW()
         WHERE generation_status = 'generated'"
    );
    $rfCount = $stmt->rowCount();
    $published += $rfCount;

    // Update parent rechtsfragen status to 'published'
    if ($rfCount > 0) {
        $db->query(
            "UPDATE rechtsfragen rf
             INNER JOIN rechtsfrage_pages rfp ON rf.id = rfp.rechtsfrage_id
             SET rf.status = 'published'
             WHERE rfp.generation_status = 'published'
               AND rf.status != 'published'"
        );
    }

    echo "Published {$rfCount} Rechtsfrage page(s).\n";

    // ---------------------------------------------------------------
    // 3. Publish Variation pages
    // ---------------------------------------------------------------
    $stmt = $db->query(
        "UPDATE variation_pages
         SET generation_status = 'published',
             published_at = NOW(),
             updated_at = NOW()
         WHERE generation_status = 'generated'"
    );
    $vpCount = $stmt->rowCount();
    $published += $vpCount;

    echo "Published {$vpCount} Variation page(s).\n";

    // ---------------------------------------------------------------
    // 4. Generate sitemap
    // ---------------------------------------------------------------
    try {
        $sitemap = new SitemapGenerator();
        $sitemap->generate();
        echo "Sitemap generated.\n";
    } catch (Throwable $e) {
        $errors[] = 'Sitemap generation failed: ' . $e->getMessage();
        echo "Sitemap generation failed: {$e->getMessage()}\n";
    }

    // ---------------------------------------------------------------
    // 5. Ping Google
    // ---------------------------------------------------------------
    try {
        $sitemapUrl = rtrim($seoConfig['site_url'], '/') . '/sitemap.xml';
        $pingUrl = 'https://www.google.com/ping?sitemap=' . urlencode($sitemapUrl);
        @file_get_contents($pingUrl);
        echo "Google pinged.\n";
    } catch (Throwable $e) {
        // Non-fatal: don't fail the whole run if Google ping fails
        $errors[] = 'Google ping failed: ' . $e->getMessage();
        echo "Google ping failed: {$e->getMessage()}\n";
    }

    // ---------------------------------------------------------------
    // 6. Log completion
    // ---------------------------------------------------------------
    $db->update('cron_log', [
        'ended_at'        => date('Y-m-d H:i:s'),
        'items_processed' => $published,
        'errors'          => count($errors) > 0 ? implode('; ', $errors) : null,
        'notes'           => "RG: {$rgCount}, RF: {$rfCount}, VP: {$vpCount}",
    ], 'id = ?', [$cronLogId]);

    echo "\nPhase 2 complete. Total published: {$published}\n";

} finally {
    $lock->release();
}
