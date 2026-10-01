<?php
/** E3-03 public protocol diagnostic. Run only on the disposable wp_lock_e1 socket. */
if ( ! extension_loaded( 'mysqli' ) || ! function_exists( 'pcntl_fork' ) || ! function_exists( 'pcntl_waitpid' ) || ! function_exists( 'posix_kill' ) ) {
	fwrite( STDERR, "mysqli, PCNTL and POSIX are required.\n" );
	exit( 2 );
}
$socket = getenv( 'WP_LOCK_E1_SOCKET' );
$wp_src = getenv( 'WP_LOCK_E1_WP' );
if ( 'wp_lock_e1' !== getenv( 'WP_LOCK_E1_DISPOSABLE' ) || ! $socket || ! file_exists( $socket ) || 'socket' !== filetype( $socket ) || ! $wp_src || ! is_file( $wp_src . '/wp-includes/class-wpdb.php' ) ) {
	fwrite( STDERR, "Use only the disposable wp_lock_e1 socket and a released WordPress source.\n" );
	exit( 2 );
}
define( 'ABSPATH', rtrim( $wp_src, '/' ) . '/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_NAME', 'wp_lock_e1' );
define( 'DB_HOST', 'localhost:' . $socket );
require ABSPATH . 'wp-includes/compat.php';
require ABSPATH . 'wp-includes/plugin.php';
require ABSPATH . 'wp-includes/load.php';
require ABSPATH . 'wp-includes/class-wpdb.php';
require ABSPATH . 'wp-includes/version.php';
require __DIR__ . '/../../lib/backend/class-wp-lock-backend.php';
require __DIR__ . '/../../lib/class-wp-lock.php';
require __DIR__ . '/../../lib/backend/class-wp-lock-backend-db.php';
require __DIR__ . '/../../lib/backend/class-wp-lock-foundations.php';

use iTRON\WP_Lock\WP_Lock;
use iTRON\WP_Lock\WP_Lock_Backend_DB;
use iTRON\WP_Lock\WP_Lock_Foundations;
use iTRON\WP_Lock\WP_Lock_Ownership_Uncertain;
use iTRON\WP_Lock\WP_Lock_Ownership_Lost;

