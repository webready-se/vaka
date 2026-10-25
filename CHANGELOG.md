# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Configuration from an optional `.env` file, with OS environment variables taking precedence.
- SQLite database in WAL mode with versioned, lazy migrations.
- `php vaka` command line tool with `help` and `version`.
