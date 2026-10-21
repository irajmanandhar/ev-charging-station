"use client";

import dynamic from "next/dynamic";
import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import useSWR from "swr";
import { useT } from "@/lib/i18n";
import type { LatLng } from "@/components/station-map";
import { Alert, Button, Card, Empty, Input, Select, Skeleton, cx } from "@/components/ui";
import { CONNECTOR_LABEL, hours, money } from "@/lib/format";
import type { Station } from "@/lib/types";

const StationMap = dynamic(() => import("@/components/station-map"), {
  ssr: false,
  loading: () => <div className="h-full w-full animate-pulse bg-surface-2" />,
});

const RADII = [5, 10, 25, 50, 200];

function useDebounced<T>(value: T, ms = 300): T {
  const [v, setV] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setV(value), ms);
    return () => clearTimeout(t);
  }, [value, ms]);
  return v;
}

export function StationFinder() {
  const t = useT();
  const [you, setYou] = useState<LatLng | null>(null);
  const [locating, setLocating] = useState(false);
  const [geoError, setGeoError] = useState<string | null>(null);
  const [radius, setRadius] = useState(25);
  const [connector, setConnector] = useState("");
  const [minPower, setMinPower] = useState("");
  const [availableNow, setAvailableNow] = useState(false);
  const [q, setQ] = useState("");
  const [selected, setSelected] = useState<number | null>(null);
  const [view, setView] = useState<"list" | "map">("list");
  const query = useDebounced(q);

  const key = useMemo(() => {
    const p = new URLSearchParams();
    if (you) {
      p.set("lat", you.lat.toFixed(5));
      p.set("lng", you.lng.toFixed(5));
      p.set("radius_km", String(radius));
    }
    if (connector) p.set("connector", connector);
    if (minPower) p.set("min_power", minPower);
    if (availableNow) p.set("available_now", "1");
    if (query.trim()) p.set("q", query.trim());
    return `/stations?${p.toString()}`;
  }, [you, radius, connector, minPower, availableNow, query]);

  // Live availability changes minute to minute; refresh quietly in the background.
  const { data: stations, error, isLoading } = useSWR<Station[]>(key, { refreshInterval: 30_000, keepPreviousData: true });

  const locate = () => {
    if (!navigator.geolocation) {
      setGeoError("Your browser doesn't support location.");
      return;
    }
    setLocating(true);
    setGeoError(null);
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        setYou({ lat: pos.coords.latitude, lng: pos.coords.longitude });
        setLocating(false);
      },
      () => {
        setGeoError("We couldn't get your location. Showing all stations instead.");
        setLocating(false);
      },
      { enableHighAccuracy: false, timeout: 10_000 },
    );
  };

  const list = stations ?? [];
  const totalFree = list.reduce((n, s) => n + (s.summary?.available ?? 0), 0);

  // On desktop the finder is exactly one screen tall (below the 4rem header and its 1px border;
  // flex-none, so flex-1 can't grow it past that height); only the station list scrolls and the map stays put.
  return (
    <div className="flex flex-1 flex-col lg:h-[calc(100dvh-4rem-1px)] lg:flex-none lg:flex-row lg:overflow-hidden">
      {/* Left: filters + results */}
      <section className="flex w-full flex-col border-line lg:w-[440px] lg:shrink-0 lg:border-r">
        <div className="border-b border-line bg-surface px-4 py-5 sm:px-6">
          <h1 className="text-2xl font-semibold tracking-tight">{t("finder.title")}</h1>
          <p className="mt-1 text-sm text-ink-2">Live availability across the valley. Book a slot or plug in on arrival.</p>

          <div className="mt-4 flex gap-2">
            <Input
              type="search"
              placeholder={t("finder.search")}
              value={q}
              onChange={(e) => setQ(e.target.value)}
              aria-label="Search stations"
            />
            <Button variant="secondary" onClick={locate} loading={locating} className="shrink-0" aria-label="Use my location">
              {!locating && (
                <svg viewBox="0 0 24 24" className="size-4" fill="none" stroke="currentColor" strokeWidth="2">
                  <circle cx="12" cy="12" r="3" />
                  <path d="M12 2v3M12 19v3M2 12h3M19 12h3" strokeLinecap="round" />
                </svg>
              )}
              <span className="hidden sm:inline">{you ? "Located" : "Near me"}</span>
            </Button>
          </div>

          <div className="mt-3 grid grid-cols-2 gap-2">
            <Select value={connector} onChange={(e) => setConnector(e.target.value)} aria-label="Connector type">
              <option value="">Any connector</option>
              {Object.entries(CONNECTOR_LABEL).map(([v, l]) => (
                <option key={v} value={v}>
                  {l}
                </option>
              ))}
            </Select>
            <Select value={minPower} onChange={(e) => setMinPower(e.target.value)} aria-label="Minimum power">
              <option value="">Any power</option>
              <option value="22">22 kW+</option>
              <option value="50">50 kW+ (fast)</option>
              <option value="100">100 kW+ (rapid)</option>
            </Select>
            <Select
              value={radius}
              onChange={(e) => setRadius(Number(e.target.value))}
              disabled={!you}
              aria-label="Search radius"
              title={you ? undefined : "Use your location to filter by distance"}
              className="col-span-2"
            >
              {RADII.map((r) => (
                <option key={r} value={r}>
                  Within {r} km
                </option>
              ))}
            </Select>
          </div>

          <label className="mt-3 flex cursor-pointer items-center gap-2 text-sm text-ink-2">
            <input
              type="checkbox"
              checked={availableNow}
              onChange={(e) => setAvailableNow(e.target.checked)}
              className="size-4 accent-[var(--brand)]"
            />
            Only show stations with a free charger now
          </label>
          {geoError && (
            <div className="mt-3">
              <Alert tone="info">{geoError}</Alert>
            </div>
          )}
        </div>

        {/* Mobile list/map switch */}
        <div className="flex gap-1 border-b border-line bg-surface p-2 lg:hidden" role="tablist">
          {(["list", "map"] as const).map((v) => (
            <button
              key={v}
              role="tab"
              aria-selected={view === v}
              onClick={() => setView(v)}
              className={cx(
                "flex-1 rounded-lg py-2 text-sm font-medium capitalize",
                view === v ? "bg-brand-soft text-ink" : "text-ink-2",
              )}
            >
              {v}
            </button>
          ))}
        </div>

        <div className={cx("flex-1 overflow-y-auto px-4 py-4 sm:px-6", view === "map" && "hidden lg:block")}>
          {error ? (
            <Alert>{error.message}</Alert>
          ) : isLoading && !stations ? (
            <div className="space-y-3">
              {[0, 1, 2].map((i) => (
                <Skeleton key={i} className="h-32" />
              ))}
            </div>
          ) : list.length === 0 ? (
            <Empty title="No stations match">
              Try a wider radius, another connector, or turn off “free now”.
            </Empty>
          ) : (
            <>
              <p className="mb-3 text-sm text-ink-2" aria-live="polite">
                {list.length} {list.length === 1 ? "station" : "stations"} · {totalFree} chargers free right now
              </p>
              <ul className="space-y-3">
                {list.map((s) => (
                  <li key={s.id}>
                    <StationCard station={s} selected={s.id === selected} onHover={() => setSelected(s.id)} />
                  </li>
                ))}
              </ul>
            </>
          )}
        </div>
      </section>

      {/* Right: map */}
      <section
        className={cx("relative h-[70dvh] lg:h-auto lg:flex-1", view === "list" && "hidden lg:block")}
        aria-label="Map of stations"
      >
        <StationMap stations={list} you={you} selectedId={selected} onSelect={setSelected} />
        <MapLegend />
      </section>
    </div>
  );
}

