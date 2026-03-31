<?php
session_start();
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
// -----------------------------------------------------------------------------
// 1) Funciones auxiliares
// -----------------------------------------------------------------------------
function fixUrl(string $url, string $base_url): string {
    $url = trim($url);
    if ($url === '') return '';
    if (preg_match('#^https?://#i', $url)) return $url;
    $url = ltrim($url, '/');
    $url = preg_replace('#^(visibility2/app/|app/)#i', '', $url);
    return rtrim($base_url, '/') . '/' . ltrim($url, '/');
}

function formatearFecha($f): string {
    return $f ? date('d/m/Y H:i:s', strtotime($f)) : '';
}

function cacheRemember(string $namespace, array $payload, int $ttl, callable $resolver) {
    $dir = __DIR__ . '/cache_mod_galeria';

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $hash = md5(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $file = $dir . '/' . $namespace . '_' . $hash . '.cache';

    if (is_file($file) && (time() - filemtime($file) < $ttl)) {
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $data = @unserialize($raw);
            if ($data !== false || $raw === serialize(false)) {
                return $data;
            }
        }
    }

    $data = $resolver();
    @file_put_contents($file, serialize($data), LOCK_EX);

    return $data;
}
// -----------------------------------------------------------------------------
// 2) Includes
// -----------------------------------------------------------------------------
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

// -----------------------------------------------------------------------------
// 3) Parámetros
// -----------------------------------------------------------------------------
$division_id       = (int)($_SESSION['division_id'] ?? 0);
$divisionLogin     = $division_id;
$division          = (int)($_GET['division'] ?? $division_id);
$subdivision       = (int)($_GET['subdivision'] ?? 0);
$region            = (int)($_GET['region'] ?? 0);
$zona              = (int)($_GET['zona'] ?? 0);
$distrito          = (int)($_GET['distrito'] ?? 0);
$comuna            = (int)($_GET['comuna'] ?? 0);
$usuarioFiltro     = (int)($_GET['usuario'] ?? 0);
$jefeVentaFiltro   = (int)($_GET['jefe_venta'] ?? 0);
$codigoLocalFiltro = trim($_GET['codigo_local'] ?? '');
$view              = in_array(trim($_GET['view'] ?? 'implementacion'), ['implementacion','encuesta'], true)
    ? trim($_GET['view'] ?? 'implementacion')
    : 'implementacion';

$preguntaFiltro = trim($_GET['pregunta'] ?? '');

// Mantengo tu regla original, pero si quieres lo dejamos abierto para más divisiones
$puedeVerFiltroPregunta = ($view === 'encuesta' && in_array($divisionLogin, [1, 14], true));

$start_date = trim($_GET['start_date'] ?? '');
$end_date   = trim($_GET['end_date'] ?? '');
$base_url   = "https://visibility.cl/visibility2/app/";

// Fecha por defecto
if ($start_date === '' && $end_date === '') {
    $today = date('Y-m-d');
    $start_date = $today;
    $end_date   = $today;
}

