<?php
/**
 * Follow Action Bar component: the Follow item for Action Bar for HivePress.
 *
 * Only the Follow item. The Save and search-alert items this component used to add moved into
 * Action Bar for HivePress itself in its 1.8.0 (its Quick Actions section), because they act on
 * Listings and searches rather than on Vendors and so had no reason to need this plugin installed.
 * Nothing here may add them again: two plugins adding the same button shows the visitor two.
 *
 * Action Bar (1.7.2) builds its items, caps the owner's rows at five, and then applies
 * `hivepress/v1/action_bar/items` (includes/components/class-hpab-action-bar.php:1670). Whatever
 * comes back is normalised to exactly these keys: link, url, icon, label, style, badge,
 * badge_count, bell, external (:1673-1697), so nothing else survives and the bar itself needs no
 * change. Each item's href is a bare fragment (`#hpfv-follow-12`), which the bar's own
 * current-page highlighter skips (assets/js/frontend.js:150) and which this plugin's script uses
 * to find the item. A signed-out visitor gets `link => 'auth_modal'` with the real login page as
 * the href: the bar renders that as `data-hpab-auth-modal` and its script upgrades the click to
 * HivePress's sign-in pop-up (:3079, frontend.js:206-218).
 *
 * Registered on `init`, never at construction: class_exists() on a HivePress class autoloads it
 * and runs its static init(), which translates, and doing that at plugins_loaded logs
 * "translation loading was triggered too early" on every request of a translated site
 * (resources/hivepress-framework.md, "Boot order and extension registration").
 *
 * Every gate is the route NAME, never a request context alone: the `vendor` context is also set
 * on the Vendor's own edit screens (resources/hivepress-framework.md, "A request context is not a
 * page check").
 *
 * @package Follow_Vendors
 */

namespace HivePress\Components;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Follow Action Bar component class.
 *
 * @class Hpfv_Follow_Action_Bar
 */
final class Hpfv_Follow_Action_Bar extends Component {

	/**
	 * The items built for this request, keyed by handle, or null before they are built.
	 *
	 * @var array|null
	 */
	protected $items = null;

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {
		if ( ! is_admin() ) {
			add_action( 'init', [ $this, 'register' ], 50 );
		}

		parent::__construct( $args );
	}

