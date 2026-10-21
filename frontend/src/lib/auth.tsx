"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { api, getToken, setToken } from "./api";
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

  const refresh = useCallback(async () => {
    if (!getToken()) {
      setUser(null);
      setLoading(false);
      return;
    }
    try {
      const { data } = await api<User>("/me");
      setUser(data);
    } catch {
      setUser(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    // Initial session check against the API; state updates happen after the await.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    refresh();
    const onSignedOut = () => setUser(null);
    window.addEventListener("ev:signed-out", onSignedOut);
    return () => window.removeEventListener("ev:signed-out", onSignedOut);
  }, [refresh]);

  const signIn = useCallback((token: string, u: User) => {
    setToken(token);
    setUser(u);
  }, []);

  const signOut = useCallback(async () => {
    try {
      await api("/logout", { method: "POST" });
    } catch {
      /* token may already be invalid */
    }
    setToken(null);
    setUser(null);
  }, []);

  const value = useMemo(() => ({ user, loading, signIn, signOut, refresh }), [user, loading, signIn, signOut, refresh]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used inside <AuthProvider>");
  return ctx;
}
