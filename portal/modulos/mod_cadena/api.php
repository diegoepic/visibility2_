<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
$db = $conexion ?? $conn ?? $mysqli ?? null;

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!($db instanceof mysqli)) {
    respond(['ok' => false, 'message' => 'No fue posible conectar con la base de datos.'], 500);
}

$db->set_charset('utf8mb4');

$profile = strtolower(trim((string)($_SESSION['perfil_nombre'] ?? '')));
if ($profile === '') {
    respond(['ok' => false, 'message' => 'Sesión no válida o expirada.'], 401);
}
$canEdit = in_array($profile, ['editor', 'coordinador', 'administrador', 'admin'], true);
$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'list');

if ($action === 'list') {
    $search = trim((string)($_GET['search'] ?? ''));
    $sql = "
        SELECT
            ca.id,
            ca.nombre,
            ca.id_cuenta,
            ca.logo_url,
            ca.icono_url,
            cu.nombre AS cuenta_nombre,
            cu.logo_url AS cuenta_logo_url,
            cu.icono_url AS cuenta_icono_url,
            COALESCE(lc.locales_activos, 0) AS locales_total,
            COALESCE(lc.locales_referencias, 0) AS locales_referencias
        FROM cadena ca
        LEFT JOIN cuenta cu ON cu.id = ca.id_cuenta
        LEFT JOIN (
            SELECT
                id_cadena,
                SUM(CASE WHEN deleted_at IS NULL THEN 1 ELSE 0 END) AS locales_activos,
                COUNT(*) AS locales_referencias
            FROM local
            GROUP BY id_cadena
        ) lc ON lc.id_cadena = ca.id
    ";

    if ($search !== '') {
        $sql .= "
            WHERE CAST(ca.id AS CHAR) LIKE CONCAT('%', ?, '%')
               OR ca.nombre LIKE CONCAT('%', ?, '%')
               OR CAST(cu.id AS CHAR) LIKE CONCAT('%', ?, '%')
               OR cu.nombre LIKE CONCAT('%', ?, '%')
        ";
    }

    $sql .= ' ORDER BY ca.nombre ASC, ca.id ASC';
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        respond(['ok' => false, 'message' => 'No fue posible preparar el listado.'], 500);
    }
    if ($search !== '') {
        $stmt->bind_param('ssss', $search, $search, $search, $search);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        respond(['ok' => false, 'message' => 'No fue posible cargar las cadenas.'], 500);
    }

    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    respond([
        'ok' => true,
        'data' => $rows,
        'total' => count($rows),
        'can_edit' => $canEdit
    ]);
}

if ($action === 'locals') {
    $chainId = (int)($_GET['chain_id'] ?? 0);
    $scope = ($_GET['scope'] ?? 'id') === 'name' ? 'name' : 'id';
    if ($chainId <= 0) {
        respond(['ok' => false, 'message' => 'ID de cadena inválido.'], 422);
    }

    $source = $db->prepare('SELECT nombre FROM cadena WHERE id = ? LIMIT 1');
    $source->bind_param('i', $chainId);
    $source->execute();
    $sourceRow = $source->get_result()->fetch_assoc();
    $source->close();
    if (!$sourceRow) {
        respond(['ok' => false, 'message' => 'La cadena seleccionada no existe.'], 404);
    }

    $where = $scope === 'name'
        ? 'LOWER(TRIM(ca.nombre)) = LOWER(TRIM(?))'
        : 'ca.id = ?';
    $scopeValue = $scope === 'name' ? (string)$sourceRow['nombre'] : $chainId;
    $scopeType = $scope === 'name' ? 's' : 'i';

    $countSql = "
        SELECT COUNT(*) AS total
        FROM local l
        JOIN cadena ca ON ca.id = l.id_cadena
        WHERE {$where} AND l.deleted_at IS NULL
    ";
    $countStmt = $db->prepare($countSql);
    $countStmt->bind_param($scopeType, $scopeValue);
    $countStmt->execute();
    $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    $sql = "
        SELECT
            l.id,
            l.codigo,
            l.nombre,
            l.direccion,
            l.id_cadena,
            ca.nombre AS cadena_nombre,
            l.id_cuenta,
            cu.nombre AS cuenta_nombre,
            l.id_empresa,
            l.id_division
        FROM local l
        JOIN cadena ca ON ca.id = l.id_cadena
        LEFT JOIN cuenta cu ON cu.id = l.id_cuenta
        WHERE {$where} AND l.deleted_at IS NULL
        ORDER BY ca.nombre ASC, l.nombre ASC, l.id ASC
        LIMIT 5000
    ";
    $stmt = $db->prepare($sql);
    $stmt->bind_param($scopeType, $scopeValue);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    respond([
        'ok' => true,
        'data' => $rows,
        'total' => $total,
        'shown' => count($rows),
        'scope' => $scope,
        'source_name' => $sourceRow['nombre']
    ]);
}

