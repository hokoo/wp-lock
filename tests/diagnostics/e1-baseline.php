<?php
/**
 * E1-02 legacy diagnostic. Run only against a disposable wp_lock_e1 database.
 * WP_LOCK_E1_DISPOSABLE=wp_lock_e1 WP_LOCK_E1_SOCKET=/path/mysql.sock WP_LOCK_E1_WP=/path/to/wp/src php7.4 tests/diagnostics/e1-baseline.php
 */

if ( ! extension_loaded( 'mysqli' ) || ! function_exists( 'pcntl_fork' ) || ! function_exists( 'pcntl_waitpid' ) || ! function_exists( 'posix_kill' ) ) {
	fwrite( STDERR, "mysqli, PCNTL, and POSIX are required; this diagnostic cannot be skipped.\n" );
	exit( 2 );
}

$socket = getenv( 'WP_LOCK_E1_SOCKET' );
$wp_src = getenv( 'WP_LOCK_E1_WP' );
if ( 'wp_lock_e1' !== getenv( 'WP_LOCK_E1_DISPOSABLE' ) || ! $socket || ! file_exists( $socket ) || 'socket' !== filetype( $socket ) || ! $wp_src || ! is_file( $wp_src . '/wp-includes/class-wpdb.php' ) ) {
	fwrite( STDERR, "Set WP_LOCK_E1_DISPOSABLE=wp_lock_e1, WP_LOCK_E1_SOCKET to a disposable server socket, and WP_LOCK_E1_WP to WordPress source.\n" );
	exit( 2 );
}

define( 'ABSPATH', rtrim( $wp_src, '/' ) . '/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_DISPLAY', false );
require ABSPATH . 'wp-includes/compat.php';
require ABSPATH . 'wp-includes/plugin.php';
require ABSPATH . 'wp-includes/load.php';
require ABSPATH . 'wp-includes/class-wpdb.php';
require ABSPATH . 'wp-includes/version.php';
require __DIR__ . '/../../lib/backend/class-wp-lock-backend.php';
require __DIR__ . '/../../lib/class-wp-lock.php';
require __DIR__ . '/../../lib/backend/class-wp-lock-backend-db.php';

use iTRON\WP_Lock\WP_Lock;
use iTRON\WP_Lock\WP_Lock_Backend_DB;

function wp_debug_backtrace_summary() { return 'E1 diagnostic'; }

function e1_sql( $db, $query ) {
	$result = $db->query( $query );
	if ( false === $result ) {
		throw new RuntimeException( $db->last_error . ' [' . $query . ']' );
	}
	return $result;
}

