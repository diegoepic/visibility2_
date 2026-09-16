let map;
let markers = [];
let overlayProjection = null;
let selectedKeys = new Set();
let selectionMode = false;
let isDragging = false;
let dragStart = null;
let dropTargetKey = null;
let suppressMarkerClickUntil = 0;
let moveInProgress = false;
let highlightedPlanningDate = '';

const markerByKey = new Map();
const dataByKey = new Map();
const routeColors = new Map();
const routePalette = [
  '#2563eb', '#db2777', '#7c3aed', '#ea580c',
  '#0891b2', '#65a30d', '#dc2626', '#4f46e5',
  '#c026d3', '#0d9488', '#ca8a04', '#9333ea'
];

function getKey(item) {
  return `${item.id_formulario}_${item.id_local}`;
}

function estadoLocal(item) {
  const visitado = item.fechaVisita && item.fechaVisita !== '0000-00-00 00:00:00';

  if (visitado) {
    return 'visitado';
  }

  const pregunta = String(item.pregunta || '').toUpperCase();

  if (['AUDITORIA', 'IMPLEMENTACION', 'IMPL/AUD'].includes(pregunta)) {
    return 'gestionado';
  }

  return 'pendiente';
}

function statusColor(item) {
  const estado = estadoLocal(item);

  if (estado === 'visitado') {
    return '#37e7b2';
  }

  if (estado === 'gestionado') {
    return '#ffcf4d';
  }

  return '#7f97c6';
}

function normalizedDate(value) {
  const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!match || match[1] === '0000') return '';

  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  const parsed = new Date(Date.UTC(year, month - 1, day));

  if (
    parsed.getUTCFullYear() !== year ||
    parsed.getUTCMonth() !== month - 1 ||
    parsed.getUTCDate() !== day
  ) return '';

  return `${match[1]}-${match[2]}-${match[3]}`;
}

function dateLabel(value) {
  const date = normalizedDate(value);
  if (!date) return 'SIN FECHA';
  const [year, month, day] = date.split('-');
  return `${day}/${month}/${year.slice(2)}`;
}

function routeKey(item) {
  return [
    parseInt(item.id_formulario, 10) || 0,
    parseInt(item.id_usuario, 10) || 0,
    normalizedDate(item.fechaPropuesta) || 'sin-fecha'
  ].join('_');
}

function routeColor(item) {
  const key = routeKey(item);
  if (!routeColors.has(key)) {
    routeColors.set(key, routePalette[routeColors.size % routePalette.length]);
  }
  return routeColors.get(key);
}

function escapeXml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&apos;');
}

function buildSvgIcon(item, options = {}) {
  const color = routeColor(item);
  const selected = Boolean(options.selected);
  const dropTarget = Boolean(options.dropTarget);
  const stroke = dropTarget ? '#ffffff' : (selected ? '#16d7ff' : '#dbeafe');
  const strokeWidth = dropTarget || selected ? 4 : 2;
  const label = escapeXml(dateLabel(item.fechaPropuesta));
  const centerColor = statusColor(item);
  const svg = `
    <svg xmlns="http://www.w3.org/2000/svg" width="82" height="68" viewBox="0 0 82 68">
      <rect x="2" y="1" width="78" height="22" rx="7" fill="#07162d" stroke="${color}" stroke-width="2"/>
      <text x="41" y="16" fill="#ffffff" font-family="Arial, sans-serif" font-size="11" font-weight="700" text-anchor="middle">${label}</text>
      <path d="M41 24C31.6 24 24 31.6 24 41c0 12.8 17 27 17 27s17-14.2 17-27c0-9.4-7.6-17-17-17z"
            fill="${color}" stroke="${stroke}" stroke-width="${strokeWidth}" />
      <circle cx="41" cy="41" r="7" fill="${centerColor}" stroke="#041225" stroke-width="2"/>
    </svg>
  `;

  return {
    url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg),
    scaledSize: new google.maps.Size(82, 68),
    anchor: new google.maps.Point(41, 68)
  };
}

