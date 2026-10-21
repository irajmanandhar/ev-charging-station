"use client";

import Link from "next/link";
import useSWR from "swr";
import { Alert, ButtonLink, Card, Empty, PageHeader, Skeleton, cx } from "@/components/ui";
import { hours } from "@/lib/format";
import type { Station } from "@/lib/types";

export default function OperatorStationsPage() {
  const { data, error } = useSWR<Station[]>("/operator/stations", { refreshInterval: 30_000 });

  return (
    <>
      <PageHeader
        title="Stations"
        subtitle="Manage locations, opening hours and chargers."
        actions={<ButtonLink href="/operator/stations/new">Add station</ButtonLink>}
      />
      {error ? (
        <Alert>{error.message}</Alert>
      ) : !data ? (
        <Skeleton className="h-64" />
      ) : data.length === 0 ? (
        <Empty title="No stations yet" action={<ButtonLink href="/operator/stations/new">Add your first station</ButtonLink>} />
      ) : (
        <Card className="overflow-x-auto">
          <table className="w-full min-w-[720px] text-sm">
            <thead className="text-left text-ink-2">
              <tr className="border-b border-line">
                <th className="px-4 py-2.5 font-medium">Station</th>
                <th className="px-4 py-2.5 font-medium">Hours</th>
                <th className="px-4 py-2.5 font-medium">Chargers</th>
                <th className="px-4 py-2.5 font-medium">Free now</th>
                <th className="px-4 py-2.5 font-medium">Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-line">
              {data.map((s) => (
                <tr key={s.id} className="hover:bg-surface-2">
                  <td className="px-4 py-3">
                    <Link href={`/operator/stations/${s.id}`} className="font-medium hover:underline">
                      {s.name}
                    </Link>
                    <p className="text-xs text-muted">
                      {s.address}
                      {s.city ? `, ${s.city}` : ""}
                      {s.operator ? ` · ${s.operator.name}` : ""}
                    </p>
                  </td>
                  <td className="px-4 py-3 text-ink-2">{hours(s)}</td>
                  <td className="px-4 py-3 tabular">{s.summary?.chargers ?? 0}</td>
                  <td className="px-4 py-3 tabular">{s.summary?.available ?? 0}</td>
                  <td className="px-4 py-3">
                    <span
                      className={cx(
                        "rounded-full px-2.5 py-0.5 text-xs font-medium capitalize",
                        s.status === "active" ? "bg-available-soft" : s.status === "maintenance" ? "bg-occupied-soft" : "bg-offline-soft",
                      )}
                    >
                      {s.status}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}
    </>
  );
}
