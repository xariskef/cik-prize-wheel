# Prize Wheel Kiosk

A WordPress plugin that runs touchscreen visitor registration and a "spin the wheel" prize draw at trade shows and events, with fair odds, hard stock limits, voucher redemption and a CSV export of every lead.

## The problem it solves

Exhibitors at trade shows use a prize wheel to attract visitors to the booth and collect their contact details. Doing that on paper, or with a generic form plus a random-number toy, causes the same problems every time:

- prizes run out mid-show, or the expensive prize is gone in the first hour;
- two visitors "win" the last unit at the same moment;
- nobody can prove afterwards that the odds were what the organiser said they were;
- the lead list arrives as a spreadsheet that Excel opens as mojibake;
- the booth network is slow, and the kiosk freezes while an email is being sent.

This plugin keeps all of that on the server: the kiosk only asks the REST API what to show and what the result of a spin is, and WordPress decides, records and audits everything.

## Features

- **REST API for a kiosk front end** (`cikw/v1`): configuration, registration, spin, finish, redemption and a live status feed.
- **Spin engine** with two independent draws (which category the wheel lands on, then whether a prize is won), integer maths, `random_int()` only, and a JSON decision log stored with every spin.
- **Stock that cannot be oversold**: an atomic conditional `UPDATE` claims each unit; optional per-prize daily caps.
- **Configurable out-of-stock policy**: a sold-out prize's share either becomes "no win" or is redistributed across the remaining prizes.
- **Vouchers**: 6-character codes from an alphabet without look-alike characters (no 0/O, 1/I/L), unique at database level, redeemable from wp-admin or by booth staff through the API.
- **Admin screens** under a "Prize Wheel" menu: categories (drag-and-drop wheel order, colours, images), prizes (probabilities, stock, daily caps), entries (filterable list table), redemption, settings and test tools.
- **CSV export** that follows the active filters, streams in chunks, starts with a UTF-8 BOM so Excel reads non-Latin text correctly, and neutralises spreadsheet formula injection.
- **Confirmation emails** (HTML and plain text) sent from a WP-Cron queue with retries and exponential backoff, so the kiosk never waits for SMTP.
- **Dashboard widget** with live counters, last activity and the scarcest prizes.
- **WP-CLI commands** to drain the email queue and inspect it.
- **Demo data and simulation**: a 12-category / 48-prize dataset and a tool that runs simulated visitors through the real engine for dry runs.
- **Translatable**: all strings are English source strings in the `cik-prize-wheel` text domain.

## How it works

### Request flow

```
Kiosk (touchscreen)                    WordPress (this plugin)
-------------------                    -----------------------
GET  /config  ───────────────────────► wheel segments, form rules, timings, UI copy
POST /register  (X-CIKW-Kiosk-Key) ──► validate + rate limit ──► wp_cikw_entries
                ◄─────────────────────  entry_id + entry_token (random, 43 chars)
POST /spin      (entry_id + token) ──► CIKW_Spin_Engine ──► wp_cikw_spins (+ voucher)
                ◄─────────────────────  segment_index, outcome, prize, voucher_code
POST /finish    (entry_id + token) ──► mark finished ──► email queued for WP-Cron
                                       WP-Cron / WP-CLI ──► CIKW_Mailer ──► visitor's inbox
Booth staff
POST /redeem    (X-CIKW-Staff-Key) ──► CIKW_Vouchers::redeem() ──► redeemed_at / redeemed_by
```

The touchscreen front end itself (attract screen, form, wheel animation, on-screen keyboard) is a separate client and is not part of this repository. Everything it needs is served by `GET /config`, so wording and timings can change without rebuilding it.

### The spin engine (`includes/class-cikw-spin-engine.php`)

