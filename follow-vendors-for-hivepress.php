<?php
/**
 * Plugin Name: Follow Vendors for HivePress
 * Plugin URI: https://github.com/irapidchris-del/follow-vendors-for-hivepress
 * Description: Lets users follow Vendors, lists them in the account menu, emails followers about new Listings, and adds a Follow item to Action Bar.
 * Version: 1.0.1
 * Author: ChrisB @ HivePress Community
 * Author URI: https://community.hivepress.io/u/chrisb/summary
 * Text Domain: follow-vendors-for-hivepress
 * Domain Path: /languages/
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: hivepress
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/irapidchris-del/follow-vendors-for-hivepress
 *
 * @package Follow_Vendors
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Keep in step with the Version header above and the readme Stable tag on every release.
define( 'HPFV_VERSION', '1.0.1' );

// The main file, for asset paths and URLs that must not depend on the installed folder name.
define( 'HPFV_FILE', __FILE__ );

/**
 * Prefix for every option this plugin stores, WITHOUT HivePress's own "hp_" in front.
 *
 * HivePress stores a settings field as "hp_" + the field name (hivepress/includes/components/
 * class-admin.php:297), so every field on this plugin's settings tab is named with this prefix
 * and lands in the database as "hp_follow_vendors_for_hivepress_*". uninstall.php sweeps that
 * same prefix, so nothing outside it may ever be stored under it.
 */
define( 'HPFV_OPTION_PREFIX', 'follow_vendors_for_hivepress_' );

// Set up updates from GitHub releases.
require_once __DIR__ . '/includes/updater.php';

Follow_Vendors\Updater\bootstrap( __FILE__ );

/**
 * Flushes the rewrite rules on activation.
 *
 * The follow component also flushes on init whenever the stored version changes, which covers an
 * update done by copying files over. This covers the other case: a deactivate and reactivate of
 * the same version, during which any plugin or theme activation may have rebuilt the rules without
 * the account route. Deleting the option is core's own way of flushing (components/
 * class-router.php:503-505, core 1.7.31): WordPress rebuilds lazily on the next request.
 */
function hpfv_activate() {
	delete_option( 'rewrite_rules' );
}

register_activation_hook( __FILE__, 'hpfv_activate' );

/**
 * Registers the extension.
 *
 * Two registration forms exist and both have a failure mode. HivePress resolves
 * a bare directory path to `{dirname}/{dirname}.php`, so the string form fails
 * silently whenever the installed folder name differs from the main file name
 * (a source zip unpacks to `follow-vendors-for-hivepress-main`, for instance).
 * The array form always registers, but core's updater probe concatenates every
 * entry as a string, so an array entry makes it log a warning on each request
 * unless the probe has already been satisfied. So: the string form whenever
 * the folder name matches, and only for a renamed folder the array form, with
 * the probe run here first over the string entries so core's loop never
 * reaches the array. The filter is registered late so extensions that bundle
 * the updates package are already listed by the time that probe runs.
 *
 * @param array<string, mixed> $extensions Extension arguments.
 * @return array<string, mixed>
 */
function hpfv_register_extension( $extensions ) {
	if ( file_exists( __DIR__ . '/' . basename( __DIR__ ) . '.php' ) ) {
		$extensions[] = __DIR__;

		return $extensions;
	}

	if ( ! isset( $extensions['updates'] ) ) {
		$path = '/vendor/hivepress/hivepress-updates';

		foreach ( $extensions as $dir ) {
			if ( is_string( $dir ) && file_exists( $dir . $path . '/hivepress-updates.php' ) ) {
				$extensions['updates'] = $dir . $path;

				break;
			}
		}

		// Set it even when nothing was found. Core's own probe (class-core.php:245-256) only runs
		// while this key is unset, and it concatenates EVERY entry as a string, so on a site with
		// no premium extension the array entry below would make it warn "Array to string
		// conversion" on every single request. A path that does not exist is harmless: core's
		// string branch drops it at its own file_exists() guard (:277), which is the same outcome
		// as the probe finding nothing, minus the warning. Only ever reached on a renamed folder,
		// where the array entry is the only way this plugin loads at all.
		if ( ! isset( $extensions['updates'] ) ) {
			$extensions['updates'] = __DIR__ . $path;
		}
	}

	$extensions['follow_vendors_for_hivepress'] = [
		'name'    => 'Follow Vendors for HivePress',
		'version' => HPFV_VERSION,
		'path'    => __DIR__,
		'url'     => rtrim( plugin_dir_url( __FILE__ ), '/' ),
	];

	return $extensions;
}

add_filter( 'hivepress/v1/extensions', 'hpfv_register_extension', 100 );

