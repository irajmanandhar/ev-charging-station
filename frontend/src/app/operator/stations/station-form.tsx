"use client";

import dynamic from "next/dynamic";
import { useState } from "react";
import { Alert, Button, Card, Field, Input, Select, cx, inputClass } from "@/components/ui";
import { ApiError, api } from "@/lib/api";
import type { Station } from "@/lib/types";

const LocationPicker = dynamic(() => import("@/components/location-picker"), {
  ssr: false,
  loading: () => <div className="h-full w-full animate-pulse bg-surface-2" />,
});

const AMENITIES = ["Parking", "Restroom", "Cafe", "Wi-Fi", "Food court", "Convenience store", "24/7 security", "Lake view"];

export function StationForm({
  station,
  onSaved,
  canSetStatus = true,
}: {
  station?: Station;
  onSaved: (s: Station) => void;
  canSetStatus?: boolean;
}) {
  const [form, setForm] = useState({
    name: station?.name ?? "",
    description: station?.description ?? "",
    address: station?.address ?? "",
    city: station?.city ?? "Kathmandu",
    latitude: station?.latitude ?? (null as number | null),
    longitude: station?.longitude ?? (null as number | null),
    is_24_hours: station?.is_24_hours ?? false,
    opens_at: station?.opens_at ?? "07:00",
    closes_at: station?.closes_at ?? "21:00",
    amenities: station?.amenities ?? ["Parking"],
    status: station?.status ?? "active",
  });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);

  const set = <K extends keyof typeof form>(k: K, v: (typeof form)[K]) => setForm((f) => ({ ...f, [k]: v }));

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError(null);
    const body = {
      ...form,
      opens_at: form.is_24_hours ? null : form.opens_at,
      closes_at: form.is_24_hours ? null : form.closes_at,
      ...(canSetStatus ? {} : { status: undefined }),
    };
    try {
      const { data } = station
        ? await api<Station>(`/operator/stations/${station.id}`, { method: "PATCH", body })
        : await api<Station>("/operator/stations", { method: "POST", body });
      onSaved(data);
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError("Something went wrong.", 0));
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={save} noValidate>
      <Card className="divide-y divide-line">
        <div className="grid gap-4 p-4 sm:grid-cols-2 sm:p-5">
          <div className="sm:col-span-2">
            <Field label="Station name" error={error?.field("name")}>
              <Input value={form.name} onChange={(e) => set("name", e.target.value)} required maxLength={120} />
            </Field>
          </div>
          <Field label="Street / area" error={error?.field("address")}>
            <Input value={form.address} onChange={(e) => set("address", e.target.value)} />
          </Field>
          <Field label="City" error={error?.field("city")}>
            <Input value={form.city} onChange={(e) => set("city", e.target.value)} />
          </Field>
          <div className="sm:col-span-2">
            <Field label="Description" error={error?.field("description")}>
              <textarea
                className={cx(inputClass, "h-24 py-2")}
                value={form.description}
                onChange={(e) => set("description", e.target.value)}
                maxLength={2000}
              />
            </Field>
          </div>
        </div>

        <div className="p-4 sm:p-5">
          <p className="text-sm font-medium">Location</p>
          <p className="mb-3 text-sm text-ink-2">Click the map to drop the pin where drivers should go.</p>
          <div className="h-64 overflow-hidden rounded-lg border border-line">
            <LocationPicker
              lat={form.latitude}
              lng={form.longitude}
              onPick={(lat, lng) => setForm((f) => ({ ...f, latitude: +lat.toFixed(7), longitude: +lng.toFixed(7) }))}
            />
          </div>
          <p className="mt-2 text-xs text-muted tabular">
            {form.latitude != null ? `${form.latitude.toFixed(5)}, ${form.longitude?.toFixed(5)}` : "No location set"}
          </p>
          {(error?.field("latitude") || error?.field("longitude")) && (
            <p className="mt-1 text-sm text-danger">Pick the station&apos;s location on the map.</p>
          )}
        </div>

        <div className="grid gap-4 p-4 sm:grid-cols-3 sm:p-5">
          <label className="flex items-center gap-2 text-sm sm:col-span-3">
            <input
              type="checkbox"
              checked={form.is_24_hours}
              onChange={(e) => set("is_24_hours", e.target.checked)}
              className="size-4 accent-[var(--brand)]"
            />
            Open 24 hours
          </label>
          {!form.is_24_hours && (
            <>
              <Field label="Opens at" error={error?.field("opens_at")}>
                <Input type="time" step={900} value={form.opens_at} onChange={(e) => set("opens_at", e.target.value)} />
              </Field>
              <Field label="Closes at" error={error?.field("closes_at")}>
                <Input type="time" step={900} value={form.closes_at} onChange={(e) => set("closes_at", e.target.value)} />
              </Field>
            </>
          )}
          {canSetStatus && station && (
            <Field label="Status" hint="Maintenance hides the station from new bookings.">
              <Select value={form.status} onChange={(e) => set("status", e.target.value as Station["status"])}>
                <option value="active">Active</option>
                <option value="maintenance">Maintenance</option>
                <option value="inactive">Inactive (hidden)</option>
              </Select>
            </Field>
          )}
        </div>

        <div className="p-4 sm:p-5">
          <p className="mb-2 text-sm font-medium">Amenities</p>
          <div className="flex flex-wrap gap-2">
            {AMENITIES.map((a) => {
              const on = form.amenities.includes(a);
              return (
                <button
                  type="button"
                  key={a}
                  aria-pressed={on}
                  onClick={() => set("amenities", on ? form.amenities.filter((x) => x !== a) : [...form.amenities, a])}
                  className={cx(
                    "rounded-full border px-3 py-1 text-sm",
                    on ? "border-brand bg-brand-soft font-medium" : "border-line text-ink-2 hover:bg-surface-2",
                  )}
                >
                  {a}
                </button>
              );
            })}
          </div>
        </div>

        <div className="flex flex-wrap items-center justify-end gap-3 p-4 sm:p-5">
          {error && !Object.keys(error.errors).length && <Alert>{error.message}</Alert>}
          {error && Object.keys(error.errors).length > 0 && <p className="text-sm text-danger">{error.message}</p>}
          <Button type="submit" loading={saving}>
            {station ? "Save changes" : "Create station"}
          </Button>
        </div>
      </Card>
    </form>
  );
}
