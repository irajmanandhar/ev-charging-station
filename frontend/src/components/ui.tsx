"use client";

import Link from "next/link";
import { cloneElement, isValidElement, useId } from "react";
import type { ComponentProps, ReactElement, ReactNode } from "react";
import type { LiveStatus } from "@/lib/types";

const cx = (...c: (string | false | null | undefined)[]) => c.filter(Boolean).join(" ");
export { cx };

type Variant = "primary" | "secondary" | "ghost" | "danger";

const variants: Record<Variant, string> = {
  primary: "bg-brand text-brand-ink hover:bg-brand-hover",
  secondary: "bg-surface text-ink border border-line-strong hover:bg-surface-2",
  ghost: "text-ink-2 hover:bg-surface-2 hover:text-ink",
  danger: "bg-surface text-danger border border-line-strong hover:bg-danger-soft",
};

const base =
  "inline-flex items-center justify-center gap-2 rounded-lg px-4 h-10 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand disabled:opacity-50 disabled:pointer-events-none";

export function Button({
  variant = "primary",
  loading,
  className,
  children,
  ...props
}: ComponentProps<"button"> & { variant?: Variant; loading?: boolean }) {
  return (
    <button className={cx(base, variants[variant], className)} disabled={loading || props.disabled} {...props}>
      {loading && <Spinner />}
      {children}
    </button>
  );
}

export function ButtonLink({
  variant = "primary",
  className,
  ...props
}: ComponentProps<typeof Link> & { variant?: Variant }) {
  return <Link className={cx(base, variants[variant], className)} {...props} />;
}

export function Spinner({ className }: { className?: string }) {
  return (
    <span
      aria-hidden
      className={cx("inline-block size-4 animate-spin rounded-full border-2 border-current border-r-transparent", className)}
    />
  );
}

export function Card({ className, ...props }: ComponentProps<"div">) {
  return <div className={cx("rounded-xl border border-line bg-surface", className)} {...props} />;
}

export function Field({
  label,
  error,
  hint,
  children,
}: {
  label: string;
  error?: string;
  hint?: string;
  children: ReactNode;
}) {
  const noteId = useId();
  const note = error ?? hint;
  // Link a single form control to its error/hint; wrappers (e.g. a row of inputs) are left alone.
  const isControl = isValidElement<{ "aria-describedby"?: string; "aria-invalid"?: boolean }>(children) &&
    (children.type === Input || children.type === Select || ["input", "select", "textarea"].includes(children.type as string));
  const control = isControl && note
    ? cloneElement(children as ReactElement<{ "aria-describedby"?: string; "aria-invalid"?: boolean }>, {
        "aria-describedby": noteId,
        ...(error ? { "aria-invalid": true } : {}),
      })
    : children;

  return (
    <div>
      {/* The note sits outside the <label> so it isn't read as part of the field's name. */}
      <label className="block">
        <span className="mb-1.5 block text-sm font-medium text-ink">{label}</span>
        {control}
      </label>
      {error ? (
        <span id={noteId} role="alert" className="mt-1 block text-sm text-danger">
          {error}
        </span>
      ) : hint ? (
        <span id={noteId} className="mt-1 block text-sm text-muted">
          {hint}
        </span>
      ) : null}
    </div>
  );
}

const inputBase =
  "h-10 rounded-lg border border-line-strong bg-surface px-3 text-sm text-ink placeholder:text-muted focus:outline-2 focus:outline-brand focus:border-transparent";

export const inputClass = `w-full ${inputBase}`;

/** Full width unless the caller sets its own width (avoids w-full vs w-* conflicts). */
const sized = (className?: string) => (className && /(^|\s|:)w-/.test(className) ? className : cx("w-full", className));

export function Input(props: ComponentProps<"input">) {
  return <input {...props} className={cx(inputBase, sized(props.className))} />;
}

export function Select(props: ComponentProps<"select">) {
  return <select {...props} className={cx(inputBase, "pr-8", sized(props.className))} />;
}

export function Alert({ tone = "danger", children }: { tone?: "danger" | "info" | "success"; children: ReactNode }) {
  const tones = {
    danger: "bg-danger-soft text-danger border-danger/30",
    info: "bg-reserved-soft text-ink border-reserved/30",
    success: "bg-available-soft text-ink border-available/30",
  };
  return (
    <div role={tone === "danger" ? "alert" : "status"} className={cx("rounded-lg border px-3 py-2.5 text-sm", tones[tone])}>
      {children}
    </div>
  );
}

const STATUS: Record<LiveStatus, { label: string; dot: string; chip: string }> = {
  available: { label: "Available", dot: "bg-available", chip: "bg-available-soft" },
  occupied: { label: "Charging", dot: "bg-occupied", chip: "bg-occupied-soft" },
  reserved: { label: "Reserved", dot: "bg-reserved", chip: "bg-reserved-soft" },
  offline: { label: "Offline", dot: "bg-offline", chip: "bg-offline-soft" },
};

/** Charger state: colour dot + text label, never colour alone. */
export function StatusBadge({ status }: { status: LiveStatus }) {
  const s = STATUS[status];
  return (
    <span className={cx("inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium text-ink", s.chip)}>
      <span aria-hidden className={cx("size-2 rounded-full", s.dot)} />
      {s.label}
    </span>
  );
}

export const statusColorVar: Record<LiveStatus, string> = {
  available: "var(--available)",
  occupied: "var(--occupied)",
  reserved: "var(--reserved)",
  offline: "var(--offline)",
};

export function BookingStatusBadge({ status }: { status: string }) {
  const map: Record<string, { label: string; cls: string }> = {
    confirmed: { label: "Upcoming", cls: "bg-reserved-soft" },
    active: { label: "Charging", cls: "bg-occupied-soft" },
    completed: { label: "Completed", cls: "bg-available-soft" },
    cancelled: { label: "Cancelled", cls: "bg-offline-soft" },
    no_show: { label: "Missed", cls: "bg-danger-soft" },
  };
  const s = map[status] ?? { label: status, cls: "bg-surface-2" };
  return <span className={cx("rounded-full px-2.5 py-0.5 text-xs font-medium text-ink", s.cls)}>{s.label}</span>;
}

export function Empty({ title, children, action }: { title: string; children?: ReactNode; action?: ReactNode }) {
  return (
    <div className="rounded-xl border border-dashed border-line-strong px-6 py-12 text-center">
      <p className="font-medium text-ink">{title}</p>
      {children && <p className="mx-auto mt-1 max-w-md text-sm text-ink-2">{children}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}

export function Skeleton({ className }: { className?: string }) {
  return <div className={cx("animate-pulse rounded-lg bg-surface-2", className)} />;
}

export function PageHeader({ title, subtitle, actions }: { title: string; subtitle?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight text-ink">{title}</h1>
        {subtitle && <p className="mt-1 text-sm text-ink-2">{subtitle}</p>}
      </div>
      {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
    </div>
  );
}

export function StatTile({ label, value, hint }: { label: string; value: ReactNode; hint?: ReactNode }) {
  return (
    <Card className="p-4">
      <p className="text-sm text-ink-2">{label}</p>
      <p className="mt-1 text-2xl font-semibold tracking-tight text-ink">{value}</p>
      {hint && <p className="mt-1 text-xs text-muted">{hint}</p>}
    </Card>
  );
}
