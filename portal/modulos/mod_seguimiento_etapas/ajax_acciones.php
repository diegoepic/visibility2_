<?php
declare(strict_types=1);
// ajax_acciones.php — mutaciones del módulo Seguimiento por Etapas (solo editor).
// Dispatcher por $_POST['action']. Guardas: sesión, perfil editor, CSRF, pertenencia a empresa.
// Cada acción se audita en formularioQuestion_admin_log.
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Santiago');

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

function jr(array $arr, int $code = 200): void {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['usuario_id'])) { jr(['ok' => false, 'message' => 'Sesión expirada'], 401); }

$conn->set_charset('utf8mb4');
$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);
$id_admin   = (int)$_SESSION['usuario_id'];
$perfil     = strtolower((string)($_SESSION['perfil_nombre'] ?? ''));

if ($perfil !== 'editor') { jr(['ok' => false, 'message' => 'Solo un perfil editor puede realizar esta acción.'], 403); }

// CSRF
$csrfSess = (string)($_SESSION['csrf_token'] ?? '');
$csrfPost = (string)($_POST['csrf_token'] ?? '');
if ($csrfSess === '' || $csrfPost === '' || !hash_equals($csrfSess, $csrfPost)) {
    jr(['ok' => false, 'message' => 'Token CSRF inválido.'], 419);
}

$ETAPAS = ['armado', 'entregado', 'implementado', 'retirado'];
// MC (division 1) opera sobre cualquier empresa; el resto solo su empresa.
$isMC = ((int)($_SESSION['division_id'] ?? 0) === 1);

/* ---------- Helpers de pertenencia ---------- */
// Devuelven también la empresa de la campaña (camp_empresa / id_empresa) para validar usuarios/locales
// contra esa empresa, no la del admin (clave para MC).
function fqInfo(mysqli $conn, int $idFQ, int $empresa_id, bool $isMC): ?array {
    $sql = "SELECT fq.id, fq.id_local, fq.id_formulario, fq.id_usuario, fq.material,
                   fq.etapa_material, fq.valor_propuesto, f.id_empresa AS camp_empresa
            FROM formularioQuestion fq
            INNER JOIN formulario f ON f.id = fq.id_formulario
            WHERE fq.id = ? AND f.modalidad = 'implementacion_por_etapas' "
          . ($isMC ? "" : "AND f.id_empresa = ? ") . "LIMIT 1";
    $st = $conn->prepare($sql);
    if ($isMC) { $st->bind_param('i', $idFQ); }
    else       { $st->bind_param('ii', $idFQ, $empresa_id); }
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}
function formInfo(mysqli $conn, int $idForm, int $empresa_id, bool $isMC): ?array {
    $sql = "SELECT id, id_division, id_empresa FROM formulario WHERE id = ? AND modalidad = 'implementacion_por_etapas' "
          . ($isMC ? "" : "AND id_empresa = ? ") . "LIMIT 1";
    $st = $conn->prepare($sql);
    if ($isMC) { $st->bind_param('i', $idForm); }
    else       { $st->bind_param('ii', $idForm, $empresa_id); }
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}
function usuarioValido(mysqli $conn, int $idUsuario, int $empresa_id): bool {
    $st = $conn->prepare("SELECT 1 FROM usuario WHERE id = ? AND id_empresa = ? AND activo = 1 AND id_perfil = 3 LIMIT 1");
    $st->bind_param('ii', $idUsuario, $empresa_id);
    $st->execute();
    $ok = $st->get_result()->num_rows > 0;
    $st->close();
    return $ok;
}
function audit(mysqli $conn, string $accion, int $id_admin, array $payload, int $afectados): void {
    unset($payload['csrf_token']);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $st = $conn->prepare("INSERT INTO formularioQuestion_admin_log (accion, id_admin, payload_json, total_afectados, created_at) VALUES (?,?,?,?,NOW())");
    if ($st) { $st->bind_param('sisi', $accion, $id_admin, $json, $afectados); @$st->execute(); $st->close(); }
}
function resolverMaterialId(mysqli $conn, string $nombre, int $idDivision): int {
    $st = $conn->prepare("SELECT id FROM material WHERE nombre = ? AND id_division = ? LIMIT 1");
    $st->bind_param('si', $nombre, $idDivision);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ? (int)$row['id'] : 0;
}
function fechaPropuestaLocal(mysqli $conn, int $idForm, int $idLocal): string {
    $st = $conn->prepare("SELECT fechaPropuesta FROM formularioQuestion WHERE id_formulario = ? AND id_local = ? AND fechaPropuesta IS NOT NULL LIMIT 1");
    $st->bind_param('ii', $idForm, $idLocal);
    $st->execute();
    $st->bind_result($fp);
    $val = ($st->fetch() && $fp) ? (string)$fp : '';
    $st->close();
    return $val !== '' ? $val : date('Y-m-d');
}

