<?php
/**
 * Servicio para cargar SETS BASE DE RUTA.
 * El set solo guarda codigo_local + usuario + division.
 * No genera fechas, no crea formulario, no inserta en formularioQuestion.
 */
class RutasProgramadasSetService
{
    private mysqli $conn;
    private int $idEmpresa;
    private int $idUsuarioSesion;

    public function __construct(mysqli $conn, int $idEmpresa, int $idUsuarioSesion)
    {
        $this->conn = $conn;
        $this->idEmpresa = $idEmpresa;
        $this->idUsuarioSesion = $idUsuarioSesion;
    }

public function crearSetDesdeArchivo(array $data, array $file): array
{
    $nombreRuta = trim((string)($data['nombre_ruta'] ?? ''));
    $idDivision = (int)($data['id_division'] ?? 0);
    $validarDivisionLocal = (int)($data['validar_division_local'] ?? 1) === 1;
    $idRutaSetEditar = (int)($data['id_ruta_set'] ?? 0);
    $idCategoria = (int)($data['id_categoria'] ?? 0);
    

    $modoSetPost = strtolower(trim((string)($data['modo_set'] ?? 'masivo')));
    $tipoScope = $modoSetPost === 'individual' ? 'individual' : 'masiva';

    $idUsuarioFijo = (int)($data['id_usuario_fijo'] ?? 0);
    $usuarioFijo = null;

    if ($nombreRuta === '') {
        throw new RuntimeException('Debes ingresar un nombre para el set.');
    }

    if ($idDivision <= 0) {
        throw new RuntimeException('Debes seleccionar la división asociada al set.');
    }

    $this->validarDivision($idDivision);
    if ($idCategoria <= 0) {
        throw new RuntimeException('Debes seleccionar una categoría para el set.');
    }
    
    $this->validarCategoria($idCategoria, $idDivision);

    if ($tipoScope === 'individual') {
        if ($idUsuarioFijo <= 0) {
            throw new RuntimeException('No fue posible identificar el trabajador para el set individual.');
        }

        $usuarioFijo = $this->buscarUsuarioPorId($idUsuarioFijo);

        if (!$usuarioFijo) {
            throw new RuntimeException('El trabajador seleccionado no existe, no está activo o no pertenece a la empresa.');
        }
    }

    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Debes subir un archivo CSV válido.');
    }

    /*
        Individual:
        - puede venir solo codigo_local
        - si trae usuario, se ignora y se usa id_usuario_fijo

        Masiva:
        - debe venir codigo_local + usuario
    */
    $rows = $this->leerArchivoCsv($file['tmp_name'], $tipoScope === 'masiva');

    if (!$rows) {
        throw new RuntimeException('El archivo no contiene registros válidos.');
    }

    $errores = [];
    $advertencias = [];
    $detalleValidado = [];
    $duplicados = [];
    $orden = 1;

