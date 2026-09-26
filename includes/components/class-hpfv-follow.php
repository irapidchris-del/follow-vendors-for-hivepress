<?php
/**
 * Follow component: the follow records, the cached lists, the account menu item and the toggle
 * on Vendor cards and pages.
 *
 * Mirrors the Favorites extension's component (hivepress-favorites/includes/components/
 * class-favorite.php, 1.2.2) with the Listing swapped for a Vendor. The digest email and the
 * Action Bar items live in their own components so a reader sent to one never wades through the
 * other.
 *
 * Registered by HivePress itself: core globs includes/components/*.php across every registered
 * extension, so the accessor is hivepress()->hpfv_follow (derived from this file name).
 *
 * @package Follow_Vendors
 */

namespace HivePress\Components;

use HivePress\Models;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Follow component class.
 *
 * @class Hpfv_Follow
 */
final class Hpfv_Follow extends Component {

	/**
	 * Request context key holding the current user's followed Vendor IDs.
	 *
	 * @var string
	 */
	const CONTEXT_KEY = 'hpfv_follow_ids';

	/**
	 * Cache group for every cached value derived from the follow rows.
	 *
	 * Core clears this group by itself: on every create, update or delete of a comment whose
	 * type resolves to a model, it bumps the transient group, the commenter's user-meta cache
	 * and the post's post-meta cache for `models/{model}` (components/class-cache.php:624-648,
	 * core 1.7.31). So a per-user list and a per-Vendor count keyed under this group clear on
	 * every follow and unfollow with no code here. The name is core's own derivation from the
	 * model, `models/` + the model name (components/class-model.php:70-81).
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'models/hpfv_follow';

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {

		// Delete a user's follows when the user goes. wp_delete_user() deletes their posts and
		// nothing else, so without this the rows would point at somebody who no longer exists
		// (resources/wordpress-php-notes.md, "wp_delete_user() does not delete the user's comments").
		add_action( 'hivepress/v1/models/user/delete', [ $this, 'delete_follows' ] );

		// Flush the rewrite rules when the version changes, so the account route works after an
		// update as well as after an activation (resources/hivepress-framework.md, "An
		// activation-only flush is not enough"). Priority 20: the router registers routes at 10.
		add_action( 'init', [ $this, 'maybe_flush_rewrite_rules' ], 20 );

		if ( ! is_admin() ) {

			// Build the followed-Vendor list once per request. Priority 100, like Favorites, which is
			// also where core sets the current user on the request (components/class-request.php:31).
			add_action( 'init', [ $this, 'set_follow_ids' ], 100 );

			// Alter account menu.
			add_filter( 'hivepress/v1/menus/user_account', [ $this, 'alter_account_menu' ] );

			// Alter templates.
			add_filter( 'hivepress/v1/templates/vendor_view_block', [ $this, 'alter_vendor_view_block' ] );
			add_filter( 'hivepress/v1/templates/vendor_view_page', [ $this, 'alter_vendor_view_page' ] );

			// Priority 10, after core enqueues its own stylesheet at 5 (components/class-asset.php:33),
			// because wp_add_inline_style() attaches to a handle that must already be registered.
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_styles' ] );

			// Page 2 of the account page answers 404 without this (resources/hivepress-framework.md,
			// "Paginated routes are served with a 404 status").
			add_action( 'template_redirect', [ $this, 'fix_paged_status' ], 1 );
		}

		parent::__construct( $args );
	}

	/*
	--------------------------------------------------------------------------
	Labels and settings.
	--------------------------------------------------------------------------
	*/

	/**
	 * Gets the "Follow" label.
	 *
	 * @return string
	 */
	public function get_follow_label() {
		$label = hpfv_get_option( HPFV_OPTION_PREFIX . 'follow_label', '' );

		return '' === (string) $label ? esc_html__( 'Follow', 'follow-vendors-for-hivepress' ) : (string) $label;
	}

	/**
	 * Gets the "Following" label.
	 *
	 * @return string
	 */
	public function get_following_label() {
		$label = hpfv_get_option( HPFV_OPTION_PREFIX . 'following_label', '' );

		return '' === (string) $label ? esc_html__( 'Following', 'follow-vendors-for-hivepress' ) : (string) $label;
	}

