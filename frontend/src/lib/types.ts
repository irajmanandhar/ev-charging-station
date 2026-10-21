export type Role = "driver" | "operator" | "admin";
export type LiveStatus = "available" | "occupied" | "reserved" | "offline";
export type ConnectorType = "CCS2" | "CHAdeMO" | "Type2" | "GBT";
export type BookingStatus = "confirmed" | "active" | "completed" | "cancelled" | "no_show";
export type StationStatus = "active" | "maintenance" | "inactive";

export interface User {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  role: Role;
  email_verified: boolean;
  created_at: string;
  stations_count?: number;
  bookings_count?: number;
}

export interface Charger {
  id: number;
  station_id: number;
  label: string;
  connector_type: ConnectorType;
  power_kw: number;
  price_per_kwh: number;
  status: "available" | "offline" | "maintenance";
  live_status?: LiveStatus;
  busy_until?: string | null;
}

export interface Station {
  id: number;
  name: string;
  description: string | null;
  address: string | null;
  city: string | null;
  latitude: number;
  longitude: number;
  timezone: string;
  is_24_hours: boolean;
  opens_at: string | null;
  closes_at: string | null;
  amenities: string[];
  status: StationStatus;
  distance_km?: number;
  operator?: { id: number; name: string };
  chargers?: Charger[];
  summary?: {
    chargers: number;
    available: number;
    max_power_kw: number | null;
    min_price_per_kwh: number | null;
    connector_types: ConnectorType[];
  };
  created_at: string;
}

export interface Session {
  id: number;
  booking_id: number | null;
  status: "active" | "completed";
  started_at: string;
  ended_at: string | null;
  energy_kwh: number;
  cost: number;
  charger?: {
    id: number;
    label: string;
    connector_type: ConnectorType;
    power_kw: number;
    price_per_kwh: number;
    station: { id: number; name: string } | null;
  };
  user?: { id: number; name: string };
}

export interface Booking {
  id: number;
  reference: string;
  status: BookingStatus;
  starts_at: string;
  ends_at: string;
  duration_minutes: number;
  vehicle: string | null;
  estimated_kwh: number;
  estimated_cost: number;
  cancelled_at: string | null;
  created_at: string;
  user?: { id: number; name: string; email: string };
  charger?: {
    id: number;
    label: string;
    connector_type: ConnectorType;
    power_kw: number;
    price_per_kwh: number;
    station: {
      id: number;
      name: string;
      address: string | null;
      latitude: number;
      longitude: number;
      timezone: string;
    } | null;
  };
  session?: Session | null;
}

export interface Slot {
  start: string;
  end: string;
  free: boolean;
  past: boolean;
}

export interface Availability {
  date: string;
  timezone: string;
  opens_at: string;
  closes_at: string;
  slot_minutes: number;
  min_minutes: number;
  max_minutes: number;
  slots: Slot[];
  busy: { start: string; end: string; status: BookingStatus }[];
  free_minutes_now: number;
}

export interface Metrics {
  range: { from: string; to: string; timezone: string };
  totals: {
    sessions: number;
    energy_kwh: number;
    revenue: number;
    avg_session_minutes: number;
    utilization: number;
    bookings: number;
    no_show_rate: number;
    cancellation_rate: number;
  };
  daily: { date: string; sessions: number; energy_kwh: number; revenue: number }[];
  hourly: { hour: number; sessions: number }[];
  by_connector: { connector_type: ConnectorType; sessions: number; energy_kwh: number }[];
  by_station: {
    station_id: number;
    name: string;
    sessions: number;
    energy_kwh: number;
    revenue: number;
    utilization: number;
  }[];
  live: Record<LiveStatus, number>;
  upcoming_bookings: number;
}

export interface AdminOverview {
  counts: {
    users: Partial<Record<Role, number>>;
    stations: Partial<Record<StationStatus, number>>;
    chargers: number;
  };
  metrics: Metrics;
  recent_activity: {
    id: number;
    action: string;
    subject: string | null;
    details: Record<string, string> | null;
    admin: string | null;
    created_at: string;
  }[];
}

export interface Paginated<T> {
  items: T[];
  meta: { current_page: number; last_page: number; total: number };
}
