import { expect, test } from "@playwright/test";
import { createDriver, signIn } from "./helpers";

test("a visitor can browse stations without an account", async ({ page }) => {
  await page.goto("/");
  await expect(page.getByRole("heading", { name: "Find a charger" })).toBeVisible();
  // seeded stations appear in the list
  await expect(page.getByRole("link", { name: /view|book|details/i }).first().or(page.locator("a[href^='/stations/']").first())).toBeVisible();
});

test("a new driver registers with an email code and lands signed in", async ({ page }) => {
  const email = `signup-${Date.now()}@example.com`;
  await page.goto("/register");
  await page.getByLabel("Full name").fill("Sita Sharma");
  await page.getByLabel("Email").fill(email);
  await page.getByLabel("Password", { exact: true }).fill("password123");
  await page.getByLabel("Confirm password").fill("password123");
  await page.getByRole("button", { name: /create account|continue|sign up/i }).click();

  await expect(page.getByRole("heading", { name: "Check your email" })).toBeVisible();
  const code = await page.locator("strong.tabular").innerText(); // dev mode shows the code
  await page.getByLabel("Verification code").fill(code);
  await page.getByRole("button", { name: "Verify and continue" }).click();

  await expect(page.getByRole("link", { name: "My bookings" }).first()).toBeVisible();
});

test("a driver books a slot for tomorrow and then cancels it", async ({ page, request }) => {
  const { email, password } = await createDriver(request);
  await signIn(page, email, password);
  await expect(page.getByRole("link", { name: "My bookings" }).first()).toBeVisible();

  await page.goto("/");
  const first = page.locator("a[href^='/stations/']").first();
  await first.click();
  await expect(page).toHaveURL(/\/stations\/\d+/);

  await page.getByRole("option").filter({ hasNotText: "Offline" }).first().click();
  await page.getByRole("button", { name: /^Tomorrow/ }).click();

  const slot = page.locator("button[aria-pressed]").filter({ hasText: /^\d\d:\d\d$/ }).and(page.locator(":enabled")).first();
  await slot.click();
  await page.getByRole("button", { name: "Confirm booking" }).click();

  await expect(page).toHaveURL(/\/bookings/);
  await expect(page.getByRole("heading", { name: "Upcoming" })).toBeVisible();
  await expect(page.getByRole("button", { name: "Cancel booking" })).toHaveCount(1);

  page.once("dialog", (d) => d.accept());
  await page.getByRole("button", { name: "Cancel booking" }).click();
  await expect(page.getByText("No upcoming bookings")).toBeVisible();
});

test("the same slot cannot be double-booked by two drivers", async ({ request }) => {
  const a = await createDriver(request);
  const b = await createDriver(request);
  const login = async (c: { email: string; password: string }) =>
    (await (await request.post(`${process.env.E2E_API_BASE ?? `http://localhost:${process.env.E2E_API_PORT ?? "8100"}`}/api/login`, { data: c })).json()).data.token as string;
  const base = `http://localhost:${process.env.E2E_API_PORT ?? "8100"}/api`;
  const [ta, tb] = [await login(a), await login(b)];

  const stations = (await (await request.get(`${base}/stations`)).json()).data;
  const charger = stations.flatMap((s: { chargers: { id: number; status: string }[] }) => s.chargers).find((c: { status: string }) => c.status === "available");
  const avail = (await (await request.get(`${base}/chargers/${charger.id}/availability?date=${new Date(Date.now() + 2 * 86400000).toISOString().slice(0, 10)}`)).json()).data;
  const start = avail.slots.find((s: { free: boolean }, i: number) => s.free && avail.slots[i + 1]?.free && avail.slots[i + 2]?.free);

  const book = (token: string) =>
    request.post(`${base}/bookings`, {
      headers: { Authorization: `Bearer ${token}` },
      data: { charger_id: charger.id, starts_at: start.start, duration_minutes: 30 },
    });

  const [r1, r2] = await Promise.all([book(ta), book(tb)]);
  const codes = [r1.status(), r2.status()].sort();
  expect(codes).toEqual([201, 409]);
});

test("pages for other roles are protected", async ({ page, request }) => {
  // guests are sent to sign in, and come back afterwards
  await page.goto("/bookings");
  await expect(page).toHaveURL(/\/login\?next=%2Fbookings/);

  const { email, password } = await createDriver(request);
  await signIn(page, email, password);
  await page.goto("/admin");
  await expect(page.getByText("You don't have access to this page")).toBeVisible();
  await page.goto("/operator");
  await expect(page.getByText("You don't have access to this page")).toBeVisible();
});

test("the language switcher translates the navigation", async ({ page }) => {
  await page.goto("/");
  await page.getByRole("button", { name: "नेपाली" }).first().click();
  await expect(page.getByRole("heading", { name: "चार्जर खोज्नुहोस्" })).toBeVisible();
  await expect(page.locator("html")).toHaveAttribute("lang", "ne");
});

test("the mobile layout has no horizontal scroll", async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 800 });
  await page.goto("/");
  await expect(page.getByRole("heading", { name: "Find a charger" })).toBeVisible();
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(1);
});

test("on desktop only the station list scrolls; the page and the map stay put", async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 800 });
  await page.goto("/");
  const map = page.getByRole("region", { name: "Map of stations" });
  await expect(map).toBeVisible();
  await expect(page.locator("a[href^='/stations/']").first()).toBeVisible();

  const pageOverflow = await page.evaluate(() => document.documentElement.scrollHeight - document.documentElement.clientHeight);
  expect(pageOverflow).toBeLessThanOrEqual(0);

  const mapTop = (await map.boundingBox())!.y;
  await page.locator("a[href^='/stations/']").first().hover();
  await page.mouse.wheel(0, 1500);
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(0);
  expect((await map.boundingBox())!.y).toBe(mapTop);
});
