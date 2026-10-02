<?php
foreach ( array( 'pcntl_fork', 'pcntl_exec', 'pcntl_waitpid', 'posix_kill' ) as $function ) {
	if ( ! function_exists( $function ) ) {
		throw new RuntimeException( 'Required process control is unavailable: ' . $function );
	}
}
$test_root = getenv( 'WP_TESTS_DIR' ) ? : dirname( __FILE__ ) . '/../wordpress-develop/tests/phpunit';

require $test_root . '/includes/functions.php';

require dirname( __FILE__ ) . '/include.php';

function _manually_load_plugin() {
	require dirname( __FILE__ ) . '/../plugin.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

require $test_root . '/includes/bootstrap.php';

$expected_isolation = getenv( 'WP_LOCK_EXPECTED_ISOLATION' );
if ( false !== $expected_isolation ) {
	global $wpdb, $wp_version;
	$actual_isolation = $wpdb->get_var( "SHOW SESSION VARIABLES LIKE 'transaction_isolation'", 1 );
	if ( null === $actual_isolation ) {
		$actual_isolation = $wpdb->get_var( "SHOW SESSION VARIABLES LIKE 'tx_isolation'", 1 );
	}
	$actual_isolation = str_replace( '-', ' ', strtoupper( (string) $actual_isolation ) );
	$route = $wpdb->get_row( 'SELECT DATABASE() AS database_name, VERSION() AS server_version, @@default_storage_engine AS engine, @@read_only AS read_only, CONNECTION_ID() AS connection_id', ARRAY_A );
	if ( ! is_array( $route ) || ! empty( $wpdb->last_error ) || $expected_isolation !== $actual_isolation ||
		getenv( 'WP_LOCK_EXPECTED_PHP_VERSION' ) !== PHP_VERSION || getenv( 'WP_LOCK_EXPECTED_WORDPRESS_VERSION' ) !== $wp_version ||
		'InnoDB' !== $route['engine'] || ! in_array( (string) $route['read_only'], array( '0', 'OFF' ), true ) ||
		! $route['database_name'] || ! $route['connection_id'] ) {
		throw new RuntimeException( 'Native matrix database or isolation did not verify.' );
	}
	$record = getenv( 'WP_LOCK_RUNTIME_RECORD' );
	if ( ! $record || false === file_put_contents( $record, json_encode( array(
		'php' => PHP_VERSION,
		'wordpress' => $wp_version,
		'isolation' => $actual_isolation,
		'route' => $route,
	) ) . "\n" ) ) {
		throw new RuntimeException( 'Native matrix runtime record could not be saved.' );
	}
}