    foreach ($rows as $row) {
        $linea = (int)$row['_linea'];
        $codigoLocal = trim((string)($row['codigo_local'] ?? ''));
        $usuarioCodigoArchivo = trim((string)($row['usuario'] ?? ''));

        if ($codigoLocal === '' && $usuarioCodigoArchivo === '') {
            continue;
        }

        if ($codigoLocal === '') {
            $errores[] = "Línea {$linea}: código de local vacío.";
            continue;
        }

        if ($tipoScope === 'individual') {
            $usuario = $usuarioFijo;
            $usuarioCodigo = (string)$usuarioFijo['usuario'];

            if ($usuarioCodigoArchivo !== '' && strcasecmp($usuarioCodigoArchivo, $usuarioCodigo) !== 0) {
                $advertencias[] = "Línea {$linea}: el usuario del archivo '{$usuarioCodigoArchivo}' fue ignorado. Se usó '{$usuarioCodigo}'.";
            }
        } else {
            $usuarioCodigo = $usuarioCodigoArchivo;

            if ($usuarioCodigo === '') {
                $errores[] = "Línea {$linea}: usuario vacío para local {$codigoLocal}.";
                continue;
            }

            $usuario = $this->buscarUsuario($usuarioCodigo);

            if (!$usuario) {
                $errores[] = "Línea {$linea}: usuario '{$usuarioCodigo}' no existe, no está activo o no es perfil trabajador.";
                continue;
            }
        }

        $local = $this->buscarLocal($codigoLocal, $idDivision, $validarDivisionLocal);

        if (!$local) {
            $msgDivision = $validarDivisionLocal ? ' para la división seleccionada' : '';
            $errores[] = "Línea {$linea}: local '{$codigoLocal}' no existe{$msgDivision}.";
            continue;
        }

        $key = (int)$usuario['id'] . '-' . (int)$local['id'];

        if (isset($duplicados[$key])) {
            $advertencias[] = "Línea {$linea}: local {$codigoLocal} duplicado para usuario {$usuarioCodigo}. Se omitió.";
            continue;
        }

        $duplicados[$key] = true;

        $detalleValidado[] = [
            'id_usuario'   => (int)$usuario['id'],
            'usuario'      => (string)$usuario['usuario'],
            'id_local'     => (int)$local['id'],
            'codigo_local' => (string)$local['codigo'],
            'orden'        => $orden++,
        ];
    }

