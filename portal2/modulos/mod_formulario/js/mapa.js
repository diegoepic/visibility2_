(function(){
  const esc = (s) => $('<div>').text(s ?? '').html();
  const overlay = document.getElementById('overlay');
  let markersLocales = {};
  let clusterer;

  window.initMap = function initMap(){
    const locales = window.MAPA_DATA || [];
    const isComplementaria = !!(window.MAPA_CONFIG && window.MAPA_CONFIG.isComplementaria);
    const iwRequiereLocal = !!(window.MAPA_CONFIG && window.MAPA_CONFIG.iwRequiereLocal);
    const iwNoLocal = isComplementaria && !iwRequiereLocal;
    const chileBounds = new google.maps.LatLngBounds({lat:-56,lng:-76},{lat:-17.5,lng:-66});

    const mapLocales = new google.maps.Map(document.getElementById('map'), {
      center:{lat:-33.45,lng:-70.66}, zoom:5, streetViewControl:false, mapTypeControl:false
    });
    mapLocales.fitBounds(chileBounds);
    window.mapLocales = mapLocales;

    const mapGestiones = new google.maps.Map(document.getElementById('mapGestiones'), {
      center:{lat:-33.45,lng:-70.66}, zoom:5, streetViewControl:false, mapTypeControl:false
    });
    mapGestiones.fitBounds(chileBounds);
    window.mapGestiones = mapGestiones;
    if (window.GestionesMap) window.GestionesMap.setMap(mapGestiones);

    // OPTIMIZACIÓN: Crear InfoWindow solo al hacer clic (lazy loading)
    locales.forEach(loc => {
      const markerLat = (loc.markerLat != null) ? loc.markerLat : loc.lat;
      const markerLng = (loc.markerLng != null) ? loc.markerLng : loc.lng;
      if (markerLat == null || markerLng == null) return;
      const pos = {lat:+markerLat, lng:+markerLng};
      const icon=`/visibility2/portal/assets/images/marker_${loc.is_priority?'blue':'red'}1.png`;
      const marker = new google.maps.Marker({ position: pos, map: mapLocales, icon:{url:icon, scaledSize:new google.maps.Size(30,30)} });

      // LAZY LOADING: Generar contenido solo al hacer clic
      marker.addListener('click', () => {
        console.log('[mapa.js] Marker clicked for location:', { idLocal: loc.idLocal, visitaId: loc.visitaId });
        const last = (loc.lastLat!=null && loc.lastLng!=null && loc.lat!=null && loc.lng!=null)
          ? {lat:+loc.lastLat,lng:+loc.lastLng}
          : null;
        const dist = last ? haversineMeters({lat:+loc.lat,lng:+loc.lng}, last) : null;
        const pill = dist==null || iwNoLocal ? '' : `<span class="badge badge-${dist<=150?'success':'danger'} ml-1">${dist} m</span>`;

        const infoTitle = iwNoLocal
          ? `<strong>Visita #${esc(loc.visitaId ?? loc.idLocal ?? '')}</strong>`
          : `<strong>${esc(loc.nombreLocal)}</strong>`;
        const infoSecondary = iwNoLocal ? '' : `<small>${esc(loc.direccionLocal)}</small><br>`;
        const infoEstado = isComplementaria
          ? `<small><strong>Visitas:</strong> ${+loc.visitasCount || 0} · <strong>Respuestas:</strong> ${+loc.gestionesCount || 0}</small><br>`
          : `<small><strong>Estado:</strong> ${esc(loc.estadoLabel ?? loc.estadoGestion ?? '—')} ${pill}</small><br>`;
        const infoFooter = iwNoLocal
          ? `<small><strong>Fecha:</strong> ${esc(loc.fechaVisita ?? '—')} · ${esc(loc.usuarioGestion ?? '—')}</small><br>`
          : `<small><strong>Última visita:</strong> ${esc(loc.fechaVisita ?? '—')} · ${esc(loc.usuarioGestion ?? '—')}</small><br>`;
        const visitaIdForButton = +loc.visitaId || +loc.idLocal;
        console.log('[mapa.js] Button will use visitaId:', visitaIdForButton, 'for iwNoLocal:', iwNoLocal);
        const detailButton = iwNoLocal
          ? `<div class="mt-2 d-flex"><button class="btn btn-sm btn-info" onclick="DetalleLocalModal.open(${MAPA_CONFIG.campanaId},0,${visitaIdForButton})">Detalle</button></div>`
          : `<div class="mt-2 d-flex"><button class="btn btn-sm btn-info" onclick="DetalleLocalModal.open(${MAPA_CONFIG.campanaId},${+loc.idLocal})">Detalle</button></div>`;

        const iw = new google.maps.InfoWindow({content:`
          <div style="max-width:240px">
            ${infoTitle}<br>
            ${infoSecondary}
            <img src="${esc(loc.fotoRef)}" loading="lazy" decoding="async" style="width:100%;border-radius:4px;margin:8px 0;"><br>
            ${infoEstado}
            ${infoFooter}
            ${!isComplementaria ? `<small><strong>V/G:</strong> ${+loc.visitasCount || 0} / ${+loc.gestionesCount || 0}</small><br>` : ''}
            ${detailButton}
          </div>`
        });

        iw.open(mapLocales, marker);
        highlightRow(+loc.idLocal);
      });

      markersLocales[+loc.idLocal]=marker;
    });

    clusterer = new markerClusterer.MarkerClusterer({ map: mapLocales, markers: Object.values(markersLocales) });
    if (overlay) overlay.style.display='none';

    $('#tblLocales').on('mouseenter','tr', function(){
      const id = +$(this).data('id'); const m = markersLocales[id]; if(!m) return;
      m.setAnimation(google.maps.Animation.BOUNCE); setTimeout(()=>m.setAnimation(null), 700);
    });

    $('#tblLocales').on('click','tr', function(){
      const id = +$(this).data('id');
      console.log('[mapa.js] Row clicked, data-id:', id);
      if (!id) {
        console.warn('[mapa.js] No valid ID found in data-id');
        return;
      }
      if ($('#tabLocales').hasClass('active')) {
        console.log('[mapa.js] Locales tab active, triggering marker click');
        const m = markersLocales[id];
        if (!m) {
          console.error('[mapa.js] Marker not found for id:', id);
          return;
        }
        mapLocales.setZoom(15); mapLocales.panTo(m.getPosition()); google.maps.event.trigger(m,'click');
      } else if (window.GestionesMap) {
        // FIX: Pasar el ID correcto (puede ser idLocal o visitaId)
        console.log('[mapa.js] Gestiones tab active, calling GestionesMap.load with id:', id);
        window.GestionesMap.load(id);
      }
    });

    $('#tabLocales').click(e=>{
      e.preventDefault(); $('#tabLocales').addClass('active'); $('#tabGestiones').removeClass('active'); $('#mapGestiones').hide(); $('#map').show();
    });
    $('#tabGestiones').click(e=>{
      e.preventDefault(); $('#tabGestiones').addClass('active'); $('#tabLocales').removeClass('active'); $('#map').hide(); $('#mapGestiones').show();
      const firstId = +$('#tblLocales tbody tr:first').data('id');
      if (window.GestionesMap && firstId) { window.GestionesMap.load(firstId); }
    });

    $('#btnToggleSidebar').on('click', ()=> document.getElementById('sbar').classList.toggle('collapsed'));
  };

  function haversineMeters(a,b){
    const R = 6371000; const dLat = deg2rad(b.lat - a.lat); const dLon = deg2rad(b.lng - a.lng);
    const la1 = deg2rad(a.lat); const la2 = deg2rad(b.lat);
    const h = Math.sin(dLat/2)**2 + Math.cos(la1)*Math.cos(la2)*Math.sin(dLon/2)**2;
    return Math.round(R * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1-h)));
  }
  function deg2rad(x){ return x * (Math.PI/180); }

  window.haversineMeters = haversineMeters;
  window.highlightRow = function(localId){
    const $row = $(`#tblLocales tr[data-id="${localId}"]`);
    if(!$row.length) return;
    $('#tblLocales tr').removeClass('table-active');
    $row.addClass('table-active')[0].scrollIntoView({block:'center', behavior:'smooth'});
  };
})();
