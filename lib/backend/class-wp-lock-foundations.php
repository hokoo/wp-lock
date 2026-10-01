<?php

namespace iTRON\WP_Lock;

/** A failed statement with a driver error code from the controlled connection. */
final class WP_Lock_Foundation_SQL_Error extends \RuntimeException {}

/**
 * Controlled 3.0 resource and owner transactions on an independent connection.
 */
final class WP_Lock_Foundations {
	const RESOURCE_TABLE = 'lock_resource';
	const SCHEMA_OPTION = 'wp_lock_db_foundation_schema_version';
	const SCHEMA_VERSION = '3.0.0-foundations';

	private $db;
	private string $namespace;
	private int $connection_id;
	private array $route;
	private bool $in_transaction = false;
	private bool $resource_locked = false;
	private ?string $locked_key = null;

	private function __construct( $db, string $namespace, array $route ) {
		$this->db = $db;
		$this->namespace = $namespace;
		$this->connection_id = (int) $route['connection_id'];
		$this->route = $route;
	}

	/** Prepare additive schema explicitly; live-site protocol switching is separate. */
	public static function prepare_schema(): void {
		global $wpdb;

		$namespace = self::namespace();
		$session = self::connect( $namespace );
		$db = $session->db;
		$owner = self::quote( $namespace . WP_Lock_Backend_DB::TABLE_NAME );
		$resource = self::quote( $namespace . self::RESOURCE_TABLE );
		$charset = $wpdb->get_charset_collate();
		try {
		self::ddl( $session, "CREATE TABLE IF NOT EXISTS {$owner} (
			id int(10) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
			lock_key varchar(50) DEFAULT NULL,
			original_key varchar(50) DEFAULT NULL,
			level smallint(5) unsigned DEFAULT NULL,
			pid int(10) unsigned DEFAULT NULL,
			cid int(10) unsigned DEFAULT NULL,
			expire decimal(16,6) unsigned DEFAULT NULL,
			KEY lock_key (lock_key), KEY level (level)
		) ENGINE=InnoDB {$charset}" );
		self::ddl( $session, "CREATE TABLE IF NOT EXISTS {$resource} (
			lock_key char(32) NOT NULL PRIMARY KEY
		) ENGINE=InnoDB {$charset}" );
		if ( ! self::column( $db, $namespace . WP_Lock_Backend_DB::TABLE_NAME, 'attempt_token' ) ) {
			self::ddl( $session, "ALTER TABLE {$owner} ADD COLUMN attempt_token char(32) DEFAULT NULL" );
		}
		if ( ! self::index( $db, $namespace . WP_Lock_Backend_DB::TABLE_NAME, 'attempt_token' ) ) {
			self::ddl( $session, "ALTER TABLE {$owner} ADD UNIQUE KEY attempt_token (attempt_token)" );
		}

		$session->assert_connection();
		self::verify_schema( $db, $namespace );
		$session->assert_connection();
		$options = self::quote( $namespace . 'options' );
		self::ddl( $session, $db->prepare(
			"INSERT INTO {$options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
			self::SCHEMA_OPTION, self::SCHEMA_VERSION
		) );
		$version = $db->get_var( $db->prepare( "SELECT option_value FROM {$options} WHERE option_name = %s", self::SCHEMA_OPTION ) );
		if ( ! empty( $db->last_error ) || self::SCHEMA_VERSION !== $version ) {
			throw new \RuntimeException( 'Lock foundation schema marker could not be verified.' );
		}
		$session->assert_connection();
		} finally {
			$session->close();
		}
	}

	/** Capture the physical namespace before a blog or prefix switch. */
	public static function namespace(): string {
		global $wpdb;
		if ( ! isset( $wpdb->prefix ) || ! preg_match( '/\A[A-Za-z0-9_]+\z/D', $wpdb->prefix ) ) {
			throw new \RuntimeException( 'Unsupported lock table namespace.' );
		}
		return $wpdb->prefix;
	}

	public static function open( ?string $namespace = null ): self {
		global $wpdb;
		$namespace = null === $namespace ? self::namespace() : $namespace;
		$session = self::connect( $namespace );
		try {
			$level = self::session_isolation( $wpdb );
			$session->isolation( $level );
			self::verify_schema( $session->db, $namespace );
			$session->assert_connection();
			return $session;
		} catch ( \Throwable $error ) {
			$session->close();
			throw $error;
		}
	}

