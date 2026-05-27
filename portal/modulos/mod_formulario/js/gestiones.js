(function(){
  const state = { map:null, markers:{} };

  window.GestionesMap = {
    setMap(map){ state.map = map; },
    load(itemId){
      if (!state.map) return;

      Object.values(state.markers).forEach(m=>m.setMap(null));
      state.markers = {};
      const isComplementaria = !!(MAPA_CONFIG && MAPA_CONFIG.isComplementaria);
      const iwRequiereLocal = !!(MAPA_CONFIG && MAPA_CONFIG.iwRequiereLocal);
      const iwNoLocal = isComplementaria && !iwRequiereLocal;

      const params = new URLSearchParams({ campana: MAPA_CONFIG.campanaId });
      if (iwNoLocal) {
        params.set('visita', itemId);
      } else {
        params.set('local', itemId);
      }

      fetch('ajax_gestiones_mapa.php?'+params.toString(), { headers: { 'X-CSRF-TOKEN': MAPA_CONFIG.csrf }})
        .then(r=>{
          if (!r.ok) throw new Error(`HTTP ${r.status}`);
          return r.json();
        })
        .then(data=>{
          const bounds = new google.maps.LatLngBounds();
          const loc = (window.MAPA_DATA || []).find(x=> +x.idLocal === +itemId || +x.visitaId === +itemId) || {};

          if (!Array.isArray(data) || data.length === 0) {
            window.showMapaToast('No se encontraron gestiones para este ' + (iwNoLocal ? 'visita' : 'local'), 'warning');
            return;
          }

          data.forEach((g, idx)=>{
            if (g.lat == null || g.lng == null) return;
            const pos = { lat:+g.lat, lng:+g.lng }; bounds.extend(pos);
            const marker = new google.maps.Marker({
              position: pos, map: state.map,
              icon: { url: `/visibility2/portal/assets/images/marker_${loc.is_priority ? 'blue' : 'red'}1.png`, scaledSize: new google.maps.Size(30,30) }
            });

            const visitaIdForButton = iwNoLocal ? (g.visitaId || loc.visitaId || itemId) : (g.visitaId || itemId);

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

          if (!bounds.isEmpty()) state.map.fitBounds(bounds);
        })
        .catch(err=>{
          window.showMapaToast('Error al cargar gestiones: ' + err.message, 'danger');
        });
    }
  };
})();
