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

$obraInicio = !empty($obra['obrFechaInicio']) ? $obra['obrFechaInicio'] : null;
$formError  = null;
$formOld    = null; // datos a re-mostrar en el modal tras error

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
        .obra-hero h1 { font-size: clamp(1.5rem, 2.6vw, 2.1rem); line-height: 1.2; margin: .55rem 0 .5rem; }
        .tag-row { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
        .tag { font-size: .72rem; font-weight: 700; color: var(--muted); background: rgba(255,255,255,.05); padding: .25rem .6rem; border-radius: 6px; border: 1px solid var(--border); letter-spacing: .03em; }
        .pill { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: .25rem .7rem; border-radius: 999px; background: rgba(255,255,255,.12); color: #fff; border: 1px solid rgba(255,255,255,.25); display: inline-flex; align-items: center; gap: .4rem; }
        .pill::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: #4ade80; box-shadow: 0 0 8px #4ade80; }
        .obra-loc { display: flex; align-items: center; gap: .4rem; font-size: .88rem; color: var(--muted); }
        .hero-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: .75rem; margin-top: 1.25rem; padding-top: 1.1rem; border-top: 1px solid var(--border); }
        .hero-stats .lbl { display: block; font-size: .68rem; text-transform: uppercase; color: var(--subtle); letter-spacing: .05em; margin-bottom: .2rem; }
        .hero-stats .val { font-weight: 700; color: #fff; font-size: 1rem; }

        /* ---------- Sección de partidas ---------- */
        .section-header-row { display: flex; justify-content: space-between; align-items: flex-end; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.35rem; }
        .search-box { position: relative; }
        .search-box input { padding-left: 2.3rem; min-width: 240px; }
        .search-box svg { position: absolute; left: .8rem; top: 50%; transform: translateY(-50%); color: var(--subtle); pointer-events: none; }

        .partidas-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.2rem; }
        .partida-card {
            position: relative; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
            padding: 1.3rem 1.3rem 1.1rem; backdrop-filter: blur(14px); display: flex; flex-direction: column;
            transition: transform .25s var(--ease), border-color .25s var(--ease), box-shadow .25s var(--ease);
            animation: rise .45s var(--ease) both;
        }
        .partida-card::before {
            content: ''; position: absolute; inset: 0 0 auto 0; height: 2px; border-radius: var(--radius) var(--radius) 0 0;
            background: linear-gradient(90deg, #ff007f, #7928ca, #0070f3, #00dfd8); opacity: 0; transition: opacity .25s var(--ease);
        }
        .partida-card:hover { transform: translateY(-3px); border-color: rgba(255,255,255,.3); box-shadow: 0 16px 36px -15px rgba(0,0,0,.7); }
        .partida-card:hover::before { opacity: 1; }
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

        /* ---------- Modal ---------- */
        .modal-backdrop {
            position: fixed; inset: 0; z-index: 100; display: grid; place-items: center; padding: 1rem;
            background: rgba(0,0,0,.65); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
            opacity: 0; visibility: hidden; transition: opacity .25s var(--ease), visibility .25s;
        }
        .modal-backdrop.open { opacity: 1; visibility: visible; }
        .modal {
            width: 100%; max-width: 560px; max-height: calc(100vh - 2rem); overflow: visible;
            background: #111114; border: 1px solid var(--border-strong); border-radius: 18px; padding: 1.75rem;
            box-shadow: 0 30px 80px -20px rgba(0,0,0,.9); transform: translateY(16px) scale(.98); transition: transform .3s var(--ease);
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

        @media (max-width: 760px) {
            .obra-hero { grid-template-columns: 1fr; }
            .form-grid { grid-template-columns: 1fr; }
            .search-box input { min-width: 0; width: 100%; }
            .section-header-row > * { width: 100%; }
            .calendar.align-right { left: 0; right: auto; }
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
            <a href="index.php#obras-activas" class="back-link">&larr; Volver a Obras Activas</a>

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
                    <div class="tag-row">
                        <span class="tag"><?= htmlspecialchars($obra['obrContrato'] ?: 'S/N CONTRATO') ?></span>
                        <span class="pill"><?= htmlspecialchars($statusLabel) ?></span>
                    </div>
                    <h1><?= htmlspecialchars($obra['obrNombreObra'] ?: 'Obra sin nombre') ?></h1>
                    <?php if (!empty($obra['obrUbicacion'])): ?>
                        <p class="obra-loc">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            <?= htmlspecialchars($obra['obrUbicacion']) ?>
                        </p>
                    <?php endif; ?>
                    <div class="hero-stats">
                        <div><span class="lbl">Total Neto</span><span class="val">$<?= number_format((float)$montoTotal, 2) ?></span></div>
                        <div><span class="lbl">Fecha Inicio</span><span class="val"><?= fmt_date($obraInicio) ?></span></div>
                        <div><span class="lbl">Ejercicio</span><span class="val"><?= htmlspecialchars($obra['obrEjercicioFiscal'] ?: '—') ?></span></div>
                        <div><span class="lbl">Partidas</span><span class="val"><?= count($partidas) ?></span></div>
                    </div>
                </div>
            </section>

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
                            ?>
                                <article class="partida-card" style="animation-delay: <?= min($i, 12) * 40 ?>ms"
                                         data-search="<?= htmlspecialchars(mb_strtolower(($p['pdoClave'] ?? '') . ' ' . ($p['pdoDescripcion'] ?? ''))) ?>">
                                    <div style="display: flex; justify-content: space-between; align-items: center; gap: .5rem;">
                                        <span class="partida-clave"><?= htmlspecialchars($p['pdoClave'] ?: 'S/C') ?></span>
                                        <span class="tag"><?= (int)$p['totalInsumos'] ?> insumo<?= (int)$p['totalInsumos'] === 1 ? '' : 's' ?></span>
                                    </div>
                                    <p class="partida-desc"><?= htmlspecialchars($p['pdoDescripcion'] ?: 'Sin descripción') ?></p>
                                    <div class="partida-dates">
                                        <div><span class="lbl">Inicio</span><span class="val"><?= fmt_date($p['pdoFechaInicio']) ?></span></div>
                                        <div><span class="lbl">Fin</span><span class="val"><?= fmt_date($p['pdoFechaFin']) ?></span></div>
                                    </div>
                                    <?php if ($dias): ?>
                                        <span class="partida-duration">Duración: <?= $dias ?> día<?= $dias === 1 ? '' : 's' ?></span>
                                    <?php endif; ?>
                                    <div class="partida-actions">
                                        <a class="act-btn" href="partida_insumos.php?id=<?= (int)$p['idPartidaObra'] ?>" title="Ver insumos de la partida">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                                            Insumos <span class="count-badge"><?= (int)$p['totalInsumos'] ?></span>
                                        </a>
                                        <?php if ($perm['modificar']): ?>
                                            <button type="button" class="act-btn js-editar" title="Editar partida"
                                                data-id="<?= (int)$p['idPartidaObra'] ?>"
                                                data-clave="<?= htmlspecialchars($p['pdoClave'] ?? '') ?>"
                                                data-desc="<?= htmlspecialchars($p['pdoDescripcion'] ?? '') ?>"
                                                data-ini="<?= htmlspecialchars($p['pdoFechaInicio'] ?? '') ?>"
                                                data-fin="<?= htmlspecialchars($p['pdoFechaFin'] ?? '') ?>">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                                Editar
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($perm['eliminar']): ?>
                                            <button type="button" class="act-btn danger js-eliminar" title="Eliminar partida"
                                                data-id="<?= (int)$p['idPartidaObra'] ?>"
                                                data-clave="<?= htmlspecialchars($p['pdoClave'] ?? '') ?>"
                                                data-insumos="<?= (int)$p['totalInsumos'] ?>">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                                                Eliminar
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <p class="no-results" id="noResults">No se encontraron partidas con ese criterio.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
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
    <?php endif; ?>

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
        const openModal = m => { m.classList.add('open'); document.body.style.overflow = 'hidden'; };
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
    })();
    </script>
</body>
</html>
