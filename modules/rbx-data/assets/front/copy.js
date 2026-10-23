// Copy buttons for codes. Buttons are hidden until JS (and the Clipboard API) is available.
if ( navigator.clipboard ) {
	document.querySelectorAll( '.rbx-copy[data-copy]' ).forEach( ( btn ) => {
		btn.hidden = false;
		const label = btn.textContent;
		btn.addEventListener( 'click', async () => {
			const status = document.querySelector( '[data-rbx-copy-status]' );
			try {
				await navigator.clipboard.writeText( btn.dataset.copy );
				btn.textContent = '✓';
				if ( status ) {
					status.textContent = btn.dataset.copied || btn.dataset.copy;
				}
				setTimeout( () => ( btn.textContent = label ), 1500 );
			} catch ( e ) {}
		} );
	} );
}