	private static function connect( string $namespace ): self {
		global $wpdb;

		if ( ! preg_match( '/\A[A-Za-z0-9_]+\z/D', $namespace ) || get_class( $wpdb ) !== 'wpdb' ||
			! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' ) || ! defined( 'DB_HOST' ) ) {
			throw new \RuntimeException( 'Independent primary routing cannot be established.' );
		}

		$caller = self::route( $wpdb );
		require_once __DIR__ . '/class-wp-lock-foundation-db.php';
		$db = new WP_Lock_Foundation_DB( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$db->suppress_errors( true );
		try {
			$route = self::route( $db );
			if ( $caller['connection_id'] === $route['connection_id'] ||
				$caller['database_name'] !== $route['database_name'] ||
				$caller['server_name'] !== $route['server_name'] ||
				(int) $caller['server_port'] !== (int) $route['server_port'] ||
				(int) $caller['server_id'] !== (int) $route['server_id'] ||
				! self::writable_read_only( $caller['read_only'] ) || ! self::writable_read_only( $route['read_only'] ) ||
				'1' !== (string) $route['autocommit'] ) {
				throw new \RuntimeException( 'Independent writable-primary routing could not be verified.' );
			}
			$session = new self( $db, $namespace, $route );
			self::configure_snapshot_isolation( $db );
			$session->assert_connection();
			return $session;
		} catch ( \Throwable $error ) {
			$db->close();
			throw $error;
		}
	}

	private static function route( $db ): array {
		$row = $db->get_row( 'SELECT DATABASE() AS database_name, @@hostname AS server_name, @@port AS server_port, @@server_id AS server_id, @@read_only AS read_only, @@autocommit AS autocommit, CONNECTION_ID() AS connection_id', ARRAY_A );
		if ( ! is_array( $row ) || ! empty( $db->last_error ) || ! $row['database_name'] || ! $row['connection_id'] ) {
			throw new \RuntimeException( 'Unable to verify database session routing.' );
		}
		return $row;
	}

	private static function writable_read_only( $value ): bool {
		return in_array( (string) $value, array( '0', 'OFF' ), true );
	}

	private static function configure_snapshot_isolation( $db ): void {
		$variable = $db->get_row( "SHOW SESSION VARIABLES LIKE 'innodb_snapshot_isolation'", ARRAY_A );
		if ( ! empty( $db->last_error ) ) {
			throw new \RuntimeException( 'Unable to inspect foundation snapshot isolation.' );
		}
		if ( null === $variable ) {
			return; // MySQL and older MariaDB do not expose this variable.
		}
		if ( false === $db->query( 'SET SESSION innodb_snapshot_isolation = OFF' ) || ! empty( $db->last_error ) ) {
			throw new \RuntimeException( 'Foundation snapshot isolation could not be set.' );
		}
		$variable = $db->get_row( "SHOW SESSION VARIABLES LIKE 'innodb_snapshot_isolation'", ARRAY_A );
		if ( ! is_array( $variable ) || ! empty( $db->last_error ) ) {
			throw new \RuntimeException( 'Foundation snapshot isolation could not be verified.' );
		}
		$variable = array_change_key_case( $variable, CASE_LOWER );
		if ( ! isset( $variable['value'] ) || ! self::writable_read_only( $variable['value'] ) ) {
			throw new \RuntimeException( 'Foundation snapshot isolation could not be verified.' );
		}
	}

	private static function quote( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	private static function ddl( self $session, string $sql ): void {
		try {
			$session->checked( $sql, false, 'schema-ddl' );
		} catch ( \RuntimeException $error ) {
			throw new \RuntimeException( 'Lock foundation schema change failed.', 0, $error );
		}
	}

	private static function column( $db, string $table, string $column ): ?array {
		$row = $db->get_row( $db->prepare(
			'SELECT data_type AS data_type, column_type AS column_type, is_nullable AS is_nullable, character_maximum_length AS character_maximum_length, extra AS extra FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s',
			$table, $column
		), ARRAY_A );
		if ( ! empty( $db->last_error ) ) {
			throw new \RuntimeException( 'Unable to inspect lock foundation columns.' );
		}
		return is_array( $row ) ? $row : null;
	}

	private static function index( $db, string $table, string $name ): ?array {
		$row = $db->get_row( $db->prepare(
			'SELECT non_unique AS non_unique, column_name AS column_name, sub_part AS sub_part FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s AND seq_in_index = 1',
			$table, $name
		), ARRAY_A );
		if ( ! empty( $db->last_error ) ) {
			throw new \RuntimeException( 'Unable to inspect lock foundation indexes.' );
		}
		if ( is_array( $row ) ) {
			$row['parts'] = $db->get_var( $db->prepare(
				'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
				$table, $name
			) );
			if ( ! empty( $db->last_error ) ) {
				throw new \RuntimeException( 'Unable to inspect lock foundation index parts.' );
			}
		}
		return is_array( $row ) ? $row : null;
	}

	private static function verify_schema( $db, string $namespace ): void {
		foreach ( array( WP_Lock_Backend_DB::TABLE_NAME, self::RESOURCE_TABLE ) as $suffix ) {
			$engine = $db->get_var( $db->prepare(
				'SELECT engine AS engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
				$namespace . $suffix
			) );
			if ( ! empty( $db->last_error ) || 'InnoDB' !== $engine ) {
				throw new \RuntimeException( 'Lock foundation requires verified InnoDB tables.' );
			}
		}
		$owner = $namespace . WP_Lock_Backend_DB::TABLE_NAME;
		$resource = $namespace . self::RESOURCE_TABLE;
		$checks = array(
			array( $owner, 'id', 'int', null, 'NO' ),
			array( $owner, 'lock_key', 'varchar', 50, 'YES' ),
			array( $owner, 'original_key', 'varchar', 50, 'YES' ),
			array( $owner, 'level', 'smallint', null, 'YES' ),
			array( $owner, 'pid', 'int', null, 'YES' ),
			array( $owner, 'cid', 'int', null, 'YES' ),
			array( $owner, 'expire', 'decimal', null, 'YES' ),
			array( $owner, 'attempt_token', 'char', 32, 'YES' ),
			array( $resource, 'lock_key', 'char', 32, 'NO' ),
		);
		foreach ( $checks as $check ) {
			$column = self::column( $db, $check[0], $check[1] );
			if ( ! $column || $check[2] !== $column['data_type'] ||
				( null !== $check[3] && (int) $column['character_maximum_length'] !== $check[3] ) ||
				$check[4] !== $column['is_nullable'] ||
				( 'id' === $check[1] && ( false === strpos( $column['extra'], 'auto_increment' ) || false === strpos( $column['column_type'], 'unsigned' ) ) ) ||
				( in_array( $check[1], array( 'level', 'pid', 'cid', 'expire' ), true ) && false === strpos( $column['column_type'], 'unsigned' ) ) ||
				( 'expire' === $check[1] && false === strpos( $column['column_type'], '(16,6)' ) ) ) {
				throw new \RuntimeException( 'Lock foundation column verification failed: ' . $check[1] );
			}
		}
		foreach ( array(
			array( $owner, 'PRIMARY', 'id', 0 ),
			array( $owner, 'lock_key', 'lock_key', 1 ),
			array( $owner, 'attempt_token', 'attempt_token', 0 ),
			array( $resource, 'PRIMARY', 'lock_key', 0 ),
		) as $check ) {
			$index = self::index( $db, $check[0], $check[1] );
			if ( ! $index || $check[2] !== $index['column_name'] ||
				$check[3] !== (int) $index['non_unique'] || 1 !== (int) $index['parts'] || null !== $index['sub_part'] ) {
				throw new \RuntimeException( 'Lock foundation index verification failed: ' . $check[1] );
			}
		}
		$invalid = $db->get_var( $db->prepare(
			'SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND non_unique = 0 AND index_name NOT IN (%s, %s)',
			$owner, 'PRIMARY', 'attempt_token'
		) );
		if ( ! empty( $db->last_error ) || 0 !== (int) $invalid ) {
			throw new \RuntimeException( 'Lock foundation owner index verification failed.' );
		}
	}

	private function checked( string $sql, bool $rows = false, string $tag = 'unspecified' ) {
		$this->assert_connection();
		$result = $rows ? $this->db->get_results( $sql, ARRAY_A ) : $this->db->query( $sql );
		if ( false === $result || ! empty( $this->db->last_error ) ) {
			$operation = preg_match( '/\A\s*(SELECT|INSERT|DELETE|UPDATE|COMMIT|ROLLBACK|START|SET|CREATE|ALTER)\b/i', $sql, $matches ) ? strtoupper( $matches[1] ) : 'OTHER';
			list( $errno, $sqlstate ) = $this->db->lock_error_codes();
			throw new WP_Lock_Foundation_SQL_Error( sprintf(
				'Lock foundation SQL failed (operation=%s, tag=%s, result=%s, last_error=%s, errno=%s, sqlstate=%s).',
				$operation, $tag, false === $result ? 'false' : 'non-false', empty( $this->db->last_error ) ? 'empty' : 'present',
				null === $errno ? 'unavailable' : (string) $errno,
				null === $sqlstate ? 'unavailable' : $sqlstate
			), null === $errno ? 0 : $errno );
		}
		$this->assert_connection();
		return $result;
	}

	private function assert_connection(): void {
		try {
			$route = self::route( $this->db );
		} catch ( \RuntimeException $error ) {
			throw new WP_Lock_Ownership_Uncertain( 'Lock foundation session changed or is unavailable; outcome uncertain.', 0, $error );
		}
		foreach ( array( 'database_name', 'server_name', 'server_port', 'server_id', 'connection_id' ) as $key ) {
			if ( (string) $this->route[ $key ] !== (string) $route[ $key ] ) {
				throw new WP_Lock_Ownership_Uncertain( 'Lock foundation session changed or is unavailable; outcome uncertain.' );
			}
		}
		if ( ! self::writable_read_only( $route['read_only'] ) ) {
			throw new WP_Lock_Ownership_Uncertain( 'Lock foundation session changed or is unavailable; outcome uncertain.' );
		}
	}

	public function connection_id(): int {
		$this->assert_connection();
		return $this->connection_id;
	}

	public function isolation( string $level ): void {
		if ( ! in_array( $level, array( 'REPEATABLE READ', 'READ COMMITTED' ), true ) ) {
			throw new \InvalidArgumentException( 'Unsupported transaction isolation.' );
		}
		$this->checked( 'SET SESSION TRANSACTION ISOLATION LEVEL ' . $level, false, 'isolation-set' );
		if ( $level !== $this->verified_isolation() ) {
			throw new \RuntimeException( 'Foundation isolation could not be verified.' );
		}
	}

	private function verified_isolation(): string {
		$actual = self::session_isolation( $this->db );
		$this->assert_connection();
		return $actual;
	}

	private static function session_isolation( $db ): string {
		$actual = $db->get_var( "SHOW SESSION VARIABLES LIKE 'transaction_isolation'", 1 );
		if ( ! empty( $db->last_error ) ) {
			throw new \RuntimeException( 'Foundation isolation could not be verified.' );
		}
		if ( null === $actual ) {
			$actual = $db->get_var( "SHOW SESSION VARIABLES LIKE 'tx_isolation'", 1 );
		}
		$actual = str_replace( '-', ' ', strtoupper( (string) $actual ) );
		if ( ! empty( $db->last_error ) || ! in_array( $actual, array( 'REPEATABLE READ', 'READ COMMITTED' ), true ) ) {
			throw new \RuntimeException( 'Foundation isolation could not be verified.' );
		}
		return $actual;
	}

	public function begin(): void {
		if ( $this->in_transaction ) {
			throw new \LogicException( 'Foundation transaction is already active.' );
		}
		$this->verified_isolation();
		$this->checked( 'START TRANSACTION', false, 'transaction-begin' );
		$this->in_transaction = true;
	}

	public function begin_resource( string $id ): void {
		$this->begin();
		$this->lock_resource( $id );
	}

	public function lock_resource( string $id ): void {
		if ( ! $this->in_transaction || $this->resource_locked ) {
			throw new \LogicException( 'Resource lock requires a fresh foundation transaction.' );
		}
		$key = md5( $id );
		$table = self::quote( $this->namespace . self::RESOURCE_TABLE );
		$this->checked( $this->db->prepare( "INSERT IGNORE INTO {$table} (lock_key) VALUES (%s)", $key ), false, 'resource-insert' );
		$rows = $this->checked( $this->db->prepare( "SELECT lock_key FROM {$table} WHERE lock_key = %s FOR UPDATE", $key ), true, 'resource-select' );
		if ( 1 !== count( $rows ) || $key !== $rows[0]['lock_key'] ) {
			throw new \RuntimeException( 'Resource row could not be locked.' );
		}
		$this->resource_locked = true;
		$this->locked_key = $key;
	}

	public function current_owners( string $id ): array {
		$this->require_resource_lock( $id );
		$table = self::quote( $this->namespace . WP_Lock_Backend_DB::TABLE_NAME );
		return $this->checked( $this->db->prepare( "SELECT id, level, expire, attempt_token FROM {$table} WHERE lock_key = %s FOR UPDATE", md5( $id ) ), true, 'owner-select' );
	}

	/** Database time is read after the resource lock, never from a waiting statement. */
	public function now(): float {
		$this->assert_connection();
		$now = $this->db->get_var( 'SELECT UNIX_TIMESTAMP(NOW(6))' );
		if ( null === $now || ! empty( $this->db->last_error ) ) {
			throw new \RuntimeException( 'Unable to read lock database time.' );
		}
		$this->assert_connection();
		return (float) $now;
	}

	public function delete_owner( string $id, int $owner_id, ?string $token ): int {
		$this->require_resource_lock( $id );
		$table = self::quote( $this->namespace . WP_Lock_Backend_DB::TABLE_NAME );
		$token_condition = null === $token ? 'attempt_token IS NULL' : $this->db->prepare( 'attempt_token = %s', $token );
		return $this->checked( $this->db->prepare(
			"DELETE FROM {$table} WHERE id = %d AND lock_key = %s AND {$token_condition}",
			$owner_id, md5( $id )
		), false, 'owner-delete' );
	}

	public function insert_owner( string $id, int $level, string $token, float $expire = 0.0, ?string $original_key = null ): int {
		$this->require_resource_lock( $id );
		if ( ! in_array( $level, array( WP_Lock::READ, WP_Lock::WRITE ), true ) || ! preg_match( '/\A[0-9a-f]{32}\z/D', $token ) ) {
			throw new \InvalidArgumentException( 'Invalid foundation owner or attempt token.' );
		}
		$table = self::quote( $this->namespace . WP_Lock_Backend_DB::TABLE_NAME );
		$original = null === $original_key ? 'NULL' : $this->db->prepare( '%s', $original_key );
		$this->checked( $this->db->prepare(
			"INSERT INTO {$table} (lock_key, original_key, level, pid, cid, attempt_token, expire) VALUES (%s, {$original}, %d, %d, CONNECTION_ID(), %s, %f)",
			md5( $id ), $level, getmypid() ?: 0, $token, $expire
		), false, 'owner-insert' );
		return (int) $this->db->insert_id;
	}

	public function exists( string $id, int $level ): bool {
		$table = self::quote( $this->namespace . WP_Lock_Backend_DB::TABLE_NAME );
		$found = $this->db->get_var( $this->db->prepare(
			"SELECT 1 FROM {$table} WHERE lock_key = %s AND level >= %d AND (expire = 0 OR expire > UNIX_TIMESTAMP(NOW(6))) LIMIT 1",
			md5( $id ), $level
		) );
		if ( ! empty( $this->db->last_error ) ) {
			throw new \RuntimeException( 'Unable to check lock existence because of a database error.' );
		}
		$this->assert_connection();
		return null !== $found;
	}

	public function find_attempt( string $id, string $token ): array {
		$this->require_resource_lock( $id );
		$table = self::quote( $this->namespace . WP_Lock_Backend_DB::TABLE_NAME );
		return $this->checked( $this->db->prepare(
			"SELECT id, lock_key, attempt_token FROM {$table} WHERE lock_key = %s AND attempt_token = %s FOR UPDATE",
			md5( $id ), $token
		), true, 'attempt-select' );
	}

	public static function new_token(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	private function require_resource_lock( string $id ): void {
		if ( ! $this->in_transaction || ! $this->resource_locked || md5( $id ) !== $this->locked_key ) {
			throw new \LogicException( 'A resource row lock is required.' );
		}
	}

	public function commit(): void {
		if ( ! $this->in_transaction ) {
			throw new \LogicException( 'No foundation transaction to commit.' );
		}
		$this->checked( 'COMMIT', false, 'transaction-commit' );
		$this->in_transaction = false;
		$this->resource_locked = false;
		$this->locked_key = null;
	}

	public function rollback(): void {
		if ( ! $this->in_transaction ) {
			throw new \LogicException( 'No foundation transaction to roll back.' );
		}
		$this->checked( 'ROLLBACK', false, 'transaction-rollback' );
		$this->in_transaction = false;
		$this->resource_locked = false;
		$this->locked_key = null;
	}

	public function close(): void {
		$this->db->close();
	}
}
