<?php
/**
 * Follow email component: the digest that tells followers about new Listings.
 *
 * WHY A DIGEST AND NOT ONE EMAIL PER LISTING. A Vendor who publishes five Listings in ten minutes
 * would otherwise send five emails to every follower, each one holding the Vendor's own publish
 * request open while wp_mail() ran for the whole follower list. So publishing only STAMPS the
 * Listing and queues one background job per Vendor per quiet period; the job gathers every
 * Listing stamped since the last digest and sends each follower one email listing them all.
 *
 * WHY THE WINDOW NUMBER IS IN THE JOB'S ARGUMENTS. HivePress's scheduler refuses to queue a hook
 * whose exact arguments are already pending or RUNNING (components/class-scheduler.php:57, core
 * 1.7.31). With `[ $vendor_id ]` alone, a Listing published while the previous digest was mid-run
 * would queue nothing, and the cursor would then move past it: announced by nobody. With the
 * window in the arguments, publishes inside one quiet period share one job (that is the throttle)
 * and a publish in the next period always gets its own. The job for window W runs at
 * publish-time + delay, which is inside window W+1, so any later publish inside W happens before
 * the job runs and is caught by the cursor query.
 *
 * WHY FOLLOWERS ARE SENT IN BATCHES OF 50 THAT EACH CARRY THEIR BOUNDS. A running Action Scheduler
 * action that passes 300 seconds is marked FAILED, not retried (action-scheduler/classes/
 * ActionScheduler_QueueCleaner.php:206-230), so one big run over thousands of followers is a
 * silent loss. Small batches are the whole mitigation: a batch that dies costs at most 50
 * followers one digest. Every batch carries the Listing bounds it must announce and its own
 * offset in the arguments, so successive batches never match each other in the scheduler's
 * duplicate check and every batch announces the same Listing set.
 *
 * @package Follow_Vendors
 */

namespace HivePress\Components;

use HivePress\Models;
use HivePress\Emails;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Follow email component class.
 *
 * @class Hpfv_Follow_Email
 */
final class Hpfv_Follow_Email extends Component {

	/**
	 * The scheduled hook.
	 *
	 * @var string
	 */
	const HOOK = 'hpfv_send_follow_emails';

	/**
	 * Listing meta: when the Listing was stamped for announcement (unix time).
	 *
	 * @var string
	 */
	const NOTIFIED_META = 'hp_hpfv_notified_time';

	/**
	 * Vendor meta: the stamp of the last Listing announced for this Vendor (unix time).
	 *
	 * @var string
	 */
	const CURSOR_META = 'hp_hpfv_digest_time';

	/**
	 * Listings announced per batch, and followers emailed per batch.
	 *
	 * @var int
	 */
	const BATCH = 50;

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {

		// Fires only when the status really changed and never under import
		// (components/class-hook.php:243-268, core 1.7.31).
		add_action( 'hivepress/v1/models/listing/update_status', [ $this, 'queue_digest' ], 10, 4 );

		// Action Scheduler passes the stored arguments positionally.
		add_action( self::HOOK, [ $this, 'send_digest' ], 10, 5 );

		parent::__construct( $args );
	}

	/**
	 * Checks whether the emails are switched on.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) hpfv_get_option( HPFV_OPTION_PREFIX . 'enable_email', true );
	}

	/**
	 * Gets the quiet period in seconds.
	 *
	 * @return int
	 */
	public function get_delay() {
		$minutes = hpfv_get_number_option( HPFV_OPTION_PREFIX . 'email_delay', 10 );

		return max( 1, min( 1440, $minutes ) ) * MINUTE_IN_SECONDS;
	}

