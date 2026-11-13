# CLAUDE.md

## Project Overview

**Vaka** (working name) - keeps watch over a set of websites. Swedish "vaka över" means to keep watch over.

- **Tagline:** "Keeps watch while you sleep."
- **Subtitle:** "Uptime, response times and certificates for every site, plus domains, exposed files, security headers and mail DNS. One cron line, no dependencies."

Vaka replaces an older uptime monitor built on a full framework stack that became hard to keep up to date. Vaka must not go the same way: every decision below is made so the project still runs, unchanged, on whatever PHP 8.x is current years from now.

Uptime is the main job: knowing when a site goes down, and saying so once (not fifty times) to the right people. SSL certificates come second, the other checks after. The dashboard should look good, not just work.

Free tools (Uptime Kuma, Gatus) already cover plain uptime, SSL, domain expiry, maintenance windows, groups and Slack. Vaka must not become "Uptime Kuma in PHP". Its reasons to exist, which lead the README and the roadmap:

1. **One alert per server** instead of one per site. Others alert per endpoint; grouping exists only in paid tools or external alert routers.
2. **Snooze / acknowledge** an incident, with a new alert if it is still down when the snooze ends.
3. **Alert policy per group:** immediate, after N minutes, digest only, silent.
4. **Continuous external security hygiene** that alerts when something gets worse: exposed files, headers that disappear, DNS changes. Elsewhere this is a one-off test, a pentest scanner without alert state, or paid SaaS.
5. **UX:** a busy site owner understands it in five seconds.

Built for our own need first. Open source (MIT, like Puls), public since 0.3.0.

## Never store sensitive data in git

This repository is public. Nothing about the user, the company, its customers or their sites goes into it: no real names, domains, URLs, IPs, server names, Slack channels or webhook URLs. That covers code, tests, fixtures, docs, examples, screenshots, commit messages, the CHANGELOG, issues and PRs.

- Use reserved example names: `example.com`, `example.se`, `shop.example`, and documentation IPs (`192.0.2.x`, `198.51.100.x`, `203.0.113.x`).
- Real data lives only in gitignored paths (`data/`, `.env`, `users.json`, `*.sqlite`, `.private/`) or outside the repo. Real site lists are imported with `site:import <path>` from wherever they live; never copy them, or anything from them, into the repo.
- Demo data comes from a seed script with fake sites.
- Sensitive project notes (real groups, servers, migration steps) go in `.private/` (gitignored). Read `.private/README.md` for what is there. Never reference its contents from tracked files.
- A tool that is not public yet is not named here either: not in code, tests, docs, the roadmap, the changelog or commit messages. A reader outside gains nothing from it, and it is not this repository's to announce. What Vaka needs to know about one goes in `.private/notes.md`, and its name is on the list until it is released. A public sibling may be named where it explains a decision.
- Handoffs go in `.claude/handoffs/` (gitignored), since they name real sites.
- Before every commit, check the staged diff, the commit message and the PR title and text for real names, domains, IPs and URLs. Text written while looking at production data is where they slip in: describe the case with example names instead.
- Hooks enforce it against the gitignored `.private/real-names.txt`: `scripts/hooks/pre-commit` and `commit-msg` for commits, `pre-push` for what a push sends (branch and tag names, every new commit), `.claude/hooks/no-real-names.sh` for every text `gh` sends to GitHub. See `.claude/rules/no-real-names.md`; never bypass them.

## Principles

- **Great UI/UX is the USP** (for every Webready tool). Design for a busy site owner, not an ops expert:
  - plain language instead of jargon, and every number explained
  - lead with what is wrong right now and what to do about it, with detail one click away
  - good empty states and onboarding (the first site added in under a minute)
  - calm, good-looking, fast, mobile and accessible, in dark and light
  - the same goes for CLI output and alert messages

  Test every screen, message and command output with: would someone understand this in five seconds?

  Small conveniences are part of this, not extras: a prompt offers a good default where one exists (Enter makes a strong password; the dashboard shows this server's own cron line), and the very first run is walked through as a new user would meet it (no users, no sites, no SSH) before calling a flow done.

  The look follows the Webready open source design kit (see Design below); `docs/brand.md` holds only what is Vaka's own (the night panel, the owl's states, the tab count). Review visual changes with `node scripts/smoke.mjs --screenshots=<dir>` (login, light, dark, detail, phone, phone detail, and the first visit: no users, no sites).
