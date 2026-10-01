<?php

namespace iTRON\WP_Lock;

/** A lost connection must never make wpdb replay a write outside its transaction. */
class WP_Lock_Foundation_DB extends \wpdb {
	public function check_connection( $allow_bail = true ) {
		return false;
	}

	/** Return driver codes without exposing SQL text or connection details. */
	public function lock_error_codes(): array {
		if ( ! $this->dbh instanceof \mysqli ) {
			return array( null, null );
		}
		try {
			return array( mysqli_errno( $this->dbh ), mysqli_sqlstate( $this->dbh ) );
		} catch ( \Throwable $error ) {
			return array( null, null );
		}
	}
}