function initMap() {
  const defaultCenter = { lat: -33.4489, lng: -70.6693 };

  map = new google.maps.Map(document.getElementById('map'), {
    center: defaultCenter,
    zoom: 11,
    mapTypeControl: false,
    streetViewControl: false,
    fullscreenControl: true,
  });

  const bounds = new google.maps.LatLngBounds();

  const projectionOverlay = new google.maps.OverlayView();
  projectionOverlay.onAdd = function () {};
  projectionOverlay.draw = function () {
    overlayProjection = this.getProjection();
  };
  projectionOverlay.onRemove = function () {};
  projectionOverlay.setMap(map);

  coordenadasLocales.forEach(item => {
    const lat = parseFloat(item.lat);
    const lng = parseFloat(item.lng);

    if (!lat || !lng) {
      return;
    }

    const key = getKey(item);
    const position = { lat, lng };

    const marker = new google.maps.Marker({
      position,
      map,
      icon: buildSvgIcon(item),
      title: `${item.codigo || ''} - ${item.nombre_local || ''}`,
      draggable: false,
      optimized: false
    });

    marker.addListener('click', () => {
      if (Date.now() < suppressMarkerClickUntil) return;
      toggleMarker(key);
    });

    const info = new google.maps.InfoWindow({
      content: `
        <div style="font-size:13px; color:#111; min-width:230px;">
          <strong>${escapeHtml(item.nombre_local || '-')}</strong><br>
          Código: ${escapeHtml(item.codigo || '-')}<br>
          Usuario: ${escapeHtml(item.usuario_local || '-')}<br>
          Fecha propuesta: ${escapeHtml(item.fechaPropuesta || '-')}
        </div>
      `
    });

    marker.addListener('mouseover', () => info.open(map, marker));
    marker.addListener('mouseout', () => info.close());
    initMarkerDrag(key, marker, item, info, position);

    markerByKey.set(key, marker);
    dataByKey.set(key, item);
    markers.push({ key, marker, item });

    bounds.extend(position);
  });

  if (markers.length > 0) {
    map.fitBounds(bounds);
  }

  initSelectionRectangle();
  initAdminActions();
  cargarUsuariosAccion();
  renderPlannedDays();
  renderRouteLegend();
  updateSelectedUI();
}

function toggleMarker(key) {
  if (selectedKeys.has(key)) {
    selectedKeys.delete(key);
  } else {
    selectedKeys.add(key);
  }

  refreshMarker(key);
  updateSelectedUI();
}

function selectMarker(key) {
  selectedKeys.add(key);
  refreshMarker(key);
}

function unselectMarker(key) {
  selectedKeys.delete(key);
  refreshMarker(key);
  updateSelectedUI();
}

function refreshMarker(key) {
  const marker = markerByKey.get(key);
  const item = dataByKey.get(key);

  if (!marker || !item) {
    return;
  }

  marker.setIcon(buildSvgIcon(item, {
    selected: selectedKeys.has(key),
    dropTarget: dropTargetKey === key
  }));
  const isHighlighted = !highlightedPlanningDate || normalizedDate(item.fechaPropuesta) === highlightedPlanningDate;
  marker.setOpacity(isHighlighted ? 1 : 0.2);
  marker.setZIndex(isHighlighted ? 500 : 1);
  const draggable = (
    !selectionMode &&
    !moveInProgress &&
    selectedKeys.has(key) &&
    estadoLocal(item) !== 'visitado'
  );
  marker.setDraggable(draggable);
  marker.setCursor(draggable ? 'grab' : 'pointer');
}

function planningDayLabel(date) {
  if (!date) return 'SIN FECHA';
  return dateLabel(date);
}

function renderPlannedDays() {
  const container = document.getElementById('plannedDaysList');
  if (!container) return;

  const counts = new Map();
  coordenadasLocales.forEach(item => {
    const date = normalizedDate(item.fechaPropuesta);
    if (!date) return;
    counts.set(date, (counts.get(date) || 0) + 1);
  });

  const dates = Array.from(counts.keys()).sort((left, right) => left.localeCompare(right));
  if (dates.length === 0) {
    container.innerHTML = '<div class="route-legend-help">No hay días planificados para los filtros actuales.</div>';
    return;
  }

  const total = Array.from(counts.values()).reduce((sum, count) => sum + count, 0);
  const days = [{ value: '', label: 'Todos', count: total }].concat(
    dates.map(date => ({ value: date, label: planningDayLabel(date), count: counts.get(date) }))
  );

  container.innerHTML = days.map(day => `
    <button type="button"
            class="planned-day-btn${highlightedPlanningDate === day.value ? ' active' : ''}"
            data-planning-date="${day.value}"
            title="${day.value ? escapeHtml(day.value) : 'Mostrar todos los días con la misma intensidad'}">
      <span class="day-label">${escapeHtml(day.label)}</span>
      <span class="day-count">${day.count} local${day.count === 1 ? '' : 'es'}</span>
    </button>
  `).join('');

  container.querySelectorAll('.planned-day-btn').forEach(button => {
    button.addEventListener('click', () => {
      highlightedPlanningDate = button.dataset.planningDate || '';
      renderPlannedDays();
      refreshPlanningHighlight();
    });
  });
}

