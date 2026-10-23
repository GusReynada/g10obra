<?php
require_once __DIR__ . '/auth.php';
require_admin_login();

$db = Database::getConnection();

// Marcar como leído o archivado
if (isset($_GET['mark']) && isset($_GET['id']) && $db) {
    $id = (int)$_GET['id'];
    $status = in_array($_GET['mark'], ['read', 'unread', 'archived']) ? $_GET['mark'] : 'read';
    $stmt = $db->prepare("UPDATE contact_messages SET status = ? WHERE id = ?");
    $stmt->execute([$status, $id]);
    header('Location: messages.php');
    exit;
}

// Eliminar mensaje
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id']) && $db) {
    $id = (int)$_GET['id'];
    $stmt = $db->prepare("DELETE FROM contact_messages WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: messages.php?deleted=1');
    exit;
}

// Obtener mensajes
$messages = [];
if ($db) {
    try {
        $messages = $db->query("SELECT * FROM contact_messages ORDER BY id DESC")->fetchAll();
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="es" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bandeja de Mensajes | G10 Obra Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body { background-color: #09090b; color: #fafafa; }
        .admin-nav { border-bottom: 1px solid rgba(255, 255, 255, 0.08); background: #09090b; }
        .admin-nav-inner { display: flex; align-items: center; justify-content: space-between; height: 3.75rem; }
        .admin-menu { display: flex; align-items: center; gap: 1.5rem; list-style: none; }
        .admin-menu a { font-size: 0.85rem; color: #a1a1aa; }
        .admin-menu a:hover, .admin-menu a.active { color: #ffffff; }
        .msg-card {
            background: #121215;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: var(--radius);
            padding: 1.25rem 1.5rem;
            margin-bottom: 1rem;
            transition: border-color 0.15s ease;
        }
        .msg-card.unread {
            border-left: 3px solid #fff;
            background: #16161a;
        }
    </style>
</head>
<body class="dark-theme">

    <header class="admin-nav">
        <div class="container admin-nav-inner">
            <div style="display: flex; align-items: center; gap: 2rem;">
                <a href="dashboard.php" class="brand">
                    <span class="brand-badge">G10</span>
                    <span>OBRA</span>
                </a>
                <ul class="admin-menu">
                    <li><a href="dashboard.php">Panel Principal</a></li>
                    <li><a href="projects.php">Obras</a></li>
                    <li><a href="messages.php" class="active">Mensajes</a></li>
                </ul>
            </div>
            <div>
                <a href="logout.php" class="btn btn-secondary btn-sm" style="color: #ef4444;">Cerrar Sesión</a>
            </div>
        </div>
    </header>

    <main class="container" style="padding-top: 2rem; padding-bottom: 3rem; max-width: 900px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
            <div>
                <h1 style="font-size: 1.5rem; font-weight: 700;">Mensajes y Consultas</h1>
                <p style="font-size: 0.85rem; color: #a1a1aa;">Leads y contactos recibidos a través del formulario de la web.</p>
            </div>
            <a href="dashboard.php" class="btn btn-secondary btn-sm">&larr; Volver al Panel</a>
        </div>

        <?php if (!empty($messages)): ?>
            <?php foreach ($messages as $m): ?>
                <div class="msg-card <?= $m['status'] === 'unread' ? 'unread' : '' ?>">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem;">
                        <div>
                            <div style="font-weight: 700; font-size: 1rem; color: #fff;">
                                <?= htmlspecialchars($m['name']) ?>
                                <?php if ($m['status'] === 'unread'): ?>
                                    <span class="badge" style="background: #ffffff; color: #000; font-size: 0.65rem; margin-left: 0.5rem; border:none;">Nuevo</span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size: 0.8rem; color: #a1a1aa; margin-top: 0.2rem;">
                                Correo: <a href="mailto:<?= htmlspecialchars($m['email']) ?>" style="text-decoration: underline; color: #fff;"><?= htmlspecialchars($m['email']) ?></a>
                                <?php if (!empty($m['phone'])): ?>
                                    &middot; Tel: <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $m['phone']) ?>" target="_blank" style="color: #10b981;">WhatsApp (<?= htmlspecialchars($m['phone']) ?>)</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="font-size: 0.75rem; color: #71717a;">
                            <?= date('d/m/Y H:i', strtotime($m['created_at'])) ?>
                        </div>
                    </div>

                    <div style="background: #09090b; padding: 1rem; border-radius: 6px; font-size: 0.875rem; line-height: 1.6; color: #e4e4e7; margin: 1rem 0; border: 1px solid rgba(255,255,255,0.04);">
                        <?= nl2br(htmlspecialchars($m['message'])) ?>
                    </div>

                    <div style="display: flex; gap: 0.75rem; align-items: center; justify-content: flex-end;">
                        <?php if ($m['status'] === 'unread'): ?>
                            <a href="messages.php?mark=read&id=<?= $m['id'] ?>" class="btn btn-secondary btn-sm">Marcar como leído</a>
                        <?php else: ?>
                            <a href="messages.php?mark=unread&id=<?= $m['id'] ?>" class="btn btn-outline btn-sm">Marcar como no leído</a>
                        <?php endif; ?>
                        <a href="messages.php?action=delete&id=<?= $m['id'] ?>" class="btn btn-outline btn-sm" onclick="return confirm('¿Eliminar este mensaje?');" style="color: #ef4444;">Eliminar</a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="card" style="text-align: center; padding: 3rem; color: #a1a1aa;">
                No hay consultas registradas todavía. Cuando un usuario llene el formulario de contacto de la portada, aparecerá listado aquí en tiempo real.
            </div>
        <?php endif; ?>
    </main>

</body>
</html>
