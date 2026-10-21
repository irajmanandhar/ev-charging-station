"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { useState } from "react";
import { AuthCard, TextLink } from "@/components/auth-card";
import { Alert, Button, Field, Input } from "@/components/ui";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { User } from "@/lib/types";

const DEMO = [
  { label: "Driver", email: "driver@evcharge.test" },
  { label: "Operator", email: "operator@evcharge.test" },
  { label: "Admin", email: "admin@evcharge.test" },
];

/** Only allow same-site relative redirects. */
function safeNext(next: string | null): string | null {
  if (!next || !next.startsWith("/") || next.startsWith("//") || next.includes("\\")) return null;
  try {
    return new URL(next, window.location.origin).origin === window.location.origin ? next : null;
  } catch {
    return null;
  }
}

export function LoginForm() {
  const router = useRouter();
  const params = useSearchParams();
  const { signIn } = useAuth();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);

  const submit = async (e?: React.FormEvent, creds?: { email: string; password: string }) => {
    e?.preventDefault();
    setLoading(true);
    setError(null);
    try {
      const { data } = await api<{ user: User; token: string }>("/login", {
        method: "POST",
        body: creds ?? { email, password },
      });
      signIn(data.token, data.user);
      setPassword("");
      const home = data.user.role === "admin" ? "/admin" : data.user.role === "operator" ? "/operator" : "/";
      router.push(safeNext(params.get("next")) ?? home);
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError("Something went wrong.", 0));
    } finally {
      setLoading(false);
    }
  };

  return (
    <AuthCard
      title="Sign in"
      subtitle="Book chargers and keep track of your sessions."
      footer={
        <>
          New here? <TextLink href="/register">Create an account</TextLink>
        </>
      }
    >
      <form onSubmit={submit} className="space-y-4" noValidate>
        <Field label="Email" error={error?.field("email")}>
          <Input type="email" autoComplete="email" required value={email} onChange={(e) => setEmail(e.target.value)} />
        </Field>
        <Field label="Password" error={error?.field("password")}>
          <Input type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} />
        </Field>
        {error && !error.field("email") && !error.field("password") && <Alert>{error.message}</Alert>}
        <Button type="submit" className="w-full" loading={loading}>
          Sign in
        </Button>
        <p className="text-center text-sm">
          <TextLink href="/forgot-password">Forgot your password?</TextLink>
        </p>
      </form>

      {process.env.NEXT_PUBLIC_DEMO_LOGIN === "true" && (
      <div className="mt-6 rounded-lg border border-dashed border-line-strong p-3">
        <p className="text-xs font-medium text-ink-2">Demo accounts (password: <code>password</code>)</p>
        <div className="mt-2 grid grid-cols-3 gap-2">
          {DEMO.map((d) => (
            <Button
              key={d.email}
              type="button"
              variant="secondary"
              className="h-9 px-2 text-xs"
              disabled={loading}
              onClick={() => {
                setEmail(d.email);
                setPassword("password");
                submit(undefined, { email: d.email, password: "password" });
              }}
            >
              {d.label}
            </Button>
          ))}
        </div>
      </div>
      )}
    </AuthCard>
  );
}
