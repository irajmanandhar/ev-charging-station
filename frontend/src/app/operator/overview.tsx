"use client";

import { useState } from "react";
import useSWR from "swr";
import { LiveStrip, MetricsDashboard, RangePicker } from "@/components/metrics-dashboard";
import { useToast } from "@/components/toast";
import { Alert, BookingStatusBadge, Button, ButtonLink, Card, Empty, PageHeader, Select, Skeleton } from "@/components/ui";
import { ApiError, api, downloadCsv } from "@/lib/api";
import { addDays, date, time, ymd } from "@/lib/format";
import { useT } from "@/lib/i18n";
import type { Booking, Metrics, Station } from "@/lib/types";

export function OperatorOverview() {
  const t = useT();
  const toast = useToast();
  const [exporting, setExporting] = useState(false);
  const [days, setDays] = useState(30);
  const [stationId, setStationId] = useState("");
  const { data: stations } = useSWR<Station[]>("/operator/stations");
  const { data: metrics, error } = useSWR<Metrics>(
    `/operator/metrics?days=${days}${stationId ? `&station_id=${stationId}` : ""}`,
    { refreshInterval: 60_000, keepPreviousData: true },
  );

  const exportCsv = async () => {
    setExporting(true);
    try {
      const to = ymd(new Date());
      const from = addDays(to, -(days - 1));
      const blob = await downloadCsv(`/operator/export/bookings?from=${from}&to=${to}${stationId ? `&station_id=${stationId}` : ""}`);
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = `bookings-${from}-to-${to}.csv`;
      a.click();
      URL.revokeObjectURL(url);
    } catch (e) {
      toast(e instanceof ApiError ? e.message : "Couldn't export.", "error");
    } finally {
      setExporting(false);
    }
  };

  if (stations && stations.length === 0) {
    return (
      <>
        <PageHeader title="Operator overview" />
        <Empty title="Add your first station" action={<ButtonLink href="/operator/stations/new">Add a station</ButtonLink>}>
          Once your station and its chargers are listed, drivers can find and book them, and your usage numbers show up here.
        </Empty>
      </>
    );
  }

  return (
    <>
      <PageHeader
        title="Operator overview"
        subtitle="Usage, revenue and live status across your stations."
        actions={
          <>
            <Select value={stationId} onChange={(e) => setStationId(e.target.value)} aria-label="Station" className="w-full sm:w-60">
              <option value="">All stations</option>
              {stations?.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </Select>
            <RangePicker value={days} onChange={setDays} />
            <Button variant="secondary" onClick={exportCsv} loading={exporting}>
              {t("operator.export")}
            </Button>
          </>
        }
      />

      {error ? (
        <Alert>{error.message}</Alert>
      ) : !metrics ? (
        <div className="space-y-4">
          <Skeleton className="h-14" />
          <Skeleton className="h-40" />
          <Skeleton className="h-72" />
        </div>
      ) : (
        <div className="space-y-6">
          <LiveStrip live={metrics.live} upcoming={metrics.upcoming_bookings} />
          <TodaysBookings stationId={stationId} />
          <MetricsDashboard metrics={metrics} days={days} />
        </div>
      )}
    </>
  );
}

function TodaysBookings({ stationId }: { stationId: string }) {
  const today = ymd(new Date());
  const [day, setDay] = useState(today);
  const toast = useToast();
  const [stopping, setStopping] = useState<number | null>(null);
  const { data, mutate } = useSWR<Booking[]>(
    `/operator/bookings?date=${day}${stationId ? `&station_id=${stationId}` : ""}`,
    { refreshInterval: 30_000 },
  );

  const stop = async (b: Booking) => {
    if (!b.session) return;
    if (!window.confirm(`Stop the charging session on ${b.charger?.label} for ${b.user?.name}?`)) return;
    setStopping(b.id);
    try {
      await api(`/sessions/${b.session.id}/stop`, { method: "POST" });
      toast("Session stopped.");
      mutate();
    } catch (e) {
      toast(e instanceof ApiError ? e.message : "Couldn't stop the session.", "error");
    } finally {
      setStopping(null);
    }
  };

  const rows = (data ?? []).filter((b) => b.status !== "cancelled");

  return (
    <Card>
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
        <h2 className="font-semibold">Bookings · {day === today ? "today" : date(`${day}T06:00:00Z`)}</h2>
        <div className="flex gap-1">
          <Button variant="ghost" className="h-8 px-2" onClick={() => setDay(addDays(day, -1))} aria-label="Previous day">
            ←
          </Button>
          <Button variant="ghost" className="h-8 px-3 text-xs" onClick={() => setDay(today)} disabled={day === today}>
            Today
          </Button>
          <Button variant="ghost" className="h-8 px-2" onClick={() => setDay(addDays(day, 1))} aria-label="Next day">
            →
          </Button>
        </div>
      </div>
      {!data ? (
        <div className="p-4">
          <Skeleton className="h-24" />
        </div>
      ) : rows.length === 0 ? (
        <p className="px-4 py-6 text-sm text-ink-2">No bookings for this day.</p>
      ) : (
        <div className="max-h-96 overflow-auto">
          <table className="w-full min-w-[640px] text-sm">
            <thead className="sticky top-0 bg-surface text-left text-ink-2">
              <tr className="border-b border-line">
                <th className="px-4 py-2 font-medium">Time</th>
                <th className="px-4 py-2 font-medium">Station · charger</th>
                <th className="px-4 py-2 font-medium">Driver</th>
                <th className="px-4 py-2 font-medium">Status</th>
                <th className="px-4 py-2" />
              </tr>
            </thead>
            <tbody className="divide-y divide-line">
              {rows.map((b) => (
                <tr key={b.id}>
                  <td className="px-4 py-2.5 whitespace-nowrap tabular">
                    {time(b.starts_at)}–{time(b.ends_at)}
                  </td>
                  <td className="px-4 py-2.5">
                    {b.charger?.station?.name} · <span className="font-medium">{b.charger?.label}</span>
                  </td>
                  <td className="px-4 py-2.5">
                    {b.user?.name}
                    {b.vehicle && <span className="block text-xs text-muted">{b.vehicle}</span>}
                  </td>
                  <td className="px-4 py-2.5">
                    <BookingStatusBadge status={b.status} />
                  </td>
                  <td className="px-4 py-2.5 text-right">
                    {b.status === "active" && b.session && (
                      <Button variant="danger" className="h-8 px-3 text-xs" loading={stopping === b.id} onClick={() => stop(b)}>
                        Stop session
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  );
}
