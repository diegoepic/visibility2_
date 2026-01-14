<?php
/** @var array $viewData */
$campanaNombre = $viewData['campanaNombre'];
$campanaInfo = $viewData['campanaInfo'] ?? [];
$isComplementaria = (bool)($viewData['isComplementaria'] ?? false);
$iwRequiereLocal = (bool)($viewData['iwRequiereLocal'] ?? false);
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
          <?php if (!$isComplementaria || $iwRequiereLocal): ?>
            <div class="form-group mb-1">
              <input type="text" name="filter_codigo" class="form-control form-control-sm" placeholder="Código local" value="<?= htmlspecialchars($filters['filterCodigo'], ENT_QUOTES) ?>">
            </div>
          <?php endif; ?>
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
          <?php if (!$isComplementaria): ?>
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
          <?php endif; ?>
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
      <?php if ($filters['useDefaultRange'] ?? false): ?>
        <div class="alert alert-info alert-sm py-2 px-2 mx-2 mb-2 d-flex justify-content-between align-items-center" style="font-size:0.85rem;">
          <span><i class="fas fa-info-circle mr-1"></i> Mostrando últimos 30 días</span>
          <a href="?id=<?= (int)$filters['idCampana'] ?>&fdesde=&fhasta=&page=1" class="btn btn-sm btn-outline-primary" style="font-size:0.75rem;padding:2px 8px;">Ver todos</a>
        </div>
      <?php endif; ?>
      <?php if ($isComplementaria && $iwRequiereLocal && $filters['filterUserId'] > 0 && !empty($viewData['filteredUserName'])): ?>
        <div class="alert alert-warning alert-sm py-2 px-2 mx-2 mb-2" style="font-size:0.85rem;">
          <i class="fas fa-filter mr-1"></i> <strong>Filtrando por <?= htmlspecialchars($viewData['filteredUserName'], ENT_QUOTES) ?>:</strong><br>
          <small>Mostrando locales con al menos una visita de este usuario. En el detalle verás todas las visitas para contexto completo.</small>
        </div>
      <?php endif; ?>
    </div>
    <div class="p-2">
      <table class="table table-sm table-hover" id="tblLocales">
        <thead>
          <?php if ($isComplementaria && !$iwRequiereLocal): ?>
            <tr><th>Visita</th><th>Usuario</th><th>Fecha</th></tr>
          <?php elseif ($isComplementaria && $iwRequiereLocal): ?>
            <tr><th>Cod.</th><th>Nombre</th><th>Usuario</th><th>Última visita</th></tr>
          <?php else: ?>
            <tr><th>Cod.</th><th>Nombre</th><th>Estado</th><th>Última visita</th></tr>
          <?php endif; ?>
        </thead>
        <tbody>
        <?php foreach ($locales as $loc): ?>
          <tr data-id="<?= (int)$loc['idLocal'] ?>">
            <?php if ($isComplementaria && !$iwRequiereLocal): ?>
              <td><?= htmlspecialchars('Visita #'.(int)($loc['visitaId'] ?? $loc['idLocal']), ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars($loc['usuarioGestion'] ?? '—', ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars($loc['fechaVisita'] ?? '—', ENT_QUOTES) ?></td>
            <?php elseif ($isComplementaria && $iwRequiereLocal): ?>
              <td><?= htmlspecialchars($loc['codigoLocal'] ?? '', ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars($loc['nombreLocal'] ?? '', ENT_QUOTES) ?></td>
              <td>
                <?= htmlspecialchars($loc['usuarioGestion'] ?? '—', ENT_QUOTES) ?>
                <?php if ($filters['filterUserId'] > 0 && isset($loc['visitasUsuarioFiltrado']) && isset($loc['visitasCount']) && $loc['visitasCount'] > 0): ?>
                  <span class="badge badge-primary ml-1" style="font-size:0.7rem;" title="<?= (int)$loc['visitasUsuarioFiltrado'] ?> de <?= (int)$loc['visitasCount'] ?> visitas son de este usuario">
                    <?= (int)$loc['visitasUsuarioFiltrado'] ?>/<?= (int)$loc['visitasCount'] ?>
                  </span>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($loc['fechaVisita'] ?? '—', ENT_QUOTES) ?></td>
            <?php else: ?>
              <td><?= htmlspecialchars($loc['codigoLocal'] ?? '', ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars($loc['nombreLocal'] ?? '', ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars($loc['estadoLabel'] ?? '', ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars($loc['fechaVisita'] ?? '—', ENT_QUOTES) ?></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="d-flex justify-content-between align-items-center mb-3">
        <?php
          // FIX: Texto dinámico según tipo de campaña
          $itemLabel = ($isComplementaria && !$iwRequiereLocal) ? 'visitas' : 'locales';
        ?>
        <div class="small">Página <?= (int)$pagination['currentPage'] ?> / <?= (int)$pagination['totalPages'] ?> (<?= (int)$pagination['totalRows'] ?> <?= $itemLabel ?>)</div>
        <div>
          <?php if ($pagination['currentPage'] > 1): ?>
            <a class="btn btn-outline-secondary btn-sm" href="?<?= http_build_query([...$_GET, 'page' => $pagination['currentPage']-1]) ?>" title="Página anterior">&laquo;</a>
          <?php endif; ?>
          <?php if ($pagination['currentPage'] < $pagination['totalPages']): ?>
            <a class="btn btn-outline-secondary btn-sm" href="?<?= http_build_query([...$_GET, 'page' => $pagination['currentPage']+1]) ?>" title="Página siguiente">&raquo;</a>
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
      csrf: '<?= htmlspecialchars($csrf, ENT_QUOTES) ?>',
      isComplementaria: <?= $isComplementaria ? 'true' : 'false' ?>,
      iwRequiereLocal: <?= $iwRequiereLocal ? 'true' : 'false' ?>,
      filteredUserId: <?= (int)($filters['filterUserId'] ?? 0) ?>
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