function MapLegend() {
  return (
    <div className="pointer-events-none absolute bottom-6 left-4 z-[500] rounded-lg border border-line bg-surface/95 px-3 py-2 text-xs text-ink-2 shadow">
      <p className="flex items-center gap-2">
        <span className="size-2.5 rounded-full bg-available" /> Free chargers (number in pin)
      </p>
      <p className="mt-1 flex items-center gap-2">
        <span className="size-2.5 rounded-full bg-occupied" /> All chargers busy
      </p>
    </div>
  );
}

function StationCard({ station: s, selected, onHover }: { station: Station; selected: boolean; onHover: () => void }) {
  const free = s.summary?.available ?? 0;
  const total = s.summary?.chargers ?? 0;

  return (
    <Link href={`/stations/${s.id}`} onMouseEnter={onHover} onFocus={onHover} className="block">
      <Card
        className={cx(
          "p-4 transition-colors hover:border-line-strong",
          selected && "border-brand ring-1 ring-brand",
        )}
      >
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <h2 className="truncate font-semibold text-ink">{s.name}</h2>
            <p className="truncate text-sm text-ink-2">
              {s.address}
              {s.city ? `, ${s.city}` : ""}
            </p>
          </div>
          {s.distance_km != null && (
            <span className="shrink-0 text-sm font-medium text-ink tabular">{s.distance_km.toFixed(1)} km</span>
          )}
        </div>

        <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
          <span className="inline-flex items-center gap-1.5 font-medium">
            <span aria-hidden className={cx("size-2 rounded-full", free > 0 ? "bg-available" : "bg-occupied")} />
            {free} of {total} free
          </span>
          <span className="text-ink-2">Up to {s.summary?.max_power_kw ?? "—"} kW</span>
          <span className="text-ink-2">from {money(s.summary?.min_price_per_kwh, 2)}/kWh</span>
        </div>

        <div className="mt-3 flex flex-wrap items-center gap-1.5">
          {s.summary?.connector_types.map((c) => (
            <span key={c} className="rounded-md bg-surface-2 px-2 py-0.5 text-xs font-medium text-ink-2">
              {CONNECTOR_LABEL[c] ?? c}
            </span>
          ))}
          <span className="ml-auto text-xs text-muted">{hours(s)}</span>
        </div>
      </Card>
    </Link>
  );
}
