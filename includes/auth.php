<?php
/**
 * Autenticación y sesión - tabla `usuarios`
 *
 * Usuario:    usuLogin  o  UsuCorreoElectronico
 * Contraseña: usuPassword (codificación heredada, ver includes/crypto.php)
 * Solo usuarios con UsuStatus = 'A' pueden ingresar.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/crypto.php';

const SESSION_IDLE_SECONDS = 7200;   // 2 horas de inactividad
const LOGIN_MAX_ATTEMPTS   = 5;      // intentos fallidos permitidos...
const LOGIN_LOCK_SECONDS   = 300;    // ...antes de bloquear 5 minutos

/**
 * Inicia la sesión con cookies seguras.
 */
function auth_start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_name('G10SESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // Cierre por inactividad
    if (isset($_SESSION['user'], $_SESSION['last_activity'])
        && time() - $_SESSION['last_activity'] > SESSION_IDLE_SECONDS) {
        auth_logout(false);
        session_start();
        $_SESSION['flash'] = 'Tu sesión expiró por inactividad. Ingresa de nuevo.';
    }
    $_SESSION['last_activity'] = time();
}

function auth_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function auth_check(): bool {
    return auth_user() !== null;
}

/**
 * Protege una página: si no hay sesión, redirige al login.
 */
function require_login(): void {
    auth_start_session();
    if (!auth_check()) {
        header('Location: index.php');
        exit;
    }
}

/* ---------- CSRF ---------- */

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_verify(?string $token): bool {
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

/* ---------- Límite de intentos ---------- */

function login_locked_seconds(): int {
    $until = $_SESSION['login_lock_until'] ?? 0;
    return max(0, $until - time());
}

function login_register_failure(): void {
    $_SESSION['login_fails'] = ($_SESSION['login_fails'] ?? 0) + 1;
    if ($_SESSION['login_fails'] >= LOGIN_MAX_ATTEMPTS) {
        $_SESSION['login_lock_until'] = time() + LOGIN_LOCK_SECONDS;
        $_SESSION['login_fails'] = 0;
    }
}

/* ---------- Login / Logout ---------- */

/**
 * Intenta iniciar sesión. Devuelve null si tuvo éxito o un mensaje de error.
 */
function auth_attempt(string $identifier, string $password): ?string {
    $identifier = trim($identifier);
    if ($identifier === '' || $password === '') {
        return 'Ingresa tu usuario o correo y tu contraseña.';
    }

    $db = Database::getConnection();
    if (!$db) {
        return 'No fue posible conectar con el servidor. Intenta más tarde.';
    }

    $stmt = $db->prepare(
        "SELECT idUsuario, usuLogin, usuPassword, UsuPasswordV1, UsuCorreoElectronico,
                UsuNombre, UsuPaterno, UsuMaterno, usuNivel, UsuStatus
           FROM usuarios
          WHERE usuLogin = :ident1 OR UsuCorreoElectronico = :ident2"
    );
    $stmt->execute([':ident1' => $identifier, ':ident2' => $identifier]);

    $match = null;
    foreach ($stmt->fetchAll() as $row) {
        $stored = (string)($row['usuPassword'] ?? '');
        $valid = false;

        // 1. Codificación heredada (+127) en usuPassword
        if ($stored !== '') {
            try {
                if (legacy_decode($stored) === $password || legacy_encode($password) === $stored) {
                    $valid = true;
                }
            } catch (Throwable $e) {}
        }

        // 2. TripleDES desencriptar64 sobre usuPassword
        if (!$valid && $stored !== '') {
            try {
                $dec = @desencriptar64($stored);
                if ($dec !== false && $dec === $password) {
                    $valid = true;
                }
            } catch (Throwable $e) {}
        }

        // 3. TripleDES desencriptar64 sobre UsuPasswordV1 (si existe)
        if (!$valid && !empty($row['UsuPasswordV1'])) {
            try {
                $decV1 = @desencriptar64($row['UsuPasswordV1']);
                if ($decV1 !== false && $decV1 === $password) {
                    $valid = true;
                }
            } catch (Throwable $e) {}
        }

        // 4. Comparación directa si estuviera en texto plano
        if (!$valid && $stored !== '' && hash_equals($stored, $password)) {
            $valid = true;
        }

        if ($valid) {
            $match = $row;
            break;
        }
    }

    if ($match === null) {
        login_register_failure();
        return 'Usuario o contraseña incorrectos.';
    }

    if (strtoupper((string)$match['UsuStatus']) !== 'A') {
        return 'Tu usuario está inactivo. Contacta al administrador.';
    }

    // Éxito: regenerar id de sesión para evitar fijación si aún no se enviaron encabezados
    if (!headers_sent() && session_status() === PHP_SESSION_ACTIVE) {
        @session_regenerate_id(true);
    }
    unset($_SESSION['login_fails'], $_SESSION['login_lock_until']);

    $nombre = trim(implode(' ', array_filter([
        $match['UsuNombre'], $match['UsuPaterno'], $match['UsuMaterno'],
    ])));

    $_SESSION['user'] = [
        'id'     => (int)$match['idUsuario'],
        'login'  => $match['usuLogin'],
        'email'  => $match['UsuCorreoElectronico'],
        'nombre' => $nombre !== '' ? $nombre : $match['usuLogin'],
        'nivel'  => (int)$match['usuNivel'],
    ];
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));

    return null;
}

function auth_logout(bool $restart = true): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        auth_start_session();
    }
    $_SESSION = [];
    if (!headers_sent() && ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}
