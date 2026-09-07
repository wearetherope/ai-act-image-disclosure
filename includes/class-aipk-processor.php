<?php
/**
 * Hooks into the upload pipeline: reads provenance from the original,
 * stores it as attachment meta and re-injects XMP/IPTC into every
 * generated size, including the "-scaled" file WordPress serves as full.
 *
 * @package AI_Act_Image_Disclosure
 */

defined( 'ABSPATH' ) || exit;

/**
 * Upload pipeline processor.
 */
class AIPK_Processor {

	const META_KEY   = '_aipk_provenance';
	const META_AI    = '_aipk_ai';
	const META_SIZES = '_aipk_sizes';

	/**
	 * Register hooks.
	 */
	public static function init() {
		// Runs after every size has been generated, for uploads and regenerations alike.
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_generate_metadata' ), 999, 2 );
		// Image edits (crop, rotate) produce a new original: scan it again.
		add_filter( 'wp_update_attachment_metadata', array( __CLASS__, 'on_update_metadata' ), 999, 2 );
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete' ) );
	}

	/**
	 * Process an attachment right after its sizes were generated.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment id.
	 * @return array Unchanged metadata.
	 */
	public static function on_generate_metadata( $metadata, $attachment_id ) {
		self::process( $attachment_id, $metadata );
		return $metadata;
	}

	/**
	 * Re-scan when metadata is updated by the image editor (new file names).
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment id.
	 * @return array
	 */
	public static function on_update_metadata( $metadata, $attachment_id ) {
		if ( did_filter( 'wp_generate_attachment_metadata' ) ) {
			return $metadata; // already handled in the same request.
		}
		$stored = get_post_meta( $attachment_id, self::META_SIZES, true );
		$file   = isset( $metadata['file'] ) ? $metadata['file'] : '';
		if ( is_array( $stored ) && isset( $stored['_file'] ) && $stored['_file'] === $file ) {
			return $metadata; // same files as last time, nothing new to inject.
		}
		self::process( $attachment_id, $metadata );
		return $metadata;
	}

	/**
	 * Read the original, store the record, inject into derivatives.
	 *
	 * @param int   $attachment_id Attachment id.
	 * @param array $metadata      Attachment metadata (may be empty for non-images).
	 * @return array|null The record, or null when not an image we handle.
	 */
	public static function process( $attachment_id, $metadata = null ) {
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return null;
		}
		if ( null === $metadata ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
		}
		$original = self::original_path( $attachment_id, $metadata );
		if ( ! $original || ! file_exists( $original ) ) {
			return null;
		}
		$record = AIPK_Reader::read( $original );
		if ( ! $record['readable'] ) {
			return null;
		}
		$record['original_clean'] = self::maybe_clean_original( $original, $record );
		if ( 'cleaned' === $record['original_clean'] ) {
			$record = array_merge( $record, AIPK_Reader::read( $original ), array( 'original_clean' => 'cleaned' ) );
		}
		$record['scanned_at'] = time();
		update_post_meta( $attachment_id, self::META_KEY, $record );
		update_post_meta( $attachment_id, self::META_AI, $record['ai'] ? '1' : '0' );

