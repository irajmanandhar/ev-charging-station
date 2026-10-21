"use client";

import Link from "next/link";
import useSWR from "swr";
import { useToast } from "@/components/toast";
import { Alert, Card, PageHeader, Select, Skeleton } from "@/components/ui";
import { ApiError, api } from "@/lib/api";
import type { Station } from "@/lib/types";

export default function AdminStationsPage() {
  const toast = useToast();
  const { data, error, mutate } = useSWR<Station[]>("/admin/stations");

  const setStatus = async (s: Station, status: string) => {
    try {
      await api(`/admin/stations/${s.id}/status`, { method: "PATCH", body: { status } });
      toast(`${s.name} set to ${status}.`);
      mutate();
    } catch (e) {
      toast(e instanceof ApiError ? e.message : "Update failed.", "error");
    }
  };

  return (
    <>
      <PageHeader title="All stations" subtitle="Pause or hide any station on the network." />
      {error ? (
        <Alert>{error.message}</Alert>
      ) : !data ? (
        <Skeleton className="h-96" />
      ) : (
        <Card className="overflow-x-auto">
          <table className="w-full min-w-[720px] text-sm">
            <thead className="text-left text-ink-2">
              <tr className="border-b border-line">
                <th className="px-4 py-2.5 font-medium">Station</th>
                <th className="px-4 py-2.5 font-medium">Operator</th>
                <th className="px-4 py-2.5 text-right font-medium">Chargers</th>
                <th className="px-4 py-2.5 text-right font-medium">Free now</th>
                <th className="px-4 py-2.5 font-medium">Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-line">
              {data.map((s) => (
                <tr key={s.id}>
                  <td className="px-4 py-2.5">
                    <Link href={`/operator/stations/${s.id}`} className="font-medium hover:underline">
                      {s.name}
                    </Link>
                    <p className="text-xs text-muted">{s.city}</p>
                  </td>
                  <td className="px-4 py-2.5 text-ink-2">{s.operator?.name}</td>
                  <td className="px-4 py-2.5 text-right tabular">{s.summary?.chargers ?? 0}</td>
                  <td className="px-4 py-2.5 text-right tabular">{s.summary?.available ?? 0}</td>
                  <td className="px-4 py-2.5">
                    <Select value={s.status} onChange={(e) => setStatus(s, e.target.value)} className="h-9 w-auto" aria-label={`Status for ${s.name}`}>
                      <option value="active">Active</option>
                      <option value="maintenance">Maintenance</option>
                      <option value="inactive">Inactive</option>
                    </Select>
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
