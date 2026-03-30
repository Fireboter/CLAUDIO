<?php
$rfName   = htmlspecialchars($rechtsfrage['name']);
$rgName   = htmlspecialchars($rechtsgebiet['name']);
$varValue = htmlspecialchars($variation_value['value']);
$typeSlug = $variation_type['slug'] ?? 'staedte';
$typeName = htmlspecialchars($variation_type['name'] ?? 'Städte');

// Context phrase adapts to variation type
$isCity        = ($typeSlug === 'staedte');
$contextPhrase = $isCity ? "in {$varValue}" : "für {$varValue}";

$pageTitleStr    = isset($page['title']) && $page['title']
    ? $page['title']
    : "{$rfName} {$contextPhrase} | Rechtecheck";
$pageMeta        = isset($page['meta_description']) && $page['meta_description']
    ? $page['meta_description']
    : mb_substr("{$rfName} {$contextPhrase} – Kostenlose Ersteinschätzung durch spezialisierte Anwälte bei Rechtecheck.", 0, 155);
$pageKeywords    = ($page['meta_keywords'] ?? "{$rfName}, {$rgName}") . ', ' . $variation_value['value'];

$title           = $pageTitleStr;
$meta_description = $pageMeta;
$meta_keywords   = $pageKeywords;
$og_title        = $title;
$og_description  = $meta_description;
$canonical_url   = "https://rechtecheck.de/{$rechtsfrage['slug']}-{$variation_value['slug']}/";
$breadcrumbs     = [
    ['name' => 'Home',          'url' => '/'],
    ['name' => 'Rechtsgebiete', 'url' => '/experten-service/unsere-services/'],
    ['name' => $rechtsgebiet['name'], 'url' => "/experten-service/{$rechtsgebiet['slug']}/"],
    ['name' => $rechtsfrage['name'], 'url' => "/{$rechtsfrage['slug']}/"],
    ['name' => $varValue, 'url' => "/{$rechtsfrage['slug']}-{$variation_value['slug']}/"],
];

$schemaData = [
    '@context'    => 'https://schema.org',
    '@type'       => 'LegalService',
    'name'        => "{$rfName} {$contextPhrase} – Rechtecheck",
    'description' => $meta_description,
    'url'         => $canonical_url,
];
if ($isCity) {
    $schemaData['areaServed'] = ['@type' => 'City', 'name' => $variation_value['value']];
}
$schema_extra = json_encode($schemaData, JSON_UNESCAPED_UNICODE);

// Use variation page content if available, otherwise fall back to parent
$htmlContent = '';
if ($page && !empty($page['html_content'])) {
    $htmlContent = $page['html_content'];
} elseif ($parent_page && !empty($parent_page['html_content'])) {
    $htmlContent = $parent_page['html_content'];
}

// Reuse TOC functions from rechtsfrage template
function varExtractToc(string $html): array {
    $toc = [];
    if (preg_match_all('/<h2[^>]*>(.*?)<\/h2>/si', $html, $matches)) {
        foreach ($matches[1] as $i => $heading) {
            $toc[] = ['id' => 'section-' . ($i + 1), 'text' => strip_tags($heading)];
        }
    }
    return $toc;
}

function varInjectH2Ids(string $html): string {
    $counter = 0;
    return preg_replace_callback('/<h2([^>]*)>(.*?)<\/h2>/si', function($m) use (&$counter) {
        $counter++;
        return "<h2{$m[1]} id=\"section-{$counter}\">{$m[2]}</h2>";
    }, $html);
}

$toc = varExtractToc($htmlContent);
$htmlContent = varInjectH2Ids($htmlContent);

ob_start();
?>

<!-- Hero -->
<section class="rc-hero">
    <div class="container">
        <h1><?= $rfName ?> <?= $contextPhrase ?></h1>
        <p class="mt-3"><?= $rgName ?> – Rechtliche Unterstützung <?= $contextPhrase ?></p>
    </div>
</section>

