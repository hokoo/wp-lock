<?php
/** E4 timing cases, run by e4-timing.php after guarded E3 bootstrap. */
use iTRON\WP_Lock\WP_Lock;
use iTRON\WP_Lock\WP_Lock_Ownership_Lost;

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
