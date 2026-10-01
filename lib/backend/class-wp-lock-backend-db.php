<?php

namespace iTRON\WP_Lock;

use iTRON\WP_Lock\helpers\Database;

class WP_Lock_Ownership_Uncertain extends \RuntimeException {}
class WP_Lock_Ownership_Lost extends \RuntimeException {}

class WP_Lock_Backend_DB implements WP_Lock_Backend {
	const TABLE_NAME = 'lock';
	const SCHEMA_VERSION = '2.0.0';
	const SCHEMA_VERSION_OPTION = 'wp_lock_db_schema_version';
	const DEFAULT_BLOCKING_TIMEOUT = 30.0;
	const DEFAULT_DB_ERROR_RETRIES = 3;
	const POLL_INTERVAL_MICROSECONDS = 5000;

	/**
	 * @var string[] The locked storages.
	 *
	 * Format: [lock_key => lock_id]
	 */
	private array $lock_ids = [];
	private array $unresolved = [];

	/**
	 * @var float Maximum time a blocking acquire may wait, in seconds.
	 */
	private float $blocking_timeout;

	/**
	 * @var int Number of retries after a failed database query.
	 */
	private int $db_error_retries;

	/**
	 * Lock backend constructor.
	 *
	 * @param float $blocking_timeout Maximum blocking wait in seconds.
	 * @param int   $db_error_retries Number of retries after the initial failed query.
	 */
	public function __construct(
		float $blocking_timeout = self::DEFAULT_BLOCKING_TIMEOUT,
		int $db_error_retries = self::DEFAULT_DB_ERROR_RETRIES
	) {
		if ( ! is_finite( $blocking_timeout ) || $blocking_timeout < 0 ) {
			throw new \InvalidArgumentException( 'The blocking timeout must be finite and non-negative.' );
		}

		if ( $db_error_retries < 0 ) {
			throw new \InvalidArgumentException( 'The database error retry count cannot be negative.' );
		}

		$this->blocking_timeout = $blocking_timeout;
		$this->db_error_retries = $db_error_retries;
	}

	/**
	 * Return the lock store table name.
	 *
	 * @return string The database table name.
	 */
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Get key name for given resource ID.
	 *
	 * @private
	 *
	 * @param string $id The resource ID.
	 *
	 * @return string The key name.
	 */
	private function get_lock_key( string $id ): string {
		return md5( $id );
	}

	/** Store only a complete, representable diagnostic ID. The hash always uses the full ID. */
	private function get_original_key( string $id ): ?string {
		global $wpdb;

		if ( preg_match( '/\A[\x00-\x7f]*\z/D', $id ) ) {
			return strlen( $id ) <= 50 ? $id : null;
		}

		$charset = $wpdb->get_col_charset( $this->get_table_name(), 'original_key' );
		if ( ! in_array( $charset, array( 'utf8', 'utf8mb3', 'utf8mb4' ), true ) || ! preg_match( '//u', $id ) ) {
			return null;
		}

		if ( 'utf8mb4' !== $charset && preg_match( '/[\x{10000}-\x{10FFFF}]/u', $id ) ) {
			return null;
		}

		return preg_match_all( '/./us', $id ) <= 50 ? $id : null;
	}

	/**
	 * Ensure a caller supplied a supported lock level.
	 *
	 * @param int $level The requested lock level.
	 *
	 * @return void
	 */
	private function validate_lock_level( $level ): void {
		if ( WP_Lock::READ !== $level && WP_Lock::WRITE !== $level ) {
			throw new \InvalidArgumentException( 'Lock level must be WP_Lock::READ or WP_Lock::WRITE.' );
		}
	}

