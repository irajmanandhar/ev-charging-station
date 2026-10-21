"use client";

import { inputClass, cx } from "./ui";

/** Single field with one-time-code autofill; simpler and more accessible than six boxes. */
export function OtpInput({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  return (
    <label className="block">
      <span className="mb-1.5 block text-sm font-medium">Verification code</span>
      <input
        className={cx(inputClass, "h-12 text-center font-mono text-xl tracking-[0.5em]")}
        inputMode="numeric"
        autoComplete="one-time-code"
        pattern="[0-9]{6}"
        maxLength={6}
        autoFocus
        value={value}
        onChange={(e) => onChange(e.target.value.replace(/\D/g, "").slice(0, 6))}
      />
    </label>
  );
}
