# Bijuli Charge: EV charging station finder & booking platform

A full-stack platform for EV drivers and charging-station operators in Nepal.

- **Drivers** find nearby chargers on a map, see live availability, book a slot up to 7 days ahead (or start charging on the spot), and track the session.
- **Operators** list stations and chargers, set hours and prices, watch today's bookings and see usage and revenue metrics.
- **Admins** manage roles and can pause or hide any station on the network.

| Layer | Stack |
|---|---|
| API | Laravel 12 · PHP 8.3 · Sanctum tokens · MySQL 8 (SQLite for local/tests) |
| Web | Next.js 16 (App Router) · React 19 · TypeScript · Tailwind CSS 4 · SWR · Leaflet/OpenStreetMap · Recharts |
| Ops | Docker Compose (MySQL, PHP-FPM, Nginx, queue worker, scheduler, Next.js), GitHub Actions CI |

This project started as a three-repo team project (a Flutter app, a Laravel API and a Next.js CMS). The original auth flow (email OTP sign-up, password reset by OTP, Google and Facebook login) was kept and cleaned up. The station, booking, session, operator and admin domain was built on top of it, and the web app was rewritten in TypeScript.

---

## Quick start (Docker, development)

This stack is for development only: demo data, known passwords, OTP codes shown on screen. To deploy, see [DEPLOYMENT.md](DEPLOYMENT.md).

```bash
docker compose up --build
```

- Web app: http://localhost:3000
- API: http://localhost:8000

On first boot the database is migrated and seeded with 12 demo stations around the Kathmandu valley (plus Pokhara and Dhulikhel), 60 days of charging history, and some live activity. All stations and people in the seed data are fictional.

| Demo login | Password | Sees |
|---|---|---|
| `driver@evcharge.test` | `password` | Map, booking, My bookings |
| `operator@evcharge.test` | `password` | Operator dashboard for 6 stations |
| `operator2@evcharge.test` | `password` | A second operator's 6 stations |
| `admin@evcharge.test` | `password` | Everything, plus Admin |

The login page has one-click demo buttons.

## Running without Docker

**API** (needs PHP 8.2+ and Composer):

```bash
cd backend
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve --port=8000      # in another terminal: php artisan schedule:work
```

**Web** (needs Node 20+):

```bash
cd frontend
cp .env.example .env.local         # NEXT_PUBLIC_API_URL=http://localhost:8000
npm install
npm run dev
```

## Tests

```bash
cd backend && php artisan test                  # PHPUnit: feature + unit (SQLite in memory)
cd frontend && npm run lint && npm run typecheck && npm test   # ESLint, tsc, Vitest component tests
cd frontend && npm run test:e2e                 # Playwright: boots its own API + web server
```

- **E2E** builds the web app, creates a throwaway SQLite database with the demo seed, and drives driver, operator and admin flows in a real browser. Locally it uses the Chrome you have installed; set `E2E_CHANNEL=` and run `npx playwright install chromium` to use Playwright's own browser.
- **Booking races:** the PHPUnit suite is sequential, so `scripts/concurrency/` fires truly simultaneous requests at a multi-worker server on MySQL (see its README). CI runs both, and the whole PHPUnit suite on MySQL as well.
- **API docs:** [`backend/openapi.yaml`](backend/openapi.yaml) (OpenAPI 3.1). `OpenApiTest` fails if a route is added or removed without updating it. Open it in [Swagger Editor](https://editor.swagger.io) or any OpenAPI viewer.

---

## How booking works

- **Time grid:** chargers are booked in 15-minute steps, for between 30 minutes and 4 hours, up to 7 days ahead, within the station's opening hours (in the station's own time zone, Asia/Kathmandu by default).
- **No double-booking:** creating a booking locks the charger row (`SELECT … FOR UPDATE`) inside a transaction before the overlap check. Two drivers racing for the same slot are processed one after the other, and the second gets `409 slot_taken`. Intervals are half-open, so back-to-back bookings are allowed.
- **Live status** is derived rather than stored:
  - **offline:** the charger is under maintenance or the station is paused
  - **occupied:** a session is active
  - **reserved:** a confirmed booking covers now or starts within 15 minutes
  - **available:** otherwise
- **Check-in:** opens 10 minutes before the slot and closes 15 minutes after its start. Early check-in can't eat into someone else's slot.
- **Stopping:**
  - Energy is metered as `power × hours × 0.85` (capped at 80 kWh) and billed at the charger's NPR/kWh price.
  - Stopping early releases the rest of the slot to other drivers.
  - The every-minute `bookings:sweep` job marks missed bookings as no-shows and stops sessions whose slot has ended.