// -----------------------------------------------------------------------------
// 3.1) Cargar divisiones
// -----------------------------------------------------------------------------
$divisiones = [];
$resDiv = $conn->query("
    SELECT id, nombre
    FROM division_empresa
    WHERE estado = 1
    ORDER BY nombre
");
if ($resDiv) {
    while ($r = $resDiv->fetch_assoc()) {
        $divisiones[] = $r;
    }
    $resDiv->close();
}

// -----------------------------------------------------------------------------
// 3.2) Cargar jefes de venta
// -----------------------------------------------------------------------------
$jefesVenta = [];
$resJV = $conn->query("
    SELECT id, nombre
    FROM jefe_venta
    ORDER BY nombre ASC
");
if ($resJV) {
    while ($rowJV = $resJV->fetch_assoc()) {
        $jefesVenta[] = $rowJV;
    }
    $resJV->close();
}

// -----------------------------------------------------------------------------
// 4) Construcción de filtros base
// -----------------------------------------------------------------------------
$where  = "1=1";
$params = [];
$types  = "";

// filtros comunes
if ($division > 0) {
    $where .= " AND f.id_division = ?";
    $types .= "i";
    $params[] = $division;
}

if ($subdivision > 0) {
    $where .= " AND f.id_subdivision = ?";
    $types .= "i";
    $params[] = $subdivision;
}

if ($region > 0) {
    $where .= " AND r.id = ?";
    $types .= "i";
    $params[] = $region;
}

if ($zona > 0) {
    $where .= " AND z.id = ?";
    $types .= "i";
    $params[] = $zona;
}

if ($distrito > 0) {
    $where .= " AND d.id = ?";
    $types .= "i";
    $params[] = $distrito;
}

if ($comuna > 0) {
    $where .= " AND co.id = ?";
    $types .= "i";
    $params[] = $comuna;
}

if ($usuarioFiltro > 0) {
    $where .= ($view === 'implementacion')
        ? " AND fv.id_usuario = ?"
        : " AND fqr.id_usuario = ?";
    $types .= "i";
    $params[] = $usuarioFiltro;
}

if ($jefeVentaFiltro > 0) {
    $where .= " AND l.id_jefe_venta = ?";
    $types .= "i";
    $params[] = $jefeVentaFiltro;
}

if ($codigoLocalFiltro !== '') {
    $where .= " AND l.codigo LIKE ?";
    $types .= "s";
    $params[] = '%' . $codigoLocalFiltro . '%';
}

$fieldFecha = ($view === 'implementacion') ? 'fq.fechaVisita' : 'fqr.created_at';

if ($start_date !== '') {
    $where .= " AND {$fieldFecha} >= ?";
    $types .= "s";
    $params[] = $start_date . ' 00:00:00';
}

if ($end_date !== '') {
    $where .= " AND {$fieldFecha} <= ?";
    $types .= "s";
    $params[] = $end_date . ' 23:59:59';
}

// filtro pregunta solo en encuesta
if ($view === 'encuesta' && $preguntaFiltro !== '') {
    $where .= " AND UPPER(TRIM(fq.question_text)) = ?";
    $types .= "s";
    $params[] = mb_strtoupper(trim($preguntaFiltro), 'UTF-8');
}

// -----------------------------------------------------------------------------
// 4.1) Cargar preguntas para el filtro
// -----------------------------------------------------------------------------
$preguntasEncuesta = [];

if ($puedeVerFiltroPregunta) {
    $preguntasEncuesta = cacheRemember('galeria_preguntas_v1', [
        'division'          => $division,
        'subdivision'       => $subdivision,
        'region'            => $region,
        'zona'              => $zona,
        'distrito'          => $distrito,
        'comuna'            => $comuna,
        'usuarioFiltro'     => $usuarioFiltro,
        'jefeVentaFiltro'   => $jefeVentaFiltro,
        'codigoLocalFiltro' => $codigoLocalFiltro,
        'start_date'        => $start_date,
        'end_date'          => $end_date
    ], 900, function () use (
        $conn,
        $division,
        $subdivision,
        $region,
        $zona,
        $distrito,
        $comuna,
        $usuarioFiltro,
        $jefeVentaFiltro,
        $codigoLocalFiltro,
        $start_date,
        $end_date
    ) {
        $preguntas = [];

        $sqlPreguntas = "
            SELECT DISTINCT UPPER(TRIM(fq.question_text)) AS pregunta
            FROM form_question_responses fqr
            INNER JOIN form_questions fq ON fq.id = fqr.id_form_question
            INNER JOIN formulario f      ON f.id = fq.id_formulario
            INNER JOIN local l           ON l.id = fqr.id_local
            LEFT JOIN comuna co          ON co.id = l.id_comuna
            LEFT JOIN region r           ON r.id = co.id_region
            LEFT JOIN distrito d         ON d.id = l.id_distrito
            LEFT JOIN zona z             ON z.id = d.id_zona
            LEFT JOIN jefe_venta jv      ON jv.id = l.id_jefe_venta
            WHERE fq.id_question_type = 7
              AND COALESCE(TRIM(fq.question_text), '') <> ''
              AND COALESCE(TRIM(fqr.answer_text), '') <> ''
        ";

        $paramsPreg = [];
        $typesPreg  = "";

        if ($division > 0) {
            $sqlPreguntas .= " AND f.id_division = ?";
            $typesPreg .= "i";
            $paramsPreg[] = $division;
        }

        if ($subdivision > 0) {
            $sqlPreguntas .= " AND f.id_subdivision = ?";
            $typesPreg .= "i";
            $paramsPreg[] = $subdivision;
        }

        if ($region > 0) {
            $sqlPreguntas .= " AND r.id = ?";
            $typesPreg .= "i";
            $paramsPreg[] = $region;
        }

        if ($zona > 0) {
            $sqlPreguntas .= " AND z.id = ?";
            $typesPreg .= "i";
            $paramsPreg[] = $zona;
        }

        if ($distrito > 0) {
            $sqlPreguntas .= " AND d.id = ?";
            $typesPreg .= "i";
            $paramsPreg[] = $distrito;
        }

        if ($comuna > 0) {
            $sqlPreguntas .= " AND co.id = ?";
            $typesPreg .= "i";
            $paramsPreg[] = $comuna;
        }

        if ($usuarioFiltro > 0) {
            $sqlPreguntas .= " AND fqr.id_usuario = ?";
            $typesPreg .= "i";
            $paramsPreg[] = $usuarioFiltro;
        }

        if ($jefeVentaFiltro > 0) {
            $sqlPreguntas .= " AND l.id_jefe_venta = ?";
            $typesPreg .= "i";
            $paramsPreg[] = $jefeVentaFiltro;
        }

        if ($codigoLocalFiltro !== '') {
            $sqlPreguntas .= " AND l.codigo LIKE ?";
            $typesPreg .= "s";
            $paramsPreg[] = '%' . $codigoLocalFiltro . '%';
        }

        if ($start_date !== '') {
            $sqlPreguntas .= " AND fqr.created_at >= ?";
            $typesPreg .= "s";
            $paramsPreg[] = $start_date . ' 00:00:00';
        }

        if ($end_date !== '') {
            $sqlPreguntas .= " AND fqr.created_at <= ?";
            $typesPreg .= "s";
            $paramsPreg[] = $end_date . ' 23:59:59';
        }

        $sqlPreguntas .= " ORDER BY pregunta ASC";

        $stmtPreg = $conn->prepare($sqlPreguntas);
        if (!$stmtPreg) {
            throw new RuntimeException('Error consulta preguntas: ' . $conn->error);
        }

        if ($typesPreg !== '') {
            $stmtPreg->bind_param($typesPreg, ...$paramsPreg);
        }

        $stmtPreg->execute();
        $resPreg = $stmtPreg->get_result();

        while ($rowPreg = $resPreg->fetch_assoc()) {
            if (!empty($rowPreg['pregunta'])) {
                $preguntas[] = $rowPreg['pregunta'];
            }
        }

        $stmtPreg->close();

        return $preguntas;
    });
}
// -----------------------------------------------------------------------------
// 5) Query principal
// -----------------------------------------------------------------------------
if ($view === 'implementacion') {

    $sql = "
        SELECT
            MIN(fv.id) AS foto_id,
            GROUP_CONCAT(COALESCE(fv.url,'') SEPARATOR '||') AS urls,
            fq.material,
            fq.fechaVisita,
            ANY_VALUE(f.nombre) AS campaña_nombre,
            ANY_VALUE(l.codigo) AS local_codigo_completo,
            TRIM(SUBSTRING_INDEX(ANY_VALUE(l.codigo), '-', -1)) AS local_codigo,
            ANY_VALUE(l.nombre) AS local_nombre,
            ANY_VALUE(l.direccion) AS local_direccion,
            ANY_VALUE(co.comuna) AS comuna_nombre,
            ANY_VALUE(r.region) AS region_nombre,
            ANY_VALUE(d.nombre_distrito) AS distrito_nombre,
            ANY_VALUE(z.nombre_zona) AS zona_nombre,
            ANY_VALUE(c.nombre) AS cadena_nombre,
            ANY_VALUE(ct.nombre) AS cuenta_nombre,
            ANY_VALUE(jv.nombre) AS jefe_venta_nombre,
            ANY_VALUE(u.usuario) AS usuario
        FROM formularioQuestion fq
        INNER JOIN formulario f   ON f.id = fq.id_formulario
        INNER JOIN fotoVisita fv  ON fv.id_formularioQuestion = fq.id
        INNER JOIN local l        ON l.id = fq.id_local
        LEFT JOIN comuna co       ON co.id = l.id_comuna
        LEFT JOIN region r        ON r.id = co.id_region
        LEFT JOIN distrito d      ON d.id = l.id_distrito
        LEFT JOIN zona z          ON z.id = d.id_zona
        LEFT JOIN jefe_venta jv   ON jv.id = l.id_jefe_venta
        INNER JOIN cadena c       ON c.id = l.id_cadena
        INNER JOIN cuenta ct      ON ct.id = l.id_cuenta
        INNER JOIN usuario u      ON u.id = fv.id_usuario
        WHERE {$where}
          AND fq.fechaVisita IS NOT NULL
        GROUP BY u.id, l.id, fq.material, fq.fechaVisita
        ORDER BY fq.fechaVisita DESC
    ";

} else {

    $sql = "
        SELECT
            MIN(fqr.id) AS foto_id,
            GROUP_CONCAT(COALESCE(fqr.answer_text,'') SEPARATOR '||') AS urls,
            ANY_VALUE(fqr.created_at) AS fechaSubida,
            UPPER(TRIM(fq.question_text)) AS pregunta,
            ANY_VALUE(f.nombre) AS campaña_nombre,
            ANY_VALUE(l.codigo) AS local_codigo_completo,
            TRIM(SUBSTRING_INDEX(ANY_VALUE(l.codigo), '-', -1)) AS local_codigo,
            ANY_VALUE(l.nombre) AS local_nombre,
            ANY_VALUE(l.direccion) AS local_direccion,
            ANY_VALUE(co.comuna) AS comuna_nombre,
            ANY_VALUE(r.region) AS region_nombre,
            ANY_VALUE(d.nombre_distrito) AS distrito_nombre,
            ANY_VALUE(z.nombre_zona) AS zona_nombre,
            ANY_VALUE(c.nombre) AS cadena_nombre,
            ANY_VALUE(ct.nombre) AS cuenta_nombre,
            ANY_VALUE(jv.nombre) AS jefe_venta_nombre,
            ANY_VALUE(u.usuario) AS usuario
        FROM form_question_responses fqr
        INNER JOIN form_questions fq ON fq.id = fqr.id_form_question
        INNER JOIN formulario f      ON f.id = fq.id_formulario
        INNER JOIN local l           ON l.id = fqr.id_local
        LEFT JOIN comuna co          ON co.id = l.id_comuna
        LEFT JOIN region r           ON r.id = co.id_region
        LEFT JOIN distrito d         ON d.id = l.id_distrito
        LEFT JOIN zona z             ON z.id = d.id_zona
        LEFT JOIN jefe_venta jv      ON jv.id = l.id_jefe_venta
        INNER JOIN cadena c          ON c.id = l.id_cadena
        INNER JOIN cuenta ct         ON ct.id = l.id_cuenta
        INNER JOIN usuario u         ON u.id = fqr.id_usuario
        WHERE {$where}
          AND fq.id_question_type = 7
          AND COALESCE(TRIM(fqr.answer_text), '') <> ''
        GROUP BY fqr.id_usuario, fqr.id_local, fqr.id_form_question
        ORDER BY ANY_VALUE(fqr.created_at) DESC
    ";
}

// -----------------------------------------------------------------------------
// 6) Ejecutar + cachear resultados
// -----------------------------------------------------------------------------
$cachePayload = [
    'view'              => $view,
    'divisionLogin'     => $divisionLogin,
    'division'          => $division,
    'subdivision'       => $subdivision,
    'region'            => $region,
    'zona'              => $zona,
    'distrito'          => $distrito,
    'comuna'            => $comuna,
    'usuarioFiltro'     => $usuarioFiltro,
    'jefeVentaFiltro'   => $jefeVentaFiltro,
    'codigoLocalFiltro' => $codigoLocalFiltro,
    'preguntaFiltro'    => $preguntaFiltro,
    'start_date'        => $start_date,
    'end_date'          => $end_date
];

try {
    $data = cacheRemember('galeria_data_v1', $cachePayload, 300, function () use ($conn, $sql, $types, $params, $base_url) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Error al preparar la consulta: ' . $conn->error);
        }

        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $res = $stmt->get_result();

        $rows = [];

        while ($row = $res->fetch_assoc()) {
            $rawUrls = array_filter(explode('||', (string)$row['urls']));
            $fixed = [];

            foreach ($rawUrls as $u) {
                $f = fixUrl($u, $base_url);
                if ($f !== '') {
                    $fixed[] = $f;
                }
            }

            $row['photos'] = $fixed;
            $row['photos_count'] = count($fixed);
            $row['thumbnail'] = $fixed[0] ?? null;
            $rows[] = $row;
        }

        $stmt->close();

        return $rows;
    });
} catch (Throwable $e) {
    error_log('[mod_galeria] ' . $e->getMessage());
    $data = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Galería Campañas Programadas</title>
  <!--<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css"> -->
      <!-- Bootstrap 4 desde CDN -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
  <link rel="stylesheet"
      href="/visibility2/portal/css/mod_galeria.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/css/mod_galeria.css') ?>">
    <link rel="stylesheet"
          href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap4.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <style>
        .select2-container--default .select2-selection--single {
          height: 38px;
          padding: 5px 10px;
        }
        
        .select2-selection__rendered {
          line-height: 28px !important;
        }
          #formFiltrosGaleria {
            border-radius: 12px;
            border: 1px solid #e9ecef;
            background: #fff;
          }
        
          #formFiltrosGaleria label {
            font-size: 13px;
            color: #495057;
          }
        
          #formFiltrosGaleria .form-control {
            height: 40px;
            border-radius: 8px;
          }
        
          #formFiltrosGaleria .btn {
           /* height: 40px; */
            border-radius: 8px;
          }
        
          .nav-tabs .nav-link {
            border-radius: 10px 10px 0 0;
            font-weight: 600;
          }
        
          .nav-tabs .nav-link.active {
            background-color: #fff;
            border-color: #dee2e6 #dee2e6 #fff;
          }
        
          /* Overlay de carga */
          #loadingOverlayGaleria {
            position: fixed;
            inset: 0;
            background: rgba(255,255,255,0.75);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            backdrop-filter: blur(2px);
          }
        
          #loadingOverlayGaleria .spinner-border {
            width: 3rem;
            height: 3rem;
          }
        
          #loadingOverlayGaleria .loading-text {
            margin-top: 12px;
            font-size: 16px;
            font-weight: 600;
            color: #343a40;
          }
        
          @media (max-width: 768px) {
            #formFiltrosGaleria .btn-block {
              width: 100%;
            }
          }
            
          .custom-img-cell {
            position: relative;
            text-align: center;
          }
        
          .thumbnail {
            max-width: 110px;
            max-height: 85px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            transition: transform .2s ease, box-shadow .2s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,.15);
          }
        
          .thumbnail:hover {
            transform: scale(1.04);
            box-shadow: 0 4px 14px rgba(0,0,0,.22);
          }
        
          .badge-count {
            position: absolute;
            top: 4px;
            right: 6px;
            z-index: 2;
            background: #007bff;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            border-radius: 20px;
            padding: 3px 8px;
          }
        
          #fullSizeModal .modal-content {
            border-radius: 14px;
            overflow: hidden;
          }
        
          #fullSizeModal .modal-body {
            background: #111;
            min-height: 70vh;
            display: flex;
            align-items: center;
            justify-content: center;
          }
        
          #fullSizeModal .carousel-item {
            height: 70vh;
            text-align: center;
            background: #111;
          }
        
          #fullSizeModal .carousel-item img {
            max-width: 100%;
            max-height: 70vh;
            object-fit: contain;
            margin: 0 auto;
          }
        
          #fullSizeModal .carousel-control-prev,
          #fullSizeModal .carousel-control-next {
            width: 8%;
          }
        
          #fullSizeModal .carousel-control-prev-icon,
          #fullSizeModal .carousel-control-next-icon {
            background-size: 65% 65%;
            background-color: rgba(0,0,0,0.45);
            border-radius: 50%;
            width: 52px;
            height: 52px;
          }
        
          #fullSizeModal .carousel-indicators li {
            width: 10px;
            height: 10px;
            border-radius: 50%;
          }
        
          @media (max-width: 768px) {
            #fullSizeModal .carousel-item,
            #fullSizeModal .modal-body,
            #fullSizeModal .carousel-item img {
              height: 55vh;
              max-height: 55vh;
            }
          }
        
          @media (max-width: 768px) {
            #fullSizeModal .carousel-item,
            #fullSizeModal .modal-body,
            #fullSizeModal .carousel-item img {
              height: 55vh;
              max-height: 55vh;
            }
          }
