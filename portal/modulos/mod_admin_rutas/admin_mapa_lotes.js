let map;
let markers = [];
let overlayProjection = null;
let selectedKeys = new Set();
let selectionMode = false;
let isDragging = false;
let dragStart = null;

const markerByKey = new Map();
const dataByKey = new Map();

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

function colorMarker(item, selected = false) {
  if (selected) {
    return '#16d7ff';
  }

  const estado = estadoLocal(item);

  if (estado === 'visitado') {
    return '#37e7b2';
  }

  if (estado === 'gestionado') {
    return '#ffcf4d';
  }

  return '#7f97c6';
}

function buildSvgIcon(color) {
  const svg = `
    <svg xmlns="http://www.w3.org/2000/svg" width="34" height="44" viewBox="0 0 34 44">
      <path d="M17 0C7.6 0 0 7.6 0 17c0 12.8 17 27 17 27s17-14.2 17-27C34 7.6 26.4 0 17 0z"
            fill="${color}" />
      <circle cx="17" cy="17" r="7" fill="#041225" opacity="0.95"/>
    </svg>
  `;

  return {
    url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg),
    scaledSize: new google.maps.Size(34, 44),
    anchor: new google.maps.Point(17, 44)
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
      icon: buildSvgIcon(colorMarker(item)),
      title: `${item.codigo || ''} - ${item.nombre_local || ''}`
    });

    marker.addListener('click', () => {
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

  marker.setIcon(buildSvgIcon(colorMarker(item, selectedKeys.has(key))));
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
      ? '<i class="fa fa-check-square mr-2"></i> Selección activa'
      : '<i class="fa fa-vector-square mr-2"></i> Modo selección';

    map.setOptions({
      draggable: !selectionMode,
      scrollwheel: !selectionMode,
      disableDoubleClickZoom: selectionMode
    });
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