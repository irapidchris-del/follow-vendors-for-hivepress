<?php
/**
 * Runtime check for Follow Vendors for HivePress, run against a real HivePress install with WP-CLI.
 *
 * Three phases, chosen with the HPFV_PHASE environment variable, because the account page can
 * only be rendered for a user that was signed in BEFORE WordPress loaded (--user=), and the
 * fixtures have to exist before that user can be named:
 *
 *   HPFV_PHASE=seed  wp eval-file tests/runtime-check.php                      creates the fixtures, prints the follower's ID
 *   HPFV_PHASE=check wp eval-file tests/runtime-check.php --user=<follower id> runs every assertion
 *   HPFV_PHASE=card  wp eval-file tests/runtime-check.php --user=<follower id> renders one Vendor card (run per theme)
 *   HPFV_PHASE=clean wp eval-file tests/runtime-check.php                      removes everything it made
 *
 * No email is ever sent: pre_wp_mail short-circuits wp_mail() and records what would have gone.
 *
 * The counter lives inside hpfv_check() as a static. wp eval-file includes this file inside a
 * function, so a file-scope counter read through `global` binds to a different variable and the
 * summary would always say everything passed (resources/testing-playbook.md, "A wp eval-file probe
 * cannot count its own failures with a global").
 *
 * @package Follow_Vendors\Tests
 */

use HivePress\Models;
use HivePress\Blocks;

function hpfv_check( $label, $condition = true, $detail = '' ) {
	static $fails = 0;

	if ( is_null( $label ) ) {
		return $fails;
	}

	if ( $condition ) {
		echo "  PASS  {$label}\n";
	} else {
		$fails++;
		echo "  FAIL  {$label}" . ( '' !== (string) $detail ? "  [{$detail}]" : '' ) . "\n";
	}
}

function hpfv_fixture() {
	$fixture = get_option( 'hpfv_test_fixture' );

	return is_array( $fixture ) ? $fixture : [];
}

function hpfv_pending_digests( $vendor_id ) {
	$actions = as_get_scheduled_actions(
		[
			'hook'     => 'hpfv_send_follow_emails',
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'group'    => 'hivepress',
			'per_page' => 50,
		]
	);

	$found = [];

	foreach ( $actions as $id => $action ) {
		$args = $action->get_args();

		if ( isset( $args[0] ) && (int) $args[0] === (int) $vendor_id ) {
			$found[ $id ] = $args;
		}
	}

	return $found;
}

$phase = getenv( 'HPFV_PHASE' ) ? getenv( 'HPFV_PHASE' ) : 'check';

/*
 * --------------------------------------------------------------------------------------------------
 * seed
 * --------------------------------------------------------------------------------------------------
 */
if ( 'seed' === $phase ) {
	$owner = wp_insert_user(
		[
			'user_login'   => 'hpfv_test_owner',
			'user_email'   => 'hpfv_test_owner@example.com',
			'user_pass'    => wp_generate_password( 24 ),
			'display_name' => 'HPFV Owner',
			'role'         => 'contributor',
		]
	);

	$follower = wp_insert_user(
		[
			'user_login'   => 'hpfv_test_follower',
			'user_email'   => 'hpfv_test_follower@example.com',
			'user_pass'    => wp_generate_password( 24 ),
			'display_name' => 'HPFV Follower',
			'role'         => 'subscriber',
		]
	);

	$follower2 = wp_insert_user(
		[
			'user_login'   => 'hpfv_test_follower2',
			'user_email'   => 'hpfv_test_follower2@example.com',
			'user_pass'    => wp_generate_password( 24 ),
			'display_name' => 'HPFV Follower Two',
			'role'         => 'subscriber',
		]
	);

	if ( is_wp_error( $owner ) || is_wp_error( $follower ) || is_wp_error( $follower2 ) ) {
		echo "seed failed: users\n";
		exit( 1 );
	}

	$vendor_id = wp_insert_post(
		[
			'post_type'   => 'hp_vendor',
			'post_status' => 'publish',
			'post_title'  => 'HPFV Test Vendor',
			'post_name'   => 'hpfv-test-vendor',
			'post_author' => $owner,
		]
	);

	$listings = [];

	foreach ( [ 'HPFV Listing One', 'HPFV Listing <Two>', 'HPFV Listing Three', 'HPFV Listing Four', 'HPFV Listing Five' ] as $title ) {
		$listings[] = wp_insert_post(
			[
				'post_type'   => 'hp_listing',
				'post_status' => 'draft',
				'post_title'  => $title,
				'post_author' => $owner,
				'post_parent' => $vendor_id,
			]
		);
	}

	update_option(
		'hpfv_test_fixture',
		[
			'owner'     => (int) $owner,
			'follower'  => (int) $follower,
			'follower2' => (int) $follower2,
			'vendor'    => (int) $vendor_id,
			'listings'  => array_map( 'intval', $listings ),
		],
		false
	);

	echo "FOLLOWER_ID={$follower}\nVENDOR_ID={$vendor_id}\nOWNER_ID={$owner}\n";
	exit( 0 );
}

