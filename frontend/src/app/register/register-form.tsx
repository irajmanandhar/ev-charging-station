"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { AuthCard, TextLink } from "@/components/auth-card";
import { OtpInput } from "@/components/otp-input";
import { useToast } from "@/components/toast";
import { Alert, Button, Field, Input } from "@/components/ui";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { User } from "@/lib/types";

export function RegisterForm() {
  const router = useRouter();
  const toast = useToast();
  const { signIn } = useAuth();
  const [step, setStep] = useState<"details" | "verify">("details");
  const [form, setForm] = useState({ name: "", email: "", phone: "", password: "", password_confirmation: "" });
  const [otp, setOtp] = useState("");
  const [devOtp, setDevOtp] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);

  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const register = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError(null);
    try {
      const { data } = await api<{ email: string; dev_otp?: string } | null>("/register", {
        method: "POST",
        body: { ...form, phone: form.phone || null },
      });
      setDevOtp(data?.dev_otp ?? null);
      setStep("verify");
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError("Something went wrong.", 0));
    } finally {
      setLoading(false);
    }
  };

  const verify = async (e?: React.FormEvent) => {
    e?.preventDefault();
    setLoading(true);
    setError(null);
    try {
      const { data } = await api<{ user: User; token: string }>("/verify-email-otp", {
        method: "POST",
        body: { email: form.email, otp },
      });
      signIn(data.token, data.user);
      toast("Welcome! Your account is ready.");
      router.push("/");
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError("Something went wrong.", 0));
    } finally {
      setLoading(false);
    }
  };

  const resend = async () => {
    setError(null);
    try {
      const { data } = await api<{ dev_otp?: string } | null>("/resend-otp", { method: "POST", body: { email: form.email } });
      setDevOtp(data?.dev_otp ?? null);
      toast("A new code is on its way.");
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError("Something went wrong.", 0));
    }
  };

  if (step === "verify") {
    return (
      <AuthCard title="Check your email" subtitle={<>We sent a 6-digit code to <strong className="text-ink">{form.email}</strong>.</>}>
        <form onSubmit={verify} className="space-y-4">
          <OtpInput value={otp} onChange={setOtp} />
          {devOtp && (
            <Alert tone="info">
              Development mode: your code is <strong className="tabular">{devOtp}</strong>
            </Alert>
          )}
          {error && <Alert>{error.message}</Alert>}
          <Button type="submit" className="w-full" loading={loading} disabled={otp.length !== 6}>
            Verify and continue
          </Button>
          <div className="flex justify-between text-sm">
            <button type="button" onClick={() => setStep("details")} className="text-ink-2 hover:text-ink">
              ← Change details
            </button>
            <button type="button" onClick={resend} className="font-medium text-brand hover:underline">
              Resend code
            </button>
          </div>
        </form>
      </AuthCard>
    );
  }

  return (
    <AuthCard
      title="Create your account"
      subtitle="Reserve charging slots and keep a history of every session."
      footer={
        <>
          Already have an account? <TextLink href="/login">Sign in</TextLink>
        </>
      }
    >
      <form onSubmit={register} className="space-y-4" noValidate>
        <Field label="Full name" error={error?.field("name")}>
          <Input autoComplete="name" required value={form.name} onChange={set("name")} />
        </Field>
        <Field label="Email" error={error?.field("email")}>
          <Input type="email" autoComplete="email" required value={form.email} onChange={set("email")} />
        </Field>
        <Field label="Mobile number (optional)" error={error?.field("phone")} hint="10 digits, e.g. 98XXXXXXXX">
          <Input type="tel" inputMode="numeric" autoComplete="tel" value={form.phone} onChange={set("phone")} />
        </Field>
        <Field label="Password" error={error?.field("password")} hint="At least 8 characters">
          <Input type="password" autoComplete="new-password" required value={form.password} onChange={set("password")} />
        </Field>
        <Field label="Confirm password">
          <Input type="password" autoComplete="new-password" required value={form.password_confirmation} onChange={set("password_confirmation")} />
        </Field>
        {error && !Object.keys(error.errors).some((k) => ["name", "email", "phone", "password"].includes(k)) && <Alert>{error.message}</Alert>}
        <Button type="submit" className="w-full" loading={loading}>
          Continue
        </Button>
      </form>
    </AuthCard>
  );
}
