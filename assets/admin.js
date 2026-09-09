/* global Ropemark, jQuery, wp */
( function ( $ ) {
	'use strict';

	/* ---------- attachment panel ---------- */
	function panelPost( $btn, action, extra ) {
		var $panel = $btn.closest( '.ropemark-panel' ),
			data = $.extend( { action: action, id: $panel.data( 'id' ), nonce: $panel.data( 'nonce' ) }, extra || {} );
		$panel.find( 'button, select' ).prop( 'disabled', true );
		$.post( Ropemark.ajax, data )
			.done( function ( res ) {
				if ( res && res.success ) {
					$panel.replaceWith( res.data.html );
				} else {
					$panel.find( 'button, select' ).prop( 'disabled', false );
					window.alert( ( res && res.data && res.data.message ) || Ropemark.i18n.error );
				}
			} )
			.fail( function () {
				$panel.find( 'button, select' ).prop( 'disabled', false );
				window.alert( Ropemark.i18n.error );
			} );
	}
	$( document ).on( 'click', '.ropemark-rescan', function () {
		panelPost( $( this ), 'ropemark_rescan' );
	} );
	$( document ).on( 'click', '.ropemark-classify', function () {
		panelPost( $( this ), 'ropemark_classify', { value: $( this ).data( 'value' ) } );
	} );
	$( document ).on( 'click', '.ropemark-classify-apply', function () {
		var $p = $( this ).closest( '.ropemark-panel' );
		panelPost( $( this ), 'ropemark_classify', { value: $p.find( '.ropemark-classify-select' ).val(), disclose: $p.find( '.ropemark-disclose-select' ).val() } );
	} );

	/* ---------- settings: tabs ---------- */
	var $tabs = $( '.ropemark-tabs .nav-tab' );
	if ( $tabs.length ) {
		function showTab( id ) {
			if ( ! $( '.ropemark-tab[data-tab="' + id + '"]' ).length ) {
				id = $tabs.first().data( 'tab' );
			}
			$tabs.removeClass( 'nav-tab-active' ).filter( '[data-tab="' + id + '"]' ).addClass( 'nav-tab-active' );
			$( '.ropemark-tab' ).removeClass( 'is-active' ).filter( '[data-tab="' + id + '"]' ).addClass( 'is-active' );
			try { window.localStorage.setItem( 'ropemark-tab', id ); } catch ( e ) {}
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
			try { initial = window.localStorage.getItem( 'ropemark-tab' ) || ''; } catch ( e ) {}
		}
		showTab( initial || $tabs.first().data( 'tab' ) );
	}

	/* ---------- settings: library scan with progress ---------- */
	$( document ).on( 'click', '#ropemark-scan, #ropemark-scan-all', function () {
		var all = $( this ).data( 'all' ) === 1,
			$status = $( '#ropemark-scan-status' ),
			$bar = $status.find( '.ropemark-progress-bar span' ),
			$text = $status.find( 'p' ),
			$buttons = $( '#ropemark-scan, #ropemark-scan-all' ),
			done = 0,
			ai = 0;

		$buttons.prop( 'disabled', true );
		$status.prop( 'hidden', false );
		$bar.css( 'width', '2%' );
		$text.text( Ropemark.i18n.scanning );

		function step( offset ) {
			$.post( Ropemark.ajax, { action: 'ropemark_scan', nonce: Ropemark.scanNonce, offset: offset, all: all ? 1 : 0 } )
				.done( function ( res ) {
					if ( ! res || ! res.success ) {
						$text.text( Ropemark.i18n.error );
						$buttons.prop( 'disabled', false );
						return;
					}
					done += res.data.processed;
					ai += res.data.ai;
					var total = Math.max( res.data.total, done, 1 );
					$bar.css( 'width', Math.min( 100, Math.round( done / total * 100 ) ) + '%' );
					$text.text( Ropemark.i18n.progress.replace( '%1$d', done ).replace( '%2$d', total ).replace( '%3$d', ai ) );
					if ( res.data.finished ) {
						$bar.css( 'width', '100%' );
						$text.text( Ropemark.i18n.done + ' ' + $text.text() );
						$buttons.prop( 'disabled', false );
						window.setTimeout( function () { window.location.reload(); }, 1200 );
					} else {
						step( all ? res.data.next : 0 );
					}
				} )
				.fail( function () {
					$text.text( Ropemark.i18n.error );
					$buttons.prop( 'disabled', false );
				} );
		}
		step( 0 );
	} );

	/* ---------- settings: badge image chooser ---------- */
	$( document ).on( 'click', '#ropemark-badge-image-choose', function ( e ) {
		e.preventDefault();
		if ( ! window.wp || ! wp.media ) {
			return;
		}
		var frame = wp.media( { title: Ropemark.i18n.choose, button: { text: Ropemark.i18n.use }, multiple: false, library: { type: 'image' } } );
		frame.on( 'select', function () {
			var a = frame.state().get( 'selection' ).first().toJSON(),
				url = ( a.sizes && a.sizes.thumbnail ) ? a.sizes.thumbnail.url : a.url;
			$( '#ropemark-badge-image' ).val( a.id ).attr( 'data-url', url ).trigger( 'change' );
			$( '#ropemark-badge-image-preview' ).html( '<img src="' + url + '" alt="">' );
		} );
		frame.open();
	} );
	$( document ).on( 'click', '#ropemark-badge-image-clear', function ( e ) {
		e.preventDefault();
		$( '#ropemark-badge-image' ).val( 0 ).attr( 'data-url', '' ).trigger( 'change' );
		$( '#ropemark-badge-image-preview' ).empty();
	} );

	/* ---------- settings: reset ---------- */
	$( document ).on( 'click', '#ropemark-reset', function ( e ) {
		if ( ! window.confirm( Ropemark.i18n.reset ) ) {
			e.preventDefault();
		}
	} );

	/* ---------- settings: live preview ---------- */
	var $preview = $( '#ropemark-preview' );
	if ( $preview.length ) {
		var $form = $( '#ropemark-form' ),
			$badge = $( '#ropemark-preview-badge' ),
			$popup = $( '#ropemark-preview-popup' );

		function field( name ) {
			return $form.find( '[name="ropemark_settings[' + name + ']"]' );
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
				imgUrl = $( '#ropemark-badge-image' ).attr( 'data-url' ),
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
			$badge.attr( 'class', 'ropemark-ai-badge ropemark-pos-' + pos );
			if ( imgUrl ) {
				$badge.html( '<img src="' + imgUrl + '" alt="">' );
			} else {
				$badge.text( text );
			}
			if ( ! popupOn || ! enabled ) {
				$popup.prop( 'hidden', true );
			}
			$popup.find( '.ropemark-popup-title' ).text( field( 'popup_title' ).val() );
			$popup.find( '.ropemark-preview-disclosure' ).text( field( 'label_text' ).val() );
			$( '#ropemark-preview-credit' ).prop( 'hidden', ! field( 'credit_link' ).is( ':checked' ) );
			$( '#ropemark-preview-label' ).prop( 'hidden', ! field( 'frontend_label' ).is( ':checked' ) ).text( field( 'label_text' ).val() );
		}
		$form.on( 'input change', '[data-preview]', render );
		$badge.on( 'click', function () {
			if ( field( 'popup_enabled' ).is( ':checked' ) ) {
				$popup.prop( 'hidden', ! $popup.prop( 'hidden' ) );
			}
		} );
		$popup.on( 'click', '.ropemark-popup-close', function () {
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
			var a = this.model.get( 'ropemark' );
			this.$el.find( '.ropemark-badge' ).remove();
			if ( a && a.badge ) {
				this.$el.find( '.thumbnail' ).append(
					$( '<span class="ropemark-badge"></span>' ).addClass( 'ropemark-badge-' + a.kind ).attr( 'title', a.title ).text( a.badge )
				);
			}
			return this;
		};

		if ( wp.media.view.AttachmentFilters && wp.media.view.AttachmentsBrowser ) {
			wp.media.view.AttachmentFilters.Ropemark = wp.media.view.AttachmentFilters.extend( {
				className: 'attachment-filters ropemark-filter',
				createFilters: function () {
					var filters = {}, priority = 10;
					$.each( Ropemark.filters, function ( value, text ) {
						filters[ value || 'all' ] = { text: text, props: { ropemark_filter: value }, priority: priority };
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
				this.toolbar.set( 'ropemarkFilter', new wp.media.view.AttachmentFilters.Ropemark( { controller: this.controller, model: this.collection.props, priority: -75 } ).render() );
			};
		}
	}
}( jQuery ) );
