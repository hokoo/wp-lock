<?php
/** Disposable E5 switch rehearsal. Run through d1-matrix.sh with the tagged 2.0 source. */
if ( ! extension_loaded( 'mysqli' ) || ! function_exists( 'proc_open' ) || ! function_exists( 'pcntl_waitpid' ) || ! function_exists( 'posix_kill' ) ) {
	fwrite( STDERR, "mysqli, process control, and POSIX are required.\n" );
	exit( 2 );
}
$socket = getenv( 'WP_LOCK_E1_SOCKET' );
$wp_src = getenv( 'WP_LOCK_E1_WP' );
$legacy = getenv( 'WP_LOCK_E5_LEGACY_ROOT' );
if ( 'wp_lock_e1' !== getenv( 'WP_LOCK_E1_DISPOSABLE' ) || ! $socket || ! file_exists( $socket ) ||
	! $wp_src || ! is_file( $wp_src . '/wp-includes/class-wpdb.php' ) ||
	! $legacy || ! is_file( $legacy . '/lib/backend/class-wp-lock-backend-db.php' ) ) {
	fwrite( STDERR, "Use the disposable matrix and tagged 2.0 source.\n" );
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

function wp_debug_backtrace_summary() { return 'E5 migration diagnostic'; }
function e5_check( $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function e5_sql( string $query ): void {
	global $wpdb;
	e5_check( false !== $wpdb->query( $query ) && empty( $wpdb->last_error ), 'Fixture SQL failed: ' . $wpdb->last_error );
}
function e5_value( string $query ) {
	global $wpdb;
	$value = $wpdb->get_var( $query );
	e5_check( empty( $wpdb->last_error ), 'Fixture read failed.' );
	return $value;
}
function e5_marker( string $name ): ?string {
	global $wpdb;
	$value = e5_value( $wpdb->prepare( 'SELECT option_value FROM e5_options WHERE option_name = %s', $name ) );
	return null === $value ? null : (string) $value;
}
function e5_owners(): array {
	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT id, lock_key, level, expire, attempt_token FROM e5_lock ORDER BY id', ARRAY_A );
	e5_check( empty( $wpdb->last_error ) && is_array( $rows ), 'Owner inspection failed.' );
	return $rows;
}
function e5_state( string $phase ): void {
	global $wpdb;
	$tables = $wpdb->get_results( "SELECT table_name, engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('e5_lock', 'e5_lock_resource') ORDER BY table_name", ARRAY_A );
	$columns = $wpdb->get_results( "SELECT table_name, column_name, column_type, is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name IN ('e5_lock', 'e5_lock_resource') ORDER BY table_name, ordinal_position", ARRAY_A );
	$indexes = $wpdb->get_results( "SELECT table_name, index_name, non_unique, seq_in_index, column_name FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name IN ('e5_lock', 'e5_lock_resource') ORDER BY table_name, index_name, seq_in_index", ARRAY_A );
	e5_check( empty( $wpdb->last_error ), 'Schema inspection failed.' );
	echo json_encode( array( 'case' => 'state', 'phase' => $phase, 'tables' => $tables, 'columns' => $columns, 'indexes' => $indexes,
		'legacy_schema_version' => e5_marker( WP_Lock_Backend_DB::SCHEMA_VERSION_OPTION ),
		'foundation_version' => e5_marker( WP_Lock_Foundations::SCHEMA_OPTION ),
		'protocol_version' => e5_marker( WP_Lock_Foundations::PROTOCOL_OPTION ),
		'owners' => is_array( $tables ) && count( $tables ) ? e5_owners() : array() ) ) . "\n";
}
function e5_case( string $name, callable $run ): void {
	$run();
	echo json_encode( array( 'case' => $name, 'pass' => true ) ) . "\n";
}
function e5_refuses( callable $run, string $expected ): void {
	try {
		$run();
		throw new LogicException( 'Expected refusal was absent.' );
	} catch ( RuntimeException $error ) {
		e5_check( false !== strpos( $error->getMessage(), $expected ), 'Unexpected refusal: ' . $error->getMessage() );
	}
}
function e5_old_start( string $id, string $barrier, string $isolation ): array {
	$env = getenv();
	$env['WP_LOCK_E5_BARRIER'] = $barrier;
	$env['WP_LOCK_E5_ISOLATION'] = $isolation;
	$process = proc_open( array( PHP_BINARY, __DIR__ . '/e5-legacy-worker.php', $id ),
		array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $env );
	e5_check( is_resource( $process ), 'Legacy participant did not start.' );
	stream_set_timeout( $pipes[1], 15 );
	$line = fgets( $pipes[1] );
	$record = json_decode( (string) $line, true );
	if ( ! is_array( $record ) || 'legacy-owner' !== ( $record['event'] ?? null ) ) {
		if ( ! feof( $pipes[1] ) ) { proc_terminate( $process ); }
		$error = stream_get_contents( $pipes[2] );
		foreach ( $pipes as $pipe ) { fclose( $pipe ); }
		$status = proc_close( $process );
		throw new RuntimeException( 'Legacy participant failed (exit=' . $status . '): ' . $error );
	}
	return array( $process, $pipes, $record );
}
function e5_old_stop( array $child ): void {
	list( $process, $pipes ) = $child;
	fwrite( $pipes[0], "release\n" );
	fclose( $pipes[0] );
	stream_set_timeout( $pipes[1], 15 );
	$reply = fgets( $pipes[1] );
	if ( "released\n" !== $reply && ! feof( $pipes[1] ) ) { proc_terminate( $process ); }
	fclose( $pipes[1] );
	$error = stream_get_contents( $pipes[2] );
	fclose( $pipes[2] );
	$status = proc_close( $process );
	e5_check( "released\n" === $reply, 'Legacy release did not complete: ' . $error );
	e5_check( 0 === $status, 'Legacy participant did not stop: ' . $error );
}
function e5_barrier_rejects_old( string $barrier ): void {
	e5_check( 0 === count( e5_owners() ), 'Restart probe requires zero owners.' );
	$env = getenv();
	$env['WP_LOCK_E5_BARRIER'] = $barrier;
	$process = proc_open( array( PHP_BINARY, __DIR__ . '/e5-legacy-worker.php', 'forbidden-restart' ),
		array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $env );
	e5_check( is_resource( $process ), 'Restart probe did not start.' );
	stream_set_timeout( $pipes[2], 15 );
	$error = fgets( $pipes[2] );
	if ( stream_get_meta_data( $pipes[2] )['timed_out'] ) { proc_terminate( $process ); }
	foreach ( $pipes as $pipe ) { fclose( $pipe ); }
	$status = proc_close( $process );
	e5_check( 4 === $status && "Fixture admissions barrier is engaged.\n" === $error,
		'Fixture allowed an old participant to restart (exit=' . $status . ', stderr=' . var_export( $error, true ) . ').' );
	e5_check( 0 === count( e5_owners() ), 'Restart probe created an owner.' );
}

$barrier = sys_get_temp_dir() . '/wp-lock-e5-' . bin2hex( random_bytes( 8 ) );
$child = null;
try {
	global $wpdb, $wp_version;
	$wpdb = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$wpdb->prefix = 'e5_';
	$wpdb->suppress_errors( true );
	e5_check( 'wp_lock_e1' === e5_value( 'SELECT DATABASE()' ), 'Wrong disposable database.' );
	$server = e5_value( 'SELECT VERSION()' );
	foreach ( array( 'REPEATABLE READ', 'READ COMMITTED' ) as $isolation ) {
		e5_sql( 'DROP TABLE IF EXISTS e5_lock_resource' );
		e5_sql( 'DROP TABLE IF EXISTS e5_lock' );
		e5_sql( 'DROP TABLE IF EXISTS e5_options' );
		e5_sql( 'CREATE TABLE e5_options (option_name varchar(191) NOT NULL PRIMARY KEY, option_value longtext NOT NULL, autoload varchar(20) NOT NULL) ENGINE=InnoDB' );
		e5_sql( 'SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation );
		$actual = $wpdb->get_var( "SHOW SESSION VARIABLES LIKE 'transaction_isolation'", 1 );
		if ( null === $actual ) { $actual = $wpdb->get_var( "SHOW SESSION VARIABLES LIKE 'tx_isolation'", 1 ); }
		$actual = str_replace( '-', ' ', strtoupper( (string) $actual ) );
		e5_check( $isolation === $actual && empty( $wpdb->last_error ), 'Isolation did not verify.' );
		$engine = e5_value( 'SELECT @@default_storage_engine' );
		e5_check( 'InnoDB' === $engine, 'Unexpected fixture storage engine.' );
		echo json_encode( array( 'case' => 'environment', 'database' => 'wp_lock_e1', 'server' => $server, 'php' => PHP_VERSION,
			'wordpress' => $wp_version, 'engine' => $engine, 'isolation' => $actual, 'connection_id' => e5_value( 'SELECT CONNECTION_ID()' ) ) ) . "\n";
			e5_case( 'clean_install_failed_ddl', function() {
				$fail = function( $query ) { return 0 === strpos( $query, 'CREATE TABLE IF NOT EXISTS' ) ? 'SELECT * FROM e5_missing_ddl' : $query; };
				add_filter( 'query', $fail );
				try { e5_refuses( function() { WP_Lock_Foundations::prepare_schema(); }, 'schema change failed' ); }
				finally { remove_filter( 'query', $fail ); }
				e5_check( null === e5_marker( WP_Lock_Foundations::SCHEMA_OPTION ) && null === e5_marker( WP_Lock_Foundations::PROTOCOL_OPTION ), 'Failed DDL advanced a marker.' );
				e5_state( 'failed-ddl' );
			} );
			e5_case( 'clean_install_prepared_not_enabled', function() {
				WP_Lock_Foundations::prepare_schema();
				WP_Lock_Foundations::record_legacy_schema_version();
				e5_check( WP_Lock_Backend_DB::SCHEMA_VERSION === e5_marker( WP_Lock_Backend_DB::SCHEMA_VERSION_OPTION ), 'Legacy owner schema marker missing.' );
				e5_check( WP_Lock_Foundations::SCHEMA_VERSION === e5_marker( WP_Lock_Foundations::SCHEMA_OPTION ), 'Foundation marker missing.' );
				e5_check( null === e5_marker( WP_Lock_Foundations::PROTOCOL_OPTION ), 'Preparation enabled acquisition.' );
				e5_refuses( function() { ( new WP_Lock_Backend_DB( 0, 0 ) )->acquire( 'pre-switch', WP_Lock::WRITE, false, 0 ); }, 'database error' );
				e5_state( 'prepared' );
			} );
			e5_case( 'incompatible_legacy_insert_default', function() {
			global $wpdb;
			e5_sql( $wpdb->prepare( 'UPDATE e5_options SET option_value = %s WHERE option_name = %s', 'unverified', WP_Lock_Foundations::SCHEMA_OPTION ) );
			e5_sql( "ALTER TABLE e5_lock MODIFY attempt_token char(32) DEFAULT 'reused'" );
			try {
				e5_refuses( function() { WP_Lock_Foundations::prepare_schema(); }, 'column verification failed' );
				e5_refuses( function() { WP_Lock_Foundations::switch_protocol( WP_Lock_Foundations::PROTOCOL_VERSION ); }, 'column verification failed' );
			}
			finally { e5_sql( 'ALTER TABLE e5_lock MODIFY attempt_token char(32) DEFAULT NULL' ); }
			e5_check( 'unverified' === e5_marker( WP_Lock_Foundations::SCHEMA_OPTION ) && null === e5_marker( WP_Lock_Foundations::PROTOCOL_OPTION ), 'Invalid legacy insert default advanced a marker.' );
			WP_Lock_Foundations::prepare_schema();
		} );
		e5_case( 'tagged_legacy_owner_blocks_switch', function() use ( &$child, $barrier, $isolation ) {
			$child = e5_old_start( 'legacy-ttl0', $barrier, $isolation );
			e5_check( $isolation === $child[2]['isolation'], 'Old participant isolation did not verify.' );
			echo json_encode( array( 'case' => 'legacy_process', 'pid' => $child[2]['pid'], 'connection_id' => $child[2]['connection_id'], 'isolation' => $child[2]['isolation'] ) ) . "\n";
			e5_check( count( e5_owners() ) === 1 && 0.0 === (float) e5_owners()[0]['expire'], 'Legacy TTL=0 owner was not retained.' );
			e5_refuses( function() { WP_Lock_Foundations::switch_protocol( WP_Lock_Foundations::PROTOCOL_VERSION ); }, 'owners remain' );
			e5_check( null === e5_marker( WP_Lock_Foundations::PROTOCOL_OPTION ), 'Owner blocked switch but marker advanced.' );
			e5_state( 'legacy-owner-blocked' );
			e5_check( 1 === file_put_contents( $barrier, '1' ), 'Fixture barrier failed.' );
			e5_old_stop( $child );
			$child = null;
			e5_barrier_rejects_old( $barrier );
			e5_check( 0 === count( e5_owners() ), 'Old owner did not drain.' );
		} );
		e5_case( 'interrupted_switch_and_recovery', function() {
			$fail = function( $query ) { return false !== strpos( $query, WP_Lock_Foundations::PROTOCOL_OPTION ) && false !== strpos( $query, 'INSERT INTO' ) ? 'SELECT * FROM e5_missing_switch' : $query; };
			add_filter( 'query', $fail );
			try { e5_refuses( function() { WP_Lock_Foundations::switch_protocol( WP_Lock_Foundations::PROTOCOL_VERSION ); }, 'failed' ); }
			finally { remove_filter( 'query', $fail ); }
			e5_check( null === e5_marker( WP_Lock_Foundations::PROTOCOL_OPTION ), 'Interrupted write enabled target.' );
			WP_Lock_Foundations::switch_protocol( WP_Lock_Foundations::PROTOCOL_VERSION );
			e5_check( WP_Lock_Foundations::PROTOCOL_VERSION === e5_marker( WP_Lock_Foundations::PROTOCOL_OPTION ), 'Target switch did not verify.' );
			e5_state( 'target-enabled' );
		} );
		e5_case( 'target_smoke_and_reverse_switch', function() use ( $barrier, $isolation ) {
			$a = new WP_Lock( 'e5-smoke' );
			$b = new WP_Lock( 'e5-smoke' );
			$c = new WP_Lock( 'e5-smoke' );
			e5_check( $a->acquire( WP_Lock::READ, false, 0 ), 'First shared reader failed.' );
			$owners = e5_owners();
			e5_check( 1 === count( $owners ) && WP_Lock::READ === (int) $owners[0]['level'], 'First reader owner missing.' );
			$a_owner = $owners[0];
			e5_check( $b->acquire( WP_Lock::READ, false, 0 ), 'Second shared reader failed.' );
			$owners = e5_owners();
			e5_check( 2 === count( $owners ) && $a_owner === $owners[0] && WP_Lock::READ === (int) $owners[1]['level'], 'Shared reader owners missing.' );
			$b_owner = $owners[1];
			e5_check( ! $c->acquire( WP_Lock::WRITE, false, 0 ), 'Writer overlapped readers.' );
			e5_refuses( function() { WP_Lock_Foundations::switch_protocol( '2.0.0' ); }, 'owners remain' );
			$a->release();
			e5_check( array( $b_owner ) === e5_owners(), 'First reader release removed another owner.' );
			$b->release();
			e5_check( 0 === count( e5_owners() ), 'Readers did not release.' );
			e5_check( $c->acquire( WP_Lock::WRITE, false, 0 ), 'Exclusive writer failed.' );
			$owners = e5_owners();
			e5_check( 1 === count( $owners ) && WP_Lock::WRITE === (int) $owners[0]['level'], 'Writer owner missing.' );
			e5_check( ! $a->acquire( WP_Lock::READ, false, 0 ), 'Reader overlapped writer.' );
			$c->release();
			e5_check( 0 === count( e5_owners() ), 'Writer did not release.' );
			e5_barrier_rejects_old( $barrier );
			WP_Lock_Foundations::switch_protocol( '2.0.0' );
			e5_check( '2.0.0' === e5_marker( WP_Lock_Foundations::PROTOCOL_OPTION ) && 0 === count( e5_owners() ), 'Rollback postcondition failed.' );
			e5_refuses( function() { ( new WP_Lock_Backend_DB( 0, 0 ) )->acquire( 'post-rollback', WP_Lock::WRITE, false, 0 ); }, 'database error' );
			e5_state( 'rolled-back' );
			e5_barrier_rejects_old( $barrier );
			unlink( $barrier );
			$old = e5_old_start( 'old-after-rollback', $barrier, $isolation );
			e5_old_stop( $old );
			e5_check( 0 === count( e5_owners() ), 'Legacy rollback smoke left an owner.' );
		} );
	}
	echo json_encode( array( 'case' => 'summary', 'pass' => true ) ) . "\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, get_class( $error ) . ': ' . $error->getMessage() . "\n" );
	echo json_encode( array( 'case' => 'summary', 'pass' => false ) ) . "\n";
	exit( 1 );
} finally {
	if ( null !== $child ) {
		proc_terminate( $child[0] );
		foreach ( $child[1] as $pipe ) { fclose( $pipe ); }
		proc_close( $child[0] );
	}
	if ( file_exists( $barrier ) ) { unlink( $barrier ); }
}
