# Alerts

Vaka tells you when something changes, once, in as few messages as it can. This page explains when alerts go out, where they go and what they contain.

## When an alert goes out

- **Down** after two failed checks in a row (`DOWN_AFTER_FAILURES`). A failing site is checked again every minute, so a real outage is confirmed within a couple of minutes, and a single hiccup sends nothing.
- **Up again** on the first good check after that, with how long the site was down.
- **Still down** every four hours while a site stays down (`REMIND_EVERY_HOURS`, `0` turns it off): "shop.example has been down for 4 h", so an alert missed at night is not forgotten. A snooze or a maintenance window holds the reminders back, and groups with the digest or silent policy get none.
- Otherwise nothing while the status stays the same. Vaka remembers what it last told you and only sends a message when that is no longer true.

If a message cannot be delivered (Slack is down, the webhook answers an error, `mail()` fails), Vaka tries again on the next run.

## Certificates

Every https site's certificate is checked every six hours, the way a browser sees it:

- **Expiring**: an alert at 14, 7 and 1 days before it expires (`SSL_ALERT_DAYS`), each once per certificate. When the certificate is renewed, you hear that too, and the countdown starts over.
- **Not valid**: expired, issued for another name, self-signed, or missing its intermediate certificates. The alert says which, and what to do about it.
- **Valid again**: once it is fixed.

A site that does not answer at all is not checked for its certificate (the uptime alert covers it); a site that is down because of its certificate is, since that is what explains it. For certificates from an internal authority, point `SSL_CA_FILE` at a CA bundle that includes it.

## Domains

Every site's domain is looked up once a day (see [Checks](checks.md#domain-expiry)):

- **Expiring**: an alert at 30, 14, 7 and 1 days before the registration runs out (`DOMAIN_ALERT_DAYS`), each once per registration period. Sites that share a domain get one alert.
- **Expired**: right away. When the registry takes a domain down, the site and its email stop working.
- **Renewed**: after a warning, once the new date shows up.

If your registrar renews on the expiry day, every renewal passes the thresholds and alerts. Use fewer days then, such as `DOMAIN_ALERT_DAYS=7,1`.

## Exposed files

