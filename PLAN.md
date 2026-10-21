# Plan

Each phase ends with: backend tests, frontend lint/typecheck/build, E2E (once it exists), commit, push to `develop`, and a summary row below.

1. **Phase 0** Audit (AUDIT.md) and this plan.
2. **Phase 1** Correctness and security: the H/M items in AUDIT.md, rate limits, security headers, error codes, feature tests.
3. **Phase 2** Features: notifications and reminders, cancellation rules, `PaymentService` (sandbox), pagination, polling availability, i18n (en/ne), CSV export.
4. **Phase 3** Frontend quality, component tests, Playwright E2E for driver, operator and admin.
5. **Phase 4** CI, production Docker, env validation, OpenAPI, docs, tile provider setting.
6. **Phase 5** Reviewer pass, fixes, full suite, PR `develop` to `main`.

## Decisions (safest defaults)

- Tests run on SQLite. `FOR UPDATE` is not exercised there; a CI MySQL job comes in Phase 4.
- Bearer token stays in `localStorage` (SPA design). Mitigation: short expiry and tight headers.
- `gh` is not installed. The final PR is prepared as a compare URL plus description unless `gh` is available.
- Payments are sandbox only, behind an interface, off by default.

## Phase summaries

| Phase | Done | Skipped | Why |
|---|---|---|---|
| 0 | Full repo read, AUDIT.md, PLAN.md, baseline run | n/a | n/a |