- **Walk-up charging:** "Charge now" creates a booking from the current minute and checks in immediately, so walk-ups go through the same conflict rules.
- **Limits:** a driver can hold at most 3 upcoming bookings, and can't have two bookings that overlap. A driver's own requests are serialised (user row lock, then charger row lock, always in that order), so two tabs can't slip past those limits.
- **Cancellation:** free until `cancellation_free_minutes` (60) before the start; later cancellations keep `cancellation_fee_percent` (20 %) of the estimated cost. A paid booking is refunded minus the fee where the payment provider has a refund API, otherwise the payment is flagged `refund_pending` for the operator.
- **Emails** (queued): booking confirmed, reminder `reminder_minutes_before` (30) before the start, cancelled, and missed (no-show). Run `php artisan bookings:remind` (the scheduler does it every minute).
- **Payments (sandbox):** `PaymentService` talks to a `PaymentGateway` interface with `none` (default), `khalti` and `esewa` implementations. The driver is redirected to the provider and the API verifies the result with the provider before marking the booking paid. See `config/payments.php`.

Rules live in `backend/config/ev.php`. Business logic lives in `app/Services/` (`BookingService`, `AvailabilityService`, `PricingService`, `MetricsService`), and the controllers stay thin.

## API overview

All responses use `{ success, message, data }`. Errors use `{ success: false, message, code, errors }`: `code` is a stable value such as `validation_failed`, `forbidden` or `too_many_requests`, and business-rule errors repeat theirs in `errors.code` (for example `slot_taken`, `station_closed` or `too_early`). The full contract is in [`backend/openapi.yaml`](backend/openapi.yaml).

| | Endpoint |
|---|---|
| **Auth** | `POST /api/register` → `POST /api/verify-email-otp` · `POST /api/login` · `GET /api/me` · `POST /api/logout` · `POST /api/password/{forgot,verify-otp,reset}` · `GET /api/auth/{google,facebook}/redirect` |
| **Public** | `GET /api/config` · `GET /api/stations?lat&lng&radius_km&connector&min_power&available_now&q` · `GET /api/stations/{id}` · `GET /api/chargers/{id}/availability?date=` · `GET /api/chargers/{id}/quote?minutes=` |
| **Driver** | `GET/POST /api/bookings` (`?scope&status&page&per_page`) · `POST /api/bookings/{id}/pay` · `POST /api/payments/{id}/confirm` · `POST /api/bookings/{id}/cancel` · `POST /api/bookings/{id}/check-in` · `POST /api/chargers/{id}/start` · `GET /api/sessions` · `POST /api/sessions/{id}/stop` |
| **Operator** | `GET /api/operator/metrics?days=7\|30\|90&station_id` · `GET /api/operator/bookings?date&station_id&status` · `GET /api/operator/export/bookings?from&to&station_id` (CSV) · CRUD `/api/operator/stations` · `POST /api/operator/stations/{id}/chargers` · `PATCH/DELETE /api/operator/chargers/{id}` |
| **Admin** | `GET /api/admin/overview` · `GET /api/admin/users` · `PATCH /api/admin/users/{id}/role` · `GET /api/admin/stations` · `PATCH /api/admin/stations/{id}/status` |

Access is enforced by Sanctum, a `role:` middleware and policies (`StationPolicy`, `BookingPolicy`, `ChargingSessionPolicy`). Operators can only touch their own stations. `role` is never mass-assignable, and admin actions are written to `admin_logs`.

## Data model

```
users (role: driver|operator|admin)
 └─ stations (operator_id, lat/lng, timezone, hours, amenities, status)
     └─ chargers (label, connector CCS2|CHAdeMO|Type2|GBT, power_kw, price_per_kwh, status)
         ├─ bookings (user_id, starts_at, ends_at, status: confirmed|active|completed|cancelled|no_show, estimates)
         └─ charging_sessions (booking_id, started_at, ended_at, energy_kwh, cost)
admin_logs · pending_users · user_otps · auth_providers · personal_access_tokens
```

## Notes

- **Email:** in local mode, OTP emails are written to `storage/logs/laravel.log`. With `EXPOSE_OTP_IN_RESPONSE=true` (honoured only when `APP_ENV` is `local` or `testing`), the code is also returned to the sign-up screen, so you can register without a mail server. Codes are stored hashed and die after 5 wrong guesses.
- **Maps:** tiles default to the public OpenStreetMap server, which is fine for development but not for real traffic. Set `NEXT_PUBLIC_TILE_URL` (and `NEXT_PUBLIC_TILE_ATTRIBUTION`) at build time to use a provider that allows production use.
- **Language:** the web app has an English / नेपाली switch (navigation, finder and My bookings so far). API messages are English only. The Nepali strings need a native-speaker review before launch.
- **Tokens:** API tokens expire after `SANCTUM_TOKEN_EXPIRATION_MINUTES` (default 7 days) and the web app keeps its token in `localStorage`, so keep the page's script surface small and don't add third-party scripts casually.
- **Security:** the original Flutter repo had Google API keys hard-coded in `lib/core/constants/api_end_points.dart`. Rotate them in Google Cloud Console if that repo was ever public.
