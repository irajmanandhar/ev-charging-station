"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { useSWRConfig } from "swr";
import { ApiError, api, getToken, setToken } from "./api";
import type { User } from "./types";

type AuthState = {
  user: User | null;
  /** true until the stored token has been checked against /me */
  loading: boolean;
  signIn: (token: string, user: User) => void;
  signOut: () => Promise<void>;
  refresh: () => Promise<void>;
};

const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);
  const { mutate } = useSWRConfig();
  // Cached API data (bookings, admin lists, ...) belongs to whoever was signed in; drop it on any change.
  const clearCache = useCallback(() => mutate(() => true, undefined, { revalidate: false }), [mutate]);

  const refresh = useCallback(async () => {
    if (!getToken()) {
      setUser(null);
      setLoading(false);
      return;
    }
    for (;;) {
      try {
        const { data } = await api<User>("/me");
        setUser(data);
        setLoading(false);
        return;
      } catch (e) {
        if (e instanceof ApiError && e.status !== 0) {
          // 401: the API already dropped the token. Other API errors: treat as signed out.
          setUser(null);
          setLoading(false);
          return;
        }
        // API unreachable: keep the session and try again instead of bouncing to /login.
        await new Promise((r) => setTimeout(r, 3000));
      }
    }
  }, []);

  useEffect(() => {
    // Initial session check against the API; state updates happen after the await.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    refresh();
    const onSignedOut = () => {
      setUser(null);
      clearCache();
    };
    window.addEventListener("ev:signed-out", onSignedOut);
    return () => window.removeEventListener("ev:signed-out", onSignedOut);
  }, [refresh, clearCache]);

  const signIn = useCallback(
    (token: string, u: User) => {
      clearCache();
      setToken(token);
      setUser(u);
    },
    [clearCache],
  );

  const signOut = useCallback(async () => {
    try {
      await api("/logout", { method: "POST" });
    } catch {
      /* token may already be invalid */
    }
    setToken(null);
    setUser(null);
    clearCache();
  }, [clearCache]);

  const value = useMemo(() => ({ user, loading, signIn, signOut, refresh }), [user, loading, signIn, signOut, refresh]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used inside <AuthProvider>");
  return ctx;
}