function refreshPlanningHighlight() {
  markers.forEach(({ key }) => refreshMarker(key));

  document.querySelectorAll('.route-legend-item[data-route-date]').forEach(item => {
    const active = !highlightedPlanningDate || item.dataset.routeDate === highlightedPlanningDate;
    item.style.opacity = active ? '1' : '0.28';
  });
}

function renderRouteLegend() {
  const container = document.getElementById('routeLegendItems');
  if (!container) return;

  const routes = new Map();
  coordenadasLocales.forEach(item => {
    const key = routeKey(item);
    if (!routes.has(key)) {
      routes.set(key, {
        color: routeColor(item),
        usuario: item.usuario_local || 'SIN USUARIO',
        fecha: dateLabel(item.fechaPropuesta),
        date: normalizedDate(item.fechaPropuesta),
        campana: item.nombre_campana || 'SIN CAMPAÑA'
      });
    }
  });

  if (routes.size === 0) {
    container.innerHTML = '<div class="route-legend-help">No hay rutas para los filtros actuales.</div>';
    return;
  }

  const maxVisible = 18;
  const items = Array.from(routes.values());
  container.innerHTML = items.slice(0, maxVisible).map(route => `
    <div class="route-legend-item" data-route-date="${escapeHtml(route.date)}" title="${escapeHtml(route.campana)}">
      <span class="route-legend-swatch" style="background:${route.color}"></span>
      <span class="route-legend-label">${escapeHtml(route.fecha)} · ${escapeHtml(route.usuario)}</span>
    </div>
  `).join('') + (items.length > maxVisible
    ? `<div class="route-legend-help">Y ${items.length - maxVisible} ruta(s) adicionales.</div>`
    : '');

  refreshPlanningHighlight();
}

function nearestDropTarget(sourceKey, sourceMarker, maxDistancePx = 62) {
  if (!overlayProjection) return null;

  const sourcePoint = overlayProjection.fromLatLngToContainerPixel(sourceMarker.getPosition());
  let nearest = null;
  let nearestDistance = maxDistancePx;

  markers.forEach(({ key, marker, item }) => {
    if (key === sourceKey || selectedKeys.has(key) || !marker.getVisible()) return;

    const point = overlayProjection.fromLatLngToContainerPixel(marker.getPosition());
    const distance = Math.hypot(point.x - sourcePoint.x, point.y - sourcePoint.y);

    if (distance < nearestDistance) {
      nearestDistance = distance;
      nearest = { key, marker, item };
    }
  });

  return nearest;
}

function setDropTarget(key) {
  if (dropTargetKey === key) return;

  const previousKey = dropTargetKey;
  dropTargetKey = key;

  if (previousKey) refreshMarker(previousKey);
  if (dropTargetKey) refreshMarker(dropTargetKey);
}

function initMarkerDrag(key, marker, item, info, originalPosition) {
  if (estadoLocal(item) === 'visitado') return;

  marker.addListener('dragstart', () => {
    info.close();
    marker.setZIndex(google.maps.Marker.MAX_ZINDEX + 10);
    setDropTarget(null);
  });

  marker.addListener('drag', () => {
    const target = nearestDropTarget(key, marker);
    setDropTarget(target ? target.key : null);
  });

  marker.addListener('dragend', () => {
    const targetKey = dropTargetKey;
    const targetItem = targetKey ? dataByKey.get(targetKey) : null;

    marker.setPosition(originalPosition);
    marker.setZIndex(undefined);
    suppressMarkerClickUntil = Date.now() + 400;
    setDropTarget(null);

    if (!targetItem || moveInProgress) return;
    solicitarMoverPlanificacion(item, targetItem);
  });
}

