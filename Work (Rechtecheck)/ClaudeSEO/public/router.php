<?php
// PHP dev server router: serve static files directly, everything else via index.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;
if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    return false; // serve static file as-is
}
require __DIR__ . '/index.php';
