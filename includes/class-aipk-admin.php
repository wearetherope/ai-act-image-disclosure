<?php
/**
 * Media Library integration and settings screen.
 *
 * @package AI_Act_Image_Disclosure
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
class AIPK_Admin {

	const PAGE = 'ai-act-image-disclosure';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'manage_media_columns', array( __CLASS__, 'column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'list_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_list_filter' ) );
		add_filter( 'ajax_query_attachments_args', array( __CLASS__, 'apply_grid_filter' ) );
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'attachment_fields' ), 10, 2 );
		add_filter( 'wp_prepare_attachment_for_js', array( __CLASS__, 'prepare_for_js' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'wp_ajax_aipk_rescan', array( __CLASS__, 'ajax_rescan' ) );
		add_action( 'wp_ajax_aipk_scan', array( __CLASS__, 'ajax_scan' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AIPK_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/* ------------------------------------------------------------ list view */

	/**
	 * Add the column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'author' === $key ) {
				$out['aipk'] = __( 'AI provenance', 'ai-act-image-disclosure' );
			}
		}
		if ( ! isset( $out['aipk'] ) ) {
			$out['aipk'] = __( 'AI provenance', 'ai-act-image-disclosure' );
		}
		return $out;
	}

	/**
	 * Column content.
	 *
	 * @param string $column_name Column.
	 * @param int    $post_id     Attachment id.
	 */
	public static function column_content( $column_name, $post_id ) {
		if ( 'aipk' !== $column_name ) {
			return;
		}
		if ( ! wp_attachment_is_image( $post_id ) ) {
			echo '<span aria-hidden="true">&mdash;</span>';
			return;
		}
		$record = AIPK_Processor::record( $post_id );
		if ( ! $record ) {
			echo '<span class="aipk-muted">' . esc_html__( 'Not scanned', 'ai-act-image-disclosure' ) . '</span>';
			return;
		}
		echo wp_kses_post( self::badge( $record ) );
		if ( ! empty( $record['generators'] ) ) {
			echo '<div class="aipk-muted">' . esc_html( implode( ', ', $record['generators'] ) ) . '</div>';
		}
	}

	/**
	 * Badge HTML for a record.
	 *
	 * @param array $record Record.
	 * @return string
	 */
	public static function badge( $record ) {
		if ( ! empty( $record['ai'] ) ) {
			$dst = $record['digital_source_type'] ? $record['digital_source_type'] : $record['c2pa_digital_source_type'];
			return '<span class="aipk-badge aipk-badge-ai" title="' . esc_attr( $dst ) . '">' . esc_html( AIPK_Reader::term_label( $dst ) ) . '</span>';
		}
		if ( ! empty( $record['digital_source_type'] ) ) {
			return '<span class="aipk-badge aipk-badge-marked" title="' . esc_attr( $record['digital_source_type'] ) . '">' . esc_html( AIPK_Reader::term_label( $record['digital_source_type'] ) ) . '</span>';
		}
		return '<span class="aipk-badge aipk-badge-none">' . esc_html__( 'No marking', 'ai-act-image-disclosure' ) . '</span>';
	}

	/**
	 * Filter dropdown in the list view.
	 *
	 * @param string $post_type Post type.
	 */
	public static function list_filter( $post_type ) {
		if ( 'attachment' !== $post_type ) {
			return;
		}
		$current = isset( $_GET['aipk_filter'] ) ? sanitize_key( wp_unslash( $_GET['aipk_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<label class="screen-reader-text" for="aipk_filter">' . esc_html__( 'Filter by AI provenance', 'ai-act-image-disclosure' ) . '</label>';
		echo '<select name="aipk_filter" id="aipk_filter">';
		foreach ( self::filter_options() as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * Filter options.
	 *
	 * @return array
	 */
	public static function filter_options() {
		return array(
			''         => __( 'All provenance', 'ai-act-image-disclosure' ),
			'ai'       => __( 'AI generated', 'ai-act-image-disclosure' ),
			'marked'   => __( 'With provenance marking', 'ai-act-image-disclosure' ),
			'unmarked' => __( 'Without marking', 'ai-act-image-disclosure' ),
		);
	}

	/**
	 * Meta query for a filter value.
	 *
	 * @param string $value Filter.
	 * @return array|null
	 */
	private static function filter_meta_query( $value ) {
		switch ( $value ) {
			case 'ai':
				return array(
					array(
						'key'   => AIPK_Processor::META_AI,
						'value' => '1',
					),
				);
			case 'marked':
				return array(
					array(
						'key'     => AIPK_Processor::META_KEY,
						'value'   => '"digital_source_type";s:',
						'compare' => 'LIKE',
					),
					array(
						'key'     => AIPK_Processor::META_KEY,
						'value'   => '"digital_source_type";s:0:""',
						'compare' => 'NOT LIKE',
					),
				);
			case 'unmarked':
				return array(
					'relation' => 'OR',
					array(
						'key'     => AIPK_Processor::META_KEY,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => AIPK_Processor::META_KEY,
						'value'   => '"digital_source_type";s:0:""',
						'compare' => 'LIKE',
					),
				);
		}
		return null;
	}

	/**
	 * Apply the list filter.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function apply_list_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'attachment' !== $query->get( 'post_type' ) ) {
			return;
		}
		$value = isset( $_GET['aipk_filter'] ) ? sanitize_key( wp_unslash( $_GET['aipk_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$mq    = self::filter_meta_query( $value );
		if ( $mq ) {
			$query->set( 'meta_query', $mq ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
	}

	/**
	 * Apply the grid filter (core strips unknown keys, so read the raw request).
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public static function apply_grid_filter( $args ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only filtering of a core AJAX query, capability checked by core.
		$value = isset( $_REQUEST['query']['aipk_filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['query']['aipk_filter'] ) ) : '';
		$mq    = self::filter_meta_query( $value );
		if ( $mq ) {
			$args['meta_query'] = $mq; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		return $args;
	}

	/* ------------------------------------------------------ attachment view */

	/**
	 * Read-only provenance panel in the attachment details.
	 *
	 * @param array   $form_fields Fields.
	 * @param WP_Post $post        Attachment.
	 * @return array
	 */
	public static function attachment_fields( $form_fields, $post ) {
		if ( ! wp_attachment_is_image( $post->ID ) ) {
			return $form_fields;
		}
		$form_fields['aipk'] = array(
			'label' => __( 'AI provenance', 'ai-act-image-disclosure' ),
			'input' => 'html',
			'html'  => self::panel( $post->ID ),
		);
		return $form_fields;
	}

	/**
	 * Panel HTML.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return string
	 */
	public static function panel( $attachment_id ) {
		$record = AIPK_Processor::record( $attachment_id );
		$sizes  = get_post_meta( $attachment_id, AIPK_Processor::META_SIZES, true );
		$html   = '<div class="aipk-panel" data-id="' . (int) $attachment_id . '">';
		if ( ! $record ) {
			$html .= '<p class="aipk-muted">' . esc_html__( 'Not scanned yet.', 'ai-act-image-disclosure' ) . '</p>';
		} else {
			$html .= '<p>' . self::badge( $record ) . '</p><dl class="aipk-dl">';
			$rows = array(
				__( 'Digital source type', 'ai-act-image-disclosure' ) => $record['digital_source_type'] ? $record['digital_source_type'] : __( 'absent', 'ai-act-image-disclosure' ),
				__( 'Disclosure text', 'ai-act-image-disclosure' )     => $record['description'],
				__( 'Creator', 'ai-act-image-disclosure' )             => $record['creator'],
				__( 'Credit', 'ai-act-image-disclosure' )              => $record['credit'],
				__( 'Rights', 'ai-act-image-disclosure' )              => $record['rights'],
				__( 'C2PA manifest', 'ai-act-image-disclosure' )       => $record['has_c2pa']
					? ( $record['generators'] ? implode( ', ', $record['generators'] ) : __( 'present', 'ai-act-image-disclosure' ) )
					: __( 'absent', 'ai-act-image-disclosure' ),
				__( 'Blocks in the original', 'ai-act-image-disclosure' ) => implode(
					', ',
					array_filter(
						array(
							$record['has_xmp'] ? 'XMP' : '',
							$record['has_iptc'] ? 'IPTC' : '',
							$record['has_c2pa'] ? 'C2PA' : '',
						)
					)
				),
			);
			foreach ( $rows as $label => $value ) {
				if ( '' === (string) $value ) {
					continue;
				}
				$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
			}
			if ( is_array( $sizes ) ) {
				$parts = array();
				foreach ( $sizes as $size => $state ) {
					if ( '_file' === $size ) {
						continue;
					}
					$parts[] = esc_html( $size ) . ': ' . esc_html( self::state_label( $state ) );
				}
				if ( $parts ) {
					$html .= '<dt>' . esc_html__( 'Generated sizes', 'ai-act-image-disclosure' ) . '</dt><dd>' . implode( '<br>', $parts ) . '</dd>';
				} elseif ( $record['ai'] ) {
					$html .= '<dt>' . esc_html__( 'Generated sizes', 'ai-act-image-disclosure' ) . '</dt><dd>' . esc_html__( 'nothing to carry (no XMP or IPTC in the original)', 'ai-act-image-disclosure' ) . '</dd>';
				}
			}
			if ( ! empty( $record['scanned_at'] ) ) {
				$html .= '<dt>' . esc_html__( 'Scanned', 'ai-act-image-disclosure' ) . '</dt><dd>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $record['scanned_at'] ) ) . '</dd>';
			}
			$html .= '</dl>';
		}
		if ( current_user_can( 'edit_post', $attachment_id ) ) {
			$html .= '<p><button type="button" class="button button-small aipk-rescan" data-nonce="' . esc_attr( wp_create_nonce( 'aipk_rescan_' . $attachment_id ) ) . '">' . esc_html__( 'Re-scan file', 'ai-act-image-disclosure' ) . '</button></p>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Human label for a per-size state.
	 *
	 * @param string $state State.
	 * @return string
	 */
	private static function state_label( $state ) {
		switch ( $state ) {
			case 'kept':
				return __( 'marked', 'ai-act-image-disclosure' );
			case 'injected':
				return __( 'marking carried over', 'ai-act-image-disclosure' );
			case 'missing':
				return __( 'file missing', 'ai-act-image-disclosure' );
			case 'unsupported':
				return __( 'format not supported', 'ai-act-image-disclosure' );
			case 'skipped':
				return __( 'skipped by settings', 'ai-act-image-disclosure' );
		}
		return $state;
	}

	/**
	 * Expose a compact record to the media grid.
	 *
	 * @param array   $response   JS response.
	 * @param WP_Post $attachment Attachment.
	 * @return array
	 */
	public static function prepare_for_js( $response, $attachment ) {
		$record = AIPK_Processor::record( $attachment->ID );
		if ( $record && ( $record['ai'] || $record['digital_source_type'] ) ) {
			$dst               = $record['digital_source_type'] ? $record['digital_source_type'] : $record['c2pa_digital_source_type'];
			$response['aipk'] = array(
				'ai'    => (bool) $record['ai'],
				'badge' => $record['ai'] ? __( 'AI', 'ai-act-image-disclosure' ) : __( 'Marked', 'ai-act-image-disclosure' ),
				'title' => AIPK_Reader::term_label( $dst ),
			);
		}
		return $response;
	}

	/**
	 * Admin assets.
	 *
	 * @param string $hook Screen hook.
	 */
	public static function assets( $hook ) {
		wp_register_style( 'aipk-admin', AIPK_URL . 'assets/admin.css', array(), AIPK_VERSION );
		wp_register_script( 'aipk-admin', AIPK_URL . 'assets/admin.js', array( 'jquery' ), AIPK_VERSION, true );
		wp_localize_script(
			'aipk-admin',
			'AIPK',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'filters' => self::filter_options(),
				'i18n'    => array(
					'scanning'  => __( 'Scanning…', 'ai-act-image-disclosure' ),
					'done'      => __( 'Scan complete.', 'ai-act-image-disclosure' ),
					'error'     => __( 'Something went wrong.', 'ai-act-image-disclosure' ),
					/* translators: 1: processed count, 2: total, 3: AI count */
					'progress'  => __( '%1$d of %2$d scanned, %3$d marked as AI', 'ai-act-image-disclosure' ),
				),
				'scanNonce' => wp_create_nonce( 'aipk_scan' ),
			)
		);
		// The media modal can open on any screen: load everywhere the media scripts are enqueued.
		wp_enqueue_style( 'aipk-admin' );
		wp_enqueue_script( 'aipk-admin' );
	}

	/* ------------------------------------------------------------- settings */

	/**
	 * Menu entry under Media.
	 */
	public static function menu() {
		add_media_page(
			__( 'AI Act Disclosure', 'ai-act-image-disclosure' ),
			__( 'AI Act Disclosure', 'ai-act-image-disclosure' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'settings_page' )
		);
	}

	/**
	 * Plugin row link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'upload.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'ai-act-image-disclosure' ) . '</a>' );
		return $links;
	}

	/**
	 * Settings page.
	 */
	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o     = AIPK_Options::all();
		$env   = self::environment();
		$stats = self::stats();
		?>
		<div class="wrap aipk-settings">
			<h1><?php esc_html_e( 'AI Act Image Disclosure', 'ai-act-image-disclosure' ); ?></h1>
			<p class="description"><?php esc_html_e( 'WordPress keeps the uploaded original untouched but strips XMP and IPTC from every generated size, including the scaled copy it serves as "full". This plugin reads the provenance of the original, carries the marking into each size and shows it in the Media Library.', 'ai-act-image-disclosure' ); ?></p>

			<h2><?php esc_html_e( 'Library', 'ai-act-image-disclosure' ); ?></h2>
			<table class="widefat striped aipk-table"><tbody>
				<tr><td><?php esc_html_e( 'Images in the library', 'ai-act-image-disclosure' ); ?></td><td><?php echo (int) $stats['images']; ?></td></tr>
				<tr><td><?php esc_html_e( 'Scanned', 'ai-act-image-disclosure' ); ?></td><td><?php echo (int) $stats['scanned']; ?></td></tr>
				<tr><td><?php esc_html_e( 'Marked as AI', 'ai-act-image-disclosure' ); ?></td><td><?php echo (int) $stats['ai']; ?> <a href="<?php echo esc_url( admin_url( 'upload.php?mode=list&aipk_filter=ai' ) ); ?>"><?php esc_html_e( 'show', 'ai-act-image-disclosure' ); ?></a></td></tr>
			</tbody></table>
			<p>
				<button type="button" class="button button-secondary" id="aipk-scan" data-all="0"><?php esc_html_e( 'Scan new images', 'ai-act-image-disclosure' ); ?></button>
				<button type="button" class="button" id="aipk-scan-all" data-all="1"><?php esc_html_e( 'Re-scan the whole library', 'ai-act-image-disclosure' ); ?></button>
				<span id="aipk-scan-status" class="aipk-muted" aria-live="polite"></span>
			</p>
			<p class="description"><?php esc_html_e( 'Scanning reads each original and carries the marking into sizes that lack it. It runs in small batches and can be repeated safely.', 'ai-act-image-disclosure' ); ?></p>

			<h2><?php esc_html_e( 'Environment', 'ai-act-image-disclosure' ); ?></h2>
			<table class="widefat striped aipk-table"><tbody>
				<?php foreach ( $env['rows'] as $row ) : ?>
					<tr><td><?php echo esc_html( $row[0] ); ?></td><td><?php echo esc_html( $row[1] ); ?></td></tr>
				<?php endforeach; ?>
			</tbody></table>
			<?php foreach ( $env['warnings'] as $w ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( $w ); ?></p></div>
			<?php endforeach; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'aipk' ); ?>
				<h2><?php esc_html_e( 'Original file', 'ai-act-image-disclosure' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Uploaded original', 'ai-act-image-disclosure' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="aipk_settings[original_mode]" value="keep" <?php checked( $o['original_mode'], 'keep' ); ?>> <?php esc_html_e( 'Leave it exactly as uploaded (recommended)', 'ai-act-image-disclosure' ); ?></label><br>
								<label><input type="radio" name="aipk_settings[original_mode]" value="clean" <?php checked( $o['original_mode'], 'clean' ); ?>> <?php esc_html_e( 'Remove post-production traces, keep the marking', 'ai-act-image-disclosure' ); ?></label>
								<p class="description"><?php esc_html_e( 'Cleaning drops Camera Raw settings, document history, ancestors and the creator tool from the XMP. Provenance, description, credits and rights stay. Files that carry a C2PA manifest are never touched, since any byte change would break its signature.', 'ai-act-image-disclosure' ); ?></p>
							</fieldset>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Generated sizes', 'ai-act-image-disclosure' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Which images', 'ai-act-image-disclosure' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="aipk_settings[scope]" value="ai" <?php checked( $o['scope'], 'ai' ); ?>> <?php esc_html_e( 'AI generated and AI composite images only (recommended)', 'ai-act-image-disclosure' ); ?></label><br>
								<label><input type="radio" name="aipk_settings[scope]" value="all" <?php checked( $o['scope'], 'all' ); ?>> <?php esc_html_e( 'Every image that carries XMP or IPTC metadata', 'ai-act-image-disclosure' ); ?></label>
								<p class="description"><?php esc_html_e( 'The second option also carries photographer credits and captions, and with them any personal data the metadata may contain.', 'ai-act-image-disclosure' ); ?></p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'What to carry', 'ai-act-image-disclosure' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="aipk_settings[derivative_mode]" value="full" <?php checked( $o['derivative_mode'], 'full' ); ?>> <?php esc_html_e( 'The whole XMP and IPTC blocks of the original (recommended)', 'ai-act-image-disclosure' ); ?></label><br>
								<label><input type="radio" name="aipk_settings[derivative_mode]" value="minimal" <?php checked( $o['derivative_mode'], 'minimal' ); ?>> <?php esc_html_e( 'Only the provenance fields: digital source type, description, creator, credit, rights, usage terms', 'ai-act-image-disclosure' ); ?></label><br>
								<label><input type="radio" name="aipk_settings[derivative_mode]" value="none" <?php checked( $o['derivative_mode'], 'none' ); ?>> <?php esc_html_e( 'Nothing: sizes stay as WordPress makes them', 'ai-act-image-disclosure' ); ?></label>
								<p class="description"><?php esc_html_e( 'The minimal packet is written from scratch and weighs about one kilobyte per file.', 'ai-act-image-disclosure' ); ?></p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sizes to mark', 'ai-act-image-disclosure' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( self::size_list() as $size => $label ) : ?>
									<label style="display:inline-block;min-width:220px"><input type="checkbox" name="aipk_settings[skip_sizes][]" value="<?php echo esc_attr( $size ); ?>" <?php checked( ! in_array( $size, $o['skip_sizes'], true ) ); ?> class="aipk-size-toggle"> <?php echo esc_html( $label ); ?></label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'Unchecked sizes are left unmarked. Sizes added by the theme after this page was rendered are marked by default.', 'ai-act-image-disclosure' ); ?></p>
							</fieldset>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Front end', 'ai-act-image-disclosure' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Data attributes', 'ai-act-image-disclosure' ); ?></th>
						<td><label><input type="checkbox" name="aipk_settings[frontend_attrs]" value="1" <?php checked( $o['frontend_attrs'] ); ?>> <?php esc_html_e( 'Add data-digital-source-type and data-ai-generated to image tags', 'ai-act-image-disclosure' ); ?></label>
						<p class="description"><?php esc_html_e( 'Invisible to visitors; lets the theme style or label AI images with CSS and JavaScript.', 'ai-act-image-disclosure' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'AI badge on the image', 'ai-act-image-disclosure' ); ?></th>
						<td>
							<label><input type="checkbox" name="aipk_settings[badge_enabled]" value="1" <?php checked( $o['badge_enabled'] ); ?>> <?php esc_html_e( 'Overlay a round, semi-transparent badge on AI generated images', 'ai-act-image-disclosure' ); ?></label>
							<div class="aipk-badge-options">
								<p>
									<label><?php esc_html_e( 'Text', 'ai-act-image-disclosure' ); ?> <input type="text" name="aipk_settings[badge_text]" value="<?php echo esc_attr( $o['badge_text'] ); ?>" size="6" maxlength="12"></label>
									<label><?php esc_html_e( 'Position', 'ai-act-image-disclosure' ); ?>
										<select name="aipk_settings[badge_position]">
											<?php foreach ( AIPK_Options::positions() as $value => $label ) : ?>
												<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $o['badge_position'], $value ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>
									<label><?php esc_html_e( 'Diameter (px)', 'ai-act-image-disclosure' ); ?> <input type="number" name="aipk_settings[badge_size]" value="<?php echo (int) $o['badge_size']; ?>" min="12" max="120" class="small-text"></label>
								</p>
								<p>
									<label><?php esc_html_e( 'Background', 'ai-act-image-disclosure' ); ?> <input type="color" name="aipk_settings[badge_bg]" value="<?php echo esc_attr( $o['badge_bg'] ); ?>"></label>
									<label><?php esc_html_e( 'Opacity (%)', 'ai-act-image-disclosure' ); ?> <input type="number" name="aipk_settings[badge_opacity]" value="<?php echo (int) $o['badge_opacity']; ?>" min="0" max="100" class="small-text"></label>
									<label><?php esc_html_e( 'Text color', 'ai-act-image-disclosure' ); ?> <input type="color" name="aipk_settings[badge_color]" value="<?php echo esc_attr( $o['badge_color'] ); ?>"></label>
									<label><?php esc_html_e( 'Hide on images narrower than (px)', 'ai-act-image-disclosure' ); ?> <input type="number" name="aipk_settings[badge_min_width]" value="<?php echo (int) $o['badge_min_width']; ?>" min="0" class="small-text"></label>
								</p>
								<p>
									<label><?php esc_html_e( 'Tooltip and screen reader text', 'ai-act-image-disclosure' ); ?><br><input type="text" class="regular-text" name="aipk_settings[badge_title]" value="<?php echo esc_attr( $o['badge_title'] ); ?>"></label>
								</p>
								<div class="aipk-preview" aria-hidden="true">
									<div class="aipk-preview-img"><span class="aipk-preview-badge"><?php echo esc_html( $o['badge_text'] ); ?></span></div>
									<p class="description"><?php esc_html_e( 'Preview', 'ai-act-image-disclosure' ); ?></p>
								</div>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Text label under the image', 'ai-act-image-disclosure' ); ?></th>
						<td><label><input type="checkbox" name="aipk_settings[frontend_label]" value="1" <?php checked( $o['frontend_label'] ); ?>> <?php esc_html_e( 'Print a short text under AI generated images', 'ai-act-image-disclosure' ); ?></label><br>
						<input type="text" class="regular-text" name="aipk_settings[label_text]" value="<?php echo esc_attr( $o['label_text'] ); ?>">
						<p class="description"><?php esc_html_e( 'The AI Act asks for a visible disclosure on the page, separate from the file marking. Badge and label are two ways to provide it; a note in the product description or a badge in the theme are others.', 'ai-act-image-disclosure' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Custom CSS', 'ai-act-image-disclosure' ); ?></th>
						<td>
							<textarea name="aipk_settings[custom_css]" rows="6" class="large-text code" placeholder=".aipk-ai-badge { border: 1px solid #fff; }"><?php echo esc_textarea( $o['custom_css'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Printed after the badge and label styles. Selectors: .aipk-wrap, .aipk-ai-badge, .aipk-pos-bottom-right (and the other corners), .aipk-label, img[data-ai-generated].', 'ai-act-image-disclosure' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Registered sizes plus the scaled full.
	 *
	 * @return array size => label
	 */
	private static function size_list() {
		$out = array( 'full' => __( 'Scaled copy served as full', 'ai-act-image-disclosure' ) );
		foreach ( wp_get_registered_image_subsizes() as $name => $info ) {
			$out[ $name ] = $name . ' (' . (int) $info['width'] . '×' . (int) $info['height'] . ')';
		}
		return $out;
	}

	/**
	 * Library counters.
	 *
	 * @return array
	 */
	private static function stats() {
		global $wpdb;
		$images  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png','image/webp')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$scanned = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", AIPK_Processor::META_KEY ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ai      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'", AIPK_Processor::META_AI ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return compact( 'images', 'scanned', 'ai' );
	}

	/**
	 * Environment facts and warnings.
	 *
	 * @return array{rows:array,warnings:array}
	 */
	public static function environment() {
		$rows     = array();
		$warnings = array();

		$editor = _wp_image_editor_choose( array( 'mime_type' => 'image/jpeg' ) );
		$rows[] = array( __( 'Image editor', 'ai-act-image-disclosure' ), $editor ? $editor : __( 'none', 'ai-act-image-disclosure' ) );
		$rows[] = array( __( 'Behaviour of the editor', 'ai-act-image-disclosure' ), __( 'Strips XMP and IPTC from every generated size; the plugin carries them back after generation.', 'ai-act-image-disclosure' ) );

		$threshold = apply_filters( 'big_image_size_threshold', 2560, array( 0, 0 ), '', 0 );
		$rows[]    = array(
			__( 'Big image threshold', 'ai-act-image-disclosure' ),
			$threshold
				/* translators: %d: pixels */
				? sprintf( __( '%d px: larger uploads are served as a scaled copy, which is also marked.', 'ai-act-image-disclosure' ), (int) $threshold )
				: __( 'disabled', 'ai-act-image-disclosure' ),
		);

		$formats = apply_filters( 'image_editor_output_format', array(), '', 'image/jpeg' );
		if ( ! empty( $formats ) ) {
			$rows[] = array( __( 'Output format conversion', 'ai-act-image-disclosure' ), implode( ', ', array_map( 'strval', $formats ) ) );
			if ( in_array( 'image/avif', $formats, true ) ) {
				$warnings[] = __( 'Sizes are converted to AVIF. The plugin cannot write XMP into AVIF yet, so those sizes stay unmarked.', 'ai-act-image-disclosure' );
			}
		}

		$optimizers = array(
			'wp-smushit/wp-smush.php'                            => 'Smush',
			'wp-smush-pro/wp-smush.php'                          => 'Smush Pro',
			'ewww-image-optimizer/ewww-image-optimizer.php'      => 'EWWW Image Optimizer',
			'shortpixel-image-optimiser/wp-shortpixel.php'       => 'ShortPixel',
			'imagify/imagify.php'                                => 'Imagify',
			'optimole-wp/optimole-wp.php'                        => 'Optimole',
			'tiny-compress-images/tiny-compress-images.php'      => 'TinyPNG',
			'webp-converter-for-media/webp-converter-for-media.php' => 'Converter for Media',
			'litespeed-cache/litespeed-cache.php'                => 'LiteSpeed Cache (image optimization)',
			'jetpack/jetpack.php'                                => 'Jetpack (Site Accelerator)',
		);
		$active = array();
		foreach ( $optimizers as $file => $name ) {
			if ( is_plugin_active( $file ) ) {
				$active[] = $name;
			}
		}
		$rows[] = array( __( 'Image optimizers detected', 'ai-act-image-disclosure' ), $active ? implode( ', ', $active ) : __( 'none', 'ai-act-image-disclosure' ) );
		if ( $active ) {
			$warnings[] = sprintf(
				/* translators: %s: plugin names */
				__( '%s rewrites image files after WordPress generates them and may strip metadata. Disable its "strip metadata" option, or re-scan the library after optimization runs.', 'ai-act-image-disclosure' ),
				implode( ', ', $active )
			);
		}
		if ( ! function_exists( 'iptcparse' ) ) {
			$warnings[] = __( 'PHP iptcparse() is unavailable: IPTC captions will not be read (XMP still is).', 'ai-act-image-disclosure' );
		}
		return compact( 'rows', 'warnings' );
	}

	/* ----------------------------------------------------------------- AJAX */

	/**
	 * Re-scan one attachment.
	 */
	public static function ajax_rescan() {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_ajax_referer( 'aipk_rescan_' . $id, 'nonce' );
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'ai-act-image-disclosure' ) ), 403 );
		}
		AIPK_Processor::process( $id );
		wp_send_json_success( array( 'html' => self::panel( $id ) ) );
	}

	/**
	 * Batch scan of the library.
	 */
	public static function ajax_scan() {
		check_ajax_referer( 'aipk_scan', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'ai-act-image-disclosure' ) ), 403 );
		}
		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$all    = ! empty( $_POST['all'] );
		$batch  = 10;
		$args   = array(
			'post_type'      => 'attachment',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp' ),
			'post_status'    => 'inherit',
			'posts_per_page' => $batch,
			'offset'         => $offset,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
		);
		if ( ! $all ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => AIPK_Processor::META_KEY,
					'compare' => 'NOT EXISTS',
				),
			);
			$args['offset'] = 0; // unscanned ones leave the set as they are processed.
		}
		$q     = new WP_Query( $args );
		$total = (int) $q->found_posts + ( $all ? 0 : $offset );
		$ai    = 0;
		foreach ( $q->posts as $id ) {
			$r = AIPK_Processor::process( $id );
			if ( $r && $r['ai'] ) {
				$ai++;
			}
		}
		$done = count( $q->posts );
		wp_send_json_success(
			array(
				'processed' => $done,
				'ai'        => $ai,
				'next'      => $offset + $done,
				'total'     => $total,
				'finished'  => $done < $batch,
			)
		);
	}
}
