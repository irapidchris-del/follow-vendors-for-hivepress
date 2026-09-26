<?php
/**
 * Follow toggle block.
 *
 * Shaped like the Favorites extension's toggle (hivepress-favorites/includes/blocks/
 * class-favorite-toggle.php). Everything that makes it work is inherited from core's Toggle
 * (blocks/class-toggle.php:72-123, core 1.7.31): a signed-in visitor gets href="#",
 * data-component="toggle", the REST URL in data-url and the opposite state's icon and caption in
 * data attributes; a guest gets href="#user_login_modal", which core's own script turns into the
 * sign-in pop-up. Core's front-end script (assets/js/frontend.js:7-49) swaps the icon and caption
 * and POSTs with the REST nonce, so this block ships no script of its own.
 *
 * @package Follow_Vendors
 */

namespace HivePress\Blocks;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Follow toggle block class.
 *
 * @class Hpfv_Follow_Toggle
 */
class Hpfv_Follow_Toggle extends Toggle {

	/**
	 * Whether the toggle may render for the Vendor in context.
	 *
	 * @var bool
	 */
	protected $allowed = false;

	/**
	 * Class constructor.
	 *
	 * The `states` form is used rather than `icon` plus `captions` because it carries a different
	 * glyph for each state (Toggle::boot() reads both columns out of it, :81-84). Both glyphs are
	 * in core's Font Awesome 5 bundle: `user-plus` and `user-check` are in configs/icons.php
	 * (:960, :947), the curated list core itself vouches for.
	 *
	 * @param array $args Block arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_arrays(
			[
				'states' => [
					[
						'icon'    => 'user-plus',
						'caption' => hivepress()->hpfv_follow->get_follow_label(),
					],
					[
						'icon'    => 'user-check',
						'caption' => hivepress()->hpfv_follow->get_following_label(),
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}

	/**
	 * Bootstraps block properties.
	 */
	protected function boot() {

		// Get Vendor.
		$vendor = $this->get_context( 'vendor' );

		if ( hivepress()->hpfv_follow->can_follow( $vendor ) ) {
			$this->allowed = true;

			// Set URL.
			$this->url = hivepress()->router->get_url(
				'hpfv_vendor_follow_action',
				[
					'vendor_id' => $vendor->get_id(),
				]
			);

			// Set active flag.
			if ( hivepress()->hpfv_follow->is_following( $vendor->get_id() ) ) {
				$this->active = true;
			}
		}

		parent::boot();
	}

	/**
	 * Renders block HTML.
	 *
	 * Nothing at all for the Vendor's own owner, an unpublished Vendor, or a site with Vendor
	 * pages switched off: a control that could only ever answer 403 is worse than no control.
	 *
	 * @return string
	 */
	public function render() {
		if ( ! $this->allowed ) {
			return '';
		}

		return parent::render();
	}
}
