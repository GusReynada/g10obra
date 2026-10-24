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
// Servir fotografía en streaming directamente desde la BD (evita memory leaks)
// ---------------------------------------------------------------------------
if (isset($_GET['ver_foto'])) {
    $idFotoVer = (int)$_GET['ver_foto'];
    if ($idFotoVer > 0) {
        try {
            $stF = $db->prepare("SELECT pdfFoto FROM obrapartidasfotos WHERE idPartidaFoto = ? AND idPartidaObra = ? LIMIT 1");
            $stF->execute([$idFotoVer, $idPartida]);
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

$obraInicio = !empty($partida['obrInicio']) ? $partida['obrInicio'] : null;
$formError  = null;
$formModal  = null; // 'partida', 'insumo', 'foto' o 'avance'
$seccionActiva = (($_GET['seccion'] ?? $_POST['seccion'] ?? 'insumos') === 'fotos') ? 'fotos' : 'insumos';

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
    // ACTUALIZAR AVANCE DE LA PARTIDA (0 - 100%)
    elseif ($action === 'actualizar_avance') {
        if (!$perm['modificar']) {
            $formError = 'No tienes permiso para modificar el avance de la partida.';
            $formModal = 'avance';
        } else {
            $nuevoAvance = (int)($_POST['pdoAvance'] ?? 0);
            if ($nuevoAvance < 0 || $nuevoAvance > 100) {
                $formError = 'El porcentaje de avance debe estar entre 0% y 100%.';
                $formModal = 'avance';
            } else {
                try {
                    $stAv = $db->prepare("UPDATE obrapartidas SET pdoAvance = ? WHERE idPartidaObra = ? AND idObra = ?");
                    $stAv->execute([$nuevoAvance, $idPartida, $idObra]);
                    $_SESSION['flash_success'] = "Avance de la partida actualizado al {$nuevoAvance}%.";
                    $secParam = ($seccionActiva === 'fotos') ? '&seccion=fotos' : '';
                    header("Location: partida.php?id={$idPartida}{$secParam}");
                    exit;
                } catch (Exception $e) {
                    $formError = 'Error al actualizar el avance: ' . $e->getMessage();
                    $formModal = 'avance';
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
            $rawCosto    = trim((string)($_POST['opiCostoUnitario'] ?? ''));
            $costoUnit   = ($rawCosto !== '' && is_numeric(str_replace(',', '.', $rawCosto))) ? (float)str_replace(',', '.', $rawCosto) : -1;

            if ($cantidad <= 0) {
                $formError = 'La cantidad debe ser un número entero mayor a 0.';
                $formModal = 'insumo';
            } elseif ($costoUnit < 0) {
                $formError = 'El costo unitario debe ser un número mayor o igual a 0.';
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
                            // Sumar cantidad si ya existe y actualizar costo unitario
                            $stUpd = $db->prepare("UPDATE obrapartidasinsumos SET opiCantidad = opiCantidad + ?, opiSaldo = opiSaldo + ?, opiCostoUnitario = ? WHERE idPartidaInsumos = ?");
                            $stUpd->execute([$cantidad, $cantidad, $costoUnit, $asigExist['idPartidaInsumos']]);
                            $_SESSION['flash_success'] = "El insumo ya existía en la partida; se incrementó la cantidad en {$cantidad} y se actualizó el costo unitario.";
                        } else {
                            $stAdd = $db->prepare("INSERT INTO obrapartidasinsumos (idPartidaObra, idInsumo, opiCantidad, opiSaldo, opiCostoUnitario) VALUES (?, ?, ?, ?, ?)");
                            $stAdd->execute([$idPartida, $idInsumoFinal, $cantidad, $cantidad, $costoUnit]);
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
                            $stUpd = $db->prepare("UPDATE obrapartidasinsumos SET opiCantidad = opiCantidad + ?, opiSaldo = opiSaldo + ?, opiCostoUnitario = ? WHERE idPartidaInsumos = ?");
                            $stUpd->execute([$cantidad, $cantidad, $costoUnit, $asigExist['idPartidaInsumos']]);
                            $_SESSION['flash_success'] = "El insumo ya estaba en la partida; se incrementó su cantidad en {$cantidad} y se actualizó el costo unitario.";
                        } else {
                            $stAdd = $db->prepare("INSERT INTO obrapartidasinsumos (idPartidaObra, idInsumo, opiCantidad, opiSaldo, opiCostoUnitario) VALUES (?, ?, ?, ?, ?)");
                            $stAdd->execute([$idPartida, $idInsumoSel, $cantidad, $cantidad, $costoUnit]);
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
    // 4. MODIFICAR INSUMO (CANTIDAD Y COSTO UNITARIO)
    elseif ($action === 'editar_cantidad_insumo') {
        if (!$perm['modificar']) {
            $_SESSION['flash_error'] = 'No tienes permiso para modificar insumos.';
        } else {
            $idPartidaInsumos = (int)($_POST['idPartidaInsumos'] ?? 0);
            $nuevaCantidad    = (int)($_POST['opiCantidad'] ?? 0);
            $rawCosto         = trim((string)($_POST['opiCostoUnitario'] ?? ''));
            $nuevoCosto       = ($rawCosto !== '' && is_numeric(str_replace(',', '.', $rawCosto))) ? (float)str_replace(',', '.', $rawCosto) : -1;

            if ($idPartidaInsumos <= 0 || $nuevaCantidad <= 0 || $nuevoCosto < 0) {
                $_SESSION['flash_error'] = 'Ingresa una cantidad mayor a 0 y un costo unitario válido mayor o igual a 0.';
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

                        $stUpd = $db->prepare("UPDATE obrapartidasinsumos SET opiCantidad = ?, opiSaldo = ?, opiCostoUnitario = ? WHERE idPartidaInsumos = ? AND idPartidaObra = ?");
                        $stUpd->execute([$nuevaCantidad, $nuevoSaldo, $nuevoCosto, $idPartidaInsumos, $idPartida]);
                        $_SESSION['flash_success'] = 'Insumo actualizado correctamente.';
                    }
                } catch (Exception $e) {
                    $_SESSION['flash_error'] = 'Error al actualizar insumo: ' . $e->getMessage();
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
    // 6. AGREGAR FOTOGRAFÍA A LA PARTIDA
    elseif ($action === 'agregar_foto') {
        if (!$perm['agregar'] && !$perm['modificar']) {
            $_SESSION['flash_error'] = 'No tienes permiso para agregar fotografías a la partida.';
        } else {
            $pdfTipo        = (int)($_POST['pdfTipo'] ?? 0);
            $pdfDescripcion = trim($_POST['pdfDescripcion'] ?? '');
            if (mb_strlen($pdfDescripcion) > 400) {
                $pdfDescripcion = mb_substr($pdfDescripcion, 0, 400);
            }
            $rawBase64 = trim($_POST['fotoBase64'] ?? '');
            $blobData  = null;

            if ($rawBase64 !== '') {
                if (preg_match('/^data:image\/(\w+);base64,/', $rawBase64)) {
                    $rawBase64 = substr($rawBase64, strpos($rawBase64, ',') + 1);
                }
                $blobData = base64_decode($rawBase64);
            } elseif (!empty($_FILES['fotoFile']['tmp_name']) && is_uploaded_file($_FILES['fotoFile']['tmp_name'])) {
                $blobData = file_get_contents($_FILES['fotoFile']['tmp_name']);
            }

            if ($pdfTipo <= 0) {
                $formError = 'Debes seleccionar el tipo de fotografía (Antes de, Después de, Actual).';
                $formModal = 'foto';
            } elseif (!$blobData || strlen($blobData) < 10) {
                $formError = 'Debes adjuntar o capturar una fotografía.';
                $formModal = 'foto';
            } else {
                try {
                    // Compresión y optimización en servidor con GD
                    if (extension_loaded('gd')) {
                        $img = @imagecreatefromstring($blobData);
                        if ($img !== false) {
                            $origW = imagesx($img);
                            $origH = imagesy($img);
                            $maxDim = 1920;

                            if ($origW > $maxDim || $origH > $maxDim) {
                                if ($origW >= $origH) {
                                    $newW = $maxDim;
                                    $newH = (int)round(($origH / $origW) * $maxDim);
                                } else {
                                    $newH = $maxDim;
                                    $newW = (int)round(($origW / $origH) * $maxDim);
                                }
                                $resized = imagecreatetruecolor($newW, $newH);
                                imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
                                imagedestroy($img);
                                $img = $resized;
                            }

                            ob_start();
                            imagejpeg($img, null, 85);
                            $compressed = ob_get_clean();
                            imagedestroy($img);

                            if ($compressed && strlen($compressed) > 100) {
                                $blobData = $compressed;
                            }
                        }
                    }

                    $usuLogin = $currentUser['login'] ?? null;
                    $stInsFoto = $db->prepare(
                        "INSERT INTO obrapartidasfotos (idPartidaObra, pdfFoto, pdfFecha, pdfTipo, pdfDescripcion, pdfFechaRegistroEnSistema, usuLogin)
                         VALUES (?, ?, NOW(), ?, ?, CURDATE(), ?)"
                    );
                    $stInsFoto->execute([$idPartida, $blobData, $pdfTipo, $pdfDescripcion !== '' ? $pdfDescripcion : null, $usuLogin]);
                    $_SESSION['flash_success'] = 'Fotografía agregada a la partida correctamente.';
                    header('Location: partida.php?id=' . $idPartida . '&seccion=fotos');
                    exit;
                } catch (Exception $e) {
                    $formError = 'Error al registrar la fotografía: ' . $e->getMessage();
                    $formModal = 'foto';
                }
            }
        }
        header('Location: partida.php?id=' . $idPartida . '&seccion=fotos');
        exit;
    }
    // 7. ELIMINAR FOTOGRAFÍA DE LA PARTIDA
    elseif ($action === 'eliminar_foto') {
        if (!$perm['eliminar']) {
            $_SESSION['flash_error'] = 'No tienes permiso para eliminar fotografías.';
        } else {
            $idPartidaFoto = (int)($_POST['idPartidaFoto'] ?? 0);
            try {
                $stDelFoto = $db->prepare("DELETE FROM obrapartidasfotos WHERE idPartidaFoto = ? AND idPartidaObra = ?");
                $stDelFoto->execute([$idPartidaFoto, $idPartida]);
                $_SESSION['flash_success'] = 'Fotografía eliminada correctamente.';
            } catch (Exception $e) {
                $_SESSION['flash_error'] = 'Error al eliminar la fotografía: ' . $e->getMessage();
            }
        }
        header('Location: partida.php?id=' . $idPartida . '&seccion=fotos');
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
                    opi.opiCostoUnitario,
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

// ---------------------------------------------------------------------------
// Consultar fotos asignadas a esta partida y catálogo de tipos
// ---------------------------------------------------------------------------
$fotosPartida = [];
$catalogoTiposFotos = [];
if ($puedeVer) {
    try {
        $stFotos = $db->prepare(
            "SELECT f.idPartidaFoto, f.idPartidaObra, f.pdfFecha, f.pdfTipo, f.pdfDescripcion, f.usuLogin,
                    t.pdftDescripcion,
                    LENGTH(f.pdfFoto) AS fotoSize
               FROM obrapartidasfotos f
          LEFT JOIN obrapartidasfotostipo t ON t.pdfTipo = f.pdfTipo
              WHERE f.idPartidaObra = ?
              ORDER BY f.pdfFecha DESC, f.idPartidaFoto DESC"
        );
        $stFotos->execute([$idPartida]);
        $fotosPartida = $stFotos->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $fotosPartida = [];
    }

    try {
        $catalogoTiposFotos = $db->query(
            "SELECT pdfTipo, pdftDescripcion FROM obrapartidasfotostipo ORDER BY pdfTipo ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $catalogoTiposFotos = [];
    }
}

$totalFotos    = count($fotosPartida);
$seccionActiva = ($_GET['seccion'] ?? 'insumos') === 'fotos' ? 'fotos' : 'insumos';

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

$totalInsumos   = count($insumosAsignados);
$sumaCantidad   = array_sum(array_column($insumosAsignados, 'opiCantidad'));
$granTotalCosto = 0;
foreach ($insumosAsignados as $ins) {
    $granTotalCosto += (int)$ins['opiCantidad'] * (float)($ins['opiCostoUnitario'] ?? 0);
}

// Porcentaje de avance físico de la partida (0 - 100%)
$porcentajeAvance = (int)($partida['pdoAvance'] ?? 0);
$porcentajeAvance = max(0, min(100, $porcentajeAvance));
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

        .hero-actions { display: flex; gap: .6rem; align-items: center; }
        .hero-menu-wrap { position: relative; }
        .btn-dots {
            width: 36px; height: 36px; border-radius: 10px;
            background: rgba(255,255,255,.05); border: 1px solid var(--border);
            color: var(--muted); display: grid; place-items: center;
            cursor: pointer; transition: all .2s var(--ease);
        }
        .btn-dots:hover, .btn-dots[aria-expanded="true"] {
            background: rgba(255,255,255,.14); color: #fff; border-color: rgba(255,255,255,.3);
        }
        .hero-dropdown-menu {
            position: absolute; right: 0; top: calc(100% + 8px); z-index: 50;
            min-width: 175px; background: #141417; border: 1px solid var(--border-strong);
            border-radius: 12px; padding: .35rem; box-shadow: 0 18px 40px -10px rgba(0,0,0,.9);
            opacity: 0; transform: translateY(-6px); pointer-events: none;
            transition: opacity .18s var(--ease), transform .18s var(--ease);
        }
        .hero-dropdown-menu.open {
            opacity: 1; transform: none; pointer-events: auto;
        }
        .hero-dropdown-item {
            width: 100%; display: flex; align-items: center; gap: .65rem;
            padding: .6rem .8rem; font: inherit; font-size: .84rem; font-weight: 500;
            color: #e4e4e7; background: transparent; border: none; border-radius: 8px;
            cursor: pointer; text-align: left; transition: all .15s var(--ease);
        }
        .hero-dropdown-item:hover {
            background: rgba(255,255,255,.08); color: #fff;
        }
        .hero-dropdown-item.danger {
            color: #f87171;
        }
        .hero-dropdown-item.danger:hover {
            background: rgba(239,68,68,.14); color: #fca5a5;
        }
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

        table.insumos-table tfoot {
            border-top: 1px solid var(--border-strong);
            background: rgba(255, 255, 255, .03);
        }
        table.insumos-table tfoot td {
            padding: 1.15rem 1.15rem;
            border-bottom: none;
            vertical-align: middle;
        }
        .tfoot-label {
            text-align: right;
            font-weight: 700;
            font-size: .8rem;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--muted);
        }
        .tfoot-val {
            font-size: 1.1rem;
            font-weight: 800;
            color: #fff;
            letter-spacing: .01em;
        }

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

        /* ---------- Botón Blanco FOTOS ---------- */
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

        /* ---------- Selector de Secciones (Insumos / Fotos) ---------- */
        .nav-section-tabs {
            display: flex; gap: .65rem; margin-bottom: 1.75rem;
            border-bottom: 1px solid var(--border); padding-bottom: .85rem; align-items: center;
        }
        .tab-section-btn {
            display: inline-flex; align-items: center; gap: .55rem;
            padding: .65rem 1.2rem; border-radius: 10px;
            font-family: 'Outfit', 'Inter', sans-serif; font-size: .88rem; font-weight: 600;
            color: var(--muted); background: transparent; border: 1px solid transparent;
            text-decoration: none; transition: all .2s var(--ease); cursor: pointer;
        }
        .tab-section-btn:hover { color: #fff; background: rgba(255,255,255,.05); }
        .tab-section-btn.active {
            color: #fff; background: rgba(255,255,255,.1);
            border-color: var(--border-strong);
            box-shadow: 0 4px 14px rgba(0,0,0,.3);
        }
        .tab-count-badge {
            background: rgba(255,255,255,.12); border-radius: 999px;
            padding: .15rem .55rem; font-size: .74rem; font-weight: 700;
        }
        .tab-section-btn.active .tab-count-badge { background: #fff; color: #000; }

        /* ---------- Filtros de Fotos ---------- */
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

        /* ---------- Grid y Cards de Fotos ---------- */
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
        .badge-tipo-1 { /* Antes de */
            background: rgba(59, 130, 246, .15); color: #93c5fd; border-color: rgba(59, 130, 246, .35);
        }
        .badge-tipo-2 { /* Despues de */
            background: rgba(16, 185, 129, .15); color: #6ee7b7; border-color: rgba(16, 185, 129, .35);
        }
        .badge-tipo-3 { /* Actual */
            background: rgba(168, 85, 247, .15); color: #d8b4fe; border-color: rgba(168, 85, 247, .35);
        }
        .foto-date { display: flex; align-items: center; gap: .4rem; font-size: .78rem; color: var(--muted); }
        .foto-footer {
            display: flex; justify-content: space-between; align-items: center;
            border-top: 1px solid var(--border); padding-top: .75rem; margin-top: auto;
        }
        .empty-icon-camera {
            background: linear-gradient(135deg, rgba(0,112,243,.25), rgba(121,40,202,.25), rgba(255,0,127,.25)) !important;
            box-shadow: 0 0 40px rgba(0,112,243,.3) !important;
        }

        /* ---------- Componentes de Cámara y Carga en Modal ---------- */
        .dropzone-box {
            border: 2px dashed var(--border-strong); border-radius: 14px;
            padding: 2.2rem 1.5rem; text-align: center; background: rgba(255, 255, 255, .02);
            cursor: pointer; transition: all .2s var(--ease);
        }
        .dropzone-box:hover, .dropzone-box.dragover {
            border-color: #fff; background: rgba(255, 255, 255, .06);
        }
        .dropzone-icon {
            width: 52px; height: 52px; border-radius: 14px; margin: 0 auto .85rem;
            display: grid; place-items: center; background: rgba(255,255,255,.06);
            color: #fff; border: 1px solid var(--border);
        }
        .camera-box {
            position: relative; width: 100%; aspect-ratio: 4 / 3; max-height: 330px;
            background: #09090b; border-radius: 14px; overflow: hidden;
            border: 1px solid var(--border-strong); display: flex;
            align-items: center; justify-content: center;
        }
        .camera-video { width: 100%; height: 100%; object-fit: cover; }
        .camera-controls-bar {
            position: absolute; bottom: 12px; left: 0; right: 0;
            display: flex; justify-content: center; align-items: center; gap: 1.25rem; z-index: 10;
        }
        .btn-shutter {
            width: 58px; height: 58px; border-radius: 50%; background: #fff;
            border: 4px solid rgba(255,255,255,.5); cursor: pointer;
            box-shadow: 0 0 24px rgba(255,255,255,.7);
            transition: transform .15s var(--ease), background .15s;
        }
        .btn-shutter:hover { transform: scale(1.08); background: #e4e4e7; }
        .btn-shutter:active { transform: scale(.94); }
        .btn-cam-ctrl {
            width: 40px; height: 40px; border-radius: 50%;
            background: rgba(0,0,0,.65); border: 1px solid rgba(255,255,255,.3);
            color: #fff; display: grid; place-items: center; cursor: pointer;
            backdrop-filter: blur(6px); transition: all .2s var(--ease);
        }
        .btn-cam-ctrl:hover { background: rgba(255,255,255,.2); }

        .photo-preview-wrap {
            position: relative; width: 100%; max-height: 290px;
            border-radius: 14px; overflow: hidden; border: 1px solid var(--border-strong);
            background: #09090b; text-align: center;
        }
        .photo-preview-img {
            max-width: 100%; max-height: 290px; object-fit: contain; display: block; margin: 0 auto;
        }
        .preview-meta {
            display: flex; justify-content: space-between; align-items: center;
            padding: .6rem .85rem; background: rgba(255,255,255,.05);
            font-size: .8rem; color: var(--muted); border-radius: 9px; margin-top: .75rem;
        }

        /* ---------- Lightbox Modal ---------- */
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

        /* ---------- Barra Porcentual de Avance (Cápsula Neón idéntica al diseño) ---------- */
        .partida-progress-container {
            margin-top: 1.15rem;
            width: 100%;
            max-width: 320px;
        }
        .progress-pill-track {
            position: relative;
            display: flex;
            align-items: center;
            height: 38px;
            width: 100%;
            background: #1e2024;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 9999px;
            overflow: hidden;
            box-shadow: inset 0 2px 5px rgba(0, 0, 0, 0.55), 0 4px 14px rgba(0, 0, 0, 0.25);
            cursor: default;
            user-select: none;
        }
        .progress-pill-fill {
            position: relative;
            height: 100%;
            min-width: 0;
            border-radius: 9999px;
            background: linear-gradient(90deg, #2ae083 0%, #1fd79b 50%, #12caba 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: width .45s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 0 20px rgba(42, 224, 131, 0.35);
        }
        .progress-pill-text {
            font-family: 'Outfit', sans-serif;
            font-size: 1.05rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            line-height: 1;
            user-select: none;
            white-space: nowrap;
        }
        .progress-pill-text.in-fill {
            color: #074933;
            padding: 0 1rem;
        }
        .progress-pill-text.outside-fill {
            color: #2ae083;
            margin-left: .95rem;
            text-shadow: 0 0 10px rgba(42, 224, 131, 0.4);
        }

        /* Controles de Avance en Modal */
        .range-slider {
            -webkit-appearance: none;
            appearance: none;
            width: 100%;
            height: 9px;
            border-radius: 9999px;
            background: rgba(255, 255, 255, 0.12);
            outline: none;
            transition: background .2s;
        }
        .range-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2ae083, #12caba);
            cursor: pointer;
            box-shadow: 0 0 14px rgba(42, 224, 131, 0.65);
            border: 2px solid #fff;
            transition: transform .15s var(--ease);
        }
        .range-slider::-webkit-slider-thumb:hover {
            transform: scale(1.15);
        }
        .range-slider::-moz-range-thumb {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2ae083, #12caba);
            cursor: pointer;
            box-shadow: 0 0 14px rgba(42, 224, 131, 0.65);
            border: 2px solid #fff;
        }
        .quick-pct-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: .5rem;
        }
        .btn-quick-pct {
            padding: .5rem .25rem;
            font-size: .84rem;
            font-weight: 700;
            color: #e4e4e7;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: 9px;
            cursor: pointer;
            transition: all .15s var(--ease);
        }
        .btn-quick-pct:hover {
            background: rgba(42, 224, 131, 0.15);
            border-color: #2ae083;
            color: #2ae083;
            transform: translateY(-1px);
        }

        @keyframes rise { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
        @keyframes float { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }

        @media (max-width: 768px) {
            .hero-top { flex-direction: column; }
            .hero-actions { width: 100%; justify-content: flex-start; }
            .section-header-row > * { width: 100%; }
            .search-box input { width: 100%; min-width: 0; }
            .form-grid { grid-template-columns: 1fr; }
            .fotos-grid { grid-template-columns: 1fr; }
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
                <?php if ($seccionActiva === 'fotos'): ?>
                    <a href="partida.php?id=<?= $idPartida ?>&seccion=insumos">Partida: <?= htmlspecialchars($partida['pdoClave'] ?: 'S/C') ?></a>
                    <span class="sep">/</span>
                    <span class="current">Fotos</span>
                <?php else: ?>
                    <span class="current">Partida: <?= htmlspecialchars($partida['pdoClave'] ?: 'S/C') ?></span>
                <?php endif; ?>
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

                        <!-- Barra Porcentual de Avance (Diseño Cápsula Neón - Estática) -->
                        <div class="partida-progress-container" aria-label="Avance físico de la partida: <?= $porcentajeAvance ?>%">
                            <div class="progress-pill-track" id="partidaProgressTrack" role="progressbar" aria-valuenow="<?= $porcentajeAvance ?>" aria-valuemin="0" aria-valuemax="100">
                                <div class="progress-pill-fill" id="partidaProgressFill" style="width: <?= $porcentajeAvance ?>%;">
                                    <?php if ($porcentajeAvance >= 14): ?>
                                        <span class="progress-pill-text in-fill"><?= $porcentajeAvance ?>%</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($porcentajeAvance < 14): ?>
                                    <span class="progress-pill-text outside-fill"><?= $porcentajeAvance ?>%</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Acciones del Hero: Botón Blanco FOTOS y Menú de opciones de partida -->
                    <div class="hero-actions">
                        <?php if ($seccionActiva === 'fotos'): ?>
                            <a href="partida.php?id=<?= $idPartida ?>&seccion=insumos" class="btn-hero-white" id="btnToggleSeccion" title="Ver Insumos de la partida">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                                    <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                                    <line x1="12" y1="22.08" x2="12" y2="12"/>
                                </svg>
                                <span>INSUMOS</span>
                            </a>
                        <?php else: ?>
                            <a href="partida.php?id=<?= $idPartida ?>&seccion=fotos" class="btn-hero-white" id="btnIrFotos" title="Ver Fotografías de la partida">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                                    <circle cx="12" cy="13" r="4"/>
                                </svg>
                                <span>FOTOS</span>
                            </a>
                        <?php endif; ?>

                        <?php if ($perm['modificar'] || $perm['eliminar']): ?>
                            <div class="hero-menu-wrap" id="heroMenuWrap">
                                <button type="button" class="btn-dots" id="btnHeroDots" aria-haspopup="true" aria-expanded="false" title="Más opciones de la partida">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                                        <circle cx="12" cy="5" r="1.8"/>
                                        <circle cx="12" cy="12" r="1.8"/>
                                        <circle cx="12" cy="19" r="1.8"/>
                                    </svg>
                                </button>
                                <div class="hero-dropdown-menu" id="heroDropdownMenu" role="menu">
                                    <?php if ($perm['modificar']): ?>
                                        <button type="button" class="hero-dropdown-item js-avance-partida" id="btnAvancePartida" role="menuitem">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                                <line x1="18" y1="20" x2="18" y2="10"/>
                                                <line x1="12" y1="20" x2="12" y2="4"/>
                                                <line x1="6" y1="20" x2="6" y2="14"/>
                                            </svg>
                                            <span>Avance</span>
                                        </button>
                                        <button type="button" class="hero-dropdown-item js-editar-partida" id="btnEditarPartida" role="menuitem">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                            <span>Editar partida</span>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($perm['eliminar']): ?>
                                        <button type="button" class="hero-dropdown-item danger js-eliminar-partida" id="btnEliminarPartida" role="menuitem">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                                            <span>Eliminar partida</span>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
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
                        <span class="lbl">Avance Físico</span>
                        <span class="val" id="statAvanceFisico" style="color: #2ae083;"><?= $porcentajeAvance ?>%</span>
                    </div>
                    <div class="stat-item">
                        <span class="lbl">Total Insumos</span>
                        <span class="val" id="statTotalInsumos"><?= $totalInsumos ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="lbl">Total Fotos</span>
                        <span class="val" id="statTotalFotos"><?= $totalFotos ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="lbl">Costo Total Insumos</span>
                        <span class="val" id="statCostoTotal">$<?= number_format($granTotalCosto, 2) ?></span>
                    </div>
                </div>
            </section>

            <!-- Selector de Secciones (Insumos / Fotos) -->
            <div class="nav-section-tabs" role="tablist" aria-label="Secciones de la partida">
                <a href="partida.php?id=<?= $idPartida ?>&seccion=insumos" class="tab-section-btn <?= $seccionActiva === 'insumos' ? 'active' : '' ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                    <span>Insumos</span>
                    <span class="tab-count-badge"><?= $totalInsumos ?></span>
                </a>
                <a href="partida.php?id=<?= $idPartida ?>&seccion=fotos" class="tab-section-btn <?= $seccionActiva === 'fotos' ? 'active' : '' ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    <span>Fotografías</span>
                    <span class="tab-count-badge"><?= $totalFotos ?></span>
                </a>
            </div>

            <?php if ($seccionActiva === 'insumos'): ?>
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
                                        <th style="text-align: right;">Costo Unitario</th>
                                        <th style="text-align: right;">Costo Total</th>
                                        <?php if ($perm['modificar'] || $perm['eliminar']): ?>
                                            <th style="text-align: right;">Acciones</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($insumosAsignados as $ins): 
                                        $costoUnit = (float)($ins['opiCostoUnitario'] ?? 0);
                                        $costoTot  = (int)$ins['opiCantidad'] * $costoUnit;
                                    ?>
                                        <tr data-search="<?= htmlspecialchars(mb_strtolower(($ins['imoDescripcion'] ?? '') . ' ' . ($ins['umeDescripcion'] ?? ''))) ?>"
                                            data-id="<?= (int)$ins['idPartidaInsumos'] ?>"
                                            data-desc="<?= htmlspecialchars($ins['imoDescripcion']) ?>"
                                            data-qty="<?= (int)$ins['opiCantidad'] ?>"
                                            data-costo="<?= $costoUnit ?>"
                                            data-costo-total="<?= $costoTot ?>">
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
                                            <td style="text-align: right;">
                                                <span style="font-weight: 600; color: #e4e4e7; font-size: .92rem;">
                                                    $<?= number_format($costoUnit, 2) ?>
                                                </span>
                                            </td>
                                            <td style="text-align: right;">
                                                <span style="font-weight: 700; color: #fff; font-size: .95rem;">
                                                    $<?= number_format($costoTot, 2) ?>
                                                </span>
                                            </td>
                                            <?php if ($perm['modificar'] || $perm['eliminar']): ?>
                                                <td>
                                                    <div class="tbl-actions">
                                                        <?php if ($perm['modificar']): ?>
                                                            <button type="button" class="btn-tbl js-editar-qty" title="Modificar insumo"
                                                                data-id="<?= (int)$ins['idPartidaInsumos'] ?>"
                                                                data-desc="<?= htmlspecialchars($ins['imoDescripcion']) ?>"
                                                                data-qty="<?= (int)$ins['opiCantidad'] ?>"
                                                                data-costo="<?= $costoUnit ?>">
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
                                <tfoot>
                                    <tr class="tfoot-total-row">
                                        <td colspan="4" class="tfoot-label">
                                            Total Insumos Partida:
                                        </td>
                                        <td style="text-align: right; color: var(--subtle); font-size: .75rem;">
                                            —
                                        </td>
                                        <td style="text-align: right;" class="tfoot-val" id="totalCostosInsumos">
                                            $<?= number_format($granTotalCosto, 2) ?>
                                        </td>
                                        <?php if ($perm['modificar'] || $perm['eliminar']): ?>
                                            <td></td>
                                        <?php endif; ?>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <p class="no-results" id="noResultsInsumos">No se encontraron insumos con ese criterio.</p>
                    </div>
                <?php endif; ?>
            </section>
            <?php else: ?>
            <!-- Sección de Fotografías -->
            <section aria-labelledby="secFotosTitle" id="secFotos">
                <div class="section-header-row">
                    <div>
                        <h2 id="secFotosTitle" style="font-size: 1.35rem; color: #fff; margin-bottom: .25rem;">Fotografías de la Partida</h2>
                        <p style="color: var(--muted); font-size: .88rem;">Registro y evidencia visual de la partida (Antes de, Actual y Después de).</p>
                    </div>

                    <div style="display: flex; gap: .75rem; align-items: center; flex-wrap: wrap;">
                        <?php if ($perm['agregar'] || $perm['modificar']): ?>
                            <button type="button" class="btn btn-primary js-abrir-foto" id="btnAgregarFoto" style="width: auto; padding: .65rem 1.35rem; font-size: .88rem;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/><line x1="12" y1="9" x2="12" y2="17"/><line x1="8" y1="13" x2="16" y2="13"/></svg>
                                <span>Agregar foto</span>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (empty($fotosPartida)): ?>
                    <div class="empty-insumos">
                        <div class="empty-icon empty-icon-camera">
                            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                        </div>
                        <h3>Esta partida aún no tiene ninguna foto.</h3>
                        <p>Adjunta archivos o toma fotografías directamente con tu cámara para documentar el progreso, antecedentes y resultados de esta partida.</p>
                        <?php if ($perm['agregar'] || $perm['modificar']): ?>
                            <button type="button" class="btn btn-primary js-abrir-foto" id="btnPrimerFoto" style="width: auto; padding: .8rem 1.7rem; font-size: .92rem;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                <span>+ Agregar primer foto</span>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <!-- Filtros por tipo de foto -->
                    <div class="filter-pills" id="fotoFilters" role="tablist">
                        <button type="button" class="filter-pill active" data-filter="all">Todas (<?= $totalFotos ?>)</button>
                        <?php
                            $conteoPorTipo = [];
                            foreach ($fotosPartida as $fp) {
                                $t = (int)($fp['pdfTipo'] ?? 0);
                                $conteoPorTipo[$t] = ($conteoPorTipo[$t] ?? 0) + 1;
                            }
                            foreach ($catalogoTiposFotos as $ctf):
                                $tipoId = (int)$ctf['pdfTipo'];
                                $cnt = $conteoPorTipo[$tipoId] ?? 0;
                        ?>
                            <button type="button" class="filter-pill" data-filter="<?= $tipoId ?>">
                                <?= htmlspecialchars($ctf['pdftDescripcion']) ?> (<?= $cnt ?>)
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="fotos-grid" id="fotosGrid">
                        <?php foreach ($fotosPartida as $foto): 
                            $tipoId = (int)($foto['pdfTipo'] ?? 0);
                            $tipoDesc = $foto['pdftDescripcion'] ?: 'Fotografía';
                            $imgSrc = "partida.php?id={$idPartida}&ver_foto=" . (int)$foto['idPartidaFoto'];
                            $tipoBadgeCls = 'badge-tipo-' . $tipoId;
                            $pesoKb = !empty($foto['fotoSize']) ? round((int)$foto['fotoSize'] / 1024) : 0;
                            $fotoDesc = trim((string)($foto['pdfDescripcion'] ?? ''));
                        ?>
                            <div class="foto-card" data-tipo="<?= $tipoId ?>">
                                <div class="foto-thumb-wrap js-lightbox-trigger"
                                     data-src="<?= $imgSrc ?>"
                                     data-title="<?= htmlspecialchars($tipoDesc) ?>"
                                     data-date="<?= htmlspecialchars(fmt_date_time($foto['pdfFecha'])) ?>"
                                     data-desc="<?= htmlspecialchars($fotoDesc) ?>"
                                     title="Ver en pantalla completa">
                                    <img src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($fotoDesc ?: $tipoDesc) ?>" class="foto-thumb-img" loading="lazy">
                                    <div class="foto-thumb-overlay">
                                        <div class="btn-zoom-circle">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                                        </div>
                                    </div>
                                </div>
                                <div class="foto-body">
                                    <div class="foto-body-top">
                                        <span class="badge-tipo <?= $tipoBadgeCls ?>">
                                            <?= htmlspecialchars($tipoDesc) ?>
                                        </span>
                                        <?php if ($pesoKb > 0): ?>
                                            <span style="font-size: .72rem; color: var(--subtle); font-weight: 600;"><?= $pesoKb ?> KB</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($fotoDesc !== ''): ?>
                                        <div class="foto-desc" style="font-size: .88rem; color: #f4f4f5; line-height: 1.45; font-weight: 500; word-break: break-word; margin: .15rem 0 .25rem;">
                                            <?= nl2br(htmlspecialchars($fotoDesc)) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="foto-date">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                        <span><?= htmlspecialchars(fmt_date_time($foto['pdfFecha'])) ?></span>
                                    </div>
                                    <div class="foto-footer">
                                        <button type="button" class="btn-act js-lightbox-trigger"
                                                data-src="<?= $imgSrc ?>"
                                                data-title="<?= htmlspecialchars($tipoDesc) ?>"
                                                data-date="<?= htmlspecialchars(fmt_date_time($foto['pdfFecha'])) ?>"
                                                data-desc="<?= htmlspecialchars($fotoDesc) ?>"
                                                style="padding: .45rem .85rem; font-size: .78rem;">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
                                            <span>Ampliar</span>
                                        </button>
                                        <?php if ($perm['eliminar']): ?>
                                            <button type="button" class="btn-tbl danger js-eliminar-foto"
                                                    data-id="<?= (int)$foto['idPartidaFoto'] ?>"
                                                    data-tipo="<?= htmlspecialchars($tipoDesc) ?>"
                                                    data-date="<?= htmlspecialchars(fmt_date_time($foto['pdfFecha'])) ?>"
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
                <?php endif; ?>
            </section>
            <?php endif; ?>
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

                    <!-- Costo Unitario -->
                    <div class="full">
                        <label class="form-label" for="opiCostoUnitario">Costo unitario ($) <span class="req">*</span></label>
                        <input type="number" class="input-control" id="opiCostoUnitario" name="opiCostoUnitario" min="0" step="0.01" placeholder="Ej. 150.00" required>
                        <p class="form-hint">Costo unitario del insumo para el cálculo de costos de la partida.</p>
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
    <!-- MODAL: MODIFICAR INSUMO (CANTIDAD Y COSTO UNITARIO)                       -->
    <!-- ========================================================================= -->
    <div class="modal-backdrop" id="modalEditarQty" role="dialog" aria-modal="true" aria-labelledby="modalQtyTitle">
        <div class="modal modal-sm">
            <div class="modal-head">
                <div>
                    <h2 id="modalQtyTitle">Modificar insumo</h2>
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

                <div style="margin: 1.25rem 0; text-align: left;">
                    <label class="form-label" for="editCostoInput">Costo unitario ($) <span class="req">*</span></label>
                    <input type="number" class="input-control" id="editCostoInput" name="opiCostoUnitario" min="0" step="0.01" placeholder="0.00" required>
                    <p class="form-hint">Costo por unidad de este insumo en la partida.</p>
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
    <!-- ========================================================================= -->
    <!-- MODAL: MODIFICAR AVANCE DE LA PARTIDA                                      -->
    <!-- ========================================================================= -->
    <div class="modal-backdrop" id="modalAvance" role="dialog" aria-modal="true" aria-labelledby="modalAvanceTitle">
        <div class="modal modal-sm">
            <div class="modal-head">
                <div>
                    <h2 id="modalAvanceTitle">Avance de la Partida</h2>
                    <p style="color: var(--muted); font-size: .84rem;">Ajusta el porcentaje de avance físico</p>
                </div>
                <button type="button" class="modal-close js-close" aria-label="Cerrar">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <div class="alert alert-error modal-error" id="modalAvanceError" role="alert">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span id="modalAvanceErrorText"></span>
            </div>

            <form method="POST" action="partida.php?id=<?= $idPartida ?><?= $seccionActiva === 'fotos' ? '&seccion=fotos' : '' ?>" id="formAvance">
                <input type="hidden" name="action" value="actualizar_avance">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="idPartidaObra" value="<?= $idPartida ?>">

                <!-- Vista previa en vivo -->
                <div style="margin: 1.25rem 0 1.5rem;">
                    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: .6rem;">
                        <span style="font-size: .78rem; text-transform: uppercase; letter-spacing: .05em; color: var(--subtle); font-weight: 700;">Vista previa</span>
                        <strong id="previewAvanceLabel" style="font-family: 'Outfit', sans-serif; font-size: 1.25rem; color: #2ae083;"><?= $porcentajeAvance ?>%</strong>
                    </div>
                    <div class="progress-pill-track preview-track" style="width: 100%; max-width: 100%; margin: 0;">
                        <div class="progress-pill-fill" id="previewAvanceFill" style="width: <?= $porcentajeAvance ?>%;">
                            <span class="progress-pill-text in-fill" id="previewAvanceTextIn" style="<?= $porcentajeAvance < 14 ? 'display:none;' : '' ?>"><?= $porcentajeAvance ?>%</span>
                        </div>
                        <span class="progress-pill-text outside-fill" id="previewAvanceTextOut" style="<?= $porcentajeAvance >= 14 ? 'display:none;' : '' ?>"><?= $porcentajeAvance ?>%</span>
                    </div>
                </div>

                <!-- Input numérico y slider -->
                <div style="margin-bottom: 1.35rem;">
                    <div style="display: flex; gap: 1rem; align-items: flex-end; margin-bottom: 1rem;">
                        <div style="flex: 1;">
                            <label class="form-label" for="inputAvanceRange">Deslizar para ajustar</label>
                            <input type="range" class="range-slider" id="inputAvanceRange" min="0" max="100" step="1" value="<?= $porcentajeAvance ?>" style="margin-top: .4rem;">
                        </div>
                        <div style="width: 110px;">
                            <label class="form-label" for="inputAvanceVal">Valor (%)</label>
                            <div style="position: relative;">
                                <input type="number" class="input-control" id="inputAvanceVal" name="pdoAvance" min="0" max="100" step="1" value="<?= $porcentajeAvance ?>" required style="padding-right: 2rem; font-weight: 700; text-align: right; font-size: 1.05rem;">
                                <span style="position: absolute; right: .75rem; top: 50%; transform: translateY(-50%); color: var(--muted); font-weight: 800; pointer-events: none;">%</span>
                            </div>
                        </div>
                    </div>

                    <!-- Botones de acceso rápido -->
                    <label class="form-label" style="margin-bottom: .45rem;">Accesos rápidos</label>
                    <div class="quick-pct-grid">
                        <button type="button" class="btn-quick-pct" data-val="0">0%</button>
                        <button type="button" class="btn-quick-pct" data-val="25">25%</button>
                        <button type="button" class="btn-quick-pct" data-val="50">50%</button>
                        <button type="button" class="btn-quick-pct" data-val="75">75%</button>
                        <button type="button" class="btn-quick-pct" data-val="100">100%</button>
                    </div>
                </div>

                <div class="modal-actions" style="justify-content: flex-end; gap: .75rem;">
                    <button type="button" class="btn btn-ghost js-close" style="padding: .8rem 1.3rem;">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btnGuardarAvance" style="width: auto; padding: .8rem 1.8rem; background: linear-gradient(135deg, #10b981, #059669); border-color: #10b981;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        <span>Guardar avance</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL: EDITAR PARTIDA (TRASLADADO DE OBRA.PHP)                             -->
    <!-- ========================================================================= -->
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
    <!-- ========================================================================= -->
    <!-- MODAL: AGREGAR FOTOGRAFÍA (SUBIR ARCHIVO O TOMAR CON CÁMARA)               -->
    <!-- ========================================================================= -->
    <?php if ($perm['agregar'] || $perm['modificar']): ?>
    <div class="modal-backdrop" id="modalAgregarFoto" role="dialog" aria-modal="true" aria-labelledby="modalFotoTitle">
        <div class="modal" style="max-width: 580px;">
            <div class="modal-head">
                <div>
                    <h2 id="modalFotoTitle">Agregar fotografía</h2>
                    <p>Partida: <?= htmlspecialchars($partida['pdoClave']) ?> &bull; <?= htmlspecialchars($partida['pdoDescripcion']) ?></p>
                </div>
                <button type="button" class="modal-close js-close" aria-label="Cerrar">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <div class="alert alert-error modal-error" id="modalFotoError" role="alert">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span id="modalFotoErrorText"></span>
            </div>

            <!-- Tabs: Subir Archivo vs Tomar con Cámara -->
            <div class="tab-pills" role="tablist">
                <button type="button" class="tab-pill active" id="tabFotoArchivo" role="tab">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: .3rem;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    Adjuntar archivo
                </button>
                <button type="button" class="tab-pill" id="tabFotoCamara" role="tab">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: .3rem;"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    Tomar con cámara
                </button>
            </div>

            <form method="POST" action="partida.php?id=<?= $idPartida ?>" id="formFoto" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="action" value="agregar_foto">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="idPartidaObra" value="<?= $idPartida ?>">
                <input type="hidden" name="fotoBase64" id="fotoBase64Input" value="">

                <!-- Panel 1: Subir Archivo -->
                <div id="panelFotoArchivo">
                    <div class="dropzone-box" id="dropzoneFoto">
                        <div class="dropzone-icon">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                        </div>
                        <p style="font-weight: 600; color: #fff; margin-bottom: .25rem; font-size: .95rem;">Arrastra tu fotografía aquí o haz clic para explorar</p>
                        <p style="font-size: .78rem; color: var(--muted); margin: 0;">Formatos JPG, PNG, WEBP (se optimizará y comprimirá automáticamente)</p>
                    </div>
                    <input type="file" id="inpFotoFile" accept="image/*" style="display: none;">
                </div>

                <!-- Panel 2: Tomar Fotografía con la Cámara -->
                <div id="panelFotoCamara" style="display: none;">
                    <div class="camera-box" id="cameraBox">
                        <video id="cameraVideo" class="camera-video" autoplay playsinline muted></video>
                        <div id="cameraStatusMsg" style="position: absolute; text-align: center; color: var(--muted); padding: 1.5rem; display: none;">
                            <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: .5rem; color: #fbbf24;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <p id="cameraStatusTxt" style="font-size: .88rem; color: #e4e4e7; margin: 0 0 .75rem;">Solicitando permiso de cámara...</p>
                            <button type="button" class="btn btn-ghost" id="btnRetryCam" style="padding: .4rem .9rem; font-size: .8rem;">Reintentar</button>
                        </div>
                        <div class="camera-controls-bar" id="cameraControls">
                            <button type="button" class="btn-cam-ctrl" id="btnCamSwitch" title="Cambiar cámara (frontal/trasera)">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0-4.418-3.582-8-8-8s-8 3.582-8 8c0 1.954.702 3.744 1.872 5.143L4 18h5v-5l-1.854 1.854C6.386 13.824 6 12.474 6 10c0-3.314 2.686-6 6-6s6 2.686 6 6c0 2.474-.386 3.824-1.146 4.854L15 13v5h5l-1.872-2.857C19.298 13.744 20 11.954 20 10z"/></svg>
                            </button>
                            <button type="button" class="btn-shutter" id="btnShutter" title="Tomar fotografía" aria-label="Tomar fotografía"></button>
                        </div>
                    </div>
                </div>

                <!-- Preview de la foto capturada o seleccionada -->
                <div id="panelFotoPreview" style="display: none; margin-top: .75rem;">
                    <div class="photo-preview-wrap">
                        <img id="photoPreviewImg" class="photo-preview-img" alt="Vista previa">
                    </div>
                    <div class="preview-meta">
                        <span id="previewInfo">Foto lista para guardar</span>
                        <button type="button" class="btn-act" id="btnCambiarFoto" style="padding: .35rem .75rem; font-size: .78rem;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 4v6h6"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                            <span>Cambiar / Reintentar</span>
                        </button>
                    </div>
                </div>

                <!-- Selector de Tipo de Fotografía (obrapartidasfotostipo) -->
                <div style="margin-top: 1.35rem;">
                    <label class="form-label" for="pdfTipoSelect">Tipo de fotografía <span class="req">*</span></label>
                    <select class="input-control" id="pdfTipoSelect" name="pdfTipo" required>
                        <option value="">-- Selecciona el tipo de fotografía --</option>
                        <?php foreach ($catalogoTiposFotos as $ctf): ?>
                            <option value="<?= (int)$ctf['pdfTipo'] ?>">
                                <?= htmlspecialchars($ctf['pdftDescripcion']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-hint">Clasificación de la imagen: evidencia previa (Antes de), avance en curso (Actual) o conclusión (Después de).</p>
                </div>

                <!-- Descripción de la fotografía (pdfDescripcion) -->
                <div style="margin-top: 1.15rem;">
                    <label class="form-label" for="pdfDescripcion">
                        Descripción de la fotografía
                        <span class="char-count" id="fotoDescCount">0/400</span>
                    </label>
                    <textarea class="input-control" id="pdfDescripcion" name="pdfDescripcion" maxlength="400" rows="3" placeholder="Describe brevemente lo que muestra la fotografía (ej. Armado de varilla, colado de losa, avance al 50%)..."></textarea>
                    <p class="form-hint">Pequeña descripción u observación sobre la fotografía (máx. 400 caracteres).</p>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost js-close" style="padding: .8rem 1.3rem;">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btnGuardarFoto" style="width: auto; padding: .8rem 1.8rem;">
                        <span class="spinner" aria-hidden="true"></span>
                        <span class="label">Guardar fotografía</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL: ELIMINAR FOTOGRAFÍA                                                -->
    <!-- ========================================================================= -->
    <div class="modal-backdrop" id="modalEliminarFoto" role="alertdialog" aria-modal="true" aria-labelledby="modalEliminarFotoTitle">
        <div class="modal modal-sm">
            <div class="danger-icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <h2 id="modalEliminarFotoTitle" style="font-size: 1.2rem; color: #fff; margin-bottom: .5rem;">¿Eliminar esta fotografía?</h2>
            <p style="color: var(--muted); font-size: .88rem; line-height: 1.55;" id="eliminarFotoTexto"></p>
            <form method="POST" action="partida.php?id=<?= $idPartida ?>">
                <input type="hidden" name="action" value="eliminar_foto">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="idPartidaObra" value="<?= $idPartida ?>">
                <input type="hidden" name="idPartidaFoto" id="eliminarFotoId" value="0">
                <div class="modal-actions" style="justify-content: center;">
                    <button type="button" class="btn btn-ghost js-close" style="padding: .8rem 1.3rem;">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-danger" style="width: auto; padding: .8rem 1.6rem;">Sí, eliminar</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ========================================================================= -->
    <!-- MODAL: LIGHTBOX / PANTALLA COMPLETA DE FOTO                               -->
    <!-- ========================================================================= -->
    <div class="lightbox-modal" id="modalLightbox" role="dialog" aria-modal="true" aria-label="Visualizador de Fotografía">
        <div class="lightbox-topbar">
            <div>
                <strong id="lightboxTitle" style="color: #fff; font-size: 1.05rem; display: block;">Fotografía</strong>
                <span id="lightboxDate" style="color: var(--muted); font-size: .82rem;"></span>
                <p id="lightboxDesc" style="color: #f4f4f5; font-size: .88rem; margin: .35rem 0 0; display: none;"></p>
            </div>
            <div style="display: flex; gap: .75rem; align-items: center;">
                <a href="#" id="lightboxDownload" download="fotografia-partida.jpg" class="btn-act" style="padding: .5rem 1rem; font-size: .82rem;" title="Descargar imagen">
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

        // ---------------- Control de Modales ----------------
        const openModal = m => { if (!m) return; m.classList.add('open'); document.body.style.overflow = 'hidden'; };
        const closeModal = m => {
            if (!m) return;
            m.classList.remove('open');
            document.body.style.overflow = '';
            closeAllCalendars();
            if (m.id === 'modalAgregarFoto' && typeof stopCamera === 'function') {
                stopCamera();
            }
        };
        document.querySelectorAll('.modal-backdrop').forEach(m => {
            m.addEventListener('mousedown', e => { if (e.target === m) closeModal(m); });
            m.querySelectorAll('.js-close').forEach(b => b.addEventListener('click', () => closeModal(m)));
        });
        document.addEventListener('keydown', e => {
            if (e.key !== 'Escape') return;
            if (document.querySelector('.calendar.open')) { closeAllCalendars(); return; }
            const lb = document.getElementById('modalLightbox');
            if (lb && lb.classList.contains('open')) {
                lb.classList.remove('open');
                document.body.style.overflow = '';
                const img = document.getElementById('lightboxImg');
                if (img) img.src = '';
                return;
            }
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
        const totalFooter = document.getElementById('totalCostosInsumos');
        const tfoot = document.querySelector('#tablaInsumos tfoot');
        if (searchInput && rows.length) {
            const fmtMoney = n => '$' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            searchInput.addEventListener('input', () => {
                const q = searchInput.value.trim().toLowerCase();
                let visible = 0;
                let visibleTotal = 0;
                rows.forEach(r => {
                    const text = r.dataset.search || '';
                    const match = !q || text.includes(q);
                    r.style.display = match ? '' : 'none';
                    if (match) {
                        visible++;
                        visibleTotal += parseFloat(r.dataset.costoTotal || 0);
                    }
                });
                if (noResults) noResults.style.display = visible === 0 ? 'block' : 'none';
                if (tfoot) tfoot.style.display = visible === 0 ? 'none' : '';
                if (totalFooter) totalFooter.textContent = fmtMoney(visibleTotal);
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
            const inpCosto = document.getElementById('opiCostoUnitario');
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
                    if (inpCosto) inpCosto.value = '';
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
                const costoVal = inpCosto ? inpCosto.value.trim() : '';
                const costo = Number(costoVal);

                if (!qty || qty <= 0) {
                    e.preventDefault();
                    errText.textContent = 'Ingresa una cantidad entera mayor a 0.';
                    errBox.classList.add('show');
                    inpQty.focus();
                    return;
                }

                if (costoVal === '' || isNaN(costo) || costo < 0) {
                    e.preventDefault();
                    errText.textContent = 'Ingresa un costo unitario válido mayor o igual a 0.';
                    errBox.classList.add('show');
                    if (inpCosto) inpCosto.focus();
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

        // ---------------- Modal: Modificar Insumo ----------------
        const modalEditarQty = document.getElementById('modalEditarQty');
        if (modalEditarQty) {
            const editId = document.getElementById('editQtyId');
            const editInput = document.getElementById('editQtyInput');
            const editCosto = document.getElementById('editCostoInput');
            const editDesc = document.getElementById('modalQtyDesc');

            document.querySelectorAll('.js-editar-qty').forEach(btn => {
                btn.addEventListener('click', () => {
                    editId.value = btn.dataset.id;
                    editDesc.textContent = btn.dataset.desc;
                    editInput.value = btn.dataset.qty;
                    if (editCosto) {
                        const c = parseFloat(btn.dataset.costo);
                        editCosto.value = isNaN(c) ? '0.00' : c.toFixed(2);
                    }
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

        // ---------------- Menú 3 puntos de Partida ----------------
        const btnHeroDots = document.getElementById('btnHeroDots');
        const heroDropdownMenu = document.getElementById('heroDropdownMenu');
        if (btnHeroDots && heroDropdownMenu) {
            btnHeroDots.addEventListener('click', (e) => {
                e.stopPropagation();
                const isOpen = heroDropdownMenu.classList.toggle('open');
                btnHeroDots.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });

            document.addEventListener('click', (e) => {
                if (!heroDropdownMenu.contains(e.target) && e.target !== btnHeroDots) {
                    heroDropdownMenu.classList.remove('open');
                    btnHeroDots.setAttribute('aria-expanded', 'false');
                }
            });

            heroDropdownMenu.querySelectorAll('.hero-dropdown-item').forEach(item => {
                item.addEventListener('click', () => {
                    heroDropdownMenu.classList.remove('open');
                    btnHeroDots.setAttribute('aria-expanded', 'false');
                });
            });
        }

        // ---------------- Modal: Avance de la Partida ----------------
        const modalAvance = document.getElementById('modalAvance');
        const btnAvancePartida = document.getElementById('btnAvancePartida');
        if (modalAvance) {
            const inputVal = document.getElementById('inputAvanceVal');
            const inputRng = document.getElementById('inputAvanceRange');
            const prevFill = document.getElementById('previewAvanceFill');
            const prevTxtIn = document.getElementById('previewAvanceTextIn');
            const prevTxtOut = document.getElementById('previewAvanceTextOut');
            const prevLabel = document.getElementById('previewAvanceLabel');
            const formAvance = document.getElementById('formAvance');
            const errBoxAvance = document.getElementById('modalAvanceError');

            function syncAvance(val) {
                let num = parseInt(val, 10);
                if (isNaN(num)) num = 0;
                num = Math.max(0, Math.min(100, num));
                if (inputVal && document.activeElement !== inputVal) inputVal.value = num;
                if (inputRng && document.activeElement !== inputRng) inputRng.value = num;
                if (prevLabel) prevLabel.textContent = `${num}%`;
                if (prevFill) prevFill.style.width = `${num}%`;
                if (prevTxtIn) {
                    prevTxtIn.textContent = `${num}%`;
                    prevTxtIn.style.display = num >= 14 ? '' : 'none';
                }
                if (prevTxtOut) {
                    prevTxtOut.textContent = `${num}%`;
                    prevTxtOut.style.display = num < 14 ? '' : 'none';
                }
            }

            if (inputVal) {
                inputVal.addEventListener('input', () => {
                    let num = parseInt(inputVal.value, 10);
                    if (isNaN(num)) num = 0;
                    num = Math.max(0, Math.min(100, num));
                    if (inputRng) inputRng.value = num;
                    if (prevLabel) prevLabel.textContent = `${num}%`;
                    if (prevFill) prevFill.style.width = `${num}%`;
                    if (prevTxtIn) {
                        prevTxtIn.textContent = `${num}%`;
                        prevTxtIn.style.display = num >= 14 ? '' : 'none';
                    }
                    if (prevTxtOut) {
                        prevTxtOut.textContent = `${num}%`;
                        prevTxtOut.style.display = num < 14 ? '' : 'none';
                    }
                });
            }
            if (inputRng) {
                inputRng.addEventListener('input', () => {
                    syncAvance(inputRng.value);
                });
            }

            document.querySelectorAll('.btn-quick-pct').forEach(btn => {
                btn.addEventListener('click', () => {
                    syncAvance(btn.dataset.val);
                    if (inputVal) inputVal.value = btn.dataset.val;
                });
            });

            function abrirModalAvance() {
                if (errBoxAvance) errBoxAvance.classList.remove('show');
                openModal(modalAvance);
                setTimeout(() => {
                    if (inputVal) {
                        inputVal.focus();
                        inputVal.select();
                    }
                }, 120);
            }

            if (btnAvancePartida) {
                btnAvancePartida.addEventListener('click', abrirModalAvance);
            }
        }

        // =========================================================================
        // SECCIÓN FOTOGRAFÍAS: COMPRESIÓN, CÁMARA, DROPZONE, LIGHTBOX Y FILTROS
        // =========================================================================

        // 1. Compresor de imagen en cliente (Canvas) con calidad sin pérdida perceptible
        function compressImage(source, maxWidth = 1920, maxHeight = 1920, quality = 0.85) {
            return new Promise((resolve, reject) => {
                const img = new Image();
                img.onload = () => {
                    let width = img.naturalWidth || img.width;
                    let height = img.naturalHeight || img.height;

                    if (width > maxWidth || height > maxHeight) {
                        if (width / height > maxWidth / maxHeight) {
                            height = Math.round((height * maxWidth) / width);
                            width = maxWidth;
                        } else {
                            width = Math.round((width * maxHeight) / height);
                            height = maxHeight;
                        }
                    }

                    const canvas = document.createElement('canvas');
                    canvas.width = width;
                    canvas.height = height;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0, width, height);

                    const dataUrl = canvas.toDataURL('image/jpeg', quality);
                    const estKb = Math.round((dataUrl.length * 3) / 4 / 1024);
                    resolve({ dataUrl, width, height, estKb });
                };
                img.onerror = () => reject(new Error('No se pudo procesar la imagen seleccionada.'));

                if (typeof source === 'string') {
                    img.src = source;
                } else if (source instanceof Blob || source instanceof File) {
                    const reader = new FileReader();
                    reader.onload = e => { img.src = e.target.result; };
                    reader.onerror = () => reject(new Error('Error al leer el archivo.'));
                    reader.readAsDataURL(source);
                } else {
                    reject(new Error('Formato de imagen inválido.'));
                }
            });
        }

        // 2. Control de Cámara (navigator.mediaDevices.getUserMedia)
        let cameraStream = null;
        let currentFacingMode = 'environment'; // Cámara trasera por defecto en móviles

        async function startCamera() {
            stopCamera();
            const video = document.getElementById('cameraVideo');
            const statusMsg = document.getElementById('cameraStatusMsg');
            const statusTxt = document.getElementById('cameraStatusTxt');
            const controls = document.getElementById('cameraControls');
            if (!video) return;

            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                if (statusMsg) {
                    statusMsg.style.display = 'block';
                    statusTxt.textContent = 'Tu navegador o entorno no soporta captura directa de cámara. Por favor utiliza la opción "Adjuntar archivo".';
                }
                if (controls) controls.style.display = 'none';
                return;
            }

            if (statusMsg) {
                statusMsg.style.display = 'block';
                statusTxt.textContent = 'Solicitando permiso para usar la cámara...';
            }
            if (controls) controls.style.display = 'none';

            try {
                const constraints = {
                    video: {
                        facingMode: { ideal: currentFacingMode },
                        width: { ideal: 1920 },
                        height: { ideal: 1080 }
                    },
                    audio: false
                };
                cameraStream = await navigator.mediaDevices.getUserMedia(constraints);
                video.srcObject = cameraStream;
                await video.play();
                if (statusMsg) statusMsg.style.display = 'none';
                if (controls) controls.style.display = 'flex';
            } catch (err) {
                console.error('Camera error:', err);
                if (statusMsg) {
                    statusMsg.style.display = 'block';
                    if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
                        statusTxt.textContent = 'Permiso denegado. Concede permiso de cámara en la configuración o barra de tu navegador para tomar fotos directamente.';
                    } else if (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError') {
                        statusTxt.textContent = 'No se encontró cámara disponible en este dispositivo.';
                    } else {
                        statusTxt.textContent = 'No se pudo iniciar la cámara: ' + (err.message || 'Error desconocido.');
                    }
                }
                if (controls) controls.style.display = 'none';
            }
        }

        function stopCamera() {
            if (cameraStream) {
                cameraStream.getTracks().forEach(track => track.stop());
                cameraStream = null;
            }
            const video = document.getElementById('cameraVideo');
            if (video) video.srcObject = null;
        }

        // 3. Modal Agregar Foto
        const modalAgregarFoto = document.getElementById('modalAgregarFoto');
        if (modalAgregarFoto) {
            const formFoto = document.getElementById('formFoto');
            const tabArchivo = document.getElementById('tabFotoArchivo');
            const tabCamara = document.getElementById('tabFotoCamara');
            const panelArchivo = document.getElementById('panelFotoArchivo');
            const panelCamara = document.getElementById('panelFotoCamara');
            const panelPreview = document.getElementById('panelFotoPreview');
            const dropzone = document.getElementById('dropzoneFoto');
            const inpFile = document.getElementById('inpFotoFile');
            const photoPreviewImg = document.getElementById('photoPreviewImg');
            const previewInfo = document.getElementById('previewInfo');
            const btnCambiarFoto = document.getElementById('btnCambiarFoto');
            const btnShutter = document.getElementById('btnShutter');
            const btnCamSwitch = document.getElementById('btnCamSwitch');
            const btnRetryCam = document.getElementById('btnRetryCam');
            const fotoBase64 = document.getElementById('fotoBase64Input');
            const pdfTipoSel = document.getElementById('pdfTipoSelect');
            const inpFotoDesc = document.getElementById('pdfDescripcion');
            const countFotoDesc = document.getElementById('fotoDescCount');
            const errBox = document.getElementById('modalFotoError');
            const errText = document.getElementById('modalFotoErrorText');
            const btnGuardar = document.getElementById('btnGuardarFoto');

            let modoActivo = 'archivo'; // 'archivo' | 'camara'

            // Contador de caracteres para pdfDescripcion
            if (inpFotoDesc && countFotoDesc) {
                const updFotoCount = () => { countFotoDesc.textContent = `${inpFotoDesc.value.length}/400`; };
                inpFotoDesc.addEventListener('input', updFotoCount);
                updFotoCount();
            }

            function switchModo(modo) {
                modoActivo = modo;
                errBox.classList.remove('show');
                if (modo === 'archivo') {
                    tabArchivo.classList.add('active');
                    tabCamara.classList.remove('active');
                    panelArchivo.style.display = fotoBase64.value ? 'none' : 'block';
                    panelCamara.style.display = 'none';
                    stopCamera();
                } else {
                    tabCamara.classList.add('active');
                    tabArchivo.classList.remove('active');
                    panelArchivo.style.display = 'none';
                    panelCamara.style.display = fotoBase64.value ? 'none' : 'block';
                    if (!fotoBase64.value) {
                        startCamera();
                    }
                }
            }

            function setCapturedPhoto(dataUrl, info) {
                fotoBase64.value = dataUrl;
                photoPreviewImg.src = dataUrl;
                previewInfo.textContent = info || 'Fotografía optimizada lista para guardar';
                panelPreview.style.display = 'block';
                panelArchivo.style.display = 'none';
                panelCamara.style.display = 'none';
                stopCamera();
            }

            function clearCapturedPhoto() {
                fotoBase64.value = '';
                photoPreviewImg.src = '';
                panelPreview.style.display = 'none';
                inpFile.value = '';
                if (modoActivo === 'camara') {
                    panelCamara.style.display = 'block';
                    startCamera();
                } else {
                    panelArchivo.style.display = 'block';
                }
            }

            tabArchivo.addEventListener('click', () => switchModo('archivo'));
            tabCamara.addEventListener('click', () => switchModo('camara'));

            if (btnCambiarFoto) btnCambiarFoto.addEventListener('click', clearCapturedPhoto);
            if (btnRetryCam) btnRetryCam.addEventListener('click', () => startCamera());

            if (btnCamSwitch) {
                btnCamSwitch.addEventListener('click', () => {
                    currentFacingMode = currentFacingMode === 'environment' ? 'user' : 'environment';
                    startCamera();
                });
            }

            // Capturar foto desde la cámara
            if (btnShutter) {
                btnShutter.addEventListener('click', async () => {
                    const video = document.getElementById('cameraVideo');
                    if (!video || !video.videoWidth) return;

                    btnShutter.style.transform = 'scale(0.88)';
                    setTimeout(() => { btnShutter.style.transform = ''; }, 150);

                    const canvas = document.createElement('canvas');
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                    const rawUrl = canvas.toDataURL('image/jpeg', 0.95);

                    try {
                        const comp = await compressImage(rawUrl, 1920, 1920, 0.85);
                        setCapturedPhoto(comp.dataUrl, `Cámara: ${comp.width}x${comp.height} px (${comp.estKb} KB)`);
                    } catch (e) {
                        setCapturedPhoto(rawUrl, 'Foto capturada desde la cámara');
                    }
                });
            }

            // Procesar archivo seleccionado (Dropzone)
            async function handleFile(file) {
                if (!file || !file.type.startsWith('image/')) {
                    errText.textContent = 'Por favor selecciona un archivo de imagen válido (JPG, PNG, WEBP).';
                    errBox.classList.add('show');
                    return;
                }
                errBox.classList.remove('show');
                try {
                    const comp = await compressImage(file, 1920, 1920, 0.85);
                    setCapturedPhoto(comp.dataUrl, `Archivo: ${comp.width}x${comp.height} px (${comp.estKb} KB optimizada)`);
                } catch (e) {
                    errText.textContent = 'Error al procesar la fotografía: ' + e.message;
                    errBox.classList.add('show');
                }
            }

            if (dropzone && inpFile) {
                dropzone.addEventListener('click', () => inpFile.click());
                inpFile.addEventListener('change', () => {
                    if (inpFile.files && inpFile.files[0]) {
                        handleFile(inpFile.files[0]);
                    }
                });

                ['dragenter', 'dragover'].forEach(eventName => {
                    dropzone.addEventListener(eventName, e => {
                        e.preventDefault();
                        dropzone.classList.add('dragover');
                    });
                });
                ['dragleave', 'drop'].forEach(eventName => {
                    dropzone.addEventListener(eventName, e => {
                        e.preventDefault();
                        dropzone.classList.remove('dragover');
                    });
                });
                dropzone.addEventListener('drop', e => {
                    if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
                        handleFile(e.dataTransfer.files[0]);
                    }
                });
            }

            // Abrir modal agregar foto
            document.querySelectorAll('.js-abrir-foto').forEach(btn => {
                btn.addEventListener('click', () => {
                    formFoto.reset();
                    fotoBase64.value = '';
                    photoPreviewImg.src = '';
                    panelPreview.style.display = 'none';
                    if (inpFotoDesc) {
                        inpFotoDesc.value = '';
                        if (countFotoDesc) countFotoDesc.textContent = '0/400';
                    }
                    errBox.classList.remove('show');
                    btnGuardar.classList.remove('loading');
                    switchModo('archivo');
                    openModal(modalAgregarFoto);
                });
            });

            // Envío del formulario
            formFoto.addEventListener('submit', e => {
                errBox.classList.remove('show');
                if (!fotoBase64.value) {
                    e.preventDefault();
                    errText.textContent = 'Debes adjuntar un archivo o tomar una fotografía antes de guardar.';
                    errBox.classList.add('show');
                    return;
                }
                if (!pdfTipoSel.value) {
                    e.preventDefault();
                    errText.textContent = 'Selecciona el tipo de fotografía (Antes de, Después de, Actual).';
                    errBox.classList.add('show');
                    pdfTipoSel.focus();
                    return;
                }
                btnGuardar.classList.add('loading');
            });
        }

        // 4. Modal Eliminar Fotografía
        const modalEliminarFoto = document.getElementById('modalEliminarFoto');
        if (modalEliminarFoto) {
            const elimFotoId = document.getElementById('eliminarFotoId');
            const elimFotoTxt = document.getElementById('eliminarFotoTexto');

            document.querySelectorAll('.js-eliminar-foto').forEach(btn => {
                btn.addEventListener('click', () => {
                    elimFotoId.value = btn.dataset.id;
                    const descInfo = btn.dataset.desc ? `<br><span style="color:#d4d4d8; font-style:italic;">"${btn.dataset.desc}"</span>` : '';
                    elimFotoTxt.innerHTML = `¿Deseas eliminar esta fotografía del tipo <strong>"${btn.dataset.tipo}"</strong>${descInfo} registrada el <strong>${btn.dataset.date}</strong>? Esta acción no se puede deshacer.`;
                    openModal(modalEliminarFoto);
                });
            });
        }

        // 5. Filtros de Fotos (Todas, Antes de, Actual, Después de)
        const filterPills = document.querySelectorAll('#fotoFilters .filter-pill');
        const fotoCards = document.querySelectorAll('#fotosGrid .foto-card');
        if (filterPills.length > 0 && fotoCards.length > 0) {
            filterPills.forEach(pill => {
                pill.addEventListener('click', () => {
                    filterPills.forEach(p => p.classList.remove('active'));
                    pill.classList.add('active');
                    const fil = pill.dataset.filter;
                    fotoCards.forEach(card => {
                        if (fil === 'all' || card.dataset.tipo === fil) {
                            card.style.display = 'flex';
                        } else {
                            card.style.display = 'none';
                        }
                    });
                });
            });
        }

        // 6. Lightbox / Pantalla Completa
        const modalLightbox = document.getElementById('modalLightbox');
        const lightboxImg = document.getElementById('lightboxImg');
        const lightboxTitle = document.getElementById('lightboxTitle');
        const lightboxDate = document.getElementById('lightboxDate');
        const lightboxDesc = document.getElementById('lightboxDesc');
        const lightboxDownload = document.getElementById('lightboxDownload');

        function openLightbox(src, title, date, desc) {
            if (!modalLightbox || !lightboxImg) return;
            lightboxImg.src = src;
            if (lightboxTitle) lightboxTitle.textContent = title || 'Fotografía de Partida';
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
            el.addEventListener('click', (e) => {
                e.stopPropagation();
                openLightbox(el.dataset.src, el.dataset.title, el.dataset.date, el.dataset.desc);
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
            <?php elseif ($formModal === 'foto'): ?>
                document.getElementById('modalFotoErrorText').textContent = <?= json_encode($formError) ?>;
                document.getElementById('modalFotoError').classList.add('show');
                openModal(document.getElementById('modalAgregarFoto'));
            <?php elseif ($formModal === 'avance' && $perm['modificar']): ?>
                document.getElementById('modalAvanceErrorText').textContent = <?= json_encode($formError) ?>;
                document.getElementById('modalAvanceError').classList.add('show');
                openModal(document.getElementById('modalAvance'));
            <?php endif; ?>
        <?php endif; ?>
    })();
    </script>
</body>
</html>
