<?php
$seoConfig = require __DIR__ . '/../config/seo.php';
$siteUrl = $seoConfig['site_url'];
$siteName = $seoConfig['site_name'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'Rechtecheck') ?></title>
    <meta name="description" content="<?= htmlspecialchars($meta_description ?? '') ?>">
    <?php if (!empty($meta_keywords)): ?>
    <meta name="keywords" content="<?= htmlspecialchars($meta_keywords) ?>">
    <?php endif; ?>

    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= htmlspecialchars($og_title ?? $title ?? '') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($og_description ?? $meta_description ?? '') ?>">
    <meta property="og:url" content="<?= htmlspecialchars($canonical_url ?? '') ?>">
    <meta property="og:site_name" content="Rechtecheck">

    <!-- Canonical -->
    <?php if (!empty($canonical_url)): ?>
    <link rel="canonical" href="<?= htmlspecialchars($canonical_url) ?>">
    <?php endif; ?>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="/assets/css/style.css" rel="stylesheet">

    <!-- Schema.org BreadcrumbList -->
    <?php if (!empty($breadcrumbs)): ?>
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "BreadcrumbList",
        "itemListElement": [
            <?php foreach ($breadcrumbs as $i => $crumb): ?>
            {
                "@type": "ListItem",
                "position": <?= $i + 1 ?>,
                "name": "<?= htmlspecialchars($crumb['name']) ?>",
                "item": "<?= htmlspecialchars($siteUrl . $crumb['url']) ?>"
            }<?= $i < count($breadcrumbs) - 1 ? ',' : '' ?>
            <?php endforeach; ?>
        ]
    }
    </script>
    <?php endif; ?>

    <!-- Additional Schema -->
    <?php if (!empty($schema_extra)): ?>
    <script type="application/ld+json"><?= $schema_extra ?></script>
    <?php endif; ?>
</head>
<body>

    <!-- Sticky Header -->
    <header class="rc-header">
        <nav class="navbar py-3">
            <div class="container d-flex justify-content-between align-items-center">
                <a href="/" class="navbar-brand fw-bold rc-logo">RECHTECHECK</a>
                <div class="d-flex gap-4 align-items-center">
                    <a href="/experten-service/unsere-services/" class="rc-nav-link">Rechtsgebiete</a>
                    <a href="#" class="rc-nav-link">Magazin</a>
                    <a href="#" class="btn btn-sm rc-btn-cta">Kostenlose Ersteinschätzung</a>
                </div>
            </div>
        </nav>
    </header>

    <!-- Breadcrumbs -->
    <?php if (!empty($breadcrumbs)): ?>
    <div class="rc-breadcrumbs">
        <div class="container">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <?php foreach ($breadcrumbs as $i => $crumb): ?>
                    <?php if ($i === count($breadcrumbs) - 1): ?>
                    <li class="breadcrumb-item active"><?= htmlspecialchars($crumb['name']) ?></li>
                    <?php else: ?>
                    <li class="breadcrumb-item"><a href="<?= htmlspecialchars($crumb['url']) ?>"><?= htmlspecialchars($crumb['name']) ?></a></li>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </ol>
            </nav>
        </div>
    </div>
    <?php endif; ?>

    <!-- Main Content -->
    <main>
        <?= $content ?? '' ?>
    </main>

    <!-- Footer -->
    <footer class="rc-footer">
        <div class="container">
            <div class="row g-4">
                <div class="col-md-4">
                    <h5 class="fw-bold mb-3">Rechtecheck</h5>
                    <p class="small text-muted">Ihr Portal für rechtliche Ersteinschätzungen und Anwaltssuche.</p>
                </div>
                <div class="col-md-4">
                    <h5 class="fw-bold mb-3">Services</h5>
                    <ul class="list-unstyled small">
                        <li><a href="/experten-service/unsere-services/" class="text-muted text-decoration-none">Rechtsgebiete</a></li>
                        <li><a href="#" class="text-muted text-decoration-none">Kostenlose Ersteinschätzung</a></li>
                    </ul>
                </div>
                <div class="col-md-4">
                    <h5 class="fw-bold mb-3">Rechtliches</h5>
                    <ul class="list-unstyled small">
                        <li><a href="#" class="text-muted text-decoration-none">Datenschutz</a></li>
                        <li><a href="#" class="text-muted text-decoration-none">Impressum</a></li>
                        <li><a href="#" class="text-muted text-decoration-none">Kontakt</a></li>
                    </ul>
                </div>
            </div>
            <hr class="my-4">
            <p class="text-center text-muted small mb-0">&copy; 2026 Rechtecheck. Alle Rechte vorbehalten.</p>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
