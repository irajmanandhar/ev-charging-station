"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { use, useState } from "react";
import useSWR from "swr";
import { useToast } from "@/components/toast";
import { Alert, Button, Card, Field, Input, PageHeader, Select, Skeleton, StatusBadge } from "@/components/ui";
import { ApiError, api } from "@/lib/api";
import { CONNECTOR_LABEL, money } from "@/lib/format";
import type { Charger, Station } from "@/lib/types";
import { StationForm } from "../station-form";

export function ManageStation({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const router = useRouter();
  const toast = useToast();
  const { data: station, error, mutate } = useSWR<Station, ApiError>(`/operator/stations/${id}`);
  const [deleting, setDeleting] = useState(false);

  if (error) return <Alert>{error.status === 403 ? "This station belongs to another operator." : error.message}</Alert>;
  if (!station) return <Skeleton className="h-96" />;

  const remove = async () => {
    if (!window.confirm(`Delete ${station.name}? This can't be undone.`)) return;
    setDeleting(true);
    try {
      await api(`/operator/stations/${station.id}`, { method: "DELETE" });
      toast("Station deleted.");
      router.push("/operator/stations");
    } catch (e) {
      toast(e instanceof ApiError ? e.message : "Couldn't delete the station.", "error");
    } finally {
      setDeleting(false);
    }
  };

  return (
    <div className="mx-auto max-w-3xl">
      <Link href="/operator/stations" className="text-sm font-medium text-ink-2 hover:text-ink">
        ← Stations
      </Link>
      <div className="mt-3">
        <PageHeader
          title={station.name}
          subtitle={
            <Link href={`/stations/${station.id}`} className="text-brand hover:underline">
              View public page →
            </Link>
          }
        />
      </div>

      <div className="space-y-8">
        <Chargers station={station} onChange={() => mutate()} />

        <section>
          <h2 className="mb-3 text-lg font-semibold">Station details</h2>
          <StationForm
            station={station}
            onSaved={(s) => {
              mutate(s, { revalidate: true });
              toast("Saved.");
            }}
          />
        </section>

        <Card className="flex flex-wrap items-center justify-between gap-3 border-danger/30 p-4">
          <div>
            <p className="font-medium">Delete station</p>
            <p className="text-sm text-ink-2">Only possible when there are no upcoming bookings. Consider maintenance mode instead.</p>
          </div>
          <Button variant="danger" onClick={remove} loading={deleting}>
            Delete station
          </Button>
        </Card>
      </div>
    </div>
  );
}

const EMPTY = { label: "", connector_type: "CCS2", power_kw: "60", price_per_kwh: "20" };

function Chargers({ station, onChange }: { station: Station; onChange: () => void }) {
  const toast = useToast();
  const [adding, setAdding] = useState(false);
  const [form, setForm] = useState(EMPTY);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const chargers = station.chargers ?? [];

  const add = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await api(`/operator/stations/${station.id}/chargers`, {
        method: "POST",
        body: { ...form, power_kw: Number(form.power_kw), price_per_kwh: Number(form.price_per_kwh) },
      });
      toast(`Charger ${form.label} added.`);
      setForm(EMPTY);
      setAdding(false);
      onChange();
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError("Something went wrong.", 0));
    } finally {
      setSaving(false);
    }
  };

  return (
    <section>
      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-lg font-semibold">Chargers</h2>
        {!adding && (
          <Button variant="secondary" onClick={() => setAdding(true)}>
            Add charger
          </Button>
        )}
      </div>

      <Card>
        {chargers.length === 0 && !adding && (
          <p className="px-4 py-6 text-sm text-ink-2">No chargers yet. Add one so drivers can book this station.</p>
        )}
        <ul className="divide-y divide-line">
          {chargers.map((c) => (
            <ChargerItem key={c.id} charger={c} onChange={onChange} />
          ))}
        </ul>

        {adding && (
          <form onSubmit={add} className="border-t border-line p-4" noValidate>
            <div className="grid gap-3 sm:grid-cols-4">
              <Field label="Label" error={error?.field("label")}>
                <Input value={form.label} onChange={(e) => setForm({ ...form, label: e.target.value.toUpperCase() })} placeholder="A1" maxLength={20} />
              </Field>
              <Field label="Connector" error={error?.field("connector_type")}>
                <Select value={form.connector_type} onChange={(e) => setForm({ ...form, connector_type: e.target.value })}>
                  {Object.entries(CONNECTOR_LABEL).map(([v, l]) => (
                    <option key={v} value={v}>
                      {l}
                    </option>
                  ))}
                </Select>
              </Field>
              <Field label="Power (kW)" error={error?.field("power_kw")}>
                <Input type="number" min={3} max={400} value={form.power_kw} onChange={(e) => setForm({ ...form, power_kw: e.target.value })} />
              </Field>
              <Field label="Price (NPR/kWh)" error={error?.field("price_per_kwh")}>
                <Input type="number" min={0} step="0.5" value={form.price_per_kwh} onChange={(e) => setForm({ ...form, price_per_kwh: e.target.value })} />
              </Field>
            </div>
            <div className="mt-4 flex justify-end gap-2">
              <Button type="button" variant="ghost" onClick={() => setAdding(false)}>
                Cancel
              </Button>
              <Button type="submit" loading={saving}>
                Add charger
              </Button>
            </div>
          </form>
        )}
      </Card>
    </section>
  );
}

