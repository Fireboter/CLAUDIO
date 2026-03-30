<?php
require_once dirname(__DIR__) . '/config.php';
require_once SHARED_PATH . '/db.php';
require_once SHARED_PATH . '/helpers.php';
require_once SHARED_PATH . '/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/admin#', '', $uri);
$path = rtrim($path, '/') ?: '/';

// Public routes
if ($path === '/login')  { require __DIR__ . '/pages/login.php'; exit; }
if ($path === '/logout') { auth_logout(); redirect('/admin/login'); }

// All other routes require auth
auth_check();

$routes = [
    '/'           => 'dashboard.php',
    '/products'   => 'products.php',
    '/products/new' => 'products-new.php',
    '/collections'  => 'collections.php',
    '/collections/new' => 'collections-new.php',
    '/groups'     => 'groups.php',
    '/orders'     => 'orders.php',
    '/contacts'   => 'contacts.php',
    '/marketing'  => 'marketing.php',
    '/settings'   => 'settings.php',
];

// Dynamic routes
if (preg_match('#^/products/(\d+)/edit$#', $path, $m)) {
    $editId = (int)$m[1]; require __DIR__ . '/pages/products-edit.php'; exit;
}
if (preg_match('#^/collections/(\d+)/edit$#', $path, $m)) {
    $editId = (int)$m[1]; require __DIR__ . '/pages/collections-edit.php'; exit;
}
if (preg_match('#^/orders/(\d+)$#', $path, $m)) {
    $orderId = (int)$m[1]; require __DIR__ . '/pages/orders-detail.php'; exit;
}

// API routes within admin
if (str_starts_with($path, '/api/')) {
    $base = __DIR__ . $path;
    $file = is_file($base) ? $base : $base . '.php';
    if (is_file($file)) { require $file; exit; }
}

if (isset($routes[$path])) { require __DIR__ . '/pages/' . $routes[$path]; exit; }
http_response_code(404); echo '<h1>404</h1>';
