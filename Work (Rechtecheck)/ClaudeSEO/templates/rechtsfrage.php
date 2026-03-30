<?php
$rfName = htmlspecialchars($rechtsfrage['name']);
$rgName = htmlspecialchars($rechtsgebiet['name']);

$pageTitle = $page['title'] ?? "{$rfName} - {$rgName} | Rechtecheck";
$pageMeta = $page['meta_description'] ?? "Informieren Sie sich über {$rfName} im Bereich {$rgName}. Kostenlose Ersteinschätzung durch spezialisierte Anwälte bei Rechtecheck.";

$title = $pageTitle;
$meta_description = $pageMeta;
$meta_keywords = $page['meta_keywords'] ?? "{$rfName}, {$rgName}, {$rfName} Anwalt, kostenlose Ersteinschätzung";
$og_title = $page['og_title'] ?? $title;
$og_description = $page['og_description'] ?? $meta_description;
$canonical_url = "https://rechtecheck.de/{$rechtsfrage['slug']}/";
$breadcrumbs = [
    ['name' => 'Home', 'url' => '/'],
    ['name' => 'Rechtsgebiete', 'url' => '/experten-service/unsere-services/'],
    ['name' => $rechtsgebiet['name'], 'url' => "/experten-service/{$rechtsgebiet['slug']}/"],
    ['name' => $rechtsfrage['name'], 'url' => "/{$rechtsfrage['slug']}/"],
];

// Extract TOC from html_content
function extractToc(string $html): array {
    $toc = [];
    if (preg_match_all('/<h2[^>]*>(.*?)<\/h2>/si', $html, $matches)) {
        foreach ($matches[1] as $i => $heading) {
            $id = 'section-' . ($i + 1);
            $toc[] = ['id' => $id, 'text' => strip_tags($heading)];
        }
    }
    return $toc;
}

// Inject IDs into H2 tags
function injectH2Ids(string $html): string {
    $counter = 0;
    return preg_replace_callback('/<h2([^>]*)>(.*?)<\/h2>/si', function($m) use (&$counter) {
        $counter++;
        $id = 'section-' . $counter;
        return "<h2{$m[1]} id=\"{$id}\">{$m[2]}</h2>";
    }, $html);
}

// Extract FAQ items for schema
function extractFaqItems(string $html): array {
    $faqs = [];
    if (preg_match('/<h2[^>]*>.*?(?:FAQ|Häufige Fragen|Fragen).*?<\/h2>(.*?)(?=<h2|$)/si', $html, $faqSection)) {
        if (preg_match_all('/<h3[^>]*>(.*?)<\/h3>\s*<p>(.*?)<\/p>/si', $faqSection[1], $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $faqs[] = ['question' => strip_tags($match[1]), 'answer' => strip_tags($match[2])];
            }
        }
    }
    return $faqs;
}

$htmlContent = $page['html_content'] ?? '';
$toc = extractToc($htmlContent);
$htmlContent = injectH2Ids($htmlContent);
$faqItems = extractFaqItems($htmlContent);

// Build schema
$schemaData = [
    '@context' => 'https://schema.org',
    '@type' => 'LegalService',
    'name' => "{$rechtsfrage['name']} - Rechtecheck",
    'description' => $meta_description,
    'url' => $canonical_url,
];
$schema_extra = json_encode($schemaData, JSON_UNESCAPED_UNICODE);

// Add FAQ schema if we have items
$faqSchema = '';
if (!empty($faqItems)) {
    $faqSchema = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array_map(fn($faq) => [
            '@type' => 'Question',
            'name' => $faq['question'],
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $faq['answer'],
            ],
        ], $faqItems),
    ], JSON_UNESCAPED_UNICODE);
}

ob_start();
?>

<!-- Hero -->
<section class="rc-hero">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <p class="rc-hero-eyebrow"><?= $rgName ?></p>
                <h1><?= $rfName ?></h1>
                <p class="rc-hero-sub mt-3">Verstehen Sie Ihre Rechte – und handeln Sie jetzt. Kostenlose Ersteinschätzung durch spezialisierte Anwälte.</p>
                <a href="#" class="btn rc-btn-cta rc-btn-cta-lg mt-4">
                    Kostenlose Ersteinschätzung anfordern
                </a>
            </div>
            <div class="col-lg-4 d-none d-lg-block text-center">
                <div class="rc-hero-badge">
                    <div class="rc-hero-badge-icon"><i class="fas fa-shield-halved"></i></div>
                    <div class="rc-hero-badge-text">Über 10 Mio. Verbraucher informiert</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- How it works -->
