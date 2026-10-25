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

## Coming next

- **Exposed files**: `.env`, `.git/`, database dumps and backups that should never be public, matched on their content.
- **Security headers**: a grade from A to F, and an alert when a header disappears or weakens.
- **Mail DNS**: MX, SPF and DMARC, and an alert when they change.
- **Domain expiry** through RDAP.

See the [roadmap](../ROADMAP.md).
