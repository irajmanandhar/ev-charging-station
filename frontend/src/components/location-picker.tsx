"use client";

import "leaflet/dist/leaflet.css";
import { CircleMarker, MapContainer, TileLayer, useMapEvents } from "react-leaflet";
import { TILE_ATTRIBUTION, TILE_URL } from "@/lib/map-config";
import { useVisibleMount } from "@/lib/use-visible-mount";

function ClickHandler({ onPick }: { onPick: (lat: number, lng: number) => void }) {
  useMapEvents({ click: (e) => onPick(e.latlng.lat, e.latlng.lng) });
  return null;
}

export default function LocationPicker({
  lat,
  lng,
  onPick,
}: {
  lat: number | null;
  lng: number | null;
  onPick: (lat: number, lng: number) => void;
}) {
  const center: [number, number] = lat != null && lng != null ? [lat, lng] : [27.7072, 85.324];
  const visible = useVisibleMount();
  if (!visible) return null;
  return (
    <MapContainer center={center} zoom={lat != null ? 15 : 12} style={{ height: "100%", width: "100%" }}>
      <TileLayer attribution={TILE_ATTRIBUTION} url={TILE_URL} />
      <ClickHandler onPick={onPick} />
      {lat != null && lng != null && (
        <CircleMarker center={[lat, lng]} radius={9} pathOptions={{ color: "#ffffff", weight: 3, fillColor: "#0a7d55", fillOpacity: 1 }} />
      )}
    </MapContainer>
  );
}
