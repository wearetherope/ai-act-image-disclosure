<?php
/**
 * Plugin Name:       Ropemark Image Marking for the EU AI Act
 * Plugin URI:        https://github.com/wearetherope/ai-act-image-disclosure
 * Description:       Mark, keep and disclose AI-generated images: writes the IPTC marking where it is missing, keeps it in every size WordPress generates, adds an AI badge with provenance popup and documents everything in the Media Library. EU AI Act, article 50.
 * Version:           1.0.1
 * Requires at least: 6.1
 * Requires PHP:      7.4
 * Author:            The Rope
 * Author URI:        https://therope.it
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ropemark-image-marking-for-eu-ai-act
 *
 * Copyright (C) 2026 The Chain S.r.l. (The Rope)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * @package Ropemark_Image_Marking
 */

defined( 'ABSPATH' ) || exit;

define( 'ROPEMARK_VERSION', '1.0.1' );
// Set to true in wp-config.php to have the credit line in the provenance popup on by default.
if ( ! defined( 'ROPEMARK_CREDIT_DEFAULT' ) ) {
	define( 'ROPEMARK_CREDIT_DEFAULT', false );
}
define( 'ROPEMARK_FILE', __FILE__ );
define( 'ROPEMARK_DIR', plugin_dir_path( __FILE__ ) );
define( 'ROPEMARK_URL', plugin_dir_url( __FILE__ ) );

require_once ROPEMARK_DIR . 'includes/class-ropemark-segments.php';
require_once ROPEMARK_DIR . 'includes/class-ropemark-reader.php';
require_once ROPEMARK_DIR . 'includes/class-ropemark-options.php';
require_once ROPEMARK_DIR . 'includes/class-ropemark-processor.php';
require_once ROPEMARK_DIR . 'includes/class-ropemark-frontend.php';

if ( is_admin() ) {
	require_once ROPEMARK_DIR . 'includes/class-ropemark-admin.php';
}
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once ROPEMARK_DIR . 'includes/class-ropemark-cli.php';
}

/**
 * Boot on init: settings and meta registration use translated strings,
 * and WordPress 6.7+ wants translations loaded no earlier than init.
 */
function ropemark_init() {
	Ropemark_Options::init();
	Ropemark_Processor::init();
	Ropemark_Frontend::init();
	if ( is_admin() ) {
		Ropemark_Admin::init();
	}
}
add_action( 'init', 'ropemark_init' );

/**
 * Activation: store defaults once.
 */
function ropemark_activate() {
	if ( false === get_option( Ropemark_Options::OPTION ) ) {
		add_option( Ropemark_Options::OPTION, Ropemark_Options::defaults() );
	}
	set_transient( 'ropemark_activated', 1, 300 );
}
register_activation_hook( __FILE__, 'ropemark_activate' );

/**
 * Deactivation: stop any background scan and its schedule.
 */
function ropemark_deactivate() {
	Ropemark_Processor::bg_stop();
}
register_deactivation_hook( __FILE__, 'ropemark_deactivate' );

/**
 * Public helper: provenance record of an attachment.
 *
 * @param int $attachment_id Attachment id.
 * @return array|null Record, or null when never scanned.
 */
function ropemark_get_provenance( $attachment_id ) {
	return Ropemark_Processor::record( (int) $attachment_id );
}

/**
 * Public helper: is the attachment marked as AI generated or AI composite?
 *
 * @param int $attachment_id Attachment id.
 * @return bool
 */
function ropemark_is_ai_generated( $attachment_id ) {
	return '1' === get_post_meta( (int) $attachment_id, Ropemark_Processor::META_AI, true );
}
