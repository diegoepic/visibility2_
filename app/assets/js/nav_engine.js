(function(window){
  'use strict';

  const OFF_ROUTE_TOL_M = { slow: 40, fast: 80 };
  const REROUTE_COOLDOWN_MS = 30000;
  const OFF_ROUTE_PERSIST_MS = 12000;
  const MIN_ACCURACY_M = 80;
  const MIN_SPEED_KMH = 3;

  function haversine(a,b){
    const R=6371000; const toRad=x=>x*Math.PI/180;
    const dLat=toRad(b.lat-a.lat), dLng=toRad(b.lng-a.lng);
    const sLat=toRad(a.lat), sLng=toRad(b.lng-a.lng);
    const aa=Math.sin(dLat/2)**2 + Math.cos(toRad(a.lat))*Math.cos(toRad(b.lat))*Math.sin(dLng/2)**2;
    return 2*R*Math.asin(Math.sqrt(aa));
  }

  function decode(path){ return google.maps.geometry.encoding.decodePath(path||'').map(ll=>({lat:ll.lat(), lng:ll.lng()})); }

  function buildSteps(route){
    const steps=[];
    (route.legs||[]).forEach(leg=>{
      (leg.steps||[]).forEach(st=>{
        steps.push({
          text: (st.navigationInstruction && st.navigationInstruction.instructions) || st.navigationInstruction || 'Seguir',
          distanceMeters: st.distanceMeters || 0,
          staticDuration: st.staticDuration || '0s',
          end: st.endLocation || st.end_location || st.startLocation || { lat:0,lng:0 }
        });
      });
    });
    return steps;
  }

  class Navigator3D{
    constructor(map, hooks){
      this.map = map;
      this.hooks = hooks || {};
      this.active=false; this.steps=[]; this.stepIdx=0; this.route=null;
      this.geoWatch=null; this.offRouteSince=null; this.lastRerouteAt=0;
      this.lastPos=null; this.lastHeading=0; this._lastTime=null; this.path=[];
    }

    async startFromSelection(params){
      const { origin, destination, waypoints } = params;
      const route = await window.RouteEngine.computeRouteUnified({ origin, destination, waypoints, optimize: params.optimize, mode:'nav' });
      this.route=route; this.steps=buildSteps(route); this.stepIdx=0; this.path=decode(route.polyline?.encodedPolyline||'');
      this.active=true; this.offRouteSince=null; this.lastRerouteAt=0; this.lastHeading=0;
      this.hooks.onRoute && this.hooks.onRoute(route, this.steps);
      this.watchGps();
    }

    stop(){
      this.active=false; this.unwatchGps(); this.route=null; this.steps=[]; this.stepIdx=0; this.offRouteSince=null;
      this.hooks.onStop && this.hooks.onStop();
    }

    unwatchGps(){ if(this.geoWatch!=null){ navigator.geolocation.clearWatch(this.geoWatch); this.geoWatch=null; } }

    watchGps(){
      this.unwatchGps();
      this.geoWatch = navigator.geolocation.watchPosition(pos=>{
        const cur={lat:pos.coords.latitude, lng:pos.coords.longitude};
        const acc=pos.coords.accuracy||999;
        const now=Date.now();
        const speedKmh = this.lastPos ? (haversine(this.lastPos, cur)/((now-(this._lastTime||now))/1000))*3.6 : 0;
        this._lastTime=now; this.lastPos=cur;
        if(acc > MIN_ACCURACY_M){ return; }
        if(this.hooks.onCamera){ this.hooks.onCamera(cur, speedKmh, acc); }
        if(this.hooks.onPosition){ this.hooks.onPosition(cur, speedKmh, acc); }
        this.advanceStep(cur);
        if(!this.isOnRoute(cur, speedKmh)){
          if(!this.offRouteSince) this.offRouteSince = now;
          if(now - this.offRouteSince > OFF_ROUTE_PERSIST_MS){ this.tryReroute(cur, speedKmh, acc); }
        }else{ this.offRouteSince=null; }
      }, ()=>{}, { enableHighAccuracy:true, maximumAge:1000, timeout:10000 });
    }

    isOnRoute(point, speedKmh){
      if(!this.path.length) return true;
      const tol = speedKmh > 35 ? OFF_ROUTE_TOL_M.fast : OFF_ROUTE_TOL_M.slow;
      const gll = new google.maps.LatLng(point.lat, point.lng);
      const poly = new google.maps.Polyline({ path: this.path.map(p=>new google.maps.LatLng(p.lat,p.lng)) });
      return google.maps.geometry.poly.isLocationOnEdge(gll, poly, tol/6378137);
    }

    advanceStep(cur){
      const s=this.steps[this.stepIdx]; if(!s) return;
      const d=haversine(cur, s.end);
      if(d < 12){
        this.stepIdx++; this.hooks.onStep && this.hooks.onStep(this.stepIdx, this.steps[this.stepIdx]);
      }
    }

    async tryReroute(cur, speedKmh, acc){
      const now=Date.now();
      if(now - this.lastRerouteAt < REROUTE_COOLDOWN_MS) return;
      if(speedKmh < MIN_SPEED_KMH) return;
      if(acc > MIN_ACCURACY_M) return;
      this.lastRerouteAt = now; window.RouteEngine.markReroute();
      const remaining = this.steps.slice(this.stepIdx).map(s=>s.end);
      const destination = remaining.length ? remaining[remaining.length-1] : cur;
      const waypoints = remaining.slice(0,-1);
      try{
        const route = await window.RouteEngine.computeRouteUnified({ origin:cur, destination, waypoints, optimize:false, mode:'nav' });
        this.route=route; this.steps=buildSteps(route); this.stepIdx=0; this.path=decode(route.polyline?.encodedPolyline||'');
        this.offRouteSince=null;
        this.hooks.onRoute && this.hooks.onRoute(route, this.steps, true);
      }catch(_){ /* silent */ }
    }
  }

  window.NavEngine = { Navigator3D };
})(window);
