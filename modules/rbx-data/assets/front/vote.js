// "Do these numbers match your game? Yes / No": posts the answer to /rbx/v1/vote. No also opens the
// "Report a wrong value" dialog for the same item. Without JS the form posts to admin-post.php.
const shownAt = performance.now();

document.addEventListener( 'submit', async ( ev ) => {
	const form = ev.target.closest( '.rbx-vote' );
	if ( ! form || ! window.fetch ) {
		return;
	}
	ev.preventDefault();
	const answer = ev.submitter ? ev.submitter.value : 'yes';
	const live = form.querySelector( '.rbx-live' );
	const buttons = form.querySelectorAll( 'button' );
	buttons.forEach( ( b ) => ( b.disabled = true ) );
	const body = new FormData( form );
	body.set( 'answer', answer );
	// Time the page was open, from the page's own timer (the device clock can be wrong).
	body.set( 'e', String( Math.round( performance.now() - shownAt ) ) );
	if ( 'no' === answer ) {
		const report = form.parentElement.querySelector( '[data-rbx-report]' );
		if ( report ) {
			report.click();
		}
	}
	try {
		const res = await fetch( form.dataset.endpoint, { method: 'POST', body } );
		const out = await res.json().catch( () => ( {} ) );
		if ( ! res.ok ) {
			throw new Error( out.message || form.dataset.error );
		}
		live.textContent = out.message || form.dataset.thanks;
	} catch ( e ) {
		live.textContent = e.message || form.dataset.error;
		buttons.forEach( ( b ) => ( b.disabled = false ) );
	}
} );
