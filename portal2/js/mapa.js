let mapa, markers = [];
window.activeInfoWindow = null;

function initMap() {
  const mapStyles = [
    {
      featureType: "administrative",
      elementType: "geometry.stroke",
      stylers: [{ color: "#c9d3db" }]
    },
    {
      featureType: "administrative.land_parcel",
      elementType: "geometry.stroke",
      stylers: [{ color: "#dcdfe3" }]
    },
    {
      featureType: "landscape",
      elementType: "geometry",
      stylers: [{ color: "#eef3e6" }]
    },
    {
      featureType: "poi",
      elementType: "geometry",
      stylers: [{ color: "#dfead7" }]
    },
    {
      featureType: "poi.park",
      elementType: "geometry",
      stylers: [{ color: "#cfe8b4" }]
    },
    {
      featureType: "road",
      elementType: "geometry",
      stylers: [{ color: "#ffffff" }]
    },
    {
      featureType: "road.arterial",
      elementType: "geometry",
      stylers: [{ color: "#f8f1d7" }]
    },
    {
      featureType: "road.highway",
      elementType: "geometry",
      stylers: [{ color: "#f4c46a" }]
    },
    {
      featureType: "road.highway",
      elementType: "geometry.stroke",
      stylers: [{ color: "#e2a93b" }]
    },
    {
      featureType: "transit",
      elementType: "geometry",
      stylers: [{ color: "#dfe5ea" }]
    },
    {
      featureType: "water",
      elementType: "geometry",
      stylers: [{ color: "#9fd3f2" }]
    },
    {
      featureType: "water",
      elementType: "labels.text.fill",
      stylers: [{ color: "#2d6f91" }]
    },
    {
      elementType: "labels.text.fill",
      stylers: [{ color: "#3f4a54" }]
    },
    {
      elementType: "labels.text.stroke",
      stylers: [{ color: "#ffffff" }]
    },
    {
      featureType: "poi",
      elementType: "labels.icon",
      stylers: [{ visibility: "off" }]
    }
  ];

  mapa = new google.maps.Map(document.getElementById("map"), {
    zoom: 6,
    center: { lat: -33.4489, lng: -70.6693 },
    mapTypeId: "roadmap",
    styles: mapStyles,
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

    let iconUrl = "../../images/icon/GMC_R.png";

    if (local.markerColor === "orange") {
      iconUrl = "../../images/icon/GMC_G.png";
    } else if (local.markerColor === "green") {
      iconUrl = "../../images/icon/GMC_V.png";
    }

    if (["LOCAL NO EXISTE", "LOCAL CERRADO"].includes((local.estado || "").toUpperCase())) {
      iconUrl = "../../images/icon/GMC_R.png";
    }

    const marker = new google.maps.Marker({
      position: {
        lat: parseFloat(local.latitud),
        lng: parseFloat(local.longitud)
      },
      map: mapa,
      title: local.nombre_campana || "",
      icon: {
        url: iconUrl,
        scaledSize: new google.maps.Size(32, 32)
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

function verDetalleLocal(idLocal) {
  const local = coordenadasLocales.find(l => l.idLocal === idLocal);
  if (!local) return;

  document.getElementById("modalHeaderExtra").innerHTML = "";
  document.getElementById("modalTituloLocal").textContent = local.nombre_campana || "-";

  document.getElementById("modalHeaderExtra").innerHTML = `
    <div><strong>LOCAL:</strong> ${local.nombre_local ?? "-"}</div>
    <div><strong>DIRECCIÓN:</strong> ${local.direccion_local ?? "-"}</div>
    <div><strong>COMUNA:</strong> ${local.comuna_local ?? "-"}</div>
    <div><strong>REGIÓN:</strong> ${local.region_local ?? "-"}</div>
  `;

  document.getElementById("modalBodyDetalle").innerHTML = `
    <div class="container-fluid mt-2">
      <div class="row text-center">
        <div class="col-6 col-md-3 mb-3">
          <div class="border rounded p-3">
            <strong>ÚLTIMA VISITA</strong><br>
            ${local.fechaVisita ?? "-"}<br>
            ${local.horaVisita ?? "-"}
          </div>
        </div>

        <div class="col-12 col-md-3 mb-3">
          <div class="border rounded p-3">
            <strong>ESTADO</strong><br>
            ${local.estado ?? "-"}
          </div>
        </div>

        <div class="col-12 col-md-3 mb-3">
          <div class="border rounded p-3">
            <strong>TIPO DE GESTIÓN</strong><br>
            ${local.modalidad ?? "-"}
          </div>
        </div>
      </div>

      <div class="col-lg-12">
        <div class="card" style="background-color:#fff !important;">
          <div class="card-header">GESTIÓN</div>
          <div class="card-body" style="background-color:#fff !important; color:#333;">
          </div>
        </div>
      </div>
    </div>
  `;

  $('#modalDetalle').modal('show');
}

function cerrarModalDetalle() {
  $('#modalDetalle').modal('hide');
}