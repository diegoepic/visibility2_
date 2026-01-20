<?php
// ui_dashboard.php
// 1) Iniciar sesión y ajustes iniciales
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/_session_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'
] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'
] . '/visibility2/portal/modulos/session_data.php';

// 2) Variables de sesión: empresa, división, perfil
$empresa_id  = $_SESSION['empresa_id'];
$division_id = $_SESSION['division_id'];
$perfilUser  = $_SESSION['perfil_nombre'];



// 3) Obtener el nombre de la empresa
$stmt_empresa = $conn->prepare("SELECT nombre FROM empresa WHERE id = ?");
$stmt_empresa->bind_param("i", $empresa_id);
$stmt_empresa->execute();
$stmt_empresa->bind_result($nombre_empresa);
if (!$stmt_empresa->fetch()) {
    $stmt_empresa->close();
    die("Empresa no encontrada o error al obtenerla.");
}
$stmt_empresa->close();

// Determinar si es Mentecreativa
$es_mentecreativa = false;
$nombre_empresa_limpio = mb_strtolower(trim($nombre_empresa), 'UTF-8');
if ($nombre_empresa_limpio === 'mentecreativa') {
    $es_mentecreativa = true;
}



if (isset($_GET['estado'
])) {
    $estado_seleccionado = intval($_GET['estado'
  ]);
} else {
  // Mentecreativa arranca en 'En curso (1)', resto en 'En proceso (2)'
    $estado_seleccionado = $es_mentecreativa ? 1 : 2;
}
// 4) Obtener listado de empresas (si es Mentecreativa) para el filtro
if ($es_mentecreativa) {
    $stmt_all_empresas = $conn->prepare("SELECT id, nombre FROM empresa ORDER BY nombre ASC");
    if ($stmt_all_empresas) {
        $stmt_all_empresas->execute();
        $res_all = $stmt_all_empresas->get_result();
        $empresas_all = $res_all->fetch_all(MYSQLI_ASSOC);
        $stmt_all_empresas->close();
  } else {
        $empresas_all = [];
  }
} else {
  // Si no es Mentecreativa, sólo su empresa
    $empresas_all = [
    ['id' => $empresa_id, 'nombre' => $nombre_empresa
    ]
  ];
}
// 5) Leer filtros GET (empresa y división)
$empresa_seleccionada  = $empresa_id;
if ($es_mentecreativa && isset($_GET['empresa'
])) {
    $empresa_seleccionada = intval($_GET['empresa'
  ]);
}
$division_seleccionada = $division_id;
if (isset($_GET['division'
])) {
    $division_seleccionada = intval($_GET['division'
  ]);
}
// 6) Armar la parte de la cláusula WHERE para filtrar
$filtros_sql = "";
$parametros  = [];
$tipos_param = "";

// Filtrar por empresa
if ($es_mentecreativa) {
    if ($empresa_seleccionada > 0) {
        $filtros_sql .= " AND f.id_empresa = ?";
        $parametros[] = $empresa_seleccionada;
        $tipos_param .= "i";
  }
} else {
    $filtros_sql .= " AND f.id_empresa = ?";
    $parametros[] = $empresa_id;
    $tipos_param .= "i";
}
// Filtrar por división
if ($division_seleccionada > 0) {
    $filtros_sql .= " AND f.id_division = ?";
    $parametros[] = $division_seleccionada;
    $tipos_param .= "i";
}
// Filtrar por estado si es 1 o 3
if ($estado_seleccionado > 0) {
    $filtros_sql   .= " AND f.estado = ?";
    $parametros[]   = $estado_seleccionado;
    $tipos_param   .= "i";
}
// ----------------------------------------------------------------------------
// 7) Consulta para campañas IPT (tipo=3)
// ----------------------------------------------------------------------------
$sql_ipt = "
    SELECT
        f.id AS id_campana,
        f.nombre AS nombre_campana,
        f.modalidad AS modalidad,
        f.reference_image    AS reference_image,
        DATE(f.fechaInicio) AS fechaInicio,
        DATE(f.fechaTermino) AS fechaTermino,
        e.nombre AS nombre_empresa,
        COUNT(DISTINCT fq.id_local) AS locales_programados,
        COUNT(DISTINCT CASE 
             WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','solo_auditoria','en proceso','solo_retirado','cancelado')
             THEN CONCAT(l.codigo, fq.fechaVisita)
             END
        ) AS locales_visitados,
        COUNT(DISTINCT CASE
             WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','solo_auditoria','solo_retirado')
             THEN CONCAT(l.codigo, fq.fechaVisita)
             END
        ) AS locales_implementados,
        ROUND(
            (
             COUNT(DISTINCT CASE
                WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','solo_auditoria','en proceso','cancelado','solo_retirado')
                THEN CONCAT(l.codigo, fq.fechaVisita)
             END)
             / 
             COUNT(DISTINCT fq.id_local)
            ) * 100
        ) AS porcentaje_visitado,
        ROUND(
            (
             COUNT(DISTINCT CASE
                WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','solo_auditoria','solo_retirado')
                THEN CONCAT(l.codigo, fq.fechaVisita)
             END)
             /
             COUNT(DISTINCT fq.id_local)
            ) * 100
        ) AS porcentaje_completado,
        f.estado
    FROM formulario f
    INNER JOIN empresa e ON e.id = f.id_empresa
    INNER JOIN formularioQuestion fq ON fq.id_formulario = f.id
    INNER JOIN local l ON l.id = fq.id_local
    WHERE f.tipo = 3
      AND fq.id_usuario != 50
      $filtros_sql
    GROUP BY f.id, f.nombre, f.fechaInicio, f.fechaTermino, e.nombre, f.estado
    ORDER BY f.fechaInicio DESC
