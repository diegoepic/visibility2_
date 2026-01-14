(function(){
  const state = { map:null, markers:{} };

  window.GestionesMap = {
    setMap(map){ state.map = map; },
    load(itemId){
      if (!state.map) {
        console.error('[GestionesMap] Map not initialized');
        return;
      }

      console.log('[GestionesMap] Loading gestiones for itemId:', itemId);
      Object.values(state.markers).forEach(m=>m.setMap(null));
      state.markers = {};
      const isComplementaria = !!(MAPA_CONFIG && MAPA_CONFIG.isComplementaria);
      const iwRequiereLocal = !!(MAPA_CONFIG && MAPA_CONFIG.iwRequiereLocal);
      const iwNoLocal = isComplementaria && !iwRequiereLocal;

      console.log('[GestionesMap] Mode:', { isComplementaria, iwRequiereLocal, iwNoLocal });

      const params = new URLSearchParams({ campana: MAPA_CONFIG.campanaId });
      if (iwNoLocal) {
        params.set('visita', itemId); // itemId es visitaId
        console.log('[GestionesMap] Using visita parameter:', itemId);
      } else {
        params.set('local', itemId); // itemId es localId
        console.log('[GestionesMap] Using local parameter:', itemId);
      }

      const url = 'ajax_gestiones_mapa.php?'+params.toString();
      console.log('[GestionesMap] Fetching:', url);

      fetch(url, { headers: { 'X-CSRF-TOKEN': MAPA_CONFIG.csrf }})
        .then(r=>{
          console.log('[GestionesMap] Response status:', r.status);
          if (!r.ok) {
            throw new Error(`HTTP ${r.status}`);
          }
          return r.json();
        })
        .then(data=>{
          console.log('[GestionesMap] Received data:', data);
          const bounds = new google.maps.LatLngBounds();
          // FIX: Buscar por idLocal o visitaId según el tipo
          const loc = (window.MAPA_DATA || []).find(x=> +x.idLocal === +itemId || +x.visitaId === +itemId) || {};
          console.log('[GestionesMap] Found location data:', loc);

          if (!Array.isArray(data) || data.length === 0) {
            console.warn('[GestionesMap] No gestiones found for this item');
            alert('No se encontraron gestiones para esta ' + (iwNoLocal ? 'visita' : 'local'));
            return;
          }

          data.forEach((g, idx)=>{
            if (g.lat == null || g.lng == null) {
              console.warn(`[GestionesMap] Skipping gestion ${idx} - missing coordinates`);
              return;
            }
            const pos = { lat:+g.lat, lng:+g.lng }; bounds.extend(pos);
            const marker = new google.maps.Marker({
              position: pos, map: state.map,
              icon: { url: `/visibility2/portal/assets/images/marker_${loc.is_priority ? 'blue' : 'red'}1.png`, scaledSize: new google.maps.Size(30,30) }
            });

            // FIX: Para IW sin local, usar visitaId de los datos de la gestión
            const visitaIdForButton = iwNoLocal ? (g.visitaId || loc.visitaId || itemId) : (g.visitaId || itemId);
            console.log(`[GestionesMap] Marker ${idx} visitaId:`, visitaIdForButton);

            const iw = new google.maps.InfoWindow({ content: `
              <div style="max-width:240px">
                ${iwNoLocal ? `<strong>Visita #${g.visitaId ?? loc.visitaId ?? ''}</strong><br>` : `<strong>${loc.nombreLocal ?? ''}</strong><br>`}
                ${iwNoLocal ? '' : `<small>${loc.direccionLocal ?? ''}</small><br>`}
                <img src="${loc.fotoRef ?? ''}" loading="lazy" decoding="async" style="width:100%;border-radius:4px;margin:8px 0;"><br>
                <small><strong>Usuario:</strong> ${g.usuario ?? '—'}</small><br>
                <small><strong>Fecha:</strong> ${g.fechaVisita ?? '—'}</small><br>
                <button class="btn btn-sm btn-info mt-2" onclick="DetalleLocalModal.open(${MAPA_CONFIG.campanaId},${iwNoLocal ? 0 : +itemId},${visitaIdForButton})">Detalle</button>
              </div>`});
            marker.addListener('click', ()=> iw.open(state.map, marker));
            state.markers[g.idFQ || g.id || Math.random()] = marker;
          });

          if (!bounds.isEmpty()) {
            state.map.fitBounds(bounds);
            console.log('[GestionesMap] Map bounds updated, markers:', Object.keys(state.markers).length);
          } else {
            console.warn('[GestionesMap] Bounds empty after processing gestiones');
          }
        })
        .catch(err=>{
          console.error('[GestionesMap] Error loading gestiones:', err);
          alert('Error al cargar las gestiones: ' + err.message);
        });
    }
  };
})();