function solicitarMoverPlanificacion(source, target) {
  const sourceKey = getKey(source);
  const selectedSources = (selectedKeys.has(sourceKey)
    ? Array.from(selectedKeys).map(key => dataByKey.get(key))
    : [source]
  ).filter(item => item && estadoLocal(item) !== 'visitado');

  if (selectedSources.length === 0) {
    alert('La selección no contiene locales pendientes que se puedan mover.');
    return;
  }

  if (selectedSources.some(item => parseInt(item.id_formulario, 10) !== parseInt(target.id_formulario, 10))) {
    alert('Sólo puedes arrastrar locales entre planificaciones de la misma campaña o ruta.');
    return;
  }

  if (!normalizedDate(target.fechaPropuesta) || !(parseInt(target.id_usuario, 10) > 0)) {
    alert('El local de destino no tiene una fecha y un usuario válidos para copiar su planificación.');
    return;
  }

  if (selectedSources.every(item => routeKey(item) === routeKey(target))) {
    alert('Los locales seleccionados ya pertenecen a la planificación de destino.');
    return;
  }

  const count = selectedSources.length;
  const ok = confirm(
    `Mover ${count} local${count === 1 ? '' : 'es'} a la planificación de ${target.codigo || target.nombre_local || 'destino'}:\n\n` +
    `Usuario: ${target.usuario_local || '-'}\n` +
    `Fecha propuesta: ${dateLabel(target.fechaPropuesta)}\n\n` +
    'Sólo se modificarán registros pendientes/no visitados. ¿Deseas continuar?'
  );

  if (!ok) return;

  moveInProgress = true;
  markers.forEach(({ marker: currentMarker }) => {
    currentMarker.setDraggable(false);
    currentMarker.setCursor('wait');
  });

  fetch('ajax_admin_mapa_acciones.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      accion: 'mover_planificacion',
      seleccionados: selectedSources.map(item => ({
        id_formulario: parseInt(item.id_formulario, 10),
        id_local: parseInt(item.id_local, 10)
      })),
      destino: {
        id_formulario: parseInt(target.id_formulario, 10),
        id_local: parseInt(target.id_local, 10)
      }
    })
  })
    .then(async response => {
      const data = await response.json();
      if (!response.ok || !data.ok) {
        throw new Error(data.message || 'No se pudo mover el local.');
      }
      alert(data.message || 'Local incorporado a la planificación.');
      location.reload();
    })
    .catch(error => {
      console.error(error);
      alert(error.message || 'Error de comunicación con el servidor.');
      moveInProgress = false;
      markers.forEach(({ key }) => refreshMarker(key));
    });
}

function updateSelectedUI() {
  const contador = document.getElementById('contadorSeleccionados');
  const lista = document.getElementById('listaSeleccionados');

  contador.textContent = selectedKeys.size;

  if (selectedKeys.size === 0) {
    lista.innerHTML = 'No hay locales seleccionados.';
    return;
  }

  let html = '';

  selectedKeys.forEach(key => {
    const item = dataByKey.get(key);

    if (!item) {
      return;
    }

    html += `
      <span class="selected-chip">
        ${escapeHtml(item.codigo || '-')} - ${escapeHtml(item.nombre_local || '-')}
        <button type="button" onclick="unselectMarker('${key}')">×</button>
      </span>
    `;
  });

  lista.innerHTML = html;
}

function initSelectionRectangle() {
  const mapDiv = document.getElementById('map');
  const selectionBox = document.getElementById('selectionBox');
  const btnModo = document.getElementById('btnModoSeleccion');

  btnModo.addEventListener('click', () => {
    selectionMode = !selectionMode;
    btnModo.classList.toggle('success', selectionMode);
    btnModo.classList.toggle('secondary', !selectionMode);
    btnModo.innerHTML = selectionMode
      ? '<i class="fa fa-check mr-2"></i> Finalizar selección'
      : '<i class="fa fa-vector-square mr-2"></i> Modo selección';

    map.setOptions({
      draggable: !selectionMode,
      scrollwheel: !selectionMode,
      disableDoubleClickZoom: selectionMode
    });

    markers.forEach(({ key }) => refreshMarker(key));
  });

  mapDiv.addEventListener('mousedown', e => {
    if (!selectionMode || e.button !== 0) {
      return;
    }

    isDragging = true;

    const rect = mapDiv.getBoundingClientRect();

    dragStart = {
      x: e.clientX,
      y: e.clientY,
      mapLeft: rect.left,
      mapTop: rect.top
    };

    selectionBox.style.display = 'block';
    selectionBox.style.left = `${e.clientX}px`;
    selectionBox.style.top = `${e.clientY}px`;
    selectionBox.style.width = '0px';
    selectionBox.style.height = '0px';

    e.preventDefault();
  });

  document.addEventListener('mousemove', e => {
    if (!isDragging || !dragStart) {
      return;
    }

    const x1 = Math.min(dragStart.x, e.clientX);
    const y1 = Math.min(dragStart.y, e.clientY);
    const x2 = Math.max(dragStart.x, e.clientX);
    const y2 = Math.max(dragStart.y, e.clientY);

    selectionBox.style.left = `${x1}px`;
    selectionBox.style.top = `${y1}px`;
    selectionBox.style.width = `${x2 - x1}px`;
    selectionBox.style.height = `${y2 - y1}px`;
  });

  document.addEventListener('mouseup', e => {
    if (!isDragging || !dragStart) {
      return;
    }

    isDragging = false;

    const x1 = Math.min(dragStart.x, e.clientX);
    const y1 = Math.min(dragStart.y, e.clientY);
    const x2 = Math.max(dragStart.x, e.clientX);
    const y2 = Math.max(dragStart.y, e.clientY);

    selectMarkersInsideBox(x1, y1, x2, y2);

    selectionBox.style.display = 'none';
    dragStart = null;
    updateSelectedUI();
  });
}

