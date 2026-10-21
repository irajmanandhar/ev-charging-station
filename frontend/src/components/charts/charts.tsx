"use client";

import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
  type TooltipContentProps,
} from "recharts";
import type { NameType, ValueType } from "recharts/types/component/DefaultTooltipContent";
import { compact } from "@/lib/format";

/*
 * Single-series charts, so no legend box: the card title names the series.
 * Marks follow the house spec: <=24px bars with 4px rounded data-ends, 2px lines,
 * hairline solid gridlines, muted axis text, hover tooltip on every chart.
 */

const SERIES = "var(--chart-series-1)";
const axis = { fill: "var(--chart-axis)", fontSize: 12 };

function ChartTooltip({
  active,
  payload,
  label,
  format,
  labelFormat,
}: Partial<TooltipContentProps<ValueType, NameType>> & {
  format: (v: number) => string;
  labelFormat?: (l: string) => string;
}) {
  if (!active || !payload?.length) return null;
  return (
    <div className="rounded-lg border border-line bg-surface px-3 py-2 text-sm shadow-lg">
      <p className="text-xs text-ink-2">{labelFormat ? labelFormat(String(label)) : label}</p>
      <p className="mt-0.5 flex items-center gap-2 font-semibold text-ink">
        <span aria-hidden className="size-2 rounded-full" style={{ background: SERIES }} />
        {format(Number(payload[0].value))}
      </p>
    </div>
  );
}

const dayLabel = (d: string) =>
  new Intl.DateTimeFormat("en-GB", { day: "numeric", month: "short", timeZone: "UTC" }).format(new Date(`${d}T12:00:00Z`));

export function TrendChart({
  data,
  dataKey,
  format,
  height = 240,
}: {
  data: { date: string }[];
  dataKey: string;
  format: (v: number) => string;
  height?: number;
}) {
  return (
    <ResponsiveContainer width="100%" height={height}>
      <AreaChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
        <CartesianGrid vertical={false} stroke="var(--chart-grid)" />
        <XAxis
          dataKey="date"
          tickFormatter={dayLabel}
          tick={axis}
          tickLine={false}
          axisLine={{ stroke: "var(--chart-grid)" }}
          minTickGap={24}
        />
        <YAxis tick={axis} tickLine={false} axisLine={false} width={44} tickFormatter={(v) => compact(v)} />
        <Tooltip
          cursor={{ stroke: "var(--chart-axis)", strokeWidth: 1 }}
          content={(p) => <ChartTooltip {...p} format={format} labelFormat={dayLabel} />}
        />
        <Area
          type="monotone"
          dataKey={dataKey}
          stroke={SERIES}
          strokeWidth={2}
          fill={SERIES}
          fillOpacity={0.1}
          activeDot={{ r: 5, stroke: "var(--chart-surface)", strokeWidth: 2, fill: SERIES }}
          dot={false}
          isAnimationActive={false}
        />
      </AreaChart>
    </ResponsiveContainer>
  );
}

export function ColumnChart({
  data,
  xKey,
  yKey,
  format,
  xFormat,
  height = 220,
}: {
  data: object[];
  xKey: string;
  yKey: string;
  format: (v: number) => string;
  xFormat?: (v: string) => string;
  height?: number;
}) {
  return (
    <ResponsiveContainer width="100%" height={height}>
      <BarChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: 0 }} barCategoryGap={2}>
        <CartesianGrid vertical={false} stroke="var(--chart-grid)" />
        <XAxis
          dataKey={xKey}
          tick={axis}
          tickLine={false}
          axisLine={{ stroke: "var(--chart-grid)" }}
          tickFormatter={xFormat}
          interval="preserveStartEnd"
          minTickGap={8}
        />
        <YAxis tick={axis} tickLine={false} axisLine={false} width={36} allowDecimals={false} tickFormatter={(v) => compact(v)} />
        <Tooltip
          cursor={{ fill: "var(--surface-2)" }}
          content={(p) => <ChartTooltip {...p} format={format} labelFormat={xFormat} />}
        />
        <Bar dataKey={yKey} fill={SERIES} radius={[4, 4, 0, 0]} maxBarSize={24} isAnimationActive={false} />
      </BarChart>
    </ResponsiveContainer>
  );
}

/** Horizontal bars with the value at the tip — used for ranked lists (stations, connectors). */
export function RankBars({
  rows,
  format,
}: {
  rows: { label: string; value: number; sub?: string }[];
  format: (v: number) => string;
}) {
  const max = Math.max(1, ...rows.map((r) => r.value));
  return (
    <ul className="space-y-3">
      {rows.map((r) => (
        <li key={r.label} className="group" title={`${r.label}: ${format(r.value)}`}>
          <div className="mb-1 flex items-baseline justify-between gap-3 text-sm">
            <span className="truncate text-ink">{r.label}</span>
            <span className="shrink-0 font-medium tabular text-ink">{format(r.value)}</span>
          </div>
          <div className="h-2.5 w-full">
            <div
              className="h-full rounded-r-[4px] transition-opacity group-hover:opacity-80"
              style={{ width: `${Math.max(1, (r.value / max) * 100)}%`, background: SERIES }}
            />
          </div>
          {r.sub && <p className="mt-0.5 text-xs text-muted">{r.sub}</p>}
        </li>
      ))}
    </ul>
  );
}
