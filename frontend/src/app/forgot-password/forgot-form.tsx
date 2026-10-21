"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { AuthCard, TextLink } from "@/components/auth-card";
import { OtpInput } from "@/components/otp-input";
import { useToast } from "@/components/toast";
import { Alert, Button, Field, Input } from "@/components/ui";
import { ApiError, api } from "@/lib/api";

export function ForgotForm() {
  const router = useRouter();
  const toast = useToast();
  const [step, setStep] = useState<"email" | "code" | "password">("email");
  const [email, setEmail] = useState("");
  const [otp, setOtp] = useState("");
  const [devOtp, setDevOtp] = useState<string | null>(null);
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);

  const run = async (fn: () => Promise<void>) => {
    setLoading(true);
    setError(null);
    try {
      await fn();
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError("Something went wrong.", 0));
    } finally {
      setLoading(false);
    }
  };

  return (
    <AuthCard
      title={step === "password" ? "Choose a new password" : "Reset your password"}
      subtitle={
        step === "email"
          ? "Enter your email and we'll send you a 6-digit code."
          : step === "code"
            ? `If an account exists for ${email}, a code is on its way.`
            : undefined
      }
      footer={<TextLink href="/login">Back to sign in</TextLink>}
    >
      {step === "email" && (
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault();
            run(async () => {
              const { data } = await api<{ dev_otp?: string } | null>("/password/forgot", { method: "POST", body: { email } });
              setDevOtp(data?.dev_otp ?? null);
              setStep("code");
            });
          }}
        >
          <Field label="Email" error={error?.field("email")}>
            <Input type="email" autoComplete="email" required value={email} onChange={(e) => setEmail(e.target.value)} />
          </Field>
          {error && !error.field("email") && <Alert>{error.message}</Alert>}
          <Button type="submit" className="w-full" loading={loading}>
            Send code
          </Button>
        </form>
      )}

      {step === "code" && (
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault();
            run(async () => {
              await api("/password/verify-otp", { method: "POST", body: { email, otp } });
              setStep("password");
            });
          }}
        >
          <OtpInput value={otp} onChange={setOtp} />
          {devOtp && (
            <Alert tone="info">
              Development mode: your code is <strong className="tabular">{devOtp}</strong>
            </Alert>
          )}
          {error && <Alert>{error.message}</Alert>}
          <Button type="submit" className="w-full" loading={loading} disabled={otp.length !== 6}>
            Verify code
          </Button>
        </form>
      )}

      {step === "password" && (
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault();
            run(async () => {
              await api("/password/reset", {
                method: "POST",
                body: { email, otp, password, password_confirmation: confirm },
              });
              toast("Password updated. Sign in with your new password.");
              router.push("/login");
            });
          }}
        >
          <Field label="New password" hint="At least 8 characters" error={error?.field("password")}>
            <Input type="password" autoComplete="new-password" required value={password} onChange={(e) => setPassword(e.target.value)} />
          </Field>
          <Field label="Confirm new password">
            <Input type="password" autoComplete="new-password" required value={confirm} onChange={(e) => setConfirm(e.target.value)} />
          </Field>
          {error && !error.field("password") && <Alert>{error.message}</Alert>}
          <Button type="submit" className="w-full" loading={loading}>
            Update password
          </Button>
        </form>
      )}
    </AuthCard>
  );
}
