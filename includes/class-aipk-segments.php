<?php
/**
 * Raw metadata segment extraction and injection for JPEG, PNG and WebP.
 *
 * WordPress regenerates every image size through GD or Imagick, and both
 * drop XMP and IPTC. This class copies those blocks from the uploaded
 * original into each generated file, byte for byte, without touching pixels.
 *
 * C2PA manifests (JUMBF) are deliberately not copied: their hard binding
 * covers the exact bytes of the original file, so a copy inside a resized
 * derivative would validate as tampered.
 *
 * @package AI_Act_Image_Disclosure
 */

defined( 'ABSPATH' ) || exit;

/**
 * Segment level reader and writer.
 */
class AIPK_Segments {

	const XMP_HEADER = "http://ns.adobe.com/xap/1.0/\0";
	const XMP_EXT_HEADER = "http://ns.adobe.com/xmp/extension/\0";
	const PHOTOSHOP_HEADER = "Photoshop 3.0\0";
	const JUMBF_HEADER = "JP";
	const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

	/**
	 * Extract the provenance bundle from a file.
	 *
	 * @param string $path Absolute path.
	 * @return array{format:string,xmp:string,iptc:string,c2pa:bool,c2pa_raw:string}|null Null when unreadable.
	 */
	public static function extract( $path ) {
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$format = self::detect_format( $path );
		switch ( $format ) {
			case 'jpeg':
				return self::extract_jpeg( $path );
			case 'png':
				return self::extract_png( $path );
			case 'webp':
				return self::extract_webp( $path );
		}
		return null;
	}

