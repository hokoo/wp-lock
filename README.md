# `WP_Lock`

## because WordPress is not thread-safe

[![PHPUnit Tests](https://github.com/soulseekah/wp-lock/actions/workflows/phpunit.yml/badge.svg)](https://github.com/soulseekah/wp-lock/actions/workflows/phpunit.yml)

WordPress is no longer just a blogging platform. It's a framework. And like all mature frameworks it drastically needs a lock API.

## Example

Consider the following user balance topup function that is susceptible to a race condition:

```php
// topup function that is not thread-safe
public function topup_user_balance( $user_id, $topup ) {
	$balance = get_user_meta( $user_id, 'balance', true );
	$balance = $balance + $topup;
	update_user_meta( $user_id, 'balance', $balance );
	return $balance;
}
```

Try to call the above code 100 times in 16 threads. The balance will be less than it is supposed to be.

```php
// A thread-safe version of the above topup function.
public function topup_user_balance( $user_id, $topup ) {
	$user_balance_lock = new WP_Lock( "$user_id:meta:balance" );
	$user_balance_lock->acquire( WP_Lock::WRITE );

	$balance = get_user_meta( $user_id, 'balance', true );
	$balance = $balance + $topup;
	update_user_meta( $user_id, 'balance', $balance );

	$user_balance_lock->release();

	return $balance;
}
```

The above code is thread safe.

## Lock levels

- `WP_Lock::READ` - other processes can acquire READ but not WRITE until the original lock is released. A shared read lock.
- `WP_Lock::WRITE` (default) - other processes can't acquire READ or WRITE locks until the original lock is released. An exclusive read-write lock

## Usage

Require via Composer `composer require hokoo/wp-lock` in your plugin.

```php
use iTRON\WP_Lock;
require 'vendor/autoload.php';
```
Acquire a read blocking lock without a timeout.
* Blocking means that the process will wait here until the lock is obtained (5 microseconds spinlock).
* 0-second timeout means that the lock should be released, otherwise it will be released automatically after the php process is finished.
* Non-zero timeout means that the lock might not be released manually, and then it will be done automatically after the specified number of seconds.

```php
$lock = new WP_Lock\WP_Lock( 'my-lock' );

$lock->acquire( WP_Lock::READ, true, 0 );
// do something
$lock->release();
```
Non-blocking lock acquisition does not wait for the other locks to be released, but returns false immediately if the lock is already acquired by another process. So, it should be checked for success.

```php
if ( $lock->acquire( WP_Lock::READ, false, 0 ) ) {
    // do something
    $lock->release();
}
```

## Caveats

In highly concurrent setups you may get Deadlock errors from MySQL. This is normal. The library handles these gracefully and retries the query as needed.

This is a fork from original [soulseekah/wp-lock](https://github.com/soulseekah/wp-lock) with the following changes and advantages:
- Custom database table is used as the WPDB locks' storage.
- It allows manage locks in simple and convenient way.
- Fast: making one lock takes up to 2 queries instead of 11 or more.
- Readable: the code is well documented and easy to understand.