function e1_connect( $isolation ) {
	// Check the named database before wpdb's full WordPress error-rendering path.
	mysqli_report( MYSQLI_REPORT_OFF );
	$probe = mysqli_init();
	if ( ! $probe || ! @mysqli_real_connect( $probe, 'localhost', 'root', '', 'wp_lock_e1', null, getenv( 'WP_LOCK_E1_SOCKET' ) ) ) {
		throw new RuntimeException( 'Cannot connect to disposable wp_lock_e1: ' . mysqli_connect_error() );
	}
	$probe->close();
	$db = new wpdb( 'root', '', 'wp_lock_e1', 'localhost:' . getenv( 'WP_LOCK_E1_SOCKET' ) );
	$db->prefix = 'e1_';
	$db->suppress_errors( true );
	if ( 'wp_lock_e1' !== $db->get_var( 'SELECT DATABASE()' ) ) {
		throw new RuntimeException( 'Refusing to mutate a database other than wp_lock_e1.' );
	}
	e1_sql( $db, 'SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation );
	e1_sql( $db, 'SET SESSION innodb_lock_wait_timeout = 2' );
	e1_sql( $db, "SET SESSION sql_mode = 'STRICT_ALL_TABLES'" );
	e1_sql( $db, 'SET NAMES utf8mb4' );
	return $db;
}

function e1_environment( $db ) {
	global $wp_version;
	$details = $db->get_row( 'SELECT VERSION() AS version, CONNECTION_ID() AS connection_id, @@autocommit AS autocommit, @@sql_mode AS sql_mode', ARRAY_A );
	$details['wordpress'] = $wp_version;
	$details['isolation'] = $db->get_var( "SHOW VARIABLES LIKE 'transaction_isolation'", 1 );
	if ( ! $details['isolation'] ) {
		$details['isolation'] = $db->get_var( "SHOW VARIABLES LIKE 'tx_isolation'", 1 );
	}
	$details['isolation'] = str_replace( '-', ' ', strtoupper( (string) $details['isolation'] ) );
	$details['engine'] = $db->get_var( "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'e1_lock'" );
	return $details;
}

function e1_count( $db ) {
	$count = $db->get_var( 'SELECT COUNT(*) FROM e1_lock' );
	if ( ! empty( $db->last_error ) ) {
		throw new RuntimeException( $db->last_error );
	}
	return (int) $count;
}

function e1_emit( $case, $data ) {
	echo json_encode( array( 'case' => $case, 'data' => $data ), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n";
}

function e1_line( $stream ) {
	$line = fgets( $stream );
	if ( false === $line || stream_get_meta_data( $stream )['timed_out'] ) {
		throw new RuntimeException( 'Worker response timed out or connection closed.' );
	}
	return trim( $line );
}

function e1_worker( $stream, $isolation, $level ) {
	global $wpdb;
	try {
		$wpdb = e1_connect( $isolation );
		fwrite( $stream, json_encode( e1_environment( $wpdb ) ) . "\n" );
		while ( 'go' === e1_line( $stream ) ) {
			$backend = new WP_Lock_Backend_DB( 0.1 );
			try {
				$result = $backend->acquire( 'e1-race', $level, false, 30 );
				fwrite( $stream, json_encode( array( 'acquired' => $result, 'error' => null ) ) . "\n" );
			} catch ( Throwable $error ) {
				$result = false;
				fwrite( $stream, json_encode( array( 'acquired' => false, 'error' => $error->getMessage() ) ) . "\n" );
			}
			// The parent observes both results and persisted rows before this release signal.
			if ( 'release' !== e1_line( $stream ) ) {
				throw new RuntimeException( 'Missing release signal.' );
			}
			if ( $result && ! $backend->release( 'e1-race' ) ) {
				throw new RuntimeException( 'Worker could not release its row.' );
			}
			fwrite( $stream, "released\n" );
		}
		return 0;
	} catch ( Throwable $error ) {
		fwrite( STDERR, $error->getMessage() . "\n" );
		return 1;
	}
}

function e1_reap( $workers ) {
	$failed = false;
	foreach ( $workers as $worker ) {
		$pid = $worker['pid'];
		$deadline = microtime( true ) + 5;
		do {
			$waited = pcntl_waitpid( $pid, $status, WNOHANG );
			if ( $pid === $waited ) {
				fclose( $worker['stream'] );
				$failed = $failed || ! pcntl_wifexited( $status ) || 0 !== pcntl_wexitstatus( $status );
				continue 2;
			}
			usleep( 10000 );
		} while ( 0 === $waited && microtime( true ) < $deadline );
		if ( 0 === $waited ) {
			posix_kill( $pid, SIGKILL );
			pcntl_waitpid( $pid, $status );
		}
		fclose( $worker['stream'] );
		$failed = true;
	}
	return ! $failed;
}

function e1_pair( $isolation, $levels, $rounds ) {
	global $wpdb;
	e1_sql( $wpdb, 'DELETE FROM e1_lock' );
	$wpdb->close();
	$workers = array();
	try {
		foreach ( $levels as $level ) {
			$pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
			if ( false === $pair ) {
				throw new RuntimeException( 'Unable to create worker socket pair.' );
			}
			$pid = pcntl_fork();
			if ( -1 === $pid ) {
				throw new RuntimeException( 'Unable to fork worker.' );
			}
			if ( 0 === $pid ) {
				fclose( $pair[0] );
				exit( e1_worker( $pair[1], $isolation, $level ) );
			}
			fclose( $pair[1] );
			stream_set_timeout( $pair[0], 15 );
			$workers[] = array( 'pid' => $pid, 'stream' => $pair[0] );
		}
		$wpdb = e1_connect( $isolation );
		$parent_environment = e1_environment( $wpdb );
		if ( $isolation !== $parent_environment['isolation'] ) {
			throw new RuntimeException( 'Parent isolation did not match the requested value.' );
		}
		$parent_id = $parent_environment['connection_id'];
		$connections = array( $parent_id );
		foreach ( $workers as $worker ) {
			$ready = json_decode( e1_line( $worker['stream'] ), true );
			if ( ! is_array( $ready ) || $isolation !== $ready['isolation'] ) {
				throw new RuntimeException( 'Worker isolation did not match the requested value.' );
			}
			$connections[] = $ready['connection_id'];
		}
		if ( 3 !== count( array_unique( $connections ) ) ) {
			throw new RuntimeException( 'Workers did not use independent database connections.' );
		}
		$both = 0;
		$one_or_more = 0;
		$errors = 0;
		$error_samples = array();
		$max_rows = 0;
		$row_mismatches = 0;
		for ( $round = 0; $round < $rounds; $round++ ) {
			foreach ( $workers as $worker ) {
				fwrite( $worker['stream'], "go\n" );
			}
			$results = array();
			foreach ( $workers as $worker ) {
				$results[] = json_decode( e1_line( $worker['stream'] ), true );
			}
			if ( ! is_array( $results[0] ) || ! is_array( $results[1] ) ) {
				throw new RuntimeException( 'Worker returned invalid JSON.' );
			}
			$acquired = (int) $results[0]['acquired'] + (int) $results[1]['acquired'];
			$rows = e1_count( $wpdb );
			$row_mismatches += $rows !== $acquired ? 1 : 0;
			$both += 2 === $acquired && 2 === $rows ? 1 : 0;
			$one_or_more += $acquired > 0 ? 1 : 0;
			$max_rows = max( $max_rows, $rows );
			if ( null !== $results[0]['error'] || null !== $results[1]['error'] ) {
				$errors++;
				if ( count( $error_samples ) < 2 ) {
					$error_samples[] = array( 'round' => $round + 1, 'results' => $results );
				}
			}
			foreach ( $workers as $worker ) {
				fwrite( $worker['stream'], "release\n" );
			}
			foreach ( $workers as $worker ) {
				if ( 'released' !== e1_line( $worker['stream'] ) ) {
					throw new RuntimeException( 'Worker did not acknowledge release.' );
				}
			}
			$row_mismatches += 0 !== e1_count( $wpdb ) ? 1 : 0;
		}
		foreach ( $workers as $worker ) {
			fwrite( $worker['stream'], "stop\n" );
		}
		$reaped = e1_reap( $workers );
		if ( ! $reaped ) {
			throw new RuntimeException( 'A worker failed or exceeded its reap deadline.' );
		}
		return compact( 'isolation', 'levels', 'rounds', 'connections', 'both', 'one_or_more', 'errors', 'error_samples', 'max_rows', 'row_mismatches' );
	} catch ( Throwable $error ) {
		foreach ( $workers as $worker ) {
			if ( 0 === pcntl_waitpid( $worker['pid'], $status, WNOHANG ) ) {
				posix_kill( $worker['pid'], SIGKILL );
				pcntl_waitpid( $worker['pid'], $status );
			}
			if ( is_resource( $worker['stream'] ) ) {
				fclose( $worker['stream'] );
			}
		}
		throw $error;
	}
}

function e1_ordered( $isolation, $owner_level ) {
	global $wpdb;
	e1_sql( $wpdb, 'DELETE FROM e1_lock' );
	$wpdb->close();
	$pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
	if ( false === $pair ) {
		throw new RuntimeException( 'Unable to create ordered-control socket pair.' );
	}
	$pid = pcntl_fork();
	if ( -1 === $pid ) {
		throw new RuntimeException( 'Unable to fork ordered-control worker.' );
	}
	if ( 0 === $pid ) {
		fclose( $pair[0] );
		exit( e1_worker( $pair[1], $isolation, WP_Lock::WRITE ) );
	}
	fclose( $pair[1] );
	stream_set_timeout( $pair[0], 15 );
	$worker = array( 'pid' => $pid, 'stream' => $pair[0] );
	$owner = null;
	$held = false;
	try {
		$wpdb = e1_connect( $isolation );
		$ready = json_decode( e1_line( $pair[0] ), true );
		$parent = e1_environment( $wpdb );
		if ( ! is_array( $ready ) || $isolation !== $ready['isolation'] || $isolation !== $parent['isolation'] || $ready['connection_id'] === $parent['connection_id'] ) {
			throw new RuntimeException( 'Ordered control lacks independent connections at requested isolation.' );
		}
		$owner = new WP_Lock_Backend_DB( 0.1 );
		$held = $owner->acquire( 'e1-race', $owner_level, false, 30 );
		$rows_held = e1_count( $wpdb );
		fwrite( $pair[0], "go\n" );
		$contended = json_decode( e1_line( $pair[0] ), true );
		$rows_contended = e1_count( $wpdb );
		fwrite( $pair[0], "release\n" );
		if ( 'released' !== e1_line( $pair[0] ) ) {
			throw new RuntimeException( 'Ordered control did not acknowledge first observation.' );
		}
		if ( $held ) {
			$owner->release( 'e1-race' );
			$held = false;
		}
		fwrite( $pair[0], "go\n" );
		$after_release = json_decode( e1_line( $pair[0] ), true );
		$rows_after_release = e1_count( $wpdb );
		fwrite( $pair[0], "release\n" );
		if ( 'released' !== e1_line( $pair[0] ) ) {
			throw new RuntimeException( 'Ordered control did not acknowledge second observation.' );
		}
		fwrite( $pair[0], "stop\n" );
		$reaped = e1_reap( array( $worker ) );
		$pass = $reaped && $held === false && 1 === $rows_held && 1 === $rows_contended && 1 === $rows_after_release &&
			false === $contended['acquired'] && null === $contended['error'] && true === $after_release['acquired'] && null === $after_release['error'];
		return compact( 'isolation', 'owner_level', 'rows_held', 'rows_contended', 'rows_after_release', 'contended', 'after_release', 'pass' ) + array( 'connections' => array( $parent['connection_id'], $ready['connection_id'] ), 'classification' => 'GREEN ordered ownership control' );
	} catch ( Throwable $error ) {
		if ( $held ) {
			$owner->release( 'e1-race' );
		}
		if ( 0 === pcntl_waitpid( $pid, $status, WNOHANG ) ) {
			posix_kill( $pid, SIGKILL );
			pcntl_waitpid( $pid, $status );
		}
		if ( is_resource( $pair[0] ) ) {
			fclose( $pair[0] );
		}
		throw $error;
	}
}

try {
	$wpdb = e1_connect( 'REPEATABLE READ' );
	e1_sql( $wpdb, 'DROP TABLE IF EXISTS e1_lock' );
	e1_sql( $wpdb, 'CREATE TABLE e1_lock (
		id int(10) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
		lock_key varchar(50) DEFAULT NULL, original_key varchar(50) DEFAULT NULL,
		level smallint(5) unsigned DEFAULT NULL, pid int(10) unsigned DEFAULT NULL,
		cid int(10) unsigned DEFAULT NULL, expire decimal(16,6) unsigned DEFAULT NULL,
		KEY lock_key (lock_key), KEY level (level)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' );
	$environment = e1_environment( $wpdb );
	e1_emit( 'environment', $environment + array( 'php' => PHP_VERSION, 'wp_source' => realpath( ABSPATH ) ) );
	if ( 'InnoDB' !== $environment['engine'] || false === strpos( $environment['sql_mode'], 'STRICT_ALL_TABLES' ) ) {
		throw new RuntimeException( 'InnoDB and strict SQL mode are required.' );
	}
	$failures = array();
	foreach ( array( 'REPEATABLE READ', 'READ COMMITTED' ) as $isolation ) {
		foreach ( array( WP_Lock::WRITE, WP_Lock::READ ) as $owner_level ) {
			$ordered = e1_ordered( $isolation, $owner_level );
			e1_emit( 'ordered_owner_then_writer', $ordered );
			if ( ! $ordered['pass'] ) { $failures[] = $isolation . ' ordered ' . $owner_level; }
		}
		foreach ( array( 'write_write' => array( WP_Lock::WRITE, WP_Lock::WRITE ), 'read_write' => array( WP_Lock::READ, WP_Lock::WRITE ), 'read_read' => array( WP_Lock::READ, WP_Lock::READ ) ) as $name => $levels ) {
			$result = e1_pair( $isolation, $levels, 100 );
			$pass = 0 === $result['errors'] && 0 === $result['row_mismatches'] && 100 === $result['one_or_more'];
			if ( 'read_read' === $name ) {
				$pass = $pass && 100 === $result['both'];
			} elseif ( 'REPEATABLE READ' === $isolation ) {
				$pass = $pass && 0 === $result['both'];
			} else {
				$pass = $pass && $result['both'] > 0;
			}
			$result['classification'] = 'READ COMMITTED' === $isolation && 'read_read' !== $name ? 'expected legacy RED, sampled empty-key race' : 'GREEN sampled empty-key control';
			$result['pass'] = $pass;
			e1_emit( $name, $result );
			if ( ! $pass ) {
				$failures[] = $isolation . ' ' . $name;
			}
		}
	}

	// Caller rollback reproduces lost ownership without modifying the backend or SQL.
	e1_sql( $wpdb, 'DELETE FROM e1_lock' );
	e1_sql( $wpdb, 'START TRANSACTION' );
	$outer = new WP_Lock_Backend_DB( 0.1 );
	$first = $outer->acquire( 'e1-rollback', WP_Lock::WRITE, false, 30 );
	e1_sql( $wpdb, 'ROLLBACK' );
	$other = e1_connect( 'REPEATABLE READ' );
	$wpdb = $other;
	$successor = new WP_Lock_Backend_DB( 0.1 );
	$second = $successor->acquire( 'e1-rollback', WP_Lock::WRITE, false, 30 );
	$rows = e1_count( $wpdb );
	$outer->release( 'e1-rollback' );
	$successor->release( 'e1-rollback' );
	$pass = $first && $second && 1 === $rows;
	e1_emit( 'outer_rollback', compact( 'first', 'second', 'rows', 'pass' ) + array( 'classification' => 'expected legacy RED', 'method' => 'real DB; independent second connection' ) );
	if ( ! $pass ) { $failures[] = 'outer_rollback'; }

	// TTL is a real clock/DB control: the old owner remains in PHP after expiry.
	e1_sql( $wpdb, 'DELETE FROM e1_lock' );
	$old = new WP_Lock_Backend_DB( 0.1 );
	$first = $old->acquire( 'e1-ttl', WP_Lock::WRITE, false, 1 );
	usleep( 1200000 );
	$new = new WP_Lock_Backend_DB( 0.1 );
	$second = $new->acquire( 'e1-ttl', WP_Lock::WRITE, false, 30 );
	$old->release( 'e1-ttl' );
	$successor_survives = $new->exists( 'e1-ttl' );
	$new->release( 'e1-ttl' );
	$pass = $first && $second && $successor_survives;
	e1_emit( 'ttl_overlap', compact( 'first', 'second', 'successor_survives', 'pass' ) + array( 'classification' => 'expected legacy RED with safe old-release control', 'method' => 'real DB; elapsed TTL' ) );
	if ( ! $pass ) { $failures[] = 'ttl_overlap'; }

	// D6: raw SQL probes nullable diagnostic storage; backend calls show the current behavior.
	e1_sql( $wpdb, 'DELETE FROM e1_lock' );
	$ids = array( 'ascii_51' => str_repeat( 'x', 51 ), 'unicode_52_bytes' => str_repeat( 'é', 26 ), 'unicode_51_chars' => str_repeat( 'é', 51 ), 'prefix_a' => str_repeat( 'p', 50 ) . 'A', 'prefix_b' => str_repeat( 'p', 50 ) . 'B' );
	foreach ( $ids as $name => $id ) {
		$backend = new WP_Lock_Backend_DB( 0.1 );
		try {
			$acquired = $backend->acquire( $id, WP_Lock::WRITE, false, 30 );
			$error = null;
			if ( $acquired ) { $backend->release( $id ); }
		} catch ( Throwable $exception ) {
			$acquired = false;
			$error = $exception->getMessage();
		}
		$expected = 'unicode_52_bytes' === $name;
		$pass = $expected === $acquired && ( $expected || null !== $error );
		e1_emit( 'original_key_backend', compact( 'name', 'acquired', 'error', 'pass' ) + array( 'bytes' => strlen( $id ), 'characters' => function_exists( 'mb_strlen' ) ? mb_strlen( $id, 'UTF-8' ) : null, 'classification' => $expected ? 'GREEN control' : 'expected legacy RED', 'method' => 'real backend with strict SQL mode' ) );
		if ( ! $pass ) { $failures[] = 'original_key_backend ' . $name; }
		// Isolated SQL feasibility: nullable diagnostic value and full-ID md5 key, no backend rewrite.
		$key = md5( $id );
		e1_sql( $wpdb, $wpdb->prepare( 'INSERT INTO e1_lock (lock_key, original_key, level, expire) VALUES (%s, NULL, %d, 0)', $key, WP_Lock::READ ) );
		$stored = $wpdb->get_row( $wpdb->prepare( 'SELECT lock_key, original_key FROM e1_lock WHERE id = %d', $wpdb->insert_id ), ARRAY_A );
		$feasible = $key === $stored['lock_key'] && null === $stored['original_key'];
		e1_emit( 'original_key_nullable_sql', compact( 'name', 'feasible' ) + array( 'key' => $key, 'method' => 'raw SQL feasibility only; no backend injection or production behavior' ) );
		if ( ! $feasible ) { $failures[] = 'original_key_nullable_sql ' . $name; }
	}
	$distinct = md5( $ids['prefix_a'] ) !== md5( $ids['prefix_b'] );
	e1_emit( 'shared_prefix_identity', compact( 'distinct' ) + array( 'classification' => 'GREEN control', 'method' => 'full string passed to md5; raw nullable rows persisted' ) );
	if ( ! $distinct ) { $failures[] = 'shared_prefix_identity'; }
	e1_sql( $wpdb, 'DROP TABLE e1_lock' );
	e1_emit( 'summary', array( 'pass' => empty( $failures ), 'failures' => $failures ) );
	exit( empty( $failures ) ? 0 : 1 );
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" );
	exit( 1 );
}