	/**
	 * Inject XMP (and, for JPEG, IPTC) into a file.
	 *
	 * @param string $path   Target file, rewritten in place.
	 * @param array  $bundle Bundle from extract().
	 * @return true|WP_Error
	 */
	public static function inject( $path, $bundle ) {
		if ( ! is_readable( $path ) || ! wp_is_writable( $path ) ) {
			return new WP_Error( 'aipk_unwritable', 'File is not writable.' );
		}
		if ( empty( $bundle['xmp'] ) && empty( $bundle['iptc'] ) ) {
			return new WP_Error( 'aipk_nothing', 'Nothing to inject.' );
		}
		$format = self::detect_format( $path );
		switch ( $format ) {
			case 'jpeg':
				$out = self::inject_jpeg( $path, $bundle );
				break;
			case 'png':
				$out = self::inject_png( $path, $bundle );
				break;
			case 'webp':
				$out = self::inject_webp( $path, $bundle );
				break;
			default:
				return new WP_Error( 'aipk_format', 'Unsupported format for injection.' );
		}
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$tmp = $path . '.aipk-tmp';
		if ( false === file_put_contents( $tmp, $out ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new WP_Error( 'aipk_write', 'Could not write temporary file.' );
		}
		// Atomic replace: the derivative is never half-written while a visitor requests it.
		if ( ! rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			wp_delete_file( $tmp );
			return new WP_Error( 'aipk_rename', 'Could not replace target file.' );
		}
		return true;
	}

	/**
	 * Detect format from magic bytes.
	 *
	 * @param string $path File path.
	 * @return string jpeg|png|webp|''
	 */
	public static function detect_format( $path ) {
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return '';
		}
		$head = fread( $fh, 12 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( strlen( $head ) < 12 ) {
			return '';
		}
		if ( "\xFF\xD8" === substr( $head, 0, 2 ) ) {
			return 'jpeg';
		}
		if ( self::PNG_SIGNATURE === substr( $head, 0, 8 ) ) {
			return 'png';
		}
		if ( 'RIFF' === substr( $head, 0, 4 ) && 'WEBP' === substr( $head, 8, 4 ) ) {
			return 'webp';
		}
		return '';
	}

	/* ------------------------------------------------------------------ JPEG */

	/**
	 * Walk JPEG marker segments up to SOS.
	 *
	 * @param string $data File contents.
	 * @return array{segments:array,offset:int} Segments (marker, start, length, payload) and offset of the first non-segment byte.
	 */
	private static function jpeg_segments( $data ) {
		$len      = strlen( $data );
		$pos      = 2; // after SOI.
		$segments = array();
		while ( $pos + 4 <= $len ) {
			if ( "\xFF" !== $data[ $pos ] ) {
				break;
			}
			$marker = ord( $data[ $pos + 1 ] );
			if ( 0xFF === $marker ) { // padding.
				$pos++;
				continue;
			}
			if ( 0xD8 === $marker || ( $marker >= 0xD0 && $marker <= 0xD7 ) || 0x01 === $marker ) {
				$pos += 2; // standalone markers.
				continue;
			}
			if ( 0xDA === $marker || 0xD9 === $marker ) { // SOS or EOI: image data begins.
				break;
			}
			$seglen = ( ord( $data[ $pos + 2 ] ) << 8 ) | ord( $data[ $pos + 3 ] );
			if ( $seglen < 2 || $pos + 2 + $seglen > $len ) {
				break;
			}
			$segments[] = array(
				'marker'  => $marker,
				'start'   => $pos,
				'length'  => 2 + $seglen,
				'payload' => substr( $data, $pos + 4, $seglen - 2 ),
			);
			$pos += 2 + $seglen;
		}
		return array(
			'segments' => $segments,
			'offset'   => $pos,
		);
	}

	/**
	 * Extract from JPEG.
	 *
	 * @param string $path File path.
	 * @return array
	 */
	private static function extract_jpeg( $path ) {
		$data   = self::read_head( $path );
		$walk   = self::jpeg_segments( $data );
		$bundle = self::empty_bundle( 'jpeg' );
		$ext    = array();
		foreach ( $walk['segments'] as $seg ) {
			$p = $seg['payload'];
			if ( 0xE1 === $seg['marker'] ) {
				if ( 0 === strpos( $p, self::XMP_HEADER ) ) {
					$bundle['xmp'] = substr( $p, strlen( self::XMP_HEADER ) );
				} elseif ( 0 === strpos( $p, self::XMP_EXT_HEADER ) ) {
					$ext[] = $seg;
				}
			} elseif ( 0xED === $seg['marker'] && 0 === strpos( $p, self::PHOTOSHOP_HEADER ) ) {
				// Keep only the IPTC (0x0404) resource block; drop thumbnails and Photoshop private data.
				$iptc = self::irb_filter( substr( $p, strlen( self::PHOTOSHOP_HEADER ) ), array( 0x0404 ) );
				if ( '' !== $iptc ) {
					$bundle['iptc'] = $iptc;
				}
			} elseif ( 0xEB === $seg['marker'] && 0 === strpos( $p, self::JUMBF_HEADER ) ) {
				$bundle['c2pa_raw'] .= substr( $p, 2 );
				if ( false !== strpos( $p, 'c2pa' ) ) {
					$bundle['c2pa'] = true;
				}
			}
		}
		$bundle['xmp_extended'] = $ext;
		return $bundle;
	}

	/**
	 * Inject into JPEG: drop existing XMP/APP13, insert ours after APP0/EXIF.
	 *
	 * @param string $path   Target.
	 * @param array  $bundle Bundle.
	 * @return string|WP_Error New file contents.
	 */
	private static function inject_jpeg( $path, $bundle ) {
		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $data || "\xFF\xD8" !== substr( $data, 0, 2 ) ) {
			return new WP_Error( 'aipk_jpeg', 'Not a JPEG.' );
		}
		$walk = self::jpeg_segments( $data );
		$head = array(); // APP0, EXIF first.
		$rest = array();
		foreach ( $walk['segments'] as $seg ) {
			$p = $seg['payload'];
			$is_xmp  = 0xE1 === $seg['marker'] && ( 0 === strpos( $p, self::XMP_HEADER ) || 0 === strpos( $p, self::XMP_EXT_HEADER ) );
			$is_irb  = 0xED === $seg['marker'] && 0 === strpos( $p, self::PHOTOSHOP_HEADER );
			if ( $is_xmp || $is_irb ) {
				continue; // replaced below.
			}
			$raw = substr( $data, $seg['start'], $seg['length'] );
			if ( 0xE0 === $seg['marker'] || ( 0xE1 === $seg['marker'] && 0 === strpos( $p, "Exif\0" ) ) ) {
				$head[] = $raw;
			} else {
				$rest[] = $raw;
			}
		}
		$ours = array();
		if ( ! empty( $bundle['xmp'] ) ) {
			$ours[] = self::jpeg_segment( 0xE1, self::XMP_HEADER . $bundle['xmp'] );
			if ( ! empty( $bundle['xmp_extended'] ) ) {
				foreach ( $bundle['xmp_extended'] as $seg ) {
					$ours[] = self::jpeg_segment( 0xE1, $seg['payload'] );
				}
			}
		}
		if ( ! empty( $bundle['iptc'] ) ) {
			$ours[] = self::jpeg_segment( 0xED, self::PHOTOSHOP_HEADER . $bundle['iptc'] );
		}
		if ( empty( $ours ) ) {
			return new WP_Error( 'aipk_nothing', 'Nothing to inject.' );
		}
		return "\xFF\xD8" . implode( '', $head ) . implode( '', $ours ) . implode( '', $rest ) . substr( $data, $walk['offset'] );
	}

