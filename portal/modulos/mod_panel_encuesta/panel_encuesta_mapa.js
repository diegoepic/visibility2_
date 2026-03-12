(function($){
  'use strict';
  const PE = window.PE;

  let map = null;
  let markerLayer = null;
  let mapOpen = false;

  function initMap(){
    if (map) return;
    map = L.map('pe-map').setView([-33.45, -70.65], 10);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '© OpenStreetMap contributors'
    }).addTo(map);
    markerLayer = L.layerGroup().addTo(map);
  }

  function loadMapData(){
    if (!PE || typeof PE.buildParams !== 'function') return;
    const params = PE.buildParams();
    delete params.page;
    delete params.limit;
    delete params.facets;

    markerLayer.clearLayers();

    $.ajax({
      url: 'ajax_locales_geo.php',
      data: params,
      dataType: 'json',
      timeout: 30000,
      success: function(resp){
        if (!resp || resp.status !== 'ok' || !resp.data || !resp.data.length) return;

        const bounds = [];
        resp.data.forEach(loc => {
          if (!loc.lat || !loc.lng) return;
          const lat = parseFloat(loc.lat);
          const lng = parseFloat(loc.lng);
          if (isNaN(lat) || isNaN(lng)) return;

          bounds.push([lat, lng]);
          const popup = L.popup().setContent(
            `<strong>${PE.escapeHtml(loc.codigo||'')} - ${PE.escapeHtml(loc.nombre||'')}</strong><br>` +
            (loc.cadena    ? `${PE.escapeHtml(loc.cadena)}<br>`    : '') +
            (loc.direccion ? `${PE.escapeHtml(loc.direccion)}<br>` : '') +
            `Visitas: <strong>${loc.visitas||0}</strong>` +
            (loc.ultima_visita ? ` · Última: ${PE.escapeHtml(loc.ultima_visita)}` : '') +
            `<br><a href="#" class="local-detalle-link" data-local-id="${loc.id}" data-form-id="0">Ver detalle</a>`
          );
          const marker = L.marker([lat, lng]).bindPopup(popup);
          markerLayer.addLayer(marker);
        });

        if (bounds.length) {
          map.fitBounds(bounds, { padding: [30, 30], maxZoom: 14 });
        }
      },
      error: function(){
        if (PE.showError) PE.showError('Error', 'No se pudo cargar el mapa de locales.');
      }
    });
  }

  // Toggle mapa al presionar "Ver mapa"
  $('#btnVerMapa').on('click', function(){
    const $map = $('#pe-map');
    mapOpen = !mapOpen;
    $map.toggle(mapOpen);
    $(this).toggleClass('active', mapOpen);

    if (mapOpen) {
      initMap();
      // Leaflet necesita invalidateSize después de mostrar el div
      setTimeout(() => {
        map.invalidateSize();
        loadMapData();
      }, 50);
    }
  });

  // C5: recargar mapa cuando haya nueva búsqueda (si mapa está abierto)
  $(document).on('pe:data-loaded', function(){
    if (mapOpen && map) {
      loadMapData();
    }
  });

})(jQuery);
