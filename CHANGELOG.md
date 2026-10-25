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