	/**
	 * Build a JPEG APPn segment. Payloads over 65533 bytes cannot fit and are dropped.
	 *
	 * @param int    $marker  Marker byte.
	 * @param string $payload Payload.
	 * @return string
	 */
	private static function jpeg_segment( $marker, $payload ) {
		$len = strlen( $payload ) + 2;
		if ( $len > 0xFFFF ) {
			return '';
		}
		return "\xFF" . chr( $marker ) . chr( $len >> 8 ) . chr( $len & 0xFF ) . $payload;
	}

	/**
	 * Keep only the wanted Photoshop Image Resource Blocks.
	 *
	 * @param string $irb  Concatenated 8BIM blocks.
	 * @param int[]  $keep Resource ids to keep.
	 * @return string
	 */
	private static function irb_filter( $irb, $keep ) {
		$out = '';
		$pos = 0;
		$len = strlen( $irb );
		while ( $pos + 12 <= $len ) {
			if ( '8BIM' !== substr( $irb, $pos, 4 ) ) {
				break;
			}
			$id      = ( ord( $irb[ $pos + 4 ] ) << 8 ) | ord( $irb[ $pos + 5 ] );
			$namelen = ord( $irb[ $pos + 6 ] );
			$namepad = ( $namelen + 1 ) % 2 ? $namelen + 2 : $namelen + 1; // Pascal string padded to even.
			$sizeoff = $pos + 6 + $namepad;
			if ( $sizeoff + 4 > $len ) {
				break;
			}
			$size  = unpack( 'N', substr( $irb, $sizeoff, 4 ) )[1];
			$total = 6 + $namepad + 4 + $size + ( $size % 2 );
			if ( $pos + $total > $len ) {
				break;
			}
			if ( in_array( $id, $keep, true ) ) {
				$out .= substr( $irb, $pos, $total );
			}
			$pos += $total;
		}
		return $out;
	}

	/* ------------------------------------------------------------------- PNG */

