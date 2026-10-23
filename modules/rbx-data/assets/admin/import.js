/* RBX Data admin: batched apply, rollback, view filters. No build step. */
( function () {
	'use strict';
	const cfg = window.rbxAdmin || {};
	const t = cfg.i18n || {};
	const fmt = ( s, ...a ) => a.reduce( ( o, v, i ) => o.replace( '%' + ( i + 1 ) + '$s', v ), s || '' );

	async function call( path, nonce ) {
		const res = await fetch( cfg.rest + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce, 'X-RBX-Run-Nonce': nonce },
		} );
		const body = await res.json().catch( () => ( {} ) );
		if ( ! res.ok ) {
			throw new Error( body.message || res.status );
		}
		return body;
	}

	document.querySelectorAll( '.rbx-apply' ).forEach( ( box ) => {
		const btn = box.querySelector( '[data-rbx-apply]' );
		const bar = box.querySelector( 'progress' );
		const live = box.querySelector( '.rbx-live' );
		btn.addEventListener( 'click', async () => {
			btn.disabled = true;
			try {
				for ( ;; ) {
					const r = await call( 'import/runs/' + box.dataset.run + '/apply-batch', box.dataset.nonce );
					bar.max = r.batches || 1;
					bar.value = r.batch || 0;
					live.textContent = fmt( t.applying, r.batch, r.batches );
					if ( r.done ) {
						live.textContent = t.done;
						setTimeout( () => window.location.reload(), 1200 );
						return;
					}
				}
			} catch ( e ) {
				live.textContent = ( t.failed || '' ) + ' ' + e.message;
				btn.disabled = false;
			}
		} );
	} );

	document.querySelectorAll( '.rbx-rollback' ).forEach( ( btn ) => {
		btn.addEventListener( 'click', async () => {
			// eslint-disable-next-line no-alert -- admin-only confirmation.
			if ( ! window.confirm( t.confirm ) ) {
				return;
			}
			const live = document.getElementById( 'rbx-rollback-status' );
			btn.disabled = true;
			try {
				const r = await call( 'import/runs/' + btn.dataset.run + '/rollback', btn.dataset.nonce );
				const u = new URL( window.location.href );
				u.searchParams.set( 'run', r.run_id );
				window.location.href = u.toString();
			} catch ( e ) {
				if ( live ) {
					live.textContent = ( t.failed || '' ) + ' ' + e.message;
				}
				btn.disabled = false;
			}
		} );
	} );

	const only = ( sel, rows, keep ) => {
		const box = document.querySelector( sel );
		if ( box ) {
			box.addEventListener( 'change', () => {
				document.querySelectorAll( rows ).forEach( ( r ) => {
					r.hidden = box.checked && ! r.matches( keep );
				} );
			} );
		}
	};
	only( '[data-rbx-only-errors]', '.rbx-issue', '.rbx-issue--error' );
	only( '[data-rbx-only-large]', '.rbx-op', '.rbx-op--large' );
} )();