    if (!$detalleValidado) {
        $detalleError = '';

        if (!empty($errores)) {
            $detalleError = ' Detalle: ' . implode(' | ', array_slice($errores, 0, 8));
        }

        throw new RuntimeException('No existen registros válidos para guardar el set.' . $detalleError);
    }

$this->conn->begin_transaction();

try {
    $idUsuarioSet = $tipoScope === 'individual' ? $idUsuarioFijo : null;

    $parametrosPayload = [
        'tipo_set' => 'base_sin_fecha',

        'modo_set' => $tipoScope,

        'id_division' => $idDivision,
        'id_categoria' => $idCategoria,

        'id_usuario_fijo' => $tipoScope === 'individual'
            ? (int)$idUsuarioSet
            : null,

        'columnas_requeridas' => $tipoScope === 'individual'
            ? ['codigo_local']
            : ['codigo_local', 'usuario'],

        'validaciones' => [
            'validar_division_local' => $validarDivisionLocal,
            'requiere_usuario_archivo' => $tipoScope === 'masiva',
            'usa_usuario_fijo' => $tipoScope === 'individual',
            'requiere_categoria_activa' => true,
        ],

        'archivo' => [
            'nombre_original' => $file['name'] ?? null,
            'tipo_mime' => $file['type'] ?? null,
            'tamano_bytes' => isset($file['size']) ? (int)$file['size'] : null,
        ],

        'auditoria' => [
            'created_by' => $this->idUsuarioSesion,
            'created_at' => date('Y-m-d H:i:s'),
        ],
    ];

    $parametros = json_encode(
        $parametrosPayload,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($parametros === false) {
        throw new RuntimeException('No fue posible preparar los parámetros del set: ' . json_last_error_msg());
    }

        $idUsuarioSet = $tipoScope === 'individual' ? $idUsuarioFijo : null;

if ($idRutaSetEditar > 0) {
    $setActual = $this->obtenerSetEditable($idRutaSetEditar);

    if ((string)$setActual['tipo_scope'] !== $tipoScope) {
        throw new RuntimeException('No puedes cambiar el tipo del set. Debes reemplazarlo desde el mismo modo en que fue creado.');
    }

    if ($tipoScope === 'individual' && (int)$setActual['id_usuario'] !== (int)$idUsuarioSet) {
        throw new RuntimeException('Este set individual no pertenece al trabajador seleccionado.');
    }

    $idRutaSet = $idRutaSetEditar;

    $sqlUpdateSet = "
        UPDATE ruta_set
        SET
            nombre = ?,
            id_division = ?,
            id_categoria = ?,
            id_usuario = ?,
            tipo_scope = ?,
            parametros_json = ?,
            total_locales = 0,
            total_usuarios = 0,
            updated_at = NOW()
        WHERE id = ?
          AND id_empresa = ?
          AND estado = 'borrador'
    ";

    $stmtSet = $this->conn->prepare($sqlUpdateSet);
    $stmtSet->bind_param(
        'siiissii',
        $nombreRuta,
        $idDivision,
        $idCategoria,
        $idUsuarioSet,
        $tipoScope,
        $parametros,
        $idRutaSet,
        $this->idEmpresa
    );
    $stmtSet->execute();
    $stmtSet->close();

    $stmtDeleteDetalle = $this->conn->prepare("
        DELETE FROM ruta_set_detalle
        WHERE id_ruta_set = ?
    ");
    $stmtDeleteDetalle->bind_param('i', $idRutaSet);
    $stmtDeleteDetalle->execute();
    $stmtDeleteDetalle->close();

} else {
    $sqlSet = "
        INSERT INTO ruta_set (
            nombre,
            id_empresa,
            id_division,
            id_categoria,
            id_subdivision,
            id_usuario,
            tipo_scope,
            origen,
            estado,
            fecha_inicio,
            fecha_termino,
            locales_por_dia,
            total_locales,
            total_usuarios,
            parametros_json,
            created_by
        ) VALUES (?, ?, ?, ?, NULL, ?, ?, 'archivo', 'borrador', NULL, NULL, NULL, 0, 0, ?, ?)
    ";

    $stmtSet = $this->conn->prepare($sqlSet);
    $stmtSet->bind_param(
        'siiiissi',
        $nombreRuta,
        $this->idEmpresa,
        $idDivision,
        $idCategoria,
        $idUsuarioSet,
        $tipoScope,
        $parametros,
        $this->idUsuarioSesion
    );
    $stmtSet->execute();

    $idRutaSet = (int)$this->conn->insert_id;
    $stmtSet->close();
}

        $sqlDetalle = "
            INSERT INTO ruta_set_detalle (
                id_ruta_set,
                id_usuario,
                id_local,
                codigo_local,
                orden,
                dia_numero,
                fecha_propuesta,
                estado,
                observacion
            ) VALUES (?, ?, ?, ?, ?, NULL, NULL, 'pendiente', NULL)
        ";

        $stmtDetalle = $this->conn->prepare($sqlDetalle);
        $insertados = 0;

        foreach ($detalleValidado as $d) {
            $stmtDetalle->bind_param(
                'iiisi',
                $idRutaSet,
                $d['id_usuario'],
                $d['id_local'],
                $d['codigo_local'],
                $d['orden']
            );
            $stmtDetalle->execute();
            $insertados++;
        }

        $stmtDetalle->close();

        $totalLocales = $this->contarLocalesSet($idRutaSet);
        $totalUsuarios = $this->contarUsuariosSet($idRutaSet);

        $sqlUpdate = "
            UPDATE ruta_set
            SET total_locales = ?, total_usuarios = ?
            WHERE id = ? AND id_empresa = ?
        ";

        $stmtUpdate = $this->conn->prepare($sqlUpdate);
        $stmtUpdate->bind_param('iiii', $totalLocales, $totalUsuarios, $idRutaSet, $this->idEmpresa);
        $stmtUpdate->execute();
        $stmtUpdate->close();

        $this->conn->commit();

        return [
            'id_ruta_set'   => $idRutaSet,
            'modo_set'      => $tipoScope,
            'insertados'    => $insertados,
            'omitidos'      => count($errores) + count($advertencias),
            'total_locales' => $totalLocales,
            'total_usuarios'=> $totalUsuarios,
            'errores'       => $errores,
            'advertencias'  => $advertencias,
        ];

    } catch (Throwable $e) {
        $this->conn->rollback();
        throw $e;
    }
}

    public function listarSets(?int $idDivision = null, string $tipoScope = 'masiva', int $idUsuarioFijo = 0): array
    {
        $tipoScope = strtolower(trim($tipoScope));
    
        if (!in_array($tipoScope, ['individual', 'masiva'], true)) {
            $tipoScope = 'masiva';
        }
    
        $types = 'is';
        $params = [
            $this->idEmpresa,
            $tipoScope
        ];
    
        $where = "
            WHERE rs.id_empresa = ?
              AND rs.origen = 'archivo'
              AND rs.tipo_scope = ?
        ";
    
        if ($idDivision !== null && $idDivision > 0) {
            $where .= " AND rs.id_division = ? ";
            $types .= 'i';
            $params[] = $idDivision;
        }
    
        if ($tipoScope === 'individual') {
            if ($idUsuarioFijo > 0) {
                $where .= " AND rs.id_usuario = ? ";
                $types .= 'i';
                $params[] = $idUsuarioFijo;
            } else {
                // Seguridad: si se pide individual pero no viene trabajador,
                // evitamos mostrar sets individuales de todos.
                $where .= " AND rs.id_usuario = 0 ";
            }
        }
    
        $sql = "
            SELECT
                rs.id,
                rs.id_categoria,
                c.nombre AS categoria_nombre,                
                rs.nombre,
                rs.id_division,
                d.nombre AS division_nombre,
                rs.id_usuario,
                rs.tipo_scope,
                rs.estado,
                rs.total_locales,
                rs.total_usuarios,
                DATE_FORMAT(rs.created_at, '%d/%m/%Y %H:%i') AS created_at,
                COALESCE(CONCAT(ucrea.nombre, ' ', ucrea.apellido), ucrea.usuario, '') AS creado_por,
                COALESCE(CONCAT(uej.nombre, ' ', uej.apellido), uej.usuario, '') AS trabajador_nombre,
                uej.usuario AS trabajador_usuario
            FROM ruta_set rs
            LEFT JOIN division_empresa d
                ON d.id = rs.id_division
            LEFT JOIN ruta_set_categoria c
                ON c.id = rs.id_categoria                
            LEFT JOIN usuario ucrea
                ON ucrea.id = rs.created_by
            LEFT JOIN usuario uej
                ON uej.id = rs.id_usuario
            {$where}
            ORDER BY rs.created_at DESC, rs.id DESC
            LIMIT 80
        ";
    
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
    
        $res = $stmt->get_result();
    
        $data = [];
        while ($row = $res->fetch_assoc()) {
            $data[] = $row;
        }
    
        $stmt->close();
    
        return $data;
    }

    private function validarDivision(int $idDivision): void
    {
        $sql = "
            SELECT id
            FROM division_empresa
            WHERE id = ?
              AND id_empresa = ?
              AND estado = 1
            LIMIT 1
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('ii', $idDivision, $this->idEmpresa);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new RuntimeException('La división seleccionada no existe o no está activa para esta empresa.');
        }
    }

