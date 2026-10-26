# Checks

What Vaka checks, how often, and what counts as a problem. Every result is explained in plain words, with advice on what to look at.

## Uptime and response time

Every 5 minutes (`UPTIME_INTERVAL`, or a site's own `--interval`), Vaka asks for the site's page, the way a browser would:

- It follows up to five redirects (http and https only).
- **Up** means an answer below 400 within `UPTIME_TIMEOUT` seconds, and, if the site has a keyword, that text on the page (any letter case; HTML entities are decoded).
- **Down** means `DOWN_AFTER_FAILURES` (2) failed checks in a row. After the first failure the site is checked again every minute, so an outage is confirmed in about two minutes and one hiccup alerts nobody.
- When a site starts failing, the other sites on the same server are checked at once too, so a server that goes down shows up, and is alerted, as one.

Every check stores the response time and the IP address the site answered on, kept for `SAMPLES_RETENTION_DAYS`. Uptime percentages are weighted by time: a site that was down for 14 minutes in a day was up 99.02% of it, however often it was checked meanwhile.

What a failure means, and what the dashboard suggests:

| What happened | What it says |
|---------------|--------------|
| No answer in time | The server may be down or overloaded |
| The domain does not resolve | Check the DNS records, and that the domain is still registered |
| The connection was refused | Is the web server running? |
| 500-599 | The site itself answers with an error: look at its error log or the last deploy |
| 404, 410 | The page is not there |
| 401, 403 | The site refuses Vaka: access rules, a firewall, a password |
| 429 | The site limits how often it may be asked |
| The keyword is missing | The page loads, but shows something else |
| Certificate not accepted | Browsers will refuse the site too |
| Redirect loop | Check the redirect rules |

A site behind a CDN (Cloudflare, CloudFront, Fastly, Akamai, Bunny) is recognised from its response headers. CDN addresses are shared by unrelated sites, so such a site is not grouped with others by IP; set its server by hand with `site:edit --server=<name>` if you want it grouped.

## SSL certificates

Every six hours for https sites. See [Alerts](alerts.md#certificates) for when it alerts.

- **Valid**: trusted, covers the site's name, not expired. The dashboard shows until when and who issued it.
- **Expires soon**: within 14 days (the largest of `SSL_ALERT_DAYS`).
- **Not valid**: expired, issued for another name, self-signed, not valid yet, or the chain to a known authority is missing.

The handshakes happen one at a time and stop 40 seconds into a run; what is left waits for the next run.

## Exposed files

Once a day, Vaka asks each site for files that should never be on the web. It looks next to the site's address, so `https://shop.example/blog` is checked in `/blog/`. See [Alerts](alerts.md#exposed-files) for when it alerts.

| File | What it holds | Level |
|------|---------------|-------|
| `.env` | The environment file, often with database passwords and API keys | Error |
| `.git/HEAD`, `.git/config` | The Git repository: the site's source code and its history | Error |
| `wp-config.php.bak` | A copy of the WordPress settings, with the database password | Error |
| `backup.zip`, `site.zip` | A backup of the site | Error |
| `db.sql`, `dump.sql`, `backup.sql`, `db.sql.gz` | A copy of the database | Error |
| `phpinfo.php`, `info.php` | A PHP information page: versions, settings and paths | Warning |
| `server-status` | Apache's status page: who is visiting and what they ask for | Warning |
| `composer.json`, `composer.lock`, `package.json` | The packages the site uses, and their versions | Warning |
| `.DS_Store` | A macOS folder index that lists the files next to it | Warning |

- **Error**: the file holds secrets, source code or data. Remove it or block it, then change every password and key it held: assume someone has read it.
- **Warning**: the file holds no secrets, but it tells an attacker which software and versions the site runs.

A file counts only when its content says what it is. Many sites answer every address with a page of their own and status 200 (a "soft 404"); that page never counts as a `.env` or a database dump. Vaka also asks for a made-up address each time, and content that the made-up address gets too is the site's answer to anything, not a file. An error status (403, 404) means the file is not public.

Vaka reads at most 64 KB of each answer, never a whole backup. While something is public the site is checked every hour, so a fix shows up soon. A site that is down is checked when it is back.

**Firewalls.** Asking for `/.env` and `/.git/` is also what attackers do, and some firewalls (ModSecurity rules, Imunify360, Wordfence) may block the address the requests come from. Then the uptime check sees the site as down. Vaka asks for 18 addresses per site once a day, with its own User-Agent. If a firewall blocks it, allow Vaka's IP address, or turn the check off for that site:

```bash
php vaka site:edit shop.example --disable=exposed
```

## Security headers

Every six hours, Vaka looks at the headers the site's page answers with and grades six protections that browsers enforce for the site's visitors:

| Protection | What it does | Header that adds it |
|------------|--------------|---------------------|
| HSTS | Makes browsers always use https for the site | `Strict-Transport-Security: max-age=31536000` |
| CSP | Limits which scripts may run: the main defence against injected scripts | `Content-Security-Policy: default-src 'self'` |
| X-Content-Type-Options | Stops browsers from guessing what a file is | `X-Content-Type-Options: nosniff` |
| Frame protection | Stops other sites from showing this one in a frame to trick visitors into clicking | `X-Frame-Options: SAMEORIGIN`, or `frame-ancestors` in the CSP |
| Referrer-Policy | Keeps full addresses from leaking to other sites | `Referrer-Policy: strict-origin-when-cross-origin` |
| Permissions-Policy | Turns off browser features the site does not use | `Permissions-Policy: camera=(), microphone=(), geolocation=()` |

Each one is **in place**, **weak** or **missing**. Weak means it is there but lets through what it is meant to stop: HSTS for less than six months, a CSP that allows inline scripts (`'unsafe-inline'` without a nonce or hash), `eval` or scripts from anywhere, `frame-ancestors *`, or a Referrer-Policy of `unsafe-url` or `no-referrer-when-downgrade`. A `Content-Security-Policy-Report-Only` header blocks nothing, so it does not count.

The grade counts what is in place: all six is A (B if one of them is weak), five B, four C, three D, two E, fewer F. The site detail lists each protection, why it is weak or missing, and the line that adds it. A site on plain http cannot have HSTS, so it gets a B at best.

A low grade is shown, never alerted: many sites start at D or E, and that is something to improve, not an incident. What alerts is a protection that disappears or gets weaker, typically after a deploy or a change at the CDN; see [Alerts](alerts.md#security-headers). A change is looked at again 15 minutes later and only counts when that look agrees, so one odd answer does not alert. An error page is not graded.

Vaka grades its own pages A over https.

## Coming next

- **Mail DNS**: MX, SPF and DMARC, and an alert when they change.
- **Domain expiry** through RDAP.

See the [roadmap](../ROADMAP.md).
