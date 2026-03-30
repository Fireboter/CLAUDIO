<?php

function generate_gift_code(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = 'GIFT-';
    for ($i = 0; $i < 4; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
    $code .= '-';
    for ($i = 0; $i < 4; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
    return $code;
}

function generate_order_number(): string {
    $datePrefix = date('ymd'); // YYMMDD
    $suffix = strtoupper(substr(base_convert(rand(0, PHP_INT_MAX), 10, 36), 0, 6));
    return $datePrefix . $suffix;
}

function format_price(float $amount): string {
    return number_format($amount, 2, '.', '') . ' €';
}

function format_date(string $date): string {
    return date('d/m/Y', strtotime($date));
}

function slugify(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[àáâãäå]/u', 'a', $text);
    $text = preg_replace('/[èéêë]/u', 'e', $text);
    $text = preg_replace('/[ìíîï]/u', 'i', $text);
    $text = preg_replace('/[òóôõö]/u', 'o', $text);
    $text = preg_replace('/[ùúûü]/u', 'u', $text);
    $text = preg_replace('/[^a-z0-9\-]/', '-', $text);
    $text = preg_replace('/-+/', '-', $text);
    return trim($text, '-');
}

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(): bool {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}
