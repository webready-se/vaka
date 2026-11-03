# Security Policy

## Reporting a Vulnerability

If you discover a security vulnerability in Vaka, please report it responsibly.

**Do not open a public issue.**

Instead, use [GitHub's private vulnerability reporting](../../security/advisories/new) to submit your report. This keeps the details confidential until a fix is available.

## What to Include

- Description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if you have one)

## Response

We aim to acknowledge reports within a few working days and to provide a fix or mitigation plan within two weeks for confirmed vulnerabilities.

## Scope

- `public/` - web front controller and dashboard
- `src/` - checks, alerting, authentication and storage
- `vaka` - CLI tool
- Authentication, sessions, CSRF and access per group
- Requests Vaka makes to monitored sites