function ChargerItem({ charger: c, onChange }: { charger: Charger; onChange: () => void }) {
  const toast = useToast();
  const [busy, setBusy] = useState(false);
  const [price, setPrice] = useState(String(c.price_per_kwh));

  const patch = async (body: Record<string, unknown>, msg: string) => {
    setBusy(true);
    try {
      await api(`/operator/chargers/${c.id}`, { method: "PATCH", body });
      toast(msg);
      onChange();
    } catch (e) {
      toast(e instanceof ApiError ? e.message : "Update failed.", "error");
    } finally {
      setBusy(false);
    }
  };

  const remove = async () => {
    if (!window.confirm(`Remove charger ${c.label}?`)) return;
    setBusy(true);
    try {
      await api(`/operator/chargers/${c.id}`, { method: "DELETE" });
      toast(`Charger ${c.label} removed.`);
      onChange();
    } catch (e) {
      toast(e instanceof ApiError ? e.message : "Couldn't remove the charger.", "error");
    } finally {
      setBusy(false);
    }
  };

  return (
    <li className="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3">
      <span className="grid size-10 place-items-center rounded-lg border border-line font-mono text-sm font-semibold">{c.label}</span>
      <div className="min-w-40 flex-1">
        <p className="font-medium">
          {CONNECTOR_LABEL[c.connector_type]} · {c.power_kw} kW
        </p>
        <p className="text-sm text-ink-2">{money(c.price_per_kwh, 2)}/kWh</p>
      </div>
      {c.live_status && <StatusBadge status={c.live_status} />}
      <div className="flex w-full flex-wrap items-center gap-2 md:w-auto">
        <form
          className="flex items-center gap-1"
          onSubmit={(e) => {
            e.preventDefault();
            patch({ price_per_kwh: Number(price) }, `Price for ${c.label} updated.`);
          }}
        >
          <Input
            type="number"
            min={0}
            step="0.5"
            value={price}
            onChange={(e) => setPrice(e.target.value)}
            className="h-9 w-20"
            aria-label={`Price per kWh for ${c.label}`}
          />
          <Button type="submit" variant="ghost" className="h-9 px-2 text-xs" disabled={busy || Number(price) === c.price_per_kwh}>
            Save
          </Button>
        </form>
        <Select
          value={c.status}
          disabled={busy}
          onChange={(e) => patch({ status: e.target.value }, `${c.label} set to ${e.target.value}.`)}
          className="h-9 w-auto"
          aria-label={`Status for ${c.label}`}
        >
          <option value="available">In service</option>
          <option value="maintenance">Maintenance</option>
          <option value="offline">Offline</option>
        </Select>
        <Button variant="ghost" className="h-9 px-2 text-xs text-danger" onClick={remove} disabled={busy}>
          Remove
        </Button>
      </div>
    </li>
  );
}