	/**
	 * Stamps a newly published Listing and queues the Vendor's digest.
	 *
	 * @param int    $listing_id Listing ID.
	 * @param string $new_status New status.
	 * @param string $old_status Old status.
	 * @param mixed  $listing Listing object.
	 */
	public function queue_digest( $listing_id, $new_status, $old_status, $listing ) {
		if ( 'publish' !== $new_status ) {
			return;
		}

		if ( ! $listing instanceof Models\Listing ) {
			return;
		}

		$vendor_id = absint( $listing->get_vendor__id() );

		if ( ! $vendor_id ) {
			return;
		}

		// A Listing is announced once in its life. Renewals, hide/unhide and moderation round
		// trips all pass through publish again; none of them is news to a follower.
		if ( get_post_meta( absint( $listing_id ), self::NOTIFIED_META, true ) ) {
			return;
		}

		// Stamp BEFORE the enabled check, so the stamp records "this Listing has had its first
		// publish" whether or not an email went out. Checking first left a Listing published
		// while the emails were off unstamped; when the owner ticked the box again, that
		// Listing's next publish transition (a renewal, a hide and unhide, a moderation round
		// trip) was stamped and announced as new, which is exactly what the settings tab and
		// the readme promise never happens.
		update_post_meta( absint( $listing_id ), self::NOTIFIED_META, time() );

		if ( ! $this->is_enabled() ) {
			return;
		}

		$delay  = $this->get_delay();
		$window = (int) floor( time() / $delay );

		hivepress()->scheduler->add_action( self::HOOK, [ $vendor_id, $window ], time() + $delay );
	}

	/**
	 * Sends one batch of the digest.
	 *
	 * THREE RULES THIS ENCODES:
	 *
	 * 1. The cursor is the stamp of the last Listing announced, never "now". Listings 51 onwards,
	 *    and anything stamped later, stay above the cursor and are announced by the next job.
	 * 2. `$until = time() - 1` closes a same-second race. A Listing stamped in the second this job
	 *    starts, after the query ran, would otherwise sit at exactly `$until`, below the cursor,
	 *    and be lost. Excluding the current second means anything stamped after the query is
	 *    strictly above the cursor; the cost is that a Listing published in that one second waits
	 *    for its own window's job.
	 * 3. The cursor is written BEFORE sending, so a Listing published while the batches run is
	 *    above it and safe.
	 *
	 * @param int $vendor_id Vendor ID.
	 * @param int $window Quiet-period window number (part of the job identity only).
	 * @param int $offset Follower offset for this batch.
	 * @param int $since Exclusive lower bound of the announced stamps (continuation batches).
	 * @param int $until Inclusive upper bound of the announced stamps (continuation batches).
	 */
	public function send_digest( $vendor_id, $window = 0, $offset = 0, $since = 0, $until = 0 ) {
		$vendor_id = absint( $vendor_id );
		$window    = (int) $window;
		$offset    = absint( $offset );
		$since     = (int) $since;
		$until     = (int) $until;

		// The owner may have switched the emails off after this job was queued.
		if ( ! $this->is_enabled() ) {
			return;
		}

		$vendor = Models\Vendor::query()->get_by_id( $vendor_id );

		if ( empty( $vendor ) || 'publish' !== $vendor->get_status() ) {
			return;
		}

		if ( 0 === $offset ) {
			$since = (int) get_post_meta( $vendor_id, self::CURSOR_META, true );
			$until = time() - 1;

			$listing_ids = $this->get_listing_ids( $vendor_id, $since, $until, self::BATCH );

			if ( ! $listing_ids ) {
				return;
			}

			// A full page means more may be waiting: stop at the last one announced so the rest
			// stay above the cursor for the next job.
			if ( count( $listing_ids ) >= self::BATCH ) {
				$last  = (int) end( $listing_ids );
				$until = (int) get_post_meta( $last, self::NOTIFIED_META, true );
			}

			update_post_meta( $vendor_id, self::CURSOR_META, $until );
		} else {
			$listing_ids = $this->get_listing_ids( $vendor_id, $since, $until, 0 );

			if ( ! $listing_ids ) {
				return;
			}
		}

		/**
		 * Filters the Listings a digest announces.
		 *
		 * @hook hpfv_digest_listings
		 * @param {array} $listing_ids Listing IDs.
		 * @param {int} $vendor_id Vendor ID.
		 * @return {array} Listing IDs.
		 */
		$listing_ids = array_values( array_filter( array_map( 'absint', (array) apply_filters( 'hpfv_digest_listings', $listing_ids, $vendor_id ) ) ) );

		if ( ! $listing_ids ) {
			return;
		}

		// One page of followers, read once: the unfiltered count decides whether another batch
		// follows, the filtered list decides who is emailed in this one.
		$page = hivepress()->hpfv_follow->get_follower_user_ids( $vendor_id, self::BATCH, $offset );

		/**
		 * Filters the followers a digest batch is sent to.
		 *
		 * @hook hpfv_digest_recipients
		 * @param {array} $user_ids User IDs.
		 * @param {int} $vendor_id Vendor ID.
		 * @return {array} User IDs.
		 */
		$user_ids = array_values( array_filter( array_map( 'absint', (array) apply_filters( 'hpfv_digest_recipients', $page, $vendor_id ) ) ) );

		$listings_html = $this->get_listings_html( $listing_ids );

		foreach ( $user_ids as $user_id ) {
			$user = Models\User::query()->get_by_id( $user_id );

			if ( empty( $user ) || ! $user->get_email() ) {
				continue;
			}

			// The two names are substituted raw into a body that has ALREADY been through
			// hp\sanitize_html() (helpers.php:319-387 is a plain str_replace, core 1.7.31), so
			// anything wp-admin, an importer or another plugin wrote into the Vendor's post_title
			// would land in every follower's inbox as markup. Tags are stripped rather than
			// escaped because the same token is used in the subject line, where an escaped
			// ampersand would show as "&amp;".
			( new Emails\Hpfv_Listing_Follow(
				[
					'recipient' => $user->get_email(),

					'tokens'    => [
						'user_name'            => wp_strip_all_tags( (string) $user->get_display_name() ),
						'vendor_name'          => wp_strip_all_tags( (string) $vendor->get_name() ),
						'vendor_url'           => get_permalink( $vendor_id ),
						'listings'             => $listings_html,
						'listing_count'        => count( $listing_ids ),
						'followed_vendors_url' => hivepress()->router->get_url( 'hpfv_vendors_follow_page' ),
						'user'                 => $user,
						'vendor'               => $vendor,
					],
				]
			) )->send();
		}

		// A full page of followers means there may be more: queue the next batch with its own
		// offset and the same bounds, so the scheduler's duplicate check sees a new job and every
		// batch announces the same Listings. Async, so it runs moments later.
		if ( count( $page ) >= self::BATCH ) {
			hivepress()->scheduler->add_action( self::HOOK, [ $vendor_id, $window, $offset + self::BATCH, $since, $until ] );
		}
	}

