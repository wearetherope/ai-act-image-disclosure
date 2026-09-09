<?php
/**
 * Front end: data attributes, badge with provenance popup, text label,
 * schema.org digitalSourceType, site notice, shortcode and block.
 *
 * The visible disclosure required by AI Act article 50(4) belongs to the
 * page, not the file. This class offers the tools for it; the site decides.
 *
 * @package Ropemark_Image_Marking
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front end output.
 */
class Ropemark_Frontend {

	/**
	 * AI images rendered on this request, for schema and the signature comment.
	 *
	 * @var array
	 */
	private static $rendered = array();

	/**
	 * Popup counter for unique ids.
	 *
	 * @var int
	 */
	private static $n = 0;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'image_attributes' ), 10, 2 );
		add_filter( 'wp_content_img_tag', array( __CLASS__, 'content_img_tag' ), 10, 3 );
		add_filter( 'wp_get_attachment_image', array( __CLASS__, 'attachment_image_html' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'footer' ), 20 );
		add_shortcode( 'ropemark_disclosure', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
	}

	/**
	 * Data attributes on images rendered through wp_get_attachment_image().
	 *
	 * @param array   $attr       Attributes.
	 * @param WP_Post $attachment Attachment.
	 * @return array
	 */
	public static function image_attributes( $attr, $attachment ) {
		if ( ! Ropemark_Options::get( 'frontend_attrs' ) || ! $attachment instanceof WP_Post ) {
			return $attr;
		}
		$record = Ropemark_Processor::record( $attachment->ID );
		if ( $record && ! empty( $record['digital_source_type'] ) ) {
			$attr['data-digital-source-type'] = $record['digital_source_type'];
			if ( $record['ai'] ) {
				$attr['data-ai-generated'] = 'true';
			}
		}
		return $attr;
	}

	/**
	 * Images inside post content (WordPress 6.0+).
	 *
	 * @param string $filtered_image Image tag.
	 * @param string $context        Context.
	 * @param int    $attachment_id  Attachment id, 0 when unknown.
	 * @return string
	 */
	public static function content_img_tag( $filtered_image, $context, $attachment_id ) {
		if ( ! $attachment_id || is_admin() || is_feed() ) {
			return $filtered_image;
		}
		$record = Ropemark_Processor::record( $attachment_id );
		if ( ! $record || empty( $record['digital_source_type'] ) ) {
			return $filtered_image;
		}
		if ( Ropemark_Options::get( 'frontend_attrs' ) && false === strpos( $filtered_image, 'data-digital-source-type=' ) ) {
			$attrs = ' data-digital-source-type="' . esc_attr( $record['digital_source_type'] ) . '"';
			if ( $record['ai'] ) {
				$attrs .= ' data-ai-generated="true"';
			}
			$filtered_image = preg_replace( '/<img\b/i', '<img' . $attrs, $filtered_image, 1 );
		}
		return self::decorate( $filtered_image, $record, $attachment_id );
	}

	/**
	 * Output of wp_get_attachment_image().
	 *
	 * @param string $html          Image HTML.
	 * @param int    $attachment_id Attachment id.
	 * @return string
	 */
	public static function attachment_image_html( $html, $attachment_id ) {
		if ( is_admin() || is_feed() || '' === $html ) {
			return $html;
		}
		$record = Ropemark_Processor::record( $attachment_id );
		return $record ? self::decorate( $html, $record, $attachment_id ) : $html;
	}

	/**
	 * Wrap an AI image with badge and popup, append the text label.
	 *
	 * @param string $html          Image tag.
	 * @param array  $record        Record.
	 * @param int    $attachment_id Attachment id.
	 * @return string
	 */
	private static function decorate( $html, $record, $attachment_id ) {
		if ( empty( $record['ai'] ) || false !== strpos( $html, 'data-ropemark=' ) ) {
			return $html;
		}
		$disclose = Ropemark_Processor::disclose( $attachment_id );
		$show     = 'hide' !== $disclose;
		/**
		 * Whether this image gets the visible badge and label.
		 *
		 * @param bool   $show   Default: the per-media choice, else true.
		 * @param array  $record Record.
		 * @param string $html   Image tag.
		 */
		if ( ! apply_filters( 'ropemark_decorate_image', $show, $record, $html ) ) {
			return $html;
		}
		$html = preg_replace( '/<img\b/i', '<img data-ropemark="1"', $html, 1 );
		self::$rendered[ $attachment_id ] = array(
			'src'    => preg_match( '/\ssrc=["\']([^"\']+)/i', $html, $m ) ? $m[1] : '',
			'record' => $record,
		);
		$force = ( 'show' === $disclose );
		$out   = $html;
		if ( ( Ropemark_Options::get( 'badge_enabled' ) || $force ) && ( $force || self::wide_enough( $html ) ) ) {
			$out = '<span class="ropemark-wrap">' . $out . self::badge( $record, $attachment_id ) . '</span>';
		}
		if ( Ropemark_Options::get( 'frontend_label' ) || $force ) {
			$label = Ropemark_Options::get( 'label_text' );
			/**
			 * Filter the visible label text for one image.
			 *
			 * @param string $label  Label text.
			 * @param array  $record Record.
			 */
			$label = apply_filters( 'ropemark_label_text', $label, $record );
			$out  .= '<span class="ropemark-label" role="note">' . esc_html( $label ) . '</span>';
		}
		return $out;
	}

	/**
	 * Badge markup, with the popup when enabled.
	 *
	 * @param array $record        Record.
	 * @param int   $attachment_id Attachment id.
	 * @return string
	 */
	private static function badge( $record, $attachment_id ) {
		$o     = Ropemark_Options::all();
		$title = $o['badge_title'];
		$text  = $o['badge_text'];
		/**
		 * Filter the badge text for one image.
		 *
		 * @param string $text   Badge text.
		 * @param array  $record Record.
		 */
		$text  = apply_filters( 'ropemark_badge_text', $text, $record );
		$inner = esc_html( $text );
		if ( $o['badge_image'] ) {
			$img = wp_get_attachment_image_url( (int) $o['badge_image'], 'thumbnail' );
			if ( $img ) {
				$inner = '<img src="' . esc_url( $img ) . '" alt="" data-ropemark="1">';
			}
		}
		$pos = 'ropemark-pos-' . esc_attr( $o['badge_position'] );
		if ( ! $o['popup_enabled'] ) {
			return '<span class="ropemark-ai-badge ' . $pos . '" title="' . esc_attr( $title ) . '" aria-label="' . esc_attr( $title ) . '" role="img">' . $inner . '</span>';
		}
		self::$n++;
		$id  = 'ropemark-pop-' . (int) $attachment_id . '-' . self::$n;
		$out = '<button type="button" class="ropemark-ai-badge ' . $pos . '" title="' . esc_attr( $title ) . '" aria-label="' . esc_attr( $title ) . '" aria-expanded="false" aria-controls="' . esc_attr( $id ) . '">' . $inner . '</button>';
		$out .= self::popup( $id, $record, $o );
		return $out;
	}

	/**
	 * Provenance popup: static HTML, hidden until the badge is clicked.
	 *
	 * @param string $id     Element id.
	 * @param array  $record Record.
	 * @param array  $o      Options.
	 * @return string
	 */
	private static function popup( $id, $record, $o ) {
		$dst  = $record['digital_source_type'] ? $record['digital_source_type'] : $record['c2pa_digital_source_type'];
		$rows = array(
			__( 'Type', 'ropemark-image-marking-for-eu-ai-act' )        => Ropemark_Reader::term_label( $dst ),
			__( 'Disclosure', 'ropemark-image-marking-for-eu-ai-act' )  => $record['description'],
			__( 'Creator', 'ropemark-image-marking-for-eu-ai-act' )     => $record['creator'],
			__( 'Credit', 'ropemark-image-marking-for-eu-ai-act' )      => $record['credit'],
			__( 'Rights', 'ropemark-image-marking-for-eu-ai-act' )      => $record['rights'],
			__( 'Generator', 'ropemark-image-marking-for-eu-ai-act' )   => implode( ', ', $record['generators'] ),
			__( 'Marking', 'ropemark-image-marking-for-eu-ai-act' )     => implode(
				', ',
				array_filter(
					array(
						'IPTC ' . $dst,
						$record['has_c2pa'] ? __( 'C2PA manifest in the original', 'ropemark-image-marking-for-eu-ai-act' ) : '',
					)
				)
			),
		);
		/**
		 * Filter the rows shown in the provenance popup.
		 *
		 * @param array $rows   label => value.
		 * @param array $record Record.
		 */
		$rows = apply_filters( 'ropemark_popup_rows', $rows, $record );
		$html = '<div id="' . esc_attr( $id ) . '" class="ropemark-popup" role="dialog" aria-label="' . esc_attr( $o['popup_title'] ) . '" hidden>';
		$html .= '<div class="ropemark-popup-head"><span class="ropemark-popup-title">' . esc_html( $o['popup_title'] ) . '</span><button type="button" class="ropemark-popup-close" aria-label="' . esc_attr__( 'Close', 'ropemark-image-marking-for-eu-ai-act' ) . '">&times;</button></div>';
		$html .= '<dl class="ropemark-popup-rows">';
		foreach ( $rows as $label => $value ) {
			if ( '' === trim( (string) $value ) ) {
				continue;
			}
			$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
		}
		$html .= '</dl>';
		$html .= '<p class="ropemark-popup-note">' . esc_html__( 'Marked under EU Regulation 2024/1689 (AI Act), article 50.', 'ropemark-image-marking-for-eu-ai-act' ) . '</p>';
		if ( $o['credit_link'] ) {
			$html .= '<p class="ropemark-popup-credit">' . sprintf(
				/* translators: 1: plugin name, 2: agency link */
				esc_html__( 'Marked with %1$s by %2$s', 'ropemark-image-marking-for-eu-ai-act' ),
				'Ropemark Image Marking for the EU AI Act',
				'<a href="https://therope.it" rel="noopener">The Rope</a>'
			) . '</p>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Skip the badge on tiny renditions.
	 *
	 * @param string $html Image tag.
	 * @return bool
	 */
	private static function wide_enough( $html ) {
		$min = (int) Ropemark_Options::get( 'badge_min_width' );
		if ( $min <= 0 ) {
			return true;
		}
		if ( preg_match( '/\swidth=["\']?(\d+)/i', $html, $m ) ) {
			return (int) $m[1] >= $min;
		}
		return true;
	}

	/**
	 * Register and enqueue the front end assets: the styles are inline because
	 * they depend on the badge settings, the popup script is a few lines.
	 */
	public static function enqueue_assets() {
		$o      = Ropemark_Options::all();
		$forced = (bool) get_transient( 'ropemark_forced_disclosure' );
		$badge  = $o['badge_enabled'] || $forced;
		if ( ! $badge && ! $o['frontend_label'] && ! $o['footer_notice'] ) {
			return;
		}
		wp_register_style( 'ropemark', false, array(), ROPEMARK_VERSION );
		wp_enqueue_style( 'ropemark' );
		wp_add_inline_style( 'ropemark', self::css( $o, $forced ) );

		if ( $badge && $o['popup_enabled'] ) {
			wp_register_script( 'ropemark-popup', '', array(), ROPEMARK_VERSION, true );
			wp_enqueue_script( 'ropemark-popup' );
			wp_add_inline_script( 'ropemark-popup', self::popup_js() );
		}
	}

	/**
	 * Styles for badge, popup, label and site notice.
	 *
	 * @param array $o      Settings.
	 * @param bool  $forced Some image forces the badge.
	 * @return string
	 */
	private static function css( $o, $forced ) {
		$css = '';
		if ( $o['badge_enabled'] || $forced ) {
			$size    = (int) $o['badge_size'];
			$offset  = max( 4, (int) round( $size / 3 ) );
			$opacity = max( 0, min( 1, (int) $o['badge_opacity'] / 100 ) );
			$rgb     = self::hex_to_rgb( $o['badge_bg'] );
			$color   = sanitize_hex_color( $o['badge_color'] );
			$color   = $color ? $color : '#ffffff';
			$pos     = array(
				'bottom-right' => "bottom:{$offset}px;right:{$offset}px",
				'bottom-left'  => "bottom:{$offset}px;left:{$offset}px",
				'top-right'    => "top:{$offset}px;right:{$offset}px",
				'top-left'     => "top:{$offset}px;left:{$offset}px",
			);
			$css .= '.ropemark-wrap{position:relative;display:inline-block;max-width:100%;line-height:0}'
				. '.ropemark-wrap>img{display:block}'
				. '.ropemark-ai-badge{position:absolute;z-index:2;display:flex;align-items:center;justify-content:center;margin:0;padding:0;border:0;cursor:pointer;'
				. "width:{$size}px;height:{$size}px;border-radius:50%;"
				. "background:rgba({$rgb[0]},{$rgb[1]},{$rgb[2]},{$opacity});color:{$color};"
				. 'font:600 ' . max( 9, (int) round( $size * 0.42 ) ) . 'px/1 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;'
				. 'letter-spacing:.02em;text-transform:uppercase;user-select:none;backdrop-filter:blur(2px);box-shadow:0 1px 3px rgba(0,0,0,.25)}'
				. '.ropemark-ai-badge img{width:70%;height:70%;object-fit:contain;display:block}'
				. '.ropemark-ai-badge:focus-visible{outline:2px solid #fff;outline-offset:2px}';
			foreach ( $pos as $name => $rule ) {
				$css .= ".ropemark-ai-badge.ropemark-pos-{$name}{{$rule}}";
			}
			$css .= '.ropemark-popup{position:absolute;z-index:3;right:8px;bottom:8px;left:8px;max-width:360px;margin-left:auto;padding:14px 16px;border-radius:10px;background:#fff;color:#1d2327;box-shadow:0 8px 30px rgba(0,0,0,.25);font:14px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;text-align:left;line-height:1.45}'
				. '.ropemark-popup[hidden]{display:none}'
				. '.ropemark-popup-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px}'
				. '.ropemark-popup-title{font-weight:600}'
				. '.ropemark-popup-close{border:0;background:none;font-size:22px;line-height:1;cursor:pointer;color:inherit;padding:0 2px}'
				. '.ropemark-popup-rows{margin:0;display:grid;grid-template-columns:auto 1fr;gap:4px 12px;font-size:13px}'
				. '.ropemark-popup-rows dt{font-weight:600;margin:0}.ropemark-popup-rows dd{margin:0;word-break:break-word}'
				. '.ropemark-popup-note,.ropemark-popup-credit{margin:10px 0 0;font-size:12px;opacity:.75}'
				. '.ropemark-popup-credit a{color:inherit;text-decoration:underline}';
		}
		if ( $o['frontend_label'] || $forced ) {
			$css .= '.ropemark-label{display:block;font-size:.8em;line-height:1.4;opacity:.75;margin:.35em 0 0}';
		}
		if ( $o['footer_notice'] ) {
			$css .= '.ropemark-site-notice{font-size:.8em;line-height:1.4;opacity:.75;text-align:center;padding:12px 16px;margin:0}';
		}
		return $css;
	}

	/**
	 * The few lines that open and close the provenance popup.
	 *
	 * @return string
	 */
	private static function popup_js() {
		return "(function(){function all(){return document.querySelectorAll('.ropemark-popup:not([hidden])');}"
			. "function closeAll(){all().forEach(function(p){p.hidden=true;var b=document.querySelector('[aria-controls=\"'+p.id+'\"]');if(b){b.setAttribute('aria-expanded','false');}});}"
			. "document.addEventListener('click',function(e){var b=e.target.closest('.ropemark-ai-badge[aria-controls]');if(b){e.preventDefault();var p=document.getElementById(b.getAttribute('aria-controls'));if(!p){return;}var open=p.hidden;closeAll();if(open){p.hidden=false;b.setAttribute('aria-expanded','true');var c=p.querySelector('.ropemark-popup-close');if(c){c.focus();}}return;}"
			. "if(e.target.closest('.ropemark-popup-close')){closeAll();return;}"
			. "if(!e.target.closest('.ropemark-popup')){closeAll();}});"
			. "document.addEventListener('keydown',function(e){if(e.key==='Escape'){closeAll();}});})();";
	}

	/**
	 * Footer: popup script, schema.org graph, site notice, signature comment.
	 */
	public static function footer() {
		$o = Ropemark_Options::all();
		if ( $o['footer_notice'] ) {
			echo '<p class="ropemark-site-notice">' . esc_html( $o['notice_text'] ) . '</p>' . "\n";
		}
		if ( $o['schema_enabled'] && ! empty( self::$rendered ) ) {
			$graph = array();
			foreach ( self::$rendered as $id => $item ) {
				$r   = $item['record'];
				$dst = $r['digital_source_type'] ? $r['digital_source_type'] : $r['c2pa_digital_source_type'];
				if ( '' === $dst ) {
					continue;
				}
				$node = array(
					'@type'             => 'ImageObject',
					'contentUrl'        => $item['src'] ? $item['src'] : wp_get_attachment_url( $id ),
					'digitalSourceType' => Ropemark_Reader::CV . $dst,
				);
				if ( $r['description'] ) {
					$node['description'] = $r['description'];
				}
				if ( $r['creator'] ) {
					$node['creator'] = array(
						'@type' => 'Organization',
						'name'  => $r['creator'],
					);
				}
				if ( $r['credit'] ) {
					$node['creditText'] = $r['credit'];
				}
				if ( $r['rights'] ) {
					$node['copyrightNotice'] = $r['rights'];
				}
				$graph[] = $node;
			}
			if ( $graph ) {
				$json = wp_json_encode(
					array(
						'@context' => 'https://schema.org',
						'@graph'   => $graph,
					),
					JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				);
				wp_print_inline_script_tag( $json, array( 'type' => 'application/ld+json' ) );
			}
		}
		if ( ! empty( self::$rendered ) ) {
			echo "<!-- AI-generated images on this page are marked and disclosed with Ropemark Image Marking for the EU AI Act by The Rope, https://therope.it -->\n";
		}
	}

	/**
	 * [ropemark_disclosure text="" count="1"] shortcode.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts  = shortcode_atts(
			array(
				'text'  => Ropemark_Options::get( 'notice_text' ),
				'count' => '1',
			),
			$atts,
			'ropemark_disclosure'
		);
		$html  = '<div class="ropemark-disclosure"><p>' . esc_html( $atts['text'] ) . '</p>';
		if ( '1' === (string) $atts['count'] ) {
			$n = (int) Ropemark_Processor::counts()['ai'];
			/* translators: %d: number of images */
			$html .= '<p class="ropemark-disclosure-count">' . esc_html( sprintf( _n( '%d image in the media library is marked as AI generated or AI modified.', '%d images in the media library are marked as AI generated or AI modified.', $n, 'ropemark-image-marking-for-eu-ai-act' ), $n ) ) . '</p>';
		}
		return $html . '</div>';
	}

	/**
	 * Dynamic block wrapping the shortcode.
	 */
	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script( 'ropemark-block', ROPEMARK_URL . 'assets/block.js', array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components' ), ROPEMARK_VERSION, true );
		wp_set_script_translations( 'ropemark-block', 'ropemark-image-marking-for-eu-ai-act' );
		register_block_type(
			'ropemark/disclosure',
			array(
				'api_version'     => 3,
				'editor_script'   => 'ropemark-block',
				'attributes'      => array(
					'text'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'count' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
				'render_callback' => function ( $attributes ) {
					return self::shortcode(
						array(
							'text'  => ! empty( $attributes['text'] ) ? $attributes['text'] : Ropemark_Options::get( 'notice_text' ),
							'count' => empty( $attributes['count'] ) ? '0' : '1',
						)
					);
				},
			)
		);
	}

	/**
	 * Hex color to RGB triplet.
	 *
	 * @param string $hex #rrggbb or #rgb.
	 * @return int[]
	 */
	private static function hex_to_rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			return array( 0, 0, 0 );
		}
		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}
}
