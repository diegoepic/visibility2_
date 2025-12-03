// Roads API: snapToRoads para map-matching (interpolate=true)
const KEY = import.meta.env.VITE_GOOGLE_MAPS_API_KEY as string;

export async function snapToRoads(path: google.maps.LatLngLiteral[]): Promise<google.maps.LatLngLiteral[]> {
  if (!path.length) return [];
  const pathParam = path.map(p => `${p.lat},${p.lng}`).join("|");
  const url = `https://roads.googleapis.com/v1/snapToRoads?key=${KEY}&interpolate=true&path=${encodeURIComponent(pathParam)}`;
  const res = await fetch(url);
  if (!res.ok) throw new Error(`Roads API ${res.status}`);
  const data = await res.json();
  const pts = (data.snappedPoints || []).map((sp: any) => ({
    lat: sp.location.latitude, lng: sp.location.longitude
  }));
  return pts;
}
