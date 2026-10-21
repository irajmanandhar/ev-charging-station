"use client";

import { useRouter } from "next/navigation";
import { useEffect } from "react";
import { Spinner } from "@/components/ui";
import { setToken } from "@/lib/api";
import { useAuth } from "@/lib/auth";

/** Landing page for Google/Facebook sign-in: the API redirects here with #token=... (a fragment, so it never hits a server log). */
export function SocialCallback() {
  const router = useRouter();
  const { refresh } = useAuth();

  useEffect(() => {
    const token = new URLSearchParams(window.location.hash.slice(1)).get("token");
    window.history.replaceState(null, "", window.location.pathname);
    if (!token) {
      router.replace("/login");
      return;
    }
    setToken(token);
    refresh().then(() => router.replace("/"));
  }, [refresh, router]);

  return (
    <div className="flex flex-1 items-center justify-center gap-3 text-ink-2">
      <Spinner /> Signing you in…
    </div>
  );
}
