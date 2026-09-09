<?php
/**
 * Turns the raw bundle into a provenance record: IPTC digital source type,
 * disclosure text, attribution, C2PA generator names and generator
 * signatures for images that carry no formal marking.
 *
 * @package Ropemark_Image_Marking
 */

defined( 'ABSPATH' ) || exit;

/**
 * Provenance reader.
 */
class Ropemark_Reader {

	const CV = 'http://cv.iptc.org/newscodes/digitalsourcetype/';

	/**
	 * IPTC digital source type terms that mean "AI was involved".
	 *
	 * @var string[]
	 */
	public static $ai_terms = array(
		'trainedAlgorithmicMedia',
		'compositeWithTrainedAlgorithmicMedia',
		'algorithmicMedia',
		'compositeSynthetic',
		'virtualRecording',
	);

	/**
	 * Generator signatures: regex => label. Matched against software fields,
	 * XMP text and PNG text chunks. A match is a hint, never a marking.
	 *
	 * @var array
	 */
	public static $signatures = array(
		'/midjourney/i'                         => 'Midjourney',
		'/dall[\s\-·]?e/i'                      => 'DALL·E',
		'/adobe firefly|firefly/i'              => 'Adobe Firefly',
		'/stable[\s\-]?diffusion|sd-?xl|automatic1111|invokeai|fooocus/i' => 'Stable Diffusion',
		'/comfyui/i'                            => 'ComfyUI',
		'/novelai/i'                            => 'NovelAI',
		'/leonardo\.?ai/i'                      => 'Leonardo.ai',
		'/ideogram/i'                           => 'Ideogram',
		'/\bflux\b(\.1|[\s\-]?(pro|dev|schnell|kontext))/i' => 'Flux',
		'/imagen|nano ?banana|gemini|google generative ai|google c2pa/i' => 'Google',
		'/openai|gpt-?image|sora/i'             => 'OpenAI',
		'/recraft/i'                            => 'Recraft',
		'/runway/i'                             => 'Runway',
		'/higgsfield/i'                         => 'Higgsfield',
		'/krea\.?ai/i'                          => 'Krea',
		'/bing image creator|microsoft designer/i' => 'Microsoft Designer',
		'/canva ai|magic media/i'               => 'Canva AI',
		'/synthid/i'                            => 'SynthID watermark',
	);

	/**
	 * Read a file and return the provenance record.
	 *
	 * @param string $path Absolute path.
	 * @return array Record with 'readable' false when the format is unsupported.
	 */
	public static function read( $path ) {
		$record = self::empty_record();
		$bundle = Ropemark_Segments::extract( $path );
		if ( null === $bundle ) {
			return $record;
		}
		$record['readable'] = true;
		$record['format']   = $bundle['format'];
		$record['has_xmp']  = '' !== $bundle['xmp'];
		$record['has_iptc'] = '' !== $bundle['iptc'];
		$record['has_c2pa'] = $bundle['c2pa'];

		if ( $record['has_xmp'] ) {
			$record = array_merge( $record, self::parse_xmp( $bundle['xmp'] ) );
		}
		if ( $record['has_iptc'] && '' === $record['description'] ) {
			$record = array_merge( $record, self::parse_iptc( $bundle['iptc'] ) );
		}
		if ( ! empty( $bundle['exif']['Software'] ) ) {
			$record['software'] = $bundle['exif']['Software'];
		}
		if ( $bundle['c2pa'] ) {
			$record['generators']               = self::c2pa_generators( $bundle['c2pa_raw'] );
			$record['c2pa_digital_source_type'] = self::c2pa_dst( $bundle['c2pa_raw'] );
		}
		$dst              = $record['digital_source_type'] ? $record['digital_source_type'] : $record['c2pa_digital_source_type'];
		$record['ai']     = in_array( $dst, self::$ai_terms, true );
		$record['source'] = $record['digital_source_type'] ? 'xmp' : ( $record['c2pa_digital_source_type'] ? 'c2pa' : '' );

		// Signatures: where the marking is missing, say who probably made it.
		$haystack = implode(
			"\n",
			array_filter(
				array(
					$record['software'],
					$record['creator_tool'],
					$record['description'],
					$record['instructions'],
					implode( "\n", $record['generators'] ),
					implode( "\n", array_keys( $bundle['text'] ) ),
					implode( "\n", $bundle['text'] ),
					$record['has_xmp'] ? substr( $bundle['xmp'], 0, 20000 ) : '',
				)
			)
		);
		$found = array();
		foreach ( self::$signatures as $re => $label ) {
			if ( preg_match( $re, $haystack ) ) {
				$found[ $label ] = true;
			}
		}
		if ( isset( $bundle['text']['parameters'] ) || isset( $bundle['text']['prompt'] ) || isset( $bundle['text']['workflow'] ) ) {
			$found[ isset( $bundle['text']['workflow'] ) ? 'ComfyUI' : 'Stable Diffusion' ] = true;
		}
		$record['signatures'] = array_keys( $found );
		$record['suspect']    = ! $record['ai'] && '' === $dst && ! empty( $record['signatures'] );
		return $record;
	}

