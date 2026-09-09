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

	wp.blocks.registerBlockType( 'ropemark/disclosure', {
		title: __( 'AI disclosure notice', 'ropemark-image-marking-for-eu-ai-act' ),
		description: __( 'A transparency notice about AI-generated images on this site, with an optional count.', 'ropemark-image-marking-for-eu-ai-act' ),
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
						{ title: __( 'Notice', 'ropemark-image-marking-for-eu-ai-act' ) },
						el( TextareaControl, {
							label: __( 'Text (empty = plugin setting)', 'ropemark-image-marking-for-eu-ai-act' ),
							value: a.text,
							onChange: function ( v ) { props.setAttributes( { text: v } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Show the number of AI images', 'ropemark-image-marking-for-eu-ai-act' ),
							checked: a.count,
							onChange: function ( v ) { props.setAttributes( { count: v } ); }
						} )
					)
				),
				ServerSideRender
					? el( ServerSideRender, { block: 'ropemark/disclosure', attributes: a } )
					: el( 'p', null, a.text || __( 'AI disclosure notice', 'ropemark-image-marking-for-eu-ai-act' ) )
			);
		},
		save: function () {
			return null;
		}
	} );
}() );