1. **Authenticate the entry.** The entry token is compared with `hash_equals()`.
2. **Check the spin budget** (`max_spins_per_registration`).
3. **Pick the category.** Each active category has a `wheel_weight`; weights are scaled to integers and one `random_int()` roll walks the cumulative total. The index returned is the position in `GET /config`'s segment list, so the animation always stops on the category that was actually drawn.
4. **Build the prize distribution** for that category. Probabilities are `DECIMAL(6,3)` percentages, scaled ×1000 into a 100,000-unit space, so there are no float comparisons in the draw. Sold-out prizes and prizes that hit their daily cap are excluded; their share goes to "no win" (`to_lose`) or is spread over the remaining prizes (`redistribute`). If the total ever exceeds 100% (for example after a direct database edit) it is scaled down rather than allowed to make "no win" negative.
5. **Roll once** over the 100,000 units. Anything past the last prize is a legitimate "no win".
6. **Claim the stock** with `UPDATE … SET stock_remaining = stock_remaining - 1 WHERE id = %d AND stock_remaining > 0`. Only the request whose `UPDATE` affects one row gets the prize. A request that loses this race is **downgraded to "no win" instead of re-rolling**, because re-rolling would bias the published odds.
7. **Write the spin row** with a new voucher code. Voucher uniqueness is enforced by a `UNIQUE` index; on a collision the code is regenerated (up to 5 attempts). If the row cannot be written, the claimed unit is handed back.
8. **Return** the segment index, outcome, prize and voucher, and keep the full decision (weights, units, roll, exclusions, policy, claim result) in the spin's `decision_log` JSON column for auditing.

### Concurrency

- **Stock** is protected by the conditional `UPDATE` above. InnoDB applies it atomically per row, with no explicit transaction or lock, so parallel kiosks cannot oversell. `tests/concurrency-spin-test.php` fires 50 parallel HTTP spins at a prize with stock 3 and asserts exactly 3 wins.
- **The spin budget** is enforced by a `UNIQUE (entry_id, spin_number)` index. Two simultaneous `/spin` calls for the same visitor can both pass the quick count check, but only one row can be written; the other gets HTTP 409.
- **Voucher codes** rely on a `UNIQUE` index rather than a read-then-insert check.
- **Redemption** uses `UPDATE … WHERE redeemed_at IS NULL`, so if two staff members scan the same code at once, exactly one succeeds and the other is told it is already redeemed.
- **Daily caps** are a read-then-claim check, so under heavy parallel load a cap can be exceeded by a request or two. Total stock is never exceeded.

## Security notes

- **Public kiosk endpoints.** The kiosk runs unattended on a public URL, so it cannot hold a WordPress login. Write endpoints require a shared **kiosk key** header (`X-CIKW-Kiosk-Key`), compared in constant time. The key ships inside the kiosk page, so it is treated as a filter against random traffic, not a secret. Abuse is limited by:
  - a per-device **and** per-IP hourly registration limit (the per-IP limit exists because `device_id` is client-supplied);
  - per-entry random tokens for `/spin` and `/finish`, so knowing an entry ID is not enough;
  - the spin budget per registration, enforced in the database;
  - server-side validation and length limits on every field.