	/**
	 * Empty record.
	 *
	 * @return array
	 */
	public static function empty_record() {
		return array(
			'readable'                 => false,
			'format'                   => '',
			'has_xmp'                  => false,
			'has_iptc'                 => false,
			'has_c2pa'                 => false,
			'digital_source_type'      => '',
			'ai'                       => false,
			'source'                   => '',
			'description'              => '',
			'creator'                  => '',
			'credit'                   => '',
			'rights'                   => '',
			'usage_terms'              => '',
			'instructions'             => '',
			'creator_tool'             => '',
			'software'                 => '',
			'generators'               => array(),
			'c2pa_digital_source_type' => '',
			'signatures'               => array(),
			'suspect'                  => false,
			'manual'                   => '',
		);
	}

	/**
	 * Parse the fields we care about from an XMP packet.
	 *
	 * @param string $xmp XMP XML.
	 * @return array
	 */
	public static function parse_xmp( $xmp ) {
		$out = array();
		$map = array(
			'digital_source_type' => array( 'http://iptc.org/std/Iptc4xmpExt/2008-02-29/', 'DigitalSourceType' ),
			'description'         => array( 'http://purl.org/dc/elements/1.1/', 'description' ),
			'creator'             => array( 'http://purl.org/dc/elements/1.1/', 'creator' ),
			'rights'              => array( 'http://purl.org/dc/elements/1.1/', 'rights' ),
			'credit'              => array( 'http://ns.adobe.com/photoshop/1.0/', 'Credit' ),
			'instructions'        => array( 'http://ns.adobe.com/photoshop/1.0/', 'Instructions' ),
			'usage_terms'         => array( 'http://ns.adobe.com/xap/1.0/rights/', 'UsageTerms' ),
			'creator_tool'        => array( 'http://ns.adobe.com/xap/1.0/', 'CreatorTool' ),
		);

		$prev = libxml_use_internal_errors( true );
		$doc  = new DOMDocument();
		$ok   = $doc->loadXML( $xmp, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( $ok ) {
			$xp = new DOMXPath( $doc );
			$xp->registerNamespace( 'rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#' );
			foreach ( $map as $key => $ns ) {
				$xp->registerNamespace( 'n', $ns[0] );
				$val   = '';
				$nodes = $xp->query( '//n:' . $ns[1] );
				if ( $nodes && $nodes->length ) {
					$el = $nodes->item( 0 );
					$li = $xp->query( './/rdf:li[@xml:lang="x-default"]', $el );
					if ( ! $li || ! $li->length ) {
						$li = $xp->query( './/rdf:li', $el );
					}
					$val = ( $li && $li->length ) ? $li->item( 0 )->textContent : $el->textContent;
				}
				if ( '' === trim( (string) $val ) ) {
					$attr = $xp->query( '//rdf:Description/@n:' . $ns[1] );
					if ( $attr && $attr->length ) {
						$val = $attr->item( 0 )->nodeValue;
					}
				}
				$val = trim( (string) $val );
				if ( '' !== $val ) {
					$out[ $key ] = $val;
				}
			}
		} elseif ( preg_match( '#DigitalSourceType\s*(?:=\s*"|>\s*)([^"<\s]+)#', $xmp, $m ) ) {
			$out['digital_source_type'] = $m[1];
		}
		if ( ! empty( $out['digital_source_type'] ) ) {
			$out['digital_source_type'] = self::short_term( $out['digital_source_type'] );
		}
		return $out;
	}

	/**
	 * Parse IPTC IIM caption, byline, credit, copyright from a Photoshop IRB block.
	 *
	 * @param string $irb 8BIM blocks.
	 * @return array
	 */
	public static function parse_iptc( $irb ) {
		$out = array();
		if ( ! function_exists( 'iptcparse' ) ) {
			return $out;
		}
		$parsed = @iptcparse( Ropemark_Segments::PHOTOSHOP_HEADER . $irb ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $parsed ) ) {
			return $out;
		}
		$fields = array(
			'description'  => '2#120',
			'creator'      => '2#080',
			'credit'       => '2#110',
			'rights'       => '2#116',
			'instructions' => '2#040',
		);
		foreach ( $fields as $key => $code ) {
			if ( ! empty( $parsed[ $code ][0] ) ) {
				$out[ $key ] = trim( (string) $parsed[ $code ][0] );
			}
		}
		return $out;
	}

	/**
	 * Shorten a digital source type URI to its term.
	 *
	 * @param string $value URI or term.
	 * @return string
	 */
	public static function short_term( $value ) {
		$value = trim( (string) $value );
		if ( 0 === strpos( $value, self::CV ) ) {
			return substr( $value, strlen( self::CV ) );
		}
		return preg_replace( '#^.*/#', '', $value );
	}

