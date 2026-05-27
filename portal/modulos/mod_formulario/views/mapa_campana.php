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

  <div id="mapaToast" style="position:fixed;bottom:20px;right:20px;z-index:9999;min-width:280px;display:none;" role="alert" aria-live="assertive">
    <div class="alert alert-info alert-dismissible mb-0 shadow" id="mapaToastBody">
      <button type="button" class="close" onclick="document.getElementById('mapaToast').style.display='none';" aria-label="Cerrar">&times;</button>
      <span id="mapaToastMsg"></span>
    </div>
  </div>

  <script>
    window.MAPA_DATA = <?= json_encode($locales, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.MAPA_CONFIG = {
      campanaId: <?= (int)$filters['idCampana'] ?>,
      csrf: <?= json_encode($csrf) ?>,
      isComplementaria: <?= $isComplementaria ? 'true' : 'false' ?>,
      iwRequiereLocal: <?= $iwRequiereLocal ? 'true' : 'false' ?>,
      filteredUserId: <?= (int)($filters['filterUserId'] ?? 0) ?>
    };
    window.MAP_KEY = <?= json_encode($mapKey) ?>;
    window.showMapaToast = function(msg, type) {
      var el   = document.getElementById('mapaToast');
      var body = document.getElementById('mapaToastBody');
      var msgEl = document.getElementById('mapaToastMsg');
      body.className = 'alert alert-' + (type || 'info') + ' alert-dismissible mb-0 shadow';
      msgEl.textContent = msg;
      el.style.display = 'block';
      setTimeout(function(){ el.style.display = 'none'; }, 4500);
    };
  </script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.6.1/js/bootstrap.bundle.min.js"></script>
  <script src="https://unpkg.com/@googlemaps/markerclusterer/dist/index.min.js"></script>
  <script src="js/gestiones.js"></script>
  <script src="js/detalle_local.js"></script>
  <script src="js/mapa.js"></script>
<script>
(function () {
  let fallbackMarkers = [];
  let fallbackInfoWindow = null;
  let capturedMainMap = null;

  function vzParseNumber(value) {
    if (value === null || value === undefined || value === '') return null;

    const n = parseFloat(
      String(value)
        .replace(',', '.')
        .replace(/[^\d.-]/g, '')
    );

    return Number.isFinite(n) ? n : null;
  }

  function vzGetLatLng(local) {
    const lat = vzParseNumber(
      local.lat ??
      local.latitud ??
      local.latitude ??
      local.localLat ??
      local.latLocal ??
      local.lat_local ??
      local.latGestion ??
      local.lat_gestion
    );

    const lng = vzParseNumber(
      local.lng ??
      local.lon ??
      local.longitud ??
      local.longitude ??
      local.localLng ??
      local.lngLocal ??
      local.lng_local ??
      local.lngGestion ??
      local.lng_gestion
    );

    if (lat === null || lng === null) return null;
    if (lat === 0 || lng === 0) return null;

    return { lat: lat, lng: lng };
  }

  function vzEscape(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function vzGetEstado(local) {
    return String(
      local.estadoLabel ??
      local.estadoGestion ??
      local.estado ??
      local.estado_gestion ??
      ''
    );
  }

  function vzGetColor(local) {
    const estado = vzGetEstado(local).toLowerCase();

    if (
      estado.includes('implement') ||
      estado.includes('auditor') ||
      estado.includes('encuesta') ||
      estado.includes('entregado')
    ) {
      return '#22a85a';
    }

    if (
      estado.includes('cancel') ||
      estado.includes('cerrado') ||
      estado.includes('no existe')
    ) {
      return '#ef4444';
    }

    if (
      estado.includes('pendiente') ||
      estado.includes('proceso') ||
      estado.includes('sin dato') ||
      estado.includes('sin_dato') ||
      estado.trim() === ''
    ) {
      return '#4d7eff';
    }

    return '#4d7eff';
  }

  function vzPinIcon(color) {
    const svg = `
      <svg width="42" height="52" viewBox="0 0 42 52" xmlns="http://www.w3.org/2000/svg">
        <defs>
          <filter id="shadow" x="-40%" y="-40%" width="180%" height="180%">
            <feDropShadow dx="0" dy="6" stdDeviation="5" flood-color="#0f2445" flood-opacity="0.32"/>
          </filter>
        </defs>
        <path filter="url(#shadow)" d="M21 2C11.1 2 3 10.1 3 20c0 13.6 18 30 18 30s18-16.4 18-30C39 10.1 30.9 2 21 2Z" fill="${color}"/>
        <circle cx="21" cy="20" r="8" fill="white" fill-opacity="0.96"/>
        <circle cx="21" cy="20" r="4" fill="${color}"/>
      </svg>
    `;

    return {
      url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg),
      scaledSize: new google.maps.Size(42, 52),
      anchor: new google.maps.Point(21, 50)
    };
  }

  function vzGetMapInstance() {
    return capturedMainMap ||
      window.map ||
      window.mapa ||
      window.mainMap ||
      window.mapLocales ||
      null;
  }

  function vzClearFallbackMarkers() {
    fallbackMarkers.forEach(marker => marker.setMap(null));
    fallbackMarkers = [];
  }

  function vzPopupHtml(local) {
    const codigo = local.codigoLocal ?? local.codigo ?? local.local_codigo ?? '-';
    const nombre = local.nombreLocal ?? local.nombre ?? local.local_nombre ?? '-';
    const direccion = local.direccionLocal ?? local.direccion ?? local.local_direccion ?? '-';
    const usuario = local.usuarioGestion ?? local.usuario ?? '-';
    const estado = local.estadoLabel ?? local.estadoGestion ?? local.estado ?? '-';
    const fecha = local.fechaVisita ?? local.fecha_visita ?? local.ultima_visita ?? '-';

    return `
      <div style="min-width:240px;max-width:320px;font-family:Arial,sans-serif;">
        <div style="font-size:14px;font-weight:800;color:#15315d;margin-bottom:8px;">
          ${vzEscape(nombre)}
        </div>
        <div style="font-size:12px;color:#324a6d;line-height:1.55;">
          <div><strong>Código:</strong> ${vzEscape(codigo)}</div>
          <div><strong>Dirección:</strong> ${vzEscape(direccion)}</div>
          <div><strong>Usuario:</strong> ${vzEscape(usuario)}</div>
          <div><strong>Estado:</strong> ${vzEscape(estado)}</div>
          <div><strong>Última visita:</strong> ${vzEscape(fecha)}</div>
        </div>
      </div>
    `;
  }

  function vzRenderFallbackPins() {
    const map = vzGetMapInstance();

    if (!map || !window.google || !google.maps) {
      console.warn('[Visibility mapa] No se encontró instancia del mapa para dibujar pins.');
      return;
    }

    const locales = window.MAPA_DATA || [];
    vzClearFallbackMarkers();

    const bounds = new google.maps.LatLngBounds();
    let totalPins = 0;

    locales.forEach(local => {
      const position = vzGetLatLng(local);
      if (!position) return;

      const codigo = local.codigoLocal ?? local.codigo ?? local.local_codigo ?? 'Local';
      const idLocal = local.idLocal ?? local.id_local ?? local.id ?? '';
      const color = vzGetColor(local);

      const marker = new google.maps.Marker({
        position: position,
        map: map,
        title: String(codigo),
        icon: vzPinIcon(color),
        visible: true,
        opacity: 1,
        zIndex: 999999
      });

      marker.addListener('click', function () {
        if (!fallbackInfoWindow) {
          fallbackInfoWindow = new google.maps.InfoWindow();
        }

        fallbackInfoWindow.setContent(vzPopupHtml(local));
        fallbackInfoWindow.open(map, marker);

        if (idLocal) {
          const row = document.querySelector('#tblLocales tr[data-id="' + idLocal + '"]');
          if (row) {
            document.querySelectorAll('#tblLocales tr').forEach(tr => tr.classList.remove('table-active'));
            row.classList.add('table-active');
          }
        }
      });

      fallbackMarkers.push(marker);
      bounds.extend(position);
      totalPins++;
    });

    if (totalPins === 1) {
      map.setCenter(bounds.getCenter());
      map.setZoom(15);
    } else if (totalPins > 1) {
      map.fitBounds(bounds);
    }

    console.log('[Visibility mapa] Pins individuales visibles:', totalPins);
  }

  const originalInitMap = window.initMap;

  window.initMap = function () {
    if (window.google && google.maps && google.maps.Map && !google.maps.Map.__visibilityCaptured) {
      const OriginalMap = google.maps.Map;

      google.maps.Map = function () {
        const instance = new OriginalMap(...arguments);

        if (!capturedMainMap) {
          capturedMainMap = instance;
          window.__visibilityMainMap = instance;
        }

        return instance;
      };

      google.maps.Map.prototype = OriginalMap.prototype;
      google.maps.Map.__visibilityCaptured = true;
    }

    if (typeof originalInitMap === 'function') {
      originalInitMap();
    }

    setTimeout(vzRenderFallbackPins, 700);
  };

  window.renderVisibilityPins = vzRenderFallbackPins;
})();
</script>
<script>
$(document).on('click', '#tblLocales tbody tr', function () {
  const id = String($(this).data('id') || '');
  const local = (window.MAPA_DATA || []).find(item => {
    return String(item.idLocal ?? item.id_local ?? item.id ?? '') === id;
  });

  if (!local || !window.__visibilityMainMap) return;

  const lat = parseFloat(String(
    local.lat ??
    local.latitud ??
    local.latitude ??
    local.localLat ??
    local.latLocal ??
    local.lat_local ??
    local.latGestion ??
    local.lat_gestion ??
    ''
  ).replace(',', '.'));

  const lng = parseFloat(String(
    local.lng ??
    local.lon ??
    local.longitud ??
    local.longitude ??
    local.localLng ??
    local.lngLocal ??
    local.lng_local ??
    local.lngGestion ??
    local.lng_gestion ??
    ''
  ).replace(',', '.'));

  if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

  window.__visibilityMainMap.setCenter({ lat, lng });
  window.__visibilityMainMap.setZoom(16);
});
</script>
  <script async defer src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode($mapKey) ?>&callback=initMap"></script>
</body>
</html>
