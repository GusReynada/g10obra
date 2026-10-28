<?php
/**
 * Detalle de Obra / Partidas - G10 Obra
 * Muestra la información de una obra y gestiona sus partidas (tabla `obrapartidas`).
 * Permisos controlados por el módulo con ModClave = 'PAS8' (usuariosmodulos).
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

require_login();
$currentUser = auth_user();
$db = Database::getConnection();

const MODULO_PARTIDAS = 'PAS8';

$idObra = (int)($_GET['id'] ?? $_POST['idObra'] ?? 0);
if ($idObra <= 0 || !$db) {
    header('Location: index.php');
    exit;
}

// ---------------------------------------------------------------------------
// Obra
// ---------------------------------------------------------------------------
$stObra = $db->prepare("SELECT * FROM obra WHERE idObra = ? LIMIT 1");
$stObra->execute([$idObra]);
$obra = $stObra->fetch(PDO::FETCH_ASSOC);
if (!$obra) {
    $_SESSION['flash_success'] = 'La obra solicitada no existe.';
    header('Location: index.php');
    exit;
}

// ---------------------------------------------------------------------------
// Servir fotografía en streaming directamente desde la BD (evita memory leaks)
// ---------------------------------------------------------------------------
if (isset($_GET['ver_foto'])) {
    $idFotoVer = (int)$_GET['ver_foto'];
    $esMiniatura = isset($_GET['thumb']) && $_GET['thumb'] === '1';
    if ($idFotoVer > 0) {
        try {
            if ($esMiniatura) {
                $stF = $db->prepare(
                    "SELECT COALESCE(f.pdfFotoMiniatura, f.pdfFoto) AS foto
                       FROM obrapartidasfotos f
                       JOIN obrapartidas p ON p.idPartidaObra = f.idPartidaObra
                      WHERE f.idPartidaFoto = ? AND p.idObra = ?
                      LIMIT 1"
                );
            } else {
                $stF = $db->prepare(
                    "SELECT f.pdfFoto AS foto
                       FROM obrapartidasfotos f
                       JOIN obrapartidas p ON p.idPartidaObra = f.idPartidaObra
                      WHERE f.idPartidaFoto = ? AND p.idObra = ?
                      LIMIT 1"
                );
            }
            $stF->execute([$idFotoVer, $idObra]);
            $blob = $stF->fetchColumn();
            if ($blob) {
                header('Content-Type: image/jpeg');
                header('Cache-Control: public, max-age=604800, immutable');
                header('Content-Length: ' . strlen($blob));
                echo $blob;
                exit;
            }
        } catch (Exception $e) {}
    }
    http_response_code(404);
    exit;
}

// ---------------------------------------------------------------------------
// Permisos del módulo PARTIDAS (PAS8)
// ---------------------------------------------------------------------------
$perm = ['consultar' => false, 'agregar' => false, 'modificar' => false, 'eliminar' => false];
try {
    $stPerm = $db->prepare(
        "SELECT um.UsuModConsultar, um.UsuModAgregar, um.UsuModModificar, um.UsuModEliminar
           FROM usuariosmodulos um
           JOIN usuariosmodulosmodulos m ON m.idModulo = um.idModulo
          WHERE um.idUsuario = ? AND m.ModClave = ?
          LIMIT 1"
    );
    $stPerm->execute([$currentUser['id'], MODULO_PARTIDAS]);
    if ($p = $stPerm->fetch(PDO::FETCH_ASSOC)) {
        $perm = [
            'consultar' => (int)$p['UsuModConsultar'] === 1,
            'agregar'   => (int)$p['UsuModAgregar'] === 1,
            'modificar' => (int)$p['UsuModModificar'] === 1,
            'eliminar'  => (int)$p['UsuModEliminar'] === 1,
        ];
    }
} catch (Exception $e) {}

// Si tiene cualquier permiso de escritura, se asume que puede consultar
$puedeVer = $perm['consultar'] || $perm['agregar'] || $perm['modificar'] || $perm['eliminar'];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function valid_date(?string $d): bool {
    if (!$d) return false;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}
function fmt_date(?string $d): string {
    return $d ? date('d/m/Y', strtotime($d)) : 'S/F';
}
function fmt_date_time(?string $d): string {
    return $d ? date('d/m/Y H:i', strtotime($d)) . ' hrs' : 'S/F';
}

$obraInicio    = !empty($obra['obrFechaInicio']) ? $obra['obrFechaInicio'] : null;
$formError     = null;
$formOld       = null; // datos a re-mostrar en el modal tras error
$seccionActiva = (($_GET['seccion'] ?? $_POST['seccion'] ?? 'partidas') === 'fotos') ? 'fotos' : 'partidas';

// ---------------------------------------------------------------------------
// Acciones POST
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $formError = 'La sesión del formulario expiró. Por favor intenta de nuevo.';
    } elseif ($action === 'guardar_partida') {
        $idPartida   = (int)($_POST['idPartidaObra'] ?? 0);
        $esEdicion   = $idPartida > 0;
        $clave       = trim($_POST['pdoClave'] ?? '');
        $descripcion = trim($_POST['pdoDescripcion'] ?? '');
        $fIni        = trim($_POST['pdoFechaInicio'] ?? '') ?: null;
        $fFin        = trim($_POST['pdoFechaFin'] ?? '') ?: null;
        $formOld     = compact('idPartida', 'clave', 'descripcion', 'fIni', 'fFin');

        if ($esEdicion && !$perm['modificar']) {
            $formError = 'No tienes permiso para modificar partidas.';
        } elseif (!$esEdicion && !$perm['agregar']) {
            $formError = 'No tienes permiso para crear partidas.';
        } elseif ($clave === '' || $descripcion === '') {
            $formError = 'La clave y la descripción son obligatorias.';
        } elseif (mb_strlen($clave) > 50 || mb_strlen($descripcion) > 200) {
            $formError = 'La clave admite 50 caracteres y la descripción 200.';
        } elseif (($fIni && !valid_date($fIni)) || ($fFin && !valid_date($fFin))) {
            $formError = 'Formato de fecha inválido.';
        } elseif ($fIni && $obraInicio && $fIni < $obraInicio) {
            $formError = 'La fecha de inicio no puede ser anterior al inicio de la obra (' . fmt_date($obraInicio) . ').';
        } elseif ($fFin && $obraInicio && $fFin < $obraInicio) {
            $formError = 'La fecha de fin no puede ser anterior al inicio de la obra (' . fmt_date($obraInicio) . ').';
        } elseif ($fIni && $fFin && $fFin < $fIni) {
            $formError = 'La fecha de fin no puede ser anterior a la fecha de inicio.';
        } else {
            // Clave única dentro de la obra
            $stDup = $db->prepare("SELECT COUNT(*) FROM obrapartidas WHERE idObra = ? AND pdoClave = ? AND idPartidaObra <> ?");
            $stDup->execute([$idObra, $clave, $idPartida]);
            if ((int)$stDup->fetchColumn() > 0) {
                $formError = "Ya existe una partida con la clave '{$clave}' en esta obra.";
            } else {
                try {
                    if ($esEdicion) {
                        $st = $db->prepare("UPDATE obrapartidas
                                               SET pdoClave = ?, pdoDescripcion = ?, pdoFechaInicio = ?, pdoFechaFin = ?
                                             WHERE idPartidaObra = ? AND idObra = ?");
                        $st->execute([$clave, $descripcion, $fIni, $fFin, $idPartida, $idObra]);
                        $_SESSION['flash_success'] = "Partida '{$clave}' actualizada correctamente.";
                    } else {
                        $st = $db->prepare("INSERT INTO obrapartidas (idObra, pdoClave, pdoDescripcion, pdoFechaInicio, pdoFechaFin)
                                            VALUES (?, ?, ?, ?, ?)");
                        $st->execute([$idObra, $clave, $descripcion, $fIni, $fFin]);
                        $_SESSION['flash_success'] = "Partida '{$clave}' creada exitosamente.";
                    }
                    header('Location: obra.php?id=' . $idObra);
                    exit;
                } catch (Exception $e) {
                    $formError = 'Error al guardar la partida: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'eliminar_partida') {
        $idPartida = (int)($_POST['idPartidaObra'] ?? 0);
        if (!$perm['eliminar']) {
            $_SESSION['flash_error'] = 'No tienes permiso para eliminar partidas.';
        } else {
            try {
                $db->beginTransaction();
                // Verificar pertenencia a la obra
                $stChk = $db->prepare("SELECT pdoClave FROM obrapartidas WHERE idPartidaObra = ? AND idObra = ?");
                $stChk->execute([$idPartida, $idObra]);
                $claveDel = $stChk->fetchColumn();
                if ($claveDel !== false) {
                    $db->prepare("DELETE FROM obrapartidasinsumos WHERE idPartidaObra = ?")->execute([$idPartida]);
                    $db->prepare("DELETE FROM obrapartidas WHERE idPartidaObra = ? AND idObra = ?")->execute([$idPartida, $idObra]);
                    $_SESSION['flash_success'] = "Partida '{$claveDel}' y sus insumos fueron eliminados.";
                }
                $db->commit();
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $_SESSION['flash_error'] = 'Error al eliminar la partida: ' . $e->getMessage();
            }
        }
        header('Location: obra.php?id=' . $idObra);
        exit;
    } elseif ($action === 'eliminar_foto') {
        if (!$perm['eliminar']) {
            $_SESSION['flash_error'] = 'No tienes permiso para eliminar fotografías.';
        } else {
            $idPartidaFoto = (int)($_POST['idPartidaFoto'] ?? 0);
            try {
                $stDelFoto = $db->prepare(
                    "DELETE f FROM obrapartidasfotos f
                       JOIN obrapartidas p ON p.idPartidaObra = f.idPartidaObra
                      WHERE f.idPartidaFoto = ? AND p.idObra = ?"
                );
                $stDelFoto->execute([$idPartidaFoto, $idObra]);
                $_SESSION['flash_success'] = 'Fotografía eliminada correctamente.';
            } catch (Exception $e) {
                $_SESSION['flash_error'] = 'Error al eliminar la fotografía: ' . $e->getMessage();
            }
        }
        header('Location: obra.php?id=' . $idObra . '&seccion=fotos');
        exit;
    }
}

// ---------------------------------------------------------------------------
// Partidas de la obra (con conteo de insumos)
// ---------------------------------------------------------------------------
$partidas = [];
if ($puedeVer) {
    $stPart = $db->prepare(
        "SELECT p.*, (SELECT COUNT(*) FROM obrapartidasinsumos i WHERE i.idPartidaObra = p.idPartidaObra) AS totalInsumos
           FROM obrapartidas p
          WHERE p.idObra = ?
          ORDER BY p.pdoClave ASC, p.idPartidaObra ASC"
    );
    $stPart->execute([$idObra]);
    $partidas = $stPart->fetchAll(PDO::FETCH_ASSOC);
}

// ---------------------------------------------------------------------------
// Todas las fotografías de la obra (juntando todas las partidas) y tipos
// ---------------------------------------------------------------------------
$fotosObra = [];
$catalogoTiposFotos = [];
if ($puedeVer) {
    try {
        $stFotosObra = $db->prepare(
            "SELECT f.idPartidaFoto, f.idPartidaObra, f.pdfFecha, f.pdfTipo, f.pdfDescripcion, f.usuLogin, f.pdfFechaFoto,
                    t.pdftDescripcion,
                    p.pdoClave, p.pdoDescripcion,
                    LENGTH(f.pdfFoto) AS fotoSize,
                    LENGTH(COALESCE(f.pdfFotoMiniatura, f.pdfFoto)) AS thumbSize
               FROM obrapartidasfotos f
               JOIN obrapartidas p ON p.idPartidaObra = f.idPartidaObra
          LEFT JOIN obrapartidasfotostipo t ON t.pdfTipo = f.pdfTipo
              WHERE p.idObra = ?
              ORDER BY COALESCE(f.pdfFechaFoto, f.pdfFecha) DESC, f.idPartidaFoto DESC"
        );
        $stFotosObra->execute([$idObra]);
        $fotosObra = $stFotosObra->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $fotosObra = [];
    }

    try {
        $catalogoTiposFotos = $db->query(
            "SELECT pdfTipo, pdftDescripcion FROM obrapartidasfotostipo ORDER BY pdfTipo ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $catalogoTiposFotos = [];
    }
}
$totalFotosObra = count($fotosObra);

// Flash
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError   = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Foto de perfil e iniciales
$userPhotoData = null;
try {
    $stFoto = $db->prepare("SELECT UsuFoto FROM usuarios WHERE idUsuario = ? LIMIT 1");
    $stFoto->execute([$currentUser['id']]);
    $blobFoto = $stFoto->fetchColumn();
    if ($blobFoto) {
        $mime = 'image/jpeg';
        if (str_starts_with($blobFoto, "\x89PNG")) $mime = 'image/png';
        elseif (str_starts_with($blobFoto, "GIF8")) $mime = 'image/gif';
        elseif (str_starts_with($blobFoto, "RIFF") && substr($blobFoto, 8, 4) === "WEBP") $mime = 'image/webp';
        $userPhotoData = 'data:' . $mime . ';base64,' . base64_encode($blobFoto);
    }
} catch (Exception $e) {}

$initials = 'U';
if (!empty($currentUser['nombre'])) {
    $parts = explode(' ', trim($currentUser['nombre']));
    $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}

// Foto de la obra
$obraFotoData = null;
$blobObra = $obra['obrFoto'] ?: $obra['obrFotoMiniatura'];
if (!empty($blobObra)) {
    $mime = 'image/jpeg';
    if (str_starts_with($blobObra, "\x89PNG")) $mime = 'image/png';
    elseif (str_starts_with($blobObra, "GIF8")) $mime = 'image/gif';
    elseif (str_starts_with($blobObra, "RIFF") && substr($blobObra, 8, 4) === "WEBP") $mime = 'image/webp';
    $obraFotoData = 'data:' . $mime . ';base64,' . base64_encode($blobObra);
}

$statusMap = [
    'A' => 'Activa', 'P' => 'En Planeación', 'S' => 'Suspendida', 'T' => 'Terminada', 'C' => 'Cancelada',
];
$statusLabel = $statusMap[$obra['obrStatus'] ?? ''] ?? 'Activa';
$montoTotal  = ($obra['obrMontoSinIva'] ?? 0) + ($obra['obrIva'] ?? 0) - ($obra['obrRetencion'] ?? 0);
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($obra['obrNombreObra'] ?: 'Obra') ?> · Partidas | G10 Obra</title>
    <meta name="description" content="Partidas de la obra <?= htmlspecialchars($obra['obrNombreObra'] ?? '') ?> en G10 Obra.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/auth.css?v=<?= filemtime(__DIR__ . '/assets/css/auth.css') ?>">
    <style>
        .avatar-img { object-fit: cover; border: 2px solid rgba(255,255,255,.25); box-shadow: 0 4px 12px rgba(0,0,0,.5); }
        .avatar.avatar-colorful {
            background: linear-gradient(135deg, #ff007f 0%, #7928ca 30%, #0070f3 65%, #00dfd8 100%);
            color: #fff; font-weight: 800; text-shadow: 0 1px 3px rgba(0,0,0,.5);
            box-shadow: 0 0 20px rgba(121,40,202,.45), 0 4px 10px rgba(0,0,0,.4);
            border: 1px solid rgba(255,255,255,.35);
        }
        .back-link { display: inline-flex; align-items: center; gap: .4rem; font-size: .85rem; color: var(--muted); margin-bottom: 1rem; transition: color .2s var(--ease); }
        .back-link:hover { color: #fff; }

        /* ---------- Encabezado de la obra ---------- */
        .obra-hero {
            display: grid; grid-template-columns: 260px 1fr; gap: 1.75rem;
            background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
            padding: 1.5rem; backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
            margin-bottom: 2.25rem; animation: rise .5s var(--ease) both;
        }
        .obra-hero-img { width: 100%; height: 190px; border-radius: 12px; overflow: hidden; background: #18181b; display: grid; place-items: center; color: var(--subtle); border: 1px solid var(--border); }
        .obra-hero-img img { width: 100%; height: 100%; object-fit: cover; }
        .obra-hero-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap; }
        .obra-hero h1 { font-size: clamp(1.5rem, 2.6vw, 2.1rem); line-height: 1.2; margin: .55rem 0 .5rem; }
        .tag-row { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
        .tag { font-size: .72rem; font-weight: 700; color: var(--muted); background: rgba(255,255,255,.05); padding: .25rem .6rem; border-radius: 6px; border: 1px solid var(--border); letter-spacing: .03em; }
        .pill { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: .25rem .7rem; border-radius: 999px; background: rgba(255,255,255,.12); color: #fff; border: 1px solid rgba(255,255,255,.25); display: inline-flex; align-items: center; gap: .4rem; }
        .pill::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: #4ade80; box-shadow: 0 0 8px #4ade80; }
        .obra-loc { display: flex; align-items: center; gap: .4rem; font-size: .88rem; color: var(--muted); }
        .hero-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: .75rem; margin-top: 1.25rem; padding-top: 1.1rem; border-top: 1px solid var(--border); }
        .hero-stats .lbl { display: block; font-size: .68rem; text-transform: uppercase; color: var(--subtle); letter-spacing: .05em; margin-bottom: .2rem; }
        .hero-stats .val { font-weight: 700; color: #fff; font-size: 1rem; }

        /* ---------- Botón Blanco FOTOS DE LA OBRA ---------- */
        .btn-hero-white {
            display: inline-flex; align-items: center; gap: .55rem;
            font-family: 'Outfit', 'Inter', sans-serif; font-size: .86rem; font-weight: 700; letter-spacing: .03em;
            padding: .62rem 1.25rem; border-radius: 11px;
            background: #ffffff; color: #09090b; border: 1px solid #ffffff;
            cursor: pointer; text-decoration: none;
            box-shadow: 0 4px 18px rgba(255, 255, 255, .22), 0 2px 6px rgba(0, 0, 0, .4);
            transition: all .22s var(--ease);
        }
        .btn-hero-white:hover {
            background: #f4f4f5; color: #000;
            transform: translateY(-1px);
            box-shadow: 0 8px 24px rgba(255, 255, 255, .35), 0 3px 8px rgba(0, 0, 0, .5);
        }
        .btn-hero-white svg { color: #09090b; transition: transform .2s var(--ease); }
        .btn-hero-white:hover svg { transform: scale(1.1); }

        /* ---------- Sección Fotografías de la Obra y Botón FILTRO ---------- */
        .photos-top-bar {
            display: flex; align-items: center; justify-content: space-between; gap: .85rem; margin-bottom: 2rem; flex-wrap: wrap;
        }
        .filtro-dropdown-wrap {
            position: relative; display: inline-block;
        }
        .btn-filtro-fotos {
            display: inline-flex; align-items: center; gap: .55rem;
            font-family: 'Outfit', 'Inter', sans-serif; font-size: .86rem; font-weight: 700; letter-spacing: .03em;
            padding: .62rem 1.15rem; border-radius: 11px;
            background: rgba(255, 255, 255, .08); color: #fff; border: 1px solid var(--border-strong);
            cursor: pointer; transition: all .2s var(--ease);
            box-shadow: 0 4px 14px rgba(0, 0, 0, .35);
        }
        .btn-filtro-fotos:hover,
        .btn-filtro-fotos[aria-expanded="true"] {
            background: rgba(255, 255, 255, .15); border-color: rgba(255, 255, 255, .45);
            transform: translateY(-1px);
        }
        .filtro-active-tag {
            font-size: .72rem; font-weight: 700; padding: .15rem .55rem;
            border-radius: 999px; background: #fff; color: #09090b;
            letter-spacing: .02em;
        }
        .filtro-chevron {
            color: var(--muted); transition: transform .2s var(--ease);
        }
        .btn-filtro-fotos[aria-expanded="true"] .filtro-chevron {
            transform: rotate(180deg); color: #fff;
        }
        .filtro-dropdown-menu {
            position: absolute; right: 0; top: calc(100% + 8px); z-index: 60;
            min-width: 240px; background: #141418; border: 1px solid var(--border-strong);
            border-radius: 14px; padding: .45rem;
            box-shadow: 0 22px 48px -10px rgba(0, 0, 0, .92);
            opacity: 0; transform: translateY(-8px); pointer-events: none;
            transition: opacity .18s var(--ease), transform .18s var(--ease);
        }
        .filtro-dropdown-menu.open {
            opacity: 1; transform: none; pointer-events: auto;
        }
        .filtro-menu-header {
            font-size: .68rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .06em; color: var(--subtle);
            padding: .45rem .75rem .35rem; border-bottom: 1px solid var(--border);
            margin-bottom: .3rem;
        }
        .filtro-menu-item {
            width: 100%; display: flex; align-items: center; gap: .65rem;
            padding: .6rem .75rem; font: inherit; font-size: .85rem; font-weight: 600;
            color: #d4d4d8; background: transparent; border: none; border-radius: 9px;
            cursor: pointer; text-align: left; transition: all .15s var(--ease);
        }
        .filtro-menu-item:hover {
            background: rgba(255, 255, 255, .08); color: #fff;
        }
        .filtro-menu-item.active {
            background: rgba(255, 255, 255, .14); color: #fff; font-weight: 700;
        }
        .filtro-item-dot {
            width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
            background: #fff;
        }
        .filtro-item-dot.dot-all { background: #fff; box-shadow: 0 0 6px rgba(255,255,255,.6); }
        .filtro-item-dot.dot-tipo-1 { background: #60a5fa; box-shadow: 0 0 6px rgba(96,165,250,.6); }
        .filtro-item-dot.dot-tipo-2 { background: #34d399; box-shadow: 0 0 6px rgba(52,211,153,.6); }
        .filtro-item-dot.dot-tipo-3 { background: #c084fc; box-shadow: 0 0 6px rgba(192,132,252,.6); }
        .filtro-item-lbl { flex: 1; }
        .filtro-item-count {
            font-size: .72rem; font-weight: 700; padding: .1rem .48rem;
            border-radius: 999px; background: rgba(255, 255, 255, .1); color: var(--muted);
        }
        .filtro-menu-item.active .filtro-item-count {
            background: #fff; color: #000;
        }

        .filter-pills { display: flex; gap: .45rem; flex-wrap: wrap; margin-bottom: 1.35rem; }
        .filter-pill {
            padding: .4rem .9rem; font-size: .8rem; font-weight: 600;
            border-radius: 999px; border: 1px solid var(--border);
            background: rgba(255,255,255,.04); color: var(--muted);
            cursor: pointer; transition: all .2s var(--ease);
        }
        .filter-pill:hover { color: #fff; background: rgba(255,255,255,.08); }
        .filter-pill.active {
            background: #fff; color: #000; font-weight: 700;
            border-color: #fff; box-shadow: 0 4px 12px rgba(255,255,255,.2);
        }

        .fotos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
            gap: 1.35rem; animation: rise .45s var(--ease) both;
        }
        .foto-card {
            background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
            overflow: hidden; display: flex; flex-direction: column;
            backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
            transition: all .25s var(--ease); box-shadow: 0 12px 30px -10px rgba(0,0,0,.5);
        }
        .foto-card:hover {
            transform: translateY(-4px); border-color: var(--border-strong);
            box-shadow: 0 20px 40px -10px rgba(0,0,0,.75);
        }
        .foto-thumb-wrap {
            position: relative; width: 100%; aspect-ratio: 4 / 3;
            background: #09090b; overflow: hidden; cursor: pointer;
        }
        .foto-thumb-img {
            width: 100%; height: 100%; object-fit: cover;
            transition: transform .35s var(--ease); display: block;
        }
        .foto-card:hover .foto-thumb-img { transform: scale(1.05); }
        .foto-thumb-overlay {
            position: absolute; inset: 0; background: rgba(0,0,0,.45);
            opacity: 0; display: grid; place-items: center; color: #fff;
            transition: opacity .2s var(--ease); backdrop-filter: blur(2px);
        }
        .foto-card:hover .foto-thumb-overlay { opacity: 1; }
        .btn-zoom-circle {
            width: 44px; height: 44px; border-radius: 50%;
            background: rgba(255,255,255,.9); color: #000;
            display: grid; place-items: center;
            box-shadow: 0 8px 20px rgba(0,0,0,.5);
            transform: scale(.85); transition: transform .2s var(--ease);
        }
        .foto-card:hover .btn-zoom-circle { transform: scale(1); }
        .foto-body {
            padding: 1rem 1.15rem; display: flex; flex-direction: column;
            gap: .75rem; flex: 1;
        }
        .foto-body-top { display: flex; justify-content: space-between; align-items: center; gap: .6rem; }
        .badge-tipo {
            display: inline-flex; align-items: center; gap: .35rem;
            font-size: .72rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .05em; padding: .3rem .65rem; border-radius: 8px; border: 1px solid transparent;
        }
        .badge-tipo-1 { background: rgba(59, 130, 246, .15); color: #93c5fd; border-color: rgba(59, 130, 246, .35); }
        .badge-tipo-2 { background: rgba(16, 185, 129, .15); color: #6ee7b7; border-color: rgba(16, 185, 129, .35); }
        .badge-tipo-3 { background: rgba(168, 85, 247, .15); color: #d8b4fe; border-color: rgba(168, 85, 247, .35); }
        .foto-partida-tag {
            display: inline-flex; align-items: center; gap: .4rem;
            font-size: .76rem; color: #d4d4d8;
            background: rgba(255,255,255,.04); border: 1px solid var(--border);
            padding: .32rem .65rem; border-radius: 8px;
            text-decoration: none; transition: all .2s var(--ease);
            max-width: 100%; overflow: hidden;
        }
        .foto-partida-tag:hover {
            background: rgba(255,255,255,.1); color: #fff; border-color: var(--border-strong);
        }
        .foto-partida-tag strong {
            font-family: 'Outfit', sans-serif; color: #fff; font-weight: 700; letter-spacing: .03em; flex-shrink: 0;
        }
        .foto-partida-tag span {
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--muted);
        }
        .foto-date { display: flex; align-items: center; gap: .4rem; font-size: .78rem; color: var(--muted); }
        .foto-footer {
            display: flex; justify-content: space-between; align-items: center;
            border-top: 1px solid var(--border); padding-top: .75rem; margin-top: auto;
        }
        .btn-act {
            display: inline-flex; align-items: center; gap: .45rem;
            font: inherit; font-size: .84rem; font-weight: 600; padding: .65rem 1.1rem; border-radius: 10px;
            background: rgba(255,255,255,.06); border: 1px solid var(--border); color: #e4e4e7;
            cursor: pointer; transition: all .2s var(--ease); text-decoration: none;
        }
        .btn-act:hover { background: rgba(255,255,255,.14); color: #fff; border-color: rgba(255,255,255,.3); }
        .btn-tbl {
            background: rgba(255,255,255,.05); border: 1px solid var(--border); color: var(--muted);
            border-radius: 8px; width: 32px; height: 32px; display: grid; place-items: center;
            cursor: pointer; transition: all .2s var(--ease);
        }
        .btn-tbl:hover { background: rgba(255,255,255,.14); color: #fff; border-color: rgba(255,255,255,.3); }
        .btn-tbl.danger:hover { background: rgba(239,68,68,.18); color: #fca5a5; border-color: rgba(239,68,68,.4); }
        .empty-icon-camera {
            background: linear-gradient(135deg, rgba(0,112,243,.25), rgba(121,40,202,.25), rgba(255,0,127,.25)) !important;
            box-shadow: 0 0 40px rgba(0,112,243,.3) !important;
        }
        .lightbox-modal {
            position: fixed; inset: 0; z-index: 120;
            background: rgba(0,0,0,.94); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
            display: flex; flex-direction: column;
            opacity: 0; visibility: hidden; transition: opacity .25s var(--ease), visibility .25s;
        }
        .lightbox-modal.open { opacity: 1; visibility: visible; }
        .lightbox-topbar {
            display: flex; justify-content: space-between; align-items: center;
            padding: 1rem 1.75rem; border-bottom: 1px solid var(--border);
            background: rgba(18,18,22,.6);
        }
        .lightbox-content {
            flex: 1; display: grid; place-items: center; padding: 1.5rem; overflow: hidden;
        }
        .lightbox-img {
            max-width: 90vw; max-height: 82vh; object-fit: contain; border-radius: 12px;
            box-shadow: 0 25px 60px rgba(0,0,0,.95);
        }

        /* ---------- Sección de partidas ---------- */
        .section-header-row { display: flex; justify-content: space-between; align-items: flex-end; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.35rem; }
        .search-box { position: relative; }
        .search-box input { padding-left: 2.3rem; min-width: 240px; }
        .search-box svg { position: absolute; left: .8rem; top: 50%; transform: translateY(-50%); color: var(--subtle); pointer-events: none; }

        .partidas-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: 1.25rem; }
        .partida-card {
            position: relative; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
            padding: 1.3rem 1.3rem 1.1rem; backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
            display: flex; flex-direction: column;
            text-decoration: none; color: inherit; cursor: pointer;
            transition: transform .25s var(--ease), border-color .25s var(--ease), box-shadow .25s var(--ease);
            animation: rise .45s var(--ease) both;
        }
        .partida-card::before {
            content: ''; position: absolute; inset: 0 0 auto 0; height: 2px; border-radius: var(--radius) var(--radius) 0 0;
            background: linear-gradient(90deg, #ff007f, #7928ca, #0070f3, #00dfd8); opacity: 0; transition: opacity .25s var(--ease);
        }
        .partida-card:hover { transform: translateY(-4px); border-color: rgba(255,255,255,.35); box-shadow: 0 20px 40px -15px rgba(0,0,0,.8); }
        .partida-card:hover::before { opacity: 1; }
        .partida-card:hover .card-arrow { color: #fff !important; transform: translateX(3px); }
        .card-arrow { transition: color .2s var(--ease), transform .2s var(--ease); }

        .partida-card-inner {
            display: flex; flex-direction: row; align-items: center; justify-content: space-between; gap: 1.25rem; width: 100%; height: 100%;
        }
        .partida-card-info {
            flex: 1; min-width: 0; display: flex; flex-direction: column; height: 100%;
        }
        .partida-card-circle {
            flex-shrink: 0; display: flex; align-items: center; justify-content: center;
            cursor: default; user-select: none; padding: .25rem;
        }
        .radial-progress-svg {
            display: block; filter: drop-shadow(0 0 12px rgba(34, 224, 133, 0.25)); user-select: none;
        }
        .partida-clave { font-family: 'Outfit', sans-serif; font-size: .78rem; font-weight: 800; letter-spacing: .06em; color: #fff; background: rgba(255,255,255,.08); border: 1px solid var(--border-strong); padding: .28rem .65rem; border-radius: 7px; }
        .partida-desc { font-size: 1rem; font-weight: 600; color: #f4f4f5; line-height: 1.45; margin: .85rem 0 1rem; flex: 1; }
        .partida-dates { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; padding: .8rem; border-radius: 10px; background: rgba(255,255,255,.03); border: 1px solid var(--border); font-size: .82rem; }
        .partida-dates .lbl { display: block; font-size: .66rem; text-transform: uppercase; color: var(--subtle); letter-spacing: .05em; margin-bottom: .15rem; }
        .partida-dates .val { font-weight: 600; color: #e4e4e7; }
        .partida-duration { font-size: .74rem; color: var(--muted); margin-top: .55rem; }
        .partida-actions { display: flex; gap: .45rem; margin-top: 1rem; padding-top: .9rem; border-top: 1px solid var(--border); }
        .act-btn {
            flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: .35rem;
            font: inherit; font-size: .78rem; font-weight: 600; color: #d4d4d8; cursor: pointer;
            background: rgba(255,255,255,.04); border: 1px solid var(--border); border-radius: 9px; padding: .5rem .4rem;
            transition: background .2s var(--ease), color .2s var(--ease), border-color .2s var(--ease);
        }
        .act-btn:hover { background: rgba(255,255,255,.1); color: #fff; border-color: rgba(255,255,255,.3); }
        .act-btn.danger:hover { background: rgba(239,68,68,.14); color: #fca5a5; border-color: rgba(239,68,68,.45); }
        .count-badge { font-size: .66rem; background: rgba(255,255,255,.14); padding: .05rem .4rem; border-radius: 999px; }

        .empty-partidas {
            text-align: center; padding: 3.5rem 1.5rem; border: 1px dashed var(--border-strong); border-radius: var(--radius);
            background: var(--surface); backdrop-filter: blur(14px); animation: rise .5s var(--ease) both;
        }
        .empty-icon { width: 76px; height: 76px; border-radius: 22px; margin: 0 auto 1.3rem; display: grid; place-items: center; color: #fff;
            background: linear-gradient(135deg, rgba(255,0,127,.25), rgba(121,40,202,.25), rgba(0,112,243,.25)); border: 1px solid rgba(255,255,255,.2);
            box-shadow: 0 0 40px rgba(121,40,202,.3); animation: float 4s ease-in-out infinite; }
        .empty-partidas h3 { font-size: 1.25rem; color: #fff; margin-bottom: .5rem; }
        .empty-partidas p { color: var(--muted); font-size: .9rem; max-width: 440px; margin: 0 auto 1.5rem; line-height: 1.55; }
        .no-results { display: none; text-align: center; color: var(--muted); padding: 2rem; font-size: .9rem; }

        /* ---------- Modal Responsivo ---------- */
        .modal-backdrop {
            position: fixed; inset: 0; z-index: 100;
            display: flex; justify-content: center; align-items: center;
            padding: max(1rem, env(safe-area-inset-top)) max(0.75rem, env(safe-area-inset-right)) max(1rem, env(safe-area-inset-bottom)) max(0.75rem, env(safe-area-inset-left));
            background: rgba(0,0,0,.75); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
            opacity: 0; visibility: hidden; transition: opacity .25s var(--ease), visibility .25s;
            overflow-y: auto; overflow-x: hidden;
            -webkit-overflow-scrolling: touch;
        }
        .modal-backdrop.open { opacity: 1; visibility: visible; }
        .modal {
            width: 100%; max-width: 580px;
            margin: auto;
            max-height: calc(100dvh - 2rem);
            max-height: calc(100vh - 2rem);
            overflow-y: auto;
            overscroll-behavior: contain;
            -webkit-overflow-scrolling: touch;
            background: #111114; border: 1px solid var(--border-strong); border-radius: 18px; padding: 1.75rem;
            box-shadow: 0 30px 80px -20px rgba(0,0,0,.9); transform: translateY(16px) scale(.98); transition: transform .3s var(--ease);
            position: relative;
        }
        .modal-backdrop.open .modal { transform: none; }
        .modal-sm { max-width: 420px; text-align: center; }
        .modal-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.35rem; }
        .modal-head h2 { font-size: 1.3rem; color: #fff; }
        .modal-head p { font-size: .84rem; color: var(--muted); margin-top: .25rem; }
        .modal-close { background: none; border: 1px solid var(--border); color: var(--muted); width: 34px; height: 34px; border-radius: 9px; cursor: pointer; display: grid; place-items: center; transition: all .2s var(--ease); flex-shrink: 0; }
        .modal-close:hover { color: #fff; border-color: rgba(255,255,255,.35); }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.1rem; }
        .full { grid-column: 1 / -1; }
        .form-label { display: block; font-size: .82rem; font-weight: 600; color: #d4d4d8; margin-bottom: .45rem; }
        .form-label .req { color: #fff; font-weight: 800; }
        .form-hint { font-size: .72rem; color: var(--subtle); margin-top: .35rem; }
        .input-control {
            width: 100%; font: inherit; font-size: .92rem; color: var(--text); background: rgba(255,255,255,.03);
            border: 1px solid var(--border-strong); border-radius: 10px; padding: .75rem .95rem; outline: none;
            transition: border-color .2s var(--ease), box-shadow .2s var(--ease), background .2s var(--ease);
        }
        .input-control:hover { border-color: rgba(255,255,255,.35); }
        .input-control:focus { border-color: #fff; background: rgba(255,255,255,.06); box-shadow: 0 0 0 3px rgba(255,255,255,.12); }
        .input-control.invalid { border-color: #f87171; box-shadow: 0 0 0 3px rgba(248,113,113,.15); }
        textarea.input-control { resize: vertical; min-height: 84px; }
        .char-count { float: right; font-size: .7rem; color: var(--subtle); font-weight: 500; }
        .modal-actions { display: flex; justify-content: flex-end; gap: .75rem; margin-top: 1.6rem; }
        .modal-error { display: none; margin-bottom: 1rem; }
        .modal-error.show { display: flex; }
        .danger-icon { width: 64px; height: 64px; border-radius: 50%; margin: .25rem auto 1.1rem; display: grid; place-items: center; color: #fca5a5; background: rgba(239,68,68,.12); border: 1px solid rgba(239,68,68,.35); }
        .btn-danger { background: #ef4444 !important; color: #fff !important; border-color: #ef4444 !important; }
        .btn-danger:hover { background: #dc2626 !important; }

        /* ---------- Date picker ---------- */
        .date-field { position: relative; display: flex; }
        .date-field .input-control { padding-right: 3rem; }
        .date-btn {
            position: absolute; right: 6px; top: 50%; transform: translateY(-50%); width: 36px; height: 34px;
            border-radius: 8px; border: 1px solid var(--border); background: rgba(255,255,255,.06); color: #e4e4e7;
            display: grid; place-items: center; cursor: pointer; transition: all .2s var(--ease);
        }
        .date-btn:hover, .date-btn[aria-expanded="true"] { background: #fff; color: #000; border-color: #fff; }
        .calendar {
            position: absolute; top: calc(100% + 8px); left: 0; z-index: 30; width: 292px; padding: 1rem;
            background: #18181b; border: 1px solid var(--border-strong); border-radius: 14px;
            box-shadow: 0 24px 50px -12px rgba(0,0,0,.85); opacity: 0; transform: translateY(-6px); pointer-events: none;
            transition: opacity .18s var(--ease), transform .18s var(--ease);
        }
        .calendar.align-right { left: auto; right: 0; }
        .calendar.open { opacity: 1; transform: none; pointer-events: auto; }
        .cal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: .75rem; }
        .cal-title { font-family: 'Outfit', sans-serif; font-weight: 700; color: #fff; text-transform: capitalize; font-size: .98rem; }
        .cal-nav { width: 30px; height: 30px; border-radius: 8px; border: 1px solid var(--border); background: transparent; color: #d4d4d8; cursor: pointer; display: grid; place-items: center; }
        .cal-nav:hover { background: rgba(255,255,255,.1); color: #fff; }
        .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 3px; }
        .cal-dow { font-size: .66rem; text-transform: uppercase; color: var(--subtle); text-align: center; padding: .3rem 0; font-weight: 700; }
        .cal-day {
            aspect-ratio: 1; border: none; border-radius: 8px; background: transparent; color: #e4e4e7; font: inherit; font-size: .82rem; cursor: pointer;
            transition: background .15s var(--ease), color .15s var(--ease);
        }
        .cal-day:hover:not(:disabled) { background: rgba(255,255,255,.12); }
        .cal-day.other { color: #52525b; }
        .cal-day.today { box-shadow: inset 0 0 0 1px rgba(255,255,255,.45); }
        .cal-day.in-range { background: rgba(121,40,202,.22); }
        .cal-day.selected { background: linear-gradient(135deg, #7928ca, #0070f3); color: #fff; font-weight: 700; }
        .cal-day:disabled { color: #3f3f46; cursor: not-allowed; text-decoration: line-through; }
        .cal-foot { display: flex; justify-content: space-between; margin-top: .75rem; padding-top: .65rem; border-top: 1px solid var(--border); }
        .cal-link { background: none; border: none; color: #a1a1aa; font: inherit; font-size: .78rem; cursor: pointer; font-weight: 600; }
        .cal-link:hover { color: #fff; }

        @keyframes rise { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
        @keyframes float { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }

        /* ---------- Responsive Media Queries ---------- */
        @media (max-width: 900px) {
            .app { padding: 1.25rem 1rem 3.5rem; }
            .hero-stats { grid-template-columns: repeat(2, 1fr); }
            .partidas-grid { grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); }
            .fotos-grid { grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); }
        }

        @media (max-width: 760px) {
            .obra-hero { grid-template-columns: 1fr; padding: 1.25rem 1rem; }
            .obra-hero-img { height: 200px; width: 100%; }
            .form-grid { grid-template-columns: 1fr; }
            .search-box input { min-width: 0; width: 100%; }
            .section-header-row { flex-direction: column; align-items: stretch; gap: .85rem; }
            .section-header-row > * { width: 100%; }
            .partidas-grid { grid-template-columns: 1fr; }
            .fotos-grid { grid-template-columns: 1fr; }
            .filtro-dropdown-wrap { width: 100%; }
            .btn-filtro-fotos { width: 100%; justify-content: center; }
            .filtro-dropdown-menu { left: 0; right: 0; width: 100%; }
        }

        @media (max-width: 640px) {
            .topbar { padding: .75rem 0 1.25rem; }
            .user-meta { display: none; }
            .topbar .btn-ghost span { display: none; }
            .topbar .btn-ghost { padding: .5rem; }
            .hero-stats { grid-template-columns: repeat(2, 1fr); gap: .55rem; padding-top: 1rem; }
            .hero-stats .stat-item { padding: .65rem .75rem; }
            .hero-stats .val { font-size: .95rem; }
            .obra-hero-top { flex-direction: column; align-items: stretch; gap: .75rem; }
            .obra-hero-top .btn-hero-white { width: 100%; justify-content: center; }
            .photos-top-bar .btn-hero-white { width: 100%; justify-content: center; }
            .lightbox-topbar { padding: .75rem 1rem; }
            .lightbox-content { padding: .75rem; }
            .lightbox-img { max-width: 96vw; max-height: 78vh; }

            /* Modales en teléfonos móviles */
            .modal-backdrop {
                padding: 0.5rem 0.5rem max(0.75rem, env(safe-area-inset-bottom));
                align-items: center;
            }
            .modal {
                max-height: calc(100dvh - 1rem);
                max-height: calc(100vh - 1rem);
                padding: 1.25rem 1rem;
                border-radius: 16px;
            }
            .modal-head { margin-bottom: 1rem; }
            .modal-head h2 { font-size: 1.18rem; }
            .modal-head p { font-size: .78rem; }
            .modal-actions {
                flex-direction: column-reverse;
                gap: 0.55rem;
                margin-top: 1.25rem;
            }
            .modal-actions .btn,
            .modal-actions button {
                width: 100% !important;
                justify-content: center;
                padding: 0.85rem 1.2rem !important;
            }
            .calendar {
                width: min(290px, calc(100vw - 2.5rem));
                left: 50% !important;
                right: auto !important;
                transform: translate(-50%, -6px);
            }
            .calendar.open {
                transform: translate(-50%, 0);
            }
        }

        @media (max-width: 480px) {
            .app { padding: 1rem 0.65rem 3rem; }
            .partida-card-inner { flex-direction: column; align-items: center; text-align: left; }
            .partida-card-info { width: 100%; }
            .partida-card-circle { margin-top: .75rem; }
            .partida-actions { flex-wrap: wrap; }
            .act-btn { min-width: calc(50% - .25rem); }
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
        <header class="topbar">
            <div class="logo">
                <a href="index.php" style="display: flex; align-items: center; gap: .7rem;">
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
                </div>
                <a href="logout.php" class="btn btn-ghost" title="Cerrar sesión">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    <span>Salir</span>
                </a>
            </div>
        </header>

        <main class="content">
            <?php if ($seccionActiva === 'fotos'): ?>
                <a href="obra.php?id=<?= $idObra ?>" class="back-link">&larr; Volver a <?= htmlspecialchars($obra['obrNombreObra'] ?: 'la Obra') ?></a>
            <?php else: ?>
                <a href="index.php#obras-activas" class="back-link">&larr; Volver a Obras Activas</a>
            <?php endif; ?>

            <?php if ($seccionActiva !== 'fotos'): ?>
            <!-- Encabezado de la obra -->
            <section class="obra-hero" aria-label="Datos de la obra">
                <div class="obra-hero-img">
                    <?php if ($obraFotoData): ?>
                        <img src="<?= $obraFotoData ?>" alt="<?= htmlspecialchars($obra['obrNombreObra']) ?>">
                    <?php else: ?>
                        <svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="obra-hero-top">
                        <div class="tag-row">
                            <span class="tag"><?= htmlspecialchars($obra['obrContrato'] ?: 'S/N CONTRATO') ?></span>
                            <span class="pill"><?= htmlspecialchars($statusLabel) ?></span>
                        </div>
                        <?php if ($puedeVer): ?>
                            <a href="obra.php?id=<?= $idObra ?>&seccion=fotos" class="btn-hero-white" id="btnFotosDeLaObra" title="Ver todas las fotografías de la obra">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                                    <circle cx="12" cy="13" r="4"/>
                                </svg>
                                <span>FOTOS DE LA OBRA</span>
                            </a>
                        <?php endif; ?>
                    </div>
                    <h1><?= htmlspecialchars($obra['obrNombreObra'] ?: 'Obra sin nombre') ?></h1>
                    <?php if (!empty($obra['obrUbicacion'])): ?>
                        <p class="obra-loc">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            <?= htmlspecialchars($obra['obrUbicacion']) ?>
                        </p>
                    <?php endif; ?>
                    <div class="hero-stats">
                        <div><span class="lbl">Partidas</span><span class="val"><?= count($partidas) ?></span></div>
                        <div><span class="lbl">Fotos de la Obra</span><span class="val"><?= $totalFotosObra ?></span></div>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($flashSuccess): ?>
                <div class="alert alert-info" role="status" style="margin-bottom: 1.5rem; background: rgba(255,255,255,.08); border-color: rgba(255,255,255,.3); color: #fff;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <span><?= htmlspecialchars($flashSuccess) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($flashError): ?>
                <div class="alert alert-error" role="alert" style="margin-bottom: 1.5rem;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <span><?= htmlspecialchars($flashError) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($seccionActiva !== 'fotos'): ?>
            <!-- Partidas -->
            <section id="partidas" aria-labelledby="partidasTitle">
                <?php if (!$puedeVer): ?>
                    <div class="empty-partidas">
                        <div class="empty-icon"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div>
                        <h3 id="partidasTitle">Acceso restringido</h3>
                        <p>No tienes permisos sobre el módulo de Partidas (<?= MODULO_PARTIDAS ?>). Solicita acceso al administrador del sistema.</p>
                    </div>
                <?php else: ?>
                    <div class="section-header-row">
                        <div>
                            <div style="display: flex; align-items: center; gap: .65rem;">
                                <h2 id="partidasTitle" style="font-size: 1.4rem; font-weight: 700; color: #fff;">Partidas de la obra</h2>
                                <span class="badge" style="position: static; font-size: .72rem; padding: .2rem .6rem;"><?= count($partidas) ?></span>
                            </div>
                            <p style="color: var(--muted); font-size: .88rem; margin-top: .35rem;">Desglose de actividades, claves y cronograma.</p>
                        </div>
                        <?php if (!empty($partidas)): ?>
                            <div style="display: flex; gap: .75rem; flex-wrap: wrap;">
                                <label class="search-box">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                    <input type="search" id="buscarPartida" class="input-control" placeholder="Buscar por clave o descripción" aria-label="Buscar partida">
                                </label>
                                <?php if ($perm['agregar']): ?>
                                    <button type="button" class="btn btn-primary js-nueva-partida" id="btnNuevaPartida" style="width: auto; padding: .65rem 1.35rem; font-size: .88rem;">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                        <span>Nueva partida</span>
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($partidas)): ?>
                        <div class="empty-partidas">
                            <div class="empty-icon">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                            </div>
                            <h3>Aún no tienes ninguna partida creada.</h3>
                            <p>Las partidas te permiten dividir la obra en actividades con su clave, descripción y periodo de ejecución.</p>
                            <?php if ($perm['agregar']): ?>
                                <button type="button" class="btn btn-primary js-nueva-partida" id="btnPrimeraPartida" style="width: auto; padding: .8rem 1.7rem; font-size: .92rem;">
                                    <span>Crear primera partida &rarr;</span>
                                </button>
                            <?php else: ?>
                                <p style="font-size: .8rem; margin: 0 auto;">No tienes permiso para crear partidas.</p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="partidas-grid" id="partidasGrid">
                            <?php foreach ($partidas as $i => $p):
                                $dias = null;
                                if ($p['pdoFechaInicio'] && $p['pdoFechaFin']) {
                                    $dias = (new DateTime($p['pdoFechaInicio']))->diff(new DateTime($p['pdoFechaFin']))->days + 1;
                                }
                                $pctAvance = max(0, min(100, (int)($p['pdoAvance'] ?? 0)));
                                $circRadius = 38;
                                $circumference = 2 * M_PI * $circRadius;
                                $dashOffset = $circumference - ($circumference * $pctAvance / 100);
                            ?>
                                <a href="partida.php?id=<?= (int)$p['idPartidaObra'] ?>" class="partida-card" style="animation-delay: <?= min($i, 12) * 40 ?>ms"
                                   data-search="<?= htmlspecialchars(mb_strtolower(($p['pdoClave'] ?? '') . ' ' . ($p['pdoDescripcion'] ?? ''))) ?>"
                                   title="Ver detalles e insumos de la partida <?= htmlspecialchars($p['pdoClave'] ?? '') ?>">
                                    <div class="partida-card-inner">
                                        <div class="partida-card-info">
                                            <div style="display: flex; justify-content: space-between; align-items: center; gap: .5rem;">
                                                <span class="partida-clave"><?= htmlspecialchars($p['pdoClave'] ?: 'S/C') ?></span>
                                                <span class="tag"><?= (int)$p['totalInsumos'] ?> insumo<?= (int)$p['totalInsumos'] === 1 ? '' : 's' ?></span>
                                            </div>
                                            <p class="partida-desc"><?= htmlspecialchars($p['pdoDescripcion'] ?: 'Sin descripción') ?></p>
                                            <div class="partida-dates">
                                                <div><span class="lbl">Inicio</span><span class="val"><?= fmt_date($p['pdoFechaInicio']) ?></span></div>
                                                <div><span class="lbl">Fin</span><span class="val"><?= fmt_date($p['pdoFechaFin']) ?></span></div>
                                            </div>
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: .75rem; padding-top: .65rem; border-top: 1px solid var(--border);">
                                                <?php if ($dias): ?>
                                                    <span class="partida-duration" style="margin-top: 0;">Duración: <?= $dias ?> día<?= $dias === 1 ? '' : 's' ?></span>
                                                <?php else: ?>
                                                    <span></span>
                                                <?php endif; ?>
                                                <span class="card-arrow" style="font-size: .8rem; font-weight: 600; color: #a1a1aa; display: inline-flex; align-items: center; gap: .3rem;">
                                                    Ver insumos
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Círculo Porcentual de Avance (Estático a la derecha) -->
                                        <div class="partida-card-circle" onclick="event.stopPropagation(); event.preventDefault();" aria-label="Avance: <?= $pctAvance ?>%">
                                            <svg width="86" height="86" viewBox="0 0 100 100" class="radial-progress-svg" aria-hidden="true">
                                                <defs>
                                                    <linearGradient id="circleGrad-<?= (int)$p['idPartidaObra'] ?>" x1="0%" y1="0%" x2="100%" y2="100%">
                                                        <stop offset="0%" stop-color="#0cd2a6" />
                                                        <stop offset="100%" stop-color="#22e085" />
                                                    </linearGradient>
                                                </defs>
                                                <circle cx="50" cy="50" r="<?= $circRadius ?>" fill="none" stroke="#1d2025" stroke-width="11" />
                                                <?php if ($pctAvance > 0): ?>
                                                    <circle cx="50" cy="50" r="<?= $circRadius ?>" fill="none"
                                                            stroke="url(#circleGrad-<?= (int)$p['idPartidaObra'] ?>)"
                                                            stroke-width="11"
                                                            stroke-linecap="round"
                                                            stroke-dasharray="<?= number_format($circumference, 3, '.', '') ?>"
                                                            stroke-dashoffset="<?= number_format($dashOffset, 3, '.', '') ?>"
                                                            transform="rotate(-90 50 50)" />
                                                <?php endif; ?>
                                                <text x="50" y="50" text-anchor="middle" dominant-baseline="central"
                                                      fill="#22e085"
                                                      font-family="'Outfit', -apple-system, sans-serif"
                                                      font-size="22"
                                                      font-weight="800"
                                                      letter-spacing="-0.02em"><?= $pctAvance ?>%</text>
                                            </svg>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <p class="no-results" id="noResults">No se encontraron partidas con ese criterio.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
            <?php else: ?>
            <!-- ========================================================================= -->
            <!-- SECCIÓN: FOTOS DE LA OBRA (TODAS LAS PARTIDAS CONSOLIDADAS)               -->
            <!-- ========================================================================= -->
            <div class="photos-top-bar" aria-label="Navegación de Fotografías de la Obra">
                <a href="obra.php?id=<?= $idObra ?>" class="btn-hero-white" id="btnVolverPartidas" title="Volver a las partidas de la obra">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="19" y1="12" x2="5" y2="12"/>
                        <polyline points="12 19 5 12 12 5"/>
                    </svg>
                    <span>PARTIDAS DE LA OBRA</span>
                </a>
            </div>

            <section aria-labelledby="secFotosObraTitle" id="secFotosObra">
                <?php
                    $conteoPorTipo = [];
                    foreach ($fotosObra as $fo) {
                        $t = (int)($fo['pdfTipo'] ?? 0);
                        $conteoPorTipo[$t] = ($conteoPorTipo[$t] ?? 0) + 1;
                    }
                ?>
                <div class="section-header-row">
                    <div>
                        <div style="display: flex; align-items: center; gap: .65rem; flex-wrap: wrap;">
                            <h2 id="secFotosObraTitle" style="font-size: 1.4rem; font-weight: 700; color: #fff; margin: 0;">Fotografías de la Obra</h2>
                            <span class="badge" style="position: static; font-size: .72rem; padding: .2rem .6rem;"><?= $totalFotosObra ?></span>
                        </div>
                        <p style="color: var(--muted); font-size: .88rem; margin-top: .35rem;">
                            Evidencia visual completa de <strong><?= htmlspecialchars($obra['obrNombreObra']) ?></strong> reuniendo todas sus partidas.
                        </p>
                    </div>

                    <?php if (!empty($fotosObra)): ?>
                        <div class="filtro-dropdown-wrap" id="filtroDropdownWrap">
                            <button type="button" class="btn-filtro-fotos" id="btnFiltroFotos" aria-haspopup="true" aria-expanded="false" title="Filtrar por tipo de fotografía">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                                </svg>
                                <span>FILTRO</span>
                                <span class="filtro-active-tag" id="filtroActiveTag">Todas (<?= $totalFotosObra ?>)</span>
                                <svg class="filtro-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="6 9 12 15 18 9"/>
                                </svg>
                            </button>

                            <div class="filtro-dropdown-menu" id="filtroDropdownMenu" role="menu" aria-label="Filtrar por tipo de fotografía">
                                <div class="filtro-menu-header">Filtrar por tipo de fotografía</div>
                                <button type="button" class="filtro-menu-item active" data-filter="all" data-label="Todas (<?= $totalFotosObra ?>)" role="menuitem">
                                    <span class="filtro-item-dot dot-all"></span>
                                    <span class="filtro-item-lbl">Todas las fotografías</span>
                                    <span class="filtro-item-count"><?= $totalFotosObra ?></span>
                                </button>
                                <?php foreach ($catalogoTiposFotos as $ctf):
                                    $tipoId = (int)$ctf['pdfTipo'];
                                    $cnt = $conteoPorTipo[$tipoId] ?? 0;
                                ?>
                                    <button type="button" class="filtro-menu-item" data-filter="<?= $tipoId ?>" data-label="<?= htmlspecialchars($ctf['pdftDescripcion']) ?> (<?= $cnt ?>)" role="menuitem">
                                        <span class="filtro-item-dot dot-tipo-<?= $tipoId ?>"></span>
                                        <span class="filtro-item-lbl"><?= htmlspecialchars($ctf['pdftDescripcion']) ?></span>
                                        <span class="filtro-item-count"><?= $cnt ?></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (empty($fotosObra)): ?>
                    <div class="empty-partidas">
                        <div class="empty-icon empty-icon-camera">
                            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                        </div>
                        <h3>Esta obra aún no tiene fotografías registradas.</h3>
                        <p>Ingresa a cualquiera de las partidas de la obra para capturar o adjuntar fotografías (Antes de, Actual y Después de).</p>
                        <a href="obra.php?id=<?= $idObra ?>" class="btn btn-primary" style="width: auto; padding: .8rem 1.7rem; font-size: .92rem; display: inline-flex;">
                            <span>&larr; Ir a las partidas de la obra</span>
                        </a>
                    </div>
                <?php else: ?>
                    <!-- Píldoras rápidas sincronizadas con el botón FILTRO -->
                    <div class="filter-pills" id="fotoFilters" role="tablist">
                        <button type="button" class="filter-pill active" data-filter="all" data-label="Todas (<?= $totalFotosObra ?>)">Todas (<?= $totalFotosObra ?>)</button>
                        <?php foreach ($catalogoTiposFotos as $ctf):
                            $tipoId = (int)$ctf['pdfTipo'];
                            $cnt = $conteoPorTipo[$tipoId] ?? 0;
                        ?>
                            <button type="button" class="filter-pill" data-filter="<?= $tipoId ?>" data-label="<?= htmlspecialchars($ctf['pdftDescripcion']) ?> (<?= $cnt ?>)">
                                <?= htmlspecialchars($ctf['pdftDescripcion']) ?> (<?= $cnt ?>)
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="fotos-grid" id="fotosGrid">
                        <?php foreach ($fotosObra as $foto):
                            $tipoId = (int)($foto['pdfTipo'] ?? 0);
                            $tipoDesc = $foto['pdftDescripcion'] ?: 'Fotografía';
                            $idFoto = (int)$foto['idPartidaFoto'];
                            $idPartFoto = (int)$foto['idPartidaObra'];
                            $imgThumbSrc = "obra.php?id={$idObra}&ver_foto={$idFoto}&thumb=1";
                            $imgFullSrc  = "obra.php?id={$idObra}&ver_foto={$idFoto}";
                            $tipoBadgeCls = 'badge-tipo-' . $tipoId;
                            $pesoFullKb = !empty($foto['fotoSize']) ? round((int)$foto['fotoSize'] / 1024) : 0;
                            $pesoThumbKb = !empty($foto['thumbSize']) ? round((int)$foto['thumbSize'] / 1024) : 0;
                            $fotoDesc = trim((string)($foto['pdfDescripcion'] ?? ''));
                            $fechaFotoDisplay = !empty($foto['pdfFechaFoto']) ? fmt_date($foto['pdfFechaFoto']) : fmt_date_time($foto['pdfFecha']);
                            $partidaClave = $foto['pdoClave'] ?: 'S/C';
                            $partidaDesc  = $foto['pdoDescripcion'] ?: '';
                            $lightboxTitleTxt = $tipoDesc . ' · Partida ' . $partidaClave;
                        ?>
                            <div class="foto-card" data-tipo="<?= $tipoId ?>" data-full-src="<?= $imgFullSrc ?>">
                                <div class="foto-thumb-wrap js-lightbox-trigger"
                                     data-src="<?= $imgFullSrc ?>"
                                     data-title="<?= htmlspecialchars($lightboxTitleTxt) ?>"
                                     data-date="<?= htmlspecialchars($fechaFotoDisplay) ?>"
                                     data-desc="<?= htmlspecialchars($fotoDesc) ?>"
                                     title="Doble clic o clic en Ampliar para ver en alta resolución">
                                    <img src="<?= $imgThumbSrc ?>" alt="<?= htmlspecialchars($fotoDesc ?: $tipoDesc) ?>" class="foto-thumb-img" loading="lazy">
                                    <div class="foto-thumb-overlay">
                                        <div class="btn-zoom-circle" title="Ampliar fotografía">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                                        </div>
                                    </div>
                                </div>
                                <div class="foto-body">
                                    <div class="foto-body-top">
                                        <span class="badge-tipo <?= $tipoBadgeCls ?>">
                                            <?= htmlspecialchars($tipoDesc) ?>
                                        </span>
                                        <div style="display: flex; gap: .4rem; align-items: center;">
                                            <?php if ($pesoThumbKb > 0): ?>
                                                <span style="font-size: .72rem; color: #2ae083; font-weight: 700; background: rgba(42,224,131,.1); border: 1px solid rgba(42,224,131,.25); border-radius: 6px; padding: .15rem .45rem;" title="Tamaño miniatura en listado">
                                                    <?= $pesoThumbKb ?> KB
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($pesoFullKb > 0): ?>
                                                <span style="font-size: .72rem; color: var(--subtle); font-weight: 600;" title="Tamaño fotografía original">
                                                    HD <?= $pesoFullKb ?> KB
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <a href="partida.php?id=<?= $idPartFoto ?>&seccion=fotos" class="foto-partida-tag" title="Ver fotos de la partida <?= htmlspecialchars($partidaClave) ?>">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0;"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                                        <strong><?= htmlspecialchars($partidaClave) ?></strong>
                                        <?php if ($partidaDesc !== ''): ?>
                                            <span>&bull; <?= htmlspecialchars($partidaDesc) ?></span>
                                        <?php endif; ?>
                                    </a>

                                    <?php if ($fotoDesc !== ''): ?>
                                        <div class="foto-desc" style="font-size: .88rem; color: #f4f4f5; line-height: 1.45; font-weight: 500; word-break: break-word; margin: .1rem 0 .2rem;">
                                            <?= nl2br(htmlspecialchars($fotoDesc)) ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="foto-date">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                        <span><?= htmlspecialchars($fechaFotoDisplay) ?></span>
                                    </div>

                                    <div class="foto-footer">
                                        <button type="button" class="btn-act js-lightbox-trigger"
                                                data-src="<?= $imgFullSrc ?>"
                                                data-title="<?= htmlspecialchars($lightboxTitleTxt) ?>"
                                                data-date="<?= htmlspecialchars($fechaFotoDisplay) ?>"
                                                data-desc="<?= htmlspecialchars($fotoDesc) ?>"
                                                style="padding: .45rem .85rem; font-size: .78rem;"
                                                title="Ver fotografía original en alta resolución">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
                                            <span>Ampliar HD</span>
                                        </button>
                                        <?php if ($perm['eliminar']): ?>
                                            <button type="button" class="btn-tbl danger js-eliminar-foto"
                                                    data-id="<?= $idFoto ?>"
                                                    data-tipo="<?= htmlspecialchars($tipoDesc) ?>"
                                                    data-partida="<?= htmlspecialchars($partidaClave) ?>"
                                                    data-date="<?= htmlspecialchars($fechaFotoDisplay) ?>"
                                                    data-desc="<?= htmlspecialchars($fotoDesc) ?>"
                                                    title="Eliminar fotografía">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="no-results" id="noResultsFotos">No se encontraron fotografías del tipo seleccionado en esta obra.</p>
                <?php endif; ?>
            </section>
            <?php endif; ?>
        </main>
    </div>

    <?php if ($perm['agregar'] || $perm['modificar']): ?>
    <!-- Modal crear / editar partida -->
    <div class="modal-backdrop" id="modalPartida" role="dialog" aria-modal="true" aria-labelledby="modalPartidaTitle">
        <div class="modal">
            <div class="modal-head">
                <div>
                    <h2 id="modalPartidaTitle">Nueva partida</h2>
                    <p>Obra: <?= htmlspecialchars($obra['obrNombreObra']) ?></p>
                </div>
                <button type="button" class="modal-close js-close" aria-label="Cerrar">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <div class="alert alert-error modal-error" id="modalError" role="alert">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span id="modalErrorText"></span>
            </div>

            <form method="POST" action="obra.php?id=<?= $idObra ?>" id="formPartida" novalidate>
                <input type="hidden" name="action" value="guardar_partida">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="idObra" value="<?= $idObra ?>">
                <input type="hidden" name="idPartidaObra" id="idPartidaObra" value="0">

                <div class="form-grid">
                    <div class="full">
                        <label class="form-label" for="pdoClave">Clave de la partida <span class="req">*</span></label>
                        <input class="input-control" type="text" id="pdoClave" name="pdoClave" maxlength="50" required placeholder="Ej. PRE-01" autocomplete="off">
                    </div>
                    <div class="full">
                        <label class="form-label" for="pdoDescripcion">Descripción <span class="req">*</span> <span class="char-count" id="descCount">0/200</span></label>
                        <textarea class="input-control" id="pdoDescripcion" name="pdoDescripcion" maxlength="200" required placeholder="Ej. Trazo y nivelación del terreno"></textarea>
                    </div>
                    <div>
                        <label class="form-label" for="pdoFechaInicioTxt">Fecha de inicio</label>
                        <div class="date-field" data-target="pdoFechaInicio">
                            <input class="input-control date-text" type="text" id="pdoFechaInicioTxt" placeholder="dd/mm/aaaa" inputmode="numeric" maxlength="10" autocomplete="off">
                            <input type="hidden" name="pdoFechaInicio" id="pdoFechaInicio">
                            <button type="button" class="date-btn" aria-label="Abrir calendario de fecha de inicio" aria-expanded="false" id="btnCalInicio">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            </button>
                            <div class="calendar"></div>
                        </div>
                        <?php if ($obraInicio): ?><p class="form-hint">Desde <?= fmt_date($obraInicio) ?> (inicio de obra)</p><?php endif; ?>
                    </div>
                    <div>
                        <label class="form-label" for="pdoFechaFinTxt">Fecha de fin</label>
                        <div class="date-field" data-target="pdoFechaFin">
                            <input class="input-control date-text" type="text" id="pdoFechaFinTxt" placeholder="dd/mm/aaaa" inputmode="numeric" maxlength="10" autocomplete="off">
                            <input type="hidden" name="pdoFechaFin" id="pdoFechaFin">
                            <button type="button" class="date-btn" aria-label="Abrir calendario de fecha de fin" aria-expanded="false" id="btnCalFin">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            </button>
                            <div class="calendar align-right"></div>
                        </div>
                        <p class="form-hint" id="durHint">&nbsp;</p>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost js-close" style="padding: .8rem 1.3rem;">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btnGuardarPartida" style="width: auto; padding: .8rem 1.8rem;">
                        <span class="spinner" aria-hidden="true"></span>
                        <span class="label" id="btnGuardarLabel">Guardar partida</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($perm['eliminar']): ?>
    <!-- Modal confirmar eliminación -->
    <div class="modal-backdrop" id="modalEliminar" role="alertdialog" aria-modal="true" aria-labelledby="modalEliminarTitle">
        <div class="modal modal-sm">
            <div class="danger-icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <h2 id="modalEliminarTitle" style="font-size: 1.2rem; color: #fff; margin-bottom: .5rem;">¿Está seguro de querer eliminar esta partida?</h2>
            <p style="color: var(--muted); font-size: .88rem; line-height: 1.55;" id="eliminarDetalle"></p>
            <form method="POST" action="obra.php?id=<?= $idObra ?>">
                <input type="hidden" name="action" value="eliminar_partida">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="idObra" value="<?= $idObra ?>">
                <input type="hidden" name="idPartidaObra" id="eliminarId">
                <div class="modal-actions" style="justify-content: center;">
                    <button type="button" class="btn btn-ghost js-close" style="padding: .8rem 1.3rem;">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-danger" id="btnConfirmarEliminar" style="width: auto; padding: .8rem 1.6rem;">Sí, eliminar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal eliminar fotografía desde Fotos de la Obra -->
    <div class="modal-backdrop" id="modalEliminarFoto" role="alertdialog" aria-modal="true" aria-labelledby="modalEliminarFotoTitle">
        <div class="modal modal-sm">
            <div class="danger-icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <h2 id="modalEliminarFotoTitle" style="font-size: 1.2rem; color: #fff; margin-bottom: .5rem;">¿Eliminar esta fotografía?</h2>
            <p style="color: var(--muted); font-size: .88rem; line-height: 1.55;" id="eliminarFotoTexto"></p>
            <form method="POST" action="obra.php?id=<?= $idObra ?>&seccion=fotos">
                <input type="hidden" name="action" value="eliminar_foto">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="idObra" value="<?= $idObra ?>">
                <input type="hidden" name="idPartidaFoto" id="eliminarFotoId" value="0">
                <div class="modal-actions" style="justify-content: center;">
                    <button type="button" class="btn btn-ghost js-close" style="padding: .8rem 1.3rem;">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-danger" style="width: auto; padding: .8rem 1.6rem;">Sí, eliminar</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Lightbox de fotografía a pantalla completa -->
    <div class="lightbox-modal" id="modalLightbox" role="dialog" aria-modal="true" aria-label="Visualizador de Fotografía">
        <div class="lightbox-topbar">
            <div>
                <strong id="lightboxTitle" style="color: #fff; font-size: 1.05rem; display: block;">Fotografía</strong>
                <span id="lightboxDate" style="color: var(--muted); font-size: .82rem;"></span>
                <p id="lightboxDesc" style="color: #f4f4f5; font-size: .88rem; margin: .35rem 0 0; display: none;"></p>
            </div>
            <div style="display: flex; gap: .75rem; align-items: center;">
                <a href="#" id="lightboxDownload" download="fotografia-obra.jpg" class="btn-act" style="padding: .5rem 1rem; font-size: .82rem;" title="Descargar imagen">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    <span>Descargar</span>
                </a>
                <button type="button" class="modal-close js-lightbox-close" aria-label="Cerrar visualizador">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
        </div>
        <div class="lightbox-content" id="lightboxContent">
            <img src="" id="lightboxImg" class="lightbox-img" alt="Fotografía en alta resolución">
        </div>
    </div>

    <script>
    (() => {
        const OBRA_INICIO = <?= json_encode($obraInicio) ?>;
        const MESES = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
        const DOW = ['Lu','Ma','Mi','Ju','Vi','Sá','Do'];

        const iso = d => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
        const parseIso = s => { if (!s) return null; const [y,m,d] = s.split('-').map(Number); return new Date(y, m-1, d); };
        const isoToTxt = s => s ? s.split('-').reverse().join('/') : '';
        const txtToIso = t => {
            const m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(t.trim());
            if (!m) return null;
            const d = new Date(+m[3], +m[2]-1, +m[1]);
            return (d.getDate() === +m[1] && d.getMonth() === +m[2]-1) ? iso(d) : null;
        };

        // ---------------- Modales ----------------
        const openModal = m => {
            if (!m) return;
            m.classList.add('open');
            document.body.style.overflow = 'hidden';
            const mb = m.querySelector('.modal');
            if (mb) mb.scrollTop = 0;
            m.scrollTop = 0;
        };
        const closeModal = m => { m.classList.remove('open'); document.body.style.overflow = ''; closeAllCalendars(); };
        document.querySelectorAll('.modal-backdrop').forEach(m => {
            m.addEventListener('mousedown', e => { if (e.target === m) closeModal(m); });
            m.querySelectorAll('.js-close').forEach(b => b.addEventListener('click', () => closeModal(m)));
        });
        document.addEventListener('keydown', e => {
            if (e.key !== 'Escape') return;
            if (document.querySelector('.calendar.open')) { closeAllCalendars(); return; }
            document.querySelectorAll('.modal-backdrop.open').forEach(closeModal);
        });

        // ---------------- Date pickers ----------------
        const pickers = [];
        function closeAllCalendars(except) {
            pickers.forEach(p => { if (p !== except) p.close(); });
        }

        function DatePicker(field) {
            const txt = field.querySelector('.date-text');
            const hidden = field.querySelector('input[type=hidden]');
            const btn = field.querySelector('.date-btn');
            const cal = field.querySelector('.calendar');
            let view = new Date(); view.setDate(1);
            const api = { min: null, max: null, onChange: null };

            function set(value, silent) {
                hidden.value = value || '';
                txt.value = isoToTxt(value);
                txt.classList.remove('invalid');
                if (!silent && api.onChange) api.onChange(value);
            }

            function render() {
                const y = view.getFullYear(), mo = view.getMonth();
                const first = new Date(y, mo, 1);
                const offset = (first.getDay() + 6) % 7;
                const start = new Date(y, mo, 1 - offset);
                const todayIso = iso(new Date());
                const sel = hidden.value;
                const other = api.rangeOther ? api.rangeOther() : null;
                let html = `<div class="cal-head">
                    <button type="button" class="cal-nav" data-nav="-1" aria-label="Mes anterior">&#8249;</button>
                    <span class="cal-title">${MESES[mo]} ${y}</span>
                    <button type="button" class="cal-nav" data-nav="1" aria-label="Mes siguiente">&#8250;</button>
                </div><div class="cal-grid">${DOW.map(d => `<span class="cal-dow">${d}</span>`).join('')}`;
                for (let i = 0; i < 42; i++) {
                    const d = new Date(start); d.setDate(start.getDate() + i);
                    const v = iso(d);
                    const cls = ['cal-day'];
                    if (d.getMonth() !== mo) cls.push('other');
                    if (v === todayIso) cls.push('today');
                    if (v === sel) cls.push('selected');
                    if (sel && other) {
                        const [a, b] = sel < other ? [sel, other] : [other, sel];
                        if (v > a && v < b) cls.push('in-range');
                    }
                    const dis = (api.min && v < api.min) || (api.max && v > api.max);
                    html += `<button type="button" class="${cls.join(' ')}" data-date="${v}" ${dis ? 'disabled' : ''}>${d.getDate()}</button>`;
                }
                html += `</div><div class="cal-foot">
                    <button type="button" class="cal-link" data-act="clear">Limpiar</button>
                    <button type="button" class="cal-link" data-act="today">Hoy</button>
                </div>`;
                cal.innerHTML = html;
            }

            api.open = () => {
                closeAllCalendars(api);
                const base = parseIso(hidden.value) || parseIso(api.min) || new Date();
                view = new Date(base.getFullYear(), base.getMonth(), 1);
                render();
                cal.classList.add('open');
                btn.setAttribute('aria-expanded', 'true');
            };
            api.close = () => { cal.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); };
            api.set = set;
            api.get = () => hidden.value;
            api.txt = txt;

            btn.addEventListener('click', e => { e.stopPropagation(); cal.classList.contains('open') ? api.close() : api.open(); });
            cal.addEventListener('click', e => {
                e.stopPropagation();
                const nav = e.target.closest('[data-nav]');
                const day = e.target.closest('[data-date]');
                const act = e.target.closest('[data-act]');
                if (nav) { view.setMonth(view.getMonth() + Number(nav.dataset.nav)); render(); }
                else if (day && !day.disabled) { set(day.dataset.date); api.close(); }
                else if (act?.dataset.act === 'clear') { set(''); api.close(); }
                else if (act?.dataset.act === 'today') {
                    const t = iso(new Date());
                    if ((api.min && t < api.min) || (api.max && t > api.max)) { view = new Date(); view.setDate(1); render(); }
                    else { set(t); api.close(); }
                }
            });

            // Escritura manual con máscara dd/mm/aaaa
            txt.addEventListener('input', () => {
                let v = txt.value.replace(/\D/g, '').slice(0, 8);
                if (v.length > 4) v = v.slice(0,2) + '/' + v.slice(2,4) + '/' + v.slice(4);
                else if (v.length > 2) v = v.slice(0,2) + '/' + v.slice(2);
                txt.value = v;
                const parsed = txtToIso(v);
                if (parsed) { hidden.value = parsed; txt.classList.remove('invalid'); api.onChange && api.onChange(parsed); }
                else { hidden.value = ''; }
            });
            txt.addEventListener('blur', () => {
                if (txt.value && !txtToIso(txt.value)) txt.classList.add('invalid');
                if (!txt.value) { hidden.value = ''; api.onChange && api.onChange(''); }
            });

            pickers.push(api);
            return api;
        }
        document.addEventListener('click', () => closeAllCalendars());

        // ---------------- Formulario de partida ----------------
        const modalPartida = document.getElementById('modalPartida');
        if (modalPartida) {
            const form = document.getElementById('formPartida');
            const fields = form.querySelectorAll('.date-field');
            const dpIni = DatePicker(fields[0]);
            const dpFin = DatePicker(fields[1]);
            const inpId = document.getElementById('idPartidaObra');
            const inpClave = document.getElementById('pdoClave');
            const inpDesc = document.getElementById('pdoDescripcion');
            const descCount = document.getElementById('descCount');
            const durHint = document.getElementById('durHint');
            const errBox = document.getElementById('modalError');
            const errText = document.getElementById('modalErrorText');
            const title = document.getElementById('modalPartidaTitle');
            const btnLabel = document.getElementById('btnGuardarLabel');
            const btnSave = document.getElementById('btnGuardarPartida');

            dpIni.rangeOther = () => dpFin.get();
            dpFin.rangeOther = () => dpIni.get();

            function syncLimits() {
                dpIni.min = OBRA_INICIO;
                dpIni.max = dpFin.get() || null;
                dpFin.min = dpIni.get() || OBRA_INICIO;
                const a = dpIni.get(), b = dpFin.get();
                if (a && b && b >= a) {
                    const days = Math.round((parseIso(b) - parseIso(a)) / 86400000) + 1;
                    durHint.textContent = `Duración: ${days} día${days === 1 ? '' : 's'}`;
                } else durHint.innerHTML = '&nbsp;';
            }
            dpIni.onChange = syncLimits;
            dpFin.onChange = syncLimits;

            const updCount = () => descCount.textContent = `${inpDesc.value.length}/200`;
            inpDesc.addEventListener('input', updCount);

            function showError(msg) { errText.textContent = msg; errBox.classList.add('show'); }
            function hideError() { errBox.classList.remove('show'); }

            function fill(data) {
                const edit = data && Number(data.id) > 0;
                inpId.value = edit ? data.id : 0;
                inpClave.value = data?.clave || '';
                inpDesc.value = data?.desc || '';
                dpIni.set(data?.ini || '', true);
                dpFin.set(data?.fin || '', true);
                title.textContent = edit ? 'Editar partida' : 'Nueva partida';
                btnLabel.textContent = edit ? 'Guardar cambios' : 'Guardar partida';
                [inpClave, inpDesc].forEach(i => i.classList.remove('invalid'));
                btnSave.classList.remove('loading');
                updCount(); syncLimits(); hideError();
            }

            document.querySelectorAll('.js-nueva-partida').forEach(b => b.addEventListener('click', () => {
                fill(null); openModal(modalPartida); setTimeout(() => inpClave.focus(), 120);
            }));
            document.querySelectorAll('.js-editar').forEach(b => b.addEventListener('click', () => {
                fill(b.dataset); openModal(modalPartida); setTimeout(() => inpClave.focus(), 120);
            }));

            form.addEventListener('submit', e => {
                hideError();
                [inpClave, inpDesc].forEach(i => i.classList.toggle('invalid', !i.value.trim()));
                const a = dpIni.get(), b = dpFin.get();
                let msg = null;
                if (!inpClave.value.trim() || !inpDesc.value.trim()) msg = 'La clave y la descripción son obligatorias.';
                else if ((dpIni.txt.value && !a) || (dpFin.txt.value && !b)) msg = 'Revisa el formato de las fechas (dd/mm/aaaa).';
                else if (OBRA_INICIO && a && a < OBRA_INICIO) msg = `La fecha de inicio no puede ser anterior al inicio de la obra (${isoToTxt(OBRA_INICIO)}).`;
                else if (OBRA_INICIO && b && b < OBRA_INICIO) msg = `La fecha de fin no puede ser anterior al inicio de la obra (${isoToTxt(OBRA_INICIO)}).`;
                else if (a && b && b < a) msg = 'La fecha de fin no puede ser anterior a la fecha de inicio.';
                if (msg) { e.preventDefault(); showError(msg); return; }
                btnSave.classList.add('loading');
            });

            <?php if ($formError && $formOld !== null): ?>
            fill(<?= json_encode([
                'id'    => $formOld['idPartida'],
                'clave' => $formOld['clave'],
                'desc'  => $formOld['descripcion'],
                'ini'   => $formOld['fIni'],
                'fin'   => $formOld['fFin'],
            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>);
            showError(<?= json_encode($formError, JSON_HEX_TAG) ?>);
            openModal(modalPartida);
            <?php endif; ?>
        }

        // ---------------- Eliminar ----------------
        const modalEliminar = document.getElementById('modalEliminar');
        if (modalEliminar) {
            const det = document.getElementById('eliminarDetalle');
            document.querySelectorAll('.js-eliminar').forEach(b => b.addEventListener('click', () => {
                document.getElementById('eliminarId').value = b.dataset.id;
                const n = Number(b.dataset.insumos);
                det.textContent = `Se eliminará la partida "${b.dataset.clave}"` +
                    (n > 0 ? ` junto con sus ${n} insumo${n === 1 ? '' : 's'} asignado${n === 1 ? '' : 's'}.` : '.') +
                    ' Esta acción no se puede deshacer.';
                openModal(modalEliminar);
            }));
        }

        // ---------------- Búsqueda ----------------
        const buscar = document.getElementById('buscarPartida');
        if (buscar) {
            const cards = document.querySelectorAll('.partida-card');
            const none = document.getElementById('noResults');
            buscar.addEventListener('input', () => {
                const q = buscar.value.trim().toLowerCase();
                let visibles = 0;
                cards.forEach(c => { const ok = c.dataset.search.includes(q); c.style.display = ok ? '' : 'none'; if (ok) visibles++; });
                none.style.display = visibles ? 'none' : 'block';
            });
        }

        // ---------------- Sección FOTOS DE LA OBRA: Botón FILTRO, Píldoras y Lightbox ----------------
        const btnFiltroFotos = document.getElementById('btnFiltroFotos');
        const filtroDropdownMenu = document.getElementById('filtroDropdownMenu');
        const filtroActiveTag = document.getElementById('filtroActiveTag');
        const menuItems = document.querySelectorAll('#filtroDropdownMenu .filtro-menu-item');
        const filterPills = document.querySelectorAll('#fotoFilters .filter-pill');
        const fotoCards = document.querySelectorAll('#fotosGrid .foto-card');
        const noResultsFotos = document.getElementById('noResultsFotos');

        function applyPhotoFilter(filterVal, labelTxt) {
            menuItems.forEach(item => {
                item.classList.toggle('active', item.dataset.filter === filterVal);
            });
            filterPills.forEach(pill => {
                pill.classList.toggle('active', pill.dataset.filter === filterVal);
            });
            if (filtroActiveTag && labelTxt) {
                filtroActiveTag.textContent = labelTxt;
            }
            let visibles = 0;
            fotoCards.forEach(card => {
                const show = (filterVal === 'all' || card.dataset.tipo === filterVal);
                card.style.display = show ? 'flex' : 'none';
                if (show) visibles++;
            });
            if (noResultsFotos) {
                noResultsFotos.style.display = visibles > 0 ? 'none' : 'block';
            }
        }

        if (btnFiltroFotos && filtroDropdownMenu) {
            btnFiltroFotos.addEventListener('click', e => {
                e.stopPropagation();
                const isOpen = filtroDropdownMenu.classList.toggle('open');
                btnFiltroFotos.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });

            document.addEventListener('click', e => {
                if (!filtroDropdownMenu.contains(e.target) && e.target !== btnFiltroFotos) {
                    filtroDropdownMenu.classList.remove('open');
                    btnFiltroFotos.setAttribute('aria-expanded', 'false');
                }
            });

            menuItems.forEach(item => {
                item.addEventListener('click', () => {
                    applyPhotoFilter(item.dataset.filter, item.dataset.label);
                    filtroDropdownMenu.classList.remove('open');
                    btnFiltroFotos.setAttribute('aria-expanded', 'false');
                });
            });
        }

        if (filterPills.length > 0) {
            filterPills.forEach(pill => {
                pill.addEventListener('click', () => {
                    applyPhotoFilter(pill.dataset.filter, pill.dataset.label);
                });
            });
        }

        // Modal Eliminar Fotografía
        const modalEliminarFoto = document.getElementById('modalEliminarFoto');
        if (modalEliminarFoto) {
            const elimFotoId = document.getElementById('eliminarFotoId');
            const elimFotoTxt = document.getElementById('eliminarFotoTexto');

            document.querySelectorAll('.js-eliminar-foto').forEach(btn => {
                btn.addEventListener('click', () => {
                    elimFotoId.value = btn.dataset.id;
                    const descInfo = btn.dataset.desc ? `<br><span style="color:#d4d4d8; font-style:italic;">"${btn.dataset.desc}"</span>` : '';
                    const partInfo = btn.dataset.partida ? ` de la partida <strong>"${btn.dataset.partida}"</strong>` : '';
                    elimFotoTxt.innerHTML = `¿Deseas eliminar esta fotografía del tipo <strong>"${btn.dataset.tipo}"</strong>${partInfo}${descInfo} registrada el <strong>${btn.dataset.date}</strong>? Esta acción no se puede deshacer.`;
                    openModal(modalEliminarFoto);
                });
            });
        }

        // Lightbox de Fotografía
        const modalLightbox = document.getElementById('modalLightbox');
        const lightboxImg = document.getElementById('lightboxImg');
        const lightboxTitle = document.getElementById('lightboxTitle');
        const lightboxDate = document.getElementById('lightboxDate');
        const lightboxDesc = document.getElementById('lightboxDesc');
        const lightboxDownload = document.getElementById('lightboxDownload');

        function openLightbox(src, title, date, desc) {
            if (!modalLightbox || !lightboxImg) return;
            lightboxImg.src = src;
            if (lightboxTitle) lightboxTitle.textContent = title || 'Fotografía de la Obra';
            if (lightboxDate) lightboxDate.textContent = date || '';
            if (lightboxDesc) {
                if (desc && desc.trim()) {
                    lightboxDesc.textContent = desc.trim();
                    lightboxDesc.style.display = 'block';
                } else {
                    lightboxDesc.textContent = '';
                    lightboxDesc.style.display = 'none';
                }
            }
            if (lightboxDownload) lightboxDownload.href = src;
            modalLightbox.classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        function closeLightbox() {
            if (!modalLightbox) return;
            modalLightbox.classList.remove('open');
            document.body.style.overflow = '';
            if (lightboxImg) lightboxImg.src = '';
            if (lightboxDesc) {
                lightboxDesc.textContent = '';
                lightboxDesc.style.display = 'none';
            }
        }

        document.querySelectorAll('.js-lightbox-trigger').forEach(el => {
            el.addEventListener('click', e => {
                e.stopPropagation();
                openLightbox(el.dataset.src, el.dataset.title, el.dataset.date, el.dataset.desc);
            });
        });

        document.querySelectorAll('.foto-card').forEach(card => {
            card.addEventListener('dblclick', e => {
                if (e.target.closest('.js-eliminar-foto') || e.target.closest('.foto-partida-tag')) return;
                const trigger = card.querySelector('.js-lightbox-trigger');
                if (trigger) {
                    openLightbox(trigger.dataset.src, trigger.dataset.title, trigger.dataset.date, trigger.dataset.desc);
                }
            });
        });

        document.querySelectorAll('.js-lightbox-close').forEach(btn => {
            btn.addEventListener('click', closeLightbox);
        });

        if (modalLightbox) {
            modalLightbox.addEventListener('click', e => {
                if (e.target === modalLightbox || e.target.id === 'lightboxContent') {
                    closeLightbox();
                }
            });
        }
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && modalLightbox && modalLightbox.classList.contains('open')) {
                closeLightbox();
            }
        });
    })();
    </script>
</body>
</html>
