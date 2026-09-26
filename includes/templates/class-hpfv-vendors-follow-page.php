<?php
/**
 * Followed Vendors account page template.
 *
 * The Favorites extension's account page (hivepress-favorites/includes/templates/
 * class-listings-favorite-page.php) with the Listings block swapped for the Vendors block. The
 * controller puts the followed-Vendor query into the `post_query` request context and passes a
 * `vendors` key in the template context, which is what makes the Vendors block read the main
 * query instead of running its own (blocks/class-vendors.php:183, core 1.7.31).
 *
 * The template name HivePress derives from this class is `hpfv_vendors_follow_page`, which is
 * what the controller renders.
 *
 * @package Follow_Vendors
 */

namespace HivePress\Templates;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Followed Vendors page template class.
 *
 * @class Hpfv_Vendors_Follow_Page
 */
class Hpfv_Vendors_Follow_Page extends User_Account_Page {

	/**
	 * Class constructor.
	 *
	 * @param array $args Template arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_trees(
			[
				'blocks' => [
					'page_content' => [
						'blocks' => [
							'vendors'           => [
								'type'    => 'vendors',
								'columns' => 2,
								'_order'  => 10,
							],

							'vendor_pagination' => [
								'type'   => 'part',
								'path'   => 'page/pagination',
								'_order' => 20,
							],
						],
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}
}
