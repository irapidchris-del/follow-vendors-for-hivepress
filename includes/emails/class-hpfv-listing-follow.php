<?php
/**
 * New Listings from a followed Vendor email.
 *
 * Registered by HivePress itself: core globs includes/emails/*.php across every registered
 * extension and derives the email name from the file name, so this file becomes the email
 * "hpfv_listing_follow". Giving the class a truthy `label` is what makes it editable by the site
 * owner under HivePress > Emails: the Email component then looks for an hp_email post whose slug
 * matches that name and swaps in its subject and body (components/class-email.php:59-91, core
 * 1.7.31).
 *
 * Token values are substituted RAW (helpers.php:319-387, replace_tokens() does a plain
 * str_replace), the default body is only passed through make_clickable() (emails/class-email.php:
 * 133-141) and the email part echoes the body as is (templates/email/email-content.php). So the
 * %listings% token is built by the sending component as ready-made, escaped HTML: one line per
 * Listing, each an anchor around an escaped title, separated by <br>. Nothing in the chain adds
 * paragraphs, so <br> is the right separator.
 *
 * @package Follow_Vendors
 */

namespace HivePress\Emails;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Sent to a follower when a Vendor they follow publishes new Listings.
 *
 * @class Hpfv_Listing_Follow
 */
class Hpfv_Listing_Follow extends Email {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Email meta.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label'       => esc_html__( 'New Listings from a Followed Vendor', 'follow-vendors-for-hivepress' ),
				'description' => esc_html__( 'This email is sent to users who follow a Vendor when that Vendor publishes new Listings. One email covers every Listing published within the quiet period set under Settings, Follow Vendors.', 'follow-vendors-for-hivepress' ),
				'recipient'   => hivepress()->translator->get_string( 'user' ),
				'tokens'      => [ 'user_name', 'vendor_name', 'vendor_url', 'listings', 'listing_count', 'followed_vendors_url', 'user', 'vendor' ],
			],
			$meta
		);

		parent::init( $meta );
	}

	/**
	 * Class constructor.
	 *
	 * The tokens are passed as arguments rather than written into the string. The I18n sniff reads
	 * "%u" and "%v" inside "%user_name%" as printf placeholders and its fixer renumbers them into
	 * tokens that match nothing (resources/wordpress-php-notes.md, "phpcbf rewrites %token% inside
	 * a translatable string"). Kept out of the string, they also stay out of the POT, where a
	 * translator could localise them into nothing.
	 *
	 * @param array $args Email arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_arrays(
			[
				'subject' => sprintf(
					/* translators: %s: the Vendor's name. Filled in automatically. */
					esc_html__( 'New Listings from %s', 'follow-vendors-for-hivepress' ),
					'%vendor_name%'
				),

				'body'    => hp\sanitize_html(
					sprintf(
						/* translators: 1: the follower's name, 2: the Vendor's name, 3: the list of new Listings, 4: the link to the follower's Followed Vendors page. All four are filled in automatically. */
						__( 'Hi, %1$s! %2$s, a Vendor you follow, has published new Listings: %3$s You can stop these emails by unfollowing the Vendor on the site or from your Followed Vendors page: %4$s', 'follow-vendors-for-hivepress' ),
						'%user_name%',
						'%vendor_name%',
						'%listings%',
						'%followed_vendors_url%'
					)
				),
			],
			$args
		);

		parent::__construct( $args );
	}
}
