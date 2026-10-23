<?php
require_once __DIR__ . '/auth.php';
require_admin_login();

$db = Database::getConnection();
$message = null;
$action = $_GET['action'] ?? 'list';
$editProject = null;

// Crear directorio de uploads si no existe
$uploadDir = __DIR__ . '/../uploads/projects/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Procesar Guardar / Editar Proyecto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_project'])) {
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $title = trim($_POST['title'] ?? '');
    $category = trim($_POST['category'] ?? 'Residencial');
    $location = trim($_POST['location'] ?? '');
    $year = trim($_POST['year'] ?? date('Y'));
    $surface_area = trim($_POST['surface_area'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $featured = isset($_POST['featured']) ? 1 : 0;
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)));

    $main_image = $_POST['existing_image'] ?? '';

    // Manejo de subida de imagen
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['image']['tmp_name'];
        $fileName = $_FILES['image']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];

        if (in_array($fileExtension, $allowedExtensions)) {
            $newFileName = uniqid('obra_', true) . '.' . $fileExtension;
            $destPath = $uploadDir . $newFileName;
            if (move_uploaded_file($fileTmpPath, $destPath)) {
                $main_image = 'uploads/projects/' . $newFileName;
            }
        }
    }

    if ($db && !empty($title)) {
        try {
            if ($id) {
                $stmt = $db->prepare("UPDATE projects SET title = ?, slug = ?, category = ?, location = ?, year = ?, surface_area = ?, description = ?, main_image = ?, featured = ? WHERE id = ?");
                $stmt->execute([$title, $slug, $category, $location, $year, $surface_area, $description, $main_image, $featured, $id]);
                $message = ['type' => 'success', 'text' => 'Obra actualizada con éxito.'];
            } else {
                $stmt = $db->prepare("INSERT INTO projects (title, slug, category, location, year, surface_area, description, main_image, featured) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$title, $slug, $category, $location, $year, $surface_area, $description, $main_image, $featured]);
                $message = ['type' => 'success', 'text' => 'Nueva obra agregada con éxito.'];
            }
            $action = 'list';
        } catch (Exception $e) {
            $message = ['type' => 'error', 'text' => 'Error en base de datos: ' . $e->getMessage()];
        }
    }
}

