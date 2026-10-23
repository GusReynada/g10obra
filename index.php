<?php
define('APP_INIT', true);
$pageTitle = "G10 Obra | Arquitectura, Diseño & Construcción";
require_once __DIR__ . '/includes/header.php';

// Cargar proyectos desde la base de datos
$db = Database::getConnection();
$projects = [];
if ($db) {
    try {
        $stmt = $db->query("SELECT * FROM projects WHERE status = 'published' ORDER BY featured DESC, id DESC LIMIT 6");
        $projects = $stmt->fetchAll();
    } catch (Exception $e) {}
}

// Manejo del formulario de contacto
$feedbackMessage = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_contact'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (!empty($name) && !empty($email) && !empty($message)) {
        if ($db) {
            try {
                $stmt = $db->prepare("INSERT INTO contact_messages (name, email, phone, message) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $email, $phone, $message]);
                $feedbackMessage = ['type' => 'success', 'text' => '¡Mensaje recibido con éxito! El equipo de G10 Obra se pondrá en contacto a la brevedad.'];
            } catch (Exception $e) {
                $feedbackMessage = ['type' => 'error', 'text' => 'Error al guardar el mensaje: ' . $e->getMessage()];
            }
        } else {
            $feedbackMessage = ['type' => 'error', 'text' => 'La base de datos MySQL aún no está conectada. Configura tus credenciales en config/db.php para recibir los mensajes en tu panel.'];
        }
    } else {
        $feedbackMessage = ['type' => 'error', 'text' => 'Por favor completa todos los campos requeridos (*).'];
    }
}
?>

<!-- Sección Hero -->
<section class="section hero" style="padding: 7rem 0 5rem;">
    <div class="container" style="text-align: center;">
        <span class="badge" style="margin-bottom: 1.25rem;">
            <span class="badge-dot success"></span>
            ESTUDIO &middot; CONSTRUCTORA &middot; DISEÑO
        </span>
        <h1 class="hero-title" style="max-width: 900px; margin: 0 auto 1.5rem;">
            Espacios que trascienden.<br>Precisión en cada detalle.
        </h1>
        <p class="hero-subtitle">
            Desarrollo integral de proyectos arquitectónicos, dirección técnica y ejecución de obras con los más altos estándares constructivos y materialidad noble.
        </p>
        <div class="hero-cta" style="margin-bottom: 3.5rem;">
            <a href="#contacto" class="btn btn-primary" style="padding: 0.75rem 1.75rem; font-size: 0.95rem;">Iniciar Proyecto &rarr;</a>
            <a href="#proyectos" class="btn btn-secondary" style="padding: 0.75rem 1.75rem; font-size: 0.95rem;">Explorar Obras</a>
            <a href="admin/login.php" class="btn btn-outline" style="padding: 0.75rem 1.25rem; font-size: 0.95rem;">Panel Admin</a>
        </div>

        <!-- Estado de Conexión MySQL / Hostinger -->
        <div class="db-status-box" style="max-width: 720px; margin: 0 auto; text-align: left;">
            <div>
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.35rem;">
                    <span class="badge-dot <?= $dbStatus['success'] ? 'success' : 'error' ?>"></span>
                    <strong style="font-size: 0.9rem;">
                        <?= $dbStatus['success'] ? 'MySQL Conectado y Listo' : 'MySQL Pendiente de Conexión (Hostinger)' ?>
                    </strong>
                </div>
                <p style="font-size: 0.8rem; color: var(--muted-foreground); margin: 0;">
                    <?= htmlspecialchars($dbStatus['message']) ?>
                    <?php if (!$dbStatus['success']): ?>
                        <br><small>Edita <code>config/db.php</code> con el nombre de BD, usuario y clave creados en tu hPanel de Hostinger.</small>
                    <?php endif; ?>
                </p>
            </div>
            <span class="badge"><?= htmlspecialchars(DB_HOST) ?></span>
        </div>
    </div>
</section>

