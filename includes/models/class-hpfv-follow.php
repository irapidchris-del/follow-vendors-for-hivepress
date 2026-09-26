<?php
/**
 * Follow model.
 *
 * One row in wp_comments per "user U follows Vendor V": the user in `user_id`, the Vendor's post
 * ID in `comment_post_ID`, the moment in `comment_date`. This is exactly how the Favorites
 * extension stores a favourite (hivepress-favorites/includes/models/class-favorite.php), with the
 * Listing swapped for a Vendor.
 *
 * THE CLASS NAME IS LOAD-BEARING AND SHORT ON PURPOSE. HivePress derives the comment type from the
 * class name, "hp_" + the lower-case class name (models/class-comment.php:29, core 1.7.31), and
 * `wp_comments.comment_type` is varchar(20). Anything longer than 17 characters is truncated on
 * write and then never matches on read, because Comment::get() compares the full string (:56), so
 * every row saves fine and is invisible for ever. `Hpfv_Follow` gives `hp_hpfv_follow`, 14
 * characters (resources/hivepress-data.md, "A comment model's class name has 17 characters to play
 * with"). Do not rename it to something more descriptive.
 *
 * The prefix is what stops a collision with a future official extension: core instantiates
 * \HivePress\Models\{Filename} across every registered extension and loads exactly one file per
 * class name, so a second plugin shipping class-follow.php would silently stop one of them loading.
 *
 * @package Follow_Vendors
 */

namespace HivePress\Models;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * A user following a Vendor.
 *
 * Field getters are magic (Model::__call), so the ones this plugin calls are declared for static
 * analysis. Related-ID getters follow core's `get_{relation}__id()` shape.
 *
 * @method int|null get_user__id()
 * @method int|null get_vendor__id()
 * @method string|null get_added_date()
 * @method static \HivePress\Queries\Query query()
 *
 * @class Hpfv_Follow
 */
class Hpfv_Follow extends Comment {

	/**
	 * Class constructor.
	 *
	 * @param array $args Model arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_arrays(
			[
				'fields' => [
					'added_date' => [
						'type'   => 'date',
						'format' => 'Y-m-d H:i:s',
						'_alias' => 'comment_date',
					],

					'user'       => [
						'type'      => 'number',
						'min_value' => 1,
						'required'  => true,
						'_alias'    => 'user_id',
						'_model'    => 'user',
					],

					'vendor'     => [
						'type'      => 'number',
						'min_value' => 1,
						'required'  => true,
						'_alias'    => 'comment_post_ID',
						'_model'    => 'vendor',
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}
}
