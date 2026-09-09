<?php
/**
 * Uninstall: remove the option and the computed attachment meta.
 * Image files are left exactly as they are.
 *
 * @package Ropemark_Image_Marking
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'ropemark_settings' );
delete_metadata( 'post', 0, '_ropemark_provenance', '', true );
delete_metadata( 'post', 0, '_ropemark_ai', '', true );
delete_metadata( 'post', 0, '_ropemark_sizes', '', true );
delete_metadata( 'post', 0, '_ropemark_manual', '', true );
delete_metadata( 'post', 0, '_ropemark_disclose', '', true );
delete_metadata( 'post', 0, '_ropemark_suspect', '', true );
delete_metadata( 'post', 0, '_ropemark_marked', '', true );
delete_option( 'ropemark_bg_scan' );
delete_transient( 'ropemark_counts' );
delete_transient( 'ropemark_forced_disclosure' );
delete_transient( 'ropemark_bg_done' );
wp_clear_scheduled_hook( 'ropemark_bg_scan_run' );
