# Roadmap

Uptime comes first: knowing when a site goes down is the main job. SSL certificates come second, the other checks after. Every check is built test-first.

What makes Vaka worth building, since free tools already cover plain uptime, SSL and domain expiry:

1. One alert per server instead of one per site
2. Snooze an incident, with a new alert if it is still down when the snooze ends
3. Alert policy per group: immediate, after N minutes, digest only, silent
4. Continuous external security hygiene that alerts when something gets worse: exposed files, headers that disappear, DNS changes
5. A dashboard a busy site owner understands in five seconds

## Milestone 0: Foundation

- [x] Repository skeleton: LICENSE (MIT), CHANGELOG.md, SECURITY.md, CONTRIBUTING.md, .editorconfig
- [x] .gitignore before the first commit: `data/`, `.env`, `users.json`, `*.sqlite`, `.private/`
- [x] composer.json: platform requirements (PHP 8.3+, pdo_sqlite, curl, openssl) and Pest as the only dev dependency
- [x] `config.php` with optional `.env`, Forge zero-downtime path detection
- [x] `src/bootstrap.php` and `tests/bootstrap.php` loading code with plain `require`
- [x] SQLite in WAL mode, `PRAGMA user_version` and lazy migrations (safe when several processes open a new database at once)
- [x] `php vaka` CLI skeleton: help, version, argument parsing, readline prompt helper
- [x] Pre-push hook and GitHub Actions CI (PHP 8.3, 8.4, 8.5), Dependabot for Actions and Pest

## Milestone 1: Uptime

- [x] Groups: `group:add`, `group:edit`, `group:remove`, `group:list`; each group has an alert policy and its own Slack webhook, JSON webhook and email
- [x] Sites: `site:add`, `site:edit`, `site:remove`, `site:list` with URL normalization; a site belongs to one group (or none)
- [x] `site:import <file.csv>` (`group,url,enabled`), read from wherever the file lives
- [x] Runner: `php vaka run` with a lock file and due-check scheduling
- [ ] Time budget per run for the blocking checks (SSL, DNS, RDAP)
- [x] Parallel HTTP via `curl_multi` with a concurrency limit
- [x] Uptime check: status code, response time, optional keyword; down after 2 consecutive failures
- [x] Server grouping: the IP a site answered on (curl's primary IP) puts sites on the same server together; an optional label per server
- [x] Sites behind a CDN (`cf-ray` or `server: cloudflare` and similar): alerted on their own instead of with a server; `site:edit --server=<label>` sets the server by hand
- [ ] Dashboard shows "behind CDN" instead of a server
- [x] State per check and an event history per site
- [x] Alerts reconcile what was last sent with the current state (retries, delays and silences fall out of this)
- [x] Grouped alerts: one message per destination per run, sites on the same server folded together ("server 203.0.113.10: 23 sites down"), recoveries the same way
- [x] When a site starts failing, the other sites on its server are checked in the same run, so a server outage arrives as one alert
- [x] Flapping: a second outage within 30 min sends one "unstable" alert, then nothing until 15 min without a change
- [x] `server:list` and `server:label`
- [x] Alert policies per group: immediate, after N minutes down, digest only, silent (dashboard only)
- [x] Delivery: Slack incoming webhook, generic JSON webhook, email via `mail()`
- [x] Maintenance windows: one-off or recurring (e.g. every night 00:00-07:00, across midnight), per site or per group; checks still run and record; incidents inside a window show on the dashboard only; still down when it ends alerts
- [x] Snooze from the CLI: an ongoing incident (or a whole server) for 1 h, 4 h or 24 h; alerts again if still down when it ends
- [x] Snooze API for the dashboard (`POST /?api=snooze`)
- [ ] Snooze buttons in the dashboard
- [x] `php vaka check <url>` and `php vaka status`
- [x] `php vaka alert:test`
- [x] Auth: `user:*` commands, login with CSRF, lockout per IP in SQLite, session hardening, security headers
- [x] Access per group: `groups` per user (empty = all), enforced in every endpoint and in snooze
- [ ] Dashboard: leads with what is wrong right now and what to do about it; every site at a glance, grouped by group and server; response time and uptime history; per-site event history; maintenance and snooze state; plain language, every number explained; dark/light, accessible, mobile, and good-looking
- [ ] Empty states and onboarding: the first site added in under a minute
- [ ] Dashboard shows when Vaka last ran and warns when it is older than a few minutes (cron stopped)
- [ ] Strict CSP (`script-src 'self'`, `style-src 'self'`), event delegation in `app.js`
- [ ] Dashboard smoke test (`scripts/smoke.mjs`): fails on JS errors and CSP violations, renders hostile values as text
- [x] `/?health` that reports a stopped cron
- [x] Dead man's switch: every run pings `HEARTBEAT_URL` (e.g. Healthchecks.io's free tier), which alerts when the pings stop
- [ ] Seed script with fake sites (example.com, shop.example, 192.0.2.x) for demos, screenshots and the smoke test