- **Client IP** is read from `REMOTE_ADDR` only. Proxy headers such as `X-Forwarded-For` are opt-in via the `cikw_trusted_ip_headers` filter, so a client cannot fake its IP to dodge the rate limit. The IP is never stored; only an HMAC-SHA256 with a site salt is kept.
- **Redemption** (`POST /redeem`) requires either a logged-in user with `manage_options` (cookie auth plus the standard `wp_rest` nonce) or a separate **staff key** that is never shown on public pages. `GET /status` returns aggregates and stock only, with no personal data.
- **wp-admin:** every screen and `admin-post` action checks `current_user_can('manage_options')` and a nonce (`check_admin_referer`). Destructive test tools also need an explicit "I understand" checkbox in the same request. Redirects use `wp_safe_redirect()`.
- **Output** is escaped at the point of output (`esc_html`, `esc_attr`, `esc_url`, `esc_textarea`, `wp_kses_post`). **Input** is unslashed and sanitised (`sanitize_text_field`, `sanitize_email`, `absint`, allow-lists for enums, dates and `ORDER BY` columns).
- **SQL:** every value goes through `$wpdb->prepare()`; table names are bound with `%i` where possible. The two dynamic `WHERE` builders only concatenate fragments that were already prepared.
- **CSV export** is admin-only and nonce-protected, and prefixes any cell starting with `=`, `+`, `-`, `@`, tab or CR (also after leading spaces) with `'`, so a visitor cannot plant a spreadsheet formula.
- **Direct access:** every PHP file in `includes/` exits without `ABSPATH`. The CLI scripts in `tests/` and `tools/` refuse to run outside the command line, and `index.php` files prevent directory listings.
- **Uninstall** removes tables and options only if the site owner opted in ("Delete data on uninstall"). Participant data is never dropped by accident.

## Requirements

- WordPress 6.5 or later
- PHP 8.1 or later
- MySQL 5.7+ or MariaDB 10.2.7+ with InnoDB (the spins table uses a `JSON` column)
- A working `wp_mail()` setup (an SMTP plugin is recommended for event networks)
- Optional: WP-CLI, and a real system cron for the email queue

## Installation

1. Copy the plugin folder to `wp-content/plugins/cik-prize-wheel/`.
2. Activate **Prize Wheel Kiosk** in *Plugins*.

On activation the plugin creates four tables (`{prefix}cikw_categories`, `cikw_prizes`, `cikw_entries`, `cikw_spins`) with `dbDelta()`, stores default settings, **loads the demo dataset if the wheel is empty**, and schedules the email queue. Schema changes in later versions are applied automatically on the next admin page load.

## Configuration

Go to **Prize Wheel → Settings**:

| Setting | What it does |
| --- | --- |
| Maximum spins per registration | How many times one visitor may spin (default 2). |
| Out-of-stock policy | Sold-out prize share becomes "no win", or is redistributed. |
| Idle timeout / warning / auto-return | Kiosk timings, passed to the front end. |
| Spin duration, sound, screen orientation | Front-end presentation options. |
| Required form fields | Name, email, phone, interest, company, role. |
| Registrations per hour / device | Rate limit (the per-IP ceiling defaults to 5×). |
| Kiosk key / Staff key | Clear a field and save to rotate the key. |
| Consent text and version, privacy policy URL | The version is stored with each entry. |
| Email subject and template | Placeholders: `{full_name}`, `{first_name}`, `{category_recap}`, `{prize_details}`, `{voucher_codes}`. |
| Delete data on uninstall | Off by default. |

Then set up **Categories** (wheel segments, in drag-and-drop order) and **Prizes** (probability %, total stock where 0 = unlimited, remaining stock, optional daily cap). The prizes screen refuses to save a category whose active probabilities add up to more than 100%.

The kiosk front end needs the REST base URL and the kiosk key, both shown on **Prize Wheel → Test tools**.

Phone numbers are validated as 10 digits with an optional `+30` / `0030` prefix (the Greek format the plugin was first used with).

### Hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `cikw_config_payload` | filter | Change the whole `/config` payload. |
| `cikw_config_strings` | filter | Change the kiosk UI copy. |
| `cikw_trusted_ip_headers` | filter | `$_SERVER` keys to trust for the client IP behind a proxy/CDN, e.g. `['HTTP_CF_CONNECTING_IP']`. |
| `cikw_rate_limit_per_ip` | filter | Hourly registration ceiling per IP across all devices. |

## WP-CLI commands

WP-Cron only runs when someone loads a page, and a kiosk sitting on its attract screen loads none. During an event, drain the queue from a shell or a system cron:

