<?php

function auth_check(): void {
    if (empty($_SESSION['admin_logged_in'])) {
        redirect('/admin/login');
    }
}

function auth_login(string $password): bool {
    if (!defined('ADMIN_PASSWORD')) return false;
    if (password_verify($password, ADMIN_PASSWORD)) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_login_time'] = time();
        return true;
    }
    // Also support plain text password for simplicity (hash on first login)
    if ($password === ADMIN_PASSWORD) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_login_time'] = time();
        return true;
    }
    return false;
}

function auth_logout(): void {
    $_SESSION = [];
    session_destroy();
}

function auth_is_logged_in(): bool {
    return !empty($_SESSION['admin_logged_in']);
}
