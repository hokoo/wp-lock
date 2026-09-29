<?php
// Investigation harness: real repository backend + WordPress wpdb, isolated database only.
// Usage: WP_LOCK_AUDIT_SOCKET=/path/mysql.sock WP_LOCK_AUDIT_WP=/path/wordpress php7.4 probe.php
// Creates/drops audit_lock only, in the dedicated wp_lock_audit database.
define( 'ABSPATH', rtrim( getenv( 'WP_LOCK_AUDIT_WP' ) ?: '/tmp/wordpress', '/' ) . '/' );
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_DISPLAY', false );
require ABSPATH . 'wp-includes/compat.php';
require ABSPATH . 'wp-includes/plugin.php';
require ABSPATH . 'wp-includes/load.php';
require ABSPATH . 'wp-includes/class-wpdb.php';
require __DIR__ . '/../../lib/backend/class-wp-lock-backend.php';
require __DIR__ . '/../../lib/class-wp-lock.php';
require __DIR__ . '/../../lib/backend/class-wp-lock-backend-db.php';

use iTRON\WP_Lock\WP_Lock;
use iTRON\WP_Lock\WP_Lock_Backend_DB;

// wpdb uses this only for error diagnostics in the minimal bootstrap.
function wp_debug_backtrace_summary() { return 'wp-lock investigation'; }

