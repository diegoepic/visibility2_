<?php
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
$conn->set_charset("utf8");

// Capturar parámetros
$formato   = $_GET['formato']  ?? 'csv';
$perfil    = (isset($_GET['perfil'])    && $_GET['perfil']    !== '') ? intval($_GET['perfil'])    : null;
$division  = (isset($_GET['division'])  && $_GET['division']  !== '') ? intval($_GET['division'])  : null;

// Construir cláusula WHERE dinámica
$filtros = '';
if ($perfil !== null) {
    $filtros .= " AND u.id_perfil   = $perfil";
}
if ($division !== null) {
    $filtros .= " AND u.id_division = $division";
}

// Consulta principal (nota el WHERE correctamente escrito)
$query = "
    SELECT 
        UPPER(u.rut)          AS RUT,
        UPPER(u.nombre)       AS NOMBRE,
        UPPER(u.apellido)     AS APELLIDO,
        UPPER(u.telefono)     AS TELEFONO,
        UPPER(u.email)        AS CORREO,
        UPPER(u.usuario)      AS USUARIO,
        UPPER(de.nombre)      AS DIVISION,
        u.fechaCreacion       AS `FECHA CREACION`,
        u.login_count         AS LOGINS,
        u.last_login          AS `ULTIMO LOGIN`,
        UPPER(p.nombre)       AS PERFIL
    FROM usuario u
    JOIN division_empresa de ON de.id = u.id_division
    JOIN perfil p            ON p.id  = u.id_perfil
    WHERE 1=1
      $filtros
";

// Ejecutar
$result = $conn->query($query);
if (!$result) {
    die("Error en consulta: " . $conn->error);
}

if ($result->num_rows > 0) {
    // Preparar CSV
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="data_usuarios.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM

    // Encabezados
    $first = $result->fetch_assoc();
    fputcsv($out, array_keys($first), ';');
    fputcsv($out, $first, ';');

    // El resto de filas
    while ($row = $result->fetch_assoc()) {
        fputcsv($out, $row, ';');
    }
    fclose($out);
} else {
    echo "No hay datos disponibles para exportar.";
}
$conn->close();