if ($action === 'catalog') {
    $stmt = $db->prepare("
        SELECT
            ca.id,
            ca.nombre,
            ca.id_cuenta,
            cu.nombre AS cuenta_nombre
        FROM cadena ca
        LEFT JOIN cuenta cu ON cu.id = ca.id_cuenta
        ORDER BY cu.nombre ASC, ca.nombre ASC, ca.id ASC
    ");
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    respond(['ok' => true, 'data' => $rows]);
}

if (!$canEdit) {
    respond(['ok' => false, 'message' => 'No tiene permisos para modificar cadenas o cuentas.'], 403);
}

$sessionToken = (string)($_SESSION['csrf_token'] ?? '');
$requestToken = (string)($_POST['csrf_token'] ?? '');
if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
    respond(['ok' => false, 'message' => 'Token CSRF inválido. Recargue la página.'], 400);
}

function validatedName(string $value): string
{
    $value = preg_replace('/[\p{Z}\s]+/u', ' ', trim($value));
    if ($value === '') {
        respond(['ok' => false, 'message' => 'El nombre no puede estar vacío.'], 422);
    }
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($length > 45) {
        respond(['ok' => false, 'message' => 'El nombre no puede superar los 45 caracteres.'], 422);
    }
    return $value;
}

if ($action === 'update_chain') {
    $id = (int)($_POST['id'] ?? 0);
    $name = validatedName((string)($_POST['nombre'] ?? ''));
    if ($id <= 0) {
        respond(['ok' => false, 'message' => 'ID de cadena inválido.'], 422);
    }

    $check = $db->prepare('SELECT id_cuenta FROM cadena WHERE id = ? LIMIT 1');
    $check->bind_param('i', $id);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    $check->close();
    if (!$row) {
        respond(['ok' => false, 'message' => 'La cadena no existe.'], 404);
    }

    $accountId = (int)$row['id_cuenta'];
    $duplicate = $db->prepare('SELECT id FROM cadena WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(?)) AND id_cuenta = ? AND id <> ? LIMIT 1');
    $duplicate->bind_param('sii', $name, $accountId, $id);
    $duplicate->execute();
    $duplicateRow = $duplicate->get_result()->fetch_assoc();
    $duplicate->close();
    if ($duplicateRow) {
        respond(['ok' => false, 'message' => 'Ya existe otra cadena con ese nombre dentro de la misma cuenta.'], 409);
    }

    $stmt = $db->prepare('UPDATE cadena SET nombre = ? WHERE id = ?');
    $stmt->bind_param('si', $name, $id);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        respond(['ok' => false, 'message' => 'No fue posible actualizar la cadena.'], 500);
    }
    respond(['ok' => true, 'message' => 'Nombre de cadena actualizado.', 'nombre' => $name]);
}

if ($action === 'update_account') {
    $id = (int)($_POST['id'] ?? 0);
    $name = validatedName((string)($_POST['nombre'] ?? ''));
    if ($id <= 0) {
        respond(['ok' => false, 'message' => 'ID de cuenta inválido.'], 422);
    }

    $duplicate = $db->prepare('SELECT id FROM cuenta WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(?)) AND id <> ? LIMIT 1');
    $duplicate->bind_param('si', $name, $id);
    $duplicate->execute();
    $duplicateRow = $duplicate->get_result()->fetch_assoc();
    $duplicate->close();
    if ($duplicateRow) {
        respond(['ok' => false, 'message' => 'Ya existe otra cuenta con ese nombre.'], 409);
    }

    $stmt = $db->prepare('UPDATE cuenta SET nombre = ? WHERE id = ?');
    $stmt->bind_param('si', $name, $id);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if (!$ok) {
        respond(['ok' => false, 'message' => 'No fue posible actualizar la cuenta.'], 500);
    }
    if ($affected === 0) {
        $exists = $db->prepare('SELECT id FROM cuenta WHERE id = ? LIMIT 1');
        $exists->bind_param('i', $id);
        $exists->execute();
        $existsRow = $exists->get_result()->fetch_assoc();
        $exists->close();
        if (!$existsRow) {
            respond(['ok' => false, 'message' => 'La cuenta no existe.'], 404);
        }
    }
    respond(['ok' => true, 'message' => 'Nombre de cuenta actualizado.', 'nombre' => $name]);
}

