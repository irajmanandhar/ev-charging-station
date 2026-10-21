// Boots a throwaway API for the E2E run: fresh SQLite file, demo seed, php's built-in server.
import { spawn, spawnSync } from "node:child_process";
import { mkdirSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const here = path.dirname(fileURLToPath(import.meta.url));
const backend = path.resolve(here, "../../backend");
const dbFile = path.join(backend, "database", "e2e.sqlite");
const port = process.env.E2E_API_PORT ?? "8100";
const webPort = process.env.E2E_WEB_PORT ?? "3100";

mkdirSync(path.dirname(dbFile), { recursive: true });
rmSync(dbFile, { force: true });
writeFileSync(dbFile, "");

const run = (args, opts = {}) => spawnSync("php", args, { cwd: backend, shell: true, encoding: "utf8", ...opts });

const env = {
  ...process.env,
  APP_ENV: "local",
  APP_DEBUG: "false",
  APP_URL: `http://localhost:${port}`,
  DB_CONNECTION: "sqlite",
  DB_DATABASE: dbFile,
  CACHE_STORE: "array",
  SESSION_DRIVER: "array",
  QUEUE_CONNECTION: "sync",
  MAIL_MAILER: "log",
  LOG_CHANNEL: "stderr",
  FRONTEND_URL: `http://localhost:${webPort}`,
  EXPOSE_OTP_IN_RESPONSE: "true",
};

if (!env.APP_KEY) {
  env.APP_KEY = run(["artisan", "key:generate", "--show"], { env }).stdout.trim();
}

// The demo seed builds 60 days of history in memory; php's 128 MB default is not enough.
const migrate = run(["-d", "memory_limit=512M", "artisan", "migrate:fresh", "--seed", "--force"], { env, stdio: "inherit" });
if (migrate.status !== 0) process.exit(migrate.status ?? 1);

const server = spawn("php", ["artisan", "serve", `--port=${port}`, "--no-reload"], { cwd: backend, shell: true, env, stdio: "inherit" });
const stop = () => server.kill();
process.on("SIGINT", stop);
process.on("SIGTERM", stop);
server.on("exit", (code) => process.exit(code ?? 0));
