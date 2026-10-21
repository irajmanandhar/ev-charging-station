# Bijuli Charge: EV charging station finder & booking platform

A full-stack platform for EV drivers and charging-station operators in Nepal.

- **Drivers** find nearby chargers on a map, see live availability, book a slot up to 7 days ahead (or start charging on the spot), and track the session.
- **Operators** list stations and chargers, set hours and prices, watch today's bookings and see usage and revenue metrics.
- **Admins** manage roles and can pause or hide any station on the network.

| Layer | Stack |
|---|---|
| API | Laravel 12 · PHP 8.3 · Sanctum tokens · MySQL 8 (SQLite for local/tests) |
| Web | Next.js 16 (App Router) · React 19 · TypeScript · Tailwind CSS 4 · SWR · Leaflet/OpenStreetMap · Recharts |
| Ops | Docker Compose (MySQL, PHP-FPM, Nginx, scheduler, Next.js) |

This project started as a three-repo team project (a Flutter app, a Laravel API and a Next.js CMS). The original auth flow (email OTP sign-up, password reset by OTP, Google and Facebook login) was kept and cleaned up. The station, booking, session, operator and admin domain was built on top of it, and the web app was rewritten in TypeScript.

---

## Quick start (Docker)

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

**Tests:**

```bash
cd backend && php artisan test
```

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
- **Limits:** a driver can hold at most 3 upcoming bookings, and can't have two bookings that overlap.

Rules live in `backend/config/ev.php`. Business logic lives in `app/Services/` (`BookingService`, `AvailabilityService`, `PricingService`, `MetricsService`), and the controllers stay thin.

## API overview

All responses use `{ success, message, data }`. Errors use `{ success: false, message, errors }`, and business-rule errors include `errors.code` (for example `slot_taken`, `station_closed` or `too_early`).

| | Endpoint |
|---|---|
| **Auth** | `POST /api/register` → `POST /api/verify-email-otp` · `POST /api/login` · `GET /api/me` · `POST /api/logout` · `POST /api/password/{forgot,verify-otp,reset}` · `GET /api/auth/{google,facebook}/redirect` |
| **Public** | `GET /api/stations?lat&lng&radius_km&connector&min_power&available_now&q` · `GET /api/stations/{id}` · `GET /api/chargers/{id}/availability?date=` · `GET /api/chargers/{id}/quote?minutes=` |
| **Driver** | `GET/POST /api/bookings` · `POST /api/bookings/{id}/cancel` · `POST /api/bookings/{id}/check-in` · `POST /api/chargers/{id}/start` · `GET /api/sessions` · `POST /api/sessions/{id}/stop` |
| **Operator** | `GET /api/operator/metrics?days=7\|30\|90&station_id` · `GET /api/operator/bookings?date&station_id` · CRUD `/api/operator/stations` · `POST /api/operator/stations/{id}/chargers` · `PATCH/DELETE /api/operator/chargers/{id}` |
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

- **Email:** in local mode, OTP emails are written to `storage/logs/laravel.log`. With `EXPOSE_OTP_IN_RESPONSE=true` (honoured only when `APP_ENV` is `local` or `testing`), the code is also returned to the sign-up screen, so you can register without a mail server.
- **Maps:** map tiles come from OpenStreetMap, so no API key is needed. For real traffic, switch to a tile provider that allows production use.
- **Security:** the original Flutter repo had Google API keys hard-coded in `lib/core/constants/api_end_points.dart`. Rotate them in Google Cloud Console if that repo was ever public.
