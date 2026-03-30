<?php

class Router {
    private Database $db;
    private array $seoConfig;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->seoConfig = require __DIR__ . '/../config/seo.php';
    }

    public function dispatch(): void {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri = rtrim($uri, '/');
        if (empty($uri)) $uri = '/';

        // Root redirect
        if ($uri === '/') {
            header('Location: /experten-service/unsere-services');
            exit;
        }

        // Route 1: Rechtsgebiete overview
        if ($uri === '/experten-service/unsere-services') {
            $this->renderRechtsgebiete();
            return;
        }

        // Route 2: Single Rechtsgebiet
        if (preg_match('#^/experten-service/([a-z0-9\-]+)$#', $uri, $m)) {
            $this->renderRechtsgebiet($m[1]);
            return;
        }

        // Route 3 or 4: Rechtsfrage or Variation
        if (preg_match('#^/([a-z0-9\-]+)$#', $uri, $m)) {
            $slug = $m[1];
            // First try as a Rechtsfrage
            $rf = $this->db->fetchOne('SELECT * FROM rechtsfragen WHERE slug = ?', [$slug]);
            if ($rf) {
                $this->renderRechtsfrage($rf);
                return;
            }
            // Try as variation: split slug from end, last segment might be city
            $this->tryVariation($slug);
            return;
        }

        $this->render404();
    }

    private function renderRechtsgebiete(): void {
        $rechtsgebiete = $this->db->fetchAll(
            'SELECT rg.*, (SELECT COUNT(*) FROM rechtsfragen rf WHERE rf.rechtsgebiet_id = rg.id) as rf_count
             FROM rechtsgebiete rg ORDER BY rg.name'
        );
        $data = ['rechtsgebiete' => $rechtsgebiete];
        $this->render('rechtsgebiete', $data);
    }

    private function renderRechtsgebiet(string $slug): void {
        $rg = $this->db->fetchOne('SELECT * FROM rechtsgebiete WHERE slug = ?', [$slug]);
        if (!$rg) { $this->render404(); return; }

        $rechtsfragen = $this->db->fetchAll(
            'SELECT * FROM rechtsfragen WHERE rechtsgebiet_id = ? ORDER BY name', [$rg['id']]
        );
        $page = $this->db->fetchOne(
            'SELECT * FROM rechtsgebiet_pages WHERE rechtsgebiet_id = ?', [$rg['id']]
        );
        $related = $this->db->fetchAll(
            'SELECT * FROM rechtsgebiete WHERE id != ? ORDER BY RAND() LIMIT 5', [$rg['id']]
        );

        $data = [
            'rechtsgebiet' => $rg,
            'rechtsfragen' => $rechtsfragen,
            'page' => $page,
            'related' => $related,
        ];
        $this->render('rechtsgebiet', $data);
    }

    private function renderRechtsfrage(array $rf): void {
        $rg = $this->db->fetchOne('SELECT * FROM rechtsgebiete WHERE id = ?', [$rf['rechtsgebiet_id']]);
        $page = $this->db->fetchOne(
            'SELECT * FROM rechtsfrage_pages WHERE rechtsfrage_id = ?', [$rf['id']]
        );

        // Get all variation types and their values grouped by type
        $rawVarGroups = $this->db->fetchAll(
            'SELECT vv.id, vv.value, vv.slug, vv.tier, vt.slug AS type_slug, vt.name AS type_name
             FROM variation_values vv
             JOIN variation_types vt ON vv.variation_type_id = vt.id
             WHERE vt.rechtsgebiet_id = ?
             ORDER BY vt.slug ASC, vv.tier ASC, vv.value ASC',
            [$rg['id']]
        );

        $variationGroups = [];
        foreach ($rawVarGroups as $row) {
            $ts = $row['type_slug'];
            if (!isset($variationGroups[$ts])) {
                $variationGroups[$ts] = [
                    'name'   => $row['type_name'],
                    'slug'   => $ts,
                    'values' => [],
                ];
            }
            $variationGroups[$ts]['values'][] = $row;
        }

        $siblings = $this->db->fetchAll(
            'SELECT * FROM rechtsfragen WHERE rechtsgebiet_id = ? AND id != ? LIMIT 5',
            [$rf['rechtsgebiet_id'], $rf['id']]
        );

        $data = [
            'rechtsfrage'      => $rf,
            'rechtsgebiet'     => $rg,
            'page'             => $page,
            'variation_groups' => $variationGroups,
            'siblings'         => $siblings,
        ];
        $this->render('rechtsfrage', $data);
    }

    private function tryVariation(string $fullSlug): void {
        // Split from end: last segment is city slug
        $lastHyphen = strrpos($fullSlug, '-');
        while ($lastHyphen !== false) {
            $rfSlug = substr($fullSlug, 0, $lastHyphen);
            $citySlug = substr($fullSlug, $lastHyphen + 1);

            $rf = $this->db->fetchOne('SELECT * FROM rechtsfragen WHERE slug = ?', [$rfSlug]);
            $vv = $this->db->fetchOne('SELECT * FROM variation_values WHERE slug = ?', [$citySlug]);

            if ($rf && $vv) {
                $this->renderVariation($rf, $vv);
                return;
            }
            // Try splitting further left
            $lastHyphen = strrpos($rfSlug, '-');
        }
        $this->render404();
    }

    private function renderVariation(array $rf, array $vv): void {
        $rg = $this->db->fetchOne('SELECT * FROM rechtsgebiete WHERE id = ?', [$rf['rechtsgebiet_id']]);

        // Resolve variation type for this value
        $vt = $this->db->fetchOne(
            'SELECT slug AS type_slug, name AS type_name FROM variation_types WHERE id = ?',
            [$vv['variation_type_id']]
        );
        $typeSlug = $vt ? $vt['type_slug'] : 'staedte';
        $typeName = $vt ? $vt['type_name'] : 'Städte';

        // Fetch base content
        $parentPage = $this->db->fetchOne(
            'SELECT * FROM rechtsfrage_pages WHERE rechtsfrage_id = ?', [$rf['id']]
        );

        // Fetch smart intro paragraph for this type
        $introRow = $this->db->fetchOne(
            'SELECT intro_content FROM rechtsfrage_variation_intros WHERE rechtsfrage_id = ? AND variation_type_slug = ?',
            [$rf['id'], $typeSlug]
        );

        // Build page data: smart substitution if intro exists, else fallback to variation_pages
        if ($introRow && $parentPage) {
            $introHtml    = str_replace('[VARIATION_VALUE]', htmlspecialchars($vv['value']), $introRow['intro_content']);
            $combinedHtml = $introHtml . "\n" . ($parentPage['html_content'] ?? '');
            $page = [
                'html_content'     => $combinedHtml,
                'title'            => null, // template builds title from rf + vv
                'meta_description' => null,
                'meta_keywords'    => $parentPage['meta_keywords'] ?? '',
            ];
        } else {
            // Fallback: pre-generated variation page (old system)
            $page = $this->db->fetchOne(
                'SELECT * FROM variation_pages WHERE rechtsfrage_id = ? AND variation_value_id = ?',
                [$rf['id'], $vv['id']]
            );
        }

        // Sibling variations: same type, same rechtsgebiet, different value
        $siblingVariations = $this->db->fetchAll(
            'SELECT vv.* FROM variation_values vv
             JOIN variation_types vt ON vv.variation_type_id = vt.id
             WHERE vt.rechtsgebiet_id = ? AND vt.slug = ? AND vv.id != ?
             ORDER BY vv.tier ASC, vv.value ASC',
            [$rg['id'], $typeSlug, $vv['id']]
        );

        $data = [
            'rechtsfrage'       => $rf,
            'rechtsgebiet'      => $rg,
            'variation_value'   => $vv,
            'variation_type'    => ['slug' => $typeSlug, 'name' => $typeName],
            'page'              => $page,
            'parent_page'       => $parentPage,
            'sibling_variations' => $siblingVariations,
        ];
        $this->render('variation', $data);
    }

    private function render(string $template, array $data): void {
        extract($data);
        $templateFile = __DIR__ . "/../templates/{$template}.php";
        if (!file_exists($templateFile)) {
            $this->render404();
            return;
        }
        include $templateFile;
    }

    private function render404(): void {
        http_response_code(404);
        echo '<!DOCTYPE html><html><head><title>404 - Seite nicht gefunden</title></head>';
        echo '<body><h1>404 - Seite nicht gefunden</h1></body></html>';
    }
}
