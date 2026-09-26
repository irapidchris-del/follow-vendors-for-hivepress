<?php
/**
 * Follow controller: the REST toggle and the account page route.
 *
 * Route names are NOT derived from this class name, so they carry no collision risk and stay
 * readable. The class and file are prefixed because core instantiates
 * \HivePress\Controllers\{Filename} across every extension and loads one file per class name.
 *
 * @package Follow_Vendors
 */

namespace HivePress\Controllers;

use HivePress\Helpers as hp;
use HivePress\Models;
use HivePress\Blocks;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Follow controller class.
 *
 * @class Hpfv_Follow
 */
final class Hpfv_Follow extends Controller {

	/**
	 * Class constructor.
	 *
	 * Core registers `vendors_resource` only (controllers/class-vendor.php:32-37, core 1.7.31);
	 * there is no `vendor_resource` with an ID in its path the way `listing_resource` has one.
	 * Declaring that child here, shaped exactly like core's `listing_resource`
	 * (controllers/class-listing.php:49-53), gives the toggle a proper address,
	 * /wp-json/hivepress/v1/vendors/{id}/follow/, instead of a bare /follow with the ID pushed
	 * into the query string.
	 *
	 * The page title is a string read from the settings, never a callable: route title callables
	 * assume the front-end main query and fatal in wp-admin (resources/hivepress-framework.md,
	 * "Routes").
	 *
	 * @param array $args Controller arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_arrays(
			[
				'routes' => [
					'hpfv_vendor_resource'      => [
						'base' => 'vendors_resource',
						'path' => '/(?P<vendor_id>\d+)',
						'rest' => true,
					],

					'hpfv_vendor_follow_action' => [
						'base'   => 'hpfv_vendor_resource',
						'path'   => '/follow',
						'method' => 'POST',
						'action' => [ $this, 'follow_vendor' ],
						'rest'   => true,
					],

					'hpfv_vendors_follow_page'  => [
						'title'     => $this->get_page_title(),
						'base'      => 'user_account_page',
						'path'      => '/followed-vendors',
						'redirect'  => [ $this, 'redirect_hpfv_vendors_follow_page' ],
						'action'    => [ $this, 'render_hpfv_vendors_follow_page' ],
						'paginated' => true,
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}

	/**
	 * Gets the account page title from the settings.
	 *
	 * Read here rather than through the component because controllers are built by the router
	 * on init (priority 10), and the label must be a plain string at that moment.
	 *
	 * @return string
	 */
	protected function get_page_title() {
		$title = function_exists( 'hpfv_get_option' ) ? hpfv_get_option( HPFV_OPTION_PREFIX . 'page_title', '' ) : '';

		return '' === (string) $title ? esc_html__( 'Followed Vendors', 'follow-vendors-for-hivepress' ) : (string) $title;
	}

	/**
	 * Follows or unfollows a Vendor.
	 *
	 * Every HivePress REST route registers with permission_callback __return_true, so the checks
	 * below are the whole of the security (resources/security-standards.md, 3), in this order:
	 * signed in, Vendor exists and is published, allowed to follow it (own Vendor, display off,
	 * the filter), then the toggle.
	 *
	 * @param \WP_REST_Request $request API request.
	 * @return \WP_REST_Response
	 */
	public function follow_vendor( $request ) {

		// Check authentication.
		if ( ! is_user_logged_in() ) {
			return hp\rest_error( 401 );
		}

		// Get Vendor.
		$vendor = Models\Vendor::query()->get_by_id( absint( $request->get_param( 'vendor_id' ) ) );

		if ( empty( $vendor ) || 'publish' !== $vendor->get_status() ) {
			return hp\rest_error( 404 );
		}

		// Check permissions.
		if ( ! hivepress()->hpfv_follow->can_follow( $vendor ) ) {
			return hp\rest_error( 403 );
		}

		// Get follows.
		$follows = Models\Hpfv_Follow::query()->filter(
			[
				'user'   => get_current_user_id(),
				'vendor' => $vendor->get_id(),
			]
		)->get();

		$following = false;

		if ( $follows->count() ) {

			// Delete follows.
			$follows->delete();
		} else {

			// Add follow.
			$follow = ( new Models\Hpfv_Follow() )->fill(
				[
					'user'   => get_current_user_id(),
					'vendor' => $vendor->get_id(),
				]
			);

			if ( ! $follow->save() ) {
				return hp\rest_error( 400, $follow->_get_errors() );
			}

			$following = true;
		}

		// The count is read AFTER the row change, and core has already cleared this Vendor's
		// cached count on the comment create/delete action, so the number is fresh.
		return hp\rest_response(
			200,
			[
				'id'        => $vendor->get_id(),
				'following' => $following,
				'followers' => hivepress()->hpfv_follow->get_follower_count( $vendor->get_id() ),
			]
		);
	}

	/**
	 * Redirects the account page: guests to sign in, a user with nothing followed to the
	 * account home, exactly as Favorites does (controllers/class-favorite.php:117-135).
	 *
	 * @return mixed
	 */
	public function redirect_hpfv_vendors_follow_page() {

		// Check authentication.
		if ( ! is_user_logged_in() ) {
			return hivepress()->router->get_return_url( 'user_login_page' );
		}

		// Check Vendors.
		if ( ! hivepress()->hpfv_follow->has_followed_vendors() ) {
			return hivepress()->router->get_url( 'user_account_page' );
		}

		return false;
	}

	/**
	 * Renders the account page.
	 *
	 * The query goes into the `post_query` request context, which the Page block turns into the
	 * main query (blocks/class-page.php:53, core 1.7.31); the Vendors block then takes the main
	 * query whenever the template context carries a `vendors` key (blocks/class-vendors.php:183,
	 * :247-267) and renders each result as a Vendor card.
	 *
	 * @return string
	 */
	public function render_hpfv_vendors_follow_page() {
		$per_page = absint( get_option( 'hp_vendors_per_page' ) );

		if ( ! $per_page ) {
			$per_page = absint( get_option( 'posts_per_page', 10 ) );
		}

		// Query Vendors.
		hivepress()->request->set_context(
			'post_query',
			Models\Vendor::query()->filter(
				[
					'status' => 'publish',
					'id__in' => hivepress()->hpfv_follow->get_follow_ids(),
				]
			)
			->order( 'id__in' )
			->limit( $per_page )
			->paginate( hivepress()->request->get_page_number() )
			->get_args()
		);

		// Render template.
		return ( new Blocks\Template(
			[
				'template' => 'hpfv_vendors_follow_page',

				'context'  => [
					'vendors' => [],
				],
			]
		) )->render();
	}
}
