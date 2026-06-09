<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Visualizador de Ruta Excel</title>
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.2/css/all.min.css">
  <style>
    :root {
      --bg: #071225;
      --panel: #0f1f3b;
      --panel-soft: #14294b;
      --line: rgba(125, 170, 230, .22);
      --text: #e8f2ff;
      --muted: #9fb6d4;
      --accent: #25c2ff;
      --green: #25d685;
      --danger: #ff6b6b;
    }

    body {
      min-height: 100vh;
      margin: 0;
      background: linear-gradient(180deg, #08142a 0%, #050b17 100%);
      color: var(--text);
      font-family: Arial, Helvetica, sans-serif;
    }

    .app-shell {
      min-height: 100vh;
      display: grid;
      grid-template-columns: minmax(340px, 420px) 1fr;
    }

    .side {
      background: rgba(15, 31, 59, .96);
      border-right: 1px solid var(--line);
      padding: 22px;
      overflow-y: auto;
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 22px;
    }

    .brand-icon {
      width: 44px;
      height: 44px;
      display: grid;
      place-items: center;
      border-radius: 12px;
      background: rgba(37, 194, 255, .14);
      color: var(--accent);
      border: 1px solid rgba(37, 194, 255, .28);
      font-size: 1.35rem;
    }

    .brand h1 {
      font-size: 1.15rem;
      font-weight: 800;
      margin: 0;
      letter-spacing: 0;
    }

    .brand p {
      color: var(--muted);
      margin: 2px 0 0;
      font-size: .9rem;
    }

    .upload-box,
    .stats-box,
    .route-list {
      background: rgba(20, 41, 75, .78);
      border: 1px solid var(--line);
      border-radius: 8px;
      padding: 16px;
      margin-bottom: 16px;
    }

    .upload-label {
      display: block;
      color: #bcd3ef;
      font-weight: 700;
      font-size: .9rem;
      margin-bottom: 8px;
    }

    .file-control {
      background: #0b1930;
      border: 1px solid var(--line);
      color: var(--text);
      border-radius: 8px;
      padding: 10px;
      width: 100%;
    }

    .select-control {
      background: #0b1930;
      border: 1px solid var(--line);
      color: var(--text);
      border-radius: 8px;
      padding: 10px;
      width: 100%;
      margin-top: 12px;
    }

    .hint {
      color: var(--muted);
      font-size: .86rem;
      line-height: 1.45;
      margin: 10px 0 0;
    }

    .stat-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
    }

    .stat {
      background: rgba(7, 18, 37, .72);
      border: 1px solid rgba(125, 170, 230, .16);
      border-radius: 8px;
      padding: 12px;
    }

    .stat span {
      display: block;
      color: var(--muted);
      font-size: .78rem;
      text-transform: uppercase;
      font-weight: 700;
    }

    .stat strong {
      display: block;
      font-size: 1.35rem;
      margin-top: 4px;
      color: #fff;
    }

    .route-list h2 {
      font-size: .96rem;
      font-weight: 800;
      margin: 0 0 12px;
    }

    .point-row {
      display: grid;
      grid-template-columns: 34px 1fr;
      gap: 10px;
      padding: 10px 0;
      border-top: 1px solid rgba(125, 170, 230, .14);
      cursor: pointer;
    }

    .point-row:first-of-type {
      border-top: 0;
    }

    .point-num {
      width: 28px;
      height: 28px;
      display: grid;
      place-items: center;
      border-radius: 50%;
      background: var(--accent);
      color: #031325;
      font-weight: 800;
      font-size: .82rem;
    }

    .point-title {
      font-weight: 800;
      color: #fff;
      font-size: .9rem;
      margin-bottom: 3px;
    }

    .point-meta {
      color: var(--muted);
      font-size: .8rem;
      line-height: 1.35;
    }

    .map-wrap {
      position: relative;
      min-height: 100vh;
    }

    #map {
      width: 100%;
      height: 100%;
      min-height: 100vh;
    }

    .map-empty {
      position: absolute;
      left: 24px;
      bottom: 24px;
      max-width: 420px;
      background: rgba(7, 18, 37, .9);
      border: 1px solid var(--line);
      border-radius: 8px;
      padding: 14px 16px;
      color: var(--muted);
      box-shadow: 0 12px 34px rgba(0, 0, 0, .24);
    }

    .status {
      color: var(--muted);
      font-size: .88rem;
      min-height: 24px;
      margin-top: 12px;
    }

    .status.ok {
      color: var(--green);
    }

    .status.bad {
      color: var(--danger);
    }

    @media (max-width: 980px) {
      .app-shell {
        grid-template-columns: 1fr;
      }

      .side {
        border-right: 0;
        border-bottom: 1px solid var(--line);
      }

      #map,
      .map-wrap {
        min-height: 62vh;
      }
    }
  </style>
