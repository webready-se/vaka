# Checks

What Vaka checks, how often, and what counts as a problem. Every result is explained in plain words, with advice on what to look at.

## Uptime and response time

Every 5 minutes (`UPTIME_INTERVAL`, or a site's own `--interval`), Vaka asks for the site's page, the way a browser would:

- It follows up to five redirects (http and https only).
- **Up** means an answer below 400 within `UPTIME_TIMEOUT` seconds, and, if the site has a keyword, that text on the page (any letter case; HTML entities are decoded).
- **Down** means `DOWN_AFTER_FAILURES` (2) failed checks in a row. After the first failure the site is checked again every minute, so an outage is confirmed in about two minutes and one hiccup alerts nobody.
- When a site starts failing, the other sites on the same server are checked at once too, so a server that goes down shows up, and is alerted, as one. Not when its domain does not resolve or its keyword is missing: no server was asked, or it answered, so that is the site's own trouble.

A run from cron starts checking 15 seconds into the minute (`RUN_AT_SECOND`). Most servers start their own scheduled jobs at the top of every minute, and for a few seconds they answer several times slower than at any other moment: measured then, response times would show that burst, not what visitors get.

Each site has a minute of its own within its interval, so the checks are spread over it: with 50 sites every 5 minutes, about ten are asked each minute instead of all fifty at once. Asking them together hit a server with all its sites at the same moment, and made those minutes' response times look slower than the sites are.

Every check stores the response time and the IP address the site answered on, kept for `SAMPLES_RETENTION_DAYS`. Uptime percentages are weighted by time: a site that was down for 14 minutes in a day was up 99.02% of it, however often it was checked meanwhile. Downtime inside one of the site's maintenance windows is planned: it is left out of uptime and shown on its own. Only the part inside the window counts as planned, so a site still down after its window loses uptime from then on.

The 30 day bars show one day each, in `TIMEZONE`: green without an outage, amber when the site was down for less than an hour in all, red for an hour or more, the quiet colour when it was down only in maintenance, grey before the first check. Hovering a bar says what happened that day; in a site's detail, the line under the bars sums up the month and tells the day under the pointer or finger.

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

### Behind a bot check

Some hosts and CDNs put a bot check in front of a site: a page that runs a script in the browser before it lets anyone through. Vaka runs no scripts, so it gets that page instead of the site. It recognises two: Cloudflare's challenge (`cf-mitigated: challenge`, status 403) and Imunify360's "One moment, please..." page, which many shared hosts show with status 200. Such a page says nothing about the site, so Vaka does not grade it as the site:

- Uptime keeps the status it had: no outage, no recovery, no response time. The dashboard puts the site in "Needs your attention" as hidden behind a bot check, with the address Vaka's server asked from (when it is a public one), the address to let through. Nothing is alerted: the fix is one change at the host, and an alert each time the check comes back would be noise.
- Security headers and exposed files say "Could not check", which never alerts. Graded, the bot check's page would look as if every protection had been removed.

Let Vaka's server through: ask the host, or add a rule at the CDN. A host's bot check often starts after requests that look like an attack, and the exposed-files check asks for `.env`, `.git/config` and the like once a day; when a host keeps putting the check up, turning that check off for the site (`site:edit <site> --disable=exposed`) can keep it away.

### Behind a CDN

A site behind a CDN (Cloudflare, CloudFront, Fastly, Akamai, Bunny) is recognised from its response headers. CDN addresses are shared by unrelated sites, so such a site is not grouped with others by IP; set its server by hand with `site:edit --server=<name>` if you want it grouped.

## SSL certificates

Every six hours for https sites. See [Alerts](alerts.md#certificates) for when it alerts.

- **Valid**: trusted, covers the site's name, not expired. The dashboard shows until when and who issued it.
- **Expires soon**: within 14 days (the largest of `SSL_ALERT_DAYS`).
- **Not valid**: expired, issued for another name, self-signed, not valid yet, or the chain to a known authority is missing.

Behind Cloudflare, visitors get Cloudflare's certificate, not the one on your server, and that is the one Vaka sees. Cloudflare renews it by itself about 30 days before it expires, so the dashboard says who renews it, and 31 days left is nothing to act on. Vaka still warns at 14 days: a certificate Cloudflare has not renewed by then is stuck, often on a CAA record, and the advice says where to look. The certificate on your own server cannot be seen from outside; when it expires, Cloudflare (in SSL mode Full (strict)) answers 526, and the uptime check reports the site down.

The handshakes happen one at a time and stop 40 seconds into a run; what is left waits for the next run.

## Domain expiry

Once a day, Vaka looks up when each site's domain registration runs out. `shop.example.se` is looked up as `example.se`; sites that share a domain cause one lookup.

- **RDAP** is the registries' own structured data. Vaka finds the right registry in IANA's bootstrap file, kept in `data/` for a week.
- **WHOIS**, for registries without RDAP, such as .se and .nu (`WHOIS_SERVERS`). Only the `expires:` line is read.
- **Unknown** when the registry publishes no expiry date Vaka can read, when it does not know the domain, or for a site reached by its IP address. That never alerts.

See [Alerts](alerts.md#domains) for when it alerts. Sites that are down are looked up too: an expired domain may be why.

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

**Firewalls.** Asking for `/.env` and `/.git/` is also what attackers do, and some firewalls (ModSecurity rules, Imunify360, Wordfence) may block the address the requests come from. Then the uptime check sees the site as down, or hidden behind a bot check (see "Behind a bot check" above). Vaka asks for 18 addresses per site once a day, with its own User-Agent (`VakaBot/<version>`). If a firewall blocks it, allow Vaka's IP address, or turn the check off for that site:

```bash
php vaka site:edit shop.example --disable=exposed
```

## Security headers

Every six hours, Vaka looks at the headers the site's page answers with and grades six protections that browsers enforce for the site's visitors:

| Protection | What it does | Header that adds it |
|------------|--------------|---------------------|
| HSTS | Makes browsers always use https for the site | `Strict-Transport-Security: max-age=31536000` |
| CSP | Limits which scripts may run: the main defence against injected scripts | `Content-Security-Policy: default-src 'self'`, tried first as `Content-Security-Policy-Report-Only` |
| X-Content-Type-Options | Stops browsers from guessing what a file is | `X-Content-Type-Options: nosniff` |
| Frame protection | Stops other sites from showing this one in a frame to trick visitors into clicking | `X-Frame-Options: SAMEORIGIN`, or `frame-ancestors` in the CSP |
| Referrer-Policy | Keeps full addresses from leaking to other sites | `Referrer-Policy: strict-origin-when-cross-origin` |
| Permissions-Policy | Turns off browser features the site does not use | `Permissions-Policy: camera=(), microphone=(), geolocation=()` |

Each one is **in place**, **weak** or **missing**. The site detail shows what the site sends for each: under "In place" one click away, and next to a weak one, so the line to change it to can be read against it. Every line to add is labelled with what to do with it, and the scripts a CSP item names ("2 inline scripts") open the list of them. Weak also means sent twice with different values ("sent twice, \"DENY\" and \"SAMEORIGIN\": the application and the web server both add it, so browsers may take either; keep one"): the usual cause is an application that sets its own headers plus a web server rule on top, as Forge's nginx adds `X-Frame-Options SAMEORIGIN`. A CSP sent twice is no duplicate, since both apply. Otherwise weak means it is there but lets through what it is meant to stop: HSTS for less than six months, a CSP that allows inline scripts (`'unsafe-inline'` without a nonce or hash), `eval` or scripts from anywhere, `frame-ancestors *`, or a Referrer-Policy of `unsafe-url` or `no-referrer-when-downgrade`. A `Content-Security-Policy-Report-Only` header blocks nothing, so it does not count.

Without a CSP, the site detail suggests a `Content-Security-Policy-Report-Only` line rather than the policy itself: Report-Only blocks nothing and lists in the browser's console what the policy would block, so the site can allow what it needs and then rename the header. The line is `default-src 'self'` when the page needs nothing beyond the site itself, and otherwise a draft from what the page has: the origins its scripts, stylesheets, images, icons and frames come from, a `'sha256-...'` hash for each inline script and `<style>` block as they are today, `'unsafe-inline'` for styles when the page has `style="..."` attributes (nothing else lets them through), known companions (Google Fonts loads from `fonts.gstatic.com`) and `object-src 'none'`. The item says what the page has ("the page has 8 inline scripts, a script from tags.example and 4 inline styles: the line below allows what is there today") and shows the inline scripts by their start. The draft is a draft, said in the tip: the hashes change with the code, so the build should add them or the code move to files; a tag manager loads more hosts later and other pages may need more, which Report-Only shows in the console (and the report endpoint on the roadmap will collect). A page that loads a tag manager is told so in the note itself ("It loads Google Tag Manager, so a strict policy takes more than a line"), with the why one click away: it loads more later, by what is set up in its own interface, so a strict CSP with it needs nonces from the site's own code, or the policy stays weak with `'unsafe-inline'`; Report-Only with reporting is the middle. A site that sends its CSP only as Report-Only is shown as "Sent as Report-Only, not in force", with its own policy under the enforcing name as the line to add, once the console is clean. A Report-Only sent next to a policy in force is said to add nothing: the one in force may come from the application itself, not from the line just added to the web server. The tip adds `object-src 'none'` to the enforced policy: no site needs plugins, and it is the remark other scanners make on an otherwise complete one.

Each line is offered as the header itself (`Name: value`), or as the line that adds it in nginx (`add_header Name "value" always;`) or in Apache (`Header always set Name "value"`), with a Copy button, preselecting the web server the site answered with (its `Server` header; behind a CDN the last choice is kept). A header copied as sent into nginx, colon and all, is a line nginx refuses, and with it the whole configuration: the old one keeps running, so nothing changes and nothing says why.
With a CSP in place, Vaka compares the page with it as browsers do: each `<script src>` against `script-src-elem`, `script-src` or `default-src` (the sources as browsers read them: `'self'`, schemes, hosts with `*.`, ports and paths), each inline script's sha256 against the hashes in it (a nonce in the policy lets an element with a `nonce` attribute through, since Vaka cannot see the nonce the page was sent with); stylesheets and `<style>` blocks the same way against `style-src`, and `style="..."` attributes, which only `'unsafe-inline'` (or `'unsafe-hashes'`) lets through; images (`src` and `srcset`) and the site's icons (`<link rel="icon">` and the like, which browsers load under `img-src`) against `img-src`, where a `data:` address passes only the `data:` source, as in browsers (an inline SVG favicon under `default-src 'self'` is the usual case); frames against `frame-src` or `child-src`. A blocked script stops working, a blocked style makes the page look broken, so both count. What the policy blocks is said in the summary ("the CSP blocks 2 inline scripts, 15 inline styles and an image from cdn.example on the page"), the check warns, and the site detail lists it by start, kind and address. It is a change like the others: confirmed by a second look 15 minutes later, so a deploy in between does not count, and alerted once when it starts, since visitors do not get those scripts and nothing else says so. The grade does not change: the CSP is in place and strict, that is the point. Sent only as Report-Only, the same comparison says what the policy would block, or that it would block nothing on the page, so it is safe to rename. Limits: one page, and only what is in its HTML: not what scripts add later, nor fonts and images inside the CSS files, nor the connections scripts make.

X-Content-Type-Options matters most on scripts and styles, and they are where it most often goes missing. So Vaka also reads the first stylesheet and the first script the page loads from the site's own server (its host, or a host under it, such as `static.shop.example` for `shop.example` or `www.shop.example`), and checks that they say `nosniff` too. When the page does and they do not, X-Content-Type-Options counts as weak, and the site detail names the files. The usual cause is a web server rule for those files that adds a header of its own, such as `Cache-Control`: in nginx, a `location` with its own `add_header` drops every `add_header` set for the whole server, for those files only. Only files that answer 200 count (a missing file can end up at the site's own error page), files on another host, such as a CDN, are left out (a file on a host under the page's is named with its host), and frame protection and CSP are not asked of them, since they do nothing on a file.

Adding them in nginx has two traps. Without `always`, nginx leaves `add_header` lines off error pages (4xx and 5xx). And a `location` with an `add_header` of its own drops every `add_header` from the `server` block, for whatever that location serves, so a header added for the whole site can still be missing on the page or its files. So put them in the `server` block with `always`, and keep `add_header` out of `location` blocks (for caching, `expires` sets `Cache-Control` without it), or repeat every header in each such block. The site detail and the read API say so under the list. Vaka grades the page and the first stylesheet and script it loads; error pages are not graded.

The grade counts what is in place: all six is A (B if one of them is weak), five B, four C, three D, two E, fewer F. The site detail lists each protection, why it is weak or missing, and the line that adds it. A site on plain http cannot have HSTS, so it gets a B at best.

A low grade is shown, never alerted: many sites start at D or E, and that is something to improve, not an incident. What alerts is a protection that disappears or gets weaker, typically after a deploy or a change at the CDN, and `nosniff` disappearing from the scripts and styles, typically after a new cache rule; see [Alerts](alerts.md#security-headers). A change is looked at again 15 minutes later and only counts when that look agrees, so one odd answer does not alert. An error page is not graded.

Vaka grades its own pages A over https.

## Mail DNS

Every six hours, Vaka reads three DNS records of the site's domain (the one the domain check found, so `www.shop.example` and `app.shop.example` both mean `shop.example`). Sites that share a domain cause one lookup.

| Record | What it does | Where |
|--------|--------------|-------|
| MX | Where email to the domain is delivered | `shop.example` |
| SPF | Which servers may send email in the domain's name | A TXT record at `shop.example` starting `v=spf1` |
| DMARC | What receivers do with email that pretends to come from the domain, and where they send reports | A TXT record at `_dmarc.shop.example` starting `v=DMARC1` |

The check is **ok** when SPF and DMARC are in place, and **warn** when one of them is missing or there are two SPF records (receivers then treat SPF as broken). Without them, anyone can send email that looks like it comes from the domain, which is how most phishing in a company's name starts. A domain without MX, or with a null MX (`0 .`, which says so on purpose), receives no email, which is fine on its own, and a DMARC record with `p=none` only collects reports: both are shown, neither is a warning.

The site detail lists each record with what the domain has and, when one is missing or weak, the value to add and where. What it suggests depends on whether the domain sends email, as far as its records tell:

- **It sends no email:** SPF allows no sender (`v=spf1 -all`), or there is neither SPF nor a mail server. Then `v=spf1 -all` and `v=DMARC1; p=reject`, at once: there is no email of its own to stop, and anything in its name is forged. Reports are optional.
- **It sends email:** SPF names senders (`include:`, `ip4:` and so on), or there is a mail server, whose users send too. Then `p=none` with reports first, and `p=quarantine` and `p=reject` once the reports show that your own email passes, the mail provider's and the site's own (a contact form, say), which can fail the check when the web server sends it. When Vaka cannot tell, it suggests this one: `p=reject` on a domain that does send could stop its own email.

`rua` in a DMARC record says where receivers send their reports. It is optional, but with `p=none` the reports are all the record gives. They come as zipped XML, so a free DMARC report service is easier than a mailbox: it gives the address to put in `rua` and sums the reports up, for example Cloudflare's DMARC Management for a domain on Cloudflare, or Postmark's weekly DMARC digests. A record with `p=none` and no `rua` neither stops anything nor reports, and the detail says so. On a domain that sends, it suggests adding `rua` first, keeping `p=none`, since without reports there is nothing to tell when `p=quarantine` is safe. Once `rua` is added, the detail waits four weeks from when Vaka saw it before it suggests `p=quarantine`, so that senders who send once a month, such as an invoicing system or a newsletter, show up in the reports too; until then it says when the reports started and until when to read them. A record that already had `rua` when Vaka first looked gets the suggestion at once, since Vaka cannot tell how long the reports have come.

Missing records are shown, never alerted. What alerts is a change: MX pointing somewhere new, an SPF record that now allows other servers, a DMARC record that disappears. Someone who can change those can read or send the domain's email; see [Alerts](alerts.md#mail-dns). A change is looked up again 15 minutes later and only counts when that look agrees, since resolvers can disagree for a while after a change. A lookup that fails keeps what was known and tries again within the hour.

Turn it off for a site with `php vaka site:edit <site> --disable=dns`.
