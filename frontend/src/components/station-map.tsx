"use client";

import "leaflet/dist/leaflet.css";
import L from "leaflet";
import { useEffect, useMemo } from "react";
import { CircleMarker, MapContainer, Marker, Popup, TileLayer, useMap } from "react-leaflet";
import Link from "next/link";
import type { Station } from "@/lib/types";
import { money } from "@/lib/format";
import { useVisibleMount } from "@/lib/use-visible-mount";
import { TILE_ATTRIBUTION, TILE_URL } from "@/lib/map-config";

export type LatLng = { lat: number; lng: number };

const KATHMANDU: LatLng = { lat: 27.7072, lng: 85.324 };

const iconCache = new Map<string, L.DivIcon>();

function pinIcon(station: Station, selected: boolean): L.DivIcon {
  const available = station.summary?.available ?? 0;
  const color = station.status !== "active" ? "var(--offline)" : available > 0 ? "var(--available)" : "var(--occupied)";
  const key = `${color}|${available}|${selected}`;
  const cached = iconCache.get(key);
  if (cached) return cached;
  const size = selected ? 40 : 32;
  // Count inside the pin so state doesn't rely on colour alone.
  const icon = L.divIcon({
    className: "ev-pin",
    iconSize: [size, size],
    iconAnchor: [size / 2, size],
    popupAnchor: [0, -size + 4],
    html: `<div style="width:${size}px;height:${size}px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);background:${color};border:2px solid var(--surface);box-shadow:0 2px 6px rgba(0,0,0,.3);display:grid;place-items:center">
      <span style="transform:rotate(45deg);color:#fff;font:600 ${selected ? 14 : 12}px system-ui">${available}</span>
    </div>`,
  });
  iconCache.set(key, icon);
  return icon;
}

/** Leaflet measures its box once; re-measure when it changes (e.g. a map revealed from display:none). */
function AutoResize() {
  const map = useMap();
  useEffect(() => {
    // Skip once the map is being torn down (route hidden): Leaflet throws on a removed map.
    const ro = new ResizeObserver(() => {
      if (map.getContainer().isConnected && map.getPane("mapPane")) map.invalidateSize({ animate: false });
    });
    ro.observe(map.getContainer());
    return () => ro.disconnect();
  }, [map]);
  return null;
}

function FitTo({ points, center }: { points: LatLng[]; center?: LatLng | null }) {
  const map = useMap();
  const key = points.map((p) => `${p.lat.toFixed(4)},${p.lng.toFixed(4)}`).join("|");
  useEffect(() => {
    if (points.length > 1) {
      map.fitBounds(L.latLngBounds(points.map((p) => [p.lat, p.lng])), { padding: [40, 40], maxZoom: 15, animate: false });
    } else if (points.length === 1) {
      map.setView([points[0].lat, points[0].lng], 14, { animate: false });
    } else if (center) {
      map.setView([center.lat, center.lng], 12, { animate: false });
    }
    // No animation: a pan/zoom still running when the route is hidden would fire on the removed map.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, map]);
  return null;
}

export default function StationMap({
  stations,
  you,
  selectedId,
  onSelect,
  className,
  interactive = true,
}: {
  stations: Station[];
  you?: LatLng | null;
  selectedId?: number | null;
  onSelect?: (id: number) => void;
  className?: string;
  interactive?: boolean;
}) {
  const points = useMemo(
    () => [...stations.map((s) => ({ lat: s.latitude, lng: s.longitude })), ...(you ? [you] : [])],
    [stations, you],
  );
  const start = you ?? (stations[0] ? { lat: stations[0].latitude, lng: stations[0].longitude } : KATHMANDU);
  const visible = useVisibleMount();

  if (!visible) return null;

  return (
    <MapContainer
      center={[start.lat, start.lng]}
      zoom={12}
      scrollWheelZoom={interactive}
      dragging={interactive}
      className={className}
      style={{ height: "100%", width: "100%" }}
    >
      <TileLayer attribution={TILE_ATTRIBUTION} url={TILE_URL} />
      <AutoResize />
      <FitTo points={points} center={start} />
      {you && (
        <CircleMarker
          center={[you.lat, you.lng]}
          radius={8}
          pathOptions={{ color: "#ffffff", weight: 2, fillColor: "#2a78d6", fillOpacity: 1 }}
        >
          <Popup>You are here</Popup>
        </CircleMarker>
      )}
      {stations.map((s) => (
        <Marker
          key={s.id}
          position={[s.latitude, s.longitude]}
          icon={pinIcon(s, s.id === selectedId)}
          zIndexOffset={s.id === selectedId ? 1000 : 0}
          eventHandlers={{ click: () => onSelect?.(s.id) }}
          title={`${s.name}: ${s.summary?.available ?? 0} available`}
        >
          {interactive && (
            <Popup>
              <div className="min-w-44">
                <p className="font-semibold">{s.name}</p>
                <p className="text-xs opacity-75">{s.address}</p>
                <p className="mt-1 text-xs">
                  {s.summary?.available ?? 0} of {s.summary?.chargers ?? 0} chargers free · from{" "}
                  {money(s.summary?.min_price_per_kwh, 2)}/kWh
                </p>
                <Link href={`/stations/${s.id}`} className="mt-2 inline-block text-xs font-semibold text-brand">
                  View &amp; book →
                </Link>
              </div>
            </Popup>
          )}
        </Marker>
      ))}
    </MapContainer>
  );
}