function connect_audit( $isolation = 'REPEATABLE READ' ) {
	$db = new wpdb( 'root', '', 'wp_lock_audit', 'localhost:' . getenv( 'WP_LOCK_AUDIT_SOCKET' ) );
	$db->prefix = 'audit_';
	$db->suppress_errors( true );
	$db->query( 'SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation );
	$db->query( 'SET SESSION innodb_lock_wait_timeout = 2' );
	return $db;
}
function emit( $case, $data ) { echo json_encode( array( 'case' => $case, 'data' => $data ), JSON_UNESCAPED_SLASHES ) . "\n"; }
function sql_ok( $db, $sql ) {
	$result = $db->query( $sql );
	if ( false === $result ) { throw new RuntimeException( $db->last_error ); }
	return $result;
}
function reset_table() { global $wpdb; sql_ok( $wpdb, 'DELETE FROM audit_lock' ); }

$wpdb = connect_audit();
emit( 'environment', $wpdb->get_row( 'SELECT VERSION() AS version, @@autocommit AS autocommit, @@innodb_autoinc_lock_mode AS autoinc_mode, @@binlog_format AS binlog_format', ARRAY_A ) );
sql_ok( $wpdb, 'DROP TABLE IF EXISTS audit_lock' );
sql_ok( $wpdb, 'CREATE TABLE audit_lock (
 id int(10) unsigned NOT NULL AUTO_INCREMENT,
 lock_key varchar(50) DEFAULT NULL, original_key varchar(50) DEFAULT NULL,
 level smallint(5) unsigned DEFAULT NULL, pid int(10) unsigned DEFAULT NULL,
 cid int(10) unsigned DEFAULT NULL, expire decimal(16,6) unsigned DEFAULT NULL,
 PRIMARY KEY (id), KEY lock_key (lock_key), KEY level (level)
) ENGINE=InnoDB' );

// Both independent processes keep acquired rows until both results have been collected.
// No trigger, SQL modification, or application transaction is used for these races.
foreach ( array( 'REPEATABLE READ', 'READ COMMITTED' ) as $isolation ) {
	foreach ( array( array( 32, 32 ), array( 8, 32 ), array( 8, 8 ) ) as $levels ) {
		reset_table();
		$wpdb->close();
		$workers = array();
		foreach ( $levels as $level ) {
			$pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
			$pid = pcntl_fork();
			if ( 0 === $pid ) {
				fclose( $pair[0] );
				$wpdb = connect_audit( $isolation );
				while ( false !== ( $line = fgets( $pair[1] ) ) && 'stop' !== trim( $line ) ) {
					$backend = new WP_Lock_Backend_DB( 0.1, 3 );
					try { $result = $backend->acquire( 'race', $level, false, 30 ); }
					catch ( Throwable $e ) { $result = $e->getMessage(); }
					fwrite( $pair[1], json_encode( $result ) . "\n" );
					fgets( $pair[1] ); // Parent has observed both owners before allowing release.
					if ( true === $result ) { $backend->release( 'race' ); }
					fwrite( $pair[1], "released\n" );
				}
				exit( 0 );
			}
			fclose( $pair[1] );
			stream_set_timeout( $pair[0], 15 );
			$workers[] = array( $pid, $pair[0] );
		}
		$wpdb = connect_audit( $isolation );
		$both = 0; $errors = 0; $max_rows = 0;
		for ( $round = 0; $round < 200; $round++ ) {
			foreach ( $workers as $w ) { fwrite( $w[1], "go\n" ); }
			$results = array();
			foreach ( $workers as $w ) {
				$line = fgets( $w[1] );
				if ( false === $line ) { throw new RuntimeException( 'Worker timed out or exited.' ); }
				$results[] = json_decode( $line, true );
			}
			if ( true === $results[0] && true === $results[1] ) { $both++; }
			foreach ( $results as $r ) { if ( ! is_bool( $r ) ) { $errors++; } }
			$max_rows = max( $max_rows, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM audit_lock' ) );
			foreach ( $workers as $w ) { fwrite( $w[1], "release\n" ); }
			foreach ( $workers as $w ) { fgets( $w[1] ); }
		}
		foreach ( $workers as $w ) { fwrite( $w[1], "stop\n" ); pcntl_waitpid( $w[0], $status ); fclose( $w[1] ); }
		emit( 'simultaneous_acquire', compact( 'isolation', 'levels', 'both', 'errors', 'max_rows' ) + array( 'rounds' => 200 ) );
	}
}

reset_table();
$a = new WP_Lock( 'ttl' ); $b = new WP_Lock( 'ttl' );
$first = $a->acquire( WP_Lock::WRITE, false, 1 );
usleep( 1100000 );
$second = $b->acquire( WP_Lock::WRITE, false, 30 );
try { $a->acquire(); $still_held = false; } catch ( LogicException $e ) { $still_held = true; }
$a->release();
$successor_survives = $b->lock_exists();
$b->release();
emit( 'ttl_overlap_and_safe_old_release', compact( 'first', 'second', 'still_held', 'successor_survives' ) );

reset_table();
sql_ok( $wpdb, 'START TRANSACTION' );
$a = new WP_Lock( 'rollback' );
$first = $a->acquire( WP_Lock::WRITE, false, 30 );
sql_ok( $wpdb, 'ROLLBACK' );
$b = new WP_Lock( 'rollback' );
$second = $b->acquire( WP_Lock::WRITE, false, 30 );
$a->release(); $b->release();
emit( 'outer_rollback_loses_lock', compact( 'first', 'second' ) );

reset_table();
sql_ok( $wpdb, 'CREATE TRIGGER audit_delay BEFORE INSERT ON audit_lock FOR EACH ROW DO SLEEP(1.2)' );
$a = new WP_Lock( 'sql-delay', new WP_Lock_Backend_DB( 0.01, 0 ) );
$start = microtime( true );
$acquired = $a->acquire( WP_Lock::WRITE, false, 1 );
$elapsed = microtime( true ) - $start;
$exists_on_return = $a->lock_exists();
$a->release();
$b = new WP_Lock( 'blocking-sql-delay', new WP_Lock_Backend_DB( 0.01, 0 ) );
$blocking_start = microtime( true );
$blocking_acquired = $b->acquire( WP_Lock::WRITE, true, 30 );
$blocking_elapsed = microtime( true ) - $blocking_start;
$b->release();
sql_ok( $wpdb, 'DROP TRIGGER audit_delay' );
emit( 'slow_statement_ttl', compact( 'acquired', 'elapsed', 'exists_on_return', 'blocking_acquired', 'blocking_elapsed' ) );

// Fault injection: simulate a PID unobservable on this host and a failed liveness query.
// The real owner connection stays alive throughout the check.
reset_table();
$a = new WP_Lock( 'ghost-query-error' );
$a->acquire( WP_Lock::WRITE, false, 0 );
sql_ok( $wpdb, 'UPDATE audit_lock SET pid = 0' );
$fault = function( $query ) {
	return false !== strpos( $query, 'SELECT id FROM information_schema.processlist' )
		? 'SELECT id FROM audit_nonexistent_processlist' : $query;
};
add_filter( 'query', $fault );
$b = new WP_Lock( 'ghost-query-error' );
$second_after_liveness_error = $b->acquire( WP_Lock::WRITE, false, 30 );
remove_filter( 'query', $fault );
$a->release(); $b->release();
emit( 'ghost_liveness_error_deletes_live_lock', compact( 'second_after_liveness_error' ) );

// Simulated stale PID/CID combination: an unrelated live local PID masks a dead owner.
reset_table();
$a = new WP_Lock( 'ghost-stale-pid' );
$a->acquire( WP_Lock::WRITE, false, 0 );
sql_ok( $wpdb, 'UPDATE audit_lock SET cid = 0' );
$ghosts_with_local_pid = count( ( new WP_Lock_Backend_DB() )->get_ghosts( 'ghost-stale-pid' ) );
$a->release();
emit( 'ghost_local_pid_masks_absent_connection', compact( 'ghosts_with_local_pid' ) );

reset_table();
$a = new WP_Lock( str_repeat( 'x', 51 ) );
sql_ok( $wpdb, "SET SESSION sql_mode = 'STRICT_ALL_TABLES'" );
try { $long_id = $a->acquire( WP_Lock::WRITE, false, 30 ); if ( $long_id ) { $a->release(); } }
catch ( Throwable $e ) { $long_id = $e->getMessage(); }
emit( 'long_resource_id_strict_mode', $long_id );

reset_table();
$a = new WP_Lock( 'exists-error' ); $a->acquire( WP_Lock::WRITE, false, 30 );
sql_ok( $wpdb, 'ALTER TABLE audit_lock RENAME TO audit_lock_hidden' );
$exists = $a->lock_exists(); $error = $wpdb->last_error;
sql_ok( $wpdb, 'ALTER TABLE audit_lock_hidden RENAME TO audit_lock' );
$a->release();
emit( 'exists_database_error', compact( 'exists', 'error' ) );
sql_ok( $wpdb, 'DROP TABLE audit_lock' );
