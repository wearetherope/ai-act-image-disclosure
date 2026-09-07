<?php
/**
 * Uninstall: remove the option and the computed attachment meta.
 * Image files are left exactly as they are.
 *
 * @package AI_Act_Image_Disclosure
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'aipk_settings' );
delete_metadata( 'post', 0, '_aipk_provenance', '', true );
delete_metadata( 'post', 0, '_aipk_ai', '', true );
delete_metadata( 'post', 0, '_aipk_sizes', '', true );
delete_metadata( 'post', 0, '_aipk_manual', '', true );
delete_metadata( 'post', 0, '_aipk_disclose', '', true );
delete_metadata( 'post', 0, '_aipk_suspect', '', true );
delete_metadata( 'post', 0, '_aipk_marked', '', true );
delete_option( 'aipk_bg_scan' );
delete_transient( 'aipk_counts' );
delete_transient( 'aipk_forced_disclosure' );
delete_transient( 'aipk_bg_done' );
wp_clear_scheduled_hook( 'aipk_bg_scan_run' );
