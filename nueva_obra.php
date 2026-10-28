<?php
/**
 * Registrar Nueva Obra - G10 Obra
 * Permite registrar una obra llenando todos los campos de la tabla `obra` (excepto idObra autoincremental).
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

require_login();
$currentUser = auth_user();
$db = Database::getConnection();

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

// Iniciales para avatar de respaldo
$initials = 'U';
if (!empty($currentUser['nombre'])) {
    $parts = explode(' ', trim($currentUser['nombre']));
    $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}

$error = null;
$success = null;

// Manejo del formulario de registro
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'crear_obra') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'La sesión del formulario expiró. Por favor intenta de nuevo.';
    } else {
        $obrContrato           = trim($_POST['obrContrato'] ?? '');
        $obrNombreObra         = trim($_POST['obrNombreObra'] ?? '');
        $obrUbicacion          = trim($_POST['obrUbicacion'] ?? '');
        $obrMontoSinIva        = $_POST['obrMontoSinIva'] !== '' ? floatval($_POST['obrMontoSinIva']) : null;
        $obrIva                = $_POST['obrIva'] !== '' ? floatval($_POST['obrIva']) : null;
        $obrRetencion          = $_POST['obrRetencion'] !== '' ? floatval($_POST['obrRetencion']) : null;
        $obrStatus             = trim($_POST['obrStatus'] ?? 'A');
        $obrFechaAdjudicacion  = !empty($_POST['obrFechaAdjudicacion']) ? $_POST['obrFechaAdjudicacion'] : null;
        $obrFechaContrato      = !empty($_POST['obrFechaContrato']) ? $_POST['obrFechaContrato'] : null;
        $obrFechadeContratacion= !empty($_POST['obrFechadeContratacion']) ? $_POST['obrFechadeContratacion'] : null;
        $obrFechaInicio        = !empty($_POST['obrFechaInicio']) ? $_POST['obrFechaInicio'] : null;
        $obrEjercicioFiscal    = trim($_POST['obrEjercicioFiscal'] ?? date('Y'));

        // Procesar subida de foto de la obra
        $obrFoto = null;
        $obrFotoMiniatura = null;
        if (!empty($_FILES['obrFoto']['tmp_name']) && is_uploaded_file($_FILES['obrFoto']['tmp_name'])) {
            $obrFoto = file_get_contents($_FILES['obrFoto']['tmp_name']);
            // Guardamos la misma imagen o miniatura
            $obrFotoMiniatura = $obrFoto;
        }

        if ($obrNombreObra === '') {
            $error = 'El nombre de la obra es obligatorio.';
        } elseif (!$db) {
            $error = 'No se pudo conectar a la base de datos.';
        } else {
            try {
                $sql = "INSERT INTO obra (
                            obrContrato,
                            obrNombreObra,
                            obrUbicacion,
                            obrMontoSinIva,
                            obrIva,
                            obrRetencion,
                            obrStatus,
                            obrFechaAdjudicacion,
                            obrFechaContrato,
                            obrFechadeContratacion,
                            obrFechaInicio,
                            obrEjercicioFiscal,
                            obrFoto,
                            obrFotoMiniatura
                        ) VALUES (
                            :obrContrato,
                            :obrNombreObra,
                            :obrUbicacion,
                            :obrMontoSinIva,
                            :obrIva,
                            :obrRetencion,
                            :obrStatus,
                            :obrFechaAdjudicacion,
                            :obrFechaContrato,
                            :obrFechadeContratacion,
                            :obrFechaInicio,
                            :obrEjercicioFiscal,
                            :obrFoto,
                            :obrFotoMiniatura
                        )";

                $stmt = $db->prepare($sql);
                $stmt->bindValue(':obrContrato', $obrContrato ?: null, PDO::PARAM_STR);
                $stmt->bindValue(':obrNombreObra', $obrNombreObra, PDO::PARAM_STR);
                $stmt->bindValue(':obrUbicacion', $obrUbicacion ?: null, PDO::PARAM_STR);
                $stmt->bindValue(':obrMontoSinIva', $obrMontoSinIva, $obrMontoSinIva !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmt->bindValue(':obrIva', $obrIva, $obrIva !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmt->bindValue(':obrRetencion', $obrRetencion, $obrRetencion !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmt->bindValue(':obrStatus', $obrStatus ?: 'A', PDO::PARAM_STR);
                $stmt->bindValue(':obrFechaAdjudicacion', $obrFechaAdjudicacion, $obrFechaAdjudicacion ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmt->bindValue(':obrFechaContrato', $obrFechaContrato, $obrFechaContrato ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmt->bindValue(':obrFechadeContratacion', $obrFechadeContratacion, $obrFechadeContratacion ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmt->bindValue(':obrFechaInicio', $obrFechaInicio, $obrFechaInicio ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmt->bindValue(':obrEjercicioFiscal', $obrEjercicioFiscal ?: null, PDO::PARAM_STR);
                $stmt->bindValue(':obrFoto', $obrFoto, $obrFoto !== null ? PDO::PARAM_LOB : PDO::PARAM_NULL);
                $stmt->bindValue(':obrFotoMiniatura', $obrFotoMiniatura, $obrFotoMiniatura !== null ? PDO::PARAM_LOB : PDO::PARAM_NULL);

                $stmt->execute();
                
                $_SESSION['flash_success'] = "¡Obra '{$obrNombreObra}' registrada exitosamente!";
                header('Location: index.php');
                exit;
            } catch (Exception $e) {
                $error = 'Error al registrar la obra: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrar Nueva Obra | G10 Obra</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/auth.css?v=<?= filemtime(__DIR__ . '/assets/css/auth.css') ?>">
    <style>
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.25rem;
        }
        .form-group-full {
            grid-column: 1 / -1;
        }
        .section-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.75rem;
            margin-bottom: 1.5rem;
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }
        .section-card h3 {
            font-size: 1.1rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 0.35rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .section-card p.subtitle {
            font-size: 0.85rem;
            color: var(--muted);
            margin-bottom: 1.25rem;
        }
        .form-label {
            display: block;
            font-size: 0.82rem;
            font-weight: 600;
            color: #d4d4d8;
            margin-bottom: 0.45rem;
            letter-spacing: 0.01em;
        }
        .form-label span.req {
            color: #ffffff;
            font-weight: 800;
            margin-left: 2px;
        }
        .input-control {
            width: 100%;
            font: inherit;
            font-size: 0.92rem;
            color: var(--text);
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border-strong);
            border-radius: 10px;
            padding: 0.75rem 0.95rem;
            outline: none;
            transition: border-color 0.2s var(--ease), box-shadow 0.2s var(--ease), background 0.2s var(--ease);
        }
        .input-control:hover {
            border-color: rgba(255, 255, 255, 0.35);
        }
        .input-control:focus {
            border-color: #ffffff;
            background: rgba(255, 255, 255, 0.06);
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.12);
        }
        select.input-control {
            background: #141417;
            cursor: pointer;
        }
        .file-upload-box {
            border: 2px dashed var(--border-strong);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            background: rgba(255, 255, 255, 0.02);
            cursor: pointer;
            transition: border-color 0.2s var(--ease), background 0.2s var(--ease);
            position: relative;
        }
        .file-upload-box:hover {
            border-color: #ffffff;
            background: rgba(255, 255, 255, 0.04);
        }
        .file-upload-box input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }
        .preview-container {
            display: none;
            margin-top: 1rem;
            text-align: center;
        }
        .preview-img {
            max-width: 220px;
            max-height: 160px;
            border-radius: 10px;
            border: 1px solid var(--border-strong);
            object-fit: cover;
        }
        .calc-preview {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 1rem 1.25rem;
            margin-top: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .calc-preview .item {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
        }
        .calc-preview .item span.title {
            font-size: 0.72rem;
            text-transform: uppercase;
            color: var(--muted);
            letter-spacing: 0.04em;
        }
        .calc-preview .item span.val {
            font-size: 1.15rem;
            font-weight: 700;
            color: #ffffff;
        }
        .actions-bar {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 1rem;
            margin-top: 2rem;
            margin-bottom: 3rem;
        }
        /* Avatar con foto o iniciales coloridas */
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

        /* ---------- Responsive Media Queries ---------- */
        @media (max-width: 768px) {
            .app { padding: 1.25rem 1rem 3.5rem; }
            .section-card { padding: 1.35rem 1.15rem; }
            .form-grid { grid-template-columns: 1fr; }
            .actions-bar {
                flex-direction: column-reverse;
                gap: 0.75rem;
                align-items: stretch;
            }
            .actions-bar .btn {
                width: 100% !important;
                justify-content: center;
                padding: 0.85rem 1.2rem;
            }
        }

        @media (max-width: 560px) {
            .topbar { padding: .75rem 0 1.25rem; }
            .user-meta { display: none; }
            .topbar .btn-ghost span { display: none; }
            .topbar .btn-ghost { padding: .5rem; }
            .calc-preview {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }
            .file-upload-box {
                padding: 1.25rem 1rem;
            }
            .preview-img {
                max-width: 100%;
                height: auto;
            }
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
                <a href="index.php" style="display: flex; align-items: center; gap: 0.7rem;">
                    <div class="brand-mark" aria-hidden="true">G10</div>
                    <span>G10 Obra</span>
                </a>
            </div>

            <div class="user-chip">
                <?php if (!empty($userPhotoData)): ?>
                    <img src="<?= $userPhotoData ?>" class="avatar avatar-img" alt="Foto de <?= htmlspecialchars($currentUser['nombre']) ?>">
                <?php else: ?>
                    <div class="avatar avatar-colorful" aria-hidden="true"><?= htmlspecialchars($initials) ?></div>
                <?php endif; ?>
                <div class="user-meta">
                    <strong><?= htmlspecialchars($currentUser['nombre']) ?></strong>
                    <span><?= htmlspecialchars($currentUser['login']) ?></span>
                </div>
                <a href="index.php" class="btn btn-ghost" title="Volver al panel principal">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"/><polyline points="12 19 5 12 12 5"/></svg>
                    <span>Volver a Obras</span>
                </a>
                <a href="logout.php" class="btn btn-ghost" title="Cerrar sesión">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    <span>Salir</span>
                </a>
            </div>
        </header>

        <!-- Contenedor Principal del Formulario -->
        <main class="content">
            <div class="hero" style="margin-bottom: 2rem;">
                <a href="index.php" style="display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; color: var(--muted); margin-bottom: 0.75rem;">
                    &larr; Volver a Obras Activas
                </a>
                <h1>Registrar <span class="grad">Nueva Obra</span></h1>
                <p>Completa los campos correspondientes a la tabla <code>obra</code> para dar de alta un nuevo proyecto.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error" role="alert" style="margin-bottom: 1.5rem;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="nueva_obra.php" enctype="multipart/form-data" id="formNuevaObra">
                <input type="hidden" name="action" value="crear_obra">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

                <!-- Sección 1: Información General -->
                <section class="section-card">
                    <h3>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                        1. Datos Generales de la Obra
                    </h3>
                    <p class="subtitle">Identificación, contrato y ubicación física de la obra.</p>

                    <div class="form-grid">
                        <div class="form-group-full">
                            <label class="form-label" for="obrNombreObra">Nombre de la Obra <span class="req">*</span></label>
                            <input class="input-control" type="text" id="obrNombreObra" name="obrNombreObra" required maxlength="150" placeholder="Ej. Pavimentación y Drenaje en Col. Centro" value="<?= htmlspecialchars($_POST['obrNombreObra'] ?? '') ?>" autofocus>
                        </div>

                        <div>
                            <label class="form-label" for="obrContrato">Número de Contrato</label>
                            <input class="input-control" type="text" id="obrContrato" name="obrContrato" maxlength="50" placeholder="Ej. G10-OBR-2026-001" value="<?= htmlspecialchars($_POST['obrContrato'] ?? '') ?>">
                        </div>

                        <div>
                            <label class="form-label" for="obrEjercicioFiscal">Ejercicio Fiscal</label>
                            <input class="input-control" type="text" id="obrEjercicioFiscal" name="obrEjercicioFiscal" maxlength="50" placeholder="2026" value="<?= htmlspecialchars($_POST['obrEjercicioFiscal'] ?? date('Y')) ?>">
                        </div>

                        <div>
                            <label class="form-label" for="obrStatus">Estatus de la Obra</label>
                            <select class="input-control" id="obrStatus" name="obrStatus">
                                <option value="A" selected>A - Activa / En Proceso</option>
                                <option value="P">P - En Planeación</option>
                                <option value="S">S - Suspendida</option>
                                <option value="T">T - Terminada</option>
                                <option value="C">C - Cancelada</option>
                            </select>
                        </div>

                        <div class="form-group-full">
                            <label class="form-label" for="obrUbicacion">Ubicación / Domicilio</label>
                            <input class="input-control" type="text" id="obrUbicacion" name="obrUbicacion" maxlength="100" placeholder="Ej. Calle Principal s/n, Chilpancingo, Guerrero" value="<?= htmlspecialchars($_POST['obrUbicacion'] ?? '') ?>">
                        </div>
                    </div>
                </section>

                <!-- Sección 2: Montos y Finanzas -->
                <section class="section-card">
                    <h3>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                        2. Presupuesto y Montos Contractuales
                    </h3>
                    <p class="subtitle">Montos de adjudicación con desglose de impuestos y retenciones.</p>

                    <div class="form-grid">
                        <div>
                            <label class="form-label" for="obrMontoSinIva">Monto sin IVA ($)</label>
                            <input class="input-control" type="number" step="0.01" min="0" id="obrMontoSinIva" name="obrMontoSinIva" placeholder="0.00" value="<?= htmlspecialchars($_POST['obrMontoSinIva'] ?? '') ?>">
                        </div>

                        <div>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.45rem;">
                                <label class="form-label" for="obrIva" style="margin-bottom: 0;">IVA ($)</label>
                                <button type="button" id="btnAutoIva" style="background: none; border: none; color: #a1a1aa; font-size: 0.72rem; cursor: pointer; text-decoration: underline;">
                                    Calcular 16% auto
                                </button>
                            </div>
                            <input class="input-control" type="number" step="0.01" min="0" id="obrIva" name="obrIva" placeholder="0.00" value="<?= htmlspecialchars($_POST['obrIva'] ?? '') ?>">
                        </div>

                        <div>
                            <label class="form-label" for="obrRetencion">Retención ($)</label>
                            <input class="input-control" type="number" step="0.01" min="0" id="obrRetencion" name="obrRetencion" placeholder="0.00" value="<?= htmlspecialchars($_POST['obrRetencion'] ?? '') ?>">
                        </div>
                    </div>

                    <!-- Resumen en vivo de monto total -->
                    <div class="calc-preview">
                        <div class="item">
                            <span class="title">Subtotal Sin IVA</span>
                            <span class="val" id="lblSubtotal">$0.00</span>
                        </div>
                        <div class="item">
                            <span class="title">+ IVA (16%)</span>
                            <span class="val" id="lblIva">$0.00</span>
                        </div>
                        <div class="item">
                            <span class="title">- Retención</span>
                            <span class="val" id="lblRetencion">$0.00</span>
                        </div>
                        <div class="item" style="border-left: 1px solid var(--border); padding-left: 1rem;">
                            <span class="title" style="color: #ffffff; font-weight: 700;">Monto Neto Estimado</span>
                            <span class="val" id="lblTotal" style="font-size: 1.35rem; color: #ffffff;">$0.00</span>
                        </div>
                    </div>
                </section>

                <!-- Sección 3: Cronograma y Fechas -->
                <section class="section-card">
                    <h3>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        3. Fechas y Cronograma
                    </h3>
                    <p class="subtitle">Hitos temporales y plazos estipulados de contratación y ejecución.</p>

                    <div class="form-grid">
                        <div>
                            <label class="form-label" for="obrFechaAdjudicacion">Fecha de Adjudicación</label>
                            <input class="input-control" type="date" id="obrFechaAdjudicacion" name="obrFechaAdjudicacion" value="<?= htmlspecialchars($_POST['obrFechaAdjudicacion'] ?? '') ?>">
                        </div>

                        <div>
                            <label class="form-label" for="obrFechaContrato">Fecha de Contrato</label>
                            <input class="input-control" type="date" id="obrFechaContrato" name="obrFechaContrato" value="<?= htmlspecialchars($_POST['obrFechaContrato'] ?? '') ?>">
                        </div>

                        <div>
                            <label class="form-label" for="obrFechadeContratacion">Fecha de Contratación</label>
                            <input class="input-control" type="date" id="obrFechadeContratacion" name="obrFechadeContratacion" value="<?= htmlspecialchars($_POST['obrFechadeContratacion'] ?? '') ?>">
                        </div>

                        <div>
                            <label class="form-label" for="obrFechaInicio">Fecha de Inicio de Obra</label>
                            <input class="input-control" type="date" id="obrFechaInicio" name="obrFechaInicio" value="<?= htmlspecialchars($_POST['obrFechaInicio'] ?? '') ?>">
                        </div>
                    </div>
                </section>

                <!-- Sección 4: Fotografía de la Obra -->
                <section class="section-card">
                    <h3>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                        4. Fotografía de la Obra
                    </h3>
                    <p class="subtitle">Adjunta una imagen del proyecto (se almacenará en <code>obrFoto</code> y <code>obrFotoMiniatura</code>).</p>

                    <div class="file-upload-box" id="dropArea">
                        <input type="file" id="obrFoto" name="obrFoto" accept="image/jpeg,image/png,image/webp">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--muted); margin-bottom: 0.5rem;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        <p style="font-size: 0.9rem; font-weight: 600; color: #ffffff;">Haz clic o arrastra una imagen aquí</p>
                        <p style="font-size: 0.75rem; color: var(--muted); margin-top: 0.25rem;">Formatos recomendados: JPG, PNG o WEBP (máx. 10MB)</p>
                    </div>

                    <div class="preview-container" id="previewContainer">
                        <p style="font-size: 0.78rem; color: var(--muted); margin-bottom: 0.4rem;">Vista previa de la fotografía:</p>
                        <img id="imgPreview" class="preview-img" alt="Vista previa">
                    </div>
                </section>

                <!-- Barra de Acciones Final -->
                <div class="actions-bar">
                    <a href="index.php" class="btn btn-ghost" style="padding: 0.85rem 1.4rem;">
                        Cancelar
                    </a>
                    <button type="submit" class="btn btn-primary" id="btnGuardar" style="width: auto; padding: 0.85rem 2rem;">
                        <span class="spinner" aria-hidden="true"></span>
                        <span class="label">Guardar y Registrar Obra &rarr;</span>
                    </button>
                </div>
            </form>
        </main>
    </div>

    <script>
        // Cálculo automático y dinámico de totales
        const inputMonto = document.getElementById('obrMontoSinIva');
        const inputIva   = document.getElementById('obrIva');
        const inputRet   = document.getElementById('obrRetencion');
        const btnAutoIva = document.getElementById('btnAutoIva');

        const lblSubtotal  = document.getElementById('lblSubtotal');
        const lblIva       = document.getElementById('lblIva');
        const lblRetencion = document.getElementById('lblRetencion');
        const lblTotal     = document.getElementById('lblTotal');

        function formatMoney(amount) {
            return '$' + Number(amount || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function updateTotals() {
            const monto = parseFloat(inputMonto.value) || 0;
            const iva   = parseFloat(inputIva.value) || 0;
            const ret   = parseFloat(inputRet.value) || 0;
            const total = monto + iva - ret;

            lblSubtotal.textContent  = formatMoney(monto);
            lblIva.textContent       = formatMoney(iva);
            lblRetencion.textContent = formatMoney(ret);
            lblTotal.textContent     = formatMoney(total);
        }

        inputMonto.addEventListener('input', updateTotals);
        inputIva.addEventListener('input', updateTotals);
        inputRet.addEventListener('input', updateTotals);

        btnAutoIva.addEventListener('click', () => {
            const monto = parseFloat(inputMonto.value) || 0;
            const iva = (monto * 0.16).toFixed(2);
            inputIva.value = iva;
            updateTotals();
        });

        // Vista previa de la foto seleccionada
        const fileInput = document.getElementById('obrFoto');
        const previewContainer = document.getElementById('previewContainer');
        const imgPreview = document.getElementById('imgPreview');

        fileInput.addEventListener('change', function() {
            const file = this.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    imgPreview.src = e.target.result;
                    previewContainer.style.display = 'block';
                };
                reader.readAsDataURL(file);
            } else {
                previewContainer.style.display = 'none';
            }
        });

        // Estado de carga al enviar
        const form = document.getElementById('formNuevaObra');
        const btnGuardar = document.getElementById('btnGuardar');
        form.addEventListener('submit', () => {
            if (form.checkValidity()) {
                btnGuardar.classList.add('loading');
                btnGuardar.style.opacity = '0.85';
            }
        });

        updateTotals();
    </script>
</body>
</html>
