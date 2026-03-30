<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= isset($pageTitle) ? e($pageTitle) . ' — ' : '' ?>Admin <?= e(SITE_NAME) ?></title>
  <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-body">
<div class="admin-layout">
  <aside class="admin-sidebar">
    <div class="sidebar-logo">
      <a href="/admin/"><?= e(SITE_NAME) ?></a>
    </div>
    <?php
    $currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $navItems = [
        '/admin/'          => 'Dashboard',
        '/admin/products'  => 'Productos',
        '/admin/orders'    => 'Pedidos',
        '/admin/contacts'  => 'Contactos',
        '/admin/marketing' => 'Marketing',
        '/admin/settings'  => 'Ajustes',
    ];
    foreach ($navItems as $href => $label):
        $active = $currentPath === $href || str_starts_with($currentPath, $href . '/') ? 'active' : '';
    ?>
      <a href="<?= $href ?>" class="sidebar-link <?= $active ?>"><?= $label ?></a>
    <?php endforeach; ?>
    <div class="sidebar-footer">
      <a href="<?= e(PARTNER_ADMIN_URL) ?>" target="_blank" class="sidebar-link" style="font-size:0.75rem;opacity:0.6">Ir a otro admin</a>
      <a href="/admin/logout" class="sidebar-link" style="color:#dc2626">Cerrar sesión</a>
    </div>
  </aside>
  <main class="admin-main">
    <div class="admin-content">
