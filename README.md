# WP Lock

[![PHPUnit Tests](https://github.com/hokoo/wp-lock/actions/workflows/phpunit.yml/badge.svg)](https://github.com/hokoo/wp-lock/actions/workflows/phpunit.yml)

WP Lock provides shared READ locks and exclusive WRITE locks for WordPress. Version 2 uses a WordPress database table as its only bundled backend.

## Requirements

- PHP 7.4 or newer
- WordPress with a MySQL-compatible database

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

Always check the result of `acquire()`. A non-blocking acquire returns `false` immediately on contention, while a blocking acquire returns `false` when its backend wait deadline is reached.

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

The bundled database backend polls for at most 30 seconds by default when `$blocking` is `true`. This wait limit is separate from the acquired lock's expiration. Non-blocking acquisition does not wait.

Failed acquisition queries are retried up to three times after the initial query. A persistent database error throws `RuntimeException`; contention itself returns `false` and is not treated as a database error.

The database backend settings can be customized explicitly:

```php
use iTRON\WP_Lock\WP_Lock;
use iTRON\WP_Lock\WP_Lock_Backend_DB;

// Wait for at most 10 seconds and retry DB errors twice after the first attempt.
$backend = new WP_Lock_Backend_DB( 10.0, 2 );
$lock    = new WP_Lock( 'scheduled-import', $backend );
```

Both constructor values must be non-negative.

### Expiration

`$expiration` is the lifetime, in seconds, of a lock after it has been acquired. It is not an acquisition timeout.

- The default is 30 seconds.
- `0` means no TTL; the lock remains until explicitly released or later identified as a database ghost.
- The value must be a non-negative integer.

Use `try`/`finally` and release every acquired lock. Ghost cleanup is a recovery mechanism, not a substitute for deterministic release.

### Checking lock existence

`lock_exists()` checks for an unexpired lock at the requested level without acquiring it:

```php
if ( $lock->lock_exists( WP_Lock::WRITE ) ) {
	// An exclusive lock currently exists.
}
```

The default level is `WP_Lock::WRITE`. Checking READ returns `true` for either a READ or WRITE lock; checking WRITE only returns `true` for a WRITE lock.

## Exceptions

- `InvalidArgumentException` indicates an invalid resource identifier, backend, lock level, expiration, blocking timeout, or retry count.
- `LogicException` indicates lifecycle misuse, such as acquiring the same object twice or releasing an object that does not hold a lock.
- `RuntimeException` indicates a persistent database failure or a backend release failure. When release fails, the object remains in the held state so release can be retried.

## Custom backends

A custom backend must implement `iTRON\WP_Lock\WP_Lock_Backend`:

```php
interface WP_Lock_Backend {
	public function acquire( $id, $level, $blocking, $expiration ): bool;
	public function release( $id ): bool;
	public function exists( $id, $level ): bool;
}
```

The backend is supplied as the second `WP_Lock` constructor argument or through the `wp_lock_backend` filter. Returning `false` from `release()` causes `WP_Lock::release()` to throw `RuntimeException` and preserve its ownership state.

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