	/**
	 * Ensure a caller supplied a valid lock lifetime.
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

	/**
	 * Check whether a database error indicates that the lock table is absent.
	 *
	 * @param string $db_error The database error text.
	 *
	 * @return bool Whether the lock table needs to be installed.
	 */
	private function is_missing_table_error( string $db_error ): bool {
		return false !== stripos( $db_error, $this->get_table_name() ) &&
			( false !== stripos( $db_error, "doesn't exist" ) || false !== stripos( $db_error, 'does not exist' ) );
	}

	/** Remove only finite expired owners under their permanent resource row. */
	public function drop_ghosts( $lock_id = null ): bool {
		if ( null === $lock_id ) {
			return false;
		}
		$session = WP_Lock_Foundations::open();
		try {
			$session->begin_resource( $lock_id );
			$now = $session->now();
			$deleted = false;
			foreach ( $session->current_owners( $lock_id ) as $owner ) {
				if ( 0.0 !== (float) $owner['expire'] && (float) $owner['expire'] <= $now ) {
					$deleted = 1 === $session->delete_owner( $lock_id, (int) $owner['id'], $owner['attempt_token'] ) || $deleted;
				}
			}
			$session->commit();
			return $deleted;
		} catch ( \Throwable $error ) {
			throw new WP_Lock_Ownership_Uncertain( 'Expired-owner cleanup could not be confirmed.', 0, $error );
		} finally {
			$session->close();
		}
	}

	/** Return finite expired owners for diagnostics; TTL=0 requires manual recovery. */
	public function get_ghosts( $lock_id = null ): array {
		global $wpdb;
		$filter = null === $lock_id ? '' : $wpdb->prepare( ' AND lock_key = %s', $this->get_lock_key( $lock_id ) );
		$rows = $wpdb->get_results(
			"SELECT * FROM {$this->get_table_name()} WHERE expire > 0 AND expire <= UNIX_TIMESTAMP(NOW(6)){$filter}",
			ARRAY_A
		);
		if ( ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( 'Unable to inspect expired lock owners.' );
		}
		return (array) $rows;
	}

