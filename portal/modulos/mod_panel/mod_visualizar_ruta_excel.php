<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Visualizador de Rutas Excel</title>
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
      grid-template-columns: minmax(360px, 460px) 1fr;
    }

    .side {
      background: rgba(15, 31, 59, .96);
      border-right: 1px solid var(--line);
      padding: 22px;
      overflow-y: auto;
      max-height: 100vh;
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
    }

    .brand p {
      color: var(--muted);
      margin: 2px 0 0;
      font-size: .9rem;
    }

    .box {
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

    .file-control,
    .select-control,
    .text-control {
      background: #0b1930;
      border: 1px solid var(--line);
      color: var(--text);
      border-radius: 8px;
      padding: 10px;
      width: 100%;
    }

    .hint {
      color: var(--muted);
      font-size: .86rem;
      line-height: 1.45;
      margin: 10px 0 0;
    }

    .status {
      color: var(--muted);
      font-size: .88rem;
      min-height: 24px;
      margin-top: 12px;
    }

    .status.ok { color: var(--green); }
    .status.bad { color: var(--danger); }

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
      grid-template-columns: 26px 34px 1fr 78px;
      gap: 10px;
      padding: 10px 0;
      border-top: 1px solid rgba(125, 170, 230, .14);
      cursor: pointer;
    }

    .point-row:first-of-type { border-top: 0; }

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

    .move-buttons {
      display: flex;
      gap: 4px;
      justify-content: flex-end;
      align-items: start;
    }

    .move-buttons button {
      width: 32px;
      height: 30px;
      border-radius: 6px;
      border: 1px solid rgba(125, 170, 230, .28);
      background: #0b1930;
      color: var(--text);
    }

    .move-buttons button:disabled {
      opacity: .35;
      cursor: not-allowed;
    }

    .bulk-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      margin-bottom: 10px;
      padding: 10px;
      background: rgba(7, 18, 37, .72);
      border: 1px solid rgba(125, 170, 230, .16);
      border-radius: 8px;
    }

    .bulk-bar strong {
      color: #fff;
    }

    .row-check {
      margin-top: 7px;
      accent-color: var(--accent);
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

    @media (max-width: 980px) {
      .app-shell { grid-template-columns: 1fr; }
      .side {
        border-right: 0;
        border-bottom: 1px solid var(--line);
        max-height: none;
      }
      #map,
      .map-wrap { min-height: 62vh; }
    }
  </style>
</head>
<body>
<div class="app-shell">
  <aside class="side">
    <div class="brand">
      <div class="brand-icon"><i class="fa fa-route"></i></div>
      <div>
        <h1>Visualizador de rutas</h1>
        <p>Sube el Excel, revisa el orden y descarga la ruta editada.</p>
      </div>
    </div>

    <div class="box">
      <label class="upload-label" for="archivoRuta">Archivo Excel o CSV</label>
      <input class="file-control" type="file" id="archivoRuta" accept=".xlsx,.xls,.csv">
      <p class="hint">
        Formato esperado: Codigo Local, Nombre, Lat, Lng, Usuario Nombre, Fecha Ruta, Grupo Ruta y Orden Visita.
      </p>
      <p class="hint">
        Rojo con !: error o sin ruta. Amarillo con !: sin ruta asignada con sugerencia. Colores neon: rutas planificadas.
      </p>

      <label class="upload-label mt-3" for="selectorUsuario">Usuario</label>
      <select class="select-control" id="selectorUsuario" disabled>
        <option value="">Seleccionar usuario</option>
      </select>

      <label class="upload-label mt-3" for="selectorRuta">Ruta</label>
      <select class="select-control" id="selectorRuta" disabled>
        <option value="">Seleccionar ruta</option>
      </select>

      <button class="btn btn-success btn-block mt-3" id="btnDescargarRuta" type="button" disabled>
        <i class="fa fa-file-excel"></i> Descargar rutas editadas
      </button>

      <label class="upload-label mt-3" for="selectorMesMensual">Ruta mensual</label>
      <input class="text-control" type="month" id="selectorMesMensual">
      <button class="btn btn-info btn-block mt-2" id="btnDescargarMensual" type="button" disabled>
        <i class="fa fa-calendar-alt"></i> Descargar como ruta mensual
      </button>
      <p class="hint">
        Repite el patron de dias planificados sobre los dias habiles del mes. Evita repetir el mismo cliente en menos de 5 dias cuando existe alternativa.
      </p>

      <div class="status" id="estadoCarga">Esperando archivo...</div>
    </div>

    <div class="box">
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

    <div class="box route-list">
      <h2>Locales de la ruta</h2>
      <div class="bulk-bar">
        <span><strong id="selectedCount">0</strong> seleccionados</span>
        <button class="btn btn-sm btn-primary" id="btnEditarSeleccion" type="button" disabled>
          <i class="fa fa-user-edit"></i> Reasignar
        </button>
      </div>
      <div id="listaLocales">
        <div class="hint">Los locales apareceran aqui numerados. Usa las flechas para cambiar el orden.</div>
      </div>
    </div>
  </aside>

  <main class="map-wrap">
    <div id="map"></div>
    <div class="map-empty" id="mapHint">
      Carga un archivo para dibujar los puntos en el mapa. El numero del marcador corresponde al orden de visita.
    </div>
  </main>
</div>

<div class="modal fade" id="modalReasignar" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content" style="background:#0f1f3b;color:#e8f2ff;border:1px solid rgba(125,170,230,.22);">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa fa-route"></i> Reasignar locales</h5>
        <button type="button" class="close text-light" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <p class="hint mt-0">Se actualizaran <strong id="modalSelectedCount">0</strong> local(es).</p>
        <label class="upload-label" for="modalUsuario">Usuario destino</label>
        <select class="select-control" id="modalUsuario"></select>

        <label class="upload-label mt-3" for="modalRuta">Ruta destino</label>
        <select class="select-control" id="modalRuta"></select>

        <label class="upload-label mt-3" for="modalOrdenInicial">Orden inicial</label>
        <input class="text-control" type="number" min="1" id="modalOrdenInicial" placeholder="Automatico al final de la ruta">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-light" data-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-success" id="btnAplicarReasignacion">
          <i class="fa fa-check"></i> Aplicar
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>
<script>
let map;
let bounds;
let infoWindow;
let markers = [];
let markerByPointId = new Map();
let routeLines = [];
let currentPoints = [];
let loadedPoints = [];
let loadedHeaders = [];
let currentFileBaseName = 'rutas_editadas';
let selectedPointIds = new Set();
const routePalette = [
  '#25c2ff', '#25d685', '#ffb020', '#ff6b6b', '#9b7bff',
  '#00a6a6', '#f97316', '#84cc16', '#ec4899', '#38bdf8',
  '#a16207', '#14b8a6', '#ef4444', '#6366f1', '#22c55e'
];

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
}

