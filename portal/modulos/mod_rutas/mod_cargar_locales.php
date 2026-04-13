<?php
declare(strict_types=1);

session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('memory_limit', '512M');
set_time_limit(180);

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

mysqli_set_charset($conn, 'utf8mb4');
date_default_timezone_set('America/Santiago');

function json_fail(string $message, int $code = 400, array $extra = []): void
{
    http_response_code($code);
    echo json_encode(array_merge([
        'success' => false,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function normalize_text(string $value): string
{
    $value = trim($value);
    $map = [
        'Á'=>'A','À'=>'A','Ä'=>'A','Â'=>'A','á'=>'a','à'=>'a','ä'=>'a','â'=>'a',
        'É'=>'E','È'=>'E','Ë'=>'E','Ê'=>'E','é'=>'e','è'=>'e','ë'=>'e','ê'=>'e',
        'Í'=>'I','Ì'=>'I','Ï'=>'I','Î'=>'I','í'=>'i','ì'=>'i','ï'=>'i','î'=>'i',
        'Ó'=>'O','Ò'=>'O','Ö'=>'O','Ô'=>'O','ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o',
        'Ú'=>'U','Ù'=>'U','Ü'=>'U','Û'=>'U','ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u',
        'Ñ'=>'N','ñ'=>'n'
    ];
    $value = strtr($value, $map);
    $value = mb_strtolower($value, 'UTF-8');
    $value = preg_replace('/\s+/', ' ', $value);
    return trim($value);
}

function find_header_index(array $headers, array $aliases): int
{
    $normalizedHeaders = [];
    foreach ($headers as $idx => $header) {
        $normalizedHeaders[$idx] = normalize_text((string)$header);
    }

    foreach ($aliases as $alias) {
        $aliasNorm = normalize_text($alias);
        foreach ($normalizedHeaders as $idx => $headerNorm) {
            if ($headerNorm === $aliasNorm) {
                return $idx;
            }
        }
    }

    return -1;
}

function has_valid_coords(array $local): bool
{
    return isset($local['lat'], $local['lng'])
        && $local['lat'] !== null
        && $local['lng'] !== null
        && $local['lat'] !== ''
        && $local['lng'] !== ''
        && is_numeric((string)$local['lat'])
        && is_numeric((string)$local['lng']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('Método no permitido.', 405);
}

if (!isset($_FILES['csvFile']) || !is_array($_FILES['csvFile'])) {
    json_fail('No se recibió el archivo CSV.');
}

$file = $_FILES['csvFile'];

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_fail('Error al subir el archivo.');
}

$tmpPath = $file['tmp_name'] ?? '';
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    json_fail('Archivo temporal inválido.');
}

$handle = fopen($tmpPath, 'r');
if (!$handle) {
    json_fail('No se pudo abrir el archivo CSV.');
}

// Detectar BOM UTF-8
$firstLine = fgets($handle);
if ($firstLine === false) {
    fclose($handle);
    json_fail('El archivo CSV está vacío.');
}
$firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);

// Reiniciar lectura
rewind($handle);

// Intentar delimitadores
$sample = $firstLine;
$delimiters = [',', ';', "\t"];
$bestDelimiter = ',';
$bestCount = -1;

foreach ($delimiters as $delimiter) {
    $count = count(str_getcsv($sample, $delimiter));
    if ($count > $bestCount) {
        $bestCount = $count;
        $bestDelimiter = $delimiter;
    }
}

$headers = fgetcsv($handle, 0, $bestDelimiter);
if ($headers === false || empty($headers)) {
    fclose($handle);
    json_fail('No se pudo leer la cabecera del CSV.');
}

$headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);

$idxCodigo = find_header_index($headers, ['codigo', 'codigo_local', 'local', 'cod_local']);
$idxUsuario = find_header_index($headers, ['usuario', 'user', 'ejecutor', 'merchan']);

if ($idxCodigo < 0) {
    fclose($handle);
    json_fail('El CSV debe contener una columna llamada "codigo".');
}

if ($idxUsuario < 0) {
    fclose($handle);
    json_fail('El CSV debe contener una columna llamada "usuario".');
}

$rows = [];
$rowNumber = 1;

while (($data = fgetcsv($handle, 0, $bestDelimiter)) !== false) {
    $rowNumber++;

    if ($data === [null] || $data === false) {
        continue;
    }

    $codigo = trim((string)($data[$idxCodigo] ?? ''));
    $usuarioInput = trim((string)($data[$idxUsuario] ?? ''));

    if ($codigo === '' && $usuarioInput === '') {
        continue;
    }

    $rows[] = [
        'fila_csv' => $rowNumber,
        'codigo' => $codigo,
        'usuario_input' => $usuarioInput,
    ];
}

fclose($handle);

if (empty($rows)) {
    json_fail('El CSV no contiene filas válidas.');
}

// Obtener códigos únicos
$codigos = [];
$usuariosInput = [];

foreach ($rows as $row) {
    if ($row['codigo'] !== '') {
        $codigos[$row['codigo']] = true;
    }
    if ($row['usuario_input'] !== '') {
        $usuariosInput[$row['usuario_input']] = true;
    }
}

$codigos = array_keys($codigos);
$usuariosInput = array_keys($usuariosInput);

// Buscar locales
$localesMap = [];
if (!empty($codigos)) {
    $placeholders = implode(',', array_fill(0, count($codigos), '?'));
    $types = str_repeat('s', count($codigos));

    $sqlLocales = "
        SELECT
            l.codigo,
            l.nombre,
            l.direccion,
            c.comuna,
            l.lat,
            l.lng
        FROM local l
        LEFT JOIN comuna c
            ON c.id = l.id_comuna
        WHERE l.codigo IN ($placeholders)
          AND l.deleted_at IS NULL
    ";

    $stmtLocales = $conn->prepare($sqlLocales);
    if (!$stmtLocales) {
        json_fail('Error al preparar consulta de locales: ' . $conn->error, 500);
    }

    $stmtLocales->bind_param($types, ...$codigos);

    if (!$stmtLocales->execute()) {
        $msg = $stmtLocales->error;
        $stmtLocales->close();
        json_fail('Error al ejecutar consulta de locales: ' . $msg, 500);
    }

    $resLocales = $stmtLocales->get_result();
    while ($row = $resLocales->fetch_assoc()) {
        $codigo = trim((string)$row['codigo']);
        $localesMap[$codigo] = [
            'codigo'    => $codigo,
            'nombre'    => $row['nombre'] ?? '',
            'direccion' => $row['direccion'] ?? '',
            'comuna'    => $row['comuna'] ?? '',
            'lat'       => $row['lat'],
            'lng'       => $row['lng'],
        ];
    }
    $stmtLocales->close();
}

// Buscar usuarios
$usuariosMap = [];
if (!empty($usuariosInput)) {
    $sqlUsuarios = "
        SELECT
            u.id,
            u.usuario,
            u.nombre,
            u.apellido,
            u.activo
        FROM usuario u
        WHERE u.activo = 1
    ";

    $resUsuarios = $conn->query($sqlUsuarios);
    if (!$resUsuarios) {
        json_fail('Error al consultar usuarios: ' . $conn->error, 500);
    }

    while ($u = $resUsuarios->fetch_assoc()) {
        $id = (int)($u['id'] ?? 0);
        $login = trim((string)($u['usuario'] ?? ''));
        $nombre = trim((string)($u['nombre'] ?? ''));
        $apellido = trim((string)($u['apellido'] ?? ''));
        $nombreCompleto = trim($nombre . ' ' . $apellido);

        $payload = [
            'usuario_id' => $id,
            'usuario_login' => $login,
            'usuario_nombre' => $nombreCompleto,
        ];

        if ($login !== '') {
            $usuariosMap[normalize_text($login)] = $payload;
        }

        if ($id > 0) {
            $usuariosMap[(string)$id] = $payload;
        }

        if ($nombreCompleto !== '') {
            $usuariosMap[normalize_text($nombreCompleto)] = $payload;
        }
    }
}

$encontradosValidos = [];
$localesNoEncontrados = [];
$usuariosNoValidos = [];
$filasInvalidas = [];

foreach ($rows as $row) {
    $codigo = $row['codigo'];
    $usuarioInput = $row['usuario_input'];
    $filaCsv = $row['fila_csv'];

    $errores = [];

    if ($codigo === '') {
        $errores[] = 'Código vacío';
    }
    if ($usuarioInput === '') {
        $errores[] = 'Usuario vacío';
    }

    $local = $localesMap[$codigo] ?? null;
    if ($codigo !== '' && !$local) {
        $errores[] = 'Código de local no existe';
    }

    $usuarioMatch = null;
    if ($usuarioInput !== '') {
        $keyNorm = normalize_text($usuarioInput);
        if (isset($usuariosMap[$keyNorm])) {
            $usuarioMatch = $usuariosMap[$keyNorm];
        } elseif (isset($usuariosMap[(string)$usuarioInput])) {
            $usuarioMatch = $usuariosMap[(string)$usuarioInput];
        }
    }

    if ($usuarioInput !== '' && !$usuarioMatch) {
        $errores[] = 'Usuario no existe';
    }

    if (!empty($errores)) {
        $filaError = [
            'fila_csv' => $filaCsv,
            'codigo' => $codigo,
            'usuario_input' => $usuarioInput,
            'motivo' => implode(' | ', $errores),
        ];

        $filasInvalidas[] = $filaError;

        if ($codigo !== '' && !$local) {
            $localesNoEncontrados[] = [
                'fila_csv' => $filaCsv,
                'codigo' => $codigo,
                'usuario_input' => $usuarioInput,
                'motivo' => 'Código no existe en local',
            ];
        }

        if ($usuarioInput !== '' && !$usuarioMatch) {
            $usuariosNoValidos[] = [
                'fila_csv' => $filaCsv,
                'codigo' => $codigo,
                'usuario_input' => $usuarioInput,
                'motivo' => 'Usuario no existe en tabla usuario',
            ];
        }

        continue;
    }

    $encontradosValidos[] = [
        'fila_csv'       => $filaCsv,
        'codigo'         => $local['codigo'],
        'nombre'         => $local['nombre'],
        'direccion'      => $local['direccion'],
        'comuna'         => $local['comuna'],
        'lat'            => $local['lat'],
        'lng'            => $local['lng'],
        'tiene_coords'   => has_valid_coords($local),
        'usuario_input'  => $usuarioInput,
        'usuario_id'     => $usuarioMatch['usuario_id'],
        'usuario_login'  => $usuarioMatch['usuario_login'],
        'usuario_nombre' => $usuarioMatch['usuario_nombre'],
    ];
}

echo json_encode([
    'success' => true,
    'message' => 'Archivo procesado correctamente.',
    'total_csv' => count($rows),
    'encontrados' => $encontradosValidos,
    'no_encontrados' => $localesNoEncontrados,
    'usuarios_no_validos' => $usuariosNoValidos,
    'filas_invalidas' => $filasInvalidas,
    'resumen' => [
        'validos' => count($encontradosValidos),
        'locales_no_encontrados' => count($localesNoEncontrados),
        'usuarios_no_validos' => count($usuariosNoValidos),
        'filas_invalidas' => count($filasInvalidas),
    ],
], JSON_UNESCAPED_UNICODE);