function wp_debug_backtrace_summary() { return 'E3 ownership diagnostic'; }
function check( $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function e3_report_error( Throwable $error, string $context ): void {
	fwrite( STDERR, $context . "\n" );
	for ( $depth = 0; null !== $error; ++$depth, $error = $error->getPrevious() ) {
		fwrite( STDERR, $depth . ': ' . get_class( $error ) . ' code=' . $error->getCode() . ': ' . $error->getMessage() . "\n" );
	}
}
function sql( string $query ): void {
	global $wpdb;
	check( false !== $wpdb->query( $query ) && empty( $wpdb->last_error ), 'Setup SQL failed: ' . $wpdb->last_error );
}
function connect( string $isolation ): wpdb {
	$db = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$db->prefix = 'e3_';
	$db->suppress_errors( true );
	check( false !== $db->query( 'SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation ), 'Isolation setup failed.' );
	return $db;
}
function count_owners( string $id ): int {
	global $wpdb;
	$n = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM e3_lock WHERE lock_key = %s', md5( $id ) ) );
	check( empty( $wpdb->last_error ), 'Owner count failed.' );
	return (int) $n;
}
function case_ok( string $name, callable $run ): void {
	$run();
	echo json_encode( array( 'case' => $name, 'pass' => true ) ) . "\n";
}
function fail_query_once( string $needle ): callable {
	$filter = function( $query ) use ( $needle, &$filter ) {
		if ( false !== strpos( $query, $needle ) ) {
			remove_filter( 'query', $filter );
			return 'SELECT * FROM e3_missing_injected_table';
		}
		return $query;
	};
	add_filter( 'query', $filter );
	return $filter;
}
function zero_delete_once( string $id, bool &$injected ): callable {
	$filter = function( $query ) use ( $id, &$filter, &$injected ) {
		if ( false !== strpos( $query, 'DELETE FROM `e3_lock`' ) && false !== strpos( $query, md5( $id ) ) ) {
			remove_filter( 'query', $filter );
			$injected = true;
			return $query . ' AND id = 0';
		}
		return $query;
	};
	add_filter( 'query', $filter );
	return $filter;
}
function kill_controlled_once( string $needle, bool &$injected ): callable {
	$filter = function( $query ) use ( $needle, &$filter, &$injected ) {
		global $wpdb;
		$matches = 'COMMIT' === $needle ? 'COMMIT' === strtoupper( trim( $query ) ) : false !== strpos( $query, $needle );
		if ( $matches ) {
			remove_filter( 'query', $filter );
			$controlled = null;
			foreach ( debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT ) as $frame ) {
				if ( isset( $frame['object'] ) && $frame['object'] instanceof \iTRON\WP_Lock\WP_Lock_Foundation_DB ) {
					$controlled = $frame['object'];
					break;
				}
			}
			check( null !== $controlled, 'Controlled connection for fault injection was not found.' );
			$handle = new ReflectionProperty( wpdb::class, 'dbh' );
			if ( PHP_VERSION_ID < 80100 ) {
				$handle->setAccessible( true );
			}
			$dbh = $handle->getValue( $controlled );
			check( $dbh instanceof mysqli, 'Controlled connection handle was unavailable.' );
			$injected = false !== $wpdb->query( 'KILL CONNECTION ' . mysqli_thread_id( $dbh ) ) && empty( $wpdb->last_error );
			check( $injected, 'Controlled connection kill failed.' );
		}
		return $query;
	};
	add_filter( 'query', $filter );
	return $filter;
}
function public_first_pair( string $isolation, int $level, int $expected ): void {
	global $wpdb;
	$id = 'first-' . uniqid();
	$children = array();
	try {
		foreach ( array( 0, 1 ) as $index ) {
			$pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
			check( false !== $pair, 'First-acquirer socket unavailable.' );
			$pid = pcntl_fork();
			check( -1 !== $pid, 'First-acquirer fork failed.' );
			if ( 0 === $pid ) {
				fclose( $pair[0] );
				$step = 'connect';
				$connection = null;
				try {
					$wpdb = connect( $isolation );
					$step = 'identify caller connection';
					$connection = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
					$step = 'construct lock';
					$lock = new WP_Lock( $id, new WP_Lock_Backend_DB( 1.5 ) );
					$step = 'wait for start';
					fwrite( $pair[1], "ready\n" );
					check( "go\n" === fgets( $pair[1] ), 'First-acquirer start signal missing.' );
					$step = 'acquire';
					$acquired = $lock->acquire( $level, true, 0 );
					$step = 'wait for release';
					fwrite( $pair[1], json_encode( array( 'acquired' => $acquired, 'connection' => $connection ) ) . "\n" );
					check( "release\n" === fgets( $pair[1] ), 'First-acquirer release signal missing.' );
					$step = 'release';
					if ( $acquired ) { $lock->release(); }
					exit( 0 );
				} catch ( Throwable $error ) {
					e3_report_error( $error, 'First-acquirer child=' . $index . ' isolation=' . $isolation . ' level=' . $level . ' caller_connection=' . ( null === $connection ? 'unknown' : $connection ) . ' step=' . $step );
					exit( 1 );
				}
			}
			fclose( $pair[1] );
			stream_set_timeout( $pair[0], 6 );
			$children[] = array( $pid, $pair[0] );
		}
		foreach ( $children as $child ) { check( "ready\n" === fgets( $child[1] ), 'First-acquirer was not ready.' ); }
		foreach ( $children as $child ) { fwrite( $child[1], "go\n" ); }
		$results = array();
		foreach ( $children as $child ) {
			$reply = json_decode( (string) fgets( $child[1] ), true );
			check( is_array( $reply ) && isset( $reply['acquired'], $reply['connection'] ), 'First-acquirer result missing.' );
			$results[] = $reply;
		}
		check( $results[0]['connection'] !== $results[1]['connection'], 'First-acquirers shared a caller connection.' );
		check( $expected === (int) $results[0]['acquired'] + (int) $results[1]['acquired'], 'First-acquirer grant count is wrong.' );
		check( $expected === count_owners( $id ), 'First-acquirer owners were not held through observation.' );
	} finally {
		foreach ( $children as $child ) { fwrite( $child[1], "release\n" ); }
		foreach ( $children as $child ) {
			fclose( $child[1] );
			pcntl_waitpid( $child[0], $status );
			check( pcntl_wifexited( $status ) && 0 === pcntl_wexitstatus( $status ), 'First-acquirer child failed.' );
		}
	}
	check( 0 === count_owners( $id ), 'First-acquirer release left an owner.' );
}
function public_repeated_read_pair( string $isolation ): void {
	global $wpdb;
	$id = 'repeated-read-' . uniqid();
	$seed = new WP_Lock( $id );
	check( $seed->acquire( WP_Lock::READ, false, 0 ), 'Repeated-read resource setup failed.' );
	$seed->release();
	check( 0 === count_owners( $id ), 'Repeated-read resource setup release left an owner.' );
	$children = array();
	try {
		foreach ( array( 0, 1 ) as $index ) {
			$pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
			check( false !== $pair, 'Repeated-read socket unavailable.' );
			$pid = pcntl_fork();
			check( -1 !== $pid, 'Repeated-read fork failed.' );
			if ( 0 === $pid ) {
				fclose( $pair[0] );
				try {
					$wpdb = connect( $isolation );
					$connection = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
					$lock = new WP_Lock( $id, new WP_Lock_Backend_DB( 5.0 ) );
					fwrite( $pair[1], "ready\n" );
					check( "go\n" === fgets( $pair[1] ), 'Repeated-read start signal missing.' );
					for ( $attempt = 0; $attempt < 25; ++$attempt ) {
						check( $lock->acquire( WP_Lock::READ, true, 0 ), 'Repeated-read acquisition failed at attempt ' . $attempt . '.' );
						usleep( 1000 );
						$lock->release();
					}
					fwrite( $pair[1], $connection . "\n" );
					exit( 0 );
				} catch ( Throwable $error ) {
					e3_report_error( $error, 'Repeated-read child=' . $index . ' isolation=' . $isolation );
					exit( 1 );
				}
			}
			fclose( $pair[1] );
			stream_set_timeout( $pair[0], 15 );
			$children[] = array( $pid, $pair[0] );
		}
		foreach ( $children as $child ) { check( "ready\n" === fgets( $child[1] ), 'Repeated-read child was not ready.' ); }
		foreach ( $children as $child ) { fwrite( $child[1], "go\n" ); }
		$connections = array();
		foreach ( $children as $child ) { $connections[] = (int) fgets( $child[1] ); }
		check( $connections[0] > 0 && $connections[1] > 0 && $connections[0] !== $connections[1], 'Repeated readers did not use independent connections.' );
	} finally {
		foreach ( $children as $child ) {
			fclose( $child[1] );
			pcntl_waitpid( $child[0], $status );
			check( pcntl_wifexited( $status ) && 0 === pcntl_wexitstatus( $status ), 'Repeated-read child failed.' );
		}
	}
	check( 0 === count_owners( $id ), 'Repeated-read left an owner.' );
}
function public_lock_wait_retry( string $isolation ): void {
	global $wpdb;
	$id = 'lock-wait-' . uniqid();
	$pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
	check( false !== $pair, 'Lock-wait socket unavailable.' );
	$pid = pcntl_fork();
	check( -1 !== $pid, 'Lock-wait fork failed.' );
	if ( 0 === $pid ) {
		fclose( $pair[0] );
		try {
			$wpdb = connect( $isolation );
			sql( 'START TRANSACTION' );
			sql( 'INSERT INTO e3_probe (id) VALUES (1)' );
			$rollbacks = 0;
			$filter = function( $query ) use ( &$rollbacks, $pair ) {
				if ( 'START TRANSACTION' !== $query && 'ROLLBACK' !== $query ) { return $query; }
				foreach ( debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT ) as $frame ) {
					if ( ! isset( $frame['object'] ) || ! $frame['object'] instanceof WP_Lock_Foundations ) { continue; }
					if ( 'START TRANSACTION' === $query ) {
						$property = new ReflectionProperty( WP_Lock_Foundations::class, 'db' );
						if ( PHP_VERSION_ID < 80100 ) {
							$property->setAccessible( true );
						}
						check( false !== $property->getValue( $frame['object'] )->query( 'SET SESSION innodb_lock_wait_timeout = 1' ), 'Controlled timeout setup failed.' );
					} else {
						++$rollbacks;
						fwrite( $pair[1], "rolled_back\n" );
					}
					break;
				}
				return $query;
			};
			add_filter( 'query', $filter );
			$lock = new WP_Lock( $id, new WP_Lock_Backend_DB( 3.5, 2 ) );
			fwrite( $pair[1], "ready\n" );
			check( "go\n" === fgets( $pair[1] ), 'Lock-wait start signal missing.' );
			check( $lock->acquire( WP_Lock::WRITE, true, 0 ), 'Lock-wait retry did not acquire.' );
			remove_filter( 'query', $filter );
			check( $rollbacks >= 1, 'No controlled transaction rollback occurred.' );
			check( 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM e3_probe WHERE id = 1' ), 'Controlled rollback touched caller work.' );
			fwrite( $pair[1], "acquired\n" );
			check( "release\n" === fgets( $pair[1] ), 'Lock-wait release signal missing.' );
			$lock->release();
			sql( 'ROLLBACK' );
			exit( 0 );
		} catch ( Throwable $error ) {
			fwrite( STDERR, $error->getMessage() . "\n" );
			exit( 1 );
		}
	}
	fclose( $pair[1] );
	stream_set_timeout( $pair[0], 7 );
	$holder = connect( $isolation );
	try {
		check( false !== $holder->query( 'START TRANSACTION' ), 'Holder transaction failed.' );
		check( false !== $holder->query( $holder->prepare( 'INSERT INTO e3_lock_resource (lock_key) VALUES (%s)', md5( $id ) ) ), 'Holder resource insert failed.' );
		check( "ready\n" === fgets( $pair[0] ), 'Lock-wait child was not ready.' );
		fwrite( $pair[0], "go\n" );
		$start = microtime( true );
		check( "rolled_back\n" === fgets( $pair[0] ) && microtime( true ) - $start >= 0.9, 'Actual InnoDB lock-wait rollback was not observed.' );
		check( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM e3_probe WHERE id = 1' ), 'Uncommitted caller work leaked.' );
		check( false !== $holder->query( 'COMMIT' ), 'Holder commit failed.' );
		check( "acquired\n" === fgets( $pair[0] ) && 1 === count_owners( $id ), 'Retry owner was not held.' );
	} finally {
		$holder->query( 'ROLLBACK' );
		$holder->close();
		fwrite( $pair[0], "release\n" );
		fclose( $pair[0] );
		pcntl_waitpid( $pid, $status );
		check( pcntl_wifexited( $status ) && 0 === pcntl_wexitstatus( $status ), 'Lock-wait child failed.' );
	}
	check( 0 === count_owners( $id ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM e3_probe WHERE id = 1' ), 'Lock-wait cleanup failed.' );
}

function public_release_lock_wait_retry( string $isolation ): void {
	global $wpdb;
	$id = 'release-wait-' . uniqid();
	$pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
	check( false !== $pair, 'Release-wait socket unavailable.' );
	$pid = pcntl_fork();
	check( -1 !== $pid, 'Release-wait fork failed.' );
	if ( 0 === $pid ) {
		fclose( $pair[0] );
		try {
			$wpdb = connect( $isolation );
			$rollbacks = 0;
			$filter = function( $query ) use ( &$rollbacks, $pair ) {
				if ( 'START TRANSACTION' !== $query && 'ROLLBACK' !== $query ) { return $query; }
				foreach ( debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT ) as $frame ) {
					if ( ! isset( $frame['object'] ) || ! $frame['object'] instanceof WP_Lock_Foundations ) { continue; }
					if ( 'START TRANSACTION' === $query ) {
						$property = new ReflectionProperty( WP_Lock_Foundations::class, 'db' );
						if ( PHP_VERSION_ID < 80100 ) {
							$property->setAccessible( true );
						}
						check( false !== $property->getValue( $frame['object'] )->query( 'SET SESSION innodb_lock_wait_timeout = 1' ), 'Controlled release timeout setup failed.' );
					} else {
						++$rollbacks;
						fwrite( $pair[1], "rolled_back\n" );
					}
					break;
				}
				return $query;
			};
			add_filter( 'query', $filter );
			$lock = new WP_Lock( $id, new WP_Lock_Backend_DB( 3.5, 2 ) );
			check( $lock->acquire( WP_Lock::WRITE, false, 0 ), 'Release-wait acquisition failed.' );
			fwrite( $pair[1], "acquired\n" );
			check( "release\n" === fgets( $pair[1] ), 'Release-wait start signal missing.' );
			$lock->release();
			remove_filter( 'query', $filter );
			check( $rollbacks >= 1, 'No controlled release rollback occurred.' );
			fwrite( $pair[1], "released\n" );
			exit( 0 );
		} catch ( Throwable $error ) {
			e3_report_error( $error, 'Release-wait child failed.' );
			exit( 1 );
		}
	}
	fclose( $pair[1] );
	stream_set_timeout( $pair[0], 7 );
	$holder = connect( $isolation );
	try {
		check( "acquired\n" === fgets( $pair[0] ) && 1 === count_owners( $id ), 'Release-wait owner was not held.' );
		check( false !== $holder->query( 'START TRANSACTION' ), 'Release-wait holder transaction failed.' );
		check( md5( $id ) === $holder->get_var( $holder->prepare( 'SELECT lock_key FROM e3_lock_resource WHERE lock_key = %s FOR UPDATE', md5( $id ) ) ), 'Release-wait holder could not lock resource.' );
		fwrite( $pair[0], "release\n" );
		$start = microtime( true );
		check( "rolled_back\n" === fgets( $pair[0] ) && microtime( true ) - $start >= 0.9, 'Actual InnoDB release lock-wait rollback was not observed.' );
		check( 1 === count_owners( $id ), 'Release wait removed the owner before retry.' );
		check( false !== $holder->query( 'COMMIT' ), 'Release-wait holder commit failed.' );
		check( "released\n" === fgets( $pair[0] ), 'Release retry did not complete.' );
	} finally {
		$holder->query( 'ROLLBACK' );
		$holder->close();
		fclose( $pair[0] );
		pcntl_waitpid( $pid, $status );
		check( pcntl_wifexited( $status ) && 0 === pcntl_wexitstatus( $status ), 'Release-wait child failed.' );
	}
	check( 0 === count_owners( $id ), 'Release retry left an owner.' );
}

try {
	global $wpdb, $wp_version;
	$wpdb = connect( 'REPEATABLE READ' );
	sql( 'DROP TABLE IF EXISTS e3_lock_resource' );
	sql( 'DROP TABLE IF EXISTS e3_lock' );
	sql( 'DROP TABLE IF EXISTS e3_options' );
	sql( 'CREATE TABLE e3_options (option_name varchar(191) NOT NULL PRIMARY KEY, option_value longtext NOT NULL, autoload varchar(20) NOT NULL) ENGINE=InnoDB' );
	sql( 'DROP TABLE IF EXISTS e3_probe' );
	sql( 'CREATE TABLE e3_probe (id int NOT NULL PRIMARY KEY) ENGINE=InnoDB' );
	WP_Lock_Foundations::prepare_schema();
	$server = $wpdb->get_var( 'SELECT VERSION()' );
	foreach ( array( 'REPEATABLE READ', 'READ COMMITTED' ) as $isolation ) {
		$wpdb = connect( $isolation );
		$engine = $wpdb->get_var( "SELECT engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'e3_lock'" );
		$actual = $wpdb->get_var( "SHOW SESSION VARIABLES LIKE 'transaction_isolation'", 1 );
		if ( null === $actual ) { $actual = $wpdb->get_var( "SHOW SESSION VARIABLES LIKE 'tx_isolation'", 1 ); }
		$actual = str_replace( '-', ' ', strtoupper( (string) $actual ) );
		check( 'InnoDB' === $engine && $isolation === $actual && empty( $wpdb->last_error ), 'Environment did not verify.' );
		echo json_encode( array( 'case' => 'environment', 'isolation' => $actual, 'engine' => $engine, 'server' => $server, 'php' => PHP_VERSION, 'wordpress' => $wp_version ) ) . "\n";
		case_ok( 'simultaneous_first_write_' . $isolation, function() use ( $isolation ) { public_first_pair( $isolation, WP_Lock::WRITE, 1 ); } );
		case_ok( 'simultaneous_first_read_' . $isolation, function() use ( $isolation ) { public_first_pair( $isolation, WP_Lock::READ, 2 ); } );
		case_ok( 'repeated_shared_read_' . $isolation, function() use ( $isolation ) { public_repeated_read_pair( $isolation ); } );
		case_ok( 'real_lock_wait_retry_' . $isolation, function() use ( $isolation ) { public_lock_wait_retry( $isolation ); } );
		case_ok( 'real_release_lock_wait_retry_' . $isolation, function() use ( $isolation ) { public_release_lock_wait_retry( $isolation ); } );
		case_ok( 'shared_and_exclusive_' . $isolation, function() use ( $isolation ) {
			$id = 'public-' . $isolation;
			$a = new WP_Lock( $id, new WP_Lock_Backend_DB( 0.03 ) );
			$b = new WP_Lock( $id, new WP_Lock_Backend_DB( 0.03 ) );
			$c = new WP_Lock( $id, new WP_Lock_Backend_DB( 0.03 ) );
			check( $a->acquire( WP_Lock::READ, false, 0 ), 'First reader failed.' );
			check( $b->acquire( WP_Lock::READ, false, 0 ), 'Second reader failed.' );
			check( 2 === count_owners( $id ) && ! $c->acquire( WP_Lock::WRITE, false, 0 ), 'Writer overlapped readers.' );
			$b->release(); $a->release();
			check( $c->acquire( WP_Lock::WRITE, false, 0 ), 'Uncontended writer failed.' );
			check( ! $a->acquire( WP_Lock::READ, false, 0 ) && ! $b->acquire( WP_Lock::WRITE, false, 0 ), 'Writer did not exclude another owner.' );
			$c->release();
		} );
		case_ok( 'independent_process_conflict_' . $isolation, function() use ( $isolation ) {
			global $wpdb;
			$id = 'fork-' . uniqid();
			$owner = new WP_Lock( $id );
			check( $owner->acquire( WP_Lock::WRITE, false, 0 ), 'Parent owner failed.' );
			$parent_connection = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
			$pid = pcntl_fork();
			check( -1 !== $pid, 'Fork failed.' );
			if ( 0 === $pid ) {
				$wpdb = connect( $isolation );
				$child_connection = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
				$waiter = new WP_Lock( $id );
				$code = $child_connection !== $parent_connection && ! $waiter->acquire( WP_Lock::READ, false, 0 ) ? 0 : 1;
				pcntl_exec( '/bin/sh', array( '-c', 'exit ' . $code ) );
				exit( $code );
			}
			pcntl_waitpid( $pid, $status );
			check( pcntl_wifexited( $status ) && 0 === pcntl_wexitstatus( $status ) && 1 === count_owners( $id ), 'Independent process overlapped owner.' );
			$owner->release();
		} );
		case_ok( 'caller_rollback_' . $isolation, function() {
			global $wpdb;
			$id = 'outer-' . uniqid();
			sql( 'START TRANSACTION' );
			$lock = new WP_Lock( $id );
			check( $lock->acquire( WP_Lock::WRITE, false, 0 ), 'Outer-transaction acquisition failed.' );
			sql( 'ROLLBACK' );
			check( 1 === count_owners( $id ) && $lock->lock_exists(), 'Caller rollback removed ownership.' );
			$lock->release();
		} );
		case_ok( 'failed_exists_and_release_retry_' . $isolation, function() {
			$id = 'error-' . uniqid();
			$lock = new WP_Lock( $id );
			check( $lock->acquire( WP_Lock::WRITE, false, 0 ), 'Error-case acquisition failed.' );
			$filter = fail_query_once( 'SELECT 1 FROM `e3_lock`' );
			try {
				$lock->lock_exists();
				throw new RuntimeException( 'SELECT error looked like absence.' );
			} catch ( RuntimeException $error ) {
				check( false === strpos( $error->getMessage(), 'looked like absence' ), 'SELECT error looked like absence.' );
			} finally { remove_filter( 'query', $filter ); }
			$filter = fail_query_once( 'DELETE FROM `e3_lock`' );
			try {
				$lock->release();
				throw new RuntimeException( 'Failed release returned success.' );
			} catch ( RuntimeException $error ) {
				check( false === strpos( $error->getMessage(), 'returned success' ), 'Failed release returned success.' );
			} finally { remove_filter( 'query', $filter ); }
			check( 1 === count_owners( $id ), 'Failed release lost owner.' );
			try { $lock->acquire( WP_Lock::WRITE, false, 0 ); throw new RuntimeException( 'Failed release cleared held state.' ); }
			catch ( LogicException $expected ) {}
			$lock->release();
			check( 0 === count_owners( $id ), 'Release retry failed.' );
		} );
		case_ok( 'uncertain_insert_' . $isolation, function() {
			$id = 'insert-' . uniqid();
			$lock = new WP_Lock( $id );
			$injected = false;
			$filter = kill_controlled_once( 'INSERT INTO `e3_lock`', $injected );
			try {
				$lock->acquire( WP_Lock::WRITE, false, 0 );
				throw new RuntimeException( 'Lost INSERT returned a grant.' );
			} catch ( WP_Lock_Ownership_Uncertain $expected ) {
			} finally { remove_filter( 'query', $filter ); }
			check( $injected, 'Lost INSERT fault was not injected.' );
			$lock->release();
			check( 0 === count_owners( $id ), 'Uncertain INSERT cleanup failed.' );
		} );
		case_ok( 'uncertain_commit_' . $isolation, function() {
			$id = 'commit-' . uniqid();
			$lock = new WP_Lock( $id );
			$injected = false;
			$filter = kill_controlled_once( 'COMMIT', $injected );
			try {
				$lock->acquire( WP_Lock::WRITE, false, 0 );
				throw new RuntimeException( 'Lost COMMIT returned a grant.' );
			} catch ( WP_Lock_Ownership_Uncertain $expected ) {
			} finally { remove_filter( 'query', $filter ); }
			check( $injected, 'Lost COMMIT fault was not injected.' );
			$lock->release();
			check( 0 === count_owners( $id ), 'Uncertain COMMIT cleanup failed.' );
		} );
		case_ok( 'committed_unconfirmed_zero_row_cleanup_' . $isolation, function() {
			$id = 'post-commit-' . uniqid();
			$lock = new WP_Lock( $id );
			$count = 0;
			$filter = function( $query ) use ( &$count ) {
				if ( false !== strpos( $query, 'SELECT UNIX_TIMESTAMP(NOW(6))' ) && ++$count === 3 ) {
					return 'SELECT * FROM e3_missing_injected_table';
				}
				return $query;
			};
			add_filter( 'query', $filter );
			try {
				$lock->acquire( WP_Lock::WRITE, false, 60 );
				throw new RuntimeException( 'Unconfirmed owner returned a grant.' );
			} catch ( WP_Lock_Ownership_Uncertain $expected ) {
			} finally { remove_filter( 'query', $filter ); }
			check( 3 === $count, 'Post-commit time failure was not injected.' );
			check( 1 === count_owners( $id ), 'Committed attempt was not retained for cleanup.' );
			$injected = false;
			$filter = zero_delete_once( $id, $injected );
			try {
				$lock->release();
				throw new RuntimeException( 'Zero-row cleanup returned release success.' );
			} catch ( WP_Lock_Ownership_Uncertain $expected ) {
			} finally { remove_filter( 'query', $filter ); }
			check( $injected && 1 === count_owners( $id ), 'Zero-row release cleanup was not observed.' );
			$injected = false;
			$filter = zero_delete_once( $id, $injected );
			try {
				$lock->acquire( WP_Lock::WRITE, false, 0 );
				throw new RuntimeException( 'Zero-row cleanup allowed acquisition.' );
			} catch ( WP_Lock_Ownership_Uncertain $expected ) {
			} finally { remove_filter( 'query', $filter ); }
			check( $injected && 1 === count_owners( $id ), 'Zero-row acquire cleanup was not observed.' );
			$lock->release();
			check( 0 === count_owners( $id ), 'Committed unconfirmed attempt was not cleaned.' );
		} );
		case_ok( 'namespace_capture_' . $isolation, function() {
			global $wpdb;
			$id = 'namespace-' . uniqid();
			$lock = new WP_Lock( $id );
			check( $lock->acquire( WP_Lock::WRITE, false, 0 ), 'Namespace owner failed.' );
			$wpdb->prefix = 'other_';
			try { $lock->release(); } finally { $wpdb->prefix = 'e3_'; }
			check( 0 === count_owners( $id ), 'Release changed namespace.' );
		} );
		case_ok( 'predecessor_safe_release_' . $isolation, function() {
			global $wpdb;
			$id = 'stale-' . uniqid();
			$old = new WP_Lock( $id );
			check( $old->acquire( WP_Lock::WRITE, false, 0 ), 'Predecessor acquire failed.' );
			sql( $wpdb->prepare( 'DELETE FROM e3_lock WHERE lock_key = %s', md5( $id ) ) );
			$new = new WP_Lock( $id );
			check( $new->acquire( WP_Lock::WRITE, false, 0 ), 'Successor acquire failed.' );
			try { $old->release(); throw new RuntimeException( 'Stale release succeeded.' ); }
			catch ( WP_Lock_Ownership_Lost $expected ) {}
			check( 1 === count_owners( $id ) && $new->lock_exists(), 'Stale release removed successor.' );
			$new->release();
		} );
	}
	echo json_encode( array( 'case' => 'summary', 'pass' => true, 'php' => PHP_VERSION, 'wordpress' => $wp_version ) ) . "\n";
	exit( 0 );
} catch ( Throwable $error ) {
	e3_report_error( $error, 'E3 ownership diagnostic failed.' );
	echo json_encode( array( 'case' => 'summary', 'pass' => false, 'php' => PHP_VERSION, 'wordpress' => $wp_version ) ) . "\n";
	exit( 1 );
}