</head>
<body>
<div class="app-shell">
  <aside class="side">
    <div class="brand">
      <div class="brand-icon"><i class="fa fa-route"></i></div>
      <div>
        <h1>Visualizador de ruta</h1>
        <p>Sube el Excel exportado y revisa el orden sugerido.</p>
      </div>
    </div>

    <div class="upload-box">
      <label class="upload-label" for="archivoRuta">Archivo Excel o CSV</label>
      <input class="file-control" type="file" id="archivoRuta" accept=".xlsx,.xls,.csv">
      <p class="hint">
        El mapa usa las columnas LAT y LNG cuando existen. Si el archivo no las trae, intentara ubicar por direccion, comuna y region.
      </p>
      <label class="upload-label mt-3" for="selectorUsuario">Usuario</label>
      <select class="select-control" id="selectorUsuario" disabled>
        <option value="">Seleccionar usuario</option>
      </select>
      <label class="upload-label mt-3" for="selectorFecha">Fecha planificada</label>
      <select class="select-control" id="selectorFecha" disabled>
        <option value="">Seleccionar fecha</option>
      </select>
      <div class="status" id="estadoCarga">Esperando archivo...</div>
    </div>

    <div class="stats-box">
      <div class="stat-grid">
        <div class="stat">
          <span>Leidos</span>
          <strong id="statLeidos">0</strong>
        </div>
        <div class="stat">
          <span>Mapeados</span>
          <strong id="statMapeados">0</strong>
        </div>
        <div class="stat">
          <span>Sin mapa</span>
          <strong id="statSinMapa">0</strong>
        </div>
        <div class="stat">
          <span>KM aprox.</span>
          <strong id="statKm">0</strong>
        </div>
      </div>
    </div>

    <div class="route-list">
      <h2>Locales sugeridos</h2>
      <div id="listaLocales">
        <div class="hint">Los locales apareceran aqui numerados como 1, 2, 3, 4...</div>
      </div>
    </div>
  </aside>

  <main class="map-wrap">
    <div id="map"></div>
    <div class="map-empty" id="mapHint">
      Carga un archivo para dibujar los puntos en el mapa. El numero del marcador corresponde al orden sugerido del archivo.
    </div>
  </main>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
let map;
let bounds;
let infoWindow;
let geocoder;
let markers = [];
let markerByPointId = new Map();
let routeLine = null;
let currentPoints = [];
let loadedPoints = [];

function initMap() {
  map = new google.maps.Map(document.getElementById('map'), {
    center: { lat: -33.45, lng: -70.66 },
    zoom: 5,
    mapTypeControl: false,
    streetViewControl: false,
    fullscreenControl: true
  });

  bounds = new google.maps.LatLngBounds();
  infoWindow = new google.maps.InfoWindow();
  geocoder = new google.maps.Geocoder();
}