";
// ----------------------------------------------------------------------------
// 8) Consulta para campañas Programadas (tipo=1)
// ----------------------------------------------------------------------------
$sql_prog = "
SELECT
    f.id AS id_campana,
    f.nombre AS nombre_campana,
    f.modalidad AS modalidad,
    f.reference_image AS reference_image,
    DATE(f.fechaInicio) AS fechaInicio,
    DATE(f.fechaTermino) AS fechaTermino,
    e.nombre AS nombre_empresa,

    /* ---- PROGRAMADOS ---- */
    CASE
        WHEN f.modalidad = 'solo_auditoria'
            THEN COUNT(fq.id_local)
        ELSE
            COUNT(DISTINCT fq.id_local)
    END AS locales_programados,

    /* ---- VISITADOS ---- */
    CASE 
        WHEN f.modalidad = 'solo_auditoria'
            THEN COUNT(
                CASE
                    WHEN fq.pregunta IN (
                        'solo_auditoria',
                        'solo_retirado',
                        'en proceso',
                        'cancelado',
                        'implementado_auditado'
                    )
                    THEN fq.id
                END
            )
        ELSE COUNT(DISTINCT
                CASE
                    WHEN fq.pregunta IN (
                        'implementado_auditado',
                        'solo_implementado',
                        'solo_auditoria',
                        'solo_retirado',
                        'en proceso',
                        'cancelado'
                    )
                    THEN l.codigo
                END
            )
    END AS locales_visitados,

    /* ---- IMPLEMENTADOS ---- */
    CASE 
        WHEN f.modalidad = 'solo_auditoria'
            THEN COUNT(
                CASE
                    WHEN fq.pregunta IN ('solo_auditoria','implementado_auditado','solo_retirado')
                    THEN fq.id
                END
            )
        ELSE COUNT(DISTINCT
                CASE
                    WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','solo_auditoria','solo_retirado')
                    THEN l.codigo
                END
            )
    END AS locales_implementados,

    /* --- % VISITADOS --- */
    CASE
        WHEN f.modalidad = 'solo_auditoria'
            THEN ROUND(
                COUNT(
                    CASE WHEN fq.pregunta IN (
                        'solo_auditoria', 'en proceso','cancelado','solo_retirado','implementado_auditado'
                    )
                    THEN fq.id END
                )
                /
                COUNT(fq.id_local)
                * 100
            )
        ELSE ROUND(
            COUNT(DISTINCT
                CASE WHEN fq.pregunta IN (
                    'implementado_auditado','solo_implementado','solo_auditoria','solo_retirado','en proceso','cancelado'
                )
                THEN l.codigo END
            )
            /
            COUNT(DISTINCT fq.id_local)
            * 100
        )
    END AS porcentaje_visitado,

    /* --- % COMPLETADOS --- */
    CASE
        WHEN f.modalidad = 'solo_auditoria'
            THEN ROUND(
                COUNT(
                    CASE WHEN fq.pregunta IN ('solo_auditoria','implementado_auditado','solo_retirado')
                    THEN fq.id END
                )
                /
                COUNT(fq.id_local)
                * 100
            )
        ELSE ROUND(
            COUNT(DISTINCT 
                CASE WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','solo_auditoria','solo_retirado')
                THEN l.codigo END
            )
            /
            COUNT(DISTINCT fq.id_local)
            * 100
        )
    END AS porcentaje_completado,

    f.estado
    FROM formulario f
    INNER JOIN empresa e ON e.id = f.id_empresa
    INNER JOIN formularioQuestion fq ON fq.id_formulario = f.id
    INNER JOIN local l ON l.id = fq.id_local
    WHERE f.tipo = 1
      $filtros_sql
    GROUP BY f.id, f.nombre, f.fechaInicio, f.fechaTermino, e.nombre, f.estado
    ORDER BY f.fechaInicio DESC
";
// ----------------------------------------------------------------------------
// 9) Consulta para campañas Complementarias (tipo=2)
// ----------------------------------------------------------------------------
$sql_comp = "
    SELECT
        f.id AS id_campana,
        f.nombre AS nombre_campana,
        f.estado
    FROM formulario f
    WHERE f.tipo = 2
      {$filtros_sql
}
    ORDER BY f.nombre ASC
";
// 10) Ejecutar consultas
// IPT
$stmt_ipt = $conn->prepare($sql_ipt);
if (!$stmt_ipt) {
    die("Error en consulta IPT: " . htmlspecialchars($conn->error));
}
if (!empty($parametros)) {
    $stmt_ipt->bind_param($tipos_param, ...$parametros);
}
$stmt_ipt->execute();
$result_ipt = $stmt_ipt->get_result();
$stmt_ipt->close();

// Programadas
$stmt_prog = $conn->prepare($sql_prog);
if (!$stmt_prog) {
    die("Error en consulta Programadas: " . htmlspecialchars($conn->error));
}
if (!empty($parametros)) {
    $stmt_prog->bind_param($tipos_param, ...$parametros);
}
$stmt_prog->execute();
$result_prog = $stmt_prog->get_result();
$stmt_prog->close();

// Complementarias
$stmt_comp = $conn->prepare($sql_comp);
if (!$stmt_comp) {
    die("Error en consulta Complementarias: " . htmlspecialchars($conn->error));
}
if (!empty($parametros)) {
    $stmt_comp->bind_param($tipos_param, ...$parametros);
}
$stmt_comp->execute();
$result_comp = $stmt_comp->get_result();
$stmt_comp->close();

