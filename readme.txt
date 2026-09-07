=== AI Act Image Disclosure: AI badge, label and provenance metadata for AI-generated images ===
Contributors: therope
Tags: ai act, ai generated images, ai label, provenance, compliance
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Label AI-generated images with a badge, keep their provenance metadata in every size and see it in the Media Library. EU AI Act article 50, done.

== Description ==

**Since 2 August 2026 the EU AI Act (article 50) requires AI-generated and AI-manipulated images to be marked in a machine-readable way and, when they show people or scenes that look real, to be disclosed visibly to the public.** If you publish virtual models, AI product shots, campaign visuals or any image made with Midjourney, Nano Banana, DALL·E, Firefly, Stable Diffusion or similar tools, this concerns your WordPress site.

AI Act Image Disclosure gives you both halves of the obligation in one plugin.

= 1. The visible disclosure =

* **AI badge**: a round, semi-transparent "AI" mark overlaid on every AI-generated image, in the corner you choose (bottom right by default). Text, diameter, colors, opacity and minimum image width are settings; a custom CSS box lets you restyle it completely.
* **Text label**: an optional short line under AI images ("Image generated with artificial intelligence"), editable.
* **Data attributes**: `data-ai-generated="true"` and `data-digital-source-type` on every AI image tag, so your theme, your product gallery or your JavaScript can add its own disclosure.

= 2. The machine-readable marking =

WordPress never touches the file you upload, but every size it generates (thumbnails, medium, large and the scaled copy it serves as "full") comes out of GD or Imagick **without XMP and IPTC**. The marking your generator or your DAM wrote into the original is gone from the images your pages actually show.

* The plugin **reads** the provenance of each upload: the IPTC `DigitalSourceType` term (`trainedAlgorithmicMedia`, `compositeWithTrainedAlgorithmicMedia`, and the rest of the vocabulary), the disclosure text, creator, credit and rights, and whether a **C2PA / Content Credentials** manifest is present, with the names of the tools that signed it (Google, Adobe Lightroom, Photoshop, and so on).
* It **carries the marking into every generated size**, byte for byte, without re-encoding pixels: JPEG, PNG and WebP.
* You decide **what to carry** (the whole XMP and IPTC blocks, or only the provenance fields), **into which sizes**, and whether the **original** is left untouched or cleaned of post-production traces (Camera Raw settings, document history) while keeping the marking.

= 3. The audit trail in the Media Library =

* A column and a badge in the list view, a badge on grid tiles.
* A read-only **provenance panel** in the attachment details: digital source type, disclosure text, creator, credit, rights, C2PA signers, and which generated sizes carry the marking.
* A **filter**: AI-generated images, images with provenance marking, images without.
* A **library scan** for everything uploaded before the plugin, in small batches, or with WP-CLI.
* The record is exposed on the **REST API** attachment endpoint for headless themes.

= What the plugin does not do =

* It does not guess whether an image is AI-made by looking at pixels. It reads what the file declares in the standard vocabularies (IPTC Photo Metadata, C2PA). Files with no marking are shown as "No marking": write the marking in production, before upload (most generators do it; exiftool or any DAM can add `DigitalSourceType`).
* It does not copy C2PA manifests into resized copies. A C2PA signature covers the exact bytes of the original file, so a copy inside a derivative would validate as tampered. The original keeps its manifest; the plugin records the fact and the signers.
* It does not write AVIF yet. If your site converts sizes to AVIF, those sizes stay unmarked and the settings screen tells you.
* It is not legal advice. The AI Act distinguishes the machine-readable marking of the file (article 50(2)) from the visible disclosure to the person looking at the image (article 50(4)). The plugin gives you the tools for both; how you word the disclosure is your call, with your legal team.

= Who it is for =

Brands, e-commerce teams and agencies that publish AI-generated visuals (virtual models, product shots, campaign images, AI-retouched photos) and need the marking to survive publication, with proof in the Media Library. Works with WooCommerce galleries, the block editor, classic themes and headless setups.

= Standards =