<!-- Sección Obras Destacadas -->
<section class="section" id="proyectos" style="border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); background-color: var(--card);">
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 3rem; flex-wrap: wrap; gap: 1rem;">
            <div>
                <span class="badge" style="margin-bottom: 0.5rem;">Portafolio</span>
                <h2 style="font-size: 2rem; font-weight: 700; letter-spacing: -0.02em;">Obras y Proyectos</h2>
            </div>
            <a href="admin/projects.php" class="btn btn-outline btn-sm">Gestionar Obras desde el Panel &rarr;</a>
        </div>

        <div class="grid grid-cols-3">
            <?php if (!empty($projects)): ?>
                <?php foreach ($projects as $item): ?>
                    <article class="card">
                        <div style="height: 220px; background: var(--secondary); border-radius: calc(var(--radius) * 0.7); margin-bottom: 1.25rem; overflow: hidden; display: flex; align-items: center; justify-content: center; border: 1px solid var(--border);">
                            <?php if (!empty($item['main_image']) && file_exists(__DIR__ . '/' . $item['main_image'])): ?>
                                <img src="<?= htmlspecialchars($item['main_image']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <div style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; color: var(--muted-foreground);">
                                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                        <polyline points="21 15 16 10 5 21"></polyline>
                                    </svg>
                                    <span style="font-size: 0.8rem; font-weight: 500;">Fotografía de Obra</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                            <span class="badge"><?= htmlspecialchars($item['category']) ?></span>
                            <?php if (!empty($item['year'])): ?>
                                <span style="font-size: 0.775rem; color: var(--muted-foreground);"><?= htmlspecialchars($item['year']) ?></span>
                            <?php endif; ?>
                        </div>
                        <h3 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 0.35rem;"><?= htmlspecialchars($item['title']) ?></h3>
                        <?php if (!empty($item['location'])): ?>
                            <p style="font-size: 0.8rem; color: var(--muted-foreground); margin-bottom: 0.65rem;">
                                📍 <?= htmlspecialchars($item['location']) ?> <?= !empty($item['surface_area']) ? ' &middot; ' . htmlspecialchars($item['surface_area']) : '' ?>
                            </p>
                        <?php endif; ?>
                        <p style="color: var(--muted-foreground); font-size: 0.85rem; line-height: 1.5;">
                            <?= htmlspecialchars(substr($item['description'] ?? '', 0, 110)) ?><?= strlen($item['description'] ?? '') > 110 ? '...' : '' ?>
                        </p>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <!-- Tarjetas de muestra idénticas al estilo de la referencia si aún no hay obras en MySQL -->
                <article class="card">
                    <div style="height: 220px; background: var(--secondary); border-radius: calc(var(--radius) * 0.7); margin-bottom: 1.25rem; display: flex; align-items: center; justify-content: center; border: 1px solid var(--border); color: var(--muted-foreground);">
                        <span>Residencia V&amp;M</span>
                    </div>
                    <span class="badge" style="margin-bottom: 0.5rem;">Residencial &middot; 2026</span>
                    <h3 style="font-size: 1.2rem; font-weight: 700; margin-bottom: 0.5rem;">Residencia V&amp;M</h3>
                    <p style="color: var(--muted-foreground); font-size: 0.85rem;">
                        Vivienda unifamiliar con volumetría pura de hormigón visto, amplios paños vidriados y patios interiores.
                    </p>
                </article>

                <article class="card">
                    <div style="height: 220px; background: var(--secondary); border-radius: calc(var(--radius) * 0.7); margin-bottom: 1.25rem; display: flex; align-items: center; justify-content: center; border: 1px solid var(--border); color: var(--muted-foreground);">
                        <span>Edificio Nexo</span>
                    </div>
                    <span class="badge" style="margin-bottom: 0.5rem;">Comercial &middot; 2025</span>
                    <h3 style="font-size: 1.2rem; font-weight: 700; margin-bottom: 0.5rem;">Edificio Nexo</h3>
                    <p style="color: var(--muted-foreground); font-size: 0.85rem;">
                        Sede corporativa bioclimática con certificación de sustentabilidad, piel vidriada y control solar pasivo.
                    </p>
                </article>

                <article class="card">
                    <div style="height: 220px; background: var(--secondary); border-radius: calc(var(--radius) * 0.7); margin-bottom: 1.25rem; display: flex; align-items: center; justify-content: center; border: 1px solid var(--border); color: var(--muted-foreground);">
                        <span>Loft Histórico</span>
                    </div>
                    <span class="badge" style="margin-bottom: 0.5rem;">Remodelación &middot; 2025</span>
                    <h3 style="font-size: 1.2rem; font-weight: 700; margin-bottom: 0.5rem;">Loft Histórico</h3>
                    <p style="color: var(--muted-foreground); font-size: 0.85rem;">
                        Puesta en valor de edificio patrimonial preservando techos altos originales y muros de ladrillo visto.
                    </p>
                </article>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Sección Servicios -->
