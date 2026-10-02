<?php
/** E4-03 disposable recovery rehearsal; included within the RR/RC diagnostic loop. */
use iTRON\WP_Lock\WP_Lock;
use iTRON\WP_Lock\WP_Lock_Backend_DB;
use iTRON\WP_Lock\WP_Lock_Foundations;
use iTRON\WP_Lock\WP_Lock_Ownership_Lost;
use iTRON\WP_Lock\WP_Lock_Ownership_Uncertain;

case_ok( 'ttl_zero_visibility_is_not_liveness_' . $isolation, function() {
	global $wpdb;
	$id = 'zero-visible-' . uniqid();
	$owner = new WP_Lock( $id );
	$backend = new WP_Lock_Backend_DB();
	check( $owner->acquire( WP_Lock::WRITE, false, 0 ), 'TTL=0 owner failed.' );
	// PID/CID may be reused, unavailable, or visible only in another namespace.
	sql( $wpdb->prepare( 'UPDATE e3_lock SET pid = %d, cid = %d WHERE lock_key = %s', getmypid(), 0, md5( $id ) ) );
	$filter = fail_query_once( 'SELECT 1 FROM `e3_lock`' );
	try {
		try { $owner->lock_exists(); throw new RuntimeException( 'Failed SELECT looked like absence.' ); }
		catch ( RuntimeException $error ) { check( false === strpos( $error->getMessage(), 'looked like absence' ), 'Failed SELECT looked like absence.' ); }
	} finally { remove_filter( 'query', $filter ); }
	$filter = fail_query_once( 'SELECT * FROM e3_lock WHERE expire > 0' );
	try {
		try { $backend->get_ghosts( $id ); throw new RuntimeException( 'Failed ghost SELECT looked like an empty result.' ); }
		catch ( RuntimeException $error ) { check( false === strpos( $error->getMessage(), 'looked like an empty result' ), 'Failed ghost SELECT looked like an empty result.' ); }
	} finally { remove_filter( 'query', $filter ); }
	check( ! $backend->drop_ghosts( $id ) && array() === $backend->get_ghosts( $id ), 'TTL=0 was treated as a ghost.' );
	check( 1 === count_owners( $id ) && ! ( new WP_Lock( $id ) )->acquire( WP_Lock::WRITE, false, 0 ), 'Unreliable visibility removed a live owner.' );
	$owner->release();
} );

case_ok( 'finite_cleanup_faults_and_successor_' . $isolation, function() {
	global $wpdb;
	$id = 'finite-clean-' . uniqid();
	$old = new WP_Lock( $id );
	$backend = new WP_Lock_Backend_DB();
	check( $old->acquire( WP_Lock::WRITE, false, 30 ), 'Finite owner failed.' );
	sql( $wpdb->prepare( 'UPDATE e3_lock SET expire = UNIX_TIMESTAMP(NOW(6)) - 1 WHERE lock_key = %s', md5( $id ) ) );
	check( 1 === count( $backend->get_ghosts( $id ) ), 'Finite expired row was not visible by database time.' );
	$filter = fail_query_once( 'SELECT id, level, expire, attempt_token FROM `e3_lock`' );
	try { try { $backend->drop_ghosts( $id ); throw new RuntimeException( 'Cleanup SELECT fault returned normally.' ); } catch ( WP_Lock_Ownership_Uncertain $expected ) {} }
	finally { remove_filter( 'query', $filter ); }
	check( 1 === count_owners( $id ), 'SELECT fault removed owner.' );
	$injected = false;
	$filter = zero_delete_once( $id, $injected );
	try { try { $backend->drop_ghosts( $id ); throw new RuntimeException( 'Zero-row cleanup returned normally.' ); } catch ( WP_Lock_Ownership_Uncertain $expected ) {} }
	finally { remove_filter( 'query', $filter ); }
	check( $injected && 1 === count_owners( $id ), 'Zero-row cleanup was not explicit.' );
	$filter = fail_query_once( 'DELETE FROM `e3_lock`' );
	try { try { $backend->drop_ghosts( $id ); throw new RuntimeException( 'Cleanup DELETE fault returned normally.' ); } catch ( WP_Lock_Ownership_Uncertain $expected ) {} }
	finally { remove_filter( 'query', $filter ); }
	check( 1 === count_owners( $id ), 'DELETE fault removed owner.' );
	$injected = false;
	$filter = kill_controlled_once( 'COMMIT', $injected );
	try { try { $backend->drop_ghosts( $id ); throw new RuntimeException( 'Cleanup COMMIT fault returned normally.' ); } catch ( WP_Lock_Ownership_Uncertain $expected ) {} }
	finally { remove_filter( 'query', $filter ); }
	check( $injected && 1 === count_owners( $id ), 'COMMIT uncertainty was treated as confirmed deletion.' );
	check( $backend->drop_ghosts( $id ) && 0 === count_owners( $id ), 'Finite cleanup did not complete.' );
	$new = new WP_Lock( $id );
	check( $new->acquire( WP_Lock::WRITE, false, 0 ), 'Successor failed after cleanup.' );
	try { $old->release(); throw new RuntimeException( 'Predecessor release succeeded.' ); } catch ( WP_Lock_Ownership_Lost $expected ) {}
	check( 1 === count_owners( $id ) && $new->lock_exists(), 'Predecessor deleted successor.' );
	$new->release();
} );