<section class="py-5">
    <div class="container">
        <div class="row g-5">
            <!-- Main Content -->
            <div class="col-lg-8">
                <?php if (!empty($toc)): ?>
                <div class="rc-toc">
                    <h2>Inhalt</h2>
                    <ol>
                        <?php foreach ($toc as $item): ?>
                        <li><a href="#<?= $item['id'] ?>"><?= htmlspecialchars($item['text']) ?></a></li>
                        <?php endforeach; ?>
                    </ol>
                </div>
                <?php endif; ?>

                <!-- Content -->
                <?php if (!empty($htmlContent)): ?>
                <div class="rc-content">
                    <?= $htmlContent ?>
                </div>
                <?php else: ?>
                <div class="rc-content">
                    <p class="lead"><?= htmlspecialchars($rechtsfrage['description'] ?? "Informationen zu {$rfName} {$contextPhrase}.") ?></p>
                    <div class="alert alert-info mt-4">
                        <strong>Inhalt wird gerade erstellt.</strong> Fordern Sie in der Zwischenzeit eine kostenlose Ersteinschätzung an.
                    </div>
                </div>
                <?php endif; ?>

                <!-- Local Experts Section -->
                <?php if ($isCity): ?>
                <div id="local-experts" class="mt-5 p-4 border rounded-3 bg-light">
                    <h2 class="h4 fw-bold mb-3">Fachanwälte für <?= $rfName ?> in <?= $varValue ?></h2>
                    <p>Finden Sie hier spezialisierte Anwälte für Ihr Anliegen in <?= $varValue ?>. Unsere Partneranwälte bieten Ihnen eine kostenlose Ersteinschätzung Ihres Falls.</p>
                    <a href="#" class="btn rc-btn-cta">Anwalt in <?= $varValue ?> finden</a>
                </div>
                <?php endif; ?>

                <!-- Inline CTA -->
                <div class="my-5 p-4 text-center rounded-3" style="background: linear-gradient(135deg, #003366, #004c99); color: white;">
                    <h3 class="h4 mb-3">Brauchen Sie rechtliche Hilfe <?= $contextPhrase ?>?</h3>
                    <p>Unsere spezialisierten Partneranwälte helfen Ihnen weiter.</p>
                    <a href="#" class="btn rc-btn-cta rc-btn-cta-lg">Kostenlose Ersteinschätzung anfordern</a>
                </div>

                <!-- Sibling Variation Links -->
                <?php if (!empty($sibling_variations)): ?>
                <div class="mt-5">
                    <h2 class="h4 fw-bold mb-3"><?= $rfName ?> – Weitere <?= $typeName ?></h2>
                    <div class="rc-variation-grid">
                        <?php foreach ($sibling_variations as $sv): ?>
                        <a href="/<?= htmlspecialchars($rechtsfrage['slug']) ?>-<?= htmlspecialchars($sv['slug']) ?>/" class="rc-variation-link">
                            <?= htmlspecialchars($sv['value']) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
                <div class="rc-sidebar-sticky">
                    <div class="rc-sidebar-cta mb-4">
                        <h3>Jetzt handeln!</h3>
                        <p class="small text-muted">Anwaltliche Ersteinschätzung durch Partner-Anwälte <?= $contextPhrase ?>.</p>
                        <a href="#" class="btn rc-btn-cta rc-btn-cta-lg w-100">Kostenlose Hilfe anfordern</a>
                    </div>

                    <div class="p-3 mb-4">
                        <a href="/<?= htmlspecialchars($rechtsfrage['slug']) ?>/" class="text-decoration-none" style="color: var(--rc-primary);">
                            &larr; Zurück zur Hauptübersicht: <?= $rfName ?>
                        </a>
                    </div>

                    <?php if (!empty($sibling_variations)): ?>
                    <div class="p-4 bg-light rounded-3">
                        <h4 class="h6 fw-bold mb-3"><?= $typeName ?></h4>
                        <ul class="list-unstyled mb-0 small">
                            <?php foreach (array_slice($sibling_variations, 0, 15) as $sv): ?>
                            <li class="mb-1">
                                <a href="/<?= htmlspecialchars($rechtsfrage['slug']) ?>-<?= htmlspecialchars($sv['slug']) ?>/" class="text-decoration-none" style="color: var(--rc-primary);">
                                    <?= htmlspecialchars($sv['value']) ?>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
