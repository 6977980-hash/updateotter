// Evidence screenshots open in a <dialog>; without JS they open the image file.
if ( typeof HTMLDialogElement === 'function' ) {
	let dlg;
	document.addEventListener( 'click', ( ev ) => {
		const a = ev.target.closest( '[data-rbx-lightbox]' );
		if ( ! a ) {
			return;
		}
		ev.preventDefault();
		if ( ! dlg ) {
			dlg = document.createElement( 'dialog' );
			dlg.className = 'rbx-dialog rbx-lightbox';
			dlg.innerHTML = '<form method="dialog"><button class="rbx-dialog__close" aria-label="Close">×</button></form><figure><img alt=""><figcaption></figcaption></figure>';
			dlg.addEventListener( 'click', ( e ) => e.target === dlg && dlg.close() );
			document.body.appendChild( dlg );
		}
		const img = dlg.querySelector( 'img' );
		img.src = a.href;
		img.alt = a.dataset.alt || '';
		dlg.querySelector( 'figcaption' ).textContent = a.dataset.credit || '';
		dlg.showModal();
	} );
}