	/**
	 * Gets the published Listings of a Vendor stamped inside ( $since, $until ], oldest stamp first.
	 *
	 * @param int $vendor_id Vendor ID.
	 * @param int $since Exclusive lower bound.
	 * @param int $until Inclusive upper bound.
	 * @param int $limit Maximum number, 0 for no limit.
	 * @return int[]
	 */
	public function get_listing_ids( $vendor_id, $since, $until, $limit ) {
		$ids = get_posts(
			[
				'post_type'      => 'hp_listing',
				'post_status'    => 'publish',
				'post_parent'    => absint( $vendor_id ),
				'fields'         => 'ids',
				'posts_per_page' => $limit > 0 ? absint( $limit ) : -1,
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the stamp is what orders the digest; the scan is bounded by post_parent and the two-sided range below.
				'meta_key'       => self::NOTIFIED_META,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- see above.
				'meta_query'     => [
					'relation' => 'AND',
					[
						'key'     => self::NOTIFIED_META,
						'value'   => (int) $since,
						'compare' => '>',
						'type'    => 'NUMERIC',
					],
					[
						'key'     => self::NOTIFIED_META,
						'value'   => (int) $until,
						'compare' => '<=',
						'type'    => 'NUMERIC',
					],
				],
			]
		);

		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}

	/**
	 * Builds the %listings% token: one escaped anchor per Listing, separated by <br>.
	 *
	 * Escaped here because the token value is substituted raw into the body and echoed as is by
	 * the email part. A Listing title containing "<" therefore cannot become markup.
	 *
	 * @param int[] $listing_ids Listing IDs.
	 * @return string
	 */
	public function get_listings_html( $listing_ids ) {
		$lines = [];

		foreach ( $listing_ids as $listing_id ) {
			$title = get_the_title( $listing_id );
			$url   = get_permalink( $listing_id );

			if ( '' === (string) $title || ! $url ) {
				continue;
			}

			$lines[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>';
		}

		if ( ! $lines ) {
			return '';
		}

		return '<br>' . implode( '<br>', $lines ) . '<br>';
	}
}