/** Fixture-only exact recovery while the namespace admissions barrier remains engaged. */
if ( ! function_exists( 'rehearse_recovery' ) ) {
function recovery_postcondition( string $id, string $isolation, bool &$barrier ): void {
	check( $barrier, 'Recovery started without an admissions barrier.' );
	$fresh = connect( $isolation );
	$remaining = $fresh->get_results( $fresh->prepare( 'SELECT id, attempt_token FROM e3_lock WHERE lock_key = %s', md5( $id ) ), ARRAY_A );
	check( empty( $fresh->last_error ) && array() === $remaining, 'Fresh-primary recovery postcondition was not verified.' );
	$barrier = false;
}
function recovery_record( string $id, int $owner_id, ?string $token, string $outcome, string $postcondition, bool $barrier ): void {
	echo json_encode( array( 'event' => 'manual_recovery', 'namespace' => 'e3_', 'database' => 'wp_lock_e1', 'resource' => $id,
		'owner_id' => $owner_id, 'attempt_token' => $token, 'reason' => 'terminated disposable TTL=0 fixture owner',
		'termination' => 'fixture participants positively stopped', 'operator' => 'diagnostic fixture', 'time_utc' => gmdate( 'c' ),
		'sql_outcome' => $outcome, 'postcondition' => $postcondition, 'barrier_engaged' => $barrier ) ) . "\n";
}
function rehearse_recovery( string $id, int $owner_id, ?string $token, string $isolation, bool &$barrier ): void {
	check( $barrier, 'Recovery started without an admissions barrier.' );
	$session = WP_Lock_Foundations::open( 'e3_' );
	try {
		$session->begin_resource( $id );
		$rows = $session->current_owners( $id );
		check( 1 === count( $rows ) && $owner_id === (int) $rows[0]['id'] && $token === $rows[0]['attempt_token'] && 0.0 === (float) $rows[0]['expire'], 'Exact stale owner was not verified under resource lock.' );
		check( 1 === $session->delete_owner( $id, $owner_id, $token ), 'Exact owner DELETE did not affect one row.' );
		$session->commit();
	} finally {
		$session->close();
	}
	recovery_postcondition( $id, $isolation, $barrier );
}
}

