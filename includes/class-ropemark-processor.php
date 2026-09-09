<?php
/**
 * Upload pipeline: reads provenance from the original, applies the manual
 * classification, writes the marking where it is missing, stores the record
 * and carries XMP/IPTC into every generated size.
 *
 * @package Ropemark_Image_Marking
 */

defined( 'ABSPATH' ) || exit;

/**
 * Upload pipeline processor.
 */
class Ropemark_Processor {

	const META_KEY    = '_ropemark_provenance';
	const META_AI     = '_ropemark_ai';
	const META_SIZES  = '_ropemark_sizes';
	const META_MANUAL = '_ropemark_manual';
	const META_DISCLOSE = '_ropemark_disclose';
	const META_SUSPECT  = '_ropemark_suspect';
	const META_MARKED   = '_ropemark_marked';
	const COUNTS_CACHE  = 'ropemark_counts';
	const BG_OPTION     = 'ropemark_bg_scan';
	const BG_HOOK       = 'ropemark_bg_scan_run';

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
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- one minute, only while a background scan is active.
		add_action( self::BG_HOOK, array( __CLASS__, 'bg_run' ) );
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
		$record = Ropemark_Reader::read( $original );
		if ( ! $record['readable'] ) {
			return null;
		}

		// Original: optional cleaning of post-production traces.
		$record['original_clean'] = self::maybe_clean_original( $original, $record );
		if ( 'cleaned' === $record['original_clean'] ) {
			$record = array_merge( Ropemark_Reader::read( $original ), array( 'original_clean' => 'cleaned' ) );
		}

		// Manual classification: writes the marking into the files.
		$manual           = get_post_meta( $attachment_id, self::META_MANUAL, true );
		$record['manual'] = is_string( $manual ) ? $manual : '';
		$bundle           = Ropemark_Segments::extract( $original );
		if ( '' !== $record['manual'] ) {
			$record = self::apply_manual( $record, $bundle, $original );
		}

