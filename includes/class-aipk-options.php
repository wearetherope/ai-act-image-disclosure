<?php
/**
 * Settings storage and REST exposure of the provenance meta.
 *
 * @package AI_Act_Image_Disclosure
 */

defined( 'ABSPATH' ) || exit;

/**
 * Options.
 */
class AIPK_Options {

	const OPTION = 'aipk_settings';

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Which images get the marking carried into sizes: ai | all.
			'scope'           => 'ai',
			// Original file: keep | clean (remove Lightroom/Photoshop traces, keep the marking).
			'original_mode'   => 'keep',
			// Generated sizes: full (copy XMP + IPTC) | minimal (provenance fields only) | none.
			'derivative_mode' => 'full',
			// Size names that must not be marked.
			'skip_sizes'      => array(),
			// Front end.
			'frontend_attrs'  => 1,
			'frontend_label'  => 0,
			'label_text'      => __( 'Image generated with artificial intelligence', 'ai-act-image-disclosure' ),
			'badge_enabled'   => 0,
			'badge_text'      => 'AI',
			'badge_position'  => 'bottom-right',
			'badge_size'      => 28,
			'badge_bg'        => '#000000',
			'badge_opacity'   => 55,
			'badge_color'     => '#ffffff',
			'badge_min_width' => 200,
			'badge_title'     => __( 'Image generated with artificial intelligence', 'ai-act-image-disclosure' ),
			'custom_css'      => '',
		);
	}

	/**
	 * Register setting and meta.
	 */
	public static function init() {
		register_setting(
			'aipk',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'readable'                 => array( 'type' => 'boolean' ),
				'format'                   => array( 'type' => 'string' ),
				'has_xmp'                  => array( 'type' => 'boolean' ),
				'has_iptc'                 => array( 'type' => 'boolean' ),
				'has_c2pa'                 => array( 'type' => 'boolean' ),
				'digital_source_type'      => array( 'type' => 'string' ),
				'ai'                       => array( 'type' => 'boolean' ),
				'description'              => array( 'type' => 'string' ),
				'creator'                  => array( 'type' => 'string' ),
				'credit'                   => array( 'type' => 'string' ),
				'rights'                   => array( 'type' => 'string' ),
				'usage_terms'              => array( 'type' => 'string' ),
				'instructions'             => array( 'type' => 'string' ),
				'creator_tool'             => array( 'type' => 'string' ),
				'generators'               => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'c2pa_digital_source_type' => array( 'type' => 'string' ),
				'scanned_at'               => array( 'type' => 'integer' ),
			),
			'additionalProperties' => true,
		);
		register_post_meta(
			'attachment',
			AIPK_Processor::META_KEY,
			array(
				'single'        => true,
				'type'          => 'object',
				'show_in_rest'  => array( 'schema' => $schema ),
				'auth_callback' => array( __CLASS__, 'can_write_meta' ),
			)
		);
		register_post_meta(
			'attachment',
			AIPK_Processor::META_AI,
			array(
				'single'        => true,
				'type'          => 'string',
				'show_in_rest'  => true,
				'auth_callback' => array( __CLASS__, 'can_write_meta' ),
			)
		);
	}

	/**
	 * Meta is computed from the file: nobody writes it through REST.
	 *
	 * @return bool
	 */
	public static function can_write_meta() {
		return false;
	}

	/**
	 * All settings, merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Badge positions.
	 *
	 * @return array
	 */
	public static function positions() {
		return array(
			'bottom-right' => __( 'Bottom right', 'ai-act-image-disclosure' ),
			'bottom-left'  => __( 'Bottom left', 'ai-act-image-disclosure' ),
			'top-right'    => __( 'Top right', 'ai-act-image-disclosure' ),
			'top-left'     => __( 'Top left', 'ai-act-image-disclosure' ),
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$d     = self::defaults();
		$out   = $d;

		$out['scope']           = ( isset( $input['scope'] ) && 'all' === $input['scope'] ) ? 'all' : 'ai';
		$out['original_mode']   = ( isset( $input['original_mode'] ) && 'clean' === $input['original_mode'] ) ? 'clean' : 'keep';
		$out['derivative_mode'] = ( isset( $input['derivative_mode'] ) && in_array( $input['derivative_mode'], array( 'full', 'minimal', 'none' ), true ) ) ? $input['derivative_mode'] : 'full';

		$out['skip_sizes'] = array();
		if ( ! empty( $input['skip_sizes'] ) && is_array( $input['skip_sizes'] ) ) {
			foreach ( $input['skip_sizes'] as $size ) {
				$size = sanitize_key( $size );
				if ( '' !== $size ) {
					$out['skip_sizes'][] = $size;
				}
			}
		}

		$out['frontend_attrs'] = empty( $input['frontend_attrs'] ) ? 0 : 1;
		$out['frontend_label'] = empty( $input['frontend_label'] ) ? 0 : 1;
		$out['label_text']     = self::text( $input, 'label_text', $d['label_text'] );

		$out['badge_enabled']   = empty( $input['badge_enabled'] ) ? 0 : 1;
		$out['badge_text']      = mb_substr( self::text( $input, 'badge_text', $d['badge_text'] ), 0, 12 );
		$out['badge_position']  = ( isset( $input['badge_position'] ) && isset( self::positions()[ $input['badge_position'] ] ) ) ? $input['badge_position'] : 'bottom-right';
		$out['badge_size']      = isset( $input['badge_size'] ) ? min( 120, max( 12, (int) $input['badge_size'] ) ) : $d['badge_size'];
		$out['badge_opacity']   = isset( $input['badge_opacity'] ) ? min( 100, max( 0, (int) $input['badge_opacity'] ) ) : $d['badge_opacity'];
		$out['badge_min_width'] = isset( $input['badge_min_width'] ) ? max( 0, (int) $input['badge_min_width'] ) : $d['badge_min_width'];
		$out['badge_title']     = self::text( $input, 'badge_title', $d['badge_title'] );
		foreach ( array( 'badge_bg', 'badge_color' ) as $k ) {
			$c = isset( $input[ $k ] ) ? sanitize_hex_color( wp_unslash( $input[ $k ] ) ) : '';
			$out[ $k ] = $c ? $c : $d[ $k ];
		}
		$css = isset( $input['custom_css'] ) ? (string) wp_unslash( $input['custom_css'] ) : '';
		$out['custom_css'] = wp_strip_all_tags( $css );
		return $out;
	}

	/**
	 * Text field helper.
	 *
	 * @param array  $input   Input.
	 * @param string $key     Key.
	 * @param string $default Default.
	 * @return string
	 */
	private static function text( $input, $key, $default ) {
		if ( ! isset( $input[ $key ] ) ) {
			return $default;
		}
		$v = sanitize_text_field( wp_unslash( $input[ $key ] ) );
		return '' === $v ? $default : $v;
	}
}