case_ok( 'ttl_zero_manual_recovery_barrier_' . $isolation, function() use ( $isolation ) {
	global $wpdb;
	$id = 'manual-' . uniqid();
	$barrier = false;
	$pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
	check( false !== $pair, 'Recovery child socket unavailable.' );
	$pid = pcntl_fork();
	check( -1 !== $pid, 'Recovery child fork failed.' );
	if ( 0 === $pid ) {
		fclose( $pair[0] );
		try {
			$wpdb = connect( $isolation );
			$lock = new WP_Lock( $id );
			check( $lock->acquire( WP_Lock::WRITE, false, 0 ), 'Recovery child could not acquire.' );
			fwrite( $pair[1], "acquired\n" );
			check( "stop\n" === fgets( $pair[1] ), 'Recovery child did not receive stop instruction.' );
			// Exec ends the PHP owner without invoking the wrapper shutdown hook.
			pcntl_exec( '/bin/sh', array( '-c', 'exit 0' ) );
		} catch ( Throwable $error ) { e3_report_error( $error, 'Recovery child failed.' ); }
		exit( 1 );
	}
	fclose( $pair[1] );
	stream_set_timeout( $pair[0], 6 );
	$acquired = fgets( $pair[0] );
	check( "acquired\n" === $acquired, 'Owner did not acquire before barrier.' );
	$barrier = true;
	check( 5 === fwrite( $pair[0], "stop\n" ), 'Owner stop instruction failed.' );
	fclose( $pair[0] );
	pcntl_waitpid( $pid, $status );
	check( pcntl_wifexited( $status ) && 0 === pcntl_wexitstatus( $status ), 'Owner termination was not positively confirmed.' );
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, attempt_token, expire FROM e3_lock WHERE lock_key = %s', md5( $id ) ), ARRAY_A );
	check( empty( $wpdb->last_error ) && 1 === count( $rows ) && 0.0 === (float) $rows[0]['expire'] && preg_match( '/\A[0-9a-f]{32}\z/D', $rows[0]['attempt_token'] ), 'Captured TTL=0 identity is not complete.' );
	$owner_id = (int) $rows[0]['id'];
	$token = $rows[0]['attempt_token'];
	$admit = function( string $resource ) use ( &$barrier ) {
		check( ! $barrier, 'Fixture admissions barrier remains engaged.' );
		$lock = new WP_Lock( $resource );
		check( $lock->acquire( WP_Lock::WRITE, false, 0 ), 'Verified recovery did not permit acquisition.' );
		$lock->release();
	};
	foreach ( array( 'SELECT id, level, expire, attempt_token FROM `e3_lock`', 'DELETE FROM `e3_lock`', 'COMMIT' ) as $fault ) {
		$injected = false;
		$filter = 'COMMIT' === $fault ? kill_controlled_once( 'COMMIT', $injected ) : fail_query_once( $fault );
		try {
			try { rehearse_recovery( $id, $owner_id, $token, $isolation, $barrier ); throw new RuntimeException( 'Faulted recovery returned normally.' ); }
			catch ( RuntimeException $expected ) { check( false === strpos( $expected->getMessage(), 'returned normally' ), 'Faulted recovery returned normally.' ); }
			check( false === has_filter( 'query', $filter ) && ( 'COMMIT' !== $fault || $injected ), 'Recovery fault was not injected.' );
		}
		finally { remove_filter( 'query', $filter ); }
		check( $barrier && 1 === count_owners( $id ), 'Recovery fault dropped the barrier or owner.' );
		recovery_record( $id, $owner_id, $token, $fault . ' failed', 'owner retained', $barrier );
		try { $admit( $id ); throw new RuntimeException( 'Fixture admitted work under barrier.' ); }
		catch ( RuntimeException $expected ) { check( false === strpos( $expected->getMessage(), 'admitted work' ), 'Fixture admitted work under barrier.' ); }
	}
	$filter = fail_query_once( 'SELECT id, attempt_token FROM e3_lock' );
	try {
		try { rehearse_recovery( $id, $owner_id, $token, $isolation, $barrier ); throw new RuntimeException( 'Failed postcondition returned normally.' ); }
		catch ( RuntimeException $expected ) { check( false === strpos( $expected->getMessage(), 'returned normally' ), 'Failed postcondition returned normally.' ); }
		check( false === has_filter( 'query', $filter ), 'Fresh-primary read fault was not injected.' );
	}
	finally { remove_filter( 'query', $filter ); }
	check( $barrier && 0 === count_owners( $id ), 'Failed postcondition did not retain barrier after committed deletion.' );
	recovery_record( $id, $owner_id, $token, 'COMMIT confirmed', 'fresh read failed; barrier retained', $barrier );
	try { $admit( $id ); throw new RuntimeException( 'Fixture admitted work after failed postcondition.' ); }
	catch ( RuntimeException $expected ) { check( false === strpos( $expected->getMessage(), 'admitted work' ), 'Fixture admitted work after failed postcondition.' ); }
	recovery_postcondition( $id, $isolation, $barrier );
	recovery_record( $id, $owner_id, $token, 'no second DELETE', 'fresh primary absence verified', $barrier );
	check( ! $barrier, 'Verified recovery did not lift the barrier.' );
	$admit( $id );
	check( 0 === count_owners( $id ), 'Post-recovery successor remained.' );
	$legacy = 'manual-legacy-' . uniqid();
	$barrier = true;
	$session = WP_Lock_Foundations::open( 'e3_' );
	try { $session->begin_resource( $legacy ); $session->commit(); } finally { $session->close(); }
	sql( $wpdb->prepare( 'INSERT INTO e3_lock (lock_key, level, expire, attempt_token) VALUES (%s, %d, 0, NULL)', md5( $legacy ), WP_Lock::READ ) );
	$legacy_id = (int) $wpdb->insert_id;
	check( $legacy_id > 0, 'Legacy fixture identity missing.' );
	sql( $wpdb->prepare( 'INSERT INTO e3_lock (lock_key, level, expire, attempt_token) VALUES (%s, %d, 0, NULL)', md5( $legacy ), WP_Lock::READ ) );
	$peer_id = (int) $wpdb->insert_id;
	check( $peer_id > $legacy_id, 'Shared legacy peer identity missing.' );
	try { $admit( $legacy ); throw new RuntimeException( 'Legacy fixture admitted work under barrier.' ); }
	catch ( RuntimeException $expected ) { check( false === strpos( $expected->getMessage(), 'admitted work' ), 'Legacy fixture admitted work under barrier.' ); }
	try { rehearse_recovery( $legacy, $legacy_id, null, $isolation, $barrier ); throw new RuntimeException( 'Shared owner was ignored.' ); }
	catch ( RuntimeException $expected ) { check( false === strpos( $expected->getMessage(), 'was ignored' ), 'Shared owner was ignored.' ); }
	check( $barrier && 2 === count_owners( $legacy ), 'Unresolved shared owner was removed.' );
	recovery_record( $legacy, $legacy_id, null, 'no DELETE: shared peer unresolved', 'two owners retained; barrier retained', $barrier );
	$session = WP_Lock_Foundations::open( 'e3_' );
	try {
		$session->begin_resource( $legacy );
		$rows = $session->current_owners( $legacy );
		check( 2 === count( $rows ), 'Shared legacy owner count changed.' );
		$ids = array( (int) $rows[0]['id'], (int) $rows[1]['id'] );
		sort( $ids );
		check( array( $legacy_id, $peer_id ) === $ids &&
			null === $rows[0]['attempt_token'] && null === $rows[1]['attempt_token'], 'Shared legacy identities changed.' );
		check( 1 === $session->delete_owner( $legacy, $peer_id, null ), 'Exact shared peer DELETE failed.' );
		$session->commit();
	} finally { $session->close(); }
	$fresh = connect( $isolation );
	$remaining = $fresh->get_results( $fresh->prepare( 'SELECT id, attempt_token FROM e3_lock WHERE lock_key = %s', md5( $legacy ) ), ARRAY_A );
	check( empty( $fresh->last_error ) && 1 === count( $remaining ) && $legacy_id === (int) $remaining[0]['id'] &&
		null === $remaining[0]['attempt_token'] && $barrier, 'Shared peer postcondition was not accounted for.' );
	recovery_record( $legacy, $peer_id, null, 'one exact IS NULL DELETE and COMMIT', 'one identified legacy owner remains; barrier retained', $barrier );
	rehearse_recovery( $legacy, $legacy_id, null, $isolation, $barrier );
	recovery_record( $legacy, $legacy_id, null, 'one exact IS NULL DELETE and COMMIT', 'fresh primary absence verified', $barrier );
	$admit( $legacy );
} );
