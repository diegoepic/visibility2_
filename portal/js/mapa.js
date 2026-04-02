let mapa;
let markers = [];
let glowMarkers = [];
let ejecutorMarker = null;
let ejecutorGlowMarker = null;
window.activeInfoWindow = null;

function initMap() {
  const darkMapStyle = [
    { elementType: "geometry", stylers: [{ color: "#071326" }] },
    { elementType: "labels.text.stroke", stylers: [{ color: "#071326" }] },
    { elementType: "labels.text.fill", stylers: [{ color: "#6f88b9" }] },

    { featureType: "administrative", elementType: "geometry", stylers: [{ color: "#1c3158" }] },
    { featureType: "administrative.country", elementType: "geometry.stroke", stylers: [{ color: "#24416d" }] },
    { featureType: "administrative.land_parcel", stylers: [{ visibility: "off" }] },
    { featureType: "administrative.locality", elementType: "labels.text.fill", stylers: [{ color: "#93b7e6" }] },

    { featureType: "poi", elementType: "labels.text.fill", stylers: [{ color: "#5d79a8" }] },
    { featureType: "poi.business", stylers: [{ visibility: "off" }] },
    { featureType: "poi.park", elementType: "geometry", stylers: [{ color: "#0d1e34" }] },
    { featureType: "poi.park", elementType: "labels.text.fill", stylers: [{ color: "#50729e" }] },

    { featureType: "road", elementType: "geometry", stylers: [{ color: "#112544" }] },
    { featureType: "road", elementType: "geometry.stroke", stylers: [{ color: "#112544" }] },
    { featureType: "road", elementType: "labels.text.fill", stylers: [{ color: "#6c86b3" }] },

    { featureType: "road.local", elementType: "labels", stylers: [{ visibility: "off" }] },

    { featureType: "road.highway", elementType: "geometry", stylers: [{ color: "#16345c" }] },
    { featureType: "road.highway", elementType: "geometry.stroke", stylers: [{ color: "#1c477f" }] },
    { featureType: "road.highway", elementType: "labels.text.fill", stylers: [{ color: "#9cc7ff" }] },

    { featureType: "transit", stylers: [{ visibility: "off" }] },

    { featureType: "water", elementType: "geometry", stylers: [{ color: "#05101f" }] },
    { featureType: "water", elementType: "labels.text.fill", stylers: [{ color: "#4f78a7" }] },
    { featureType: "water", elementType: "labels.text.stroke", stylers: [{ color: "#05101f" }] }
  ];

  const centroDefault = { lat: -33.4489, lng: -70.6693 };

  mapa = new google.maps.Map(document.getElementById("map"), {
    zoom: 10,
    center: centroDefault,
    styles: darkMapStyle,
    disableDefaultUI: true,
    zoomControl: true,
    mapTypeControl: false,
    streetViewControl: false,
    fullscreenControl: false,
    rotateControl: false,
    scaleControl: false,
    gestureHandling: "greedy",
    clickableIcons: false
  });

  if (typeof coordenadasLocales === "undefined" || !Array.isArray(coordenadasLocales)) {
    console.error("No se encontró la variable 'coordenadasLocales'");
    return;
  }

  limpiarMarcadores();
  crearMarcadoresLocales();
  ajustarVistaMapa();
  iniciarSeguimientoEjecutor();
}

function limpiarMarcadores() {
  markers.forEach(marker => marker.setMap(null));
  glowMarkers.forEach(marker => marker.setMap(null));

  markers = [];
  glowMarkers = [];
}

function obtenerColorMarcador(local) {
  const estado = String(local.estado || "").trim().toUpperCase();

  // Casos críticos
  if (["LOCAL NO EXISTE", "LOCAL CERRADO", "NO PERMITIERON", "SIN PRODUCTOS"].includes(estado)) {
    return {
      fill: "#ff4d4f",
      stroke: "#ffd6d6",
      glow: "rgba(255,77,79,0.22)"
    };
  }

  // Gestionado
  if (estado === "GESTIONADO") {
    return {
      fill: "#22c55e",
      stroke: "#dcfce7",
      glow: "rgba(34,197,94,0.22)"
    };
  }

  // Sin estado o vacío
  if (estado === "" || estado === "-" || estado === "NULL") {
    return {
      fill: "#3b82f6",
      stroke: "#dbeafe",
      glow: "rgba(59,130,246,0.22)"
    };
  }

  // Cualquier otro estado no contemplado
  return {
    fill: "#3b82f6",
    stroke: "#dbeafe",
    glow: "rgba(59,130,246,0.22)"
  };
}

