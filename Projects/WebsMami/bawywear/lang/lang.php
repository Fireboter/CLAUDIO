<?php
function t(string $key, array $params = []): string {
    static $translations = null;
    if ($translations === null) {
        $lang = $_SESSION['lang'] ?? 'es';
        $file = dirname(__DIR__) . '/lang/' . $lang . '.php';
        if (!file_exists($file)) $file = dirname(__DIR__) . '/lang/es.php';
        $translations = require $file;
    }
    $text = $translations[$key] ?? $key;
    foreach ($params as $k => $v) {
        $text = str_replace('{' . $k . '}', $v, $text);
    }
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function set_lang(string $lang): void {
    $_SESSION['lang'] = in_array($lang, ['es', 'ca']) ? $lang : 'es';
}

// Handle ?lang= query param
if (isset($_GET['lang'])) {
    set_lang($_GET['lang']);
    $url = strtok($_SERVER['REQUEST_URI'], '?');
    redirect($url);
}