	/**
	 * @inheritDoc
	 */
	public function acquire( $id, $level, $blocking, $expiration = 0 ): bool {
		$this->validate_lock_level( $level );
		$this->validate_expiration( $expiration );
		$key = $this->get_lock_key( $id );
		if ( isset( $this->lock_ids[ $key ] ) ) {
			throw new \LogicException( 'This backend instance already owns the requested resource.' );
		}
		if ( isset( $this->unresolved[ $key ] ) ) {
			$this->reconcile( $id, $key );
		}

		$started = hrtime( true );
		$deadline = $started + (int) min( PHP_INT_MAX - $started, $this->blocking_timeout * 1000000000 );
		$errors = 0;
		$attempted = false;
		while ( true ) {
			if ( $attempted && ( ! $blocking || hrtime( true ) >= $deadline ) ) {
				return false;
			}
			$attempted = true;
			$session = null;
			$token = null;
			$commit_started = false;
			try {
				$session = WP_Lock_Foundations::open();
				$namespace = WP_Lock_Foundations::namespace();
				$session->begin_resource( $id );
				$now = $session->now();
				$conflict = false;
				foreach ( $session->current_owners( $id ) as $owner ) {
					if ( 0.0 !== (float) $owner['expire'] && (float) $owner['expire'] <= $now ) {
						$session->delete_owner( $id, (int) $owner['id'], $owner['attempt_token'] );
					} elseif ( WP_Lock::WRITE === $level || WP_Lock::WRITE === (int) $owner['level'] ) {
						$conflict = true;
					}
				}
				if ( $conflict ) {
					$session->rollback();
					if ( ! $blocking || hrtime( true ) >= $deadline ) {
						return false;
					}
					usleep( min( self::POLL_INTERVAL_MICROSECONDS, (int) max( 0, ( $deadline - hrtime( true ) ) / 1000 ) ) );
					continue;
				}
				$token = WP_Lock_Foundations::new_token();
				$expire = $expiration ? $session->now() + $expiration : 0.0;
				$owner_id = $session->insert_owner( $id, $level, $token, $expire, $this->get_original_key( $id ) );
				$commit_started = true;
				$session->commit();
				// A committed owner is retained until its exact cleanup is confirmed.
				$this->unresolved[ $key ] = array( 'namespace' => $namespace, 'token' => $token );
				if ( ( $expiration && $session->now() >= $expire ) || ( $blocking && $this->blocking_timeout > 0 && hrtime( true ) >= $deadline ) ) {
					$this->reconcile( $id, $key );
					return false;
				}
				unset( $this->unresolved[ $key ] );
				$this->lock_ids[ $key ] = array( 'id' => $owner_id, 'token' => $token, 'namespace' => $namespace );
				return true;
			} catch ( WP_Lock_Ownership_Uncertain $error ) {
				if ( null !== $token ) {
					$this->unresolved[ $key ] = array( 'namespace' => $namespace, 'token' => $token );
				}
				throw $error;
			} catch ( \Throwable $error ) {
				if ( null !== $token && $commit_started ) {
					$this->unresolved[ $key ] = array( 'namespace' => $namespace, 'token' => $token );
					throw new WP_Lock_Ownership_Uncertain( 'Lock commit or confirmation is uncertain.', 0, $error );
				}
				if ( null !== $session ) {
					try {
						$session->rollback();
					} catch ( \LogicException $rollback_error ) {
						// START TRANSACTION failed before a transaction existed.
					} catch ( \Throwable $rollback_error ) {
						if ( null !== $token ) {
							$this->unresolved[ $key ] = array( 'namespace' => $namespace, 'token' => $token );
						}
						throw new WP_Lock_Ownership_Uncertain( 'Lock rollback could not be confirmed.', 0, $rollback_error );
					}
				}
				if ( $error instanceof \InvalidArgumentException || $error instanceof \LogicException ) {
					throw $error;
				}
				if ( $errors++ >= $this->db_error_retries || ! $blocking || hrtime( true ) >= $deadline ) {
					throw new \RuntimeException( 'Unable to acquire lock because of a database error.', 0, $error );
				}
				usleep( min( self::POLL_INTERVAL_MICROSECONDS * $errors, 100000 ) );
			} finally {
				if ( null !== $session ) {
					$session->close();
				}
			}
		}
	}

	/** Remove an unresolved attempt before the wrapper can try a new owner. */
	private function reconcile( string $id, string $key ): void {
		$attempt = $this->unresolved[ $key ];
		$session = null;
		try {
			$session = WP_Lock_Foundations::open( $attempt['namespace'] );
			$session->begin_resource( $id );
			$owners = $session->find_attempt( $id, $attempt['token'] );
			if ( $owners ) {
				if ( 1 !== $session->delete_owner( $id, (int) $owners[0]['id'], $attempt['token'] ) ) {
					throw new \RuntimeException( 'The unresolved lock owner could not be deleted.' );
				}
			}
			$session->commit();
			unset( $this->unresolved[ $key ] );
		} catch ( \Throwable $error ) {
			throw new WP_Lock_Ownership_Uncertain( 'Unresolved lock attempt could not be reconciled.', 0, $error );
		} finally {
			if ( null !== $session ) {
				$session->close();
			}
		}
	}

