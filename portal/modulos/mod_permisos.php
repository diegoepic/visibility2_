<?php
declare(strict_types=1);

require_once __DIR__ . '/../_session_guard.php';
require_once __DIR__ . '/../includes/permisos.php';

requiereModulo($conn, 'permisos.admin');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$mensaje = '';
$error = '';
$tablasPermisosListas = vc_permiso_db_disponible($conn);

if (!$tablasPermisosListas) {
    $error = 'Primero importa el archivo sql_modulo_permisos.sql para crear las tablas de permisos.';
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function permiso_catalogo(mysqli $conn, string $sql): array
{
    $res = $conn->query($sql);
    if (!$res instanceof mysqli_result) {
        return [];
    }

    $items = [];
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }
    $res->free();

    return $items;
}

function permiso_post_int(string $key): ?int
{
    if (!isset($_POST[$key]) || $_POST[$key] === '') {
        return null;
    }

    return max(0, (int)$_POST[$key]);
}

function permiso_desactivar_perfil(mysqli $conn, int $idModulo, ?int $idPerfil, ?int $idEmpresa, ?int $idDivision, ?int $idSubdivision): void
{
    $stmt = $conn->prepare(
        "UPDATE modulo_permiso
            SET activo = 0
          WHERE id_modulo = ?
            AND id_perfil <=> ?
            AND id_empresa <=> ?
            AND id_division <=> ?
            AND id_subdivision <=> ?"
    );
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('iiiii', $idModulo, $idPerfil, $idEmpresa, $idDivision, $idSubdivision);
    $stmt->execute();
    $stmt->close();
}

