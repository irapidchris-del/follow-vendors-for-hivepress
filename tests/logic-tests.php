<?php
/**
 * Logic tests for Follow Vendors for HivePress.
 *
 * Stubs WordPress and HivePress, then drives the real plugin code. Needs PHP only: no database,
 * no WordPress install. Run from the plugin folder:
 *
 *     php tests/logic-tests.php
 *
 * Env: HPFV_BAR=absent        -> no Action Bar for HivePress
 *      HPFV_FAVORITES=absent  -> no Favorites extension
 *      HPFV_ALERTS=absent     -> no Search Alerts extension
 *      HPFV_FAFH=absent       -> no shared icon library
 *
 * Each case is named after the rule it protects. The stubs mirror how core actually behaves where
 * the plugin depends on it: Toggle::boot()/render() are copied from core 1.7.31 so the captions
 * and data attributes are the real ones, merge_arrays() is core's own algorithm, and the scheduler
 * refuses a duplicate of a pending job exactly as Scheduler::add_action() does.
 *
 * @package Follow_Vendors\Tests
 */

namespace HivePress\Helpers {
	function merge_arrays() {
		$merged = [];
		foreach ( func_get_args() as $array ) {
			foreach ( (array) $array as $key => $value ) {
				if ( ! isset( $merged[ $key ] ) || ( ! is_array( $merged[ $key ] ) || ! is_array( $value ) ) ) {
					if ( is_numeric( $key ) ) { $merged[] = $value; } else { $merged[ $key ] = $value; }
				} else {
					$merged[ $key ] = merge_arrays( $merged[ $key ], $value );
				}
			}
		}
		return $merged;
	}
	function merge_trees( $a, $b ) { return merge_arrays( $a, $b ); }
	function get_array_value( $array, $key, $default = null ) { return is_array( $array ) && array_key_exists( $key, $array ) ? $array[ $key ] : $default; }
	function get_first_array_value( $array, $default = null ) { $array = (array) $array; return $array ? reset( $array ) : $default; }
	function get_last_array_value( $array, $default = null ) { $array = (array) $array; return $array ? end( $array ) : $default; }
	function get_class_name( $class ) { return strtolower( substr( strrchr( '\\' . $class, '\\' ), 1 ) ); }
	function prefix( $n ) { return 'hp_' . $n; }
	function html_attributes( $attributes ) {
		$out = '';
		foreach ( (array) $attributes as $name => $value ) {
			if ( true === $value ) { $value = $name; } elseif ( is_array( $value ) ) { $value = implode( ' ', $value ); }
			$out .= \esc_attr( $name ) . '="' . \esc_attr( $value ) . '" ';
		}
		return rtrim( $out );
	}
	function sanitize_html( $html ) { return strip_tags( (string) $html, '<strong><a><i>' ); }
	function rest_response( $code, $data = null ) { return new \Fake_Response( $code, is_null( $data ) ? [] : [ 'data' => $data ] ); }
	function rest_error( $code, $errors = [] ) { return new \Fake_Response( $code, [ 'error' => [ 'code' => $code ] ] ); }
}

namespace HivePress\Models {
	abstract class Model {
		protected $id = 0;
		protected $fields = [];
		public function __construct( $args = [] ) { $this->fields = $args['fields'] ?? []; }
		public function get_id() { return $this->id; }
		public function set_id( $id ) { $this->id = $id; return $this; }
		public function _get_fields() { return $this->fields; }
	}
	// Core's Comment model answers query() through Model::__callStatic; the fake reads the follow table.
	abstract class Comment extends Model {
		public static function query() { return new \Fake_Follow_Query(); }
	}

	class Vendor extends Model {
		public $status = 'publish'; public $user = 0; public $name = 'Vendor';
		public function get_status() { return $this->status; }
		public function get_user__id() { return $this->user; }
		public function get_name() { return $this->name; }
		public static function query() { return new Fake_Vendor_Query(); }
	}
	class Fake_Vendor_Query {
		private $ids = null;
		public function get_by_id( $id ) { return $GLOBALS['_vendors'][ $id ] ?? null; }
		public function filter( $a ) { if ( isset( $a['id__in'] ) ) { $this->ids = $a['id__in']; } return $this; }
		public function get_first_id() {
			foreach ( (array) $this->ids as $id ) { if ( isset( $GLOBALS['_vendors'][ $id ] ) && 'publish' === $GLOBALS['_vendors'][ $id ]->status ) { return $id; } }
			return 0;
		}
	}
	class Listing extends Model {
		public $status = 'publish'; public $vendor = 0; public $title = '';
		public function get_status() { return $this->status; }
		public function get_vendor__id() { return $this->vendor; }
		public static function query() { return new Fake_Listing_Query(); }
	}
	class Fake_Listing_Query {
		public function get_by_id( $id ) { return $GLOBALS['_listings_objects'][ $id ] ?? null; }
	}
	class User extends Model {
		public $email = ''; public $display_name = '';
		public function get_email() { return $this->email; }
		public function get_display_name() { return $this->display_name; }
		public static function query() { return new Fake_User_Query(); }
	}
	class Fake_User_Query {
		public function get_by_id( $id ) { return $GLOBALS['_users'][ $id ] ?? null; }
	}
}

namespace HivePress\Components {
	abstract class Component {
		public function __construct( $args = [] ) {}
	}
	if ( 'absent' !== getenv( 'HPFV_BAR' ) ) {
		class Hpab_Action_Bar {}
	}
	if ( 'absent' !== getenv( 'HPFV_FAVORITES' ) ) {
		class Favorite {}
	}
	if ( 'absent' !== getenv( 'HPFV_ALERTS' ) ) {
		class Search_Alert {
			public function get_model_form( $params ) { return $GLOBALS['_alert_form']; }
			public function get_alert_key( $params ) { return 'http://' . md5( json_encode( $params ) ); }
		}
	}
}

namespace HivePress\Controllers {
	abstract class Controller {
		public $routes = [];
		public function __construct( $args = [] ) { $this->routes = $args['routes'] ?? []; }
	}
}

namespace HivePress\Templates {
	class User_Account_Page {
		public $args = [];
		public function __construct( $args = [] ) { $this->args = $args; }
	}
}

namespace HivePress\Emails {
	class Email {
		protected $args = [];
		protected static $meta = [];
		public static function init( $meta = [] ) { static::$meta = $meta; }
		public static function get_meta( $key ) { return static::$meta[ $key ] ?? null; }
		public function __construct( $args = [] ) { $this->args = $args; }
		public function send() { $GLOBALS['_sent'][] = $this->args; return true; }
		public function get_args() { return $this->args; }
	}
}

namespace HivePress\Blocks {
	use HivePress\Helpers as hp;

	abstract class Block {
		protected $context = [];
		protected $args = [];
		public function __construct( $args = [] ) {
			$this->args = $args;
			foreach ( $args as $name => $value ) { if ( property_exists( $this, $name ) ) { $this->$name = $value; } }
			$this->boot();
		}
		protected function boot() {}
		public function get_context( $name, $default = null ) { return $this->context[ $name ] ?? $default; }
		abstract public function render();
	}