	/**
	 * Gets the account page title, which is also the menu item label.
	 *
	 * @return string
	 */
	public function get_page_title() {
		$title = hpfv_get_option( HPFV_OPTION_PREFIX . 'page_title', '' );

		return '' === (string) $title ? esc_html__( 'Followed Vendors', 'follow-vendors-for-hivepress' ) : (string) $title;
	}

	/**
	 * Checks whether Vendor pages and cards exist on this site at all.
	 *
	 * With "Enable vendor pages" off, core makes the Vendor post type non-public
	 * (components/class-vendor.php:420, core 1.7.31): no profile pages, no cards, every Vendor
	 * URL bounces home. Nothing of this plugin is worth showing then.
	 *
	 * @return bool
	 */
	public function is_display_enabled() {
		return (bool) get_option( 'hp_vendor_enable_display' );
	}

	/**
	 * Checks whether the toggle shows on the given surface.
	 *
	 * @param string $surface 'block' for Vendor cards, 'page' for Vendor pages.
	 * @return bool
	 */
	public function is_toggle_enabled( $surface ) {
		$location = hpfv_get_choice_option( HPFV_OPTION_PREFIX . 'toggle_location', [ 'both', 'block', 'page' ], 'both' );

		return 'both' === $location || $surface === $location;
	}

	/**
	 * Checks whether the follower count is shown.
	 *
	 * @return bool
	 */
	public function is_follower_count_enabled() {
		return (bool) hpfv_get_option( HPFV_OPTION_PREFIX . 'show_follower_count', false );
	}

	/*
	--------------------------------------------------------------------------
	Follow records.
	--------------------------------------------------------------------------
	*/

