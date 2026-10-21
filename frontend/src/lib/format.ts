export const DEFAULT_TZ = "Asia/Kathmandu";

const npr = new Intl.NumberFormat("en-IN", { maximumFractionDigits: 0 });

export function money(value: number | null | undefined): string {
  if (value == null) return "—";
  return `NPR ${npr.format(Math.round(value))}`;
}

export function compact(value: number): string {
  return new Intl.NumberFormat("en", { notation: "compact", maximumFractionDigits: 1 }).format(value);
}

export function kwh(value: number | null | undefined, digits = 1): string {
  if (value == null) return "—";
  return `${value.toLocaleString("en", { maximumFractionDigits: digits })} kWh`;
}

export function percent(ratio: number, digits = 1): string {
  return `${(ratio * 100).toFixed(digits)}%`;
}

export function time(iso: string, tz = DEFAULT_TZ): string {
  return new Intl.DateTimeFormat("en-GB", { hour: "2-digit", minute: "2-digit", timeZone: tz }).format(new Date(iso));
}

export function date(iso: string, tz = DEFAULT_TZ): string {
  return new Intl.DateTimeFormat("en-GB", { weekday: "short", day: "numeric", month: "short", timeZone: tz }).format(
    new Date(iso),
  );
}

export function dateTime(iso: string, tz = DEFAULT_TZ): string {
  return `${date(iso, tz)}, ${time(iso, tz)}`;
}

/** YYYY-MM-DD for a Date in a given time zone. */
export function ymd(d: Date, tz = DEFAULT_TZ): string {
  return new Intl.DateTimeFormat("en-CA", { timeZone: tz }).format(d);
}

export function addDays(ymdStr: string, days: number): string {
  const d = new Date(`${ymdStr}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

export function duration(minutes: number): string {
  const h = Math.floor(minutes / 60);
  const m = Math.round(minutes % 60);
  if (!h) return `${m} min`;
  return m ? `${h} h ${m} min` : `${h} h`;
}

export function hours(station: { is_24_hours: boolean; opens_at: string | null; closes_at: string | null }): string {
  if (station.is_24_hours || !station.opens_at || !station.closes_at) return "Open 24 hours";
  return `${station.opens_at}–${station.closes_at}`;
}

export const CONNECTOR_LABEL: Record<string, string> = {
  CCS2: "CCS2",
  CHAdeMO: "CHAdeMO",
  Type2: "Type 2 (AC)",
  GBT: "GB/T",
};
