<?php
/** Real tagged 2.0.0 participant in a separate PHP process; disposable E5 fixture only. */
$socket = getenv( 'WP_LOCK_E1_SOCKET' );
$wp_src = getenv( 'WP_LOCK_E1_WP' );
$legacy = getenv( 'WP_LOCK_E5_LEGACY_ROOT' );
$barrier = getenv( 'WP_LOCK_E5_BARRIER' );
if ( 'wp_lock_e1' !== getenv( 'WP_LOCK_E1_DISPOSABLE' ) || ! $socket || ! file_exists( $socket ) ||
	! $wp_src || ! is_file( $wp_src . '/wp-includes/class-wpdb.php' ) ||
	! $legacy || ! is_file( $legacy . '/lib/backend/class-wp-lock-backend-db.php' ) || ! $barrier ) {
	fwrite( STDERR, "Disposable legacy fixture is unavailable.\n" );
	exit( 2 );
}
if ( file_exists( $barrier ) ) {
	fwrite( STDERR, "Fixture admissions barrier is engaged.\n" );
	exit( 4 );
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
require $legacy . '/lib/backend/class-wp-lock-backend.php';
require $legacy . '/lib/class-wp-lock.php';
require $legacy . '/lib/backend/class-wp-lock-backend-db.php';

function wp_debug_backtrace_summary() { return 'E5 tagged legacy fixture'; }

$wpdb = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$wpdb->prefix = 'e5_';
$wpdb->suppress_errors( true );
$isolation = getenv( 'WP_LOCK_E5_ISOLATION' );
if ( ! in_array( $isolation, array( 'REPEATABLE READ', 'READ COMMITTED' ), true ) ||
	false === $wpdb->query( 'SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation ) ||
	'wp_lock_e1' !== $wpdb->get_var( 'SELECT DATABASE()' ) || ! empty( $wpdb->last_error ) ) {
	exit( 2 );
}
$id = $argv[1] ?? 'legacy-e5-owner';
$lock = new \iTRON\WP_Lock\WP_Lock( $id, new \iTRON\WP_Lock\WP_Lock_Backend_DB() );
if ( ! $lock->acquire( \iTRON\WP_Lock\WP_Lock::WRITE, false, 0 ) ) {
	exit( 3 );
}
echo json_encode( array( 'event' => 'legacy-owner', 'pid' => getmypid(), 'connection_id' => (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' ), 'isolation' => $isolation ) ) . "\n";
flush();
if ( "release\n" !== fgets( STDIN ) ) {
	exit( 5 );
}
$lock->release();
echo "released\n";
