( function ( wp ) {
	const el = wp.element.createElement;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, SelectControl } = wp.components;
	const ServerSideRender = wp.serverSideRender;
	const tools = ( window.rbxTools && window.rbxTools.tools ) || [];

	wp.blocks.registerBlockType( 'rbx/calculator', {
		edit( props ) {
			const a = props.attributes;
			const value = a.game && a.tool ? a.game + '|' + a.tool : '';
			const options = [ { label: '—', value: '' } ].concat(
				tools.map( ( t ) => ( { label: t.label + ' (' + t.game + ')', value: t.game + '|' + t.tool } ) )
			);
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: 'Calculator' },
						el( SelectControl, {
							label: 'Tool',
							value,
							options,
							onChange: ( v ) => {
								const [ game, tool ] = v.split( '|' );
								props.setAttributes( { game: game || '', tool: tool || '' } );
							},
							__nextHasNoMarginBottom: true,
						} )
					)
				),
				el( ServerSideRender, { block: 'rbx/calculator', attributes: a } )
			);
		},
		save: () => null,
	} );
} )( window.wp );
