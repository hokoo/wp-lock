<?php

use iTRON\WP_Lock\helpers\Database;
use iTRON\WP_Lock\WP_Lock;
use iTRON\WP_Lock\WP_Lock_Backend_DB;

class WP_Lock_Backend_Generic_UnitTestCase extends WP_UnitTestCase {
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

	private function prevent_transactions(): void {
		global $wpdb;

		$wpdb->close();
		$wpdb->db_connect( false );
		$this->delete_all_locks();
	}

	/**
	 * Disable the WordPress test transaction so independent DB connections and
	 * the production atomic INSERT ... SELECT query use the same real table.
	 */
	private function disable_wordpress_test_transaction(): void {
		global $wpdb;

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$wpdb->query( 'ROLLBACK' );
		$wpdb->query( 'SET autocommit = 1' );
	}

	private function generate_lock_resource_id(): string {
		return uniqid( 'lock_', true );
	}

	private function delete_all_locks(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . WP_Lock_Backend_DB::TABLE_NAME;
		$wpdb->query( "DELETE FROM `{$table_name}`" );
	}

	private function require_process_control(): void {
		if (
			! function_exists( 'pcntl_fork' ) ||
			! function_exists( 'pcntl_exec' ) ||
			! function_exists( 'pcntl_waitpid' ) ||
			! function_exists( 'posix_kill' )
		) {
			$this->markTestSkipped( 'PCNTL and POSIX process control are required.' );
		}
	}

	private function assert_children_succeeded( array $statuses ): void {
		foreach ( $statuses as $child => $status ) {
			$this->assertTrue( pcntl_wifexited( $status ), "Child process {$child} did not exit normally." );
			$this->assertSame( 0, pcntl_wexitstatus( $status ), "Child process {$child} failed." );
		}
	}

	public function test_acquire_read(): void {
		$resource_id = $this->generate_lock_resource_id();
		$reader_1    = new WP_Lock_Backend_DB();
		$reader_2    = new WP_Lock_Backend_DB();

		$this->assertTrue( $reader_1->acquire( $resource_id, WP_Lock::READ, true, 0 ) );
		$this->assertTrue( $reader_2->acquire( $resource_id, WP_Lock::READ, false, 0 ) );
		$this->assertTrue( $reader_1->release( $resource_id ) );
		$this->assertTrue( $reader_2->release( $resource_id ) );
	}

	public function test_acquire_write_excludes_readers_and_writers(): void {
		$resource_id = $this->generate_lock_resource_id();
		$writer      = new WP_Lock_Backend_DB();
		$writer_2    = new WP_Lock_Backend_DB();
		$reader      = new WP_Lock_Backend_DB();

		$this->assertTrue( $writer->acquire( $resource_id, WP_Lock::WRITE, true, 0 ) );
		$this->assertFalse( $writer_2->acquire( $resource_id, WP_Lock::WRITE, false, 0 ) );
		$this->assertFalse( $reader->acquire( $resource_id, WP_Lock::READ, false, 0 ) );
		$this->assertTrue( $writer->release( $resource_id ) );
	}

	public function test_read_lock_excludes_writer(): void {
		$resource_id = $this->generate_lock_resource_id();
		$reader      = new WP_Lock_Backend_DB();
		$writer      = new WP_Lock_Backend_DB();

		$this->assertTrue( $reader->acquire( $resource_id, WP_Lock::READ, true, 0 ) );
		$this->assertFalse( $writer->acquire( $resource_id, WP_Lock::WRITE, false, 0 ) );
		$this->assertTrue( $reader->release( $resource_id ) );
	}