// Add a Settings link on the Plugins screen, pointing at this plugin's own HivePress settings tab.
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function( $links ) {
		if ( class_exists( '\HivePress\Core' ) ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=hp_settings&tab=follow_vendors' ) ) . '">' . esc_html__( 'Settings', 'follow-vendors-for-hivepress' ) . '</a>' );
		}

		return $links;
	}
);

// Show a notice if HivePress is not active.
add_action(
	'admin_notices',
	function() {
		if ( ! class_exists( '\HivePress\Core' ) && current_user_can( 'activate_plugins' ) ) {

			// Dismissible, because an undismissable notice on every admin screen is admin hijacking even
			// when the thing it says is true. WordPress only hides it for the current page load, so the
			// warning returns until HivePress is actually activated.
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Follow Vendors for HivePress requires the HivePress plugin to be installed and activated.', 'follow-vendors-for-hivepress' ) . '</p></div>';
		}
	}
);

/**
 * The author's support page.
 *
 * One place, so the Plugins row and the View details popup can never drift apart.
 *
 * @return string
 */
function hpfv_get_support_url() {
	return 'https://ko-fi.com/chrisbathivepresscommunity';
}

/**
 * Adds a quiet "Donate" link to this plugin's row meta.
 *
 * WordPress fires plugin_row_meta for EVERY plugin on the screen and joins the items with a pipe,
 * so without the basename test the link would appear on every row on the site.
 *
 * The markup is copied verbatim from the house spec in `releasing.md` rather than composed here:
 * every plugin's row has to look identical, and sessions have drifted before. The label is exactly
 * "Donate", which is also the wording WordPress uses in the details popup, and the icon is a
 * Dashicon rather than Font Awesome because Dashicons is the admin's own font and is always loaded
 * there. WordPress joins row-meta items with " | " itself, so this returns a bare anchor.
 *
 * @param array<string> $meta Row meta links.
 * @param string        $plugin_file Plugin file the row belongs to.
 * @return array<string>
 */
function hpfv_add_row_meta( $meta, $plugin_file ) {
	if ( plugin_basename( __FILE__ ) === $plugin_file ) {
		$meta[] = '<a href="' . esc_url( hpfv_get_support_url() ) . '" target="_blank" rel="noopener noreferrer">'
			. '<span class="dashicons dashicons-star-filled" style="font-size:14px;line-height:1.3;"></span> '
			. esc_html__( 'Donate', 'follow-vendors-for-hivepress' )
			. '</a>';
	}

	return $meta;
}

add_filter( 'plugin_row_meta', 'hpfv_add_row_meta', 10, 2 );

/**
 * Reads one of this plugin's settings, with HivePress's stored-empty behaviour accounted for.
 *
 * HivePress seeds a field's `default` only when HivePress itself is activated or updated, and
 * otherwise applies it only while RENDERING the settings screen (class-admin.php:265, :307), so
 * a site that installed this plugin later has no stored value at all until the tab is first saved.
 * Once it is saved, an unticked checkbox and a cleared field are both stored as an empty string.
 * So: absent (null or false) means "use the default", and an empty string is a deliberate empty
 * that must be respected. Full write-up: resources/hivepress-settings.md, "The stored-empty trap".
 *
 * @param string $name Option name without the "hp_" prefix.
 * @param mixed  $fallback Value when the option has never been saved.
 * @return mixed
 */
function hpfv_get_option( $name, $fallback ) {
	$value = get_option( 'hp_' . $name, null );

	if ( null === $value || false === $value ) {
		return $fallback;
	}

	return $value;
}

/**
 * Reads a number setting. A cleared number field is stored as an empty string, which `(int)`
 * would turn into 0 rather than the documented default, so anything non-numeric falls back.
 * An explicit 0 is numeric and is respected.
 *
 * @param string $name Option name without the "hp_" prefix.
 * @param int    $fallback Value when the option is absent or not a number.
 * @return int
 */
function hpfv_get_number_option( $name, $fallback ) {
	$value = hpfv_get_option( $name, $fallback );

	return is_numeric( $value ) ? (int) $value : (int) $fallback;
}

/**
 * Reads a single-choice setting against a closed list of stored values.
 *
 * A radio field that has never been saved is absent, and one whose option row was written
 * as an empty string (a cleared tab, an older build) is ''. Neither is one of the choices the
 * screen offers, so both fall back to the default rather than reaching a branch that matches
 * nothing (resources/hivepress-settings.md, "A select's stored values must match what the
 * code branches on").
 *
 * @param string   $name Option name without the "hp_" prefix.
 * @param string[] $allowed The values the field can store.
 * @param string   $fallback Value when the stored value is absent or not in the list.
 * @return string
 */
function hpfv_get_choice_option( $name, $allowed, $fallback ) {
	$value = hpfv_get_option( $name, $fallback );

	return in_array( $value, $allowed, true ) ? $value : $fallback;
}
