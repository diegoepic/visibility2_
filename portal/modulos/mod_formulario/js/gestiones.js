(function(){
  const state = { map:null, markers:{} };

  window.GestionesMap = {
    setMap(map){ state.map = map; },
    load(localId){
      if (!state.map) return;
      Object.values(state.markers).forEach(m=>m.setMap(null));
      state.markers = {};
      const isComplementaria = !!(MAPA_CONFIG && MAPA_CONFIG.isComplementaria);
      const iwRequiereLocal = !!(MAPA_CONFIG && MAPA_CONFIG.iwRequiereLocal);
      const params = new URLSearchParams({ campana: MAPA_CONFIG.campanaId });
      if (isComplementaria && !iwRequiereLocal) {
        params.set('visita', localId);
      } else {
        params.set('local', localId);
      }
      fetch('ajax_gestiones_mapa.php?'+params.toString(), { headers: { 'X-CSRF-TOKEN': MAPA_CONFIG.csrf }})
        .then(r=>r.json())
        .then(data=>{
          const bounds = new google.maps.LatLngBounds();
          const loc = (window.MAPA_DATA || []).find(x=> +x.idLocal===+localId) || {};
          data.forEach(g=>{
            if (g.lat == null || g.lng == null) return;
            const pos = { lat:+g.lat, lng:+g.lng }; bounds.extend(pos);
            const marker = new google.maps.Marker({
              position: pos, map: state.map,
              icon: { url: `/visibility2/portal/assets/images/marker_${loc.is_priority ? 'blue' : 'red'}1.png`, scaledSize: new google.maps.Size(30,30) }
            });
            const iw = new google.maps.InfoWindow({ content: `
              <div style="max-width:240px">
                ${MAPA_CONFIG.isComplementaria && !MAPA_CONFIG.iwRequiereLocal ? `<strong>Visita #${g.visitaId ?? ''}</strong><br>` : `<strong>${loc.nombreLocal ?? ''}</strong><br>`}
                ${MAPA_CONFIG.isComplementaria && !MAPA_CONFIG.iwRequiereLocal ? '' : `<small>${loc.direccionLocal ?? ''}</small><br>`}
                <img src="${loc.fotoRef ?? ''}" loading="lazy" decoding="async" style="width:100%;border-radius:4px;margin:8px 0;"><br>
                <small><strong>Usuario:</strong> ${g.usuario ?? '—'}</small><br>
                <small><strong>Fecha:</strong> ${g.fechaVisita ?? '—'}</small><br>
                <button class="btn btn-sm btn-info mt-2" onclick="DetalleLocalModal.open(${MAPA_CONFIG.campanaId},${MAPA_CONFIG.isComplementaria && !MAPA_CONFIG.iwRequiereLocal ? 0 : +localId},${+g.visitaId || +localId})">Detalle</button>
              </div>`});
            marker.addListener('click', ()=> iw.open(state.map, marker));
            state.markers[g.idFQ || g.id || Math.random()] = marker;
          });
          if (!bounds.isEmpty()) state.map.fitBounds(bounds);
        });
    }
  };
})();
