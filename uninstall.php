<?php
/**
 * Uninstall routine.
 *
 * Runs when the plugin is deleted from the Plugins screen, never on deactivation, so switching the
 * plugin off temporarily loses nothing at all.
 *
 * **Deleting the plugin keeps the owner's settings and everyone's follows by default.** Someone
 * who deletes the plugin by accident, or removes it to install a clean copy, gets everything back
 * when they reinstall. Destruction is opt-in, through the "Delete all data" checkbox on the
 * plugin's settings tab, and is never a surprise.
 *
 * There is no way to ask at delete time. The confirmation form in wp-admin/plugins.php:400-412 is
 * hard-coded with no do_action or apply_filters inside it, so a checkbox cannot be added to that
 * screen; the setting has to live on our own tab. Worse, WordPress prints "(will also delete its
 * data)" on that screen whenever an uninstall.php exists at all (wp-admin/plugins.php:379, WP 7.1),
 * whatever the file actually does, so the setting's own description tells the owner that the core
 * warning does not apply unless they ticked the box.
 *
 * @package Follow_Vendors
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Exit unless WordPress is genuinely uninstalling this plugin.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * The option prefix, repeated here rather than read from the main plugin file, because uninstall.php
 * runs on its own and that file is never loaded. Must match HPFV_OPTION_PREFIX with "hp_" in front.
 */
$hpfv_prefix = 'hp_follow_vendors_for_hivepress_';

/**
 * Removes this plugin's traces from the site that is current when it is called.
 *
 * Written as a function so that on a network it can run once per site: the uninstaller runs in
 * the context of one site only.
 *
 * @param string $prefix Option prefix.
 * @return void
 */
