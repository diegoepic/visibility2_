<?php
declare(strict_types=1);

// mod_user/procesar_csv.php

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

/* =========================================================
   Helpers
========================================================= */
function redirectWithMessage(string $type, string $message): void
{
    $_SESSION[$type] = $message;
    header("Location: ../mod_create_user.php");
    exit();
}

function limpiarTexto(?string $value): string
{
    return trim((string)($value ?? ''));
}

function normalizarHeader(string $col): string
{
    $col = trim($col);
    $col = mb_strtolower($col, 'UTF-8');
    $col = str_replace(["\xEF\xBB\xBF", ' '], '', $col);
    return $col;
}

function obtenerValorFila(array $data, int $index): string
{
    return isset($data[$index]) ? trim((string)$data[$index]) : '';
}

function detectarDelimitador(string $filePath): string
{
    $handle = fopen($filePath, 'r');
    if ($handle === false) {
        return ';';
    }

    $firstBytes = fread($handle, 3);
    if ($firstBytes !== "\xEF\xBB\xBF") {
        fseek($handle, 0);
    }

    $sample = fgets($handle);
    fclose($handle);

    if ($sample === false) {
        return ';';
    }

    return (substr_count($sample, ';') >= substr_count($sample, ',')) ? ';' : ',';
}

function empresaExiste(int $empresaId, array $empresas): bool
{
    foreach ($empresas as $empresa) {
        if ((int)$empresa['id'] === $empresaId) {
            return true;
        }
    }
    return false;
}

function divisionValidaParaEmpresa(int $divisionId, int $empresaId): bool
{
    if ($divisionId <= 0) {
        return true;
    }

    $divisiones = obtenerDivisionesPorEmpresa($empresaId);
    foreach ($divisiones as $division) {
        if ((int)$division['id'] === $divisionId) {
            return true;
        }
    }

    return false;
}

/* =========================================================
   CSRF / método
========================================================= */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithMessage('error_formulario', 'Método de solicitud inválido.');
}

if (
    !isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
    !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])
) {
    redirectWithMessage('error_formulario', 'Solicitud inválida (CSRF).');
}

/* =========================================================
   Archivo
========================================================= */
if (!isset($_FILES['csvFile']) || $_FILES['csvFile']['error'] !== UPLOAD_ERR_OK) {
    redirectWithMessage('error_formulario', 'Debes subir un archivo CSV válido.');
}

$fileTmpPath   = $_FILES['csvFile']['tmp_name'];
$fileName      = $_FILES['csvFile']['name'] ?? '';
$fileSize      = (int)($_FILES['csvFile']['size'] ?? 0);
$fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

if ($fileExtension !== 'csv') {
    redirectWithMessage('error_formulario', 'Solo se permiten archivos CSV.');
}

if ($fileSize <= 0) {
    redirectWithMessage('error_formulario', 'El archivo CSV está vacío.');
}

if ($fileSize > 10 * 1024 * 1024) {
    redirectWithMessage('error_formulario', 'El archivo CSV supera el tamaño máximo permitido de 10MB.');
}

/* =========================================================
   Empresa / división
========================================================= */
$id_empresa_csv = isset($_POST['empresa_id_csv']) ? (int)$_POST['empresa_id_csv'] : 0;
$id_division_csv = isset($_POST['division_id']) && $_POST['division_id'] !== ''
    ? (int)$_POST['division_id']
    : 0;

if ($id_empresa_csv <= 0) {
    redirectWithMessage('error_formulario', 'Debes seleccionar una empresa para la carga masiva.');
}

$empresas = obtenerEmpresasActivas();
if (!empresaExiste($id_empresa_csv, $empresas)) {
    redirectWithMessage('error_formulario', 'La empresa seleccionada no es válida.');
}

if (!divisionValidaParaEmpresa($id_division_csv, $id_empresa_csv)) {
    redirectWithMessage('error_formulario', 'La división seleccionada no pertenece a la empresa indicada.');
}

