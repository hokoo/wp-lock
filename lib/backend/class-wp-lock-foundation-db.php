<?php

namespace iTRON\WP_Lock;

/** A lost connection must never make wpdb replay a write outside its transaction. */
class WP_Lock_Foundation_DB extends \wpdb {
	public function check_connection( $allow_bail = true ) {
		return false;
	}
}
