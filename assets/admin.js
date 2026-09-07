/* global AIPK, jQuery, wp */
( function ( $ ) {
	'use strict';

	/* ---------- attachment panel ---------- */
	function panelPost( $btn, action, extra ) {
		var $panel = $btn.closest( '.aipk-panel' ),
			data = $.extend( { action: action, id: $panel.data( 'id' ), nonce: $panel.data( 'nonce' ) }, extra || {} );
		$panel.find( 'button, select' ).prop( 'disabled', true );
		$.post( AIPK.ajax, data )
			.done( function ( res ) {
				if ( res && res.success ) {
					$panel.replaceWith( res.data.html );
				} else {
					$panel.find( 'button, select' ).prop( 'disabled', false );
					window.alert( ( res && res.data && res.data.message ) || AIPK.i18n.error );
				}
			} )
			.fail( function () {
				$panel.find( 'button, select' ).prop( 'disabled', false );
				window.alert( AIPK.i18n.error );
			} );
	}
	$( document ).on( 'click', '.aipk-rescan', function () {
		panelPost( $( this ), 'aipk_rescan' );
	} );
	$( document ).on( 'click', '.aipk-classify', function () {
		panelPost( $( this ), 'aipk_classify', { value: $( this ).data( 'value' ) } );
	} );
	$( document ).on( 'click', '.aipk-classify-apply', function () {
		var $p = $( this ).closest( '.aipk-panel' );
		panelPost( $( this ), 'aipk_classify', { value: $p.find( '.aipk-classify-select' ).val(), disclose: $p.find( '.aipk-disclose-select' ).val() } );
	} );

	/* ---------- settings: tabs ---------- */
	var $tabs = $( '.aipk-tabs .nav-tab' );
	if ( $tabs.length ) {
		function showTab( id ) {
			if ( ! $( '.aipk-tab[data-tab="' + id + '"]' ).length ) {
				id = $tabs.first().data( 'tab' );
			}
			$tabs.removeClass( 'nav-tab-active' ).filter( '[data-tab="' + id + '"]' ).addClass( 'nav-tab-active' );
			$( '.aipk-tab' ).removeClass( 'is-active' ).filter( '[data-tab="' + id + '"]' ).addClass( 'is-active' );
			try { window.localStorage.setItem( 'aipk-tab', id ); } catch ( e ) {}
		}
		$tabs.on( 'click', function ( e ) {
			e.preventDefault();
			showTab( $( this ).data( 'tab' ) );
			if ( window.history && window.history.replaceState ) {
				window.history.replaceState( null, '', '#' + $( this ).data( 'tab' ) );
			}
		} );
		$( window ).on( 'hashchange', function () {
			showTab( ( window.location.hash || '' ).replace( '#', '' ) );
		} );
		var initial = ( window.location.hash || '' ).replace( '#', '' );
		if ( ! initial ) {
			try { initial = window.localStorage.getItem( 'aipk-tab' ) || ''; } catch ( e ) {}
		}
		showTab( initial || $tabs.first().data( 'tab' ) );
	}

	/* ---------- settings: library scan with progress ---------- */
	$( document ).on( 'click', '#aipk-scan, #aipk-scan-all', function () {
		var all = $( this ).data( 'all' ) === 1,
			$status = $( '#aipk-scan-status' ),
			$bar = $status.find( '.aipk-progress-bar span' ),
			$text = $status.find( 'p' ),
			$buttons = $( '#aipk-scan, #aipk-scan-all' ),
			done = 0,
			ai = 0;

		$buttons.prop( 'disabled', true );
		$status.prop( 'hidden', false );
		$bar.css( 'width', '2%' );
		$text.text( AIPK.i18n.scanning );

		function step( offset ) {
			$.post( AIPK.ajax, { action: 'aipk_scan', nonce: AIPK.scanNonce, offset: offset, all: all ? 1 : 0 } )
				.done( function ( res ) {
					if ( ! res || ! res.success ) {
						$text.text( AIPK.i18n.error );
						$buttons.prop( 'disabled', false );
						return;
					}
					done += res.data.processed;
					ai += res.data.ai;
					var total = Math.max( res.data.total, done, 1 );
					$bar.css( 'width', Math.min( 100, Math.round( done / total * 100 ) ) + '%' );
					$text.text( AIPK.i18n.progress.replace( '%1$d', done ).replace( '%2$d', total ).replace( '%3$d', ai ) );
					if ( res.data.finished ) {
						$bar.css( 'width', '100%' );
						$text.text( AIPK.i18n.done + ' ' + $text.text() );
						$buttons.prop( 'disabled', false );
						window.setTimeout( function () { window.location.reload(); }, 1200 );
					} else {
						step( all ? res.data.next : 0 );
					}
				} )
				.fail( function () {
					$text.text( AIPK.i18n.error );
					$buttons.prop( 'disabled', false );
				} );
		}
		step( 0 );
	} );

	/* ---------- settings: badge image chooser ---------- */
	$( document ).on( 'click', '#aipk-badge-image-choose', function ( e ) {
		e.preventDefault();
		if ( ! window.wp || ! wp.media ) {
			return;
		}
		var frame = wp.media( { title: AIPK.i18n.choose, button: { text: AIPK.i18n.use }, multiple: false, library: { type: 'image' } } );
		frame.on( 'select', function () {
			var a = frame.state().get( 'selection' ).first().toJSON(),
				url = ( a.sizes && a.sizes.thumbnail ) ? a.sizes.thumbnail.url : a.url;
			$( '#aipk-badge-image' ).val( a.id ).attr( 'data-url', url ).trigger( 'change' );
			$( '#aipk-badge-image-preview' ).html( '<img src="' + url + '" alt="">' );
		} );
		frame.open();
	} );
	$( document ).on( 'click', '#aipk-badge-image-clear', function ( e ) {
		e.preventDefault();
		$( '#aipk-badge-image' ).val( 0 ).attr( 'data-url', '' ).trigger( 'change' );
		$( '#aipk-badge-image-preview' ).empty();
	} );

	/* ---------- settings: reset ---------- */
	$( document ).on( 'click', '#aipk-reset', function ( e ) {
		if ( ! window.confirm( AIPK.i18n.reset ) ) {
			e.preventDefault();
		}
	} );

	/* ---------- settings: live preview ---------- */
	var $preview = $( '#aipk-preview' );
	if ( $preview.length ) {
		var $form = $( '#aipk-form' ),
			$badge = $( '#aipk-preview-badge' ),
			$popup = $( '#aipk-preview-popup' ),
			$css = $( '#aipk-preview-css' );

		function field( name ) {
			return $form.find( '[name="aipk_settings[' + name + ']"]' );
		}
		function hexToRgb( hex ) {
			hex = ( hex || '#000000' ).replace( '#', '' );
			if ( hex.length === 3 ) { hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2]; }
			var n = parseInt( hex, 16 );
			return [ ( n >> 16 ) & 255, ( n >> 8 ) & 255, n & 255 ];
		}
		function render() {
			var enabled = field( 'badge_enabled' ).is( ':checked' ),
				size = Math.max( 12, Math.min( 120, parseInt( field( 'badge_size' ).val(), 10 ) || 28 ) ),
				offset = Math.max( 4, Math.round( size / 3 ) ),
				pos = field( 'badge_position' ).val() || 'bottom-right',
				rgb = hexToRgb( field( 'badge_bg' ).val() ),
				opacity = Math.max( 0, Math.min( 100, parseInt( field( 'badge_opacity' ).val(), 10 ) ) ) / 100,
				color = field( 'badge_color' ).val() || '#fff',
				text = field( 'badge_text' ).val() || 'AI',
				imgUrl = $( '#aipk-badge-image' ).attr( 'data-url' ),
				popupOn = field( 'popup_enabled' ).is( ':checked' ),
				css = '';

			$badge.toggle( enabled ).css( {
				width: size, height: size,
				background: 'rgba(' + rgb.join( ',' ) + ',' + opacity + ')',
				color: color,
				fontSize: Math.max( 9, Math.round( size * 0.42 ) ),
				top: '', right: '', bottom: '', left: ''
			} );
			var place = { 'bottom-right': { bottom: offset, right: offset }, 'bottom-left': { bottom: offset, left: offset }, 'top-right': { top: offset, right: offset }, 'top-left': { top: offset, left: offset } };
			$badge.css( place[ pos ] || place['bottom-right'] );
			$badge.attr( 'class', 'aipk-ai-badge aipk-pos-' + pos );
			if ( imgUrl ) {
				$badge.html( '<img src="' + imgUrl + '" alt="">' );
			} else {
				$badge.text( text );
			}
			if ( ! popupOn || ! enabled ) {
				$popup.prop( 'hidden', true );
			}
			$popup.find( '.aipk-popup-title' ).text( field( 'popup_title' ).val() );
			$popup.find( '.aipk-preview-disclosure' ).text( field( 'label_text' ).val() );
			$( '#aipk-preview-credit' ).prop( 'hidden', ! field( 'credit_link' ).is( ':checked' ) );
			$( '#aipk-preview-label' ).prop( 'hidden', ! field( 'frontend_label' ).is( ':checked' ) ).text( field( 'label_text' ).val() );
			$css.text( field( 'custom_css' ).val() || '' );
		}
		$form.on( 'input change', '[data-preview]', render );
		$badge.on( 'click', function () {
			if ( field( 'popup_enabled' ).is( ':checked' ) ) {
				$popup.prop( 'hidden', ! $popup.prop( 'hidden' ) );
			}
		} );
		$popup.on( 'click', '.aipk-popup-close', function () {
			$popup.prop( 'hidden', true );
		} );
		render();
	}

	/* ---------- media grid: badge on tiles and filter ---------- */
	if ( window.wp && wp.media && wp.media.view ) {
		var Attachment = wp.media.view.Attachment,
			render0 = Attachment.prototype.render;

		Attachment.prototype.render = function () {
			render0.apply( this, arguments );
			var a = this.model.get( 'aipk' );
			this.$el.find( '.aipk-badge' ).remove();
			if ( a && a.badge ) {
				this.$el.find( '.thumbnail' ).append(
					$( '<span class="aipk-badge"></span>' ).addClass( 'aipk-badge-' + a.kind ).attr( 'title', a.title ).text( a.badge )
				);
			}
			return this;
		};

		if ( wp.media.view.AttachmentFilters && wp.media.view.AttachmentsBrowser ) {
			wp.media.view.AttachmentFilters.Aipk = wp.media.view.AttachmentFilters.extend( {
				className: 'attachment-filters aipk-filter',
				createFilters: function () {
					var filters = {}, priority = 10;
					$.each( AIPK.filters, function ( value, text ) {
						filters[ value || 'all' ] = { text: text, props: { aipk_filter: value }, priority: priority };
						priority += 10;
					} );
					this.filters = filters;
				}
			} );
			var Browser = wp.media.view.AttachmentsBrowser,
				createToolbar = Browser.prototype.createToolbar;
			Browser.prototype.createToolbar = function () {
				createToolbar.apply( this, arguments );
				if ( this.options.filters === false ) {
					return;
				}
				this.toolbar.set( 'aipkFilter', new wp.media.view.AttachmentFilters.Aipk( { controller: this.controller, model: this.collection.props, priority: -75 } ).render() );
			};
		}
	}
}( jQuery ) );
