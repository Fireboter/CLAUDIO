<?php
require_once dirname(__DIR__) . '/config.php';
require_once SHARED_PATH . '/db.php';
require_once SHARED_PATH . '/helpers.php';
require_once SHARED_PATH . '/cart.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Parse URL path (strip query string)
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/') ?: '/';

// Route matching
$routes = [
    '/'                             => 'home.php',
    '/shop'                         => 'shop.php',
    '/cart'                         => 'cart.php',
    '/checkout'                     => 'checkout.php',
    '/payment/success'              => 'payment-success.php',
    '/payment/failure'              => 'payment-failure.php',
    '/gift-card'                    => 'gift-card.php',
    '/collections/tarjeta-regalo'   => 'tarjeta-regalo.php',
    '/track-order'                  => 'track-order.php',
    '/pages/contact'                => 'contact.php',
    '/pages/quienes-somos'          => 'quienes-somos.php',
    '/pages/garantia'               => 'garantia.php',
    '/pages/punto-de-venta'         => 'punto-de-venta.php',
    '/policies/legal-notice'        => 'legal-notice.php',
    '/policies/privacy-policy'      => 'privacy-policy.php',
    '/policies/shipping-policy'     => 'shipping-policy.php',
    '/policies/contact-information' => 'contact-information.php',
];

// Dynamic routes (patterns)
if (preg_match('#^/product/(\d+)$#', $uri, $m)) {
    $productId = (int)$m[1];
    require dirname(__DIR__) . '/pages/product.php';
    exit;
}

// Admin routes
if (str_starts_with($uri, '/admin')) {
    require dirname(__DIR__) . '/admin/index.php';
    exit;
}

// API routes
if (str_starts_with($uri, '/api/')) {
    $apiFile = dirname(__DIR__) . $uri . '.php';
    if (is_file($apiFile)) { require $apiFile; exit; }
    // Handle routes without .php extension already in URI
    $apiFileDirect = dirname(__DIR__) . $uri;
    if (is_file($apiFileDirect)) { require $apiFileDirect; exit; }
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

if (isset($routes[$uri])) {
    require dirname(__DIR__) . '/pages/' . $routes[$uri];
    exit;
}

// 404
http_response_code(404);
require dirname(__DIR__) . '/pages/404.php';