    private function buscarUsuario(string $usuarioCodigo): ?array
    {
        $sql = "
            SELECT id, usuario, nombre, apellido
            FROM usuario
            WHERE id_empresa = ?
              AND activo = 1
              AND id_perfil = 3
              AND UPPER(TRIM(usuario)) = UPPER(TRIM(?))
            LIMIT 1
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('is', $this->idEmpresa, $usuarioCodigo);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }
    
    private function buscarUsuarioPorId(int $idUsuario): ?array
    {
        $sql = "
            SELECT id, usuario, nombre, apellido
            FROM usuario
            WHERE id_empresa = ?
              AND activo = 1
              AND id_perfil = 3
              AND id = ?
            LIMIT 1
        ";
    
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('ii', $this->idEmpresa, $idUsuario);
        $stmt->execute();
    
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    
        return $row ?: null;
    }

    private function buscarLocal(string $codigoLocal, int $idDivision, bool $validarDivisionLocal): ?array
    {
        $types = 'is';
        $params = [$this->idEmpresa, $codigoLocal];
        $whereDivision = '';

        if ($validarDivisionLocal) {
            $whereDivision = ' AND l.id_division = ? ';
            $types .= 'i';
            $params[] = $idDivision;
        }

        $sql = "
            SELECT l.id, l.codigo, l.nombre, l.direccion, l.id_division
            FROM local l
            WHERE l.id_empresa = ?
              AND UPPER(TRIM(l.codigo)) = UPPER(TRIM(?))
              AND (
                    l.deleted_at IS NULL
                    OR CAST(l.deleted_at AS CHAR(19)) = '0000-00-00 00:00:00'
                    OR CAST(l.deleted_at AS CHAR(10)) = '0000-00-00'
                  )
              {$whereDivision}
            LIMIT 1
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function contarLocalesSet(int $idRutaSet): int
    {
        $sql = "SELECT COUNT(DISTINCT id_local) AS total FROM ruta_set_detalle WHERE id_ruta_set = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $idRutaSet);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['total'] ?? 0);
    }

    private function contarUsuariosSet(int $idRutaSet): int
    {
        $sql = "SELECT COUNT(DISTINCT id_usuario) AS total FROM ruta_set_detalle WHERE id_ruta_set = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $idRutaSet);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['total'] ?? 0);
    }

    private function leerArchivoCsv(string $tmpPath, bool $requiereUsuario = true): array
    {
        $delimiter = $this->detectarDelimitador($tmpPath);
        $handle = fopen($tmpPath, 'r');
    
        if (!$handle) {
            throw new RuntimeException('No fue posible leer el archivo subido.');
        }
    
        $header = fgetcsv($handle, 0, $delimiter);
    
        if (!$header) {
            fclose($handle);
            throw new RuntimeException('El archivo no tiene encabezados.');
        }
    
        $map = $this->mapearEncabezados($header);
    
        if (!isset($map['codigo_local'])) {
            fclose($handle);
            throw new RuntimeException('El archivo debe tener la columna codigo_local o codigo.');
        }
    
        if ($requiereUsuario && !isset($map['usuario'])) {
            fclose($handle);
            throw new RuntimeException('El archivo masivo debe tener la columna usuario.');
        }
    
        $rows = [];
        $linea = 1;
    
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            $linea++;
    
            $row = ['_linea' => $linea];
    
            foreach ($map as $campo => $index) {
                $row[$campo] = isset($data[$index]) ? trim((string)$data[$index]) : '';
            }
    
            $rows[] = $row;
        }
    
        fclose($handle);
    
        return $rows;
    }

