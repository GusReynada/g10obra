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
    <link rel="stylesheet" href="assets/css/auth.css">
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
    <link rel="stylesheet" href="assets/css/auth.css">
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
            background: hsla(152, 60%, 50%, 0.12);
            color: hsl(152, 70%, 55%);
            border: 1px solid hsla(152, 60%, 50%, 0.25);
        }
        .status-badge .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: hsl(152, 70%, 55%);
            box-shadow: 0 0 10px hsl(152, 70%, 55%);
        }
        .info-box {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.75rem;
            margin-top: 2rem;
            backdrop-filter: blur(14px);
        }
        .info-box h2 {
            font-size: 1.15rem;
            font-weight: 700;
            margin-bottom: 0.6rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .info-box p {
            color: var(--muted);
            font-size: 0.9rem;
            line-height: 1.55;
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
                <div class="avatar" aria-hidden="true"><?= htmlspecialchars($initials) ?></div>
                <div class="user-meta">
                    <strong><?= htmlspecialchars($currentUser['nombre']) ?></strong>
                    <span><?= htmlspecialchars($currentUser['login']) ?></span>
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
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 0.5rem;">
                    <div class="status-badge">
                        <span class="dot"></span>
                        <span>Hostinger MySQL Conectado &middot; <?= htmlspecialchars(DB_NAME) ?></span>
                    </div>
                    <span style="font-size: 0.8rem; color: var(--subtle);">Nivel de acceso: <?= (int)$currentUser['nivel'] ?></span>
                </div>
                <h1>Bienvenido, <span class="grad"><?= htmlspecialchars($currentUser['nombre']) ?></span></h1>
                <p>Sesión activa mediante tabla <code>usuarios</code>. Tu sistema está listo para gestionar las 9 tablas de la base de datos.</p>
            </div>

            <!-- Métricas rápidas de las tablas activas -->
            <section class="stats-grid" aria-label="Métricas del sistema">
                <div class="stat-card">
                    <span class="label">Obras Registradas</span>
                    <span class="num"><?= $stats['obras'] ?></span>
                </div>
                <div class="stat-card">
                    <span class="label">Partidas de Obra</span>
                    <span class="num"><?= $stats['partidas'] ?></span>
                </div>
                <div class="stat-card">
                    <span class="label">Insumos en Catálogo</span>
                    <span class="num"><?= $stats['insumos'] ?></span>
                </div>
                <div class="stat-card">
                    <span class="label">Módulos Asignados</span>
                    <span class="num"><?= $stats['modulos'] ?></span>
                </div>
            </section>

            <!-- Módulos de trabajo pendientes según la hoja de ruta -->
            <h2 style="font-size: 1.15rem; font-weight: 700; margin-top: 2rem;">Módulos del Sistema</h2>
            <div class="modules">
                <div class="module">
                    <span class="badge">Siguiente paso</span>
                    <div class="m-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                    </div>
                    <h3>Gestión de Obras</h3>
                    <p>Tabla <code>obra</code>. Alta, edición, cálculo de IVA, retención, montos y estatus de obras.</p>
                </div>

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

            <!-- Caja de confirmación de estado -->
            <section class="info-box">
                <h2>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--accent);"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    Acceso y Autenticación Completados
                </h2>
                <p>
                    El sistema está configurado para que al ingresar al dominio aparezca directamente la pantalla de inicio de sesión.
                    Las credenciales son validadas en tiempo real contra la base de datos de Hostinger usando tu usuario o correo electrónico y la contraseña correspondiente.
                </p>
            </section>
        </main>
    </div>
</body>
</html>