<section class="rc-how-it-works py-4 border-bottom">
    <div class="container">
        <div class="row g-3 align-items-center justify-content-center">
            <div class="col-auto d-flex align-items-center gap-3">
                <div class="rc-step-num">1</div>
                <span class="fw-semibold small">Sie schildern Ihre Situation</span>
            </div>
            <div class="col-auto rc-step-arrow d-none d-md-block"><i class="fas fa-arrow-right text-muted"></i></div>
            <div class="col-auto d-flex align-items-center gap-3">
                <div class="rc-step-num">2</div>
                <span class="fw-semibold small">Unsere Experten prüfen Ihren Fall</span>
            </div>
            <div class="col-auto rc-step-arrow d-none d-md-block"><i class="fas fa-arrow-right text-muted"></i></div>
            <div class="col-auto d-flex align-items-center gap-3">
                <div class="rc-step-num">3</div>
                <span class="fw-semibold small">Sie erfahren Ihre nächsten Schritte</span>
            </div>
            <div class="col-auto ms-md-4">
                <a href="#" class="btn rc-btn-cta btn-sm px-4">Jetzt starten</a>
            </div>
        </div>
    </div>
</section>

<!-- Main Content -->
<section class="py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-9">

                <?php if (!empty($toc)): ?>
                <!-- Table of Contents -->
                <div class="rc-toc">
                    <h2>Inhalt</h2>
                    <ol>
                        <?php foreach ($toc as $item): ?>
                        <li><a href="#<?= $item['id'] ?>"><?= htmlspecialchars($item['text']) ?></a></li>
                        <?php endforeach; ?>
                    </ol>
                </div>
                <?php endif; ?>

                <!-- Article Content -->
                <?php if (!empty($htmlContent)): ?>
                <div class="rc-content">
                    <?= $htmlContent ?>
                </div>
                <?php else: ?>
                <div class="rc-content">
                    <p class="lead">Informationen zu <?= $rfName ?> im Bereich <?= $rgName ?>.</p>
                    <div class="alert alert-info mt-4">
                        <strong>Inhalt wird gerade erstellt.</strong> Fordern Sie in der Zwischenzeit eine kostenlose Ersteinschätzung an.
                    </div>
                </div>
                <?php endif; ?>

                <!-- Inline CTA -->
                <div class="rc-inline-cta my-5">
                    <div class="rc-inline-cta-icon"><i class="fas fa-gavel"></i></div>
                    <div>
                        <h3 class="h5 mb-1">Brauchen Sie Hilfe bei <?= $rfName ?>?</h3>
                        <p class="mb-0 text-muted small">Unsere spezialisierten Partneranwälte helfen Ihnen weiter – kostenlos und unverbindlich.</p>
                    </div>
                    <a href="#" class="btn rc-btn-cta rc-btn-cta-lg ms-auto flex-shrink-0">Kostenlose Hilfe anfordern</a>
                </div>

            </div>
        </div>
    </div>
</section>

<!-- USP Section -->
<section class="rc-usp py-5 border-top">
    <div class="container">
        <h2 class="text-center fw-bold mb-5">Ihre Vorteile mit Rechtecheck</h2>
        <div class="row g-4 justify-content-center">
            <div class="col-md-4 col-lg-2-4">
                <div class="rc-usp-item text-center">
                    <div class="rc-usp-icon"><i class="fas fa-bolt"></i></div>
                    <h3 class="h6 fw-bold mt-3">Schnelle Einschätzung</h3>
                    <p class="small text-muted mb-0">Antwort innerhalb von 24 Stunden</p>
                </div>
            </div>
            <div class="col-md-4 col-lg-2-4">
                <div class="rc-usp-item text-center">
                    <div class="rc-usp-icon"><i class="fas fa-user-tie"></i></div>
                    <h3 class="h6 fw-bold mt-3">Erfahrene Partner-Anwälte</h3>
                    <p class="small text-muted mb-0">Bundesweit spezialisierte Kanzleien</p>
                </div>
            </div>
            <div class="col-md-4 col-lg-2-4">
                <div class="rc-usp-item text-center">
                    <div class="rc-usp-icon"><i class="fas fa-euro-sign"></i></div>
                    <h3 class="h6 fw-bold mt-3">Kostenfreie Ersteinschätzung</h3>
                    <p class="small text-muted mb-0">Ohne versteckte Kosten oder Risiko</p>
                </div>
            </div>
            <div class="col-md-4 col-lg-2-4">
                <div class="rc-usp-item text-center">
                    <div class="rc-usp-icon"><i class="fas fa-lock"></i></div>
                    <h3 class="h6 fw-bold mt-3">Datenschutz garantiert</h3>
                    <p class="small text-muted mb-0">Ihre Daten sind bei uns sicher</p>
                </div>
            </div>
            <div class="col-md-4 col-lg-2-4">
                <div class="rc-usp-item text-center">
                    <div class="rc-usp-icon"><i class="fas fa-star"></i></div>
                    <h3 class="h6 fw-bold mt-3">Über 10 Mio. informiert</h3>
                    <p class="small text-muted mb-0">Vertrauen von Millionen Verbrauchern</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Variation Groups — rechtecheck.de style grid layout -->
