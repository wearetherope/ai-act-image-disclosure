<?php
/**
 * Upload pipeline: reads provenance from the original, applies the manual
 * classification, writes the marking where it is missing, stores the record
 * and carries XMP/IPTC into every generated size.
 *
 * @package AI_Act_Image_Disclosure
 */

defined( 'ABSPATH' ) || exit;

/**
 * Upload pipeline processor.
 */
class AIPK_Processor {

	const META_KEY    = '_aipk_provenance';
	const META_AI     = '_aipk_ai';
	const META_SIZES  = '_aipk_sizes';
	const META_MANUAL = '_aipk_manual';
	const META_DISCLOSE = '_aipk_disclose';

	/**
	 * Per-media visible disclosure: '' follows the settings, 'show' forces badge and label, 'hide' suppresses them.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $value         ''|show|hide.
	 */
	public static function set_disclose( $attachment_id, $value ) {
		$value = in_array( $value, array( 'show', 'hide' ), true ) ? $value : '';
		if ( '' === $value ) {
			delete_post_meta( $attachment_id, self::META_DISCLOSE );
		} else {
			update_post_meta( $attachment_id, self::META_DISCLOSE, $value );
		}
	}

	/**
	 * Per-media disclosure value.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return string ''|show|hide
	 */
	public static function disclose( $attachment_id ) {
		$v = get_post_meta( $attachment_id, self::META_DISCLOSE, true );
		return in_array( $v, array( 'show', 'hide' ), true ) ? $v : '';
	}

