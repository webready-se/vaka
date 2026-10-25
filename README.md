# Vaka

**Keeps watch while you sleep.**

Know when a site goes down, hear about it once, and see what got worse since yesterday. For everyone who runs a handful or a few hundred sites. One dashboard, one cron line, no dependencies.

> Status: in development. Nothing to install yet. See [ROADMAP.md](ROADMAP.md).

## What makes it different

- **One message, not fifty.** When a server goes down, Vaka sends one alert for every site on it: "server 203.0.113.10: 23 sites down". Recoveries too. Most monitors send one alert per site.
- **Snooze.** Already on it? Snooze the incident for an hour, four hours or a day. If it is still down when the snooze ends, you hear about it again, so nothing is forgotten.
- **Rules per group.** Each group has its own Slack channel and alert policy: right away, after a few minutes down, a daily digest, or dashboard only. Maintenance windows (one-off or every night) keep planned work quiet while checks keep recording.
- **Watches for things getting worse.** Exposed `.env` files, git folders and database dumps, security headers that disappear, mail DNS that changes. Checked every day, with an alert when something changes, not a one-off scan.
- **Plain language.** It leads with what is wrong right now and what to do about it. Every number is explained; the detail is one click away.

## Everything it checks

- **Uptime and response time**, with an optional keyword that must be on the page. Down after two failures in a row, so one hiccup does not wake anyone.
- **SSL certificates**: a warning at 14, 7 and 1 days before they expire, and when they turn invalid.
- **Domain expiry** through RDAP, the registries' own structured data.
- **Exposed files** like `.env`, `.git/HEAD`, database dumps and backup zips, matched on their content so a site that answers 200 for everything does not cry wolf.
- **Security headers** graded A to F.
- **Mail DNS**: MX, SPF and DMARC.
- **Alerts** to Slack, a JSON webhook or email.

## Why self-host it

- **Copy the files, add a cron line.** PHP 8.3+ and SQLite. No framework, no Composer packages at runtime, no build step, no CDN.
- **Stays maintainable.** Nothing to upgrade except PHP itself.
- **Strict by default.** A strict Content Security Policy, CSRF tokens, login lockout and no personal data stored.
- **Watched itself.** Vaka pings a heartbeat URL of your choice on every run, so you hear about it when Vaka stops. Run it on a different server than the sites it watches.

## Quick start

```bash
php vaka user:add admin
php vaka site:add https://shop.example
php vaka run
```

Then run `php vaka run` every minute from cron and point a web server at `public/`.

## Docs

- [Install](docs/install.md)
- [Configuration](docs/configuration.md)
- [Checks](docs/checks.md)
- [Alerts](docs/alerts.md)

## License

MIT, made by [Webready](https://webready.se). Sibling of [Puls](https://github.com/webready-se/puls).