<section class="section" id="servicios">
    <div class="container">
        <div style="text-align: center; max-width: 700px; margin: 0 auto 3.5rem;">
            <span class="badge" style="margin-bottom: 0.5rem;">Servicios Integrales</span>
            <h2 style="font-size: 2.25rem; font-weight: 700; letter-spacing: -0.02em;">Desde la idea hasta la entrega de llave</h2>
            <p style="color: var(--muted-foreground); margin-top: 0.5rem;">
                Control riguroso de cómputo, presupuesto, dirección de obra y estándares técnicos.
            </p>
        </div>

        <div class="grid grid-cols-3">
            <div class="card">
                <div class="stat-label">01 / PROYECTO</div>
                <h3 style="font-size: 1.15rem; font-weight: 700; margin-bottom: 0.5rem;">Arquitectura &amp; Diseño</h3>
                <p style="color: var(--muted-foreground); font-size: 0.85rem; line-height: 1.6;">
                    Planos ejecutivos, modelado 3D BIM, renders fotorealistas y resolución espacial personalizada para cada cliente.
                </p>
            </div>
            <div class="card">
                <div class="stat-label">02 / EJECUCIÓN</div>
                <h3 style="font-size: 1.15rem; font-weight: 700; margin-bottom: 0.5rem;">Construcción Llave en Mano</h3>
                <p style="color: var(--muted-foreground); font-size: 0.85rem; line-height: 1.6;">
                    Gestión integral de mano de obra especializada, acopio de materiales de primera calidad y cumplimiento estricto de plazos.
                </p>
            </div>
            <div class="card">
                <div class="stat-label">03 / SUPERVISIÓN</div>
                <h3 style="font-size: 1.15rem; font-weight: 700; margin-bottom: 0.5rem;">Dirección Técnica</h3>
                <p style="color: var(--muted-foreground); font-size: 0.85rem; line-height: 1.6;">
                    Auditoría continua de estructuras, instalaciones sanitarias/eléctricas y control de calidad en cada fase del cronograma.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- Sección Contacto -->
<section class="section" id="contacto" style="border-top: 1px solid var(--border); background-color: var(--card);">
    <div class="container" style="max-width: 760px;">
        <div style="text-align: center; margin-bottom: 2.5rem;">
            <span class="badge" style="margin-bottom: 0.5rem;">Contacto Directo</span>
            <h2 style="font-size: 2.25rem; font-weight: 700; letter-spacing: -0.02em;">Comencemos tu próxima obra</h2>
            <p style="color: var(--muted-foreground); margin-top: 0.5rem; font-size: 0.9rem;">
                Envíanos los detalles de tu lote o proyecto. Los mensajes se guardan en tu base de datos y se notifican en el panel.
            </p>
        </div>

        <?php if ($feedbackMessage): ?>
            <div style="padding: 1rem 1.25rem; border-radius: var(--radius); margin-bottom: 1.5rem; font-size: 0.875rem; <?= $feedbackMessage['type'] === 'success' ? 'background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3);' : 'background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3);' ?>">
                <?= htmlspecialchars($feedbackMessage['text']) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="index.php#contacto" class="card" style="padding: 2rem;">
            <div class="grid grid-cols-2" style="margin-bottom: 1rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="name">Nombre y Apellido *</label>
                    <input class="form-input" type="text" id="name" name="name" required placeholder="Tu nombre">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="phone">Teléfono / WhatsApp</label>
                    <input class="form-input" type="text" id="phone" name="phone" placeholder="+54 9 11 ...">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="email">Correo Electrónico *</label>
                <input class="form-input" type="email" id="email" name="email" required placeholder="cliente@correo.com">
            </div>

            <div class="form-group">
                <label class="form-label" for="message">Detalles del Proyecto o Consulta *</label>
                <textarea class="form-textarea" id="message" name="message" rows="4" required placeholder="Ubicación, tipo de construcción, metros cuadrados estimados..."></textarea>
            </div>

            <button type="submit" name="send_contact" class="btn btn-primary btn-block" style="padding: 0.75rem 1.5rem; margin-top: 0.5rem;">
                Enviar Consulta al Estudio &rarr;
            </button>
        </form>
    </div>
</section>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