.container{
        font-size: 80% !important;    
}

#formFiltrosGaleria .form-control {
    height: 60% !important;    
}
.form-control {
        font-size: 80% !important;    
}
.select2-results__option {
    font-size: 80%;
}
#formFiltrosGaleria .btn {
    font-size: 80%;
    width:70%;
}
.btn-secondary {
    font-size: 80%;
}
#example thead th {
    font-size: 80%!important;    
}
.btn{
    font-size: 80% !important;    
}
td{
font-size: 80% !important;    
}
    </style>
</head>
<body class="bg-light">
<div class="container mt-4">
    
<!-- Nav tabs -->
<ul class="nav nav-tabs mb-3" id="tabsGaleria">
  <li class="nav-item">
    <a class="nav-link <?= $view==='implementacion'?'active':'' ?>"
       href="?<?= http_build_query(array_merge($_GET,['view'=>'implementacion','page'=>1])) ?>">
      Fotos Implementación
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $view==='encuesta'?'active':'' ?>"
       href="?<?= http_build_query(array_merge($_GET,['view'=>'encuesta','page'=>1])) ?>">
      Fotos Encuesta
    </a>
  </li>
</ul>

<!-- Filtros -->
<form method="GET" id="formFiltrosGaleria" class="card card-body shadow-sm mb-4">
  <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">

  <div class="row">
    
    <?php if ($division_id === 1): ?>
      <div class="col-md-3 col-sm-6 mb-3">
        <label for="divisionSelect" class="font-weight-bold mb-1">División</label>
        <select id="divisionSelect" name="division" class="form-control">
          <option value="0">-- Todas --</option>
          <?php foreach($divisiones as $d): ?>
            <option value="<?= $d['id'] ?>" <?= $d['id']==$division?'selected':'' ?>>
              <?= htmlspecialchars($d['nombre']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php else: ?>
      <input type="hidden" name="division" value="<?= $division_id ?>">
    <?php endif; ?>

    <div class="col-md-3 col-sm-6 mb-3">
      <label for="subdivisionSelect" class="font-weight-bold mb-1">Subdivisión</label>
      <select id="subdivisionSelect" name="subdivision" class="form-control">
        <option value="0">-- Todas --</option>
      </select>
    </div>

    <?php if ($divisionLogin != 14): ?>
      <div class="col-md-3 col-sm-6 mb-3">
        <label for="regionSelect" class="font-weight-bold mb-1">Región</label>
        <select id="regionSelect" name="region" class="form-control">
          <option value="0">-- Todas --</option>
        </select>
      </div>

      <div class="col-md-3 col-sm-6 mb-3">
        <label for="comunaSelect" class="font-weight-bold mb-1">Comuna</label>
        <select id="comunaSelect" name="comuna" class="form-control">
          <option value="0">-- Todas --</option>
        </select>
      </div>
    <?php endif; ?>

    <div class="col-md-3 col-sm-6 mb-3">
      <label for="zonaSelect" class="font-weight-bold mb-1">Zona</label>
      <select id="zonaSelect" name="zona" class="form-control">
        <option value="0">-- Todas --</option>
      </select>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
      <label for="distritoSelect" class="font-weight-bold mb-1">Distrito</label>
      <select id="distritoSelect" name="distrito" class="form-control">
        <option value="0">-- Todos --</option>
      </select>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
      <label class="font-weight-bold mb-1">Desde</label>
      <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
      <label class="font-weight-bold mb-1">Hasta</label>
      <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
      <label for="codigoLocalInput" class="font-weight-bold mb-1">Código local</label>
      <input 
        type="text" 
        name="codigo_local" 
        id="codigoLocalInput" 
        class="form-control"
        placeholder="Ej: 12345"
        value="<?= htmlspecialchars($codigoLocalFiltro ?? '') ?>"
      >
    </div>
    
    <?php if ($puedeVerFiltroPregunta): ?>
      <div class="col-md-3 col-sm-6 mb-3">
        <label for="preguntaSelect" class="font-weight-bold mb-1">Pregunta</label>
        <select id="preguntaSelect" name="pregunta" class="form-control">
          <option value="">-- Todas --</option>
          <?php foreach ($preguntasEncuesta as $preg): ?>
            <option value="<?= htmlspecialchars($preg, ENT_QUOTES) ?>"
              <?= $preguntaFiltro === $preg ? 'selected' : '' ?>>
              <?= htmlspecialchars($preg, ENT_QUOTES) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>    

    <div class="col-md-3 col-sm-6 mb-3">
      <label for="usuarioSelect" class="font-weight-bold mb-1">Usuario</label>
      <select id="usuarioSelect" name="usuario" class="form-control">
        <option value="0">-- Todos --</option>
      </select>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
      <label for="jefe_ventaSelect" class="font-weight-bold mb-1">Jefe de Venta</label>
      <select name="jefe_venta" id="jefe_ventaSelect" class="form-control">
        <option value="0">-- Todos --</option>
        <?php foreach ($jefesVenta as $jv): ?>
          <option value="<?= $jv['id'] ?>" <?= $jefeVentaFiltro == $jv['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($jv['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>


    <div class="col-md-3 col-sm-6 mb-3 d-flex align-items-end">
      <button type="submit" id="btnFiltrar" class="btn btn-primary btn-block">
        <i class="fas fa-filter"></i> Aplicar filtros
      </button>
    </div>

    <div class="col-md-3 col-sm-6 mb-3 d-flex align-items-end">
      <a href="?view=<?= urlencode($view) ?>" class="btn btn-outline-secondary btn-block">
        Limpiar filtros
      </a>
    </div>

  </div>
</form>

  <!-- Descargar ZIP -->
  <form id="zipForm" method="POST" action="download_zip.php" style="display:none">
    <input type="hidden" name="jsonFotos" id="jsonFotos">
  </form>
  <button id="btnDownloadSelected" class="btn btn-secondary">
      Descargar seleccionadas
      <i class="fas fa-download"></i>
  </button>

<br>

  <?php if ($view === 'implementacion'): ?>
    <table id="example"
                        class="table table-sm table-bordered table-hover"
                        cellspacing="0"
                        width="100%">
      <thead>
        <tr>
          <th><input type="checkbox" id="selectAll"></th>
          <th>#</th>
          <th>Imagen</th>
          <th>Campaña</th>          
          <th>Cód. Local</th>
          <th>Local</th>
          <th>Dirección</th>
          <th>Material</th>
          <th>Cadena</th>
          <th>Cuenta</th>
          <th>Usuario</th>
          <th>Fecha</th>
        </tr>
      </thead>
<tbody>
  <?php if (!empty($data)): ?>
    <?php foreach ($data as $r): 
      $usuarioSafe = preg_replace('/[^a-zA-Z0-9]/', '_', $r['usuario'] ?? '');

      if ($view === 'implementacion') {
        $inner = $r['material'] ?? '';
      } else {
        $inner = $r['pregunta'] ?? '';
      }

      $innerSafe  = preg_replace('/[^a-zA-Z0-9]/', '_', $inner);
      $codigoSafe = preg_replace('/[^a-zA-Z0-9]/', '_', $r['local_codigo'] ?? '');

      $prefix = trim("{$usuarioSafe}_{$innerSafe}_{$codigoSafe}", '_');
    ?>
      <tr>
        <td>
          <input type="checkbox"
                 class="imgCheckbox"
                 data-urls="<?= htmlspecialchars(implode('||', $r['photos']), ENT_QUOTES) ?>"
                 data-prefix="<?= htmlspecialchars($prefix, ENT_QUOTES) ?>">
        </td>
        <td></td>
        <td class="custom-img-cell">
          <span class="badge-count"><?= (int)$r['photos_count'] ?></span>
          <img src="<?= htmlspecialchars($r['thumbnail'], ENT_QUOTES) ?>"
               class="thumbnail img-click"
               alt="Vista previa"
               title="Clic para ver fotos"
               data-local="<?= htmlspecialchars($r['local_nombre'] ?? 'Fotos del local', ENT_QUOTES) ?>"
               data-urls="<?= htmlspecialchars(implode('||', $r['photos']), ENT_QUOTES) ?>">
        </td>
        <td><?= htmlspecialchars($r['campaña_nombre'], ENT_QUOTES) ?></td>
        <td><?= htmlspecialchars($r['local_codigo'], ENT_QUOTES) ?></td>
        <td><?= htmlspecialchars($r['local_nombre'], ENT_QUOTES) ?></td>
        <td><?= htmlspecialchars($r['local_direccion'], ENT_QUOTES) ?></td>
        <td><?= htmlspecialchars($r['material'], ENT_QUOTES) ?></td>
        <td><?= htmlspecialchars($r['cadena_nombre'], ENT_QUOTES) ?></td>
        <td><?= htmlspecialchars($r['cuenta_nombre'], ENT_QUOTES) ?></td>
        <td><?= htmlspecialchars($r['usuario'], ENT_QUOTES) ?></td>
        <td><?= formatearFecha($r['fechaVisita']) ?></td>
      </tr>
    <?php endforeach; ?>
  <?php endif; ?>
</tbody>
    </table>
  <?php else: /* === pestaña Encuesta === */ ?>
    <table id="example"
                        class="table table-sm table-bordered table-hover"
                        cellspacing="0"
                        width="100%">
      <thead class="thead-light">
        <tr>
          <th><input type="checkbox" id="selectAll"></th>
          <th>#</th>
          <th>Imagen</th>
          <th>Campaña</th> 
          <th>Pregunta</th>          
          <th>Cód. Local</th>          
          <th>Local</th>
          <th>Dirección</th>
          <th>Cadena</th>
          <th>Cuenta</th>
          <th>Usuario</th>
          <th>Fecha Subida</th>
        </tr>
      </thead>
<tbody>
  <?php if (!empty($data)): ?>
    <?php foreach ($data as $r): 
      $usuarioSafe = preg_replace('/[^a-zA-Z0-9]/', '_', $r['usuario'] ?? '');

      if ($view === 'implementacion') {
        $inner = $r['material'] ?? '';
      } else {
        $inner = $r['pregunta'] ?? '';
      }

      $innerSafe  = preg_replace('/[^a-zA-Z0-9]/', '_', $inner);
      $codigoSafe = preg_replace('/[^a-zA-Z0-9]/', '_', $r['local_codigo'] ?? '');

      $prefix = trim("{$usuarioSafe}_{$innerSafe}_{$codigoSafe}", '_');
    ?>
      <tr>
        <td>
          <input type="checkbox"
                 class="imgCheckbox"
                 data-urls="<?= htmlspecialchars(implode('||', $r['photos']), ENT_QUOTES) ?>"
                 data-prefix="<?= htmlspecialchars($prefix, ENT_QUOTES) ?>">
        </td>
        <td></td>
        <td class="custom-img-cell">
          <span class="badge-count"><?= (int)$r['photos_count'] ?></span>
          <img src="<?= htmlspecialchars($r['thumbnail'], ENT_QUOTES) ?>"
               class="thumbnail img-click"
               alt="Vista previa"
               title="Clic para ver fotos"
               data-local="<?= htmlspecialchars($r['local_nombre'] ?? 'Fotos del local', ENT_QUOTES) ?>"
               data-urls="<?= htmlspecialchars(implode('||', $r['photos']), ENT_QUOTES) ?>">
        </td>
        <td><?= htmlspecialchars($r['campaña_nombre'], ENT_QUOTES) ?></td>
        <td><?= htmlspecialchars($r['local_codigo'], ENT_QUOTES) ?></td>
        <td><?= htmlspecialchars($r['local_nombre'], ENT_QUOTES) ?></td>
        <td><?= htmlspecialchars($r['local_direccion'], ENT_QUOTES) ?></td>
        <td><?= htmlspecialchars($r['pregunta'], ENT_QUOTES) ?></td>
        <td><?= htmlspecialchars($r['cadena_nombre'], ENT_QUOTES) ?></td>
        <td><?= htmlspecialchars($r['cuenta_nombre'], ENT_QUOTES) ?></td>
        <td><?= htmlspecialchars($r['usuario'], ENT_QUOTES) ?></td>
        <td><?= formatearFecha($r['fechaSubida']) ?></td>
      </tr>
    <?php endforeach; ?>
  <?php endif; ?>
</tbody>
    </table>
  <?php endif; ?>


</div>

<!-- Modal visor de fotos -->
<div class="modal fade" id="fullSizeModal" tabindex="-1" aria-labelledby="fullSizeModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content bg-dark border-0">

      <div class="modal-header border-0">
        <h5 class="modal-title text-white" id="fullSizeModalLabel">Vista de fotos</h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body p-0">
        <div id="carouselFotosModal" class="carousel slide" data-interval="false">
          <div class="carousel-inner" id="modalBodyImgs"></div>

          <a class="carousel-control-prev" href="#carouselFotosModal" role="button" data-slide="prev">
            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
            <span class="sr-only">Anterior</span>
          </a>

          <a class="carousel-control-next" href="#carouselFotosModal" role="button" data-slide="next">
            <span class="carousel-control-next-icon" aria-hidden="true"></span>
            <span class="sr-only">Siguiente</span>
          </a>

          <ol class="carousel-indicators mb-2" id="carouselIndicatorsFotos"></ol>
        </div>
      </div>

      <div class="modal-footer border-0 justify-content-between">
        <small class="text-white-50" id="contadorFotosModal"></small>
        <button type="button" class="btn btn-light" data-dismiss="modal">Cerrar</button>
      </div>

    </div>
  </div>
</div>

<div id="loadingOverlayGaleria">
  <div class="spinner-border text-primary" role="status">
    <span class="sr-only">Cargando...</span>
  </div>
  <div class="loading-text">Cargando filtros, por favor espera...</div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<!-- Bootstrap 4 desde CDN -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap4.min.js"></script>

<script>
$(function () {

  $('#usuarioSelect').select2({
    placeholder: "Buscar usuario...",
    allowClear: true,
    width: '100%'
  });
  
  $('#jefe_ventaSelect').select2({
    placeholder: "Buscar jefe de venta...",
    allowClear: true,
    width: '100%'
  });

  $('#preguntaSelect').select2({
    placeholder: "Buscar pregunta...",
    allowClear: true,
    width: '100%'
  });

});
    
</script>
<script>
$(function () {
  $('#example').DataTable({
    paging: true,
    searching: true,
    ordering: true,
    info: true,
    autoWidth: false,
    pageLength: 50,
    lengthMenu: [[25, 50, 100], [25, 50, 100]],
    language: {
      url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-ES.json',
      emptyTable: 'Sin registros disponibles'
    },
    columnDefs: [
      { targets: [0, 1, 2], orderable: false },
      { targets: [0, 2], searchable: false }
    ]
  });
});
</script>
<script>
window.GALERIA_FILTROS = {
  division: <?= (int)$division ?>,
  usuario: <?= (int)$usuarioFiltro ?>,
  subdivision: <?= (int)$subdivision ?>,
  region: <?= (int)($_GET['region'] ?? 0) ?>,
  comuna: <?= (int)($_GET['comuna'] ?? 0) ?>,
  zona: <?= (int)($_GET['zona'] ?? 0) ?>,
  distrito: <?= (int)($_GET['distrito'] ?? 0) ?>
};
</script>

<script>
$(function () {

  /* ===============================
     Estado inicial desde PHP
  =============================== */
  const F = window.GALERIA_FILTROS || {
    division: 0,
    subdivision: 0,
    region: 0,
    comuna: 0,
    zona: 0,
    distrito: 0
  };

  /* ===============================
     DIVISION → USUARIO
  =============================== */

$(function () {

  const currentDivision = window.GALERIA_FILTROS?.division || 0;
  const currentUsuario  = window.GALERIA_FILTROS?.usuario || 0;

  function loadUsuarios(division, selected) {
    const $user = $('#usuarioSelect');
    $user.html('<option value="0">-- Todos --</option>');

    if (!division || division == 0) return;

    $.getJSON('/visibility2/portal/modulos/mod_galeria/ajax_usuarios.php',
      { division: division },
      function (data) {

        data.forEach(function (u) {
          $user.append(
            $('<option>', {
              value: u.id,
              text: u.nombre_completo,
              selected: u.id == selected
            })
          );
        });

      });
  }

  // Cuando cambia la división
  $('#divisionSelect').on('change', function () {
    loadUsuarios($(this).val(), 0);
  });

  // Carga inicial (cuando viene por GET)
  if (currentDivision > 0) {
    loadUsuarios(currentDivision, currentUsuario);
  }

});


  /* ===============================
     DIVISIÓN → SUBDIVISIÓN
  =============================== */
  function loadSubdivisions(division, selected) {
    const $sub = $('#subdivisionSelect');
    $sub.html('<option value="0">-- Todas --</option>');

    if (!division || division == 0) return;

    $.getJSON('ajax_subdivisiones.php', { division }, function (data) {
      data.forEach(s => {
        $sub.append(
          $('<option>', {
            value: s.id,
            text: s.nombre,
            selected: s.id == selected
          })
        );
      });
    });
  }

  $('#divisionSelect').on('change', function () {
    loadSubdivisions(this.value, 0);
  });

  if (F.division > 0) {
    $('#divisionSelect').val(F.division);
    loadSubdivisions(F.division, F.subdivision);
  }

  /* ===============================
     REGIÓN → COMUNA
  =============================== */
  function loadRegiones() {
    $.getJSON('ajax_regiones.php', function (data) {
      const $r = $('#regionSelect');
      $r.html('<option value="0">-- Todas --</option>');

      data.forEach(r => {
        $r.append(
          $('<option>', {
            value: r.id,
            text: r.nombre,
            selected: r.id == F.region
          })
        );
      });

      if (F.region > 0) {
        loadComunas(F.region, F.comuna);
      }
    });
  }

  function loadComunas(region, selected) {
    const $c = $('#comunaSelect');
    $c.html('<option value="0">-- Todas --</option>');

    if (!region || region == 0) return;

    $.getJSON('ajax_comunas.php', { region }, function (data) {
      data.forEach(c => {
        $c.append(
          $('<option>', {
            value: c.id,
            text: c.nombre,
            selected: c.id == selected
          })
        );
      });
    });
  }

  $('#regionSelect').on('change', function () {
    loadComunas(this.value, 0);
  });

  loadRegiones();

  /* ===============================
     ZONA → DISTRITO
  =============================== */
  function loadZonas() {
    $.getJSON('ajax_zonas.php', function (data) {
      const $z = $('#zonaSelect');
      $z.html('<option value="0">-- Todas --</option>');

      data.forEach(z => {
        $z.append(
          $('<option>', {
            value: z.id,
            text: z.nombre,
            selected: z.id == F.zona
          })
        );
      });

      if (F.zona > 0) {
        loadDistritos(F.zona, F.distrito);
      }
    });
  }

  function loadDistritos(zona, selected) {
    const $d = $('#distritoSelect');
    $d.html('<option value="0">-- Todos --</option>');

    if (!zona || zona == 0) return;

    $.getJSON('ajax_distritos.php', { zona }, function (data) {
      data.forEach(d => {
        $d.append(
          $('<option>', {
            value: d.id,
            text: d.nombre,
            selected: d.id == selected
          })
        );
      });
    });
  }

  $('#zonaSelect').on('change', function () {
    loadDistritos(this.value, 0);
  });

  loadZonas();

});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('formFiltrosGaleria');
  const btnFiltrar = document.getElementById('btnFiltrar');
  const overlay = document.getElementById('loadingOverlayGaleria');
  const tabs = document.querySelectorAll('#tabsGaleria a.nav-link');

  function mostrarCarga() {
    if (overlay) {
      overlay.style.display = 'flex';
    }

    if (btnFiltrar) {
      btnFiltrar.disabled = true;
      btnFiltrar.innerHTML = '<span class="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span> Cargando...';
    }
  }

  if (form) {
    form.addEventListener('submit', function () {
      mostrarCarga();
    });
  }

  if (tabs.length) {
    tabs.forEach(function(tab) {
      tab.addEventListener('click', function () {
        if (overlay) {
          overlay.style.display = 'flex';
        }
      });
    });
  }
});
</script>

