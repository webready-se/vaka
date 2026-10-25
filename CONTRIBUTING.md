# Contributing to Vaka

Thanks for your interest in contributing! Vaka is intentionally minimal: PHP and SQLite, no framework, no runtime dependencies. Please keep that in mind when proposing changes.

## Ground Rules

- **No runtime dependencies.** Vaka must stay deployable by copying the files to any PHP 8.3+ host with `pdo_sqlite`, `curl` and `openssl`.
- **No build step.** The dashboard is plain HTML, CSS and JavaScript.
- **Strict CSP.** No inline scripts, inline event handlers or inline styles.
- **No real data.** Use `example.com`, `shop.example` and documentation IPs (`192.0.2.x`, `198.51.100.x`, `203.0.113.x`) in code, tests, docs and screenshots.

## How to Contribute

1. Fork the repo and create a branch from `main`
2. Make your changes, with tests
3. Run tests: `./vendor/bin/pest`
4. Open a pull request with a clear description of what and why

## Development Setup

```bash
git clone git@github.com:your-fork/vaka.git
cd vaka
composer install          # installs Pest + activates the pre-push hook
php vaka help
./vendor/bin/pest
```

`composer.json` pins `config.platform.php` to 8.3, the lowest PHP version Vaka supports, so `composer.lock` resolves for everyone on the supported range.

## Code Style

- 4-space indentation in PHP, 2-space in HTML/CSS/JS
- English code and comments
- Plain functions in `src/`, grouped by concern
- No unnecessary abstractions: three similar lines beat a premature helper

## Tests

All changes must pass the test suite, and new behaviour needs tests. A bug fix starts with a test that fails. Tests never use the real network: checks run against local fixture sites served by `php -S`.

```bash
./vendor/bin/pest
```

## Reporting Bugs

Open a [GitHub issue](../../issues) with:

- What you expected
- What happened instead
- Steps to reproduce
- PHP version and environment details

Security issues go through [SECURITY.md](SECURITY.md), not public issues.