/*
 * --------------------------------------------------------------------------------------------------
 * clean
 * --------------------------------------------------------------------------------------------------
 */
if ( 'clean' === $phase ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';

	$f = hpfv_fixture();

	if ( $f ) {
		foreach ( $f['listings'] as $id ) {
			wp_delete_post( $id, true );
		}

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array_keys( hpfv_pending_digests( $f['vendor'] ) ) as $action_id ) {
				ActionScheduler_Store::instance()->cancel_action( $action_id );
			}
		}

		foreach ( get_comments( [ 'type' => 'hp_hpfv_follow', 'post_id' => $f['vendor'], 'fields' => 'ids', 'number' => 0 ] ) as $cid ) {
			wp_delete_comment( $cid, true );
		}

		wp_delete_post( $f['vendor'], true );

		foreach ( [ 'owner', 'follower', 'follower2' ] as $key ) {
			wp_delete_user( $f[ $key ] );
		}
	}

	delete_option( 'hpfv_test_fixture' );

	// Retired when the Save and Alert items moved to Action Bar for HivePress. Still deleted here so
	// a dev site that ran an earlier build does not keep the stale row.
	delete_option( 'hp_follow_vendors_for_hivepress_bar_listing_item' );
	delete_option( 'hp_follow_vendors_for_hivepress_bar_follow' );
	delete_option( 'hp_follow_vendors_for_hivepress_show_follower_count' );
	delete_option( 'hp_follow_vendors_for_hivepress_enable_email' );
	delete_option( 'hp_follow_vendors_for_hivepress_following_label' );

	echo "cleaned\n";
	exit( 0 );
}

/*
 * --------------------------------------------------------------------------------------------------
 * card: one Vendor card through the real template path, on whichever theme is active.
 * --------------------------------------------------------------------------------------------------
 */