	/**
	 * Decides whether a Vendor can be followed by a user.
	 *
	 * One function, called by the toggle block, the REST action and the Action Bar items, so the
	 * three can never disagree about who may follow whom. A guest (user 0) passes the ownership
	 * test because there is nobody to own the Vendor, so the guest branch of the toggle still
	 * renders and opens the login pop-up.
	 *
	 * `instanceof`, never method_exists(): every model getter is magic, so method_exists() is
	 * always false on a model and a guard written that way leaves the feature dead everywhere
	 * (resources/hivepress-data.md, "Model objects and magic methods").
	 *
	 * @param mixed    $vendor Vendor object.
	 * @param int|null $user_id User ID, or null for the current user.
	 * @return bool
	 */
	public function can_follow( $vendor, $user_id = null ) {
		if ( ! $vendor instanceof \HivePress\Models\Vendor ) {
			return false;
		}

		if ( is_null( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		$user_id = absint( $user_id );

		$allowed = 'publish' === $vendor->get_status() && $this->is_display_enabled();

		if ( $allowed && $user_id && absint( $vendor->get_user__id() ) === $user_id ) {
			$allowed = false;
		}

		/**
		 * Filters whether a user may follow a Vendor.
		 *
		 * @hook hpfv_can_follow
		 * @param {bool} $allowed Whether following is allowed.
		 * @param {object} $vendor Vendor object.
		 * @param {int} $user_id User ID (0 for a guest).
		 * @return {bool} Whether following is allowed.
		 */
		return (bool) apply_filters( 'hpfv_can_follow', $allowed, $vendor, $user_id );
	}

	/**
	 * Checks whether the current user follows a Vendor.
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return bool
	 */
	public function is_following( $vendor_id ) {

		// A guest follows nobody, whatever the request context holds: the list is only ever built
		// for a signed-in user, and a check that read it for user 0 would answer for whoever built it.
		if ( ! is_user_logged_in() ) {
			return false;
		}

		return in_array( absint( $vendor_id ), array_map( 'absint', (array) hivepress()->request->get_context( self::CONTEXT_KEY, [] ) ), true );
	}

	/**
	 * Gets the current user's followed Vendor IDs, newest first.
	 *
	 * @return int[]
	 */
	public function get_follow_ids() {
		return array_map( 'absint', (array) hivepress()->request->get_context( self::CONTEXT_KEY, [] ) );
	}

	/**
	 * Builds and caches the current user's followed Vendor IDs, then puts them in the request
	 * context for the toggle, the menu and the Action Bar items to read.
	 *
	 * Copied from Favorites' set_favorites() (class-favorite.php:65-100). The cache key is the
	 * query arguments plus a `fields` marker, in the group core clears for us on every follow
	 * row change; only lists of 1,000 or fewer are cached, so a pathological account never
	 * writes a huge meta row.
	 */
	public function set_follow_ids() {

		// Check authentication.
		if ( ! is_user_logged_in() ) {
			return;
		}

		// Set query.
		$query = Models\Hpfv_Follow::query()->filter(
			[
				'user' => get_current_user_id(),
			]
		)->order( [ 'added_date' => 'desc' ] );

		$cache_key = array_merge( $query->get_args(), [ 'fields' => 'vendor_ids' ] );

		// Get cached IDs.
		$vendor_ids = hivepress()->cache->get_user_cache( get_current_user_id(), $cache_key, self::CACHE_GROUP );

		if ( is_null( $vendor_ids ) ) {

			// Get Vendor IDs.
			$vendor_ids = array_map(
				function( $follow ) {
					return absint( $follow->get_vendor__id() );
				},
				$query->get()->serialize()
			);

			// Cache IDs.
			if ( count( $vendor_ids ) <= 1000 ) {
				hivepress()->cache->set_user_cache( get_current_user_id(), $cache_key, self::CACHE_GROUP, $vendor_ids );
			}
		}

		// Set request context.
		hivepress()->request->set_context( self::CONTEXT_KEY, $vendor_ids );
	}

	/**
	 * Gets how many people follow a Vendor.
	 *
	 * Cached on the Vendor itself, in the group core clears whenever a follow row for that
	 * Vendor is created or deleted. The integer is cached, never false or '': a stored false
	 * reads back as a miss and re-runs the count for ever (resources/hivepress-data.md, "The
	 * cache component").
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return int
	 */
	public function get_follower_count( $vendor_id ) {
		$vendor_id = absint( $vendor_id );

		if ( ! $vendor_id ) {
			return 0;
		}

		$count = hivepress()->cache->get_post_cache( $vendor_id, 'follower_count', self::CACHE_GROUP );

		if ( is_null( $count ) ) {
			$count = (int) Models\Hpfv_Follow::query()->filter(
				[
					'vendor' => $vendor_id,
				]
			)->get_count();

			hivepress()->cache->set_post_cache( $vendor_id, 'follower_count', self::CACHE_GROUP, $count );
		}

		return absint( $count );
	}

	/**
	 * Gets the user IDs following a Vendor, in a stable order for batching.
	 *
	 * Deliberately uncached: the digest reads it in pages of 50 and a stale page would either
	 * repeat a follower or skip one. Ordered by comment ID so successive pages never overlap.
	 * `type` is passed explicitly because core hides non-public comment types from every
	 * comment query that does not name a type (components/class-comment.php:70-97).
	 *
	 * @param int $vendor_id Vendor ID.
	 * @param int $limit Page size.
	 * @param int $offset Rows to skip.
	 * @return int[]
	 */
	public function get_follower_user_ids( $vendor_id, $limit = 50, $offset = 0 ) {
		$comments = get_comments(
			[
				'type'    => 'hp_hpfv_follow',
				'post_id' => absint( $vendor_id ),
				'number'  => absint( $limit ),
				'offset'  => absint( $offset ),
				'orderby' => 'comment_ID',
				'order'   => 'ASC',
			]
		);

		$user_ids = [];

		foreach ( (array) $comments as $comment ) {
			$user_id = $comment instanceof \WP_Comment ? absint( $comment->user_id ) : 0;

			if ( $user_id && ! in_array( $user_id, $user_ids, true ) ) {
				$user_ids[] = $user_id;
			}
		}

		return $user_ids;
	}

	/**
	 * Deletes every follow belonging to a user.
	 *
	 * @param int $user_id User ID.
	 */
	public function delete_follows( $user_id ) {
		Models\Hpfv_Follow::query()->filter(
			[
				'user' => absint( $user_id ),
			]
		)->delete();
	}

	/*
	--------------------------------------------------------------------------
	Routes and menus.
	--------------------------------------------------------------------------
	*/

	/**
	 * Flushes the rewrite rules once per version.
	 *
	 * Core's own flush is just deleting the option and letting WordPress rebuild lazily
	 * (components/class-router.php:503-505), so this is cheap. Keyed on the version rather than
	 * an activation flag so an upgrade done by copying files over is covered too.
	 */
	public function maybe_flush_rewrite_rules() {
		$option = 'hp_' . HPFV_OPTION_PREFIX . 'version';

		if ( get_option( $option ) !== HPFV_VERSION ) {
			delete_option( 'rewrite_rules' );

			update_option( $option, HPFV_VERSION );
		}
	}

	/**
	 * Checks whether the current user follows at least one published Vendor.
	 *
	 * The account menu item and the page's redirect both hang off this, exactly as Favorites
	 * gates its item on at least one published favourite.
	 *
	 * @return bool
	 */
	public function has_followed_vendors() {
		$ids = $this->get_follow_ids();

		if ( ! $ids || ! $this->is_display_enabled() ) {
			return false;
		}

		return (bool) Models\Vendor::query()->filter(
			[
				'status' => 'publish',
				'id__in' => $ids,
			]
		)->get_first_id();
	}

	/**
	 * Adds the account menu item.
	 *
	 * _order 22: Favorites sits at 20 and Search Alerts at 25 (their components, verified), so
	 * the three "things I keep" pages sit together above core's Settings at 50.
	 *
	 * @param array $menu Menu arguments.
	 * @return array
	 */
	public function alter_account_menu( $menu ) {
		if ( $this->has_followed_vendors() ) {
			$menu['items']['hpfv_vendors_follow'] = [
				'route'  => 'hpfv_vendors_follow_page',
				'_order' => 22,
			];
		}

		return $menu;
	}

	/**
	 * Clears the 404 WordPress sets on page 2 and beyond of the account page.
	 *
	 * The rewrite rule sets `paged`, the main query finds no posts because the page is virtual,
	 * and WP::handle_404() fires before HivePress clears the flag on template_include, long
	 * after the status header went out. Only this plugin's own route is named, so genuinely
	 * missing pages still 404.
	 */
	public function fix_paged_status() {
		if ( ! is_404() ) {
			return;
		}

		if ( ! in_array( hivepress()->router->get_current_route_name(), [ 'hpfv_vendors_follow_page' ], true ) ) {
			return;
		}

		global $wp_query;

		$wp_query->is_404 = false;

		status_header( 200 );
	}

	/*
	--------------------------------------------------------------------------
	Templates.
	--------------------------------------------------------------------------
	*/

	/**
	 * Fixes the label and icon colour on the Vendor page button.
	 *
	 * The page toggle renders as `<a class="hp-vendor__action hp-vendor__action--follow button
	 * button--large button--secondary hp-link">` holding `<i class="hp-icon fas fa-user-plus">`
	 * and a label. All six official themes ship HiveTheme's `.hp-vendor__action i` and
	 * `.hp-link i { color: rgba(7,36,86,.25) }` and `.hp-link:hover i { color: #ffc107 }`
	 * (each theme's vendor/hivepress/hivetheme/assets/css/frontend.min.css), which beat the white
	 * the label inherits from `.button--secondary { color: #fff }`, so the glyph painted as a
	 * faint navy on the green button beside a white label. Three classes give (0,3,1), which wins
	 * over both theme rules, at rest and on hover, whichever stylesheet loads last. The icon
	 * cannot simply be dropped: core's toggle script (assets/js/frontend.js:14) reads the `<i>`
	 * element's class on click and throws when there is none, so the POST would never fire.
	 *
	 * The label needs the same defence (measured 26 Sept 2026, ListingHive, core 1.7.31). Core's
	 * `.hp-link:not(:hover){color:inherit}` (common.min.css) at (0,2,0) beats `.button--secondary`
	 * at rest, so the label took the page's dark text colour on the green button; on hover
	 * HiveTheme's `.hp-link:hover{color:#ffc107}` (0,2,0) and bare `a:hover{color:#1db1fc}`
	 * (0,1,1) turned it amber. `a` plus two classes, (0,2,1) at rest and (0,3,1) on hover and
	 * focus, keeps it white in every state, and only on the page button: the card toggle carries
	 * no `button--secondary`, so it is untouched.
	 *
	 * Attached to core's own front-end stylesheet rather than shipped as a file: a few rules do
	 * not earn an extra request, and the handle is registered on every front-end page.
	 */
	public function enqueue_styles() {
		if ( ! $this->is_display_enabled() || ! $this->is_toggle_enabled( 'page' ) ) {
			return;
		}

		wp_add_inline_style( 'hivepress-core-frontend', '.hp-vendor__action--follow.button.hp-link i{color:inherit}a.hp-vendor__action--follow.button--secondary,a.hp-vendor__action--follow.button--secondary:hover,a.hp-vendor__action--follow.button--secondary:focus{color:#fff}' );
	}

	/**
	 * Adds the toggle and the follower count to the Vendor card.
	 *
	 * Two merge_blocks() calls, never one map naming a parent and a descendant: a matched
	 * block's children are not visited in the same pass, so a single map targeting both
	 * `vendor_footer` and a block inside it silently drops the inner one
	 * (resources/hivepress-ui.md, "Template hooks").
	 *
	 * The container is `vendor_actions_primary` (templates/class-vendor-view-block.php:158-166,
	 * core 1.7.31), whose existing occupants are Messages at 5 and 10 and Requests at 20, so 30
	 * lands last. All six official themes keep the container; MeetingHive moves the page one
	 * into a widget and carries its children with it.
	 *
	 * @param array $template Template arguments.
	 * @return array
	 */
	public function alter_vendor_view_block( $template ) {
		if ( ! $this->is_display_enabled() ) {
			return $template;
		}

		if ( $this->is_toggle_enabled( 'block' ) ) {
			hivepress()->template->merge_blocks(
				$template,
				[
					'vendor_actions_primary' => [
						'blocks' => [
							'hpfv_follow_toggle' => [
								'type'       => 'hpfv_follow_toggle',
								'view'       => 'icon',
								'_order'     => 30,

								'attributes' => [
									'class' => [ 'hp-vendor__action', 'hp-vendor__action--follow' ],
								],
							],
						],
					],
				]
			);
		}

		if ( $this->is_follower_count_enabled() ) {
			hivepress()->template->merge_blocks(
				$template,
				[
					'vendor_details_primary' => [
						'blocks' => [
							'hpfv_follower_count' => [
								'type'   => 'hpfv_follower_count',
								'_order' => 15,
							],
						],
					],
				]
			);
		}

		return $template;
	}

	/**
	 * Adds the toggle and the follower count to the Vendor page.
	 *
	 * On the page the actions box holds real buttons (Messages' Send Message is
	 * `button button--large button--primary alt`, Requests' is `button button--large`), so the
	 * toggle carries the same button classes and lines up with its neighbours instead of sitting
	 * as a bare link beside them (resources/hivepress-ui.md, "Matching a core button").
	 *
	 * @param array $template Template arguments.
	 * @return array
	 */
	public function alter_vendor_view_page( $template ) {
		if ( ! $this->is_display_enabled() ) {
			return $template;
		}

		if ( $this->is_toggle_enabled( 'page' ) ) {
			hivepress()->template->merge_blocks(
				$template,
				[
					'vendor_actions_primary' => [
						'blocks' => [
							'hpfv_follow_toggle' => [
								'type'       => 'hpfv_follow_toggle',
								'_order'     => 30,

								'attributes' => [
									'class' => [ 'hp-vendor__action', 'hp-vendor__action--follow', 'button', 'button--large', 'button--secondary' ],
								],
							],
						],
					],
				]
			);
		}

		if ( $this->is_follower_count_enabled() ) {
			hivepress()->template->merge_blocks(
				$template,
				[
					'vendor_details_primary' => [
						'blocks' => [
							'hpfv_follower_count' => [
								'type'   => 'hpfv_follower_count',
								'_order' => 15,
							],
						],
					],
				]
			);
		}

		return $template;
	}
}
