"use client";

import { SWRConfig } from "swr";
import { AuthProvider } from "@/lib/auth";
import { fetcher } from "@/lib/api";
import { LocaleProvider } from "@/lib/i18n";
import { ToastProvider } from "./toast";

export function Providers({ children }: { children: React.ReactNode }) {
  return (
    <SWRConfig value={{ fetcher, revalidateOnFocus: true, shouldRetryOnError: false }}>
      <AuthProvider>
        <LocaleProvider>
          <ToastProvider>{children}</ToastProvider>
        </LocaleProvider>
      </AuthProvider>
    </SWRConfig>
  );
}
