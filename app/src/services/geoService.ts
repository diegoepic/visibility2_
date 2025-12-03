// WatchPosition + helpers de bearing/velocidad + debounce off-route.
export interface GeoState {
  pos: google.maps.LatLngLiteral;
  speedKmh: number;
  heading?: number; // brújula si disponible
  ts: number;
}

type Listener = (g: GeoState) => void;

export class GeoTracker {
  private id?: number;
  private listeners = new Set<Listener>();
  private last?: GeoState;

  start() {
    if (!navigator.geolocation) throw new Error("Geolocalización no soportada");
    this.id = navigator.geolocation.watchPosition(p => {
      const speedMs = (p.coords.speed ?? 0);
      const speedKmh = Math.max(0, speedMs) * 3.6;
      const g: GeoState = {
        pos: { lat: p.coords.latitude, lng: p.coords.longitude },
        speedKmh,
        ts: Date.now()
      };
      this.last = g;
      this.listeners.forEach(l => l(g));
    }, err => {
      console.warn("watchPosition", err);
    }, { enableHighAccuracy: true, maximumAge: 1000, timeout: 10000 });
  }
  stop(){ if (this.id!=null) navigator.geolocation.clearWatch(this.id); }

  on(fn: Listener){ this.listeners.add(fn); return ()=>this.listeners.delete(fn); }
  getLast(){ return this.last; }
}

// Utilidad: rumbo entre 2 puntos
export function bearing(a: google.maps.LatLngLiteral, b: google.maps.LatLngLiteral){
  const toRad = (d:number)=>d*Math.PI/180, toDeg=(r:number)=>r*180/Math.PI;
  const φ1=toRad(a.lat), φ2=toRad(b.lat), λ1=toRad(a.lng), λ2=toRad(b.lng);
  const y = Math.sin(λ2-λ1)*Math.cos(φ2);
  const x = Math.cos(φ1)*Math.sin(φ2)-Math.sin(φ1)*Math.cos(φ2)*Math.cos(λ2-λ1);
  return (toDeg(Math.atan2(y,x))+360)%360;
}
