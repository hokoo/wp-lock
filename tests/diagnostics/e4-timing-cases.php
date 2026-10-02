<?php
/** E4 timing cases, run by e4-timing.php after guarded E3 bootstrap. */
use iTRON\WP_Lock\WP_Lock;
use iTRON\WP_Lock\WP_Lock_Backend_DB;
use iTRON\WP_Lock\WP_Lock_Ownership_Lost;
use iTRON\WP_Lock\WP_Lock_Ownership_Uncertain;

		case_ok( 'slow_commit_expired_lease_' . $isolation, function() {
			$id = 'slow-lease-' . uniqid();
			$lock = new WP_Lock( $id );
			$delayed = false;
			$filter = function( $query ) use ( &$delayed ) {
				if ( 'COMMIT' === $query && ! $delayed ) {
					$delayed = true;
					usleep( 1200000 );
				}
				return $query;
			};
			add_filter( 'query', $filter );
			try { check( ! $lock->acquire( WP_Lock::WRITE, false, 1 ), 'Expired commit returned a grant.' ); }
			finally { remove_filter( 'query', $filter ); }
			check( $delayed && 0 === count_owners( $id ), 'Expired attempt was not cleaned.' );
		} );
		case_ok( 'statement_wait_lease_start_' . $isolation, function() {
			global $wpdb;
			$id = 'statement-wait-' . uniqid();
			$lock = new WP_Lock( $id );
			$delayed = false;
			$filter = function( $query ) use ( &$delayed ) {
				if ( ! $delayed && false !== strpos( $query, 'SELECT lock_key FROM `e3_lock_resource`' ) ) {
					$delayed = true;
					return str_replace( ' FOR UPDATE', ' AND SLEEP(1) = 0 FOR UPDATE', $query );
				}
				return $query;
			};
			add_filter( 'query', $filter );
			try { check( $lock->acquire( WP_Lock::WRITE, false, 3 ), 'Statement-wait lease failed.' ); }
			finally { remove_filter( 'query', $filter ); }
			$expiry = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT expire FROM e3_lock WHERE lock_key = %s', md5( $id ) ) );
			$now = (float) $wpdb->get_var( 'SELECT UNIX_TIMESTAMP(NOW(6))' );
			check( $delayed && empty( $wpdb->last_error ) && $expiry - $now > 2.5, 'Statement delay was counted against lease.' );
			$lock->release();
		} );
		case_ok( 'database_clock_authority_' . $isolation, function() {
			global $wpdb;
			$id = 'clock-' . uniqid();
			$lock = new WP_Lock( $id );
			$reads = 0;
			$filter = function( $query ) use ( &$reads ) {
				if ( 'SELECT UNIX_TIMESTAMP(NOW(6))' === $query ) {
					++$reads;
					return $query . ' + 3600';
				}
				return $query;
			};
			add_filter( 'query', $filter );
			try { check( $lock->acquire( WP_Lock::WRITE, false, 30 ), 'Primary-clock lease failed.' ); }
			finally { remove_filter( 'query', $filter ); }
			$expiry = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT expire FROM e3_lock WHERE lock_key = %s', md5( $id ) ) );
			$now = (float) $wpdb->get_var( 'SELECT UNIX_TIMESTAMP(NOW(6))' );
			check( 3 === $reads && empty( $wpdb->last_error ) && $expiry - $now > 3600, 'Lease did not follow database time.' );
			$lock->release();
		} );
		case_ok( 'expired_present_owner_release_' . $isolation, function() {
			global $wpdb;
			$id = 'expired-present-' . uniqid();
			$lock = new WP_Lock( $id );
			check( $lock->acquire( WP_Lock::WRITE, false, 30 ), 'Finite owner acquire failed.' );
			$owner_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM e3_lock WHERE lock_key = %s', md5( $id ) ) );
			check( $owner_id > 0 && empty( $wpdb->last_error ), 'Finite owner row was not found.' );
			sql( $wpdb->prepare( 'UPDATE e3_lock SET expire = UNIX_TIMESTAMP(NOW(6)) - 1 WHERE id = %d', $owner_id ) );
			check( 1 === $wpdb->rows_affected, 'Finite owner expiry was not set.' );
			try { $lock->release(); throw new RuntimeException( 'Expired owner release succeeded.' ); }
			catch ( WP_Lock_Ownership_Lost $expected ) {}
			$remaining = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM e3_lock WHERE id = %d AND expire > 0 AND expire <= UNIX_TIMESTAMP(NOW(6))', $owner_id ) );
			check( 1 === $remaining && 1 === count_owners( $id ), 'Expired release changed its owner row.' );
			check( $lock->acquire( WP_Lock::WRITE, false, 30 ), 'Wrapper did not clear lost ownership.' );
			$new_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM e3_lock WHERE lock_key = %s', md5( $id ) ) );
			check( $new_id !== $owner_id && 1 === count_owners( $id ), 'Reacquisition did not replace expired owner.' );
			$lock->release();
			check( 0 === count_owners( $id ), 'Reacquired owner was not released.' );
		} );
		case_ok( 'expired_predecessor_safe_release_' . $isolation, function() {
			global $wpdb;
			$id = 'expired-' . uniqid();
			$old = new WP_Lock( $id );
			$new = new WP_Lock( $id );
			check( $old->acquire( WP_Lock::WRITE, false, 30 ), 'Predecessor acquire failed.' );
			sql( $wpdb->prepare( 'UPDATE e3_lock SET expire = UNIX_TIMESTAMP(NOW(6)) - 1 WHERE lock_key = %s', md5( $id ) ) );
			check( $new->acquire( WP_Lock::WRITE, false, 30 ), 'Expired-owner takeover failed.' );
			try { $old->release(); throw new RuntimeException( 'Expired release succeeded.' ); }
			catch ( WP_Lock_Ownership_Lost $expected ) {}
			check( 1 === count_owners( $id ) && $new->lock_exists(), 'Expired release removed successor.' );
			$new->release();
		} );
		case_ok( 'slow_sql_exhausts_wait_before_insert_' . $isolation, function() {
			$id = 'slow-wait-' . uniqid();
			$lock = new WP_Lock( $id, new WP_Lock_Backend_DB( 0.02, 3 ) );
			$delayed = false;
			$inserts = 0;
			$filter = function( $query ) use ( &$delayed, &$inserts ) {
				if ( ! $delayed && 'SELECT UNIX_TIMESTAMP(NOW(6))' === $query ) {
					$delayed = true;
					usleep( 70000 );
				}
				if ( false !== strpos( $query, 'INSERT INTO `e3_lock`' ) ) { ++$inserts; }
				return $query;
			};
			add_filter( 'query', $filter );
			try { check( ! $lock->acquire( WP_Lock::WRITE, true, 0 ), 'Slow SQL exceeded the wait budget and granted.' ); }
			finally { remove_filter( 'query', $filter ); }
			check( $delayed && 0 === $inserts && 0 === count_owners( $id ), 'Expired wait performed an owner insert.' );
		} );
		case_ok( 'slow_resource_upsert_stops_next_sql_' . $isolation, function() {
			$id = 'slow-resource-' . uniqid();
			$delayed = false;
			$resource_reads = 0;
			$filter = function( $query ) use ( &$delayed, &$resource_reads ) {
				if ( false !== strpos( $query, 'INSERT INTO `e3_lock_resource`' ) && ! $delayed ) {
					$delayed = true;
					usleep( 70000 );
				}
				if ( false !== strpos( $query, 'SELECT lock_key FROM `e3_lock_resource`' ) ) { ++$resource_reads; }
				return $query;
			};
			add_filter( 'query', $filter );
			try { check( ! ( new WP_Lock( $id, new WP_Lock_Backend_DB( 0.02, 0 ) ) )->acquire( WP_Lock::WRITE, true, 0 ), 'Slow resource upsert returned a grant.' ); }
			finally { remove_filter( 'query', $filter ); }
			check( $delayed && 0 === $resource_reads && 0 === count_owners( $id ), 'Expired resource upsert reached its locking read.' );
		} );
		case_ok( 'slow_diagnostic_lookup_stops_owner_insert_' . $isolation, function() {
			$id = 'slow-diagnostic-' . chr( 195 ) . chr( 169 ) . '-' . uniqid();
			$looked_up = false;
			$inserts = 0;
			$lookup = function( $charset ) use ( &$looked_up ) {
				$looked_up = true;
				usleep( 70000 );
				return 'utf8mb4';
			};
			$filter = function( $query ) use ( &$inserts ) {
				if ( false !== strpos( $query, 'INSERT INTO `e3_lock`' ) ) { ++$inserts; }
				return $query;
			};
			add_filter( 'pre_get_col_charset', $lookup );
			add_filter( 'query', $filter );
			try { check( ! ( new WP_Lock( $id, new WP_Lock_Backend_DB( 0.02, 0 ) ) )->acquire( WP_Lock::WRITE, true, 0 ), 'Slow diagnostic lookup returned a grant.' ); }
			finally { remove_filter( 'pre_get_col_charset', $lookup ); remove_filter( 'query', $filter ); }
			check( $looked_up && 0 === $inserts && 0 === count_owners( $id ), 'Expired diagnostic lookup reached owner INSERT.' );
		} );
		case_ok( 'failed_sql_exhausts_wait_as_error_' . $isolation, function() {
			$id = 'slow-error-' . uniqid();
			$failed = false;
			$filter = function( $query ) use ( &$failed ) {
				if ( ! $failed && false !== strpos( $query, 'SELECT id, level, expire, attempt_token FROM `e3_lock`' ) ) {
					$failed = true;
					usleep( 70000 );
					return 'SELECT * FROM e3_missing_injected_table';
				}
				return $query;
			};
			add_filter( 'query', $filter );
			try {
				try { ( new WP_Lock( $id, new WP_Lock_Backend_DB( 0.02, 3 ) ) )->acquire( WP_Lock::WRITE, true, 0 ); throw new RuntimeException( 'Expired SQL error returned a result.' ); }
				catch ( RuntimeException $error ) { check( false !== strpos( $error->getMessage(), 'database error' ), 'Expired SQL error became ordinary contention.' ); }
			} finally { remove_filter( 'query', $filter ); }
			check( $failed && 0 === count_owners( $id ), 'Expired SQL error did not roll back.' );
		} );
		case_ok( 'zero_and_nonblocking_single_attempt_' . $isolation, function() {
			$id = 'first-attempt-' . uniqid();
			$first = new WP_Lock( $id, new WP_Lock_Backend_DB( 0, 3 ) );
			check( $first->acquire( WP_Lock::WRITE, true, 0 ), 'Zero budget rejected its first uncontended attempt.' );
			$reads = 0;
			$filter = function( $query ) use ( &$reads ) {
				if ( false !== strpos( $query, 'SELECT id, level, expire, attempt_token FROM `e3_lock`' ) ) { ++$reads; }
				return $query;
			};
			add_filter( 'query', $filter );
			try {
				check( ! ( new WP_Lock( $id, new WP_Lock_Backend_DB( 0, 3 ) ) )->acquire( WP_Lock::WRITE, true, 0 ), 'Zero budget ignored contention.' );
				check( ! ( new WP_Lock( $id, new WP_Lock_Backend_DB( 1, 3 ) ) )->acquire( WP_Lock::WRITE, false, 0 ), 'Nonblocking attempt ignored contention.' );
			} finally { remove_filter( 'query', $filter ); $first->release(); }
			check( 2 === $reads && 0 === count_owners( $id ), 'Zero/nonblocking call polled after its first attempt.' );
		} );
		case_ok( 'alternating_errors_and_contention_share_budget_' . $isolation, function() {
			$id = 'mixed-wait-' . uniqid();
			$owner = new WP_Lock( $id );
			check( $owner->acquire( WP_Lock::WRITE, false, 0 ), 'Mixed wait owner failed.' );
			$reads = 0;
			$filter = function( $query ) use ( &$reads ) {
				if ( false !== strpos( $query, 'SELECT id, level, expire, attempt_token FROM `e3_lock`' ) ) {
					++$reads;
					usleep( 35000 );
					if ( $reads % 2 ) { return 'SELECT * FROM e3_missing_injected_table'; }
				}
				return $query;
			};
			add_filter( 'query', $filter );
			$start = hrtime( true );
			try {
				$acquired = false;
				try { $acquired = ( new WP_Lock( $id, new WP_Lock_Backend_DB( 0.2, 20 ) ) )->acquire( WP_Lock::WRITE, true, 0 ); }
				catch ( RuntimeException $expected ) {}
				check( ! $acquired, 'Mixed retry granted under contention.' );
			} finally { remove_filter( 'query', $filter ); }
			$elapsed = ( hrtime( true ) - $start ) / 1000000000;
			check( $reads >= 2 && $reads <= 7 && $elapsed < 0.6 && 1 === count_owners( $id ), 'Mixed retries did not share the finite budget.' );
			$owner->release();
		} );
		case_ok( 'proven_conflict_supersedes_earlier_sql_error_' . $isolation, function() {
			$id = 'error-then-conflict-' . uniqid();
			$owner = new WP_Lock( $id );
			check( $owner->acquire( WP_Lock::WRITE, false, 0 ), 'Conflict owner failed.' );
			$reads = 0;
			$filter = function( $query ) use ( &$reads ) {
				if ( false !== strpos( $query, 'SELECT id, level, expire, attempt_token FROM `e3_lock`' ) ) {
					++$reads;
					if ( 1 === $reads ) { return 'SELECT * FROM e3_missing_injected_table'; }
				}
				return $query;
			};
			add_filter( 'query', $filter );
			try { check( ! ( new WP_Lock( $id, new WP_Lock_Backend_DB( 0.4, 3 ) ) )->acquire( WP_Lock::WRITE, true, 0 ), 'Proven conflict returned a grant.' ); }
			finally { remove_filter( 'query', $filter ); $owner->release(); }
			check( $reads >= 2 && 0 === count_owners( $id ), 'Earlier SQL error concealed proven contention.' );
		} );
		case_ok( 'late_commit_exact_cleanup_' . $isolation, function() {
			$id = 'late-clean-' . uniqid();
			$lock = new WP_Lock( $id, new WP_Lock_Backend_DB( 0.02, 0 ) );
			$commits = 0;
			$filter = function( $query ) use ( &$commits ) {
				if ( 'COMMIT' === $query && 1 === ++$commits ) { usleep( 70000 ); }
				return $query;
			};
			add_filter( 'query', $filter );
			try { check( ! $lock->acquire( WP_Lock::WRITE, true, 0 ), 'Late commit returned ownership.' ); }
			finally { remove_filter( 'query', $filter ); }
			check( $commits >= 2 && 0 === count_owners( $id ), 'Late owner cleanup was not confirmed.' );
		} );
		case_ok( 'late_commit_failed_cleanup_retains_identity_' . $isolation, function() {
			$id = 'late-uncertain-' . uniqid();
			$backend = new WP_Lock_Backend_DB( 0.02, 0 );
			$lock = new WP_Lock( $id, $backend );
			$commits = 0;
			$delayed = function( $query ) use ( &$commits ) {
				if ( 'COMMIT' === $query && 1 === ++$commits ) { usleep( 70000 ); }
				return $query;
			};
			$injected = false;
			$failed_delete = zero_delete_once( $id, $injected );
			add_filter( 'query', $delayed );
			try {
				try { $lock->acquire( WP_Lock::WRITE, true, 0 ); throw new RuntimeException( 'Failed late cleanup returned a result.' ); }
				catch ( WP_Lock_Ownership_Uncertain $expected ) {}
			} finally { remove_filter( 'query', $delayed ); remove_filter( 'query', $failed_delete ); }
			check( $commits === 1 && $injected && $backend->has_unresolved( $id ) && 1 === count_owners( $id ), 'Failed late cleanup lost the owner handle.' );
			$lock->release();
			check( ! $backend->has_unresolved( $id ) && 0 === count_owners( $id ), 'Late owner could not be reconciled.' );
		} );
		case_ok( 'timed_uncertain_commit_retains_identity_' . $isolation, function() {
			$id = 'timed-commit-' . uniqid();
			$backend = new WP_Lock_Backend_DB( 0.2, 0 );
			$lock = new WP_Lock( $id, $backend );
			$injected = false;
			$filter = kill_controlled_once( 'COMMIT', $injected );
			try {
				try { $lock->acquire( WP_Lock::WRITE, true, 0 ); throw new RuntimeException( 'Uncertain timed commit returned a result.' ); }
				catch ( WP_Lock_Ownership_Uncertain $expected ) {}
			} finally { remove_filter( 'query', $filter ); }
			check( $injected && $backend->has_unresolved( $id ), 'Timed uncertain commit lost attempt identity.' );
			$lock->release();
			check( ! $backend->has_unresolved( $id ) && 0 === count_owners( $id ), 'Timed commit reconciliation failed.' );
		} );
