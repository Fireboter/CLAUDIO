<?php
$title = 'Rechtsgebiete - Finden Sie den richtigen Anwalt | Rechtecheck';
$meta_description = 'Übersicht aller Rechtsgebiete bei Rechtecheck. Finden Sie spezialisierte Anwälte für Ihr rechtliches Anliegen und erhalten Sie eine kostenlose Ersteinschätzung.';
$meta_keywords = 'Rechtsgebiete, Anwalt finden, kostenlose Ersteinschätzung, Rechtsberatung';
$og_title = $title;
$og_description = $meta_description;
$canonical_url = 'https://rechtecheck.de/experten-service/unsere-services/';
$breadcrumbs = [
    ['name' => 'Home', 'url' => '/'],
    ['name' => 'Rechtsgebiete', 'url' => '/experten-service/unsere-services/'],
];
$schema_extra = json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => 'Rechtsgebiete',
    'description' => $meta_description,
], JSON_UNESCAPED_UNICODE);

ob_start();
?>

<!-- Hero Section -->
<section class="rc-hero">
    <div class="container">
        <h1>Auf der Suche nach dem richtigen Anwalt für Ihr Thema?</h1>
        <p class="mt-3">Wählen Sie Ihr Rechtsgebiet und erhalten Sie eine kostenlose Ersteinschätzung</p>
    </div>
</section>

<!-- Search/Filter -->
<section class="py-4">
    <div class="container">
        <div class="rc-search">
            <input type="text" id="rg-search" placeholder="Rechtsgebiet suchen..." oninput="filterCards(this.value)">
        </div>
    </div>
</section>

<!-- Cards Grid -->
<section class="pb-5">
    <div class="container">
        <div class="row g-4" id="rg-grid">
            <?php foreach ($rechtsgebiete as $rg): ?>
            <div class="col-md-6 col-lg-4 rg-card-col" data-name="<?= mb_strtolower(htmlspecialchars($rg['name']), 'UTF-8') ?>">
                <div class="rc-card">
                    <div class="rc-card-body">
                        <h2 class="rc-card-title"><?= htmlspecialchars($rg['name']) ?></h2>
                        <p class="text-muted small mb-3"><?= (int)$rg['rf_count'] ?> Rechtsfragen verfügbar</p>
                        <div class="d-flex gap-2">
                            <a href="/experten-service/<?= htmlspecialchars($rg['slug']) ?>/" class="btn btn-sm btn-outline-primary">Weitere Informationen</a>
                            <a href="#" class="btn btn-sm rc-btn-cta">Ersteinschätzung</a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (empty($rechtsgebiete)): ?>
        <p class="text-center text-muted py-5">Keine Rechtsgebiete verfügbar.</p>
        <?php endif; ?>
    </div>
</section>

<script>
function filterCards(query) {
    const q = query.toLowerCase().trim();
    document.querySelectorAll('.rg-card-col').forEach(col => {
        const name = col.getAttribute('data-name');
        col.style.display = (!q || name.includes(q)) ? '' : 'none';
    });
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
