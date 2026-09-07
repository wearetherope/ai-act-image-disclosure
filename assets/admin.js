/* global AIPK, jQuery, wp */
( function ( $ ) {
	'use strict';

	/* Re-scan one file from the attachment details panel. */
	$( document ).on( 'click', '.aipk-rescan', function () {
		var $btn = $( this ),
			$panel = $btn.closest( '.aipk-panel' ),
			id = $panel.data( 'id' );
		$btn.prop( 'disabled', true );
		$.post( AIPK.ajax, { action: 'aipk_rescan', id: id, nonce: $btn.data( 'nonce' ) } )
			.done( function ( res ) {
				if ( res && res.success ) {
					$panel.replaceWith( res.data.html );
				} else {
					$btn.prop( 'disabled', false );
					window.alert( AIPK.i18n.error );
				}
			} )
			.fail( function () {
				$btn.prop( 'disabled', false );
				window.alert( AIPK.i18n.error );
			} );
	} );

	/* Library scan on the settings page. */
	$( document ).on( 'click', '#aipk-scan, #aipk-scan-all', function () {
		var all = $( this ).data( 'all' ) === 1,
			$status = $( '#aipk-scan-status' ),
			$buttons = $( '#aipk-scan, #aipk-scan-all' ),
			done = 0,
			ai = 0;

		$buttons.prop( 'disabled', true );
		$status.text( AIPK.i18n.scanning );

		function step( offset ) {
			$.post( AIPK.ajax, { action: 'aipk_scan', nonce: AIPK.scanNonce, offset: offset, all: all ? 1 : 0 } )
				.done( function ( res ) {
					if ( ! res || ! res.success ) {
						$status.text( AIPK.i18n.error );
						$buttons.prop( 'disabled', false );
						return;
					}
					done += res.data.processed;
					ai += res.data.ai;
					$status.text( AIPK.i18n.progress.replace( '%1$d', done ).replace( '%2$d', res.data.total ).replace( '%3$d', ai ) );
					if ( res.data.finished ) {
						$status.text( AIPK.i18n.done + ' ' + $status.text() );
						$buttons.prop( 'disabled', false );
						window.setTimeout( function () { window.location.reload(); }, 1200 );
					} else {
						step( all ? res.data.next : 0 );
					}
				} )
				.fail( function () {
					$status.text( AIPK.i18n.error );
					$buttons.prop( 'disabled', false );
				} );
		}
		step( 0 );
	} );

	/* Media grid: badge on tiles and a provenance filter in the toolbar. */
	if ( window.wp && wp.media && wp.media.view ) {
		var Attachment = wp.media.view.Attachment,
			render = Attachment.prototype.render;

		Attachment.prototype.render = function () {
			render.apply( this, arguments );
			var a = this.model.get( 'aipk' );
			this.$el.find( '.aipk-badge' ).remove();
			if ( a && a.badge ) {
				this.$el.find( '.thumbnail' ).append(
					$( '<span class="aipk-badge"></span>' )
						.addClass( a.ai ? 'aipk-badge-ai' : 'aipk-badge-marked' )
						.attr( 'title', a.title )
						.text( a.badge )
				);
			}
			return this;
		};

		if ( wp.media.view.AttachmentFilters && wp.media.view.AttachmentsBrowser ) {
			wp.media.view.AttachmentFilters.Aipk = wp.media.view.AttachmentFilters.extend( {
				className: 'attachment-filters aipk-filter',
				createFilters: function () {
					var filters = {},
						priority = 10;
					$.each( AIPK.filters, function ( value, text ) {
						filters[ value || 'all' ] = {
							text: text,
							props: { aipk_filter: value },
							priority: priority
						};
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
				this.toolbar.set(
					'aipkFilter',
					new wp.media.view.AttachmentFilters.Aipk( {
						controller: this.controller,
						model: this.collection.props,
						priority: -75
					} ).render()
				);
			};
		}
	}
}( jQuery ) );