if ($action === 'bulk_reassign') {
    $sourceChainId = (int)($_POST['source_chain_id'] ?? 0);
    $targetChainId = (int)($_POST['target_chain_id'] ?? 0);
    $scope = ($_POST['scope'] ?? 'id') === 'name' ? 'name' : 'id';
    $applyAll = ($_POST['apply_all'] ?? '0') === '1';
    $selectedIds = json_decode((string)($_POST['local_ids'] ?? '[]'), true);

    if ($sourceChainId <= 0 || $targetChainId <= 0) {
        respond(['ok' => false, 'message' => 'Debe indicar una cadena de origen y una cadena de destino.'], 422);
    }

    $target = $db->prepare("
        SELECT ca.id, ca.nombre, ca.id_cuenta, cu.nombre AS cuenta_nombre
        FROM cadena ca
        JOIN cuenta cu ON cu.id = ca.id_cuenta
        WHERE ca.id = ?
        LIMIT 1
    ");
    $target->bind_param('i', $targetChainId);
    $target->execute();
    $targetRow = $target->get_result()->fetch_assoc();
    $target->close();
    if (!$targetRow) {
        respond(['ok' => false, 'message' => 'La cadena destino no existe o no tiene una cuenta válida asociada.'], 422);
    }

    $targetAccountId = (int)$targetRow['id_cuenta'];
    $db->begin_transaction();
    try {
        $affected = 0;
        if ($applyAll) {
            if ($scope === 'name') {
                $source = $db->prepare('SELECT nombre FROM cadena WHERE id = ? LIMIT 1');
                $source->bind_param('i', $sourceChainId);
                $source->execute();
                $sourceRow = $source->get_result()->fetch_assoc();
                $source->close();
                if (!$sourceRow) {
                    throw new RuntimeException('La cadena de origen no existe.');
                }
                $sourceName = (string)$sourceRow['nombre'];
                $stmt = $db->prepare("
                    UPDATE local l
                    JOIN cadena ca_origen ON ca_origen.id = l.id_cadena
                    SET l.id_cadena = ?, l.id_cuenta = ?, l.updated_at = NOW()
                    WHERE LOWER(TRIM(ca_origen.nombre)) = LOWER(TRIM(?))
                      AND l.deleted_at IS NULL
                ");
                $stmt->bind_param('iis', $targetChainId, $targetAccountId, $sourceName);
            } else {
                $stmt = $db->prepare("
                    UPDATE local
                    SET id_cadena = ?, id_cuenta = ?, updated_at = NOW()
                    WHERE id_cadena = ? AND deleted_at IS NULL
                ");
                $stmt->bind_param('iii', $targetChainId, $targetAccountId, $sourceChainId);
            }
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
        } else {
            if (!is_array($selectedIds)) {
                throw new RuntimeException('La selección de locales no es válida.');
            }
            $selectedIds = array_values(array_unique(array_filter(array_map('intval', $selectedIds), static function ($id) {
                return $id > 0;
            })));
            if (!$selectedIds) {
                throw new RuntimeException('Debe seleccionar al menos un local.');
            }
            if (count($selectedIds) > 5000) {
                throw new RuntimeException('La selección supera el máximo de 5.000 locales por operación.');
            }

            foreach (array_chunk($selectedIds, 500) as $chunk) {
                // Los IDs se convierten previamente a enteros; la lista resultante es segura para IN().
                $idList = implode(',', $chunk);
                if ($scope === 'name') {
                    $source = $db->prepare('SELECT nombre FROM cadena WHERE id = ? LIMIT 1');
                    $source->bind_param('i', $sourceChainId);
                    $source->execute();
                    $sourceRow = $source->get_result()->fetch_assoc();
                    $source->close();
                    if (!$sourceRow) {
                        throw new RuntimeException('La cadena de origen no existe.');
                    }
                    $sourceName = (string)$sourceRow['nombre'];
                    $stmt = $db->prepare("
                        UPDATE local
                        SET id_cadena = ?, id_cuenta = ?, updated_at = NOW()
                        WHERE id IN ({$idList})
                          AND id_cadena IN (
                              SELECT id FROM cadena WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(?))
                          )
                          AND deleted_at IS NULL
                    ");
                    $stmt->bind_param('iis', $targetChainId, $targetAccountId, $sourceName);
                } else {
                    $stmt = $db->prepare("
                        UPDATE local
                        SET id_cadena = ?, id_cuenta = ?, updated_at = NOW()
                        WHERE id IN ({$idList})
                          AND id_cadena = ?
                          AND deleted_at IS NULL
                    ");
                    $stmt->bind_param('iii', $targetChainId, $targetAccountId, $sourceChainId);
                }
                $stmt->execute();
                $affected += $stmt->affected_rows;
                $stmt->close();
            }
        }

        $db->commit();
        respond([
            'ok' => true,
            'message' => "Se actualizaron {$affected} locales.",
            'affected' => $affected,
            'target_chain' => $targetRow['nombre'],
            'target_account' => $targetRow['cuenta_nombre']
        ]);
    } catch (Throwable $error) {
        $db->rollback();
        respond(['ok' => false, 'message' => $error->getMessage()], 422);
    }
}

if ($action === 'delete_chain') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        respond(['ok' => false, 'message' => 'ID de cadena inválido.'], 422);
    }

    $db->begin_transaction();
    try {
        $chainStmt = $db->prepare('SELECT nombre, id_cuenta FROM cadena WHERE id = ? FOR UPDATE');
        $chainStmt->bind_param('i', $id);
        $chainStmt->execute();
        $chain = $chainStmt->get_result()->fetch_assoc();
        $chainStmt->close();
        if (!$chain) {
            throw new RuntimeException('La cadena ya no existe.');
        }

        // Se cuentan todas las referencias, incluso locales eliminados lógicamente.
        $countStmt = $db->prepare('SELECT COUNT(*) AS total FROM local WHERE id_cadena = ?');
        $countStmt->bind_param('i', $id);
        $countStmt->execute();
        $references = (int)$countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();
        if ($references > 0) {
            throw new RuntimeException("No se puede eliminar: la cadena todavía tiene {$references} locales asociados, incluyendo históricos.");
        }

        $deleteStmt = $db->prepare('DELETE FROM cadena WHERE id = ?');
        $deleteStmt->bind_param('i', $id);
        $ok = $deleteStmt->execute();
        $deleteError = $deleteStmt->error;
        $affected = $deleteStmt->affected_rows;
        $deleteStmt->close();
        if (!$ok || $affected !== 1) {
            throw new RuntimeException($deleteError !== ''
                ? 'La cadena está siendo utilizada por otro registro: ' . $deleteError
                : 'No fue posible eliminar la cadena.');
        }

        $db->commit();
        respond([
            'ok' => true,
            'message' => "Cadena '{$chain['nombre']}' (ID {$id}) eliminada definitivamente."
        ]);
    } catch (Throwable $error) {
        $db->rollback();
        respond(['ok' => false, 'message' => $error->getMessage()], 409);
    }
}

if ($action === 'bulk_delete_chains') {
    $ids = json_decode((string)($_POST['ids'] ?? '[]'), true);
    if (!is_array($ids)) {
        respond(['ok' => false, 'message' => 'La selección de cadenas no es válida.'], 422);
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id) {
        return $id > 0;
    })));
    if (!$ids) {
        respond(['ok' => false, 'message' => 'Debe seleccionar al menos una cadena.'], 422);
    }
    if (count($ids) > 2000) {
        respond(['ok' => false, 'message' => 'No se pueden eliminar más de 2.000 cadenas por operación.'], 422);
    }

    // Los valores fueron convertidos a enteros antes de construir IN().
    $idList = implode(',', $ids);
    $db->begin_transaction();
    try {
        $lockStmt = $db->prepare("SELECT id FROM cadena WHERE id IN ({$idList}) ORDER BY id FOR UPDATE");
        if (!$lockStmt || !$lockStmt->execute()) {
            throw new RuntimeException('No fue posible bloquear las cadenas seleccionadas.');
        }
        $lockedIds = array_map('intval', array_column($lockStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
        $lockStmt->close();
        if (count($lockedIds) !== count($ids)) {
            throw new RuntimeException('Una o más cadenas ya no existen. Actualice el listado e inténtelo nuevamente.');
        }

        $referenceStmt = $db->prepare("
            SELECT id_cadena, COUNT(*) AS total
            FROM local
            WHERE id_cadena IN ({$idList})
            GROUP BY id_cadena
        ");
        if (!$referenceStmt || !$referenceStmt->execute()) {
            throw new RuntimeException('No fue posible validar las asociaciones de locales.');
        }
        $referenced = $referenceStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $referenceStmt->close();
        if ($referenced) {
            $blockedIds = implode(', ', array_column($referenced, 'id_cadena'));
            throw new RuntimeException("No se eliminó ninguna cadena. Estos IDs todavía tienen locales asociados: {$blockedIds}.");
        }

        $deleteStmt = $db->prepare("DELETE FROM cadena WHERE id IN ({$idList})");
        if (!$deleteStmt) {
            throw new RuntimeException('No fue posible preparar la eliminación masiva.');
        }
        $ok = $deleteStmt->execute();
        $deleteError = $deleteStmt->error;
        $affected = $deleteStmt->affected_rows;
        $deleteStmt->close();
        if (!$ok || $affected !== count($ids)) {
            throw new RuntimeException($deleteError !== ''
                ? 'No se eliminó ninguna cadena porque existe otra relación: ' . $deleteError
                : 'No fue posible eliminar todas las cadenas seleccionadas.');
        }

        $db->commit();
        respond([
            'ok' => true,
            'message' => "Se eliminaron definitivamente {$affected} cadenas.",
            'affected' => $affected
        ]);
    } catch (Throwable $error) {
        $db->rollback();
        respond(['ok' => false, 'message' => $error->getMessage()], 409);
    }
}

respond(['ok' => false, 'message' => 'Acción no reconocida.'], 400);