		$record['scanned_at'] = time();
		update_post_meta( $attachment_id, self::META_KEY, $record );
		update_post_meta( $attachment_id, self::META_AI, $record['ai'] ? '1' : '0' );
		// Flat flags for indexable library filters and counters.
		update_post_meta( $attachment_id, self::META_SUSPECT, ! empty( $record['suspect'] ) ? '1' : '0' );
		update_post_meta( $attachment_id, self::META_MARKED, ( '' !== $record['digital_source_type'] || '' !== $record['c2pa_digital_source_type'] ) ? '1' : '0' );
		delete_transient( self::COUNTS_CACHE );

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
			$record['description'] = Ropemark_Options::get( 'label_text' );
		}
		if ( null === $bundle ) {
			return $record;
		}
		$bundle['xmp']          = Ropemark_Segments::set_digital_source_type( $bundle['xmp'], $term, $record );
		$bundle['xmp_extended'] = array();
		if ( Ropemark_Options::get( 'manual_writes_original' ) && ! $record['has_c2pa'] ) {
			$ok                        = Ropemark_Segments::inject( $original, $bundle );
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
		$scope  = Ropemark_Options::get( 'scope' );
		$want   = ( 'all' === $scope ) ? ( $record['has_xmp'] || $record['has_iptc'] ) : $record['ai'];
		/**
		 * Whether the marking is carried into derivatives for this attachment.
		 *
		 * @param bool  $want          Decision from the "scope" option.
		 * @param int   $attachment_id Attachment id.
		 * @param array $record        Record.
		 */
		$want = apply_filters( 'ropemark_should_preserve', $want, $attachment_id, $record );
		if ( ! $want ) {
			return $result;
		}
		$mode = Ropemark_Options::get( 'derivative_mode' );
		if ( 'none' === $mode ) {
			return $result;
		}
		if ( null === $bundle ) {
			$bundle = Ropemark_Segments::extract( $original );
		}
		if ( null === $bundle || ( '' === $bundle['xmp'] && '' === $bundle['iptc'] ) ) {
			return $result;
		}
		if ( 'minimal' === $mode ) {
			$bundle['xmp']          = Ropemark_Segments::build_xmp( $record );
			$bundle['xmp_extended'] = array();
			$bundle['iptc']         = '';
		}
		$skip    = (array) Ropemark_Options::get( 'skip_sizes' );
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
			// One read per derivative: check the tag as text, write only when missing.
			$ok              = Ropemark_Segments::ensure( $path, $bundle, $record['digital_source_type'] );
			$result[ $size ] = is_wp_error( $ok ) ? 'error: ' . $ok->get_error_message() : $ok;
		}
		/**
		 * Fires after derivatives were processed.
		 *
		 * @param int   $attachment_id Attachment id.
		 * @param array $result        Per-size outcome.
		 */
		do_action( 'ropemark_after_inject', $attachment_id, $result );
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
		if ( 'clean' !== Ropemark_Options::get( 'original_mode' ) ) {
			return 'kept';
		}
		if ( $record['has_c2pa'] ) {
			return 'skipped-c2pa';
		}
		if ( ! $record['has_xmp'] ) {
			return 'skipped';
		}
		$bundle = Ropemark_Segments::extract( $original );
		if ( null === $bundle || '' === $bundle['xmp'] ) {
			return 'skipped';
		}
		$cleaned = Ropemark_Segments::clean_xmp( $bundle['xmp'] );
		if ( $cleaned === $bundle['xmp'] ) {
			return 'kept';
		}
		$bundle['xmp']          = $cleaned;
		$bundle['xmp_extended'] = array();
		$ok                     = Ropemark_Segments::inject( $original, $bundle );
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
		delete_post_meta( $attachment_id, self::META_SUSPECT );
		delete_post_meta( $attachment_id, self::META_MARKED );
		delete_transient( self::COUNTS_CACHE );
	}

	/**
	 * Stored record.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array|null
	 */
	public static function record( $attachment_id ) {
		$r = get_post_meta( $attachment_id, self::META_KEY, true );
		return is_array( $r ) ? array_merge( Ropemark_Reader::empty_record(), $r ) : null;
	}

	/**
	 * Ids of AI images (export).
	 *
	 * @return int[]
	 */
	public static function ai_ids() {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1' ORDER BY post_id", self::META_AI ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Library counters, cached for ten minutes and invalidated on every scan.
	 *
	 * @return array images, scanned, ai, suspect, marked, unscanned
	 */
	public static function counts() {
		$c = get_transient( self::COUNTS_CACHE );
		if ( is_array( $c ) && isset( $c['images'] ) ) {
			return $c;
		}
		global $wpdb;
		$count_flag = function ( $key ) use ( $wpdb ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'", $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		};
		$c = array(
			'images'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png','image/webp')" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'scanned' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_KEY ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'ai'      => $count_flag( self::META_AI ),
			'suspect' => $count_flag( self::META_SUSPECT ),
			'marked'  => $count_flag( self::META_MARKED ),
		);
		$c['unscanned'] = max( 0, $c['images'] - $c['scanned'] );
		set_transient( self::COUNTS_CACHE, $c, 10 * MINUTE_IN_SECONDS );
		return $c;
	}

	/**
	 * Process ids until the batch or the time budget runs out.
	 *
	 * @param int[] $ids     Attachment ids.
	 * @param float $seconds Time budget.
	 * @return array{done:int,ai:int,ids:int[]} Processed count, AI count, processed ids.
	 */
	public static function run_batch( $ids, $seconds ) {
		$start = microtime( true );
		$done  = 0;
		$ai    = 0;
		$seen  = array();
		foreach ( $ids as $id ) {
			$r = self::process( (int) $id );
			$done++;
			$seen[] = (int) $id;
			if ( $r && $r['ai'] ) {
				$ai++;
			}
			if ( 0 === $done % 25 ) {
				self::release_memory();
			}
			if ( microtime( true ) - $start > $seconds ) {
				break;
			}
		}
		return array(
			'done' => $done,
			'ai'   => $ai,
			'ids'  => $seen,
		);
	}

	/**
	 * Keep long loops flat: drop the per-request object caches WordPress accumulates.
	 */
	public static function release_memory() {
		Ropemark_Segments::forget();
		global $wp_object_cache;
		if ( is_object( $wp_object_cache ) && ! wp_using_ext_object_cache() ) {
			$wp_object_cache->cache = array();
			if ( method_exists( $wp_object_cache, '__remoteset' ) ) {
				$wp_object_cache->__remoteset();
			}
		}
	}

	/**
	 * Seconds we may spend in one request: 80% of max_execution_time, capped.
	 *
	 * @param float $cap Upper bound.
	 * @return float
	 */
	public static function time_budget( $cap ) {
		$max = (int) ini_get( 'max_execution_time' );
		$b   = $max > 0 ? $max * 0.8 : $cap;
		return max( 3, min( $cap, $b ) );
	}

	/* ------------------------------------------------------ background scan */

	/**
	 * One-minute schedule, used only while a background scan is active.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function cron_schedule( $schedules ) {
		$schedules['ropemark_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (Ropemark Image Marking for the EU AI Act scan)', 'ropemark-image-marking-for-eu-ai-act' ),
		);
		return $schedules;
	}

	/**
	 * Start a background scan.
	 *
	 * @param bool $all Re-scan everything, or only unscanned images.
	 */
	public static function bg_start( $all ) {
		$c = self::counts();
		update_option(
			self::BG_OPTION,
			array(
				'all'     => (bool) $all,
				'offset'  => 0,
				'done'    => 0,
				'ai'      => 0,
				'total'   => $all ? $c['images'] : $c['unscanned'],
				'started' => time(),
				'last'    => 0,
			),
			false
		);
		self::bg_schedule_next( 0 );
		// First batch right away, so the user sees movement.
		self::bg_run();
	}

	/**
	 * Schedule the next tick: Action Scheduler when available (WooCommerce and
	 * others ship it), else the one-minute WP-Cron event.
	 *
	 * @param int $delay Seconds.
	 */
	private static function bg_schedule_next( $delay ) {
		if ( function_exists( 'as_schedule_single_action' ) && function_exists( 'as_has_scheduled_action' ) ) {
			if ( ! as_has_scheduled_action( self::BG_HOOK, array(), 'ropemark-image-marking-for-eu-ai-act' ) ) {
				as_schedule_single_action( time() + $delay, self::BG_HOOK, array(), 'ropemark-image-marking-for-eu-ai-act' );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::BG_HOOK ) ) {
			wp_schedule_event( time() + $delay, 'ropemark_minute', self::BG_HOOK );
		}
	}

	/**
	 * Stop a background scan.
	 */
	public static function bg_stop() {
		delete_option( self::BG_OPTION );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::BG_HOOK, array(), 'ropemark-image-marking-for-eu-ai-act' );
		}
		$ts = wp_next_scheduled( self::BG_HOOK );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::BG_HOOK );
			$ts = wp_next_scheduled( self::BG_HOOK );
		}
	}

	/**
	 * Background scan state, or null when none is running.
	 *
	 * @return array|null
	 */
	public static function bg_status() {
		$s = get_option( self::BG_OPTION );
		return is_array( $s ) ? $s : null;
	}

	/**
	 * One tick: process as many images as the time budget allows, then stop or chain.
	 */
	public static function bg_run() {
		$s = self::bg_status();
		if ( ! $s ) {
			return;
		}
		/**
		 * Upper bound of images per background tick.
		 *
		 * @param int $batch Default 200.
		 */
		$batch = max( 1, (int) apply_filters( 'ropemark_bg_batch', 200 ) );
		$args  = array(
			'post_type'      => 'attachment',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp' ),
			'post_status'    => 'inherit',
			'posts_per_page' => $batch,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);
		if ( $s['all'] ) {
			$args['offset'] = (int) $s['offset'];
		} else {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => self::META_KEY,
					'compare' => 'NOT EXISTS',
				),
			);
		}
		$ids = get_posts( $args );
		if ( empty( $ids ) ) {
			self::bg_stop();
			set_transient( 'ropemark_bg_done', $s, DAY_IN_SECONDS );
			return;
		}
		$res          = self::run_batch( $ids, self::time_budget( 25 ) );
		$s['done']   += $res['done'];
		$s['ai']     += $res['ai'];
		$s['offset'] += $res['done'];
		$s['last']    = time();
		if ( $res['done'] >= count( $ids ) && count( $ids ) < $batch ) {
			self::bg_stop();
			set_transient( 'ropemark_bg_done', $s, DAY_IN_SECONDS );
			return;
		}
		update_option( self::BG_OPTION, $s, false );
		self::bg_schedule_next( 5 );
	}
}
