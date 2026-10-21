# EV Charge Platform

## Stack
- Backend: Laravel 12 (PHP 8.2+), Sanctum, Socialite — `backend/`
- Frontend: Next.js 16, React 19, Tailwind 4, SWR, Leaflet, Recharts — `frontend/`
- Docker Compose at repo root.

## Commands
- Backend tests: `cd backend && php artisan test`
- Backend lint: `cd backend && vendor/bin/pint`
- Frontend lint / typecheck / unit tests: `cd frontend && npm run lint && npm run typecheck && npm test`
- End-to-end: `cd frontend && npm run test:e2e`
- API contract: update `backend/openapi.yaml` when routes change (`OpenApiTest` checks it)
- Frontend build: `cd frontend && npm run build`
- Frontend dev: `cd frontend && npm run dev`

## Architecture rules
- Business logic lives in `app/Services`. Controllers stay thin.
- Tunable values live in `config/ev.php`, not hard-coded.
- Keep the API response envelope consistent across endpoints.
- Keep the `SELECT ... FOR UPDATE` lock in the booking flow intact.
- Keep authorization policies (`app/Policies`) intact and applied.

## Working style
- Surgical, minimal diffs. No whole-file rewrites.

## Commits, PRs, docs
- No `Co-Authored-By` trailer, no "Generated with Claude" text.
- No mention of Claude or AI in commits, PRs, code comments or docs.
- Commit messages: normal human style, imperative, concise.
