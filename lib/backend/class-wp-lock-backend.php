<?php
namespace iTRON\WP_Lock;

/**
 * The lock backend interface.
 */
interface WP_Lock_Backend {
	/**
	 * Acquire a lock.
	 *
	 * @todo Write more about how to write a backend and atomicity.
	 *
	 * @param string $id         The resource identifier.
	 * @param int    $level      Lock level. One of:
	 *                             WP_Lock::READ
	 *                             WP_Lock::WRITE
	 * @param bool   $blocking   Whether acquiring the lock blocks or not.
	 * @param int    $expiration Auto-release after $expiration seconds.
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
