<?php
/**
 * Parametrized callbacks compatible with PHP 5.2 and 7.2
 */
class WP_Lock_Backend_Callback {
	private $args;
	private $callback;

	public function __construct( $callback, $args = array() ) {
		$this->callback = $callback;
		$this->args = $args;
	}

	public function run() {
		return call_user_func_array( $this->callback, $this->args );
	}
}

/**
 * Fork and test in child process.
 */
function run_in_child( $callback ) {
	global $wpdb;

	if ( ! function_exists( 'pcntl_fork' ) || ! function_exists( 'pcntl_waitpid' ) ) {
		throw new RuntimeException( 'PCNTL process control is unavailable.' );
	}

	$wpdb->close();

	$child = pcntl_fork();
	if ( -1 === $child ) {
		$wpdb->db_connect( false );
		throw new RuntimeException( 'Unable to fork the test process.' );
	}

	if ( 0 === $child ) {
		$wpdb->db_connect( false );

		try {
			$exit_code = call_user_func( $callback ) ? 0 : 1;
		} catch ( Throwable $error ) {
			fwrite( STDERR, $error->getMessage() . PHP_EOL );
			$exit_code = 1;
		}

		// Replace the forked PHPUnit process so inherited PHP shutdown handlers
		// cannot keep the child alive or finalize the parent test run twice.
		if ( function_exists( 'pcntl_exec' ) ) {
			pcntl_exec( '/bin/sh', array( '-c', 'exit ' . $exit_code ) );
		}

		exit( $exit_code );
	}

	$wpdb->db_connect( false );

	return $child;
}

/**
 * Terminate and reap child processes that are still running.
 *
 * @param int[] $children Child process IDs.
 *
 * @return void
 */
function wp_lock_terminate_children( array $children ): void {
	foreach ( $children as $child ) {
		$result = pcntl_waitpid( $child, $status, WNOHANG );
		if ( 0 === $result && function_exists( 'posix_kill' ) ) {
			posix_kill( $child, SIGKILL );
			pcntl_waitpid( $child, $status );
		}
	}
}

/**
 * Wait for a group of child processes without allowing an indefinite hang.
 *
 * @param int[] $children        Child process IDs.
 * @param float $timeout_seconds Maximum total wait time.
 *
 * @return int[] Exit statuses keyed by process ID.
 */
function wp_lock_wait_for_children( array $children, float $timeout_seconds = 15.0 ): array {
	$remaining = array_fill_keys( $children, true );
	$statuses  = array();
	$deadline  = microtime( true ) + $timeout_seconds;

	while ( ! empty( $remaining ) && microtime( true ) < $deadline ) {
		foreach ( array_keys( $remaining ) as $child ) {
			$result = pcntl_waitpid( $child, $status, WNOHANG );
			if ( $child === $result ) {
				$statuses[ $child ] = $status;
				unset( $remaining[ $child ] );
			} elseif ( -1 === $result ) {
				wp_lock_terminate_children( array_keys( $remaining ) );
				throw new RuntimeException( "Unable to wait for child process {$child}." );
			}
		}

		if ( ! empty( $remaining ) ) {
			usleep( 10000 );
		}
	}

	if ( ! empty( $remaining ) ) {
		$timed_out = array_keys( $remaining );
		wp_lock_terminate_children( $timed_out );
		throw new RuntimeException( 'Child process timeout: ' . implode( ', ', $timed_out ) );
	}

	return $statuses;
}
