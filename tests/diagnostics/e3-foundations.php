<?php
/** E3-02 only: disposable wp_lock_e1 database, socket and released WordPress source. */

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
require __DIR__ . '/../../lib/backend/class-wp-lock-foundation-db.php';

use iTRON\WP_Lock\WP_Lock;
use iTRON\WP_Lock\WP_Lock_Backend_DB;
use iTRON\WP_Lock\WP_Lock_Foundations;

function wp_debug_backtrace_summary() { return 'E3 diagnostic'; }

function e3_assert( $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function e3_sql( string $query ): int {
	global $wpdb;
	$result = $wpdb->query( $query );
	if ( false === $result || ! empty( $wpdb->last_error ) ) {
		throw new RuntimeException( 'Diagnostic SQL failed: ' . $wpdb->last_error );
	}
	return (int) $result;
}
function e3_connect( string $isolation ): wpdb {
	$db = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$db->prefix = 'e3_';
	$db->suppress_errors( true );
	e3_assert( 'wp_lock_e1' === $db->get_var( 'SELECT DATABASE()' ) && empty( $db->last_error ), 'Wrong disposable database.' );
	e3_assert( false !== $db->query( 'SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation ), 'Isolation setup failed.' );
	e3_assert( false !== $db->query( "SET SESSION sql_mode = 'STRICT_ALL_TABLES'" ), 'Strict SQL setup failed.' );
	e3_assert( false !== $db->query( 'SET NAMES utf8mb4' ), 'UTF-8 setup failed.' );
	e3_assert( false !== $db->query( 'SET SESSION innodb_lock_wait_timeout = 3' ), 'Lock wait setup failed.' );
	return $db;
}
function e3_count( string $id ): int {
	global $wpdb;
	$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM e3_lock WHERE lock_key = %s', md5( $id ) ) );
	e3_assert( empty( $wpdb->last_error ), 'Owner count query failed.' );
	return (int) $count;
}
function e3_marker(): ?string {
	global $wpdb;
	$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM e3_options WHERE option_name = %s', WP_Lock_Foundations::SCHEMA_OPTION ) );
	e3_assert( empty( $wpdb->last_error ), 'Marker query failed.' );
	return $value;
}
function e3_delete( string $id ): void {
	global $wpdb;
	e3_sql( $wpdb->prepare( 'DELETE FROM e3_lock WHERE lock_key = %s', md5( $id ) ) );
}
function e3_case( string $name, callable $run ): void {
	$run();
	echo json_encode( array( 'case' => $name, 'pass' => true ) ) . "\n";
}
function e3_session_db( WP_Lock_Foundations $session ): wpdb {
	$property = new ReflectionProperty( WP_Lock_Foundations::class, 'db' );
	if ( PHP_VERSION_ID < 80100 ) {
		$property->setAccessible( true );
	}
	return $property->getValue( $session );
}
function e3_variable( wpdb $db, string $name ): ?string {
	$row = $db->get_row( "SHOW SESSION VARIABLES LIKE '" . $name . "'", ARRAY_A );
	e3_assert( empty( $db->last_error ), 'Session variable lookup failed: ' . $name );
	if ( null === $row ) {
		return null;
	}
	e3_assert( is_array( $row ), 'Unexpected session variable result: ' . $name );
	$row = array_change_key_case( $row, CASE_LOWER );
	e3_assert( isset( $row['value'] ), 'Session variable value missing: ' . $name );
	return (string) $row['value'];
}
function e3_session_state( wpdb $db ): array {
	$row = $db->get_row( 'SELECT CONNECTION_ID() AS connection_id, @@autocommit AS autocommit', ARRAY_A );
	e3_assert( is_array( $row ) && empty( $db->last_error ), 'Session state lookup failed.' );
	$row = array_change_key_case( $row, CASE_LOWER );
	$level = e3_variable( $db, 'transaction_isolation' );
	if ( null === $level ) {
		$level = e3_variable( $db, 'tx_isolation' );
	}
	e3_assert( null !== $level, 'Session isolation lookup failed.' );
	return array(
		'connection_id' => (string) $row['connection_id'],
		'autocommit' => (string) $row['autocommit'],
		'isolation' => $level,
		'innodb_snapshot_isolation' => e3_variable( $db, 'innodb_snapshot_isolation' ),
	);
}
class E3_Fault_DB extends \iTRON\WP_Lock\WP_Lock_Foundation_DB {
	public ?string $lose_ack = null;
	public ?string $kill_before = null;
	public bool $reject_snapshot_setting = false;
	public int $transaction_starts = 0;
	public function query( $query ) {
		global $wpdb;
		if ( 'START TRANSACTION' === $query ) {
			++$this->transaction_starts;
		}
		if ( $this->reject_snapshot_setting && 'SET SESSION innodb_snapshot_isolation = OFF' === $query ) {
			$this->last_error = 'Injected session-setting refusal.';
			return false;
		}
		if ( null !== $this->kill_before && 0 === strpos( $query, $this->kill_before ) ) {
			$this->kill_before = null;
			e3_sql( 'KILL CONNECTION ' . (int) $this->dbh->thread_id );
		}
		$result = parent::query( $query );
		if ( null !== $this->lose_ack && 0 === strpos( $query, $this->lose_ack ) && false !== $result ) {
			$this->lose_ack = null;
			throw new RuntimeException( 'Simulated lost SQL acknowledgment after execution.' );
		}
		return $result;
	}
}
function e3_fault_session(): WP_Lock_Foundations {
	$session = WP_Lock_Foundations::open();
	$old = e3_session_db( $session );
	$db = new E3_Fault_DB( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$db->suppress_errors( true );
	$configure = new ReflectionMethod( WP_Lock_Foundations::class, 'configure_snapshot_isolation' );
	if ( PHP_VERSION_ID < 80100 ) {
		$configure->setAccessible( true );
	}
	$configure->invoke( null, $db );
	$route_method = new ReflectionMethod( WP_Lock_Foundations::class, 'route' );
	if ( PHP_VERSION_ID < 80100 ) {
		$route_method->setAccessible( true );
	}
	$route = $route_method->invoke( null, $db );
	foreach ( array( 'db' => $db, 'route' => $route, 'connection_id' => (int) $route['connection_id'] ) as $name => $value ) {
		$property = new ReflectionProperty( WP_Lock_Foundations::class, $name );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( $session, $value );
	}
	$old->close();
	$session->connection_id();
	return $session;
}
function e3_pair_first( string $isolation ): void {
	global $wpdb;
	$id = 'e3-first-' . str_replace( ' ', '-', $isolation );
	e3_delete( $id );
	e3_sql( $wpdb->prepare( 'DELETE FROM e3_lock_resource WHERE lock_key = %s', md5( $id ) ) );
	$pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
	e3_assert( false !== $pair, 'Worker socket unavailable.' );
	$pid = pcntl_fork();
	e3_assert( -1 !== $pid, 'Worker fork unavailable.' );
	if ( 0 === $pid ) {
		fclose( $pair[0] );
		try {
			if ( "go\n" !== fgets( $pair[1] ) ) {
				exit( 1 );
			}
			$wpdb = e3_connect( $isolation );
			$session = WP_Lock_Foundations::open();
			$session->isolation( $isolation );
			fwrite( $pair[1], "started\n" );
			$started = microtime( true );
			$session->begin_resource( $id );
			$elapsed = microtime( true ) - $started;
			$session->commit();
			$session->close();
			fwrite( $pair[1], 'acquired:' . $elapsed . "\n" );
			exit( 0 );
		} catch ( Throwable $error ) {
			fwrite( STDERR, $error->getMessage() . "\n" );
			exit( 1 );
		}
	}
	fclose( $pair[1] );
	$session = null;
	try {
		$session = WP_Lock_Foundations::open();
		$session->isolation( $isolation );
		$session->begin_resource( $id );
		fwrite( $pair[0], "go\n" );
		stream_set_timeout( $pair[0], 5 );
		e3_assert( "started\n" === fgets( $pair[0] ), 'Second first-acquirer did not start.' );
		$read = array( $pair[0] ); $write = null; $except = null;
		e3_assert( 0 === stream_select( $read, $write, $except, 0, 200000 ), 'Second first-acquirer did not wait.' );
		$session->commit();
		$reply = fgets( $pair[0] );
		e3_assert( 0 === strpos( (string) $reply, 'acquired:' ) && (float) substr( $reply, 9 ) >= 0.15, 'Second first-acquirer did not wait and resume.' );
	} finally {
		if ( $session ) {
			$session->close();
		}
		fclose( $pair[0] );
		pcntl_waitpid( $pid, $status );
		e3_assert( pcntl_wifexited( $status ) && 0 === pcntl_wexitstatus( $status ), 'First-acquirer worker failed.' );
	}
}

try {
	global $wpdb, $wp_version;
	e3_case( 'read_only_values', function() {
		$writable = new ReflectionMethod( WP_Lock_Foundations::class, 'writable_read_only' );
		if ( PHP_VERSION_ID < 80100 ) {
			$writable->setAccessible( true );
		}
		foreach ( array( '0', 'OFF' ) as $value ) {
			e3_assert( true === $writable->invoke( null, $value ), 'Writable read_only value was rejected: ' . $value );
		}
		foreach ( array( '1', 'ON', 'NO_LOCK', 'NO_LOCK_NO_ADMIN', 'unknown' ) as $value ) {
			e3_assert( false === $writable->invoke( null, $value ), 'Read-only or unknown value was accepted: ' . $value );
		}
	} );
	$wpdb = e3_connect( 'REPEATABLE READ' );
	e3_sql( 'DROP TABLE IF EXISTS e3_lock_resource' );
	e3_sql( 'DROP TABLE IF EXISTS e3_lock' );
	e3_sql( 'DROP TABLE IF EXISTS e3_options' );
	e3_sql( 'CREATE TABLE e3_options (option_name varchar(191) NOT NULL PRIMARY KEY, option_value longtext NOT NULL, autoload varchar(20) NOT NULL) ENGINE=InnoDB' );
	e3_case( 'install', function() {
		WP_Lock_Foundations::prepare_schema();
		e3_assert( WP_Lock_Foundations::SCHEMA_VERSION === e3_marker(), 'Foundation marker missing.' );
	} );
	e3_case( 'snapshot_setting_rejection_if_exposed', function() {
		$db = new E3_Fault_DB( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$db->suppress_errors( true );
		try {
			if ( null === e3_variable( $db, 'innodb_snapshot_isolation' ) ) {
				return;
			}
			$db->reject_snapshot_setting = true;
			$configure = new ReflectionMethod( WP_Lock_Foundations::class, 'configure_snapshot_isolation' );
			if ( PHP_VERSION_ID < 80100 ) {
				$configure->setAccessible( true );
			}
			try {
				$configure->invoke( null, $db );
				throw new RuntimeException( 'Rejected snapshot setting was accepted.' );
			} catch ( RuntimeException $expected ) {
				e3_assert( false !== strpos( $expected->getMessage(), 'could not be set' ), 'Unexpected snapshot-setting refusal result.' );
			}
		} finally {
			$db->close();
		}
	} );
	e3_case( 'invalid_schema_and_ddl_failure', function() {
		global $wpdb;
		e3_sql( $wpdb->prepare( 'UPDATE e3_options SET option_value = %s WHERE option_name = %s', 'old', WP_Lock_Foundations::SCHEMA_OPTION ) );
		e3_sql( 'ALTER TABLE e3_lock_resource DROP PRIMARY KEY' );
		try {
			WP_Lock_Foundations::prepare_schema();
			throw new RuntimeException( 'Invalid schema was accepted.' );
		} catch ( RuntimeException $error ) {
			e3_assert( false !== strpos( $error->getMessage(), 'verification failed' ), 'Unexpected invalid-schema result.' );
		}
		e3_assert( 'old' === e3_marker(), 'Invalid schema advanced marker.' );
		e3_sql( 'ALTER TABLE e3_lock_resource ADD PRIMARY KEY (lock_key)' );
		foreach ( array( 'UNIQUE KEY extra_owner (lock_key)', 'UNIQUE KEY short_token (attempt_token(8))' ) as $index ) {
			e3_sql( 'ALTER TABLE e3_lock ADD ' . $index );
			try {
				WP_Lock_Foundations::prepare_schema();
				throw new RuntimeException( 'Invalid owner index was accepted.' );
			} catch ( RuntimeException $error ) {
				e3_assert( false !== strpos( $error->getMessage(), 'verification failed' ), 'Unexpected owner-index result.' );
			}
			e3_assert( 'old' === e3_marker(), 'Invalid index advanced marker.' );
			e3_sql( 'ALTER TABLE e3_lock DROP INDEX ' . ( 0 === strpos( $index, 'UNIQUE KEY extra_owner' ) ? 'extra_owner' : 'short_token' ) );
		}
		e3_sql( 'ALTER TABLE e3_lock DROP INDEX attempt_token, ADD UNIQUE KEY attempt_token (attempt_token(8))' );
		try {
			WP_Lock_Foundations::prepare_schema();
			throw new RuntimeException( 'Prefix token key was accepted.' );
		} catch ( RuntimeException $error ) {
			e3_assert( false !== strpos( $error->getMessage(), 'verification failed' ), 'Unexpected prefix-key result.' );
		}
		e3_assert( 'old' === e3_marker(), 'Prefix key advanced marker.' );
		e3_sql( 'ALTER TABLE e3_lock DROP INDEX attempt_token, ADD UNIQUE KEY attempt_token (attempt_token)' );
		$fail_ddl = function( $query ) { return 0 === strpos( $query, 'CREATE TABLE IF NOT EXISTS' ) ? '' : $query; };
		add_filter( 'query', $fail_ddl );
		try {
			WP_Lock_Foundations::prepare_schema();
			throw new RuntimeException( 'Failed DDL was accepted.' );
		} catch ( RuntimeException $error ) {
			e3_assert( false !== strpos( $error->getMessage(), 'schema change failed' ), 'Unexpected DDL-failure result.' );
		} finally {
			remove_filter( 'query', $fail_ddl );
		}
		e3_assert( 'old' === e3_marker(), 'DDL failure advanced marker.' );
		WP_Lock_Foundations::prepare_schema();
	} );
	e3_case( 'strict_full_identity', function() {
		global $wpdb;
		$a = str_repeat( 'x', 50 ) . 'A';
		$b = str_repeat( 'x', 50 ) . 'B';
		$unicode = str_repeat( 'Ж', 26 );
		$backend_a = new WP_Lock_Backend_DB();
		$backend_b = new WP_Lock_Backend_DB();
		e3_assert( $backend_a->acquire( $a, WP_Lock::READ, false, 0 ), 'First long ID rejected.' );
		e3_assert( $backend_b->acquire( $b, WP_Lock::READ, false, 0 ), 'Second long ID rejected.' );
		e3_assert( $backend_a->acquire( $unicode, WP_Lock::READ, false, 0 ), 'Unicode ID rejected.' );
		foreach ( array( $a, $b, $unicode ) as $id ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT lock_key, original_key FROM e3_lock WHERE lock_key = %s', md5( $id ) ), ARRAY_A );
			e3_assert( $row && md5( $id ) === $row['lock_key'], 'Full ID was not hashed.' );
			e3_assert( $unicode === $id ? $unicode === $row['original_key'] : null === $row['original_key'], 'Diagnostic was not stored exactly.' );
		}
		e3_assert( $backend_a->release( $a ) && $backend_b->release( $b ) && $backend_a->release( $unicode ), 'Legacy cleanup failed.' );
		e3_sql( 'ALTER TABLE e3_lock MODIFY original_key varchar(50) CHARACTER SET utf8mb3 DEFAULT NULL' );
		$wpdb = e3_connect( 'REPEATABLE READ' ); // New metadata cache for the changed column.
		$three_byte = new WP_Lock_Backend_DB();
		e3_assert( $three_byte->acquire( $unicode, WP_Lock::READ, false, 0 ), 'Representable utf8mb3 ID rejected.' );
		$row = $wpdb->get_var( $wpdb->prepare( 'SELECT original_key FROM e3_lock WHERE lock_key = %s', md5( $unicode ) ) );
		e3_assert( $unicode === $row, 'Representable utf8mb3 diagnostic was omitted.' );
		e3_assert( $three_byte->release( $unicode ), 'utf8mb3 cleanup failed.' );
		$emoji = 'e3-emoji-😀';
		e3_assert( $three_byte->acquire( $emoji, WP_Lock::READ, false, 0 ), 'Unrepresentable diagnostic ID rejected.' );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT lock_key, original_key FROM e3_lock WHERE lock_key = %s', md5( $emoji ) ), ARRAY_A );
		e3_assert( $row && md5( $emoji ) === $row['lock_key'] && null === $row['original_key'], 'Unrepresentable diagnostic was stored or full ID lost.' );
		e3_assert( $three_byte->release( $emoji ), 'Emoji cleanup failed.' );
		e3_sql( 'ALTER TABLE e3_lock MODIFY original_key varchar(50) CHARACTER SET utf8mb4 DEFAULT NULL' );
		$wpdb = e3_connect( 'REPEATABLE READ' );
	} );
	e3_case( 'finite_timeout', function() {
		foreach ( array( NAN, INF, -INF, -1.0 ) as $limit ) {
			try {
				new WP_Lock_Backend_DB( $limit );
				throw new RuntimeException( 'Non-finite/negative timeout accepted.' );
			} catch ( InvalidArgumentException $expected ) {
			}
		}
		$zero = new WP_Lock_Backend_DB( 0.0 );
		$positive = new WP_Lock_Backend_DB( 0.01 );
		e3_assert( $zero->acquire( 'e3-zero-timeout', WP_Lock::WRITE, true, 0 ), 'Zero timeout did not attempt free resource.' );
		e3_assert( ! $positive->acquire( 'e3-zero-timeout', WP_Lock::WRITE, true, 0 ), 'Positive timeout did not preserve contention.' );
		e3_assert( $zero->release( 'e3-zero-timeout' ), 'Zero-timeout owner release failed.' );
	} );
	foreach ( array( 'REPEATABLE READ', 'READ COMMITTED' ) as $isolation ) {
		$wpdb = e3_connect( $isolation );
		e3_case( 'implicit_isolation_' . $isolation, function() {
			$session = WP_Lock_Foundations::open();
			try {
				$actual = str_replace( '-', ' ', strtoupper( e3_session_state( e3_session_db( $session ) )['isolation'] ) );
				if ( in_array( $actual, array( 'REPEATABLE READ', 'READ COMMITTED' ), true ) ) {
					$session->begin();
					$session->rollback();
				} else {
					try {
						$session->begin();
						throw new RuntimeException( 'Unsupported default isolation started a transaction.' );
					} catch ( RuntimeException $expected ) {
						e3_assert( false !== strpos( $expected->getMessage(), 'isolation could not be verified' ), 'Unexpected default-isolation refusal.' );
					}
				}
			} finally {
				$session->close();
			}
		} );
		e3_case( 'changed_isolation_refused_' . $isolation, function() use ( $isolation ) {
			foreach ( array( 'READ UNCOMMITTED', 'SERIALIZABLE' ) as $changed ) {
				$session = e3_fault_session();
				try {
					$session->isolation( $isolation );
					$db = e3_session_db( $session );
					e3_assert( false !== $db->query( 'SET SESSION TRANSACTION ISOLATION LEVEL ' . $changed ) && empty( $db->last_error ), 'Controlled isolation change failed.' );
					$id = 'e3-refused-' . str_replace( ' ', '-', $changed );
					$owners = e3_count( $id );
					try {
						$session->begin_resource( $id );
						throw new RuntimeException( 'Changed isolation started a foundation transaction.' );
					} catch ( RuntimeException $expected ) {
						e3_assert( false !== strpos( $expected->getMessage(), 'isolation' ), 'Unexpected changed-isolation refusal.' );
					}
					e3_assert( 0 === $db->transaction_starts && $owners === e3_count( $id ), 'Rejected isolation started a transaction or wrote an owner.' );
				} finally {
					$session->close();
				}
			}
		} );
		$caller_state = e3_session_state( $wpdb );
		$controlled = WP_Lock_Foundations::open();
		$controlled->isolation( $isolation );
		$controlled_state = e3_session_state( e3_session_db( $controlled ) );
		$controlled->close();
		e3_assert( $caller_state === e3_session_state( $wpdb ), 'Opening controlled session changed caller settings.' );
		e3_assert( $caller_state['connection_id'] !== $controlled_state['connection_id'], 'Controlled session shares caller connection.' );
		e3_assert( null === $caller_state['innodb_snapshot_isolation'] ? null === $controlled_state['innodb_snapshot_isolation'] :
			in_array( $controlled_state['innodb_snapshot_isolation'], array( '0', 'OFF' ), true ), 'Controlled snapshot isolation was not OFF.' );
		e3_assert( str_replace( '-', ' ', strtoupper( $controlled_state['isolation'] ) ) === $isolation, 'Controlled isolation changed.' );
		$environment = $wpdb->get_row( 'SELECT VERSION() AS version, CONNECTION_ID() AS connection_id, @@autocommit AS autocommit, @@sql_mode AS sql_mode', ARRAY_A );
		$environment['php'] = PHP_VERSION;
		$environment['wordpress'] = $wp_version;
		$environment['isolation'] = $isolation;
		$environment['engine'] = $wpdb->get_var( "SELECT engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'e3_lock'" );
		$environment['caller_session'] = $caller_state;
		$environment['controlled_session'] = $controlled_state;
		e3_assert( 'InnoDB' === $environment['engine'], 'InnoDB required.' );
		echo json_encode( array( 'case' => 'environment', 'data' => $environment ) ) . "\n";
		e3_case( 'first_acquirer_' . $isolation, function() use ( $isolation ) { e3_pair_first( $isolation ); } );
		e3_case( 'current_read_' . $isolation, function() use ( $isolation ) {
			$id = 'e3-snapshot-' . $isolation;
			$phase = 'cleanup';
			$active = null;
			try {
				e3_delete( $id );
				$phase = 'snapshot';
				$active = $a = WP_Lock_Foundations::open();
				$a->isolation( $isolation );
				$a->begin();
				e3_assert( array() === e3_session_db( $a )->get_results( e3_session_db( $a )->prepare( 'SELECT id FROM e3_lock WHERE lock_key = %s', md5( $id ) ), ARRAY_A ), 'Snapshot setup not empty.' );
				$phase = 'competing_owner';
				$active = $b = WP_Lock_Foundations::open();
				$b->isolation( $isolation );
				$b->begin_resource( $id );
				$token = WP_Lock_Foundations::new_token();
				$b->insert_owner( $id, WP_Lock::WRITE, $token );
				$b->commit();
				$b->close();
				$phase = 'resource_lock';
				$active = $a;
				$a->lock_resource( $id );
				$phase = 'current_owners';
				e3_assert( 1 === count( $a->current_owners( $id ) ), 'Current locking read missed owner.' );
				$phase = 'commit';
				$a->commit();
				$a->close();
				$phase = 'cleanup';
				$active = null;
				e3_delete( $id );
			} catch ( Throwable $error ) {
				$db = $active ? e3_session_db( $active ) : null;
				$errno = $db && $db->dbh instanceof mysqli ? mysqli_errno( $db->dbh ) : 0;
				$sqlstate = $db && $db->dbh instanceof mysqli ? mysqli_sqlstate( $db->dbh ) : 'unknown';
				$db_error = $db ? preg_replace( "/'[^']*'|`[^`]*`/", '[redacted]', $db->last_error ) : '';
				throw new RuntimeException( 'current_read ' . $isolation . ' ' . $phase . ' failed: errno=' . $errno . ' sqlstate=' . $sqlstate . ' error=' . $db_error, 0, $error );
			}
		} );
		e3_case( 'shared_readers_' . $isolation, function() use ( $isolation ) {
			$id = 'e3-readers-' . $isolation;
			e3_delete( $id );
			foreach ( array( 1, 2 ) as $reader ) {
				$session = WP_Lock_Foundations::open();
				$session->isolation( $isolation );
				$session->begin_resource( $id );
				foreach ( $session->current_owners( $id ) as $owner ) {
					e3_assert( WP_Lock::READ === (int) $owner['level'], 'Reader saw incompatible owner.' );
				}
				$session->insert_owner( $id, WP_Lock::READ, WP_Lock_Foundations::new_token() );
				$session->commit();
				$session->close();
			}
			e3_assert( 2 === e3_count( $id ), 'Readers did not coexist.' );
			e3_delete( $id );
		} );
		e3_case( 'outer_transaction_namespace_and_tokens_' . $isolation, function() use ( $isolation ) {
			global $wpdb;
			$id = 'e3-outer-' . $isolation;
			e3_delete( $id );
			e3_sql( 'CREATE TABLE IF NOT EXISTS e3_probe (id int PRIMARY KEY) ENGINE=InnoDB' );
			e3_sql( 'DELETE FROM e3_probe' );
			e3_sql( 'START TRANSACTION' );
			e3_sql( 'INSERT INTO e3_probe VALUES (1)' );
			$caller_state = e3_session_state( $wpdb );
			$caller_id = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
			WP_Lock_Foundations::prepare_schema(); // DDL and marker must not commit the caller's row.
			$session = WP_Lock_Foundations::open();
			$session->isolation( $isolation );
			e3_assert( $caller_id !== $session->connection_id(), 'Session is not independent.' );
			$wpdb->prefix = 'changed_';
			$session->begin_resource( $id );
			$token = WP_Lock_Foundations::new_token();
			$session->insert_owner( $id, WP_Lock::WRITE, $token );
			$session->commit();
			$session->close();
			$wpdb->prefix = 'e3_';
			e3_assert( $caller_state === e3_session_state( $wpdb ), 'Controlled work changed caller transaction settings.' );
			e3_sql( 'ROLLBACK' );
			e3_assert( $caller_id === (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' ) &&
				1 === (int) $wpdb->get_var( 'SELECT @@autocommit' ), 'Caller session state changed.' );
			e3_assert( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM e3_probe' ), 'Caller transaction was committed.' );
			e3_assert( 1 === e3_count( $id ), 'Caller rollback removed owner.' );
			$reconcile = WP_Lock_Foundations::open();
			$reconcile->isolation( $isolation );
			$reconcile->begin_resource( $id );
			e3_assert( 1 === count( $reconcile->find_attempt( $id, $token ) ), 'Lost response token was not recovered.' );
			e3_assert( array() === $reconcile->find_attempt( $id, WP_Lock_Foundations::new_token() ), 'Foreign token matched.' );
			$reconcile->commit();
			$reconcile->close();
			e3_delete( $id );
		} );
		e3_case( 'lost_insert_and_commit_response_' . $isolation, function() use ( $isolation ) {
			$id = 'e3-lost-' . $isolation;
			e3_delete( $id );
			$token = WP_Lock_Foundations::new_token();
			$other = WP_Lock_Foundations::new_token();
			e3_assert( $token !== $other, 'Attempt tokens duplicated.' );
			$old = e3_fault_session();
			$old->isolation( $isolation );
			$old->begin_resource( $id );
			e3_session_db( $old )->lose_ack = 'INSERT INTO `e3_lock`';
			try {
				$old->insert_owner( $id, WP_Lock::WRITE, $token );
				throw new RuntimeException( 'Lost INSERT acknowledgment was accepted.' );
			} catch ( RuntimeException $error ) {
				e3_assert( false !== strpos( $error->getMessage(), 'lost SQL acknowledgment' ), 'Unexpected INSERT fault.' );
			}
			e3_assert( 1 === (int) e3_session_db( $old )->get_var( e3_session_db( $old )->prepare( 'SELECT COUNT(*) FROM e3_lock WHERE attempt_token = %s', $token ) ), 'INSERT was not applied before acknowledgment fault.' );
			e3_session_db( $old )->lose_ack = 'COMMIT';
			try {
				$old->commit();
				throw new RuntimeException( 'Lost COMMIT acknowledgment was accepted.' );
			} catch ( RuntimeException $error ) {
				e3_assert( false !== strpos( $error->getMessage(), 'lost SQL acknowledgment' ), 'Unexpected COMMIT fault.' );
			}
			$old->close(); // Commit was applied, and the old session is resolved before lookup.
			$fresh = WP_Lock_Foundations::open();
			$fresh->isolation( $isolation );
			$fresh->begin_resource( $id );
			e3_assert( 1 === count( $fresh->find_attempt( $id, $token ) ), 'Committed attempt token missing.' );
			e3_assert( array() === $fresh->find_attempt( $id, $other ), 'Different attempt token matched.' );
			try {
				$fresh->insert_owner( $id, WP_Lock::READ, $token );
				throw new RuntimeException( 'Duplicate token was accepted.' );
			} catch ( RuntimeException $expected ) {
				e3_assert( false !== strpos( $expected->getMessage(), 'SQL failed' ), 'Unexpected token uniqueness result.' );
			}
			$fresh->rollback();
			$fresh->close();
			e3_delete( $id );
			$rolled_back = e3_fault_session();
			$rolled_back->isolation( $isolation );
			$rolled_back->begin_resource( $id );
			e3_session_db( $rolled_back )->lose_ack = 'INSERT INTO `e3_lock`';
			try {
				$rolled_back->insert_owner( $id, WP_Lock::WRITE, $other );
				throw new RuntimeException( 'Lost rollback INSERT acknowledgment was accepted.' );
			} catch ( RuntimeException $error ) {
				e3_assert( false !== strpos( $error->getMessage(), 'lost SQL acknowledgment' ), 'Unexpected rollback INSERT fault.' );
			}
			$rolled_back->rollback();
			$rolled_back->close();
			$fresh = WP_Lock_Foundations::open();
			$fresh->isolation( $isolation );
			$fresh->begin_resource( $id );
			e3_assert( array() === $fresh->find_attempt( $id, $other ), 'Rolled-back attempt looked committed.' );
			$fresh->commit();
			$fresh->close();
		} );
	}
		e3_case( 'routing_and_reconnect_fail_closed', function() {
		global $wpdb;
		$normal = $wpdb;
		$wpdb = new class(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST) extends wpdb {};
		$wpdb->prefix = $normal->prefix;
		try {
			WP_Lock_Foundations::open();
			throw new RuntimeException( 'Unverifiable drop-in route was accepted.' );
		} catch ( RuntimeException $expected ) {
			e3_assert( false !== strpos( $expected->getMessage(), 'routing' ), 'Unexpected route-failure result.' );
		} finally {
			$wpdb = $normal;
		}
		$session = e3_fault_session();
		$session->begin_resource( 'e3-reconnect' );
		e3_session_db( $session )->kill_before = 'INSERT INTO `e3_lock`';
		try {
			$session->insert_owner( 'e3-reconnect', WP_Lock::WRITE, WP_Lock_Foundations::new_token() );
			throw new RuntimeException( 'Lost transaction returned an owner.' );
		} catch ( Throwable $expected ) {
			e3_assert( false === strpos( $expected->getMessage(), 'returned an owner' ), 'Lost connection granted an owner.' );
		}
		e3_assert( 0 === e3_count( 'e3-reconnect' ), 'Lost transaction inserted an owner.' );
		$session->close();
		try {
			$session->connection_id();
			throw new RuntimeException( 'Closed/reconnected session retained authority.' );
		} catch ( RuntimeException $expected ) {
			e3_assert( false !== strpos( $expected->getMessage(), 'session changed' ), 'Unexpected reconnect result.' );
		}
	} );
	echo json_encode( array( 'case' => 'summary', 'data' => array( 'pass' => true, 'php' => PHP_VERSION, 'wordpress' => $wp_version ) ) ) . "\n";
	exit( 0 );
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" );
		echo json_encode( array( 'case' => 'summary', 'data' => array( 'pass' => false, 'error' => $error->getMessage(), 'php' => PHP_VERSION, 'wordpress' => $wp_version ) ) ) . "\n";
	exit( 1 );
}
