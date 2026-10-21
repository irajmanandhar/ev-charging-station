"use client";

import { useRouter } from "next/navigation";
import { useMemo, useState } from "react";
import useSWR from "swr";
import { useToast } from "@/components/toast";
import { Alert, Button, Card, Field, Input, Skeleton, cx } from "@/components/ui";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { addDays, date, duration, kwh, money, time, ymd } from "@/lib/format";
import type { Availability, Charger, Station } from "@/lib/types";

const DURATIONS = [30, 45, 60, 90, 120, 180, 240];

export function BookingPanel({
  station,
  charger,
  onBooked,
}: {
  station: Station;
  charger: Charger;
  onBooked: () => void;
}) {
  const tz = station.timezone;
  const today = ymd(new Date(), tz);
  const [mode, setMode] = useState<"slot" | "now">("slot");
  const [day, setDay] = useState(today);
  const [start, setStart] = useState<string | null>(null);
  const [minutes, setMinutes] = useState(60);
  const [vehicle, setVehicle] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const { user } = useAuth();
  const router = useRouter();
  const toast = useToast();

  const { data: avail, mutate: refreshAvail } = useSWR<Availability>(
    `/chargers/${charger.id}/availability?date=${day}`,
    { refreshInterval: 30_000 },
  );

  // How long a booking can run from the chosen start: contiguous free slots, capped at the max.
  const maxFromStart = useMemo(() => {
    if (!avail || !start) return 0;
    const i = avail.slots.findIndex((s) => s.start === start);
    if (i < 0) return 0;
    let n = 0;
    for (let j = i; j < avail.slots.length && avail.slots[j].free; j++) n++;
    return Math.min(n * avail.slot_minutes, avail.max_minutes);
  }, [avail, start]);

  const freeNow = avail?.free_minutes_now ?? 0;
  const canStartNow = charger.live_status === "available" && freeNow >= (avail?.min_minutes ?? 30) && day === today;
  const limit = mode === "now" ? Math.floor(freeNow / 15) * 15 : maxFromStart;
  const options = DURATIONS.filter((d) => d <= limit);
  const chosen = options.includes(minutes) ? minutes : options[options.length - 1] ?? 0;

  const { data: quote } = useSWR<{ kwh: number; cost: number }>(
    chosen ? `/chargers/${charger.id}/quote?minutes=${chosen}` : null,
  );

  const requireUser = () => {
    if (user) return true;
    router.push(`/login?next=${encodeURIComponent(`/stations/${station.id}`)}`);
    return false;
  };

  const submit = async () => {
    if (!requireUser() || !chosen) return;
    setSubmitting(true);
    setError(null);
    try {
      if (mode === "now") {
        await api(`/chargers/${charger.id}/start`, {
          method: "POST",
          body: { duration_minutes: chosen, vehicle: vehicle || null },
        });
        toast("Charging started. Track it under My bookings.");
      } else {
        await api("/bookings", {
          method: "POST",
          body: { charger_id: charger.id, starts_at: start, duration_minutes: chosen, vehicle: vehicle || null },
        });
        toast(`Booked ${charger.label} for ${dateLabel(start!, tz)}`);
      }
      onBooked();
      router.push("/bookings");
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : "Something went wrong. Please try again.";
      setError(msg);
      if (e instanceof ApiError && e.code === "slot_taken") {
        setStart(null);
        refreshAvail();
      }
    } finally {
      setSubmitting(false);
    }
  };

  const days = Array.from({ length: 7 }, (_, i) => addDays(today, i));

  return (
    <Card>
      <div className="border-b border-line p-4">
        <p className="text-sm text-ink-2">Charger {charger.label}</p>
        <h2 className="font-semibold">
          {charger.connector_type} · {charger.power_kw} kW · {money(charger.price_per_kwh)}/kWh
        </h2>
        <div className="mt-3 grid grid-cols-2 gap-1 rounded-lg bg-surface-2 p-1" role="tablist">
          <TabButton active={mode === "slot"} onClick={() => setMode("slot")}>
            Book a slot
          </TabButton>
          <TabButton active={mode === "now"} onClick={() => setMode("now")} disabled={!canStartNow && mode !== "now"}>
            Charge now
          </TabButton>
        </div>
      </div>

      <div className="space-y-5 p-4">
        {mode === "slot" ? (
          <>
            <div>
              <p className="mb-2 text-sm font-medium">Day</p>
              <div className="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1">
                {days.map((d, i) => (
                  <button
                    key={d}
                    onClick={() => {
                      setDay(d);
                      setStart(null);
                    }}
                    className={cx(
                      "flex shrink-0 flex-col items-center rounded-lg border px-3 py-1.5 text-xs",
                      d === day ? "border-brand bg-brand-soft font-semibold" : "border-line hover:bg-surface-2",
                    )}
                  >
                    <span>{i === 0 ? "Today" : i === 1 ? "Tomorrow" : weekday(d)}</span>
                    <span className="text-ink-2">{d.slice(8)}/{d.slice(5, 7)}</span>
                  </button>
                ))}
              </div>
            </div>

            <div>
              <p className="mb-2 text-sm font-medium">Start time</p>
              {!avail ? (
                <Skeleton className="h-40" />
              ) : (
                <SlotGrid avail={avail} tz={tz} selected={start} onSelect={setStart} />
              )}
            </div>
          </>
        ) : (
          <Alert tone="info">
            {canStartNow ? (
              <>Free for the next {duration(freeNow)}. Charging starts as soon as you confirm, so be at the charger.</>
            ) : (
              <>This charger isn&apos;t free right now. Book a slot instead.</>
            )}
          </Alert>
        )}

        {(mode === "now" ? canStartNow : !!start) && (
          <>
            <div>
              <p className="mb-2 text-sm font-medium">Duration</p>
              <div className="flex flex-wrap gap-1.5">
                {options.length === 0 ? (
                  <p className="text-sm text-ink-2">Not enough free time from this start. Pick an earlier slot.</p>
                ) : (
                  options.map((d) => (
                    <button
                      key={d}
                      onClick={() => setMinutes(d)}
                      className={cx(
                        "rounded-lg border px-3 py-1.5 text-sm",
                        d === chosen ? "border-brand bg-brand-soft font-semibold" : "border-line hover:bg-surface-2",
                      )}
                    >
                      {duration(d)}
                    </button>
                  ))
                )}
              </div>
            </div>

            <Field label="Vehicle (optional)" hint="Helps the operator if there's a problem at the bay.">
              <Input value={vehicle} onChange={(e) => setVehicle(e.target.value)} placeholder="e.g. BYD Atto 3, Ba 1 Pa 1234" maxLength={80} />
            </Field>

            {chosen > 0 && (
              <div className="rounded-lg bg-surface-2 p-3 text-sm">
                <div className="flex justify-between">
                  <span className="text-ink-2">When</span>
                  <span className="font-medium">
                    {mode === "now" ? "Now" : dateLabel(start!, tz)} · {duration(chosen)}
                  </span>
                </div>
                <div className="mt-1 flex justify-between">
                  <span className="text-ink-2">Estimated energy</span>
                  <span className="font-medium">{quote ? kwh(quote.kwh) : "…"}</span>
                </div>
                <div className="mt-1 flex justify-between">
                  <span className="text-ink-2">Estimated cost</span>
                  <span className="font-semibold">{quote ? money(quote.cost) : "…"}</span>
                </div>
                <p className="mt-2 text-xs text-muted">You pay for energy actually delivered. Stop early and the rest of the slot is released.</p>
              </div>
            )}

            {error && <Alert>{error}</Alert>}

            <Button className="w-full" onClick={submit} loading={submitting} disabled={!chosen}>
              {!user ? "Sign in to book" : mode === "now" ? "Start charging" : "Confirm booking"}
            </Button>
          </>
        )}
      </div>
    </Card>
  );
}

