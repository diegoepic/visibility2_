let mapa, markers = [];
window.activeInfoWindow = null;

function initMap() {
  mapa = new google.maps.Map(document.getElementById("map"), {
    zoom: 6,
    center: { lat: -33.4489, lng: -70.6693 },
    mapTypeId: "roadmap",
    colorScheme: google.maps.ColorScheme.LIGHT,
    streetViewControl: false,
    fullscreenControl: true,
    mapTypeControl: true,
    zoomControl: true,
    gestureHandling: "greedy"
  });

  // Validar que existan coordenadas
  if (typeof coordenadasLocales === "undefined" || !Array.isArray(coordenadasLocales)) {
    console.error("No se encontró la variable 'coordenadasLocales'");
    return;
  }

  // Crear marcadores
  coordenadasLocales.forEach((local) => {
    if (!local.latitud || !local.longitud) return;

    // El backend marca en verde los locales con al menos una gestión completada.
    const iconUrl = local.markerColor === "green"
      ? "../../images/icon/gestionadov1.png"
      : "../../images/icon/nogestionadov1.png";

    const marker = new google.maps.Marker({
      position: {
        lat: parseFloat(local.latitud),
        lng: parseFloat(local.longitud)
      },
      map: mapa,
      title: local.nombre_campana || "",
      icon: {
        url: iconUrl,
        scaledSize: new google.maps.Size(48, 48),
        // Apoyar el pie del mástil sobre la coordenada del local.
        anchor: new google.maps.Point(16, 43)
      }
    });

    const contenidoInfo = `
      <div style="min-width:230px; font-family:Calibri, sans-serif;">
        <h6 style="margin-bottom:4px; font-weight:bold; color:#2c3e50;">
          ${local.nombre_campana ?? ""}
        </h6>
        <div style="font-size:13px; color:#555;">
          <div><strong>Dirección:</strong> ${local.direccion_local ?? "-"}</div>
          <div><strong>Comuna:</strong> ${local.comuna_local ?? "-"}</div>
          <div><strong>Región:</strong> ${local.region_local ?? "-"}</div>
          <hr style="margin:6px 0; border:0; border-top:1px solid #ccc;">
          <div><strong>Estado:</strong> ${local.estado ?? "-"}</div>
          <div><strong>Fecha visita:</strong> ${local.fechaVisita ?? "-"}</div>
          <div><strong>Hora visita:</strong> ${local.horaVisita ?? "-"}</div>
        </div>
        <div style="text-align:left; margin-top:8px;">
          <button
            onclick="verDetalleLocal(${local.idLocal})"
            style="
              background-color:#2c3e50;
              color:white;
              border:none;
              padding:5px 10px;
              font-size:13px;
              border-radius:4px;
              cursor:pointer;
            ">
            Ver detalle
          </button>
        </div>
      </div>
    `;

    const infoWindow = new google.maps.InfoWindow({
      content: contenidoInfo
    });

    marker.addListener("click", () => {
      if (window.activeInfoWindow) {
        window.activeInfoWindow.close();
      }
      infoWindow.open(mapa, marker);
      window.activeInfoWindow = infoWindow;
    });

    markers.push(marker);
  });

  // Ajustar zoom al conjunto de puntos
  if (markers.length) {
    const bounds = new google.maps.LatLngBounds();
    markers.forEach((m) => bounds.extend(m.getPosition()));
    mapa.fitBounds(bounds);

    google.maps.event.addListenerOnce(mapa, "bounds_changed", function () {
      if (this.getZoom() > 16) {
        this.setZoom(16);
      }
    });
  }

  // Seguimiento del ejecutor (solo si existe)
  if (typeof idEjecutor !== "undefined" && idEjecutor > 0) {
    let ejecutorMarker = null;
    let pollHandle = null;

    function pollUbicacionEjecutor(id) {
      fetch(`poll_ubicacion.php?id_ejecutor=${id}`)
        .then((r) => r.json())
        .then((data) => {
          if (!data || !data.lat || !data.lng) return;

          const latLng = {
            lat: parseFloat(data.lat),
            lng: parseFloat(data.lng)
          };

          if (!ejecutorMarker) {
            ejecutorMarker = new google.maps.Marker({
              position: latLng,
              map: mapa,
              title: "Ubicación actual",
              icon: {
                url: "../../images/icon/marker_user.png",
                scaledSize: new google.maps.Size(36, 36)
              },
              zIndex: 999
            });
          } else {
            ejecutorMarker.setPosition(latLng);
          }
        })
        .catch((err) => {
          console.error("Error al obtener ubicación del ejecutor:", err);
        });
    }

    pollUbicacionEjecutor(idEjecutor);
    pollHandle = setInterval(() => pollUbicacionEjecutor(idEjecutor), 30000);

    document.addEventListener("visibilitychange", () => {
      if (document.hidden && pollHandle) {
        clearInterval(pollHandle);
        pollHandle = null;
      } else if (!document.hidden && !pollHandle) {
        pollUbicacionEjecutor(idEjecutor);
        pollHandle = setInterval(() => pollUbicacionEjecutor(idEjecutor), 30000);
      }
    });
  }
}

const mapaFiltrosBusqueda = window.location.search;
let detalleSolicitud = null;

function detalleElemento(tag, clase, texto) {
  const element = document.createElement(tag);
  if (clase) element.className = clase;
  if (texto !== undefined) element.textContent = texto;
  return element;
}

