// "Report a wrong value": opens a <dialog> filled with the clicked value's context and posts to /rbx/v1/report.
// Without JS (or <dialog>), the button is a normal link to /report/.
const tpl = document.getElementById( 'rbx-report-tpl' );
const i18nEl = document.getElementById( 'rbx-report-i18n' );
const i18n = i18nEl ? JSON.parse( i18nEl.textContent ) : {};

if ( tpl && typeof HTMLDialogElement === 'function' ) {
	document.body.appendChild( tpl.content.cloneNode( true ) );
	const dlg = document.querySelector( '.rbx-dialog' );
	const form = dlg.querySelector( '.rbx-report-form' );
	const live = form.querySelector( '.rbx-live' );
	const submit = form.querySelector( 'button[type="submit"]' );
	let opener = null;

	document.addEventListener( 'click', ( ev ) => {
		const btn = ev.target.closest( '[data-rbx-report]' );
		if ( ! btn ) {
			return;
		}
		ev.preventDefault();
		opener = btn;
		form.reset();
		live.textContent = '';
		submit.disabled = false;
		const set = ( n, v ) => {
			let el = form.elements[ n ];
			if ( ! el ) {
				el = document.createElement( 'input' );
				el.type = 'hidden';
				el.name = n;
				form.appendChild( el );
			}
			el.value = v || '';
		};
		set( 'game', btn.dataset.game );
		set( 'record_id', btn.dataset.record );
		set( 'field', btn.dataset.field );
		set( 'value', btn.dataset.value );
		set( 'dataset_version', btn.dataset.version );
		set( 'page_url', window.location.href.split( '#' )[ 0 ] );
		set( 't', String( Date.now() ) );
		dlg.showModal();
	} );

	dlg.addEventListener( 'close', () => opener && opener.focus() );

	form.addEventListener( 'submit', async ( ev ) => {
		ev.preventDefault();
		submit.disabled = true;
		try {
			const res = await fetch( form.dataset.endpoint, { method: 'POST', body: new FormData( form ) } );
			const body = await res.json().catch( () => ( {} ) );
			if ( ! res.ok ) {
				throw new Error( body.message || i18n.error );
			}
			live.textContent = i18n.thanks;
			setTimeout( () => dlg.open && dlg.close(), 2500 );
		} catch ( e ) {
			live.textContent = e.message || i18n.error;
			submit.disabled = false;
		}
	} );
}
