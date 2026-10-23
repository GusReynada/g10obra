<?php
/**
 * Admin Session & Authentication Guard
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

function require_admin_login(): void {
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        header('Location: login.php');
        exit;
    }
}

function get_current_admin(): ?array {
    return $_SESSION['admin_user'] ?? null;
}