Every site is checked once a day for files that should never be public (see [Checks](checks.md#exposed-files)):

- **Public**: an alert the first time a file is found, with what it holds and what to do. A file with secrets, source code or data is red; one that only tells versions and paths is amber. Every file is alerted once; a new one is alerted when it turns up, with the ones still public.
- **Private again**: once nothing is public any more. When one file of several is taken down, nothing is sent; if it comes back, it is alerted again.

These alerts go out right away under the `delayed` policy too: the delay is for outages.

## Security headers

A grade on its own never alerts (see [Checks](checks.md#security-headers)). Vaka alerts when a protection that was in place is removed or gets weaker, compared with what it last told you:

> ⚠️ **shop.example: security headers got worse, grade A to B**
> CSP was removed
> HSTS is weaker: it lasts only 30 days; six months or more is advised
> If that was not on purpose, look at the last deploy, the web server or the CDN settings.

A change has to show up twice, 15 minutes apart, before it counts. Improvements are recorded in the site's history without a message. Like exposed files, these alerts are not delayed by the `delayed` policy.

## One message, not fifty

All alerts from one run go out together: one message per destination.

Sites on the same server are folded into one line. Vaka knows a site's server from the IP address it answered on, or from a name you set by hand (`php vaka site:edit <site> --server=<name>`). When one site starts failing, Vaka checks the other sites on its server right away too, so a server that goes down is confirmed in the same runs and arrives as one alert:

> 🔴 **Server web-1 (203.0.113.10) is not answering: all 10 sites are down**
> shop.example, www.example.se, blog.example.com, …
> No answer within 10 s

When only some of them are down, the alert says "3 of 10 sites on server web-1 (203.0.113.10) are down". Recoveries are folded the same way.

Name a server with `php vaka server:label 203.0.113.10 web-1`; `php vaka server:list` shows every server and its sites.

Sites behind a CDN (Cloudflare, CloudFront, Fastly, Akamai, Bunny) answer on the CDN's addresses, which unrelated sites share, so they are alerted on their own unless you set their server by hand.

## A site that keeps going down and up

A site that flaps does not flood the channel:

1. The first outage is an ordinary "down", and its end an ordinary "up again".
2. A second outage within 30 minutes sends one message instead: "shop.example is unstable: down 2 times in 30 min".
3. After that Vaka stays quiet about the site until it has gone 15 minutes without a change. Then it sends one last message: "stable again: up for 15 min", or "down" if it settled down.

That is at most three messages for an unstable site, however long it keeps at it. Everything is still recorded in the site's history.

## Quiet times

**Maintenance windows** keep planned work quiet. Checks keep running and everything is recorded, but an outage that starts and ends inside the window is never alerted. A site that is still down when the window ends is alerted then.

```bash
php vaka maintenance:add Nightly --at=00:00-07:00                  # a group, every night
php vaka maintenance:add shop.example --at=22:00-02:00 --days=sat  # one site, Saturday nights
php vaka maintenance:add shop.example --from="2026-11-01 22:00" --to="2026-11-02 02:00"
php vaka maintenance:list
php vaka maintenance:remove 2
```

Times are local (`TIMEZONE`) and keep their hours across daylight saving changes. A window that runs past midnight belongs to the day it starts. Outages during maintenance do not count towards "unstable".

**Snooze** quiets an incident you already know about, for 1, 4 or 24 hours:

```bash
php vaka snooze shop.example --for=4h
php vaka snooze 203.0.113.10        # every site that is down on that server
php vaka unsnooze shop.example
```

There is no snooze without an end, so nothing is forgotten: if the site is still down when the snooze ends, you get "shop.example is still down after the snooze", and the reminders every four hours start over from there. A recovery ends the snooze and is alerted as usual.

## Rules per group

Each group has an alert policy (`php vaka group:edit <name> --policy=...`):

| Policy | What happens |
|--------|--------------|
| `immediate` | Alerts go out right away (the default) |
| `delayed` | Only when a site has been down for `--delay` minutes; a shorter outage sends nothing at all |
| `digest` | No live alerts; one daily summary at `DIGEST_TIME` (local time in `TIMEZONE`), and only when something happened |
| `silent` | Nothing is sent; the sites show on the dashboard only |

## Where alerts go

A group can have its own Slack webhook, JSON webhook and email addresses (`--slack=`, `--webhook=`, `--email=`). A group without any, and sites without a group, use the defaults in `.env`: `SLACK_WEBHOOK_URL`, `WEBHOOK_URL` and `ALERT_EMAIL`. `php vaka group:list` shows where each group's alerts go, and `php vaka alert:test <group>` sends a test message.

### Slack

Create an [incoming webhook](https://api.slack.com/messaging/webhooks) for the channel and use its URL. Text from the sites (names, error messages, keywords) is escaped, so it can never mention `@channel` or break the formatting. When `APP_URL` is set, every message links to the dashboard.

### Email

Email goes out through PHP's `mail()`, which hands the message to the server's mail transfer agent (sendmail or postfix). Many hosts, including a fresh Forge server, have none set up: then `mail()` fails, Vaka keeps retrying, and `php vaka alert:test` tells you. Set `ALERT_FROM` to an address your server may send from. SMTP delivery is on the roadmap.

### JSON webhook

Every message is a `POST` with `Content-Type: application/json`. The fields are stable; new ones may be added.

```json
{
  "source": "vaka",
  "version": "0.1.0",
  "sent_at": "2026-10-25T12:01:00Z",
  "alerts": [
    {
      "kind": "down",
      "headline": "Server 203.0.113.10 is not answering: all 2 sites are down",
      "details": ["shop.example, blog.example", "No answer within 10 s"],
      "server": "203.0.113.10",
      "sites": [
        {
          "url": "https://shop.example",
          "group": "Customers",
          "summary": "No answer within 10 s",
          "since": "2026-10-25T12:00:00Z"
        }
      ]
    }
  ]
}
```

| Field | Meaning |
|-------|---------|
| `kind` | `down`, `up`, `unstable`, `stable`, `still_down` (after a snooze), `reminder` (still down, every `REMIND_EVERY_HOURS`), `ssl_expiring`, `ssl_invalid`, `ssl_fixed`, `ssl_renewed`, `domain_expiring`, `domain_expired`, `domain_renewed`, `exposed` (a file with secrets, code or data is public), `exposed_minor` (a file that tells versions and paths), `exposed_fixed`, `headers_worse` (a security header was removed or weakened), `digest` or `test` |
| `headline` | The alert in one sentence, as Slack and email show it |
| `details` | Further lines: the sites and the reason |
| `server` | The server's name or IP when sites were folded together, otherwise `null` |
| `sites[].url` | The site's URL |
| `sites[].group` | Its group, or `null` |
| `sites[].summary` | What the last check found, in plain words |
| `sites[].since` | When the current status began (UTC, ISO 8601) |

`summary` and `details` contain text that came from the monitored sites (error messages, keywords). Treat them as data, never as instructions.