	public function test_writer_can_acquire_only_after_all_readers_release(): void {
		$resource_id = $this->generate_lock_resource_id();
		$reader_1    = new WP_Lock_Backend_DB();
		$reader_2    = new WP_Lock_Backend_DB();

		$this->assertTrue( $reader_1->acquire( $resource_id, WP_Lock::READ, true, 0 ) );
		$this->assertTrue( $reader_2->acquire( $resource_id, WP_Lock::READ, false, 0 ) );
		$this->assertFalse(
			( new WP_Lock_Backend_DB() )->acquire( $resource_id, WP_Lock::WRITE, false, 0 )
		);

		$this->assertTrue( $reader_1->release( $resource_id ) );
		$this->assertFalse(
			( new WP_Lock_Backend_DB() )->acquire( $resource_id, WP_Lock::WRITE, false, 0 )
		);

		$this->assertTrue( $reader_2->release( $resource_id ) );
		$writer = new WP_Lock_Backend_DB();
		$this->assertTrue( $writer->acquire( $resource_id, WP_Lock::WRITE, false, 0 ) );
		$this->assertTrue( $writer->release( $resource_id ) );
	}

	public function test_readers_can_acquire_only_after_writer_releases(): void {
		$resource_id = $this->generate_lock_resource_id();
		$writer      = new WP_Lock_Backend_DB();

		$this->assertTrue( $writer->acquire( $resource_id, WP_Lock::WRITE, true, 0 ) );
		$this->assertFalse(
			( new WP_Lock_Backend_DB() )->acquire( $resource_id, WP_Lock::READ, false, 0 )
		);
		$this->assertTrue( $writer->exists( $resource_id, WP_Lock::WRITE ) );
		$this->assertTrue( $writer->exists( $resource_id, WP_Lock::READ ) );
		$this->assertTrue( $writer->release( $resource_id ) );

		$reader = new WP_Lock_Backend_DB();
		$this->assertTrue( $reader->acquire( $resource_id, WP_Lock::READ, false, 0 ) );
		$this->assertFalse( $reader->exists( $resource_id, WP_Lock::WRITE ) );
		$this->assertTrue( $reader->exists( $resource_id, WP_Lock::READ ) );
		$this->assertTrue( $reader->release( $resource_id ) );
		$this->assertFalse( $reader->exists( $resource_id, WP_Lock::READ ) );
	}