    private function detectarDelimitador(string $tmpPath): string
    {
        $line = '';
        $handle = fopen($tmpPath, 'r');
        if ($handle) {
            $line = (string)fgets($handle);
            fclose($handle);
        }

        $delimiters = [";", ",", "\t"];
        $bestDelimiter = ';';
        $bestCount = 0;

        foreach ($delimiters as $delimiter) {
            $count = substr_count($line, $delimiter);
            if ($count > $bestCount) {
                $bestCount = $count;
                $bestDelimiter = $delimiter;
            }
        }

        return $bestDelimiter;
    }

    private function mapearEncabezados(array $header): array
    {
        $map = [];

        foreach ($header as $index => $name) {
            $normalized = $this->normalizarHeader((string)$name);

            if (in_array($normalized, ['codigo_local', 'codigolocal', 'codigo', 'cod_local', 'codlocal', 'codigo_sala', 'codigosala'], true)) {
                $map['codigo_local'] = $index;
                continue;
            }

            if (in_array($normalized, ['usuario', 'user', 'ejecutor', 'merchan', 'mercaderista'], true)) {
                $map['usuario'] = $index;
                continue;
            }
        }

        return $map;
    }

    private function normalizarHeader(string $value): string
    {
        $value = str_replace("\xEF\xBB\xBF", '', $value);
        $value = preg_replace('/^\x{FEFF}/u', '', $value);
    
        $value = trim(mb_strtolower((string)$value, 'UTF-8'));
    
        $from = ['á','é','í','ó','ú','ñ','Á','É','Í','Ó','Ú','Ñ'];
        $to   = ['a','e','i','o','u','n','a','e','i','o','u','n'];
    
        $value = str_replace($from, $to, $value);
        $value = preg_replace('/[^a-z0-9]+/u', '_', $value);
        $value = trim((string)$value, '_');
    
        return $value;
    }
    
