<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Panel de Encuesta</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<style>
  :root{ --thumb-size: 120px; }
  body.thumb-small{ --thumb-size: 80px; }
  body.thumb-medium{ --thumb-size: 120px; }
  body.thumb-large{ --thumb-size: 160px; }

  body{background:#f7f8fb}
  .card{border:0; box-shadow:0 8px 24px rgba(0,0,0,.06); border-radius:14px;}
  .table thead th{white-space:nowrap;}
  .sticky-toolbar{position:sticky; top:0; z-index:100; background:#fff; border-bottom:1px solid #eee; padding:.5rem 1rem; border-top-left-radius:14px; border-top-right-radius:14px;}
  #resultsTable td{vertical-align:middle;}
  .pagination{margin-bottom:0;}

  .thumb-wrap{display:flex; flex-wrap:wrap; gap:6px; position:relative; min-height: calc(var(--thumb-size) + 6px);}
  .thumb{height:var(--thumb-size); width:auto; cursor:pointer; border-radius:8px; margin:0; box-shadow:0 2px 6px rgba(0,0,0,.15);}
  .thumb-count{position:absolute; top:6px; left:6px; background:#111; color:#fff; border-radius:50%; width:26px; height:26px; font-size:12px; display:flex; align-items:center; justify-content:center; box-shadow:0 1px 4px rgba(0,0,0,.3);}

  .qf-card{border:1px solid #e5e7eb; border-radius:10px; padding:.5rem .75rem; margin-bottom:.5rem; background:#fff;}
  .qf-title{font-weight:600;}
  .qf-badges .badge{margin-right:.25rem; margin-bottom:.25rem;}
  .select2-container--default .select2-selection--multiple .select2-selection__choice{max-width:98%; overflow:hidden; text-overflow:ellipsis;}
  .filter-chip{display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; border:1px solid #e5e7eb; background:#f1f5f9; color:#334155; margin:0 6px 6px 0; font-size:.75rem;}

  /* Loader / estado de carga */
  #panel-encuesta-table-wrapper.is-loading{
    opacity:.5;
    pointer-events:none;
  }
  #panel-encuesta-loading{
    font-size:.9rem;
  }

  /* límite de alto para qfilters para que no empuje tanto la tabla */
  #qfilters {
    max-height: 240px;
    overflow-y: auto;
  }
</style>
</head>
<body class="p-3 thumb-medium">
<div class="container-fluid">
  <div class="d-flex align-items-center mb-3">
    <h3 class="mb-0">Panel de Encuesta</h3>
    <div class="ml-auto">
      <a class="btn btn-sm btn-outline-secondary" href="/visibility2/portal/home.php">Volver</a>
    </div>
  </div>

  <div class="card">
    <!-- ahora es un <form> real para poder usar submit/Enter -->
    <form class="sticky-toolbar d-flex align-items-end flex-wrap" id="panel-encuesta-filtros">
      <input type="hidden" name="csrf_token" id="csrf_token" value="<?=htmlspecialchars($csrf_token)?>">
      <?php if ($is_mc): ?>
        <div class="mr-3 mb-2">
          <label class="mb-1"><small>División</small></label>
          <select id="f_division" name="division" class="form-control form-control-sm" style="min-width:200px">
            <option value="0">-- Todas --</option>
            <?php foreach($divisiones as $d): ?>
              <option value="<?=$d['id']?>" <?=$sel_div==$d['id']?'selected':''?>><?=htmlspecialchars($d['nombre'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mr-3 mb-2">
          <label class="mb-1"><small>Subdivisión</small></label>
          <select id="f_subdivision" name="subdivision" class="form-control form-control-sm" style="min-width:200px">
            <option value="0">-- Todas --</option>
            <?php foreach($subdivisiones as $s): ?>
              <option value="<?=$s['id']?>" <?=$sel_sub==$s['id']?'selected':''?>><?=htmlspecialchars($s['nombre'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php else: ?>
        <input type="hidden" id="f_division" name="division" value="<?=$user_div?>">
        <div class="mr-3 mb-2">
          <label class="mb-1"><small>Subdivisión</small></label>
          <select id="f_subdivision" name="subdivision" class="form-control form-control-sm" style="min-width:200px">
            <option value="0">-- Todas --</option>
            <?php foreach($subdivisiones as $s): ?>
              <option value="<?=$s['id']?>"><?=htmlspecialchars($s['nombre'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>

      <!-- Tipo -->
      <div class="mr-3 mb-2">
        <label class="mb-1"><small>Tipo</small></label>
        <select id="f_tipo" name="tipo" class="form-control form-control-sm" style="min-width:200px">
          <option value="0"  <?=$sel_tipo===0?'selected':''?>>Programadas + Ruta IPT</option>
          <option value="1"  <?=$sel_tipo===1?'selected':''?>>Programadas</option>
          <option value="3"  <?=$sel_tipo===3?'selected':''?>>Ruta IPT</option>
        </select>
      </div>

      <!-- Campaña (PRIMERO) -->
      <div class="mr-3 mb-2">
        <label class="mb-1"><small>Campaña</small></label>
        <select id="f_form" name="form_id" class="form-control form-control-sm" style="min-width:260px">
          <option value="0">-- Todas --</option>
          <?php foreach($formularios as $f): ?>
            <option value="<?=$f['id']?>"><?=htmlspecialchars($f['nombre'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Preguntas (dependiente de Campaña) -->
      <div class="mr-3 mb-2" style="min-width:320px; max-width:420px">
        <label class="mb-1"><small>Preguntas</small></label>
        <select id="f_preguntas" class="form-control form-control-sm" multiple></select>

        <div class="d-flex align-items-center mt-2">
          <div class="dropdown mr-2">
            <button class="btn btn-light btn-sm dropdown-toggle" type="button" id="btnLoadPreset" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
              Cargar preset
            </button>
            <div class="dropdown-menu" aria-labelledby="btnLoadPreset" id="presetsMenu">
              <span class="dropdown-item-text text-muted">Sin presets aún</span>
            </div>
          </div>

          <button class="btn btn-outline-primary btn-sm mr-2" type="button" id="btnSavePreset"><i class="fa fa-save"></i> Guardar preset</button>
          <button class="btn btn-outline-danger btn-sm" type="button" id="btnClearPreset"><i class="fa fa-trash"></i> Limpiar filtros</button>
        </div>
      </div>

      <!-- Contenedor filtros por pregunta + stats -->
      <div class="mr-3 mb-2 w-100">
        <div id="qfilters" class="border rounded p-2 bg-white" style="min-height:44px">
          <small class="text-muted">Añade una o más preguntas y configura el filtro de su respuesta aquí…</small>
        </div>
        <small class="text-muted d-block mt-1">
          <strong>Importante:</strong> por defecto el panel solo muestra visitas que cumplen 
          <strong>todas</strong> las condiciones de las preguntas seleccionadas.
          Si en una visita hay al menos una pregunta del filtro que no se respondió en la visita, esa gestión no aparecerá en los resultados.
          Puedes activar <strong>Incluir parciales</strong> para ver visitas que cumplan al menos una condición.
        </small>
        <div class="form-check mt-1">
          <input class="form-check-input" type="checkbox" id="f_qfilters_match" value="any">
          <label class="form-check-label small text-muted" for="f_qfilters_match">
            Incluir parciales (mostrar visitas que cumplan al menos una condición).
          </label>
        </div>
      </div>

      <div class="mr-2 mb-2">
        <label class="mb-1"><small>Desde</small></label>
        <input type="date" id="f_desde" name="desde" class="form-control form-control-sm">
      </div>
      <div class="mr-3 mb-2">
        <label class="mb-1"><small>Hasta</small></label>
        <input type="date" id="f_hasta" name="hasta" class="form-control form-control-sm">
      </div>

      <div class="mr-3 mb-2">
        <label class="mb-1"><small>Distrito</small></label>
        <select id="f_distrito" name="distrito" class="form-control form-control-sm" style="min-width:180px">
          <option value="0">-- Todos --</option>
          <?php foreach($distritos as $d): ?>
            <option value="<?=$d['id']?>"><?=htmlspecialchars($d['nombre_distrito'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mr-3 mb-2">
        <label class="mb-1"><small>Jefe de venta</small></label>
        <select id="f_jv" name="jv" class="form-control form-control-sm" style="min-width:180px">
          <option value="0">-- Todos --</option>
          <?php foreach($jefes as $j): ?>
            <option value="<?=$j['id']?>"><?=htmlspecialchars($j['nombre'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mr-3 mb-2">
        <label class="mb-1"><small>Usuario</small></label>
        <select id="f_usuario" name="usuario" class="form-control form-control-sm" style="min-width:200px">
          <option value="0">-- Todos --</option>
          <?php foreach($usuarios as $u): ?>
            <option value="<?=$u['id']?>"><?=htmlspecialchars($u['usuario'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mr-3 mb-2">
        <label class="mb-1"><small>Cód. Local</small></label>
        <input id="f_codigo" name="codigo" class="form-control form-control-sm" placeholder="Ej: L1234">
      </div>

      <!-- Filtro geográfico (colapsable) -->
      <div class="w-100 mb-1">
        <a href="#" class="small text-muted" data-toggle="collapse" data-target="#geoFilterPanel">
          <i class="fa fa-map-marker-alt"></i> Filtro geográfico <small>(radio / bbox)</small>
        </a>
      </div>
      <div id="geoFilterPanel" class="collapse w-100 border rounded p-2 mb-2 bg-light">
        <div class="d-flex flex-wrap align-items-end">
          <div class="mr-2 mb-1">
            <label class="mb-0"><small>Lat. centro</small></label>
            <input type="number" id="f_geo_lat" name="geo_lat" step="any" class="form-control form-control-sm" style="width:110px" placeholder="-33.45">
          </div>
          <div class="mr-2 mb-1">
            <label class="mb-0"><small>Lng. centro</small></label>
            <input type="number" id="f_geo_lng" name="geo_lng" step="any" class="form-control form-control-sm" style="width:110px" placeholder="-70.65">
          </div>
          <div class="mr-3 mb-1">
            <label class="mb-0"><small>Radio</small></label>
            <select id="f_radius_km" name="radius_km" class="form-control form-control-sm">
              <option value="">Sin radio</option>
              <option value="1">1 km</option>
              <option value="5">5 km</option>
              <option value="10">10 km</option>
              <option value="25">25 km</option>
              <option value="50">50 km</option>
            </select>
          </div>
          <div class="mr-1 mb-1">
            <button type="button" id="btnGeolocate" class="btn btn-sm btn-outline-secondary" title="Usar mi ubicación actual">
              <i class="fa fa-location-arrow"></i>
            </button>
          </div>
          <div class="mb-1">
            <button type="button" id="btnGeoClear" class="btn btn-sm btn-outline-secondary" title="Limpiar filtro geográfico">
              <i class="fa fa-times"></i> Limpiar geo
            </button>
          </div>
        </div>
        <small class="text-muted d-block mt-1">El filtro de radio usa la fórmula Haversine sobre las coordenadas del local.</small>
      </div>

      <!-- Botones + leyenda -->
      <div class="ml-auto mb-2 text-right">
        <button id="btnBuscar" type="submit" class="btn btn-primary btn-sm">
          <i class="fa fa-search"></i> Buscar
        </button>
        <button id="btnResetFilters" type="button" class="btn btn-outline-secondary btn-sm">
          <i class="fa fa-eraser"></i> Limpiar todo
        </button>

        <div class="btn-group btn-group-sm mt-1">
          <button id="btnCSV" type="button" class="btn btn-outline-secondary">
            <i class="fa fa-file-csv"></i> CSV
          </button>
          <button id="btnCSVRaw" type="button" class="btn btn-outline-secondary" title="1 fila por respuesta – ideal para Excel pivot o Python/R">
            <i class="fa fa-table"></i> CSV Raw
          </button>
          <button id="btnFotosHTML" type="button" class="btn btn-outline-secondary">
            <i class="fa fa-image"></i> Fotos HTML
          </button>
          <button id="btnPDF" type="button" class="btn btn-outline-secondary">
            <i class="fa fa-file-pdf"></i> Fotos PDF
          </button>
          <button id="btnZIPFotos" type="button" class="btn btn-outline-secondary d-none" title="Descarga ZIP con las fotos originales (máx. 500)">
            <i class="fa fa-file-archive"></i> ZIP Fotos
          </button>
        </div>

        <small class="text-muted d-block mt-1">
          Exportación de fotos (HTML / PDF / ZIP) incluye solo respuestas de tipo foto.
        </small>
      </div>
    </form>

    <div class="card-body">
      <div id="panel-encuesta-loading" class="text-muted d-none mb-2">
        <i class="fa fa-circle-notch fa-spin"></i> Cargando datos…
      </div>

      <div class="d-flex align-items-center mb-2 flex-wrap">
        <div class="d-flex align-items-center mr-3 mb-2">
          <div class="mr-2">Mostrar</div>
          <select id="f_limit" class="form-control form-control-sm" style="width:auto">
            <?php foreach([25,50,100,150,200] as $n): ?>
              <option value="<?=$n?>"><?=$n?></option>
            <?php endforeach; ?>
          </select>
          <div class="ml-2">registros</div>
        </div>

        <div class="d-flex align-items-center mr-3 mb-2">
          <div class="mr-2">Tamaño fotos</div>
          <select id="f_thumbsize" class="form-control form-control-sm" style="width:auto">
            <option value="small">Pequeño</option>
            <option value="medium" selected>Mediano</option>
            <option value="large">Grande</option>
          </select>
        </div>

        <div class="ml-auto text-muted mb-2" id="infoTotal"></div>
      </div>

      <div id="activeFilters" class="mb-2 small text-muted"></div>

      <div id="panel-encuesta-table-wrapper">
        <div class="table-responsive">
          <table class="table table-sm table-bordered table-hover" id="resultsTable">
            <thead class="thead-light">
              <tr>
                <th>Fecha (fin visita)</th>
                <th>Campaña</th>
                <th>Pregunta</th>
                <th>Tipo</th>
                <th>Respuesta</th>
                <th>Valor</th>
                <th>Cód. Local</th>
                <th>Local</th>
                <th>Dirección</th>
                <th>Cadena</th>
                <th>Jefe Venta</th>
                <th>Usuario</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td colspan="12" class="text-center text-muted">
                  Ajusta los filtros y presiona <strong>Buscar</strong> para cargar datos.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <nav>
          <ul class="pagination justify-content-center" id="pager"></ul>
        </nav>
      </div>
    </div>
  </div>
</div>

<!-- Modal fotos (lightbox) -->
<div class="modal fade" id="photoModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl" role="document">
    <div class="modal-content bg-dark position-relative">
      <button type="button" class="btn btn-dark position-absolute" id="lbPrev" style="left:8px; top:50%; transform:translateY(-50%); font-size:24px; z-index:2;">‹</button>
      <button type="button" class="btn btn-dark position-absolute" id="lbNext" style="right:8px; top:50%; transform:translateY(-50%); font-size:24px; z-index:2;">›</button>
      <div class="modal-body text-center p-0 position-relative">
        <img id="photoModalImg" src="" alt="" style="max-width:100%; max-height:80vh;">
        <div id="photoModalCaption" class="position-absolute text-white-50 small" style="right:10px; bottom:8px;"></div>
        <a id="photoModalOpen" class="btn btn-sm btn-light position-absolute" style="left:10px; bottom:8px;" target="_blank" rel="noopener">
          Abrir en nueva pestaña
        </a>
      </div>
    </div>
  </div>
</div>

<!-- Modal de errores (reutilizabl) -->
<div class="modal fade" id="errorModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title">Ups…</h6>
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body"><div id="errorModalMsg" class="small text-muted"></div></div>
    </div>
  </div>
</div>

<!-- JS: jQuery + Bootstrap + Select2 -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
window.PEConfig = <?= json_encode([
    'isMC'            => (bool)$is_mc,
    'USER_ID'         => (int)$user_id,
    'USER_DIV'        => (int)$user_div,
    'FACTORY_PRESETS' => $factory_presets ?? [],
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php
// Ruta absoluta al directorio de este módulo (evita problemas con base URLs de portales)
$_pe_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/');
$_pe_v   = date('Ymd');
?>
<script src="<?= $_pe_dir ?>/panel_encuesta_core.js?v=<?= $_pe_v ?>"></script>
<script src="<?= $_pe_dir ?>/panel_encuesta_filters.js?v=<?= $_pe_v ?>"></script>
<script src="<?= $_pe_dir ?>/panel_encuesta_presets.js?v=<?= $_pe_v ?>"></script>
<script src="<?= $_pe_dir ?>/panel_encuesta_export.js?v=<?= $_pe_v ?>"></script>
<script src="<?= $_pe_dir ?>/panel_encuesta_chart.js?v=<?= $_pe_v ?>"></script>
<!-- Modal: Detalle de Local -->
<div class="modal fade" id="localDetalleModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="localDetalleModalTitle">Detalle del local</h6>
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body" id="localDetalleModalBody">
        <div class="text-center text-muted py-4"><i class="fa fa-spinner fa-spin"></i> Cargando…</div>
      </div>
    </div>
  </div>
</div>
<script src="<?= $_pe_dir ?>/panel_encuesta_detalle.js?v=<?= $_pe_v ?>"></script>
</body>
</html>