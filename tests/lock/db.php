<?php

use iTRON\WP_Lock\helpers\Database;
use iTRON\WP_Lock\WP_Lock;
use iTRON\WP_Lock\WP_Lock_Backend_DB;

class WP_Lock_Backend_DB_UnitTestCase extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->disable_wordpress_test_transaction();

		Database::register_table( WP_Lock_Backend_DB::TABLE_NAME );
		WP_Lock_Backend_DB::maybe_upgrade_schema( true );
		$this->delete_all_locks();
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

	public function test_acquire_recovers_when_the_lock_table_is_missing(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . WP_Lock_Backend_DB::TABLE_NAME;
		update_option( WP_Lock_Backend_DB::SCHEMA_VERSION_OPTION, WP_Lock_Backend_DB::SCHEMA_VERSION, false );
		$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );

		$backend  = new WP_Lock_Backend_DB( 0.05, 1 );
		$acquired = false;
		try {
			$acquired = $backend->acquire( 'missing-table', WP_Lock::WRITE, false, 0 );
			$this->assertTrue( $acquired );
			$this->assertSame(
				$table_name,
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) )
			);
			$this->assertTrue( $this->index_exists( 'lock_key' ) );
		} finally {
			if ( $acquired ) {
				$backend->release( 'missing-table' );
			}
			WP_Lock_Backend_DB::maybe_upgrade_schema( true );
		}
	}

	public function test_persistent_database_error_has_bounded_retries_and_restores_error_mode(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . WP_Lock_Backend_DB::TABLE_NAME;
		$attempts   = 0;
		$count_acquisition_queries = function( $query ) use ( $table_name, &$attempts ) {
			if ( 0 === strpos( ltrim( $query ), "INSERT INTO {$table_name}" ) ) {
				$attempts++;
			}

			return $query;
		};

		$wpdb->query( "ALTER TABLE `{$table_name}` DROP COLUMN `original_key`" );
		$previous_suppression = $wpdb->suppress_errors( true );
		add_filter( 'query', $count_acquisition_queries );

		try {
			$backend = new WP_Lock_Backend_DB( 0.05, 2 );
			$backend->acquire( 'persistent-db-error', WP_Lock::WRITE, false, 0 );
			$this->fail( 'Expected a persistent database error to throw.' );
		} catch ( RuntimeException $exception ) {
			$this->assertStringContainsString( 'database error', $exception->getMessage() );
			$this->assertSame( 3, $attempts );
			$this->assertTrue( $wpdb->suppress_errors );
		} finally {
			remove_filter( 'query', $count_acquisition_queries );
			$wpdb->suppress_errors( $previous_suppression );
			WP_Lock_Backend_DB::maybe_upgrade_schema( true );
		}
	}

	public function test_constructor_migrates_schema_and_records_version(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . WP_Lock_Backend_DB::TABLE_NAME;
		$this->assertTrue( $this->index_exists( 'lock_key' ) );
		$wpdb->query( "ALTER TABLE `{$table_name}` DROP INDEX `lock_key`" );
		update_option( WP_Lock_Backend_DB::SCHEMA_VERSION_OPTION, '1.0.0', false );

		try {
			new WP_Lock_Backend_DB();
			$this->assertTrue( $this->index_exists( 'lock_key' ) );
			$this->assertSame(
				WP_Lock_Backend_DB::SCHEMA_VERSION,
				get_option( WP_Lock_Backend_DB::SCHEMA_VERSION_OPTION )
			);
		} finally {
			WP_Lock_Backend_DB::maybe_upgrade_schema( true );
		}
	}
}
