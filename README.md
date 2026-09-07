# AI Act Image Disclosure

WordPress plugin for the EU AI Act, article 50: a visible **AI badge** or label on AI-generated images, the **provenance metadata** (IPTC `DigitalSourceType`, XMP, IPTC, C2PA detection) carried into every image size WordPress generates, and the whole audit trail in the **Media Library**.

Made by [The Rope](https://www.therope.it) (The Chain S.r.l.), out of its own AI image productions for eyewear brands. GPL-2.0-or-later.

## Why

Since 2 August 2026 AI-generated and AI-manipulated images must be marked in a machine-readable way, and deepfakes (including photorealistic portraits of people who do not exist, per the Commission guidelines of 20 July 2026) must be disclosed visibly to the public.

WordPress leaves the uploaded file untouched, but every generated size (thumbnail, medium, large and the `-scaled` copy served as "full" above 2560 px) goes through GD or Imagick and loses XMP and IPTC. Measured on WordPress 7.1 with GD: the original keeps `DigitalSourceType`, disclosure text and C2PA manifest; the seven generated files carry nothing. Those are the files the pages show.

## What it does

- **Visible disclosure**: round semi-transparent "AI" badge in a chosen corner (text, diameter, colors, opacity, minimum width, tooltip, custom CSS), optional text label under the image, `data-ai-generated` and `data-digital-source-type` attributes on image tags.
- **Machine-readable marking**: reads the provenance of the original (IPTC digital source type, description, creator, credit, rights, C2PA presence and signers) and copies the XMP and IPTC segments into every generated size without re-encoding. JPEG (APP1 + APP13), PNG (`iTXt`), WebP (`XMP` chunk, VP8X header created when missing).
- **Control**: which images (AI only, or every image with metadata), what to carry (full blocks or a minimal provenance packet), which sizes, and whether to clean post-production traces from the original while keeping the marking (never on files with a C2PA manifest).
- **Media Library**: column and badge in list view, badge on grid tiles, read-only provenance panel in the attachment details, filter by provenance (list and grid), re-scan per file.
- **Library scan** in batches (settings screen) and WP-CLI (`wp ai-provenance scan`).
- **REST**: `_aipk_provenance` and `_aipk_ai` on the attachment endpoint.

It does not detect AI from pixels, does not copy C2PA manifests into derivatives (their hard binding covers the original bytes only), and does not write AVIF yet.

## Install

Clone or download into `wp-content/plugins/ai-act-image-disclosure`, activate, then Media → AI Act Disclosure. Requires WordPress 6.0 and PHP 7.4.

## Developers

```php
$record = aipk_get_provenance( $attachment_id ); // digital_source_type, ai, description, creator, credit, rights, generators, has_c2pa, ...
if ( aipk_is_ai_generated( $attachment_id ) ) { /* ... */ }

add_filter( 'aipk_should_preserve', fn( $want, $id, $record ) => $want, 10, 3 );
add_filter( 'aipk_decorate_image', fn( $show, $record, $html ) => $show, 10, 3 );
add_filter( 'aipk_badge_text', fn( $text, $record ) => $text, 10, 2 );
add_filter( 'aipk_label_text', fn( $text, $record ) => $text, 10, 2 );
add_action( 'aipk_after_inject', fn( $id, $result ) => null, 10, 2 );
```

CSS hooks: `.aipk-wrap`, `.aipk-ai-badge`, `.aipk-pos-{bottom-right|bottom-left|top-right|top-left}`, `.aipk-label`, `img[data-ai-generated]`.

## Development

```bash
for f in $(git ls-files '*.php'); do php -l "$f"; done
```

The GitHub workflow lints every PHP file on PHP 7.4 to 8.4 and runs the WordPress Plugin Check. Translations: `languages/ai-act-image-disclosure.pot`, Italian included.

## Companion tool

The marking itself is written before upload. The Rope uses `tagga-ai`, a macOS script built on exiftool and c2patool that writes `DigitalSourceType`, the disclosure text, attribution and a C2PA manifest chained to the generator's one. Any DAM or exiftool command that writes `Iptc4xmpExt:DigitalSourceType` works.

## License

GPL-2.0-or-later. Copyright (C) 2026 The Chain S.r.l.
