/* global wp */
( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	const el = element.createElement;
	const __ = i18n.__;
	const data = window.rbxDataEditor || { games: [], sections: [] };

	blocks.registerBlockType( 'rbx/data-section', {
		edit: function ( props ) {
			const a = props.attributes;
			const game = data.games.find( ( g ) => g.key === a.game );
			const entities = game ? game.entities : [];
			return el(
				element.Fragment,
				null,
				el(
					blockEditor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Data section', 'rbx-data' ) },
						el( components.SelectControl, {
							label: __( 'Game', 'rbx-data' ),
							value: a.game,
							options: [ { label: '—', value: '' } ].concat( data.games.map( ( g ) => ( { label: g.name, value: g.key } ) ) ),
							onChange: ( v ) => props.setAttributes( { game: v } ),
						} ),
						el( components.SelectControl, {
							label: __( 'Section', 'rbx-data' ),
							value: a.section,
							options: [ { label: '—', value: '' } ].concat( data.sections.map( ( s ) => ( { label: s, value: s } ) ) ),
							onChange: ( v ) => props.setAttributes( { section: v } ),
						} ),
						( a.section === 'list' || a.section === 'count' ) &&
							el( components.SelectControl, {
								label: __( 'Type', 'rbx-data' ),
								value: a.entity,
								options: [ { label: '—', value: '' } ].concat( entities.map( ( e ) => ( { label: e.label, value: e.code } ) ) ),
								onChange: ( v ) => props.setAttributes( { entity: v } ),
							} ),
						a.section === 'top_tier' &&
							el( components.RangeControl, {
								label: __( 'How many', 'rbx-data' ),
								value: a.limit,
								min: 1,
								max: 10,
								onChange: ( v ) => props.setAttributes( { limit: v } ),
							} )
					)
				),
				el(
					'div',
					blockEditor.useBlockProps(),
					a.section
						? el( serverSideRender, { block: 'rbx/data-section', attributes: a } )
						: el( components.Placeholder, { label: __( 'Game data section', 'rbx-data' ), instructions: __( 'Pick a game and a section in the sidebar.', 'rbx-data' ) } )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( wp.blocks, wp.element, wp.blockEditor, wp.components, wp.serverSideRender, wp.i18n );
