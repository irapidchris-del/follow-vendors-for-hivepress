<?php
/**
 * Follower count block.
 *
 * A one-line detail under the Vendor's name, injected into `vendor_details_primary` on cards and
 * pages when the setting is on. Renders nothing for a Vendor nobody follows, so a site never
 * shows a row of "0 followers" on activation.
 *
 * `hp-vendor__details` rows on the core templates are small icon-plus-text parts (the registered
 * date is `<div class="hp-vendor__registered-date"><i class="hp-icon fas fa-calendar"></i>...`),
 * so this reuses that shape and adds no CSS.
 *
 * @package Follow_Vendors
 */

namespace HivePress\Blocks;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Follower count block class.
 *
 * @class Hpfv_Follower_Count
 */
class Hpfv_Follower_Count extends Block {

	/**
	 * Renders block HTML.
	 *
	 * @return string
	 */
	public function render() {
		$vendor = $this->get_context( 'vendor' );

		if ( ! $vendor instanceof \HivePress\Models\Vendor ) {
			return '';
		}

		$count = hivepress()->hpfv_follow->get_follower_count( $vendor->get_id() );

		if ( ! $count ) {
			return '';
		}

		/* translators: %s: number of followers. */
		$text = sprintf( _n( '%s follower', '%s followers', $count, 'follow-vendors-for-hivepress' ), number_format_i18n( $count ) );

		return '<div class="hp-vendor__follower-count"><i class="hp-icon fas fa-users"></i><span>' . esc_html( $text ) . '</span></div>';
	}
}
