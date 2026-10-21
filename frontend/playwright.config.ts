import { defineConfig } from "@playwright/test";

const API_PORT = process.env.E2E_API_PORT ?? "8100";
const WEB_PORT = process.env.E2E_WEB_PORT ?? "3100";

export default defineConfig({
  testDir: "./e2e",
  testMatch: "**/*.spec.ts",
  timeout: 45_000,
  expect: { timeout: 10_000 },
  fullyParallel: false, // flows share one seeded database
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [["github"], ["html", { open: "never" }]] : "list",
  use: {
    baseURL: `http://localhost:${WEB_PORT}`,
    trace: "retain-on-failure",
    // Locally use the installed Chrome; CI installs Playwright's own Chromium (E2E_CHANNEL="").
    channel: process.env.E2E_CHANNEL ?? (process.env.CI ? undefined : "chrome"),
  },
  webServer: [
    {
      command: "node e2e/start-api.mjs",
      url: `http://localhost:${API_PORT}/up`,
      timeout: 120_000,
      reuseExistingServer: false,
    },
    {
      command: `npm run build && npm run start -- -p ${WEB_PORT}`,
      url: `http://localhost:${WEB_PORT}`,
      timeout: 300_000,
      reuseExistingServer: false,
      env: {
        NEXT_PUBLIC_API_URL: `http://localhost:${API_PORT}`,
        NEXT_PUBLIC_DEMO_LOGIN: "true",
      },
    },
  ],
});
