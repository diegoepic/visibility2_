// File: portal/modulos/mod_mapa/mapa_locales.js
(function(){
  let map, infoWindow;

  function initMap(){
    map = new google.maps.Map(document.getElementById('map'), {
      center: { lat: -33.4489, lng: -70.6693 },
      zoom: 12
    });
    infoWindow = new google.maps.InfoWindow();
    cargarLocales();
  }

  function cargarLocales(){
    fetch(`/visibility2/portal/modulos/mod_mapa/ajax_obtener_locales_mapa.php?formulario_id=${CAMPAIGN_ID}`)
      .then(r=>r.json())
      .then(json=>{
        if(!json.success) {
          alert(json.message||'Error al cargar locales');
          return;
        }
        json.data.forEach(p => addMarker(p));
      })
      .catch(err=>{
        console.error(err);
        alert('Error de red al obtener locales.');
      });
  }

  function addMarker(loc){
    if (!loc.latitud||!loc.longitud) return;
    // usamos la foto como icono
    const icon = {
      url: `/visibility2/app/${loc.fotoRef}`,
      scaledSize: new google.maps.Size(50,50)
    };
    const m = new google.maps.Marker({
      position: { lat: loc.latitud, lng: loc.longitud },
      map,
      icon,
      title: loc.nombreLocal
    });
    m.addListener('click', ()=>{
      const content = document.createElement('div');
      content.className = 'info-window';
      const img = document.createElement('img');
      img.src = `/visibility2/app/${loc.fotoRef}`;
      content.appendChild(img);
      const title = document.createElement('div');
      title.innerHTML = `<strong>${loc.nombreLocal}</strong><br>${loc.direccionLocal}`;
      content.appendChild(title);
      const btn = document.createElement('button');
      btn.className='btn btn-sm btn-primary mt-2';
      btn.textContent = 'Gestionar Local';
      btn.onclick = ()=>{
        window.location.href = `/visibility2/portal/modulos/mod_formulario/ajax_ver_gestiones.php?formulario_id=${CAMPAIGN_ID}&local_id=${loc.idLocal}`;
      };
      content.appendChild(btn);
      infoWindow.setContent(content);
      infoWindow.open(map, m);
    });
  }

  // espera a que Google Maps llame a initMap
  window.initMap = initMap;
})();
