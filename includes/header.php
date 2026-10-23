<?php
if (!defined('APP_INIT')) {
    define('APP_INIT', true);
}
require_once __DIR__ . '/../config/db.php';
$dbStatus = Database::checkStatus();
?>
<!DOCTYPE html>
<html lang="es" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'G10 Obra - Arquitectura & Construcción') ?></title>
    <meta name="description" content="Estudio de arquitectura, diseño y ejecución de obras de alta precisión.">
    <!-- Google Fonts Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Main Stylesheet -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="dark-theme">
    <header class="site-header">
        <div class="container nav-wrap">
            <a href="index.php" class="brand" aria-label="Inicio">
                <span class="brand-badge">G10</span>
                <span>OBRA</span>
            </a>

            <nav>
                <ul class="nav-links" id="nav-links">
                    <li><a href="index.php" class="nav-link active">Inicio</a></li>
                    <li><a href="#proyectos" class="nav-link">Proyectos</a></li>
                    <li><a href="#servicios" class="nav-link">Servicios</a></li>
                    <li><a href="#contacto" class="nav-link">Contacto</a></li>
                    <li><a href="admin/login.php" class="nav-link" style="color: #fafafa;">Acceso Admin</a></li>
                </ul>
            </nav>

            <div class="nav-actions">
                <button type="button" class="btn-icon" id="theme-toggle" aria-label="Cambiar tema claro/oscuro">
                    <span id="theme-toggle-icon"></span>
                </button>
                <a href="#contacto" class="btn btn-primary">Cotizar Obra</a>
            </div>
        </div>
    </header>
    <main>