function normalizeHeader(text) {
  return String(text || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .trim()
    .toUpperCase()
    .replace(/\s+/g, ' ');
}

function findValue(row, names) {
  const normalized = {};
  Object.keys(row).forEach(key => {
    normalized[normalizeHeader(key)] = row[key];
  });

  for (const name of names) {
    const key = normalizeHeader(name);
    if (Object.prototype.hasOwnProperty.call(normalized, key)) {
      return normalized[key];
    }
  }

  return '';
}

function rowsFromWorksheet(sheet) {
  const matrix = XLSX.utils.sheet_to_json(sheet, {
    header: 1,
    defval: '',
    blankrows: false
  });

  const headerIndex = matrix.findIndex(row => {
    const headers = row.map(normalizeHeader);
    return headers.includes('LOCAL')
      && (headers.includes('LAT') || headers.includes('LATITUD'))
      && (headers.includes('LNG') || headers.includes('LONGITUD') || headers.includes('LON'));
  });

  if (headerIndex < 0) {
    return XLSX.utils.sheet_to_json(sheet, { defval: '' });
  }

  const headers = matrix[headerIndex].map(header => String(header || '').trim());
  const rows = [];

  for (let i = headerIndex + 1; i < matrix.length; i++) {
    const source = matrix[i];
    const row = {};
    let hasValue = false;

    headers.forEach((header, index) => {
      if (!header) return;
      const value = source[index] ?? '';
      row[header] = value;
      if (value !== '') hasValue = true;
    });

    if (hasValue) {
      rows.push(row);
    }
  }

  return rows;
}

function parseCoord(value) {
  if (value === null || value === undefined || value === '') return null;
  const parsed = parseFloat(String(value).replace(',', '.').trim());
  return Number.isFinite(parsed) ? parsed : null;
}

function distanceKm(a, b) {
  const r = 6371;
  const dLat = (b.lat - a.lat) * Math.PI / 180;
  const dLng = (b.lng - a.lng) * Math.PI / 180;
  const lat1 = a.lat * Math.PI / 180;
  const lat2 = b.lat * Math.PI / 180;
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;
  return 2 * r * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
}

function estimateKm(points) {
  let total = 0;
  for (let i = 1; i < points.length; i++) {
    total += distanceKm(points[i - 1], points[i]);
  }
  return total;
}

function getOrder(row, fallback) {
  const raw = findValue(row, ['ORDEN VISITA', 'ORDEN_VISITA', 'ORDEN', '#', 'N']);
  const num = parseInt(raw, 10);
  return Number.isFinite(num) && num > 0 ? num : fallback;
}

function hasExplicitOrder(row) {
  const raw = findValue(row, ['ORDEN VISITA', 'ORDEN_VISITA', 'ORDEN', '#', 'N']);
  const num = parseInt(raw, 10);
  return Number.isFinite(num) && num > 0;
}

function normalizeDateValue(value) {
  if (value === null || value === undefined || value === '') return '';

  if (value instanceof Date && !Number.isNaN(value.getTime())) {
    return value.toISOString().slice(0, 10);
  }

  if (typeof value === 'number' && Number.isFinite(value)) {
    const parsed = XLSX.SSF.parse_date_code(value);
    if (parsed) {
      const month = String(parsed.m).padStart(2, '0');
      const day = String(parsed.d).padStart(2, '0');
      return `${parsed.y}-${month}-${day}`;
    }
  }

  const text = String(value).trim();
  const matchDmy = text.match(/^(\d{1,2})[-/](\d{1,2})[-/](\d{4})$/);
  if (matchDmy) {
    return `${matchDmy[3]}-${matchDmy[2].padStart(2, '0')}-${matchDmy[1].padStart(2, '0')}`;
  }

  const matchYmd = text.match(/^(\d{4})[-/](\d{1,2})[-/](\d{1,2})$/);
  if (matchYmd) {
    return `${matchYmd[1]}-${matchYmd[2].padStart(2, '0')}-${matchYmd[3].padStart(2, '0')}`;
  }

  return text;
}

function formatDateLabel(value) {
  const normalized = normalizeDateValue(value);
  const match = normalized.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  return match ? `${match[3]}-${match[2]}-${match[1]}` : (normalized || 'SIN FECHA');
}

function rowToPoint(row, index) {
  const lat = parseCoord(findValue(row, ['LAT', 'LATITUD', 'LATITUDE']));
  const lng = parseCoord(findValue(row, ['LNG', 'LONGITUD', 'LONGITUDE', 'LON']));
  const fechaPlanificadaRaw = findValue(row, ['FECHA PLANIFICADA', 'FECHA_PROPUESTA', 'FECHAPROPUESTA', 'FECHA RUTA', 'FECHA']);
  const codigo = String(findValue(row, ['CODIGO', 'CODIGO LOCAL', 'CÓDIGO LOCAL']) || '').trim();

  return {
    id: `${codigo || 'row'}-${index}`,
    order: getOrder(row, index + 1),
    codigo,
    nombre: String(findValue(row, ['LOCAL', 'NOMBRE', 'NOMBRE LOCAL']) || '').trim(),
    direccion: String(findValue(row, ['DIRECCION', 'DIRECCIÓN']) || '').trim(),
    region: String(findValue(row, ['REGION', 'REGIÓN']) || '').trim(),
    comuna: String(findValue(row, ['COMUNA']) || '').trim(),
    merchan: String(findValue(row, ['MERCHAN', 'USUARIO', 'EJECUTOR']) || '').trim(),
    fechaPlanificada: normalizeDateValue(fechaPlanificadaRaw),
    fechaPlanificadaLabel: formatDateLabel(fechaPlanificadaRaw),
    lat,
    lng,
    hasOrder: hasExplicitOrder(row),
    sourceRow: row
  };
}

function suggestRouteOrder(points) {
  if (points.length <= 2) return [...points];

  const centroid = points.reduce((acc, point) => ({
    lat: acc.lat + point.lat / points.length,
    lng: acc.lng + point.lng / points.length
  }), { lat: 0, lng: 0 });

  let startIndex = 0;
  let farthest = -1;

  points.forEach((point, index) => {
    const dist = distanceKm(point, centroid);
    if (dist > farthest) {
      farthest = dist;
      startIndex = index;
    }
  });

  const remaining = [...points];
  const ordered = [remaining.splice(startIndex, 1)[0]];

  while (remaining.length) {
    const current = ordered[ordered.length - 1];
    let bestIndex = 0;
    let bestDistance = Infinity;

    remaining.forEach((candidate, index) => {
      const dist = distanceKm(current, candidate);
      if (dist < bestDistance) {
        bestDistance = dist;
        bestIndex = index;
      }
    });

    ordered.push(remaining.splice(bestIndex, 1)[0]);
  }

  return ordered;
}

function clearMap() {
  markers.forEach(marker => marker.setMap(null));
  markers = [];
  markerByPointId = new Map();
  currentPoints = [];
  bounds = new google.maps.LatLngBounds();

  if (routeLine) {
    routeLine.setMap(null);
    routeLine = null;
  }
}

function markerIcon(number) {
  return {
    path: google.maps.SymbolPath.CIRCLE,
    fillColor: '#25c2ff',
    fillOpacity: 1,
    strokeColor: '#061225',
    strokeWeight: 2,
    scale: 15,
    labelOrigin: new google.maps.Point(0, 0)
  };
}

function refreshMapSize() {
  if (!map || typeof google === 'undefined' || !google.maps) return;
  google.maps.event.trigger(map, 'resize');
}

function fitRouteBounds(path) {
  if (!path.length) return;

  refreshMapSize();

  if (path.length === 1) {
    map.setCenter(path[0]);
    map.setZoom(16);
    return;
  }

  map.fitBounds(bounds, {
    top: 70,
    right: 70,
    bottom: 70,
    left: 70
  });

  setTimeout(() => {
    refreshMapSize();
    map.fitBounds(bounds, {
      top: 70,
      right: 70,
      bottom: 70,
      left: 70
    });
  }, 120);
}

function drawPoints(points) {
  clearMap();
  currentPoints = points;

  const path = [];

  points.forEach((point, index) => {
    const position = { lat: point.lat, lng: point.lng };
    path.push(position);
    bounds.extend(position);

    const marker = new google.maps.Marker({
      position,
      map,
      title: point.nombre || point.codigo || `Local ${index + 1}`,
      label: {
        text: String(index + 1),
        color: '#061225',
        fontWeight: '800',
        fontSize: '12px'
      },
      icon: markerIcon(index + 1)
    });

    marker.addListener('click', () => {
      infoWindow.setContent(`
        <div style="min-width:240px;color:#13233b;">
          <strong>Local ${index + 1}</strong><br>
          <b>${escapeHtml(point.nombre || '-')}</b><br>
          ${escapeHtml(point.direccion || '')}<br>
          ${escapeHtml([point.comuna, point.region].filter(Boolean).join(', '))}
        </div>
      `);
      infoWindow.open(map, marker);
    });

    markers.push(marker);
    markerByPointId.set(String(point.id), marker);
  });

  if (path.length > 1) {
    routeLine = new google.maps.Polyline({
      path,
      map,
      strokeColor: '#25d685',
      strokeOpacity: .95,
      strokeWeight: 4
    });
  }

  fitRouteBounds(path);
}

function focusPoint(pointId) {
  const marker = markerByPointId.get(String(pointId));
  if (!marker) return;

  const position = marker.getPosition();
  refreshMapSize();
  map.setCenter(position);
  map.setZoom(16);

  setTimeout(() => {
    map.setCenter(position);
    google.maps.event.trigger(marker, 'click');
  }, 80);
}

function renderList(points) {
  const container = document.getElementById('listaLocales');

  if (!points.length) {
    container.innerHTML = '<div class="hint">No hay locales con ubicacion para mostrar.</div>';
    return;
  }

  container.innerHTML = points.map((point, index) => `
    <div class="point-row" data-point-id="${escapeHtml(point.id)}">
      <div class="point-num">${index + 1}</div>
      <div>
        <div class="point-title">${escapeHtml(point.nombre || `Local ${index + 1}`)}</div>
        <div class="point-meta">
          ${escapeHtml(point.codigo ? `Codigo ${point.codigo}` : '')}
          ${point.codigo ? '<br>' : ''}
          ${escapeHtml(point.direccion || '')}
          ${point.direccion ? '<br>' : ''}
          ${escapeHtml([point.comuna, point.region].filter(Boolean).join(', '))}
          ${point.fechaPlanificada ? '<br>' : ''}
          ${escapeHtml(point.fechaPlanificada ? `Fecha ${point.fechaPlanificadaLabel}` : '')}
        </div>
      </div>
    </div>
  `).join('');

  container.querySelectorAll('.point-row').forEach(row => {
    row.addEventListener('click', () => {
      focusPoint(row.dataset.pointId);
    });
  });
}

function escapeHtml(text) {
  return String(text ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function setStatus(text, type = '') {
  const el = document.getElementById('estadoCarga');
  el.textContent = text;
  el.className = `status ${type}`;
}

function updateStats(total, mapped, missing) {
  document.getElementById('statLeidos').textContent = total;
  document.getElementById('statMapeados').textContent = mapped;
  document.getElementById('statSinMapa').textContent = missing;
  document.getElementById('statKm').textContent = mapped > 1 ? estimateKm(currentPoints).toFixed(1) : '0';
}

function geocodeAddress(point) {
  const address = [point.direccion, point.comuna, point.region, 'Chile'].filter(Boolean).join(', ');
  if (!address.trim()) return Promise.resolve(point);

  return new Promise(resolve => {
    geocoder.geocode({ address }, (results, status) => {
      if (status === 'OK' && results && results[0]) {
        const loc = results[0].geometry.location;
        point.lat = loc.lat();
        point.lng = loc.lng();
      }
      resolve(point);
    });
  });
}

async function ensureCoordinates(points) {
  const output = [];

  for (let i = 0; i < points.length; i++) {
    const point = points[i];

    if (point.lat === null || point.lng === null) {
      setStatus(`Buscando coordenadas ${i + 1} de ${points.length}...`);
      await geocodeAddress(point);
      await new Promise(resolve => setTimeout(resolve, 180));
    }

    output.push(point);
  }

  return output;
}

function normalizeUser(value) {
  const text = String(value || '').trim();
  return text === '' ? 'SIN USUARIO' : text;
}

function resetVisualization(message = 'Selecciona un usuario para visualizar su ruta.') {
  clearMap();
  renderList([]);
  updateStats(loadedPoints.length, 0, 0);
  document.getElementById('mapHint').style.display = 'block';
  setStatus(message);
}

function populateUserSelector(points) {
  const selector = document.getElementById('selectorUsuario');
  const counts = new Map();

  points.forEach(point => {
    const user = normalizeUser(point.merchan);
    counts.set(user, (counts.get(user) || 0) + 1);
  });

  const users = [...counts.entries()].sort((a, b) => a[0].localeCompare(b[0], 'es'));

  selector.innerHTML = '<option value="">Seleccionar usuario</option>';
  users.forEach(([user, count]) => {
    const option = document.createElement('option');
    option.value = user;
    option.textContent = `${user} (${count})`;
    selector.appendChild(option);
  });

  selector.disabled = users.length === 0;
}

function populateDateSelector(user) {
  const selector = document.getElementById('selectorFecha');
  const counts = new Map();

  loadedPoints
    .filter(point => normalizeUser(point.merchan) === user)
    .forEach(point => {
      const value = point.fechaPlanificada || 'SIN FECHA';
      const label = point.fechaPlanificadaLabel || 'SIN FECHA';
      const current = counts.get(value) || { label, count: 0 };
      current.count++;
      counts.set(value, current);
    });

  const dates = [...counts.entries()].sort((a, b) => {
    if (a[0] === 'SIN FECHA') return 1;
    if (b[0] === 'SIN FECHA') return -1;
    return a[0].localeCompare(b[0]);
  });

  selector.innerHTML = '<option value="">Seleccionar fecha</option>';
  dates.forEach(([value, info]) => {
    const option = document.createElement('option');
    option.value = value;
    option.textContent = `${info.label} (${info.count})`;
    selector.appendChild(option);
  });

  selector.disabled = dates.length === 0;
}

function handleRows(rows) {
  if (!rows.length) {
    loadedPoints = [];
    populateUserSelector([]);
    populateDateSelector('');
    resetVisualization('El archivo no contiene filas para visualizar.');
    setStatus('El archivo no contiene filas para visualizar.', 'bad');
    return;
  }

  loadedPoints = rows
    .map(rowToPoint)
    .filter(point => point.nombre || point.codigo || point.direccion)
    .sort((a, b) => a.order - b.order);

  populateUserSelector(loadedPoints);
  populateDateSelector('');
  resetVisualization(`Archivo cargado con ${loadedPoints.length} locales. Selecciona un usuario para dibujar la ruta.`);
}

async function renderSelectedUserRoute(user, dateValue) {
  if (!user) {
    resetVisualization('Selecciona un usuario para visualizar su ruta.');
    return;
  }

  if (!dateValue) {
    resetVisualization('Selecciona una fecha para visualizar la ruta del usuario.');
    return;
  }

  const selected = loadedPoints
    .filter(point => normalizeUser(point.merchan) === user)
    .filter(point => (point.fechaPlanificada || 'SIN FECHA') === dateValue)
    .sort((a, b) => a.order - b.order);

  if (!selected.length) {
    resetVisualization('No hay locales para el usuario seleccionado.');
    return;
  }

  clearMap();
  renderList([]);
  updateStats(selected.length, 0, 0);
  setStatus(`Preparando ruta de ${user}...`);

  const withCoords = await ensureCoordinates(selected);
  let mapped = withCoords.filter(point => point.lat !== null && point.lng !== null);
  const hasFileOrder = mapped.some(point => point.hasOrder);
  mapped = hasFileOrder
    ? mapped.sort((a, b) => a.order - b.order)
    : suggestRouteOrder(mapped);
  const missing = withCoords.length - mapped.length;

  drawPoints(mapped);
  renderList(mapped);
  updateStats(withCoords.length, mapped.length, missing);

  document.getElementById('mapHint').style.display = mapped.length ? 'none' : 'block';
  setStatus(mapped.length ? `Ruta de ${user} cargada con ${mapped.length} locales.` : 'No se pudieron ubicar locales en el mapa.', mapped.length ? 'ok' : 'bad');
}

document.getElementById('archivoRuta').addEventListener('change', event => {
  const file = event.target.files && event.target.files[0];
  if (!file) return;

  setStatus('Procesando archivo...');
  document.getElementById('selectorUsuario').value = '';
  document.getElementById('selectorFecha').value = '';
  document.getElementById('selectorFecha').disabled = true;

  const reader = new FileReader();
  reader.onload = async evt => {
    try {
      const data = new Uint8Array(evt.target.result);
      const workbook = XLSX.read(data, { type: 'array' });
      const sheet = workbook.Sheets[workbook.SheetNames[0]];
      const rows = rowsFromWorksheet(sheet);
      await handleRows(rows);
    } catch (error) {
      console.error(error);
      setStatus('No fue posible leer el archivo. Revisa que sea Excel o CSV valido.', 'bad');
    }
  };
  reader.readAsArrayBuffer(file);
});

document.getElementById('selectorUsuario').addEventListener('change', event => {
  const user = event.target.value;
  populateDateSelector(user);
  document.getElementById('selectorFecha').value = '';
  resetVisualization(user ? 'Selecciona una fecha para visualizar la ruta del usuario.' : 'Selecciona un usuario para visualizar su ruta.');
});

document.getElementById('selectorFecha').addEventListener('change', event => {
  renderSelectedUserRoute(document.getElementById('selectorUsuario').value, event.target.value);
});
</script>
<script async defer src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDO0zLDNeEdLcQgkl7dF0C0Lgr3Wl1m3cw&callback=initMap"></script>
</body>
</html>
