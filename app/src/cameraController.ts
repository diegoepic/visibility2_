// Control de cámara: track-up, tilt y zoom dinámico, con suavizado de heading.
export interface CameraOpts {
  map: google.maps.Map;
  minZoom?: number;
  maxZoom?: number;
  tilt?: number; // 45..60
}

export class CameraController {
  private map: google.maps.Map;
  private tilt: number;
  private lastHeading = 0;
  private lastTs = 0;
  private minZoom: number;
  private maxZoom: number;
  private follow = true;

  constructor(opts: CameraOpts){
    this.map = opts.map;
    this.tilt = opts.tilt ?? 55;
    this.minZoom = opts.minZoom ?? 15;
    this.maxZoom = opts.maxZoom ?? 19;
    this.map.setTilt(this.tilt);
  }

  setFollow(f: boolean){ this.follow = f; }
  isFollowing(){ return this.follow; }

  // Zoom según velocidad (km/h) y proximidad al giro (m)
  computeZoom(speedKmh: number, distToTurnM: number){
    let z = speedKmh <= 20 ? 18 : speedKmh <= 60 ? 17 : 16;
    if (distToTurnM < 120) z += 0.5;
    return Math.max(this.minZoom, Math.min(this.maxZoom, z));
  }

  // Interpolación de heading para evitar wobble
  smoothHeading(target: number){
    const now = performance.now();
    const dt = Math.min(250, now - this.lastTs);
    this.lastTs = now;

    // Normaliza delta a [-180,180]
    let delta = ((target - this.lastHeading + 540) % 360) - 180;
    const step = delta * (dt/250); // ≤ 250ms
    const h = (this.lastHeading + step + 360) % 360;
    this.lastHeading = h;
    return h;
  }

  update(center: google.maps.LatLngLiteral, headingDeg: number, zoom: number){
    if (!this.follow) return;
    const h = this.smoothHeading(headingDeg);
    this.map.setHeading(h);
    this.map.setZoom(zoom);
    this.map.setCenter(center);
    // tilt ya se fijó al crear (vector basemap requerido)
  }
}
