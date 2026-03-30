<?php
/**
 * SitemapGenerator - generates sitemap XML files using streaming file writes.
 *
 * Produces individual sitemap files (sitemap-1.xml, sitemap-2.xml, ...) with a
 * maximum of 1000 URLs each. When only one file is needed, it is written as
 * sitemap.xml directly. When multiple files are produced, a sitemap-index.xml
 * is written and symlinked / copied to sitemap.xml.
 *
 * Also generates robots.txt.
 */
class SitemapGenerator {
    private string $publicDir;
    private string $siteUrl;
    private int $maxUrlsPerSitemap = 1000;

    public function __construct() {
        $seoConfig = require __DIR__ . '/../config/seo.php';
        $this->siteUrl = rtrim($seoConfig['site_url'], '/');
        $this->publicDir = __DIR__ . '/../public';
    }

    /**
     * Main entry point: query all published pages and write sitemap files.
     */
    public function generate(): void {
        $db = Database::getInstance();

        // Collect all URLs as a generator to avoid loading everything into memory
        $urls = $this->collectUrls($db);

        $fileIndex = 1;
        $urlCount = 0;
        $totalUrls = 0;
        $sitemapFiles = [];
        $fh = null;

        try {
            foreach ($urls as $entry) {
                // Start a new sitemap file when needed
                if ($fh === null || $urlCount >= $this->maxUrlsPerSitemap) {
                    if ($fh !== null) {
                        $this->closeSitemap($fh);
                        $fileIndex++;
                    }
                    $filename = "sitemap-{$fileIndex}.xml";
                    $sitemapFiles[] = $filename;
                    $fh = $this->openSitemap($filename);
                    $urlCount = 0;
                }

                $this->writeUrl($fh, $entry['loc'], $entry['lastmod'], $entry['changefreq'], $entry['priority']);
                $urlCount++;
                $totalUrls++;
            }

            // Close the last open sitemap
            if ($fh !== null) {
                $this->closeSitemap($fh);
            }
        } catch (Throwable $e) {
            // Make sure the file handle is closed on error
            if ($fh !== null && is_resource($fh)) {
                fwrite($fh, "</urlset>\n");
                fclose($fh);
            }
            throw $e;
        }

        // Write the main sitemap.xml (index or single sitemap)
        if (count($sitemapFiles) === 0) {
            // No published pages: write an empty sitemap
            $this->writeEmptySitemap();
        } elseif (count($sitemapFiles) === 1) {
            // Single file: just rename/copy it to sitemap.xml
            $src = $this->publicDir . '/' . $sitemapFiles[0];
            $dst = $this->publicDir . '/sitemap.xml';
            if (file_exists($dst)) {
                @unlink($dst);
            }
            copy($src, $dst);
        } else {
            // Multiple files: write a sitemap index
            $this->writeSitemapIndex($sitemapFiles);
        }

        // Generate robots.txt
        $this->writeRobotsTxt();
    }

    /**
     * Generator that yields all published page URLs in chunks.
     *
     * @param Database $db
     * @return \Generator
     */
    private function collectUrls(Database $db): \Generator {
        // Static overview page
        yield [
            'loc'        => $this->siteUrl . '/experten-service/unsere-services/',
            'lastmod'    => date('Y-m-d'),
            'changefreq' => 'weekly',
            'priority'   => '1.0',
        ];

        // Rechtsgebiet pages (priority 0.8)
        $offset = 0;
        $chunkSize = 100;
        while (true) {
            $rows = $db->fetchAll(
                "SELECT rg.slug, rp.published_at
                 FROM rechtsgebiet_pages rp
                 INNER JOIN rechtsgebiete rg ON rp.rechtsgebiet_id = rg.id
                 WHERE rp.generation_status = 'published'
                 ORDER BY rp.id
                 LIMIT {$chunkSize} OFFSET {$offset}"
            );
            if (empty($rows)) {
                break;
            }
            foreach ($rows as $row) {
                yield [
                    'loc'        => $this->siteUrl . '/experten-service/' . $row['slug'] . '/',
                    'lastmod'    => $this->formatDate($row['published_at']),
                    'changefreq' => 'weekly',
                    'priority'   => '0.8',
                ];
            }
            $offset += $chunkSize;
            if (count($rows) < $chunkSize) {
                break;
            }
        }

        // Rechtsfrage pages (priority 0.9)
        $offset = 0;
        while (true) {
            $rows = $db->fetchAll(
                "SELECT rf.slug, rfp.published_at
                 FROM rechtsfrage_pages rfp
                 INNER JOIN rechtsfragen rf ON rfp.rechtsfrage_id = rf.id
                 WHERE rfp.generation_status = 'published'
                 ORDER BY rfp.id
                 LIMIT {$chunkSize} OFFSET {$offset}"
            );
            if (empty($rows)) {
                break;
            }
            foreach ($rows as $row) {
                yield [
                    'loc'        => $this->siteUrl . '/' . $row['slug'] . '/',
                    'lastmod'    => $this->formatDate($row['published_at']),
                    'changefreq' => 'weekly',
                    'priority'   => '0.9',
                ];
            }
            $offset += $chunkSize;
            if (count($rows) < $chunkSize) {
                break;
            }
        }

        // Variation pages (priority 0.7)
        $offset = 0;
        while (true) {
            $rows = $db->fetchAll(
                "SELECT rf.slug AS rf_slug, vv.slug AS vv_slug, vp.published_at
                 FROM variation_pages vp
                 INNER JOIN rechtsfragen rf ON vp.rechtsfrage_id = rf.id
                 INNER JOIN variation_values vv ON vp.variation_value_id = vv.id
                 WHERE vp.generation_status = 'published'
                 ORDER BY vp.id
                 LIMIT {$chunkSize} OFFSET {$offset}"
            );
            if (empty($rows)) {
                break;
            }
            foreach ($rows as $row) {
                yield [
                    'loc'        => $this->siteUrl . '/' . $row['rf_slug'] . '-' . $row['vv_slug'] . '/',
                    'lastmod'    => $this->formatDate($row['published_at']),
                    'changefreq' => 'weekly',
                    'priority'   => '0.7',
                ];
            }
            $offset += $chunkSize;
            if (count($rows) < $chunkSize) {
                break;
            }
        }
    }