<script>
$(document).ready(function () {
  $(document).on('click', '.img-click', function () {
    const urlsRaw = $(this).attr('data-urls') || '';
    const local = $(this).attr('data-local') || 'Vista de fotos';

    const fotos = urlsRaw
      .split('||')
      .map(u => u.trim())
      .filter(u => u !== '');

    abrirModalFotos(fotos, local);
  });

  function abrirModalFotos(fotos, titulo) {
    const $contenedor = $('#modalBodyImgs');
    const $indicadores = $('#carouselIndicatorsFotos');
    const $contador = $('#contadorFotosModal');
    const $titulo = $('#fullSizeModalLabel');
    const $prev = $('#carouselFotosModal .carousel-control-prev');
    const $next = $('#carouselFotosModal .carousel-control-next');

    $contenedor.html('');
    $indicadores.html('');
    $titulo.text(titulo || 'Vista de fotos');

    if (!Array.isArray(fotos) || fotos.length === 0) {
      $contenedor.html(`
        <div class="carousel-item active">
          <div class="d-flex h-100 w-100 align-items-center justify-content-center text-white">
            No hay fotos disponibles
          </div>
        </div>
      `);
      $contador.text('');
      $prev.hide();
      $next.hide();
      $indicadores.hide();
      $('#fullSizeModal').modal('show');
      return;
    }

    fotos.forEach(function (foto, index) {
      $contenedor.append(`
        <div class="carousel-item ${index === 0 ? 'active' : ''}">
          <img src="${foto}" class="d-block" alt="Foto ${index + 1}">
        </div>
      `);

      $indicadores.append(`
        <li data-target="#carouselFotosModal"
            data-slide-to="${index}"
            class="${index === 0 ? 'active' : ''}"></li>
      `);
    });

    if (fotos.length > 1) {
      $prev.show();
      $next.show();
      $indicadores.show();
    } else {
      $prev.hide();
      $next.hide();
      $indicadores.hide();
    }

    $contador.text(`1 de ${fotos.length}`);

    $('#carouselFotosModal').carousel(0);

    $('#carouselFotosModal')
      .off('slid.bs.carousel')
      .on('slid.bs.carousel', function () {
        const index = $('#carouselFotosModal .carousel-item.active').index() + 1;
        $contador.text(`${index} de ${fotos.length}`);
      });

    $('#fullSizeModal').modal('show');
  }

  $('#fullSizeModal').on('hidden.bs.modal', function () {
    $('#modalBodyImgs').html('');
    $('#carouselIndicatorsFotos').html('');
    $('#contadorFotosModal').text('');
    $('#fullSizeModalLabel').text('Vista de fotos');
  });
});
</script>


</body>
</html>