/* =========================================================
   Preparar subida
========================================================= */
$uploadFileDir = '../uploads/csv/';
if (!is_dir($uploadFileDir) && !mkdir($uploadFileDir, 0755, true)) {
    redirectWithMessage('error_formulario', 'No se pudo crear el directorio de subida.');
}

$newFileName = 'usuarios_' . date('Ymd_His') . '_' . uniqid() . '.csv';
$dest_path = $uploadFileDir . $newFileName;

if (!move_uploaded_file($fileTmpPath, $dest_path)) {
    redirectWithMessage('error_formulario', 'Hubo un error al subir el archivo CSV.');
}

/* =========================================================
   Procesamiento CSV
========================================================= */
$handle = fopen($dest_path, 'r');
if ($handle === false) {
    @unlink($dest_path);
    redirectWithMessage('error_formulario', 'No se pudo abrir el archivo CSV.');
}

$delimiter = detectarDelimitador($dest_path);

// BOM UTF-8
$firstBytes = fread($handle, 3);
if ($firstBytes !== "\xEF\xBB\xBF") {
    fseek($handle, 0);
}

$header = fgetcsv($handle, 0, $delimiter);
if ($header === false) {
    fclose($handle);
    @unlink($dest_path);
    redirectWithMessage('error_formulario', 'El archivo CSV está vacío o no contiene encabezados válidos.');
}

$header_normalizado = array_map('normalizarHeader', $header);

$columnas_requeridas = ['rut', 'nombre', 'apellido', 'telefono', 'email', 'usuario', 'password'];
$columnas_faltantes = array_diff($columnas_requeridas, $header_normalizado);

if (!empty($columnas_faltantes)) {
    fclose($handle);
    @unlink($dest_path);
    redirectWithMessage(
        'error_formulario',
        "El archivo CSV no contiene las columnas requeridas: " .
        implode(', ', $columnas_faltantes) .
        ".<br>Encabezados encontrados: " . implode(', ', $header_normalizado)
    );
}

$rut_idx      = array_search('rut', $header_normalizado, true);
$nombre_idx   = array_search('nombre', $header_normalizado, true);
$apellido_idx = array_search('apellido', $header_normalizado, true);
$telefono_idx = array_search('telefono', $header_normalizado, true);
$email_idx    = array_search('email', $header_normalizado, true);
$usuario_idx  = array_search('usuario', $header_normalizado, true);
$password_idx = array_search('password', $header_normalizado, true);

/* =========================================================
   Perfil fijo
========================================================= */
$id_perfil_ejecutor = obtenerIdPerfilEjecutor();
if ($id_perfil_ejecutor === null) {
    fclose($handle);
    @unlink($dest_path);
    redirectWithMessage('error_formulario', "Perfil 'ejecutor' no encontrado en el sistema.");
}

