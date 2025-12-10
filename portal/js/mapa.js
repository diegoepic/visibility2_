let mapa, markers = [];

function initMap() {
  const mapStyles = [
    { elementType: "geometry", stylers: [{ color: "#f5f5f5" }] },
    { elementType: "labels.icon", stylers: [{ visibility: "off" }] },
    { elementType: "labels.text.fill", stylers: [{ color: "#616161" }] },
    { elementType: "labels.text.stroke", stylers: [{ color: "#f5f5f5" }] },
    { featureType: "poi", elementType: "geometry", stylers: [{ color: "#eeeeee" }] },
    { featureType: "road", elementType: "geometry", stylers: [{ color: "#ffffff" }] },
    { featureType: "water", elementType: "geometry", stylers: [{ color: "#c9c9c9" }] },
    { featureType: "administrative", elementType: "labels.text.fill", stylers: [{ color: "#757575" }] }
  ];

  mapa = new google.maps.Map(document.getElementById("map"), {
    zoom: 6,
    center: { lat: -33.4489, lng: -70.6693 },
    styles: mapStyles,
  });

  // Validar que existan coordenadas
  if (typeof coordenadasLocales === "undefined" || !Array.isArray(coordenadasLocales)) {
    console.error("⚠️ No se encontró la variable 'coordenadasLocales'");
    return;
  }

  // Crear marcadores
  coordenadasLocales.forEach((local) => {
    if (!local.latitud || !local.longitud) return;

    let iconUrl = "../../images/icon/GMC_R.png";
    if (local.markerColor === "orange") iconUrl = "../../images/icon/GMC_G.png";
    else if (local.markerColor === "green") iconUrl = "../../images/icon/GMC_V.png";
    if (["local_no_existe", "local_cerrado"].includes(local.estado)) iconUrl = "../../images/icon/GMC_R.png";

    const marker = new google.maps.Marker({
      position: { lat: parseFloat(local.latitud), lng: parseFloat(local.longitud) },
      map: mapa,
      title: local.nombre_campana,
      icon: { url: iconUrl, scaledSize: new google.maps.Size(32, 32) },
    });

    // 📋 InfoWindow con resumen del local
    const contenidoInfo = `
      <div style="min-width: 230px; font-family: Calibri, sans-serif;">
        <h6 style="margin-bottom: 4px; font-weight: bold; color: #2c3e50;">
          ${local.nombre_campana ?? ""}
        </h6>
        <div style="font-size: 13px; color: #555;">
          <div><strong>Direccion:</strong> ${local.direccion_local ?? "-"}</div>          
          <div><strong>Comuna:</strong> ${local.comuna_local ?? "-"}</div>
          <div><strong>Región:</strong> ${local.region_local ?? "-"}</div>
          <hr style="margin: 6px 0; border: 0; border-top: 1px solid #ccc;">
          <div><strong>Estado:</strong> ${local.estado ?? "-"}</div>
          <div><strong>Fecha Visita:</strong> ${local.fechaVisita ?? "-"}</div>
          <div><strong>Hora Visita:</strong> ${local.horaVisita ?? "-"}</div>          
        </div>
    <div style="text-align: left; margin-top: 8px;">
      <button 
        onclick="verDetalleLocal(${local.idLocal})"
        style="
          background-color: #2c3e50; 
          color: white; 
          border: none; 
          padding: 5px 10px; 
          font-size: 13px; 
          border-radius: 4px; 
          cursor: pointer;
        ">
        Ver detalle
      </button>
    </div>        
      </div>
    `;

    const infoWindow = new google.maps.InfoWindow({ content: contenidoInfo });

    marker.addListener("click", () => {
      // Cierra cualquier InfoWindow abierta antes
      if (window.activeInfoWindow) window.activeInfoWindow.close();
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

          const latLng = { lat: parseFloat(data.lat), lng: parseFloat(data.lng) };
          if (!ejecutorMarker) {
            ejecutorMarker = new google.maps.Marker({
              position: latLng,
              map: mapa,
              title: "Ubicación actual",
              icon: { url: "../../images/icon/marker_user.png" },
            });
          } else {
            ejecutorMarker.setPosition(latLng);
          }
        })
        .catch(() => {});
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

  // RESET del header extra (evita duplicados)
  document.getElementById("modalHeaderExtra").innerHTML = "";

  // TITULO
  document.getElementById("modalTituloLocal").textContent = local.nombre_campana;

  // INFO debajo del t��tulo
  document.getElementById("modalHeaderExtra").innerHTML = `
    <div><strong>LOCAL:</strong> ${local.nombre_local}</div>
    <div><strong>DIRECCION:</strong> ${local.direccion_local ?? '-'}</div>
    <div><strong>COMUNA:</strong> ${local.comuna_local ?? '-'}</div>
    <div><strong>REGION:</strong> ${local.region_local ?? '-'}</div>
  `;

  // CUADROS DEL BODY
  document.getElementById("modalBodyDetalle").innerHTML = `
    <div class="container-fluid mt-2">

      <div class="row text-center">

        <div class="col-6 col-md-3 mb-3">
          <div class="border rounded p-3">
            <strong>ULTIMA VISITA</strong><br>
            ${local.fechaVisita ?? '-'}
            ${local.horaVisita ?? '-'}            
          </div>
        </div>

        <div class="col-12 col-md-3 mb-3">
          <div class="border rounded p-3">
            <strong>ESTADO</strong><br>
            ${local.estado ?? '-'}
          </div>
        </div>
        
        <div class="col-12 col-md-3 mb-3">
          <div class="border rounded p-3">
            <strong>TIPO DE GESTION</strong><br>
            ${local.modalidad ?? '-'}
          </div>
        </div>        

      </div>
      
      <div class="col-lg-19">
            <div class="card" style="background-color: #fff;!important">
        <div class="card-header">GESTION</div>
        <div class="card-body" style="background-color: #fff;!important">
        </div>
            </div>
      </div>        
 

    </div>
  `;

  // ABRIR MODAL
  $('#modalDetalle').modal('show');
}

function cerrarModalDetalle() {
  $('#modalDetalle').modal('hide');
}