function normalizeHeader(text) {
  return String(text || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .trim()
    .toUpperCase()
    .replace(/\s+/g, ' ');
}

function escapeHtml(text) {
  return String(text ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function hashCode(text) {
  let hash = 0;
  const safe = String(text || '');
  for (let i = 0; i < safe.length; i++) {
    hash = ((hash << 5) - hash) + safe.charCodeAt(i);
    hash |= 0;
  }
  return Math.abs(hash);
}

function routeColor(routeName) {
  return routePalette[hashCode(routeName) % routePalette.length];
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

function getSourceKey(point, candidates) {
  const source = point.sourceRow || {};
  const normalized = {};
  Object.keys(source).forEach(key => {
    normalized[normalizeHeader(key)] = key;
  });

  for (const candidate of candidates) {
    const key = normalized[normalizeHeader(candidate)];
    if (key) return key;
  }

  const fallback = candidates[0];
  if (!loadedHeaders.includes(fallback)) loadedHeaders.push(fallback);
  return fallback;
}

function setSourceValue(point, candidates, value) {
  point.sourceRow[getSourceKey(point, candidates)] = value;
}

function clonePointRow(point) {
  const clone = {};
  Object.keys(point.sourceRow || {}).forEach(key => {
    clone[key] = point.sourceRow[key];
  });
  return clone;
}

function excelDateSerial(date) {
  const epoch = Date.UTC(1899, 11, 30);
  const value = Date.UTC(date.getFullYear(), date.getMonth(), date.getDate());
  return Math.round((value - epoch) / 86400000);
}

function formatDateSql(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function businessDaysInMonth(monthValue) {
  const [yearRaw, monthRaw] = String(monthValue || '').split('-');
  const year = Number(yearRaw);
  const month = Number(monthRaw);
  if (!year || !month) return [];

  const days = [];
  const date = new Date(year, month - 1, 1, 12, 0, 0);
  while (date.getMonth() === month - 1) {
    const day = date.getDay();
    if (day >= 1 && day <= 5) {
      days.push(new Date(date.getFullYear(), date.getMonth(), date.getDate(), 12, 0, 0));
    }
    date.setDate(date.getDate() + 1);
  }
  return days;
}

function dayNameEs(date) {
  return ['Domingo', 'Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado'][date.getDay()];
}

function businessWeekOfMonth(date) {
  const first = new Date(date.getFullYear(), date.getMonth(), 1, 12, 0, 0);
  let count = 0;
  while (first <= date) {
    const day = first.getDay();
    if (day >= 1 && day <= 5) count++;
    first.setDate(first.getDate() + 1);
  }
  return Math.max(1, Math.ceil(count / 5));
}

function setRowValue(row, candidates, value) {
  const normalized = {};
  Object.keys(row).forEach(key => {
    normalized[normalizeHeader(key)] = key;
  });
  let target = candidates[0];
  for (const candidate of candidates) {
    const key = normalized[normalizeHeader(candidate)];
    if (key) {
      target = key;
      break;
    }
  }
  row[target] = value;
  if (!loadedHeaders.includes(target)) loadedHeaders.push(target);
}

function routePointsFor(userName, routeName) {
  return loadedPoints
    .filter(point => point.usuarioNombre === userName)
    .filter(point => point.grupoRuta === routeName)
    .filter(point => point.lat !== null && point.lng !== null)
    .sort((a, b) => a.order - b.order);
}

function routesForUser(userName) {
  return [...new Set(
    loadedPoints
      .filter(point => point.usuarioNombre === userName)
      .map(point => point.grupoRuta || 'SIN RUTA')
  )].sort((a, b) => a.localeCompare(b, 'es'));
}

function updateSelectionUi() {
  const count = selectedPointIds.size;
  document.getElementById('selectedCount').textContent = count;
  document.getElementById('btnEditarSeleccion').disabled = count === 0;
}

function setPointUserAndRoute(point, userName, routeName) {
  point.usuarioNombre = userName;
  point.grupoRuta = routeName;
  setSourceValue(point, ['Usuario Nombre', 'USUARIO NOMBRE', 'Usuario'], userName);
  setSourceValue(point, ['Grupo Ruta', 'GRUPO RUTA', 'Ruta'], routeName);
}

function rowsFromWorksheet(sheet) {
  const matrix = XLSX.utils.sheet_to_json(sheet, {
    header: 1,
    defval: '',
    blankrows: false
  });

  const headerIndex = matrix.findIndex(row => {
    const headers = row.map(normalizeHeader);
    return (headers.includes('CODIGO LOCAL') || headers.includes('CODIGO') || headers.includes('NOMBRE'))
      && (headers.includes('LAT') || headers.includes('LATITUD'))
      && (headers.includes('LNG') || headers.includes('LONGITUD') || headers.includes('LON'));
  });

  if (headerIndex < 0) {
    loadedHeaders = [];
    return XLSX.utils.sheet_to_json(sheet, { defval: '' });
  }

  loadedHeaders = matrix[headerIndex].map(header => String(header || '').trim()).filter(Boolean);
  const rows = [];

  for (let i = headerIndex + 1; i < matrix.length; i++) {
    const source = matrix[i];
    const row = {};
    let hasValue = false;

    loadedHeaders.forEach((header, index) => {
      const value = source[index] ?? '';
      row[header] = value;
      if (value !== '') hasValue = true;
    });

    if (hasValue) rows.push(row);
  }

  return rows;
}

function parseCoord(value) {
  if (value === null || value === undefined || value === '') return null;
  const parsed = parseFloat(String(value).replace(',', '.').trim());
  return Number.isFinite(parsed) ? parsed : null;
}

function normalizeDateValue(value) {
  if (value === null || value === undefined || value === '') return '';

  if (value instanceof Date && !Number.isNaN(value.getTime())) {
    return value.toISOString().slice(0, 10);
  }

  if (typeof value === 'number' && Number.isFinite(value)) {
    const parsed = XLSX.SSF.parse_date_code(value);
    if (parsed) {
      return `${parsed.y}-${String(parsed.m).padStart(2, '0')}-${String(parsed.d).padStart(2, '0')}`;
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

function getOrder(row, fallback) {
  const raw = findValue(row, ['ORDEN VISITA', 'ORDEN_VISITA', 'ORDEN', '#', 'N']);
  const num = parseInt(raw, 10);
  return Number.isFinite(num) && num > 0 ? num : fallback;
}

function rowToPoint(row, index) {
  const fechaRaw = findValue(row, ['FECHA RUTA', 'FECHA PLANIFICADA', 'FECHA_PROPUESTA', 'FECHAPROPUESTA', 'FECHA']);
  const codigo = String(findValue(row, ['CODIGO LOCAL', 'CODIGO', 'CÓDIGO LOCAL']) || '').trim();

  return {
    id: `${codigo || 'row'}-${index}`,
    order: getOrder(row, index + 1),
    codigo,
    nombre: String(findValue(row, ['NOMBRE', 'LOCAL', 'NOMBRE LOCAL']) || '').trim(),
    direccion: String(findValue(row, ['DIRECCION', 'DIRECCIÓN']) || '').trim(),
    comuna: String(findValue(row, ['COMUNA']) || '').trim(),
    lat: parseCoord(findValue(row, ['LAT', 'LATITUD', 'LATITUDE'])),
    lng: parseCoord(findValue(row, ['LNG', 'LONGITUD', 'LONGITUDE', 'LON'])),
    cantidadObjetivoDia: findValue(row, ['CANTIDAD OBJETIVO DIA', 'CANTIDAD OBJETIVO DÍA']),
    grupoRuta: String(findValue(row, ['GRUPO RUTA', 'RUTA', 'GRUPO']) || '').trim() || 'SIN RUTA',
    grupoRutaSugerido: String(findValue(row, ['GRUPO RUTA SUGERIDO']) || '').trim(),
    observacion: String(findValue(row, ['OBSERVACION', 'OBSERVACIÓN', 'ERROR', 'ESTADO']) || '').trim(),
    fechaRuta: normalizeDateValue(fechaRaw) || 'SIN FECHA',
    fechaRutaLabel: formatDateLabel(fechaRaw),
    usuarioId: String(findValue(row, ['USUARIO ID', 'ID USUARIO']) || '').trim(),
    usuarioLogin: String(findValue(row, ['USUARIO LOGIN', 'LOGIN']) || '').trim(),
    usuarioNombre: String(findValue(row, ['USUARIO NOMBRE', 'MERCHAN', 'USUARIO', 'EJECUTOR']) || '').trim() || 'SIN USUARIO',
    diaPlan: findValue(row, ['DIA PLAN', 'DÍA PLAN']),
    semanaPlan: findValue(row, ['SEMANA PLAN']),
    diaSemanaNum: findValue(row, ['DIA SEMANA Nº', 'DÍA SEMANA Nº', 'DIA SEMANA NUM']),
    diaSemana: findValue(row, ['DIA SEMANA', 'DÍA SEMANA']),
    sourceRow: row
  };
}

function distanceKm(a, b) {
  if (![a?.lat, a?.lng, b?.lat, b?.lng].every(Number.isFinite)) return 0;
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

function clearMap() {
  markers.forEach(marker => marker.setMap(null));
  markers = [];
  markerByPointId = new Map();
  currentPoints = [];
  bounds = new google.maps.LatLngBounds();

  routeLines.forEach(line => line.setMap(null));
  routeLines = [];
}

function pointStatus(point) {
  const route = normalizeHeader(point.grupoRuta || '');
  const suggested = normalizeHeader(point.grupoRutaSugerido || '');
  const note = normalizeHeader(point.observacion || '');

  if (note.includes('ERROR') || route.includes('ERROR')) {
    return { type: 'error', color: '#ff2d55', label: '!', title: 'Error de ruta' };
  }

  if (route === '' || route === 'SIN RUTA' || route === 'SIN RUTA O FECHA' || route === 'NO PLANIFICADO') {
    return { type: suggested ? 'unassigned' : 'error', color: suggested ? '#ffd60a' : '#ff2d55', label: '!', title: suggested ? 'Sin ruta asignada' : 'Sin ruta o con error' };
  }

  return { type: 'ok', color: routeColor(point.grupoRuta), label: null, title: 'Planificado' };
}

function visibleOrder(point, fallback) {
  const order = Number(point.order || 0);
  return Number.isFinite(order) && order > 0 ? order : fallback;
}

function neonMarkerIcon(color) {
  const safeColor = color || '#25c2ff';
  const svg = `
    <svg xmlns="http://www.w3.org/2000/svg" width="44" height="44" viewBox="0 0 44 44">
      <defs>
        <filter id="glow" x="-60%" y="-60%" width="220%" height="220%">
          <feGaussianBlur stdDeviation="3.2" result="blur"/>
          <feColorMatrix in="blur" type="matrix" values="1 0 0 0 0  0 1 0 0 0  0 0 1 0 0  0 0 0 .9 0"/>
          <feMerge>
            <feMergeNode/>
            <feMergeNode in="SourceGraphic"/>
          </feMerge>
        </filter>
      </defs>
      <circle cx="22" cy="22" r="16" fill="${safeColor}" opacity=".28" filter="url(#glow)"/>
      <circle cx="22" cy="22" r="13" fill="#061225" stroke="${safeColor}" stroke-width="3"/>
      <circle cx="22" cy="22" r="8" fill="${safeColor}"/>
    </svg>`;
  return {
    url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg),
    scaledSize: new google.maps.Size(44, 44),
    anchor: new google.maps.Point(22, 22),
    labelOrigin: new google.maps.Point(22, 22)
  };
}

function drawPoints(points) {
  clearMap();
  currentPoints = points;
  const groupedPaths = {};

  points.forEach((point, index) => {
    const position = { lat: point.lat, lng: point.lng };
    const routeName = point.grupoRuta || 'SIN RUTA';
    if (!groupedPaths[routeName]) groupedPaths[routeName] = [];
    groupedPaths[routeName].push(position);
    bounds.extend(position);
    const status = pointStatus(point);
    const color = status.color;
    const markerLabel = status.label || String(visibleOrder(point, index + 1));

    const marker = new google.maps.Marker({
      position,
      map,
      title: point.nombre || point.codigo || `Local ${index + 1}`,
      label: {
        text: markerLabel,
        color: status.type === 'ok' ? '#ffffff' : '#061225',
        fontWeight: '800',
        fontSize: '12px'
      },
      icon: neonMarkerIcon(color)
    });

    marker.addListener('click', () => {
      infoWindow.setContent(`
        <div style="min-width:240px;color:#13233b;">
          <strong>${escapeHtml(routeName)} · Orden ${index + 1}</strong><br>
          <b>${escapeHtml(point.nombre || '-')}</b><br>
          Codigo: ${escapeHtml(point.codigo || '-')}<br>
          ${escapeHtml(point.direccion || '')}<br>
          ${escapeHtml(point.comuna || '')}
        </div>
      `);
      infoWindow.open(map, marker);
    });

    markers.push(marker);
    markerByPointId.set(String(point.id), marker);
  });

  Object.keys(groupedPaths).forEach(routeName => {
    const path = groupedPaths[routeName];
    if (path.length <= 1) return;
    const line = new google.maps.Polyline({
      path,
      map,
      strokeColor: routeColor(routeName),
      strokeOpacity: .95,
      strokeWeight: 4
    });
    routeLines.push(line);
  });

  const path = points.map(point => ({ lat: point.lat, lng: point.lng }));
  if (path.length === 1) {
    map.setCenter(path[0]);
    map.setZoom(16);
  } else if (path.length > 1 && !bounds.isEmpty()) {
    map.fitBounds(bounds, { top: 70, right: 70, bottom: 70, left: 70 });
  }
}

function focusPoint(pointId) {
  const marker = markerByPointId.get(String(pointId));
  if (!marker) return;
  const position = marker.getPosition();
  map.setCenter(position);
  map.setZoom(16);
  google.maps.event.trigger(marker, 'click');
}

function updateSourceOrder(points) {
  points.forEach((point, index) => {
    const order = index + 1;
    point.order = order;
    setSourceValue(point, ['Orden Visita', 'ORDEN VISITA', 'ORDEN'], order);
    setSourceValue(point, ['Tamaño Ruta', 'TAMANO RUTA'], points.length);
  });

  const totalKm = estimateKm(points);
  points.forEach((point, index) => {
    const prev = index > 0 ? points[index - 1] : null;
    const legKm = prev ? distanceKm(prev, point) : '';
    setSourceValue(point, ['Distancia Desde Anterior (KM)'], legKm === '' ? '' : Number(legKm.toFixed(2)));
    setSourceValue(point, ['Distancia Total Ruta (KM)'], Number(totalKm.toFixed(2)));
  });
}

function movePoint(pointId, direction) {
  const index = currentPoints.findIndex(point => String(point.id) === String(pointId));
  const nextIndex = index + direction;
  if (index < 0 || nextIndex < 0 || nextIndex >= currentPoints.length) return;

  const moved = currentPoints.splice(index, 1)[0];
  currentPoints.splice(nextIndex, 0, moved);
  updateSourceOrder(currentPoints);
  renderCurrentRoute();
}

function renderList(points) {
  const container = document.getElementById('listaLocales');
  const routeSelectorValue = document.getElementById('selectorRuta').value;
  const canReorder = routeSelectorValue && routeSelectorValue !== '__ALL__';
  if (!points.length) {
    container.innerHTML = '<div class="hint">No hay locales con ubicacion para mostrar.</div>';
    return;
  }

  container.innerHTML = points.map((point, index) => `
    <div class="point-row" data-point-id="${escapeHtml(point.id)}">
      <input class="row-check" type="checkbox" data-select="${escapeHtml(point.id)}" ${selectedPointIds.has(point.id) ? 'checked' : ''}>
      <div class="point-num" style="background:${pointStatus(point).color}; box-shadow:0 0 14px ${pointStatus(point).color};">
        ${pointStatus(point).label || visibleOrder(point, index + 1)}
      </div>
      <div>
        <div class="point-title">${escapeHtml(point.nombre || `Local ${index + 1}`)}</div>
        <div class="point-meta">
          Ruta ${escapeHtml(point.grupoRuta || 'SIN RUTA')}<br>
          Estado ${escapeHtml(pointStatus(point).title)}<br>
          Codigo ${escapeHtml(point.codigo || '-')}<br>
          ${escapeHtml(point.direccion || '')}<br>
          ${escapeHtml(point.comuna || '')}
        </div>
      </div>
      <div class="move-buttons">
        <button type="button" data-move="${escapeHtml(point.id)}" data-dir="-1" ${!canReorder || index === 0 ? 'disabled' : ''}>
          <i class="fa fa-arrow-up"></i>
        </button>
        <button type="button" data-move="${escapeHtml(point.id)}" data-dir="1" ${!canReorder || index === points.length - 1 ? 'disabled' : ''}>
          <i class="fa fa-arrow-down"></i>
        </button>
      </div>
    </div>
  `).join('');

  container.querySelectorAll('.point-row').forEach(row => {
    row.addEventListener('click', event => {
      if (event.target.closest('button') || event.target.closest('input')) return;
      focusPoint(row.dataset.pointId);
    });
  });

  container.querySelectorAll('[data-select]').forEach(check => {
    check.addEventListener('change', () => {
      if (check.checked) {
        selectedPointIds.add(check.dataset.select);
      } else {
        selectedPointIds.delete(check.dataset.select);
      }
      updateSelectionUi();
    });
  });

  container.querySelectorAll('[data-move]').forEach(button => {
    button.addEventListener('click', event => {
      event.stopPropagation();
      movePoint(button.dataset.move, Number(button.dataset.dir));
    });
  });
  updateSelectionUi();
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

function resetVisualization(message) {
  clearMap();
  renderList([]);
  selectedPointIds.clear();
  updateSelectionUi();
  updateStats(loadedPoints.length, 0, 0);
  document.getElementById('mapHint').style.display = 'block';
  setStatus(message || 'Selecciona usuario, fecha y ruta.');
}

function populateUserSelector() {
  const selector = document.getElementById('selectorUsuario');
  const counts = new Map();
  loadedPoints.forEach(point => {
    counts.set(point.usuarioNombre, (counts.get(point.usuarioNombre) || 0) + 1);
  });

  selector.innerHTML = '<option value="">Seleccionar usuario</option>';
  [...counts.entries()].sort((a, b) => a[0].localeCompare(b[0], 'es')).forEach(([user, count]) => {
    const option = document.createElement('option');
    option.value = user;
    option.textContent = `${user} (${count})`;
    selector.appendChild(option);
  });
  selector.disabled = counts.size === 0;
}

function populateRouteSelector(user) {
  const selector = document.getElementById('selectorRuta');
  const counts = new Map();
  loadedPoints
    .filter(point => point.usuarioNombre === user)
    .forEach(point => {
      counts.set(point.grupoRuta, (counts.get(point.grupoRuta) || 0) + 1);
    });

  selector.innerHTML = '<option value="">Seleccionar ruta</option>';
  if (counts.size > 1) {
    const total = [...counts.values()].reduce((acc, count) => acc + count, 0);
    const option = document.createElement('option');
    option.value = '__ALL__';
    option.textContent = `TODAS LAS RUTAS (${total})`;
    selector.appendChild(option);
  }
  [...counts.entries()].sort((a, b) => a[0].localeCompare(b[0], 'es')).forEach(([route, count]) => {
    const option = document.createElement('option');
    option.value = route;
    option.textContent = `${route} (${count})`;
    selector.appendChild(option);
  });
  selector.disabled = counts.size === 0;
}

function selectedRoutePoints() {
  const user = document.getElementById('selectorUsuario').value;
  const route = document.getElementById('selectorRuta').value;

  return loadedPoints
    .filter(point => point.usuarioNombre === user)
    .filter(point => route === '__ALL__' || point.grupoRuta === route)
    .filter(point => point.lat !== null && point.lng !== null)
    .sort((a, b) => a.grupoRuta.localeCompare(b.grupoRuta, 'es') || a.order - b.order);
}

function renderCurrentRoute() {
  const points = currentPoints.length ? currentPoints : selectedRoutePoints();
  if (document.getElementById('selectorRuta').value !== '__ALL__') {
    updateSourceOrder(points);
  }
  drawPoints(points);
  renderList(points);
  updateStats(selectedRoutePoints().length || points.length, points.length, Math.max(0, (selectedRoutePoints().length || points.length) - points.length));
  document.getElementById('mapHint').style.display = points.length ? 'none' : 'block';
  setStatus(points.length ? `Ruta cargada con ${points.length} locales.` : 'No hay locales con coordenadas para esta ruta.', points.length ? 'ok' : 'bad');
}

function populateModalUsers() {
  const selector = document.getElementById('modalUsuario');
  const users = [...new Set(loadedPoints.map(point => point.usuarioNombre || 'SIN USUARIO'))]
    .sort((a, b) => a.localeCompare(b, 'es'));
  const currentUser = document.getElementById('selectorUsuario').value;

  selector.innerHTML = '';
  users.forEach(user => {
    const option = document.createElement('option');
    option.value = user;
    option.textContent = user;
    selector.appendChild(option);
  });
  if (currentUser && users.includes(currentUser)) selector.value = currentUser;
  populateModalRoutes(selector.value);
}

function populateModalRoutes(userName) {
  const selector = document.getElementById('modalRuta');
  const routes = routesForUser(userName);
  const currentRoute = document.getElementById('selectorRuta').value;

  selector.innerHTML = '';
  routes.forEach(route => {
    const option = document.createElement('option');
    option.value = route;
    option.textContent = route;
    selector.appendChild(option);
  });

  const custom = document.createElement('option');
  custom.value = '__NEW__';
  custom.textContent = 'Crear nueva ruta...';
  selector.appendChild(custom);

  if (currentRoute && currentRoute !== '__ALL__' && routes.includes(currentRoute)) {
    selector.value = currentRoute;
  }
}

function openReassignModal() {
  if (!selectedPointIds.size) return;
  document.getElementById('modalSelectedCount').textContent = selectedPointIds.size;
  document.getElementById('modalOrdenInicial').value = '';
  populateModalUsers();
  $('#modalReasignar').modal('show');
}

function applyReassignment() {
  const userName = document.getElementById('modalUsuario').value;
  let routeName = document.getElementById('modalRuta').value;
  const orderRaw = document.getElementById('modalOrdenInicial').value;

  if (!userName) {
    alert('Selecciona un usuario destino.');
    return;
  }
  if (routeName === '__NEW__') {
    routeName = prompt('Nombre de la nueva ruta:', 'RUTA NUEVA');
    if (!routeName) return;
  }
  if (!routeName) {
    alert('Selecciona una ruta destino.');
    return;
  }
  if (orderRaw !== '' && (!Number.isFinite(Number(orderRaw)) || Number(orderRaw) < 1)) {
    alert('El orden inicial debe ser mayor o igual a 1.');
    return;
  }

  const selected = loadedPoints
    .filter(point => selectedPointIds.has(point.id))
    .sort((a, b) => a.order - b.order);
  const selectedSet = new Set(selected.map(point => point.id));
  const targetExisting = routePointsFor(userName, routeName)
    .filter(point => !selectedSet.has(point.id));
  const startOrder = orderRaw !== ''
    ? Number(orderRaw)
    : targetExisting.length + 1;

  selected.forEach(point => setPointUserAndRoute(point, userName, routeName));

  const before = targetExisting.filter(point => point.order < startOrder);
  const after = targetExisting.filter(point => point.order >= startOrder);
  const merged = [...before, ...selected, ...after];
  updateSourceOrder(merged);

  selectedPointIds.clear();
  populateUserSelector();
  document.getElementById('selectorUsuario').value = userName;
  populateRouteSelector(userName);
  document.getElementById('selectorRuta').value = routeName;
  currentPoints = selectedRoutePoints();
  renderCurrentRoute();
  $('#modalReasignar').modal('hide');
}

function handleRows(rows) {
  loadedPoints = rows
    .map(rowToPoint)
    .filter(point => point.nombre || point.codigo || point.direccion)
    .sort((a, b) => a.usuarioNombre.localeCompare(b.usuarioNombre, 'es')
      || String(a.fechaRuta).localeCompare(String(b.fechaRuta))
      || a.grupoRuta.localeCompare(b.grupoRuta, 'es')
      || a.order - b.order);

  populateUserSelector();
  document.getElementById('selectorRuta').innerHTML = '<option value="">Seleccionar ruta</option>';
  document.getElementById('selectorRuta').disabled = true;
  document.getElementById('btnDescargarRuta').disabled = loadedPoints.length === 0;
  document.getElementById('btnDescargarMensual').disabled = loadedPoints.length === 0;

  resetVisualization(`Archivo cargado con ${loadedPoints.length} locales. Selecciona usuario y ruta.`);
}

function downloadEditedWorkbook() {
  if (!loadedPoints.length) return;

  const headers = loadedHeaders.length
    ? loadedHeaders
    : Object.keys(loadedPoints[0].sourceRow || {});
  const rows = loadedPoints.map(point => {
    const output = {};
    headers.forEach(header => {
      output[header] = point.sourceRow[header] ?? '';
    });
    return output;
  });

  const worksheet = XLSX.utils.json_to_sheet(rows, { header: headers });
  const workbook = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(workbook, worksheet, 'Rutas Editadas');
  XLSX.writeFile(workbook, `${currentFileBaseName}_editado.xlsx`);
}

function routeSortValue(routeName, points, fallbackIndex) {
  const planDays = points
    .map(point => Number(point.diaPlan || 0))
    .filter(day => Number.isFinite(day) && day > 0);
  if (planDays.length) return Math.min(...planDays);

  const numberInRoute = String(routeName || '').match(/\d+/);
  if (numberInRoute) return Number(numberInRoute[0]);

  return fallbackIndex + 1;
}

function buildMonthlyTemplates() {
  const users = new Map();
  loadedPoints.forEach((point, index) => {
    const status = pointStatus(point);
    if (status.type !== 'ok') return;

    const user = point.usuarioNombre || 'SIN USUARIO';
    const routeName = point.grupoRuta || 'SIN RUTA';
    const normalizedRoute = normalizeHeader(routeName);
    if (!routeName || normalizedRoute === 'SIN RUTA' || normalizedRoute.includes('ERROR')) return;

    if (!users.has(user)) users.set(user, new Map());
    if (!users.get(user).has(routeName)) {
      users.get(user).set(routeName, {
        key: routeName,
        routeName,
        firstIndex: index,
        points: []
      });
    }
    users.get(user).get(routeName).points.push(point);
  });

  const output = new Map();
  users.forEach((templateMap, user) => {
    const templates = [...templateMap.values()]
      .map(template => ({
        ...template,
        sort: routeSortValue(template.routeName, template.points, template.firstIndex),
        points: template.points.slice().sort((a, b) => a.order - b.order)
      }))
      .sort((a, b) => a.sort - b.sort || a.routeName.localeCompare(b.routeName, 'es'));
    output.set(user, templates);
  });
  return output;
}

function hasRecentClientConflict(template, lastVisitByClient, date, minDays) {
  return template.points.some(point => {
    const key = point.codigo || point.nombre || point.id;
    const last = lastVisitByClient.get(key);
    if (!last) return false;
    return ((date - last) / 86400000) < minDays;
  });
}

function chooseMonthlyTemplate(templates, preferredIndex, lastVisitByClient, date) {
  if (!templates.length) return null;
  for (let offset = 0; offset < templates.length; offset++) {
    const candidate = templates[(preferredIndex + offset) % templates.length];
    if (!hasRecentClientConflict(candidate, lastVisitByClient, date, 5)) {
      return { template: candidate, nextIndex: (preferredIndex + offset + 1) % templates.length };
    }
  }
  return { template: templates[preferredIndex % templates.length], nextIndex: (preferredIndex + 1) % templates.length };
}

function monthlyRowsForTemplate(userName, template, date, dayIndex) {
  const dateSql = formatDateSql(date);
  const fechaExcel = excelDateSerial(date);
  const week = businessWeekOfMonth(date);
  const dayNum = date.getDay() === 0 ? 7 : date.getDay();
  const totalKm = estimateKm(template.points.filter(point => point.lat !== null && point.lng !== null));

  return template.points.map((point, index) => {
    const row = clonePointRow(point);
    setRowValue(row, ['Usuario Nombre', 'USUARIO NOMBRE', 'Usuario'], userName);
    setRowValue(row, ['Grupo Ruta', 'GRUPO RUTA', 'Ruta'], template.routeName || point.grupoRuta);
    setRowValue(row, ['Fecha Ruta', 'FECHA RUTA', 'Fecha'], fechaExcel);
    setRowValue(row, ['Dia Plan', 'Día Plan', 'DIA PLAN'], dayIndex + 1);
    setRowValue(row, ['Semana Plan', 'SEMANA PLAN'], week);
    setRowValue(row, ['Dia Semana Nº', 'Día Semana Nº', 'DIA SEMANA Nº'], dayNum);
    setRowValue(row, ['Dia Semana', 'Día Semana', 'DIA SEMANA'], dayNameEs(date));
    setRowValue(row, ['Orden Visita', 'ORDEN VISITA', 'Orden'], index + 1);
    setRowValue(row, ['Tamaño Ruta', 'Tamano Ruta'], template.points.length);
    setRowValue(row, ['Distancia Desde Anterior (KM)'], index === 0 ? '' : Number(distanceKm(template.points[index - 1], point).toFixed(2)));
    setRowValue(row, ['Distancia Total Ruta (KM)'], Number(totalKm.toFixed(2)));
    setRowValue(row, ['Observación', 'OBSERVACION'], `Ruta mensual ${dateSql}. Patron base ${template.key}.`);
    return row;
  });
}

function downloadMonthlyWorkbook() {
  const monthValue = document.getElementById('selectorMesMensual').value;
  if (!monthValue) {
    alert('Selecciona el mes para generar la ruta mensual.');
    return;
  }
  if (!loadedPoints.length) {
    alert('Primero debes cargar un archivo.');
    return;
  }

  const days = businessDaysInMonth(monthValue);
  if (!days.length) {
    alert('El mes seleccionado no tiene dias habiles para generar.');
    return;
  }

  const templatesByUser = buildMonthlyTemplates();
  let totalTemplates = 0;
  templatesByUser.forEach(templates => {
    totalTemplates += templates.length;
  });
  if (!totalTemplates) {
    alert('No hay rutas planificadas validas para generar mensual.');
    return;
  }

  const rows = [];

  templatesByUser.forEach((templates, userName) => {
    let templateIndex = 0;
    const lastVisitByClient = new Map();
    days.forEach((date, dayIndex) => {
      const chosen = chooseMonthlyTemplate(templates, templateIndex, lastVisitByClient, date);
      if (!chosen) return;
      templateIndex = chosen.nextIndex;
      rows.push(...monthlyRowsForTemplate(userName, chosen.template, date, dayIndex));
      chosen.template.points.forEach(point => {
        const key = point.codigo || point.nombre || point.id;
        lastVisitByClient.set(key, date);
      });
    });
  });

  const headers = loadedHeaders.length
    ? loadedHeaders
    : Object.keys(rows[0] || {});
  const normalizedRows = rows.map(row => {
    const output = {};
    headers.forEach(header => {
      output[header] = row[header] ?? '';
    });
    return output;
  });

  const worksheet = XLSX.utils.json_to_sheet(normalizedRows, { header: headers });
  const workbook = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(workbook, worksheet, 'Ruta Mensual');
  XLSX.writeFile(workbook, `${currentFileBaseName}_mensual_${monthValue}.xlsx`);
}

document.getElementById('archivoRuta').addEventListener('change', event => {
  const file = event.target.files && event.target.files[0];
  if (!file) return;

  currentFileBaseName = file.name.replace(/\.[^.]+$/, '') || 'rutas_editadas';
  setStatus('Procesando archivo...');
  const reader = new FileReader();

  reader.onload = evt => {
    try {
      const data = new Uint8Array(evt.target.result);
      const workbook = XLSX.read(data, { type: 'array' });
      const sheet = workbook.Sheets[workbook.SheetNames[0]];
      handleRows(rowsFromWorksheet(sheet));
    } catch (error) {
      console.error(error);
      setStatus('No fue posible leer el archivo. Revisa que sea Excel o CSV valido.', 'bad');
    }
  };

  reader.readAsArrayBuffer(file);
});

document.getElementById('selectorMesMensual').value = new Date().toISOString().slice(0, 7);

document.getElementById('selectorUsuario').addEventListener('change', event => {
  selectedPointIds.clear();
  currentPoints = [];
  populateRouteSelector(event.target.value);
  resetVisualization(event.target.value ? 'Selecciona una ruta.' : 'Selecciona un usuario.');
});

document.getElementById('selectorRuta').addEventListener('change', () => {
  selectedPointIds.clear();
  currentPoints = selectedRoutePoints();
  renderCurrentRoute();
});

document.getElementById('btnDescargarRuta').addEventListener('click', downloadEditedWorkbook);
document.getElementById('btnDescargarMensual').addEventListener('click', downloadMonthlyWorkbook);
document.getElementById('btnEditarSeleccion').addEventListener('click', openReassignModal);
document.getElementById('modalUsuario').addEventListener('change', event => populateModalRoutes(event.target.value));
document.getElementById('btnAplicarReasignacion').addEventListener('click', applyReassignment);

window.initMap = initMap;
</script>
<script async defer src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDO0zLDNeEdLcQgkl7dF0C0Lgr3Wl1m3cw&callback=initMap&loading=async"></script>
</body>
</html>
