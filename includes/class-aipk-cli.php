<?php
/**
 * WP-CLI commands.
 *
 * @package AI_Act_Image_Disclosure
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scan and inspect image provenance from the command line.
 */
class AIPK_CLI {

	/**
	 * Scan the media library: read provenance and carry the marking into every size.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Re-scan attachments that were already scanned.
	 *
	 * [--dry-run]
	 * : Only report what would be scanned.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-provenance scan
	 *     wp ai-provenance scan --all
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 */
	public function scan( $args, $assoc_args ) {
		global $wpdb;
		$all = isset( $assoc_args['all'] );
		$dry = isset( $assoc_args['dry-run'] );
		if ( $all ) {
			$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png','image/webp') ORDER BY ID" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = 'attachment' AND p.post_mime_type IN ('image/jpeg','image/png','image/webp') AND m.meta_id IS NULL ORDER BY p.ID", AIPK_Processor::META_KEY ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$n = count( $ids );
		if ( $dry ) {
			WP_CLI::success( sprintf( '%d attachments would be scanned.', $n ) );
			return;
		}
		if ( ! $n ) {
			WP_CLI::success( 'Nothing to scan.' );
			return;
		}
		$bar  = \WP_CLI\Utils\make_progress_bar( 'Scanning', $n );
		$n_ai = 0;
		$i    = 0;
		foreach ( $ids as $id ) {
			$r = AIPK_Processor::process( (int) $id );
			if ( $r && $r['ai'] ) {
				$n_ai++;
			}
			$bar->tick();
			if ( 0 === ++$i % 100 ) {
				AIPK_Processor::release_memory();
			}
		}
		$bar->finish();
		WP_CLI::success( sprintf( '%d attachments scanned, %d marked as AI.', $n, $n_ai ) );
	}

	/**
	 * Show the stored provenance of one attachment.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Attachment id.
	 *
	 * @param array $args Positional.
	 */
	public function status( $args ) {
		$id = (int) $args[0];
		$r  = AIPK_Processor::record( $id );
		if ( ! $r ) {
			WP_CLI::error( 'Not scanned yet. Run: wp ai-provenance scan --all' );
		}
		WP_CLI::print_value( $r, array( 'format' => 'yaml' ) );
		$sizes = get_post_meta( $id, AIPK_Processor::META_SIZES, true );
		if ( is_array( $sizes ) ) {
			unset( $sizes['_file'] );
			WP_CLI::print_value( $sizes, array( 'format' => 'yaml' ) );
		}
	}
}

WP_CLI::add_command( 'ai-provenance', 'AIPK_CLI' );