    private function obtenerSetEditable(int $idRutaSet): array
    {
        $sql = "
            SELECT
                id,
                id_empresa,
                id_division,
                id_usuario,
                tipo_scope,
                estado,
                id_formulario_generado
            FROM ruta_set
            WHERE id = ?
              AND id_empresa = ?
            LIMIT 1
        ";
    
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('ii', $idRutaSet, $this->idEmpresa);
        $stmt->execute();
    
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    
        if (!$row) {
            throw new RuntimeException('El set seleccionado no existe o no pertenece a esta empresa.');
        }
    
        if ((string)$row['estado'] !== 'borrador') {
            throw new RuntimeException('Solo se pueden modificar o eliminar sets en estado borrador.');
        }
    
        if (!empty($row['id_formulario_generado'])) {
            throw new RuntimeException('Este set ya tiene un formulario generado y no puede modificarse.');
        }
    
        return $row;
    }

public function eliminarSet(int $idRutaSet, string $tipoScope = '', int $idUsuarioFijo = 0): array
{
    if ($idRutaSet <= 0) {
        throw new RuntimeException('No fue posible identificar el set a eliminar.');
    }

    $set = $this->obtenerSetEditable($idRutaSet);

    $tipoScope = strtolower(trim($tipoScope));

    if ($tipoScope !== '' && in_array($tipoScope, ['individual', 'masiva'], true)) {
        if ((string)$set['tipo_scope'] !== $tipoScope) {
            throw new RuntimeException('El tipo del set no coincide con la operación solicitada.');
        }
    }

    if ((string)$set['tipo_scope'] === 'individual' && $idUsuarioFijo > 0) {
        if ((int)$set['id_usuario'] !== $idUsuarioFijo) {
            throw new RuntimeException('Este set individual no pertenece al trabajador seleccionado.');
        }
    }

    $this->conn->begin_transaction();

    try {
        /*
            Primero eliminamos el detalle.
            Aunque tengas FK con ON DELETE CASCADE, esto evita depender
            de la configuración exacta de la tabla.
        */
        $stmtDetalle = $this->conn->prepare("
            DELETE FROM ruta_set_detalle
            WHERE id_ruta_set = ?
        ");
        $stmtDetalle->bind_param('i', $idRutaSet);
        $stmtDetalle->execute();
        $stmtDetalle->close();

        $stmtSet = $this->conn->prepare("
            DELETE FROM ruta_set
            WHERE id = ?
              AND id_empresa = ?
              AND estado = 'borrador'
              AND id_formulario_generado IS NULL
        ");
        $stmtSet->bind_param('ii', $idRutaSet, $this->idEmpresa);
        $stmtSet->execute();

        $eliminados = $stmtSet->affected_rows;
        $stmtSet->close();

        if ($eliminados <= 0) {
            throw new RuntimeException('No fue posible eliminar el set.');
        }

        $this->conn->commit();

        return [
            'id_ruta_set' => $idRutaSet,
            'eliminado' => true,
        ];

    } catch (Throwable $e) {
        $this->conn->rollback();
        throw $e;
    }
}

private function validarCategoria(int $idCategoria, int $idDivision): void
{
    $sql = "
        SELECT id
        FROM ruta_set_categoria
        WHERE id = ?
          AND id_empresa = ?
          AND id_division = ?
          AND estado = 'activa'
        LIMIT 1
    ";

    $stmt = $this->conn->prepare($sql);
    $stmt->bind_param('iii', $idCategoria, $this->idEmpresa, $idDivision);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new RuntimeException('La categoría seleccionada no existe, no está activa o no pertenece a la división seleccionada.');
    }
}
}
