# CLAUDE.md

## Project Overview

**Vaka** (working name) - keeps watch over a set of websites. Swedish "vaka över" means to keep watch over.

- **Tagline:** "Keeps watch while you sleep."
- **Subtitle:** "Uptime, response times and certificates for every site, plus domains, exposed files, security headers and mail DNS. One cron line, no dependencies."

Vaka replaces an older uptime monitor built on a full framework stack that became hard to keep up to date. Vaka must not go the same way: every decision below is made so the project still runs, unchanged, on whatever PHP 8.x is current years from now.

Uptime is the main job: knowing when a site goes down, and saying so once (not fifty times) to the right people. SSL certificates come second, the other checks after. The dashboard should look good, not just work.

Built for our own need first. Open sourced (MIT, like Puls) once it works.

## Never store sensitive data in git

This repository will be public. Nothing about the user, the company, its customers or their sites goes into it: no real names, domains, URLs, IPs, server names, Slack channels or webhook URLs. That covers code, tests, fixtures, docs, examples, screenshots, commit messages, the CHANGELOG, issues and PRs.

- Use reserved example names: `example.com`, `example.se`, `shop.example`, and documentation IPs (`192.0.2.x`, `198.51.100.x`, `203.0.113.x`).
- Real data lives only in gitignored paths (`data/`, `.env`, `users.json`, `*.sqlite`, `.private/`) or outside the repo. Real site lists are imported with `site:import <path>` from wherever they live; never copy them, or anything from them, into the repo.
- Demo data comes from a seed script with fake sites.
- Sensitive project notes (real groups, servers, migration steps) go in `.private/` (gitignored). Read `.private/README.md` for what is there. Never reference its contents from tracked files.
- Handoffs go in `.claude/handoffs/` (gitignored), since they name real sites.
- Before every commit, check the staged diff for real names, domains, IPs and URLs.

## Principles

