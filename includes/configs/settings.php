<?php
/**
 * Settings configuration: the "Follow Vendors" tab under HivePress > Settings.
 *
 * This file keeps core's fixed name on purpose: HivePress looks a config up by bare file name
 * (class-core.php:417) and merges every extension's settings.php into one array. Every field name
 * carries HPFV_OPTION_PREFIX, so its option lands as hp_follow_vendors_for_hivepress_* and
 * uninstall.php can sweep the whole family by prefix.
 *
 * Reading these back: never with get_option() directly. A checkbox that was never saved is absent
 * (use the default), one that was unticked is '' (off), and a cleared number is '' too. The three
 * helpers in the main file, hpfv_get_option(), hpfv_get_number_option() and hpfv_get_choice_option(),
 * are what every reader goes through (resources/hivepress-settings.md, "The stored-empty trap").
 *
 * Product names (HivePress, Action Bar for HivePress) are passed into the
 * sentences as placeholders rather than written into the msgids, so a translator localises the
 * sentence and never the name (resources/hivepress-settings.md, "Copy is the boundary").
 *
 * The single-choice field is a radio set rather than a select: a select always grows a bare "-" entry
 * above a closed set of options and reads as one more choice (resources/hivepress-settings.md,
 * "A single-choice select always grows a bare '-' option").
 *
 * @package Follow_Vendors
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

return [
	'follow_vendors' => [
		'title'    => esc_html__( 'Follow Vendors', 'follow-vendors-for-hivepress' ),
		'_order'   => 220,

		'sections' => [

			/*
			 * The three label fields carry a `placeholder`, never a `default`. HivePress writes a
			 * field's default into the database with add_option() when HivePress itself activates or
			 * updates (components/class-admin.php:265, core 1.7.31) and posts it back on the first
			 * save of the tab, so a translated default would be STORED, in whichever locale was
			 * loaded at the time, and a later Loco Translate rewording of "Follow" would silently
			 * stop applying (resources/hivepress-settings.md, "Settings copy standards": never mix
			 * the two on one string). An empty box shows the default wording, stores nothing, and
			 * every reader falls back to the translatable string.
			 */
			'labels'     => [
				'title'       => esc_html__( 'Labels', 'follow-vendors-for-hivepress' ),
				'description' => esc_html__( 'The words shown on the follow button and on the account page. These are content settings for this site; leave a box empty to keep the default wording, which, like every other piece of wording in the plugin, can be reworded with a translation plugin such as Loco Translate.', 'follow-vendors-for-hivepress' ),
				'_order'      => 10,

				'fields'      => [
					HPFV_OPTION_PREFIX . 'follow_label'    => [
						'label'       => esc_html__( 'Follow Label', 'follow-vendors-for-hivepress' ),
						'description' => esc_html__( 'Shown on the button while the visitor is not yet following the Vendor.', 'follow-vendors-for-hivepress' ),
						'type'        => 'text',
						'max_length'  => 64,
						'placeholder' => esc_html__( 'Follow', 'follow-vendors-for-hivepress' ),
						'_order'      => 10,
					],

					HPFV_OPTION_PREFIX . 'following_label' => [
						'label'       => esc_html__( 'Following Label', 'follow-vendors-for-hivepress' ),
						'description' => esc_html__( 'Shown on the button once the visitor follows the Vendor. Clicking it again unfollows.', 'follow-vendors-for-hivepress' ),
						'type'        => 'text',
						'max_length'  => 64,
						'placeholder' => esc_html__( 'Following', 'follow-vendors-for-hivepress' ),
						'_order'      => 20,
					],

					HPFV_OPTION_PREFIX . 'page_title'      => [
						'label'       => esc_html__( 'Account Page Title', 'follow-vendors-for-hivepress' ),
						'description' => esc_html__( 'The name of the account menu item and the heading of the page that lists the Vendors a user follows.', 'follow-vendors-for-hivepress' ),
						'type'        => 'text',
						'max_length'  => 128,
						'placeholder' => esc_html__( 'Followed Vendors', 'follow-vendors-for-hivepress' ),
						'_order'      => 30,
					],
				],
			],

			'display'    => [
				'title'       => esc_html__( 'Where the Follow Button Shows', 'follow-vendors-for-hivepress' ),
				'description' => sprintf(
					/* translators: 1: the HivePress product name, 2: the label of the core "Enable vendor pages" setting. */
					esc_html__( 'The button sits beside the Send Message control on Vendor cards and in the actions box on Vendor pages. Vendor cards and pages only exist while "%2$s" is ticked under %1$s, Settings, Vendors; with it off nothing from this plugin is shown. If you have customised the Vendor page in the template editor and removed the actions box, the button has nowhere to go and is not shown there either.', 'follow-vendors-for-hivepress' ),
					'HivePress',
					esc_html__( 'Enable vendor pages', 'follow-vendors-for-hivepress' )
				),
				'_order'      => 20,

				'fields'      => [
					HPFV_OPTION_PREFIX . 'toggle_location' => [
						'label'       => esc_html__( 'Show the Button On', 'follow-vendors-for-hivepress' ),
						'description' => esc_html__( 'Vendor cards are the small boxes in Vendor lists and search results; the Vendor page is the Vendor\'s own profile page.', 'follow-vendors-for-hivepress' ),
						'type'        => 'radio',
						'default'     => 'both',
						'_order'      => 10,

						'options'     => [
							'both'  => esc_html__( 'Vendor cards and Vendor pages', 'follow-vendors-for-hivepress' ),
							'block' => esc_html__( 'Vendor cards only', 'follow-vendors-for-hivepress' ),
							'page'  => esc_html__( 'Vendor pages only', 'follow-vendors-for-hivepress' ),
						],
					],

					HPFV_OPTION_PREFIX . 'show_follower_count' => [
						'label'       => esc_html__( 'Follower Count', 'follow-vendors-for-hivepress' ),
						'caption'     => esc_html__( 'Show how many people follow a Vendor, under the Vendor\'s name', 'follow-vendors-for-hivepress' ),
						'description' => esc_html__( 'Appears on Vendor cards and pages once at least one person follows the Vendor. Nothing is shown for a Vendor with no followers.', 'follow-vendors-for-hivepress' ),
						'type'        => 'checkbox',
						'_order'      => 20,
					],
				],
			],

			'emails'     => [
				'title'       => esc_html__( 'Emails to Followers', 'follow-vendors-for-hivepress' ),
				'description' => sprintf(
					/* translators: 1: the HivePress product name, 2: the name of the email under HivePress > Emails. */
					esc_html__( 'When a followed Vendor publishes new Listings, each follower is emailed a list of them. Listings published inside the quiet period are gathered into one email, so a Vendor adding five Listings in a row sends one message, not five. The wording can be changed under %1$s, Emails, "%2$s". Renewing, hiding or unhiding a Listing never sends another email.', 'follow-vendors-for-hivepress' ),
					'HivePress',
					esc_html__( 'New Listings from a Followed Vendor', 'follow-vendors-for-hivepress' )
				),
				'_order'      => 30,

				'fields'      => [
					HPFV_OPTION_PREFIX . 'enable_email' => [
						'label'       => esc_html__( 'New Listing Emails', 'follow-vendors-for-hivepress' ),
						'caption'     => esc_html__( 'Email followers when a Vendor they follow publishes new Listings', 'follow-vendors-for-hivepress' ),
						'description' => esc_html__( 'Untick to stop these emails altogether. Following still works; followers simply are not told.', 'follow-vendors-for-hivepress' ),
						'type'        => 'checkbox',
						'default'     => true,
						'_order'      => 10,
					],

					HPFV_OPTION_PREFIX . 'email_delay'  => [
						'label'       => esc_html__( 'Quiet Period (minutes)', 'follow-vendors-for-hivepress' ),
						'description' => esc_html__( 'Listings published inside this window go out as one email. 10 minutes suits most sites; raise it if your Vendors tend to publish in batches over a longer stretch. Between 1 and 1440 (one day).', 'follow-vendors-for-hivepress' ),
						'type'        => 'number',
						'min_value'   => 1,
						'max_value'   => 1440,
						'default'     => 10,
						'_order'      => 20,
					],
				],
			],

			'action_bar' => [
				'title'       => esc_html__( 'Action Bar Items', 'follow-vendors-for-hivepress' ),
				'description' => sprintf(
					/* translators: %s: the Action Bar for HivePress plugin name. */
					esc_html__( 'With %s active, a Follow item is added after the items you have set up on that plugin\'s tab, on Vendor pages and on Listing pages for the Listing\'s Vendor. It is added to whichever bar shows on that page, so a bar that already has five items becomes six there; the Listing page and Vendor page bars in that plugin are the tidy way to make room. The Save and Alert buttons that used to be set up here now live on that plugin\'s own tab, under Quick Actions, so they work whether or not this plugin is installed.', 'follow-vendors-for-hivepress' ),
					'Action Bar for HivePress'
				),
				'_order'      => 40,

				'fields'      => [
					HPFV_OPTION_PREFIX . 'bar_follow' => [
						'label'       => esc_html__( 'Follow Item', 'follow-vendors-for-hivepress' ),
						'caption'     => esc_html__( 'Add a Follow item on Vendor pages, and on Listing pages for the Listing\'s Vendor', 'follow-vendors-for-hivepress' ),
						'description' => esc_html__( 'The item reads Follow or Following to match the visitor\'s current state, and switches without reloading the page.', 'follow-vendors-for-hivepress' ),
						'type'        => 'checkbox',
						'default'     => true,
						'_order'      => 10,
					],

				],
			],

			'removal'    => [
				'title'       => esc_html__( 'Removing the Plugin', 'follow-vendors-for-hivepress' ),
				'description' => esc_html__( 'Your settings and everyone\'s follows are kept if you delete this plugin, whatever the delete screen\'s generic warning says, unless you tick the box below. Deactivating the plugin never removes anything either; while it is deactivated the follow records simply sit unused (they appear on the Comments screen as "hp_hpfv_follow" entries until the plugin is active again).', 'follow-vendors-for-hivepress' ),
				'_order'      => 100,

				'fields'      => [
					HPFV_OPTION_PREFIX . 'delete_data' => [
						'label'       => esc_html__( 'Delete All Data', 'follow-vendors-for-hivepress' ),
						'caption'     => esc_html__( 'Delete this plugin\'s settings and every follow when the plugin is deleted', 'follow-vendors-for-hivepress' ),
						'description' => sprintf(
							/* translators: %s: the HivePress product name. */
							esc_html__( 'Removes every follow, the settings on this tab, the plugin\'s own record of which Listings have been announced, and any reworded copy of its email under %s, Emails. This cannot be undone.', 'follow-vendors-for-hivepress' ),
							'HivePress'
						),
						'type'        => 'checkbox',
						'_order'      => 10,
					],
				],
			],
		],
	],
];
