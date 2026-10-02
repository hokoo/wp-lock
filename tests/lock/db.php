<?php

use iTRON\WP_Lock\helpers\Database;
use iTRON\WP_Lock\WP_Lock;
use iTRON\WP_Lock\WP_Lock_Backend_DB;
use iTRON\WP_Lock\WP_Lock_Foundations;
use iTRON\WP_Lock\WP_Lock_Ownership_Lost;
use iTRON\WP_Lock\WP_Lock_Ownership_Uncertain;

class WP_Lock_Backend_DB_UnitTestCase extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->disable_wordpress_test_transaction();

		Database::register_table( WP_Lock_Backend_DB::TABLE_NAME );
		WP_Lock_Backend_DB::maybe_upgrade_schema( true );
		WP_Lock_Foundations::prepare_schema();
		$this->delete_all_locks();
		WP_Lock_Foundations::switch_protocol( WP_Lock_Foundations::PROTOCOL_VERSION );
	}

	protected function tearDown(): void {
		$this->delete_all_locks();

		parent::tearDown();
	}

	/**
	 * Use the real lock table. WordPress rewrites CREATE TABLE to CREATE
	 * TEMPORARY TABLE in its default transaction, but MySQL cannot reference a
	 * temporary table twice in the atomic INSERT ... SELECT acquisition query.
	 */
	private function disable_wordpress_test_transaction(): void {
		global $wpdb;

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$wpdb->query( 'ROLLBACK' );
		$wpdb->query( 'SET autocommit = 1' );
	}

	private function delete_all_locks(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . WP_Lock_Backend_DB::TABLE_NAME;
		$suppress_errors = $wpdb->suppress_errors( true );
		$wpdb->query( "DELETE FROM `{$table_name}`" );
		$wpdb->suppress_errors( $suppress_errors );
	}

	private function index_exists( string $index_name ): bool {
		global $wpdb;

		$table_name = $wpdb->prefix . WP_Lock_Backend_DB::TABLE_NAME;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.statistics
				WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
				$table_name,
				$index_name
			)
		);
	}

	private function count_owners( string $id ): int {
		global $wpdb;

		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$wpdb->prefix}lock` WHERE lock_key = %s",
			md5( $id )
		) );
		$this->assertSame( '', $wpdb->last_error );
		return (int) $count;
	}

	/** Replace one matching statement while leaving later retries and cleanup intact. */
	private function fail_query_once( string $needle, bool &$injected ): callable {
		$filter = function( $query ) use ( $needle, &$injected, &$filter ) {
			if ( ( 'COMMIT' === $needle && 'COMMIT' === trim( $query ) ) ||
				( 'COMMIT' !== $needle && false !== strpos( $query, $needle ) ) ) {
				remove_filter( 'query', $filter );
				$injected = true;
				return 'SELECT * FROM wp_lock_missing_injected_table';
			}
			return $query;
		};
		add_filter( 'query', $filter );
		return $filter;
	}

	private function zero_delete_once( string $id, bool &$injected ): callable {
		$filter = function( $query ) use ( $id, &$injected, &$filter ) {
			if ( false !== strpos( $query, 'DELETE FROM `' ) && false !== strpos( $query, md5( $id ) ) ) {
				remove_filter( 'query', $filter );
				$injected = true;
				return $query . ' AND id = 0';
			}
			return $query;
		};
		add_filter( 'query', $filter );
		return $filter;
	}

	/** Raise a driver-visible deadlock code for the first exact owner DELETE. */
	private function deadlock_delete_once( string $id, array &$attempts ): callable {
		$filter = function( $query ) use ( $id, &$attempts ) {
			if ( false !== strpos( $query, 'DELETE FROM `' ) && false !== strpos( $query, md5( $id ) ) ) {
				$attempts[] = $query;
				if ( 1 === count( $attempts ) ) {
					return "SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'injected deadlock'";
				}
			}
			return $query;
		};
		add_filter( 'query', $filter );
		return $filter;
	}

	/**
	 * @dataProvider invalid_constructor_arguments
	 */
	public function test_constructor_rejects_negative_limits( float $timeout, int $retries ): void {
		$this->expectException( InvalidArgumentException::class );

		new WP_Lock_Backend_DB( $timeout, $retries );
	}

	public function invalid_constructor_arguments(): array {
		return array(
			'negative timeout' => array( -0.01, 0 ),
			'NaN timeout' => array( NAN, 0 ),
			'infinite timeout' => array( INF, 0 ),
			'negative infinite timeout' => array( -INF, 0 ),
			'negative retries' => array( 0.01, -1 ),
		);
	}

	/**
	 * @dataProvider invalid_lock_levels
	 */
	public function test_backend_rejects_invalid_lock_levels( $level ): void {
		$backend = new WP_Lock_Backend_DB();

		try {
			$backend->acquire( 'invalid-level', $level, false, 0 );
			$this->fail( 'Expected invalid acquire level to throw.' );
		} catch ( InvalidArgumentException $exception ) {
			$this->assertFalse( $backend->release( 'invalid-level' ) );
		}

		$this->expectException( InvalidArgumentException::class );
		$backend->exists( 'invalid-level', $level );
	}

	public function invalid_lock_levels(): array {
		return array(
			'zero'           => array( 0 ),
			'unknown integer' => array( 16 ),
			'numeric string' => array( (string) WP_Lock::WRITE ),
			'null'           => array( null ),
		);
	}

	/**
	 * @dataProvider invalid_expirations
	 */
	public function test_backend_rejects_invalid_expiration( $expiration ): void {
		$backend = new WP_Lock_Backend_DB();

		try {
			$backend->acquire( 'invalid-expiration', WP_Lock::WRITE, false, $expiration );
			$this->fail( 'Expected invalid expiration to throw.' );
		} catch ( InvalidArgumentException $exception ) {
			$this->assertFalse( $backend->release( 'invalid-expiration' ) );
		}
	}

	public function invalid_expirations(): array {
		return array(
			'negative'       => array( -1 ),
			'numeric string' => array( '30' ),
			'float'          => array( 1.5 ),
			'null'           => array( null ),
		);
	}

	public function test_backend_rejects_repeated_acquire_without_losing_ownership(): void {
		$backend = new WP_Lock_Backend_DB();
		$this->assertTrue( $backend->acquire( 'repeated', WP_Lock::WRITE, false, 0 ) );

		try {
			$backend->acquire( 'repeated', WP_Lock::WRITE, false, 0 );
			$this->fail( 'Expected repeated backend acquire to throw.' );
		} catch ( LogicException $exception ) {
			$this->assertTrue( $backend->exists( 'repeated', WP_Lock::WRITE ) );
		} finally {
			$this->assertTrue( $backend->release( 'repeated' ) );
		}
	}

	public function test_blocking_acquire_has_a_bounded_timeout(): void {
		$resource_id = uniqid( 'bounded_', true );
		$owner       = new WP_Lock_Backend_DB();
		$waiter      = new WP_Lock_Backend_DB( 0.03 );

		$this->assertTrue( $owner->acquire( $resource_id, WP_Lock::WRITE, false, 0 ) );
		$started = microtime( true );

		try {
			$this->assertFalse( $waiter->acquire( $resource_id, WP_Lock::WRITE, true, 0 ) );
			$elapsed = microtime( true ) - $started;
			$this->assertGreaterThanOrEqual( 0.02, $elapsed );
			$this->assertLessThan( 1.0, $elapsed );
		} finally {
			$this->assertTrue( $owner->release( $resource_id ) );
		}
	}

	public function test_release_only_succeeds_for_owned_lock(): void {
		$resource_id = uniqid( 'release_', true );
		$owner       = new WP_Lock_Backend_DB();
		$other       = new WP_Lock_Backend_DB();

		$this->assertFalse( $owner->release( $resource_id ) );
		$this->assertTrue( $owner->acquire( $resource_id, WP_Lock::WRITE, false, 0 ) );
		$this->assertFalse( $other->release( $resource_id ) );
		$this->assertTrue( $owner->exists( $resource_id, WP_Lock::WRITE ) );
		$this->assertTrue( $owner->release( $resource_id ) );
		$this->assertFalse( $owner->release( $resource_id ) );
		$this->assertFalse( $owner->exists( $resource_id, WP_Lock::WRITE ) );
	}

	public function test_caller_rollback_does_not_remove_owner_or_commit_caller_work(): void {
		global $wpdb;

		$id = uniqid( 'outer_', true );
		$option = uniqid( 'wp_lock_outer_', true );
		$lock = new WP_Lock( $id );
		$acquired = false;
		try {
			$this->assertFalse( false === $wpdb->query( 'START TRANSACTION' ) );
			$this->assertSame( 1, $wpdb->query( $wpdb->prepare(
				"INSERT INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$option, 'uncommitted'
			) ) );
			$acquired = $lock->acquire( WP_Lock::WRITE, false, 0 );
			$this->assertTrue( $acquired );
			$this->assertFalse( false === $wpdb->query( 'ROLLBACK' ) );
			$this->assertSame( 1, $this->count_owners( $id ) );
			$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->options}` WHERE option_name = %s", $option
			) ) );
		} finally {
			$wpdb->query( 'ROLLBACK' );
			if ( $acquired ) {
				$lock->release();
			}
		}
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_release_uses_namespace_captured_at_acquisition(): void {
		global $wpdb;

		$id = uniqid( 'namespace_', true );
		$prefix = $wpdb->prefix;
		$lock = new WP_Lock( $id );
		$this->assertTrue( $lock->acquire( WP_Lock::WRITE, false, 0 ) );
		try {
			$wpdb->prefix = 'other_';
			$lock->release();
		} finally {
			$wpdb->prefix = $prefix;
		}
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_failed_exists_select_throws_instead_of_reporting_absence(): void {
		$id = uniqid( 'exists_error_', true );
		$lock = new WP_Lock( $id );
		$injected = false;
		$this->assertFalse( $lock->lock_exists() );
		$this->assertTrue( $lock->acquire( WP_Lock::WRITE, false, 0 ) );
		$filter = $this->fail_query_once( 'SELECT 1 FROM `', $injected );
		try {
			try {
				$lock->lock_exists();
				$this->fail( 'A failed owner SELECT must throw.' );
			} catch ( RuntimeException $error ) {
				$this->assertStringContainsString( 'database error', $error->getMessage() );
			}
		} finally {
			remove_filter( 'query', $filter );
			$lock->release();
		}
		$this->assertTrue( $injected );
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_owner_select_error_does_not_look_like_contention(): void {
		$id = uniqid( 'owner_error_', true );
		$backend = new WP_Lock_Backend_DB( 0, 0 );
		$injected = false;
		$filter = $this->fail_query_once( 'SELECT id, level, expire, attempt_token FROM `', $injected );
		try {
			$backend->acquire( $id, WP_Lock::WRITE, false, 0 );
			$this->fail( 'A failed owner SELECT must not return a grant.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'database error', $error->getMessage() );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertTrue( $injected );
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_unverifiable_caller_route_does_not_commit_caller_work(): void {
		global $wpdb;

		$id = uniqid( 'route_error_', true );
		$option = uniqid( 'wp_lock_route_', true );
		$backend = new WP_Lock_Backend_DB( 0, 0 );
		$injected = false;
		$this->assertFalse( false === $wpdb->query( 'START TRANSACTION' ) );
		try {
			$this->assertSame( 1, $wpdb->query( $wpdb->prepare(
				"INSERT INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$option, 'uncommitted'
			) ) );
			$filter = $this->fail_query_once( 'SELECT DATABASE() AS database_name', $injected );
			try {
				$backend->acquire( $id, WP_Lock::WRITE, false, 0 );
				$this->fail( 'An unverifiable route must not grant ownership.' );
			} catch ( RuntimeException $error ) {
				$this->assertStringContainsString( 'database error', $error->getMessage() );
			} finally {
				remove_filter( 'query', $filter );
			}
			$this->assertTrue( $injected );
			$this->assertFalse( false === $wpdb->query( 'ROLLBACK' ) );
			$this->assertSame( 0, $this->count_owners( $id ) );
			$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->options}` WHERE option_name = %s", $option
			) ) );
		} finally {
			$wpdb->query( 'ROLLBACK' );
		}
	}

	public function test_read_only_caller_route_refuses_acquisition_without_touching_caller_transaction(): void {
		global $wpdb;

		$id = uniqid( 'read_only_route_', true );
		$option = uniqid( 'wp_lock_route_', true );
		$injected = false;
		$filter = function( $query ) use ( &$injected, &$filter ) {
			if ( false !== strpos( $query, '@@read_only AS read_only' ) ) {
				remove_filter( 'query', $filter );
				$injected = true;
				return str_replace( '@@read_only AS read_only', '1 AS read_only', $query );
			}
			return $query;
		};

		$this->assertFalse( false === $wpdb->query( 'START TRANSACTION' ) );
		try {
			$this->assertSame( 1, $wpdb->query( $wpdb->prepare(
				"INSERT INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$option, 'uncommitted'
			) ) );
			add_filter( 'query', $filter );
			try {
				( new WP_Lock_Backend_DB( 0, 0 ) )->acquire( $id, WP_Lock::WRITE, false, 0 );
				$this->fail( 'A read-only caller route cannot establish a writable primary.' );
			} catch ( RuntimeException $error ) {
				$this->assertStringContainsString( 'database error', $error->getMessage() );
			} finally {
				remove_filter( 'query', $filter );
			}
			$this->assertTrue( $injected );
			$this->assertFalse( false === $wpdb->query( 'ROLLBACK' ) );
			$this->assertSame( 0, $this->count_owners( $id ) );
			$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->options}` WHERE option_name = %s", $option
			) ) );
		} finally {
			$wpdb->query( 'ROLLBACK' );
		}
	}

	/**
	 * @dataProvider changed_foundation_routes
	 */
	public function test_open_foundation_rejects_changed_route_before_reporting_exists( string $needle, string $replacement ): void {
		$id = uniqid( 'changed_route_', true );
		$owner = new WP_Lock( $id );
		$this->assertTrue( $owner->acquire( WP_Lock::WRITE, false, 0 ) );
		$session = WP_Lock_Foundations::open();
		$this->assertGreaterThan( 0, $session->connection_id() );
		$injected = false;
		$filter = function( $query ) use ( $needle, $replacement, &$injected, &$filter ) {
			if ( false !== strpos( $query, $needle ) ) {
				remove_filter( 'query', $filter );
				$injected = true;
				return str_replace( $needle, $replacement, $query );
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$session->exists( $id, WP_Lock::WRITE );
			$this->fail( 'A changed session route cannot confirm lock existence.' );
		} catch ( WP_Lock_Ownership_Uncertain $error ) {
			$this->assertTrue( $injected );
		} finally {
			remove_filter( 'query', $filter );
			$session->close();
			$owner->release();
		}
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function changed_foundation_routes(): array {
		return array(
			'new connection' => array( 'CONNECTION_ID() AS connection_id', 'CONNECTION_ID() + 1 AS connection_id' ),
			'became read-only' => array( '@@read_only AS read_only', '1 AS read_only' ),
		);
	}

	public function test_open_foundation_treats_lost_route_check_as_uncertain(): void {
		$id = uniqid( 'lost_route_', true );
		$owner = new WP_Lock( $id );
		$this->assertTrue( $owner->acquire( WP_Lock::WRITE, false, 0 ) );
		$session = WP_Lock_Foundations::open();
		$injected = false;
		$filter = $this->fail_query_once( 'SELECT DATABASE() AS database_name', $injected );
		try {
			$session->exists( $id, WP_Lock::WRITE );
			$this->fail( 'A lost route check cannot confirm lock existence.' );
		} catch ( WP_Lock_Ownership_Uncertain $error ) {
			$this->assertTrue( $injected );
		} finally {
			remove_filter( 'query', $filter );
			$session->close();
			$owner->release();
		}
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_failed_public_release_retains_exact_owner_and_held_state_for_retry(): void {
		$id = uniqid( 'release_error_', true );
		$lock = new WP_Lock( $id );
		$injected = false;
		$this->assertTrue( $lock->acquire( WP_Lock::WRITE, false, 0 ) );
		$filter = $this->fail_query_once( 'DELETE FROM `', $injected );
		try {
			try {
				$lock->release();
				$this->fail( 'A failed owner DELETE must not release the wrapper.' );
			} catch ( WP_Lock_Ownership_Uncertain $error ) {
				$this->assertTrue( $injected );
			}
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertSame( 1, $this->count_owners( $id ) );
		try {
			$lock->acquire( WP_Lock::WRITE, false, 0 );
			$this->fail( 'Failed release must leave the wrapper held.' );
		} catch ( LogicException $error ) {
			$this->assertSame( 1, $this->count_owners( $id ) );
		} finally {
			$lock->release();
		}
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_uncertain_release_commit_retains_retryable_wrapper_owner(): void {
		$id = uniqid( 'release_commit_', true );
		$lock = new WP_Lock( $id );
		$injected = false;
		$this->assertTrue( $lock->acquire( WP_Lock::WRITE, false, 0 ) );
		$filter = $this->fail_query_once( 'COMMIT', $injected );
		try {
			$lock->release();
			$this->fail( 'A failed release COMMIT cannot confirm release.' );
		} catch ( WP_Lock_Ownership_Uncertain $error ) {
			$this->assertTrue( $injected );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertSame( 1, $this->count_owners( $id ) );
		$lock->release();
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_public_release_retries_deadlock_against_the_same_owner(): void {
		global $wpdb;

		$id = uniqid( 'release_deadlock_', true );
		$lock = new WP_Lock( $id, new WP_Lock_Backend_DB( 0, 1 ) );
		$this->assertTrue( $lock->acquire( WP_Lock::WRITE, false, 0 ) );
		$owner = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, attempt_token FROM `{$wpdb->prefix}lock` WHERE lock_key = %s", md5( $id )
		), ARRAY_A );
		$this->assertSame( '', $wpdb->last_error );
		$this->assertNotNull( $owner );
		$attempts = array();
		$filter = $this->deadlock_delete_once( $id, $attempts );
		try {
			$lock->release();
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertCount( 2, $attempts );
		$this->assertSame( $attempts[0], $attempts[1] );
		$this->assertStringContainsString( 'id = ' . $owner['id'], $attempts[1] );
		$this->assertStringContainsString( $owner['attempt_token'], $attempts[1] );
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_exhausted_release_deadlock_retains_exact_owner_for_retry(): void {
		global $wpdb;

		$id = uniqid( 'release_deadlock_limit_', true );
		$lock = new WP_Lock( $id, new WP_Lock_Backend_DB( 0, 0 ) );
		$this->assertTrue( $lock->acquire( WP_Lock::WRITE, false, 0 ) );
		$owner = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, attempt_token FROM `{$wpdb->prefix}lock` WHERE lock_key = %s", md5( $id )
		), ARRAY_A );
		$this->assertSame( '', $wpdb->last_error );
		$this->assertNotNull( $owner );
		$attempts = array();
		$filter = $this->deadlock_delete_once( $id, $attempts );
		try {
			$lock->release();
			$this->fail( 'An exhausted deadlock retry cannot confirm release.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'after database retries', $error->getMessage() );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertCount( 1, $attempts );
		$this->assertSame( 1, $this->count_owners( $id ) );
		$this->assertSame( $owner, $wpdb->get_row( $wpdb->prepare(
			"SELECT id, attempt_token FROM `{$wpdb->prefix}lock` WHERE lock_key = %s", md5( $id )
		), ARRAY_A ) );
		$lock->release();
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_release_deadlock_with_failed_rollback_keeps_owner_uncertain(): void {
		global $wpdb;

		$id = uniqid( 'release_rollback_', true );
		$lock = new WP_Lock( $id, new WP_Lock_Backend_DB( 0, 1 ) );
		$this->assertTrue( $lock->acquire( WP_Lock::WRITE, false, 0 ) );
		$owner = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, attempt_token FROM `{$wpdb->prefix}lock` WHERE lock_key = %s", md5( $id )
		), ARRAY_A );
		$this->assertSame( '', $wpdb->last_error );
		$this->assertNotNull( $owner );
		$attempts = array();
		$rollback_failed = false;
		$delete_filter = $this->deadlock_delete_once( $id, $attempts );
		$rollback_filter = $this->fail_query_once( 'ROLLBACK', $rollback_failed );
		try {
			$lock->release();
			$this->fail( 'An unconfirmed rollback cannot confirm release.' );
		} catch ( WP_Lock_Ownership_Uncertain $error ) {
			$this->assertTrue( $rollback_failed );
		} finally {
			remove_filter( 'query', $delete_filter );
			remove_filter( 'query', $rollback_filter );
		}
		$this->assertCount( 1, $attempts );
		$this->assertSame( $owner, $wpdb->get_row( $wpdb->prepare(
			"SELECT id, attempt_token FROM `{$wpdb->prefix}lock` WHERE lock_key = %s", md5( $id )
		), ARRAY_A ) );
		$lock->release();
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_stale_release_preserves_successor(): void {
		global $wpdb;

		$id = uniqid( 'successor_', true );
		$old = new WP_Lock( $id );
		$new = new WP_Lock( $id );
		$this->assertTrue( $old->acquire( WP_Lock::WRITE, false, 0 ) );
		$this->assertSame( 1, $wpdb->query( $wpdb->prepare(
			"DELETE FROM `{$wpdb->prefix}lock` WHERE lock_key = %s", md5( $id )
		) ) );
		$this->assertTrue( $new->acquire( WP_Lock::WRITE, false, 0 ) );
		try {
			$old->release();
			$this->fail( 'A predecessor must not release its successor.' );
		} catch ( WP_Lock_Ownership_Lost $error ) {
			$this->assertSame( 1, $this->count_owners( $id ) );
			$this->assertTrue( $new->lock_exists() );
		} finally {
			$new->release();
		}
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_expired_owner_release_reports_loss_before_cleanup(): void {
		global $wpdb;

		$id = uniqid( 'expired_owner_', true );
		$old = new WP_Lock( $id );
		$this->assertTrue( $old->acquire( WP_Lock::WRITE, false, 30 ) );
		$this->assertSame( 1, $wpdb->query( $wpdb->prepare(
			"UPDATE `{$wpdb->prefix}lock` SET expire = UNIX_TIMESTAMP(NOW(6)) - 1 WHERE lock_key = %s",
			md5( $id )
		) ) );
		try {
			$old->release();
			$this->fail( 'An expired owner must report loss even before cleanup.' );
		} catch ( WP_Lock_Ownership_Lost $expected ) {
			$this->assertSame( 1, $this->count_owners( $id ) );
		}
		$this->assertTrue( $old->acquire( WP_Lock::WRITE, false, 0 ) );
		$old->release();
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_zero_row_ghost_cleanup_retains_owner_until_exact_retry(): void {
		global $wpdb;

		$id = uniqid( 'ghost_cleanup_', true );
		$backend = new WP_Lock_Backend_DB();
		$this->assertTrue( $backend->acquire( $id, WP_Lock::WRITE, false, 30 ) );
		$this->assertSame( 1, $wpdb->query( $wpdb->prepare(
			"UPDATE `{$wpdb->prefix}lock` SET expire = UNIX_TIMESTAMP(NOW(6)) - 1 WHERE lock_key = %s",
			md5( $id )
		) ) );

		$injected = false;
		$filter = $this->zero_delete_once( $id, $injected );
		try {
			$backend->drop_ghosts( $id );
			$this->fail( 'Zero-row expired-owner deletion cannot confirm cleanup.' );
		} catch ( WP_Lock_Ownership_Uncertain $expected ) {
			$this->assertTrue( $injected );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertSame( 1, $this->count_owners( $id ) );
		$this->assertTrue( $backend->drop_ghosts( $id ) );
		$this->assertSame( 0, $this->count_owners( $id ) );

		$successor = new WP_Lock( $id );
		$this->assertTrue( $successor->acquire( WP_Lock::WRITE, false, 0 ) );
		$this->assertFalse( $backend->drop_ghosts( $id ) );
		$this->assertSame( 1, $this->count_owners( $id ) );
		$successor->release();
	}

	public function test_zero_row_acquire_cleanup_rolls_back_before_owner_insert_and_can_retry(): void {
		global $wpdb;

		$id = uniqid( 'acquire_cleanup_', true );
		$old = new WP_Lock( $id );
		$backend = new WP_Lock_Backend_DB( 0, 0 );
		$successor = new WP_Lock( $id, $backend );
		$this->assertTrue( $old->acquire( WP_Lock::WRITE, false, 30 ) );
		$owner = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, attempt_token FROM `{$wpdb->prefix}lock` WHERE lock_key = %s", md5( $id )
		), ARRAY_A );
		$this->assertSame( 1, $wpdb->query( $wpdb->prepare(
			"UPDATE `{$wpdb->prefix}lock` SET expire = UNIX_TIMESTAMP(NOW(6)) - 1 WHERE lock_key = %s", md5( $id )
		) ) );
		$injected = false;
		$inserts = 0;
		$failed_delete = $this->zero_delete_once( $id, $injected );
		$count_inserts = function( $query ) use ( &$inserts, $wpdb ) {
			if ( false !== strpos( $query, "INSERT INTO `{$wpdb->prefix}lock` (lock_key, original_key" ) ) {
				++$inserts;
			}
			return $query;
		};
		add_filter( 'query', $count_inserts );
		try {
			$successor->acquire( WP_Lock::WRITE, false, 0 );
			$this->fail( 'Zero-row acquire cleanup must fail before inserting an owner.' );
		} catch ( \RuntimeException $error ) {
			$this->assertNotInstanceOf( WP_Lock_Ownership_Uncertain::class, $error );
			$this->assertSame( 'Unable to acquire lock because of a database error.', $error->getMessage() );
		} finally {
			remove_filter( 'query', $failed_delete );
			remove_filter( 'query', $count_inserts );
		}
		$this->assertTrue( $injected );
		$this->assertSame( 0, $inserts );
		$this->assertFalse( $backend->has_unresolved( $id ) );
		$this->assertSame( 1, $this->count_owners( $id ) );
		$this->assertSame( $owner, $wpdb->get_row( $wpdb->prepare(
			"SELECT id, attempt_token FROM `{$wpdb->prefix}lock` WHERE lock_key = %s", md5( $id )
		), ARRAY_A ) );
		$this->assertTrue( $successor->acquire( WP_Lock::WRITE, false, 0 ) );
		try {
			$old->release();
			$this->fail( 'Expired predecessor release must report loss.' );
		} catch ( WP_Lock_Ownership_Lost $expected ) {
			$this->assertSame( 1, $this->count_owners( $id ) );
		} finally {
			$successor->release();
		}
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_slow_commit_cannot_grant_an_expired_lease(): void {
		$id = uniqid( 'slow_commit_', true );
		$lock = new WP_Lock( $id );
		$delayed = false;
		$filter = function( $query ) use ( &$delayed ) {
			if ( 'COMMIT' === $query && ! $delayed ) {
				$delayed = true;
				usleep( 1200000 );
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$this->assertFalse( $lock->acquire( WP_Lock::WRITE, false, 1 ) );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertTrue( $delayed );
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_full_resource_id_is_used_when_diagnostic_original_key_cannot_fit(): void {
		global $wpdb;

		$shared = str_repeat( 'p', 50 );
		$emoji = str_repeat( '😀', 26 );
		$ids = array(
			str_repeat( 'x', 50 ) => str_repeat( 'x', 50 ),
			$shared . 'a' => null,
			$shared . 'b' => null,
			str_repeat( 'é', 26 ) => str_repeat( 'é', 26 ),
			str_repeat( 'é', 51 ) => null,
			$emoji => 'utf8mb4' === $wpdb->get_col_charset( $wpdb->prefix . 'lock', 'original_key' ) ? $emoji : null,
			"\xff" => null,
		);
		$locks = array();
		try {
			foreach ( $ids as $id => $original ) {
				$lock = new WP_Lock( $id );
				$this->assertTrue( $lock->acquire( WP_Lock::WRITE, false, $id === str_repeat( 'é', 26 ) ? 60 : 0 ) );
				$locks[] = $lock;
				$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT lock_key, original_key FROM `{$wpdb->prefix}lock` WHERE lock_key = %s",
					md5( $id )
				), ARRAY_A );
				$this->assertSame( '', $wpdb->last_error );
				$this->assertSame( md5( $id ), $row['lock_key'] );
				$this->assertSame( $original, $row['original_key'] );
				$this->assertFalse( ( new WP_Lock( $id ) )->acquire( WP_Lock::WRITE, false, 0 ) );
			}
		} finally {
			foreach ( $locks as $lock ) {
				$lock->release();
			}
		}
	}

	public function test_blocking_acquire_retries_one_transient_owner_select_error(): void {
		$id = uniqid( 'retry_error_', true );
		$backend = new WP_Lock_Backend_DB( 1, 1 );
		$lock = new WP_Lock( $id, $backend );
		$injected = false;
		$filter = $this->fail_query_once( 'SELECT id, level, expire, attempt_token FROM `', $injected );
		try {
			$this->assertTrue( $lock->acquire( WP_Lock::WRITE, true, 0 ) );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertTrue( $injected );
		$this->assertSame( 1, $this->count_owners( $id ) );
		$lock->release();
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_slow_database_time_read_exhausts_wait_before_owner_insert(): void {
		$id = uniqid( 'slow_wait_', true );
		$lock = new WP_Lock( $id, new WP_Lock_Backend_DB( 1, 3 ) );
		$delayed = false;
		$inserts = 0;
		$filter = function( $query ) use ( &$delayed, &$inserts ) {
			if ( ! $delayed && 'SELECT UNIX_TIMESTAMP(NOW(6))' === $query ) {
				$delayed = true;
				usleep( 1200000 );
			}
			if ( false !== strpos( $query, 'INSERT INTO `' ) && false !== strpos( $query, 'attempt_token' ) ) {
				++$inserts;
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$this->assertFalse( $lock->acquire( WP_Lock::WRITE, true, 0 ) );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertTrue( $delayed );
		$this->assertSame( 0, $inserts );
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_slow_transaction_start_rolls_back_before_resource_sql(): void {
		$id = uniqid( 'slow_start_', true );
		$starts = 0;
		$rollbacks = 0;
		$resource_inserts = 0;
		$filter = function( $query ) use ( &$starts, &$rollbacks, &$resource_inserts ) {
			if ( 'START TRANSACTION' === $query ) { ++$starts; usleep( 1200000 ); }
			if ( 'ROLLBACK' === $query ) { ++$rollbacks; }
			if ( false !== strpos( $query, 'INSERT INTO `' ) && false !== strpos( $query, 'lock_resource`' ) ) { ++$resource_inserts; }
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$this->assertFalse( ( new WP_Lock( $id, new WP_Lock_Backend_DB( 1, 0 ) ) )->acquire( WP_Lock::WRITE, true, 0 ) );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertSame( 1, $starts );
		$this->assertSame( 1, $rollbacks );
		$this->assertSame( 0, $resource_inserts );
	}

	public function test_slow_resource_upsert_stops_before_locking_read(): void {
		$id = uniqid( 'slow_resource_', true );
		$upserts = 0;
		$reads = 0;
		$rollbacks = 0;
		$filter = function( $query ) use ( &$upserts, &$reads, &$rollbacks ) {
			if ( false !== strpos( $query, 'INSERT INTO `' ) && false !== strpos( $query, 'lock_resource`' ) ) { ++$upserts; usleep( 1200000 ); }
			if ( false !== strpos( $query, 'SELECT lock_key FROM `' ) && false !== strpos( $query, 'lock_resource`' ) ) { ++$reads; }
			if ( 'ROLLBACK' === $query ) { ++$rollbacks; }
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$this->assertFalse( ( new WP_Lock( $id, new WP_Lock_Backend_DB( 1, 0 ) ) )->acquire( WP_Lock::WRITE, true, 0 ) );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertSame( 1, $upserts );
		$this->assertSame( 0, $reads );
		$this->assertSame( 1, $rollbacks );
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_expired_wait_with_failed_rollback_is_uncertain(): void {
		$id = uniqid( 'slow_rollback_', true );
		$upserts = 0;
		$rollbacks = 0;
		$filter = function( $query ) use ( &$upserts, &$rollbacks ) {
			if ( false !== strpos( $query, 'INSERT INTO `' ) && false !== strpos( $query, 'lock_resource`' ) ) { ++$upserts; usleep( 1200000 ); }
			if ( 'ROLLBACK' === $query ) { ++$rollbacks; return 'SELECT * FROM wp_lock_missing_injected_table'; }
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$this->expectException( WP_Lock_Ownership_Uncertain::class );
			( new WP_Lock( $id, new WP_Lock_Backend_DB( 1, 0 ) ) )->acquire( WP_Lock::WRITE, true, 0 );
		} finally {
			remove_filter( 'query', $filter );
			$this->assertSame( 1, $upserts );
			$this->assertSame( 1, $rollbacks );
		}
	}

	public function test_slow_diagnostic_lookup_stops_before_owner_insert(): void {
		$id = uniqid( 'slow_diagnostic_', true ) . chr( 195 ) . chr( 169 );
		$lookups = 0;
		$inserts = 0;
		$lookup = function( $charset ) use ( &$lookups ) { ++$lookups; usleep( 1200000 ); return 'utf8mb4'; };
		$filter = function( $query ) use ( &$inserts ) {
			if ( false !== strpos( $query, 'INSERT INTO `' ) && false !== strpos( $query, 'attempt_token' ) ) { ++$inserts; }
			return $query;
		};
		add_filter( 'pre_get_col_charset', $lookup );
		add_filter( 'query', $filter );
		try {
			$this->assertFalse( ( new WP_Lock( $id, new WP_Lock_Backend_DB( 1, 0 ) ) )->acquire( WP_Lock::WRITE, true, 0 ) );
		} finally {
			remove_filter( 'pre_get_col_charset', $lookup );
			remove_filter( 'query', $filter );
		}
		$this->assertSame( 1, $lookups );
		$this->assertSame( 0, $inserts );
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_expired_sql_retry_reports_error_without_proven_conflict(): void {
		$id = uniqid( 'slow_error_', true );
		$failed = false;
		$filter = function( $query ) use ( &$failed ) {
			if ( ! $failed && false !== strpos( $query, 'SELECT id, level, expire, attempt_token FROM `' ) ) {
				$failed = true;
				usleep( 1200000 );
				return 'SELECT * FROM wp_lock_missing_injected_table';
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			( new WP_Lock( $id, new WP_Lock_Backend_DB( 1, 3 ) ) )->acquire( WP_Lock::WRITE, true, 0 );
			$this->fail( 'Expired SQL failure returned an ordinary result.' );
		} catch ( RuntimeException $error ) {
			$this->assertTrue( $failed, 'The delayed owner SELECT must be reached.' );
			$this->assertStringContainsString( 'database error', $error->getMessage() );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_proven_conflict_supersedes_earlier_sql_error_at_deadline(): void {
		$id = uniqid( 'error_then_conflict_', true );
		$owner = new WP_Lock( $id );
		$this->assertTrue( $owner->acquire( WP_Lock::WRITE, false, 0 ) );
		$reads = 0;
		$filter = function( $query ) use ( &$reads ) {
			if ( false !== strpos( $query, 'SELECT id, level, expire, attempt_token FROM `' ) ) {
				++$reads;
				if ( 1 === $reads ) {
					return 'SELECT * FROM wp_lock_missing_injected_table';
				}
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$this->assertFalse( ( new WP_Lock( $id, new WP_Lock_Backend_DB( 0.4, 3 ) ) )->acquire( WP_Lock::WRITE, true, 0 ) );
		} finally {
			remove_filter( 'query', $filter );
			$owner->release();
		}
		$this->assertGreaterThanOrEqual( 2, $reads );
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_zero_wait_still_allows_first_attempt_without_polling(): void {
		$id = uniqid( 'zero_wait_', true );
		$owner = new WP_Lock( $id, new WP_Lock_Backend_DB( 0, 3 ) );
		$this->assertTrue( $owner->acquire( WP_Lock::WRITE, true, 0 ) );
		$reads = 0;
		$filter = function( $query ) use ( &$reads ) {
			if ( false !== strpos( $query, 'SELECT id, level, expire, attempt_token FROM `' ) ) {
				++$reads;
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$this->assertFalse( ( new WP_Lock( $id, new WP_Lock_Backend_DB( 0, 3 ) ) )->acquire( WP_Lock::WRITE, true, 0 ) );
			$this->assertFalse( ( new WP_Lock( $id, new WP_Lock_Backend_DB( 1, 3 ) ) )->acquire( WP_Lock::WRITE, false, 0 ) );
		} finally {
			remove_filter( 'query', $filter );
			$owner->release();
		}
		$this->assertSame( 2, $reads );
	}

	public function test_late_commit_with_failed_cleanup_retains_attempt_for_release(): void {
		$id = uniqid( 'late_cleanup_', true );
		// Leave room for instrumented setup so the wait expires at COMMIT.
		$backend = new WP_Lock_Backend_DB( 1, 0 );
		$lock = new WP_Lock( $id, $backend );
		$commits = 0;
		$delayed = function( $query ) use ( &$commits ) {
			if ( 'COMMIT' === $query && 1 === ++$commits ) {
				usleep( 1200000 );
			}
			return $query;
		};
		$injected = false;
		$uncertain = false;
		$failed_delete = $this->zero_delete_once( $id, $injected );
		add_filter( 'query', $delayed );
		try {
			$lock->acquire( WP_Lock::WRITE, true, 0 );
		} catch ( WP_Lock_Ownership_Uncertain $expected ) {
			$uncertain = true;
		} finally {
			remove_filter( 'query', $delayed );
			remove_filter( 'query', $failed_delete );
		}
		$this->assertSame( 1, $commits, 'The delayed COMMIT must be reached.' );
		$this->assertTrue( $uncertain, 'Failed late cleanup must report uncertain ownership.' );
		$this->assertTrue( $injected );
		$this->assertTrue( $backend->has_unresolved( $id ) );
		$this->assertSame( 1, $this->count_owners( $id ) );
		$lock->release();
		$this->assertFalse( $backend->has_unresolved( $id ) );
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_uncertain_commit_is_reconciled_before_retrying_acquire(): void {
		$id = uniqid( 'reconcile_acquire_', true );
		$backend = new WP_Lock_Backend_DB( 0, 0 );
		$lock = new WP_Lock( $id, $backend );
		$injected = false;
		$filter = $this->fail_query_once( 'COMMIT', $injected );
		try {
			$lock->acquire( WP_Lock::WRITE, false, 0 );
			$this->fail( 'Unconfirmed commit must not grant ownership.' );
		} catch ( WP_Lock_Ownership_Uncertain $error ) {
			$this->assertTrue( $backend->has_unresolved( $id ) );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertTrue( $injected );
		$this->assertTrue( $lock->acquire( WP_Lock::WRITE, false, 0 ) );
		$this->assertFalse( $backend->has_unresolved( $id ) );
		$this->assertSame( 1, $this->count_owners( $id ) );
		$lock->release();
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_zero_row_owner_delete_keeps_release_retryable(): void {
		$id = uniqid( 'zero_delete_', true );
		$lock = new WP_Lock( $id );
		$injected = false;
		$this->assertTrue( $lock->acquire( WP_Lock::WRITE, false, 0 ) );
		$filter = $this->zero_delete_once( $id, $injected );
		try {
			$lock->release();
			$this->fail( 'Zero-row owner deletion must not confirm release.' );
		} catch ( WP_Lock_Ownership_Uncertain $error ) {
			$this->assertTrue( $injected );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertSame( 1, $this->count_owners( $id ) );
		$lock->release();
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_uncertain_release_can_clear_absent_predecessor_without_touching_successor(): void {
		global $wpdb;

		$id = uniqid( 'uncertain_successor_', true );
		$old = new WP_Lock( $id );
		$new = new WP_Lock( $id );
		$injected = false;
		$this->assertTrue( $old->acquire( WP_Lock::WRITE, false, 0 ) );
		$filter = $this->fail_query_once( 'COMMIT', $injected );
		try {
			$old->release();
			$this->fail( 'Unconfirmed release must leave the owner retryable.' );
		} catch ( WP_Lock_Ownership_Uncertain $error ) {
			$this->assertTrue( $injected );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertSame( 1, $wpdb->query( $wpdb->prepare(
			"DELETE FROM `{$wpdb->prefix}lock` WHERE lock_key = %s", md5( $id )
		) ) );
		$this->assertTrue( $new->acquire( WP_Lock::WRITE, false, 0 ) );
		try {
			$old->release();
			$this->assertSame( 1, $this->count_owners( $id ) );
			$this->assertTrue( $new->lock_exists() );
		} finally {
			$new->release();
		}
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_failed_insert_and_rollback_leave_reconcilable_uncertain_attempt(): void {
		global $wpdb;

		$id = uniqid( 'uncertain_insert_', true );
		$backend = new WP_Lock_Backend_DB( 0, 0 );
		$lock = new WP_Lock( $id, $backend );
		$owner_insert = 'INSERT INTO `' . $wpdb->prefix . 'lock`';
		$failed = array();
		$filter = function( $query ) use ( $owner_insert, &$failed, &$filter ) {
			foreach ( array( $owner_insert, 'ROLLBACK' ) as $needle ) {
				if ( false !== strpos( $query, $needle ) && ! isset( $failed[ $needle ] ) ) {
					$failed[ $needle ] = true;
					if ( 2 === count( $failed ) ) {
						remove_filter( 'query', $filter );
					}
					return 'SELECT * FROM wp_lock_missing_injected_table';
				}
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$this->expectException( WP_Lock_Ownership_Uncertain::class );
			$lock->acquire( WP_Lock::WRITE, false, 0 );
		} finally {
			remove_filter( 'query', $filter );
			$this->assertCount( 2, $failed );
			$this->assertTrue( $backend->has_unresolved( $id ) );
			$lock->release();
			$this->assertFalse( $backend->has_unresolved( $id ) );
			$this->assertSame( 0, $this->count_owners( $id ) );
		}
	}

	public function test_failed_commit_never_grants_and_can_be_reconciled(): void {
		$id = uniqid( 'uncertain_commit_', true );
		$backend = new WP_Lock_Backend_DB( 0, 0 );
		$lock = new WP_Lock( $id, $backend );
		$injected = false;
		$filter = $this->fail_query_once( 'COMMIT', $injected );
		try {
			$this->expectException( WP_Lock_Ownership_Uncertain::class );
			$lock->acquire( WP_Lock::WRITE, false, 0 );
		} finally {
			remove_filter( 'query', $filter );
			$this->assertTrue( $injected );
			$this->assertTrue( $backend->has_unresolved( $id ) );
			$lock->release();
			$this->assertFalse( $backend->has_unresolved( $id ) );
			$this->assertSame( 0, $this->count_owners( $id ) );
		}
	}

	public function test_committed_unconfirmed_owner_requires_exact_cleanup_before_retry(): void {
		$id = uniqid( 'post_commit_', true );
		$backend = new WP_Lock_Backend_DB( 0, 0 );
		$lock = new WP_Lock( $id, $backend );
		$time_reads = 0;
		$filter = function( $query ) use ( &$time_reads, &$filter ) {
			if ( false !== strpos( $query, 'SELECT UNIX_TIMESTAMP(NOW(6))' ) && 3 === ++$time_reads ) {
				remove_filter( 'query', $filter );
				return 'SELECT * FROM wp_lock_missing_injected_table';
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			try {
				$lock->acquire( WP_Lock::WRITE, false, 60 );
				$this->fail( 'Unconfirmed committed owner must not be granted.' );
			} catch ( WP_Lock_Ownership_Uncertain $error ) {
				$this->assertTrue( $backend->has_unresolved( $id ) );
			}
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertSame( 3, $time_reads );
		$this->assertSame( 1, $this->count_owners( $id ) );
		$injected = false;
		$filter = $this->zero_delete_once( $id, $injected );
		try {
			$lock->release();
			$this->fail( 'Zero-row cleanup cannot confirm release.' );
		} catch ( WP_Lock_Ownership_Uncertain $error ) {
			$this->assertTrue( $injected );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertTrue( $backend->has_unresolved( $id ) );
		$this->assertSame( 1, $this->count_owners( $id ) );
		$lock->release();
		$this->assertFalse( $backend->has_unresolved( $id ) );
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_acquire_refuses_missing_prepared_schema(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . WP_Lock_Backend_DB::TABLE_NAME;
		update_option( WP_Lock_Backend_DB::SCHEMA_VERSION_OPTION, WP_Lock_Backend_DB::SCHEMA_VERSION, false );
		$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );

		$backend  = new WP_Lock_Backend_DB( 0.05, 1 );
		try {
			$this->expectException( RuntimeException::class );
			$backend->acquire( 'missing-table', WP_Lock::WRITE, false, 0 );
		} finally {
			WP_Lock_Backend_DB::maybe_upgrade_schema( true );
			WP_Lock_Foundations::prepare_schema();
		}
	}

	public function test_invalid_prepared_schema_throws_database_error(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . WP_Lock_Backend_DB::TABLE_NAME;
		$wpdb->query( "ALTER TABLE `{$table_name}` DROP COLUMN `original_key`" );

		try {
			$backend = new WP_Lock_Backend_DB( 0.05, 2 );
			$backend->acquire( 'persistent-db-error', WP_Lock::WRITE, false, 0 );
			$this->fail( 'Expected a persistent database error to throw.' );
		} catch ( RuntimeException $exception ) {
			$this->assertStringContainsString( 'database error', $exception->getMessage() );
		} finally {
			WP_Lock_Backend_DB::maybe_upgrade_schema( true );
			WP_Lock_Foundations::prepare_schema();
		}
	}

	public function test_acquire_refuses_missing_foundation_owner_index(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . WP_Lock_Backend_DB::TABLE_NAME;
		$this->assertTrue( $this->index_exists( 'attempt_token' ) );
		$this->assertFalse( false === $wpdb->query( "ALTER TABLE `{$table_name}` DROP INDEX `attempt_token`" ) );
		try {
			$backend = new WP_Lock_Backend_DB( 0, 0 );
			$backend->acquire( 'missing-owner-index', WP_Lock::WRITE, false, 0 );
			$this->fail( 'A missing unique owner token index must prevent acquisition.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'database error', $error->getMessage() );
			$this->assertSame( 0, $this->count_owners( 'missing-owner-index' ) );
		} finally {
			WP_Lock_Foundations::prepare_schema();
		}
		$this->assertTrue( $this->index_exists( 'attempt_token' ) );
	}

	/**
	 * @dataProvider foundation_schema_inspection_queries
	 */
	public function test_schema_inspection_error_refuses_acquisition( string $needle ): void {
		$id = uniqid( 'schema_inspection_', true );
		$injected = false;
		$filter = $this->fail_query_once( $needle, $injected );
		try {
			( new WP_Lock_Backend_DB( 0, 0 ) )->acquire( $id, WP_Lock::WRITE, false, 0 );
			$this->fail( 'Unverifiable foundation schema must not grant ownership.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'database error', $error->getMessage() );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertTrue( $injected );
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function foundation_schema_inspection_queries(): array {
		return array(
			'column metadata' => array( 'FROM information_schema.columns' ),
			'index metadata' => array( 'FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ' ),
			'index parts' => array( 'SELECT COUNT(*) FROM information_schema.statistics' ),
			'unexpected unique owner index' => array( 'SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics' ),
		);
	}

	public function test_foundation_schema_ddl_error_is_reported_before_recording_version(): void {
		$before = get_option( WP_Lock_Foundations::SCHEMA_OPTION );
		$injected = false;
		$filter = $this->fail_query_once( 'CREATE TABLE IF NOT EXISTS', $injected );
		try {
			WP_Lock_Foundations::prepare_schema();
			$this->fail( 'A failed foundation DDL statement must be reported.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'schema change failed', $error->getMessage() );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertTrue( $injected );
		$this->assertSame( $before, get_option( WP_Lock_Foundations::SCHEMA_OPTION ) );
	}

	public function test_protocol_switch_refuses_unresolved_owner_and_disables_new_acquisition(): void {
		global $wpdb;
		$id = uniqid( 'migration_owner_', true );
		$owner = new WP_Lock_Backend_DB( 0, 0 );
		$this->assertTrue( $owner->acquire( $id, WP_Lock::WRITE, false, 0 ) );
		try {
			WP_Lock_Foundations::switch_protocol( '2.0.0' );
			$this->fail( 'An indefinite owner must block rollback.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'owners remain', $error->getMessage() );
		}
		$this->assertTrue( $owner->release( $id ) );
		WP_Lock_Foundations::switch_protocol( '2.0.0' );
		$this->assertSame( '2.0.0', $wpdb->get_var( $wpdb->prepare(
			"SELECT option_value FROM `{$wpdb->prefix}options` WHERE option_name = %s",
			WP_Lock_Foundations::PROTOCOL_OPTION
		) ) );
		try {
			( new WP_Lock_Backend_DB( 0, 0 ) )->acquire( $id, WP_Lock::WRITE, false, 0 );
			$this->fail( 'A disabled protocol must not grant ownership.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'database error', $error->getMessage() );
			$this->assertStringContainsString( 'disabled', $error->getPrevious()->getMessage() );
		} finally {
			WP_Lock_Foundations::switch_protocol( WP_Lock_Foundations::PROTOCOL_VERSION );
		}
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_protocol_switch_refuses_non_null_legacy_token_default(): void {
		global $wpdb;
		$table = $wpdb->prefix . WP_Lock_Backend_DB::TABLE_NAME;
		update_option( WP_Lock_Foundations::SCHEMA_OPTION, 'unverified', false );
		$this->assertFalse( false === $wpdb->query( "ALTER TABLE `{$table}` MODIFY attempt_token char(32) DEFAULT 'reused'" ) );
		try {
			try {
				WP_Lock_Foundations::prepare_schema();
				$this->fail( 'Invalid legacy token default must block schema-version advancement.' );
			} catch ( RuntimeException $error ) {
				$this->assertStringContainsString( 'column verification failed', $error->getMessage() );
			}
			$this->assertSame( 'unverified', get_option( WP_Lock_Foundations::SCHEMA_OPTION ) );
			WP_Lock_Foundations::switch_protocol( WP_Lock_Foundations::PROTOCOL_VERSION );
			$this->fail( 'A token default incompatible with old inserts must block switching.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'column verification failed', $error->getMessage() );
		} finally {
			try {
				( new WP_Lock_Backend_DB( 0, 0 ) )->acquire( 'invalid-default', WP_Lock::WRITE, false, 0 );
				$this->fail( 'Active marker must not bypass current schema validation.' );
			} catch ( RuntimeException $error ) {
				$this->assertStringContainsString( 'database error', $error->getMessage() );
			}
			$wpdb->query( "ALTER TABLE `{$table}` MODIFY attempt_token char(32) DEFAULT NULL" );
			WP_Lock_Foundations::prepare_schema();
		}
	}

	public function test_foundation_schema_marker_must_be_read_back(): void {
		$injected = false;
		$filter = function( $query ) use ( &$injected, &$filter ) {
			if ( false !== strpos( $query, 'SELECT option_value FROM `' ) &&
				false !== strpos( $query, WP_Lock_Foundations::SCHEMA_OPTION ) ) {
				remove_filter( 'query', $filter );
				$injected = true;
				return "SELECT 'unexpected' AS option_value";
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			WP_Lock_Foundations::prepare_schema();
			$this->fail( 'An unverified schema marker cannot confirm preparation.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'marker could not be verified', $error->getMessage() );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertTrue( $injected );
	}

	public function test_unverifiable_isolation_refuses_acquisition(): void {
		$id = uniqid( 'isolation_error_', true );
		$injected = false;
		$filter = $this->fail_query_once( "SHOW SESSION VARIABLES LIKE 'transaction_isolation'", $injected );
		try {
			( new WP_Lock_Backend_DB( 0, 0 ) )->acquire( $id, WP_Lock::WRITE, false, 0 );
			$this->fail( 'An unknown caller isolation cannot grant ownership.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'database error', $error->getMessage() );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertTrue( $injected );
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_snapshot_isolation_inspection_error_refuses_acquisition_without_committing_caller_work(): void {
		global $wpdb;

		$id = uniqid( 'snapshot_error_', true );
		$option = uniqid( 'wp_lock_snapshot_', true );
		$injected = false;
		$this->assertFalse( false === $wpdb->query( 'START TRANSACTION' ) );
		try {
			$this->assertSame( 1, $wpdb->query( $wpdb->prepare(
				"INSERT INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$option, 'uncommitted'
			) ) );
			$filter = $this->fail_query_once( "SHOW SESSION VARIABLES LIKE 'innodb_snapshot_isolation'", $injected );
			try {
				( new WP_Lock_Backend_DB( 0, 0 ) )->acquire( $id, WP_Lock::WRITE, false, 0 );
				$this->fail( 'A failed foundation snapshot isolation inspection cannot grant ownership.' );
			} catch ( RuntimeException $error ) {
				$this->assertStringContainsString( 'database error', $error->getMessage() );
			} finally {
				remove_filter( 'query', $filter );
			}
			$this->assertTrue( $injected );
			$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->options}` WHERE option_name = %s", $option
			) ) );
			$this->assertFalse( false === $wpdb->query( 'ROLLBACK' ) );
			$this->assertSame( 0, $this->count_owners( $id ) );
			$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->options}` WHERE option_name = %s", $option
			) ) );
		} finally {
			$wpdb->query( 'ROLLBACK' );
		}
	}

	public function test_absent_snapshot_isolation_variable_allows_acquisition(): void {
		$id = uniqid( 'no_snapshot_variable_', true );
		$backend = new WP_Lock_Backend_DB( 0, 0 );
		$inspections = 0;
		$settings = 0;
		$filter = function( $query ) use ( &$inspections, &$settings ) {
			if ( false !== strpos( $query, "SHOW SESSION VARIABLES LIKE 'innodb_snapshot_isolation'" ) ) {
				++$inspections;
				return 'SELECT NULL AS Variable_name, NULL AS Value WHERE 1 = 0';
			}
			if ( false !== strpos( $query, 'SET SESSION innodb_snapshot_isolation' ) ) {
				++$settings;
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$this->assertTrue( $backend->acquire( $id, WP_Lock::WRITE, false, 0 ) );
			$this->assertTrue( $backend->release( $id ) );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertGreaterThan( 0, $inspections );
		$this->assertSame( 0, $settings );
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_unconfirmed_resource_row_refuses_acquisition(): void {
		$id = uniqid( 'resource_row_', true );
		$injected = false;
		$filter = function( $query ) use ( &$injected, &$filter ) {
			if ( false !== strpos( $query, 'SELECT lock_key FROM `' ) ) {
				remove_filter( 'query', $filter );
				$injected = true;
				return "SELECT 'wrong' AS lock_key";
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			( new WP_Lock_Backend_DB( 0, 0 ) )->acquire( $id, WP_Lock::WRITE, false, 0 );
			$this->fail( 'A different resource row cannot authorize ownership.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'database error', $error->getMessage() );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertTrue( $injected );
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_unsupported_namespace_refuses_acquisition(): void {
		global $wpdb;

		$id = uniqid( 'bad_namespace_', true );
		$prefix = $wpdb->prefix;
		$wpdb->prefix = 'bad-prefix';
		try {
			( new WP_Lock_Backend_DB( 0, 0 ) )->acquire( $id, WP_Lock::WRITE, false, 0 );
			$this->fail( 'An unsafe namespace cannot select a lock table.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'database error', $error->getMessage() );
		} finally {
			$wpdb->prefix = $prefix;
		}
		$this->assertSame( 0, $this->count_owners( $id ) );
	}

	public function test_schema_upgrade_is_explicit_and_records_version(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . WP_Lock_Backend_DB::TABLE_NAME;
		$this->assertTrue( $this->index_exists( 'lock_key' ) );
		$wpdb->query( "ALTER TABLE `{$table_name}` DROP INDEX `lock_key`" );
		$wpdb->query( "ALTER TABLE `{$table_name}` MODIFY `expire` int(10) unsigned DEFAULT NULL" );
		update_option( WP_Lock_Backend_DB::SCHEMA_VERSION_OPTION, '1.0.0', false );

		try {
			new WP_Lock_Backend_DB();
			$this->assertFalse( $this->index_exists( 'lock_key' ) );
			WP_Lock_Backend_DB::maybe_upgrade_schema( true );
			$this->assertTrue( $this->index_exists( 'lock_key' ) );
			$this->assertSame(
				'decimal',
				$wpdb->get_var(
					$wpdb->prepare(
						'SELECT data_type FROM information_schema.columns
						WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s',
						$table_name,
						'expire'
					)
				)
			);
			$this->assertSame(
				WP_Lock_Backend_DB::SCHEMA_VERSION,
				get_option( WP_Lock_Backend_DB::SCHEMA_VERSION_OPTION )
			);
		} finally {
			WP_Lock_Backend_DB::maybe_upgrade_schema( true );
		}
	}
}
