( function ( wp ) {
	const { registerPlugin } = wp.plugins;
	const PanelSlot = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	const { RadioControl, SelectControl, CheckboxControl } = wp.components;
	const { useSelect, useDispatch } = wp.data;
	const { createElement: el, Fragment } = wp.element;
	const cfg = window.rbxEditor || { i18n: {} };
	const t = cfg.i18n;

	function Panel() {
		const meta = useSelect( ( s ) => s( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {}, [] );
		const { editPost } = useDispatch( 'core/editor' );
		const set = ( k, v ) => editPost( { meta: { [ k ]: v } } );
		const state = meta._rbx_page_state || '';
		let reasons = meta._rbx_gate_reasons || '';
		try {
			const r = JSON.parse( reasons );
			reasons = Array.isArray( r ) ? r.join( ', ' ) : reasons;
		} catch ( e ) {}
		return el(
			PanelSlot,
			{ name: 'rbx-page', title: t.panel, className: 'rbx-page-panel' },
			el( 'p', null, el( 'strong', null, t.state + ': ' ), state || t.none ),
			reasons ? el( 'p', { className: 'rbx-reasons' }, el( 'strong', null, t.reasons + ': ' ), reasons ) : null,
			el( RadioControl, {
				label: t.kind,
				selected: meta._rbx_edit_kind || 'minor',
				options: [
					{ label: t.minor, value: 'minor' },
					{ label: t.substantive, value: 'substantive' },
				],
				onChange: ( v ) => set( '_rbx_edit_kind', v ),
			} ),
			cfg.canIndex
				? el(
						Fragment,
						null,
						el( SelectControl, {
							label: t.override,
							value: meta._rbx_index_override || '',
							options: [
								{ label: t.auto, value: '' },
								{ label: t.noindex, value: 'noindex' },
								{ label: t.index, value: 'index' },
							],
							onChange: ( v ) => set( '_rbx_index_override', v ),
							__nextHasNoMarginBottom: true,
						} ),
						el( CheckboxControl, {
							label: t.manual,
							checked: !! meta._rbx_manual_index_ok,
							onChange: ( v ) => set( '_rbx_manual_index_ok', v ),
							__nextHasNoMarginBottom: true,
						} )
				  )
				: null
		);
	}

	if ( PanelSlot ) {
		registerPlugin( 'rbx-page', { render: Panel, icon: null } );
	}
} )( window.wp );
