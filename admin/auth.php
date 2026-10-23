<?php
/**
 * Adaptador de autenticación para la carpeta admin/
 */
require_once __DIR__ . '/../includes/auth.php';

function require_admin_login(): void {
    auth_start_session();
    if (!auth_check()) {
        header('Location: ../index.php');
        exit;
    }
}

function get_current_admin(): ?array {
    return auth_user();
}