// Convertir Complementarias en array PHP
$compCampanas = [];
while ($row = $result_comp->fetch_assoc()) {
    $compCampanas[] = [
        'id_campana'     => (int)$row['id_campana'
    ],
        'nombre_campana' => htmlspecialchars($row['nombre_campana'
    ], ENT_QUOTES, 'UTF-8'),
        'estado'         => htmlspecialchars($row['estado'
    ], ENT_QUOTES, 'UTF-8')
  ];
}
// Obtener divisiones para el filtro
if ($es_mentecreativa && $empresa_seleccionada > 0) {
    $divisiones = obtenerDivisionesPorEmpresa($empresa_seleccionada);
} elseif (!$es_mentecreativa) {
    $divisiones = obtenerDivisionesPorEmpresa($empresa_id);
} else {
    $divisiones = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Visibility 2 | Dashboard</title>
  <!-- CSS principales -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&amp;display=fallback">
  <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
  <link rel="stylesheet" href="dist/css/adminlte.min.css">
  <link rel="stylesheet" href="dist/css/stylesUI.css">
  <style>
      /* ============================================
   DASHBOARD CARDS - NORMALIZACIÓN Y RESPONSIVE
   Soluciona problemas de descuadre en cards
   ============================================ */

/* ---- CONTENEDOR DE CAMPAÑAS ---- */
.card-body .row {
    display: flex;
    flex-wrap: wrap;
}

/* ---- COLUMNAS DE CARDS (campaign-item) ---- */
.campaign-item,
.campaign-item-ipt,
.col-12.col-sm-6.col-md-4.d-flex.align-items-stretch {
    display: flex;
    flex-direction: column;
    margin-bottom: 20px;
}

/* ---- CARD PRINCIPAL ---- */
.card.card-widget.widget-user {
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 420px; /* Altura mínima consistente */
}

/* ---- HEADER DE LA CARD ---- */
.widget-user .widget-user-header {
    position: relative;
    padding: 15px 20px 50px 20px; /* Padding inferior para espacio de imagen */
    min-height: 140px; /* Altura fija para el header */
    height: 140px;
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    overflow: hidden;
}

/* ---- TÍTULO DE CAMPAÑA (nombre) ---- */
.widget-user .widget-user-username {
    display: block;
    width: calc(100% - 60px); /* Dejar espacio para botón descarga */
    margin: 0;
    padding-right: 10px;
    font-size: 14px;
    font-weight: 700;
    line-height: 1.3;
    max-height: 36px; /* Máximo 2 líneas */
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    word-wrap: break-word;
    overflow-wrap: break-word;
}

/* ---- DESCRIPCIÓN (estado y fechas) ---- */
.widget-user .widget-user-desc {
    margin-top: 4px;
    font-size: 12px;
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* ---- IMAGEN DE REFERENCIA ---- */
.widget-user .widget-user-image {
    position: absolute;
    top: auto !important;
    bottom: -35px; /* Posición desde abajo del header */
    left: 50%;
    transform: translateX(-50%);
    margin: 0;
    z-index: 10;
}

.widget-user .widget-user-image img,
.widget-user .widget-user-image .reference-img {
    width: 80px !important;
    height: 80px !important;
    min-width: 80px;
    min-height: 80px;
    max-width: 80px;
    max-height: 80px;
    object-fit: cover;
    object-position: center;
    border-radius: 50%;
    border: 3px solid #fff;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    background-color: #f5f5f5;
}

/* ---- FOOTER DE LA CARD ---- */
.widget-user .card-footer {
    flex: 1;
    display: flex;
    flex-direction: column;
    padding-top: 50px !important; /* Espacio para la imagen que sobresale */
    padding-bottom: 15px;
}

/* ---- INDICADORES (Programados/Visitados/Ejecutados) ---- */
.widget-user .card-footer > .row:first-child {
    margin-bottom: 10px;
}

.widget-user .description-block {
    margin: 5px 0;
    padding: 5px 0;
}

.widget-user .description-header {
    font-size: 1.1rem;
    font-weight: 700;
    margin: 0;
}

.widget-user .description-text {
    font-size: 10px !important;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: block;
}

/* ---- BOTONES DE ACCIÓN ---- */
.widget-user .btn-app {
    margin: 3px auto;
    min-width: 70px;
    max-width: 85px;
    padding: 8px 5px;
    font-size: 11px;
}

.widget-user .btn-app i {
    font-size: 16px;
}

/* ---- PORCENTAJES ---- */
.widget-user .inner {
    padding: 8px 5px;
}

.widget-user .inner h3 {
    margin: 0;
}

.widget-user .inner p {
    margin: 5px 0 0 0;
    font-size: 11px !important;
    line-height: 1.2;
}

/* ---- CHECKBOX SELECCIÓN ---- */
.widget-user-header input[type="checkbox"] {
    position: absolute !important;
    top: 10px !important;
    left: 10px !important;
    margin: 0 !important;
    width: 18px;
    height: 18px;
    cursor: pointer;
    z-index: 15;
}

/* ---- BOTÓN DESCARGA EXCEL ---- */
.widget-user-header .dropdown.dl-compact,
.widget-user-header .download-link,
.widget-user-header .download-excel-trigger {
    position: absolute !important;
    top: 8px !important;
    right: 10px !important;
    z-index: 15;
}

.widget-user-header .download-link img {
    width: 35px !important;
    height: auto;
}

/* ---- BARRA DE PROGRESO DESCARGA ---- */
.widget-user-header .progress {
    position: absolute !important;
    top: 50px !important;
    right: 10px !important;
    width: 100px !important;
}

/* ============================================
   RESPONSIVE
   ============================================ */

/* Tablets */
@media (max-width: 991.98px) {
    .widget-user .widget-user-header {
        min-height: 130px;
        height: 130px;
    }
    
    .widget-user .widget-user-username {
        font-size: 13px;
        max-height: 34px;
    }
    
    .widget-user .widget-user-image img,
    .widget-user .widget-user-image .reference-img {
        width: 70px !important;
        height: 70px !important;
        min-width: 70px;
        min-height: 70px;
        max-width: 70px;
        max-height: 70px;
    }
    
    .widget-user .card-footer {
        padding-top: 45px !important;
    }
    
    .card.card-widget.widget-user {
        min-height: 400px;
    }
}

/* Móviles */
@media (max-width: 767.98px) {
    .campaign-item,
    .campaign-item-ipt,
    .col-12.col-sm-6.col-md-4.d-flex.align-items-stretch {
        max-width: 100%;
        flex: 0 0 100%;
    }
    
    .widget-user .widget-user-header {
        min-height: 120px;
        height: 120px;
        padding: 12px 15px 45px 15px;
    }
    
    .widget-user .widget-user-username {
        font-size: 14px;
        width: calc(100% - 50px);
        max-height: 40px;
    }
    
    .widget-user .widget-user-desc {
        font-size: 11px;
    }
    
    .widget-user .widget-user-image {
        bottom: -30px;
    }
    
    .widget-user .widget-user-image img,
    .widget-user .widget-user-image .reference-img {
        width: 65px !important;
        height: 65px !important;
        min-width: 65px;
        min-height: 65px;
        max-width: 65px;
        max-height: 65px;
    }
    
    .widget-user .card-footer {
        padding-top: 40px !important;
    }
    
    .widget-user .btn-app {
        min-width: 60px;
        padding: 6px 4px;
        font-size: 10px;
    }
    
    .widget-user .btn-app i {
        font-size: 14px;
    }
    
    .card.card-widget.widget-user {
        min-height: 380px;
    }
    
    .widget-user-header input[type="checkbox"] {
        width: 16px;
        height: 16px;
    }
}

/* Móviles pequeños */
@media (max-width: 575.98px) {
    .widget-user .widget-user-header {
        min-height: 115px;
        height: auto;
        min-height: 115px;
    }
    
    .widget-user .description-header {
        font-size: 1rem;
    }
    
    .widget-user .inner p {
        font-size: 10px !important;
    }
    
    .widget-user .inner h3 b {
        font-size: 16px !important;
    }
}

/* ============================================
   ACTIVIDADES COMPLEMENTARIAS
   (Cards más simples sin porcentajes)
   ============================================ */
.card-body .row > .col-12.col-sm-6.col-md-4:not(.campaign-item):not(.campaign-item-ipt) .widget-user {
    min-height: 320px;
}

/* ============================================
   UTILIDADES ADICIONALES
   ============================================ */

/* Efecto hover suave */
.card.card-widget.widget-user {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.card.card-widget.widget-user:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
}

/* Imagen zoom */
.widget-user-image img.zoom {
    cursor: pointer;
    transition: transform 0.2s ease;
}

.widget-user-image img.zoom:hover {
    transform: translateX(-50%) scale(1.1);
}

/* Fix para d-flex align-items-stretch en Bootstrap */
.row.d-flex > [class*="col-"] {
    display: flex;
}

/* Asegurar que todas las cards en una fila tengan misma altura */
.card-body > .container > .row {
    align-items: stretch;
}

.card-body > .container > .row > [class*="col-"] {
    display: flex;
    flex-direction: column;
}

.card-body > .container > .row > [class*="col-"] > .card {
    flex: 1;
}
  </style>
</head>
<body>

<!-- ===================== BLOQUE: Filtros ===================== -->
<div class="container mt-4">
  <form method="GET" action="ui_dashboard.php" id="filterForm" class="form-inline mb-3">
    <?php if ($division_id === 1): // Usuarios “corporativo” (división 1) ?>
      <!-- Mostrar filtro de división -->
      <label class="mr-2" for="division_filter">División:</label>
      <select name="division" id="division_filter" class="form-control mr-2">
        <option value="0" <?= $division_seleccionada===0 ? 'selected':'' ?>>-- Todas las Divisiones --</option>
        <?php foreach ($divisiones as $d): ?>
          <option value="<?= $d['id'] ?>" <?= $division_seleccionada===$d['id'
]?'selected':'' ?>>
            <?= htmlspecialchars($d['nombre'
],ENT_QUOTES,'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>

      <!-- Mostrar filtro de estado: En curso (1) o Finalizadas (3) -->
      <label class="mr-2" for="estado_filter">Estado:</label>
      <select name="estado" id="estado_filter" class="form-control mr-2">
        <option value="1" <?= $estado_seleccionado===1?'selected':'' ?>>En curso</option>
        <option value="3" <?= $estado_seleccionado===3?'selected':'' ?>>Finalizadas</option>
      </select>

<?php else: ?>
  <input type="hidden" name="division" value="<?= $division_id ?>">
  <label class="mr-2" for="estado_filter">Estado:</label>
  <select name="estado" id="estado_filter" class="form-control mr-2">
    <!-- Cambiado value de 2 a 1 -->
    <option value="1" <?= $estado_seleccionado===1?'selected':'' ?>>En proceso</option>
    <option value="3" <?= $estado_seleccionado===3?'selected':'' ?>>Finalizadas</option>
  </select>
<?php endif; ?>
  </form>
</div>

<!-- ===================== BLOQUE: CAMPAÑAS PROGRAMADAS (tipo=1) ===================== -->
<div class="card card-widget mt-4">
  <div class="card-header">
    <div class="user-block">
      <img class="img-circle" src="dist/img/mentecreativa.png" alt="User Image">
      <span class="username"><a href="#">CAMPAÑAS PLANIFICADAS</a></span>
      <span class="description">Última actividad cargada</span>
    </div>
<div class="card-tools">
  <div class="d-flex justify-content-between align-items-start w-100 py-2 px-3"style="gap:1rem;">
    <div class="me-4">
      <div class="input-group input-group-sm">
        <span class="input-group-text bg-white border-end-0">
          <i class="fas fa-search"></i>
        </span>
        <input
          type="text"
          id="searchInput"
          class="form-control border-start-0"
          placeholder="Buscar campaña…"
        >
      </div>
    </div>
    <div class="d-flex flex-column">
      <div class="form-check mb-2">
        <input
          class="form-check-input"
          type="checkbox"
          id="selectAllCheckbox"
        >
        <label class="form-check-label ms-2" for="selectAllCheckbox">
          Seleccionar todos
        </label>
      </div>
    </div>      
    <div class="d-flex flex-column">      
      <button
        id="bulkDownloadBtn"
        class="btn btn-sm btn-primary"
      >
        <i class="fas fa-download me-1"></i> Descarga masiva
      </button>
    </div>
    <button
      type="button"
      class="btn btn-tool"
      data-card-widget="collapse"
    >
      <i class="fas fa-minus"></i>
    </button>
  </div>
</div>
          </div>
  <div class="card-body">
    <div class="container mt-4">
     
      <div class="row" id="campaignsContainer">
        <?php if ($result_prog && $result_prog->num_rows > 0): ?>
          <?php while ($rowP = $result_prog->fetch_assoc()): ?>
            <?php
              switch($rowP['estado'
                ]){
                                case 1: $estado_desc='EN CURSO';     break;
                                case 2: $estado_desc='EN PROCESO';   break;
                                case 3: $estado_desc='FINALIZADO';   break;
                                case 4: $estado_desc='CANCELADO';    break;
                                default:$estado_desc='DESCONOCIDO';  break;
                }
                              $campana_upper = htmlspecialchars(mb_strtoupper($rowP['nombre_campana'
                ],'UTF-8'));
                              $locales_programados   = $rowP['locales_programados'
                ];
                              $locales_visitados     = $rowP['locales_visitados'
                ];
                              $locales_implementados = $rowP['locales_implementados'
                ];
                              $porc_visitados        = $rowP['porcentaje_visitado'
                ];
                              $porc_completados      = $rowP['porcentaje_completado'
                ];
                            ?>
            <!-- 4) Cada “.campaign-item” es la columna que engloba la card -->
            <div class="col-12 col-sm-6 col-md-4 align-items-stretch campaign-item">
              <div class="card card-widget widget-user shadow w-100">
                <div class="widget-user-header bg-info position-relative">
                    <div class="dropdown dl-compact" style="position:absolute; top:10px; right:10px; z-index:5;">
                      <button class="btn btn-sm btn-success dropdown-toggle px-2 py-1" type="button" data-toggle="dropdown" aria-expanded="false" title="Descargar Excel">
                        <i class="fas fa-file-excel"></i>
                      </button>
                      <div class="dropdown-menu dropdown-menu-right">
                       <a class="dropdown-item download-excel-trigger"
                           href="#"
                           data-id="<?= (int)$rowP['id_campana']; ?>"
                           data-modalidad="<?= htmlspecialchars($rowP['modalidad'], ENT_QUOTES, 'UTF-8'); ?>">
                           DESCARGAR DATA
                        </a>
                        <!--a class="dropdown-item"
                           href="/visibility2/portal/informes/descargar_excel_historico.php?id=<?= (int)$rowP['id_campana']; ?>"
                           target="_blank" rel="noopener">
                           Excel de gestiones históricas por local
                        </a-->
                      </div>
                    </div>
                  <div class="progress"
                       style="position:absolute; top:60px; right:10px; width:120px; display:none; background:#e9ecef; border-radius:5px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated"
                         role="progressbar"
                         style="width:0%;"
                         aria-valuemin="0" aria-valuemax="100">0%
                    </div>
                  </div>
                      <input type="checkbox"
                             id="chk-prog<?php echo $rowP['id_campana']; ?>"
                             class="mr-2" style="position: absolute;margin-top: -4%;margin-left: -48%;"
                             value="<?php echo $rowP['id_campana']; ?>"
                             data-modalidad="<?php echo htmlspecialchars($rowP['modalidad'], ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="d-flex align-items-center">
                      <h3 class="widget-user-username text-truncate campaign-name mb-0">
                        <?php echo $campana_upper; ?>
                      </h3>
                    </div> 
                  <h5 class="widget-user-desc"><?php echo $estado_desc; ?></h5>
                  <h5 class="widget-user-desc">
                    <?php echo htmlspecialchars($rowP['fechaInicio']); ?> al
                    <?php echo htmlspecialchars($rowP['fechaTermino']); ?>
                  </h5>
                </div>
                <div class="widget-user-image" style="top:110px!important;">
                  <img 
                    id="refImg-<?php echo $rowP['id_campana']; ?>"
                    class="reference-img elevation-2 zoom"
                     src="<?php echo htmlspecialchars($rowP['reference_image'] ?: 'dist/img/visibility2Logo.png'); ?>"
                    data-camp="<?php echo $rowP['id_campana'];?>"
                  >
                </div>
                <div class="card-footer">
                  <!-- Indicadores -->
                  <div class="row">
                    <div class="col-sm-4 border-right">
                      <div class="description-block">
                        <h5 class="description-header"><?php echo $locales_programados; ?></h5>
                        <span class="description-text" style="font-size:10px;">PROGRAMADOS</span>
                      </div>
                    </div>
                    <div class="col-sm-4 border-right">
                      <div class="description-block">
                        <h5 class="description-header"><?php echo $locales_visitados; ?></h5>
                        <span class="description-text" style="font-size:10px;">VISITADOS</span>
                      </div>
                    </div>
                    <div class="col-sm-4">
                      <div class="description-block">
                        <h5 class="description-header"><?php echo $locales_implementados; ?></h5>
                        <span class="description-text" style="font-size:10px;">EJECUTADOS</span>
                      </div>
                    </div>
                  </div>
                  <!-- Botones acción -->
                  <div class="row mt-2 text-center">
                    <div class="col-sm-4">
                        <a href="/visibility2/portal/modulos/mod_formulario/mapa_campana.php?id=<?php echo $rowP['id_campana']; ?>"
                           class="btn btn-app"
                           target="_blank"   
                           title="Ver mapa en línea">
                          <i class="fas fa-map-marker-alt"></i> EN LÍNEA
                        </a>
                    </div>
                    <div class="col-sm-4">
                      <a class="btn btn-app"
                         href="modulos/mod_galeria/mod_galeria.php?id=<?php echo $rowP['id_campana']; ?>"
                         target="_self">
                        <i class="fas fa-image"></i> GALERÍA
                      </a>
                    </div>
                    <div class="col-sm-4">
                        <a class="btn btn-app"
                           href="UI_informe.php?id=<?php echo $rowP['id_campana']; ?>&division=<?php echo $division_seleccionada; ?>"
                           target="_self">
                          <i class="fas fa-bars"></i> INFORME
                        </a>
                    </div>
                  </div>
                  <!-- Porcentajes -->
                  <div class="row text-center mt-2">
                    <div class="col-sm-6 border-right">
                      <div class="inner">
                        <h3 class="description-header">
                          <b style="color:yellowgreen;font-size:20px;"><?php echo $porc_visitados; ?>%</b>
                        </h3>
                        <p style="font-size:13px;font-weight:bold;">SALAS VISITADAS</p>
                      </div>
                    </div>
                    <div class="col-sm-6">
                      <div class="inner">
                        <h3 class="description-header">
                          <b style="color:yellowgreen;font-size:20px;"><?php echo $porc_completados; ?>%</b>
                        </h3>
                        <p style="font-size:13px;font-weight:bold;">SALAS EJECUTADAS</p>
                      </div>
                    </div>
                  </div>
                </div><!-- /.card-footer -->
              </div><!-- /.card -->
            </div><!-- /.col -->
          <?php endwhile; ?>
        <?php else: ?>
          <div class="col-12">
            <div class="alert alert-warning">No hay campañas planificadas disponibles.</div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>


<!-- ===================== BLOQUE: CAMPAÑAS IPT (tipo=3) ===================== -->
<div class="card card-widget mt-4">
  <div class="card-header">
    <div class="user-block">
      <img class="img-circle" src="dist/img/mentecreativa.png" alt="User Image">
      <span class="username"><a href="#">RUTAS PLANIFICADAS</a></span>
      <span class="description">Última actividad IPT cargada</span>
    </div>
    <div class="card-tools">
  <div class="d-flex justify-content-between align-items-start w-100 py-2 px-3"style="gap:1rem;">
    <div class="me-4">
      <div class="input-group input-group-sm">
        <span class="input-group-text bg-white border-end-0">
          <i class="fas fa-search"></i>
        </span>
        <input
          type="text"
          id="searchInput-ipt"
          class="form-control border-start-0"
          placeholder="Buscar campaña…"
        >
      </div>
    </div>
    <div class="d-flex flex-column">
      <div class="form-check mb-2">
        <input
          class="form-check-input"
          type="checkbox"
          id="selectAllCheckboxIPT"
        >
        <label class="form-check-label ms-2" for="selectAllCheckbox">
          Seleccionar todos
        </label>
      </div>
    </div>      
    <div class="d-flex flex-column">      
      <button
        id="bulkDownloadBtnIPT"
        class="btn btn-sm btn-primary"
      >
        <i class="fas fa-download me-1"></i> Descarga masiva
      </button>
    </div>
    <button
      type="button"
      class="btn btn-tool"
      data-card-widget="collapse"
    >
      <i class="fas fa-minus"></i>
    </button>
  </div>
    </div>
  </div>
  <div class="card-body">
    <div class="container mt-4">
      <div class="row">
        <?php if ($result_ipt && $result_ipt->num_rows>0): ?>
          <?php while($rowIpt = $result_ipt->fetch_assoc()): ?>
            <?php
              switch($rowIpt['estado'
]){
                case 1: $ed='En Curso';break;
                case 2: $ed='En Proceso';break;
                case 3: $ed='Finalizado';break;
                case 4: $ed='Cancelado';break;
                default:$ed='Desconocido';
}
              $prog  = $rowIpt['locales_programados'
];
              $visit = $rowIpt['locales_visitados'
];
              $exec  = $rowIpt['locales_implementados'
];
              $pctV  = $rowIpt['porcentaje_visitado'
];
              $pctC  = $rowIpt['porcentaje_completado'
];
            ?>
            <div class="col-12 col-sm-6 col-md-4 align-items-stretch campaign-item-ipt">
              <div class="card card-widget widget-user shadow w-100">
                <div class="widget-user-header bg-info position-relative">
                  <!-- Descargar Excel -->
                     <a
                      href="#"
                      class="position-absolute download-link download-excel-trigger"
                      data-id="<?php echo $rowIpt['id_campana']; ?>"
                      data-modalidad="<?php echo htmlspecialchars($rowIpt['modalidad'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                      title="Descargar Excel"
                      style="top:10px; right:10px;"
                    >
                      <img src="images/icon/download_excel.png" alt="Download" style="width:40px; cursor:pointer;">
                    </a>
                  <div class="progress"
                       style="position:absolute; top:60px; right:10px; width:120px; display:none; background:#e9ecef; border-radius:5px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated"
                         role="progressbar"
                         style="width:0%;"
                         aria-valuemin="0" aria-valuemax="100">0%
                    </div>
                  </div>
                      <input type="checkbox"
                             id="chk-ipt<?php echo $rowIpt['id_campana']; ?>"
                             class="mr-2" style="position: absolute;margin-top: -4%;margin-left: -48%;"
                             value="<?php echo $rowIpt['id_campana']; ?>"
                             data-modalidad="<?php echo htmlspecialchars($rowIpt['modalidad'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                  <h3 class="widget-user-username campaign-name-ipt">
                    <?php echo htmlspecialchars(mb_strtoupper($rowIpt['nombre_campana'
],'UTF-8')); ?>
                  </h3>
                  <h5 class="widget-user-desc"><?php echo $ed; ?></h5>
                  <h5 class="widget-user-desc">
                    <?php echo htmlspecialchars($rowIpt['fechaInicio'
]); ?> al
                    <?php echo htmlspecialchars($rowIpt['fechaTermino'
]); ?>
                  </h5>
                </div>
                <div class="widget-user-image" style="top:110px!important;">
                  <img 
                    id="refImg-<?php echo $rowIpt['id_campana']; ?>"
                    class="reference-img elevation-2 zoom"
                    style="height: 79px;!important" src="<?php echo htmlspecialchars($rowIpt['reference_image'] ?: 'dist/img/visibility2Logo.png'); ?>"
                    data-camp="<?php echo $rowIpt['id_campana'];?>"
                  >
                </div>
                <div class="card-footer">
                  <!-- Indicadores -->
                  <div class="row">
                    <div class="col-sm-4 border-right">
                      <div class="description-block">
                        <h5 class="description-header"><?php echo $prog; ?></h5>
                        <span class="description-text" style="font-size:10px;">Programados</span>
                      </div>
                    </div>
                    <div class="col-sm-4 border-right">
                      <div class="description-block">
                        <h5 class="description-header"><?php echo $visit; ?></h5>
                        <span class="description-text" style="font-size:10px;">Visitados</span>
                      </div>
                    </div>
                    <div class="col-sm-4">
                      <div class="description-block">
                        <h5 class="description-header"><?php echo $exec; ?></h5>
                        <span class="description-text" style="font-size:10px;">Ejecutados</span>
                      </div>
                    </div>
                  </div>
                  <!-- Botones acción -->
                  <div class="row mt-2 text-center">
                    <div class="col-sm-4">
                   
                    <a href="/visibility2/portal/modulos/mod_formulario/mapa_campana.php?id=<?php echo $rowIpt['id_campana']; ?>"
                       class="btn btn-app"
                       target="_blank">
                      <i class="fas fa-map-marker-alt"></i> EN LÍNEA
                    </a>
                    </div>
                    <div class="col-sm-4">
                      <a class="btn btn-app"
                         href="modulos/mod_galeria/mod_galeria.php?id=<?php echo $rowIpt['id_campana']; ?>"
                         target="_self">
                        <i class="fas fa-image"></i> Galería
                      </a>
                    </div>
                    <div class="col-sm-4">
                        <a class="btn btn-app"
                           href="UI_informe.php?id=<?php echo $rowIpt['id_campana']; ?>&division=<?php echo $division_seleccionada; ?>"
                           target="_self">
                          <i class="fas fa-bars"></i> INFORME
                        </a>
                    </div>
                  </div>
                  <!-- Porcentajes -->
                  <div class="row text-center mt-2">
                    <div class="col-sm-6 border-right">
                      <div class="inner">
                        <h3 class="description-header">
                          <b style="color:yellowgreen;font-size:20px;"><?php echo $pctV; ?>%</b>
                        </h3>
                        <p style="font-size:13px;font-weight:bold;">SALAS VISITADAS</p>
                      </div>
                    </div>
                    <div class="col-sm-6">
                      <div class="inner">
                        <h3 class="description-header">
                          <b style="color:yellowgreen;font-size:20px;"><?php echo $pctC; ?>%</b>
                        </h3>
                        <p style="font-size:13px;font-weight:bold;">SALAS EJECUTADAS</p>
                      </div>
                    </div>
                  </div>
                </div><!-- /.card-footer -->
              </div><!-- /.card -->
            </div><!-- /.col -->
          <?php endwhile; ?>
        <?php else: ?>
          <div class="col-12"><div class="alert alert-warning">No hay rutas IPT disponibles.</div></div>
        <?php endif; ?>
      </div><!-- /.row -->
    </div><!-- /.container -->
  </div><!-- /.card-body -->
</div><!-- /.card IPT -->

<!-- ===================== BLOQUE: Actividades Complementarias (tipo=2) ===================== -->
<div class="card card-widget mt-4">
  <div class="card-header">
    <div class="user-block">
      <img class="img-circle" src="dist/img/mentecreativa.png" alt="User Image">
      <span class="username"><a href="#">ACTIVIDADES COMPLEMENTARIAS</a></span>
      <span class="description">Última actividad complementaria cargada</span>
    </div>
    <div class="card-tools">
      <button class="btn btn-tool" data-card-widget="collapse"><i class="fas fa-minus"></i></button>
    </div>
  </div>
  <div class="card-body">
    <div class="container mt-4">
      <div class="row">
        <?php if (!empty($compCampanas)): ?>
          <?php foreach ($compCampanas as $cc): ?>
            <div class="col-12 col-sm-6 col-md-4 d-flex align-items-stretch">
              <div class="card card-widget widget-user shadow w-100">
                <div class="widget-user-header bg-info position-relative">
                  <!-- Descargar Excel -->
<a
  href="#"
  class="position-absolute download-link-cc"
  data-id="<?php echo $cc['id_campana']; ?>"
  title="Descargar Excel"
  style="top:10px; right:10px;"
>
  <img src="images/icon/download_excel.png" alt="Download" style="width:40px; cursor:pointer;">
</a>
                  <h3 class="widget-user-username text-truncate">
                    <?php echo $cc['nombre_campana'
]; ?>
                  </h3>
                  <h5 class="widget-user-desc">Actividad Complementaria</h5>
                <div class="widget-user-image">
                    <img class="elevation-2 zoom"
                         src="dist/img/visibility2Logo.png"
                         alt="Campaña Complementaria">
                  </div>
                </div>
                <div class="card-footer">
                  <!-- Indicadores -->
                  <div class="row">
                    <div class="col-sm-6 border-right">
                      <div class="description-block">
                        <h5 class="description-header"><?php echo /* no programados for tipo2 */ '-'; ?></h5>
                        <span class="description-text" style="font-size:10px;">Programados</span>
                      </div>
                    </div>
                    <div class="col-sm-6">
                      <div class="description-block">
                        <h5 class="description-header"><?php echo /* no visitados */ '-'; ?></h5>
                        <span class="description-text" style="font-size:10px;">Visitados</span>
                      </div>
                    </div>
                  </div>
                  <!-- Botones acción -->
                  <div class="row mt-2 text-center">
                                      <div class="col-sm-6">
                      <a href="/visibility2/portal/modulos/mod_formulario/mapa_campana.php?id=<?php echo $cc['id_campana']; ?>"
                         class="btn btn-app"
                         target="_blank"
                         title="Ver mapa en línea">
                        <i class="fa fa-play"></i> En línea
                      </a>
                    </div>
                    <div class="col-sm-6">
                      <a class="btn btn-app"
                         href="/visibility2/portal/modulos/mod_galeria/mod_galeria_complementarias.php?id=<?= $cc['id_campana'] ?>"
                         target="_self">
                        <i class="fas fa-image"></i> Galería
                      </a>
                    </div>
                  </div>
                </div><!-- /.card-footer -->
              </div><!-- /.card -->
            </div><!-- /.col -->
          <?php endforeach; ?>
        <?php else: ?>
          <div class="col-12"><div class="alert alert-warning">No hay actividades complementarias.</div></div>
        <?php endif; ?>
      </div><!-- /.row -->
    </div><!-- /.container -->
  </div><!-- /.card-body -->
</div><!-- /.card COMPLEMENTARIAS -->

<!-- Modal de filtro de fechas -->
<div class="modal fade" id="modalFechaFiltro" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Filtrar por rango de fechas</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <form id="formFechaFiltro">
          <div class="form-group">
            <label for="fechaDesde">Desde:</label>
            <input type="date" id="fechaDesde" name="start_date" class="form-control" >
          </div>
          <div class="form-group">
            <label for="fechaHasta">Hasta:</label>
            <input type="date" id="fechaHasta" name="end_date" class="form-control" >
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary" id="btnAplicarFiltro">Aplicar</button>
      </div>
    </div>
  </div>
</div>



<div class="modal fade" id="modalRefImage" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Imagen de Referencia</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body text-center">
        <img id="modalRefImgTag" src="" class="img-fluid mb-2" alt="Referencia">
          <?php if (strtolower($perfilUser) == 'editor' || strtolower($perfilUser) == 'coordinador'): ?>        
        <button id="btnChangeRef" class="btn btn-primary" >Cambiar foto de referencia</button>
          <?php endif; ?>        
        <form id="formChangeRef" enctype="multipart/form-data" style="display:none; margin-top:1rem;">
          <input type="file" name="new_ref" accept="image/*" required class="form-control mb-2">
          <button type="submit" class="btn btn-success">Guardar</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- JS -->
<script src="plugins/jquery/jquery.min.js"></script>
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="dist/js/adminlte.min.js"></script>

<script>
$('#selectAllCheckbox').on('change', function() {
  const marcado = this.checked;
  $("input[id^='chk-prog']").prop('checked', marcado);
});

$('#selectAllCheckboxIPT').on('change', function() {
  const marcado = this.checked;
  $("input[id^='chk-ipt']").prop('checked', marcado);
});
</script>

<script>
// Maneja tanto descarga como vista en línea
$(document).on('click', '.inline-link', function (e) {
  e.preventDefault();
  const id = $(this).data('id');
  $('#modalFechaFiltro')
    .data({ tipo: 'complementaria', id: id, mode: 'inline' })
    .modal('show');
});

// Excel de complementarias: abre modal con modo=excel
$(document).on('click', '.download-link-cc', function (e) {
  e.preventDefault();
  const id = $(this).data('id');
  $('#modalFechaFiltro')
    .data({ tipo: 'complementaria', id: id, mode: 'excel' })
    .modal('show');
});


$('#btnAplicarFiltro').off('click').on('click', function () {
  const modal = $('#modalFechaFiltro');
  const meta  = modal.data() || {};
  const tipo  = meta.tipo;                 // 'complementaria' (y futuro: otros)
  const id    = meta.id;                   // id campaña
  const mode  = meta.mode;                 // 'inline' | 'excel'

  const start = modal.find('#fechaDesde').val();
  const end   = modal.find('#fechaHasta').val();

  const base = (tipo === 'complementaria')
    ? 'informes/descargar_excel_IW.php'
    : 'informes/descargar_excel.php';     // por si reutilizas el modal

  const today = (() => {
    const d = new Date();
    const p = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())}`;
  })();

  let url = `${base}?id=${encodeURIComponent(id)}`;

  if (!start && !end) {
    // Excepción #1: histórico (sin fechas)
    // url ya armado solo con id
  } else if (start && !end) {
    // Excepción #2: desde start hasta hoy
    url += `&start_date=${encodeURIComponent(start)}&end_date=${encodeURIComponent(today)}`;
  } else if (!start && end) {
    alert('Si indicas "Hasta", debes indicar "Desde".');
    return;
  } else {
    // Ambos presentes: validar orden
    if (start > end) {
      alert('La fecha "Desde" no puede ser mayor que "Hasta".');
      return;
    }
    url += `&start_date=${encodeURIComponent(start)}&end_date=${encodeURIComponent(end)}`;
  }

  if (mode === 'inline') url += '&inline=1';

  modal.modal('hide');
  window.open(url, '_blank');
});




// Filtros de formulario
$('#empresa_filter').on('change', function(){ $('#filterForm').submit();
});
  $('#division_filter, #estado_filter')
    .on('change', function(){ $('#filterForm').submit();
});

$(document).on('click','.reference-img',function(){
  var src=$(this).attr('src'),
      camp=$(this).data('camp');
  $('#modalRefImgTag').attr('src',src);
  $('#modalRefImage').data('camp',camp).modal('show');
/*  var canEdit=<?=($perfil_nombre=="editor"&&$empresa_id===103&&$division_id===1?1: 0)?>; */
  $('#btnChangeRef').toggle(canEdit);
  $('#formChangeRef').hide();
});
$('#btnChangeRef').click(function(){
  $('#formChangeRef').show();
}); 

$('#formChangeRef').submit(function(e){
  e.preventDefault();
  var camp=$('#modalRefImage').data('camp'),
      fd=new FormData(this);
  fd.append('id',camp);
  $.ajax({
    url:'update_reference_image.php',
    method:'POST',
    data:fd,
    processData: false,
    contentType: false,
    success:function(res){
      var j=JSON.parse(res);
      if(j.ok){
        $('#refImg-' + camp).attr('src',j.url);
        $('#modalRefImgTag').attr('src',j.url);
        alert('Referencia actualizada');
      } else alert('Error: '+j.error);
      $('#formChangeRef').hide();
    },
    error:function(){alert('Error subida'); $('#formChangeRef').hide();
    }
  });
});


</script>


<!-- Modal: selección de descarga Excel -->
<div class="modal fade" id="modalDescargaExcel" tabindex="-1" aria-labelledby="modalDescargaExcelLabel" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="modalDescargaExcelLabel">Descarga de Excel</h6>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <div id="excelFotosImplementacionGroup" class="mb-3">
          <p class="mb-2 font-weight-bold" id="excelFotosImplementacionTitle">Fotos de implementación</p>
          <div class="custom-control custom-radio mb-2">
            <input type="radio" id="excelPhotosMaterialCon" name="excelPhotosMaterialOption" value="1" class="custom-control-input" checked>
            <label class="custom-control-label" for="excelPhotosMaterialCon" id="excelFotosImplementacionLabelCon">Con fotos de implementación</label>
          </div>
          <div class="custom-control custom-radio">
            <input type="radio" id="excelPhotosMaterialSin" name="excelPhotosMaterialOption" value="0" class="custom-control-input">
            <label class="custom-control-label" for="excelPhotosMaterialSin" id="excelFotosImplementacionLabelSin">Sin fotos de implementación</label>
          </div>
        </div>

        <div id="excelFotosEncuestaGroup">
          <p class="mb-2 font-weight-bold" id="excelFotosEncuestaTitle">Fotos de encuesta</p>
          <div class="custom-control custom-radio mb-2">
            <input type="radio" id="excelPhotosEncuestaCon" name="excelPhotosEncuestaOption" value="1" class="custom-control-input" checked>
            <label class="custom-control-label" for="excelPhotosEncuestaCon" id="excelFotosEncuestaLabelCon">Con fotos de encuesta</label>
          </div>
          <div class="custom-control custom-radio">
            <input type="radio" id="excelPhotosEncuestaSin" name="excelPhotosEncuestaOption" value="0" class="custom-control-input">
            <label class="custom-control-label" for="excelPhotosEncuestaSin" id="excelFotosEncuestaLabelSin">Sin fotos de encuesta</label>
          </div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-success btn-sm" id="btnDescargarExcelConfirm">
          <i class="fas fa-file-excel mr-1"></i> Descargar
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// Modal para descargar Excel con opciones de fotos (individual y masivo)
let campanaDescargaExcel = null;
let campanaModalidadExcel = '';
let modoDescargaExcel = 'single';
let campanasSeleccionadasExcel = [];

const normalizarModalidad = (modalidad = '') => (modalidad || '').toLowerCase();

const actualizarModalDescargaExcel = (modalidades) => {
  const modosEntrada = Array.isArray(modalidades) ? modalidades : [modalidades];
  const modos = modosEntrada.map(normalizarModalidad).filter(Boolean);

  const $grupoImpl = $('#excelFotosImplementacionGroup');
  const $grupoEncuesta = $('#excelFotosEncuestaGroup');

  const setTextosImpl = (textoBase) => {
    const capitalizado = textoBase.charAt(0).toUpperCase() + textoBase.slice(1);
    $('#excelFotosImplementacionTitle').text(capitalizado);
    $('#excelFotosImplementacionLabelCon').text(`Con ${textoBase}`);
    $('#excelFotosImplementacionLabelSin').text(`Sin ${textoBase}`);
  };

  const setTextosEncuesta = () => {
    $('#excelFotosEncuestaTitle').text('Fotos de encuesta');
    $('#excelFotosEncuestaLabelCon').text('Con fotos de encuesta');
    $('#excelFotosEncuestaLabelSin').text('Sin fotos de encuesta');
  };

  const hasImpl = modos.length === 0 || modos.some(m => ['solo_implementacion', 'implementacion_auditoria', 'retiro'].includes(m));
  const hasEncuesta = modos.some(m => ['solo_auditoria', 'implementacion_auditoria'].includes(m));
  const soloRetiro = modos.length > 0 && modos.every(m => m === 'retiro');

  $grupoImpl.toggle(hasImpl);
  $grupoEncuesta.toggle(hasEncuesta);

  setTextosImpl(soloRetiro ? 'fotos de retiro' : 'fotos de implementación');
  setTextosEncuesta();

  $('#excelPhotosMaterialCon').prop('checked', true);
  $('#excelPhotosEncuestaCon').prop('checked', true);
};

$(document).on('click', '.download-excel-trigger', function (e) {
  e.preventDefault();
  campanaDescargaExcel = $(this).data('id') || null;
  campanaModalidadExcel = $(this).data('modalidad') || '';
  modoDescargaExcel = 'single';
  campanasSeleccionadasExcel = campanaDescargaExcel ? [campanaDescargaExcel] : [];

  actualizarModalDescargaExcel([campanaModalidadExcel]);
  $('#modalDescargaExcel').modal('show');
});

const prepararDescargaMasiva = (checkboxSelector) => {
  const seleccionados = Array.from(document.querySelectorAll(checkboxSelector));
  if (seleccionados.length === 0) {
    alert('Por favor, selecciona al menos una campaña.');
    return;
  }

  campanaDescargaExcel = null;
  campanaModalidadExcel = '';
  modoDescargaExcel = 'bulk';
  campanasSeleccionadasExcel = seleccionados.map(cb => cb.value);
  const modalidades = seleccionados.map(cb => cb.dataset.modalidad || '');

  actualizarModalDescargaExcel(modalidades);
  $('#modalDescargaExcel').modal('show');
};

$('#btnDescargarExcelConfirm').on('click', function () {
  const params = new URLSearchParams();

  if (modoDescargaExcel === 'bulk') {
    if (campanasSeleccionadasExcel.length === 0) return;
    params.set('ids', campanasSeleccionadasExcel.join(','));
  } else {
    if (!campanaDescargaExcel) return;
    params.set('id', campanaDescargaExcel);
  }

  if ($('#excelFotosImplementacionGroup').is(':visible')) {
    const fotosMaterial = $("input[name='excelPhotosMaterialOption']:checked").val() || '1';
    params.set('fotos', fotosMaterial);
  } else if (modoDescargaExcel === 'bulk') {
    params.set('fotos', '0');
  }

  if ($('#excelFotosEncuestaGroup').is(':visible')) {
    const fotosEncuesta = $("input[name='excelPhotosEncuestaOption']:checked").val() || '1';
    params.set('fotos_encuesta', fotosEncuesta);
  } else if (modoDescargaExcel === 'bulk') {
    params.set('fotos_encuesta', '0');
  }

  const url = (modoDescargaExcel === 'bulk')
    ? `informes/descarga_excel_masivo.php?${params.toString()}`
    : `/visibility2/portal/informes/descargar_excel.php?${params.toString()}`;

  $('#modalDescargaExcel').modal('hide');
  window.open(url, '_blank');
});
</script>

<script>
  const btnMasivo = document.getElementById('bulkDownloadBtn');
  if (btnMasivo) {
    btnMasivo.addEventListener('click', function() {
      prepararDescargaMasiva("input[id^='chk-prog']:checked");
    });
  }

  const btnMasivoIPT = document.getElementById('bulkDownloadBtnIPT');
  if (btnMasivoIPT) {
    btnMasivoIPT.addEventListener('click', function() {
      prepararDescargaMasiva("input[id^='chk-ipt']:checked");
    });
  }

</script>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const configs = [
    { inputId: 'searchInput',    cardSel: '.campaign-item',     titleSel: '.campaign-name'     },
    { inputId: 'searchInput-ipt', cardSel: '.campaign-item-ipt', titleSel: '.campaign-name-ipt' }
  ];

  configs.forEach(({inputId, cardSel, titleSel}) => {
    const input = document.getElementById(inputId);
    if (!input) return;

    input.addEventListener('input', () => {
      const filter = input.value.trim().toUpperCase();
      document.querySelectorAll(cardSel).forEach(card => {
        // 1) Filtrado normal
        const titleEl = card.querySelector(titleSel);
        const matches = titleEl && titleEl.textContent.trim().toUpperCase().includes(filter);
        card.style.display = matches ? '' : 'none';

        // 2) Checkbox dentro de la tarjeta
        const chk = card.querySelector("input[type='checkbox']");
        if (chk) {
          if (!matches) {
            chk.checked = false;   // desmarcarlo
            chk.disabled = true;   // y deshabilitarlo
          } else {
            chk.disabled = false;  // volver a habilitar
          }
        }
      });
    });
  });
});

</script>

</body>
</html>