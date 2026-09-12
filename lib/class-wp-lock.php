<?php
namespace iTRON\WP_Lock;

/**
 * The WP_Lock class.
 */
class WP_Lock {
	/**
	 * @var int A non-exclusive protected read lock.
	 * Other processes can read, but not write. A shared lock.
	 */
	const READ  = 8;

	/**
	 * @var int An exclusive write lock.
	 * Other processes can neither read, nor write.
	 */
	const WRITE = 32;


	/**
	 * @var string The lock identifier.
	 */
	public $id;

	/**
	 * @var WP_Lock_Backend The lock storage.
	 */
	private $lock_backend;

	/**
	 * @var bool Whether this instance currently holds a lock.
	 */
	private $held = false;

	/**
	 * Create a resource concurrency lock.
	 *
	 * @param string          $resource_id  The lock identifier. An arbitrary string.
	 * @param WP_Lock_Backend $lock_backend The lock storage provider/backend instance.
	 */
	public function __construct( $resource_id, $lock_backend = null ) {
		if ( ! is_string( $resource_id ) ) {
			throw new \InvalidArgumentException( 'The lock resource identifier must be a string.' );
		}

		$this->id = $resource_id;

		if ( is_null( $lock_backend ) ) {
			$lock_backend = new WP_Lock_Backend_DB();
		}

		/**
		 * Filter the lock backend used.
		 *
		 * @param WP_Lock_Backend|null $lock_backend The lock backend requested.
		 * @param string               $resource_id  The lock identifier. An arbitrary string.
		 */
		$lock_backend = apply_filters( 'wp_lock_backend', $lock_backend, $resource_id );

		if ( ! $lock_backend instanceof WP_Lock_Backend ) {
			throw new \InvalidArgumentException( 'The lock backend must implement WP_Lock_Backend.' );
		}

		$this->lock_backend = $lock_backend;

		register_shutdown_function( function( $lock ) {
			if ( $lock->held ) {
				trigger_error( 'Not all locks released for ' . $lock->id );
			}
		}, $this );
	}

	/**
	 * Acquire a lock.
	 *
	 * @todo Write more about locks and their dangers here.
	 *
	 * @param int  $level      Lock level. One of:
	 *                             WP_Lock::READ
	 *                             WP_Lock::WRITE
	 *                         Default: WP_Lock::WRITE
	 * @param bool $blocking   Whether acquiring the lock blocks or not. Default: true.
	 * @param int  $expiration Auto-release after $expiration seconds. Default: 30
	 *                         Setting this value to 0 can cause zombie locks that
	 *                         will linger forever (even across reboots) if you don't
	 *                         know what you are doing.
	 *
	 * @return bool Whether the lock has been acquired or not.
	 */
	public function acquire( $level = self::WRITE, $blocking = true, $expiration = 30 ): bool {
		$this->validate_lock_level( $level );
		$this->validate_expiration( $expiration );

		if ( $this->held ) {
			throw new \LogicException( 'This WP_Lock instance already holds a lock.' );
		}

		if ( ! $this->lock_backend->acquire( $this->id, $level, $blocking, $expiration ) ) {
			return false;
		}

		$this->held = true;

		return true;
	}

	/**
	 * Release a lock.
	 *
	 * @return void
	 */
	public function release(): void {
		if ( ! $this->held ) {
			throw new \LogicException( 'This WP_Lock instance does not hold a lock.' );
		}

		if ( ! $this->lock_backend->release( $this->id ) ) {
			throw new \RuntimeException( 'The backend failed to release the lock.' );
		}

		$this->held = false;
	}

	/**
	 * Check if a lock exists.
	 *
	 * @param int $level The lock level.
	 *
	 * @return bool Whether the lock exists or not.
	 */
	public function lock_exists( $level = self::WRITE ): bool {
		$this->validate_lock_level( $level );

		return $this->lock_backend->exists( $this->id, $level );
	}

	/**
	 * Validate a public lock level argument.
	 *
	 * @param int $level The lock level.
	 *
	 * @return void
	 */
	private function validate_lock_level( $level ): void {
		if ( ! in_array( $level, array( self::READ, self::WRITE ), true ) ) {
			throw new \InvalidArgumentException( 'Lock level must be WP_Lock::READ or WP_Lock::WRITE.' );
		}
	}

	/**
	 * Validate a public expiration argument.
	 *
	 * @param int $expiration Lock lifetime in seconds, or zero for no expiration.
	 *
	 * @return void
	 */
	private function validate_expiration( $expiration ): void {
		if ( ! is_int( $expiration ) || $expiration < 0 ) {
			throw new \InvalidArgumentException( 'Lock expiration must be a non-negative integer.' );
		}
	}
}
