<?php
/**
 * Plugin Name:       AI Act Image Disclosure
 * Plugin URI:        https://github.com/wearetherope/ai-act-image-disclosure
 * Description:       Keeps the AI provenance marking of uploaded images (IPTC DigitalSourceType, XMP, C2PA), carries it into every generated size and shows it in the Media Library. Built for the EU AI Act article 50 marking obligation.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            The Rope
 * Author URI:        https://therope.it
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-act-image-disclosure
 * Domain Path:       /languages
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
 * @package AI_Act_Image_Disclosure
 */

defined( 'ABSPATH' ) || exit;

define( 'AIPK_VERSION', '1.0.0' );
define( 'AIPK_FILE', __FILE__ );
define( 'AIPK_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIPK_URL', plugin_dir_url( __FILE__ ) );

require_once AIPK_DIR . 'includes/class-aipk-segments.php';
require_once AIPK_DIR . 'includes/class-aipk-reader.php';
require_once AIPK_DIR . 'includes/class-aipk-options.php';
require_once AIPK_DIR . 'includes/class-aipk-processor.php';
require_once AIPK_DIR . 'includes/class-aipk-frontend.php';

if ( is_admin() ) {
	require_once AIPK_DIR . 'includes/class-aipk-admin.php';
}
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once AIPK_DIR . 'includes/class-aipk-cli.php';
}

/**
 * Boot on init: settings and meta registration use translated strings,
 * and WordPress 6.7+ wants translations loaded no earlier than init.
 */
function aipk_init() {
	load_plugin_textdomain( 'ai-act-image-disclosure', false, dirname( plugin_basename( AIPK_FILE ) ) . '/languages' );
	AIPK_Options::init();
	AIPK_Processor::init();
	AIPK_Frontend::init();
	if ( is_admin() ) {
		AIPK_Admin::init();
	}
}
add_action( 'init', 'aipk_init' );

/**
 * Activation: store defaults once.
 */
function aipk_activate() {
	if ( false === get_option( AIPK_Options::OPTION ) ) {
		add_option( AIPK_Options::OPTION, AIPK_Options::defaults() );
	}
}
register_activation_hook( __FILE__, 'aipk_activate' );

/**
 * Public helper: provenance record of an attachment.
 *
 * @param int $attachment_id Attachment id.
 * @return array|null Record, or null when never scanned.
 */
function aipk_get_provenance( $attachment_id ) {
	return AIPK_Processor::record( (int) $attachment_id );
}

/**
 * Public helper: is the attachment marked as AI generated or AI composite?
 *
 * @param int $attachment_id Attachment id.
 * @return bool
 */
function aipk_is_ai_generated( $attachment_id ) {
	return '1' === get_post_meta( (int) $attachment_id, AIPK_Processor::META_AI, true );
}
