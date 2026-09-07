<?php
/**
 * Front end: data attributes, optional text label and optional "AI" badge.
 *
 * The visible disclosure required by AI Act article 50(4) belongs to the
 * page, not the file. This class offers the tools for it; the site decides.
 *
 * @package AI_Act_Image_Disclosure
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front end output.
 */
class AIPK_Frontend {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'image_attributes' ), 10, 2 );
		add_filter( 'wp_content_img_tag', array( __CLASS__, 'content_img_tag' ), 10, 3 );
		add_filter( 'wp_get_attachment_image', array( __CLASS__, 'attachment_image_html' ), 10, 2 );
		add_action( 'wp_head', array( __CLASS__, 'inline_css' ), 20 );
	}

	/**
	 * Add data attributes to images rendered through wp_get_attachment_image().
	 *
	 * @param array   $attr       Attributes.
	 * @param WP_Post $attachment Attachment.
	 * @return array
	 */
	public static function image_attributes( $attr, $attachment ) {
		if ( ! AIPK_Options::get( 'frontend_attrs' ) || ! $attachment instanceof WP_Post ) {
			return $attr;
		}
		$record = AIPK_Processor::record( $attachment->ID );
		if ( $record && ! empty( $record['digital_source_type'] ) ) {
			$attr['data-digital-source-type'] = $record['digital_source_type'];
			if ( $record['ai'] ) {
				$attr['data-ai-generated'] = 'true';
			}
		}
		return $attr;
	}

	/**
	 * Images inside post content (WordPress 6.0+): attributes, badge, label.
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
		$record = AIPK_Processor::record( $attachment_id );
		if ( ! $record || empty( $record['digital_source_type'] ) ) {
			return $filtered_image;
		}
		if ( AIPK_Options::get( 'frontend_attrs' ) && false === strpos( $filtered_image, 'data-digital-source-type=' ) ) {
			$attrs = ' data-digital-source-type="' . esc_attr( $record['digital_source_type'] ) . '"';
			if ( $record['ai'] ) {
				$attrs .= ' data-ai-generated="true"';
			}
			$filtered_image = preg_replace( '/<img\b/i', '<img' . $attrs, $filtered_image, 1 );
		}
		return self::decorate( $filtered_image, $record );
	}

	/**
	 * Output of wp_get_attachment_image(): badge and label.
	 *
	 * @param string $html          Image HTML.
	 * @param int    $attachment_id Attachment id.
	 * @return string
	 */
	public static function attachment_image_html( $html, $attachment_id ) {
		if ( is_admin() || is_feed() || '' === $html ) {
			return $html;
		}
		$record = AIPK_Processor::record( $attachment_id );
		return $record ? self::decorate( $html, $record ) : $html;
	}

	/**
	 * Wrap an AI image with the badge and append the text label, as configured.
	 *
	 * @param string $html   Image HTML (an img tag).
	 * @param array  $record Provenance record.
	 * @return string
	 */
	private static function decorate( $html, $record ) {
		// The same img tag can pass through more than one filter (content tags, block render,
		// attachment image): the marker attribute makes the second pass a no-op.
		if ( empty( $record['ai'] ) || false !== strpos( $html, 'aipk-wrap' ) || false !== strpos( $html, 'data-aipk=' ) ) {
			return $html;
		}
		/**
		 * Whether this image gets the visible badge and label.
		 *
		 * @param bool   $show   Default true for AI images.
		 * @param array  $record Provenance record.
		 * @param string $html   Image tag.
		 */
		if ( ! apply_filters( 'aipk_decorate_image', true, $record, $html ) ) {
			return $html;
		}
		$out = preg_replace( '/<img\b/i', '<img data-aipk="1"', $html, 1 );
		if ( AIPK_Options::get( 'badge_enabled' ) && self::wide_enough( $html ) ) {
			$title = AIPK_Options::get( 'badge_title' );
			$text  = AIPK_Options::get( 'badge_text' );
			/**
			 * Filter the badge text for one image.
			 *
			 * @param string $text   Badge text.
			 * @param array  $record Provenance record.
			 */
			$text = apply_filters( 'aipk_badge_text', $text, $record );
			$out  = '<span class="aipk-wrap">' . $out
				. '<span class="aipk-ai-badge aipk-pos-' . esc_attr( AIPK_Options::get( 'badge_position' ) ) . '" title="' . esc_attr( $title ) . '" aria-label="' . esc_attr( $title ) . '" role="img">'
				. esc_html( $text ) . '</span></span>';
		}
		if ( AIPK_Options::get( 'frontend_label' ) ) {
			$label = AIPK_Options::get( 'label_text' );
			/**
			 * Filter the visible label text for one image.
			 *
			 * @param string $label  Label text.
			 * @param array  $record Provenance record.
			 */
			$label = apply_filters( 'aipk_label_text', $label, $record );
			$out  .= '<span class="aipk-label" role="note">' . esc_html( $label ) . '</span>';
		}
		return $out;
	}

	/**
	 * Skip the badge on tiny renditions (width attribute below the threshold).
	 *
	 * @param string $html Image tag.
	 * @return bool
	 */
	private static function wide_enough( $html ) {
		$min = (int) AIPK_Options::get( 'badge_min_width' );
		if ( $min <= 0 ) {
			return true;
		}
		if ( preg_match( '/\swidth=["\']?(\d+)/i', $html, $m ) ) {
			return (int) $m[1] >= $min;
		}
		return true;
	}

	/**
	 * CSS for the badge and the label, printed only when one of them is enabled.
	 */
	public static function inline_css() {
		$o = AIPK_Options::all();
		if ( ! $o['badge_enabled'] && ! $o['frontend_label'] && '' === trim( $o['custom_css'] ) ) {
			return;
		}
		$css = '';
		if ( $o['badge_enabled'] ) {
			$size    = (int) $o['badge_size'];
			$offset  = max( 4, (int) round( $size / 3 ) );
			$opacity = max( 0, min( 1, (int) $o['badge_opacity'] / 100 ) );
			$rgb     = self::hex_to_rgb( $o['badge_bg'] );
			$pos     = array(
				'bottom-right' => "bottom:{$offset}px;right:{$offset}px",
				'bottom-left'  => "bottom:{$offset}px;left:{$offset}px",
				'top-right'    => "top:{$offset}px;right:{$offset}px",
				'top-left'     => "top:{$offset}px;left:{$offset}px",
			);
			$css .= '.aipk-wrap{position:relative;display:inline-block;max-width:100%;line-height:0}'
				. '.aipk-wrap>img{display:block}'
				. '.aipk-ai-badge{position:absolute;z-index:2;display:flex;align-items:center;justify-content:center;'
				. "width:{$size}px;height:{$size}px;border-radius:50%;"
				. "background:rgba({$rgb[0]},{$rgb[1]},{$rgb[2]},{$opacity});color:" . $o['badge_color'] . ';'
				. 'font:600 ' . max( 9, (int) round( $size * 0.42 ) ) . 'px/1 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;'
				. 'letter-spacing:.02em;text-transform:uppercase;pointer-events:none;user-select:none;backdrop-filter:blur(2px)}';
			foreach ( $pos as $name => $rule ) {
				$css .= ".aipk-ai-badge.aipk-pos-{$name}{{$rule}}";
			}
		}
		if ( $o['frontend_label'] ) {
			$css .= '.aipk-label{display:block;font-size:.8em;line-height:1.4;opacity:.75;margin:.35em 0 0}';
		}
		$css .= "\n" . wp_strip_all_tags( $o['custom_css'] );
		echo '<style id="aipk-css">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS built from sanitized options.
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
