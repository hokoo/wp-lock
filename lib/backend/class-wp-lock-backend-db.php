<?php

namespace iTRON\WP_Lock;

use iTRON\WP_Lock\helpers\Database;

class WP_Lock_Backend_DB implements WP_Lock_Backend {
	const TABLE_NAME = 'lock';

	/**
	 * @var string[] The locked storages.
	 *
	 * Format: [lock_key => lock_id]
	 */
	private array $lock_ids = [];

	/**
	 * Lock backend constructor.
	 */
	public function __construct() {
		// Drop ghost locks.
		$this->drop_ghosts();
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

	public function drop_ghosts( $lock_id = null ): bool {
		global $wpdb;
		$ghosts = $this->get_ghosts( $lock_id );

		if ( ! empty( $ghosts ) ) {
			$wpdb->query( "DELETE FROM {$this->get_table_name()} WHERE id IN (" . implode( ',', array_column( $ghosts, 'id' ) ) . ")" );
			return true;
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
		$ids = $lock_id ? $wpdb->prepare( " AND `lock_key` = %s", $this->get_lock_key( $lock_id ) ) : '';

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

		$cids = array_column( $expired, 'cid' );
		if ( empty( $cids ) ) {
			// Here we have only locks with no process ID and no connection ID. They are certainly ghosts.
			return $expired;
		}

		// Get active CIDs from the database to check whether the given connections are still alive or not.
		$active_cids = $wpdb->get_col(
			"SELECT id FROM information_schema.processlist WHERE id IN (" . implode( ',', $cids ) . ")"
		);

		$ghosts = array_filter( $expired, function ( $lock ) use ( $active_cids ) {
			// Throw out locks that have a corresponding connection. They are not ghosts.
			return ! (
				empty( $lock['expire'] ) &&
				! empty( $lock['cid'] ) &&
				in_array( $lock['cid'], $active_cids, true )
			);
		} );

		return $ghosts;
	}

	/**
	 * @inheritDoc
	 */
	public function acquire( $id, $level, $blocking, $expiration = 0 ): bool {
		global $wpdb;

		// Lock level policy. We can only acquire a lock if there are no write locks.
		$lock_level = ( $level == WP_Lock::READ ) ? " AND `level` > $level" : '';

		// Expired locks policy. We can only acquire a lock if there are no unexpired ones.
		$expired    = " AND (`expire` = 0 OR `expire` >= " . ( microtime( true ) ) . ")";

		$query      = "INSERT INTO {$this->get_table_name()} (`lock_key`, `original_key`, `level`, `pid`, `cid`, `expire`) " .
		              "SELECT '%s', '%s', '%s', '%s', '%s', '%s' FROM dual " .
		              "WHERE NOT EXISTS (SELECT 1 FROM {$this->get_table_name()} WHERE `lock_key` = '%s'{$lock_level}{$expired})";

		$attempt  = 0;
		$suppress = false;
		do {
			// Suppress errors on first attempt when the table does not exist, to avoid polluting the log with table creation errors.
			$attempt || $suppress = $wpdb->suppress_errors();

			$acquired = $wpdb->query(
				$wpdb->prepare(
					$query,
					$this->get_lock_key( $id ),
					$id,
					$level,
					getmypid(),
					$wpdb->get_var( "SELECT CONNECTION_ID()" ),
					$expiration ? $expiration + time() : 0,
					$this->get_lock_key( $id )
				)
			);
			$db_error = $wpdb->last_error;

			// Stop suppressing errors after first attempt.
			$attempt || $wpdb->suppress_errors( $suppress );

			if ( $db_error && ! $attempt ++ ) {
				// Maybe tables are not installed yet?
				self::install();
			}

		} while ( $db_error );

		// Acquiring is ok, return true.
		if ( $acquired ) {
			$this->lock_ids[ $this->get_lock_key( $id ) ] = $wpdb->insert_id;

			return ( bool ) $acquired;
		}

		// Maybe there are some ghost locks?
		$dropped = $this->drop_ghosts( $id );

		if ( ! $blocking && ! $dropped ) {
			// There were no ghost locks and the acquiring isn't blocking, so nothing to wait.
			return false;
		}

		// Run again immediately if there were ghost locks, otherwise wait spinning for the lock.
		$dropped || usleep( 5000 );

		return $this->acquire( $id, $level, $blocking, $expiration );
	}

	/**
	 * @inheritDoc
	 */
	public function release( $id ) {
		global $wpdb;

		$lock_key = $this->get_lock_key( $id );
		if ( ! isset( $this->lock_ids[ $lock_key ] ) ) {
			// This lock is not acquired.
			return false;
		}

		$lock_id = $this->lock_ids[ $lock_key ];
		unset( $this->lock_ids[ $lock_key ] );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->get_table_name()} WHERE id = %d", $lock_id ) );

		return true;
	}

	public function exists( $id, $level = 0 ): bool {
		global $wpdb;

		$lock_key = $this->get_lock_key( $id );

		// Expired locks policy. Check whether no unexpired locks exist.
		$expired    = " AND (`expire` = 0 OR `expire` >= " . ( microtime( true ) ) . ")";

		// Lock level policy. Check whether no locks with higher level exist.
		$lock_level = " AND `level` >= $level";

		$lock = $wpdb->get_row( "SELECT 1 FROM {$this->get_table_name()} WHERE `lock_key` = '$lock_key'{$lock_level}{$expired}", ARRAY_A );

		return ! empty( $lock );
	}

	static function install() {
		Database::install_table(
			self::TABLE_NAME,
                "`id`            INT(10)     UNSIGNED NOT NULL AUTO_INCREMENT,
                `lock_key`      VARCHAR(50) NULL DEFAULT NULL COLLATE 'utf8_general_ci',
	            `original_key`  VARCHAR(50) NULL DEFAULT NULL COLLATE 'utf8_general_ci',
                `level`         SMALLINT(5) UNSIGNED NULL DEFAULT NULL,
                `pid`           INT(10)     UNSIGNED NULL DEFAULT NULL,
                `cid`           INT(10)     UNSIGNED NULL DEFAULT NULL,
                `expire`        INT(10)     UNSIGNED NULL DEFAULT NULL,
                PRIMARY KEY (id) USING BTREE,
                INDEX `id` (`id`) USING BTREE,
                INDEX `level` (`level`) USING BTREE",
				['upgrade_method' => 'query']
		);
	}
}
