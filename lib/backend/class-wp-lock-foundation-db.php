<?php

namespace iTRON\WP_Lock;

/** A lost connection must never make wpdb replay a write outside its transaction. */
class WP_Lock_Foundation_DB extends \wpdb {
	private $wait_guard;
	private bool $finish_wait_on_query = false;

	public function __construct( $dbuser, $dbpassword, $dbname, $dbhost, ?callable $wait_guard = null ) {
		$this->wait_guard = $wait_guard;
		parent::__construct( $dbuser, $dbpassword, $dbname, $dbhost );
	}

	/** Check a positive acquisition budget before each controlled SQL statement. */
	public function query( $query ) {
		if ( null !== $this->wait_guard && 'ROLLBACK' !== trim( $query ) ) {
			call_user_func( $this->wait_guard );
		}
		if ( $this->finish_wait_on_query ) {
			$this->wait_guard           = null;
			$this->finish_wait_on_query = false;
		}
		return parent::query( $query );
	}

	public function finish_wait_on_next_query(): void {
		$this->finish_wait_on_query = true;
	}

	public function finish_wait(): void {
		$this->wait_guard           = null;
		$this->finish_wait_on_query = false;
	}

	public function check_connection( $allow_bail = true ) {
		return false;
	}

	/** Return driver codes without exposing SQL text or connection details. */
	public function lock_error_codes(): array {
		if ( ! $this->dbh instanceof \mysqli ) {
			return array( null, null );
		}
		try {
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_errno,WordPress.DB.RestrictedFunctions.mysql_mysqli_sqlstate -- Error codes belong to this controlled connection, not the caller's global wpdb.
			return array( mysqli_errno( $this->dbh ), mysqli_sqlstate( $this->dbh ) );
		} catch ( \Throwable $error ) {
			return array( null, null );
		}
	}
}
