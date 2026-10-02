<?php
/** Composer-installed package smoke on the disposable wp_lock_e5_package socket. */
if ( ! extension_loaded( 'mysqli' ) || ! function_exists( 'proc_open' ) || ! function_exists( 'pcntl_waitpid' ) || ! function_exists( 'posix_kill' ) ) {
	fwrite( STDERR, "mysqli, PCNTL, and POSIX are required.\n" );
	exit( 2 );
}
$socket = getenv( 'WP_LOCK_PACKAGE_SOCKET' );
$isolation = getenv( 'WP_LOCK_PACKAGE_ISOLATION' );
if ( 'wp_lock_e5_package' !== getenv( 'WP_LOCK_PACKAGE_DISPOSABLE' ) || ! $socket || ! file_exists( $socket ) ||
	! in_array( $isolation, array( 'REPEATABLE READ', 'READ COMMITTED' ), true ) ||
	! is_file( '/wp/wp-includes/class-wpdb.php' ) || ! is_file( '/consumer/vendor/hokoo/wp-lock/plugin.php' ) ) {
	fwrite( STDERR, "Use the isolated E5 package fixture.\n" );
	exit( 2 );
}
define( 'ABSPATH', '/wp/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_NAME', 'wp_lock_e5_package' );
define( 'DB_HOST', 'localhost:' . $socket );
require ABSPATH . 'wp-includes/compat.php';
require ABSPATH . 'wp-includes/plugin.php';
require ABSPATH . 'wp-includes/load.php';
require ABSPATH . 'wp-includes/class-wpdb.php';
require ABSPATH . 'wp-includes/version.php';
require '/consumer/vendor/autoload.php';

use iTRON\WP_Lock\WP_Lock;
use iTRON\WP_Lock\WP_Lock_Backend_DB;
use iTRON\WP_Lock\WP_Lock_Foundations;

