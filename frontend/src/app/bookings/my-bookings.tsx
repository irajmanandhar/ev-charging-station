"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import useSWR from "swr";
import { useToast } from "@/components/toast";
import { useNow } from "@/components/use-now";
import { Alert, BookingStatusBadge, Button, ButtonLink, Card, Empty, PageHeader, Skeleton, StatTile } from "@/components/ui";
import { ApiError, api } from "@/lib/api";
import { date, duration, kwh, money, time } from "@/lib/format";
import { useT } from "@/lib/i18n";
import type { AppConfig, Booking, Session } from "@/lib/types";

const CHECKIN_EARLY_MIN = 10;
const GRACE_MIN = 15;
const EFFICIENCY = 0.85;

const PENDING_PAYMENT = "ev.pending-payment";

/** Send the browser to the payment provider: a plain redirect, or a form POST (eSewa). */
function goToProvider(redirectUrl: string, form: Record<string, string> | null) {
  if (!form) {
    window.location.assign(redirectUrl);
    return;
  }
  const el = document.createElement("form");
  el.method = "POST";
  el.action = redirectUrl;
  for (const [name, value] of Object.entries(form)) {
    const input = document.createElement("input");
    input.type = "hidden";
    input.name = name;
    input.value = value;
    el.appendChild(input);
  }
  document.body.appendChild(el);
  el.submit();
}

