import { expect, test } from "@playwright/test";
import { signIn } from "./helpers";

test("an operator sees their dashboard, stations, and can export bookings", async ({ page }) => {
  await signIn(page, "operator@evcharge.test", "password");
  await expect(page).toHaveURL(/\/operator/);
  await expect(page.getByRole("heading", { name: "Operator overview" })).toBeVisible();

  const download = page.waitForEvent("download");
  await page.getByRole("button", { name: "Export CSV" }).click();
  expect((await download).suggestedFilename()).toMatch(/^bookings-\d{4}-\d{2}-\d{2}-to-\d{4}-\d{2}-\d{2}\.csv$/);

  await page.getByRole("link", { name: "Stations" }).first().click();
  await expect(page.getByRole("heading", { name: /stations/i }).first()).toBeVisible();
});

test("an operator adds a station and a charger", async ({ page }) => {
  await signIn(page, "operator@evcharge.test", "password");
  await page.goto("/operator/stations/new");

  const name = `E2E Station ${Date.now()}`;
  await page.getByLabel("Station name").fill(name);
  await page.getByLabel("Street / area").fill("Test Road");
  await page.getByLabel("City").fill("Kathmandu");
  await page.getByLabel("Opens at").fill("07:00");
  await page.getByLabel("Closes at").fill("21:00");
  await page.locator(".leaflet-container").click({ position: { x: 150, y: 120 } }); // pick a location on the map
  await page.getByRole("button", { name: /save|create|add station/i }).click();

  await expect(page).toHaveURL(/\/operator\/stations\/\d+/);
  await expect(page.getByText(name).first()).toBeVisible();
});

test("an operator cannot open another operator's station", async ({ page, request }) => {
  const base = `http://localhost:${process.env.E2E_API_PORT ?? "8100"}/api`;
  const login = await request.post(`${base}/login`, { data: { email: "operator2@evcharge.test", password: "password" } });
  const token = (await login.json()).data.token as string;
  const theirs = (await (await request.get(`${base}/operator/stations`, { headers: { Authorization: `Bearer ${token}` } })).json()).data[0];

  await signIn(page, "operator@evcharge.test", "password");
  await page.goto(`/operator/stations/${theirs.id}`);
  await expect(page.getByRole("heading", { name: "Operator overview" })).toHaveCount(0);
  await expect(page.getByRole("alert").filter({ hasText: "belongs to another operator" })).toBeVisible();
});

test("an admin reviews users and pauses a station", async ({ page }) => {
  await signIn(page, "admin@evcharge.test", "password");
  await expect(page).toHaveURL(/\/admin/);

  await page.getByRole("link", { name: "Users" }).click();
  await expect(page.getByText("driver@evcharge.test")).toBeVisible();

  await page.getByRole("link", { name: "Stations" }).last().click();
  const select = page.getByRole("combobox").first();
  await select.selectOption("maintenance");
  await expect(page.getByText(/set to maintenance/i)).toBeVisible();
  await select.selectOption("active"); // put it back so other tests are unaffected
});