- **No dependencies at runtime.** PHP 8.3+ with built-in extensions only: `pdo_sqlite`, `curl`, `openssl` (`intl` optional). No Composer runtime packages, no framework, no build step, no CDN. Deploy = copy the files. Must run on Laravel Forge and on shared hosting wherever cron exists.
- **"No dependencies", not "one file".** A few files loaded with plain `require` are fine. Code lives in `src/` as plain functions, so tests load it with `require` (no regex extraction or `eval`, unlike Puls's test bootstrap).
- **SQLite in WAL mode** with a schema version (`PRAGMA user_version`) and lazy migrations, like Puls's `run_migrations()` / `migrate_schema()`.
- **Config:** `config.php` reads an optional `.env` (a copy of `.env.example`). OS environment variables win over `.env`.
- **One CLI entrypoint:** `php vaka <command>`. Every command falls back to interactive prompts (readline) when called without arguments.
- **Dashboard:** plain HTML/CSS/JS served from `public/`, no CDN, dark and light mode, accessible, works on mobile, and good-looking. Every value rendered into HTML is escaped (build DOM with `textContent`; server-side HTML goes through `e()`).
- **Strict CSP from day one:** `script-src 'self'` without `'unsafe-inline'`, and the same for `style-src`. No inline `<script>`, no `onclick`/`onkeydown` attributes, no `style="..."` in markup. Event delegation: `data-action` attributes plus one listener in `public/app.js`.
- **Auth like Puls:** `users.json` with bcrypt, managed via the CLI; access per group from the start (`groups` per user, empty = all; a restricted user only sees and snoozes sites in their groups, enforced in every endpoint); session cookies (`httponly`, `SameSite=Strict`, `secure` on HTTPS); CSRF tokens on every POST; login lockout counted per IP in SQLite (not in the session); security headers on every response.
- **Store as little as possible.** No personal data. Uptime samples are trimmed after `SAMPLES_RETENTION_DAYS`; the login audit log after 30 days.
- **Quiet alerts.** Alert on state changes and expiry thresholds only, grouped into one message per destination per run.
- **Standalone.** Other tools (like Puls) are reached through their own small JSON API with a token, never through a shared database or a shared code library. Copy patterns, don't depend on each other.
- **No new monthly running costs.** Raise any that come up.
- **Prefer established standards** (RDAP, Keep a Changelog, SemVer, Slack incoming webhooks) over custom extras.

## Architecture

### Files

```text
vaka                     CLI entrypoint (php vaka <command>), interactive with readline
config.php               Configuration: loads .env, resolves paths (Forge zero-downtime aware)
.env.example             Environment template (checked in)
.env                     Optional environment config (gitignored)
src/bootstrap.php        require_once of every src file; defines functions only, no side effects
src/db.php               get_db(), run_migrations(), migrate_schema()
src/sites.php            normalize_url(), sites, groups, servers
src/http.php             fetch_many(): parallel requests over curl_multi
src/checks/uptime.php    Uptime + response time + keyword
src/checks/ssl.php       Peer certificate capture and expiry
src/checks/domain.php    Domain expiry via RDAP (IANA bootstrap)
src/checks/exposed.php   Exposed file paths and content signatures
src/checks/headers.php   Security header grading and regressions
src/checks/dns.php       MX, SPF, DMARC snapshots
src/runner.php           run(): lock, due checks, state transitions, events
src/silence.php          Maintenance windows (one-off, recurring) and snoozes
src/alerts.php           Reconcile, policies, grouping by destination and server, digests
src/notify.php           Slack, JSON webhook and mail() delivery
src/auth.php             Sessions, CSRF, login lockout, users.json
src/web.php              respond(), e(), security_headers()
public/index.php         Web front controller (routing only, calls into src/)
public/dashboard.html    Dashboard markup (no inline script or style)
public/app.js            Dashboard JS (event delegation, fetches the JSON API)
public/app.css           Dashboard styles (light + dark)
users.json               User credentials (bcrypt, created by the CLI, gitignored)
data/vaka.sqlite         SQLite database (auto-created, gitignored)
data/vaka.lock           Run lock (flock)
tests/                   Pest suite: Unit/, Feature/, fixtures/
scripts/seed-demo.php    Demo database with fake sites, groups and servers
scripts/smoke.mjs        Dashboard smoke test: headless Chrome over DevTools protocol, no npm deps
scripts/hooks/pre-push   Git hook: runs Pest before push
docs/                    install.md, configuration.md, checks.md, alerts.md
.claude/settings.json    Project hooks (PHP lint after edit) and read denies for .env / users.json
.claude/hooks/php-lint.sh PostToolUse hook: php -l on edited PHP files and the vaka CLI
.claude/handoffs/        Session handoffs (gitignored)
.private/                Sensitive project notes (gitignored, never referenced from tracked files)
```

Only `public/` is web-served. `src/`, `data/`, `users.json` and `.env` must stay outside the document root.

**Sensitive files:** `users.json`, `data/*.sqlite` and `.env` are gitignored and must stay that way. Group webhook URLs are secrets and live in the database. Don't print their contents or overwrite them without asking.

### Data model

```text
groups       id, name, policy, delay_minutes, slack_webhook, webhook_url, email, created_at
sites        id, group_id, url (unique), name, keyword, interval_minutes, paused, disabled_checks, ip, created_at
servers      ip, label                                           (optional label for a server)
check_state  site_id, check, status, summary, details (JSON), fail_count, checked_at, next_at, changed_at,
             alerted_status, alerted_at, snoozed_until, thresholds_sent (JSON)
events       id, site_id, check, status, previous, message, silenced_by, created_at
maintenance  id, site_id | group_id, label, starts_at, ends_at  (one-off)
             or days (weekday mask), start_time, end_time       (recurring, in TIMEZONE)
digest       id, group_id, site_id, check, message, created_at  (queued for the daily digest)
samples      site_id, checked_at, status_code, ms, ok, ip        (uptime only, trimmed after SAMPLES_RETENTION_DAYS)
audit_log    id, username, action, ip, created_at               (login lockout, trimmed after 30 days)
meta         key, value                                          (last_run_at, last_digest_at)
```

Check status is one of `pending`, `ok`, `warn`, `fail`, `unknown`. Times are stored in UTC (ISO 8601); the dashboard shows local time.

### Checks

| Check | Default interval | How | Status | Alerts |
|-------|------------------|-----|--------|--------|
| `uptime` | 5 min (per site); a failing site is rechecked every run | GET via curl_multi, follows redirects, `UPTIME_TIMEOUT`; records response time and the IP it answered on | `ok` on 2xx/3xx (and keyword found); `fail` after `DOWN_AFTER_FAILURES` consecutive failures | down, recovered |
| `ssl` | 6 h | `stream_socket_client` with `capture_peer_cert`, `openssl_x509_parse` | `warn` within 14 days, `fail` when expired or invalid | at `SSL_ALERT_DAYS` (14, 7, 1), invalid, recovered |
| `domain` | 24 h | RDAP, base URL from the IANA bootstrap file (cached in `data/`) | `unknown` when the registry has no RDAP; never parses WHOIS | at `DOMAIN_ALERT_DAYS` |
| `exposed` | 24 h | GET each known path plus a random-path baseline; match content signatures | `fail` when any path matches | new exposure, resolved |
| `headers` | 6 h | Response headers of a GET to the site URL; grade A-F | `warn` below B | header removed or weakened |
| `dns` | 6 h | `dns_get_record` for MX, TXT (SPF) and `_dmarc` TXT | `warn` when SPF or DMARC is missing | record changed |

A first result (baseline) never alerts. Expiry thresholds alert once per certificate or registration; renewal resets them.

### Alerting

**Reconcile, don't fire events.** Each run compares what was last sent for a check (`alerted_status`) with its current status. When they differ and nothing silences the check, a notice is due. When it is sent, `alerted_status` is updated. Because of this:

- A failed send is simply retried on the next run.
- A site that goes down and comes back while silenced never alerts at all.
- A site still down when its maintenance window or snooze ends alerts then.
- Change checks (headers, DNS) compare against the last snapshot that was alerted on, so a change made during a silence is still reported afterwards.

**Silences** (recorded in the event history as `silenced_by`, checks keep running):

- **Maintenance window:** one-off (from, to) or recurring (weekdays plus a time range, e.g. every night 00:00-07:00, may cross midnight), per site or per group. Incidents that start and end inside a window are never sent; the dashboard shows them in the history and as a quiet summary ("2 outages during maintenance, 14 min"). A site still down when the window ends alerts then.
- **Snooze:** acknowledge an ongoing incident for 1 h, 4 h or 24 h. There is no open-ended snooze, so nothing is forgotten: still down when it ends means a new alert. A recovery clears it. Planned long outages use a maintenance window or pause the site.

**Policies per group:**

| Policy | Behaviour |
|--------|-----------|
| `immediate` | Notify on the run that sees the change (default) |
| `delayed` | Notify only when the site has been down for `delay_minutes`; recovering earlier sends nothing |
| `digest` | No live alerts; one summary per day at `DIGEST_TIME` |
| `silent` | Dashboard only |

**Grouping:** all notices from one run are bundled per destination (a group's Slack webhook, JSON webhook or email, falling back to `.env`). Within a destination, sites that answered on the same IP are folded into one line per server: "server 203.0.113.10: 23 sites down". Recoveries are folded the same way. Sites behind a CDN share the CDN's IPs, so a server label can be set by hand.

### Run flow (`php vaka run`, every minute from cron)

1. Take `data/vaka.lock` with `flock(LOCK_EX | LOCK_NB)`. If another run holds it, exit 0 quietly.
2. Collect due `(site, check)` pairs: `next_at <= now`, or no state yet.
3. All HTTP work (uptime, headers, exposed) goes through one `curl_multi` batch with a concurrency limit.
4. Blocking work (SSL handshakes, DNS, RDAP) runs after, within a time budget so the run ends before the next minute. Whatever is left stays due.
5. Store results and new states; a change writes an event.
6. Reconcile alerts, apply silences and policies, bundle per destination and server, send. Send the daily digest when it is due.
7. Trim old samples and audit log rows (once a day), then store `last_run_at`.

Checks take their inputs as arguments (resolver functions, base URLs, clocks), so tests can feed fixtures instead of the network.

### Endpoints

| Endpoint | Auth | Purpose |
|----------|------|---------|
| `GET /?health` | No | 200 when the last run is recent, 503 when cron has stopped (so something external can watch the watcher) |
| `GET /?login` | No | Login form (server-rendered, CSRF token) |
| `POST /?login` | No | Login submission (lockout per IP) |
| `POST /?logout` | Yes | End session (CSRF) |
| `GET /` | Yes | Dashboard |
| `GET /?api=overview` | Yes | Every site with the state of each check, by group and server |
| `GET /?api=site&id=N` | Yes | One site: check details, event history, recent response times |
| `POST /?snooze` | Yes | Snooze an incident (1 h, 4 h, 24 h) or lift a snooze (CSRF, group access) |

Query-string routing like Puls, so it works on shared hosting without rewrite rules.

### Security headers (Vaka's own responses)

```text
Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: no-referrer
Permissions-Policy: camera=(), microphone=(), geolocation=()
Strict-Transport-Security: max-age=31536000 (only over HTTPS)
```

Vaka's own header check must grade Vaka itself A.

### CLI

| Command | Purpose |
|---------|---------|
| `php vaka run` | Run due checks and send alerts (cron, every minute) |
| `php vaka check [<url>]` | Run every check once against a URL and print the result (no state, no alerts) |
| `php vaka status` | Overview of every site in the terminal |
| `php vaka site:add [<url>] [--group=] [--name=] [--keyword=] [--interval=]` | Add a site |
| `php vaka site:edit [<url>]` | Change group, name, keyword, interval, paused, disabled checks |
| `php vaka site:remove [<url>]` / `site:list` | Remove a site and its history / list sites |
| `php vaka site:import [<file.csv>]` | Import sites from CSV (`group,url,enabled`) |
| `php vaka group:add [<name>]` / `group:edit` / `group:remove` / `group:list` | Groups: policy, delay, Slack webhook, JSON webhook, email |
| `php vaka server:label [<ip> <label>]` | Name a server (or set one by hand for CDN sites) |
| `php vaka maintenance:add` / `maintenance:list` / `maintenance:remove` | Maintenance windows per site or group |
| `php vaka snooze [<url>] [--for=1h\|4h\|24h]` / `unsnooze` | Snooze an incident |
| `php vaka alert:test [<group>]` | Send a test alert to a group's destinations |
| `php vaka user:add [<name>]` / `user:edit` / `user:remove` / `user:list` | Manage dashboard users |

## Development

```bash
composer install                     # Pest (dev only) + activates the pre-push hook
php vaka user:add admin
php vaka group:add customers
php vaka site:add https://shop.example --group=customers
php vaka run                         # or: php vaka check https://shop.example
php -S localhost:8080 -t public      # dashboard
./vendor/bin/pest                    # tests
node scripts/smoke.mjs               # dashboard smoke test (Node 22+, Chrome)
```

Cron in production:

```cron
* * * * * php /path/to/vaka run >> /dev/null 2>&1
```

## Testing

Pest, dev dependency only. **No real network in tests.** Fixtures use reserved example names and documentation IPs only.

- **Unit** (`tests/Unit/`): pure functions. Header grading, cert expiry from a fixture PEM, exposed-file signature matching, RDAP parsing from fixture JSON, SPF/DMARC parsing, URL normalization, state transitions, alert reconciliation, maintenance window matching (including across midnight), policies, grouping by server, alert formatting. CLI as a subprocess for deterministic output.
- **Feature** (`tests/Feature/`): start `php -S` against `tests/fixtures/sites/*` (one site with an exposed `.env`, one with good headers, one that answers 500, one soft-404 site that answers 200 for everything) and run the checks against them. RDAP from fixture JSON served the same way. DNS through an injected resolver. Alerts against a local fixture webhook that records what it received.
- **Smoke** (`scripts/smoke.mjs`): headless Chrome over the DevTools protocol, no npm dependencies. Fails on JS errors and on CSP violations. Seeds hostile site names and messages and asserts they render as text.

| Change | Test? |
|--------|-------|
| New or changed check | Always, test first |
| Alerting rule (policy, silence, grouping) | Always, test first |
| Bug fix | Always: write the test first, see it fail, then fix |
| New or changed endpoint | Always |
| CLI deterministic output (errors, no-arg fallbacks) | Yes |
| Interactive STDIN prompts | No, manual testing |
| Dashboard JS | Extend `scripts/smoke.mjs` |

## Conventions

- English UI, code, comments, docs and commits. Talk to the user in Swedish.
- 4-space indentation in PHP, 2-space in HTML/CSS/JS.
- Plain functions in `src/`, grouped by concern. No classes unless a function gets unwieldy.
- All input is validated and truncated before storage. All output is escaped.
- No em-dashes anywhere; use a regular dash or rewrite the sentence.
- README is a short landing page; depth lives in `docs/`.
- `CHANGELOG.md` follows Keep a Changelog with exactly one `## [Unreleased]` section. SemVer.

## Git & commits

- Conventional commits: `type(scope): short description` with types feat, fix, docs, test, chore, refactor, style.
- One logical change per commit. Stage files explicitly (never `git add .` or `-A`).
- **Always ask the user before committing or pushing.**
- Feature branches, never push straight to main. Tests must pass before pushing (pre-push hook).

## Roadmap

`ROADMAP.md` is the source of truth for what to build. Check items off when done; completed items stay (it is a historical document). New ideas go under Ideas.

## Session Continuity

On session start:

1. Check for uncommitted work: `git status` and `git stash list`
2. Read the latest handoff in `.claude/handoffs/`
3. Read `ROADMAP.md` and suggest the next valuable step

Write a handoff when the user says "handoff", "bye", "done for today", or similar. Handoffs go in `.claude/handoffs/` (gitignored), never `docs/handoffs/`.