export function MyBookings() {
  const t = useT();
  const toast = useToast();
  const { data: bookings, error, mutate } = useSWR<Booking[]>("/bookings", { refreshInterval: 30_000 });
  const { data: sessions, mutate: mutateSessions } = useSWR<Session[]>("/sessions");
  const { data: config } = useSWR<AppConfig>("/config");
  const refresh = () => {
    mutate();
    mutateSessions();
  };

  // Coming back from the payment provider: ask the API to verify the payment it started.
  const confirming = useRef(false);
  useEffect(() => {
    let id: string | null = null;
    try {
      id = window.sessionStorage.getItem(PENDING_PAYMENT);
    } catch {
      /* storage unavailable */
    }
    if (!id || confirming.current) return;
    confirming.current = true;
    try {
      window.sessionStorage.removeItem(PENDING_PAYMENT);
    } catch {
      /* storage unavailable */
    }
    const params = new URLSearchParams(window.location.search);
    api<{ status: string }>(`/payments/${id}/confirm`, {
      method: "POST",
      body: { pidx: params.get("pidx") ?? undefined, data: params.get("data") ?? undefined, status: params.get("status") ?? undefined },
    })
      .then(({ data, message }) => {
        toast(message, data.status === "completed" ? "success" : "error");
        mutate();
      })
      .catch((e) => toast(e instanceof ApiError ? e.message : "Couldn't confirm the payment.", "error"));
    window.history.replaceState(null, "", window.location.pathname);
  }, [mutate, toast]);

  if (error) {
    return (
      <div className="mx-auto w-full max-w-4xl px-4 py-8 sm:px-6">
        <Alert>{error.message}</Alert>
      </div>
    );
  }

  const active = bookings?.filter((b) => b.status === "active") ?? [];
  const upcoming = (bookings?.filter((b) => b.status === "confirmed") ?? []).sort((a, b) => a.starts_at.localeCompare(b.starts_at));
  const past = bookings?.filter((b) => !["active", "confirmed"].includes(b.status)) ?? [];
  const completed = sessions?.filter((s) => s.status === "completed") ?? [];

  return (
    <div className="mx-auto w-full max-w-4xl px-4 py-8 sm:px-6">
      <PageHeader
        title={t("bookings.title")}
        subtitle={t("bookings.subtitle")}
        actions={<ButtonLink href="/">{t("bookings.book")}</ButtonLink>}
      />

      <div className="mb-8 grid grid-cols-2 gap-3 sm:grid-cols-3">
        <StatTile label={t("bookings.sessions")} value={completed.length} />
        <StatTile label={t("bookings.energy")} value={kwh(completed.reduce((n, s) => n + s.energy_kwh, 0), 0)} />
        <StatTile label={t("bookings.spent")} value={money(completed.reduce((n, s) => n + s.cost, 0))} />
      </div>

      {!bookings ? (
        <div className="space-y-3">
          <Skeleton className="h-28" />
          <Skeleton className="h-28" />
        </div>
      ) : (
        <div className="space-y-10">
          {active.length > 0 && (
            <section>
              <h2 className="mb-3 text-lg font-semibold">{t("bookings.chargingNow")}</h2>
              <div className="space-y-3">
                {active.map((b) => (
                  <ActiveSession key={b.id} booking={b} onChange={refresh} />
                ))}
              </div>
            </section>
          )}

          <section>
            <h2 className="mb-3 text-lg font-semibold">{t("bookings.upcoming")}</h2>
            {upcoming.length === 0 ? (
              <Empty title="No upcoming bookings" action={<ButtonLink href="/" variant="secondary">Find a charger</ButtonLink>}>
                Reserve a slot up to 7 days ahead so a charger is waiting when you arrive.
              </Empty>
            ) : (
              <div className="space-y-3">
                {upcoming.map((b) => (
                  <UpcomingBooking key={b.id} booking={b} onChange={refresh} payments={!!config?.payments_enabled} />
                ))}
              </div>
            )}
          </section>

          <section>
            <h2 className="mb-3 text-lg font-semibold">{t("bookings.history")}</h2>
            {past.length === 0 ? (
              <p className="text-sm text-ink-2">Completed and cancelled bookings will show up here.</p>
            ) : (
              <Card className="overflow-x-auto">
                <table className="w-full min-w-[560px] text-sm">
                  <thead className="text-left text-ink-2">
                    <tr className="border-b border-line">
                      <th className="px-4 py-2.5 font-medium">When</th>
                      <th className="px-4 py-2.5 font-medium">Station</th>
                      <th className="px-4 py-2.5 font-medium">Status</th>
                      <th className="px-4 py-2.5 text-right font-medium">Energy</th>
                      <th className="px-4 py-2.5 text-right font-medium">Cost</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-line">
                    {past.map((b) => (
                      <tr key={b.id}>
                        <td className="px-4 py-3 whitespace-nowrap">
                          {date(b.starts_at)} <span className="text-ink-2">{time(b.starts_at)}</span>
                        </td>
                        <td className="px-4 py-3">
                          <Link href={`/stations/${b.charger?.station?.id}`} className="hover:underline">
                            {b.charger?.station?.name}
                          </Link>
                          <span className="text-ink-2"> · {b.charger?.label}</span>
                        </td>
                        <td className="px-4 py-3">
                          <BookingStatusBadge status={b.status} />
                        </td>
                        <td className="px-4 py-3 text-right tabular">{b.session ? kwh(b.session.energy_kwh) : "—"}</td>
                        <td className="px-4 py-3 text-right tabular">{b.session ? money(b.session.cost) : "—"}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </Card>
            )}
          </section>
        </div>
      )}
    </div>
  );
}

function BookingSummary({ booking: b }: { booking: Booking }) {
  const tz = b.charger?.station?.timezone;
  return (
    <div className="min-w-0">
      <div className="flex flex-wrap items-center gap-2">
        <Link href={`/stations/${b.charger?.station?.id}`} className="font-semibold hover:underline">
          {b.charger?.station?.name}
        </Link>
        <BookingStatusBadge status={b.status} />
      </div>
      <p className="mt-1 text-sm text-ink-2">
        {date(b.starts_at, tz)} · {time(b.starts_at, tz)}–{time(b.ends_at, tz)} · Charger {b.charger?.label} (
        {b.charger?.connector_type}, {b.charger?.power_kw} kW)
      </p>
      <p className="mt-0.5 text-xs text-muted">
        Ref {b.reference}
        {b.vehicle ? ` · ${b.vehicle}` : ""}
      </p>
    </div>
  );
}

function UpcomingBooking({ booking: b, onChange, payments }: { booking: Booking; onChange: () => void; payments: boolean }) {
  const t = useT();
  const now = useNow(15_000);
  const toast = useToast();
  const [busy, setBusy] = useState<"checkin" | "cancel" | "pay" | null>(null);
  const [error, setError] = useState<string | null>(null);

  const start = new Date(b.starts_at).getTime();
  const opens = start - CHECKIN_EARLY_MIN * 60_000;
  const closes = start + GRACE_MIN * 60_000;
  const canCheckIn = now >= opens && now <= closes;
  const canCancel = now < start;
  const minsToStart = Math.round((start - now) / 60_000);

  const act = async (kind: "checkin" | "cancel") => {
    if (kind === "cancel" && !window.confirm("Cancel this booking? The slot will be released to other drivers.")) return;
    setBusy(kind);
    setError(null);
    try {
      await api(`/bookings/${b.id}/${kind === "checkin" ? "check-in" : "cancel"}`, { method: "POST" });
      toast(kind === "checkin" ? "Charging started." : "Booking cancelled.");
      onChange();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "Something went wrong.");
    } finally {
      setBusy(null);
    }
  };

  const pay = async () => {
    setBusy("pay");
    setError(null);
    try {
      const { data } = await api<{ payment_id: number; redirect_url: string; form: Record<string, string> | null }>(`/bookings/${b.id}/pay`, { method: "POST" });
      try {
        window.sessionStorage.setItem(PENDING_PAYMENT, String(data.payment_id));
      } catch {
        /* storage unavailable: the payment can still be confirmed from the next visit */
      }
      goToProvider(data.redirect_url, data.form);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "Something went wrong.");
      setBusy(null);
    }
  };

  return (
    <Card className="p-4">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <BookingSummary booking={b} />
        <div className="text-right text-sm">
          <p className="font-semibold">{money(b.estimated_cost)}</p>
          <p className="text-ink-2">est. {kwh(b.estimated_kwh)}</p>
        </div>
      </div>
      <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-3">
        <Button onClick={() => act("checkin")} loading={busy === "checkin"} disabled={!canCheckIn || busy !== null}>
          {t("bookings.checkIn")}
        </Button>
        {payments && !b.paid && (
          <Button variant="secondary" onClick={pay} loading={busy === "pay"} disabled={busy !== null}>
            {t("bookings.pay")}
          </Button>
        )}
        {b.paid && <span className="rounded bg-available-soft px-2 py-1 text-xs font-medium">{t("bookings.paid")}</span>}
        {canCancel && (
          <Button variant="ghost" onClick={() => act("cancel")} loading={busy === "cancel"} disabled={busy !== null}>
            {t("bookings.cancel")}
          </Button>
        )}
        <span className="text-sm text-ink-2">
          {canCheckIn
            ? "You can check in now."
            : now < opens
              ? `Check-in opens ${CHECKIN_EARLY_MIN} min before your slot${minsToStart < 24 * 60 ? ` (in ${duration(minsToStart - CHECKIN_EARLY_MIN)})` : ""}.`
              : "The check-in window has passed."}
        </span>
      </div>
      {error && (
        <div className="mt-3">
          <Alert>{error}</Alert>
        </div>
      )}
    </Card>
  );
}

function ActiveSession({ booking: b, onChange }: { booking: Booking; onChange: () => void }) {
  const t = useT();
  const now = useNow(1000);
  const toast = useToast();
  const [stopping, setStopping] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const session = b.session;
  if (!session || !b.charger) return null;

  const started = new Date(session.started_at).getTime();
  const ends = new Date(b.ends_at).getTime();
  const elapsedMin = Math.max(0, (Math.min(now, ends) - started) / 60_000);
  const progress = Math.min(1, (now - started) / (ends - started));
  const energy = b.charger.power_kw * (elapsedMin / 60) * EFFICIENCY;
  const remaining = Math.max(0, Math.round((ends - now) / 60_000));

  const stop = async () => {
    setStopping(true);
    setError(null);
    try {
      const { data } = await api<Session>(`/sessions/${session.id}/stop`, { method: "POST" });
      toast(`Charging stopped: ${kwh(data.energy_kwh)}, ${money(data.cost)}`);
      onChange();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "Something went wrong.");
    } finally {
      setStopping(false);
    }
  };

  return (
    <Card className="border-occupied/40 p-4">
      <BookingSummary booking={b} />
      <div className="mt-4 grid grid-cols-3 gap-3 text-center">
        <div>
          <p className="text-xs text-ink-2">Elapsed</p>
          <p className="text-lg font-semibold tabular">{clock(elapsedMin)}</p>
        </div>
        <div>
          <p className="text-xs text-ink-2">Energy (est.)</p>
          <p className="text-lg font-semibold tabular">{kwh(energy)}</p>
        </div>
        <div>
          <p className="text-xs text-ink-2">Cost so far (est.)</p>
          <p className="text-lg font-semibold tabular">{money(energy * b.charger.price_per_kwh)}</p>
        </div>
      </div>
      <div className="mt-4 h-2 overflow-hidden rounded-full bg-occupied-soft" role="progressbar" aria-valuenow={Math.round(progress * 100)} aria-valuemin={0} aria-valuemax={100} aria-label="Slot progress">
        <div className="h-full rounded-full bg-occupied transition-[width]" style={{ width: `${progress * 100}%` }} />
      </div>
      <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
        <span className="text-sm text-ink-2">{remaining > 0 ? `${duration(remaining)} left in your slot` : "Slot ending…"}</span>
        <Button variant="danger" onClick={stop} loading={stopping}>
          {t("bookings.stop")}
        </Button>
      </div>
      {error && (
        <div className="mt-3">
          <Alert>{error}</Alert>
        </div>
      )}
    </Card>
  );
}

function clock(min: number) {
  const total = Math.floor(min * 60);
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = total % 60;
  return `${h ? `${h}:` : ""}${String(m).padStart(h ? 2 : 1, "0")}:${String(s).padStart(2, "0")}`;
}