$action = (string)($_POST['action'] ?? '');

try {
    switch ($action) {

        /* ============ RECARGAR (reactivar) MATERIAL ============ */
        case 'recargar_material': {
            $idFQ = (int)($_POST['idFQ'] ?? 0);
            $fq = fqInfo($conn, $idFQ, $empresa_id, $isMC);
            if (!$fq) jr(['ok' => false, 'message' => 'Material no encontrado.'], 404);

            $etapaDestino = trim((string)($_POST['etapa_destino'] ?? ''));
            $etapa = in_array($etapaDestino, ['armado', 'entregado'], true) ? $etapaDestino : null;
            $fp = trim((string)($_POST['fechaPropuesta'] ?? '')) ?: date('Y-m-d');

            $st = $conn->prepare("UPDATE formularioQuestion
                SET etapa_material = ?, valor = '0', countVisita = 0, fechaVisita = NULL,
                    fechaPropuesta = ?, pregunta = ''
                WHERE id = ?");
            $st->bind_param('ssi', $etapa, $fp, $idFQ);
            $st->execute();
            $aff = $st->affected_rows;
            $st->close();

            audit($conn, 'recargar_material', $id_admin, $_POST, $aff);
            jr(['ok' => true, 'message' => 'Material reactivado. Volverá a aparecer en la ruta del ejecutor.']);
        }

        /* ============ RECARGAR (reactivar) LOCAL completo ============ */
        case 'recargar_local': {
            $idForm  = (int)($_POST['id_formulario'] ?? 0);
            $idLocal = (int)($_POST['id_local'] ?? 0);
            if (!formInfo($conn, $idForm, $empresa_id, $isMC)) jr(['ok' => false, 'message' => 'Campaña no válida.'], 404);
            if ($idLocal <= 0) jr(['ok' => false, 'message' => 'Local inválido.'], 400);

            $fp = trim((string)($_POST['fechaPropuesta'] ?? '')) ?: date('Y-m-d');
            $st = $conn->prepare("UPDATE formularioQuestion
                SET etapa_material = NULL, valor = '0', countVisita = 0, fechaVisita = NULL,
                    fechaPropuesta = ?, pregunta = ''
                WHERE id_formulario = ? AND id_local = ?");
            $st->bind_param('sii', $fp, $idForm, $idLocal);
            $st->execute();
            $aff = $st->affected_rows;
            $st->close();

            audit($conn, 'recargar_local', $id_admin, $_POST, $aff);
            jr(['ok' => true, 'message' => "Local reactivado ({$aff} materiales)."]);
        }

        /* ============ AGREGAR MATERIAL (nuevo) ============ */
        case 'agregar_material': {
            $idForm  = (int)($_POST['id_formulario'] ?? 0);
            $idLocal = (int)($_POST['id_local'] ?? 0);
            $idUser  = (int)($_POST['id_usuario'] ?? 0);
            $material = trim((string)($_POST['material'] ?? ''));
            $valorProp = trim((string)($_POST['valor_propuesto'] ?? ''));

            $form = formInfo($conn, $idForm, $empresa_id, $isMC);
            if (!$form) jr(['ok' => false, 'message' => 'Campaña no válida.'], 404);
            $empObj = (int)$form['id_empresa']; // empresa de la campaña (para validar ejecutor/local)
            if ($idLocal <= 0 || $material === '') jr(['ok' => false, 'message' => 'Local y material son obligatorios.'], 400);
            if (!ctype_digit($valorProp)) jr(['ok' => false, 'message' => 'Valor propuesto debe ser numérico.'], 400);
            if ($idUser <= 0 || !usuarioValido($conn, $idUser, $empObj)) jr(['ok' => false, 'message' => 'Ejecutor inválido.'], 400);
            if (resolverMaterialId($conn, $material, (int)$form['id_division']) <= 0) {
                jr(['ok' => false, 'message' => 'El material no existe en el catálogo de la división.'], 400);
            }

            // Idempotente: si ya existe para ese user/local/material, no duplicar.
            $st = $conn->prepare("SELECT id FROM formularioQuestion WHERE id_formulario = ? AND id_local = ? AND id_usuario = ? AND material = ? LIMIT 1");
            $st->bind_param('iiis', $idForm, $idLocal, $idUser, $material);
            $st->execute();
            $exist = $st->get_result()->fetch_assoc();
            $st->close();
            if ($exist) jr(['ok' => true, 'message' => 'El material ya estaba asignado a ese ejecutor/local.', 'idNuevo' => (int)$exist['id']]);

            $fp = trim((string)($_POST['fechaPropuesta'] ?? '')) ?: fechaPropuestaLocal($conn, $idForm, $idLocal);
            $st = $conn->prepare("INSERT INTO formularioQuestion
                (id_formulario, id_usuario, id_local, material, valor_propuesto, valor, observacion, fechaVisita, fechaPropuesta, countVisita, estado, pregunta)
                VALUES (?, ?, ?, ?, ?, '0', '', NULL, ?, 0, 0, '')");
            $st->bind_param('iiisss', $idForm, $idUser, $idLocal, $material, $valorProp, $fp);
            $st->execute();
            $idNuevo = (int)$st->insert_id;
            $st->close();

            audit($conn, 'agregar_material', $id_admin, $_POST, 1);
            jr(['ok' => true, 'message' => 'Material agregado.', 'idNuevo' => $idNuevo]);
        }

        /* ============ AGREGAR LOCAL (nuevo) con N materiales ============ */
        case 'agregar_local': {
            $idForm  = (int)($_POST['id_formulario'] ?? 0);
            $idLocal = (int)($_POST['id_local'] ?? 0);
            $idUser  = (int)($_POST['id_usuario'] ?? 0);
            $fp      = trim((string)($_POST['fechaPropuesta'] ?? '')) ?: date('Y-m-d');
            // El front envía materiales_json (string JSON); se acepta también materiales[] como fallback.
            $mats = $_POST['materiales'] ?? [];
            if (isset($_POST['materiales_json'])) {
                $decoded = json_decode((string)$_POST['materiales_json'], true);
                if (is_array($decoded)) { $mats = $decoded; }
            }

            $form = formInfo($conn, $idForm, $empresa_id, $isMC);
            if (!$form) jr(['ok' => false, 'message' => 'Campaña no válida.'], 404);
            $empObj = (int)$form['id_empresa']; // empresa de la campaña
            if ($idUser <= 0 || !usuarioValido($conn, $idUser, $empObj)) jr(['ok' => false, 'message' => 'Ejecutor inválido.'], 400);
            if (!is_array($mats) || count($mats) === 0) jr(['ok' => false, 'message' => 'Debes indicar al menos un material.'], 400);

            // Validar que el local pertenece a la empresa de la campaña.
            $st = $conn->prepare("SELECT id FROM local WHERE id = ? AND id_empresa = ? LIMIT 1");
            $st->bind_param('ii', $idLocal, $empObj);
            $st->execute();
            $okLocal = $st->get_result()->num_rows > 0;
            $st->close();
            if (!$okLocal) jr(['ok' => false, 'message' => 'Local inválido.'], 400);

            $idDiv = (int)$form['id_division'];
            $insertados = 0;
            $conn->begin_transaction();
            try {
                $stIns = $conn->prepare("INSERT INTO formularioQuestion
                    (id_formulario, id_usuario, id_local, material, valor_propuesto, valor, observacion, fechaVisita, fechaPropuesta, countVisita, estado, pregunta)
                    VALUES (?, ?, ?, ?, ?, '0', '', NULL, ?, 0, 0, '')");
                $stChk = $conn->prepare("SELECT id FROM formularioQuestion WHERE id_formulario = ? AND id_local = ? AND id_usuario = ? AND material = ? LIMIT 1");
                foreach ($mats as $m) {
                    $matName = trim((string)($m['material'] ?? ''));
                    $vp      = trim((string)($m['valor_propuesto'] ?? ''));
                    if ($matName === '' || !ctype_digit($vp)) continue;
                    if (resolverMaterialId($conn, $matName, $idDiv) <= 0) continue;
                    $stChk->bind_param('iiis', $idForm, $idLocal, $idUser, $matName);
                    $stChk->execute();
                    if ($stChk->get_result()->num_rows > 0) continue; // ya existe
                    $stIns->bind_param('iiisss', $idForm, $idUser, $idLocal, $matName, $vp, $fp);
                    $stIns->execute();
                    $insertados++;
                }
                $stChk->close();
                $stIns->close();
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                jr(['ok' => false, 'message' => 'Error al agregar local: ' . $e->getMessage()], 500);
            }

            audit($conn, 'agregar_local', $id_admin, $_POST, $insertados);
            jr(['ok' => true, 'message' => "Local agregado ({$insertados} materiales)."]);
        }

        /* ============ EDITAR GESTIÓN (etapa / valor / observación) ============ */
        case 'editar_gestion': {
            $idFQ = (int)($_POST['idFQ'] ?? 0);
            $fq = fqInfo($conn, $idFQ, $empresa_id, $isMC);
            if (!$fq) jr(['ok' => false, 'message' => 'Material no encontrado.'], 404);

            $etapaIn = trim((string)($_POST['etapa_material'] ?? ''));
            $etapa = ($etapaIn === '') ? null : (in_array($etapaIn, $ETAPAS, true) ? $etapaIn : null);
            if ($etapaIn !== '' && $etapa === null) jr(['ok' => false, 'message' => 'Etapa inválida.'], 400);

            $valorIn = trim((string)($_POST['valor'] ?? ''));
            if ($valorIn !== '' && !ctype_digit($valorIn)) jr(['ok' => false, 'message' => 'Valor debe ser numérico.'], 400);
            $valor = ($valorIn === '') ? null : $valorIn;

            $obs = htmlspecialchars(trim((string)($_POST['observacion'] ?? '')), ENT_QUOTES, 'UTF-8');

            $st = $conn->prepare("UPDATE formularioQuestion SET etapa_material = ?, valor = COALESCE(?, valor), observacion = ? WHERE id = ?");
            $st->bind_param('sssi', $etapa, $valor, $obs, $idFQ);
            $st->execute();
            $aff = $st->affected_rows;
            $st->close();

            audit($conn, 'editar_gestion', $id_admin, $_POST, $aff);
            jr(['ok' => true, 'message' => 'Gestión actualizada.']);
        }

        /* ============ REASIGNAR EJECUTOR ============ */
        case 'reasignar_usuario': {
            $idUserNuevo = (int)($_POST['id_usuario'] ?? 0);
            $idFQ = (int)($_POST['idFQ'] ?? 0);
            if ($idFQ > 0) {
                $fq = fqInfo($conn, $idFQ, $empresa_id, $isMC);
                if (!$fq) jr(['ok' => false, 'message' => 'Material no encontrado.'], 404);
                if (!usuarioValido($conn, $idUserNuevo, (int)$fq['camp_empresa'])) jr(['ok' => false, 'message' => 'Ejecutor inválido.'], 400);
                $st = $conn->prepare("UPDATE formularioQuestion SET id_usuario = ? WHERE id = ?");
                $st->bind_param('ii', $idUserNuevo, $idFQ);
            } else {
                $idForm  = (int)($_POST['id_formulario'] ?? 0);
                $idLocal = (int)($_POST['id_local'] ?? 0);
                $form = formInfo($conn, $idForm, $empresa_id, $isMC);
                if (!$form || $idLocal <= 0) jr(['ok' => false, 'message' => 'Parámetros inválidos.'], 400);
                if (!usuarioValido($conn, $idUserNuevo, (int)$form['id_empresa'])) jr(['ok' => false, 'message' => 'Ejecutor inválido.'], 400);
                $st = $conn->prepare("UPDATE formularioQuestion SET id_usuario = ? WHERE id_formulario = ? AND id_local = ?");
                $st->bind_param('iii', $idUserNuevo, $idForm, $idLocal);
            }
            $st->execute();
            $aff = $st->affected_rows;
            $st->close();

            audit($conn, 'reasignar_usuario', $id_admin, $_POST, $aff);
            jr(['ok' => true, 'message' => "Ejecutor reasignado ({$aff} materiales)."]);
        }

        /* ============ REPROGRAMAR FECHA ============ */
        case 'reprogramar_fecha': {
            $fp = trim((string)($_POST['fechaPropuesta'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fp)) jr(['ok' => false, 'message' => 'Fecha inválida (YYYY-MM-DD).'], 400);

            $idFQ = (int)($_POST['idFQ'] ?? 0);
            if ($idFQ > 0) {
                if (!fqInfo($conn, $idFQ, $empresa_id, $isMC)) jr(['ok' => false, 'message' => 'Material no encontrado.'], 404);
                $st = $conn->prepare("UPDATE formularioQuestion SET fechaPropuesta = ? WHERE id = ?");
                $st->bind_param('si', $fp, $idFQ);
            } else {
                $idForm  = (int)($_POST['id_formulario'] ?? 0);
                $idLocal = (int)($_POST['id_local'] ?? 0);
                if (!formInfo($conn, $idForm, $empresa_id, $isMC) || $idLocal <= 0) jr(['ok' => false, 'message' => 'Parámetros inválidos.'], 400);
                $st = $conn->prepare("UPDATE formularioQuestion SET fechaPropuesta = ? WHERE id_formulario = ? AND id_local = ?");
                $st->bind_param('sii', $fp, $idForm, $idLocal);
            }
            $st->execute();
            $aff = $st->affected_rows;
            $st->close();

            audit($conn, 'reprogramar_fecha', $id_admin, $_POST, $aff);
            jr(['ok' => true, 'message' => "Fecha reprogramada ({$aff} materiales)."]);
        }

        /* ============ ELIMINAR GESTIÓN (formularioQuestion) ============ */
        case 'eliminar_gestion': {
            $idFQ = (int)($_POST['idFQ'] ?? 0);
            if (!fqInfo($conn, $idFQ, $empresa_id, $isMC)) jr(['ok' => false, 'message' => 'Material no encontrado.'], 404);
            $borrarFotos = (string)($_POST['borrar_fotos'] ?? '') === '1';

            $conn->begin_transaction();
            try {
                if ($borrarFotos) {
                    $st = $conn->prepare("DELETE FROM fotoVisita WHERE id_formularioQuestion = ?");
                    $st->bind_param('i', $idFQ); $st->execute(); $st->close();
                }
                $st = $conn->prepare("DELETE FROM gestion_apoyo WHERE id_formularioQuestion = ?");
                $st->bind_param('i', $idFQ); $st->execute(); $st->close();
                $st = $conn->prepare("DELETE FROM formularioQuestion WHERE id = ?");
                $st->bind_param('i', $idFQ); $st->execute(); $aff = $st->affected_rows; $st->close();
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                jr(['ok' => false, 'message' => 'Error al eliminar: ' . $e->getMessage()], 500);
            }

            audit($conn, 'eliminar_gestion', $id_admin, $_POST, $aff);
            jr(['ok' => true, 'message' => 'Gestión eliminada.']);
        }

        /* ============ ELIMINAR FOTO ============ */
        case 'eliminar_foto': {
            $idFoto = (int)($_POST['id_foto'] ?? 0);
            if ($idFoto <= 0) jr(['ok' => false, 'message' => 'Foto inválida.'], 400);
            // Validar empresa vía join (MC ve cualquier empresa).
            $sqlFoto = "SELECT fv.url
                FROM fotoVisita fv
                INNER JOIN formulario f ON f.id = fv.id_formulario
                WHERE fv.id = ? " . ($isMC ? "" : "AND f.id_empresa = ? ") . "LIMIT 1";
            $st = $conn->prepare($sqlFoto);
            if ($isMC) { $st->bind_param('i', $idFoto); }
            else       { $st->bind_param('ii', $idFoto, $empresa_id); }
            $st->execute();
            $rowFoto = $st->get_result()->fetch_assoc();
            $st->close();
            if (!$rowFoto) jr(['ok' => false, 'message' => 'Foto no encontrada.'], 404);

            $st = $conn->prepare("DELETE FROM fotoVisita WHERE id = ?");
            $st->bind_param('i', $idFoto); $st->execute(); $aff = $st->affected_rows; $st->close();

            // Borrado best-effort del archivo físico.
            $rel = ltrim(preg_replace('#^/?visibility2/app/#', '', str_replace('\\', '/', (string)$rowFoto['url'])), '/');
            if ($rel !== '' && strpos($rel, '..') === false) {
                $abs = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/visibility2/app/' . $rel;
                if (is_file($abs)) @unlink($abs);
            }

            audit($conn, 'eliminar_foto', $id_admin, $_POST, $aff);
            jr(['ok' => true, 'message' => 'Foto eliminada.']);
        }

        /* ============ EDITAR APOYOS (agregar / quitar) ============ */
        case 'editar_apoyos': {
            $idFQ = (int)($_POST['idFQ'] ?? 0);
            $fq = fqInfo($conn, $idFQ, $empresa_id, $isMC);
            if (!$fq) jr(['ok' => false, 'message' => 'Material no encontrado.'], 404);

            $agregar = array_values(array_unique(array_map('intval', (array)($_POST['agregar'] ?? []))));
            $quitar  = array_values(array_unique(array_map('intval', (array)($_POST['quitar'] ?? []))));
            $idForm  = (int)$fq['id_formulario'];
            $idLocal = (int)$fq['id_local'];
            $etapa   = (string)($fq['etapa_material'] ?? '');
            $cambios = 0;

            if (!empty($agregar)) {
                $stIns = $conn->prepare("INSERT IGNORE INTO gestion_apoyo (visita_id, id_formulario, id_local, id_formularioQuestion, id_usuario_apoyo, etapa) VALUES (0, ?, ?, ?, ?, ?)");
                foreach ($agregar as $uid) {
                    if ($uid <= 0 || !usuarioValido($conn, $uid, (int)$fq['camp_empresa'])) continue;
                    $stIns->bind_param('iiiis', $idForm, $idLocal, $idFQ, $uid, $etapa);
                    $stIns->execute();
                    $cambios += $stIns->affected_rows;
                }
                $stIns->close();
            }
            if (!empty($quitar)) {
                $stDel = $conn->prepare("DELETE FROM gestion_apoyo WHERE id_formularioQuestion = ? AND id_usuario_apoyo = ?");
                foreach ($quitar as $uid) {
                    if ($uid <= 0) continue;
                    $stDel->bind_param('ii', $idFQ, $uid);
                    $stDel->execute();
                    $cambios += $stDel->affected_rows;
                }
                $stDel->close();
            }

            audit($conn, 'editar_apoyos', $id_admin, $_POST, $cambios);
            jr(['ok' => true, 'message' => 'Apoyos actualizados.']);
        }

        /* ============ FORZAR CIERRE (completar manualmente) ============ */
        case 'forzar_cierre': {
            $idFQ = (int)($_POST['idFQ'] ?? 0);
            $fq = fqInfo($conn, $idFQ, $empresa_id, $isMC);
            if (!$fq) jr(['ok' => false, 'message' => 'Material no encontrado.'], 404);

            $etapa = trim((string)($_POST['etapa'] ?? 'implementado'));
            if (!in_array($etapa, ['implementado', 'retirado'], true)) jr(['ok' => false, 'message' => 'Etapa de cierre inválida.'], 400);
            $vp = (string)((int)($fq['valor_propuesto'] ?? 0));

            $st = $conn->prepare("UPDATE formularioQuestion SET etapa_material = ?, valor = ?, fechaVisita = NOW() WHERE id = ?");
            $st->bind_param('ssi', $etapa, $vp, $idFQ);
            $st->execute();
            $aff = $st->affected_rows;
            $st->close();

            audit($conn, 'forzar_cierre', $id_admin, $_POST, $aff);
            jr(['ok' => true, 'message' => 'Material cerrado manualmente.']);
        }

        /* ============ EDITAR MOTIVO DE PARCIAL ============ */
        case 'editar_motivo_parcial': {
            $idFQ = (int)($_POST['idFQ'] ?? 0);
            if (!fqInfo($conn, $idFQ, $empresa_id, $isMC)) jr(['ok' => false, 'message' => 'Material no encontrado.'], 404);
            $motivo = trim((string)($_POST['motivo'] ?? ''));

            $st = $conn->prepare("SELECT id FROM gestion_visita WHERE id_formularioQuestion = ? ORDER BY id DESC LIMIT 1");
            $st->bind_param('i', $idFQ);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if (!$row) jr(['ok' => false, 'message' => 'No hay gestiones registradas para este material.'], 404);

            $gvId = (int)$row['id'];
            $st = $conn->prepare("UPDATE gestion_visita SET motivo_no_implementacion = ? WHERE id = ?");
            $st->bind_param('si', $motivo, $gvId);
            $st->execute();
            $aff = $st->affected_rows;
            $st->close();

            audit($conn, 'editar_motivo_parcial', $id_admin, $_POST, $aff);
            jr(['ok' => true, 'message' => 'Motivo actualizado.']);
        }

        /* ============ EDITAR DATOS DEL LOCAL ============ */
        case 'editar_local': {
            $idLocal = (int)($_POST['id_local'] ?? 0);
            $sqlChk = "SELECT id FROM local WHERE id = ? " . ($isMC ? "" : "AND id_empresa = ? ") . "LIMIT 1";
            $st = $conn->prepare($sqlChk);
            if ($isMC) { $st->bind_param('i', $idLocal); }
            else       { $st->bind_param('ii', $idLocal, $empresa_id); }
            $st->execute();
            $okLocal = $st->get_result()->num_rows > 0;
            $st->close();
            if (!$okLocal) jr(['ok' => false, 'message' => 'Local no encontrado.'], 404);

            $dir = trim((string)($_POST['direccion'] ?? ''));
            $lat = trim((string)($_POST['lat'] ?? ''));
            $lng = trim((string)($_POST['lng'] ?? ''));
            $latDb = ($lat === '' ? null : $lat);
            $lngDb = ($lng === '' ? null : $lng);

            $sqlUpd = "UPDATE local SET direccion = ?, lat = ?, lng = ?, updated_at = NOW() WHERE id = ? " . ($isMC ? "" : "AND id_empresa = ?");
            $st = $conn->prepare($sqlUpd);
            if ($isMC) { $st->bind_param('sssi', $dir, $latDb, $lngDb, $idLocal); }
            else       { $st->bind_param('sssii', $dir, $latDb, $lngDb, $idLocal, $empresa_id); }
            $st->execute();
            $aff = $st->affected_rows;
            $st->close();

            audit($conn, 'editar_local', $id_admin, $_POST, $aff);
            jr(['ok' => true, 'message' => 'Datos del local actualizados.']);
        }

        default:
            jr(['ok' => false, 'message' => 'Acción no reconocida.'], 400);
    }
} catch (Throwable $e) {
    jr(['ok' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
}