function permiso_desactivar_usuario(mysqli $conn, int $idModulo, int $idUsuario): void
{
    $stmt = $conn->prepare(
        "UPDATE usuario_permiso_modulo
            SET activo = 0
          WHERE id_modulo = ?
            AND id_usuario = ?"
    );
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('ii', $idModulo, $idUsuario);
    $stmt->execute();
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['csrf_token'], $token)) {
        $error = 'Solicitud invalida. Actualiza la pagina e intenta nuevamente.';
    } elseif (!$tablasPermisosListas) {
        $error = 'Faltan las tablas de permisos.';
    } else {
        $accion = (string)($_POST['accion'] ?? '');

        if ($accion === 'guardar_matriz_perfil') {
            $idPerfil = permiso_post_int('id_perfil');
            $idEmpresa = permiso_post_int('id_empresa');
            $idDivision = permiso_post_int('id_division');
            $idSubdivision = permiso_post_int('id_subdivision');
            $permisos = $_POST['permiso'] ?? [];

            if (!$idPerfil || !$idEmpresa) {
                $error = 'Selecciona empresa y perfil antes de guardar.';
            } elseif (!is_array($permisos)) {
                $error = 'No se recibieron permisos validos.';
            } else {
                foreach ($permisos as $idModulo => $valor) {
                    $idModulo = (int)$idModulo;
                    $valor = (string)$valor;
                    if ($idModulo <= 0 || !in_array($valor, ['inherit', '1', '0'], true)) {
                        continue;
                    }

                    permiso_desactivar_perfil($conn, $idModulo, $idPerfil, $idEmpresa, $idDivision, $idSubdivision);

                    if ($valor === 'inherit') {
                        continue;
                    }

                    $permitido = ($valor === '1') ? 1 : 0;
                    $stmt = $conn->prepare(
                        "INSERT INTO modulo_permiso
                            (id_modulo, id_perfil, id_empresa, id_division, id_subdivision, permitido, activo)
                         VALUES (?, ?, ?, ?, ?, ?, 1)"
                    );
                    if ($stmt) {
                        $stmt->bind_param('iiiiii', $idModulo, $idPerfil, $idEmpresa, $idDivision, $idSubdivision, $permitido);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
                $mensaje = 'Permisos por perfil/division guardados.';
            }
        }

        if ($accion === 'guardar_matriz_usuario') {
            $idUsuario = permiso_post_int('id_usuario');
            $permisos = $_POST['permiso'] ?? [];

            if (!$idUsuario) {
                $error = 'Selecciona un usuario antes de guardar.';
            } elseif (!is_array($permisos)) {
                $error = 'No se recibieron permisos validos.';
            } else {
                foreach ($permisos as $idModulo => $valor) {
                    $idModulo = (int)$idModulo;
                    $valor = (string)$valor;
                    if ($idModulo <= 0 || !in_array($valor, ['inherit', '1', '0'], true)) {
                        continue;
                    }

                    permiso_desactivar_usuario($conn, $idModulo, $idUsuario);

                    if ($valor === 'inherit') {
                        continue;
                    }

                    $permitido = ($valor === '1') ? 1 : 0;
                    $stmt = $conn->prepare(
                        "INSERT INTO usuario_permiso_modulo
                            (id_usuario, id_modulo, permitido, activo)
                         VALUES (?, ?, ?, 1)"
                    );
                    if ($stmt) {
                        $stmt->bind_param('iii', $idUsuario, $idModulo, $permitido);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
                $mensaje = 'Permisos por usuario guardados.';
            }
        }
    }
}

$modulos = $tablasPermisosListas
    ? permiso_catalogo($conn, "
        SELECT id, clave, nombre, descripcion
          FROM modulo
         WHERE activo = 1
           AND visible_permisos = 1
         ORDER BY orden, clave
      ")
    : [];

$empresas = permiso_catalogo($conn, "SELECT id, nombre FROM empresa ORDER BY nombre");
$divisiones = permiso_catalogo($conn, "SELECT id, id_empresa, nombre FROM division_empresa WHERE estado = 1 ORDER BY nombre");
$subdivisiones = permiso_catalogo($conn, "SELECT id, id_division, nombre FROM subdivision ORDER BY nombre");
$perfiles = permiso_catalogo($conn, "SELECT id, nombre FROM perfil ORDER BY nombre");
$usuarios = permiso_catalogo($conn, "
    SELECT u.id, u.nombre, u.apellido, u.usuario, u.id_empresa, u.id_division, u.id_perfil, p.nombre AS perfil
      FROM usuario u
      LEFT JOIN perfil p ON p.id = u.id_perfil
     WHERE u.activo = 1
     ORDER BY u.nombre, u.apellido
");

$permisosPerfil = $tablasPermisosListas
    ? permiso_catalogo($conn, "
        SELECT id_modulo, id_perfil, id_empresa, id_division, id_subdivision, permitido
          FROM modulo_permiso
         WHERE activo = 1
         ORDER BY id ASC
      ")
    : [];

$permisosUsuario = $tablasPermisosListas
    ? permiso_catalogo($conn, "
        SELECT id_modulo, id_usuario, permitido
          FROM usuario_permiso_modulo
         WHERE activo = 1
         ORDER BY id ASC
      ")
    : [];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Permisos de modulos</title>
  <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
  <link rel="stylesheet" href="../dist/css/adminlte.min.css">
<style>
    :root {
        --navy: #14264a;
        --primary: #5278ff;
        --primary-2: #675cff;
        --blue: #2ea7ff;
        --green: #1fc765;
        --danger: #ef4444;
        --warning: #f59e0b;

        --page: #edf4ff;
        --card: rgba(255, 255, 255, .84);
        --line: #d7e4f7;
        --line-soft: #e9f0fb;
        --text: #13284e;
        --muted: #71809a;

        --radius-xl: 30px;
        --radius-lg: 24px;
        --radius-md: 16px;

        --shadow-soft: 0 22px 55px rgba(38, 67, 118, .14);
        --shadow-card: 0 16px 38px rgba(38, 67, 118, .11);
        --shadow-small: 0 8px 20px rgba(38, 67, 118, .10);
    }

    * {
        box-sizing: border-box;
    }

    html,
    body {
        min-height: 100%;
    }

    body {
        margin: 0;
        background:
            radial-gradient(circle at 8% 5%, rgba(82, 120, 255, .20), transparent 31%),
            radial-gradient(circle at 92% 22%, rgba(73, 210, 164, .10), transparent 28%),
            linear-gradient(135deg, #eaf2ff 0%, #f8fbff 50%, #edf4ff 100%);
        color: var(--text);
        font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
        font-size: 13px;
        overflow-x: hidden;
    }

    body::before {
        content: "";
        position: fixed;
        inset: 0;
        pointer-events: none;
        background:
            linear-gradient(115deg, rgba(255,255,255,.48), transparent 38%),
            linear-gradient(270deg, rgba(255,255,255,.42), transparent 43%);
        z-index: -1;
    }

    .content.p-3 {
        padding: 30px 0 44px !important;
    }

    .container-fluid {
        width: calc(100% - 96px);
        max-width: none;
        padding: 0;
        margin: 0 auto;
    }

    /* =========================
       HERO SUPERIOR
    ========================== */

    .container-fluid > .d-flex:first-child {
        position: relative;
        overflow: hidden;
        min-height: 108px;
        margin-bottom: 24px !important;
        padding: 28px 32px 28px 100px;
        background: rgba(255, 255, 255, .80);
        border: 1px solid rgba(190, 207, 235, .88);
        border-radius: var(--radius-xl);
        box-shadow: var(--shadow-soft);
        backdrop-filter: blur(18px);
        -webkit-backdrop-filter: blur(18px);
    }

    .container-fluid > .d-flex:first-child::before {
        content: "\f3ed";
        position: absolute;
        left: 26px;
        top: 50%;
        transform: translateY(-50%);
        width: 58px;
        height: 58px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 20px;
        color: #5578ff;
        font-family: "Font Awesome 5 Free";
        font-weight: 900;
        font-size: 25px;
        background: linear-gradient(145deg, #eef6ff, #dfeaff);
        box-shadow:
            inset 0 1px 0 rgba(255,255,255,.95),
            0 14px 26px rgba(82, 120, 255, .18);
        z-index: 1;
    }

    .container-fluid > .d-flex:first-child::after {
        content: "";
        position: absolute;
        inset: 0;
        background:
            linear-gradient(135deg, rgba(82, 120, 255, .13), transparent 39%),
            radial-gradient(circle at 88% 0%, rgba(255,255,255,.92), transparent 36%);
        pointer-events: none;
    }

    .container-fluid > .d-flex:first-child > * {
        position: relative;
        z-index: 2;
    }

    h1.h4 {
        margin: 0;
        color: var(--navy);
        font-size: 29px;
        font-weight: 950;
        line-height: 1.08;
        letter-spacing: -.04em;
    }

    .container-fluid > .d-flex:first-child .text-muted {
        margin-top: 8px;
        color: var(--muted) !important;
        font-size: 12px;
        font-weight: 850;
        letter-spacing: -.01em;
    }

    /* =========================
       ALERTAS
    ========================== */

    .alert {
        border: 0;
        border-radius: 20px;
        padding: 15px 18px;
        font-size: 13px;
        font-weight: 800;
        box-shadow: var(--shadow-small);
    }

    .alert-success {
        background: #e8fff2;
        color: #166534;
    }

    .alert-danger {
        background: #fff0f0;
        color: #991b1b;
    }

    /* =========================
       TABS
    ========================== */

    .nav-tabs {
        gap: 10px;
        margin-bottom: 18px;
        padding: 8px;
        background: rgba(255, 255, 255, .72);
        border: 1px solid rgba(202, 216, 238, .92);
        border-radius: 22px;
        box-shadow: var(--shadow-small);
    }

    .nav-tabs .nav-item {
        margin: 0;
    }

    .nav-tabs .nav-link {
        min-height: 42px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0 18px;
        border: 0 !important;
        border-radius: 16px;
        color: #637493;
        font-size: 13px;
        font-weight: 950;
        letter-spacing: -.01em;
        transition: all .18s ease;
    }

    .nav-tabs .nav-link:hover {
        color: var(--primary);
        background: #eef4ff;
    }

    .nav-tabs .nav-link.active {
        color: #fff;
        background: linear-gradient(135deg, var(--primary), var(--primary-2));
        box-shadow: 0 12px 22px rgba(82, 120, 255, .26);
    }

    .tab-content {
        width: 100%;
    }

    .tab-content.pt-3 {
        padding-top: 0 !important;
    }

    /* =========================
       CARD / PANEL
    ========================== */

    .card {
        width: 100%;
        margin: 0;
        overflow: hidden;
        background: rgba(255, 255, 255, .80);
        border: 1px solid rgba(202, 216, 238, .92);
        border-radius: var(--radius-xl);
        box-shadow: var(--shadow-soft);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
    }

    .card-body {
        width: 100%;
        padding: 26px 24px 22px;
    }

    .card-footer {
        padding: 18px 24px;
        background: rgba(255, 255, 255, .72);
        border-top: 1px solid rgba(202, 216, 238, .88);
    }

    /* =========================
       FILTROS
    ========================== */

    .permission-toolbar {
        gap: 0;
        margin-left: -12px;
        margin-right: -12px;
        margin-bottom: 22px;
        row-gap: 18px;
    }

    .permission-toolbar > [class*="col-"] {
        padding-left: 12px;
        padding-right: 12px;
    }

    .form-group {
        margin-bottom: 0;
    }

    label {
        display: block;
        margin-bottom: 8px;
        color: #30476b;
        font-size: 12px;
        font-weight: 950;
        letter-spacing: -.01em;
    }

    .form-control {
        height: 45px;
        border: 1px solid #c8d7ee;
        border-radius: 15px;
        background-color: rgba(255, 255, 255, .94);
        color: #18305d;
        font-size: 13px;
        font-weight: 850;
        padding: 0 15px;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.9);
        transition: border-color .18s ease, box-shadow .18s ease, background-color .18s ease;
    }

    .form-control:focus {
        border-color: rgba(82, 120, 255, .85);
        background-color: #fff;
        box-shadow:
            0 0 0 4px rgba(82, 120, 255, .13),
            inset 0 1px 0 rgba(255,255,255,.9);
        outline: 0;
    }

    select.form-control {
        cursor: pointer;
    }

    .form-control:disabled {
        cursor: not-allowed;
        opacity: .62;
        background: #f3f7ff;
        box-shadow: none;
    }

    /* =========================
       BOTONES
    ========================== */

    .btn {
        border: 0;
        border-radius: 15px;
        font-size: 13px;
        font-weight: 950;
        letter-spacing: -.01em;
        transition: transform .18s ease, box-shadow .18s ease, filter .18s ease;
    }

    .btn:hover {
        transform: translateY(-2px);
        filter: brightness(1.02);
    }

    .btn:active {
        transform: translateY(0);
    }

    .btn-primary {
        min-height: 45px;
        padding: 0 20px;
        color: #fff;
        background: linear-gradient(135deg, var(--primary), var(--primary-2));
        box-shadow: 0 14px 24px rgba(82, 120, 255, .26);
    }

    .btn-primary:hover,
    .btn-primary:focus {
        background: linear-gradient(135deg, #4d73f5, #6555ef);
        box-shadow: 0 16px 28px rgba(82, 120, 255, .32);
    }

    /* =========================
       MATRIZ DE PERMISOS
    ========================== */

    .table-responsive {
        width: 100%;
        max-width: none;
        overflow-x: auto;
        border: 1px solid rgba(202, 216, 238, .88);
        border-radius: 22px;
        background: rgba(248, 251, 255, .82);
        box-shadow:
            inset 0 1px 0 rgba(255,255,255,.9),
            0 10px 24px rgba(38, 67, 118, .06);
    }

    .permission-table,
    table.permission-table {
        width: 100% !important;
        min-width: 980px;
        margin: 0;
        border-collapse: separate;
        border-spacing: 0 8px;
        padding: 8px;
        table-layout: auto;
    }

    .permission-table th,
    .permission-table td {
        vertical-align: middle !important;
    }

    .permission-table thead th,
    .permission-table th {
        position: sticky;
        top: 0;
        z-index: 5;
        padding: 15px 14px !important;
        border: 0 !important;
        background: #f1f6ff;
        color: #082657;
        font-size: 11px;
        font-weight: 950;
        text-transform: uppercase;
        letter-spacing: .035em;
        white-space: nowrap;
        box-shadow: 0 1px 0 rgba(202, 216, 238, .9);
    }

    .permission-table thead th:first-child,
    .permission-table th:first-child {
        border-radius: 17px 0 0 17px;
    }

    .permission-table thead th:last-child,
    .permission-table th:last-child {
        border-radius: 0 17px 17px 0;
    }

    .permission-table tbody td,
    .permission-table td {
        padding: 15px 14px !important;
        border-top: 0 !important;
        border-bottom: 1px solid rgba(226, 235, 248, .9);
        background: rgba(255, 255, 255, .94);
        color: #10254c;
        font-size: 12px;
        font-weight: 800;
    }

    .permission-table tbody tr {
        transition: transform .18s ease, box-shadow .18s ease;
    }

    .permission-table tbody tr:hover {
        transform: translateY(-1px);
        box-shadow: 0 12px 24px rgba(38, 67, 118, .09);
    }

    .permission-table tbody tr:hover td {
        background: #f9fbff;
    }

    .permission-table tbody tr td:first-child {
        border-radius: 18px 0 0 18px;
        border-left: 1px solid rgba(226, 235, 248, .9);
    }

    .permission-table tbody tr td:last-child {
        border-radius: 0 18px 18px 0;
        border-right: 1px solid rgba(226, 235, 248, .9);
    }

    .permission-table .module-parent td {
        background: #eef4ff !important;
        color: #10254c;
        font-weight: 950;
        border-top: 1px solid rgba(202, 216, 238, .92) !important;
        border-bottom: 1px solid rgba(202, 216, 238, .92) !important;
    }

    .permission-table .module-parent td:first-child {
        border-left: 4px solid var(--primary);
    }

    .permission-table .module-child td:first-child {
        padding-left: 38px !important;
        color: #30476b;
    }

    /* =========================
       RADIOS DE PERMISOS
    ========================== */

    .permission-radios {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        align-items: center;
    }

    .permission-radios label {
        min-height: 32px;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        margin: 0;
        padding: 0 11px;
        border: 1px solid #d8e5ff;
        border-radius: 999px;
        background: #f4f8ff;
        color: #637493;
        font-size: 11px;
        font-weight: 900;
        white-space: nowrap;
        cursor: pointer;
        transition: all .18s ease;
    }

    .permission-radios label:hover {
        color: var(--primary);
        border-color: rgba(82, 120, 255, .46);
        background: #eef4ff;
    }

    .permission-radios input[type="radio"] {
        width: 14px;
        height: 14px;
        margin: 0;
        accent-color: var(--primary);
    }

    .permission-radios label:has(input[type="radio"]:checked) {
        color: #fff;
        border-color: transparent;
        background: linear-gradient(135deg, var(--primary), var(--primary-2));
        box-shadow: 0 10px 18px rgba(82, 120, 255, .22);
    }

    /* =========================
       SCROLLBAR
    ========================== */

    .table-responsive::-webkit-scrollbar {
        width: 10px;
        height: 10px;
    }

    .table-responsive::-webkit-scrollbar-track {
        background: #eef4ff;
        border-radius: 999px;
    }

    .table-responsive::-webkit-scrollbar-thumb {
        background: #c7d7f0;
        border-radius: 999px;
        border: 2px solid #eef4ff;
    }

    .table-responsive::-webkit-scrollbar-thumb:hover {
        background: #9fb7dd;
    }

    /* =========================
       RESPONSIVE
    ========================== */

    @media (max-width: 1199px) {
        .container-fluid {
            width: calc(100% - 48px);
        }

        h1.h4 {
            font-size: 25px;
        }

        .permission-table,
        table.permission-table {
            min-width: 900px;
        }
    }

    @media (max-width: 767px) {
        .content.p-3 {
            padding-top: 18px !important;
        }

        .container-fluid {
            width: calc(100% - 24px);
        }

        .container-fluid > .d-flex:first-child {
            min-height: 98px;
            padding: 22px 18px 22px 86px;
            border-radius: 22px;
        }

        .container-fluid > .d-flex:first-child::before {
            left: 18px;
            width: 54px;
            height: 54px;
            border-radius: 18px;
            font-size: 22px;
        }

        h1.h4 {
            font-size: 22px;
        }

        .card {
            border-radius: 22px;
        }

        .card-body {
            padding: 22px 18px;
        }

        .card-footer {
            padding: 16px 18px;
        }

        .permission-toolbar > [class*="col-"] {
            flex: 1 1 100%;
            max-width: 100%;
        }

        .nav-tabs {
            overflow-x: auto;
            flex-wrap: nowrap;
        }

        .nav-tabs .nav-link {
            white-space: nowrap;
        }

        .permission-table,
        table.permission-table {
            min-width: 820px;
        }
    }
</style>
</head>
<body class="hold-transition">
<main class="content p-3">
  <div class="container-fluid">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <div>
        <h1 class="h4 mb-1">Permisos de modulos</h1>
        <p class="text-muted mb-0">Filtra el alcance y activa o desactiva modulos desde una matriz.</p>
      </div>
    </div>

    <?php if ($mensaje): ?>
      <div class="alert alert-success"><?php echo h($mensaje); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-danger"><?php echo h($error); ?></div>
    <?php endif; ?>

    <ul class="nav nav-tabs" id="permissionTabs" role="tablist">
      <li class="nav-item">
        <a class="nav-link active" id="perfil-tab" data-toggle="tab" href="#perfil-panel" role="tab">Por perfil y division</a>
      </li>
      <li class="nav-item">
        <a class="nav-link" id="usuario-tab" data-toggle="tab" href="#usuario-panel" role="tab">Por usuario</a>
      </li>
    </ul>

    <div class="tab-content pt-3">
      <section class="tab-pane fade show active" id="perfil-panel" role="tabpanel">
        <form method="post" class="card">
          <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
          <input type="hidden" name="accion" value="guardar_matriz_perfil">

          <div class="card-body">
            <div class="form-row permission-toolbar">
              <div class="form-group col-md-3">
                <label>Empresa</label>
                <select name="id_empresa" id="perfil_empresa" class="form-control" required>
                  <option value="">Seleccionar</option>
                  <?php foreach ($empresas as $empresa): ?>
                    <option value="<?php echo (int)$empresa['id']; ?>"><?php echo h($empresa['nombre']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group col-md-3">
                <label>Division</label>
                <select name="id_division" id="perfil_division" class="form-control" disabled>
                  <option value="">Todas</option>
                </select>
              </div>
              <div class="form-group col-md-3">
                <label>Subdivision</label>
                <select name="id_subdivision" id="perfil_subdivision" class="form-control" disabled>
                  <option value="">Todas</option>
                </select>
              </div>
              <div class="form-group col-md-3">
                <label>Perfil</label>
                <select name="id_perfil" id="perfil_perfil" class="form-control" required>
                  <option value="">Seleccionar</option>
                  <?php foreach ($perfiles as $perfil): ?>
                    <option value="<?php echo (int)$perfil['id']; ?>"><?php echo h($perfil['nombre']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <?php include __DIR__ . '/mod_permisos_matriz.inc.php'; ?>
          </div>

          <div class="card-footer text-right">
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-save mr-1"></i> Guardar permisos
            </button>
          </div>
        </form>
      </section>

      <section class="tab-pane fade" id="usuario-panel" role="tabpanel">
        <form method="post" class="card">
          <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
          <input type="hidden" name="accion" value="guardar_matriz_usuario">

          <div class="card-body">
            <div class="form-row permission-toolbar">
              <div class="form-group col-md-3">
                <label>Empresa</label>
                <select id="usuario_empresa" class="form-control">
                  <option value="">Seleccionar</option>
                  <?php foreach ($empresas as $empresa): ?>
                    <option value="<?php echo (int)$empresa['id']; ?>"><?php echo h($empresa['nombre']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group col-md-3">
                <label>Division</label>
                <select id="usuario_division" class="form-control" disabled>
                  <option value="">Todas</option>
                </select>
              </div>
              <div class="form-group col-md-3">
                <label>Perfil</label>
                <select id="usuario_perfil" class="form-control" disabled>
                  <option value="">Todos</option>
                </select>
              </div>
              <div class="form-group col-md-3">
                <label>Usuario</label>
                <select name="id_usuario" id="usuario_usuario" class="form-control" required disabled>
                  <option value="">Seleccionar</option>
                </select>
              </div>
            </div>

            <?php include __DIR__ . '/mod_permisos_matriz.inc.php'; ?>
          </div>

          <div class="card-footer text-right">
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-user-shield mr-1"></i> Guardar permisos
            </button>
          </div>
        </form>
      </section>
    </div>
  </div>
</main>

<script src="../plugins/jquery/jquery.min.js"></script>
<script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
const divisiones = <?php echo json_encode($divisiones, JSON_UNESCAPED_UNICODE); ?>;
const subdivisiones = <?php echo json_encode($subdivisiones, JSON_UNESCAPED_UNICODE); ?>;
const perfiles = <?php echo json_encode($perfiles, JSON_UNESCAPED_UNICODE); ?>;
const usuarios = <?php echo json_encode($usuarios, JSON_UNESCAPED_UNICODE); ?>;
const permisosPerfil = <?php echo json_encode($permisosPerfil, JSON_UNESCAPED_UNICODE); ?>;
const permisosUsuario = <?php echo json_encode($permisosUsuario, JSON_UNESCAPED_UNICODE); ?>;

function resetSelect(select, label, disabled = true) {
  select.innerHTML = `<option value="">${label}</option>`;
  select.disabled = disabled;
}

function addOption(select, value, text) {
  const option = document.createElement('option');
  option.value = String(value);
  option.textContent = text;
  select.appendChild(option);
}

function fillDivisiones(empresaId, select) {
  resetSelect(select, 'Todas', false);
  divisiones
    .filter(item => String(item.id_empresa) === String(empresaId))
    .forEach(item => addOption(select, item.id, item.nombre));
}

function fillSubdivisiones(divisionId, select) {
  resetSelect(select, 'Todas', !divisionId);
  if (!divisionId) return;
  subdivisiones
    .filter(item => String(item.id_division) === String(divisionId))
    .forEach(item => addOption(select, item.id, item.nombre));
}

function fillPerfilesUsuario(empresaId, divisionId, select) {
  const ids = new Set();
  resetSelect(select, 'Todos', !empresaId);
  if (!empresaId) return;

  usuarios
    .filter(user => String(user.id_empresa) === String(empresaId))
    .filter(user => !divisionId || String(user.id_division) === String(divisionId))
    .forEach(user => ids.add(String(user.id_perfil)));

  perfiles
    .filter(perfil => ids.has(String(perfil.id)))
    .forEach(perfil => addOption(select, perfil.id, perfil.nombre));
}

function fillUsuarios(empresaId, divisionId, perfilId, select) {
  resetSelect(select, 'Seleccionar', !empresaId);
  if (!empresaId) return;

  usuarios
    .filter(user => String(user.id_empresa) === String(empresaId))
    .filter(user => !divisionId || String(user.id_division) === String(divisionId))
    .filter(user => !perfilId || String(user.id_perfil) === String(perfilId))
    .forEach(user => addOption(select, user.id, `${user.nombre} ${user.apellido} - ${user.usuario} (${user.perfil || 'Sin perfil'})`));
}

function permissionKeyProfile(idModulo, idPerfil, idEmpresa, idDivision, idSubdivision) {
  return [idModulo, idPerfil || '', idEmpresa || '', idDivision || '', idSubdivision || ''].join('|');
}

function buildPerfilMap() {
  const map = new Map();
  permisosPerfil.forEach(row => {
    map.set(permissionKeyProfile(row.id_modulo, row.id_perfil, row.id_empresa, row.id_division, row.id_subdivision), String(row.permitido));
  });
  return map;
}

function buildUsuarioMap() {
  const map = new Map();
  permisosUsuario.forEach(row => {
    map.set([row.id_modulo, row.id_usuario].join('|'), String(row.permitido));
  });
  return map;
}

const perfilPermissionMap = buildPerfilMap();
const usuarioPermissionMap = buildUsuarioMap();

function setMatrixValue(form, idModulo, value) {
  const radio = form.querySelector(`input[name="permiso[${idModulo}]"][value="${value}"]`);
  if (radio) radio.checked = true;
}

function refreshPerfilMatrix() {
  const form = document.querySelector('#perfil-panel form');
  const idEmpresa = document.getElementById('perfil_empresa').value;
  const idDivision = document.getElementById('perfil_division').value;
  const idSubdivision = document.getElementById('perfil_subdivision').value;
  const idPerfil = document.getElementById('perfil_perfil').value;

  form.querySelectorAll('[data-modulo-id]').forEach(row => {
    const idModulo = row.dataset.moduloId;
    const value = perfilPermissionMap.get(permissionKeyProfile(idModulo, idPerfil, idEmpresa, idDivision, idSubdivision)) || 'inherit';
    setMatrixValue(form, idModulo, value);
  });
}

function refreshUsuarioMatrix() {
  const form = document.querySelector('#usuario-panel form');
  const idUsuario = document.getElementById('usuario_usuario').value;

  form.querySelectorAll('[data-modulo-id]').forEach(row => {
    const idModulo = row.dataset.moduloId;
    const value = usuarioPermissionMap.get([idModulo, idUsuario].join('|')) || 'inherit';
    setMatrixValue(form, idModulo, value);
  });
}

document.getElementById('perfil_empresa').addEventListener('change', function () {
  fillDivisiones(this.value, document.getElementById('perfil_division'));
  resetSelect(document.getElementById('perfil_subdivision'), 'Todas', true);
  refreshPerfilMatrix();
});

document.getElementById('perfil_division').addEventListener('change', function () {
  fillSubdivisiones(this.value, document.getElementById('perfil_subdivision'));
  refreshPerfilMatrix();
});

['perfil_subdivision', 'perfil_perfil'].forEach(id => {
  document.getElementById(id).addEventListener('change', refreshPerfilMatrix);
});

document.getElementById('usuario_empresa').addEventListener('change', function () {
  const empresaId = this.value;
  fillDivisiones(empresaId, document.getElementById('usuario_division'));
  fillPerfilesUsuario(empresaId, '', document.getElementById('usuario_perfil'));
  fillUsuarios(empresaId, '', '', document.getElementById('usuario_usuario'));
  refreshUsuarioMatrix();
});

document.getElementById('usuario_division').addEventListener('change', function () {
  const empresaId = document.getElementById('usuario_empresa').value;
  const divisionId = this.value;
  fillPerfilesUsuario(empresaId, divisionId, document.getElementById('usuario_perfil'));
  fillUsuarios(empresaId, divisionId, '', document.getElementById('usuario_usuario'));
  refreshUsuarioMatrix();
});

document.getElementById('usuario_perfil').addEventListener('change', function () {
  fillUsuarios(
    document.getElementById('usuario_empresa').value,
    document.getElementById('usuario_division').value,
    this.value,
    document.getElementById('usuario_usuario')
  );
  refreshUsuarioMatrix();
});

document.getElementById('usuario_usuario').addEventListener('change', refreshUsuarioMatrix);
</script>
</body>
</html>
