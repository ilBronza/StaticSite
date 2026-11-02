# Changelog

## Unreleased — dev-main

- Accept any Illuminate component version without imposing a Laravel major version.
- Keep the CI matrix explicit about tested versions without restricting host applications.

## 0.1.0 — 2026-11-02

- Explicit exportable Laravel routes and project-specific page sources.
- Anonymous HTML rendering with temporary configuration overrides.
- Public asset export, recursive CSS dependencies and local URL rewriting.
- Static paths for query states, pagination and variants.
- Sitemap, robots, 404 page and redirect rules for static hosting.
- Atomic output replacement with failure recovery and build locking.
- CLI route inventory and build commands.
- Laravel 12 support, tested with PHP 8.2/8.4 and Testbench 10.
- Laravel 11 excluded because its dependency installation is blocked by Composer security advisories.
