# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Configuration from an optional `.env` file, with OS environment variables taking precedence.
- SQLite database in WAL mode with versioned, lazy migrations.
- `php vaka` command line tool with `help` and `version`.
- Groups: `group:add`, `group:edit`, `group:remove` and `group:list`. Each group has an alert policy (right away, after a number of minutes down, a daily summary, or dashboard only) and its own Slack webhook, JSON webhook and email addresses; a group without its own destinations uses the defaults in `.env`. Webhook URLs are shown masked.
- Sites: `site:add`, `site:edit`, `site:remove` and `site:list`. URLs are stored in one spelling (https added, lowercase host, punycode, no default port or root slash), so a site cannot be added twice. A site is found by URL, by domain or picked from a list.
- `site:import <file.csv>` adds sites from a CSV file with a `url` column and optionally `group`, `enabled`, `name`, `keyword` and `interval`, separated by commas or semicolons. Missing groups are created, sites already watched are left unchanged, every problem is listed with its line number, and nothing is saved unless the whole file is valid. `--dry-run` shows what would happen.
- `php vaka run`, meant for cron every minute, checks every site that is due, in parallel. A site is up when it answers below 400 (following up to five redirects) and, when a keyword is set, the page contains it (any letter case, HTML entities decoded). It is down after `DOWN_AFTER_FAILURES` failures in a row, counted from the first one; a failing site is checked again every minute. Response time and the IP the site answered on are kept for `SAMPLES_RETENTION_DAYS`, every change of status goes in the site's history, and runs never overlap.
- Problems are described in plain words: "No answer within 10 s", "The domain does not resolve", "The site answered 503 Service Unavailable", "Up again after 14 min".
- Sites behind Cloudflare, CloudFront, Fastly, Akamai or Bunny are recognised from their response headers.
- `php vaka check <url>` checks a URL once without saving anything; `php vaka status` shows every site by group, and warns when Vaka has not run for more than five minutes.
- `HEARTBEAT_URL`: every run pings it, so a dead man's switch such as Healthchecks.io can alert you when the runs stop.
