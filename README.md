# Vaka

**Keeps watch while you sleep.**

Uptime, response times and certificates for every site you run, plus domains, exposed files, security headers and mail DNS. One dashboard, one cron line, no dependencies.

> Status: planning. Nothing to install yet. See [ROADMAP.md](ROADMAP.md).

## What you get

- **Uptime and response time** for every site, with an optional keyword that must be on the page. Down after two failures in a row, so one hiccup does not wake anyone.
- **One message, not fifty.** When a server goes down, Vaka sends one alert for every site on it: "server 203.0.113.10: 23 sites down". Recoveries too.
- **Groups with their own rules.** Each group has its own Slack channel and alert policy: right away, after a few minutes down, a daily digest, or dashboard only.
- **Maintenance windows and snooze.** Silence a site or a group every night between 02:00 and 04:00, or snooze an incident you already know about. Checks keep running and recording.
- **SSL certificates** that warn at 14, 7 and 1 days before they expire, and when they turn invalid.
- **Domain expiry** through RDAP, the registries' own structured data.
- **Exposed files** like `.env`, `.git/HEAD`, database dumps and backup zips, matched on their content so a site that answers 200 for everything does not cry wolf.
- **Security headers** graded A to F, with an alert when one disappears or gets weaker.
- **Mail DNS** (MX, SPF, DMARC), with an alert when a record changes.
- **Alerts** to Slack, a JSON webhook or email.

## Why

- **Copy the files, add a cron line.** PHP 8.3+ and SQLite. No framework, no Composer packages at runtime, no build step, no CDN.
- **Stays maintainable.** Nothing to upgrade except PHP itself.
- **Strict by default.** A strict Content Security Policy, CSRF tokens, login lockout and no personal data stored.

## Quick start

```bash
php vaka user:add admin
php vaka group:add customers
php vaka site:add https://shop.example --group=customers
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