/* =========================================================
   SQL
========================================================= */
$stmt_insert = $conn->prepare("
    INSERT INTO usuario
        (rut, nombre, apellido, telefono, email, usuario, clave, fechaCreacion, activo, id_perfil, id_empresa, id_division, login_count, last_login)
    VALUES
        (?, ?, ?, ?, ?, ?, ?, NOW(), 1, ?, ?, ?, 1, NOW())
");

if (!$stmt_insert) {
    fclose($handle);
    @unlink($dest_path);
    redirectWithMessage(
        'error_formulario',
        'Error en la preparación de la consulta de usuario: ' . htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8')
    );
}

/* =========================================================
   Lectura de filas
========================================================= */
$fila = 1;
$errores = [];
$exitosos = 0;

// Detectar duplicados dentro del mismo CSV
$rutsCsv = [];
$emailsCsv = [];
$usuariosCsv = [];

$conn->begin_transaction();

try {
    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $fila++;

        // Saltar filas completamente vacías
        $filaTexto = implode('', array_map('trim', $data));
        if ($filaTexto === '') {
            continue;
        }

        $rut_input = obtenerValorFila($data, (int)$rut_idx);
        $nombre    = obtenerValorFila($data, (int)$nombre_idx);
        $apellido  = obtenerValorFila($data, (int)$apellido_idx);
        $telefono  = obtenerValorFila($data, (int)$telefono_idx);
        $email     = obtenerValorFila($data, (int)$email_idx);
        $usuario   = obtenerValorFila($data, (int)$usuario_idx);
        $clave     = obtenerValorFila($data, (int)$password_idx);

        if ($rut_input === '' || $nombre === '' || $apellido === '' || $telefono === '' || $email === '' || $usuario === '' || $clave === '') {
            $errores[] = "Fila {$fila}: hay campos obligatorios vacíos.";
            continue;
        }

        $rut_estandarizado = preg_replace('/[^0-9kK]/', '', $rut_input);
        $rut_estandarizado = strtoupper((string)$rut_estandarizado);

        if (!validarRut($rut_estandarizado)) {
            $errores[] = "Fila {$fila}: RUT inválido.";
            continue;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errores[] = "Fila {$fila}: correo electrónico inválido.";
            continue;
        }

        $rutKey = $rut_estandarizado;
        $emailKey = mb_strtolower($email, 'UTF-8');
        $usuarioKey = mb_strtolower($usuario, 'UTF-8');

        if (isset($rutsCsv[$rutKey])) {
            $errores[] = "Fila {$fila}: el RUT '{$rut_estandarizado}' está repetido dentro del mismo CSV.";
            continue;
        }
        $rutsCsv[$rutKey] = true;

        if (isset($emailsCsv[$emailKey])) {
            $errores[] = "Fila {$fila}: el correo '{$email}' está repetido dentro del mismo CSV.";
            continue;
        }
        $emailsCsv[$emailKey] = true;

        if (isset($usuariosCsv[$usuarioKey])) {
            $errores[] = "Fila {$fila}: el usuario '{$usuario}' está repetido dentro del mismo CSV.";
            continue;
        }
        $usuariosCsv[$usuarioKey] = true;

        if (existeRut($rut_estandarizado)) {
            $errores[] = "Fila {$fila}: el RUT '{$rut_estandarizado}' ya existe.";
            continue;
        }

        if (existeUsuario($email, $usuario)) {
            $errores[] = "Fila {$fila}: el email '{$email}' o el usuario '{$usuario}' ya están registrados.";
            continue;
        }

        $hashed_password = password_hash($clave, PASSWORD_DEFAULT);

        $stmt_insert->bind_param(
            'sssssssiii',
            $rut_estandarizado,
            $nombre,
            $apellido,
            $telefono,
            $email,
            $usuario,
            $hashed_password,
            $id_perfil_ejecutor,
            $id_empresa_csv,
            $id_division_csv
        );

        if ($stmt_insert->execute()) {
            $exitosos++;
        } else {
            $errores[] = "Fila {$fila}: error al insertar el usuario en la base de datos.";
        }
    }

    // Mantengo tu lógica: inserta válidos aunque existan errores en otras filas
    $conn->commit();

} catch (Throwable $e) {
    $conn->rollback();
    fclose($handle);
    $stmt_insert->close();
    @unlink($dest_path);

    redirectWithMessage('error_formulario', 'Ocurrió un error inesperado al procesar el CSV: ' . $e->getMessage());
}

fclose($handle);
$stmt_insert->close();
@unlink($dest_path);

/* =========================================================
   Mensajes finales
========================================================= */
if ($exitosos > 0) {
    $_SESSION['success_formulario'] = "Se insertaron {$exitosos} usuarios correctamente.";
}

if (!empty($errores)) {
    $_SESSION['error_formulario'] = "Errores durante la carga masiva:<br>" . implode("<br>", $errores);
}

if ($exitosos === 0 && empty($errores)) {
    $_SESSION['error_formulario'] = "No se procesaron registros.";
}

header("Location: ../mod_create_user.php");
exit();