	/**
	 * Registers the integration when Action Bar for HivePress is active.
	 */
	public function register() {
		if ( ! $this->has_action_bar() ) {
			return;
		}

		add_filter( 'hivepress/v1/action_bar/items', [ $this, 'add_items' ], 10, 2 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
	}

	/**
	 * Checks for Action Bar for HivePress.
	 *
	 * @return bool
	 */
	public function has_action_bar() {
		return class_exists( '\HivePress\Components\Hpab_Action_Bar' );
	}

	/**
	 * Checks one of the on/off item settings.
	 *
	 * @param string $name Setting name without the prefix.
	 * @return bool
	 */
	protected function is_item_enabled( $name ) {
		return (bool) hpfv_get_option( HPFV_OPTION_PREFIX . $name, true );
	}

	/**
	 * Adds the items to the bar.
	 *
	 * @param array  $items Item arguments.
	 * @param string $bar Bar name.
	 * @return array
	 */
	public function add_items( $items, $bar ) {
		$items = (array) $items;

		foreach ( $this->get_items() as $item ) {
			$items[] = $item['bar'];
		}

		/**
		 * Filters the items this plugin appends to the Action Bar, after they are built.
		 *
		 * @hook hpfv_action_bar_items
		 * @param {array} $items Item arguments, in the bar's own shape.
		 * @param {string} $route Current route name.
		 * @param {string} $bar Bar name.
		 * @return {array} Item arguments.
		 */
		return (array) apply_filters( 'hpfv_action_bar_items', $items, (string) hivepress()->router->get_current_route_name(), (string) $bar );
	}

	/**
	 * Builds the items once per request.
	 *
	 * Built once because the enqueue decision (wp_enqueue_scripts) and the filter (wp_footer)
	 * must agree about which items exist. Each entry holds the bar-shaped item under `bar` and,
	 * for a signed-in visitor, the script's data under `data`.
	 *
	 * @return array
	 */
	public function get_items() {
		if ( ! is_null( $this->items ) ) {
			return $this->items;
		}

		$this->items = $this->build_items( (string) hivepress()->router->get_current_route_name() );

		return $this->items;
	}

	/**
	 * Builds the items for a route.
	 *
	 * Public and route-driven so the runtime check can exercise it without a real HTTP request.
	 *
	 * @param string $route Route name.
	 * @return array
	 */
	public function build_items( $route ) {
		$items = [];

		// Follow: the Vendor page, and the Listing page for the Listing's Vendor.
		if ( $this->is_item_enabled( 'bar_follow' ) && ( 'vendor_view_page' === $route || 'listing_view_page' === $route ) ) {
			$item = $this->build_follow_item( hivepress()->request->get_context( 'vendor' ) );

			if ( $item ) {
				$items[ $item['handle'] ] = $item;
			}
		}

		return $items;
	}

	/**
	 * Builds the follow item.
	 *
	 * @param mixed $vendor Vendor object from the request context.
	 * @return array|null
	 */
	protected function build_follow_item( $vendor ) {
		if ( ! hivepress()->hpfv_follow->can_follow( $vendor ) ) {
			return null;
		}

		$active = is_user_logged_in() && hivepress()->hpfv_follow->is_following( $vendor->get_id() );

		return $this->build_item(
			'hpfv-follow-' . $vendor->get_id(),
			hivepress()->router->get_url( 'hpfv_vendor_follow_action', [ 'vendor_id' => $vendor->get_id() ] ),
			$active,
			[ hivepress()->hpfv_follow->get_follow_label(), hivepress()->hpfv_follow->get_following_label() ],
			[ 'fas fa-user-plus', 'fas fa-user-check' ]
		);
	}

	/**
	 * Builds one item in the bar's shape, plus the script's data for a signed-in visitor.
	 *
	 * The current state's icon and label are server-rendered, so a slow phone never shows
	 * "Follow" for a followed Vendor. `label` is always set, so the bar's "Menu item" aria
	 * fallback is never reached.
	 *
	 * @param string   $handle Fragment handle without the "#".
	 * @param string   $url REST URL to POST to.
	 * @param bool     $active Whether the "on" state applies.
	 * @param string[] $labels Off and on labels.
	 * @param string[] $icons Off and on icon class strings.
	 * @return array
	 */
	protected function build_item( $handle, $url, $active, $labels, $icons ) {
		$state = $active ? 1 : 0;

		if ( ! is_user_logged_in() ) {
			return [
				'handle' => $handle,
				'data'   => null,
				'bar'    => [
					'link'  => 'auth_modal',
					'url'   => hivepress()->router->get_return_url( 'user_login_page' ),
					'icon'  => $icons[0],
					'label' => $labels[0],
					'style' => 'default',
				],
			];
		}

		return [
			'handle' => $handle,
			'data'   => [
				'url'    => $url,
				'active' => (bool) $active,
				'labels' => array_values( $labels ),
				'icons'  => array_values( $icons ),
				'paths'  => $this->get_icon_paths( $icons ),
			],
			'bar'    => [
				'link'  => '',
				'url'   => '#' . $handle,
				'icon'  => $icons[ $state ],
				'label' => $labels[ $state ],
				'style' => 'default',
			],
		];
	}

	/**
	 * Gets the inline-SVG pairs for two icons, when the shared icon library is loaded.
	 *
	 * Action Bar draws its icons as inline SVG through FAFH (render_icon(), class-hpab-action-bar.php:
	 * 2353-2373), so a class swap cannot change the glyph on the page; the script rebuilds the
	 * <svg> from a "viewBox|path" pair instead. The leading backslash is load-bearing: inside the
	 * HivePress\Components namespace a bare FAFH:: would resolve to a class that does not exist
	 * (resources/hivepress-ui.md, "FAFH:: inside a namespaced file"). Nothing of the library is
	 * bundled here: the bar bundles it, and this plugin only draws inside a bar the bar draws.
	 *
	 * @param string[] $icons Icon class strings.
	 * @return string[] Pairs, or empty strings when unavailable.
	 */
	protected function get_icon_paths( $icons ) {
		$paths = [];

		foreach ( $icons as $icon ) {
			$pair = '';

			if ( class_exists( 'FAFH' ) ) {
				$pair = (string) \FAFH::pair( $icon );
			}

			$paths[] = $pair;
		}

		return $paths;
	}

	/**
	 * Enqueues the bar script, only when there is a signed-in item for it to drive.
	 *
	 * Runs after Action Bar's own enqueue (both on wp_enqueue_scripts at 10; this filter was
	 * registered later, on init), so its handle is either enqueued by now or never will be. A
	 * bar that is switched off, hidden on this page or empty enqueues nothing, and then neither
	 * does this.
	 */
	public function enqueue_scripts() {
		if ( ! wp_script_is( 'hivepress-action-bar-frontend', 'enqueued' ) ) {
			return;
		}

		$data = [];

		foreach ( $this->get_items() as $handle => $item ) {
			if ( ! empty( $item['data'] ) ) {
				$data[ $handle ] = $item['data'];
			}
		}

		if ( ! $data ) {
			return;
		}

		$path = plugin_dir_path( HPFV_FILE ) . 'assets/js/frontend.js';

		wp_enqueue_script(
			'hpfv-frontend',
			plugin_dir_url( HPFV_FILE ) . 'assets/js/frontend.js',
			[ 'hivepress-action-bar-frontend' ],
			HPFV_VERSION . '.' . (int) filemtime( $path ),
			true
		);

		// Nested one level: wp_localize_script() casts every top-level scalar to a string, so a
		// bare boolean would arrive as "" or "1" (resources/wordpress-php-notes.md).
		wp_localize_script(
			'hpfv-frontend',
			'hpfvData',
			[
				'items' => $data,
			]
		);
	}
}