	/**
	 * Human label for a digital source type term.
	 *
	 * @param string $term Term.
	 * @return string
	 */
	public static function term_label( $term ) {
		$labels = array(
			'trainedAlgorithmicMedia'              => __( 'AI generated', 'ropemark-image-marking-for-eu-ai-act' ),
			'compositeWithTrainedAlgorithmicMedia' => __( 'AI modified', 'ropemark-image-marking-for-eu-ai-act' ),
			'algorithmicMedia'                     => __( 'Algorithmically generated (no AI training)', 'ropemark-image-marking-for-eu-ai-act' ),
			'compositeSynthetic'                   => __( 'Composite of synthetic elements', 'ropemark-image-marking-for-eu-ai-act' ),
			'virtualRecording'                     => __( 'Virtual recording', 'ropemark-image-marking-for-eu-ai-act' ),
			'digitalCapture'                       => __( 'Digital capture (camera)', 'ropemark-image-marking-for-eu-ai-act' ),
			'negativeFilm'                         => __( 'Scanned negative', 'ropemark-image-marking-for-eu-ai-act' ),
			'positiveFilm'                         => __( 'Scanned positive', 'ropemark-image-marking-for-eu-ai-act' ),
			'print'                                => __( 'Scanned print', 'ropemark-image-marking-for-eu-ai-act' ),
			'humanEdits'                           => __( 'Human edits', 'ropemark-image-marking-for-eu-ai-act' ),
			'compositeCapture'                     => __( 'Composite of captures', 'ropemark-image-marking-for-eu-ai-act' ),
			'algorithmicallyEnhanced'              => __( 'Algorithmically enhanced', 'ropemark-image-marking-for-eu-ai-act' ),
			'dataDrivenMedia'                      => __( 'Data driven media', 'ropemark-image-marking-for-eu-ai-act' ),
			'digitalArt'                           => __( 'Digital art', 'ropemark-image-marking-for-eu-ai-act' ),
			'screenCapture'                        => __( 'Screen capture', 'ropemark-image-marking-for-eu-ai-act' ),
		);
		return isset( $labels[ $term ] ) ? $labels[ $term ] : $term;
	}

	/**
	 * Claim generator names from raw JUMBF/CBOR bytes.
	 *
	 * @param string $raw JUMBF bytes.
	 * @return string[]
	 */
	public static function c2pa_generators( $raw ) {
		$names = array();
		$pos   = 0;
		while ( false !== ( $pos = strpos( $raw, "\x64name", $pos ) ) ) {
			$pos += 5;
			$str  = self::cbor_text_at( $raw, $pos );
			if ( '' !== $str && preg_match( '/^[\x20-\x7E]{2,80}$/', $str )
				&& ! in_array( strtolower( $str ), array( 'jumbf manifest', 'c2pa manifest' ), true )
				&& 0 !== strpos( $str, 'c2pa.' ) && 0 !== strpos( $str, 'urn:' ) ) {
				$names[ $str ] = true;
			}
		}
		return array_keys( $names );
	}

	/**
	 * First digitalSourceType URI declared in the C2PA actions.
	 *
	 * @param string $raw JUMBF bytes.
	 * @return string
	 */
	public static function c2pa_dst( $raw ) {
		$terms = array(
			'compositeWithTrainedAlgorithmicMedia', 'trainedAlgorithmicMedia', 'algorithmicallyEnhanced',
			'compositeSynthetic', 'algorithmicMedia', 'compositeCapture', 'virtualRecording',
			'dataDrivenMedia', 'digitalCapture', 'screenCapture', 'negativeFilm', 'positiveFilm',
			'humanEdits', 'digitalArt', 'print',
		);
		if ( preg_match( '#cv\.iptc\.org/newscodes/digitalsourcetype/(' . implode( '|', $terms ) . ')#', $raw, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Decode a CBOR text string (major type 3) at offset.
	 *
	 * @param string $raw Bytes.
	 * @param int    $pos Offset of the initial byte.
	 * @return string
	 */
	private static function cbor_text_at( $raw, $pos ) {
		if ( $pos >= strlen( $raw ) ) {
			return '';
		}
		$ib = ord( $raw[ $pos ] );
		if ( ( $ib >> 5 ) !== 3 ) {
			return '';
		}
		$ai = $ib & 0x1F;
		if ( $ai < 24 ) {
			return substr( $raw, $pos + 1, $ai );
		}
		if ( 24 === $ai ) {
			return substr( $raw, $pos + 2, ord( $raw[ $pos + 1 ] ) );
		}
		if ( 25 === $ai ) {
			return substr( $raw, $pos + 3, unpack( 'n', substr( $raw, $pos + 1, 2 ) )[1] );
		}
		return '';
	}
}