function wp_debug_backtrace_summary() { return 'E5 package smoke'; }
function need( $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function query( string $statement ): void {
	global $wpdb;
	need( false !== $wpdb->query( $statement ) && empty( $wpdb->last_error ), 'Fixture query failed: ' . $wpdb->last_error );
}
function value( string $statement ) {
	global $wpdb;
	$result = $wpdb->get_var( $statement );
	need( empty( $wpdb->last_error ), 'Fixture read failed.' );
	return $result;
}
function owners( string $resource ): array {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, level, pid, cid, attempt_token FROM pkg_lock WHERE lock_key = %s ORDER BY id', md5( $resource ) ), ARRAY_A );
	need( empty( $wpdb->last_error ) && is_array( $rows ), 'Owner inspection failed.' );
	foreach ( $rows as $row ) { need( 32 === strlen( (string) $row['attempt_token'] ), 'Owner token missing.' ); }
	return $rows;
}
function marker( string $name ): ?string {
	global $wpdb;
	$found = value( $wpdb->prepare( 'SELECT option_value FROM pkg_options WHERE option_name = %s', $name ) );
	return null === $found ? null : (string) $found;
}
function report( array $record ): void {
	echo json_encode( $record, JSON_UNESCAPED_SLASHES ) . "\n";
	flush();
}
function start_owner( string $resource, int $level ): array {
	$process = proc_open( array( PHP_BINARY, __FILE__, 'owner', $resource, (string) $level ),
		array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	need( is_resource( $process ), 'Independent owner did not start.' );
	$worker = array( 'process' => $process, 'pipes' => $pipes, 'active' => true, 'record' => null );
	stream_set_timeout( $pipes[1], 15 );
	$line = fgets( $pipes[1] );
	$record = json_decode( (string) $line, true );
	if ( ! is_array( $record ) || 'held' !== ( $record['event'] ?? null ) || stream_get_meta_data( $pipes[1] )['timed_out'] ) {
		stop_owner( $worker, false );
		throw new RuntimeException( 'Independent owner did not confirm acquisition.' );
	}
	$worker['record'] = $record;
	return $worker;
}
function stop_owner( array &$worker, bool $require_release = true ): void {
	if ( ! $worker['active'] ) { return; }
	list( $in, $out, $error ) = $worker['pipes'];
	@fwrite( $in, "release\n" );
	fclose( $in );
	stream_set_timeout( $out, 15 );
	$reply = fgets( $out );
	$timed_out = stream_get_meta_data( $out )['timed_out'];
	if ( $timed_out || "released\n" !== $reply ) { proc_terminate( $worker['process'] ); }
	fclose( $out );
	$message = stream_get_contents( $error );
	fclose( $error );
	$status = proc_close( $worker['process'] );
	$worker['active'] = false;
	if ( ! $require_release && '' !== $message ) { fwrite( STDERR, 'Independent owner stderr: ' . $message ); }
	if ( $require_release ) { need( ! $timed_out && "released\n" === $reply && 0 === $status, 'Independent owner release failed: ' . $message ); }
}
function refuse( string $resource, int $level ): void {
	$lock = new WP_Lock( $resource, new WP_Lock_Backend_DB( 0.0, 0 ) );
	need( false === $lock->acquire( $level, false, 0 ), 'Conflicting acquisition was granted.' );
}

try {
	global $wpdb, $wp_version;
	$wpdb = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$wpdb->prefix = 'pkg_';
	$wpdb->suppress_errors( true );
	query( 'SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation );
	$actual = $wpdb->get_var( "SHOW SESSION VARIABLES LIKE 'transaction_isolation'", 1 );
	if ( null === $actual ) { $actual = $wpdb->get_var( "SHOW SESSION VARIABLES LIKE 'tx_isolation'", 1 ); }
	$actual = str_replace( '-', ' ', strtoupper( (string) $actual ) );
	need( $isolation === $actual && empty( $wpdb->last_error ), 'Session isolation mismatch.' );
	need( 'wp_lock_e5_package' === value( 'SELECT DATABASE()' ) && '0' === (string) value( 'SELECT @@read_only' ), 'Not the writable disposable primary.' );
	need( 'InnoDB' === value( 'SELECT @@default_storage_engine' ), 'InnoDB is not the default engine.' );
	need( '8.5.11' === PHP_VERSION && '7.1.2' === $wp_version, 'PHP or released WordPress version mismatch.' );
	$backend_file = ( new ReflectionClass( WP_Lock_Backend_DB::class ) )->getFileName();
	need( false !== strpos( $backend_file, '/consumer/vendor/hokoo/wp-lock/lib/backend/' ), 'DB backend was not loaded from Composer-installed package.' );
	if ( 'owner' === ( $argv[1] ?? '' ) ) {
		$resource = $argv[2] ?? '';
		$level = (int) ( $argv[3] ?? 0 );
		need( in_array( $level, array( WP_Lock::READ, WP_Lock::WRITE ), true ), 'Invalid owner level.' );
		$lock = new WP_Lock( $resource, new WP_Lock_Backend_DB( 2.0, 0 ) );
		need( $lock->acquire( $level, true, 0 ), 'Independent owner was not granted.' );
		$rows = owners( $resource );
		$mine = array_values( array_filter( $rows, function( $row ) { return (int) $row['pid'] === getmypid(); } ) );
		need( 1 === count( $mine ) && $level === (int) $mine[0]['level'], 'Independent owner identity missing.' );
		report( array( 'event' => 'held', 'pid' => getmypid(), 'caller_connection' => (int) value( 'SELECT CONNECTION_ID()' ),
			'owner_id' => (int) $mine[0]['id'], 'owner_connection' => (int) $mine[0]['cid'], 'level' => $level ) );
		stream_set_timeout( STDIN, 45 );
		need( "release\n" === fgets( STDIN ) && ! stream_get_meta_data( STDIN )['timed_out'], 'Release instruction missing.' );
		$lock->release();
		echo "released\n";
		exit( 0 );
	}
	need( 'parent' === ( $argv[1] ?? '' ), 'Unknown smoke role.' );
	report( array( 'case' => 'environment', 'php' => PHP_VERSION, 'wordpress' => $wp_version,
		'server' => value( 'SELECT VERSION()' ), 'engine' => value( 'SELECT @@default_storage_engine' ),
		'isolation' => $actual, 'database' => value( 'SELECT DATABASE()' ), 'read_only' => value( 'SELECT @@read_only' ),
		'caller_connection' => (int) value( 'SELECT CONNECTION_ID()' ),
		'backend_file' => $backend_file ) );
	query( 'DROP TABLE IF EXISTS pkg_lock_resource' );
	query( 'DROP TABLE IF EXISTS pkg_lock' );
	query( 'DROP TABLE IF EXISTS pkg_options' );
	query( 'CREATE TABLE pkg_options (option_name varchar(191) NOT NULL PRIMARY KEY, option_value longtext NOT NULL, autoload varchar(20) NOT NULL) ENGINE=InnoDB' );
	need( null === marker( WP_Lock_Foundations::PROTOCOL_OPTION ), 'Fresh fixture already enabled.' );
	WP_Lock_Foundations::prepare_schema();
	WP_Lock_Foundations::record_legacy_schema_version();
	need( array() === owners( 'package-readers' ), 'Fresh fixture contains owners.' );
	WP_Lock_Foundations::switch_protocol( '3.0.0' );
	need( '2.0.0' === marker( WP_Lock_Backend_DB::SCHEMA_VERSION_OPTION ) &&
		'3.0.0-foundations' === marker( WP_Lock_Foundations::SCHEMA_OPTION ) &&
		'3.0.0' === marker( WP_Lock_Foundations::PROTOCOL_OPTION ), 'Schema/protocol markers mismatch.' );
	$tables = $wpdb->get_results( "SELECT table_name, engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('pkg_lock', 'pkg_lock_resource') ORDER BY table_name", ARRAY_A );
	need( empty( $wpdb->last_error ) && 2 === count( $tables ) && 'InnoDB' === $tables[0]['engine'] && 'InnoDB' === $tables[1]['engine'], 'Foundation tables are not InnoDB.' );
	report( array( 'case' => 'markers', 'legacy' => marker( WP_Lock_Backend_DB::SCHEMA_VERSION_OPTION ),
		'foundation' => marker( WP_Lock_Foundations::SCHEMA_OPTION ), 'protocol' => marker( WP_Lock_Foundations::PROTOCOL_OPTION ),
		'tables' => $tables ) );
	$workers = array();
	try {
		$workers[] = start_owner( 'package-readers', WP_Lock::READ );
		$workers[] = start_owner( 'package-readers', WP_Lock::READ );
		$rows = owners( 'package-readers' );
		need( 2 === count( $rows ) && (int) $rows[0]['id'] !== (int) $rows[1]['id'] &&
			WP_Lock::READ === (int) $rows[0]['level'] && WP_Lock::READ === (int) $rows[1]['level'] &&
			(int) $rows[0]['cid'] !== (int) $rows[1]['cid'] &&
			$workers[0]['record']['caller_connection'] !== $workers[1]['record']['caller_connection'] &&
			$workers[0]['record']['pid'] !== $workers[1]['record']['pid'] &&
			(int) $rows[0]['pid'] === $workers[0]['record']['pid'] &&
			(int) $rows[1]['pid'] === $workers[1]['record']['pid'], 'Independent readers did not coexist.' );
		$peer_token = $rows[1]['attempt_token'];
		refuse( 'package-readers', WP_Lock::WRITE );
		report( array( 'case' => 'shared_read', 'owner_ids' => array_column( $rows, 'id' ),
			'child_pids' => array( $workers[0]['record']['pid'], $workers[1]['record']['pid'] ),
			'caller_connections' => array( $workers[0]['record']['caller_connection'], $workers[1]['record']['caller_connection'] ),
			'owner_connections' => array_column( $rows, 'cid' ), 'pass' => true ) );
		stop_owner( $workers[0] );
		$rows = owners( 'package-readers' );
		need( 1 === count( $rows ) && (int) $rows[0]['id'] === $workers[1]['record']['owner_id'] &&
			$peer_token === $rows[0]['attempt_token'], 'First READ release removed the peer.' );
		stop_owner( $workers[1] );
		need( array() === owners( 'package-readers' ), 'READ release left an owner.' );
		$workers[] = start_owner( 'package-writer', WP_Lock::WRITE );
		$rows = owners( 'package-writer' );
		need( 1 === count( $rows ) && WP_Lock::WRITE === (int) $rows[0]['level'] &&
			(int) $rows[0]['id'] === $workers[2]['record']['owner_id'], 'WRITE owner identity mismatch.' );
		refuse( 'package-writer', WP_Lock::READ );
		refuse( 'package-writer', WP_Lock::WRITE );
		report( array( 'case' => 'exclusive_write', 'owner_id' => (int) $rows[0]['id'],
			'child_pid' => $workers[2]['record']['pid'], 'caller_connection' => $workers[2]['record']['caller_connection'],
			'owner_connection' => (int) $rows[0]['cid'], 'pass' => true ) );
		stop_owner( $workers[2] );
		need( array() === owners( 'package-writer' ), 'WRITE release left an owner.' );
	} finally {
		foreach ( $workers as &$worker ) {
			if ( $worker['active'] ) {
				try { stop_owner( $worker ); }
				catch ( Throwable $error ) { fwrite( STDERR, 'Owner cleanup failed: ' . $error->getMessage() . "\n" ); }
			}
		}
		unset( $worker );
	}
	need( 0 === (int) value( 'SELECT COUNT(*) FROM pkg_lock' ), 'Package smoke left owners.' );
	report( array( 'case' => 'summary', 'isolation' => $actual, 'pass' => true, 'owners' => 0 ) );
} catch ( Throwable $error ) {
	fwrite( STDERR, get_class( $error ) . ': ' . $error->getMessage() . "\n" );
	exit( 1 );
}
