<?php

use iTRON\WP_Lock\WP_Lock;
use iTRON\WP_Lock\WP_Lock_Backend;

class WP_Lock_Fake_Backend implements WP_Lock_Backend {
	public $acquire_calls = array();
	public $release_calls = array();
	public $exists_calls = array();
	public $acquire_result = true;
	public $release_result = true;
	public $exists_result = false;

	public function acquire( $id, $level, $blocking, $expiration ): bool {
		$this->acquire_calls[] = array( $id, $level, $blocking, $expiration );

		return $this->acquire_result;
	}

	public function release( $id ): bool {
		$this->release_calls[] = array( $id );

		return $this->release_result;
	}

	public function exists( $id, $level ): bool {
		$this->exists_calls[] = array( $id, $level );

		return $this->exists_result;
	}
}

class WP_Lock_UnitTestCase extends WP_UnitTestCase {
	public function test_acquire_delegates_default_arguments_and_release(): void {
		$backend = new WP_Lock_Fake_Backend();
		$lock    = new WP_Lock( 'resource', $backend );

		$this->assertTrue( $lock->acquire() );
		$this->assertSame(
			array( array( 'resource', WP_Lock::WRITE, true, 30 ) ),
			$backend->acquire_calls
		);

		$lock->release();
		$this->assertSame( array( array( 'resource' ) ), $backend->release_calls );
	}

	public function test_acquire_delegates_explicit_arguments(): void {
		$backend = new WP_Lock_Fake_Backend();
		$lock    = new WP_Lock( 'shared-resource', $backend );

		$this->assertTrue( $lock->acquire( WP_Lock::READ, false, 7 ) );
		$this->assertSame(
			array( array( 'shared-resource', WP_Lock::READ, false, 7 ) ),
			$backend->acquire_calls
		);

		$lock->release();
	}

	public function test_contention_returns_false_without_marking_lock_as_held(): void {
		$backend                 = new WP_Lock_Fake_Backend();
		$backend->acquire_result = false;
		$lock                    = new WP_Lock( 'contended', $backend );

		$this->assertFalse( $lock->acquire() );
		$this->assertSame( array(), $backend->release_calls );

		$backend->acquire_result = true;
		$this->assertTrue( $lock->acquire() );
		$this->assertCount( 2, $backend->acquire_calls );
		$lock->release();
	}

	public function test_repeated_acquire_throws_without_calling_backend_again(): void {
		$backend = new WP_Lock_Fake_Backend();
		$lock    = new WP_Lock( 'resource', $backend );
		$lock->acquire();

		try {
			$lock->acquire();
			$this->fail( 'Expected repeated acquire to throw.' );
		} catch ( LogicException $exception ) {
			$this->assertCount( 1, $backend->acquire_calls );
		} finally {
			$lock->release();
		}
	}

	public function test_release_before_acquire_throws_without_calling_backend_or_changing_state(): void {
		$backend = new WP_Lock_Fake_Backend();
		$lock    = new WP_Lock( 'resource', $backend );

		try {
			$lock->release();
			$this->fail( 'Expected release before acquire to throw.' );
		} catch ( LogicException $exception ) {
			$this->assertSame( array(), $backend->release_calls );
		}

		$this->assertTrue( $lock->acquire() );
		$lock->release();
	}

	public function test_backend_release_failure_keeps_lock_held(): void {
		$backend                 = new WP_Lock_Fake_Backend();
		$backend->release_result = false;
		$lock                    = new WP_Lock( 'resource', $backend );
		$lock->acquire();

		try {
			$lock->release();
			$this->fail( 'Expected backend release failure to throw.' );
		} catch ( RuntimeException $exception ) {
			$this->assertCount( 1, $backend->release_calls );
		}

		try {
			$lock->acquire();
			$this->fail( 'Expected the lock to remain held after release failure.' );
		} catch ( LogicException $exception ) {
			$this->assertCount( 1, $backend->acquire_calls );
		}

		$backend->release_result = true;
		$lock->release();
		$this->assertCount( 2, $backend->release_calls );
	}

	/**
	 * @dataProvider invalid_lock_levels
	 */
	public function test_acquire_rejects_invalid_lock_level_without_calling_backend( $level ): void {
		$backend = new WP_Lock_Fake_Backend();
		$lock    = new WP_Lock( 'resource', $backend );

		try {
			$lock->acquire( $level );
			$this->fail( 'Expected invalid lock level to throw.' );
		} catch ( InvalidArgumentException $exception ) {
			$this->assertSame( array(), $backend->acquire_calls );
		}
	}

	/**
	 * @dataProvider invalid_expirations
	 */
	public function test_acquire_rejects_invalid_expiration_without_calling_backend( $expiration ): void {
		$backend = new WP_Lock_Fake_Backend();
		$lock    = new WP_Lock( 'resource', $backend );

		try {
			$lock->acquire( WP_Lock::WRITE, true, $expiration );
			$this->fail( 'Expected invalid expiration to throw.' );
		} catch ( InvalidArgumentException $exception ) {
			$this->assertSame( array(), $backend->acquire_calls );
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

	/**
	 * @dataProvider invalid_lock_levels
	 */
	public function test_lock_exists_rejects_invalid_lock_level_without_calling_backend( $level ): void {
		$backend = new WP_Lock_Fake_Backend();
		$lock    = new WP_Lock( 'resource', $backend );

		try {
			$lock->lock_exists( $level );
			$this->fail( 'Expected invalid lock level to throw.' );
		} catch ( InvalidArgumentException $exception ) {
			$this->assertSame( array(), $backend->exists_calls );
		}
	}

	public function invalid_lock_levels(): array {
		return array(
			'zero'          => array( 0 ),
			'unknown int'   => array( 16 ),
			'numeric string' => array( (string) WP_Lock::WRITE ),
			'null'          => array( null ),
		);
	}

	public function test_lock_exists_delegates_default_and_explicit_levels(): void {
		$backend                = new WP_Lock_Fake_Backend();
		$backend->exists_result = true;
		$lock                   = new WP_Lock( 'resource', $backend );

		$this->assertTrue( $lock->lock_exists() );
		$backend->exists_result = false;
		$this->assertFalse( $lock->lock_exists( WP_Lock::READ ) );
		$this->assertSame(
			array(
				array( 'resource', WP_Lock::WRITE ),
				array( 'resource', WP_Lock::READ ),
			),
			$backend->exists_calls
		);
	}

	public function test_constructor_rejects_backend_that_does_not_implement_interface(): void {
		$this->expectException( InvalidArgumentException::class );

		new WP_Lock( 'resource', new stdClass() );
	}

	/**
	 * @dataProvider invalid_resource_ids
	 */
	public function test_constructor_rejects_non_string_resource_id( $resource_id ): void {
		$this->expectException( InvalidArgumentException::class );

		new WP_Lock( $resource_id, new WP_Lock_Fake_Backend() );
	}

	public function invalid_resource_ids(): array {
		return array(
			'integer' => array( 123 ),
			'null'    => array( null ),
			'array'   => array( array( 'resource' ) ),
			'object'  => array( new stdClass() ),
		);
	}
}
