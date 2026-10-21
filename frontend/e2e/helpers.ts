import { expect, type APIRequestContext, type Page } from "@playwright/test";

export const API = `http://localhost:${process.env.E2E_API_PORT ?? "8100"}/api`;

let counter = 0;

/** Create and verify a brand-new driver through the API (the dev server echoes the OTP). */
export async function createDriver(request: APIRequestContext) {
  const email = `e2e-${Date.now()}-${counter++}@example.com`;
  const password = "password123";
  const reg = await request.post(`${API}/register`, {
    data: { name: "E2E Driver", email, password, password_confirmation: password },
  });
  expect(reg.ok()).toBeTruthy();
  const otp = (await reg.json()).data.dev_otp as string;
  const ver = await request.post(`${API}/verify-email-otp`, { data: { email, otp } });
  expect(ver.ok()).toBeTruthy();
  return { email, password };
}

export async function signIn(page: Page, email: string, password: string) {
  await page.goto("/login");
  await page.getByLabel("Email").fill(email);
  await page.getByLabel("Password").fill(password);
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  // Wait until the session is established, or a following page.goto() would abort the login request.
  await expect(page.getByRole("button", { name: "Sign out" })).toBeVisible();
}