	/** @inheritDoc */
	public function release( $id ): bool {
		$key = $this->get_lock_key( $id );
		if ( isset( $this->unresolved[ $key ] ) ) {
			$this->reconcile( $id, $key );
			return true;
		}
		if ( ! isset( $this->lock_ids[ $key ] ) ) {
			return false;
		}
		$owner = $this->lock_ids[ $key ];
		$retries = 0;
		while ( true ) {
			$session = WP_Lock_Foundations::open( $owner['namespace'] );
			$commit_started = false;
			try {
				$session->begin_resource( $id );
				$found = $session->find_attempt( $id, $owner['token'] );
				if ( ! $found || (int) $found[0]['id'] !== $owner['id'] ) {
					$session->rollback();
					if ( ! empty( $owner['release_uncertain'] ) ) {
						unset( $this->lock_ids[ $key ] );
						return true;
					}
					unset( $this->lock_ids[ $key ] );
					throw new WP_Lock_Ownership_Lost( 'The recorded lock owner no longer exists.' );
				}
				$deleted = $session->delete_owner( $id, $owner['id'], $owner['token'] );
				if ( 1 !== $deleted ) {
					throw new \RuntimeException( 'The recorded lock owner could not be deleted.' );
				}
				$this->lock_ids[ $key ]['release_uncertain'] = true;
				$commit_started = true;
				$session->commit();
				unset( $this->lock_ids[ $key ] );
				return true;
			} catch ( WP_Lock_Ownership_Lost $error ) {
				throw $error;
			} catch ( \Throwable $error ) {
				if ( ! $commit_started && $error instanceof WP_Lock_Foundation_SQL_Error &&
					in_array( $error->getCode(), array( 1205, 1213 ), true ) ) {
					try {
						$session->rollback();
					} catch ( \Throwable $rollback_error ) {
						throw new WP_Lock_Ownership_Uncertain( 'Lock release rollback could not be confirmed.', 0, $rollback_error );
					}
					if ( $retries++ < $this->db_error_retries ) {
						continue;
					}
					throw new \RuntimeException( 'Lock release failed after database retries.', 0, $error );
				}
				throw new WP_Lock_Ownership_Uncertain( 'Lock release could not be confirmed.', 0, $error );
			} finally {
				$session->close();
			}
		}
	}

	public function has_unresolved( $id ): bool {
		return isset( $this->unresolved[ $this->get_lock_key( $id ) ] );
	}

	public function exists( $id, $level = WP_Lock::WRITE ): bool {
		$this->validate_lock_level( $level );
		$session = WP_Lock_Foundations::open();
		try {
			return $session->exists( $id, $level );
		} finally {
			$session->close();
		}
	}

	/**
	 * Install or upgrade the lock table schema.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		Database::install_table(
			self::TABLE_NAME,
			"id int(10) unsigned NOT NULL AUTO_INCREMENT,
			lock_key varchar(50) DEFAULT NULL,
			original_key varchar(50) DEFAULT NULL,
			level smallint(5) unsigned DEFAULT NULL,
			pid int(10) unsigned DEFAULT NULL,
			cid int(10) unsigned DEFAULT NULL,
			expire decimal(16,6) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY lock_key (lock_key),
			KEY level (level)",
			[ 'upgrade_method' => 'dbDelta' ]
		);

		$table_name = $wpdb->prefix . self::TABLE_NAME;
		if ( self::has_index( 'id' ) ) {
			$wpdb->query( "ALTER TABLE `{$table_name}` DROP INDEX `id`" );
		}
	}

	/**
	 * Install the current schema and record the version only after verification.
	 *
	 * @param bool $force Whether to install even when the stored version is current.
	 *
	 * @return bool Whether the schema was installed and verified.
	 */
	public static function maybe_upgrade_schema( bool $force = false ): bool {
		$can_track_version = function_exists( 'get_option' ) && function_exists( 'update_option' );
		if ( ! $force && $can_track_version && self::SCHEMA_VERSION === get_option( self::SCHEMA_VERSION_OPTION ) ) {
			return false;
		}

		self::install();
		if ( ! self::has_index( 'lock_key' ) ) {
			return false;
		}

		if ( $can_track_version ) {
			update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
		}

		return true;
	}

	/**
	 * Check whether the lock table contains a named index.
	 *
	 * @param string $index_name Index name.
	 *
	 * @return bool Whether the index exists.
	 */
	private static function has_index( string $index_name ): bool {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.statistics
				WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s",
				$table_name,
				$index_name
			)
		);
	}
}