	/**
	 * Manual classification values => IPTC term (empty = no term).
	 *
	 * @return array
	 */
	public static function manual_terms() {
		return array(
			'ai_generated' => 'trainedAlgorithmicMedia',
			'ai_modified'  => 'compositeWithTrainedAlgorithmicMedia',
			'not_ai'       => '',
		);
	}

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_generate_metadata' ), 999, 2 );
		add_filter( 'wp_update_attachment_metadata', array( __CLASS__, 'on_update_metadata' ), 999, 2 );
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete' ) );
	}

	/**
	 * After sizes were generated (upload and regeneration).
	 *
	 * @param array $metadata      Metadata.
	 * @param int   $attachment_id Attachment id.
	 * @return array
	 */
	public static function on_generate_metadata( $metadata, $attachment_id ) {
		self::process( $attachment_id, $metadata );
		return $metadata;
	}

	/**
	 * Image edits produce new files: process again when the file name changed.
	 *
	 * @param array $metadata      Metadata.
	 * @param int   $attachment_id Attachment id.
	 * @return array
	 */
	public static function on_update_metadata( $metadata, $attachment_id ) {
		if ( did_filter( 'wp_generate_attachment_metadata' ) ) {
			return $metadata;
		}
		$stored = get_post_meta( $attachment_id, self::META_SIZES, true );
		$file   = isset( $metadata['file'] ) ? $metadata['file'] : '';
		if ( is_array( $stored ) && isset( $stored['_file'] ) && $stored['_file'] === $file ) {
			return $metadata;
		}
		self::process( $attachment_id, $metadata );
		return $metadata;
	}

	/**
	 * Full pass on one attachment.
	 *
	 * @param int        $attachment_id Attachment id.
	 * @param array|null $metadata      Metadata, loaded when null.
	 * @return array|null Record, or null for non-images.
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

		// Original: optional cleaning of post-production traces.
		$record['original_clean'] = self::maybe_clean_original( $original, $record );
		if ( 'cleaned' === $record['original_clean'] ) {
			$record = array_merge( AIPK_Reader::read( $original ), array( 'original_clean' => 'cleaned' ) );
		}

		// Manual classification: writes the marking into the files.
		$manual           = get_post_meta( $attachment_id, self::META_MANUAL, true );
		$record['manual'] = is_string( $manual ) ? $manual : '';
		$bundle           = AIPK_Segments::extract( $original );
		if ( '' !== $record['manual'] ) {
			$record = self::apply_manual( $record, $bundle, $original );
		}

		$record['scanned_at'] = time();
		update_post_meta( $attachment_id, self::META_KEY, $record );
		update_post_meta( $attachment_id, self::META_AI, $record['ai'] ? '1' : '0' );

		$sizes          = self::inject_all( $attachment_id, $metadata, $original, $record, $bundle );
		$sizes['_file'] = isset( $metadata['file'] ) ? $metadata['file'] : '';
		update_post_meta( $attachment_id, self::META_SIZES, $sizes );
		return $record;
	}

	/**
	 * Store a manual classification and re-process.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $value         ai_generated|ai_modified|not_ai|'' (automatic).
	 * @return array|null
	 */
	public static function classify( $attachment_id, $value ) {
		$value = ( '' !== $value && isset( self::manual_terms()[ $value ] ) ) ? $value : '';
		if ( '' === $value ) {
			delete_post_meta( $attachment_id, self::META_MANUAL );
		} else {
			update_post_meta( $attachment_id, self::META_MANUAL, $value );
		}
		return self::process( $attachment_id );
	}

	/**
	 * Apply the manual classification: for AI values, write DigitalSourceType
	 * into the original (unless a C2PA manifest would break) and into the
	 * bundle used for derivatives.
	 *
	 * @param array  $record   Record.
	 * @param array  $bundle   Bundle, updated in place.
	 * @param string $original Original path.
	 * @return array Record.
	 */
	private static function apply_manual( $record, &$bundle, $original ) {
		$terms = self::manual_terms();
		$term  = $terms[ $record['manual'] ];
		if ( '' === $term ) {
			$record['ai']      = false;
			$record['suspect'] = false;
			$record['source']  = 'manual';
			return $record;
		}
		$record['digital_source_type'] = $term;
		$record['ai']                  = true;
		$record['suspect']             = false;
		$record['source']              = 'manual';
		if ( empty( $record['description'] ) ) {
			$record['description'] = AIPK_Options::get( 'label_text' );
		}
		if ( null === $bundle ) {
			return $record;
		}
		$bundle['xmp']          = AIPK_Segments::set_digital_source_type( $bundle['xmp'], $term, $record );
		$bundle['xmp_extended'] = array();
		if ( AIPK_Options::get( 'manual_writes_original' ) && ! $record['has_c2pa'] ) {
			$ok                        = AIPK_Segments::inject( $original, $bundle );
			$record['original_marked'] = ( true === $ok ) ? 'written' : 'error';
		} else {
			$record['original_marked'] = $record['has_c2pa'] ? 'skipped-c2pa' : 'skipped';
		}
		$record['has_xmp'] = true;
		return $record;
	}

	/**
	 * Absolute path of the untouched upload.
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
	 * Carry the bundle into every derivative that lacks the marking.
	 *
	 * @param int        $attachment_id Attachment id.
	 * @param array      $metadata      Metadata.
	 * @param string     $original      Original path.
	 * @param array      $record        Record.
	 * @param array|null $bundle        Bundle, extracted from the original when null.
	 * @return array size => state
	 */
	public static function inject_all( $attachment_id, $metadata, $original, $record, $bundle = null ) {
		$result = array();
		$scope  = AIPK_Options::get( 'scope' );
		$want   = ( 'all' === $scope ) ? ( $record['has_xmp'] || $record['has_iptc'] ) : $record['ai'];
		/**
		 * Whether the marking is carried into derivatives for this attachment.
		 *
		 * @param bool  $want          Decision from the "scope" option.
		 * @param int   $attachment_id Attachment id.
		 * @param array $record        Record.
		 */
		$want = apply_filters( 'aipk_should_preserve', $want, $attachment_id, $record );
		if ( ! $want ) {
			return $result;
		}
		$mode = AIPK_Options::get( 'derivative_mode' );
		if ( 'none' === $mode ) {
			return $result;
		}
		if ( null === $bundle ) {
			$bundle = AIPK_Segments::extract( $original );
		}
		if ( null === $bundle || ( '' === $bundle['xmp'] && '' === $bundle['iptc'] ) ) {
			return $result;
		}
		if ( 'minimal' === $mode ) {
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
			if ( ! $existing['readable'] ) {
				$result[ $size ] = 'unsupported';
				continue;
			}
			if ( '' !== $existing['digital_source_type'] && $existing['digital_source_type'] === $record['digital_source_type'] ) {
				$result[ $size ] = 'kept';
				continue;
			}
			$ok              = AIPK_Segments::inject( $path, $bundle );
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
	 * Original file: keep, or clean post-production traces (never with C2PA).
	 *
	 * @param string $original Original path.
	 * @param array  $record   Record.
	 * @return string kept|cleaned|skipped-c2pa|skipped|error
	 */
	private static function maybe_clean_original( $original, $record ) {
		if ( 'clean' !== AIPK_Options::get( 'original_mode' ) ) {
			return 'kept';
		}
		if ( $record['has_c2pa'] ) {
			return 'skipped-c2pa';
		}
		if ( ! $record['has_xmp'] ) {
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
		$ok                     = AIPK_Segments::inject( $original, $bundle );
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
		delete_post_meta( $attachment_id, self::META_MANUAL );
		delete_post_meta( $attachment_id, self::META_DISCLOSE );
	}

	/**
	 * Stored record.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array|null
	 */
	public static function record( $attachment_id ) {
		$r = get_post_meta( $attachment_id, self::META_KEY, true );
		return is_array( $r ) ? $r : null;
	}

	/**
	 * Ids of AI images (for counters and exports).
	 *
	 * @return int[]
	 */
	public static function ai_ids() {
		return get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::META_AI, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
	}
}
