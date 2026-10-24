<?php
/**
 * Detalle de Partida e Insumos - G10 Obra
 * Muestra la información de una partida y gestiona sus insumos (`obrapartidasinsumos`).
 * Permisos controlados por el módulo con ModClave = 'PAS8' (usuariosmodulos).
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

require_login();
$currentUser = auth_user();
$db = Database::getConnection();

const MODULO_PARTIDAS = 'PAS8';

$idPartida = (int)($_GET['id'] ?? $_POST['idPartidaObra'] ?? 0);
if ($idPartida <= 0 || !$db) {
    header('Location: index.php');
    exit;
}

// ---------------------------------------------------------------------------
// Partida y Obra asociada
// ---------------------------------------------------------------------------
$stPartida = $db->prepare(
    "SELECT p.*, o.idObra, o.obrNombreObra, o.obrContrato, o.obrUbicacion, o.obrFechaInicio AS obrInicio, o.obrStatus, o.obrFoto
       FROM obrapartidas p
       JOIN obra o ON o.idObra = p.idObra
      WHERE p.idPartidaObra = ?
      LIMIT 1"
);
$stPartida->execute([$idPartida]);
$partida = $stPartida->fetch(PDO::FETCH_ASSOC);

if (!$partida) {
    $_SESSION['flash_error'] = 'La partida solicitada no existe o fue eliminada.';
    header('Location: index.php');
    exit;
}

$idObra = (int)$partida['idObra'];

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

$obraInicio = !empty($partida['obrInicio']) ? $partida['obrInicio'] : null;
$formError  = null;
$formModal  = null; // 'partida' o 'insumo'

// ---------------------------------------------------------------------------
// Acciones POST
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $formError = 'La sesión del formulario expiró. Por favor intenta de nuevo.';
    }
    // 1. EDITAR PARTIDA
    elseif ($action === 'guardar_partida') {
        if (!$perm['modificar']) {
            $formError = 'No tienes permiso para modificar partidas.';
        } else {
            $clave       = trim($_POST['pdoClave'] ?? '');
            $descripcion = trim($_POST['pdoDescripcion'] ?? '');
            $fIni        = trim($_POST['pdoFechaInicio'] ?? '') ?: null;
            $fFin        = trim($_POST['pdoFechaFin'] ?? '') ?: null;

            if ($clave === '' || $descripcion === '') {
                $formError = 'La clave y la descripción son obligatorias.';
                $formModal = 'partida';
            } elseif (mb_strlen($clave) > 50 || mb_strlen($descripcion) > 200) {
                $formError = 'La clave admite hasta 50 caracteres y la descripción 200.';
                $formModal = 'partida';
            } elseif (($fIni && !valid_date($fIni)) || ($fFin && !valid_date($fFin))) {
                $formError = 'Formato de fecha inválido.';
                $formModal = 'partida';
            } elseif ($fIni && $obraInicio && $fIni < $obraInicio) {
                $formError = 'La fecha de inicio no puede ser anterior al inicio de la obra (' . fmt_date($obraInicio) . ').';
                $formModal = 'partida';
            } elseif ($fFin && $obraInicio && $fFin < $obraInicio) {
                $formError = 'La fecha de fin no puede ser anterior al inicio de la obra (' . fmt_date($obraInicio) . ').';
                $formModal = 'partida';
            } elseif ($fIni && $fFin && $fFin < $fIni) {
                $formError = 'La fecha de fin no puede ser anterior a la fecha de inicio.';
                $formModal = 'partida';
            } else {
                $stDup = $db->prepare("SELECT COUNT(*) FROM obrapartidas WHERE idObra = ? AND pdoClave = ? AND idPartidaObra <> ?");
                $stDup->execute([$idObra, $clave, $idPartida]);
                if ((int)$stDup->fetchColumn() > 0) {
                    $formError = "Ya existe una partida con la clave '{$clave}' en esta obra.";
                    $formModal = 'partida';
                } else {
                    try {
                        $st = $db->prepare("UPDATE obrapartidas
                                               SET pdoClave = ?, pdoDescripcion = ?, pdoFechaInicio = ?, pdoFechaFin = ?
                                             WHERE idPartidaObra = ? AND idObra = ?");
                        $st->execute([$clave, $descripcion, $fIni, $fFin, $idPartida, $idObra]);
                        $_SESSION['flash_success'] = "Partida '{$clave}' actualizada correctamente.";
                        header('Location: partida.php?id=' . $idPartida);
                        exit;
                    } catch (Exception $e) {
                        $formError = 'Error al actualizar la partida: ' . $e->getMessage();
                        $formModal = 'partida';
                    }
                }
            }
        }
    }
    // 2. ELIMINAR PARTIDA COMPLETA
    elseif ($action === 'eliminar_partida') {
        if (!$perm['eliminar']) {
            $_SESSION['flash_error'] = 'No tienes permiso para eliminar partidas.';
            header('Location: partida.php?id=' . $idPartida);
            exit;
        } else {
            try {
                $db->beginTransaction();
                $claveDel = $partida['pdoClave'] ?? 'Partida';
                $db->prepare("DELETE FROM obrapartidasinsumos WHERE idPartidaObra = ?")->execute([$idPartida]);
                $db->prepare("DELETE FROM obrapartidas WHERE idPartidaObra = ? AND idObra = ?")->execute([$idPartida, $idObra]);
                $db->commit();
                $_SESSION['flash_success'] = "La partida '{$claveDel}' y sus insumos fueron eliminados.";
                header('Location: obra.php?id=' . $idObra);
                exit;
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $_SESSION['flash_error'] = 'Error al eliminar la partida: ' . $e->getMessage();
                header('Location: partida.php?id=' . $idPartida);
                exit;
            }
        }
    }
    // 3. ASIGNAR INSUMO (EXISTENTE O NUEVO)
    elseif ($action === 'agregar_insumo') {
        if (!$perm['agregar'] && !$perm['modificar']) {
            $formError = 'No tienes permiso para agregar insumos a la partida.';
        } else {
            $tipoInsumo  = $_POST['tipoInsumo'] ?? 'existente'; // 'existente' | 'nuevo'
            $idInsumoSel = (int)($_POST['idInsumo'] ?? 0);
            $cantidad    = (int)($_POST['opiCantidad'] ?? 0);

            if ($cantidad <= 0) {
                $formError = 'La cantidad debe ser un número entero mayor a 0.';
                $formModal = 'insumo';
            } elseif ($tipoInsumo === 'nuevo') {
                $nuevoDesc = trim($_POST['nuevoImoDescripcion'] ?? '');
                $nuevoUme  = (int)($_POST['nuevoIdUnidadMedida'] ?? 0);

                if ($nuevoDesc === '') {
                    $formError = 'La descripción del nuevo insumo es obligatoria.';
                    $formModal = 'insumo';
                } elseif ($nuevoUme <= 0) {
                    $formError = 'Debes seleccionar una unidad de medida para el nuevo insumo.';
                    $formModal = 'insumo';
                } else {
                    try {
                        $db->beginTransaction();
                        // Verificar si ya existe un insumo con la misma descripción (case-insensitive)
                        $stExistImo = $db->prepare("SELECT idInsumo FROM insumos WHERE UPPER(TRIM(imoDescripcion)) = UPPER(?) LIMIT 1");
                        $stExistImo->execute([$nuevoDesc]);
                        $idInsumoFinal = $stExistImo->fetchColumn();

                        if (!$idInsumoFinal) {
                            $stInsImo = $db->prepare("INSERT INTO insumos (imoDescripcion, idUnidadMedida) VALUES (?, ?)");
                            $stInsImo->execute([mb_strtoupper($nuevoDesc), $nuevoUme]);
                            $idInsumoFinal = (int)$db->lastInsertId();
                        }

                        // Verificar si el insumo ya está asignado a esta partida
                        $stCheckDup = $db->prepare("SELECT idPartidaInsumos, opiCantidad, opiSaldo FROM obrapartidasinsumos WHERE idPartidaObra = ? AND idInsumo = ? LIMIT 1");
                        $stCheckDup->execute([$idPartida, $idInsumoFinal]);
                        $asigExist = $stCheckDup->fetch(PDO::FETCH_ASSOC);

                        if ($asigExist) {
                            // Sumar cantidad si ya existe
                            $stUpd = $db->prepare("UPDATE obrapartidasinsumos SET opiCantidad = opiCantidad + ?, opiSaldo = opiSaldo + ? WHERE idPartidaInsumos = ?");
                            $stUpd->execute([$cantidad, $cantidad, $asigExist['idPartidaInsumos']]);
                            $_SESSION['flash_success'] = "El insumo ya existía en la partida; se incrementó la cantidad en {$cantidad}.";
                        } else {
                            $stAdd = $db->prepare("INSERT INTO obrapartidasinsumos (idPartidaObra, idInsumo, opiCantidad, opiSaldo) VALUES (?, ?, ?, ?)");
                            $stAdd->execute([$idPartida, $idInsumoFinal, $cantidad, $cantidad]);
                            $_SESSION['flash_success'] = "Insumo '{$nuevoDesc}' creado y agregado a la partida.";
                        }

                        $db->commit();
                        header('Location: partida.php?id=' . $idPartida);
                        exit;
                    } catch (Exception $e) {
                        if ($db->inTransaction()) $db->rollBack();
                        $formError = 'Error al registrar nuevo insumo: ' . $e->getMessage();
                        $formModal = 'insumo';
                    }
                }
            } else {
                // Tipo existente
                if ($idInsumoSel <= 0) {
                    $formError = 'Selecciona un insumo de la lista.';
                    $formModal = 'insumo';
                } else {
                    try {
                        // Verificar si ya está asignado a la partida
                        $stCheckDup = $db->prepare("SELECT idPartidaInsumos, opiCantidad, opiSaldo FROM obrapartidasinsumos WHERE idPartidaObra = ? AND idInsumo = ? LIMIT 1");
                        $stCheckDup->execute([$idPartida, $idInsumoSel]);
                        $asigExist = $stCheckDup->fetch(PDO::FETCH_ASSOC);

                        if ($asigExist) {
                            $stUpd = $db->prepare("UPDATE obrapartidasinsumos SET opiCantidad = opiCantidad + ?, opiSaldo = opiSaldo + ? WHERE idPartidaInsumos = ?");
                            $stUpd->execute([$cantidad, $cantidad, $asigExist['idPartidaInsumos']]);
                            $_SESSION['flash_success'] = "El insumo ya estaba en la partida; se incrementó su cantidad en {$cantidad}.";
                        } else {
                            $stAdd = $db->prepare("INSERT INTO obrapartidasinsumos (idPartidaObra, idInsumo, opiCantidad, opiSaldo) VALUES (?, ?, ?, ?)");
                            $stAdd->execute([$idPartida, $idInsumoSel, $cantidad, $cantidad]);
                            $_SESSION['flash_success'] = "Insumo agregado a la partida.";
                        }
                        header('Location: partida.php?id=' . $idPartida);
                        exit;
                    } catch (Exception $e) {
                        $formError = 'Error al agregar insumo: ' . $e->getMessage();
                        $formModal = 'insumo';
                    }
                }
            }
        }
    }
    // 4. EDITAR CANTIDAD DE INSUMO
    elseif ($action === 'editar_cantidad_insumo') {
        if (!$perm['modificar']) {
            $_SESSION['flash_error'] = 'No tienes permiso para modificar insumos.';
        } else {
            $idPartidaInsumos = (int)($_POST['idPartidaInsumos'] ?? 0);
            $nuevaCantidad    = (int)($_POST['opiCantidad'] ?? 0);

            if ($idPartidaInsumos <= 0 || $nuevaCantidad <= 0) {
                $_SESSION['flash_error'] = 'La cantidad debe ser un entero mayor a 0.';
            } else {
                try {
                    // Verificar pertenencia a la partida actual y calcular ajuste de saldo proporcional
                    $stCur = $db->prepare("SELECT opiCantidad, opiSaldo FROM obrapartidasinsumos WHERE idPartidaInsumos = ? AND idPartidaObra = ? LIMIT 1");
                    $stCur->execute([$idPartidaInsumos, $idPartida]);
                    $curr = $stCur->fetch(PDO::FETCH_ASSOC);

                    if (!$curr) {
                        $_SESSION['flash_error'] = 'El insumo no pertenece a esta partida.';
                    } else {
                        $dif = $nuevaCantidad - (int)$curr['opiCantidad'];
                        $nuevoSaldo = max(0, (int)$curr['opiSaldo'] + $dif);

                        $stUpd = $db->prepare("UPDATE obrapartidasinsumos SET opiCantidad = ?, opiSaldo = ? WHERE idPartidaInsumos = ? AND idPartidaObra = ?");
                        $stUpd->execute([$nuevaCantidad, $nuevoSaldo, $idPartidaInsumos, $idPartida]);
                        $_SESSION['flash_success'] = 'Cantidad de insumo actualizada.';
                    }
                } catch (Exception $e) {
                    $_SESSION['flash_error'] = 'Error al actualizar cantidad: ' . $e->getMessage();
                }
            }
        }
        header('Location: partida.php?id=' . $idPartida);
        exit;
    }
    // 5. ELIMINAR / DESVINCULAR INSUMO DE LA PARTIDA
    elseif ($action === 'eliminar_insumo_partida') {
        if (!$perm['eliminar']) {
            $_SESSION['flash_error'] = 'No tienes permiso para eliminar insumos.';
        } else {
            $idPartidaInsumos = (int)($_POST['idPartidaInsumos'] ?? 0);
            try {
                $stDel = $db->prepare("DELETE FROM obrapartidasinsumos WHERE idPartidaInsumos = ? AND idPartidaObra = ?");
                $stDel->execute([$idPartidaInsumos, $idPartida]);
                $_SESSION['flash_success'] = 'Insumo desvinculado de la partida correctamente.';
            } catch (Exception $e) {
                $_SESSION['flash_error'] = 'Error al desvincular insumo: ' . $e->getMessage();
            }
        }
        header('Location: partida.php?id=' . $idPartida);
        exit;
    }
}

// ---------------------------------------------------------------------------
// Consultar insumos asignados a esta partida
// ---------------------------------------------------------------------------
$insumosAsignados = [];
if ($puedeVer) {
    try {
        $stInsumos = $db->prepare(
            "SELECT opi.idPartidaInsumos, opi.idPartidaObra, opi.idInsumo, opi.opiCantidad, opi.opiSaldo,
                    i.imoDescripcion,
                    u.umeDescripcion
               FROM obrapartidasinsumos opi
               JOIN insumos i ON i.idInsumo = opi.idInsumo
          LEFT JOIN insumosunidadmedida u ON u.idUnidadMedida = i.idUnidadMedida
              WHERE opi.idPartidaObra = ?
              ORDER BY i.imoDescripcion ASC"
        );
        $stInsumos->execute([$idPartida]);
        $insumosAsignados = $stInsumos->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $insumosAsignados = [];
    }
}

// ---------------------------------------------------------------------------
// Catálogo general de insumos y unidades de medida (para el modal)
// ---------------------------------------------------------------------------
$catalogoInsumos = [];
$unidadesMedida = [];
if ($perm['agregar'] || $perm['modificar']) {
    try {
        $catalogoInsumos = $db->query(
            "SELECT i.idInsumo, i.imoDescripcion, u.umeDescripcion
               FROM insumos i
          LEFT JOIN insumosunidadmedida u ON u.idUnidadMedida = i.idUnidadMedida
              ORDER BY i.imoDescripcion ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $unidadesMedida = $db->query(
            "SELECT idUnidadMedida, umeDescripcion FROM insumosunidadmedida ORDER BY umeDescripcion ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

$csrf = csrf_token();
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError   = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Foto de usuario
$userPhotoData = null;
if (!empty($currentUser['id'])) {
    try {
        $stU = $db->prepare("SELECT UsuFoto FROM usuarios WHERE idUsuario = ? LIMIT 1");
        $stU->execute([$currentUser['id']]);
        if ($f = $stU->fetchColumn()) {
            $userPhotoData = 'data:image/jpeg;base64,' . base64_encode($f);
        }
    } catch (Exception $e) {}
}

// Iniciales
$initials = 'U';
if (!empty($currentUser['nombre'])) {
    $parts = preg_split('/\s+/', trim($currentUser['nombre']));
    $initials = mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1) . mb_substr($parts[1] ?? '', 0, 1));
}

// Días de duración de la partida
$partidaDias = null;
if ($partida['pdoFechaInicio'] && $partida['pdoFechaFin']) {
    $partidaDias = (new DateTime($partida['pdoFechaInicio']))->diff(new DateTime($partida['pdoFechaFin']))->days + 1;
}

$totalInsumos = count($insumosAsignados);
$sumaCantidad = array_sum(array_column($insumosAsignados, 'opiCantidad'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($partida['pdoClave'] ?: 'Partida') ?> · Insumos | G10 Obra</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/auth.css?v=<?= filemtime(__DIR__ . '/assets/css/auth.css') ?>">
    <style>
        :root {
            --surface: rgba(18, 18, 22, 0.78);
            --surface-card: rgba(24, 24, 29, 0.85);
            --border: rgba(255, 255, 255, 0.1);
            --border-strong: rgba(255, 255, 255, 0.18);
            --text: #f4f4f5;
            --muted: #a1a1aa;
            --subtle: #71717a;
            --radius: 16px;
            --ease: cubic-bezier(0.16, 1, 0.3, 1);
        }
        body { color: var(--text); background: #000; overflow-x: hidden; }
        .app { max-width: 1240px; margin: 0 auto; padding: 1.5rem 1.25rem 4rem; }
        .topbar { display: flex; justify-content: space-between; align-items: center; padding: 1rem 0 2rem; }
        .logo a { text-decoration: none; color: #fff; font-family: 'Outfit', sans-serif; font-weight: 800; font-size: 1.25rem; }
        .brand-mark { width: 34px; height: 34px; border-radius: 9px; background: linear-gradient(135deg, #ff007f, #7928ca); display: grid; place-items: center; color: #fff; font-size: .8rem; font-weight: 800; }
        .user-chip { display: flex; align-items: center; gap: .75rem; background: var(--surface); padding: .35rem .8rem .35rem .45rem; border: 1px solid var(--border); border-radius: 999px; }
        .avatar { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; }
        .avatar-colorful { display: grid; place-items: center; color: #fff; font-weight: 700; font-size: .8rem; background: linear-gradient(135deg, #7928ca, #0070f3); }
        .user-meta strong { font-size: .85rem; color: #fff; }

        /* ---------- Breadcrumbs & Navegación ---------- */
        .breadcrumbs { display: flex; align-items: center; gap: .5rem; font-size: .85rem; color: var(--muted); margin-bottom: 1.25rem; flex-wrap: wrap; }
        .breadcrumbs a { color: var(--muted); text-decoration: none; transition: color .2s var(--ease); display: inline-flex; align-items: center; gap: .35rem; }
        .breadcrumbs a:hover { color: #fff; }
        .breadcrumbs .sep { color: var(--subtle); font-size: .75rem; }
        .breadcrumbs .current { color: #fff; font-weight: 600; }

        /* ---------- Hero de la Partida ---------- */
        .partida-hero {
            background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
            padding: 1.75rem; backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
            margin-bottom: 2rem; animation: rise .45s var(--ease) both;
            box-shadow: 0 20px 45px -15px rgba(0,0,0,.6);
        }
        .hero-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 1.2rem; flex-wrap: wrap; margin-bottom: 1.1rem; }
        .hero-title-area { flex: 1; min-width: 280px; }
        .clave-badge {
            display: inline-flex; align-items: center; gap: .5rem;
            font-family: 'Outfit', sans-serif; font-size: .84rem; font-weight: 800; letter-spacing: .06em;
            color: #fff; background: rgba(255,255,255,.08); border: 1px solid var(--border-strong);
            padding: .32rem .75rem; border-radius: 8px; margin-bottom: .6rem;
        }
        .partida-hero h1 { font-size: clamp(1.4rem, 2.4vw, 1.95rem); line-height: 1.25; margin: 0 0 .5rem; color: #fff; }
        .partida-obra-parent { display: flex; align-items: center; gap: .4rem; font-size: .88rem; color: var(--muted); }
        .partida-obra-parent svg { color: var(--subtle); }
        .partida-obra-parent strong { color: #e4e4e7; }

        .hero-actions { display: flex; gap: .6rem; align-items: center; flex-wrap: wrap; }
        .btn-act {
            display: inline-flex; align-items: center; gap: .45rem;
            font: inherit; font-size: .84rem; font-weight: 600; padding: .65rem 1.1rem; border-radius: 10px;
            background: rgba(255,255,255,.06); border: 1px solid var(--border); color: #e4e4e7;
            cursor: pointer; transition: all .2s var(--ease); text-decoration: none;
        }
        .btn-act:hover { background: rgba(255,255,255,.14); color: #fff; border-color: rgba(255,255,255,.3); }
        .btn-act.danger { background: rgba(239,68,68,.1); border-color: rgba(239,68,68,.3); color: #fca5a5; }
        .btn-act.danger:hover { background: rgba(239,68,68,.22); color: #fff; border-color: rgba(239,68,68,.6); }

        .hero-stats {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: .85rem;
            padding-top: 1.2rem; border-top: 1px solid var(--border);
        }
        .hero-stats .stat-item { background: rgba(255,255,255,.02); border: 1px solid var(--border); border-radius: 11px; padding: .75rem .95rem; }
        .hero-stats .lbl { display: block; font-size: .68rem; text-transform: uppercase; color: var(--subtle); letter-spacing: .05em; margin-bottom: .25rem; font-weight: 700; }
        .hero-stats .val { font-weight: 700; color: #fff; font-size: 1.05rem; }

        /* ---------- Barra de Acciones de Insumos ---------- */
        .section-header-row { display: flex; justify-content: space-between; align-items: flex-end; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.35rem; }
        .search-box { position: relative; }
        .search-box input { padding-left: 2.3rem; min-width: 250px; }
        .search-box svg { position: absolute; left: .8rem; top: 50%; transform: translateY(-50%); color: var(--subtle); pointer-events: none; }

        /* ---------- Tabla / Listado de Insumos ---------- */
        .insumos-card-table {
            background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
            overflow: hidden; backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
            box-shadow: 0 16px 36px -15px rgba(0,0,0,.6); animation: rise .45s var(--ease) both;
        }
        .table-wrap { overflow-x: auto; }
        table.insumos-table { width: 100%; border-collapse: collapse; text-align: left; font-size: .88rem; }
        table.insumos-table th {
            padding: .95rem 1.15rem; font-size: .72rem; text-transform: uppercase; letter-spacing: .05em;
            color: var(--subtle); font-weight: 700; background: rgba(255,255,255,.025); border-bottom: 1px solid var(--border);
        }
        table.insumos-table td { padding: 1rem 1.15rem; border-bottom: 1px solid var(--border); color: #e4e4e7; vertical-align: middle; }
        table.insumos-table tbody tr { transition: background .18s var(--ease); }
        table.insumos-table tbody tr:hover { background: rgba(255,255,255,.04); }
        table.insumos-table tbody tr:last-child td { border-bottom: none; }

        .insumo-name { font-weight: 600; color: #fff; font-size: .92rem; }
        .badge-ume { display: inline-block; font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; background: rgba(255,255,255,.08); border: 1px solid var(--border); padding: .2rem .55rem; border-radius: 6px; color: var(--muted); }
        .badge-qty { display: inline-flex; align-items: center; gap: .35rem; font-weight: 700; color: #fff; font-size: .95rem; }
        .badge-pending { display: inline-flex; align-items: center; gap: .3rem; font-size: .74rem; font-weight: 600; color: #fbbf24; background: rgba(245,158,11,.1); border: 1px solid rgba(245,158,11,.28); padding: .25rem .6rem; border-radius: 999px; }
        .badge-pending::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: #fbbf24; box-shadow: 0 0 6px #fbbf24; }

        .tbl-actions { display: flex; align-items: center; gap: .45rem; justify-content: flex-end; }
        .btn-tbl {
            background: rgba(255,255,255,.05); border: 1px solid var(--border); color: var(--muted);
            border-radius: 8px; width: 32px; height: 32px; display: grid; place-items: center;
            cursor: pointer; transition: all .2s var(--ease);
        }
        .btn-tbl:hover { background: rgba(255,255,255,.14); color: #fff; border-color: rgba(255,255,255,.3); }
        .btn-tbl.danger:hover { background: rgba(239,68,68,.18); color: #fca5a5; border-color: rgba(239,68,68,.4); }

        .empty-insumos {
            text-align: center; padding: 4rem 1.5rem; border: 1px dashed var(--border-strong); border-radius: var(--radius);
            background: var(--surface); backdrop-filter: blur(14px); animation: rise .45s var(--ease) both;
        }
        .empty-icon {
            width: 76px; height: 76px; border-radius: 22px; margin: 0 auto 1.3rem; display: grid; place-items: center; color: #fff;
            background: linear-gradient(135deg, rgba(255,0,127,.25), rgba(121,40,202,.25), rgba(0,112,243,.25)); border: 1px solid rgba(255,255,255,.2);
            box-shadow: 0 0 40px rgba(121,40,202,.3); animation: float 4s ease-in-out infinite;
        }
        .empty-insumos h3 { font-size: 1.25rem; color: #fff; margin-bottom: .5rem; }
        .empty-insumos p { color: var(--muted); font-size: .9rem; max-width: 440px; margin: 0 auto 1.5rem; line-height: 1.55; }
        .no-results { display: none; text-align: center; color: var(--muted); padding: 2.5rem; font-size: .9rem; }

        /* ---------- Modales ---------- */
        .modal-backdrop {
            position: fixed; inset: 0; z-index: 100; display: grid; place-items: center; padding: 1rem;
            background: rgba(0,0,0,.68); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
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
        select.input-control { appearance: none; -webkit-appearance: none; cursor: pointer; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23a1a1aa' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 1rem center; padding-right: 2.5rem; }
        select.input-control option { background: #18181b; color: #fff; }
        .char-count { float: right; font-size: .7rem; color: var(--subtle); font-weight: 500; }
        .modal-actions { display: flex; justify-content: flex-end; gap: .75rem; margin-top: 1.6rem; }
        .modal-error { display: none; margin-bottom: 1rem; }
        .modal-error.show { display: flex; }
        .danger-icon { width: 64px; height: 64px; border-radius: 50%; margin: .25rem auto 1.1rem; display: grid; place-items: center; color: #fca5a5; background: rgba(239,68,68,.12); border: 1px solid rgba(239,68,68,.35); }
        .btn-danger { background: #ef4444 !important; color: #fff !important; border-color: #ef4444 !important; }
        .btn-danger:hover { background: #dc2626 !important; }

        /* Tabs de modo Insumo */
        .tab-pills { display: flex; gap: .5rem; background: rgba(255,255,255,.04); padding: .3rem; border-radius: 11px; margin-bottom: 1.25rem; border: 1px solid var(--border); }
        .tab-pill { flex: 1; text-align: center; padding: .55rem .8rem; font-size: .82rem; font-weight: 600; color: var(--muted); border-radius: 8px; cursor: pointer; border: none; background: transparent; transition: all .2s var(--ease); }
        .tab-pill.active { background: #fff; color: #000; font-weight: 700; box-shadow: 0 4px 12px rgba(0,0,0,.4); }

        /* Date picker */
        .date-field { position: relative; display: flex; }
        .date-field .input-control { padding-right: 3rem; }
        .date-btn { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); width: 36px; height: 34px; border-radius: 8px; border: 1px solid var(--border); background: rgba(255,255,255,.06); color: #e4e4e7; display: grid; place-items: center; cursor: pointer; transition: all .2s var(--ease); }
        .date-btn:hover, .date-btn[aria-expanded="true"] { background: #fff; color: #000; border-color: #fff; }
        .calendar { position: absolute; top: calc(100% + 8px); left: 0; z-index: 30; width: 292px; padding: 1rem; background: #18181b; border: 1px solid var(--border-strong); border-radius: 14px; box-shadow: 0 24px 50px -12px rgba(0,0,0,.85); opacity: 0; transform: translateY(-6px); pointer-events: none; transition: opacity .18s var(--ease), transform .18s var(--ease); }
        .calendar.align-right { left: auto; right: 0; }
        .calendar.open { opacity: 1; transform: none; pointer-events: auto; }
        .cal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: .75rem; }
        .cal-title { font-family: 'Outfit', sans-serif; font-weight: 700; color: #fff; text-transform: capitalize; font-size: .98rem; }
        .cal-nav { width: 30px; height: 30px; border-radius: 8px; border: 1px solid var(--border); background: transparent; color: #d4d4d8; cursor: pointer; display: grid; place-items: center; }
        .cal-nav:hover { background: rgba(255,255,255,.1); color: #fff; }
        .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 3px; }
        .cal-dow { font-size: .66rem; text-transform: uppercase; color: var(--subtle); text-align: center; padding: .3rem 0; font-weight: 700; }
        .cal-day { aspect-ratio: 1; border: none; border-radius: 8px; background: transparent; color: #e4e4e7; font: inherit; font-size: .82rem; cursor: pointer; transition: background .15s var(--ease), color .15s var(--ease); }
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

        @media (max-width: 768px) {
            .hero-top { flex-direction: column; }
            .hero-actions { width: 100%; justify-content: flex-start; }
            .section-header-row > * { width: 100%; }
            .search-box input { width: 100%; min-width: 0; }
            .form-grid { grid-template-columns: 1fr; }
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
        <!-- Topbar -->
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
            <!-- Breadcrumbs -->
            <nav class="breadcrumbs" aria-label="Ruta de navegación">
                <a href="index.php#obras-activas">Obras Activas</a>
                <span class="sep">/</span>
                <a href="obra.php?id=<?= $idObra ?>"><?= htmlspecialchars($partida['obrNombreObra']) ?></a>
                <span class="sep">/</span>
                <span class="current">Partida: <?= htmlspecialchars($partida['pdoClave'] ?: 'S/C') ?></span>
            </nav>

            <!-- Mensajes Flash -->
            <?php if ($flashSuccess): ?>
                <div class="alert alert-success" role="alert" style="margin-bottom: 1.5rem;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <span><?= htmlspecialchars($flashSuccess) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($flashError): ?>
                <div class="alert alert-error" role="alert" style="margin-bottom: 1.5rem;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <span><?= htmlspecialchars($flashError) ?></span>
                </div>
            <?php endif; ?>

            <!-- Encabezado de la Partida -->
            <section class="partida-hero" aria-label="Información de la Partida">
                <div class="hero-top">
                    <div class="hero-title-area">
                        <div class="clave-badge">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                            CLAVE: <?= htmlspecialchars($partida['pdoClave'] ?: 'S/C') ?>
                        </div>
                        <h1><?= htmlspecialchars($partida['pdoDescripcion'] ?: 'Partida sin descripción') ?></h1>
                        <div class="partida-obra-parent">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                            <span>Obra: <strong><?= htmlspecialchars($partida['obrNombreObra']) ?></strong></span>
                            <?php if (!empty($partida['obrContrato'])): ?>
                                <span style="color: var(--subtle);">&bull;</span>
                                <span>Contrato: <?= htmlspecialchars($partida['obrContrato']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Botones trasladados de la tarjeta -->
                    <div class="hero-actions">
                        <?php if ($perm['modificar']): ?>
                            <button type="button" class="btn-act js-editar-partida" id="btnEditarPartida" title="Editar datos de esta partida">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                <span>Editar Partida</span>
                            </button>
                        <?php endif; ?>

                        <?php if ($perm['eliminar']): ?>
                            <button type="button" class="btn-act danger js-eliminar-partida" id="btnEliminarPartida" title="Eliminar partida">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                                <span>Eliminar Partida</span>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="hero-stats">
                    <div class="stat-item">
                        <span class="lbl">Fecha Inicio</span>
                        <span class="val"><?= fmt_date($partida['pdoFechaInicio']) ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="lbl">Fecha Fin</span>
                        <span class="val"><?= fmt_date($partida['pdoFechaFin']) ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="lbl">Duración</span>
                        <span class="val"><?= $partidaDias !== null ? "{$partidaDias} días" : 'Sin definir' ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="lbl">Total Insumos</span>
                        <span class="val" id="statTotalInsumos"><?= $totalInsumos ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="lbl">Suma Unidades</span>
                        <span class="val"><?= number_format($sumaCantidad) ?></span>
                    </div>
                </div>
            </section>

            <!-- Sección de Insumos -->
            <section aria-labelledby="secInsumosTitle">
                <div class="section-header-row">
                    <div>
                        <h2 id="secInsumosTitle" style="font-size: 1.35rem; color: #fff; margin-bottom: .25rem;">Insumos de la Partida</h2>
                        <p style="color: var(--muted); font-size: .88rem;">Listado de insumos asignados a esta partida y sus cantidades.</p>
                    </div>

                    <div style="display: flex; gap: .75rem; align-items: center; flex-wrap: wrap;">
                        <?php if (!empty($insumosAsignados)): ?>
                            <div class="search-box">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                <input type="text" class="input-control" id="searchInsumos" placeholder="Filtrar insumos..." autocomplete="off">
                            </div>
                        <?php endif; ?>

                        <?php if ($perm['agregar'] || $perm['modificar']): ?>
                            <button type="button" class="btn btn-primary js-abrir-insumo" id="btnAgregarInsumo" style="width: auto; padding: .65rem 1.35rem; font-size: .88rem;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                <span>Agregar insumo</span>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (empty($insumosAsignados)): ?>
                    <div class="empty-insumos">
                        <div class="empty-icon">
                            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                        </div>
                        <h3>Esta partida aún no tiene insumos asignados.</h3>
                        <p>Agrega los materiales, insumos y recursos necesarios especificando la cantidad asignada para la ejecución de esta partida.</p>
                        <?php if ($perm['agregar'] || $perm['modificar']): ?>
                            <button type="button" class="btn btn-primary js-abrir-insumo" id="btnPrimerInsumo" style="width: auto; padding: .8rem 1.7rem; font-size: .92rem;">
                                <span>+ Agregar primer insumo</span>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="insumos-card-table">
                        <div class="table-wrap">
                            <table class="insumos-table" id="tablaInsumos">
                                <thead>
                                    <tr>
                                        <th>Insumo / Descripción</th>
                                        <th>Unidad de Medida</th>
                                        <th>Cantidad</th>
                                        <th>Saldo</th>
                                        <th>Costo</th>
                                        <?php if ($perm['modificar'] || $perm['eliminar']): ?>
                                            <th style="text-align: right;">Acciones</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($insumosAsignados as $ins): ?>
                                        <tr data-search="<?= htmlspecialchars(mb_strtolower(($ins['imoDescripcion'] ?? '') . ' ' . ($ins['umeDescripcion'] ?? ''))) ?>"
                                            data-id="<?= (int)$ins['idPartidaInsumos'] ?>"
                                            data-desc="<?= htmlspecialchars($ins['imoDescripcion']) ?>"
                                            data-qty="<?= (int)$ins['opiCantidad'] ?>">
                                            <td>
                                                <div class="insumo-name"><?= htmlspecialchars($ins['imoDescripcion']) ?></div>
                                            </td>
                                            <td>
                                                <span class="badge-ume"><?= htmlspecialchars($ins['umeDescripcion'] ?: 'S/U') ?></span>
                                            </td>
                                            <td>
                                                <span class="badge-qty">
                                                    <?= number_format((int)$ins['opiCantidad']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span style="color: var(--muted); font-size: .88rem; font-weight: 600;">
                                                    <?= number_format((int)$ins['opiSaldo']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge-pending" title="Costo pendiente por definir">Pendiente</span>
                                            </td>
                                            <?php if ($perm['modificar'] || $perm['eliminar']): ?>
                                                <td>
                                                    <div class="tbl-actions">
                                                        <?php if ($perm['modificar']): ?>
                                                            <button type="button" class="btn-tbl js-editar-qty" title="Modificar cantidad"
                                                                data-id="<?= (int)$ins['idPartidaInsumos'] ?>"
                                                                data-desc="<?= htmlspecialchars($ins['imoDescripcion']) ?>"
                                                                data-qty="<?= (int)$ins['opiCantidad'] ?>">
                                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                                            </button>
                                                        <?php endif; ?>

                                                        <?php if ($perm['eliminar']): ?>
                                                            <button type="button" class="btn-tbl danger js-eliminar-insumo" title="Desvincular de la partida"
                                                                data-id="<?= (int)$ins['idPartidaInsumos'] ?>"
                                                                data-desc="<?= htmlspecialchars($ins['imoDescripcion']) ?>">
                                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="no-results" id="noResultsInsumos">No se encontraron insumos con ese criterio.</p>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL: AGREGAR INSUMO A LA PARTIDA (EXISTENTE O NUEVO)                     -->
    <!-- ========================================================================= -->
    <?php if ($perm['agregar'] || $perm['modificar']): ?>
    <div class="modal-backdrop" id="modalAgregarInsumo" role="dialog" aria-modal="true" aria-labelledby="modalInsumoTitle">
        <div class="modal">
            <div class="modal-head">
                <div>
                    <h2 id="modalInsumoTitle">Agregar insumo</h2>
                    <p>Partida: <?= htmlspecialchars($partida['pdoClave']) ?> &bull; <?= htmlspecialchars($partida['pdoDescripcion']) ?></p>
                </div>
                <button type="button" class="modal-close js-close" aria-label="Cerrar">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <div class="alert alert-error modal-error" id="modalInsumoError" role="alert">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span id="modalInsumoErrorText"></span>
            </div>

            <div class="tab-pills" role="tablist">
                <button type="button" class="tab-pill active" id="tabExistente" role="tab">Seleccionar Insumo Existente</button>
                <button type="button" class="tab-pill" id="tabNuevo" role="tab">+ Crear Nuevo Insumo</button>
            </div>

            <form method="POST" action="partida.php?id=<?= $idPartida ?>" id="formInsumo" novalidate>
                <input type="hidden" name="action" value="agregar_insumo">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="idPartidaObra" value="<?= $idPartida ?>">
                <input type="hidden" name="tipoInsumo" id="tipoInsumo" value="existente">

                <div class="form-grid">
                    <!-- Panel Insumo Existente -->
                    <div class="full" id="panelExistente">
                        <label class="form-label" for="selInsumo">Insumo <span class="req">*</span></label>
                        <select class="input-control" id="selInsumo" name="idInsumo">
                            <option value="">-- Selecciona un insumo --</option>
                            <?php foreach ($catalogoInsumos as $cat): ?>
                                <option value="<?= (int)$cat['idInsumo'] ?>">
                                    <?= htmlspecialchars($cat['imoDescripcion']) ?> (<?= htmlspecialchars($cat['umeDescripcion'] ?: 'S/U') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-hint">Si el insumo que buscas no está en la lista, puedes crearlo en la pestaña "+ Crear Nuevo Insumo".</p>
                    </div>

                    <!-- Panel Insumo Nuevo -->
                    <div class="full" id="panelNuevo" style="display: none;">
                        <div class="form-grid">
                            <div class="full">
                                <label class="form-label" for="nuevoImoDescripcion">Descripción del nuevo insumo <span class="req">*</span></label>
                                <input type="text" class="input-control" id="nuevoImoDescripcion" name="nuevoImoDescripcion" maxlength="200" placeholder="Ej. TABIQUE ROJO RECOCIDO" autocomplete="off">
                            </div>
                            <div class="full">
                                <label class="form-label" for="nuevoIdUnidadMedida">Unidad de medida <span class="req">*</span></label>
                                <select class="input-control" id="nuevoIdUnidadMedida" name="nuevoIdUnidadMedida">
                                    <option value="">-- Selecciona unidad de medida --</option>
                                    <?php foreach ($unidadesMedida as $u): ?>
                                        <option value="<?= (int)$u['idUnidadMedida'] ?>"><?= htmlspecialchars($u['umeDescripcion']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Cantidad a asignar -->
                    <div class="full">
                        <label class="form-label" for="opiCantidad">Cantidad a asignar <span class="req">*</span></label>
                        <input type="number" class="input-control" id="opiCantidad" name="opiCantidad" min="1" step="1" placeholder="Ej. 50" required>
                        <p class="form-hint">El saldo inicial de la partida tomará esta misma cantidad automáticamente.</p>
                    </div>

                    <!-- Costo recordatorio -->
                    <div class="full" style="background: rgba(245,158,11,.06); border: 1px dashed rgba(245,158,11,.3); border-radius: 10px; padding: .75rem 1rem;">
                        <div style="display: flex; align-items: center; gap: .5rem; color: #fbbf24; font-size: .82rem; font-weight: 600;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <span>Costo del insumo: Pendiente</span>
                        </div>
                        <p style="font-size: .75rem; color: var(--muted); margin: .3rem 0 0 1.5rem;">El cálculo y asignación de costos unitarios se integrará en la siguiente fase.</p>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost js-close" style="padding: .8rem 1.3rem;">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btnGuardarInsumo" style="width: auto; padding: .8rem 1.8rem;">
                        <span class="spinner" aria-hidden="true"></span>
                        <span class="label">Guardar insumo</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL: EDITAR CANTIDAD DE INSUMO                                          -->
    <!-- ========================================================================= -->
    <div class="modal-backdrop" id="modalEditarQty" role="dialog" aria-modal="true" aria-labelledby="modalQtyTitle">
        <div class="modal modal-sm">
            <div class="modal-head">
                <div>
                    <h2 id="modalQtyTitle">Modificar cantidad</h2>
                    <p id="modalQtyDesc" style="color: var(--muted); font-size: .84rem;"></p>
                </div>
                <button type="button" class="modal-close js-close" aria-label="Cerrar">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <form method="POST" action="partida.php?id=<?= $idPartida ?>" id="formEditarQty">
                <input type="hidden" name="action" value="editar_cantidad_insumo">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="idPartidaObra" value="<?= $idPartida ?>">
                <input type="hidden" name="idPartidaInsumos" id="editQtyId" value="0">

                <div style="margin: 1.25rem 0; text-align: left;">
                    <label class="form-label" for="editQtyInput">Nueva cantidad <span class="req">*</span></label>
                    <input type="number" class="input-control" id="editQtyInput" name="opiCantidad" min="1" step="1" required>
                    <p class="form-hint">El saldo del insumo se ajustará de acuerdo a la diferencia.</p>
                </div>

                <div class="modal-actions" style="justify-content: center;">
                    <button type="button" class="btn btn-ghost js-close" style="padding: .8rem 1.3rem;">Cancelar</button>
                    <button type="submit" class="btn btn-primary" style="width: auto; padding: .8rem 1.6rem;">Actualizar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL: DESVINCULAR INSUMO DE LA PARTIDA                                   -->
    <!-- ========================================================================= -->
    <div class="modal-backdrop" id="modalEliminarInsumo" role="alertdialog" aria-modal="true" aria-labelledby="modalEliminarInsumoTitle">
        <div class="modal modal-sm">
            <div class="danger-icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <h2 id="modalEliminarInsumoTitle" style="font-size: 1.2rem; color: #fff; margin-bottom: .5rem;">¿Desvincular este insumo?</h2>
            <p style="color: var(--muted); font-size: .88rem; line-height: 1.55;" id="eliminarInsumoTexto"></p>
            <form method="POST" action="partida.php?id=<?= $idPartida ?>">
                <input type="hidden" name="action" value="eliminar_insumo_partida">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="idPartidaObra" value="<?= $idPartida ?>">
                <input type="hidden" name="idPartidaInsumos" id="eliminarInsumoId" value="0">
                <div class="modal-actions" style="justify-content: center;">
                    <button type="button" class="btn btn-ghost js-close" style="padding: .8rem 1.3rem;">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-danger" style="width: auto; padding: .8rem 1.6rem;">Sí, desvincular</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ========================================================================= -->
    <!-- MODAL: EDITAR PARTIDA (TRASLADADO DE OBRA.PHP)                             -->
    <!-- ========================================================================= -->
    <?php if ($perm['modificar']): ?>
    <div class="modal-backdrop" id="modalEditarPartida" role="dialog" aria-modal="true" aria-labelledby="modalEditPartidaTitle">
        <div class="modal">
            <div class="modal-head">
                <div>
                    <h2 id="modalEditPartidaTitle">Editar partida</h2>
                    <p>Obra: <?= htmlspecialchars($partida['obrNombreObra']) ?></p>
                </div>
                <button type="button" class="modal-close js-close" aria-label="Cerrar">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <div class="alert alert-error modal-error" id="modalPartidaError" role="alert">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span id="modalPartidaErrorText"></span>
            </div>

            <form method="POST" action="partida.php?id=<?= $idPartida ?>" id="formPartida" novalidate>
                <input type="hidden" name="action" value="guardar_partida">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="idPartidaObra" value="<?= $idPartida ?>">

                <div class="form-grid">
                    <div class="full">
                        <label class="form-label" for="pdoClave">Clave de la partida <span class="req">*</span></label>
                        <input class="input-control" type="text" id="pdoClave" name="pdoClave" maxlength="50" required value="<?= htmlspecialchars($partida['pdoClave']) ?>" autocomplete="off">
                    </div>
                    <div class="full">
                        <label class="form-label" for="pdoDescripcion">Descripción <span class="req">*</span> <span class="char-count" id="descCount">0/200</span></label>
                        <textarea class="input-control" id="pdoDescripcion" name="pdoDescripcion" maxlength="200" required><?= htmlspecialchars($partida['pdoDescripcion']) ?></textarea>
                    </div>
                    <div>
                        <label class="form-label" for="pdoFechaInicioTxt">Fecha de inicio</label>
                        <div class="date-field" data-target="pdoFechaInicio">
                            <input class="input-control date-text" type="text" id="pdoFechaInicioTxt" placeholder="dd/mm/aaaa" inputmode="numeric" maxlength="10" autocomplete="off">
                            <input type="hidden" name="pdoFechaInicio" id="pdoFechaInicio" value="<?= htmlspecialchars($partida['pdoFechaInicio'] ?? '') ?>">
                            <button type="button" class="date-btn" aria-label="Abrir calendario inicio" aria-expanded="false">
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
                            <input type="hidden" name="pdoFechaFin" id="pdoFechaFin" value="<?= htmlspecialchars($partida['pdoFechaFin'] ?? '') ?>">
                            <button type="button" class="date-btn" aria-label="Abrir calendario fin" aria-expanded="false">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            </button>
                            <div class="calendar align-right"></div>
                        </div>
                        <p class="form-hint" id="durHint">&nbsp;</p>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost js-close" style="padding: .8rem 1.3rem;">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btnGuardarEditPartida" style="width: auto; padding: .8rem 1.8rem;">
                        <span class="spinner" aria-hidden="true"></span>
                        <span class="label">Guardar cambios</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ========================================================================= -->
    <!-- MODAL: ELIMINAR PARTIDA (TRASLADADO DE OBRA.PHP)                          -->
    <!-- ========================================================================= -->
    <?php if ($perm['eliminar']): ?>
    <div class="modal-backdrop" id="modalEliminarPartida" role="alertdialog" aria-modal="true" aria-labelledby="modalEliminarPartidaTitle">
        <div class="modal modal-sm">
            <div class="danger-icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <h2 id="modalEliminarPartidaTitle" style="font-size: 1.2rem; color: #fff; margin-bottom: .5rem;">¿Eliminar esta partida?</h2>
            <p style="color: var(--muted); font-size: .88rem; line-height: 1.55;">
                Se eliminará la partida "<strong><?= htmlspecialchars($partida['pdoClave']) ?></strong>" junto con sus <strong><?= $totalInsumos ?></strong> insumos asociados. Esta acción no se puede deshacer y regresarás a la obra.
            </p>
            <form method="POST" action="partida.php?id=<?= $idPartida ?>">
                <input type="hidden" name="action" value="eliminar_partida">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="idPartidaObra" value="<?= $idPartida ?>">
                <div class="modal-actions" style="justify-content: center;">
                    <button type="button" class="btn btn-ghost js-close" style="padding: .8rem 1.3rem;">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-danger" style="width: auto; padding: .8rem 1.6rem;">Sí, eliminar partida</button>
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

        // ---------------- Control de Modales ----------------
        const openModal = m => { if (!m) return; m.classList.add('open'); document.body.style.overflow = 'hidden'; };
        const closeModal = m => { if (!m) return; m.classList.remove('open'); document.body.style.overflow = ''; closeAllCalendars(); };
        document.querySelectorAll('.modal-backdrop').forEach(m => {
            m.addEventListener('mousedown', e => { if (e.target === m) closeModal(m); });
            m.querySelectorAll('.js-close').forEach(b => b.addEventListener('click', () => closeModal(m)));
        });
        document.addEventListener('keydown', e => {
            if (e.key !== 'Escape') return;
            if (document.querySelector('.calendar.open')) { closeAllCalendars(); return; }
            document.querySelectorAll('.modal-backdrop.open').forEach(closeModal);
        });

        // ---------------- Calendarios / DatePickers ----------------
        const pickers = [];
        function closeAllCalendars(except) {
            pickers.forEach(p => { if (p !== except) p.close(); });
        }
        function DatePicker(field) {
            if (!field) return null;
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
                </div>
                <div class="cal-grid">
                    ${DOW.map(d => `<div class="cal-dow">${d}</div>`).join('')}`;

                for (let i = 0; i < 42; i++) {
                    const cur = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
                    const curIso = iso(cur);
                    const isCurMonth = cur.getMonth() === mo;
                    const isToday = curIso === todayIso;
                    const isSel = curIso === sel;
                    const disabled = (api.min && curIso < api.min) || (api.max && curIso > api.max);
                    let inRange = false;
                    if (other && sel) {
                        const [minR, maxR] = sel < other ? [sel, other] : [other, sel];
                        inRange = curIso >= minR && curIso <= maxR;
                    }
                    const cls = [
                        'cal-day',
                        !isCurMonth && 'other',
                        isToday && 'today',
                        inRange && 'in-range',
                        isSel && 'selected'
                    ].filter(Boolean).join(' ');

                    html += `<button type="button" class="${cls}" data-date="${curIso}" ${disabled ? 'disabled' : ''}>${cur.getDate()}</button>`;
                }
                html += `</div>
                <div class="cal-foot">
                    <button type="button" class="cal-link" data-act="today">Hoy</button>
                    <button type="button" class="cal-link" data-act="clear">Borrar</button>
                </div>`;
                cal.innerHTML = html;
            }

            function open() {
                closeAllCalendars(api);
                const current = hidden.value ? parseIso(hidden.value) : new Date();
                view = new Date(current.getFullYear(), current.getMonth(), 1);
                render();
                cal.classList.add('open');
                btn.setAttribute('aria-expanded', 'true');
            }
            function close() {
                cal.classList.remove('open');
                btn.setAttribute('aria-expanded', 'false');
            }
            api.close = close;
            api.get = () => hidden.value || null;
            api.set = set;
            api.txt = txt;

            btn.addEventListener('click', e => { e.stopPropagation(); cal.classList.contains('open') ? close() : open(); });
            cal.addEventListener('click', e => {
                e.stopPropagation();
                const nav = e.target.closest('[data-nav]');
                if (nav) { view.setMonth(view.getMonth() + Number(nav.dataset.nav)); render(); return; }
                const act = e.target.closest('[data-act]');
                if (act) {
                    if (act.dataset.act === 'today') set(iso(new Date()));
                    if (act.dataset.act === 'clear') set('');
                    close();
                    return;
                }
                const day = e.target.closest('.cal-day:not(:disabled)');
                if (day && day.dataset.date) { set(day.dataset.date); close(); }
            });

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

        // ---------------- Filtro en vivo de Insumos ----------------
        const searchInput = document.getElementById('searchInsumos');
        const rows = document.querySelectorAll('#tablaInsumos tbody tr');
        const noResults = document.getElementById('noResultsInsumos');
        if (searchInput && rows.length) {
            searchInput.addEventListener('input', () => {
                const q = searchInput.value.trim().toLowerCase();
                let visible = 0;
                rows.forEach(r => {
                    const text = r.dataset.search || '';
                    const match = !q || text.includes(q);
                    r.style.display = match ? '' : 'none';
                    if (match) visible++;
                });
                if (noResults) noResults.style.display = visible === 0 ? 'block' : 'none';
            });
        }

        // ---------------- Modal: Agregar Insumo (Tabs Existente / Nuevo) ----------------
        const modalAgregarInsumo = document.getElementById('modalAgregarInsumo');
        if (modalAgregarInsumo) {
            const formInsumo = document.getElementById('formInsumo');
            const tabExistente = document.getElementById('tabExistente');
            const tabNuevo = document.getElementById('tabNuevo');
            const panelExistente = document.getElementById('panelExistente');
            const panelNuevo = document.getElementById('panelNuevo');
            const inpTipo = document.getElementById('tipoInsumo');
            const selInsumo = document.getElementById('selInsumo');
            const nuevoDesc = document.getElementById('nuevoImoDescripcion');
            const nuevoUme = document.getElementById('nuevoIdUnidadMedida');
            const inpQty = document.getElementById('opiCantidad');
            const errBox = document.getElementById('modalInsumoError');
            const errText = document.getElementById('modalInsumoErrorText');
            const btnSave = document.getElementById('btnGuardarInsumo');

            function switchTab(tipo) {
                inpTipo.value = tipo;
                if (tipo === 'existente') {
                    tabExistente.classList.add('active');
                    tabNuevo.classList.remove('active');
                    panelExistente.style.display = 'block';
                    panelNuevo.style.display = 'none';
                    selInsumo.focus();
                } else {
                    tabNuevo.classList.add('active');
                    tabExistente.classList.remove('active');
                    panelNuevo.style.display = 'block';
                    panelExistente.style.display = 'none';
                    nuevoDesc.focus();
                }
                errBox.classList.remove('show');
            }

            tabExistente.addEventListener('click', () => switchTab('existente'));
            tabNuevo.addEventListener('click', () => switchTab('nuevo'));

            document.querySelectorAll('.js-abrir-insumo').forEach(btn => {
                btn.addEventListener('click', () => {
                    formInsumo.reset();
                    switchTab('existente');
                    errBox.classList.remove('show');
                    btnSave.classList.remove('loading');
                    openModal(modalAgregarInsumo);
                });
            });

            formInsumo.addEventListener('submit', e => {
                errBox.classList.remove('show');
                const tipo = inpTipo.value;
                const qty = Number(inpQty.value);

                if (!qty || qty <= 0) {
                    e.preventDefault();
                    errText.textContent = 'Ingresa una cantidad entera mayor a 0.';
                    errBox.classList.add('show');
                    inpQty.focus();
                    return;
                }

                if (tipo === 'existente') {
                    if (!selInsumo.value) {
                        e.preventDefault();
                        errText.textContent = 'Selecciona un insumo de la lista.';
                        errBox.classList.add('show');
                        selInsumo.focus();
                        return;
                    }
                } else {
                    if (!nuevoDesc.value.trim()) {
                        e.preventDefault();
                        errText.textContent = 'Escribe la descripción del nuevo insumo.';
                        errBox.classList.add('show');
                        nuevoDesc.focus();
                        return;
                    }
                    if (!nuevoUme.value) {
                        e.preventDefault();
                        errText.textContent = 'Selecciona una unidad de medida para el nuevo insumo.';
                        errBox.classList.add('show');
                        nuevoUme.focus();
                        return;
                    }
                }
                btnSave.classList.add('loading');
            });
        }

        // ---------------- Modal: Editar Cantidad Insumo ----------------
        const modalEditarQty = document.getElementById('modalEditarQty');
        if (modalEditarQty) {
            const editId = document.getElementById('editQtyId');
            const editInput = document.getElementById('editQtyInput');
            const editDesc = document.getElementById('modalQtyDesc');

            document.querySelectorAll('.js-editar-qty').forEach(btn => {
                btn.addEventListener('click', () => {
                    editId.value = btn.dataset.id;
                    editDesc.textContent = btn.dataset.desc;
                    editInput.value = btn.dataset.qty;
                    openModal(modalEditarQty);
                    setTimeout(() => editInput.focus(), 100);
                });
            });
        }

        // ---------------- Modal: Eliminar / Desvincular Insumo ----------------
        const modalEliminarInsumo = document.getElementById('modalEliminarInsumo');
        if (modalEliminarInsumo) {
            const elimId = document.getElementById('eliminarInsumoId');
            const elimTxt = document.getElementById('eliminarInsumoTexto');

            document.querySelectorAll('.js-eliminar-insumo').forEach(btn => {
                btn.addEventListener('click', () => {
                    elimId.value = btn.dataset.id;
                    elimTxt.innerHTML = `¿Deseas desvincular <strong>"${btn.dataset.desc}"</strong> de esta partida? El insumo permanecerá en el catálogo del sistema.`;
                    openModal(modalEliminarInsumo);
                });
            });
        }

        // ---------------- Modal: Editar Partida ----------------
        const modalEditarPartida = document.getElementById('modalEditarPartida');
        if (modalEditarPartida) {
            const btnEdit = document.querySelector('.js-editar-partida');
            const form = document.getElementById('formPartida');
            const fields = form.querySelectorAll('.date-field');
            const dpIni = DatePicker(fields[0]);
            const dpFin = DatePicker(fields[1]);
            const inpClave = document.getElementById('pdoClave');
            const inpDesc = document.getElementById('pdoDescripcion');
            const descCount = document.getElementById('descCount');
            const durHint = document.getElementById('durHint');
            const errBox = document.getElementById('modalPartidaError');
            const errText = document.getElementById('modalPartidaErrorText');
            const btnSave = document.getElementById('btnGuardarEditPartida');

            if (dpIni && dpFin) {
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

                dpIni.set(document.getElementById('pdoFechaInicio').value, true);
                dpFin.set(document.getElementById('pdoFechaFin').value, true);
                syncLimits();
            }

            const updCount = () => descCount.textContent = `${inpDesc.value.length}/200`;
            inpDesc.addEventListener('input', updCount);
            updCount();

            if (btnEdit) {
                btnEdit.addEventListener('click', () => {
                    errBox.classList.remove('show');
                    btnSave.classList.remove('loading');
                    openModal(modalEditarPartida);
                    setTimeout(() => inpClave.focus(), 120);
                });
            }

            form.addEventListener('submit', e => {
                errBox.classList.remove('show');
                [inpClave, inpDesc].forEach(i => i.classList.toggle('invalid', !i.value.trim()));
                const a = dpIni ? dpIni.get() : null, b = dpFin ? dpFin.get() : null;
                let msg = null;
                if (!inpClave.value.trim() || !inpDesc.value.trim()) msg = 'La clave y la descripción son obligatorias.';
                else if (dpIni && ((dpIni.txt.value && !a) || (dpFin.txt.value && !b))) msg = 'Revisa el formato de las fechas (dd/mm/aaaa).';
                else if (OBRA_INICIO && a && a < OBRA_INICIO) msg = `La fecha de inicio no puede ser anterior al inicio de la obra (${isoToTxt(OBRA_INICIO)}).`;
                else if (OBRA_INICIO && b && b < OBRA_INICIO) msg = `La fecha de fin no puede ser anterior al inicio de la obra (${isoToTxt(OBRA_INICIO)}).`;
                else if (a && b && b < a) msg = 'La fecha de fin no puede ser anterior a la fecha de inicio.';
                if (msg) { e.preventDefault(); errText.textContent = msg; errBox.classList.add('show'); return; }
                btnSave.classList.add('loading');
            });
        }

        // ---------------- Modal: Eliminar Partida ----------------
        const modalEliminarPartida = document.getElementById('modalEliminarPartida');
        const btnElimPartida = document.querySelector('.js-eliminar-partida');
        if (modalEliminarPartida && btnElimPartida) {
            btnElimPartida.addEventListener('click', () => openModal(modalEliminarPartida));
        }

        // Mostrar errores al recargar si hubo POST fallido
        <?php if ($formError): ?>
            <?php if ($formModal === 'partida' && $perm['modificar']): ?>
                document.getElementById('modalPartidaErrorText').textContent = <?= json_encode($formError) ?>;
                document.getElementById('modalPartidaError').classList.add('show');
                openModal(document.getElementById('modalEditarPartida'));
            <?php elseif ($formModal === 'insumo'): ?>
                document.getElementById('modalInsumoErrorText').textContent = <?= json_encode($formError) ?>;
                document.getElementById('modalInsumoError').classList.add('show');
                openModal(document.getElementById('modalAgregarInsumo'));
            <?php endif; ?>
        <?php endif; ?>
    })();
    </script>
</body>
</html>
