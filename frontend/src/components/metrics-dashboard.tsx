"use client";

import { useState } from "react";
import { ColumnChart, RankBars, TrendChart } from "./charts/charts";
import { Card, StatTile, StatusBadge, cx } from "./ui";
import { CONNECTOR_LABEL, compact, duration, kwh, money, percent } from "@/lib/format";
import type { LiveStatus, Metrics } from "@/lib/types";

const hourLabel = (h: string | number) => `${String(h).padStart(2, "0")}:00`;

export function RangePicker({ value, onChange }: { value: number; onChange: (d: number) => void }) {
  return (
    <div className="inline-flex rounded-lg border border-line bg-surface p-1" role="radiogroup" aria-label="Date range">
      {[7, 30, 90].map((d) => (
        <button
          key={d}
          role="radio"
          aria-checked={value === d}
          onClick={() => onChange(d)}
          className={cx(
            "rounded-md px-3 py-1.5 text-sm font-medium",
            value === d ? "bg-brand-soft text-ink" : "text-ink-2 hover:text-ink",
          )}
        >
          {d} days
        </button>
      ))}
    </div>
  );
}

export function LiveStrip({ live, upcoming }: { live: Metrics["live"]; upcoming: number }) {
  const order: LiveStatus[] = ["available", "occupied", "reserved", "offline"];
  return (
    <Card className="flex flex-wrap items-center gap-x-6 gap-y-3 px-4 py-3">
      <span className="text-sm font-medium text-ink">Right now</span>
      {order.map((s) => (
        <span key={s} className="flex items-center gap-2 text-sm">
          <StatusBadge status={s} />
          <span className="font-semibold tabular">{live[s] ?? 0}</span>
        </span>
      ))}
      <span className="text-sm text-ink-2 sm:ml-auto">
        <span className="font-semibold text-ink tabular">{upcoming}</span> upcoming bookings
      </span>
    </Card>
  );
}

export function MetricsDashboard({ metrics: m, days }: { metrics: Metrics; days: number }) {
  const [metric, setMetric] = useState<"revenue" | "energy_kwh" | "sessions">("revenue");
  const [showTable, setShowTable] = useState(false);

  const metricFormat = {
    revenue: money,
    energy_kwh: (v: number) => kwh(v, 0),
    sessions: (v: number) => `${v} sessions`,
  }[metric];

  return (
    <div className="space-y-6">
      {/* Hero + tiles */}
      <div className="grid gap-3 lg:grid-cols-[1.3fr_2fr]">
        <Card className="flex flex-col justify-between p-5">
          <p className="text-sm text-ink-2">Revenue, last {days} days</p>
          <p className="mt-2 text-5xl font-semibold tracking-tight">{money(m.totals.revenue)}</p>
          <p className="mt-3 text-sm text-ink-2">
            from {m.totals.sessions.toLocaleString()} sessions and {kwh(m.totals.energy_kwh, 0)} delivered
          </p>
        </Card>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
          <StatTile label="Sessions" value={m.totals.sessions.toLocaleString()} />
          <StatTile label="Energy delivered" value={`${compact(m.totals.energy_kwh)} kWh`} />
          <StatTile label="Utilization" value={percent(m.totals.utilization)} hint="Charging time ÷ open charger time" />
          <StatTile label="Avg. session" value={duration(m.totals.avg_session_minutes)} />
          <StatTile label="No-show rate" value={percent(m.totals.no_show_rate)} hint={`${m.totals.bookings.toLocaleString()} bookings`} />
          <StatTile label="Cancellation rate" value={percent(m.totals.cancellation_rate)} />
        </div>
      </div>

      {/* Daily trend */}
      <Card className="p-4 sm:p-5">
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 className="font-semibold">
              {metric === "revenue" ? "Revenue" : metric === "energy_kwh" ? "Energy delivered" : "Sessions"} per day
            </h2>
            <p className="text-sm text-ink-2">Station local time ({m.range.timezone})</p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <div className="inline-flex rounded-lg bg-surface-2 p-1" role="radiogroup" aria-label="Metric">
              {(
                [
                  ["revenue", "Revenue"],
                  ["energy_kwh", "Energy"],
                  ["sessions", "Sessions"],
                ] as const
              ).map(([k, l]) => (
                <button
                  key={k}
                  role="radio"
                  aria-checked={metric === k}
                  onClick={() => setMetric(k)}
                  className={cx(
                    "rounded-md px-2.5 py-1 text-xs font-medium",
                    metric === k ? "bg-surface text-ink shadow-sm" : "text-ink-2",
                  )}
                >
                  {l}
                </button>
              ))}
            </div>
            <button onClick={() => setShowTable((s) => !s)} className="text-xs font-medium text-brand hover:underline">
              {showTable ? "Show chart" : "Show table"}
            </button>
          </div>
        </div>
        {showTable ? (
          <div className="max-h-72 overflow-y-auto">
            <table className="w-full text-sm">
              <thead className="sticky top-0 bg-surface text-left text-ink-2">
                <tr className="border-b border-line">
                  <th className="py-2 font-medium">Date</th>
                  <th className="py-2 text-right font-medium">Sessions</th>
                  <th className="py-2 text-right font-medium">Energy</th>
                  <th className="py-2 text-right font-medium">Revenue</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line tabular">
                {m.daily.map((d) => (
                  <tr key={d.date}>
                    <td className="py-1.5">{d.date}</td>
                    <td className="py-1.5 text-right">{d.sessions}</td>
                    <td className="py-1.5 text-right">{kwh(d.energy_kwh)}</td>
                    <td className="py-1.5 text-right">{money(d.revenue)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <TrendChart data={m.daily} dataKey={metric} format={metricFormat} />
        )}
      </Card>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card className="p-4 sm:p-5">
          <h2 className="font-semibold">Sessions by start hour</h2>
          <p className="mb-4 text-sm text-ink-2">When drivers plug in, across the period</p>
          <ColumnChart
            data={m.hourly}
            xKey="hour"
            yKey="sessions"
            format={(v) => `${v} sessions`}
            xFormat={(h) => hourLabel(h)}
          />
        </Card>

        <Card className="p-4 sm:p-5">
          <h2 className="font-semibold">Energy by connector</h2>
          <p className="mb-4 text-sm text-ink-2">kWh delivered per connector type</p>
          {m.by_connector.length === 0 ? (
            <p className="text-sm text-ink-2">No sessions in this period.</p>
          ) : (
            <RankBars
              rows={m.by_connector.map((c) => ({
                label: CONNECTOR_LABEL[c.connector_type] ?? c.connector_type,
                value: c.energy_kwh,
                sub: `${c.sessions} sessions`,
              }))}
              format={(v) => kwh(v, 0)}
            />
          )}
        </Card>
      </div>

      <Card className="p-4 sm:p-5">
        <h2 className="font-semibold">Stations by revenue</h2>
        <p className="mb-4 text-sm text-ink-2">With sessions and utilization for the period</p>
        {m.by_station.length === 0 ? (
          <p className="text-sm text-ink-2">No stations yet.</p>
        ) : (
          <RankBars
            rows={m.by_station.map((s) => ({
              label: s.name,
              value: s.revenue,
              sub: `${s.sessions} sessions · ${kwh(s.energy_kwh, 0)} · ${percent(s.utilization)} utilization`,
            }))}
            format={money}
          />
        )}
      </Card>
    </div>
  );
}