```bash
wp cikw flush-emails              # send up to 50 due emails
wp cikw flush-emails --limit=100  # custom batch size
wp cikw flush-emails --all        # keep going until nothing is due
wp cikw queue-status              # counts per email status + next cron run
```

## Running the tests

Three test scripts run with nothing but a PHP binary. They stub the few WordPress functions they need, so no WordPress, database or Composer is required:

```bash
php tests/engine-unit-test.php     # spin engine maths, policies, caps, vouchers, duplicate-spin guard
php tests/export-filters-test.php  # CSV formula hardening, export columns, filter allow-lists, mailer backoff
php tests/demo-data-test.php       # sanity checks on the demo dataset
```

Or with Docker, without installing PHP:

```bash
docker run --rm -v "$PWD:/app" -w /app php:8.1-cli php tests/engine-unit-test.php
```

The **concurrency test** needs a real WordPress install with the plugin active, because it proves something only separate PHP workers on separate database connections can show. Run it against a staging site, never during an event:

```bash
php tests/concurrency-spin-test.php --wp=/path/to/wordpress --url=https://staging.example.com
# options: --spins=50 --stock=3 --keep --insecure (self-signed TLS)
```

It creates its own category, prize and entries, temporarily deactivates the other categories, fires the parallel requests, checks the database, and restores everything.

**Demo data and dry runs** are available from **Prize Wheel → Test tools** or from the command line:

```bash
php tools/seed-demo-data.php --wp=/path/to/wordpress                 # seed if empty
php tools/seed-demo-data.php --wp=... --reset --entries=300          # re-seed and simulate 300 visitors
php tools/seed-demo-data.php --wp=... --wipe                         # delete everything
```

Simulated visitors use `@example.test` addresses and are marked as "skipped" for email, so nothing is ever sent to them.

## Project structure

```
cik-prize-wheel/
├── cik-prize-wheel.php              Plugin header, constants, bootstrapping, activation hooks
├── uninstall.php                    Opt-in data removal
├── includes/
│   ├── class-cikw-plugin.php        Wires all components together (singleton)
│   ├── class-cikw-activator.php     Tables (dbDelta), defaults, schema upgrades
│   ├── class-cikw-settings.php      Settings API screen, options, kiosk/staff keys
│   ├── class-cikw-config.php        /config payload: segments, form rules, timings, UI strings
│   ├── class-cikw-rest.php          REST routes, permission callbacks, registration validation
│   ├── class-cikw-security.php      Key checks, IP hashing, entry tokens, rate limiting
│   ├── class-cikw-spin-engine.php   The draw, stock claim, voucher generation, decision log
│   ├── class-cikw-vouchers.php      Voucher lookup, redeem / undo, summaries
│   ├── class-cikw-entries-query.php Shared filters for the list table and the export
│   ├── class-cikw-entries-table.php WP_List_Table for entries
│   ├── class-cikw-export.php        Streaming CSV export
│   ├── class-cikw-mailer.php        HTML + plain-text confirmation email, retry bookkeeping
│   ├── class-cikw-cron.php          WP-Cron email queue
│   ├── class-cikw-cli.php           WP-CLI commands
│   ├── class-cikw-dashboard.php     Dashboard widget and /status snapshot
│   ├── class-cikw-admin-data.php    Categories and prizes screens
│   ├── class-cikw-admin-entries.php Entries screen, export button, resend action
│   ├── class-cikw-admin-redeem.php  Redemption screen
│   ├── class-cikw-admin-tools.php   Test tools (seed, simulate, reset)
│   └── class-cikw-demo-data.php     Demo dataset and visitor simulation
├── assets/admin/                    Admin CSS and JS (sorting, media picker, live probability totals)
├── languages/                       Translation files
├── tests/                           Standalone unit tests + concurrency test
└── tools/seed-demo-data.php         CLI seeder / simulator
```

## License

Copyright © Cikefallinos. All rights reserved. Source code published for portfolio review only.

See [LICENSE](LICENSE).
