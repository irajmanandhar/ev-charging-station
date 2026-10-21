# Audit

Baseline: backend 37 tests pass (SQLite); frontend lint clean, typecheck and build pass.
Severity: C critical, H high, M medium, L low. Effort: S/M/L.

## Bugs / correctness

| Sev | File | Issue | Fix | Effort |
|---|---|---|---|---|
| H | backend/app/Services/BookingService.php `startNow` | `create` and `checkIn` run in separate transactions. If check-in fails (charger busy, closed), a confirmed booking stays behind, holding the slot and counting toward the 3-booking cap until swept as a no-show. | Wrap both in one transaction so a failed start rolls the booking back. | S |
| H | BookingService `cancel` | No lock and no re-read. A cancel racing a check-in can overwrite an `active` booking with `cancelled`, leaving an active session on a cancelled booking. | Lock the booking row inside a transaction and re-check status. | S |
| M | BookingService `create` | Only the charger row is locked. Two concurrent requests from one user on different chargers can both pass the per-user overlap check and the max-upcoming cap. | Lock the user row first (consistent order: user, then charger). | S |
| M | BookingService `sweep` | One `stop()` that throws (session stopped by the driver at the same moment) aborts the whole sweep, so later sessions are not stopped. | Catch `DomainRuleException` per session and continue. | S |
| M | Operator station `update` | Operators can PATCH `status`. An admin who hides a station (`inactive`) can be undone by the operator. | Operators may set only `active`/`maintenance`, and not while admin-hidden. | S |
| M | Operator Station/Charger `destroy` | FK `cascadeOnDelete` wipes bookings and charging sessions (financial history) on delete. Station delete only checks `confirmed` bookings, not `active`. | Block delete when any session or booking history exists. Point operators at offline/maintenance. | S |
| M | Auth `verifyEmail` | Two concurrent verifications create two users; the second hits a unique-constraint 500. | Create inside the transaction and catch the duplicate. | S |
| L | tests/ | `phpunit.xml` lists `tests/Unit`, which does not exist, so `php artisan test` fails with no flags. | Add `tests/Unit` with real unit tests (pricing, station hours). | S |
| L | Operator controllers | Timezone hard-coded to `Asia/Kathmandu` in metrics and bookings. | Move to `config/ev.php` (`default_timezone`). | S |
| L | StationController `index` | Distance filter and sort run in PHP on up to 200 rows; no pagination. | Add pagination and a result cap. | M |

## Security

