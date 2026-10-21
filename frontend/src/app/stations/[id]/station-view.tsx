"use client";

import dynamic from "next/dynamic";
import Link from "next/link";
import { use, useState } from "react";
import useSWR from "swr";
import { Alert, Card, Empty, Skeleton, StatusBadge, cx } from "@/components/ui";
import { CONNECTOR_LABEL, hours, money, time } from "@/lib/format";
import type { ApiError } from "@/lib/api";
import type { Charger, Station } from "@/lib/types";
import { BookingPanel } from "./booking-panel";

const StationMap = dynamic(() => import("@/components/station-map"), {
  ssr: false,
  loading: () => <div className="h-full w-full animate-pulse bg-surface-2" />,
});

export function StationView({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { data: station, error, mutate } = useSWR<Station, ApiError>(`/stations/${id}`, { refreshInterval: 30_000 });
  const [chargerId, setChargerId] = useState<number | null>(null);

  if (error) {
    return (
      <div className="mx-auto w-full max-w-6xl px-4 py-10 sm:px-6">
        <Empty title={error.status === 404 ? "Station not found" : "Couldn't load this station"} action={<Link href="/" className="text-sm font-medium text-brand">← Back to the map</Link>}>
          {error.status === 404 ? "It may have been removed or taken offline." : error.message}
        </Empty>
      </div>
    );
  }

  if (!station) {
    return (
      <div className="mx-auto w-full max-w-6xl space-y-4 px-4 py-8 sm:px-6">
        <Skeleton className="h-10 w-2/3" />
        <Skeleton className="h-64" />
      </div>
    );
  }

  const chargers = station.chargers ?? [];
  const selected = chargers.find((c) => c.id === chargerId) ?? null;
  const directions = `https://www.google.com/maps/dir/?api=1&destination=${station.latitude},${station.longitude}`;

  return (
    <div className="mx-auto w-full max-w-6xl px-4 py-6 sm:px-6 sm:py-8">
      <Link href="/" className="text-sm font-medium text-ink-2 hover:text-ink">
        ← All stations
      </Link>

      <div className="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div className="min-w-0">
          <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">{station.name}</h1>
          <p className="mt-1 text-ink-2">
            {station.address}
            {station.city ? `, ${station.city}` : ""}
          </p>
          <div className="mt-3 flex flex-wrap gap-2 text-sm">
            <span className="rounded-md bg-surface-2 px-2 py-1 text-ink-2">{hours(station)}</span>
            {station.amenities.map((a) => (
              <span key={a} className="rounded-md bg-surface-2 px-2 py-1 text-ink-2">
                {a}
              </span>
            ))}
            {station.status === "maintenance" && (
              <span className="rounded-md bg-occupied-soft px-2 py-1 font-medium">Under maintenance</span>
            )}
          </div>
        </div>
        <a
          href={directions}
          target="_blank"
          rel="noreferrer"
          className="inline-flex h-10 items-center gap-2 rounded-lg border border-line-strong bg-surface px-4 text-sm font-medium hover:bg-surface-2"
        >
          Directions ↗
        </a>
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-[1fr_400px]">
        <div className="space-y-6">
          <Card>
            <div className="flex items-center justify-between border-b border-line px-4 py-3">
              <h2 className="font-semibold">Chargers</h2>
              <span className="text-sm text-ink-2">
                {station.summary?.available ?? 0} of {chargers.length} free now
              </span>
            </div>
            {chargers.length === 0 ? (
              <p className="px-4 py-6 text-sm text-ink-2">This station has no chargers listed yet.</p>
            ) : (
              <ul className="divide-y divide-line" role="listbox" aria-label="Choose a charger">
                {chargers.map((c) => (
                  <ChargerRow
                    key={c.id}
                    charger={c}
                    tz={station.timezone}
                    selected={c.id === chargerId}
                    onSelect={() => setChargerId(c.id)}
                  />
                ))}
              </ul>
            )}
          </Card>

          {station.description && (
            <Card className="p-4">
              <h2 className="font-semibold">About this station</h2>
              <p className="mt-2 text-sm leading-relaxed text-ink-2">{station.description}</p>
              {station.operator && <p className="mt-3 text-sm text-muted">Operated by {station.operator.name}</p>}
            </Card>
          )}

          <Card className="h-64 overflow-hidden">
            <StationMap stations={[station]} interactive={false} />
          </Card>
        </div>

        <div className="lg:sticky lg:top-20 lg:self-start">
          {selected ? (
            <BookingPanel key={selected.id} station={station} charger={selected} onBooked={() => mutate()} />
          ) : (
            <Card className="p-5">
              <h2 className="font-semibold">Book a charger</h2>
              <p className="mt-1 text-sm text-ink-2">
                Pick a charger to see free slots. You can reserve up to 7 days ahead, or start charging straight away if one
                is free.
              </p>
              {station.status !== "active" && (
                <div className="mt-3">
                  <Alert tone="info">This station isn&apos;t taking bookings right now.</Alert>
                </div>
              )}
            </Card>
          )}
        </div>
      </div>
    </div>
  );
}

function ChargerRow({
  charger: c,
  tz,
  selected,
  onSelect,
}: {
  charger: Charger;
  tz: string;
  selected: boolean;
  onSelect: () => void;
}) {
  const live = c.live_status ?? "offline";
  const bookable = c.status === "available";

  return (
    <li>
      <button
        role="option"
        aria-selected={selected}
        disabled={!bookable}
        onClick={onSelect}
        className={cx(
          "flex w-full items-center gap-4 px-4 py-3.5 text-left transition-colors",
          selected ? "bg-brand-soft" : "hover:bg-surface-2",
          !bookable && "cursor-not-allowed opacity-60",
        )}
      >
        <span className="grid size-11 shrink-0 place-items-center rounded-lg border border-line bg-surface font-mono text-sm font-semibold">
          {c.label}
        </span>
        <span className="min-w-0 flex-1">
          <span className="block font-medium">
            {CONNECTOR_LABEL[c.connector_type] ?? c.connector_type} · {c.power_kw} kW
          </span>
          <span className="block text-sm text-ink-2">
            {money(c.price_per_kwh)}/kWh
            {c.busy_until && (live === "occupied" || live === "reserved") && <> · busy until {time(c.busy_until, tz)}</>}
          </span>
        </span>
        <StatusBadge status={live} />
      </button>
    </li>
  );
}
