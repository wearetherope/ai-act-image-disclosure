<?php
/**
 * Admin: Media Library integration, attachment panel, settings screen,
 * dashboard widget, row actions, help tabs, export.
 *
 * @package AI_Act_Image_Disclosure
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
class AIPK_Admin {

	const PAGE = 'ai-act-image-marking';

	/**
	 * Register hooks.
	 */
	public static function init() {
		// Media Library.
		add_filter( 'manage_media_columns', array( __CLASS__, 'column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'list_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_list_filter' ) );
		add_filter( 'ajax_query_attachments_args', array( __CLASS__, 'apply_grid_filter' ) );
		add_filter( 'bulk_actions-upload', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_filter( 'media_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'attachment_fields' ), 10, 2 );
		add_filter( 'wp_prepare_attachment_for_js', array( __CLASS__, 'prepare_for_js' ), 10, 2 );
		// Screens.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AIPK_FILE ), array( __CLASS__, 'action_links' ) );
		// AJAX and admin-post.
		add_action( 'wp_ajax_aipk_rescan', array( __CLASS__, 'ajax_rescan' ) );
		add_action( 'wp_ajax_aipk_classify', array( __CLASS__, 'ajax_classify' ) );
		add_action( 'wp_ajax_aipk_disclose', array( __CLASS__, 'ajax_disclose' ) );
		add_action( 'wp_ajax_aipk_scan', array( __CLASS__, 'ajax_scan' ) );
		add_action( 'admin_post_aipk_export', array( __CLASS__, 'export_csv' ) );
		add_action( 'admin_post_aipk_quick', array( __CLASS__, 'quick_action' ) );
		add_action( 'admin_post_aipk_reset', array( __CLASS__, 'reset_settings' ) );
		add_action( 'admin_post_aipk_bg', array( __CLASS__, 'bg_action' ) );
	}

	/* ============================================================ helpers */

	/**
	 * Settings page URL.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'upload.php' ) );
	}

	/**
	 * Classification labels.
	 *
	 * @return array
	 */
	public static function manual_labels() {
		return array(
			''             => __( 'Automatic (as declared by the file)', 'ai-act-image-marking' ),
			'ai_generated' => __( 'AI generated', 'ai-act-image-marking' ),
			'ai_modified'  => __( 'AI modified (real elements plus AI)', 'ai-act-image-marking' ),
			'not_ai'       => __( 'Not AI', 'ai-act-image-marking' ),
		);
	}

	/**
	 * Per-media disclosure labels.
	 *
	 * @return array
	 */
	public static function disclose_labels() {
		return array(
			''     => __( 'Follow the settings', 'ai-act-image-marking' ),
			'show' => __( 'Always show badge and label', 'ai-act-image-marking' ),
			'hide' => __( 'Never show them on this image', 'ai-act-image-marking' ),
		);
	}

	/**
	 * Filter options.
	 *
	 * @return array
	 */
	public static function filter_options() {
		return array(
			''         => __( 'All AI markings', 'ai-act-image-marking' ),
			'ai'       => __( 'AI generated or modified', 'ai-act-image-marking' ),
			'suspect'  => __( 'Suspected AI, to confirm', 'ai-act-image-marking' ),
			'marked'   => __( 'With provenance marking', 'ai-act-image-marking' ),
			'unmarked' => __( 'Without marking', 'ai-act-image-marking' ),
			'unscanned' => __( 'Not scanned yet', 'ai-act-image-marking' ),
		);
	}

	/**
	 * Badge HTML for a record.
	 *
	 * @param array $record Record.
	 * @return string
	 */
	public static function badge( $record ) {
		$dst = $record['digital_source_type'] ? $record['digital_source_type'] : $record['c2pa_digital_source_type'];
		if ( ! empty( $record['ai'] ) ) {
			return '<span class="aipk-badge aipk-badge-ai" title="' . esc_attr( $dst ) . '">' . esc_html( AIPK_Reader::term_label( $dst ) ) . '</span>';
		}
		if ( ! empty( $record['suspect'] ) ) {
			return '<span class="aipk-badge aipk-badge-suspect" title="' . esc_attr( implode( ', ', $record['signatures'] ) ) . '">' . esc_html__( 'Suspected AI, to confirm', 'ai-act-image-marking' ) . '</span>';
		}
		if ( 'manual' === $record['source'] && 'not_ai' === $record['manual'] ) {
			return '<span class="aipk-badge aipk-badge-none">' . esc_html__( 'Not AI (set by hand)', 'ai-act-image-marking' ) . '</span>';
		}
		if ( '' !== $dst ) {
			return '<span class="aipk-badge aipk-badge-marked" title="' . esc_attr( $dst ) . '">' . esc_html( AIPK_Reader::term_label( $dst ) ) . '</span>';
		}
		return '<span class="aipk-badge aipk-badge-none">' . esc_html__( 'No marking', 'ai-act-image-marking' ) . '</span>';
	}

	/**
	 * Human label for a per-size or original state.
	 *
	 * @param string $state State.
	 * @return string
	 */
	private static function state_label( $state ) {
		$map = array(
			'kept'         => __( 'marked', 'ai-act-image-marking' ),
			'injected'     => __( 'marking carried over', 'ai-act-image-marking' ),
			'written'      => __( 'marking written', 'ai-act-image-marking' ),
			'missing'      => __( 'file missing', 'ai-act-image-marking' ),
			'unsupported'  => __( 'format not supported', 'ai-act-image-marking' ),
			'skipped'      => __( 'skipped by settings', 'ai-act-image-marking' ),
			'skipped-c2pa' => __( 'left untouched: carries a C2PA manifest', 'ai-act-image-marking' ),
			'error'        => __( 'error', 'ai-act-image-marking' ),
		);
		return isset( $map[ $state ] ) ? $map[ $state ] : $state;
	}

	/**
	 * Where the marking comes from.
	 *
	 * @param array $record Record.
	 * @return string
	 */
	private static function source_label( $record ) {
		switch ( $record['source'] ) {
			case 'xmp':
				return __( 'declared in the file (XMP)', 'ai-act-image-marking' );
			case 'c2pa':
				return __( 'declared in the file (C2PA manifest)', 'ai-act-image-marking' );
			case 'manual':
				return __( 'set by hand in the Media Library', 'ai-act-image-marking' );
		}
		return __( 'none', 'ai-act-image-marking' );
	}

	/**
	 * Quick action URL (row actions, dashboard).
	 *
	 * @param int    $id Attachment id.
	 * @param string $do Action.
	 * @return string
	 */
	private static function quick_url( $id, $do ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=aipk_quick&id=' . (int) $id . '&do=' . $do ), 'aipk_quick_' . $id );
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
			case 'suspect':
				return array(
					array(
						'key'   => AIPK_Processor::META_SUSPECT,
						'value' => '1',
					),
				);
			case 'marked':
				return array(
					array(
						'key'   => AIPK_Processor::META_MARKED,
						'value' => '1',
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
						'key'   => AIPK_Processor::META_MARKED,
						'value' => '0',
					),
				);
			case 'unscanned':
				return array(
					array(
						'key'     => AIPK_Processor::META_KEY,
						'compare' => 'NOT EXISTS',
					),
				);
		}
		return null;
	}

	/* ========================================================== list view */

	/**
	 * Add the column after "Author".
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'author' === $key ) {
				$out['aipk'] = __( 'AI marking', 'ai-act-image-marking' );
			}
		}
		if ( ! isset( $out['aipk'] ) ) {
			$out['aipk'] = __( 'AI marking', 'ai-act-image-marking' );
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
			echo '<span class="aipk-muted" aria-hidden="true">&mdash;</span>';
			return;
		}
		$record = AIPK_Processor::record( $post_id );
		if ( ! $record ) {
			echo '<span class="aipk-badge aipk-badge-none">' . esc_html__( 'Not scanned', 'ai-act-image-marking' ) . '</span>';
			if ( current_user_can( 'edit_post', $post_id ) ) {
				echo '<div class="aipk-muted"><a href="' . esc_url( self::quick_url( $post_id, 'rescan' ) ) . '">' . esc_html__( 'Scan now', 'ai-act-image-marking' ) . '</a></div>';
			}
			return;
		}
		echo wp_kses_post( self::badge( $record ) );
		$sub = array();
		if ( ! empty( $record['generators'] ) ) {
			$sub[] = implode( ', ', $record['generators'] );
		} elseif ( ! empty( $record['signatures'] ) ) {
			$sub[] = implode( ', ', $record['signatures'] );
		}
		if ( 'manual' === $record['source'] ) {
			$sub[] = __( 'set by hand', 'ai-act-image-marking' );
		}
		$disclose = AIPK_Processor::disclose( $post_id );
		if ( 'hide' === $disclose ) {
			$sub[] = __( 'badge hidden', 'ai-act-image-marking' );
		} elseif ( 'show' === $disclose ) {
			$sub[] = __( 'badge forced', 'ai-act-image-marking' );
		}
		if ( $sub ) {
			echo '<div class="aipk-muted">' . esc_html( implode( ' · ', $sub ) ) . '</div>';
		}
	}

	/**
	 * Row actions in the list view.
	 *
	 * @param array   $actions Actions.
	 * @param WP_Post $post    Attachment.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( ! wp_attachment_is_image( $post->ID ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		$record = AIPK_Processor::record( $post->ID );
		if ( $record && $record['ai'] ) {
			$actions['aipk_not'] = '<a href="' . esc_url( self::quick_url( $post->ID, 'not_ai' ) ) . '">' . esc_html__( 'Not AI', 'ai-act-image-marking' ) . '</a>';
			$hidden              = 'hide' === AIPK_Processor::disclose( $post->ID );
			$actions['aipk_badge'] = '<a href="' . esc_url( self::quick_url( $post->ID, $hidden ? 'badge_default' : 'badge_hide' ) ) . '">' . ( $hidden ? esc_html__( 'Show badge', 'ai-act-image-marking' ) : esc_html__( 'Hide badge', 'ai-act-image-marking' ) ) . '</a>';
		} else {
			$actions['aipk_ai'] = '<a href="' . esc_url( self::quick_url( $post->ID, 'ai_generated' ) ) . '">' . esc_html__( 'Mark as AI', 'ai-act-image-marking' ) . '</a>';
		}
		return $actions;
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
		echo '<label class="screen-reader-text" for="aipk_filter">' . esc_html__( 'Filter by AI marking', 'ai-act-image-marking' ) . '</label>';
		echo '<select name="aipk_filter" id="aipk_filter">';
		foreach ( self::filter_options() as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- read-only filtering of a core AJAX query, capability checked by core.
		$value = isset( $_REQUEST['query']['aipk_filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['query']['aipk_filter'] ) ) : '';
		$mq    = self::filter_meta_query( $value );
		if ( $mq ) {
			$args['meta_query'] = $mq; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		return $args;
	}

	/**
	 * Bulk actions.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public static function bulk_actions( $actions ) {
		$actions['aipk_ai_generated'] = __( 'AI marking: AI generated', 'ai-act-image-marking' );
		$actions['aipk_ai_modified']  = __( 'AI marking: AI modified', 'ai-act-image-marking' );
		$actions['aipk_not_ai']       = __( 'AI marking: not AI', 'ai-act-image-marking' );
		$actions['aipk_auto']         = __( 'AI marking: back to automatic', 'ai-act-image-marking' );
		$actions['aipk_rescan']       = __( 'AI marking: re-scan', 'ai-act-image-marking' );
		$actions['aipk_show']         = __( 'AI badge: always show', 'ai-act-image-marking' );
		$actions['aipk_hide']         = __( 'AI badge: never show', 'ai-act-image-marking' );
		$actions['aipk_default']      = __( 'AI badge: follow the settings', 'ai-act-image-marking' );
		return $actions;
	}

	/**
	 * Handle bulk actions.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action.
	 * @param array  $ids      Ids.
	 * @return string
	 */
	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 0 !== strpos( $action, 'aipk_' ) ) {
			return $redirect;
		}
		$n = 0;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( ! current_user_can( 'edit_post', $id ) || ! wp_attachment_is_image( $id ) ) {
				continue;
			}
			self::apply_action( $id, substr( $action, 5 ) );
			$n++;
		}
		return add_query_arg( 'aipk_done', $n, remove_query_arg( 'aipk_done', $redirect ) );
	}

	/**
	 * One named action on one attachment.
	 *
	 * @param int    $id Attachment id.
	 * @param string $do rescan|ai_generated|ai_modified|not_ai|auto|show|hide|default|badge_hide|badge_default.
	 */
	private static function apply_action( $id, $do ) {
		switch ( $do ) {
			case 'rescan':
				AIPK_Processor::process( $id );
				break;
			case 'auto':
				AIPK_Processor::classify( $id, '' );
				break;
			case 'ai_generated':
			case 'ai_modified':
			case 'not_ai':
				AIPK_Processor::classify( $id, $do );
				break;
			case 'show':
			case 'hide':
			case 'default':
			case 'badge_hide':
			case 'badge_default':
				AIPK_Processor::set_disclose( $id, str_replace( array( 'badge_', 'default' ), '', $do ) );
				self::refresh_forced_flag();
				break;
		}
	}

	/**
	 * Row action / dashboard quick action handler.
	 */
	public static function quick_action() {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'aipk_quick_' . $id );
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'ai-act-image-marking' ) );
		}
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		self::apply_action( $id, $do );
		$back = wp_get_referer();
		wp_safe_redirect( add_query_arg( 'aipk_done', 1, $back ? remove_query_arg( 'aipk_done', $back ) : admin_url( 'upload.php?mode=list' ) ) );
		exit;
	}

	/**
	 * Remember whether any image forces the badge, so the front end prints the CSS.
	 */
	public static function refresh_forced_flag() {
		global $wpdb;
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = 'show'", AIPK_Processor::META_DISCLOSE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $n ) {
			set_transient( 'aipk_forced_disclosure', 1, 0 );
		} else {
			delete_transient( 'aipk_forced_disclosure' );
		}
	}

	/* ==================================================== attachment panel */

	/**
	 * Panel in the attachment details.
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
			'label' => __( 'AI marking', 'ai-act-image-marking' ),
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
		$can    = current_user_can( 'edit_post', $attachment_id );
		$html   = '<div class="aipk-panel" data-id="' . (int) $attachment_id . '" data-nonce="' . esc_attr( wp_create_nonce( 'aipk_panel_' . $attachment_id ) ) . '">';

		if ( ! $record ) {
			$html .= '<div class="aipk-panel-head"><span class="aipk-badge aipk-badge-none">' . esc_html__( 'Not scanned yet', 'ai-act-image-marking' ) . '</span>';
			if ( $can ) {
				$html .= '<button type="button" class="button button-small aipk-rescan">' . esc_html__( 'Scan file', 'ai-act-image-marking' ) . '</button>';
			}
			return $html . '</div></div>';
		}

		$dst   = $record['digital_source_type'] ? $record['digital_source_type'] : $record['c2pa_digital_source_type'];
		$html .= '<div class="aipk-panel-head">' . self::badge( $record ) . '<span class="aipk-muted">' . esc_html( self::source_label( $record ) ) . '</span></div>';

		if ( $record['suspect'] && $can ) {
			$html .= '<div class="aipk-callout"><p>' . esc_html(
				sprintf(
					/* translators: %s: generator names */
					__( 'The file carries traces of %s but no formal marking. Confirm to write it.', 'ai-act-image-marking' ),
					implode( ', ', $record['signatures'] )
				)
			) . '</p><p><button type="button" class="button button-small button-primary aipk-classify" data-value="ai_generated">' . esc_html__( 'Confirm: AI generated', 'ai-act-image-marking' ) . '</button> <button type="button" class="button button-small aipk-classify" data-value="not_ai">' . esc_html__( 'Not AI', 'ai-act-image-marking' ) . '</button></p></div>';
		}

		$facts = array(
			__( 'Type', 'ai-act-image-marking' )        => $dst ? $dst : __( 'absent', 'ai-act-image-marking' ),
			__( 'Disclosure', 'ai-act-image-marking' )  => $record['description'],
			__( 'Creator', 'ai-act-image-marking' )     => $record['creator'],
			__( 'Credit', 'ai-act-image-marking' )      => $record['credit'],
			__( 'Rights', 'ai-act-image-marking' )      => $record['rights'],
			__( 'C2PA', 'ai-act-image-marking' )        => $record['has_c2pa'] ? ( $record['generators'] ? implode( ', ', $record['generators'] ) : __( 'present', 'ai-act-image-marking' ) ) : '',
			__( 'Traces', 'ai-act-image-marking' )      => implode( ', ', $record['signatures'] ),
			__( 'Software', 'ai-act-image-marking' )    => $record['software'] ? $record['software'] : $record['creator_tool'],
		);
		$html .= '<dl class="aipk-facts">';
		foreach ( $facts as $label => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			$html .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
		}
		$html .= '</dl>';

		// Sizes: one line summary, details on demand.
		$parts = array();
		$ok    = 0;
		if ( is_array( $sizes ) ) {
			foreach ( $sizes as $size => $state ) {
				if ( '_file' === $size ) {
					continue;
				}
				$parts[] = '<li><span>' . esc_html( $size ) . '</span><span class="aipk-state aipk-state-' . esc_attr( preg_replace( '/[^a-z]/', '', $state ) ) . '">' . esc_html( self::state_label( $state ) ) . '</span></li>';
				if ( in_array( $state, array( 'kept', 'injected' ), true ) ) {
					$ok++;
				}
			}
		}
		$blocks = implode( ' + ', array_filter( array( $record['has_xmp'] ? 'XMP' : '', $record['has_iptc'] ? 'IPTC' : '', $record['has_c2pa'] ? 'C2PA' : '' ) ) );
		$html  .= '<details class="aipk-details"><summary>';
		if ( $parts ) {
			/* translators: 1: marked sizes, 2: total sizes */
			$html .= esc_html( sprintf( __( 'Sizes marked: %1$d of %2$d', 'ai-act-image-marking' ), $ok, count( $parts ) ) );
		} else {
			$html .= esc_html__( 'Generated sizes: nothing to carry', 'ai-act-image-marking' );
		}
		$html .= '</summary><ul class="aipk-sizes">' . implode( '', $parts ) . '</ul>';
		$html .= '<p class="aipk-muted">' . esc_html__( 'Blocks in the original:', 'ai-act-image-marking' ) . ' ' . esc_html( $blocks ? $blocks : __( 'none', 'ai-act-image-marking' ) );
		if ( ! empty( $record['original_marked'] ) ) {
			$html .= ' · ' . esc_html__( 'Original:', 'ai-act-image-marking' ) . ' ' . esc_html( self::state_label( $record['original_marked'] ) );
		}
		if ( ! empty( $record['scanned_at'] ) ) {
			$html .= ' · ' . esc_html__( 'Scanned', 'ai-act-image-marking' ) . ' ' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $record['scanned_at'] ) );
		}
		$html .= '</p></details>';

		if ( $can ) {
			$manual   = get_post_meta( $attachment_id, AIPK_Processor::META_MANUAL, true );
			$disclose = AIPK_Processor::disclose( $attachment_id );
			$html    .= '<div class="aipk-actions">';
			$html    .= '<label><span>' . esc_html__( 'Classification', 'ai-act-image-marking' ) . '</span><select class="aipk-classify-select">';
			foreach ( self::manual_labels() as $value => $label ) {
				$html .= '<option value="' . esc_attr( $value ) . '"' . selected( $manual, $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$html .= '</select></label>';
			$html .= '<label><span>' . esc_html__( 'Badge on this image', 'ai-act-image-marking' ) . '</span><select class="aipk-disclose-select">';
			foreach ( self::disclose_labels() as $value => $label ) {
				$html .= '<option value="' . esc_attr( $value ) . '"' . selected( $disclose, $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$html .= '</select></label>';
			$html .= '<p><button type="button" class="button button-primary button-small aipk-classify-apply">' . esc_html__( 'Apply', 'ai-act-image-marking' ) . '</button> <button type="button" class="button button-small aipk-rescan">' . esc_html__( 'Re-scan file', 'ai-act-image-marking' ) . '</button></p>';
			$html .= '<p class="aipk-muted">' . esc_html__( 'An AI classification writes the IPTC digital source type into the generated sizes and, unless the original carries a C2PA manifest, into the original too.', 'ai-act-image-marking' ) . '</p>';
			$html .= '</div>';
		}
		return $html . '</div>';
	}

	/**
	 * Compact record for the media grid.
	 *
	 * @param array   $response   JS response.
	 * @param WP_Post $attachment Attachment.
	 * @return array
	 */
	public static function prepare_for_js( $response, $attachment ) {
		$record = AIPK_Processor::record( $attachment->ID );
		if ( ! $record ) {
			return $response;
		}
		$dst = $record['digital_source_type'] ? $record['digital_source_type'] : $record['c2pa_digital_source_type'];
		if ( $record['ai'] ) {
			$response['aipk'] = array(
				'kind'  => 'ai',
				'badge' => __( 'AI', 'ai-act-image-marking' ),
				'title' => AIPK_Reader::term_label( $dst ),
			);
		} elseif ( $record['suspect'] ) {
			$response['aipk'] = array(
				'kind'  => 'suspect',
				'badge' => __( 'AI?', 'ai-act-image-marking' ),
				'title' => __( 'Suspected AI, to confirm', 'ai-act-image-marking' ),
			);
		} elseif ( '' !== $dst ) {
			$response['aipk'] = array(
				'kind'  => 'marked',
				'badge' => __( 'Marked', 'ai-act-image-marking' ),
				'title' => AIPK_Reader::term_label( $dst ),
			);
		}
		return $response;
	}

	/* ============================================================ screens */

	/**
	 * Admin assets (the media modal can open on any screen).
	 */
	public static function assets() {
		wp_register_style( 'aipk-admin', AIPK_URL . 'assets/admin.css', array(), AIPK_VERSION );
		wp_register_script( 'aipk-admin', AIPK_URL . 'assets/admin.js', array( 'jquery' ), AIPK_VERSION, true );
		wp_localize_script(
			'aipk-admin',
			'AIPK',
			array(
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'filters'   => self::filter_options(),
				'scanNonce' => wp_create_nonce( 'aipk_scan' ),
				'i18n'      => array(
					'scanning' => __( 'Scanning…', 'ai-act-image-marking' ),
					'done'     => __( 'Scan complete.', 'ai-act-image-marking' ),
					'error'    => __( 'Something went wrong.', 'ai-act-image-marking' ),
					/* translators: 1: processed count, 2: total, 3: AI count */
					'progress' => __( '%1$d of %2$d scanned, %3$d marked as AI', 'ai-act-image-marking' ),
					'choose'   => __( 'Choose badge image', 'ai-act-image-marking' ),
					'use'      => __( 'Use this image', 'ai-act-image-marking' ),
					'reset'    => __( 'Reset every setting to its default?', 'ai-act-image-marking' ),
				),
			)
		);
		wp_enqueue_style( 'aipk-admin' );
		wp_enqueue_script( 'aipk-admin' );
		if ( isset( $_GET['page'] ) && self::PAGE === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_enqueue_media();
		}
	}

	/**
	 * Menu entry under Media, with help tabs.
	 */
	public static function menu() {
		$hook = add_media_page(
			__( 'AI Act Image Marking', 'ai-act-image-marking' ),
			__( 'AI Act Marking', 'ai-act-image-marking' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'settings_page' )
		);
		add_action( 'load-' . $hook, array( __CLASS__, 'help_tabs' ) );
	}

	/**
	 * Contextual help.
	 */
	public static function help_tabs() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		$screen->add_help_tab(
			array(
				'id'      => 'aipk-overview',
				'title'   => __( 'How it works', 'ai-act-image-marking' ),
				'content' => '<p>' . esc_html__( 'WordPress keeps the uploaded original untouched but every size it generates loses XMP and IPTC. The plugin reads the provenance of the original (IPTC digital source type, C2PA manifest, credits), stores it, and copies the marking into each size. Images that carry no marking can be classified by hand: the classification is written into the files as an IPTC digital source type.', 'ai-act-image-marking' ) . '</p>',
			)
		);
		$screen->add_help_tab(
			array(
				'id'      => 'aipk-disclosure',
				'title'   => __( 'Visible disclosure', 'ai-act-image-marking' ),
				'content' => '<p>' . esc_html__( 'The AI Act separates the machine-readable marking of the file from the visible disclosure on the page. The badge, its popup, the text label, the site notice and the [ai_act_disclosure] shortcode are tools for the visible part. Each image can follow the settings, always show the badge or never show it.', 'ai-act-image-marking' ) . '</p>',
			)
		);
		$screen->add_help_tab(
			array(
				'id'      => 'aipk-limits',
				'title'   => __( 'Limits', 'ai-act-image-marking' ),
				'content' => '<p>' . esc_html__( 'C2PA manifests are never copied into resized files, since their signature covers the original bytes only. AVIF sizes are not written yet. Image optimizers that strip metadata undo the marking: disable that option or re-scan after they run. The plugin is not legal advice.', 'ai-act-image-marking' ) . '</p>',
			)
		);
		$screen->set_help_sidebar( '<p><a href="https://github.com/wearetherope/ai-act-image-disclosure" target="_blank" rel="noopener">GitHub</a></p>' );
	}

	/**
	 * Plugin row link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'ai-act-image-marking' ) . '</a>' );
		return $links;
	}

	/**
	 * Notices: after activation, after bulk or quick actions, after reset.
	 */
	public static function notices() {
		if ( get_transient( 'aipk_activated' ) && current_user_can( 'manage_options' ) ) {
			delete_transient( 'aipk_activated' );
			$screen = get_current_screen();
			if ( ! $screen || 'media_page_' . self::PAGE !== $screen->id ) {
				echo '<div class="notice notice-info is-dismissible aipk-notice"><p><strong>' . esc_html__( 'AI Act Image Marking is active.', 'ai-act-image-marking' ) . '</strong> ' . esc_html__( 'New uploads are processed automatically. Scan the images you already have and choose how to disclose them.', 'ai-act-image-marking' ) . ' <a class="button button-small" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open settings', 'ai-act-image-marking' ) . '</a></p></div>';
			}
		}
		if ( isset( $_GET['aipk_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$n = (int) $_GET['aipk_done']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			/* translators: %d: number of images */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d image processed.', '%d images processed.', $n, 'ai-act-image-marking' ), $n ) ) . '</p></div>';
		}
		if ( isset( $_GET['aipk_reset'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings reset to their defaults.', 'ai-act-image-marking' ) . '</p></div>';
		}
	}

	/**
	 * Dashboard widget.
	 */
	public static function dashboard_widget() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		wp_add_dashboard_widget( 'aipk_dashboard', __( 'AI images', 'ai-act-image-marking' ), array( __CLASS__, 'dashboard_widget_content' ) );
	}

	/**
	 * Dashboard widget content.
	 */
	public static function dashboard_widget_content() {
		$s = self::stats();
		echo '<div class="aipk-dash">';
		self::stat_card( $s['ai'], __( 'AI images', 'ai-act-image-marking' ), 'ai', 'ai' );
		self::stat_card( $s['suspect'], __( 'To confirm', 'ai-act-image-marking' ), 'suspect', 'suspect' );
		self::stat_card( $s['unscanned'], __( 'Not scanned', 'ai-act-image-marking' ), 'unscanned', 'none' );
		echo '</div>';
		$o = AIPK_Options::all();
		echo '<p class="aipk-muted">' . ( $o['badge_enabled'] ? esc_html__( 'Badge on the page: on.', 'ai-act-image-marking' ) : esc_html__( 'Badge on the page: off.', 'ai-act-image-marking' ) ) . ' <a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'ai-act-image-marking' ) . '</a></p>';
	}

	/**
	 * One stat card.
	 *
	 * @param int    $n      Number.
	 * @param string $label  Label.
	 * @param string $filter Library filter.
	 * @param string $tone   ai|suspect|marked|none|plain.
	 */
	private static function stat_card( $n, $label, $filter, $tone = 'plain' ) {
		$url = admin_url( 'upload.php?mode=list' . ( $filter ? '&aipk_filter=' . $filter : '' ) );
		echo '<a class="aipk-card aipk-card-' . esc_attr( $tone ) . '" href="' . esc_url( $url ) . '"><strong>' . (int) $n . '</strong><span>' . esc_html( $label ) . '</span></a>';
	}

	/**
	 * Library counters (cached in the processor).
	 *
	 * @return array
	 */
	private static function stats() {
		return AIPK_Processor::counts();
	}

	/**
	 * Registered sizes plus the scaled full.
	 *
	 * @return array size => label
	 */
	private static function size_list() {
		$out = array( 'full' => __( 'Scaled copy served as full', 'ai-act-image-marking' ) );
		foreach ( wp_get_registered_image_subsizes() as $name => $info ) {
			$out[ $name ] = $name . ' (' . (int) $info['width'] . '×' . (int) $info['height'] . ')';
		}
		return $out;
	}

	/**
	 * Environment facts as rows with a state: ok|warn|info.
	 *
	 * @return array
	 */
	public static function environment() {
		$rows = array();

		$editor = _wp_image_editor_choose( array( 'mime_type' => 'image/jpeg' ) );
		$rows[] = array( 'ok', __( 'Image editor', 'ai-act-image-marking' ), ( $editor ? $editor : __( 'none', 'ai-act-image-marking' ) ) . '. ' . __( 'It strips XMP and IPTC from every generated size; the plugin carries them back after generation.', 'ai-act-image-marking' ) );

		$threshold = apply_filters( 'big_image_size_threshold', 2560, array( 0, 0 ), '', 0 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- reading a core setting.
		$rows[]    = array(
			'info',
			__( 'Big image threshold', 'ai-act-image-marking' ),
			$threshold
				/* translators: %d: pixels */
				? sprintf( __( '%d px. Larger uploads are served as a scaled copy, which is marked like every other size.', 'ai-act-image-marking' ), (int) $threshold )
				: __( 'Disabled: the original is served as full.', 'ai-act-image-marking' ),
		);

		$formats = apply_filters( 'image_editor_output_format', array(), '', 'image/jpeg' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- reading a core setting.
		if ( ! empty( $formats ) ) {
			$avif   = in_array( 'image/avif', $formats, true );
			$rows[] = array( $avif ? 'warn' : 'info', __( 'Output format conversion', 'ai-act-image-marking' ), implode( ', ', array_map( 'strval', $formats ) ) . ( $avif ? '. ' . __( 'AVIF sizes cannot be written yet and stay unmarked.', 'ai-act-image-marking' ) : '' ) );
		}

		$optimizers = array(
			'wp-smushit/wp-smush.php'                               => 'Smush',
			'wp-smush-pro/wp-smush.php'                             => 'Smush Pro',
			'ewww-image-optimizer/ewww-image-optimizer.php'         => 'EWWW Image Optimizer',
			'shortpixel-image-optimiser/wp-shortpixel.php'          => 'ShortPixel',
			'imagify/imagify.php'                                   => 'Imagify',
			'optimole-wp/optimole-wp.php'                           => 'Optimole',
			'tiny-compress-images/tiny-compress-images.php'         => 'TinyPNG',
			'webp-converter-for-media/webp-converter-for-media.php' => 'Converter for Media',
			'litespeed-cache/litespeed-cache.php'                   => 'LiteSpeed Cache',
			'jetpack/jetpack.php'                                   => 'Jetpack',
		);
		$active = array();
		foreach ( $optimizers as $file => $name ) {
			if ( is_plugin_active( $file ) ) {
				$active[] = $name;
			}
		}
		$rows[] = $active
			? array( 'warn', __( 'Image optimizers', 'ai-act-image-marking' ), implode( ', ', $active ) . '. ' . __( 'They rewrite image files after WordPress and may strip metadata: disable their "strip metadata" option, or re-scan the library after they run.', 'ai-act-image-marking' ) )
			: array( 'ok', __( 'Image optimizers', 'ai-act-image-marking' ), __( 'None detected.', 'ai-act-image-marking' ) );

		$rows[] = function_exists( 'iptcparse' )
			? array( 'ok', __( 'PHP', 'ai-act-image-marking' ), PHP_VERSION . ', ' . __( 'IPTC parsing available.', 'ai-act-image-marking' ) )
			: array( 'warn', __( 'PHP', 'ai-act-image-marking' ), PHP_VERSION . ', ' . __( 'iptcparse() unavailable: IPTC captions are not read (XMP still is).', 'ai-act-image-marking' ) );

		$rows[] = wp_is_writable( wp_upload_dir()['basedir'] )
			? array( 'ok', __( 'Uploads folder', 'ai-act-image-marking' ), __( 'Writable.', 'ai-act-image-marking' ) )
			: array( 'warn', __( 'Uploads folder', 'ai-act-image-marking' ), __( 'Not writable: the marking cannot be carried into sizes.', 'ai-act-image-marking' ) );
		return $rows;
	}

	/**
	 * Settings page.
	 */
	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o         = AIPK_Options::all();
		$env       = self::environment();
		$s         = self::stats();
		$bg        = AIPK_Processor::bg_status();
		$bg_done   = $bg ? null : get_transient( 'aipk_bg_done' );
		if ( $bg_done ) {
			delete_transient( 'aipk_bg_done' );
		}
		$badge_img = $o['badge_image'] ? wp_get_attachment_image_url( (int) $o['badge_image'], 'thumbnail' ) : '';
		$warns     = count( array_filter( $env, function ( $r ) { return 'warn' === $r[0]; } ) );
		$tabs      = array(
			'marking'     => __( 'Marking', 'ai-act-image-marking' ),
			'disclosure'  => __( 'Disclosure', 'ai-act-image-marking' ),
			'environment' => __( 'Environment', 'ai-act-image-marking' ) . ( $warns ? ' <span class="aipk-count">' . (int) $warns . '</span>' : '' ),
		);
		?>
		<div class="wrap aipk-settings">
			<div class="aipk-hero">
				<div>
					<h1><?php esc_html_e( 'AI Act Image Marking', 'ai-act-image-marking' ); ?></h1>
					<p><?php esc_html_e( 'Mark, keep and disclose AI-generated images. Every upload is processed automatically; scan the library once for what came before.', 'ai-act-image-marking' ); ?></p>
				</div>
				<div class="aipk-hero-actions">
					<?php if ( $bg ) : ?>
						<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aipk_bg&do=stop' ), 'aipk_bg' ) ); ?>"><?php esc_html_e( 'Stop background scan', 'ai-act-image-marking' ); ?></a>
					<?php else : ?>
						<button type="button" class="button button-primary" id="aipk-scan" data-all="0"><?php esc_html_e( 'Scan new images', 'ai-act-image-marking' ); ?></button>
						<button type="button" class="button" id="aipk-scan-all" data-all="1"><?php esc_html_e( 'Re-scan everything', 'ai-act-image-marking' ); ?></button>
						<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aipk_bg&do=start' ), 'aipk_bg' ) ); ?>" title="<?php esc_attr_e( 'Runs in the background (Action Scheduler when present, else WP-Cron), no need to keep this page open', 'ai-act-image-marking' ); ?>"><?php esc_html_e( 'Scan in background', 'ai-act-image-marking' ); ?></a>
					<?php endif; ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aipk_export' ), 'aipk_export' ) ); ?>"><?php esc_html_e( 'Export CSV', 'ai-act-image-marking' ); ?></a>
				</div>
			</div>
			<?php if ( $bg ) : ?>
				<div class="aipk-progress" aria-live="polite"><div class="aipk-progress-bar"><span style="width:<?php echo (int) ( $bg['total'] ? min( 100, round( $bg['done'] / $bg['total'] * 100 ) ) : 100 ); ?>%"></span></div>
				<p><?php echo esc_html( sprintf(
					/* translators: 1: processed, 2: total, 3: AI count */
					__( 'Background scan running: %1$d of %2$d images, %3$d marked as AI. It continues while the site receives visits (WP-Cron); this page refreshes every minute.', 'ai-act-image-marking' ),
					(int) $bg['done'],
					(int) $bg['total'],
					(int) $bg['ai']
				) ); ?></p></div>
				<meta http-equiv="refresh" content="60">
			<?php elseif ( $bg_done ) : ?>
				<div class="notice notice-success inline"><p><?php echo esc_html( sprintf(
					/* translators: 1: processed, 2: AI count */
					__( 'Background scan finished: %1$d images processed, %2$d marked as AI.', 'ai-act-image-marking' ),
					(int) $bg_done['done'],
					(int) $bg_done['ai']
				) ); ?></p></div>
			<?php endif; ?>
			<?php if ( $s['images'] > 2000 && ! $bg ) : ?>
				<p class="description aipk-hint"><?php esc_html_e( 'Large library: use the background scan, or WP-CLI for the fastest run:', 'ai-act-image-marking' ); ?> <code>wp ai-provenance scan</code></p>
			<?php endif; ?>
			<div id="aipk-scan-status" class="aipk-progress" hidden aria-live="polite"><div class="aipk-progress-bar"><span style="width:0"></span></div><p></p></div>

			<div class="aipk-cards">
				<?php
				self::stat_card( $s['images'], __( 'Images in the library', 'ai-act-image-marking' ), '', 'plain' );
				self::stat_card( $s['ai'], __( 'AI generated or modified', 'ai-act-image-marking' ), 'ai', 'ai' );
				self::stat_card( $s['suspect'], __( 'Suspected AI, to confirm', 'ai-act-image-marking' ), 'suspect', 'suspect' );
				self::stat_card( $s['unscanned'], __( 'Not scanned yet', 'ai-act-image-marking' ), 'unscanned', 'none' );
				?>
			</div>

			<nav class="nav-tab-wrapper aipk-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'ai-act-image-marking' ); ?>">
				<?php foreach ( $tabs as $id => $label ) : ?>
					<a href="#<?php echo esc_attr( $id ); ?>" class="nav-tab" data-tab="<?php echo esc_attr( $id ); ?>"><?php echo wp_kses_post( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="options.php" id="aipk-form">
				<?php settings_fields( 'aipk' ); ?>

				<section class="aipk-tab" data-tab="marking">
					<div class="aipk-box">
						<h2><?php esc_html_e( 'Uploaded original', 'ai-act-image-marking' ); ?></h2>
						<fieldset>
							<label class="aipk-choice"><input type="radio" name="aipk_settings[original_mode]" value="keep" <?php checked( $o['original_mode'], 'keep' ); ?>><span><strong><?php esc_html_e( 'Leave it exactly as uploaded', 'ai-act-image-marking' ); ?></strong><em><?php esc_html_e( 'Recommended. The file the client sent is the file you keep.', 'ai-act-image-marking' ); ?></em></span></label>
							<label class="aipk-choice"><input type="radio" name="aipk_settings[original_mode]" value="clean" <?php checked( $o['original_mode'], 'clean' ); ?>><span><strong><?php esc_html_e( 'Remove post-production traces, keep the marking', 'ai-act-image-marking' ); ?></strong><em><?php esc_html_e( 'Drops Camera Raw settings, document history, ancestors and the creator tool from the XMP. Provenance, description, credits and rights stay. Files with a C2PA manifest are never touched.', 'ai-act-image-marking' ); ?></em></span></label>
						</fieldset>
						<label class="aipk-check"><input type="checkbox" name="aipk_settings[manual_writes_original]" value="1" <?php checked( $o['manual_writes_original'] ); ?>> <?php esc_html_e( 'When an image is classified as AI by hand, write the IPTC digital source type into the original too (never on files with a C2PA manifest)', 'ai-act-image-marking' ); ?></label>
					</div>

					<div class="aipk-box">
						<h2><?php esc_html_e( 'Generated sizes', 'ai-act-image-marking' ); ?></h2>
						<div class="aipk-grid-2">
							<fieldset>
								<legend><?php esc_html_e( 'Which images', 'ai-act-image-marking' ); ?></legend>
								<label class="aipk-choice"><input type="radio" name="aipk_settings[scope]" value="ai" <?php checked( $o['scope'], 'ai' ); ?>><span><strong><?php esc_html_e( 'AI generated and AI modified only', 'ai-act-image-marking' ); ?></strong><em><?php esc_html_e( 'Recommended.', 'ai-act-image-marking' ); ?></em></span></label>
								<label class="aipk-choice"><input type="radio" name="aipk_settings[scope]" value="all" <?php checked( $o['scope'], 'all' ); ?>><span><strong><?php esc_html_e( 'Every image with XMP or IPTC metadata', 'ai-act-image-marking' ); ?></strong><em><?php esc_html_e( 'Also carries photographer credits and captions, and with them any personal data the metadata may contain.', 'ai-act-image-marking' ); ?></em></span></label>
							</fieldset>
							<fieldset>
								<legend><?php esc_html_e( 'What to carry', 'ai-act-image-marking' ); ?></legend>
								<label class="aipk-choice"><input type="radio" name="aipk_settings[derivative_mode]" value="full" <?php checked( $o['derivative_mode'], 'full' ); ?>><span><strong><?php esc_html_e( 'The whole XMP and IPTC blocks', 'ai-act-image-marking' ); ?></strong><em><?php esc_html_e( 'Recommended.', 'ai-act-image-marking' ); ?></em></span></label>
								<label class="aipk-choice"><input type="radio" name="aipk_settings[derivative_mode]" value="minimal" <?php checked( $o['derivative_mode'], 'minimal' ); ?>><span><strong><?php esc_html_e( 'Only the provenance fields', 'ai-act-image-marking' ); ?></strong><em><?php esc_html_e( 'Digital source type, description, creator, credit, rights, usage terms. About one kilobyte per file.', 'ai-act-image-marking' ); ?></em></span></label>
								<label class="aipk-choice"><input type="radio" name="aipk_settings[derivative_mode]" value="none" <?php checked( $o['derivative_mode'], 'none' ); ?>><span><strong><?php esc_html_e( 'Nothing', 'ai-act-image-marking' ); ?></strong><em><?php esc_html_e( 'Sizes stay as WordPress makes them.', 'ai-act-image-marking' ); ?></em></span></label>
							</fieldset>
						</div>
						<fieldset class="aipk-sizes-pick">
							<legend><?php esc_html_e( 'Sizes to mark', 'ai-act-image-marking' ); ?></legend>
							<?php foreach ( self::size_list() as $size => $label ) : ?>
								<label class="aipk-check"><input type="checkbox" name="aipk_settings[skip_sizes][]" value="<?php echo esc_attr( $size ); ?>" <?php checked( ! in_array( $size, $o['skip_sizes'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Unchecked sizes stay unmarked. Sizes registered by the theme later are marked by default.', 'ai-act-image-marking' ); ?></p>
						</fieldset>
					</div>
				</section>

				<section class="aipk-tab" data-tab="disclosure">
					<div class="aipk-grid-2 aipk-grid-sticky">
						<div>
							<div class="aipk-box">
								<h2><?php esc_html_e( 'AI badge on the image', 'ai-act-image-marking' ); ?></h2>
								<label class="aipk-check aipk-switch"><input type="checkbox" name="aipk_settings[badge_enabled]" value="1" <?php checked( $o['badge_enabled'] ); ?> data-preview="badge"> <?php esc_html_e( 'Overlay a round, semi-transparent badge on AI generated images', 'ai-act-image-marking' ); ?></label>
								<div class="aipk-fields">
									<label><span><?php esc_html_e( 'Text', 'ai-act-image-marking' ); ?></span><input type="text" name="aipk_settings[badge_text]" value="<?php echo esc_attr( $o['badge_text'] ); ?>" maxlength="12" data-preview="text"></label>
									<label><span><?php esc_html_e( 'Position', 'ai-act-image-marking' ); ?></span><select name="aipk_settings[badge_position]" data-preview="position">
										<?php foreach ( AIPK_Options::positions() as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $o['badge_position'], $value ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select></label>
									<label><span><?php esc_html_e( 'Diameter (px)', 'ai-act-image-marking' ); ?></span><input type="number" name="aipk_settings[badge_size]" value="<?php echo (int) $o['badge_size']; ?>" min="12" max="120" data-preview="size"></label>
									<label><span><?php esc_html_e( 'Hide under (px wide)', 'ai-act-image-marking' ); ?></span><input type="number" name="aipk_settings[badge_min_width]" value="<?php echo (int) $o['badge_min_width']; ?>" min="0"></label>
									<label><span><?php esc_html_e( 'Background', 'ai-act-image-marking' ); ?></span><input type="color" name="aipk_settings[badge_bg]" value="<?php echo esc_attr( $o['badge_bg'] ); ?>" data-preview="bg"></label>
									<label><span><?php esc_html_e( 'Opacity (%)', 'ai-act-image-marking' ); ?></span><input type="number" name="aipk_settings[badge_opacity]" value="<?php echo (int) $o['badge_opacity']; ?>" min="0" max="100" data-preview="opacity"></label>
									<label><span><?php esc_html_e( 'Text color', 'ai-act-image-marking' ); ?></span><input type="color" name="aipk_settings[badge_color]" value="<?php echo esc_attr( $o['badge_color'] ); ?>" data-preview="color"></label>
									<label class="aipk-field-wide"><span><?php esc_html_e( 'Tooltip and screen reader text', 'ai-act-image-marking' ); ?></span><input type="text" name="aipk_settings[badge_title]" value="<?php echo esc_attr( $o['badge_title'] ); ?>"></label>
									<div class="aipk-field-wide aipk-image-pick">
										<span><?php esc_html_e( 'Badge image instead of text', 'ai-act-image-marking' ); ?></span>
										<input type="hidden" name="aipk_settings[badge_image]" id="aipk-badge-image" value="<?php echo (int) $o['badge_image']; ?>" data-preview="image" data-url="<?php echo esc_url( $badge_img ); ?>">
										<button type="button" class="button" id="aipk-badge-image-choose"><?php esc_html_e( 'Choose image', 'ai-act-image-marking' ); ?></button>
										<button type="button" class="button-link" id="aipk-badge-image-clear"><?php esc_html_e( 'Remove', 'ai-act-image-marking' ); ?></button>
										<span id="aipk-badge-image-preview"><?php if ( $badge_img ) : ?><img src="<?php echo esc_url( $badge_img ); ?>" alt=""><?php endif; ?></span>
									</div>
								</div>
							</div>

							<div class="aipk-box">
								<h2><?php esc_html_e( 'Popup on the badge', 'ai-act-image-marking' ); ?></h2>
								<label class="aipk-check aipk-switch"><input type="checkbox" name="aipk_settings[popup_enabled]" value="1" <?php checked( $o['popup_enabled'] ); ?> data-preview="popup"> <?php esc_html_e( 'A click on the badge opens a panel with the provenance of the image', 'ai-act-image-marking' ); ?></label>
								<div class="aipk-fields">
									<label class="aipk-field-wide"><span><?php esc_html_e( 'Panel title', 'ai-act-image-marking' ); ?></span><input type="text" name="aipk_settings[popup_title]" value="<?php echo esc_attr( $o['popup_title'] ); ?>" data-preview="popupTitle"></label>
								</div>
								<label class="aipk-check"><input type="checkbox" name="aipk_settings[credit_link]" value="1" <?php checked( $o['credit_link'] ); ?> data-preview="credit"> <?php esc_html_e( 'Show a credit line in the panel: "Marked with AI Act Image Marking by The Rope", linking to therope.it', 'ai-act-image-marking' ); ?></label>
								<p class="description"><?php esc_html_e( 'The panel is plain HTML kept hidden until the badge is clicked, readable by search engines and assistive technology. The credit line is your choice and is off by default.', 'ai-act-image-marking' ); ?></p>
							</div>

							<div class="aipk-box">
								<h2><?php esc_html_e( 'Text, notice, data', 'ai-act-image-marking' ); ?></h2>
								<label class="aipk-check aipk-switch"><input type="checkbox" name="aipk_settings[frontend_label]" value="1" <?php checked( $o['frontend_label'] ); ?> data-preview="label"> <?php esc_html_e( 'Print a short text under AI generated images', 'ai-act-image-marking' ); ?></label>
								<div class="aipk-fields"><label class="aipk-field-wide"><span><?php esc_html_e( 'Text', 'ai-act-image-marking' ); ?></span><input type="text" name="aipk_settings[label_text]" value="<?php echo esc_attr( $o['label_text'] ); ?>" data-preview="labelText"></label></div>
								<p class="description"><?php esc_html_e( 'Also used as disclosure for images classified by hand that carry none.', 'ai-act-image-marking' ); ?></p>
								<label class="aipk-check aipk-switch"><input type="checkbox" name="aipk_settings[footer_notice]" value="1" <?php checked( $o['footer_notice'] ); ?>> <?php esc_html_e( 'Print a one-line transparency notice at the end of every page', 'ai-act-image-marking' ); ?></label>
								<div class="aipk-fields"><label class="aipk-field-wide"><span><?php esc_html_e( 'Notice', 'ai-act-image-marking' ); ?></span><input type="text" name="aipk_settings[notice_text]" value="<?php echo esc_attr( $o['notice_text'] ); ?>"></label></div>
								<p class="description"><?php esc_html_e( 'The same text is the default of the [ai_act_disclosure] shortcode and the "AI disclosure notice" block, which also show the number of AI images in the library.', 'ai-act-image-marking' ); ?></p>
								<label class="aipk-check"><input type="checkbox" name="aipk_settings[frontend_attrs]" value="1" <?php checked( $o['frontend_attrs'] ); ?>> <?php esc_html_e( 'Add data-digital-source-type and data-ai-generated to image tags', 'ai-act-image-marking' ); ?></label>
								<label class="aipk-check"><input type="checkbox" name="aipk_settings[schema_enabled]" value="1" <?php checked( $o['schema_enabled'] ); ?>> <?php esc_html_e( 'Print schema.org ImageObject data with digitalSourceType for the AI images on the page', 'ai-act-image-marking' ); ?></label>
							</div>

							<div class="aipk-box">
								<h2><?php esc_html_e( 'Custom CSS', 'ai-act-image-marking' ); ?></h2>
								<textarea name="aipk_settings[custom_css]" rows="6" class="large-text code" placeholder=".aipk-ai-badge { border: 1px solid #fff; }" data-preview="css"><?php echo esc_textarea( $o['custom_css'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Printed after the plugin styles. Selectors: .aipk-wrap, .aipk-ai-badge, .aipk-pos-bottom-right (and the other corners), .aipk-popup, .aipk-label, .aipk-site-notice, .aipk-disclosure, img[data-ai-generated].', 'ai-act-image-marking' ); ?></p>
							</div>
						</div>

						<aside class="aipk-preview-col">
							<div class="aipk-box aipk-preview-box">
								<h2><?php esc_html_e( 'Live preview', 'ai-act-image-marking' ); ?></h2>
								<div class="aipk-preview" id="aipk-preview">
									<div class="aipk-preview-stage">
										<div class="aipk-preview-img" aria-hidden="true"></div>
										<button type="button" class="aipk-ai-badge aipk-pos-bottom-right" id="aipk-preview-badge" aria-expanded="false"><?php echo esc_html( $o['badge_text'] ); ?></button>
										<div class="aipk-popup" id="aipk-preview-popup" hidden>
											<div class="aipk-popup-head"><span class="aipk-popup-title"><?php echo esc_html( $o['popup_title'] ); ?></span><button type="button" class="aipk-popup-close" aria-label="<?php esc_attr_e( 'Close', 'ai-act-image-marking' ); ?>">&times;</button></div>
											<dl class="aipk-popup-rows">
												<dt><?php esc_html_e( 'Type', 'ai-act-image-marking' ); ?></dt><dd><?php esc_html_e( 'AI generated', 'ai-act-image-marking' ); ?></dd>
												<dt><?php esc_html_e( 'Disclosure', 'ai-act-image-marking' ); ?></dt><dd class="aipk-preview-disclosure"><?php echo esc_html( $o['label_text'] ); ?></dd>
												<dt><?php esc_html_e( 'Marking', 'ai-act-image-marking' ); ?></dt><dd>IPTC trainedAlgorithmicMedia</dd>
											</dl>
											<p class="aipk-popup-note"><?php esc_html_e( 'Marked under EU Regulation 2024/1689 (AI Act), article 50.', 'ai-act-image-marking' ); ?></p>
											<p class="aipk-popup-credit" id="aipk-preview-credit"<?php echo $o['credit_link'] ? '' : ' hidden'; ?>>Marked with AI Act Image Marking by <a href="https://therope.it" rel="noopener" tabindex="-1">The Rope</a></p>
										</div>
									</div>
									<p class="aipk-label" id="aipk-preview-label"<?php echo $o['frontend_label'] ? '' : ' hidden'; ?>><?php echo esc_html( $o['label_text'] ); ?></p>
								</div>
								<p class="description"><?php esc_html_e( 'Updates as you type. Click the badge to see the popup.', 'ai-act-image-marking' ); ?></p>
								<style id="aipk-preview-css"></style>
							</div>
						</aside>
					</div>
				</section>

				<section class="aipk-tab" data-tab="environment">
					<div class="aipk-box">
						<h2><?php esc_html_e( 'Environment', 'ai-act-image-marking' ); ?></h2>
						<ul class="aipk-env">
							<?php foreach ( $env as $row ) : ?>
								<li class="aipk-env-<?php echo esc_attr( $row[0] ); ?>"><span class="aipk-dot" aria-hidden="true"></span><strong><?php echo esc_html( $row[1] ); ?></strong><span><?php echo esc_html( $row[2] ); ?></span></li>
							<?php endforeach; ?>
						</ul>
					</div>
					<div class="aipk-box">
						<h2><?php esc_html_e( 'Tools', 'ai-act-image-marking' ); ?></h2>
						<p><?php esc_html_e( 'Shortcode:', 'ai-act-image-marking' ); ?> <code>[ai_act_disclosure]</code> · <?php esc_html_e( 'Block:', 'ai-act-image-marking' ); ?> <?php esc_html_e( 'AI disclosure notice', 'ai-act-image-marking' ); ?> · WP-CLI: <code>wp ai-provenance scan</code> · REST: <code>_aipk_provenance</code></p>
						<p><a class="button-link aipk-danger" id="aipk-reset" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aipk_reset' ), 'aipk_reset' ) ); ?>"><?php esc_html_e( 'Reset all settings to defaults', 'ai-act-image-marking' ); ?></a></p>
					</div>
				</section>

				<div class="aipk-footer"><?php submit_button( null, 'primary', 'submit', false ); ?> <span class="aipk-muted"><?php esc_html_e( 'Settings apply to every page immediately. Files already marked stay marked.', 'ai-act-image-marking' ); ?></span></div>
			</form>
		</div>
		<?php
	}

	/* =============================================================== AJAX */

	/**
	 * Re-scan one attachment.
	 */
	public static function ajax_rescan() {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_ajax_referer( 'aipk_panel_' . $id, 'nonce' );
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'ai-act-image-marking' ) ), 403 );
		}
		AIPK_Processor::process( $id );
		wp_send_json_success( array( 'html' => self::panel( $id ) ) );
	}

	/**
	 * Classify one attachment by hand.
	 */
	public static function ajax_classify() {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_ajax_referer( 'aipk_panel_' . $id, 'nonce' );
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'ai-act-image-marking' ) ), 403 );
		}
		$value = isset( $_POST['value'] ) ? sanitize_key( wp_unslash( $_POST['value'] ) ) : '';
		AIPK_Processor::classify( $id, $value );
		if ( isset( $_POST['disclose'] ) ) {
			AIPK_Processor::set_disclose( $id, sanitize_key( wp_unslash( $_POST['disclose'] ) ) );
			self::refresh_forced_flag();
		}
		wp_send_json_success( array( 'html' => self::panel( $id ) ) );
	}

	/**
	 * Per-media disclosure choice.
	 */
	public static function ajax_disclose() {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_ajax_referer( 'aipk_panel_' . $id, 'nonce' );
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'ai-act-image-marking' ) ), 403 );
		}
		$value = isset( $_POST['value'] ) ? sanitize_key( wp_unslash( $_POST['value'] ) ) : '';
		AIPK_Processor::set_disclose( $id, $value );
		self::refresh_forced_flag();
		wp_send_json_success( array( 'html' => self::panel( $id ) ) );
	}

	/**
	 * Batch scan of the library.
	 */
	public static function ajax_scan() {
		check_ajax_referer( 'aipk_scan', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'ai-act-image-marking' ) ), 403 );
		}
		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$all    = ! empty( $_POST['all'] );
		$batch  = 100; // candidates per request; the time budget decides how many get done.
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
			$args['offset'] = 0;
		}
		$q     = new WP_Query( $args );
		$total = (int) $q->found_posts + ( $all ? 0 : $offset );
		$res   = AIPK_Processor::run_batch( $q->posts, AIPK_Processor::time_budget( 12 ) );
		wp_send_json_success(
			array(
				'processed' => $res['done'],
				'ai'        => $res['ai'],
				'next'      => $offset + $res['done'],
				'total'     => $total,
				'finished'  => count( $q->posts ) < $batch && $res['done'] >= count( $q->posts ),
			)
		);
	}

	/**
	 * Start or stop the background scan.
	 */
	public static function bg_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'ai-act-image-marking' ) );
		}
		check_admin_referer( 'aipk_bg' );
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		if ( 'stop' === $do ) {
			AIPK_Processor::bg_stop();
		} else {
			AIPK_Processor::bg_start( 'start_all' === $do );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Reset settings.
	 */
	public static function reset_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'ai-act-image-marking' ) );
		}
		check_admin_referer( 'aipk_reset' );
		update_option( AIPK_Options::OPTION, AIPK_Options::defaults() );
		wp_safe_redirect( self::url( array( 'aipk_reset' => 1 ) ) );
		exit;
	}

	/**
	 * CSV export of AI images.
	 */
	public static function export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'ai-act-image-marking' ) );
		}
		check_admin_referer( 'aipk_export' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ai-images-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'id', 'file', 'url', 'digital_source_type', 'source', 'badge', 'description', 'creator', 'credit', 'rights', 'generators', 'signatures', 'c2pa_in_original', 'sizes', 'scanned_at' ) );
		foreach ( AIPK_Processor::ai_ids() as $id ) {
			$r     = AIPK_Processor::record( $id );
			$sizes = get_post_meta( $id, AIPK_Processor::META_SIZES, true );
			$s     = array();
			if ( is_array( $sizes ) ) {
				foreach ( $sizes as $k => $v ) {
					if ( '_file' !== $k ) {
						$s[] = $k . '=' . $v;
					}
				}
			}
			$d = AIPK_Processor::disclose( $id );
			fputcsv(
				$out,
				array(
					$id,
					basename( (string) get_attached_file( $id ) ),
					wp_get_attachment_url( $id ),
					$r ? $r['digital_source_type'] : '',
					$r ? $r['source'] : '',
					$d ? $d : 'default',
					$r ? $r['description'] : '',
					$r ? $r['creator'] : '',
					$r ? $r['credit'] : '',
					$r ? $r['rights'] : '',
					$r ? implode( '; ', $r['generators'] ) : '',
					$r ? implode( '; ', $r['signatures'] ) : '',
					$r && $r['has_c2pa'] ? 'yes' : 'no',
					implode( '; ', $s ),
					$r && ! empty( $r['scanned_at'] ) ? gmdate( 'c', $r['scanned_at'] ) : '',
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
