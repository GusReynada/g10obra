<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';

// Si ya está logueado, redirigir al dashboard
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: dashboard.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username) && !empty($password)) {
        $db = Database::getConnection();
        if ($db) {
            try {
                $stmt = $db->prepare("SELECT * FROM admins WHERE username = ? OR email = ? LIMIT 1");
                $stmt->execute([$username, $username]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_user'] = [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'email' => $user['email']
                    ];
                    header('Location: dashboard.php');
                    exit;
                } else {
                    $error = 'Credenciales inválidas. Revisa tu usuario y contraseña.';
                }
            } catch (Exception $e) {
                $error = 'Error al consultar la base de datos: ' . $e->getMessage();
            }
        } else {
            // Modo demo o fallback si aún no se importó schema.sql
            if ($username === 'admin' && $password === 'admin123') {
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_user'] = ['id' => 1, 'username' => 'admin', 'email' => 'admin@g10obra.com'];
                header('Location: dashboard.php');
                exit;
            }
            $error = 'No se pudo conectar a MySQL. Puedes ingresar temporalmente con admin / admin123 para probar la interfaz.';
        }
    } else {
        $error = 'Por favor completa todos los campos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Administrativo | G10 Obra</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 1.5rem;
            background: #09090b;
        }
        .login-card {
            width: 100%;
            max-width: 420px;
            background: #121215;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: var(--radius);
            padding: 2.25rem;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
        }
    </style>
</head>
<body class="dark-theme">
    <div class="login-card">
        <div style="text-align: center; margin-bottom: 2rem;">
            <div style="display: inline-flex; align-items: center; justify-content: center; width: 2.75rem; height: 2.75rem; background: #fff; color: #000; border-radius: 8px; font-weight: 900; font-size: 1.15rem; margin-bottom: 1rem;">
                G10
            </div>
            <h1 style="font-size: 1.35rem; font-weight: 700; letter-spacing: -0.02em;">Panel de Gestión</h1>
            <p style="font-size: 0.825rem; color: var(--muted-foreground); margin-top: 0.35rem;">Ingresa tus credenciales para administrar obras y mensajes</p>
        </div>

        <?php if ($error): ?>
            <div style="background: rgba(239, 68, 68, 0.12); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.25); padding: 0.75rem 1rem; border-radius: 6px; font-size: 0.825rem; margin-bottom: 1.25rem;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="form-group">
                <label class="form-label" for="username">Usuario o Correo</label>
                <input class="form-input" type="text" id="username" name="username" required autofocus placeholder="admin" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>

            <div class="form-group" style="margin-bottom: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">
                    <label class="form-label" for="password" style="margin-bottom: 0;">Contraseña</label>
                </div>
                <input class="form-input" type="password" id="password" name="password" required placeholder="••••••••">
            </div>

            <button type="submit" class="btn btn-primary btn-block" style="padding: 0.65rem 1rem;">
                Iniciar Sesión &rarr;
            </button>
        </form>

        <div style="margin-top: 2rem; padding-top: 1.25rem; border-top: 1px solid var(--border); text-align: center;">
            <a href="../index.php" style="font-size: 0.8rem; color: var(--muted-foreground);">&larr; Volver al sitio público</a>
            <div style="margin-top: 0.75rem; font-size: 0.725rem; color: #52525b;">
                Credenciales por defecto: <code>admin</code> / <code>admin123</code>
            </div>
        </div>
    </div>
</body>
</html>