function crearMarcadoresLocales() {
  coordenadasLocales.forEach((local) => {
    if (!local.latitud || !local.longitud) return;

    const lat = parseFloat(local.latitud);
    const lng = parseFloat(local.longitud);

    if (Number.isNaN(lat) || Number.isNaN(lng)) return;

    const color = obtenerColorMarcador(local);
    const position = { lat, lng };

    const glowMarker = new google.maps.Marker({
      position,
      map: mapa,
      zIndex: 1,
      clickable: false,
      icon: {
        path: google.maps.SymbolPath.CIRCLE,
        scale: 14,
        fillColor: color.fill,
        fillOpacity: 0.16,
        strokeOpacity: 0
      }
    });

    const marker = new google.maps.Marker({
      position,
      map: mapa,
      zIndex: 2,
      title: local.nombre_local || local.nombre_campana || "",
      icon: {
        path: google.maps.SymbolPath.CIRCLE,
        scale: 7,
        fillColor: color.fill,
        fillOpacity: 1,
        strokeColor: color.stroke,
        strokeWeight: 2
      }
    });

    const contenidoInfo = `
      <div class="map-info-card" style="min-width:240px; font-family:Calibri, sans-serif;">
        <div style="font-size:15px; font-weight:800; color:#10233d; margin-bottom:6px;">
          ${escapeHtml(local.nombre_local ?? local.nombre_campana ?? "Local")}
        </div>
        <div style="font-size:12px; color:#4d678c; margin-bottom:8px;">
          ${escapeHtml(local.direccion_local ?? "-")}
        </div>

        <div style="font-size:12px; color:#5b7396; line-height:1.5; margin-bottom:8px;">
          <div><strong>Comuna:</strong> ${escapeHtml(local.comuna_local ?? "-")}</div>
          <div><strong>Region:</strong> ${escapeHtml(local.region_local ?? "-")}</div>
          <div><strong>Estado:</strong> ${escapeHtml(local.estado ?? "-")}</div>
          <div><strong>Fecha visita:</strong> ${escapeHtml(local.fechaVisita ?? "-")}</div>
          <div><strong>Hora visita:</strong> ${escapeHtml(local.horaVisita ?? "-")}</div>
        </div>

        <button
          type="button"
          onclick="verDetalleLocal(${Number(local.idLocal) || 0})"
          style="
            background: linear-gradient(90deg, #11c7f3, #1fd6ff);
            color: #041225;
            border: none;
            padding: 7px 12px;
            font-size: 12px;
            font-weight: 700;
            border-radius: 8px;
            cursor: pointer;
            box-shadow: 0 6px 14px rgba(17, 199, 243, 0.22);
          ">
          Ver detalle
        </button>
      </div>
    `;

    const infoWindow = new google.maps.InfoWindow({
      content: contenidoInfo
    });

    marker.addListener("click", () => {
      if (window.activeInfoWindow) {
        window.activeInfoWindow.close();
      }

      infoWindow.open({
        anchor: marker,
        map: mapa,
        shouldFocus: false
      });

      window.activeInfoWindow = infoWindow;
    });

    markers.push(marker);
    glowMarkers.push(glowMarker);
  });
}

function ajustarVistaMapa() {
  if (!markers.length) return;

  const bounds = new google.maps.LatLngBounds();

  markers.forEach((marker) => {
    const pos = marker.getPosition();
    if (pos) bounds.extend(pos);
  });

  mapa.fitBounds(bounds, 80);

  google.maps.event.addListenerOnce(mapa, "bounds_changed", function () {
    if (this.getZoom() > 16) this.setZoom(16);
    if (this.getZoom() < 6) this.setZoom(6);
  });
}

