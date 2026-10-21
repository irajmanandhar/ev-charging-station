export const API_URL = (process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000").replace(/\/$/, "");

const TOKEN_KEY = "ev.token";

export function getToken(): string | null {
  if (typeof window === "undefined") return null;
  try {
    return window.localStorage.getItem(TOKEN_KEY);
  } catch {
    return null;
  }
}

export function setToken(token: string | null) {
  try {
    if (token) window.localStorage.setItem(TOKEN_KEY, token);
    else window.localStorage.removeItem(TOKEN_KEY);
  } catch {
    /* storage unavailable (private mode) — session-only sign-in */
  }
}

/** Error thrown for any non-2xx API response, carrying the server's message and field errors. */
export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
    public errors: Record<string, string[] | string> = {},
  ) {
    super(message);
  }

  /** First message for a form field, if the server flagged one. */
  field(name: string): string | undefined {
    const e = this.errors[name];
    return Array.isArray(e) ? e[0] : undefined;
  }

  get code(): string | undefined {
    const c = this.errors.code;
    return typeof c === "string" ? c : undefined;
  }
}

type Envelope<T> = { success: boolean; message: string; data: T; errors?: Record<string, string[] | string> };

export async function api<T>(
  path: string,
  options: { method?: string; body?: unknown; signal?: AbortSignal } = {},
): Promise<{ data: T; message: string }> {
  const token = getToken();
  let res: Response;
  try {
    res = await fetch(`${API_URL}/api${path}`, {
      method: options.method ?? "GET",
      headers: {
        Accept: "application/json",
        ...(options.body !== undefined ? { "Content-Type": "application/json" } : {}),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
      signal: options.signal,
    });
  } catch {
    throw new ApiError("Can't reach the server. Is the API running?", 0);
  }

  let json: Envelope<T> | null = null;
  try {
    json = (await res.json()) as Envelope<T>;
  } catch {
    /* empty or non-JSON body */
  }

  if (!res.ok || !json?.success) {
    if (res.status === 401 && token) {
      setToken(null);
      window.dispatchEvent(new Event("ev:signed-out"));
    }
    throw new ApiError(json?.message ?? `Request failed (${res.status})`, res.status, json?.errors ?? {});
  }

  return { data: json.data, message: json.message };
}

/** Authenticated file download (the bearer token can't ride on a plain link). */
export async function downloadCsv(path: string): Promise<Blob> {
  const token = getToken();
  let res: Response;
  try {
    res = await fetch(`${API_URL}/api${path}`, { headers: { Accept: "text/csv", ...(token ? { Authorization: `Bearer ${token}` } : {}) } });
  } catch {
    throw new ApiError("Can't reach the server. Is the API running?", 0);
  }
  if (!res.ok) {
    let message = `Download failed (${res.status})`;
    try {
      message = ((await res.json()) as { message?: string }).message ?? message;
    } catch {
      /* not JSON */
    }
    throw new ApiError(message, res.status);
  }
  return res.blob();
}

/** SWR fetcher: key is the API path. */
export const fetcher = <T,>(path: string) => api<T>(path).then((r) => r.data);
