<?php
namespace iTRON\WP_Lock;

/**
 * The lock backend interface.
 */
interface WP_Lock_Backend {
	/**
	 * Acquire a lock.
	 *
	 * Atomically exclude conflicting owners before returning true: READ owners
	 * may coexist, while WRITE excludes both levels. Preserve the exact owner
	 * identity for release; bundled DB exception classes are not required.
	 *
	 * @param string $id         The resource identifier.
	 * @param int    $level      Lock level. One of:
	 *                             WP_Lock::READ
	 *                             WP_Lock::WRITE
	 * @param bool   $blocking   Whether acquiring the lock blocks or not.
	 * @param int    $expiration Lease TTL in seconds; zero means no expiry.
	 *
	 * @return bool Whether the lock has been acquired or not.
	 */
	public function acquire( $id, $level, $blocking, $expiration ): bool;

	/**
	 * Release a lock.
	 *
	 * @param string $id The resource identifier.
	 *
	 * @return bool Whether the lock was released.
	 */
	public function release( $id ): bool;

	public function exists( $id, $level ): bool;
}
