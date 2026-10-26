# Configuration

Vaka works without any configuration. To change something, copy `.env.example` to `.env` and set what you need; settings from the server's environment (Forge, Docker, CI) win over `.env`.

## Settings

| Setting | Default | What it does |
|---------|---------|--------------|
| `APP_URL` | (empty) | Vaka's own address, for links in alerts and the User-Agent |
| `TIMEZONE` | `Europe/Stockholm` | Local time for the CLI, alerts, maintenance windows and the daily summary. Stored times are UTC |
| `DB_PATH` | `data/vaka.sqlite` | The database. A relative path is relative to Vaka's folder (on Forge zero-downtime deploys, to the site's folder) |
| `USERS_FILE` | `users.json` | Dashboard users and their password hashes |
| `SESSION_LIFETIME` | `2592000` | Seconds a login lasts (30 days) |
| `MAX_LOGIN_ATTEMPTS` | `5` | Failed logins from one IP address before it is locked out |
| `LOCKOUT_MINUTES` | `15` | How long a lockout lasts, and the window failures are counted in |
| `TRUSTED_PROXIES` | (empty) | Reverse proxies (IPs or CIDR ranges) whose `X-Forwarded-For` is trusted. Leave empty when visitors reach Vaka directly |
| `UPTIME_INTERVAL` | `5` | Minutes between checks of a site (a site can have its own). A failing site is checked every minute regardless |
| `UPTIME_TIMEOUT` | `10` | Seconds before a request counts as failed |
| `DOWN_AFTER_FAILURES` | `2` | Failed checks in a row before a site counts as down |
| `RUN_AT_SECOND` | `15` | The second of the minute a cron run starts checking (0-45). Servers start their own scheduled jobs at :00 and answer several times slower for a few seconds; checking then would not show what visitors get. A run started by hand does not wait |
| `SSL_ALERT_DAYS` | `14,7,1` | Days before a certificate expires to alert, each once per certificate |
| `DOMAIN_ALERT_DAYS` | `30,14,7,1` | The same for domain registrations, each once per registration period. If your registrar renews on the expiry day, every renewal passes these: use fewer days, such as `7,1` |
| `RDAP_BOOTSTRAP_URL` | IANA's `dns.json` | Where Vaka learns which registry to ask about a domain (cached in `data/` for a week) |
| `WHOIS_SERVERS` | `se=whois.iis.se,nu=whois.iis.nu` | Registries without RDAP that are asked over WHOIS instead, as `tld=server`. Only an `expires:` line is read, so add a TLD only if its WHOIS answers in that form |
| `SAMPLES_RETENTION_DAYS` | `30` | Days of response times to keep |
| `SSL_CA_FILE` | (empty) | A CA bundle to verify certificates against instead of the system's, for an internal authority |
| `USER_AGENT` | `Vaka/<version> (+<APP_URL>)` | The User-Agent of every check |
| `HEARTBEAT_URL` | (empty) | Pinged at the end of every run, for a dead man's switch such as Healthchecks.io |
| `REMIND_EVERY_HOURS` | `4` | Hours between reminders while a site stays down ("shop.example has been down for 4 h"). `0` sends none |
| `DIGEST_TIME` | `08:00` | When groups with the digest policy get their daily summary (local time) |
| `SLACK_WEBHOOK_URL` | (empty) | Default Slack incoming webhook, for groups without their own |
| `WEBHOOK_URL` | (empty) | Default JSON webhook |
| `ALERT_EMAIL` | (empty) | Default email recipients, comma-separated |
| `ALERT_FROM` | (empty) | The sender address of email alerts |
| `FOOTER_CREDIT` | `true` | "Made by Webready in Sweden" and the marks of the released Webready tools in the footer and on the login page; `false` leaves only Vaka's own half (the version and how fresh the results are) |

Webhook URLs are secrets: keep `.env` out of git (it is in `.gitignore`).

## Command line

Every command asks for what it needs when you leave out its arguments. `php vaka help` lists them all.

### Watching

| Command | What it does |
|---------|--------------|
| `php vaka run` | Run the checks that are due and send alerts (from cron, every minute) |
| `php vaka check <url> [--keyword=]` | Check a URL once and show the result, certificate included; saves nothing |
| `php vaka status` | How every site is doing right now, by group |

### Sites

| Command | What it does |
|---------|--------------|
| `php vaka site:add <url>` | Start watching a site. `--group=`, `--name=`, `--keyword=<text that must be on the page>`, `--interval=<minutes>`, `--disable=ssl,...`, `--pause` |
| `php vaka site:edit <site>` | Change a site: `--url=`, `--group=<name or none>`, `--name=`, `--keyword=`, `--interval=`, `--pause`, `--resume`, `--disable=`, `--enable=`, `--server=<name>` (for a site behind a CDN) |
| `php vaka site:remove <site>` | Stop watching a site and delete its history (`--force` skips the question) |
| `php vaka site:list [--group=]` | Every site |
| `php vaka site:import <file.csv> [--dry-run]` | Add sites from a CSV file: a `url` column, and optionally `group`, `enabled`, `name`, `keyword`, `interval` |

`<site>` is a URL or a domain (`shop.example`), with the port if it has one. A domain with several sites means the one without a path.

### Groups

| Command | What it does |
|---------|--------------|
| `php vaka group:add <name>` | Add a group. `--policy=immediate\|delayed\|digest\|silent`, `--delay=<minutes>` (for delayed), `--slack=<webhook>`, `--webhook=<url>`, `--email=<a,b>` |
| `php vaka group:edit <name>` | Change a group (the same options, plus `--name=`) |
| `php vaka group:remove <name>` | Remove a group; its sites stay, without a group |
| `php vaka group:list` | Every group: when and where it is alerted |

### Quiet times

| Command | What it does |
|---------|--------------|
| `php vaka maintenance:add <group or site> --at=00:00-07:00 [--days=weekdays\|mon,wed] [--label=]` | A recurring maintenance window |
| `php vaka maintenance:add <group or site> --from="2026-11-01 22:00" --to="2026-11-02 02:00"` | A single maintenance window |
| `php vaka maintenance:list` / `maintenance:remove <#>` | List or remove maintenance windows |
| `php vaka snooze <site or server> [--for=1h\|4h\|24h]` | Quiet an ongoing incident (every failing site on a server, by IP or name) |
| `php vaka unsnooze <site or server>` | End a snooze early |

### Alerts and servers

| Command | What it does |
|---------|--------------|
| `php vaka alert:test [<group>]` | Send a test alert to a group's destinations, or the defaults |
| `php vaka server:list` | Servers and their sites, as Vaka groups them |
| `php vaka server:label <ip> <name>` | Name a server in alerts and the dashboard |

### Users

| Command | What it does |
|---------|--------------|
| `php vaka user:add <name> [--groups=a,b]` | Add a dashboard user. Without `--groups`, they see every site |
| `php vaka user:edit <name> [--groups=] [--password]` | Change what a user sees, or their password |
| `php vaka user:remove <name>` | Remove a user |
| `php vaka user:list` | Every user and what they see |

A user limited to groups never sees other groups' sites, or sites without a group.