// Eliminar Proyecto
if ($action === 'delete' && isset($_GET['id']) && $db) {
    $id = (int)$_GET['id'];
    $stmt = $db->prepare("DELETE FROM projects WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: projects.php?deleted=1');
    exit;
}

// Cargar para edición
if ($action === 'edit' && isset($_GET['id']) && $db) {
    $id = (int)$_GET['id'];
    $stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
    $stmt->execute([$id]);
    $editProject = $stmt->fetch();
}

// Obtener lista de obras
$projects = [];
if ($db) {
    try {
        $projects = $db->query("SELECT * FROM projects ORDER BY id DESC")->fetchAll();
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="es" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Obras | G10 Obra Admin</title>
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
        .table-responsive { width: 100%; overflow-x: auto; margin-top: 1.5rem; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: #a1a1aa; padding: 0.75rem 1rem; border-bottom: 1px solid rgba(255,255,255,0.08); }
        td { padding: 0.85rem 1rem; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 0.85rem; vertical-align: middle; }
        tr:hover td { background: #18181b; }
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
                    <li><a href="projects.php" class="active">Obras</a></li>
                    <li><a href="messages.php">Mensajes</a></li>
                </ul>
            </div>
            <div>
                <a href="logout.php" class="btn btn-secondary btn-sm" style="color: #ef4444;">Cerrar Sesión</a>
            </div>
        </div>
    </header>

    <main class="container" style="padding-top: 2rem; padding-bottom: 3rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <div>
                <h1 style="font-size: 1.5rem; font-weight: 700;">Catálogo de Obras y Proyectos</h1>
                <p style="font-size: 0.85rem; color: #a1a1aa;">Administra las obras que se exhiben públicamente en el sitio.</p>
            </div>
            <?php if ($action === 'list'): ?>
                <a href="projects.php?action=new" class="btn btn-primary">+ Nueva Obra</a>
            <?php else: ?>
                <a href="projects.php" class="btn btn-secondary">&larr; Volver al Listado</a>
            <?php endif; ?>
        </div>

        <?php if ($message): ?>
            <div style="padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1.5rem; font-size: 0.85rem; <?= $message['type'] === 'success' ? 'background: rgba(16,185,129,0.15); color: #10b981; border: 1px solid rgba(16,185,129,0.3);' : 'background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3);' ?>">
                <?= htmlspecialchars($message['text']) ?>
            </div>
        <?php endif; ?>

        <?php if ($action === 'new' || $action === 'edit'): ?>
            <!-- Formulario de Creación / Edición -->
            <div class="card" style="max-width: 800px; margin: 0 auto;">
                <h2 style="font-size: 1.15rem; font-weight: 700; margin-bottom: 1.25rem;">
                    <?= $action === 'edit' ? 'Editar Obra: ' . htmlspecialchars($editProject['title']) : 'Registrar Nueva Obra' ?>
                </h2>

                <form method="POST" action="projects.php" enctype="multipart/form-data">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($editProject['id'] ?? '') ?>">
                    <input type="hidden" name="existing_image" value="<?= htmlspecialchars($editProject['main_image'] ?? '') ?>">

                    <div class="grid grid-cols-2">
                        <div class="form-group">
                            <label class="form-label">Título de la Obra *</label>
                            <input class="form-input" type="text" name="title" required value="<?= htmlspecialchars($editProject['title'] ?? '') ?>" placeholder="Ej: Residencia V&M">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Categoría</label>
                            <select class="form-select" name="category">
                                <?php
                                $categories = ['Residencial', 'Comercial', 'Remodelación', 'Diseño de Interiores', 'Edificio'];
                                foreach ($categories as $cat) {
                                    $selected = ($editProject['category'] ?? '') === $cat ? 'selected' : '';
                                    echo "<option value='$cat' $selected>$cat</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-3">
                        <div class="form-group">
                            <label class="form-label">Ubicación</label>
                            <input class="form-input" type="text" name="location" value="<?= htmlspecialchars($editProject['location'] ?? '') ?>" placeholder="Ej: San Isidro, Bs As">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Año</label>
                            <input class="form-input" type="text" name="year" value="<?= htmlspecialchars($editProject['year'] ?? date('Y')) ?>" placeholder="2026">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Superficie</label>
                            <input class="form-input" type="text" name="surface_area" value="<?= htmlspecialchars($editProject['surface_area'] ?? '') ?>" placeholder="Ej: 350 m²">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Fotografía Principal de la Obra</label>
                        <input class="form-input" type="file" name="image" accept="image/*">
                        <?php if (!empty($editProject['main_image'])): ?>
                            <div style="margin-top: 0.5rem; font-size: 0.75rem; color: #a1a1aa;">
                                Imagen actual: <code><?= htmlspecialchars($editProject['main_image']) ?></code>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Memoria Descriptiva / Detalles</label>
                        <textarea class="form-textarea" name="description" rows="5" placeholder="Detalla el concepto arquitectónico, sistemas constructivos empleados, etc."><?= htmlspecialchars($editProject['description'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group" style="display: flex; align-items: center; gap: 0.5rem;">
                        <input type="checkbox" id="featured" name="featured" value="1" <?= (!empty($editProject['featured'])) ? 'checked' : '' ?> style="accent-color: #fff; width: 1.1rem; height: 1.1rem;">
                        <label for="featured" class="form-label" style="margin-bottom: 0; cursor: pointer;">Destacar en la Portada Principal</label>
                    </div>

                    <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                        <button type="submit" name="save_project" class="btn btn-primary">
                            <?= $action === 'edit' ? 'Guardar Cambios' : 'Publicar Obra' ?>
                        </button>
                        <a href="projects.php" class="btn btn-secondary">Cancelar</a>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <!-- Listado de Obras -->
            <div class="card" style="padding: 0;">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Obra / Proyecto</th>
                                <th>Categoría</th>
                                <th>Ubicación</th>
                                <th>Año</th>
                                <th>Superficie</th>
                                <th>Destacado</th>
                                <th style="text-align: right;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($projects)): ?>
                                <?php foreach ($projects as $p): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 600; color: #fff;"><?= htmlspecialchars($p['title']) ?></div>
                                            <div style="font-size: 0.75rem; color: #a1a1aa;"><?= htmlspecialchars($p['slug']) ?></div>
                                        </td>
                                        <td><span class="badge"><?= htmlspecialchars($p['category']) ?></span></td>
                                        <td><?= htmlspecialchars($p['location'] ?: '—') ?></td>
                                        <td><?= htmlspecialchars($p['year'] ?: '—') ?></td>
                                        <td><?= htmlspecialchars($p['surface_area'] ?: '—') ?></td>
                                        <td>
                                            <?= $p['featured'] ? "<span class='badge' style='color:#10b981; border-color: rgba(16,185,129,0.3);'>Sí</span>" : "<span style='color:#71717a;'>No</span>" ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <a href="projects.php?action=edit&id=<?= $p['id'] ?>" class="btn btn-secondary btn-sm">Editar</a>
                                            <a href="projects.php?action=delete&id=<?= $p['id'] ?>" class="btn btn-outline btn-sm" onclick="return confirm('¿Seguro que deseas eliminar esta obra?');" style="color: #ef4444;">Eliminar</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 2.5rem; color: #a1a1aa;">
                                        No hay proyectos registrados en la base de datos aún. Haz clic en <strong>+ Nueva Obra</strong> para agregar el primero.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </main>

</body>
</html>
