"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { useEffect } from "react";
import { Spinner } from "@/components/ui";
import { setToken } from "@/lib/api";
import { useAuth } from "@/lib/auth";

/** Landing page for Google/Facebook sign-in: the API redirects here with ?token=... */
export function SocialCallback() {
  const params = useSearchParams();
  const router = useRouter();
  const { refresh } = useAuth();

  useEffect(() => {
    const token = params.get("token");
    if (!token) {
      router.replace("/login");
      return;
    }
    setToken(token);
    refresh().then(() => router.replace("/"));
  }, [params, refresh, router]);

  return (
    <div className="flex flex-1 items-center justify-center gap-3 text-ink-2">
      <Spinner /> Signing you in…
    </div>
  );
}
