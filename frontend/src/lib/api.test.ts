import { afterEach, describe, expect, it, vi } from "vitest";
import { ApiError, api, getToken, setToken } from "./api";

function respond(status: number, body: unknown) {
  return vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify(body), { status }));
}

afterEach(() => vi.restoreAllMocks());

describe("api()", () => {
  it("unwraps the success envelope and sends the bearer token", async () => {
    setToken("abc");
    const fetchSpy = respond(200, { success: true, message: "OK", data: { id: 1 } });

    const res = await api<{ id: number }>("/me");

    expect(res.data).toEqual({ id: 1 });
    const init = fetchSpy.mock.calls[0][1] as RequestInit;
    expect((init.headers as Record<string, string>).Authorization).toBe("Bearer abc");
  });

  it("throws ApiError with the server message, code and field errors", async () => {
    respond(422, { success: false, message: "Bad", code: "validation_failed", errors: { email: ["Taken"], code: "slot_taken" } });

    const err = await api("/x", { method: "POST", body: {} }).catch((e) => e);

    expect(err).toBeInstanceOf(ApiError);
    expect(err.status).toBe(422);
    expect(err.field("email")).toBe("Taken");
    expect(err.code).toBe("slot_taken");
  });

  it("signs the user out locally on a 401 for a stored token", async () => {
    setToken("stale");
    const onSignedOut = vi.fn();
    window.addEventListener("ev:signed-out", onSignedOut);
    respond(401, { success: false, message: "Please sign in to continue.", errors: {} });

    await expect(api("/me")).rejects.toBeInstanceOf(ApiError);

    expect(getToken()).toBeNull();
    expect(onSignedOut).toHaveBeenCalledOnce();
    window.removeEventListener("ev:signed-out", onSignedOut);
  });

  it("reports an unreachable server as status 0", async () => {
    vi.spyOn(globalThis, "fetch").mockRejectedValue(new TypeError("fetch failed"));

    const err = await api("/x").catch((e) => e);

    expect(err.status).toBe(0);
    expect(err.message).toMatch(/can't reach the server/i);
  });
});
