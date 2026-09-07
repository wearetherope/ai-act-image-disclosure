/* global wp */
( function () {
	'use strict';
	if ( ! wp || ! wp.blocks ) {
		return;
	}
	var el = wp.element.createElement,
		__ = wp.i18n.__,
		InspectorControls = wp.blockEditor.InspectorControls,
		PanelBody = wp.components.PanelBody,
		TextareaControl = wp.components.TextareaControl,
		ToggleControl = wp.components.ToggleControl,
		ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'aipk/disclosure', {
		title: __( 'AI disclosure notice', 'ai-act-image-disclosure' ),
		description: __( 'A transparency notice about AI-generated images on this site, with an optional count.', 'ai-act-image-disclosure' ),
		icon: 'visibility',
		category: 'widgets',
		attributes: {
			text: { type: 'string', default: '' },
			count: { type: 'boolean', default: true }
		},
		edit: function ( props ) {
			var a = props.attributes;
			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Notice', 'ai-act-image-disclosure' ) },
						el( TextareaControl, {
							label: __( 'Text (empty = plugin setting)', 'ai-act-image-disclosure' ),
							value: a.text,
							onChange: function ( v ) { props.setAttributes( { text: v } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Show the number of AI images', 'ai-act-image-disclosure' ),
							checked: a.count,
							onChange: function ( v ) { props.setAttributes( { count: v } ); }
						} )
					)
				),
				ServerSideRender
					? el( ServerSideRender, { block: 'aipk/disclosure', attributes: a } )
					: el( 'p', null, a.text || __( 'AI disclosure notice', 'ai-act-image-disclosure' ) )
			);
		},
		save: function () {
			return null;
		}
	} );
}() );