    /**
     * Open a new sitemap XML file for streaming writes.
     *
     * @param string $filename
     * @return resource
     */
    private function openSitemap(string $filename) {
        $path = $this->publicDir . '/' . $filename;
        $fh = fopen($path, 'w');
        if ($fh === false) {
            throw new RuntimeException("Cannot open sitemap file for writing: {$path}");
        }
        fwrite($fh, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        fwrite($fh, '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n");
        return $fh;
    }

    /**
     * Write a single <url> entry to the open sitemap file.
     *
     * @param resource $fh
     * @param string $loc
     * @param string $lastmod
     * @param string $changefreq
     * @param string $priority
     */
    private function writeUrl($fh, string $loc, string $lastmod, string $changefreq, string $priority): void {
        $loc = htmlspecialchars($loc, ENT_XML1, 'UTF-8');
        fwrite($fh, "  <url>\n");
        fwrite($fh, "    <loc>{$loc}</loc>\n");
        fwrite($fh, "    <lastmod>{$lastmod}</lastmod>\n");
        fwrite($fh, "    <changefreq>{$changefreq}</changefreq>\n");
        fwrite($fh, "    <priority>{$priority}</priority>\n");
        fwrite($fh, "  </url>\n");
    }

    /**
     * Close a sitemap XML file (write closing tag and close handle).
     *
     * @param resource $fh
     */
    private function closeSitemap($fh): void {
        fwrite($fh, "</urlset>\n");
        fclose($fh);
    }

    /**
     * Write a sitemap index file referencing multiple sitemap files.
     *
     * @param array $sitemapFiles List of sitemap filenames (e.g. sitemap-1.xml, sitemap-2.xml)
     */
    private function writeSitemapIndex(array $sitemapFiles): void {
        $path = $this->publicDir . '/sitemap.xml';
        $fh = fopen($path, 'w');
        if ($fh === false) {
            throw new RuntimeException("Cannot open sitemap index for writing: {$path}");
        }

        fwrite($fh, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        fwrite($fh, '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n");

        foreach ($sitemapFiles as $filename) {
            $loc = htmlspecialchars($this->siteUrl . '/' . $filename, ENT_XML1, 'UTF-8');
            fwrite($fh, "  <sitemap>\n");
            fwrite($fh, "    <loc>{$loc}</loc>\n");
            fwrite($fh, "  </sitemap>\n");
        }

        fwrite($fh, "</sitemapindex>\n");
        fclose($fh);
    }

    /**
     * Write an empty sitemap.xml (no published pages yet).
     */
    private function writeEmptySitemap(): void {
        $path = $this->publicDir . '/sitemap.xml';
        $fh = fopen($path, 'w');
        if ($fh === false) {
            throw new RuntimeException("Cannot open sitemap file for writing: {$path}");
        }
        fwrite($fh, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        fwrite($fh, '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n");
        fwrite($fh, "</urlset>\n");
        fclose($fh);
    }

    /**
     * Write robots.txt to the public directory.
     */
    private function writeRobotsTxt(): void {
        $path = $this->publicDir . '/robots.txt';
        $sitemapUrl = $this->siteUrl . '/sitemap.xml';

        $content = "User-agent: *\n";
        $content .= "Allow: /\n";
        $content .= "Sitemap: {$sitemapUrl}\n";

        file_put_contents($path, $content);
    }

    /**
     * Format a datetime string as Y-m-d for sitemap lastmod.
     *
     * @param string|null $datetime
     * @return string
     */
    private function formatDate(?string $datetime): string {
        if (empty($datetime)) {
            return date('Y-m-d');
        }
        return date('Y-m-d', strtotime($datetime));
    }
}
