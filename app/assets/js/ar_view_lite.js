(function(window){
  'use strict';

  const template = `
    <div class="ar-overlay" id="arOverlay" style="display:none;">
      <video id="arVideo" autoplay playsinline></video>
      <div class="ar-overlay__content">
        <div class="ar-instruction">
          <div class="ar-arrow" id="arArrow">▲</div>
          <div class="ar-text">
            <div id="arInstruction">Listo para iniciar</div>
            <small id="arDistance">—</small>
          </div>
        </div>
        <div class="ar-minimap" id="arMiniMap"></div>
        <button id="arClose" class="btn btn-default btn-sm ar-close">Salir</button>
      </div>
    </div>`;

  let stream=null;
  let overlay=null;
  let steps=[]; let stepIdx=0;
  let miniMap=null; let miniPolyline=null;
  let heading=0;

  function ensureOverlay(){
    if(document.getElementById('arOverlay')) return;
    const div=document.createElement('div');
    div.innerHTML=template;
    document.body.appendChild(div.firstElementChild);
    overlay=document.getElementById('arOverlay');
    document.getElementById('arClose').addEventListener('click', stop);
    listenOrientation();
  }

  function listenOrientation(){
    const handler=(e)=>{
      if(e.absolute===false && e.alpha==null) return;
      heading=e.alpha || heading;
      rotateArrow();
    };
    if(typeof DeviceOrientationEvent!=='undefined' && typeof DeviceOrientationEvent.requestPermission==='function'){
      DeviceOrientationEvent.requestPermission().then(state=>{ if(state==='granted') window.addEventListener('deviceorientation', handler, true); }).catch(()=>{});
    } else {
      window.addEventListener('deviceorientation', handler, true);
    }
  }

  function rotateArrow(){
    const el=document.getElementById('arArrow');
    if(el) el.style.transform=`rotate(${heading}deg)`;
  }

  async function startCamera(){
    if(stream) return stream;
    stream = await navigator.mediaDevices.getUserMedia({ video:{ facingMode:'environment' } });
    const vid=document.getElementById('arVideo');
    vid.srcObject=stream;
    return stream;
  }

  function updateMiniMap(route){
    if(!window.google || !window.google.maps) return;
    if(!miniMap){
      miniMap = new google.maps.Map(document.getElementById('arMiniMap'), { zoom:16, disableDefaultUI:true });
    }
    const path = google.maps.geometry.encoding.decodePath(route.polyline?.encodedPolyline || '');
    const bounds=new google.maps.LatLngBounds(); path.forEach(p=>bounds.extend(p));
    miniMap.fitBounds(bounds);
    if(miniPolyline) miniPolyline.setMap(null);
    miniPolyline=new google.maps.Polyline({ path, strokeColor:'#4285F4', strokeWeight:4, map: miniMap });
  }

  function distanceStr(m){ if(!m && m!==0) return '—'; if(m>1000) return (m/1000).toFixed(1)+' km'; return Math.round(m)+' m'; }

  function updateHud(step){
    document.getElementById('arInstruction').textContent = step ? (step.text || 'Sigue') : 'Listo';
    document.getElementById('arDistance').textContent = step ? distanceStr(step.distanceMeters) : '—';
  }

  function updatePosition(pos){
    const curStep=steps[stepIdx];
    if(!curStep) return;
    const d=haversine(pos, curStep.end);
    document.getElementById('arDistance').textContent = distanceStr(d);
    if(d < 12){ stepIdx++; updateHud(steps[stepIdx]); }
  }

  function haversine(a,b){
    const R=6371000; const toRad=x=>x*Math.PI/180;
    const dLat=toRad(b.lat-a.lat), dLng=toRad(b.lng-a.lng);
    const aa=Math.sin(dLat/2)**2 + Math.cos(toRad(a.lat))*Math.cos(toRad(b.lat))*Math.sin(dLng/2)**2;
    return 2*R*Math.asin(Math.sqrt(aa));
  }

  async function start(route, routeSteps){
    ensureOverlay();
    steps = routeSteps || [];
    stepIdx = 0;
    updateHud(steps[0]);
    updateMiniMap(route);
    try{
      await startCamera();
      overlay.style.display='block';
    }catch(_){ stop(); }
  }

  function stop(){
    if(overlay) overlay.style.display='none';
    if(stream){ stream.getTracks().forEach(t=>t.stop()); stream=null; }
  }

  window.ARViewLite = { start, stop, updatePosition };
})(window);