<?php
// PHP built-in server router: serve static files directly, route the rest through index.php
if (php_sapi_name() === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file)) {
        return false; // serve the file as-is
    }
}
require __DIR__ . '/index.php';
