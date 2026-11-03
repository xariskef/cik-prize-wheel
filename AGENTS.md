# AGENTS.md

Prize Wheel Kiosk — WordPress plugin for trade-show kiosks: visitor registration, a server-side prize draw,
vouchers and redemption, CSV export, email queue and WP-CLI. The kiosk front end talks to it over REST.

## Map

- `cik-prize-wheel.php` — header, constants, bootstrapping, activation hooks
- `includes/class-cikw-*.php` — one class per concern (see the README "Project structure")
- `includes/class-cikw-spin-engine.php` — the draw; the only place that touches stock and spin rows
- `includes/class-cikw-rest.php` — REST routes (`cikw/v1`), permission callbacks, validation
- `includes/class-cikw-security.php` — kiosk/staff keys, rate limiting, IP hashing, entry tokens
- `includes/class-cikw-activator.php` — schema (`dbDelta`), `CIKW_SCHEMA_VERSION`, upgrades
- `tests/` — standalone PHP tests (no WordPress needed) + the concurrency test (needs WordPress)

## Conventions

- Prefixes: classes `CIKW_`, constants `CIKW_*`, functions/options/hooks/tables `cikw_`, handles/CSS `cikw-`.
- Text domain `cik-prize-wheel`; source strings in English, wrapped in `__()` / `esc_html__()`.
- Sanitise on input, escape on output (late). Every admin action: nonce + `current_user_can()`.
- SQL only through `$wpdb->prepare()`; table names with `%i`.
- Any schema change: update `CIKW_Activator`, bump `CIKW_SCHEMA_VERSION`, keep `maybe_upgrade()` working on existing installs.
- The spin budget and stock are enforced by the database (unique index, conditional updates) — never by a read-then-write in PHP.
- PHP 8.1 compatible. No external dependencies.

## Checks before a commit

```bash
# syntax
docker run --rm -v "$PWD:/app" -w /app php:8.1-cli sh -c 'for f in $(find . -name "*.php"); do php -l "$f" | grep -v "No syntax errors"; done'
# tests
php tests/engine-unit-test.php
php tests/export-filters-test.php
php tests/demo-data-test.php
```

All three tests must pass, no PHP notices on the admin screens, no debug output left in the code.
Commits: short conventional messages in English (`fix:`, `feat:`, `refactor:`, `docs:`, `test:`).
