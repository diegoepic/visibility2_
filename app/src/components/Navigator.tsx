import React, { useEffect, useRef, useState } from "react";
import { Loader } from "@googlemaps/js-api-loader";
import { computeRoutes, LatLngLike } from "../services/routesService";
import { snapToRoads } from "../services/roadsService";
import { GeoTracker, bearing } from "../services/geoService";
import { speak } from "../services/ttsService";
import { CameraController } from "../cameraController";

type Waypoint = LatLngLike;

export interface NavigatorProps {
  origin: LatLngLike;
  destination: LatLngLike;
  waypoints?: Waypoint[];
  options?: {
    optimize?: boolean;
    nightMode?: "auto"|"on"|"off";
    language?: string; // e.g., "es-CL"
  }
}

const API_KEY = import.meta.env.VITE_GOOGLE_MAPS_API_KEY as string;
const MAP_ID  = import.meta.env.VITE_GOOGLE_MAPS_MAP_ID as string | undefined;

const REROUTE_MIN_MS = 2000;   // throttling
const OFF_ROUTE_DIST = 40;     // m
const OFF_ROUTE_MS   = 3000;   // 3s para confirmar off-route
const MANEUVER_HAPTIC_M = 100; // vibración a 100m

const SPEED_COLORS: Record<string, string> = {
  TRAFFIC_JAM: "#c62828",
  SLOW:        "#fb8c00",
  NORMAL:      "#43a047",
  UNKNOWN:     "#607d8b",
};

function metersToText(m: number){
  if (m >= 1000) return (m/1000).toFixed(1) + " km";
  return Math.round(m/10)*10 + " m";
}