function TabButton({
  active,
  children,
  ...props
}: React.ComponentProps<"button"> & { active: boolean }) {
  return (
    <button
      role="tab"
      aria-selected={active}
      className={cx(
        "rounded-md py-1.5 text-sm font-medium disabled:opacity-40",
        active ? "bg-surface text-ink shadow-sm" : "text-ink-2 hover:text-ink",
      )}
      {...props}
    >
      {children}
    </button>
  );
}

function SlotGrid({
  avail,
  tz,
  selected,
  onSelect,
}: {
  avail: Availability;
  tz: string;
  selected: string | null;
  onSelect: (s: string) => void;
}) {
  // A start is offered only if at least the minimum duration is free from it.
  const need = avail.min_minutes / avail.slot_minutes;
  const startable = avail.slots.map((_, i) => avail.slots.slice(i, i + need).length === need && avail.slots.slice(i, i + need).every((s) => s.free));
  const upcoming = avail.slots.filter((s) => !s.past);

  if (upcoming.length === 0) {
    return <p className="text-sm text-ink-2">The station has closed for today. Try another day.</p>;
  }

  return (
    <>
      <div className="grid max-h-56 grid-cols-4 gap-1.5 overflow-y-auto pr-1">
        {avail.slots.map((s, i) =>
          s.past ? null : (
            <button
              key={s.start}
              disabled={!startable[i]}
              onClick={() => onSelect(s.start)}
              aria-pressed={selected === s.start}
              className={cx(
                "rounded-md border py-1.5 text-sm tabular",
                selected === s.start
                  ? "border-brand bg-brand text-brand-ink"
                  : startable[i]
                    ? "border-line hover:border-brand"
                    : s.free
                      ? "border-transparent text-muted"
                      : "border-transparent bg-surface-2 text-muted line-through",
              )}
              title={s.free ? (startable[i] ? undefined : "Not enough free time after this slot") : "Booked"}
            >
              {time(s.start, tz)}
            </button>
          ),
        )}
      </div>
      <p className="mt-2 text-xs text-muted">
        Times in {tz.replace("_", " ")}. Crossed-out times are booked.
      </p>
    </>
  );
}

function weekday(d: string) {
  return new Intl.DateTimeFormat("en-GB", { weekday: "short", timeZone: "UTC" }).format(new Date(`${d}T12:00:00Z`));
}

function dateLabel(iso: string, tz: string) {
  return `${date(iso, tz)}, ${time(iso, tz)}`;
}
