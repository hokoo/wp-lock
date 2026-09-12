<?php

namespace iTRON\WP_Lock;

use iTRON\WP_Lock\helpers\Database;

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
		if ( $blocking_timeout < 0 ) {
			throw new \InvalidArgumentException( 'The blocking timeout cannot be negative.' );
		}

		if ( $db_error_retries < 0 ) {
			throw new \InvalidArgumentException( 'The database error retry count cannot be negative.' );
		}

		$this->blocking_timeout = $blocking_timeout;
		$this->db_error_retries = $db_error_retries;

		if ( function_exists( 'get_option' ) && function_exists( 'update_option' ) ) {
			self::maybe_upgrade_schema();
		}
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

	public function drop_ghosts( $lock_id = null ): bool {
		global $wpdb;
		$ghosts = $this->get_ghosts( $lock_id );

		if ( ! empty( $ghosts ) ) {
			$deleted = $wpdb->query(
				"DELETE FROM {$this->get_table_name()} WHERE id IN (" . implode( ',', array_map( 'intval', array_column( $ghosts, 'id' ) ) ) . ')'
			);

			return false !== $deleted;
		}

		return false;
	}

	/**
	 * Ghost lock is a lock that has no corresponding process and/or connection and has no expiration time.
	 * Search for a ghost lock for specific lock_id in the database and remove it.
	 *
	 * @return array List of ghost locks.
	 */
	public function get_ghosts( $lock_id = null ): array {
		global $wpdb;

		// Get all expired locks if no lock_id is provided.
		$ids = null !== $lock_id ? $wpdb->prepare( ' AND `lock_key` = %s', $this->get_lock_key( $lock_id ) ) : '';

		$expired = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table_name()} WHERE 1=1 {$ids} AND `expire` <= %f",
				microtime( true )
			),
			ARRAY_A
		);

		if ( empty( $expired ) ) {
			return [];
		}

		// Following code supposes that there might be active locks with expiration field set 0.
		// Filter out locks that have a corresponding process. They are not ghosts.
		$expired = array_filter( $expired, function ( $lock ) {
			return ! ( empty( $lock['expire'] ) && ! empty( $lock['pid'] ) && file_exists( "/proc/{$lock['pid']}" ) );
		} );

		if ( empty( $expired ) ) {
			return [];
		}

		$cids = array_filter( array_map( 'intval', array_column( $expired, 'cid' ) ) );
		if ( empty( $cids ) ) {
			// Here we have only locks with no process ID and no connection ID. They are certainly ghosts.
			return $expired;
		}

		// Get active CIDs from the database to check whether the given connections are still alive or not.
		$active_cids = array_map(
			'intval',
			(array) $wpdb->get_col(
				'SELECT id FROM information_schema.processlist WHERE id IN (' . implode( ',', $cids ) . ')'
			)
		);

		$ghosts = array_filter( $expired, function ( $lock ) use ( $active_cids ) {
			// Throw out locks that have a corresponding connection. They are not ghosts.
			return ! (
				empty( $lock['expire'] ) &&
				! empty( $lock['cid'] ) &&
				in_array( (int) $lock['cid'], $active_cids, true )
			);
		} );

		return $ghosts;
	}

	/**
	 * @inheritDoc
	 */
	public function acquire( $id, $level, $blocking, $expiration = 0 ): bool {
		global $wpdb;

		$this->validate_lock_level( $level );
		$this->validate_expiration( $expiration );

		$lock_key = $this->get_lock_key( $id );
		if ( isset( $this->lock_ids[ $lock_key ] ) ) {
			throw new \LogicException( 'This backend instance already owns the requested resource.' );
		}

		$deadline                 = $blocking ? microtime( true ) + $this->blocking_timeout : 0.0;
		$schema_install_attempted = false;
		$ghost_retry_available    = true;
		$db_error_attempt         = 0;

		while ( true ) {
			$lock_level = WP_Lock::READ === $level ? ' AND `level` > %d' : '';
			$query      = "INSERT INTO {$this->get_table_name()} (`lock_key`, `original_key`, `level`, `pid`, `cid`, `expire`) " .
				"SELECT %s, %s, %d, %d, CONNECTION_ID(), %d FROM dual " .
				"WHERE NOT EXISTS (SELECT 1 FROM {$this->get_table_name()} WHERE `lock_key` = %s{$lock_level} " .
				'AND (`expire` = 0 OR `expire` >= %f))';
			$query_args = [
				$lock_key,
				$id,
				$level,
				getmypid(),
				$expiration ? $expiration + time() : 0,
				$lock_key,
			];

			if ( WP_Lock::READ === $level ) {
				$query_args[] = $level;
			}
			$query_args[] = microtime( true );

			$prepared_query  = call_user_func_array( [ $wpdb, 'prepare' ], array_merge( [ $query ], $query_args ) );
			$suppress_errors = $wpdb->suppress_errors( true );
			try {
				$acquired = $wpdb->query( $prepared_query );
				$db_error = $wpdb->last_error;
			} finally {
				$wpdb->suppress_errors( $suppress_errors );
			}

			if ( false === $acquired || ! empty( $db_error ) ) {
				if ( ! $schema_install_attempted && $this->is_missing_table_error( $db_error ) ) {
					$schema_install_attempted = true;
					$suppress_errors          = $wpdb->suppress_errors( true );
					try {
						self::maybe_upgrade_schema( true );
					} finally {
						$wpdb->suppress_errors( $suppress_errors );
					}
				}

				if ( $db_error_attempt >= $this->db_error_retries ) {
					throw new \RuntimeException(
						'Unable to acquire lock because of a database error: ' . ( $db_error ?: 'unknown database error' )
					);
				}

				$db_error_attempt++;
				usleep( min( self::POLL_INTERVAL_MICROSECONDS * $db_error_attempt, 100000 ) );
				continue;
			}

			$db_error_attempt = 0;
			if ( $acquired ) {
				$this->lock_ids[ $lock_key ] = $wpdb->insert_id;

				return true;
			}

			$dropped = $this->drop_ghosts( $id );
			if ( ! $blocking ) {
				if ( $dropped && $ghost_retry_available ) {
					$ghost_retry_available = false;
					continue;
				}

				return false;
			}

			if ( microtime( true ) >= $deadline ) {
				return false;
			}

			if ( ! $dropped ) {
				$remaining_microseconds = (int) max( 0, ( $deadline - microtime( true ) ) * 1000000 );
				usleep( min( self::POLL_INTERVAL_MICROSECONDS, $remaining_microseconds ) );
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function release( $id ): bool {
		global $wpdb;

		$lock_key = $this->get_lock_key( $id );
		if ( ! isset( $this->lock_ids[ $lock_key ] ) ) {
			// This lock is not acquired.
			return false;
		}

		$lock_id = $this->lock_ids[ $lock_key ];
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->get_table_name()} WHERE id = %d", $lock_id ) );
		if ( false === $deleted ) {
			return false;
		}

		unset( $this->lock_ids[ $lock_key ] );

		return true;
	}

	public function exists( $id, $level = WP_Lock::WRITE ): bool {
		global $wpdb;
		$this->validate_lock_level( $level );

		$lock_key = $this->get_lock_key( $id );
		$lock = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 1 FROM {$this->get_table_name()} WHERE `lock_key` = %s AND `level` >= %d " .
				'AND (`expire` = 0 OR `expire` >= %f)',
				$lock_key,
				$level,
				microtime( true )
			),
			ARRAY_A
		);

		return ! empty( $lock );
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
			expire int(10) unsigned DEFAULT NULL,
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