export default function Navigator({ origin, destination, waypoints=[] }: NavigatorProps){
  const mapDiv = useRef<HTMLDivElement>(null);
  const [hud, setHud] = useState<{ primary?: string; secondary?: string; icon?: string; dist?: string; eta?: string; total?: string; arrival?: string }>({});
  const [state, setState] = useState<"idle"|"locating"|"routing"|"navigating"|"offroute"|"arrived">("idle");
  const trackerRef = useRef<GeoTracker>();
  const mapRef = useRef<google.maps.Map>();
  const userMarkerRef = useRef<google.maps.marker.AdvancedMarkerElement>();
  const routePathRef = useRef<google.maps.Polyline>();
  const trafficPolysRef = useRef<google.maps.Polyline[]>([]);
  const cameraRef = useRef<CameraController>();
  const routeRef = useRef<any>(null);
  const lastRerouteRef = useRef<number>(0);
  const offRouteStartRef = useRef<number|undefined>(undefined);
  const stepIndexRef = useRef<{leg:number; step:number}>({ leg:0, step:0 });
  const lastAnnouncedRef = useRef<number>(Infinity); // metros restantes al que ya anunciamos 100m

  // Helpers
  const clearTrafficPolys = () => {
    trafficPolysRef.current.forEach(p => p.setMap(null));
    trafficPolysRef.current = [];
  };

  useEffect(() => {
    let mounted = true;

    async function init(){
      setState("locating");
      const loader = new Loader({
        apiKey: API_KEY,
        version: "weekly",
        libraries: ["geometry", "places"],
        language: "es",
        region: "CL"
      });
      await loader.load();

      // MAPA vector con tilt/heading
      const map = new google.maps.Map(mapDiv.current!, {
        mapId: MAP_ID,
        center: { lat: -33.45, lng: -70.66 },
        zoom: 16,
        heading: 0,
        tilt: 55,
        disableDefaultUI: true,
        gestureHandling: "greedy",
        clickableIcons: false,
        backgroundColor: "#000"
      });
      mapRef.current = map;

      // HUD recenter
      let userInteracted = false;
      map.addListener("dragstart", ()=> userInteracted = true);
      map.addListener("tilt_changed", ()=> userInteracted = true);
      map.addListener("heading_changed", ()=> userInteracted = true);
      map.addListener("zoom_changed", ()=> userInteracted = true);

      // Cámara
      cameraRef.current = new CameraController({ map, tilt: 55 });

      // Marker usuario (AdvancedMarker requiere import maps/marker)
      const advMarker = (google.maps as any).marker?.AdvancedMarkerElement;
      if (advMarker) {
        userMarkerRef.current = new advMarker({
          map,
          position: { lat: -33.45, lng: -70.66 },
          content: document.createElement("div") // usa default pin
        });
      } else {
        userMarkerRef.current = undefined;
      }

      // Geolocalización
      const tracker = new GeoTracker();
      trackerRef.current = tracker;
      tracker.start();

      tracker.on(async g => {
        if (!mounted) return;
        if (!mapRef.current) return;

        // posicionar marker/cámara
        const center = g.pos;
        if (userMarkerRef.current) (userMarkerRef.current as any).position = center;

        // mientras no haya ruta: centra puro
        if (!routeRef.current){
          cameraRef.current?.update(center, 0, 17);
          return;
        }

        // Distancias y step actual
        advanceProgressAndHud(center, g.speedKmh);

        // Recentrar si el usuario movió la cámara (botón en HUD)
        if (!userInteracted) {
          const h = headingForCenter(center, g);
          const d2turn = distanceToNextTurn(center);
          const zoom = cameraRef.current!.computeZoom(g.speedKmh, d2turn);
          cameraRef.current!.update(center, h, zoom);
        }
        // Off-route detection
        detectOffRoute(center);
      });

      // Primera ruta
      await computeAndRenderRoute({ useAlternatives: 1 });

      setState("navigating");
    }

    init().catch(err => {
      console.error(err);
      setState("idle");
      alert("No se pudo iniciar la navegación.");
    });

    return () => { mounted = false; trackerRef.current?.stop(); };
  }, []);

  // ---------------- ROUTING ----------------
  async function computeAndRenderRoute({ useAlternatives = 0 }: { useAlternatives?: number } = {}){
    setState("routing");
    clearTrafficPolys();

    const { route } = await computeRoutes(
      origin, destination, waypoints,
      { optimize: !waypoints.length ? false : true, alternatives: useAlternatives, routingPreference: "TRAFFIC_AWARE" }
    );
    routeRef.current = route;
    stepIndexRef.current = { leg:0, step:0 };
    lastAnnouncedRef.current = Infinity;
    offRouteStartRef.current = undefined;

    // Polyline principal
    const encoded = route.polyline.encodedPolyline as string;
    const path = google.maps.geometry.encoding.decodePath(encoded);
    routePathRef.current?.setMap(null);
    routePathRef.current = new google.maps.Polyline({
      path, map: mapRef.current!,
      strokeColor: "#1976d2", strokeWeight: 6, strokeOpacity: 0.85
    });

    // Traffic segments por speedReadingIntervals
    const intervals =
      route.travelAdvisory?.speedReadingIntervals || [];
    renderTrafficSegments(path, intervals);

    // Fit viewport de la ruta al comenzar
    const vp = route.viewport;
    if (vp) {
      mapRef.current!.fitBounds(new google.maps.LatLngBounds(
        new google.maps.LatLng(vp.low?.latitude, vp.low?.longitude),
        new google.maps.LatLng(vp.high?.latitude, vp.high?.longitude)
      ));
    }
  }

  function renderTrafficSegments(path: google.maps.LatLng[], intervals: any[]){
    clearTrafficPolys();
    if (!intervals?.length || !path.length) return;

    const n = path.length;
    const clampIdx = (i:number)=> Math.max(0, Math.min(n-1, i));

    intervals.forEach(iv => {
      const start = clampIdx(iv.startPolylinePointIndex ?? 0);
      const end   = clampIdx(iv.endPolylinePointIndex ?? (n-1));
      const segPath = path.slice(start, end+1);
      const cat = iv.speed ?? "UNKNOWN";
      const poly = new google.maps.Polyline({
        path: segPath,
        map: mapRef.current!,
        strokeColor: SPEED_COLORS[cat] || SPEED_COLORS.UNKNOWN,
        strokeWeight: 7,
        strokeOpacity: 0.9
      });
      trafficPolysRef.current.push(poly);
    });
  }

  // ---------------- PROGRESO + HUD ----------------
  function flatSteps(): any[] {
    const r = routeRef.current;
    if (!r) return [];
    const out: any[] = [];
    (r.legs || []).forEach((leg: any, L: number) => {
      (leg.steps || []).forEach((st: any, S:number) => out.push({ ...st, legIndex:L, stepIndex:S }));
    });
    return out;
  }

  function stepAt(idx: {leg:number; step:number}){
    const leg = routeRef.current.legs[idx.leg];
    return leg?.steps?.[idx.step];
  }

  function distanceToNextTurn(pos: google.maps.LatLngLiteral){
    const st = stepAt(stepIndexRef.current);
    if (!st) return 0;
    const stepPath = google.maps.geometry.encoding.decodePath(st.polyline?.encodedPolyline || "");
    // distancia al final del step
    const end = stepPath[stepPath.length-1];
    return google.maps.geometry.spherical.computeDistanceBetween(new google.maps.LatLng(pos), end);
  }

  function headingForCenter(pos: google.maps.LatLngLiteral, g: { speedKmh: number }){
    // Preferir rumbo del segmento actual
    const st = stepAt(stepIndexRef.current);
    if (st?.polyline?.encodedPolyline){
      const pts = google.maps.geometry.encoding.decodePath(st.polyline.encodedPolyline);
      if (pts.length >= 2){
        const a = pts[Math.max(0, pts.length-2)];
        const b = pts[pts.length-1];
        return bearing({lat:a.lat(), lng:a.lng()}, {lat:b.lat(), lng:b.lng()});
      }
    }
    // Fallback: no girar
    return mapRef.current!.getHeading() || 0;
  }

  function instructionText(st: any){
    const ins = st?.navigationInstruction?.instructions || "";
    return ins.replace(/\s+/g," ").trim();
  }

  function advanceProgressAndHud(pos: google.maps.LatLngLiteral, speedKmh: number){
    const r = routeRef.current; if (!r) return;

    const leg = r.legs[stepIndexRef.current.leg];
    const st  = leg?.steps?.[stepIndexRef.current.step];
    if (!st) return;

    // Avance de step: si estamos muy cerca del final, pasar al siguiente
    const stepPath = google.maps.geometry.encoding.decodePath(st.polyline?.encodedPolyline || "");
    const end = stepPath[stepPath.length-1];
    const distToEnd = google.maps.geometry.spherical.computeDistanceBetween(new google.maps.LatLng(pos), end);
    if (distToEnd < 20) {
      if (stepIndexRef.current.step + 1 < leg.steps.length){
        stepIndexRef.current.step += 1;
      } else if (stepIndexRef.current.leg + 1 < r.legs.length){
        stepIndexRef.current.leg += 1;
        stepIndexRef.current.step = 0;
      } else {
        setState("arrived");
        setHud(h => ({ ...h, primary: "Has llegado", secondary: "", dist: "0 m" }));
        speak("Has llegado a tu destino.");
        return;
      }
    }

    const cur = stepAt(stepIndexRef.current)!;
    const next = (() => {
      let L = stepIndexRef.current.leg;
      let S = stepIndexRef.current.step + 1;
      if (S >= r.legs[L].steps.length){ L += 1; S = 0; }
      return r.legs[L]?.steps?.[S];
    })();

    // Distancia al próximo giro (inicio del step actual → final del step actual)
    const startOfCur = google.maps.geometry.encoding.decodePath(cur.polyline.encodedPolyline)[0];
    const distToTurn = google.maps.geometry.spherical.computeDistanceBetween(
      new google.maps.LatLng(pos), startOfCur
    );

    // Vibración + TTS a ~100 m
    if (distToTurn < MANEUVER_HAPTIC_M && lastAnnouncedRef.current > MANEUVER_HAPTIC_M){
      if ("vibrate" in navigator) navigator.vibrate(200);
      speak(`En ${MANEUVER_HAPTIC_M} metros, ${instructionText(cur)}`);
      lastAnnouncedRef.current = distToTurn;
    }

    const primary = instructionText(cur);
    const secondary = next ? instructionText(next) : "";
    const totalMeters = r.distanceMeters ?? 0;
    const remainingSec = parseFloat((r.duration || "0s").replace("s","")) || 0; // aprox (sin recorte real por avance)
    const etaDate = new Date(Date.now() + remainingSec*1000);
    const arrival = new Intl.DateTimeFormat("es-CL", { hour: "2-digit", minute: "2-digit" }).format(etaDate);

    setHud({
      primary,
      secondary,
      icon: cur.navigationInstruction?.maneuver, // puedes mapear a SVG
      dist: metersToText(distToTurn),
      eta: Math.round(remainingSec/60) + " min",
      total: metersToText(totalMeters),
      arrival
    });
  }

  // ---------------- OFF-ROUTE / REROUTE ----------------
  function detectOffRoute(pos: google.maps.LatLngLiteral){
    const poly = routePathRef.current; if (!poly) return;
    const onEdge = google.maps.geometry.poly.isLocationOnEdge(
      new google.maps.LatLng(pos), poly, 20/6378137 // ~20m tolerancia
    );
    if (onEdge) { offRouteStartRef.current = undefined; setState("navigating"); return; }

    // snap-to-road como segundo chequeo (async fire & forget)
    (async () => {
      try{
        const snapped = await snapToRoads([pos]);
        const near = snapped[0];
        const d = google.maps.geometry.spherical.computeDistanceBetween(
          new google.maps.LatLng(pos), new google.maps.LatLng(near)
        );
        const now = Date.now();
        if (d > OFF_ROUTE_DIST){
          if (offRouteStartRef.current == null) offRouteStartRef.current = now;
          if (now - (offRouteStartRef.current||0) > OFF_ROUTE_MS){
            setState("offroute");
            maybeReroute(pos);
          }
        } else {
          offRouteStartRef.current = undefined;
          setState("navigating");
        }
      }catch{}
    })();
  }

  async function maybeReroute(current: google.maps.LatLngLiteral){
    const now = Date.now();
    if (now - lastRerouteRef.current < REROUTE_MIN_MS) return;
    lastRerouteRef.current = now;

    try{
      await computeAndRenderRoute({ useAlternatives: 1 });
      setState("navigating");
      speak("Ruta actualizada.");
    }catch(e){
      console.warn("reroute failed", e);
    }
  }

  // ---------------- UI ----------------
  function recenter(){
    cameraRef.current?.setFollow(true);
  }

  return (
    <div className="nav-wrapper" style={{position:"relative", width:"100%", height:"100%"}}>
      {/* MAPA */}
      <div ref={mapDiv} style={{position:"absolute", inset:0}} />

      {/* HUD superior (próxima maniobra) */}
      <div style={{
        position:"absolute", top:10, left:10, right:10, display:"flex", gap:8, pointerEvents:"none"
      }}>
        <div style={{
          flex:1, background:"rgba(20,24,28,.9)", color:"#fff", borderRadius:12, padding:"10px 12px",
          boxShadow:"0 10px 30px rgba(0,0,0,.35)"
        }}>
          <div style={{display:"flex", alignItems:"center", gap:10}}>
            <div aria-hidden style={{width:28, height:28, borderRadius:8, background:"#2c3948"}} />
            <div style={{flex:1}}>
              <div style={{fontWeight:700, fontSize:15, lineHeight:1.2}}>{hud.primary || "Calculando dirección..."}</div>
              {hud.secondary ? <div style={{opacity:.85, fontSize:12, marginTop:2}}>{hud.secondary}</div> : null}
            </div>
            <div style={{fontWeight:700, fontSize:14}}>{hud.dist || ""}</div>
          </div>
        </div>
      </div>

      {/* HUD inferior (ETA / total / hora llegada) */}
      <div style={{
        position:"absolute", left:10, right:10, bottom:10, display:"flex", gap:8, alignItems:"center"
      }}>
        <button onClick={recenter} style={{
          padding:"10px 12px", borderRadius:10, border:"1px solid #cfd7e3", background:"#fff", boxShadow:"0 2px 8px rgba(0,0,0,.15)"
        }}>Recentrar</button>
        <div style={{
          flex:1, display:"grid", gridTemplateColumns:"1fr 1fr 1fr", gap:8
        }}>
          <div style={pill}><strong>ETA</strong><span>{hud.eta ?? "--"}</span></div>
          <div style={pill}><strong>Dist.</strong><span>{hud.total ?? "--"}</span></div>
          <div style={pill}><strong>Llega</strong><span>{hud.arrival ?? "--"}</span></div>
        </div>
      </div>

      {/* Estados */}
      <div style={{position:"absolute", left:10, bottom:70, background:"rgba(255,255,255,.9)",
                   border:"1px solid #e0e6ef", padding:"6px 10px", borderRadius:8}}>
        {state}
      </div>
    </div>
  );
}

const pill: React.CSSProperties = {
  display:"flex", alignItems:"center", justifyContent:"space-between",
  padding:"10px 12px", borderRadius:10, background:"rgba(20,24,28,.9)", color:"#fff"
};

export type { NavigatorProps };