<?php if (!empty($variation_groups)): ?>
<section class="rc-variations py-5 border-top">
    <div class="container">
        <h2 class="fw-bold mb-4"><?= htmlspecialchars($rfName) ?> – Verwandte Themen</h2>
        <div class="row g-4">
        <?php foreach ($variation_groups as $group): ?>
        <?php if (empty($group['values'])) continue; ?>
        <?php
            $groupId   = 'vg-' . htmlspecialchars($group['slug']);
            $totalVals = count($group['values']);
            $isLarge   = $totalVals > 40;
            $colClass  = $isLarge ? 'col-12' : 'col-md-6';
            $previewN  = $isLarge ? 12 : 8;
            $preview   = array_slice($group['values'], 0, $previewN);
            $remaining = array_slice($group['values'], $previewN);
        ?>
        <div class="<?= $colClass ?>">
            <h5 class="fw-bold mb-2"><?= htmlspecialchars($group['name']) ?></h5>
            <div class="list-group list-group-flush rc-variation-list">
                <?php if ($isLarge): ?>
                <div class="row row-cols-1 row-cols-md-3 g-0">
                    <?php foreach ($preview as $v): ?>
                    <div class="col">
                        <a href="/<?= htmlspecialchars($rechtsfrage['slug']) ?>-<?= htmlspecialchars($v['slug']) ?>/"
                           class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2 px-3">
                            <span><?= htmlspecialchars($rfName) ?> – <?= htmlspecialchars($v['value']) ?></span>
                            <i class="fas fa-chevron-right text-muted small"></i>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <?php foreach ($preview as $v): ?>
                <a href="/<?= htmlspecialchars($rechtsfrage['slug']) ?>-<?= htmlspecialchars($v['slug']) ?>/"
                   class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2 px-3">
                    <span><?= htmlspecialchars($rfName) ?> – <?= htmlspecialchars($v['value']) ?></span>
                    <i class="fas fa-chevron-right text-muted small"></i>
                </a>
                <?php endforeach; ?>
                <?php endif; ?>
                <?php if (!empty($remaining)): ?>
                <div class="collapse" id="<?= $groupId ?>">
                    <?php if ($isLarge): ?>
                    <div class="row row-cols-1 row-cols-md-3 g-0">
                        <?php foreach ($remaining as $v): ?>
                        <div class="col">
                            <a href="/<?= htmlspecialchars($rechtsfrage['slug']) ?>-<?= htmlspecialchars($v['slug']) ?>/"
                               class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2 px-3">
                                <span><?= htmlspecialchars($rfName) ?> – <?= htmlspecialchars($v['value']) ?></span>
                                <i class="fas fa-chevron-right text-muted small"></i>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <?php foreach ($remaining as $v): ?>
                    <a href="/<?= htmlspecialchars($rechtsfrage['slug']) ?>-<?= htmlspecialchars($v['slug']) ?>/"
                       class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2 px-3">
                        <span><?= htmlspecialchars($rfName) ?> – <?= htmlspecialchars($v['value']) ?></span>
                        <i class="fas fa-chevron-right text-muted small"></i>
                    </a>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <button class="list-group-item list-group-item-action text-primary fw-semibold py-2 px-3 border-0 bg-light text-start"
                        type="button" data-bs-toggle="collapse" data-bs-target="#<?= $groupId ?>"
                        aria-expanded="false">
                    <i class="fas fa-plus-circle me-2"></i>Alle <?= $totalVals ?> <?= htmlspecialchars($group['name']) ?> ansehen
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Related Rechtsfragen -->
<?php if (!empty($siblings)): ?>
<section class="rc-siblings py-4 border-top">
    <div class="container">
        <h2 class="h5 fw-bold mb-4">Weitere Rechtsfragen in <?= $rgName ?></h2>
        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-2">
            <?php foreach ($siblings as $sib): ?>
            <div class="col">
                <a href="/<?= htmlspecialchars($sib['slug']) ?>/"
                   class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2 px-3">
                    <span><?= htmlspecialchars($sib['name']) ?></span>
                    <i class="fas fa-chevron-right text-muted small"></i>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Floating CTA -->
<div class="rc-floater" id="rc-floater">
    <a href="#" class="btn rc-btn-cta rc-btn-cta-lg rc-floater-btn">
        <i class="fas fa-gavel me-2"></i>Kostenlose Ersteinschätzung
    </a>
</div>

<?php if (!empty($faqSchema)): ?>
<script type="application/ld+json"><?= $faqSchema ?></script>
<?php endif; ?>

<script>
// Show floating CTA after scrolling 600px
(function() {
    var floater = document.getElementById('rc-floater');
    window.addEventListener('scroll', function() {
        if (window.scrollY > 600) {
            floater.classList.add('show');
        } else {
            floater.classList.remove('show');
        }
    }, { passive: true });
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
