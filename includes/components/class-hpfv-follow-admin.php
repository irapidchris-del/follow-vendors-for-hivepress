<?php
/**
 * Follow admin component: the computed notes on the settings tab and the shared settings-screen
 * chrome.
 *
 * Admin only. The tab itself is declared in includes/configs/settings.php; this component only
 * appends sentences that depend on the site's state (Vendor pages switched off, a companion
 * extension not detected), which a static config file cannot know.
 *
 * @package Follow_Vendors
 */

namespace HivePress\Components;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Follow admin component class.
 *
 * @class Hpfv_Follow_Admin
 */
final class Hpfv_Follow_Admin extends Component {

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {
		if ( is_admin() ) {

			// After HivePress's own settings pass at 10.
			add_filter( 'hivepress/v1/settings', [ $this, 'add_settings_notes' ], 20 );

			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_settings_assets' ] );
		}

		parent::__construct( $args );
	}

	/**
	 * Appends the site-specific notes to the section descriptions.
	 *
	 * Every read is guarded, including the tab this plugin's own config file guarantees: a request
	 * that races a plugin update can reach this callback with the config file momentarily
	 * unreadable, and an unguarded read then logs warnings (resources/hivepress-settings.md, "A
	 * settings-filter callback must guard its own tab"). class_exists() on HivePress classes is
	 * only safe after init, hence the did_action() test.
	 *
	 * @param array $settings Settings configuration.
	 * @return array
	 */
	public function add_settings_notes( $settings ) {
		if ( ! is_array( $settings ) || ! isset( $settings['follow_vendors']['sections'] ) || ! is_array( $settings['follow_vendors']['sections'] ) || ! did_action( 'init' ) ) {
			return $settings;
		}

		$sections = &$settings['follow_vendors']['sections'];

		if ( isset( $sections['display']['description'] ) && ! get_option( 'hp_vendor_enable_display' ) ) {
			$sections['display']['description'] .= ' ' . sprintf(
				/* translators: %s: the HivePress product name. */
				esc_html__( 'Vendor pages are switched off under %s, Settings, Vendors on this site, so nothing from this plugin is shown until they are switched on.', 'follow-vendors-for-hivepress' ),
				'HivePress'
			);
		}

		if ( isset( $sections['action_bar']['description'] ) ) {
			$missing = [];

			// Only the bar itself is named now. Favorites and Search Alerts belong to that plugin's
			// own Quick Actions section, so naming them here would send an owner to the wrong tab.
			if ( ! class_exists( '\HivePress\Components\Hpab_Action_Bar' ) ) {
				$missing[] = 'Action Bar for HivePress';
			}

			if ( $missing ) {
				$sections['action_bar']['description'] .= ' ' . sprintf(
					/* translators: %s: a comma-separated list of plugin and extension names. */
					esc_html__( 'Not detected on this site: %s.', 'follow-vendors-for-hivepress' ),
					implode( ', ', $missing )
				);
			}
		}

		return $settings;
	}

	/**
	 * Checks whether the settings tab being rendered is this plugin's own.
	 *
	 * The address cannot answer the question: HivePress falls back to the FIRST tab whenever
	 * `tab` is absent (class-admin.php, get_settings_tab()), so `admin.php?page=hp_settings`
	 * renders a real tab that the address does not name. The registered fields can answer it:
	 * Admin::register_settings() builds the sections and fields for exactly one tab and calls
	 * add_settings_field() with the prefixed option name (class-admin.php:275-325, core 1.7.31),
	 * so after admin_init the wp_settings_fields global holds this plugin's keys on its own tab
	 * and on no other. Full rule: resources/hivepress-settings.md, "The tab IS knowable
	 * server-side: ask the registered fields".
	 *
	 * @return bool
	 */
	protected function is_settings_tab() {
		if ( ! isset( $GLOBALS['wp_settings_fields']['hp_settings'] ) || ! is_array( $GLOBALS['wp_settings_fields']['hp_settings'] ) ) {
			return false;
		}

		foreach ( $GLOBALS['wp_settings_fields']['hp_settings'] as $section ) {
			foreach ( array_keys( (array) $section ) as $field ) {
				if ( 0 === strpos( (string) $field, 'hp_' . HPFV_OPTION_PREFIX ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Dresses this plugin's settings tab with the shared settings-screen chrome.
	 *
	 * The quick-links anchor nav, the sideways floating Save control and the back-to-top button
	 * are copied from the reference implementation in Account Menu Enhancer for HivePress, so
	 * every extension in this family puts the same controls in the same places
	 * (resources/hivepress-settings.md, "The settings anchor nav: one shared marker class"). It
	 * has to be added client-side: HivePress renders the tab through do_settings_sections(),
	 * which prints each section as a bare h2 with no id and no hook between sections.
	 *
	 * Two gates on the chrome, and neither replaces the other: is_settings_tab() decides whether
	 * the files load, and the script's own field-prefix test decides whether it acts.
	 *
	 * @return void
	 */
	public function enqueue_settings_assets() {
		// Screen detection only, no form data is read or written.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of which admin page is rendering.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'hp_settings' !== $page || ! $this->is_settings_tab() ) {
			return;
		}

		$path = plugin_dir_path( HPFV_FILE );
		$url  = plugin_dir_url( HPFV_FILE );

		// The file time rides along in the version so caches refresh whenever the file changes.
		wp_enqueue_style(
			'hpfv-backend',
			$url . 'assets/css/backend.css',
			[],
			HPFV_VERSION . '.' . (int) filemtime( $path . 'assets/css/backend.css' )
		);

		wp_enqueue_script(
			'hpfv-backend',
			$url . 'assets/js/backend.js',
			[ 'jquery' ],
			HPFV_VERSION . '.' . (int) filemtime( $path . 'assets/js/backend.js' ),
			true
		);

		wp_localize_script(
			'hpfv-backend',
			'hpfvBackendData',
			[
				'labels' => [
					// The colon is part of the wording: it reads as a lead-in to the links that
					// follow it, not as a heading over them.
					'jumpTo'    => esc_html__( 'Jump to a section:', 'follow-vendors-for-hivepress' ),
					'save'      => esc_html__( 'Save Changes', 'follow-vendors-for-hivepress' ),
					'backToTop' => esc_html__( 'Back to top', 'follow-vendors-for-hivepress' ),
				],
			]
		);
	}
}