- **Never on the same server it watches.** If that server goes down, nobody gets an alert. The install docs say so, and Vaka watches itself: every run pings `HEARTBEAT_URL` (a dead man's switch such as Healthchecks.io's free tier, which alerts when pings stop), `/?health` returns 503 when cron has stopped, and the dashboard shows when Vaka last ran and warns when that is more than a few minutes ago.
- **No dependencies at runtime.** PHP 8.3+ with built-in extensions only: `pdo_sqlite`, `curl`, `openssl` (`intl` optional). No Composer runtime packages, no framework, no build step, no CDN. Deploy = copy the files. Must run on Laravel Forge and on shared hosting wherever cron exists.
- **"No dependencies", not "one file".** A few files loaded with plain `require` are fine. Code lives in `src/` as plain functions, so tests load it with `require` (no regex extraction or `eval`, unlike Puls's test bootstrap).
- **SQLite in WAL mode** with a schema version (`PRAGMA user_version`) and lazy migrations, like Puls's `run_migrations()` / `migrate_schema()`.
- **Config:** `config.php` reads an optional `.env` (a copy of `.env.example`). OS environment variables win over `.env`. `VAKA_ENV_FILE` points at another file (tests use `/dev/null`).
- **One CLI entrypoint:** `php vaka <command>`. Every command falls back to interactive prompts (readline) when called without arguments, as the design kit's docs/cli.md says: a missing site, group, user, server or maintenance window is picked from a numbered list with context next to each, the only option is taken without asking, yes or no questions show `(y/N)`, and output ends with what to do next.
- **Dashboard:** plain HTML/CSS/JS served from `public/`, no CDN, dark and light mode, accessible, works on mobile, and good-looking. Every value rendered into HTML is escaped (build DOM with `textContent`; server-side HTML goes through `e()`).
- **Strict CSP from day one:** `script-src 'self'` without `'unsafe-inline'`, and the same for `style-src`. No inline `<script>`, no `onclick`/`onkeydown` attributes, no `style="..."` in markup. Event delegation: `data-action` attributes plus one listener in `public/app.js`.
- **Auth like Puls:** `users.json` with bcrypt, managed via the CLI; access per group from the start (`groups` per user, empty = all; a restricted user only sees and snoozes sites in their groups, enforced in every endpoint); session cookies (`httponly`, `SameSite=Strict`, `secure` on HTTPS); CSRF tokens on every POST; login lockout counted per IP in SQLite (not in the session); security headers on every response.
- **Store as little as possible.** No personal data. Uptime samples are trimmed after `SAMPLES_RETENTION_DAYS`; the login audit log after 30 days.
- **Quiet alerts.** Alert on state changes and expiry thresholds only (plus a reminder every `REMIND_EVERY_HOURS` while a site stays down), grouped into one message per destination per run.
- **Standalone.** Other tools (like Puls) are reached through their own small JSON API with a token, never through a shared database or a shared code library. Copy patterns, don't depend on each other.
- **A read API from v1 (every Webready tool).** A read-only token per site or groups (one token per reader) lets the AI assistant in a site's own project read Vaka's data and act on it, and the same API serves links between the tools. Same names as Puls: `share:create`, `share:list`, `share:revoke`, `GET /?api&share=TOKEN`. Stable field names documented in `docs/api.md`; text that came from the sites is marked as untrusted data there. Planned right after go-live (see ROADMAP).
- **No new monthly running costs.** Raise any that come up.
- **Prefer established standards** (RDAP, Keep a Changelog, SemVer, Slack incoming webhooks) over custom extras.

## Design

This tool follows the Webready open source design kit
(private for now, cloned next to this repo).
Read its README.md first, then the doc for what you are changing. Copy values and
files from it; never link to it at runtime or install it. When the kit and this
tool disagree, the kit wins, unless this tool's docs say why it differs.

Vaka copied kit 0.6.0 (the version line is in the tokens at the top of `public/app.css`). The mark, the alarm mark and the marks of the released, public family tools (today Puls and Vaka itself; the footer shows no others, per the kit's docs/layout.md) are copied into `public/favicon*.svg` and `public/marks/`; the night panel's owl is generated into `OWL` in `public/app.js` from the kit's `characters/vaka.svg`, `vaka-awake.svg` and `vaka-perched.svg`. When the kit changes, copy again and update the version line.

## Architecture

### Files

```text
vaka                     CLI entrypoint (php vaka <command>), interactive with readline
config.php               Configuration: loads .env, resolves paths (Forge zero-downtime aware)
.env.example             Environment template (checked in)
.env                     Optional environment config (gitignored)
src/bootstrap.php        VAKA_VERSION + require_once of every src file; defines functions only, no side effects
src/config.php           parse_env(), load_env(), resolve_path(), int_list(); timezone_setting() and time_format_setting() (TIMEZONE and TIME_FORMAT as in Puls: unset is the server's zone, a wrong value never stops a run and is shown), clock_text()
src/db.php               get_db(), enable_wal(), migrations(), run_migrations()
src/cli.php              parse_args(), allow_options(), prompt(), ask(), confirm(), choose(), print_table(), fail()
src/sites.php            CHECKS, POLICIES, normalize_url(), groups and sites (validation, storage, plain-words descriptions); host_resolves() and unresolved_note() (a new address not in DNS waits for "Add anyway": a typo is caught before it alerts)
src/import.php           parse_import_csv(), import_sites() (all or nothing, dry run)
src/commands/groups.php  group:add, group:edit, group:remove, group:list
src/commands/sites.php   site:add, site:edit, site:remove, site:list, site:import
src/commands/run.php     run, check, status
src/commands/alerts.php  alert:test, server:list, server:label
src/commands/silence.php maintenance:add, maintenance:list, maintenance:remove, snooze, unsnooze; group_or_site() and pick_group_or_site()
src/commands/share.php   share:create, share:list (what each token reaches now), share:edit, share:revoke
src/http.php             fetch_many(): parallel GETs over curl_multi (redirects, body cap, final headers), user_agent()
src/checks/uptime.php    evaluate_uptime() (with `problem` kind), next_uptime_state(), detect_cdn(), bot_check() (a bot check is not the site: uptime keeps its status, headers and exposed files say unknown), advice_for(), plain-words errors and durations
src/checks/ssl.php       fetch_certificate() (verified, then unverified to describe a bad one), certificate_info(), host_matches(), evaluate_ssl(), ssl_notice() (thresholds once per certificate)
src/checks/domain.php    registrable_candidates(), RDAP (IANA bootstrap, cached in data/rdap-dns.json) and WHOIS for .se/.nu (expires: line only), evaluate_domain(), domain_notice()
src/checks/exposed.php   EXPOSED_FILES, exposed_matches() (content signatures), evaluate_exposed() (soft-404 baseline), check_exposed(), exposed_notice()
src/checks/headers.php   SECURITY_HEADERS, header_level() (good/weak/missing), headers_grade(), evaluate_headers(), confirm_headers() (a change counts after a second look), headers_notice() (regressions only); web_server() (nginx or Apache from the Server header, for the lines the dashboard offers) and headers_advice() (items, server, the nginx and Apache tips); csp_source_allows(), csp_blocked() and csp_item() (the page's scripts against the site's CSP: what it blocks, confirmed and alerted like a change, or what a Report-Only one would); csp_unsent() and csp_with_connect() (a script the policy lets run but not send back to its host: a guess, said with the policy and connect-src added, never a block); page_files(), files_nosniff(), own_host(), fetch_files_nosniff() (nosniff on the first stylesheet and script on the page's host or a host under it; `nosniff_files` is its own level, so a first look is a baseline); sent_twice(), twice_origin(), twice_note() and twice_tip() (a header with two different values: who sends which, from what the files on the page's own host send; frame protection stays in place, since browsers then allow no frames)
src/checks/dns.php       lookup_mail_dns() (MX, SPF, DMARC in one spelling; resolver injected), mail_domain(), evaluate_mail_dns(), dns_items(), confirm_mail_dns() (a change counts after a second look), dns_notice() (any confirmed change)
src/runner.php           run_locked(), run(): due checks, state, events, samples, cleanup, heartbeat; queue_recheck() ("Check again")
src/silence.php          Maintenance windows (parse, window_active() and window_periods() in local time, describe, site_windows()), active_silence(), snooze_site()
src/manage.php           Changing sites, groups, maintenance windows and servers from the dashboard: site_form_input() and group_form_input() (an error per field, the CLI's rules; webhooks kept unless replaced or cleared), save_site_form(), save_group_form(), describe_*_changes() (history in words), remove_*_form() (kept in the audit log); CHECK_LABELS
src/share.php            Read tokens (vaka_ and 64 hex) for a site or one or more groups: create_share_token() (only a SHA-256 and the start are kept), update_share_token() (the token stays the same), find_share_token(), share_token_expired_on() (an expired token says so), share_scope(), share_expiry(), share_default_day() (90 days); group_readers() and readers_change() (who a move to another group shows a site to: users limited to groups and group tokens follow their sites)
src/alerts.php           server_key(), alert_decision(), notice_for() (flapping), collect_notices(), bundle_notices(), alert_text(), digests
src/notify.php           deliver(): Slack (escaped mrkdwn), JSON webhook, mail(); payload builders
src/auth.php             users.json, client_ip(), login lockout, sessions, CSRF
src/report.php           The read API as Markdown for an AI assistant (&format=md): read_api_markdown_site(), read_api_markdown_list(); md_attention() and md_improvements() (what to do, worst first), md_code() (text from a site in a one-line code span)
src/stats.php            down_periods(), overlap_seconds(), planned_periods() (downtime inside a maintenance window is planned: left out of uptime), uptime_percent(), site_uptime(), daily_uptime() (per day: up, down_seconds, outages, planned_seconds), hourly_buckets()
src/web.php              handle_request() routing, CSP and security headers, login page, JSON API (overview, site, snooze; `advice` from advice_for()), read_api() with stable field names (api_site_fields(), CSV)
src/commands/users.php   user:add, user:edit, user:remove, user:list
public/index.php         Web front controller (three lines: bootstrap, config, handle_request())
public/dashboard.html    Dashboard markup (no inline script or style; {{csrf}}, {{version}}, {{user}} filled in by show_dashboard())
public/app.js            Dashboard JS: hero, "Needs your attention" (outages first, one card per server), sites by group with 24 h sparklines, filter, detail dialog (#site-N: day bars that tell their day, a tile per check, the settings card), snooze, theme, the site form (add, change, remove) in the drawer; one click listener (data-action) and one submit listener (data-form), DOM built with textContent
public/app.css           Styles for the login and the dashboard (light + dark)
public/theme.js          Applies a saved light/dark choice before paint (loaded in <head>, no inline script needed)
public/favicon.svg       The kit's owl mark on deep teal (favicon-alert.svg: on red, eyes wide, shown while a site is down)
public/icon-*.png        The home screen icon (180 for iOS, 192 and 512 for the manifest), made from scripts/app-icon.svg (docs/brand.md)
public/marks/            Vaka's mark (top bar, login) and the released Webready tools' marks (footer credit) from the kit, inline
public/fonts/            Figtree (SIL OFL, variable, Latin) and its licence: self-hosted, no CDN
docs/brand.md            What is Vaka's own on top of the design kit: the owl's files and states, the night panel, motion
users.json               User credentials (bcrypt, created by the CLI, gitignored)
data/vaka.sqlite         SQLite database (auto-created, gitignored)
data/vaka.lock           Run lock (flock)
data/rdap-dns.json       IANA's RDAP bootstrap file, refreshed weekly
composer.json            Platform requirements (PHP 8.3+, pdo_sqlite, curl, openssl) + Pest (dev only)
phpunit.xml              Test configuration (Pest)
tests/                   Pest suite: Unit/, Feature/, fixtures/
tests/Support/helpers.php temp_dir(), load_config(), cli_env() and vaka() (CLI as a subprocess, never reads the local .env); start_site()/start_web() refuse a port another process holds (assert_port_free())
tests/fixtures/import/   CSV files for site:import (example domains only)
tests/fixtures/sites/    Fixture sites served by `php -S` in feature tests (start_site()/stop_site() in helpers); hooks/ records alert POSTs
tests/fixtures/certs/    Test-only CA and certificates (valid, wrong name, self-signed), made by generate.php; keys are not secrets
tests/fixtures/tls-server.php  Minimal TLS server for the SSL check tests
tests/fixtures/rdap/     RDAP bootstrap file and domain answers (served by sites/rdap), .se WHOIS answers; reserved names only
tests/fixtures/whois-server.php  Minimal WHOIS server for the domain check tests
.github/                 CI (PHP 8.3-8.5), the release workflow (a v* tag: tests, scripts/build-release.sh, a check that the zip runs, a GitHub release with the zip and the CHANGELOG section) and Dependabot (Actions + Pest only)
scripts/seed-demo.php    Demo database with fake sites, groups, servers, history and samples (`--hostile` adds markup-laden names for the smoke test)
scripts/smoke.mjs        Dashboard smoke test: headless Chrome over DevTools protocol, no npm deps; `--screenshots=<dir>` saves login, light, dark, detail, phone, and the first visit (no users, no sites)
scripts/build-release.sh Release zip with the runtime files only, from git (never the working tree); refuses when VAKA_VERSION or the CHANGELOG section does not match
scripts/readme-media.mjs README screenshots and the social preview in docs/screenshots/, from the seeded demo (no hostile names), each cut to a dashboard element
scripts/hooks/pre-push   Git hook: stops real names in pushed branch and tag names and new commits, then runs Pest
scripts/hooks/pre-commit, commit-msg, real-names  Git hooks: stop real names (from .private/real-names.txt) in added lines and commit messages
docs/                    install.md, configuration.md, checks.md, alerts.md (alerts.md: when, where, JSON fields), api.md (the read API's fields; change them only with a changelog note)
docs/screenshots/        README images, made by scripts/readme-media.mjs (run it again after a visual change)
.claude/settings.json    Project hooks (PHP lint after edit) and read denies for .env / users.json
.claude/hooks/php-lint.sh PostToolUse hook: php -l on edited PHP files and the vaka CLI
.claude/hooks/no-real-names.sh PreToolUse hook: stops gh texts with real names (PRs, issues, merges, releases, repo, gh api)
.claude/rules/           Project rules for Claude Code (no-real-names.md)
.claude/handoffs/        Session handoffs (gitignored)
.private/                Sensitive project notes (gitignored, never referenced from tracked files)
```

Only `public/` is web-served. `src/`, `data/`, `users.json` and `.env` must stay outside the document root.

**Sensitive files:** `users.json`, `data/*.sqlite` and `.env` are gitignored and must stay that way. Group webhook URLs are secrets and live in the database. Don't print their contents or overwrite them without asking.

### Data model

```text
groups       id, name, policy, delay_minutes, slack_webhook, webhook_url, email, created_at
sites        id, group_id, url (unique), name, keyword, interval_minutes, paused, disabled_checks,
             ip, cdn, server, created_at                        (ip and cdn from the last check; server set by hand)
servers      ip, label                                           (optional label for a server)
check_state  site_id, check_name, status, summary, details (JSON), fail_count, checked_at, next_at, changed_at,
             alerted_status, alerted_at, flapping_since, snoozed_until, thresholds_sent (JSON),
             alerted_snapshot (JSON: what a change check last told, e.g. the public files)
events       id, site_id, check_name, status, previous, message, silenced_by, created_at
maintenance  id, site_id | group_id, label, starts_at, ends_at  (one-off, UTC)
             or days ("1,2,3" ISO weekdays), start_time, end_time (recurring, HH:MM in TIMEZONE)
samples      site_id, checked_at, status_code, ms, ok, ip        (uptime only, trimmed after SAMPLES_RETENTION_DAYS)
             (`check` is an SQL keyword, hence check_name)
audit_log    id, username, action, ip, created_at               (login lockout, trimmed after 30 days)
meta         key, value                                          (last_run_at, last_cleanup_on, last_digest_on, last_digest_at)
share_tokens id, token_hash, prefix, site_id, label, expires_at, last_used_at, created_at
             (read tokens: SHA-256 of the token and its start, vaka_ and 8 characters, only; 90 days unless another last day or never is chosen;
             a site, or one or more groups in share_groups; go with their site, or their last group)
share_groups token_id, group_id                                  (the groups a token reads)
```

Check status is one of `pending`, `ok`, `warn`, `fail`, `unknown`. Times are stored in UTC (ISO 8601); the dashboard shows local time.

### Checks

| Check | Default interval | How | Status | Alerts |
|-------|------------------|-----|--------|--------|
| `uptime` | 5 min (per site), in a minute of its own (`next_uptime_slot()`: site id modulo the interval), so the checks are spread; a failing site is rechecked every run | GET via curl_multi, follows redirects, `UPTIME_TIMEOUT`; records response time and the IP it answered on | `ok` on 2xx/3xx (and keyword found); `fail` after `DOWN_AFTER_FAILURES` consecutive failures | down, still down (every `REMIND_EVERY_HOURS`, 4), recovered |
| `ssl` | 6 h | `stream_socket_client` with `capture_peer_cert`, `openssl_x509_parse`; behind Cloudflare the certificate is Cloudflare's (`renewed_by`, `ssl_advice()`) | `warn` within 14 days, `fail` when expired or invalid | at `SSL_ALERT_DAYS` (14, 7, 1), invalid, recovered |
| `domain` | 24 h (1 h after a failed lookup) | RDAP, base URL from the IANA bootstrap file (cached in `data/` for a week); WHOIS only for the TLDs in `WHOIS_SERVERS` (.se and .nu have no RDAP), reading only the `expires:` line | `warn` within `DOMAIN_ALERT_DAYS`, `fail` when expired, `unknown` when nothing readable (no other WHOIS parsing) | at `DOMAIN_ALERT_DAYS`, expired, renewed; one alert per domain for sites that share it |
| `exposed` | 24 h (1 h while something is public) | GET each known path next to the site plus a made-up path as baseline, 64 KB each; match content signatures | `fail` for a file with secrets, code or data; `warn` for one that only tells versions and paths | each new file, all private again |
| `headers` | 6 h (a change is confirmed 15 min later) | Response headers of a GET to the site URL; six protections, each good, weak or missing; grade A-F. Plus nosniff on the first stylesheet and script the page loads from its host or a host under it, such as static. (200, not HTML): missing there makes X-Content-Type-Options weak | `ok` A or B, `warn` below; a low grade is shown, never in "Needs your attention" | a protection removed or weakened, or nosniff gone from the scripts and styles (confirmed), never a low grade by itself |
| `dns` | 6 h (a change is confirmed 15 min later; 1 h after a failed lookup) | `dns_get_record` for MX, TXT (SPF) and `_dmarc` TXT of the domain the domain check found; one lookup per domain per run | `ok` with SPF and DMARC, `warn` when one is missing or SPF is there twice; no MX and DMARC `p=none` are shown, not warned | any record changed (confirmed), one alert per domain; never missing records by themselves |

A first result (baseline) never alerts. Expiry thresholds alert once per certificate or registration; renewal resets them.

### Alerting

**Reconcile, don't fire events.** Each run compares what was last sent for a check (`alerted_status`) with its current status. When they differ and nothing silences the check, a notice is due. When it is sent, `alerted_status` is updated. Because of this:

- A failed send is simply retried on the next run.
- A site that goes down and comes back while silenced never alerts at all.
- A site still down when its maintenance window or snooze ends alerts then.
- Change checks (headers, DNS) compare against the last snapshot that was alerted on, so a change made during a silence is still reported afterwards.

**Silences** (recorded in the event history as `silenced_by`, checks keep running):

- **Maintenance window:** one-off (from, to) or recurring (weekdays plus a time range, e.g. every night 00:00-07:00, may cross midnight), per site or per group. Incidents that start and end inside a window are never sent; the dashboard shows them in the history and as a quiet summary ("2 outages during maintenance, 14 min"). A site still down when the window ends alerts then.
- **Snooze:** acknowledge an ongoing incident for 1 h, 4 h or 24 h. There is no open-ended snooze, so nothing is forgotten: still down when it ends means a new alert. A recovery clears it. Planned long outages use a maintenance window or pause the site. Snoozing sets `alerted_status = 'snoozed'` (when the site is down), so the end of the snooze differs from what was told and sends "still down after the snooze" (`kind = still_down`).
- **Reminder:** a site still down `REMIND_EVERY_HOURS` (4) after it was last told gets "has been down for 4 h" (`kind = reminder`, `reminder_due()`), and `alerted_at` moves on, so the next comes 4 h later. Silences hold it back like any notice; unstable sites and the digest and silent policies get none. A snooze is how to say "I'm on it, stop reminding me".
- While silenced, `collect_notices()` skips the check entirely; events written then carry `silenced_by`, and `recent_outages()` ignores them, so planned work never makes a site look unstable. Downtime inside a window does not count against uptime either (`planned_periods()`, from the windows as they are now); the 30 day bars show a day down only in maintenance in the quiet colour.

**Policies per group:**

| Policy | Behaviour |
|--------|-----------|
| `immediate` | Notify on the run that sees the change (default) |
| `delayed` | Notify only when the site has been down for `delay_minutes`; recovering earlier sends nothing |
| `digest` | No live alerts; one summary per day at `DIGEST_TIME` |
| `silent` | Dashboard only |

**Grouping:** all notices from one run are bundled per destination (a group's Slack webhook, JSON webhook or email, falling back to `.env`). Within a destination, sites on the same server that changed the same way are folded into one item: "Server 203.0.113.10 is not answering: all 23 sites are down", or "3 of 10 sites on server ... are down". Recoveries are folded the same way. When a site fails for the first time in a row, in a way that could be its server's (`server_failure()`: anything but a domain that does not resolve or a missing keyword), `server_neighbours()` checks the other sites on its server in the same run, so a whole server is confirmed down in the same runs instead of trickling in over the sites' intervals.

**Flapping:** a second outage within `FLAP_WINDOW_MINUTES` (30) sends one "unstable" notice instead of "down" and sets `flapping_since`. Nothing more is sent until the check has gone `STABLE_MINUTES` (15) without a change; then one "stable again" (or "down") closes it. At most three messages for a flapping site.

**Daily digest:** `send_digests()` runs once a day after `DIGEST_TIME` in `TIMEZONE` and summarises the `events` since the last digest plus the sites still down, per digest group. No queue table: the events are the record.

**Delivery:** a notice counts as told when at least one of its destinations accepted the message; otherwise it is retried on the next run. Slack text is escaped (`&`, `<`, `>`), email headers never carry line breaks, and the JSON webhook fields are documented in `docs/alerts.md`.

**Sites behind a CDN** answer on the CDN's IPs, which are shared by sites on different servers, so grouping by IP would be wrong. The uptime check detects a CDN from response headers (`cf-ray` or `server: cloudflare`, and the equivalents of other CDNs), and such a site shows "behind CDN" instead of a server and is alerted on its own. `site:edit --server=<label>` sets a site's server by hand and wins over both. Keep it simple: no IP range lists.

### Run flow (`php vaka run`, every minute from cron)

0. From cron (no terminal), wait until second `RUN_AT_SECOND` (15) of the minute: servers start their own scheduled jobs at :00 and answer several times slower for a few seconds (measured on the first real deploy: 2.5 s at :02, 0.4 s at :15).
1. Take `data/vaka.lock` with `flock(LOCK_EX | LOCK_NB)`. If another run holds it, exit 0 quietly.
2. Collect due `(site, check)` pairs: `next_at <= now`, or no state yet.
3. Uptime requests go through one `curl_multi` batch with a concurrency limit.
4. The slower work runs after, until `RUN_BUDGET_SECONDS` (40) since the run started: SSL handshakes one by one, then domain lookups one by one, then mail DNS one domain at a time, then security headers in parallel batches of 20 sites, then exposed files in batches of five. Whatever is left stays due for the next run. SSL skips sites that do not answer at all (uptime down by timeout, DNS, refused or connect), but not sites down because of their certificate; headers and exposed files skip every site that is down.
5. Store results and new states; a change writes an event.
6. Reconcile alerts, apply silences and policies, bundle per destination and server, send. Send the daily digest when it is due.
7. Trim old samples, audit log rows and unused session files (once a day), then store `last_run_at`.
8. Ping `HEARTBEAT_URL` if set (short timeout; a failed ping never fails the run).

Checks take their inputs as arguments (resolver functions, base URLs, clocks), so tests can feed fixtures instead of the network.

### Endpoints

| Endpoint | Auth | Purpose |
|----------|------|---------|
| `GET /?manifest` | No | The web app manifest (name, icons, standalone), for adding Vaka to a phone's home screen; browsers fetch it without cookies |
| `GET /?health` | No | `{status: ok\|stale\|never_run, last_run_at, seconds_since_last_run}`; 200 only when the last run is under 5 min old, so something external can watch the watcher |
| `GET /?login` | No | Login form (server-rendered, CSRF token in the session); before the first user exists, how to create one instead |
| `POST /?login` | No | Login (CSRF, lockout per IP, constant-time check for unknown users) |
| `POST /?logout` | Yes | End the session (CSRF in `_token`); GET answers 405 |
| `GET /` | Yes | Dashboard (redirects to `/?login` without a session) |
| `GET /?api=overview` | Yes | Totals (and, for users who see everything, empty groups too), last run, the last outage, `setup` (this server's PHP command, the directory to run commands in, the cron line, whether alerts can go anywhere, who reads each group; only for users who see everything), and every visible site by group: status, summary, advice, since, response time, server, snooze, maintenance, unstable, 24 h and 30 d uptime, 30 daily bars, hourly buckets, and the certificate, domain, exposed files and headers grade |
| `GET /?api=site&id=N` | Yes | One site: the overview's fields plus each security header (what it does, how to add it), uptime 24 h / 7 d / 30 d, samples of the last 24 h, last 100 uptime events, maintenance windows. 404 when not visible |
| `POST /?api=site-save` | Yes, a user who sees every site | Add (`{url, group, name, keyword, interval, paused, checks}`) or change (`id` too) a site; needs `X-CSRF-Token`; 422 `{error, errors: {field: message}}` and nothing saved when a field is wrong; a change goes in the site's history (`settings` event, who and what) |
| `POST /?api=site-remove` | Yes, a user who sees every site | `{id}`: remove a site and its history; needs `X-CSRF-Token`; kept in the audit log |
| `GET /?api=group&id=N` | Yes, a user who sees every site | A group for its form: name, policy, delay, the webhooks masked (`mask_secret()`, never in full), email, number of sites |
| `POST /?api=group-save` / `group-remove` | Yes, a user who sees every site | Add or change a group (`{id?, name, policy, delay, slack, webhook, email}`; an empty webhook keeps the current one, `slack_clear`/`webhook_clear` remove it) or remove one (its sites stay, without a group; 409 while users are limited to it, see `assert_group_unused()`); needs `X-CSRF-Token`; 422 with an error per field; a change goes in the history of every site in the group |
| `POST /?api=alert-test` | Yes, a user who sees every site | `{group}`: a test alert to the group's saved destinations (`send_test_alert()`, shared with `alert:test`); answers where it went and whether that worked |
| `GET /?api=server&site=N` | Yes, a user who sees every site | The server a site answers on (by IP; not behind a CDN or set by hand): its IP, name, sites and the groups they are in |
| `POST /?api=server-save` | Yes, a user who sees every site | `{ip, label, group?}`: name a server (empty removes the name) and, with `group`, move every site on it to a group (`''` for none); in the history of its sites |
| `POST /?api=maintenance-add` / `maintenance-remove` | Yes, a user who sees every site | Add a window for `site_id` or `group_id` (`{kind: daily\|days\|once, start, end, days, from, to, label}`, `maintenance_form_fields()`), or remove one by `id`; in the history of the sites it covers; 422 `{errors: {when\|label}}` |
| `POST /?api=snooze` | Yes | `{site_id, for: "1h"\|"4h"\|"24h"}` or `{site_id, action: "unsnooze"}`; needs `X-CSRF-Token`; 404 when not visible |
| `POST /?api=site-recheck` | Yes | `{site_id}`: "Check again", every check of the site due on the next run (`queue_recheck()`); needs `X-CSRF-Token`; 404 when not visible, 409 while paused. Never through a read token |
| `GET /?api` | Read token or session | The read API (docs/api.md): every site in the token's group (or the user's), in stable field names; `&site=`, or a token for one site without it, one site with 30 days, samples (`&hours=`, up to 720) and the last 100 events of every check; `&format=csv`, or `&format=md` for an AI assistant (src/report.php). Token as `Authorization: Bearer` or `&share=`, needs no session and sets no cookie; an unknown or revoked token is a 404, an expired one a 401 that says when it expired |

Any other path answers 404. Restricted users (`groups` in users.json) only ever see their groups' sites, and a site they may not see is a 404, so its existence does not leak.

Query-string routing like Puls, so it works on shared hosting without rewrite rules.

### Security headers (Vaka's own responses)

```text
Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; manifest-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'
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
| `php vaka site:edit [<url>]` | Change group, name, keyword, interval, paused, disabled checks, server (`--server=<label>`); `site:edit <server> --group=` moves every site on a server (IP or name, the same rule as `snooze`); `site:edit --group=` without a site moves several picked from a list (ranges, 0 for all), offering to add a missing group |
| `php vaka site:remove [<url>]` / `site:list` | Remove a site and its history / list sites |
| `php vaka site:import [<file.csv>]` | Import sites from CSV (`group,url,enabled`) |
| `php vaka group:add [<name>]` / `group:edit` / `group:remove` / `group:list` | Groups: policy, delay, Slack webhook, JSON webhook, email |
| `php vaka server:label [<ip> <label>]` | Name a server's IP, so alerts say "web-1 (203.0.113.10)" (empty label removes it) |
| `php vaka maintenance:add [<group\|site>] --at=HH:MM-HH:MM [--days=] \| --from= --to= [--label=]` | Maintenance window per group or site (a group name wins) |
| `php vaka maintenance:list` / `maintenance:remove [<#>]` | List or remove maintenance windows |
| `php vaka snooze [<site\|server>] [--for=1h\|4h\|24h]` / `unsnooze` | Snooze an incident, or every failing site on a server (IP or name) |
| `php vaka alert:test [<group>]` | Send a test alert to a group's destinations (no group: the defaults in .env) |
| `php vaka server:list` | Servers (by IP or hand-set name) and their sites; CDN sites counted apart |
| `php vaka user:add [<name>]` / `user:edit` / `user:remove` / `user:list` | Manage dashboard users |
| `php vaka share:create [<group\|site>] [--groups=a,b] [--label=] [--expires=YYYY-MM-DD\|never]` / `share:list` / `share:edit [<#>]` / `share:revoke [<#>]` | Read tokens for the read API (`vaka_` and 64 hex), one per reader: a site, or one or more groups; 90 days unless `--expires` gives another last day or `never`, shown once, stored hashed; `share:edit` changes groups, last day or label and keeps the token |

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

Releasing: a PR sets `VAKA_VERSION` in `src/bootstrap.php` and renames `[Unreleased]` in `CHANGELOG.md` to `[x.y.z] - YYYY-MM-DD` (with a new empty `[Unreleased]`). Once it is merged, tag `main` as `vx.y.z` and push the tag: the release workflow builds the zip, checks that it runs and publishes the release. `scripts/build-release.sh x.y.z` builds the same zip locally, in `build/`.

## Testing

Pest, dev dependency only. **No real network in tests.** Fixtures use reserved example names and documentation IPs only.

- **Unit** (`tests/Unit/`): pure functions. Header grading, cert expiry from a fixture PEM, exposed-file signature matching, RDAP parsing from fixture JSON, SPF/DMARC parsing, URL normalization, state transitions, alert reconciliation, maintenance window matching (including across midnight), policies, grouping by server, alert formatting. CLI as a subprocess for deterministic output.
- **Feature** (`tests/Feature/`): start `php -S` against `tests/fixtures/sites/*` (`exposed` leaks a `.env` and `.git/`, `headers` sends five of six protections, `error` answers 500, `good` is a soft-404 site that answers 200 for everything, `rdap` is a registry) and run the checks against them, with `rdap_bootstrap_url` and `whois_servers` pointing at the fixtures. A fixed port per server; a port another process holds fails loudly. DNS through an injected resolver. Alerts against a local fixture webhook that records what it received.
- **Smoke** (`scripts/smoke.mjs`): headless Chrome over the DevTools protocol, no npm dependencies. Seeds a demo database with hostile names and messages, logs in through the form and walks the dashboard: hero, incidents, groups, filter, detail, snooze, theme, phone width. Fails on JS errors, on CSP violations (reported by a CDP-injected `securitypolicyviolation` listener and the console) and when markup from the data renders as markup. Runs as its own CI job.

Look at the dashboard while working on it: `php scripts/seed-demo.php /tmp/demo.sqlite`, then `DB_PATH=/tmp/demo.sqlite php -S localhost:8080 -t public` with a user from `php vaka user:add`, or `node scripts/smoke.mjs --screenshots=<dir>` for the standard views.

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
- Commits, feature branches, pushing them and opening PRs are fine without asking, so there is always something to show. **Until 1.0, merge a PR to main yourself once CI is green and what it changes has been agreed** (UI text and UX choices are shown before the PR, as above), so finished work goes live without waiting for a reply. After the merge, wait for a production run on the new code (`/?health` with a newer `last_run_at`) before calling it done. At 1.0 the rule is decided again. (Between 0.1.0 and 2026-11-09 every merge was asked for first.)
- Feature branches, never push straight to main. Tests must pass before pushing (pre-push hook).
- Our own production deploys main by itself on every push, so a merge to main is live within minutes: merge only what may go live, and never change production by hand.
- Before every commit, scan the staged diff, the commit message and the PR text for real names, domains, IPs and URLs.

## Roadmap

`ROADMAP.md` is the source of truth for what to build. Check items off when done; completed items stay (it is a historical document). New ideas go under Ideas.

## Session Continuity

On session start:

1. Check for uncommitted work: `git status` and `git stash list`
2. Read the latest handoff in `.claude/handoffs/`
3. Read `ROADMAP.md` and suggest the next valuable step

Write a handoff when the user says "handoff", "bye", "done for today", or similar. Handoffs go in `.claude/handoffs/` (gitignored), never `docs/handoffs/`.