	/**
	 * Walk PNG chunks. IDAT payloads are skipped, not loaded.
	 *
	 * @param string $path File path.
	 * @return array List of chunks (type, start, length, data|null).
	 */
	private static function png_chunks( $path ) {
		$chunks = array();
		$fh     = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return $chunks;
		}
		fseek( $fh, 8 );
		while ( ! feof( $fh ) ) {
			$hdr = fread( $fh, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( 8 !== strlen( $hdr ) ) {
				break;
			}
			$len  = unpack( 'N', substr( $hdr, 0, 4 ) )[1];
			$type = substr( $hdr, 4, 4 );
			$start = ftell( $fh ) - 8;
			$data = null;
			if ( 'IDAT' === $type ) {
				fseek( $fh, $len + 4, SEEK_CUR );
			} else {
				$data = $len > 0 ? fread( $fh, $len ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				fseek( $fh, 4, SEEK_CUR ); // CRC.
			}
			$chunks[] = array(
				'type'   => $type,
				'start'  => $start,
				'length' => 12 + $len,
				'data'   => $data,
			);
			if ( 'IEND' === $type ) {
				break;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $chunks;
	}

	/**
	 * Extract from PNG (XMP in iTXt "XML:com.adobe.xmp"; C2PA in caBX).
	 *
	 * @param string $path File path.
	 * @return array
	 */
	private static function extract_png( $path ) {
		$bundle = self::empty_bundle( 'png' );
		foreach ( self::png_chunks( $path ) as $c ) {
			if ( 'iTXt' === $c['type'] && 0 === strpos( $c['data'], "XML:com.adobe.xmp\0" ) ) {
				// keyword\0 compression_flag compression_method language\0 translated\0 text.
				$rest = substr( $c['data'], strlen( "XML:com.adobe.xmp\0" ) );
				$flag = ord( $rest[0] );
				$rest = substr( $rest, 2 );
				$nul  = strpos( $rest, "\0" );
				$rest = substr( $rest, $nul + 1 );
				$nul  = strpos( $rest, "\0" );
				$text = substr( $rest, $nul + 1 );
				if ( 1 === $flag && function_exists( 'gzuncompress' ) ) {
					$text = (string) @gzuncompress( $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
				if ( '' !== $text ) {
					$bundle['xmp'] = $text;
				}
			} elseif ( 'caBX' === $c['type'] ) {
				$bundle['c2pa_raw'] .= (string) $c['data'];
				if ( false !== strpos( (string) $c['data'], 'c2pa' ) ) {
					$bundle['c2pa'] = true;
				}
			}
		}
		return $bundle;
	}

	/**
	 * Inject XMP into PNG as an uncompressed iTXt chunk right after IHDR.
	 *
	 * @param string $path   Target.
	 * @param array  $bundle Bundle.
	 * @return string|WP_Error
	 */
	private static function inject_png( $path, $bundle ) {
		if ( empty( $bundle['xmp'] ) ) {
			return new WP_Error( 'aipk_nothing', 'PNG carries XMP only.' );
		}
		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $data || self::PNG_SIGNATURE !== substr( $data, 0, 8 ) ) {
			return new WP_Error( 'aipk_png', 'Not a PNG.' );
		}
		$chunks = self::png_chunks( $path );
		$out    = self::PNG_SIGNATURE;
		$done   = false;
		foreach ( $chunks as $c ) {
			if ( 'iTXt' === $c['type'] && 0 === strpos( (string) $c['data'], "XML:com.adobe.xmp\0" ) ) {
				continue; // replaced.
			}
			$out .= substr( $data, $c['start'], $c['length'] );
			if ( 'IHDR' === $c['type'] && ! $done ) {
				$payload = "XML:com.adobe.xmp\0" . "\0\0" . "\0" . "\0" . $bundle['xmp'];
				$out    .= self::png_chunk( 'iTXt', $payload );
				$done    = true;
			}
		}
		return $out;
	}

	/**
	 * Build a PNG chunk with CRC.
	 *
	 * @param string $type Four letter type.
	 * @param string $data Payload.
	 * @return string
	 */
	private static function png_chunk( $type, $data ) {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	}

	/* ------------------------------------------------------------------ WebP */

	/**
	 * Walk WebP RIFF chunks.
	 *
	 * @param string $data File contents.
	 * @return array
	 */
	private static function webp_chunks( $data ) {
		$chunks = array();
		$len    = strlen( $data );
		$pos    = 12;
		while ( $pos + 8 <= $len ) {
			$type = substr( $data, $pos, 4 );
			$size = unpack( 'V', substr( $data, $pos + 4, 4 ) )[1];
			$chunks[] = array(
				'type'  => $type,
				'start' => $pos,
				'size'  => $size,
				'data'  => substr( $data, $pos + 8, $size ),
			);
			$pos += 8 + $size + ( $size % 2 );
		}
		return $chunks;
	}

	/**
	 * Extract from WebP (XMP chunk; C2PA chunk).
	 *
	 * @param string $path File path.
	 * @return array
	 */
	private static function extract_webp( $path ) {
		$data   = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$bundle = self::empty_bundle( 'webp' );
		if ( false === $data ) {
			return $bundle;
		}
		foreach ( self::webp_chunks( $data ) as $c ) {
			if ( 'XMP ' === $c['type'] ) {
				$bundle['xmp'] = $c['data'];
			} elseif ( 'C2PA' === $c['type'] ) {
				$bundle['c2pa_raw'] .= $c['data'];
				if ( false !== strpos( $c['data'], 'c2pa' ) ) {
					$bundle['c2pa'] = true;
				}
			}
		}
		return $bundle;
	}

	/**
	 * Inject XMP into WebP: ensure VP8X with the XMP flag, append the XMP chunk.
	 *
	 * @param string $path   Target.
	 * @param array  $bundle Bundle.
	 * @return string|WP_Error
	 */
	private static function inject_webp( $path, $bundle ) {
		if ( empty( $bundle['xmp'] ) ) {
			return new WP_Error( 'aipk_nothing', 'WebP carries XMP only.' );
		}
		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $data || 'RIFF' !== substr( $data, 0, 4 ) ) {
			return new WP_Error( 'aipk_webp', 'Not a WebP.' );
		}
		$chunks = self::webp_chunks( $data );
		$vp8x   = null;
		$body   = array();
		foreach ( $chunks as $c ) {
			if ( 'VP8X' === $c['type'] ) {
				$vp8x = $c['data'];
			} elseif ( 'XMP ' !== $c['type'] ) {
				$body[] = $c;
			}
		}
		if ( null === $vp8x ) {
			$dims = self::webp_dimensions( $chunks );
			if ( ! $dims ) {
				return new WP_Error( 'aipk_webp_dims', 'Cannot read WebP canvas size.' );
			}
			$flags = 0;
			foreach ( $body as $c ) {
				if ( 'ALPH' === $c['type'] ) {
					$flags |= 0x10;
				}
				if ( 'ICCP' === $c['type'] ) {
					$flags |= 0x20;
				}
				if ( 'EXIF' === $c['type'] ) {
					$flags |= 0x08;
				}
			}
			$vp8x = chr( $flags ) . "\0\0\0" . self::le24( $dims[0] - 1 ) . self::le24( $dims[1] - 1 );
		}
		$vp8x[0] = chr( ord( $vp8x[0] ) | 0x04 ); // XMP flag.
		$out     = self::webp_chunk( 'VP8X', $vp8x );
		foreach ( $body as $c ) {
			$out .= self::webp_chunk( $c['type'], $c['data'] );
		}
		$out .= self::webp_chunk( 'XMP ', $bundle['xmp'] );
		return 'RIFF' . pack( 'V', strlen( $out ) + 4 ) . 'WEBP' . $out;
	}

	/**
	 * Canvas size from a VP8 or VP8L bitstream.
	 *
	 * @param array $chunks Chunks.
	 * @return array{0:int,1:int}|null
	 */
	private static function webp_dimensions( $chunks ) {
		foreach ( $chunks as $c ) {
			if ( 'VP8 ' === $c['type'] && strlen( $c['data'] ) >= 10 && "\x9d\x01\x2a" === substr( $c['data'], 3, 3 ) ) {
				$w = unpack( 'v', substr( $c['data'], 6, 2 ) )[1] & 0x3FFF;
				$h = unpack( 'v', substr( $c['data'], 8, 2 ) )[1] & 0x3FFF;
				return array( $w, $h );
			}
			if ( 'VP8L' === $c['type'] && strlen( $c['data'] ) >= 5 && "\x2f" === $c['data'][0] ) {
				$b = unpack( 'V', substr( $c['data'], 1, 4 ) )[1];
				return array( ( $b & 0x3FFF ) + 1, ( ( $b >> 14 ) & 0x3FFF ) + 1 );
			}
		}
		return null;
	}

	/**
	 * Build a padded RIFF chunk.
	 *
	 * @param string $type Chunk id.
	 * @param string $data Payload.
	 * @return string
	 */
	private static function webp_chunk( $type, $data ) {
		return $type . pack( 'V', strlen( $data ) ) . $data . ( strlen( $data ) % 2 ? "\0" : '' );
	}

	/**
	 * 24-bit little endian.
	 *
	 * @param int $n Value.
	 * @return string
	 */
	private static function le24( $n ) {
		return chr( $n & 0xFF ) . chr( ( $n >> 8 ) & 0xFF ) . chr( ( $n >> 16 ) & 0xFF );
	}

	/* ------------------------------------------------------------------- XMP */

	/**
	 * Build a minimal XMP packet from provenance fields.
	 *
	 * @param array $f Fields: digital_source_type (term or URI), description, creator, credit, rights, usage_terms.
	 * @return string
	 */
	public static function build_xmp( $f ) {
		$dst = isset( $f['digital_source_type'] ) ? (string) $f['digital_source_type'] : '';
		if ( '' !== $dst && false === strpos( $dst, '://' ) ) {
			$dst = 'http://cv.iptc.org/newscodes/digitalsourcetype/' . $dst;
		}
		$x  = "<?xpacket begin=\"\xEF\xBB\xBF\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?>";
		$x .= '<x:xmpmeta xmlns:x="adobe:ns:meta/" x:xmptk="AI Act Image Disclosure">'
			. '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
			. '<rdf:Description rdf:about="" xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/"'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/"'
			. ' xmlns:xmpRights="http://ns.adobe.com/xap/1.0/rights/">';
		if ( '' !== $dst ) {
			$x .= '<Iptc4xmpExt:DigitalSourceType>' . self::xml( $dst ) . '</Iptc4xmpExt:DigitalSourceType>';
		}
		if ( ! empty( $f['description'] ) ) {
			$x .= '<dc:description><rdf:Alt><rdf:li xml:lang="x-default">' . self::xml( $f['description'] ) . '</rdf:li></rdf:Alt></dc:description>';
		}
		if ( ! empty( $f['creator'] ) ) {
			$x .= '<dc:creator><rdf:Seq><rdf:li>' . self::xml( $f['creator'] ) . '</rdf:li></rdf:Seq></dc:creator>';
		}
		if ( ! empty( $f['rights'] ) ) {
			$x .= '<dc:rights><rdf:Alt><rdf:li xml:lang="x-default">' . self::xml( $f['rights'] ) . '</rdf:li></rdf:Alt></dc:rights>';
		}
		if ( ! empty( $f['credit'] ) ) {
			$x .= '<photoshop:Credit>' . self::xml( $f['credit'] ) . '</photoshop:Credit>';
		}
		if ( ! empty( $f['usage_terms'] ) ) {
			$x .= '<xmpRights:UsageTerms><rdf:Alt><rdf:li xml:lang="x-default">' . self::xml( $f['usage_terms'] ) . '</rdf:li></rdf:Alt></xmpRights:UsageTerms>';
		}
		$x .= '</rdf:Description></rdf:RDF></x:xmpmeta>' . str_repeat( ' ', 200 ) . '<?xpacket end="w"?>';
		return $x;
	}

	/**
	 * Remove post-production traces from an XMP packet: Camera Raw settings,
	 * document history and ancestors, creator tool, dynamic media. The
	 * provenance fields stay untouched.
	 *
	 * @param string $xmp Packet.
	 * @return string Cleaned packet, or the input when it cannot be parsed.
	 */
	public static function clean_xmp( $xmp ) {
		$drop_ns = array(
			'http://ns.adobe.com/camera-raw-settings/1.0/',
			'http://ns.adobe.com/xap/1.0/mm/',
			'http://ns.adobe.com/xmp/1.0/DynamicMedia/',
			'http://ns.adobe.com/camera-raw-saved-settings/1.0/',
		);
		$drop_local = array(
			'http://ns.adobe.com/photoshop/1.0/' => array( 'DocumentAncestors' ),
			'http://ns.adobe.com/xap/1.0/'       => array( 'CreatorTool', 'MetadataDate' ),
		);
		$prev = libxml_use_internal_errors( true );
		$doc  = new DOMDocument();
		$ok   = $doc->loadXML( $xmp, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok ) {
			return $xmp;
		}
		$xp    = new DOMXPath( $doc );
		$nodes = array();
		foreach ( $xp->query( '//*' ) as $el ) {
			$ns = $el->namespaceURI;
			if ( in_array( $ns, $drop_ns, true ) || ( isset( $drop_local[ $ns ] ) && in_array( $el->localName, $drop_local[ $ns ], true ) ) ) {
				$nodes[] = $el;
			}
		}
		foreach ( $nodes as $el ) {
			if ( $el->parentNode ) {
				$el->parentNode->removeChild( $el );
			}
		}
		foreach ( $xp->query( '//*/@*' ) as $attr ) {
			$ns = $attr->namespaceURI;
			if ( in_array( $ns, $drop_ns, true ) || ( isset( $drop_local[ $ns ] ) && in_array( $attr->localName, $drop_local[ $ns ], true ) ) ) {
				$attr->ownerElement->removeAttributeNode( $attr );
			}
		}
		$out = $doc->saveXML( $doc->documentElement );
		return '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>' . $out . str_repeat( ' ', 200 ) . '<?xpacket end="w"?>';
	}

	/**
	 * XML escape.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function xml( $s ) {
		return htmlspecialchars( (string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/* --------------------------------------------------------------- helpers */

	/**
	 * Empty bundle.
	 *
	 * @param string $format Format.
	 * @return array
	 */
	private static function empty_bundle( $format ) {
		return array(
			'format'       => $format,
			'xmp'          => '',
			'xmp_extended' => array(),
			'iptc'         => '',
			'c2pa'         => false,
			'c2pa_raw'     => '',
		);
	}

	/**
	 * Read the file head: enough to cover every segment before image data (up to 4 MB).
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private static function read_head( $path ) {
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return '';
		}
		$data = (string) fread( $fh, 4 * 1024 * 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $data;
	}
}
