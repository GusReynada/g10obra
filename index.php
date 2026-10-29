<?php
/**
 * G10 Obra - Portal Principal
 * Si el usuario no ha iniciado sesión, presenta la pantalla de Login.
 * Si el usuario ya está autenticado, muestra el Panel de Trabajo.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

auth_start_session();

$error = null;
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Manejo del intento de Login
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $lockSeconds = login_locked_seconds();
    if ($lockSeconds > 0) {
        $error = "Demasiados intentos fallidos. Por seguridad, espera $lockSeconds segundos antes de reintentar.";
    } elseif (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'La sesión del formulario expiró. Por favor intenta de nuevo.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        $authError = auth_attempt($username, $password);
        if ($authError === null) {
            // Login exitoso: Redirigir a index.php para evitar reenvío de formulario
            header('Location: index.php');
            exit;
        } else {
            $error = $authError;
        }
    }
}

$currentUser = auth_user();

// ============================================================================
// VISTA: Si el usuario NO está autenticado, mostramos exclusivamente el LOGIN
// ============================================================================
if (!$currentUser):
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión | G10 Obra</title>
    <meta name="description" content="Sistema de Gestión y Control de Obras, Partidas e Insumos G10 Obra.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/auth.css?v=<?= filemtime(__DIR__ . '/assets/css/auth.css') ?>">
</head>
<body>
    <!-- Fondo decorativo con luces y malla -->
    <div class="bg-scene" aria-hidden="true">
        <div class="orb orb-1"></div>
        <div class="orb orb-2"></div>
        <div class="orb orb-3"></div>
        <div class="grid"></div>
    </div>

    <main class="auth-wrap">
        <section class="auth-card" aria-labelledby="login-title">
            <!-- Marca / Logotipo -->
            <div class="brand">
                <div class="brand-mark" aria-hidden="true">G10</div>
                <h1 id="login-title">G10 Obra</h1>
                <p>Ingresa tus credenciales para acceder al sistema</p>
            </div>

            <!-- Alertas de estado -->
            <?php if ($flash): ?>
                <div class="alert alert-info" role="status">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    <span><?= htmlspecialchars($flash) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error" role="alert">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <!-- Formulario de acceso -->
            <form method="POST" action="index.php" id="loginForm" novalidate>
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

                <!-- Campo: Usuario o Correo -->
                <div class="field">
                    <label for="username">Usuario o Correo Electrónico</label>
                    <div class="input-wrap">
                        <svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            required
                            autocomplete="username"
                            autofocus
                            placeholder="Ej. gustavo o tu@correo.com"
                            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                        >
                    </div>
                </div>

                <!-- Campo: Contraseña -->
                <div class="field">
                    <label for="password">Contraseña</label>
                    <div class="input-wrap">
                        <svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            required
                            autocomplete="current-password"
                            placeholder="••••••••"
                        >
                        <button type="button" class="toggle-pass" id="btnTogglePass" title="Mostrar/ocultar contraseña" aria-label="Mostrar u ocultar contraseña">
                            <svg id="eyeIcon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <!-- Botón de envío -->
                <button type="submit" class="btn btn-primary" id="btnSubmit">
                    <span class="spinner" aria-hidden="true"></span>
                    <span class="label">Ingresar al Sistema</span>
                    <span class="arrow" aria-hidden="true">&rarr;</span>
                </button>
            </form>

            <footer class="auth-foot">
                Acceso restringido a personal autorizado &middot; G10 Obra &copy; <?= date('Y') ?>
            </footer>
        </section>
    </main>

    <script>
        // Toggle ver/ocultar contraseña
        const passInput = document.getElementById('password');
        const btnToggle = document.getElementById('btnTogglePass');
        const eyeIcon   = document.getElementById('eyeIcon');

        btnToggle.addEventListener('click', () => {
            const isPassword = passInput.type === 'password';
            passInput.type = isPassword ? 'text' : 'password';
            eyeIcon.innerHTML = isPassword
                ? '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>'
                : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
        });

        // Estado de carga en el botón
        const form = document.getElementById('loginForm');
        const submitBtn = document.getElementById('btnSubmit');
        form.addEventListener('submit', () => {
            if (form.checkValidity()) {
                submitBtn.classList.add('loading');
                submitBtn.style.opacity = '0.85';
            }
        });
    </script>
</body>
</html>
<?php
exit;
endif;

// ============================================================================
// VISTA: Si el usuario SÍ está autenticado, mostramos el Panel de Trabajo
// ============================================================================

// Consultar métricas rápidas de las 9 tablas de la base de datos
$db = Database::getConnection();
$stats = [
    'obras'    => 0,
    'partidas' => 0,
    'insumos'  => 0,
    'modulos'  => 0,
];

if ($db) {
    try {
        $stats['obras']    = (int)$db->query("SELECT COUNT(*) FROM obra")->fetchColumn();
        $stats['partidas'] = (int)$db->query("SELECT COUNT(*) FROM obrapartidas")->fetchColumn();
        $stats['insumos']  = (int)$db->query("SELECT COUNT(*) FROM insumos")->fetchColumn();
        $st = $db->prepare("SELECT COUNT(*) FROM usuariosmodulos WHERE idUsuario = ? AND UsuModConsultar = 1");
        $st->execute([$currentUser['id']]);
        $stats['modulos']  = (int)$st->fetchColumn();
    } catch (Exception $e) {
        // En caso de incidencia no bloqueante
    }
}

// Cargar foto de perfil del usuario (si existe en usuarios.UsuFoto)
$userPhotoData = null;
if ($db && !empty($currentUser['id'])) {
    try {
        $stFoto = $db->prepare("SELECT UsuFoto FROM usuarios WHERE idUsuario = ? LIMIT 1");
        $stFoto->execute([$currentUser['id']]);
        $blobFoto = $stFoto->fetchColumn();
        if ($blobFoto) {
            $mime = 'image/jpeg';
            if (str_starts_with($blobFoto, "\x89PNG")) {
                $mime = 'image/png';
            } elseif (str_starts_with($blobFoto, "GIF8")) {
                $mime = 'image/gif';
            } elseif (str_starts_with($blobFoto, "RIFF") && substr($blobFoto, 8, 4) === "WEBP") {
                $mime = 'image/webp';
            }
            $userPhotoData = 'data:' . $mime . ';base64,' . base64_encode($blobFoto);
        }
    } catch (Exception $e) {}
}

// Consultar obras activas
$obrasActivas = [];
if ($db) {
    try {
        $stObras = $db->query("SELECT * FROM obra WHERE obrStatus = 'A' OR obrStatus IS NULL OR obrStatus = '' ORDER BY idObra DESC");
        $obrasActivas = $stObras->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $obrasActivas = [];
    }
}

// Mensaje de éxito tras registrar obra
$flashSuccess = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

// Iniciales del usuario para el avatar
$initials = 'U';
if (!empty($currentUser['nombre'])) {
    $parts = explode(' ', trim($currentUser['nombre']));
    $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Control | G10 Obra</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/auth.css?v=<?= filemtime(__DIR__ . '/assets/css/auth.css') ?>">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 1.15rem;
            margin: 2rem 0;
        }
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.4rem;
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            transition: transform 0.25s var(--ease), border-color 0.25s var(--ease);
        }
        .stat-card:hover {
            transform: translateY(-2px);
            border-color: var(--border-strong);
        }
        .stat-card .num {
            font-size: 2.2rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            background: linear-gradient(135deg, var(--text), var(--muted));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .stat-card .label {
            font-size: 0.825rem;
            color: var(--muted);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
            color: #f4f4f5;
            border: 1px solid rgba(255, 255, 255, 0.20);
        }
        .status-badge .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #ffffff;
            box-shadow: 0 0 8px rgba(255, 255, 255, 0.6);
        }
        /* Avatar con foto o iniciales muy coloridas */
        .avatar-img {
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, 0.25);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
        }
        .avatar.avatar-colorful {
            background: linear-gradient(135deg, #ff007f 0%, #7928ca 30%, #0070f3 65%, #00dfd8 100%);
            color: #ffffff;
            font-weight: 800;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.5);
            box-shadow: 0 0 20px rgba(121, 40, 202, 0.45), 0 4px 10px rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.35);
        }

        /* Sección Obras Activas */
        .obras-section {
            margin: 2rem 0;
        }
        .section-header-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            flex-wrap: wrap;
            gap: 1.25rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }
        .empty-obras-card {
            background: var(--surface);
            border: 1px dashed var(--border-strong);
            border-radius: var(--radius);
            padding: 3.5rem 1.5rem;
            text-align: center;
            backdrop-filter: blur(14px);
        }
        .empty-icon-wrap {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            display: grid;
            place-items: center;
            margin: 0 auto 1.25rem;
            color: var(--muted);
        }
        .obras-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.35rem;
        }
        .obra-card {
            color: inherit;
            text-decoration: none;
            cursor: pointer;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            backdrop-filter: blur(14px);
            display: flex;
            flex-direction: column;
            transition: transform 0.25s var(--ease), border-color 0.25s var(--ease), box-shadow 0.25s var(--ease);
        }
        .obra-card:hover {
            transform: translateY(-3px);
            border-color: rgba(255, 255, 255, 0.35);
            box-shadow: 0 16px 36px -15px rgba(0, 0, 0, 0.7);
        }
        .obra-card-cover {
            position: relative;
            height: 160px;
            background: #18181b;
            overflow: hidden;
        }
        .obra-card-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.4s var(--ease);
        }
        .obra-card:hover .obra-card-cover img {
            transform: scale(1.05);
        }
        .obra-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 0.25rem 0.65rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(8px);
        }
        .obra-card-cover .obra-status-pill {
            position: absolute;
            top: 0.85rem;
            right: 0.85rem;
        }
        .obra-card-body {
            padding: 1.35rem;
            display: flex;
            flex-direction: column;
            flex: 1;
        }
        .obra-contract-tag {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--muted);
            background: rgba(255, 255, 255, 0.05);
            padding: 0.25rem 0.55rem;
            border-radius: 6px;
            border: 1px solid var(--border);
            letter-spacing: 0.03em;
        }
        .obra-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: #ffffff;
            line-height: 1.35;
            margin: 0.4rem 0 0.5rem;
        }
        .obra-loc {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.82rem;
            color: var(--muted);
            margin-bottom: 1.1rem;
        }
        .obra-meta-grid {
            margin-top: auto;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
            font-size: 0.82rem;
        }
        .obra-meta-grid .lbl {
            display: block;
            font-size: 0.68rem;
            text-transform: uppercase;
            color: var(--subtle);
            letter-spacing: 0.04em;
            margin-bottom: 0.15rem;
        }
        .obra-meta-grid .val {
            font-weight: 600;
            color: #d4d4d8;
        }
        .obra-meta-grid .val.bold {
            font-weight: 700;
            color: #ffffff;
            font-size: 0.95rem;
        }

        /* ---------- Responsive Media Queries ---------- */
        @media (max-width: 900px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .obras-grid { grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); }
        }

        @media (max-width: 640px) {
            .hero h1 { font-size: 1.65rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; margin: 1.5rem 0; }
            .stat-card { padding: 1.1rem; }
            .stat-card .num { font-size: 1.75rem; }
            .section-header-row {
                flex-direction: column;
                align-items: stretch;
                gap: 0.85rem;
            }
            .section-header-row .btn {
                width: 100% !important;
                justify-content: center;
            }
            .obras-grid { grid-template-columns: 1fr; }
            .obra-card-cover { height: 140px; }
            .obra-card-body { padding: 1.15rem; }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .obra-meta-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="bg-scene" aria-hidden="true">
        <div class="orb orb-1"></div>
        <div class="orb orb-2"></div>
        <div class="grid"></div>
    </div>

    <div class="app">
        <!-- Barra Superior -->
        <header class="topbar">
            <div class="logo">
                <div class="brand-mark" aria-hidden="true">G10</div>
                <span>G10 Obra</span>
            </div>

            <div class="user-chip">
                <?php if (!empty($userPhotoData)): ?>
                    <img src="<?= $userPhotoData ?>" class="avatar avatar-img" alt="Foto de <?= htmlspecialchars($currentUser['nombre']) ?>">
                <?php else: ?>
                    <div class="avatar avatar-colorful" aria-hidden="true"><?= htmlspecialchars($initials) ?></div>
                <?php endif; ?>
                <div class="user-meta">
                    <strong><?= htmlspecialchars($currentUser['nombre']) ?></strong>
                  
                </div>
                <a href="logout.php" class="btn btn-ghost" title="Cerrar sesión">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    <span>Cerrar Sesión</span>
                </a>
            </div>
        </header>

        <!-- Contenido Principal -->
        <main class="content">
            <div class="hero">
                
                <h1>Bienvenido, <span class="grad"><?= htmlspecialchars($currentUser['nombre']) ?></span></h1>
                <p>Aqui podras gestionar las obras, sus partidas, insumos y modulos.</p>
            </div>

            <?php if (!empty($flashSuccess)): ?>
                <div class="alert alert-info" style="margin-top: 1.5rem; margin-bottom: 1.5rem; background: rgba(255, 255, 255, 0.08); border-color: rgba(255, 255, 255, 0.3); color: #ffffff;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <span><?= htmlspecialchars($flashSuccess) ?></span>
                </div>
            <?php endif; ?>

            <!-- Sección de Obras Activas -->
            <section class="obras-section" id="obras-activas">
                <div class="section-header-row">
                    <div>
                        <div style="display: flex; align-items: center; gap: 0.65rem;">
                            <h2 style="font-size: 1.4rem; font-weight: 700; color: #ffffff;">Obras Activas</h2>
                            <span class="badge" style="position: static; font-size: 0.72rem; padding: 0.2rem 0.6rem;"><?= count($obrasActivas) ?></span>
                        </div>
                        <p style="color: var(--muted); font-size: 0.88rem; margin-top: 0.35rem;">Proyectos vigentes y su desglose presupuestal.</p>
                    </div>

                    <a href="nueva_obra.php" class="btn btn-primary" style="width: auto; padding: 0.65rem 1.35rem; font-size: 0.88rem;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        <span>Registrar nueva obra</span>
                    </a>
                </div>

                <?php if (empty($obrasActivas)): ?>
                    <div class="empty-obras-card">
                        <div class="empty-icon-wrap">
                            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                        </div>
                        <h3 style="font-size: 1.15rem; font-weight: 700; color: #ffffff; margin-bottom: 0.45rem;">Todavia no tienes obras activas</h3>
                        <p style="color: var(--muted); font-size: 0.88rem; max-width: 460px; margin: 0 auto 1.35rem; line-height: 1.55;">
                            Registra tu primera obra para gestionar contratos, presupuestos, asignación de partidas y catálogo de insumos.
                        </p>
                        <a href="nueva_obra.php" class="btn btn-primary" style="width: auto; padding: 0.75rem 1.6rem; font-size: 0.9rem;">
                            <span>Registrar nueva obra &rarr;</span>
                        </a>
                    </div>
                <?php else: ?>
                    <div class="obras-grid">
                        <?php foreach ($obrasActivas as $obra): ?>
                            <?php
                                $obraFotoData = null;
                                $blobObra = $obra['obrFotoMiniatura'] ?: $obra['obrFoto'];
                                if (!empty($blobObra)) {
                                    $mime = 'image/jpeg';
                                    if (str_starts_with($blobObra, "\x89PNG")) $mime = 'image/png';
                                    elseif (str_starts_with($blobObra, "GIF8")) $mime = 'image/gif';
                                    elseif (str_starts_with($blobObra, "RIFF") && substr($blobObra, 8, 4) === "WEBP") $mime = 'image/webp';
                                    $obraFotoData = 'data:' . $mime . ';base64,' . base64_encode($blobObra);
                                }
                                $montoTotal = ($obra['obrMontoSinIva'] ?? 0) + ($obra['obrIva'] ?? 0) - ($obra['obrRetencion'] ?? 0);
                            ?>
                            <a href="obra.php?id=<?= (int)$obra['idObra'] ?>" class="obra-card" id="obra-<?= (int)$obra['idObra'] ?>" aria-label="Ver partidas de <?= htmlspecialchars($obra['obrNombreObra']) ?>">
                                <?php if ($obraFotoData): ?>
                                    <div class="obra-card-cover">
                                        <img src="<?= $obraFotoData ?>" alt="<?= htmlspecialchars($obra['obrNombreObra']) ?>">
                                        <span class="obra-status-pill">Activa</span>
                                    </div>
                                <?php endif; ?>

                                <div class="obra-card-body">
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 0.5rem; margin-bottom: 0.5rem;">
                                        <span class="obra-contract-tag"><?= htmlspecialchars($obra['obrContrato'] ?: 'S/N CONTRATO') ?></span>
                                        <?php if (!$obraFotoData): ?>
                                            <span class="obra-status-pill">Activa</span>
                                        <?php endif; ?>
                                    </div>

                                    <h3 class="obra-title"><?= htmlspecialchars($obra['obrNombreObra']) ?></h3>

                                    <?php if (!empty($obra['obrUbicacion'])): ?>
                                        <p class="obra-loc">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                            <span><?= htmlspecialchars($obra['obrUbicacion']) ?></span>
                                        </p>
                                    <?php endif; ?>

                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Módulos del Sistema -->
            <h2 style="font-size: 1.15rem; font-weight: 700; margin-top: 2rem;">Módulos del Sistema</h2>
            <div class="modules">
                <a href="nueva_obra.php" class="module" style="display: block;">
                    <span class="badge" style="background: rgba(255, 255, 255, 0.12); color: #ffffff;">+ Registrar</span>
                    <div class="m-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                    </div>
                    <h3>Gestión de Obras</h3>
                    <p>Tabla <code>obra</code>. Alta, edición, cálculo de IVA, retención, montos y estatus de obras.</p>
                </a>

                <div class="module">
                    <span class="badge">Pendiente</span>
                    <div class="m-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                    </div>
                    <h3>Partidas de Obra</h3>
                    <p>Tabla <code>obrapartidas</code>. Desglose de actividades, claves y cronograma por obra.</p>
                </div>

                <div class="module">
                    <span class="badge">Pendiente</span>
                    <div class="m-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                    </div>
                    <h3>Catálogo de Insumos</h3>
                    <p>Tablas <code>insumos</code> y <code>obrapartidasinsumos</code>. Asignación de cantidades y saldos.</p>
                </div>

                <div class="module">
                    <span class="badge">Configurado</span>
                    <div class="m-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <h3>Usuarios y Permisos</h3>
                    <p>Tablas <code>usuarios</code>, <code>usuariosmodulos</code> y <code>usuariosmodulosmodulos</code>.</p>
                </div>
            </div>

           
        </main>
    </div>
</body>
</html>
