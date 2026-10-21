"use client";

import { useState } from "react";
import useSWR from "swr";
import { LiveStrip, MetricsDashboard, RangePicker } from "@/components/metrics-dashboard";
import { Alert, Card, PageHeader, Skeleton, StatTile } from "@/components/ui";
import { dateTime } from "@/lib/format";
import type { AdminOverview } from "@/lib/types";

const ACTION_LABEL: Record<string, string> = {
  "user.role_changed": "Changed a user's role",
  "station.status_changed": "Changed a station's status",
};

export default function AdminOverviewPage() {
  const [days, setDays] = useState(30);
  const { data, error } = useSWR<AdminOverview>(`/admin/overview?days=${days}`, { keepPreviousData: true, refreshInterval: 60_000 });

  return (
    <>
      <PageHeader title="Platform overview" subtitle="Every station on the network." actions={<RangePicker value={days} onChange={setDays} />} />
      {error ? (
        <Alert>{error.message}</Alert>
      ) : !data ? (
        <div className="space-y-4">
          <Skeleton className="h-24" />
          <Skeleton className="h-72" />
        </div>
      ) : (
        <div className="space-y-6">
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <StatTile label="Drivers" value={data.counts.users.driver ?? 0} />
            <StatTile label="Operators" value={data.counts.users.operator ?? 0} />
            <StatTile
              label="Active stations"
              value={data.counts.stations.active ?? 0}
              hint={`${(data.counts.stations.maintenance ?? 0) + (data.counts.stations.inactive ?? 0)} paused or hidden`}
            />
            <StatTile label="Chargers" value={data.counts.chargers} />
          </div>
          <LiveStrip live={data.metrics.live} upcoming={data.metrics.upcoming_bookings} />
          <MetricsDashboard metrics={data.metrics} days={days} />
          <Card>
            <h2 className="border-b border-line px-4 py-3 font-semibold">Recent admin activity</h2>
            {data.recent_activity.length === 0 ? (
              <p className="px-4 py-6 text-sm text-ink-2">Nothing yet. Role and station status changes are logged here.</p>
            ) : (
              <ul className="divide-y divide-line">
                {data.recent_activity.map((a) => (
                  <li key={a.id} className="flex flex-wrap items-baseline justify-between gap-2 px-4 py-3 text-sm">
                    <span>
                      <span className="font-medium">{a.admin}</span> · {ACTION_LABEL[a.action] ?? a.action}
                      {a.subject && <span className="text-ink-2"> ({a.subject})</span>}
                      {a.details?.from && (
                        <span className="text-ink-2">
                          : {a.details.from} → {a.details.to}
                        </span>
                      )}
                    </span>
                    <span className="text-xs text-muted">{dateTime(a.created_at)}</span>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      )}
    </>
  );
}
