<?php
$rgName = htmlspecialchars($rechtsgebiet['name']);
$title = "{$rgName} - Häufige Rechtsfragen & Kostenlose Ersteinschätzung | Rechtecheck";
$meta_description = "Alle wichtigen Rechtsfragen zum Thema {$rgName}. Finden Sie spezialisierte Anwälte und erhalten Sie eine kostenlose Ersteinschätzung bei Rechtecheck.";
$meta_keywords = "{$rgName}, {$rgName} Anwalt, {$rgName} Rechtsberatung, kostenlose Ersteinschätzung";
$og_title = $title;
$og_description = $meta_description;
$canonical_url = "https://rechtecheck.de/experten-service/{$rechtsgebiet['slug']}/";
$breadcrumbs = [
    ['name' => 'Home', 'url' => '/'],
    ['name' => 'Rechtsgebiete', 'url' => '/experten-service/unsere-services/'],
    ['name' => $rechtsgebiet['name'], 'url' => "/experten-service/{$rechtsgebiet['slug']}/"],
];
$schema_extra = json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'LegalService',
    'name' => $rechtsgebiet['name'] . ' - Rechtecheck',
    'description' => $meta_description,
    'url' => $canonical_url,
], JSON_UNESCAPED_UNICODE);

ob_start();
?>

<!-- Hero -->
<section class="rc-hero">
    <div class="container">
        <h1><?= $rgName ?></h1>
        <p class="mt-3">Informieren Sie sich über Ihre Rechte und finden Sie den passenden Anwalt</p>
    </div>
</section>

<section class="py-5">
    <div class="container">
        <div class="row g-5">
            <!-- Main Content -->
            <div class="col-lg-8">
                <!-- AI-generated intro content (if exists) -->
                <?php if ($page && !empty($page['html_content'])): ?>
                <div class="rc-content mb-5">
                    <?= $page['html_content'] ?>
                </div>
                <?php else: ?>
                <div class="mb-5">
                    <p class="lead">Hier finden Sie alle wichtigen Informationen und Rechtsfragen zum Thema <strong><?= $rgName ?></strong>. Unsere Experten helfen Ihnen bei der Einschätzung Ihrer rechtlichen Situation.</p>
                </div>
                <?php endif; ?>

                <!-- Rechtsfragen Cards Grid -->
                <h2 class="h4 fw-bold mb-4">Häufige Rechtsfragen</h2>
                <div class="row g-3">
                    <?php foreach ($rechtsfragen as $rf): ?>
                    <div class="col-md-6">
                        <div class="rc-card">
                            <div class="rc-card-body">
                                <h3 class="rc-card-title h6"><?= htmlspecialchars($rf['name']) ?></h3>
                                <?php if (!empty($rf['description'])): ?>
                                <p class="small text-muted mb-3"><?= htmlspecialchars(mb_substr($rf['description'], 0, 150, 'UTF-8')) ?>...</p>
                                <?php endif; ?>
                                <div class="d-flex flex-column gap-2">
                                    <a href="/<?= htmlspecialchars($rf['slug']) ?>/" class="btn btn-sm btn-outline-primary">Weitere Informationen</a>
                                    <a href="#" class="btn btn-sm rc-btn-cta">Kostenlose Ersteinschätzung</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($rechtsfragen)): ?>
                <p class="text-muted">Noch keine Rechtsfragen für dieses Gebiet verfügbar.</p>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
                <div class="rc-sidebar-sticky">
                    <!-- CTA Box -->
                    <div class="rc-sidebar-cta mb-4">
                        <h3>Jetzt handeln!</h3>
                        <p class="small text-muted">Erhalten Sie eine kostenlose Ersteinschätzung von unseren Experten für <?= $rgName ?>.</p>
                        <a href="#" class="btn rc-btn-cta rc-btn-cta-lg w-100">Kostenlose Ersteinschätzung</a>
                    </div>

                    <!-- Related Rechtsgebiete -->
                    <?php if (!empty($related)): ?>
                    <div class="p-4 bg-light rounded-3">
                        <h4 class="h6 fw-bold mb-3">Verwandte Rechtsgebiete</h4>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($related as $rel): ?>
                            <li class="mb-2">
                                <a href="/experten-service/<?= htmlspecialchars($rel['slug']) ?>/" class="text-decoration-none" style="color: var(--rc-primary);">
                                    <?= htmlspecialchars($rel['name']) ?>
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