		$sizes = self::inject_all( $attachment_id, $metadata, $original, $record );
		$sizes['_file'] = isset( $metadata['file'] ) ? $metadata['file'] : '';
		update_post_meta( $attachment_id, self::META_SIZES, $sizes );
		return $record;
	}

	/**
	 * Absolute path of the untouched upload (the original_image when WordPress scaled it).
	 *
	 * @param int   $attachment_id Attachment id.
	 * @param array $metadata      Metadata.
	 * @return string
	 */
	public static function original_path( $attachment_id, $metadata ) {
		if ( ! empty( $metadata['original_image'] ) && function_exists( 'wp_get_original_image_path' ) ) {
			$p = wp_get_original_image_path( $attachment_id );
			if ( $p ) {
				return $p;
			}
		}
		return get_attached_file( $attachment_id );
	}

	/**
	 * Inject the bundle into every derivative that lacks the marking.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param array  $metadata      Metadata.
	 * @param string $original      Original path.
	 * @param array  $record        Provenance record.
	 * @return array size => 'kept'|'injected'|'skipped'|'error: ...'
	 */
	public static function inject_all( $attachment_id, $metadata, $original, $record ) {
		$result = array();
		$scope  = AIPK_Options::get( 'scope' );
		$want   = ( 'all' === $scope ) ? ( $record['has_xmp'] || $record['has_iptc'] ) : $record['ai'];
		/**
		 * Whether the marking should be carried into derivatives for this attachment.
		 *
		 * @param bool  $want          Default decision from the "scope" option.
		 * @param int   $attachment_id Attachment id.
		 * @param array $record        Provenance record.
		 */
		$want = apply_filters( 'aipk_should_preserve', $want, $attachment_id, $record );
		if ( ! $want ) {
			return $result;
		}
		$mode = AIPK_Options::get( 'derivative_mode' );
		if ( 'none' === $mode ) {
			return $result;
		}
		$bundle = AIPK_Segments::extract( $original );
		if ( null === $bundle || ( '' === $bundle['xmp'] && '' === $bundle['iptc'] ) ) {
			return $result;
		}
		if ( 'minimal' === $mode ) {
			// Only the provenance fields travel: no Camera Raw settings, no history, no IPTC block.
			$bundle['xmp']          = AIPK_Segments::build_xmp( $record );
			$bundle['xmp_extended'] = array();
			$bundle['iptc']         = '';
		}
		$skip    = (array) AIPK_Options::get( 'skip_sizes' );
		$targets = self::derivative_paths( $attachment_id, $metadata, $original );
		foreach ( $targets as $size => $path ) {
			if ( in_array( $size, $skip, true ) ) {
				$result[ $size ] = 'skipped';
				continue;
			}
			if ( ! file_exists( $path ) ) {
				$result[ $size ] = 'missing';
				continue;
			}
			$existing = AIPK_Reader::read( $path );
			if ( $existing['readable'] && $existing['digital_source_type'] === $record['digital_source_type'] && '' !== $existing['digital_source_type'] ) {
				$result[ $size ] = 'kept';
				continue;
			}
			if ( ! $existing['readable'] ) {
				$result[ $size ] = 'unsupported';
				continue;
			}
			$ok = AIPK_Segments::inject( $path, $bundle );
			$result[ $size ] = ( true === $ok ) ? 'injected' : 'error: ' . $ok->get_error_message();
		}
		/**
		 * Fires after derivatives were processed.
		 *
		 * @param int   $attachment_id Attachment id.
		 * @param array $result        Per-size outcome.
		 */
		do_action( 'aipk_after_inject', $attachment_id, $result );
		return $result;
	}

	/**
	 * Original file handling: keep it, or remove post-production traces while
	 * keeping the marking. Never touches a file that carries a C2PA manifest,
	 * because any byte change would break its signature.
	 *
	 * @param string $original Original path.
	 * @param array  $record   Provenance record.
	 * @return string kept|cleaned|skipped-c2pa|skipped|error
	 */
	private static function maybe_clean_original( $original, $record ) {
		if ( 'clean' !== AIPK_Options::get( 'original_mode' ) ) {
			return 'kept';
		}
		if ( $record['has_c2pa'] ) {
			return 'skipped-c2pa';
		}
		if ( ! $record['has_xmp'] || ! empty( $record['original_clean_done'] ) ) {
			return 'skipped';
		}
		$bundle = AIPK_Segments::extract( $original );
		if ( null === $bundle || '' === $bundle['xmp'] ) {
			return 'skipped';
		}
		$cleaned = AIPK_Segments::clean_xmp( $bundle['xmp'] );
		if ( $cleaned === $bundle['xmp'] ) {
			return 'kept';
		}
		$bundle['xmp']          = $cleaned;
		$bundle['xmp_extended'] = array();
		$ok = AIPK_Segments::inject( $original, $bundle );
		return ( true === $ok ) ? 'cleaned' : 'error';
	}

	/**
	 * Every generated file: sizes plus the scaled full when it differs from the original.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param array  $metadata      Metadata.
	 * @param string $original      Original path.
	 * @return array size => path
	 */
	public static function derivative_paths( $attachment_id, $metadata, $original ) {
		$paths = array();
		$full  = get_attached_file( $attachment_id );
		if ( $full && realpath( $full ) !== realpath( $original ) ) {
			$paths['full'] = $full;
		}
		$dir = dirname( $full ? $full : $original );
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size => $info ) {
				if ( ! empty( $info['file'] ) ) {
					$paths[ $size ] = trailingslashit( $dir ) . $info['file'];
				}
			}
		}
		return $paths;
	}

	/**
	 * Clean up meta.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public static function on_delete( $attachment_id ) {
		delete_post_meta( $attachment_id, self::META_KEY );
		delete_post_meta( $attachment_id, self::META_AI );
		delete_post_meta( $attachment_id, self::META_SIZES );
	}

	/**
	 * Stored record for an attachment.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array|null
	 */
	public static function record( $attachment_id ) {
		$r = get_post_meta( $attachment_id, self::META_KEY, true );
		return is_array( $r ) ? $r : null;
	}
}