if ( 'card' === $phase ) {
	if ( empty( $_SERVER['SERVER_NAME'] ) ) {
		$_SERVER['SERVER_NAME'] = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	$f      = hpfv_fixture();
	$vendor = Models\Vendor::query()->get_by_id( $f['vendor'] );

	hivepress()->hpfv_follow->set_follow_ids();

	$html = ( new Blocks\Template( [ 'template' => 'vendor_view_block', 'context' => [ 'vendor' => $vendor ] ] ) )->render();

	$theme = get_template();

	hpfv_check( "[{$theme}] card renders the follow toggle", false !== strpos( $html, 'hp-vendor__action--follow' ) );
	hpfv_check( "[{$theme}] toggle carries data-component=toggle", false !== strpos( $html, 'data-component="toggle"' ) );
	hpfv_check( "[{$theme}] toggle is inside the actions container", false !== strpos( $html, 'hp-vendor__actions--primary' ) && strpos( $html, 'hp-vendor__actions--primary' ) < strpos( $html, 'hp-vendor__action--follow' ) );

	$page = ( new Blocks\Template( [ 'template' => 'vendor_view_page', 'context' => [ 'vendor' => $vendor ] ] ) )->render();

	hpfv_check( "[{$theme}] page renders the follow toggle", false !== strpos( $page, 'hp-vendor__action--follow' ) );
	hpfv_check( "[{$theme}] page toggle is a button like its neighbours", false !== strpos( $page, 'hp-vendor__action--follow button button--large button--secondary' ) );

	$fails = hpfv_check( null );
	echo 0 === $fails ? "RESULT: card ok\n" : "RESULT: {$fails} card check(s) failed\n";
	exit( $fails > 0 ? 1 : 0 );
}

/*
 * --------------------------------------------------------------------------------------------------
 * check
 * --------------------------------------------------------------------------------------------------
 */
$f = hpfv_fixture();

if ( ! $f ) {
	echo "No fixture: run the seed phase first.\n";
	exit( 1 );
}

$vendor_id = $f['vendor'];
$vendor    = Models\Vendor::query()->get_by_id( $vendor_id );
$owner     = $f['owner'];
$follower  = $f['follower'];
$follower2 = $f['follower2'];
$listings  = $f['listings'];

// Rendering a whole page from WP-CLI reads $_SERVER['SERVER_NAME'] in wp-includes/general-template.php,
// which no CLI request has; a real request always does. Set so the log delta measures the plugin.
if ( empty( $_SERVER['SERVER_NAME'] ) ) {
	$_SERVER['SERVER_NAME'] = (string) wp_parse_url( home_url(), PHP_URL_HOST );
}

echo "=== The harness can fail ===\n";
hpfv_check( 'deliberately false assertion (expected FAIL, subtracted below)', false, 'control' );

echo "=== Registration ===\n";
hpfv_check( 'comment type resolves to the model', 'hpfv_follow' === hivepress()->model->get_model_name( 'comment', 'hp_hpfv_follow' ) );
hpfv_check( 'follow route URL shape', home_url( '/wp-json/hivepress/v1/vendors/' . $vendor_id . '/follow/' ) === hivepress()->router->get_url( 'hpfv_vendor_follow_action', [ 'vendor_id' => $vendor_id ] ) );
// get_classes() hands back the names it derived from the file names, lower-cased.
hpfv_check( 'email class is listed with a label', in_array( 'hpfv_listing_follow', array_map( 'HivePress\Helpers\get_class_name', hivepress()->get_classes( 'emails' ) ), true ) && \HivePress\Emails\Hpfv_Listing_Follow::get_meta( 'label' ) );
hpfv_check( 'Vendor fixture is published and owned by the owner', $vendor && 'publish' === $vendor->get_status() && (int) $vendor->get_user__id() === (int) $owner );
hpfv_check( 'current user is the follower (run with --user=)', get_current_user_id() === (int) $follower, 'user ' . get_current_user_id() );

echo "=== can_follow() ===\n";
hpfv_check( 'follower may follow the Vendor', hivepress()->hpfv_follow->can_follow( $vendor, $follower ) );
hpfv_check( 'owner may not follow their own Vendor', ! hivepress()->hpfv_follow->can_follow( $vendor, $owner ) );
hpfv_check( 'guest passes the ownership test (renders the login branch)', hivepress()->hpfv_follow->can_follow( $vendor, 0 ) );
hpfv_check( 'a non-Vendor object is refused', ! hivepress()->hpfv_follow->can_follow( new stdClass(), $follower ) );

$refuse = function () { return false; };
add_filter( 'hpfv_can_follow', $refuse );
hpfv_check( 'hpfv_can_follow filter can refuse', ! hivepress()->hpfv_follow->can_follow( $vendor, $follower ) );
remove_filter( 'hpfv_can_follow', $refuse );

echo "=== Follow round trip ===\n";
$controller = new \HivePress\Controllers\Hpfv_Follow();

hivepress()->hpfv_follow->set_follow_ids();
hpfv_check( 'not following before', ! hivepress()->hpfv_follow->is_following( $vendor_id ) );
hpfv_check( 'follower count 0 before', 0 === hivepress()->hpfv_follow->get_follower_count( $vendor_id ) );

$request = new WP_REST_Request( 'POST', '/hivepress/v1/vendors/' . $vendor_id . '/follow' );
$request->set_param( 'vendor_id', $vendor_id );

$response = $controller->follow_vendor( $request );
$data     = $response->get_data();

hpfv_check( 'follow answers 200 with following=true and followers=1', 200 === $response->get_status() && ! empty( $data['data']['following'] ) && 1 === (int) $data['data']['followers'], wp_json_encode( $data ) );

$rows = get_comments( [ 'type' => 'hp_hpfv_follow', 'user_id' => $follower, 'post_id' => $vendor_id ] );
hpfv_check( 'one follow row, type hp_hpfv_follow, approved 1', 1 === count( $rows ) && 'hp_hpfv_follow' === $rows[0]->comment_type && '1' === (string) $rows[0]->comment_approved, wp_json_encode( array_map( function ( $r ) { return [ $r->comment_type, $r->comment_approved ]; }, $rows ) ) );

hivepress()->hpfv_follow->set_follow_ids();
hpfv_check( 'cached list rebuilt after the follow (core cleared the user cache)', hivepress()->hpfv_follow->is_following( $vendor_id ) );
hpfv_check( 'follower count 1 after (core cleared the post cache)', 1 === hivepress()->hpfv_follow->get_follower_count( $vendor_id ) );
hpfv_check( 'no follow row is visible to a default comment query', 0 === count( get_comments( [ 'post_id' => $vendor_id ] ) ) );

$menu = hivepress()->hpfv_follow->alter_account_menu( [ 'items' => [] ] );
hpfv_check( 'account menu item added at _order 22', isset( $menu['items']['hpfv_vendors_follow'] ) && 22 === $menu['items']['hpfv_vendors_follow']['_order'] );
hpfv_check( 'account page does not redirect the follower away', false === $controller->redirect_hpfv_vendors_follow_page() );

$response = $controller->follow_vendor( $request );
$data     = $response->get_data();
hpfv_check( 'second call unfollows: following=false, followers=0', 200 === $response->get_status() && empty( $data['data']['following'] ) && 0 === (int) $data['data']['followers'] );
hivepress()->hpfv_follow->set_follow_ids();
hpfv_check( 'cached list rebuilt after the unfollow', ! hivepress()->hpfv_follow->is_following( $vendor_id ) );
$menu = hivepress()->hpfv_follow->alter_account_menu( [ 'items' => [] ] );
hpfv_check( 'account menu item gone with nothing followed', ! isset( $menu['items']['hpfv_vendors_follow'] ) );
hpfv_check( 'account page redirects to the account home with nothing followed', hivepress()->router->get_url( 'user_account_page' ) === $controller->redirect_hpfv_vendors_follow_page() );

// Follow again for the rest of the run.
$controller->follow_vendor( $request );
hivepress()->hpfv_follow->set_follow_ids();

echo "=== Permissions ===\n";
wp_set_current_user( $owner );
$response = $controller->follow_vendor( $request );
hpfv_check( 'owner gets 403', 403 === $response->get_status() );

wp_set_current_user( 0 );
$response = rest_do_request( $request );
hpfv_check( 'guest gets 401 through the REST server', 401 === $response->get_status(), 'status ' . $response->get_status() );

$bad = new WP_REST_Request( 'POST', '/hivepress/v1/vendors/999999999/follow' );
$bad->set_param( 'vendor_id', 999999999 );
wp_set_current_user( $follower );
$response = $controller->follow_vendor( $bad );
hpfv_check( 'unknown Vendor gets 404', 404 === $response->get_status() );

echo "=== Toggle block ===\n";
$toggle = ( new Blocks\Hpfv_Follow_Toggle( [ 'context' => [ 'vendor' => $vendor ] ] ) )->render();
hpfv_check( 'follower sees an active toggle with data-url and the following icon', false !== strpos( $toggle, 'data-component="toggle"' ) && false !== strpos( $toggle, 'data-state="active"' ) && false !== strpos( $toggle, 'fa-user-check' ) && false !== strpos( $toggle, '/vendors/' . $vendor_id . '/follow/' ), $toggle );
hpfv_check( 'toggle caption is the Following label', false !== strpos( $toggle, '<span>Following</span>' ), $toggle );

update_option( 'hp_follow_vendors_for_hivepress_following_label', 'Subscribed' );
$toggle = ( new Blocks\Hpfv_Follow_Toggle( [ 'context' => [ 'vendor' => $vendor ] ] ) )->render();
hpfv_check( 'a reworded label flows into the toggle', false !== strpos( $toggle, '<span>Subscribed</span>' ), $toggle );
delete_option( 'hp_follow_vendors_for_hivepress_following_label' );

wp_set_current_user( $owner );
hpfv_check( 'owner sees no toggle on their own Vendor', '' === ( new Blocks\Hpfv_Follow_Toggle( [ 'context' => [ 'vendor' => $vendor ] ] ) )->render() );

wp_set_current_user( 0 );
$toggle = ( new Blocks\Hpfv_Follow_Toggle( [ 'context' => [ 'vendor' => $vendor ] ] ) )->render();
hpfv_check( 'guest toggle opens the login modal', false !== strpos( $toggle, 'href="#user_login_modal"' ) && false !== strpos( $toggle, 'fa-user-plus' ), $toggle );
wp_set_current_user( $follower );

echo "=== Follower count block ===\n";
hpfv_check( 'count block reads 1 follower', false !== strpos( ( new Blocks\Hpfv_Follower_Count( [ 'context' => [ 'vendor' => $vendor ] ] ) )->render(), '1 follower<' ) );
$other = Models\Vendor::query()->filter( [ 'status' => 'publish', 'id__not_in' => [ $vendor_id ] ] )->get_first();
hpfv_check( 'count block renders nothing for a Vendor with no followers', ! $other || '' === ( new Blocks\Hpfv_Follower_Count( [ 'context' => [ 'vendor' => $other ] ] ) )->render() );

echo "=== Account page render (as the follower) ===\n";
try {
	$html = $controller->render_hpfv_vendors_follow_page();
	hpfv_check( 'page lists the followed Vendor as a card', false !== strpos( $html, 'hp-vendor--view-block' ) && false !== strpos( $html, 'HPFV Test Vendor' ) );
	hpfv_check( 'card on the page carries the follow toggle', false !== strpos( $html, 'hp-vendor__action--follow' ) );
	hpfv_check( 'page carries the account menu item', false !== strpos( $html, '/account/followed-vendors/' ) );
} catch ( \Throwable $e ) {
	hpfv_check( 'account page rendered', false, get_class( $e ) . ': ' . $e->getMessage() );
}

echo "=== Digest: queue ===\n";
wp_set_current_user( $follower2 );
hivepress()->hpfv_follow->set_follow_ids();
$controller->follow_vendor( $request );
wp_set_current_user( $follower );
hivepress()->hpfv_follow->set_follow_ids();
hpfv_check( 'two followers now', 2 === hivepress()->hpfv_follow->get_follower_count( $vendor_id ) );

$sent = [];
add_filter(
	'pre_wp_mail',
	function ( $pre, $atts ) use ( &$sent ) {
		$sent[] = $atts;

		return true;
	},
	10,
	2
);

foreach ( array_keys( hpfv_pending_digests( $vendor_id ) ) as $stale ) {
	ActionScheduler_Store::instance()->cancel_action( $stale );
}

$email = hivepress()->hpfv_follow_email;
$delay = $email->get_delay();
hpfv_check( 'default quiet period is 10 minutes', 600 === $delay, $delay );

wp_update_post( [ 'ID' => $listings[0], 'post_status' => 'publish' ] );
$stamp1 = (int) get_post_meta( $listings[0], 'hp_hpfv_notified_time', true );
hpfv_check( 'publishing stamps the Listing', $stamp1 > 0 );

$pending = hpfv_pending_digests( $vendor_id );
$window  = (int) floor( time() / $delay );
hpfv_check( 'one digest job queued with [vendor, window]', 1 === count( $pending ) && [ $vendor_id, $window ] === array_values( reset( $pending ) ), wp_json_encode( $pending ) );

wp_update_post( [ 'ID' => $listings[1], 'post_status' => 'publish' ] );
hpfv_check( 'second publish in the same window queues nothing more', 1 === count( hpfv_pending_digests( $vendor_id ) ) );

wp_update_post( [ 'ID' => $listings[0], 'post_status' => 'draft' ] );
wp_update_post( [ 'ID' => $listings[0], 'post_status' => 'publish' ] );
hpfv_check( 'republishing (hide/unhide, renewal) keeps the original stamp', $stamp1 === (int) get_post_meta( $listings[0], 'hp_hpfv_notified_time', true ) );
hpfv_check( 'and queues nothing more', 1 === count( hpfv_pending_digests( $vendor_id ) ) );

echo "=== Digest: send ===\n";
sleep( 1 );
$sent = [];
$email->send_digest( $vendor_id, $window );

hpfv_check( 'one email per follower', 2 === count( $sent ), count( $sent ) );
$to = array_map( function ( $m ) { return $m['to']; }, $sent );
sort( $to );
hpfv_check( 'sent to both followers', [ 'hpfv_test_follower2@example.com', 'hpfv_test_follower@example.com' ] === $to, wp_json_encode( $to ) );
$body = isset( $sent[0]['message'] ) ? $sent[0]['message'] : '';
hpfv_check( 'body links both Listings', false !== strpos( $body, get_permalink( $listings[0] ) ) && false !== strpos( $body, get_permalink( $listings[1] ) ) );
// The seeded "<Two>" title was stripped by kses on insert (the owner is a contributor), so the
// escaping is proven by handing the builder a title with markup through the_title instead.
$markup_title = function () { return 'HPFV <b>Bold</b> & "Quoted"'; };
add_filter( 'the_title', $markup_title, 1000 );
$lines = $email->get_listings_html( [ $listings[1] ] );
remove_filter( 'the_title', $markup_title, 1000 );
hpfv_check( 'a title containing markup is escaped in %listings%', false !== strpos( $lines, '&lt;b&gt;Bold&lt;/b&gt;' ) && false === strpos( $lines, '<b>' ) && false !== strpos( $lines, '<a href="' ), $lines );
hpfv_check( 'subject carries the Vendor name', isset( $sent[0]['subject'] ) && 'New Listings from HPFV Test Vendor' === $sent[0]['subject'], isset( $sent[0]['subject'] ) ? $sent[0]['subject'] : '' );
hpfv_check( 'body greets the follower by name and links the account page', false !== strpos( $body, 'HPFV' ) && false !== strpos( $body, '/account/followed-vendors/' ) );
$cursor = (int) get_post_meta( $vendor_id, 'hp_hpfv_digest_time', true );
hpfv_check( 'cursor written, at or after the last stamp', $cursor >= (int) get_post_meta( $listings[1], 'hp_hpfv_notified_time', true ) );

$sent = [];
$email->send_digest( $vendor_id, $window );
hpfv_check( 'running the same job again sends nothing (cursor)', 0 === count( $sent ) );

echo "=== Digest: a later publish is its own job ===\n";
foreach ( array_keys( hpfv_pending_digests( $vendor_id ) ) as $done ) {
	ActionScheduler_Store::instance()->mark_complete( $done );
}
sleep( 1 );
wp_update_post( [ 'ID' => $listings[2], 'post_status' => 'publish' ] );
hpfv_check( 'a publish after the previous job completed queues a new job', 1 === count( hpfv_pending_digests( $vendor_id ) ) );
sleep( 1 );
$sent = [];
$email->send_digest( $vendor_id, $window );
hpfv_check( 'the new job announces only the third Listing', 2 === count( $sent ) && false !== strpos( $sent[0]['message'], get_permalink( $listings[2] ) ) && false === strpos( $sent[0]['message'], get_permalink( $listings[0] ) ) );

echo "=== Digest: through Action Scheduler's runner ===\n";
foreach ( array_keys( hpfv_pending_digests( $vendor_id ) ) as $done ) {
	ActionScheduler_Store::instance()->mark_complete( $done );
}
sleep( 1 );
wp_update_post( [ 'ID' => $listings[3], 'post_status' => 'publish' ] );
sleep( 1 );
$sent = [];
// Processed through Action Scheduler's own execution path (the same do_action_ref_array a queue
// run uses) rather than run(), which returns without processing whenever another claim is open.
$action_id = as_schedule_single_action( time() - 5, 'hpfv_send_follow_emails', [ $vendor_id, 424242 ], 'hivepress' );
ActionScheduler::runner()->process_action( $action_id, 'test' );
hpfv_check( 'the scheduled hook reaches send_digest() and emails both followers once', 2 === count( $sent ) && false !== strpos( $sent[0]['message'], get_permalink( $listings[3] ) ), count( $sent ) );
hpfv_check( 'Action Scheduler marked the action complete', 'complete' === ActionScheduler_Store::instance()->get_status( $action_id ) );

echo "=== Digest: switched off ===\n";
update_option( 'hp_follow_vendors_for_hivepress_enable_email', '' );
foreach ( array_keys( hpfv_pending_digests( $vendor_id ) ) as $done ) {
	ActionScheduler_Store::instance()->mark_complete( $done );
}
wp_update_post( [ 'ID' => $listings[4], 'post_status' => 'publish' ] );
hpfv_check( 'with emails off a publish IS stamped (so it is never announced later)', (bool) get_post_meta( $listings[4], 'hp_hpfv_notified_time', true ) );
hpfv_check( 'and no job is queued', 0 === count( hpfv_pending_digests( $vendor_id ) ) );
delete_option( 'hp_follow_vendors_for_hivepress_enable_email' );
wp_update_post( [ 'ID' => $listings[4], 'post_status' => 'draft' ] );
wp_update_post( [ 'ID' => $listings[4], 'post_status' => 'publish' ] );
hpfv_check( 'emails back on: re-publishing that Listing queues nothing', 0 === count( hpfv_pending_digests( $vendor_id ) ) );

echo "=== Action Bar items ===\n";
$bar = hivepress()->hpfv_follow_action_bar;
hpfv_check( 'Action Bar detected', $bar->has_action_bar() );
hpfv_check( 'items filter registered on init', false !== has_filter( 'hivepress/v1/action_bar/items', [ $bar, 'add_items' ] ) );

hivepress()->request->set_context( 'vendor', $vendor );
$items = $bar->build_items( 'vendor_view_page' );
$item  = reset( $items );
hpfv_check( 'Vendor page: one follow item, bare fragment href, Following label and icon', 1 === count( $items ) && '#hpfv-follow-' . $vendor_id === $item['bar']['url'] && 'Following' === $item['bar']['label'] && 'fas fa-user-check' === $item['bar']['icon'] && '' === $item['bar']['link'], wp_json_encode( $items ) );
hpfv_check( 'follow item data: active, REST url, two labels, two icons', ! empty( $item['data']['active'] ) && false !== strpos( $item['data']['url'], '/vendors/' . $vendor_id . '/follow/' ) && [ 'Follow', 'Following' ] === $item['data']['labels'] && 2 === count( $item['data']['icons'] ) );
hpfv_check( 'follow item carries SVG pairs when the icon library is loaded', ! class_exists( 'FAFH' ) || ( 2 === count( $item['data']['paths'] ) && false !== strpos( $item['data']['paths'][0], '|' ) ), wp_json_encode( $item['data']['paths'] ) );

$listing = Models\Listing::query()->get_by_id( $listings[0] );
hivepress()->request->set_context( 'listing', $listing );
$items = $bar->build_items( 'listing_view_page' );
hpfv_check( 'Listing page: the follow item alone', 1 === count( $items ) && isset( $items[ 'hpfv-follow-' . $vendor_id ] ), implode( ',', array_keys( $items ) ) );

/*
 * The Save and Alert items belong to Action Bar for HivePress now (its Quick Actions section).
 * With a published Listing in context and the Favorites extension active, this plugin must still
 * produce neither, or the visitor would see the button twice.
 */
hpfv_check( 'no save item is produced here any more', ! isset( $items[ 'hpfv-favorite-' . $listings[0] ] ), implode( ',', array_keys( $items ) ) );
hpfv_check( 'and none on the Requests search route either', 0 === count( $bar->build_items( 'requests_view_page' ) ) );

update_option( 'hp_follow_vendors_for_hivepress_bar_follow', '' );
hpfv_check( 'bar_follow unticked removes the follow item', 0 === count( $bar->build_items( 'vendor_view_page' ) ) );
delete_option( 'hp_follow_vendors_for_hivepress_bar_follow' );

hpfv_check( 'search route with no search query gives no alert item', 0 === count( $bar->build_items( 'listings_view_page' ) ) );
hpfv_check( 'an unrelated route gives nothing', 0 === count( $bar->build_items( 'listing_edit_page' ) ) );

// add_items() reads the memoised items for the CURRENT route, which WP-CLI has none of, so it
// appends whatever get_items() holds (nothing here) after the owner's rows, untouched.
$appended = $bar->add_items( [ [ 'link' => 'home', 'url' => '/', 'icon' => 'fas fa-home', 'label' => 'Home' ] ], 'user' );
hpfv_check( 'add_items() keeps the owner rows first and appends the route items', count( $appended ) === 1 + count( $bar->get_items() ) && 'home' === $appended[0]['link'] );

wp_set_current_user( 0 );
$items = $bar->build_items( 'vendor_view_page' );
$item  = reset( $items );
hpfv_check( 'guest: auth_modal item pointing at the login page, Follow label, no script data', 1 === count( $items ) && 'auth_modal' === $item['bar']['link'] && false !== strpos( $item['bar']['url'], 'login' ) && 'Follow' === $item['bar']['label'] && is_null( $item['data'] ), wp_json_encode( $items ) );
wp_set_current_user( $owner );
hpfv_check( 'owner: no follow item on their own Vendor page', 0 === count( $bar->build_items( 'vendor_view_page' ) ) );
wp_set_current_user( $follower );

echo "=== Settings read-through ===\n";
hpfv_check( 'absent checkbox reads its default (on)', true === hpfv_get_option( 'follow_vendors_for_hivepress_enable_email', true ) );
update_option( 'hp_follow_vendors_for_hivepress_enable_email', '' );
hpfv_check( "stored '' reads as off", '' === hpfv_get_option( 'follow_vendors_for_hivepress_enable_email', true ) && ! $email->is_enabled() );
delete_option( 'hp_follow_vendors_for_hivepress_enable_email' );
update_option( 'hp_follow_vendors_for_hivepress_email_delay', '' );
hpfv_check( 'a cleared number falls back to 10', 10 === hpfv_get_number_option( 'follow_vendors_for_hivepress_email_delay', 10 ) );
update_option( 'hp_follow_vendors_for_hivepress_email_delay', '0' );
hpfv_check( 'an explicit 0 is respected by the reader and clamped by the digest', 0 === hpfv_get_number_option( 'follow_vendors_for_hivepress_email_delay', 10 ) && 60 === $email->get_delay() );
delete_option( 'hp_follow_vendors_for_hivepress_email_delay' );
update_option( 'hp_follow_vendors_for_hivepress_toggle_location', '' );
hpfv_check( "a radio stored as '' is treated as both", hivepress()->hpfv_follow->is_toggle_enabled( 'block' ) && hivepress()->hpfv_follow->is_toggle_enabled( 'page' ) );
update_option( 'hp_follow_vendors_for_hivepress_toggle_location', 'page' );
hpfv_check( 'toggle_location=page hides the card toggle', ! hivepress()->hpfv_follow->is_toggle_enabled( 'block' ) && hivepress()->hpfv_follow->is_toggle_enabled( 'page' ) );
delete_option( 'hp_follow_vendors_for_hivepress_toggle_location' );

echo "=== Settings tab notes (admin filter) ===\n";
$settings = hivepress()->hpfv_follow_admin->add_settings_notes( hivepress()->get_config( 'settings' ) );
hpfv_check( 'notes filter returns the tab intact', isset( $settings['follow_vendors']['sections']['action_bar'] ) );
hpfv_check( 'notes filter survives an array without the tab', [ 'x' => 1 ] === hivepress()->hpfv_follow_admin->add_settings_notes( [ 'x' => 1 ] ) );

$fails = hpfv_check( null ) - 1;
echo "\n----------------------------------------\n";
echo 0 === $fails ? "RESULT: ALL CHECKS PASSED (plus the one deliberate control failure)\n" : "RESULT: {$fails} CHECK(S) FAILED (excluding the control)\n";
exit( $fails > 0 ? 1 : 0 );
