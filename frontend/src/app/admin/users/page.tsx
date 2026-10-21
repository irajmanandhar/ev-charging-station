"use client";

import { useEffect, useState } from "react";
import useSWR from "swr";
import { useToast } from "@/components/toast";
import { Alert, Button, Card, Input, PageHeader, Select, Skeleton } from "@/components/ui";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { date } from "@/lib/format";
import type { Paginated, Role, User } from "@/lib/types";

export default function AdminUsersPage() {
  const { user: me } = useAuth();
  const toast = useToast();
  const [q, setQ] = useState("");
  const [query, setQuery] = useState("");
  const [role, setRole] = useState("");
  const [page, setPage] = useState(1);

  useEffect(() => {
    const t = setTimeout(() => {
      setQuery(q);
      setPage(1);
    }, 300);
    return () => clearTimeout(t);
  }, [q]);

  const key = `/admin/users?page=${page}${query ? `&q=${encodeURIComponent(query)}` : ""}${role ? `&role=${role}` : ""}`;
  const { data, error, mutate } = useSWR<Paginated<User>>(key, { keepPreviousData: true });

  const changeRole = async (u: User, next: Role) => {
    if (!window.confirm(`Make ${u.name} ${next === "admin" ? "an admin" : `a ${next}`}?`)) return;
    try {
      await api(`/admin/users/${u.id}/role`, { method: "PATCH", body: { role: next } });
      toast(`${u.name} is now ${next === "admin" ? "an admin" : `a ${next}`}.`);
      mutate();
    } catch (e) {
      toast(e instanceof ApiError ? e.message : "Couldn't change the role.", "error");
    }
  };

  return (
    <>
      <PageHeader title="Users" subtitle="Promote drivers to operators, or manage admins." />
      <div className="mb-4 flex flex-wrap gap-2">
        <Input type="search" placeholder="Search name or email" value={q} onChange={(e) => setQ(e.target.value)} className="max-w-xs" aria-label="Search users" />
        <Select
          value={role}
          onChange={(e) => {
            setRole(e.target.value);
            setPage(1);
          }}
          className="w-auto"
          aria-label="Filter by role"
        >
          <option value="">All roles</option>
          <option value="driver">Drivers</option>
          <option value="operator">Operators</option>
          <option value="admin">Admins</option>
        </Select>
      </div>

      {error ? (
        <Alert>{error.message}</Alert>
      ) : !data ? (
        <Skeleton className="h-96" />
      ) : (
        <>
          <Card className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-sm">
              <thead className="text-left text-ink-2">
                <tr className="border-b border-line">
                  <th className="px-4 py-2.5 font-medium">Name</th>
                  <th className="px-4 py-2.5 font-medium">Joined</th>
                  <th className="px-4 py-2.5 text-right font-medium">Bookings</th>
                  <th className="px-4 py-2.5 text-right font-medium">Stations</th>
                  <th className="px-4 py-2.5 font-medium">Role</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line">
                {data.items.map((u) => (
                  <tr key={u.id}>
                    <td className="px-4 py-2.5">
                      <p className="font-medium">{u.name}</p>
                      <p className="text-xs text-muted">{u.email}</p>
                    </td>
                    <td className="px-4 py-2.5 text-ink-2">{date(u.created_at)}</td>
                    <td className="px-4 py-2.5 text-right tabular">{u.bookings_count ?? 0}</td>
                    <td className="px-4 py-2.5 text-right tabular">{u.stations_count ?? 0}</td>
                    <td className="px-4 py-2.5">
                      <Select
                        value={u.role}
                        disabled={u.id === me?.id}
                        onChange={(e) => changeRole(u, e.target.value as Role)}
                        className="h-9 w-auto"
                        aria-label={`Role for ${u.name}`}
                        title={u.id === me?.id ? "You can't change your own role" : undefined}
                      >
                        <option value="driver">Driver</option>
                        <option value="operator">Operator</option>
                        <option value="admin">Admin</option>
                      </Select>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>
          <div className="mt-4 flex items-center justify-between text-sm text-ink-2">
            <span>{data.meta.total} users</span>
            <div className="flex items-center gap-2">
              <Button variant="secondary" className="h-9" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                Previous
              </Button>
              <span className="tabular">
                {data.meta.current_page} / {data.meta.last_page}
              </span>
              <Button variant="secondary" className="h-9" disabled={page >= data.meta.last_page} onClick={() => setPage((p) => p + 1)}>
                Next
              </Button>
            </div>
          </div>
        </>
      )}
    </>
  );
}