function iniciarSeguimientoEjecutor() {
  if (typeof idEjecutor === "undefined" || Number(idEjecutor) <= 0) return;

  let pollHandle = null;

  function actualizarUbicacionEjecutor(id) {
    fetch(`poll_ubicacion.php?id_ejecutor=${encodeURIComponent(id)}`)
      .then((r) => r.json())
      .then((data) => {
        if (!data || !data.lat || !data.lng) return;

        const lat = parseFloat(data.lat);
        const lng = parseFloat(data.lng);

        if (Number.isNaN(lat) || Number.isNaN(lng)) return;

        const latLng = { lat, lng };

        if (!ejecutorGlowMarker) {
          ejecutorGlowMarker = new google.maps.Marker({
            position: latLng,
            map: mapa,
            zIndex: 998,
            clickable: false,
            icon: {
              path: google.maps.SymbolPath.CIRCLE,
              scale: 16,
              fillColor: "#ffffff",
              fillOpacity: 0.18,
              strokeOpacity: 0
            }
          });
        } else {
          ejecutorGlowMarker.setPosition(latLng);
        }

        if (!ejecutorMarker) {
          ejecutorMarker = new google.maps.Marker({
            position: latLng,
            map: mapa,
            title: "Ubicación actual",
            zIndex: 999,
            icon: {
              path: google.maps.SymbolPath.CIRCLE,
              scale: 8,
              fillColor: "#ffffff",
              fillOpacity: 1,
              strokeColor: "#14d7ff",
              strokeWeight: 3
            }
          });
        } else {
          ejecutorMarker.setPosition(latLng);
        }
      })
      .catch((err) => {
        console.error("Error al obtener ubicación del ejecutor:", err);
      });
  }

  actualizarUbicacionEjecutor(idEjecutor);
  pollHandle = setInterval(() => actualizarUbicacionEjecutor(idEjecutor), 30000);

  document.addEventListener("visibilitychange", () => {
    if (document.hidden && pollHandle) {
      clearInterval(pollHandle);
      pollHandle = null;
    } else if (!document.hidden && !pollHandle) {
      actualizarUbicacionEjecutor(idEjecutor);
      pollHandle = setInterval(() => actualizarUbicacionEjecutor(idEjecutor), 30000);
    }
  });
}

function verDetalleLocal(idLocal) {
  const local = coordenadasLocales.find(l => Number(l.idLocal) === Number(idLocal));
  if (!local) return;

  const estadoRaw = String(local.estado || "").trim().toUpperCase();
  let estadoClass = "modal-status-neutral";

  if (["LOCAL NO EXISTE", "LOCAL CERRADO", "NO PERMITIERON"].includes(estadoRaw)) {
    estadoClass = "modal-status-bad";
  } else if (estadoRaw === "GESTIONADO") {
    estadoClass = "modal-status-ok";
  }

  document.getElementById("modalTituloLocal").textContent = local.nombre_campana || "-";

  document.getElementById("modalHeaderExtra").innerHTML = `
    <div><strong>LOCAL:</strong> ${escapeHtml(local.nombre_local ?? "-")}</div>
    <div><strong>DIRECCION:</strong> ${escapeHtml(local.direccion_local ?? "-")}</div>
    <div><strong>COMUNA:</strong> ${escapeHtml(local.comuna_local ?? "-")}</div>
    <div><strong>REGION:</strong> ${escapeHtml(local.region_local ?? "-")}</div>
  `;

  document.getElementById("modalBodyDetalle").innerHTML = `
    <div class="container-fluid px-0 mt-1">
      <div class="row">
        <div class="col-12 col-md-4 mb-3">
          <div class="modal-kpi-card text-center">
            <div class="modal-kpi-label">Ultima visita</div>
            <div class="modal-kpi-value">${escapeHtml(local.fechaVisita ?? "-")}</div>
            <div class="modal-kpi-subvalue">${escapeHtml(local.horaVisita ?? "-")}</div>
          </div>
        </div>

        <div class="col-12 col-md-4 mb-3">
          <div class="modal-kpi-card text-center">
            <div class="modal-kpi-label">Estado</div>
            <div class="mt-2">
              <span class="modal-status-pill ${estadoClass}">
                ${escapeHtml(local.estado ?? "-")}
              </span>
            </div>
          </div>
        </div>

        <div class="col-12 col-md-4 mb-3">
          <div class="modal-kpi-card text-center">
            <div class="modal-kpi-label">Tipo de gestion</div>
            <div class="modal-kpi-value" style="font-size:16px;">
              ${escapeHtml(local.modalidad ?? "-")}
            </div>
          </div>
        </div>
      </div>

      <div class="modal-section-card mt-2">
        <div class="modal-section-header">Gestion</div>
        <div class="modal-section-body">
          <div><strong>Campaña:</strong> ${escapeHtml(local.nombre_campana ?? "-")}</div>
          <div><strong>Merchan:</strong> ${escapeHtml(local.usuario_local ?? "-")}</div>
          <div><strong>Observación:</strong> ${escapeHtml(local.observacion ?? "-")}</div>
        </div>
      </div>
    </div>
  `;

  $('#modalDetalle').modal('show');
}

function cerrarModalDetalle() {
  $('#modalDetalle').modal('hide');
}

function escapeHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}