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
