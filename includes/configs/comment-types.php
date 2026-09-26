<?php
/**
 * Comment types configuration.
 *
 * This file keeps core's fixed name on purpose: HivePress looks a config up by bare file name
 * (class-core.php:417) and MERGES every extension's comment-types.php into one array, so a
 * prefixed copy would never be read. Only the key inside is ours.
 *
 * `public => false` is what keeps the follow rows out of every comment query, feed, admin
 * activity widget and comment count while the plugin is active (core's Comment component,
 * components/class-comment.php:54-97, core 1.7.31).
 *
 * @package Follow_Vendors
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

return [
	'hpfv_follow' => [
		'public' => false,
	],
];