function selectMarkersInsideBox(x1, y1, x2, y2) {
  if (!overlayProjection) {
    return;
  }

  const mapDiv = document.getElementById('map');
  const rect = mapDiv.getBoundingClientRect();

  markers.forEach(({ key, marker }) => {
    const point = overlayProjection.fromLatLngToContainerPixel(marker.getPosition());

    const pageX = rect.left + point.x;
    const pageY = rect.top + point.y;

    if (pageX >= x1 && pageX <= x2 && pageY >= y1 && pageY <= y2) {
      selectMarker(key);
    }
  });
}

function initAdminActions() {
  document.getElementById('btnLimpiarSeleccion').addEventListener('click', () => {
    selectedKeys.forEach(key => refreshMarker(key));
    selectedKeys.clear();
    markers.forEach(({ key }) => refreshMarker(key));
    updateSelectedUI();
  });

  document.getElementById('btnReasignarUsuario').addEventListener('click', () => {
    const idUsuario = document.getElementById('nuevoUsuario').value;

    if (!idUsuario) {
      alert('Debes seleccionar un usuario.');
      return;
    }

    ejecutarAccionMasiva('reasignar_usuario', {
      id_usuario: idUsuario
    });
  });

  document.getElementById('btnCambiarFecha').addEventListener('click', () => {
    const fecha = document.getElementById('nuevaFechaPropuesta').value;

    if (!fecha) {
      alert('Debes seleccionar una fecha propuesta.');
      return;
    }

    ejecutarAccionMasiva('cambiar_fecha', {
      fechaPropuesta: fecha
    });
  });

  document.getElementById('btnEliminarLocales').addEventListener('click', () => {
    if (selectedKeys.size === 0) {
      alert('Debes seleccionar al menos un local.');
      return;
    }

    const ok = confirm(
      `Vas a eliminar ${selectedKeys.size} local(es) desde formularioQuestion.\n\n` +
      `Por seguridad, el backend solo eliminará registros pendientes/no visitados.\n\n` +
      `¿Deseas continuar?`
    );

    if (!ok) {
      return;
    }

    ejecutarAccionMasiva('eliminar');
  });
}

function getSelectedPayload() {
  const items = [];

  selectedKeys.forEach(key => {
    const item = dataByKey.get(key);

    if (!item) {
      return;
    }

    items.push({
      id_formulario: parseInt(item.id_formulario, 10),
      id_local: parseInt(item.id_local, 10)
    });
  });

  return items;
}

function ejecutarAccionMasiva(accion, extra = {}) {
  const seleccionados = getSelectedPayload();

  if (seleccionados.length === 0) {
    alert('Debes seleccionar al menos un local.');
    return;
  }

  fetch('ajax_admin_mapa_acciones.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      accion,
      seleccionados,
      ...extra
    })
  })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) {
        alert(data.message || 'No se pudo ejecutar la acción.');
        return;
      }

      alert(data.message || 'Acción realizada correctamente.');
      location.reload();
    })
    .catch(err => {
      console.error(err);
      alert('Error de comunicación con el servidor.');
    });
}

function cargarUsuariosAccion() {
  const select = document.getElementById('nuevoUsuario');

  fetch('ajax_admin_mapa_usuarios.php')
    .then(r => r.json())
    .then(data => {
      if (!data.ok) {
        return;
      }

      data.usuarios.forEach(u => {
        const opt = new Option(`${u.nombre} ${u.apellido} (${u.usuario})`, u.id);
        select.add(opt);
      });
    })
    .catch(err => console.error('Error cargando usuarios:', err));
}

function escapeHtml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