function detalleGaleria(body, imagenes) {
  body.replaceChildren();
  body.appendChild(detalleElemento('p', 'detalle-ayuda',
    'Historial fotográfico de las campañas y ejecutores incluidos en la búsqueda. Haz clic en una foto para verla completa.'));
  const grupos = [
    ['implementacion', 'Implementación', 'Material'],
    ['auditoria', 'Auditoría', 'Pregunta'],
    ['estado_local', 'Estado del local e impedimentos', 'Gestión']
  ];
  grupos.forEach(([tipo, titulo, contexto]) => {
    const fotos = imagenes.filter(foto => foto.tipo === tipo);
    const section = detalleElemento('section', 'detalle-evidencias');
    section.appendChild(detalleElemento('h6', 'detalle-seccion-titulo', `${titulo} · ${fotos.length} fotos`));
    if (!fotos.length) {
      section.appendChild(detalleElemento('p', 'detalle-ayuda', 'Sin fotografías registradas en esta categoría.'));
    } else {
      const grid = detalleElemento('div', 'detalle-fotos-grid');
      fotos.forEach(foto => {
        const figure = detalleElemento('figure', 'detalle-foto');
        const link = detalleElemento('a', 'detalle-foto-enlace');
        let url;
        try { url = new URL(foto.url, window.location.href); } catch (_) { return; }
        if (!['https:', 'http:'].includes(url.protocol)) return;
        link.href = url.href;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.setAttribute('aria-label', `Ampliar foto: ${foto.etiqueta}`);
        const img = detalleElemento('img', 'detalle-foto-imagen');
        img.alt = foto.etiqueta || contexto;
        img.loading = 'lazy';
        img.decoding = 'async';
        img.addEventListener('error', () => {
          link.replaceChildren(detalleElemento('span', 'detalle-foto-error', 'Imagen no disponible. Abrir original.'));
        }, { once: true });
        img.src = url.href;
        link.appendChild(img);
        figure.appendChild(link);
        const caption = detalleElemento('figcaption', 'detalle-foto-texto');
        caption.appendChild(detalleElemento('small', 'detalle-foto-tipo', contexto));
        caption.appendChild(detalleElemento('strong', 'detalle-foto-etiqueta', foto.etiqueta || 'Sin descripción'));
        caption.appendChild(detalleElemento('div', 'detalle-foto-meta', foto.campana || 'Sin campaña'));
        caption.appendChild(detalleElemento('div', 'detalle-foto-meta', `Ejecutor: ${foto.usuario || 'Sin registro'}`));
        const fecha = foto.fecha ? new Date(String(foto.fecha).replace(' ', 'T')) : null;
        const fechaTexto = fecha && !Number.isNaN(fecha.getTime())
          ? fecha.toLocaleString('es-CL') : 'Sin fecha registrada';
        caption.appendChild(detalleElemento('div', 'detalle-foto-meta', fechaTexto));
        if (foto.visita) caption.appendChild(detalleElemento('div', 'detalle-foto-meta', `Visita #${foto.visita}`));
        if (foto.observacion) caption.appendChild(detalleElemento('p', 'detalle-foto-observacion', foto.observacion));
        figure.appendChild(caption);
        grid.appendChild(figure);
      });
      section.appendChild(grid);
    }
    body.appendChild(section);
  });
}

async function verDetalleLocal(idLocal) {
  const local = coordenadasLocales.find(l => String(l.idLocal) === String(idLocal));
  if (!local) return;
  if (detalleSolicitud) detalleSolicitud.abort();
  const solicitud = new AbortController();
  detalleSolicitud = solicitud;
  if (window.activeInfoWindow) window.activeInfoWindow.close();
  document.getElementById('modalTituloLocal').textContent = local.nombre_local || 'Detalle del local';
  const header = document.getElementById('modalHeaderExtra');
  header.replaceChildren();
  header.appendChild(detalleElemento('div', '', [local.direccion_local, local.comuna_local, local.region_local].filter(Boolean).join(' · ')));
  header.appendChild(detalleElemento('div', '', `Estado: ${local.estado || '-'} · Última visita: ${local.fechaVisita || '-'}`));
  const body = document.getElementById('modalBodyDetalle');
  const loading = detalleElemento('p', 'detalle-cargando', 'Cargando fotografías y gestiones…');
  loading.setAttribute('role', 'status');
  body.replaceChildren(loading);
  $('#modalDetalle').modal('show');
  const params = new URLSearchParams(mapaFiltrosBusqueda);
  params.set('id_local', String(idLocal));
  try {
    const response = await fetch(`ajax_mapa_evidencias.php?${params}`, {
      signal: solicitud.signal, credentials: 'same-origin', headers: { Accept: 'application/json' }
    });
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudieron cargar las evidencias.');
    if (!Array.isArray(data.imagenes)) throw new Error('La respuesta de evidencias no es válida.');
    if (detalleSolicitud !== solicitud || solicitud.signal.aborted) return;
    detalleGaleria(body, data.imagenes);
  } catch (error) {
    if (solicitud.signal.aborted || detalleSolicitud !== solicitud) return;
    const message = detalleElemento('p', 'text-danger', error instanceof SyntaxError
      ? 'No se pudo cargar el detalle. Revisa la sesión y los archivos del servidor.' : error.message);
    message.setAttribute('role', 'alert');
    const retry = detalleElemento('button', 'btn btn-outline-primary btn-sm', 'Reintentar');
    retry.type = 'button';
    retry.addEventListener('click', () => verDetalleLocal(idLocal));
    body.replaceChildren(message, retry);
  }
}

$('#modalDetalle').on('hide.bs.modal', () => {
  if (detalleSolicitud) detalleSolicitud.abort();
});

function cerrarModalDetalle() {
  $('#modalDetalle').modal('hide');
}