	public function test_lock_expiration_releases_resource(): void {
		global $wpdb;

		$resource_id = $this->generate_lock_resource_id();
		$writer      = new WP_Lock_Backend_DB();
		$started_at  = microtime( true );

		$this->assertTrue( $writer->acquire( $resource_id, WP_Lock::WRITE, false, 1 ) );
		$stored_expiration = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT `expire` FROM {$writer->get_table_name()} WHERE `lock_key` = %s",
				md5( $resource_id )
			)
		);
		$this->assertGreaterThan( $started_at + 0.99, $stored_expiration );
		$this->assertFalse(
			( new WP_Lock_Backend_DB() )->acquire( $resource_id, WP_Lock::READ, false, 0 )
		);
		$this->assertTrue( $writer->exists( $resource_id, WP_Lock::WRITE ) );

		sleep( 2 );

		$this->assertFalse( $writer->exists( $resource_id, WP_Lock::READ ) );
		$this->assertFalse( $writer->exists( $resource_id, WP_Lock::WRITE ) );
		$reader = new WP_Lock_Backend_DB();
		$this->assertTrue( $reader->acquire( $resource_id, WP_Lock::READ, false, 0 ) );
		$this->assertTrue( $writer->release( $resource_id ) );
		$this->assertTrue( $reader->release( $resource_id ) );
	}

	public function test_concurrent_process_observes_write_contention(): void {
		$this->require_process_control();
		$this->prevent_transactions();

		$resource_id = $this->generate_lock_resource_id();
		$writer      = new WP_Lock_Backend_DB();
		$this->assertTrue( $writer->acquire( $resource_id, WP_Lock::WRITE, false, 0 ) );

		$callback = new WP_Lock_Backend_Callback(
			array( $this, '_test_concurrent_process_observes_write_contention_child' ),
			array( $resource_id )
		);
		$children = array();

		try {
			$children[] = run_in_child( array( $callback, 'run' ) );
			$this->assert_children_succeeded( wp_lock_wait_for_children( $children, 5.0 ) );
		} finally {
			wp_lock_terminate_children( $children );
			$writer->release( $resource_id );
		}
	}

	public function _test_concurrent_process_observes_write_contention_child( $resource_id ): bool {
		$backend = new WP_Lock_Backend_DB();

		return false === $backend->acquire( $resource_id, WP_Lock::WRITE, false, 0 );
	}

	public function test_concurrent_pageview_updates_are_serialized(): void {
		$this->require_process_control();
		$this->prevent_transactions();

		$resource_id = $this->generate_lock_resource_id();
		$post_id     = $this->factory()->post->create();
		$increments  = 25;
		update_post_meta( $post_id, 'pageviews', 0 );

		$callback = new WP_Lock_Backend_Callback(
			array( $this, '_test_concurrent_pageview_updates_child' ),
			array( $post_id, $resource_id, $increments )
		);
		$children = array();

		try {
			foreach ( range( 1, 3 ) as $_ ) {
				$children[] = run_in_child( array( $callback, 'run' ) );
			}

			$this->_test_concurrent_pageview_updates_child( $post_id, $resource_id, $increments );
			$this->assert_children_succeeded( wp_lock_wait_for_children( $children, 15.0 ) );
			$this->assertSame( 100, (int) get_post_meta( $post_id, 'pageviews', true ) );
		} finally {
			wp_lock_terminate_children( $children );
		}
	}

	public function _test_concurrent_pageview_updates_child( $post_id, $resource_id, $increments ): bool {
		foreach ( range( 1, $increments ) as $_ ) {
			$backend = new WP_Lock_Backend_DB( 5.0 );
			if ( ! $backend->acquire( $resource_id, WP_Lock::WRITE, true, 0 ) ) {
				return false;
			}

			try {
				$pageviews = get_post_meta( $post_id, 'pageviews', true );
				update_post_meta( $post_id, 'pageviews', (int) $pageviews + 1 );
			} finally {
				$backend->release( $resource_id );
			}
		}

		return true;
	}

	/**
	 * @dataProvider ghost_lock_scenarios
	 */
	public function test_ghost_detection_is_deterministic(
		int $expiration,
		bool $expect_ghost,
		bool $expect_reacquire,
		$fixed_resource_id = null
	): void {
		$this->require_process_control();
		$this->prevent_transactions();

		$resource_id = null === $fixed_resource_id ? $this->generate_lock_resource_id() : $fixed_resource_id;
		$callback    = new WP_Lock_Backend_Callback(
			function( $child_resource_id, $child_expiration ) {
				$backend = new WP_Lock_Backend_DB();

				return $backend->acquire( $child_resource_id, WP_Lock::WRITE, false, $child_expiration );
			},
			array( $resource_id, $expiration )
		);
		$children = array();

		try {
			$children[] = run_in_child( array( $callback, 'run' ) );
			$this->assert_children_succeeded( wp_lock_wait_for_children( $children, 5.0 ) );

			$backend = new WP_Lock_Backend_DB();
			$this->assertSame( $expect_ghost, ! empty( $backend->get_ghosts( $resource_id ) ) );
			$this->assertSame(
				$expect_reacquire,
				$backend->acquire( $resource_id, WP_Lock::WRITE, false, 0 )
			);
			$this->assertSame( array(), array_values( $backend->get_ghosts( $resource_id ) ) );

			if ( $expect_reacquire ) {
				$this->assertTrue( $backend->release( $resource_id ) );
			}
		} finally {
			wp_lock_terminate_children( $children );
			$this->delete_all_locks();
		}
	}

	public function ghost_lock_scenarios(): array {
		return array(
			'no-expiration lock becomes a ghost' => array( 0, true, true, null ),
			'unexpired lock is not a ghost'       => array( 30, false, false, null ),
			'zero resource identifier is scoped'  => array( 0, true, true, '0' ),
		);
	}
}
