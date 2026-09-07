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
		$all  = isset( $assoc_args['all'] );
		$dry  = isset( $assoc_args['dry-run'] );
		$ids  = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp' ),
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$n_ai = 0;
		$n    = 0;
		foreach ( $ids as $id ) {
			if ( ! $all && AIPK_Processor::record( $id ) ) {
				continue;
			}
			$n++;
			if ( $dry ) {
				WP_CLI::log( "would scan #$id " . basename( get_attached_file( $id ) ) );
				continue;
			}
			$r = AIPK_Processor::process( $id );
			if ( $r && $r['ai'] ) {
				$n_ai++;
				WP_CLI::log( "#$id " . basename( get_attached_file( $id ) ) . ' -> ' . $r['digital_source_type'] );
			}
		}
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
