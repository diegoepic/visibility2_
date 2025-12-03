// Routes API v2 (Directions) — Compute Routes con tráfico + speed intervals
// Campo clave: X-Goog-FieldMask para no desperdiciar cuota.
export type LatLngLike =
  | google.maps.LatLngLiteral
  | { placeId: string };

export interface ComputeRouteOptions {
  optimize?: boolean;
  departureOffsetSec?: number;        // default now + 0s
  routingPreference?: "TRAFFIC_AWARE" | "TRAFFIC_AWARE_OPTIMAL";
  alternatives?: number;              // cuántas alternativas extra (0..2 aprox)
}

export interface RouteResult {
  route: any; // JSON de Routes API (recortado)
  chosenIndex: number;
}

const API = "https://routes.googleapis.com/directions/v2:computeRoutes";
const KEY = import.meta.env.VITE_GOOGLE_MAPS_API_KEY as string;

type LRUEntry = { key: string; value: RouteResult; t: number };
const LRU: LRUEntry[] = [];
const TTL_MS = 5 * 60 * 1000;
const LRU_MAX = 20;

function keyFor(o: LatLngLike, d: LatLngLike, wps: LatLngLike[], opts: ComputeRouteOptions) {
  return JSON.stringify({ o, d, wps, opts });
}
function getCache(k: string) {
  const now = Date.now();
  const i = LRU.findIndex(e => e.key === k && now - e.t < TTL_MS);
  if (i >= 0) {
    const [e] = LRU.splice(i, 1);
    LRU.unshift(e);
    return e.value;
  }
  return null;
}
function setCache(k: string, v: RouteResult) {
  LRU.unshift({ key: k, value: v, t: Date.now() });
  if (LRU.length > LRU_MAX) LRU.pop();
}

function toLocation(l: LatLngLike) {
  if ("placeId" in l) return { placeId: l.placeId };
  return { latLng: { latitude: l.lat, longitude: l.lng } };
}

export async function computeRoutes(
  origin: LatLngLike,
  destination: LatLngLike,
  waypoints: LatLngLike[] = [],
  options: ComputeRouteOptions = {}
): Promise<RouteResult> {

  const body: any = {
    origin:      { location: toLocation(origin) },
    destination: { location: toLocation(destination) },
    travelMode: "DRIVE",
    routingPreference: options.routingPreference ?? "TRAFFIC_AWARE", // con tráfico
    polylineQuality: "HIGH_QUALITY",
    polylineEncoding: "ENCODED_POLYLINE",
    computeAlternativeRoutes: !!options.alternatives,
    routeModifiers: { avoidTolls: false, avoidHighways: false, avoidFerries: false },
    optimizeWaypointOrder: !!options.optimize,
    units: "METRIC",
  };

  if (waypoints.length) {
    body.intermediates = waypoints.map(w => ({ location: toLocation(w) }));
  }

  // Salida inmediata (ETA con tráfico actual)
  const nowSec = Math.floor(Date.now()/1000) + (options.departureOffsetSec ?? 0);
  body.departureTime = { seconds: nowSec };

  // FieldMask: incluir legs/steps + speedReadingIntervals para colorear tráfico
  const fields = [
    "routes.distanceMeters",
    "routes.duration",
    "routes.polyline.encodedPolyline",
    "routes.legs",
    "routes.travelAdvisory.speedReadingIntervals",
    "routes.legs.travelAdvisory.speedReadingIntervals",
    "routes.optimizedIntermediateWaypointIndex",
    "routes.viewport",
    "routes.localizedValues",
  ];

  const cacheKey = keyFor(origin, destination, waypoints, options);
  const hit = getCache(cacheKey);
  if (hit) return hit;

  const res = await fetch(API, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-Goog-Api-Key": KEY,
      "X-Goog-FieldMask": fields.join(",")
    },
    body: JSON.stringify(body)
  });

  if (!res.ok) {
    const msg = await res.text();
    throw new Error(`Routes API ${res.status}: ${msg}`);
  }

  const data = await res.json();
  const routes = data.routes ?? [];
  if (!routes.length) throw new Error("No se encontraron rutas");

  // Elegir la mejor (por duración con penalización leve por distancia)
  let bestIdx = 0, bestScore = Infinity;
  routes.forEach((r: any, i: number) => {
    const dur = parseFloat((r.duration || "0s").replace("s","")) || 0;
    const dist = r.distanceMeters || 0;
    const score = dur + dist * 0.02; // penalización suave
    if (score < bestScore) { bestScore = score; bestIdx = i; }
  });

  const result: RouteResult = { route: routes[bestIdx], chosenIndex: bestIdx };
  setCache(cacheKey, result);
  return result;
}