| Sev | File | Issue | Fix | Effort |
|---|---|---|---|---|
| H | UserOtp, email and password controllers | 6-digit OTP with no per-code attempt limit. Throttle is per IP only (10/min), so a distributed guess of the 1M space within 10 minutes is feasible. OTPs are stored in plaintext. | Count failed attempts per OTP and invalidate after 5. Throttle by email plus IP. Store hashed OTPs. | M |
| H | LoginController | Throttle is per IP only. No per-account lockout. `Hash::check` is skipped for unknown emails, so response timing reveals which emails exist. | Throttle by email plus IP. Always run a hash check. | S |
| H | SocialAuthController | `stateless()` with no `state` check. Links a provider to an existing account by email without confirming the provider verified that email. The API token is put in the redirect URL query string. | Return the token in the URL fragment, not the query string. Only link when the provider reports a verified email. | M |
| H | config/sanctum.php | Token `expiration` is `null`: tokens never expire. | Set expiry via env (default 7 days) and prune expired tokens in the scheduler. | S |
| M | docker-compose.yml, entrypoint | Runs `APP_ENV=local`, `APP_DEBUG=true`, `EXPOSE_OTP_IN_RESPONSE=true`, demo seed with `password` for an admin account, containers run as root. | Separate production compose: no demo seed, no OTP exposure, debug off, non-root. Keep dev compose labelled as dev. | M |
| M | bootstrap/app.php, nginx | No security headers (`X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, HSTS). Frontend sends none either. | Add middleware plus nginx and Next headers. | S |
| M | frontend/src/lib/api.ts | Bearer token in `localStorage` (readable by any XSS). | Accepted trade-off for a bearer-token SPA. Record it, keep headers tight, short token expiry. | S |
| M | RegisterController, resendOtp | Reveals whether an email is registered or pending (user enumeration). | Return a uniform response. | S |
| M | Rate limits | Booking and start-now are 20/min per IP, not per user. Public availability and station endpoints have no limit. | Named limiters keyed by user id, plus a limit on public reads. | S |
| L | pending_users, user_otps | Rows never purged. | Scheduled prune command. | S |
| L | config/cors.php | `allowed_headers: *`, `max_age: 0`. Origins already env-driven. | Explicit headers, `max_age` 600. | S |

## Missing features

| Sev | Item | Fix | Effort |
|---|---|---|---|
| M | Booking reminders and notifications | Notification classes plus a scheduled reminder command. | M |
| M | Cancellation rules and refunds (no fee or window logic) | Config-driven free-cancel window. Refund only meaningful with payments. | M |
| M | Payments (none) | `PaymentService` interface with a Khalti/eSewa sandbox driver and a null driver. | L |
| M | Pagination and filtering for bookings, sessions, operator and admin lists | Paginate and add status/date filters. | M |
| L | Near-real-time availability | SWR polling on station and finder pages. | S |
| L | i18n (English only) | Lightweight en/ne dictionary. | M |
| L | Operator CSV export | Streamed CSV of bookings and sessions. | S |

## UX / accessibility / frontend

| Sev | Area | Issue | Fix | Effort |
|---|---|---|---|---|
| M | Frontend | Not yet audited page by page for loading, error and empty states, keyboard and label coverage. | Phase 3 pass per page. | M |
| M | Map | OSM public tile URL hard-coded (usage policy forbids production use). | Configurable tile URL and attribution via env. | S |
| L | SEO | Only a global title and description. | Per-page metadata, robots, sitemap. | S |
| L | Login | Demo-login buttons always shown. | Hide unless `NEXT_PUBLIC_DEMO_LOGIN=true`. | S |

## Performance

| Sev | File | Issue | Fix | Effort |
|---|---|---|---|---|
| L | StationController `index` | Live state is eager-loaded (no N+1). Filters run in PHP. | Acceptable at current scale. Paginate. | S |
| L | MetricsService | Loads every completed session in range into memory. | Range already capped at 90 days. SQL aggregation is the next step. | S |
| L | Migrations | Booking indexes present. Missing `charging_sessions(user_id, started_at)` and `bookings(status, starts_at)` (sweep). | One migration with both indexes. | S |

## Test gaps

| Sev | Gap | Fix |
|---|---|---|
| H | No tests for: `startNow` rollback, cancel/check-in race, sweep idempotency, per-user lock, OTP attempt limit, token expiry, admin log on role change, policy matrix for bookings/sessions/stations | Phase 1. |
| M | No unit tests (`tests/Unit` missing) | Pricing and station-hours unit tests. |
| M | No frontend tests, no E2E | Phase 3 (Vitest + Playwright). |
| M | Tests run on SQLite only, so `FOR UPDATE` is untested | Document. CI MySQL job in Phase 4. |

## DevOps gaps

| Sev | Gap | Fix | Effort |
|---|---|---|---|
| H | No CI | GitHub Actions. | M |
| M | Dockerfiles: single stage, dev dependencies installed, root user, no healthchecks, frontend copies full `node_modules` | Multi-stage, `--no-dev`, non-root, healthchecks, Next standalone output. | M |
| M | No boot-time env validation, no config/route caching, no queue worker in compose, `QUEUE_CONNECTION=sync` | Validate on boot, cache in entrypoint, add worker. | M |
| M | No OpenAPI docs, deployment guide or backup notes | Add. | M |
| L | Log channel `single`, no error-monitoring hook | `stderr` in containers plus optional Sentry DSN env hook. | S |
