# WP Lock

[![PHPUnit Tests](https://github.com/hokoo/wp-lock/actions/workflows/phpunit.yml/badge.svg)](https://github.com/hokoo/wp-lock/actions/workflows/phpunit.yml)

WP Lock provides shared READ locks and exclusive WRITE locks for WordPress. Its only bundled backend uses the WordPress database.

The unpublished 3.0.0 candidate uses a permanent resource row and an independent primary database session for ownership changes. Its schema must be prepared and its protocol explicitly enabled under the [migration barrier](docs/migration.md) before new acquisitions. Version 2 and 3 participants must never run together for the same database namespace.

See the [project roadmap](ROADMAP.md) for planned versions, [known issues](docs/issues/README.md) for findings and evidence, and [epics and tasks](docs/planning/README.md) for execution details.

## Requirements

- PHP 7.4 or newer (the Composer constraint; PHP 7.4 itself is upstream end-of-life)
- WordPress with a MySQL-compatible database

The 3.0 protocol passed the full local native suite at both REPEATABLE READ and READ COMMITTED on these **six exact validation configurations**, not every PHP/WordPress/database combination: PHP 8.5.11 + WordPress 7.1.2 with MySQL 26.7.0 or 9.7.2 and MariaDB 13.0.2 or 12.3.3; PHP 7.4.33 + WordPress 6.2.13 with MySQL 9.7.2 or MariaDB 12.3.3. WordPress 6.2.13 is a backward anchor, not a universal floor. See the [matrix evidence](docs/planning/evidence/E5-02.md); final candidate and remote CI acceptance remain release gates.

Directed [ownership](docs/planning/evidence/E3-03.md), [lease timing](docs/planning/evidence/E4-02.md), and [recovery](docs/planning/evidence/E4-03.md) evidence supports the bundled-backend behavior below; sampled runs cannot prove every schedule safe.

Install the package with Composer:

```bash
composer require hokoo/wp-lock
```

Load Composer's autoloader from your plugin or application:

```php
use iTRON\WP_Lock\WP_Lock;

require_once __DIR__ . '/vendor/autoload.php';
```

## Usage

Always check the result of `acquire()`. The bundled backend returns `false` for contention or an exhausted wait budget without granting the lock. Non-blocking means one acquisition attempt, not that its synchronous database call returns immediately.

```php
use iTRON\WP_Lock\WP_Lock;

$lock = new WP_Lock( 'user:' . $user_id . ':balance' );

if ( ! $lock->acquire( WP_Lock::WRITE ) ) {
	throw new RuntimeException( 'Could not acquire the balance lock.' );
}

try {
	$balance = (float) get_user_meta( $user_id, 'balance', true );
	update_user_meta( $user_id, 'balance', $balance + $topup );
} finally {
	$lock->release();
}
```

This balance sketch is **not a financial accounting design**. `get_user_meta()` may return metadata cached before the lock was acquired; use an application-controlled fresh read before relying on the value. Floating-point amounts can lose decimal precision; use an exact money representation. A lock does not make this write and a second metadata, ledger, or external update one transaction, fence a worker whose lease expired, or make a retried operation happen exactly once. Coordinate every writer on the same key, use database transactions or conditional writes where required, and give retries an application idempotency key.

The public signature is:

```php
public function acquire( $level = self::WRITE, $blocking = true, $expiration = 30 ): bool
```

An individual `WP_Lock` object is non-reentrant. Acquire it once, release it once, and use another object if a separate ownership lifecycle is needed.

### Lock levels

- `WP_Lock::READ` is shared with other readers but excludes writers.
- `WP_Lock::WRITE` is exclusive and is the default.

Only these exact integer constants are accepted. Numeric strings and other values throw `InvalidArgumentException`.

### Blocking and database retries

The bundled database backend uses a monotonic 30-second wait budget by default when `$blocking` is `true`. Contention polling and database-error retries share that budget. A zero budget or non-blocking call makes one database acquisition attempt without polling. A synchronous database call can finish after the budget, so this is not a hard wall-clock return limit. The wait budget is separate from the acquired lock's database-time TTL.

Failed acquisition queries are retried up to three times after the initial query when the wait budget permits. A persistent database error throws `RuntimeException`; contention itself returns `false` and is not treated as a database error.

If a committed acquisition finishes after a positive wait budget, the backend returns `false` only after confirming deletion of that exact owner. An unknown commit or failed cleanup raises `WP_Lock_Ownership_Uncertain`; keep the lock object and call `release()` to reconcile it.

The database backend settings can be customized explicitly:

```php
use iTRON\WP_Lock\WP_Lock;
use iTRON\WP_Lock\WP_Lock_Backend_DB;

// Use a 10-second wait budget and retry DB errors twice after the first attempt.
$backend = new WP_Lock_Backend_DB( 10.0, 2 );
$lock    = new WP_Lock( 'scheduled-import', $backend );
```

The timeout must be finite and non-negative (NaN and infinity are rejected); the retry count must be non-negative. These constructor settings apply to the bundled DB backend, not a required custom-backend policy.

Resource identity uses the MD5 of the **complete** string ID within the captured WordPress table-prefix namespace. The optional `original_key` diagnostic column stores the complete value only when it is representable in the column charset and fits 50 characters; otherwise it stores `NULL`, without changing identity. An MD5 collision can still make distinct IDs share one resource; `original_key` does not resolve collisions.

### Expiration

`$expiration` is the lifetime, in seconds, of a lock after it has been acquired. It is not an acquisition timeout.

- The default is 30 seconds.
- `0` means no TTL; the lock remains until explicitly released or manually recovered after stopping all participants.
- The value must be a non-negative integer.

Use `try`/`finally` and release every acquired lock. The primary database clock starts a finite lease after the resource row is locked. A successful `acquire()` means the lease had remaining TTL at the last database-time check after commit; it does not guarantee the lease will still exist after a later PHP pause. Choose a finite TTL longer than the maximum expected bounded duration of the protected work, with margin for scheduling and database delays. There is no lease renewal or fencing: expiry cannot stop a stalled caller's application writes. A later `release()` that observes expiry reports `WP_Lock_Ownership_Lost` and clears the confirmed handle.

If a TTL=0 owner survives its operation and cannot be reconciled with the same lock object, follow the [manual recovery procedure](docs/recovery.md) with an admissions barrier and verified owner termination. Failed visibility or PID/CID checks do not prove that a row is stale. The backend's expired-owner cleanup applies only to finite TTL rows.

### Checking lock existence

`lock_exists()` checks for an unexpired lock at the requested level without acquiring it:

```php
if ( $lock->lock_exists( WP_Lock::WRITE ) ) {
	// An exclusive lock currently exists.
}
```

The default level is `WP_Lock::WRITE`. Checking READ returns `true` for either a READ or WRITE lock; checking WRITE only returns `true` for a WRITE lock.

`lock_exists()` is only a point-in-time observation. Neither `true` nor `false` grants ownership or authorizes entry into protected work; call `acquire()` and require `true` for that.

## Exceptions

- `InvalidArgumentException` indicates an invalid resource identifier, backend, lock level, expiration, blocking timeout, or retry count.
- `LogicException` indicates lifecycle misuse, such as acquiring the same object twice or releasing an object that does not hold a lock.
- A plain `RuntimeException` from the bundled backend indicates a database failure, including a failed `lock_exists()` query. The wrapper also throws one when a custom backend returns `false` from `release()`. A failed release keeps the owner handle and held state for retry unless loss is confirmed.
- `WP_Lock_Ownership_Uncertain` indicates that an INSERT, COMMIT, release, or cleanup outcome could not be confirmed. An uncertain acquire does **not** grant permission to enter protected work. Retain the same lock object and call `release()` to reconcile its attempt; unresolved TTL=0 owners need manual recovery if reconciliation cannot complete. A failed or uncertain release retains retryable state.
- `WP_Lock_Ownership_Lost` indicates that a previously confirmed owner is gone or replaced. The wrapper clears that confirmed-lost handle and will not delete a successor.

## Custom backends

A custom backend must implement `iTRON\WP_Lock\WP_Lock_Backend`:

```php
interface WP_Lock_Backend {
	public function acquire( $id, $level, $blocking, $expiration ): bool;
	public function release( $id ): bool;
	public function exists( $id, $level ): bool;
}
```

The backend is supplied as the second `WP_Lock` constructor argument or through the `wp_lock_backend` filter. `WP_Lock::release()` returns `void`; a custom backend's `release()` must return `bool`. Its `false` makes the wrapper throw `RuntimeException` and retain held state for retry. Custom backends need not implement the bundled DB backend's named ownership exceptions or wait policy; they must exclude conflicting owners before reporting a grant.

## Migrating to 3.0

There is **no rolling upgrade or rollback** between the incompatible 2.0 and 3.0 database protocols. Before switching either way, enforce an external admissions barrier, stop and join every old-version request, cron, queue, and worker participant, and reconcile every owner on the writable primary. Finite owners may drain; TTL=0 and uncertain owners require explicit resolution, never a fixed 30-second sleep. Prepare and verify the additive schema before enabling 3.0; only then switch the protocol marker, restart one code version, smoke-test shared READ/exclusive WRITE, and lift the barrier. A failed or uncertain switch keeps admissions stopped until fresh primary verification. The [locally rehearsed operator procedure](docs/migration.md) gives the exact steps and [manual recovery](docs/recovery.md) covers unresolved owners. Rollback uses the same barrier; legacy 2.0 can grant conflicting owners under READ COMMITTED, so it does not preserve 3.0 safety.

## Migrating to 2.0

Version 2.0 contains intentional breaking changes:

- The `flock` backend and `WP_Lock_Backend_flock` class were removed. Use the bundled DB backend or provide a custom backend.
- `WP_Lock` is non-reentrant. Repeated acquire and unmatched release calls now throw `LogicException`.
- Resource IDs must be strings; levels must be the exact READ or WRITE constants; expiration must be a non-negative integer.
- Blocking acquisition is bounded to 30 seconds by default and can return `false` on timeout.
- Permanent database errors now throw `RuntimeException` after bounded retries.
- Custom backend `acquire()`, `release()`, and `exists()` methods must declare `bool` return types. `release()` must report success instead of returning `void`.
- The database schema is upgraded automatically and adds an index for `lock_key`.

Review code that assumed indefinite blocking, nested acquisition, implicit argument coercion, or a void custom-backend `release()` before upgrading. See [CHANGELOG.md](CHANGELOG.md) for the release summary.

## Origin

This project originated as a fork of [soulseekah/wp-lock](https://github.com/soulseekah/wp-lock) by Gennady Kovshenin and is now maintained as a standalone package.
