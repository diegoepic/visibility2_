<?php
/** @var array $viewData */
$campanaNombre = $viewData['campanaNombre'];
$usuarios = $viewData['usuarios'];
$estadosDisponibles = $viewData['estadosDisponibles'];
$locales = $viewData['locales'];
$pagination = $viewData['pagination'];
$filters = $viewData['filters'];
$csrf = $viewData['csrf'];
$mapKey = $viewData['mapKey'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Mapa — <?= htmlspecialchars($campanaNombre, ENT_QUOTES) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.6.1/css/bootstrap.min.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css"/>
  <style>
    body, html { height:100%; margin:0; }
    #map, #mapGestiones { height:100%; width:100%; position:absolute; top:0; left:0; display:none; }
    #map { display:block; }
    #overlay { position:absolute; top:0; left:0; width:100%; height:100%; background:rgba(255,255,255,0.85); z-index:2000; display:flex; align-items:center; justify-content:center; font-size:1.2rem; color:#007bff; }
    .sidebar { position:absolute; top:0; left:0; width:360px; height:100%; background:#fff; overflow-y:auto; box-shadow:2px 0 8px rgba(0,0,0,0.15); transition:width .3s; z-index:1000; }
    .sidebar.collapsed { width:0; }
    .sidebar .header { background:#007bff; color:#fff; padding:12px; display:flex; align-items:center; justify-content:space-between; }
    .sidebar .filters { padding:10px; border-bottom:1px solid #e3e3e3; }
    .sidebar table { width:100%; }
    .sidebar tr:hover { background:#f1f1f1; cursor:pointer; }
    .tabs-top { position:absolute; top:10px; left:50%; transform:translateX(-50%); z-index:1500; }
    #btnToggleSidebar { position:absolute; top:10px; left:380px; z-index:1500; }
    .camp-name { font-weight:600; font-size:.95rem; }
    .table-active { background:#fff3cd !important; }
    .form-row > .form-group { margin-right:6px; }
  </style>
</head>
<body>
  <div id="overlay"><i class="fas fa-spinner fa-spin mr-2"></i> Cargando mapa…</div>
  <div class="sidebar" id="sbar">
    <div class="header">
      <div>
        <div class="camp-name"><?= htmlspecialchars($campanaNombre, ENT_QUOTES) ?></div>
        <small>Campaña #<?= (int)$filters['idCampana'] ?></small>
      </div>
      <button class="btn btn-sm btn-light" id="btnToggleSidebar"><i class="fas fa-chevron-left"></i></button>
    </div>
    <div class="filters">
      <form method="get" class="mb-2">
        <input type="hidden" name="id" value="<?= (int)$filters['idCampana'] ?>">
        <div class="form-row align-items-center mb-2">
          <div class="form-group mb-1">
            <input type="text" name="filter_codigo" class="form-control form-control-sm" placeholder="Código" value="<?= htmlspecialchars($filters['filterCodigo'], ENT_QUOTES) ?>">
          </div>
          <div class="form-group mb-1">
            <select name="filter_usuario_id" class="form-control form-control-sm">
              <option value="">Todos los usuarios</option>
              <?php foreach($usuarios as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= $filters['filterUserId']===(int)$u['id']?'selected':'' ?>>
                  <?= htmlspecialchars($u['usuario'], ENT_QUOTES) ?>
                  <?= $u['nombre'] ? ' — '.htmlspecialchars($u['nombre'], ENT_QUOTES) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group mb-1">
            <select name="filter_estado" class="form-control form-control-sm">
              <option value="">Todos los estados</option>
              <?php foreach ($estadosDisponibles as $est): $sel = ($filters['filterEstado'] === $est) ? 'selected' : ''; ?>
                <option value="<?= htmlspecialchars($est,ENT_QUOTES) ?>" <?= $sel ?>><?= htmlspecialchars($est,ENT_QUOTES) ?></option>
              <?php endforeach; ?>
              <?php $selSD = ($filters['filterEstado'] === 'sin_datos') ? 'selected' : ''; ?>
              <option value="sin_datos" <?= $selSD ?>>Sin gestiones</option>
            </select>
          </div>
        </div>
        <div class="form-row align-items-center">
          <div class="form-group mb-1">
            <label class="small mb-0">Desde</label>
            <input type="date" name="fdesde" class="form-control form-control-sm" value="<?= htmlspecialchars($filters['filterDesde'] ? substr($filters['filterDesde'],0,10) : '', ENT_QUOTES) ?>">
          </div>
          <div class="form-group mb-1">
            <label class="small mb-0">Hasta</label>
            <input type="date" name="fhasta" class="form-control form-control-sm" value="<?= htmlspecialchars($filters['filterHasta'] ? substr($filters['filterHasta'],0,10) : '', ENT_QUOTES) ?>">
          </div>
          <div class="form-group mb-1">
            <label class="small mb-0">Por página</label>
            <select name="per_page" class="form-control form-control-sm">
              <?php foreach([25,50,100,150] as $opt): ?>
                <option value="<?= $opt ?>" <?= $filters['perPage']===$opt?'selected':'' ?>><?= $opt ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group mb-1 align-self-end">
            <button class="btn btn-primary btn-sm" type="submit">Filtrar</button>
          </div>
        </div>
      </form>
    </div>
    <div class="p-2">
      <table class="table table-sm table-hover" id="tblLocales">
        <thead>
          <tr><th>Cod.</th><th>Nombre</th><th>Estado</th><th>Última</th></tr>
        </thead>
        <tbody>
        <?php foreach ($locales as $loc): ?>
          <tr data-id="<?= (int)$loc['idLocal'] ?>">
            <td><?= htmlspecialchars($loc['codigoLocal'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($loc['nombreLocal'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($loc['estadoLabel'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($loc['fechaVisita'] ?? '—', ENT_QUOTES) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="small">Página <?= (int)$pagination['currentPage'] ?> / <?= (int)$pagination['totalPages'] ?> (<?= (int)$pagination['totalRows'] ?> locales)</div>
        <div>
          <?php if ($pagination['currentPage'] > 1): ?>
            <a class="btn btn-outline-secondary btn-sm" href="?<?= http_build_query(array_merge($_GET,['page'=>$pagination['currentPage']-1])) ?>">&laquo;</a>
          <?php endif; ?>
          <?php if ($pagination['currentPage'] < $pagination['totalPages']): ?>
            <a class="btn btn-outline-secondary btn-sm" href="?<?= http_build_query(array_merge($_GET,['page'=>$pagination['currentPage']+1])) ?>">&raquo;</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="tabs-top">
    <div class="btn-group">
      <button id="tabLocales" class="btn btn-sm btn-primary active">Locales</button>
      <button id="tabGestiones" class="btn btn-sm btn-outline-primary">Gestiones</button>
    </div>
  </div>

  <div id="map"></div>
  <div id="mapGestiones"></div>

  <div class="modal fade" id="detalleLocalModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content" id="detalleLocalContent"></div>
    </div>
  </div>

  <script>
    window.MAPA_DATA = <?= json_encode($locales, JSON_UNESCAPED_UNICODE) ?>;
    window.MAPA_CONFIG = {
      campanaId: <?= (int)$filters['idCampana'] ?>,
      csrf: '<?= htmlspecialchars($csrf, ENT_QUOTES) ?>'
    };
    window.MAP_KEY = '<?= htmlspecialchars($mapKey, ENT_QUOTES) ?>';
  </script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.6.1/js/bootstrap.bundle.min.js"></script>
  <script src="https://unpkg.com/@googlemaps/markerclusterer/dist/index.min.js"></script>
  <script src="js/gestiones.js"></script>
  <script src="js/detalle_local.js"></script>
  <script src="js/mapa.js"></script>
  <script async defer src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode($mapKey) ?>&callback=initMap"></script>
</body>
</html>