function hpfv_uninstall_site( $prefix ) {
	global $wpdb;

	// Read the owner's choice first, before anything is touched.
	$delete_all = ! empty( get_option( $prefix . 'delete_data' ) );

	/*
	 * -------------------------------------------------------------------------------------------------
	 * Always cleaned, whichever way the setting is set.
	 * -------------------------------------------------------------------------------------------------
	 */

	// The updater's cached release lookup. A site transient lives under its own prefix, so neither the
	// option sweep below nor a plain delete_option() would ever reach it.
	delete_site_transient( 'follow_vendors_for_hivepress_release' );

	/*
	 * The updater's other two site transients and its background job.
	 *
	 * All three are regenerable runtime state belonging to the update check, not the owner's
	 * configuration, so they go unconditionally alongside the release cache above. The scheduled
	 * refresh is worse than debris: it is a job whose callback no longer exists. Unscheduled from both
	 * places it can be, because the refresh is queued through HivePress's scheduler (Action Scheduler)
	 * when HivePress is present and through WP-Cron when it is not.
	 */
	delete_site_transient( 'follow_vendors_for_hivepress_release_reason' );
	delete_site_transient( 'follow_vendors_for_hivepress_release_rate_limit' );

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'follow_vendors_for_hivepress_release_refresh', [], 'hivepress' );
		as_unschedule_all_actions( 'follow_vendors_for_hivepress_release_refresh' );
	}

	wp_clear_scheduled_hook( 'follow_vendors_for_hivepress_release_refresh' );

	/*
	 * The stored version, which keys the rewrite flush on init (the follow component's
	 * maybe_flush_rewrite_rules()). It is regenerable runtime state, not owner configuration, so it
	 * goes on every delete: left behind, a reinstall of the SAME version would see a matching value,
	 * never flush, and if anything flushed the rules while the plugin was absent (any plugin or
	 * theme activation does) the account page would answer 404 with a live menu item pointing at it.
	 */
	delete_option( $prefix . 'version' );

	/*
	 * Every pending digest job, whatever its arguments. With NO group and NO arguments the call
	 * routes to cancel_actions_by_hook() (action-scheduler/functions.php:316-319,
	 * ActionScheduler_DBStore.php:713) and catches every argument set. Passing the group with empty
	 * arguments would match only actions whose arguments are [], which is none of ours: every digest
	 * job carries the Vendor ID and the window number at least.
	 */
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'hpfv_send_follow_emails' );
	}

	// Any ordinary transient the plugin has ever set. Nothing writes one today, but a transient is
	// stored as "_transient_{name}" plus a separate "_transient_timeout_{name}" row, so the prefix sweep
	// used for options below cannot match them: it anchors on the prefix at the start of the name.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of wildcard option names, which no WordPress API can enumerate.
	$transients = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			'_transient_' . $wpdb->esc_like( $prefix ) . '%',
			'_transient_timeout_' . $wpdb->esc_like( $prefix ) . '%'
		)
	);

	foreach ( (array) $transients as $transient_name ) {
		delete_option( $transient_name );
	}

	/*
	 * -------------------------------------------------------------------------------------------------
	 * Everything below happens only when the owner asked for it.
	 * -------------------------------------------------------------------------------------------------
	 */

	if ( $delete_all ) {

		// Every follow. `type` is passed explicitly because a comment query that names no type
		// excludes HivePress's non-public types (components/class-comment.php:70-97) while the plugin is
		// active, and the default status is kept: 'any' would let the trash through, which is not what
		// "every follow" means (resources/wordpress-php-notes.md, "get_comments( 'status' => 'any' )").
		$follow_ids = get_comments(
			[
				'type'   => 'hp_hpfv_follow',
				'fields' => 'ids',
				'number' => 0,
			]
		);

		foreach ( (array) $follow_ids as $follow_id ) {
			wp_delete_comment( (int) $follow_id, true );
		}

		// The "announced" stamp on every Listing and the digest cursor on every Vendor. The keys are
		// repeated here for the same reason as the prefix: the component that defines them is not loaded.
		foreach ( [ 'hp_hpfv_notified_time', 'hp_hpfv_digest_time' ] as $meta_key ) {
			delete_post_meta_by_key( $meta_key );
		}

		/*
		 * The cached follow lists and follower counts. HivePress's per-object cache writes ordinary
		 * user-meta and post-meta rows named "_transient_hp_models/hpfv_follow/..." plus a timeout twin,
		 * and WordPress's transient garbage collection never looks in the meta tables, so without this
		 * they are permanent (resources/wordpress-php-notes.md, "A transient stored in USER META is
		 * never garbage collected").
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of wildcard meta keys, which no WordPress API can enumerate.
		$user_rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT umeta_id FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
				'_transient_' . $wpdb->esc_like( 'hp_models/hpfv_follow/' ) . '%',
				'_transient_timeout_' . $wpdb->esc_like( 'hp_models/hpfv_follow/' ) . '%'
			)
		);

		foreach ( (array) $user_rows as $row_id ) {
			delete_metadata_by_mid( 'user', absint( $row_id ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of wildcard meta keys, which no WordPress API can enumerate.
		$post_rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
				'_transient_' . $wpdb->esc_like( 'hp_models/hpfv_follow/' ) . '%',
				'_transient_timeout_' . $wpdb->esc_like( 'hp_models/hpfv_follow/' ) . '%'
			)
		);

		foreach ( (array) $post_rows as $row_id ) {
			delete_metadata_by_mid( 'post', absint( $row_id ) );
		}

		// The owner's reworded copy of the email. HivePress keeps an edited email as an hp_email post
		// whose slug is the email name (components/class-email.php:59-91), and with the plugin gone
		// that name no longer exists, so the post would sit under HivePress > Emails describing an
		// email nothing can send.
		$email_posts = get_posts(
			[
				'post_type'      => 'hp_email',
				'post_status'    => 'any',
				'post_name__in'  => [ 'hpfv_listing_follow' ],
				'posts_per_page' => -1,
				'fields'         => 'ids',
			]
		);

		foreach ( (array) $email_posts as $email_post_id ) {
			wp_delete_post( (int) $email_post_id, true );
		}

		// Delete the options by prefix. This runs once, while the plugin is being deleted, so there is
		// nothing worth caching.
		//
		// The "delete all data" option itself is excluded here and removed at the very end. If this run
		// fails part-way through, the flag is still set, so a second attempt finishes the job. Sweeping it
		// away first would silently flip the site back to "retain" with half the data already gone.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of wildcard option names, which no WordPress API can enumerate.
		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name != %s",
				$wpdb->esc_like( $prefix ) . '%',
				$prefix . 'delete_data'
			)
		);

		foreach ( (array) $option_names as $option_name ) {

			// Use the options API so persistent object caches are invalidated too.
			delete_option( $option_name );
		}

		// Last, and only once everything above has succeeded.
		delete_option( $prefix . 'delete_data' );
	}
}

/*
 * A network install runs this file once, in one site's context. Every other site on the network has
 * its own follows and its own settings, so each one is visited in turn. On a single site the loop is
 * skipped entirely and nothing changes.
 */
if ( is_multisite() ) {
	foreach ( get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	) as $hpfv_site_id ) {
		switch_to_blog( (int) $hpfv_site_id );

		hpfv_uninstall_site( $hpfv_prefix );

		restore_current_blog();
	}
} else {
	hpfv_uninstall_site( $hpfv_prefix );
}
