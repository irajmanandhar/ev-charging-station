# Deployment guide

One server (or VM) running Docker is enough to start. The production stack is `docker-compose.prod.yml`:

| Service | What it is | Port |
|---|---|---|
| `mysql` | MySQL 8.4, data in the `mysql-data` volume | none (internal) |
| `api` | Laravel on PHP-FPM, non-root, runs migrations on start | internal |
| `queue` | `queue:work`, sends booking and sign-up emails | none |
| `scheduler` | `schedule:work`: no-shows, auto-stop, reminders, pruning | none |
| `nginx` | Serves the API (unprivileged nginx) | 8000 |
| `web` | Next.js standalone server, non-root | 3000 |

The images are multi-stage: no dev dependencies, no test code, no `.env`, opcache on, health checks on every service that listens.

## 1. Prepare

You need a domain for the API (`https://api.example.com`) and one for the web app (`https://app.example.com`), and a TLS-terminating reverse proxy in front of ports 8000 and 3000 (Caddy, Traefik, nginx or your cloud load balancer). The containers speak plain HTTP.

```bash
cp .env.prod.example .env.prod
# fill in every empty value
docker run --rm php:8.3-cli php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'   # APP_KEY
```

Keep `APP_KEY` forever and back it up with the database: it keys the OTP hashes and encrypts anything Laravel encrypts. Never commit `.env.prod` (it is git-ignored).

## 2. Start

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
docker compose -f docker-compose.prod.yml --env-file .env.prod ps     # wait for "healthy"
```

On start the API container:

1. runs `php artisan env:check` and **refuses to start** if the environment has development settings (debug on, OTP echoed in responses, `localhost` URLs, SQLite, `log` mailer, `sync` queue, a payment driver without its key);
2. waits for MySQL and runs `php artisan migrate --force`;
3. caches config, routes and events.

The same check also stops the web process if the app is ever booted in production with unsafe settings. Demo data is never seeded in production: the entrypoint refuses `SEED_DEMO_DATA=true`.

## 3. Create the first admin

Sign up through the web app, then promote that account:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod exec api \
  php artisan user:set-role you@example.com admin
```

After that, admins promote operators from **Admin → Users**; every change is written to the admin log.

## 4. Configuration reference (the parts that matter)

| Variable | Why it matters |
|---|---|
| `API_URL`, `WEB_URL` | Must be `https://`. `WEB_URL` becomes the only allowed CORS origin and the social-login redirect target. |
| `MAIL_*` | Sign-up and password-reset codes and booking emails are sent by email. Without working SMTP nobody can register. |
| `SANCTUM_TOKEN_EXPIRATION_MINUTES` | Lifetime of a login (default 7 days). |
| `PAYMENTS_DRIVER` | `none`, `khalti` or `esewa`. Both gateways default to their **sandbox** URLs; change `KHALTI_BASE_URL` / `ESEWA_*` only after the provider has approved your merchant account. |
| `TILE_URL`, `TILE_ATTRIBUTION` | Build-time. The public OpenStreetMap server must not carry production traffic. |
| `LOG_SLACK_WEBHOOK_URL` | Optional error alerts. To send errors there, set `LOG_STACK=stderr,slack` and `LOG_SLACK_WEBHOOK_URL` in the compose file's API environment; another monitor (Sentry, Flare, Bugsnag) can be added with its Laravel package and DSN variable. |

Changing a `NEXT_PUBLIC_*`/`TILE_*` value or `API_URL` needs a rebuild of `web` (`up -d --build web`), because those values are baked into the browser bundle.

## 5. Updating

```bash
git pull
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
```

Migrations run automatically when the new `api` container starts. Write migrations so the previous version of the code still works against the new schema (add columns before using them, drop columns a release later) so a rollback is just checking out the previous commit and rebuilding.

## 6. Backups

Bookings and charging sessions are billing records: back up the database daily and test a restore.

```bash
# nightly dump (cron on the host)
docker compose -f docker-compose.prod.yml --env-file .env.prod exec -T mysql \
  sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines "$MYSQL_DATABASE"' \
  | gzip > /backups/ev-$(date +%F).sql.gz

# restore into an empty database
gunzip -c /backups/ev-2026-01-01.sql.gz | docker compose -f docker-compose.prod.yml --env-file .env.prod exec -T mysql \
  sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
```

- Keep at least 7 daily and 4 weekly copies, stored off the server, and keep `APP_KEY` with them.
- Practise the restore on a spare machine before you need it.
- `--single-transaction` takes a consistent snapshot without locking bookings.
- Uploaded files: none. The app stores no user files, so the database is the only state.

## 7. Operations

- **Logs:** every container writes to stdout/stderr (`docker compose logs -f api queue scheduler`). Ship them with your host's log driver.
- **Health:** `GET /up` on the API (used by the nginx health check), and the container health states in `docker compose ps`.
- **Queue stuck?** `docker compose restart queue`; failed jobs are in the `failed_jobs` table (`php artisan queue:failed`, `queue:retry all`).
- **Scheduler stopped?** Bookings are not swept or reminded while it is down. It is safe to run again: every job is idempotent.
- **Scaling:** `api` and `web` are stateless and can run as several replicas behind the load balancer. Run exactly **one** `scheduler`. Booking correctness relies on MySQL row locks, so keep MySQL (or PostgreSQL), not SQLite.

## 8. Before you launch: checklist

- [ ] `docker compose ... ps` shows every service healthy, and `env:check` passes
- [ ] Register a real account end to end (the email arrives, the code works)
- [ ] Book, check in, stop and cancel as a driver; open the operator dashboard; export a CSV
- [ ] A map tile provider with a production agreement is configured
- [ ] Backups run, and a restore has been tested
- [ ] `PAYMENTS_DRIVER` is still the sandbox or `none` until your merchant account is approved
- [ ] The first admin account exists, and the demo accounts do not (they only exist in the development stack)
- [ ] Social login (if used): redirect URIs in the Google/Facebook consoles point at `API_URL`
- [ ] Nepali translations reviewed by a native speaker if you advertise the language switch

## Known limits

- The browser keeps the login token in `localStorage` (a bearer-token SPA). Keep third-party scripts out of the site and keep token lifetime short.
- Online refunds are not automated: Khalti and eSewa have no public refund API, so refunds are flagged `refund_pending` for a person to action in the provider dashboard.
- Rate limits use the application cache (`database` store in this stack). With several API replicas that is shared; with the `array` store (tests only) it is per process.
