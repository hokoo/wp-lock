# Changelog

All notable changes to this project are documented in this file.

## [Unreleased] - 3.0.0 candidate

### Changed

- Use a permanent InnoDB resource row and an independent primary connection to coordinate shared READ and exclusive WRITE acquisitions under REPEATABLE READ and READ COMMITTED.
- Preserve the complete resource ID for MD5 key derivation; store `original_key` only when its complete value fits the diagnostic column.
- Give finite leases database-time expiry checks and exact-owner release; distinguish contention, database errors, uncertain outcomes, and confirmed ownership loss.

### Migration

- Require a coordinated stop, drain, verify, protocol switch, and restart for 2.0 ↔ 3.0. Rolling coexistence is unsupported; TTL=0 and uncertain owners require explicit resolution. See the [operator procedure](docs/migration.md).
- Keep the legacy owner-table schema marker at `2.0.0`; the additive foundation and protocol markers are separate. Rollback to 2.0 retains its known READ COMMITTED conflict risk.

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
- Preserved the full requested TTL instead of rounding expiration down to an integer-second boundary.