	// Core 1.7.31 blocks/class-toggle.php:72-170, verbatim in substance.
	class Toggle extends Block {
		protected $view = 'link'; protected $icon; protected $url; protected $captions = []; protected $states = []; protected $attributes = []; protected $active = false;
		protected function boot() {
			$attributes = [];
			if ( $this->icon ) { $this->icon = (array) $this->icon; }
			if ( $this->states ) { $this->icon = array_column( $this->states, 'icon' ); $this->captions = array_column( $this->states, 'caption' ); }
			if ( 'link' === $this->view ) { $attributes['class'] = [ 'hp-link' ]; }
			if ( \is_user_logged_in() || ! $this->url ) {
				$attributes['href'] = '#';
				$attributes['data-component'] = 'toggle';
				if ( $this->url ) { $attributes['data-url'] = \esc_url( $this->url ); }
				if ( $this->active ) {
					$attributes['data-icon'] = hp\get_first_array_value( $this->icon );
					$attributes['data-caption'] = hp\get_first_array_value( $this->captions );
					$attributes['data-state'] = 'active';
					if ( 'icon' === $this->view ) { $attributes['title'] = hp\get_last_array_value( $this->captions ); }
				} else {
					$attributes['data-icon'] = hp\get_last_array_value( $this->icon );
					$attributes['data-caption'] = hp\get_last_array_value( $this->captions );
					if ( 'icon' === $this->view ) { $attributes['title'] = hp\get_first_array_value( $this->captions ); }
				}
			} else {
				$attributes['href'] = '#user_login_modal';
			}
			$this->attributes = hp\merge_arrays( $this->attributes, $attributes );
			parent::boot();
		}
		public function render() {
			$output = '';
			if ( $this->icon || $this->captions ) {
				$output .= '<a ' . hp\html_attributes( $this->attributes ) . '>';
				if ( $this->icon ) { $icon = $this->active ? hp\get_last_array_value( $this->icon ) : hp\get_first_array_value( $this->icon ); $output .= '<i class="hp-icon fas fa-' . \esc_attr( $icon ) . '"></i>'; }
				if ( 'icon' !== $this->view ) { $caption = $this->active ? hp\get_last_array_value( $this->captions ) : hp\get_first_array_value( $this->captions ); $output .= '<span>' . \esc_html( $caption ) . '</span>'; }
				$output .= '</a>';
			}
			return $output;
		}
	}
}

namespace {

	if ( 'absent' !== getenv( 'HPFV_FAFH' ) ) {
		class FAFH {
			public static function pair( $name ) { return '0 0 512 512|M1 1 ' . preg_replace( '/[^a-z-]/', '', $name ); }
		}
	}

	class Fake_Response {
		public $status; public $data;
		public function __construct( $status, $data ) { $this->status = $status; $this->data = $data; }
		public function get_status() { return $this->status; }
		public function get_data() { return $this->data; }
	}

	class Fake_Request {
		private $params;
		public function __construct( $params ) { $this->params = $params; }
		public function get_param( $k ) { return $this->params[ $k ] ?? null; }
	}

	class Fake_Form {
		private $values;
		public function __construct( $values ) { $this->values = $values; }
		public function get_values() { return $this->values; }
	}

	class Fake_Follow_Collection {
		private $rows;
		public function __construct( $rows ) { $this->rows = $rows; }
		public function count() { return count( $this->rows ); }
		public function delete() { foreach ( $this->rows as $id => $r ) { unset( $GLOBALS['_follows'][ $id ] ); } }
		public function serialize() { return array_map( function ( $r ) { return new Fake_Follow_Object( $r ); }, array_values( $this->rows ) ); }
	}
	class Fake_Follow_Object {
		private $r;
		public function __construct( $r ) { $this->r = $r; }
		public function get_vendor__id() { return $this->r['vendor']; }
		public function get_user__id() { return $this->r['user']; }
	}
	class Fake_Follow_Query {
		private $filter = [];
		public function filter( $f ) { $this->filter = array_merge( $this->filter, $f ); return $this; }
		public function order( $o ) { return $this; }
		public function get_args() { return [ 'filter' => $this->filter ]; }
		private function rows() {
			return array_filter( $GLOBALS['_follows'], function ( $r ) {
				foreach ( $this->filter as $k => $v ) { if ( ( $r[ $k ] ?? null ) != $v ) { return false; } }
				return true;
			} );
		}
		public function get() { return new Fake_Follow_Collection( $this->rows() ); }
		public function get_count() { return count( $this->rows() ); }
		public function delete() { foreach ( $this->rows() as $id => $r ) { unset( $GLOBALS['_follows'][ $id ] ); } }
	}

	class Fake_Request_Context {
		public $context = [];
		public function set_context( $k, $v ) { $this->context[ $k ] = $v; }
		public function get_context( $k, $d = null ) { return $this->context[ $k ] ?? $d; }
		public function get_page_number() { return 1; }
	}
	class Fake_Router {
		public $current = '';
		public function get_current_route_name() { return $this->current; }
		public function get_url( $name, $args = [] ) {
			$id = $args['vendor_id'] ?? ( $args['listing_id'] ?? '' );
			return 'https://example.test/wp-json/hivepress/v1/' . $name . '/' . $id;
		}
		public function get_return_url( $name ) { return 'https://example.test/' . $name . '/?redirect=x'; }
	}
	class Fake_Cache {
		public $user = []; public $post = [];
		public function get_user_cache( $id, $key, $group ) { return $this->user[ $id ][ json_encode( $key ) ] ?? null; }
		public function set_user_cache( $id, $key, $group, $v ) { $this->user[ $id ][ json_encode( $key ) ] = $v; }
		public function get_post_cache( $id, $key, $group ) { return $this->post[ $id ][ $key ] ?? null; }
		public function set_post_cache( $id, $key, $group, $v ) { $this->post[ $id ][ $key ] = $v; }
	}
	class Fake_Scheduler {
		public $added = [];
		public function add_action( $hook, $args = [], $time = null ) {
			$key = json_encode( [ $hook, $args ] );
			if ( in_array( $key, $GLOBALS['_as_pending'], true ) ) { return; }
			$GLOBALS['_as_pending'][] = $key;
			$this->added[] = [ 'hook' => $hook, 'args' => $args, 'time' => $time ];
		}
	}
	class Fake_Template { public function merge_blocks( &$t, $b ) { $t = HivePress\Helpers\merge_arrays( $t, $b ); return $t; } }
	class Fake_Translator { public function get_string( $k ) { return ucfirst( $k ); } }
	class Fake_Core {
		public $request; public $router; public $cache; public $scheduler; public $template; public $translator; public $search_alert;
		public $hpfv_follow; public $hpfv_follow_email; public $hpfv_follow_action_bar; public $hpfv_follow_admin;
	}
	function hivepress() { return $GLOBALS['_hp']; }

