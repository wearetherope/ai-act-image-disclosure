=== AI Act Image Marking ===
Contributors: therope
Tags: ai act, ai generated images, ai label, provenance, compliance
Requires at least: 6.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Mark, keep and disclose AI-generated images: IPTC marking written where missing and kept in every size, AI badge with provenance popup, Media Library audit. EU AI Act art. 50.

== Description ==

**Since 2 August 2026 the EU AI Act (article 50) requires AI-generated and AI-manipulated images to be marked in a machine-readable way and, when they show people or scenes that look real, to be disclosed visibly to the public.** If you publish virtual models, AI product shots, campaign visuals or any image made with Midjourney, Nano Banana, DALL·E, Firefly, Stable Diffusion or similar tools, this concerns your WordPress site.

AI Act Image Marking does four things no other plugin does together: it **marks** files that lack the marking, **keeps** the marking in every size WordPress generates, **discloses** it on the page and **documents** it in the Media Library.

= 1. The visible disclosure =

* **AI badge**: a round, semi-transparent "AI" mark overlaid on AI-generated images, in the corner you choose. Text or your own icon, diameter, colors, opacity, minimum image width, tooltip; a custom CSS box restyles it completely.
* **Provenance popup**: a click on the badge opens a small panel with type, disclosure text, creator, credit, generator and marking of that image. It is plain HTML kept hidden in the page, readable by assistive technology and search engines.
* **Per image**: not every AI image needs the badge. Each attachment can follow the settings, always show it or never show it, one by one or in bulk.
* **Text label** under AI images, **site notice** at the end of every page, and a `[ai_act_disclosure]` shortcode plus an "AI disclosure notice" block for your transparency page, with the count of AI images.
* **Data attributes** (`data-ai-generated`, `data-digital-source-type`) and **schema.org ImageObject** with `digitalSourceType` for search engines and AI crawlers.

= 2. The machine-readable marking, written and kept =

WordPress never touches the file you upload, but every size it generates (thumbnails, medium, large and the scaled copy it serves as "full") comes out of GD or Imagick **without XMP and IPTC**. The marking your generator or your DAM wrote into the original is gone from the images your pages actually show.

* The plugin **reads** the provenance of each upload: the IPTC `DigitalSourceType` term (`trainedAlgorithmicMedia`, `compositeWithTrainedAlgorithmicMedia`, and the rest of the vocabulary), the disclosure text, creator, credit and rights, and whether a **C2PA / Content Credentials** manifest is present, with the names of the tools that signed it (Google, Adobe Lightroom, Photoshop, and so on).
* It **carries the marking into every generated size**, byte for byte, without re-encoding pixels: JPEG, PNG and WebP.
* You decide **what to carry** (the whole XMP and IPTC blocks, or only the provenance fields), **into which sizes**, and whether the **original** is left untouched or cleaned of post-production traces (Camera Raw settings, document history) while keeping the marking.
* **Manual classification** for files that carry no marking (most retouched images lose it): "AI generated", "AI modified" or "not AI", per image or in bulk. An AI classification **writes the IPTC digital source type into the sizes and, unless the original carries a C2PA manifest, into the original**. A manual choice becomes a machine-readable marking that travels with the file.
* **Generator traces**: files without a formal marking but with signs of Midjourney, DALL·E, Firefly, Stable Diffusion, ComfyUI, Google, OpenAI and others (software fields, PNG parameters, XMP) are listed as "Suspected AI, to confirm", never marked automatically.

= 3. The audit trail in the Media Library =

* A column and a badge in the list view, a badge on grid tiles.
* A read-only **provenance panel** in the attachment details: digital source type, disclosure text, creator, credit, rights, C2PA signers, and which generated sizes carry the marking.
* A **filter**: AI generated or modified, suspected AI, with provenance marking, without.
* **Bulk actions** to classify and to show or hide the badge, a **library scan** for everything uploaded before the plugin, a **CSV export** of the AI images for your audit file, and WP-CLI.
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
* Filters: `aipk_should_preserve` (per attachment), `aipk_decorate_image` (badge and label per image), `aipk_badge_text`, `aipk_label_text`, `aipk_popup_rows`.
* Constant `AIPK_CREDIT_DEFAULT` (wp-config.php) to start with the credit line in the popup enabled.
* Action: `aipk_after_inject` with the per-size outcome.
* WP-CLI: `wp ai-provenance scan [--all] [--dry-run]`, `wp ai-provenance status <id>`.
* CSS hooks: `.aipk-wrap`, `.aipk-ai-badge`, `.aipk-pos-bottom-right` (and the other corners), `.aipk-popup`, `.aipk-label`, `.aipk-site-notice`, `.aipk-disclosure`, `img[data-ai-generated]`.

AI Act Image Marking is made by [The Rope](https://therope.it), a digital agency in Milan, out of its own AI image productions for eyewear brands.

== Installation ==

1. Install from the Plugins screen or upload the folder to `/wp-content/plugins/`.
2. Activate.
3. Go to **Media → AI Act Marking**, turn on the badge if you want it, and run **Scan new images** to process what was uploaded before activation.

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

Yes: text or your own icon, corner, diameter, background color, opacity, text color, minimum image width, tooltip, plus a custom CSS box. Or turn the badge off and use the `data-ai-generated` attribute in your theme.

= Can I keep the badge off some AI images? =

Yes. Every attachment has a "visible badge" choice: follow the settings, always show, never show. Bulk actions set it on many images at once.

= What if a file has no marking at all? =

Classify it by hand in the attachment details or with a bulk action. The plugin writes the IPTC digital source type into the generated sizes and, unless the original carries a C2PA manifest, into the original too. Files with generator traces are listed as "Suspected AI, to confirm" so you find them first.

= Does it work with WebP and PNG sizes? =

Yes. XMP is written as an `iTXt` chunk in PNG and as an `XMP` chunk in WebP (creating the VP8X header when the encoder did not). IPTC IIM exists only in JPEG.

= Does it slow down uploads? =

Marginally: a few milliseconds per generated size, spent copying bytes. Nothing is re-encoded.

== Screenshots ==

1. Media Library list view with the AI marking column, filter and bulk actions.
2. The marking panel in the attachment details: provenance, classification, per-image badge.
3. Badges on grid tiles.
4. The AI badge and the provenance popup on the front end.
5. Settings: original file, generated sizes, badge, popup, label, notice, custom CSS.

== Changelog ==

= 1.1.0 =
* Manual classification (AI generated, AI modified, not AI) per image and in bulk, writing the IPTC digital source type into sizes and original.
* Generator traces: "Suspected AI, to confirm" for files without formal marking.
* Provenance popup on the badge, badge image, per-image badge choice, site notice, shortcode and block, schema.org digitalSourceType, CSV export.

= 1.0.0 =
* First release: provenance reading (XMP, IPTC, C2PA detection), marking carried into JPEG, PNG and WebP sizes with configurable scope, mode and sizes, optional cleaning of the original, Media Library column, panel, badges and filter, front end badge, label and data attributes, custom CSS, library scan, WP-CLI, REST exposure.

== Upgrade Notice ==

= 1.1.0 =
Manual classification, provenance popup, per-image badge choice, schema.org and CSV export. Settings are kept.

= 1.0.0 =
First release.
