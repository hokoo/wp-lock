# `WP Lock`
[![PHPUnit Tests](https://github.com/soulseekah/wp-lock/actions/workflows/phpunit.yml/badge.svg)](https://github.com/soulseekah/wp-lock/actions/workflows/phpunit.yml)

> This was previously a fork from original repo [soulseekah/wp-lock](https://github.com/soulseekah/wp-lock) by Gennady Kovshenin.
> Now here is the standalone repo with such changes as:
> - Custom database table is used as the WPDB locks' storage that allows to manage locks in simple and convenient way.
> - Fast: making one lock takes up to one single query instead of 11 and more.
> - Readable: the code is well documented and easy to understand.


## WP Lock's Preconditions

Consider the following user balance topup function that is susceptible to a race condition:

```php
// Top-up function that is not thread-safe
public function topup_user_balance( $user_id, $topup ) {
	$balance = get_user_meta( $user_id, 'balance', true );
	$balance = $balance + $topup;
	update_user_meta( $user_id, 'balance', $balance );
	return $balance;
}
```

Try to call the above code 100 times in 16 threads. The balance will be less than it is supposed to be.


The code below is thread safe.

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

## Usage

Require via Composer `composer require hokoo/wp-lock` in your plugin.

Don't forget to include the Composer autoloader in your plugin and declare using of the `WP_Lock` class.

```php
use iTRON\WP_Lock;
require 'vendor/autoload.php';
```
Acquire a read blocking lock without a timeout.

```php
$lock = new WP_Lock\WP_Lock( 'my-lock' );

$lock->acquire( WP_Lock::READ, true, 0 );
// do something, and then
$lock->release();
```

```php
public function acquire( $level = self::WRITE, $blocking = true, $expiration = 30 )
```

### Lock levels

- `WP_Lock::READ` - other processes can acquire READ but not WRITE until the original lock is released. A shared read lock.
- `WP_Lock::WRITE` (default) - other processes can't acquire READ or WRITE locks until the original lock is released. An exclusive read-write lock

### Blocking policy
* Blocking means that the process will wait here until the lock is obtained (5 microseconds spinlock).
* Non-blocking lock acquisition does not wait for the other locks to be released, but returns false immediately if the lock is already acquired by another process. So, it should be checked for success.

```php
if ( $lock->acquire( WP_Lock::READ, false, 0 ) ) {
    // do something, and then
    $lock->release();
}
```
### Timeout policy

* 0-second timeout means that the lock should be released, otherwise it will be released automatically after the php process is finished.
* Non-zero timeout means that the lock might not be released manually, and then it will be done automatically after the specified number of seconds.

## Lock Existence Check

You may also want to check if a lock is acquired by another process without actually trying to acquire it.

```php
$another_lock = new WP_Lock\WP_Lock( 'my-lock' );

if ( $another_lock->acquire( WP_Lock::READ, false, 0 ) ) {
    $lock->lock_exists( WP_Lock::READ ); // true
    $lock->lock_exists( WP_Lock::WRITE ); // false
    
    $another_lock->release();
}

if ( $another_lock->acquire( WP_Lock::WRITE, false, 0 ) ) {
    $lock->lock_exists( WP_Lock::READ ); // true
    $lock->lock_exists( WP_Lock::WRITE ); // true
    
    $another_lock->release();
}
```

## Caveats

In highly concurrent setups you may get Deadlock errors from MySQL. This is normal. The library handles these gracefully and retries the query as needed.