## Milestone 2: SSL certificates

- [ ] SSL check: peer certificate via stream context and `openssl_x509_parse`
- [ ] Alerts at 14, 7 and 1 days before expiry, and when a certificate turns invalid
- [ ] Docs: install (Forge, shared hosting cron; never on the same server as the sites it watches), configuration, checks, alerts (honest about `mail()` needing an MTA)
- [ ] Run in production next to the previous monitor for a week, then switch over
- [ ] Read API right after go-live, named like Puls: `share:create <site|group> [--label=...] [--expires=YYYY-MM-DD]`, `share:list`, `share:revoke`; read with `GET /?api&share=TOKEN` (JSON, CSV where it helps). Read-only, scoped to one site or group, stored hashed, optional expiry. Returns status per check, incidents and response-time history
- [ ] `docs/api.md`: stable field names with a field reference, fields that hold text from the sites marked as untrusted data, and a "Use it from an AI assistant" section (token in the site project's gitignored .env, never in CLAUDE.md; a CLAUDE.md snippet with the curl call)

## Milestone 3: More checks

- [ ] Exposed files: `.env`, `.git/HEAD`, `.git/config`, `composer.json`/`.lock`, `package.json`, `phpinfo.php`/`info.php`, `.DS_Store`, backups (`backup.zip`, `site.zip`, `db.sql`, `*.sql.gz`), `wp-config.php.bak`, `server-status`; content signatures plus a soft-404 baseline
- [ ] Security headers: HSTS, CSP, X-Content-Type-Options, frame-ancestors/X-Frame-Options, Referrer-Policy, Permissions-Policy; grade A-F; alert when one disappears or weakens
- [ ] Mail DNS: MX, SPF and DMARC via `dns_get_record`; alert on change
- [ ] Domain expiry via RDAP (IANA bootstrap, cached); "unknown" where the registry has no RDAP

## Milestone 4: Open source

- [ ] Release zip with runtime files only, first tagged release (0.1.0)
- [ ] README screenshots generated from the seeded demo database
- [ ] Make the repository public

## Ideas

- [ ] Tags on sites, if one group per site proves too narrow
- [ ] Snooze straight from Slack (needs a Slack app instead of an incoming webhook)
- [ ] Connect sibling tools: each tool keeps its own small JSON API with a token; paste another tool's URL and token in Settings, e.g. to show Puls traffic next to a site's uptime. No shared database, no shared code library
- [ ] CSP report endpoint (`report-to` / `report-uri`) that collects violations from the sites
- [ ] Read-only share link per group (like Puls share tokens), e.g. for a customer
- [ ] Public status pages
- [ ] DKIM (selectors are site specific)
- [ ] SMTP delivery for email alerts
- [ ] Reminder alerts while a site stays down
- [ ] Manage sites and groups from the dashboard
- [ ] Heartbeat monitoring: cron jobs and queues ping Vaka, alert when a ping is missing
- [ ] Slow-response alerts (response time over a threshold)
- [ ] TLS details: protocol versions, chain completeness
- [ ] TOTP two-factor login