* IPTC Photo Metadata Standard: `Iptc4xmpExt:DigitalSourceType`.
* IPTC IIM (Caption, By-line, Credit, Copyright) in the Photoshop APP13 segment.
* C2PA (Coalition for Content Provenance and Authenticity) manifests: detected and reported, never altered.
* XMP in PNG (`iTXt XML:com.adobe.xmp`) and WebP (`XMP` chunk with the VP8X flag).

= For developers =

* `aipk_get_provenance( $attachment_id )` returns the record; `aipk_is_ai_generated( $attachment_id )` returns a boolean.
* Filters: `aipk_should_preserve` (per attachment), `aipk_decorate_image` (badge and label per image), `aipk_badge_text`, `aipk_label_text`.
* Action: `aipk_after_inject` with the per-size outcome.
* WP-CLI: `wp ai-provenance scan [--all] [--dry-run]`, `wp ai-provenance status <id>`.
* CSS hooks: `.aipk-wrap`, `.aipk-ai-badge`, `.aipk-pos-bottom-right` (and the other corners), `.aipk-label`, `img[data-ai-generated]`.

AI Act Image Disclosure is made by [The Rope](https://therope.it), a digital agency in Milan, out of its own AI image productions for eyewear brands.

== Installation ==

1. Install from the Plugins screen or upload the folder to `/wp-content/plugins/`.
2. Activate.
3. Go to **Media → AI Act Disclosure**, turn on the badge if you want it, and run **Scan new images** to process what was uploaded before activation.

Every new upload is processed automatically.

== Frequently Asked Questions ==

= Does the plugin detect AI images? =

No. It reads the declaration inside the file (IPTC `DigitalSourceType`, C2PA manifest). Images without a declaration are listed as "No marking". Mark files in production, before upload: most generators embed C2PA, and exiftool or any DAM can write `DigitalSourceType`.

= Is the badge enough for the AI Act? =

The plugin cannot give legal advice. Article 50 asks for a visible, clearly distinguishable disclosure at first exposure for deepfakes, which the Commission guidelines of July 2026 read as including photorealistic portraits of people who do not exist. A badge on the image or a label next to it are the two forms seen on European e-commerce sites today. Metadata alone is not considered enough for the visible part.

= Why is the C2PA manifest not in the thumbnails? =

Because it would be a lie. A C2PA signature binds the exact bytes of one file. Thumbnails are new files; the honest thing is to carry the XMP and IPTC declaration, keep the manifest on the original, and record the signing tools in the library.

= My image optimizer strips metadata. =

Smush, EWWW, ShortPixel, Imagify, TinyPNG, Optimole and LiteSpeed all have a "strip metadata" option. Turn it off for the marking to survive, then re-scan the library. The settings screen lists the optimizers it detects.

= Can I style the badge? =

Yes: text, corner, diameter, background color, opacity, text color, minimum image width, tooltip, plus a custom CSS box. Or turn the badge off and use the `data-ai-generated` attribute in your theme.

= Does it work with WebP and PNG sizes? =

Yes. XMP is written as an `iTXt` chunk in PNG and as an `XMP` chunk in WebP (creating the VP8X header when the encoder did not). IPTC IIM exists only in JPEG.

= Does it slow down uploads? =

Marginally: a few milliseconds per generated size, spent copying bytes. Nothing is re-encoded.

== Screenshots ==

1. Media Library list view with the AI provenance column and the filter.
2. The provenance panel in the attachment details.
3. Badges on grid tiles.
4. The AI badge on the front end.
5. Settings: original file, generated sizes, badge, label, custom CSS.

== Changelog ==

= 1.0.0 =
* First release: provenance reading (XMP, IPTC, C2PA detection), marking carried into JPEG, PNG and WebP sizes with configurable scope, mode and sizes, optional cleaning of the original, Media Library column, panel, badges and filter, front end badge, label and data attributes, custom CSS, library scan, WP-CLI, REST exposure.

== Upgrade Notice ==

= 1.0.0 =
First release.