	// WordPress.
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'MINUTE_IN_SECONDS', 60 );
	$GLOBALS['_hooks'] = [];
	function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['_hooks'][ $h ][] = [ $cb, $p, $a ]; }
	function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['_hooks'][ $h ][] = [ $cb, $p, $a ]; }
	function has_filter( $h, $cb = false ) { foreach ( $GLOBALS['_hooks'][ $h ] ?? [] as $r ) { if ( $r[0] == $cb ) { return true; } } return false; }
	function apply_filters( $h, $v ) { $args = func_get_args(); array_shift( $args ); foreach ( $GLOBALS['_hooks'][ $h ] ?? [] as $r ) { $args[0] = call_user_func_array( $r[0], array_slice( $args, 0, $r[2] ) ); } return $args[0]; }
	function do_action( $h ) { $args = func_get_args(); array_shift( $args ); foreach ( $GLOBALS['_hooks'][ $h ] ?? [] as $r ) { call_user_func_array( $r[0], array_slice( $args, 0, $r[2] ) ); } }
	function remove_filter( $h, $cb ) { foreach ( $GLOBALS['_hooks'][ $h ] ?? [] as $i => $r ) { if ( $r[0] == $cb ) { unset( $GLOBALS['_hooks'][ $h ][ $i ] ); } } }
	function did_action( $h ) { return 1; }
	function is_admin() { return false; }
	function plugin_basename( $f ) { return 'follow-vendors-for-hivepress/follow-vendors-for-hivepress.php'; }
	function plugin_dir_url( $f ) { return 'https://example.test/wp-content/plugins/follow-vendors-for-hivepress/'; }
	function plugin_dir_path( $f ) { return dirname( __DIR__ ) . '/'; }
	function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['_options'] ) ? $GLOBALS['_options'][ $n ] : $d; }
	function update_option( $n, $v ) { $GLOBALS['_options'][ $n ] = $v; return true; }
	function delete_option( $n ) { unset( $GLOBALS['_options'][ $n ] ); return true; }
	function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['_postmeta'][ $id ][ $k ] ?? ''; }
	function update_post_meta( $id, $k, $v ) { $GLOBALS['_postmeta'][ $id ][ $k ] = $v; return true; }
	function get_posts( $args ) {
		$rows = [];
		foreach ( $GLOBALS['_listings'] as $id => $l ) {
			if ( $l['vendor'] != $args['post_parent'] || 'publish' !== $l['status'] ) { continue; }
			$stamp = (int) ( $GLOBALS['_postmeta'][ $id ]['hp_hpfv_notified_time'] ?? 0 );
			if ( ! $stamp ) { continue; }
			$mq = $args['meta_query'];
			if ( $stamp > (int) $mq[0]['value'] && $stamp <= (int) $mq[1]['value'] ) { $rows[ $id ] = $stamp; }
		}
		asort( $rows );
		$ids = array_keys( $rows );
		if ( $args['posts_per_page'] > 0 ) { $ids = array_slice( $ids, 0, $args['posts_per_page'] ); }
		return $ids;
	}
	function get_comments( $args ) {
		$rows = [];
		foreach ( $GLOBALS['_follows'] as $id => $r ) {
			if ( $r['vendor'] != $args['post_id'] ) { continue; }
			$c = new WP_Comment(); $c->comment_ID = $id; $c->user_id = $r['user']; $rows[] = $c;
		}
		usort( $rows, function ( $a, $b ) { return $a->comment_ID <=> $b->comment_ID; } );
		return array_slice( $rows, (int) $args['offset'], (int) $args['number'] );
	}
	// get_comments() hands back WP_Comment objects; the plugin tests for that class.
	class WP_Comment { public $comment_ID; public $user_id; }
	function get_permalink( $id ) { return 'https://example.test/?p=' . $id; }
	function get_the_title( $id ) { return $GLOBALS['_listings'][ $id ]['title'] ?? ''; }
	function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8', false ); }
	function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8', false ); }
	function esc_url( $u ) { return $u; }
	function esc_html__( $t, $d = null ) { return $t; }
	function __( $t, $d = null ) { return $t; }
	function _n( $s, $p, $n, $d = null ) { return 1 == $n ? $s : $p; }
	function number_format_i18n( $n ) { return (string) $n; }
	function absint( $n ) { return abs( (int) $n ); }
	function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ); }
	function wp_unslash( $v ) { return $v; }
	function wp_json_encode( $d ) { return json_encode( $d ); }
	function add_query_arg( $params, $url ) { return $url . '?' . http_build_query( $params ); }
	function is_user_logged_in() { return $GLOBALS['_user'] > 0; }
	function get_current_user_id() { return $GLOBALS['_user']; }
	function is_search() { return $GLOBALS['_is_search']; }
	function wp_script_is( $h, $s ) { return $GLOBALS['_bar_enqueued']; }
	function wp_enqueue_script( $h, $src, $deps, $ver, $footer ) { $GLOBALS['_enqueued'][ $h ] = [ 'deps' => $deps, 'ver' => $ver ]; }
	function wp_localize_script( $h, $name, $data ) { $GLOBALS['_localized'][ $name ] = $data; }
	function wp_enqueue_style() {}
	function wp_add_inline_style( $h, $css ) { $GLOBALS['_inline_styles'][ $h ][] = $css; }
	function wp_strip_all_tags( $s ) { return trim( strip_tags( preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', '', (string) $s ) ) ); }
	function register_activation_hook( $f, $cb ) { $GLOBALS['_activation'] = $cb; }
	function current_user_can() { return true; }
	function admin_url( $p ) { return 'https://example.test/wp-admin/' . $p; }

	function reset_state() {
		$GLOBALS['_options'] = []; $GLOBALS['_postmeta'] = []; $GLOBALS['_follows'] = []; $GLOBALS['_listings'] = [];
		$GLOBALS['_listings_objects'] = []; $GLOBALS['_vendors'] = []; $GLOBALS['_users'] = []; $GLOBALS['_sent'] = [];
		$GLOBALS['_as_pending'] = []; $GLOBALS['_user'] = 0; $GLOBALS['_is_search'] = false; $GLOBALS['_alert_form'] = null;
		$GLOBALS['_bar_enqueued'] = true; $GLOBALS['_enqueued'] = []; $GLOBALS['_localized'] = []; $GLOBALS['_inline_styles'] = [];
		$GLOBALS['_hp'] = new Fake_Core();
		$GLOBALS['_hp']->request = new Fake_Request_Context();
		$GLOBALS['_hp']->router = new Fake_Router();
		$GLOBALS['_hp']->cache = new Fake_Cache();
		$GLOBALS['_hp']->scheduler = new Fake_Scheduler();
		$GLOBALS['_hp']->template = new Fake_Template();
		$GLOBALS['_hp']->translator = new Fake_Translator();
		$GLOBALS['_hp']->search_alert = class_exists( '\HivePress\Components\Search_Alert' ) ? new \HivePress\Components\Search_Alert() : null;
		$GLOBALS['_hp']->hpfv_follow = new \HivePress\Components\Hpfv_Follow();
		$GLOBALS['_hp']->hpfv_follow_email = new \HivePress\Components\Hpfv_Follow_Email();
		$GLOBALS['_hp']->hpfv_follow_action_bar = new \HivePress\Components\Hpfv_Follow_Action_Bar();
		$GLOBALS['_hp']->hpfv_follow_admin = new \HivePress\Components\Hpfv_Follow_Admin();
		$GLOBALS['_hooks'] = [];
	}

	function make_vendor( $id, $user, $status = 'publish', $name = 'Vendor' ) {
		$v = new \HivePress\Models\Vendor(); $v->set_id( $id ); $v->user = $user; $v->status = $status; $v->name = $name;
		$GLOBALS['_vendors'][ $id ] = $v; return $v;
	}
	function make_listing( $id, $vendor, $status = 'publish', $title = 'Listing' ) {
		$GLOBALS['_listings'][ $id ] = [ 'vendor' => $vendor, 'status' => $status, 'title' => $title ];
		$l = new \HivePress\Models\Listing(); $l->set_id( $id ); $l->vendor = $vendor; $l->status = $status; $l->title = $title;
		$GLOBALS['_listings_objects'][ $id ] = $l; return $l;
	}
	function make_user( $id, $email, $name = 'Someone' ) {
		$u = new \HivePress\Models\User(); $u->set_id( $id ); $u->email = $email; $u->display_name = $name;
		$GLOBALS['_users'][ $id ] = $u; return $u;
	}
	function follow( $user, $vendor ) { static $n = 100; $GLOBALS['_follows'][ ++$n ] = [ 'user' => $user, 'vendor' => $vendor ]; }

	$GLOBALS['_pass'] = 0; $GLOBALS['_fail'] = 0;
	function ok( $c, $l, $detail = '' ) {
		if ( $c ) { $GLOBALS['_pass']++; echo "  PASS  $l\n"; }
		else { $GLOBALS['_fail']++; echo "  FAIL  $l" . ( '' !== (string) $detail ? "  [$detail]" : '' ) . "\n"; }
	}

	// Load the plugin. The updater is skipped: it is a verbatim derivative of a sibling's and needs
	// half of wp-admin to stub.
	$main = file_get_contents( dirname( __DIR__ ) . '/follow-vendors-for-hivepress.php' );
	$main = str_replace( "require_once __DIR__ . '/includes/updater.php';", '', $main );
	$main = str_replace( 'Follow_Vendors\Updater\bootstrap( __FILE__ );', '', $main );
	$main = preg_replace( '/^<\?php/', '', $main );
	eval( $main ); // phpcs:ignore -- test harness only.

	require dirname( __DIR__ ) . '/includes/models/class-hpfv-follow.php';
	require dirname( __DIR__ ) . '/includes/components/class-hpfv-follow.php';
	require dirname( __DIR__ ) . '/includes/components/class-hpfv-follow-email.php';
	require dirname( __DIR__ ) . '/includes/components/class-hpfv-follow-action-bar.php';
	require dirname( __DIR__ ) . '/includes/components/class-hpfv-follow-admin.php';
	require dirname( __DIR__ ) . '/includes/controllers/class-hpfv-follow.php';
	require dirname( __DIR__ ) . '/includes/blocks/class-hpfv-follow-toggle.php';
	require dirname( __DIR__ ) . '/includes/blocks/class-hpfv-follower-count.php';
	require dirname( __DIR__ ) . '/includes/templates/class-hpfv-vendors-follow-page.php';
	require dirname( __DIR__ ) . '/includes/emails/class-hpfv-listing-follow.php';

	$bar_present = class_exists( '\HivePress\Components\Hpab_Action_Bar' );
	$fav_present = class_exists( '\HivePress\Components\Favorite' );
	$alerts_present = class_exists( '\HivePress\Components\Search_Alert' );
	$fafh_present = class_exists( 'FAFH' );

	echo "Follow Vendors for HivePress: logic tests (bar=" . ( $bar_present ? 'present' : 'absent' ) . ", favorites=" . ( $fav_present ? 'present' : 'absent' ) . ", alerts=" . ( $alerts_present ? 'present' : 'absent' ) . ", fafh=" . ( $fafh_present ? 'present' : 'absent' ) . ")\n\n";

	/* ---------------------------------------------------------------- 1. can_follow() */
	echo "1. can_follow()\n";
	reset_state(); $GLOBALS['_options']['hp_vendor_enable_display'] = '1'; $GLOBALS['_user'] = 5;
	$c = hivepress()->hpfv_follow;
	$v = make_vendor( 10, 7 );
	ok( $c->can_follow( $v ), 'C1 a published Vendor owned by somebody else can be followed' );
	ok( ! $c->can_follow( $v, 7 ), 'C2 the owner cannot follow their own Vendor' );
	ok( $c->can_follow( $v, 0 ), 'C3 a guest passes the ownership test (login branch renders)' );
	ok( ! $c->can_follow( make_vendor( 11, 7, 'draft' ) ), 'C4 a draft Vendor cannot be followed' );
	ok( ! $c->can_follow( new stdClass() ), 'C5 a non-Vendor object is refused' );
	$GLOBALS['_options']['hp_vendor_enable_display'] = '';
	ok( ! $c->can_follow( $v ), 'C6 with Vendor pages off nothing can be followed' );
	$GLOBALS['_options']['hp_vendor_enable_display'] = '1';
	add_filter( 'hpfv_can_follow', function ( $a, $vendor, $uid ) { return 10 === $vendor->get_id() ? false : $a; }, 10, 3 );
	ok( ! $c->can_follow( $v ), 'C7 hpfv_can_follow can refuse' );
	ok( $c->can_follow( make_vendor( 12, 7 ) ), 'C8 and leaves other Vendors alone' );

	/* ---------------------------------------------------------------- 2. queue_digest() */
	echo "2. queue_digest()\n";
	reset_state();
	$e = hivepress()->hpfv_follow_email;
	$l = make_listing( 50, 10 );
	$e->queue_digest( 50, 'draft', 'publish', $l );
	ok( [] === hivepress()->scheduler->added && ! get_post_meta( 50, 'hp_hpfv_notified_time', true ), 'Q1 a non-publish status is ignored' );
	$GLOBALS['_options']['hp_follow_vendors_for_hivepress_enable_email'] = '';
	$e->queue_digest( 50, 'publish', 'draft', $l );
	ok( [] === hivepress()->scheduler->added && get_post_meta( 50, 'hp_hpfv_notified_time', true ), 'Q2 with emails off the Listing IS stamped and no job is queued' );
	unset( $GLOBALS['_options']['hp_follow_vendors_for_hivepress_enable_email'] );
	$e->queue_digest( 50, 'publish', 'draft', $l );
	ok( [] === hivepress()->scheduler->added, 'Q2b so switching the emails back on never announces it on a later publish transition' );
	reset_state();
	$e = hivepress()->hpfv_follow_email;
	$l = make_listing( 50, 10 );
	$e->queue_digest( 50, 'publish', 'draft', new stdClass() );
	ok( [] === hivepress()->scheduler->added, 'Q3 a non-Listing object is ignored' );
	$before = time();
	$e->queue_digest( 50, 'publish', 'draft', $l );
	$window = (int) floor( time() / 600 );
	ok( 1 === count( hivepress()->scheduler->added ) && [ 10, $window ] === hivepress()->scheduler->added[0]['args'] && 'hpfv_send_follow_emails' === hivepress()->scheduler->added[0]['hook'], 'Q4 a publish queues [vendor, window] with the ten-minute window' );
	ok( hivepress()->scheduler->added[0]['time'] >= $before + 600, 'Q5 the job is delayed by the quiet period' );
	ok( get_post_meta( 50, 'hp_hpfv_notified_time', true ) >= $before, 'Q6 the Listing is stamped' );
	$e->queue_digest( 51, 'publish', 'pending', make_listing( 51, 10 ) );
	ok( 1 === count( hivepress()->scheduler->added ), 'Q7 a second publish in the same window shares the job (throttle)' );
	$stamp = get_post_meta( 50, 'hp_hpfv_notified_time', true );
	$e->queue_digest( 50, 'publish', 'draft', $l );
	ok( $stamp === get_post_meta( 50, 'hp_hpfv_notified_time', true ) && 1 === count( hivepress()->scheduler->added ), 'Q8 an already-stamped Listing (renewal, hide/unhide) neither restamps nor queues' );
	$GLOBALS['_as_pending'] = [];
	$e->queue_digest( 52, 'publish', 'draft', make_listing( 52, 10 ) );
	ok( 2 === count( hivepress()->scheduler->added ), 'Q9 once the job has run, the next publish gets a job of its own' );
	$GLOBALS['_options']['hp_follow_vendors_for_hivepress_email_delay'] = '30';
	ok( 1800 === $e->get_delay(), 'Q10 the quiet period setting is read in minutes' );
	$GLOBALS['_options']['hp_follow_vendors_for_hivepress_email_delay'] = 'abc';
	ok( 600 === $e->get_delay(), 'Q11 a non-numeric delay falls back to 10 minutes' );
	$GLOBALS['_options']['hp_follow_vendors_for_hivepress_email_delay'] = '99999';
	ok( 86400 === $e->get_delay(), 'Q12 the delay is clamped at one day' );

	/* ---------------------------------------------------------------- 3. send_digest() */
	echo "3. send_digest()\n";
	function digest_state( $listing_count, $follower_count, $stamp_offset = -100 ) {
		reset_state();
		make_vendor( 10, 7, 'publish', 'Acme' );
		for ( $i = 1; $i <= $listing_count; $i++ ) { make_listing( 200 + $i, 10, 'publish', 'Listing ' . $i ); update_post_meta( 200 + $i, 'hp_hpfv_notified_time', time() + $stamp_offset + $i ); }
		for ( $u = 1; $u <= $follower_count; $u++ ) { make_user( 300 + $u, 'u' . $u . '@example.com', 'User ' . $u ); follow( 300 + $u, 10 ); }
		return hivepress()->hpfv_follow_email;
	}
	$e = digest_state( 2, 3 );
	$e->send_digest( 10, 1 );
	ok( 3 === count( $GLOBALS['_sent'] ), 'S1 one email per follower' );
	$tokens = $GLOBALS['_sent'][0]['tokens'];
	ok( 'u1@example.com' === $GLOBALS['_sent'][0]['recipient'] && 'User 1' === $tokens['user_name'] && 'Acme' === $tokens['vendor_name'] && 2 === $tokens['listing_count'], 'S2 recipient and tokens are the follower and the Vendor' );
	ok( false !== strpos( $tokens['listings'], '?p=201' ) && false !== strpos( $tokens['listings'], '?p=202' ) && false !== strpos( $tokens['listings'], '<br>' ), 'S3 %listings% links every Listing, one per line' );
	ok( abs( (int) get_post_meta( 10, 'hp_hpfv_digest_time', true ) - ( time() - 1 ) ) <= 2, 'S4 the cursor is written as time() - 1 when fewer than 50 Listings were found', get_post_meta( 10, 'hp_hpfv_digest_time', true ) );
	$GLOBALS['_sent'] = [];
	$e->send_digest( 10, 1 );
	ok( 0 === count( $GLOBALS['_sent'] ), 'S5 running the same digest again sends nothing (cursor)' );
	ok( [] === hivepress()->scheduler->added, 'S6 fewer than 50 followers queues no continuation batch' );

	$e = digest_state( 51, 1, -1000 );
	$e->send_digest( 10, 1 );
	$cursor = (int) get_post_meta( 10, 'hp_hpfv_digest_time', true );
	ok( 50 === $GLOBALS['_sent'][0]['tokens']['listing_count'], 'S7 a digest announces at most 50 Listings' );
	ok( $cursor === (int) get_post_meta( 250, 'hp_hpfv_notified_time', true ), 'S8 with 50 announced the cursor is the 50th stamp, not now' );
	$GLOBALS['_sent'] = [];
	$e->send_digest( 10, 2 );
	ok( 1 === count( $GLOBALS['_sent'] ) && 1 === $GLOBALS['_sent'][0]['tokens']['listing_count'] && false !== strpos( $GLOBALS['_sent'][0]['tokens']['listings'], '?p=251' ), 'S9 the 51st Listing stays above the cursor and is announced by the next job' );

	$e = digest_state( 1, 1, 0 );
	update_post_meta( 201, 'hp_hpfv_notified_time', time() );
	$e->send_digest( 10, 1 );
	ok( 0 === count( $GLOBALS['_sent'] ), 'S10 a Listing stamped in the current second is excluded (same-second race)' );
	ok( '' === get_post_meta( 10, 'hp_hpfv_digest_time', true ), 'S11 and no cursor is written, so it is announced next time' );

	$e = digest_state( 1, 50 );
	$e->send_digest( 10, 1 );
	ok( 50 === count( $GLOBALS['_sent'] ), 'S12 a full batch of 50 followers is emailed' );
	$added = hivepress()->scheduler->added;
	ok( 1 === count( $added ) && 10 === $added[0]['args'][0] && 1 === $added[0]['args'][1] && 50 === $added[0]['args'][2] && 0 === $added[0]['args'][3] && $added[0]['args'][4] === (int) get_post_meta( 10, 'hp_hpfv_digest_time', true ) && null === $added[0]['time'], 'S13 a full batch queues the next one as [v, w, 50, since, until], async' );
	$GLOBALS['_sent'] = [];
	$e->send_digest( 10, 1, 50, $added[0]['args'][3], $added[0]['args'][4] );
	ok( 0 === count( $GLOBALS['_sent'] ), 'S14 the continuation batch finds no more followers and sends nothing' );
	$e = digest_state( 1, 51 );
	$e->send_digest( 10, 1 );
	$added = hivepress()->scheduler->added;
	$GLOBALS['_sent'] = [];
	$e->send_digest( 10, 1, 50, $added[0]['args'][3], $added[0]['args'][4] );
	ok( 1 === count( $GLOBALS['_sent'] ) && 'u51@example.com' === $GLOBALS['_sent'][0]['recipient'] && 1 === $GLOBALS['_sent'][0]['tokens']['listing_count'], 'S15 the continuation batch emails the 51st follower the same Listings' );

	$e = digest_state( 1, 1 );
	$GLOBALS['_options']['hp_follow_vendors_for_hivepress_enable_email'] = '';
	$e->send_digest( 10, 1 );
	ok( 0 === count( $GLOBALS['_sent'] ), 'S16 emails switched off after queueing sends nothing' );

	$e = digest_state( 1, 2 );
	$GLOBALS['_users'][301]->email = '';
	$e->send_digest( 10, 1 );
	ok( 1 === count( $GLOBALS['_sent'] ) && 'u2@example.com' === $GLOBALS['_sent'][0]['recipient'], 'S17 a follower with no email address is skipped' );

	$e = digest_state( 1, 1 );
	$GLOBALS['_vendors'][10]->status = 'draft';
	$e->send_digest( 10, 1 );
	ok( 0 === count( $GLOBALS['_sent'] ), 'S18 an unpublished Vendor sends nothing' );

	$e = digest_state( 1, 1 );
	$GLOBALS['_listings'][201]['title'] = 'Cheap <script>alert(1)</script> & "sofa"';
	$e->send_digest( 10, 1 );
	$html = $GLOBALS['_sent'][0]['tokens']['listings'];
	ok( false === strpos( $html, '<script>' ) && false !== strpos( $html, '&lt;script&gt;' ) && false !== strpos( $html, '&amp;' ), 'S19 %listings% escapes a title containing markup' );

	$e = digest_state( 1, 1 );
	$GLOBALS['_vendors'][10]->name = 'Acme <b>Ltd</b> & Sons<script>alert(1)</script>';
	$GLOBALS['_users'][301]->display_name = 'Pat <i>x</i>';
	$e->send_digest( 10, 1 );
	$tk = $GLOBALS['_sent'][0]['tokens'];
	ok( 'Acme Ltd & Sons' === $tk['vendor_name'] && 'Pat x' === $tk['user_name'], 'S20 the Vendor and user name tokens are stripped of tags, not escaped (the subject uses them too)', $tk['vendor_name'] . ' / ' . $tk['user_name'] );

	$e = digest_state( 2, 2 );
	add_filter( 'hpfv_digest_listings', function ( $ids ) { return [ 202 ]; } );
	add_filter( 'hpfv_digest_recipients', function ( $ids ) { return [ 302 ]; } );
	$e->send_digest( 10, 1 );
	ok( 1 === count( $GLOBALS['_sent'] ) && 'u2@example.com' === $GLOBALS['_sent'][0]['recipient'] && 1 === $GLOBALS['_sent'][0]['tokens']['listing_count'] && false !== strpos( $GLOBALS['_sent'][0]['tokens']['listings'], '?p=202' ), 'S20 hpfv_digest_listings and hpfv_digest_recipients both run' );

	/* ---------------------------------------------------------------- 4. Action Bar items */
	echo "4. Action Bar items\n";
	function bar_state( $user, $route ) {
		reset_state();
		$GLOBALS['_options']['hp_vendor_enable_display'] = '1';
		$GLOBALS['_user'] = $user;
		hivepress()->router->current = $route;
		make_vendor( 10, 7, 'publish', 'Acme' );
		make_listing( 40, 10 );
		hivepress()->request->set_context( 'vendor', $GLOBALS['_vendors'][10] );
		hivepress()->request->set_context( 'listing', $GLOBALS['_listings_objects'][40] );
		return hivepress()->hpfv_follow_action_bar;
	}
	$b = bar_state( 5, 'vendor_view_page' );
	$b->register();
	ok( $bar_present === has_filter( 'hivepress/v1/action_bar/items', [ $b, 'add_items' ] ), 'B1 the items filter is added only when Action Bar is present' );
	ok( $bar_present === has_filter( 'wp_enqueue_scripts', [ $b, 'enqueue_scripts' ] ), 'B2 the script hook likewise' );

	$items = $b->build_items( 'vendor_view_page' );
	$i = reset( $items );
	ok( 1 === count( $items ) && '#hpfv-follow-10' === $i['bar']['url'] && 'Follow' === $i['bar']['label'] && 'fas fa-user-plus' === $i['bar']['icon'] && '' === $i['bar']['link'] && 'default' === $i['bar']['style'], 'B3 Vendor page, user not following: fragment href, Follow label, plus icon' );
	ok( false === $i['data']['active'] && [ 'Follow', 'Following' ] === $i['data']['labels'] && [ 'fas fa-user-plus', 'fas fa-user-check' ] === $i['data']['icons'] && false !== strpos( $i['data']['url'], 'hpfv_vendor_follow_action/10' ), 'B4 script data carries both states and the REST url' );
	ok( $fafh_present ? ( 2 === count( $i['data']['paths'] ) && false !== strpos( $i['data']['paths'][0], '|' ) ) : [ '', '' ] === $i['data']['paths'], 'B5 icon paths are pairs with the icon library and empty without it' );

	hivepress()->request->set_context( 'hpfv_follow_ids', [ 10 ] );
	$items = $b->build_items( 'vendor_view_page' );
	$i = reset( $items );
	ok( 'Following' === $i['bar']['label'] && 'fas fa-user-check' === $i['bar']['icon'] && true === $i['data']['active'], 'B6 following: the current state is server-rendered' );

	$b = bar_state( 0, 'vendor_view_page' );
	$items = $b->build_items( 'vendor_view_page' );
	$i = reset( $items );
	ok( 1 === count( $items ) && 'auth_modal' === $i['bar']['link'] && 'https://example.test/user_login_page/?redirect=x' === $i['bar']['url'] && 'Follow' === $i['bar']['label'] && null === $i['data'], 'B7 guest: auth_modal item pointing at the login page, no script data' );

	$b = bar_state( 7, 'vendor_view_page' );
	ok( [] === $b->build_items( 'vendor_view_page' ), 'B8 the owner gets no follow item on their own Vendor' );

	$b = bar_state( 5, 'listing_view_page' );
	$items = $b->build_items( 'listing_view_page' );
	ok( 1 === count( $items ) && isset( $items['hpfv-follow-10'] ), 'B9 Listing page: the follow item and nothing else' );

	/*
	 * The Save and Alert items moved to Action Bar for HivePress (its Quick Actions section), so this
	 * plugin must produce neither, even with the Favorites context set and a live search in progress.
	 * Two plugins adding the same button would show the visitor two of it.
	 */
	hivepress()->request->set_context( 'favorite_ids', [ 40 ] );
	$items = $b->build_items( 'listing_view_page' );
	ok( 1 === count( $items ) && ! isset( $items['hpfv-favorite-40'] ), 'B10 no save item, whatever the Favorites extension says' );

	$GLOBALS['_is_search'] = true;
	$GLOBALS['_alert_form'] = new Fake_Form( [ 's' => 'sofa', 'post_type' => 'hp_listing' ] );
	$b = bar_state( 5, 'listings_view_page' );
	ok( [] === $b->build_items( 'listings_view_page' ), 'B11 no alert item on a search page' );
	ok( [] === $b->build_items( 'requests_view_page' ), 'B12 nor on the Requests search route' );
	$GLOBALS['_is_search'] = false;

	// bar_state() rebuilds the option table, so the switch is flipped after it, not before.
	$b = bar_state( 5, 'listing_view_page' );
	$GLOBALS['_options']['hp_follow_vendors_for_hivepress_bar_follow'] = '';
	ok( [] === $b->build_items( 'listing_view_page' ) && [] === $b->build_items( 'vendor_view_page' ), 'B13 the follow item unticked gives nothing' );
	unset( $GLOBALS['_options']['hp_follow_vendors_for_hivepress_bar_follow'] );

	$b = bar_state( 5, 'vendor_view_page' );
	add_filter( 'hpfv_action_bar_items', function ( $items, $route, $bar ) { $items[] = [ 'label' => 'extra:' . $route . ':' . $bar ]; return $items; }, 10, 3 );
	$out = $b->add_items( [ [ 'link' => 'home' ] ], 'user' );
	ok( 3 === count( $out ) && 'home' === $out[0]['link'] && '#hpfv-follow-10' === $out[1]['url'] && 'extra:vendor_view_page:user' === $out[2]['label'], 'B26 add_items() appends after the owner rows and hpfv_action_bar_items runs last' );

	$b = bar_state( 5, 'vendor_view_page' );
	$b->enqueue_scripts();
	ok( isset( $GLOBALS['_enqueued']['hpfv-frontend'] ) && [ 'hivepress-action-bar-frontend' ] === $GLOBALS['_enqueued']['hpfv-frontend']['deps'] && isset( $GLOBALS['_localized']['hpfvData']['items']['hpfv-follow-10'] ), 'B27 the script is enqueued after the bar script with nested data' );
	$b = bar_state( 0, 'vendor_view_page' );
	$b->enqueue_scripts();
	ok( [] === $GLOBALS['_enqueued'], 'B28 a guest-only item enqueues no script' );
	$b = bar_state( 5, 'vendor_view_page' );
	$GLOBALS['_bar_enqueued'] = false;
	$b->enqueue_scripts();
	ok( [] === $GLOBALS['_enqueued'], 'B29 no bar script on the page (bar hidden) means no script of ours' );
	$b = bar_state( 5, 'listing_edit_page' );
	ok( [] === $b->build_items( 'listing_edit_page' ), 'B30 the vendor context on an edit screen does not produce an item (route gate)' );

	/* ---------------------------------------------------------------- 5. Settings read-through */
	echo "5. Settings read-through\n";
	reset_state();
	ok( true === hpfv_get_option( 'follow_vendors_for_hivepress_enable_email', true ), 'R1 an absent option reads its default' );
	$GLOBALS['_options']['hp_follow_vendors_for_hivepress_enable_email'] = '';
	ok( '' === hpfv_get_option( 'follow_vendors_for_hivepress_enable_email', true ), "R2 a stored '' is a deliberate off" );
	ok( 10 === hpfv_get_number_option( 'follow_vendors_for_hivepress_email_delay', 10 ), 'R3 an absent number reads its default' );
	$GLOBALS['_options']['hp_follow_vendors_for_hivepress_email_delay'] = '';
	ok( 10 === hpfv_get_number_option( 'follow_vendors_for_hivepress_email_delay', 10 ), 'R4 a cleared number reads its default, not 0' );
	$GLOBALS['_options']['hp_follow_vendors_for_hivepress_email_delay'] = '0';
	ok( 0 === hpfv_get_number_option( 'follow_vendors_for_hivepress_email_delay', 10 ), 'R5 an explicit 0 is respected' );
	ok( 'both' === hpfv_get_choice_option( 'follow_vendors_for_hivepress_toggle_location', [ 'both', 'block', 'page' ], 'both' ), 'R6 an absent radio reads its default' );
	$GLOBALS['_options']['hp_follow_vendors_for_hivepress_toggle_location'] = '';
	ok( 'both' === hpfv_get_choice_option( 'follow_vendors_for_hivepress_toggle_location', [ 'both', 'block', 'page' ], 'both' ), "R7 a radio stored as '' reads its default" );
	$GLOBALS['_options']['hp_follow_vendors_for_hivepress_toggle_location'] = 'nonsense';
	ok( 'both' === hpfv_get_choice_option( 'follow_vendors_for_hivepress_toggle_location', [ 'both', 'block', 'page' ], 'both' ), 'R8 a value the screen cannot store reads the default' );
	$GLOBALS['_options']['hp_follow_vendors_for_hivepress_toggle_location'] = 'page';
	$c = hivepress()->hpfv_follow;
	ok( ! $c->is_toggle_enabled( 'block' ) && $c->is_toggle_enabled( 'page' ), 'R9 toggle_location=page: page only' );
	$GLOBALS['_options']['hp_follow_vendors_for_hivepress_toggle_location'] = 'block';
	ok( $c->is_toggle_enabled( 'block' ) && ! $c->is_toggle_enabled( 'page' ), 'R10 toggle_location=block: cards only' );

	/* ---------------------------------------------------------------- 6. Labels flow */
	echo "6. Labels flow\n";
	reset_state(); $GLOBALS['_options']['hp_vendor_enable_display'] = '1'; $GLOBALS['_user'] = 5;
	make_vendor( 10, 7 );
	$GLOBALS['_options']['hp_follow_vendors_for_hivepress_follow_label'] = 'Keep me posted';
	$GLOBALS['_options']['hp_follow_vendors_for_hivepress_following_label'] = 'Posted';
	$GLOBALS['_options']['hp_follow_vendors_for_hivepress_page_title'] = 'My Sellers';
	$t = ( new \HivePress\Blocks\Hpfv_Follow_Toggle( [ 'context' => [ 'vendor' => $GLOBALS['_vendors'][10] ] ] ) )->render();
	ok( false !== strpos( $t, '<span>Keep me posted</span>' ) && false !== strpos( $t, 'data-caption="Posted"' ) && false !== strpos( $t, 'data-component="toggle"' ) && false !== strpos( $t, 'fa-user-plus' ), 'L1 the toggle renders the Follow label and carries the Following label for the swap', $t );
	hivepress()->request->set_context( 'hpfv_follow_ids', [ 10 ] );
	$t = ( new \HivePress\Blocks\Hpfv_Follow_Toggle( [ 'context' => [ 'vendor' => $GLOBALS['_vendors'][10] ] ] ) )->render();
	ok( false !== strpos( $t, '<span>Posted</span>' ) && false !== strpos( $t, 'data-state="active"' ) && false !== strpos( $t, 'fa-user-check' ), 'L2 when following: the Following label, active state, check icon' );
	$t = ( new \HivePress\Blocks\Hpfv_Follow_Toggle( [ 'context' => [ 'vendor' => $GLOBALS['_vendors'][10] ], 'view' => 'icon' ] ) )->render();
	ok( false === strpos( $t, '<span>' ) && false !== strpos( $t, 'title="Posted"' ), 'L3 icon view (cards) carries the caption in the title' );
	$GLOBALS['_user'] = 7;
	ok( '' === ( new \HivePress\Blocks\Hpfv_Follow_Toggle( [ 'context' => [ 'vendor' => $GLOBALS['_vendors'][10] ] ] ) )->render(), 'L4 the owner sees nothing' );
	$GLOBALS['_user'] = 0;
	$t = ( new \HivePress\Blocks\Hpfv_Follow_Toggle( [ 'context' => [ 'vendor' => $GLOBALS['_vendors'][10] ] ] ) )->render();
	ok( false !== strpos( $t, 'href="#user_login_modal"' ) && false === strpos( $t, 'data-url' ), 'L5 a guest gets the login modal link and no REST url' );
	$ctl = new \HivePress\Controllers\Hpfv_Follow();
	ok( 'My Sellers' === $ctl->routes['hpfv_vendors_follow_page']['title'] && '/followed-vendors' === $ctl->routes['hpfv_vendors_follow_page']['path'] && 'user_account_page' === $ctl->routes['hpfv_vendors_follow_page']['base'] && ! empty( $ctl->routes['hpfv_vendors_follow_page']['paginated'] ), 'L6 the page title setting is the route title (a string, never a callable)' );
	ok( 'vendors_resource' === $ctl->routes['hpfv_vendor_resource']['base'] && 'hpfv_vendor_resource' === $ctl->routes['hpfv_vendor_follow_action']['base'] && 'POST' === $ctl->routes['hpfv_vendor_follow_action']['method'] && ! empty( $ctl->routes['hpfv_vendor_follow_action']['rest'] ), 'L7 the REST route hangs off a child of core vendors_resource' );
	$GLOBALS['_user'] = 5;
	hivepress()->router->current = 'vendor_view_page';
	hivepress()->request->set_context( 'vendor', $GLOBALS['_vendors'][10] );
	$items = hivepress()->hpfv_follow_action_bar->build_items( 'vendor_view_page' );
	ok( 'Posted' === reset( $items )['bar']['label'] && [ 'Keep me posted', 'Posted' ] === reset( $items )['data']['labels'], 'L8 the labels flow into the bar item' );
	$GLOBALS['_options']['hp_follow_vendors_for_hivepress_follow_label'] = '';
	ok( 'Follow' === hivepress()->hpfv_follow->get_follow_label(), 'L9 a cleared label falls back to the translatable default' );
	hivepress()->request->set_context( 'hpfv_follow_ids', [] );
	$m = hivepress()->hpfv_follow->alter_account_menu( [ 'items' => [] ] );
	ok( [ 'items' => [] ] === $m, 'L10 no menu item with nothing followed' );
	hivepress()->request->set_context( 'hpfv_follow_ids', [ 10 ] );
	$m = hivepress()->hpfv_follow->alter_account_menu( [ 'items' => [] ] );
	ok( isset( $m['items']['hpfv_vendors_follow'] ) && 22 === $m['items']['hpfv_vendors_follow']['_order'] && 'hpfv_vendors_follow_page' === $m['items']['hpfv_vendors_follow']['route'], 'L10b the menu item appears at _order 22 once a published Vendor is followed' );
	$GLOBALS['_vendors'][10]->status = 'draft';
	ok( [ 'items' => [] ] === hivepress()->hpfv_follow->alter_account_menu( [ 'items' => [] ] ), 'L10c a followed Vendor that is no longer published does not count' );
	$GLOBALS['_vendors'][10]->status = 'publish';
	$cnt = ( new \HivePress\Blocks\Hpfv_Follower_Count( [ 'context' => [ 'vendor' => $GLOBALS['_vendors'][10] ] ] ) )->render();
	ok( '' === $cnt, 'L11 the follower count renders nothing at zero' );
	follow( 5, 10 ); follow( 6, 10 );
	hivepress()->cache->post = [];
	$cnt = ( new \HivePress\Blocks\Hpfv_Follower_Count( [ 'context' => [ 'vendor' => $GLOBALS['_vendors'][10] ] ] ) )->render();
	ok( false !== strpos( $cnt, '2 followers' ) && false !== strpos( $cnt, 'fa-users' ), 'L12 and the count with the plural once there are followers' );
	ok( 2 === hivepress()->cache->post[10]['follower_count'], 'L13 the count is cached as an integer on the Vendor' );

	/* ---------------------------------------------------------------- 6b. Page button style, settings shape, activation */
	echo "6b. Page button style, settings shape, activation\n";
	reset_state(); $GLOBALS['_options']['hp_vendor_enable_display'] = '1';
	$c = hivepress()->hpfv_follow;
	$c->enqueue_styles();
	$css = implode( '', $GLOBALS['_inline_styles']['hivepress-core-frontend'] ?? [] );
	ok( false !== strpos( $css, '.hp-vendor__action--follow.button.hp-link i' ) && false !== strpos( $css, 'color:inherit' ), 'P1 the page button icon rule is attached to core\'s front-end stylesheet', $css );
	$GLOBALS['_inline_styles'] = [];
	$GLOBALS['_options']['hp_follow_vendors_for_hivepress_toggle_location'] = 'block';
	$c->enqueue_styles();
	ok( [] === $GLOBALS['_inline_styles'], 'P2 no rule when the page button is switched off' );
	$GLOBALS['_options']['hp_follow_vendors_for_hivepress_toggle_location'] = 'both';
	$GLOBALS['_options']['hp_vendor_enable_display'] = '';
	$c->enqueue_styles();
	ok( [] === $GLOBALS['_inline_styles'], 'P3 nor when Vendor pages are off' );
	$GLOBALS['_hooks'] = [];
	new \HivePress\Components\Hpfv_Follow();
	ok( has_filter( 'wp_enqueue_scripts', [ $GLOBALS['_hp']->hpfv_follow, 'enqueue_styles' ] ) || ! empty( $GLOBALS['_hooks']['wp_enqueue_scripts'] ), 'P4 the style hook is registered on the front end' );
	$cfg = require dirname( __DIR__ ) . '/includes/configs/settings.php';
	$label_fields = $cfg['follow_vendors']['sections']['labels']['fields'];
	$shape_ok = true;
	foreach ( [ 'follow_label' => 'Follow', 'following_label' => 'Following', 'page_title' => 'Followed Vendors' ] as $key => $word ) {
		$f = $label_fields[ HPFV_OPTION_PREFIX . $key ];
		if ( array_key_exists( 'default', $f ) || $word !== ( $f['placeholder'] ?? null ) ) { $shape_ok = false; }
	}
	ok( $shape_ok, 'P5 the three label fields carry a placeholder and no default (a stored default would defeat translation)' );
	ok( 'hpfv_activate' === ( $GLOBALS['_activation'] ?? null ) && function_exists( 'hpfv_activate' ), 'P6 an activation hook is registered' );
	$GLOBALS['_options']['rewrite_rules'] = 'x';
	hpfv_activate();
	ok( ! array_key_exists( 'rewrite_rules', $GLOBALS['_options'] ), 'P7 and it flushes the rewrite rules' );

	/* ---------------------------------------------------------------- 7. Version drift */
	echo "7. Version drift\n";
	$main_src = file_get_contents( dirname( __DIR__ ) . '/follow-vendors-for-hivepress.php' );
	$readme = file_get_contents( dirname( __DIR__ ) . '/readme.txt' );
	preg_match( '/^ \* Version: (.+)$/m', $main_src, $hv );
	preg_match( '/^Stable tag: (.+)$/m', $readme, $st );
	ok( trim( $hv[1] ) === HPFV_VERSION && trim( $st[1] ) === HPFV_VERSION, 'V1 header, constant and Stable tag agree', trim( $hv[1] ) . ' / ' . HPFV_VERSION . ' / ' . trim( $st[1] ) );
	ok( 1 === preg_match( '/^= ' . preg_quote( HPFV_VERSION, '/' ) . ' =$/m', $readme ), 'V2 the changelog has an entry for this version' );
	ok( false !== strpos( $main_src, ' * Author: ChrisB @ HivePress Community' ) && false !== strpos( $main_src, ' * Author URI: https://community.hivepress.io/u/chrisb/summary' ), 'V3 the author credit is byte for byte the house one' );
	$all = '';
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/includes' ) ) as $file ) { if ( 'php' === $file->getExtension() ) { $all .= file_get_contents( $file->getPathname() ); } }
	$all .= $main_src . $readme . file_get_contents( dirname( __DIR__ ) . '/assets/js/frontend.js' );
	ok( false === strpos( $all, "\xE2\x80\x94" ), 'V4 no em-dash anywhere in code or copy' );
	ok( false === strpos( file_get_contents( dirname( __DIR__ ) . '/assets/js/frontend.js' ), 'innerHTML' ), 'V6 no innerHTML in the front-end script' );

	echo "\n----------------------------------------\n";
	echo "RESULT: {$GLOBALS['_pass']} passed, {$GLOBALS['_fail']} failed\n";
	exit( $GLOBALS['_fail'] > 0 ? 1 : 0 );
}
