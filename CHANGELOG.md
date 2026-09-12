# Changelog

All notable changes to this project are documented in this file.

## [2.0.0] - 2026-09-13

### Added

- Configurable blocking wait deadline and database error retry count for the DB backend.
- Automatic, versioned database schema upgrades with a `lock_key` index.
- Deterministic lifecycle, timeout, retry, schema, and concurrency coverage.

### Changed

- Made `WP_Lock` and each DB backend resource ownership lifecycle non-reentrant.
- Added strict validation for resource identifiers, lock levels, and expiration values.
- Bounded blocking acquisition to 30 seconds and database failures to three retries by default.
- Made persistent database and release failures explicit through `RuntimeException`.
- Changed `WP_Lock_Backend::acquire()`, `release()`, and `exists()` to require `bool` return types.
- Changed backend `release()` to report success so ownership is preserved after a failed release.

### Removed

- Removed the bundled `flock` backend and the `WP_Lock_Backend_flock` class.

### Fixed

- Prevented recursive, indefinitely blocking acquisition and unbounded database-error retry loops.
- Prevented repeated acquisition from overwriting backend ownership state.
- Suppressed and recovered from first-use missing-table errors without changing the caller's wpdb error-suppression state.
