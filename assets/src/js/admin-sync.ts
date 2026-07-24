function processChunk( offset: number ): void {
	const btn = document.getElementById(
		'dm-si-replace-btn'
	) as HTMLButtonElement | null;
	const progress = document.getElementById(
		'dm-si-progress'
	) as HTMLProgressElement | null;
	const status = document.getElementById(
		'dm-si-status'
	) as HTMLElement | null;

	if ( ! btn || ! progress || ! status ) {
		return;
	}

	const nonce = btn.dataset.nonce || '';

	const formData = new FormData();
	formData.append( 'action', 'dm_si_replace_index' );
	formData.append( '_wpnonce', nonce );
	formData.append( 'offset', String( offset ) );

	fetch( ajaxurl, {
		method: 'POST',
		body: formData,
	} )
		.then( ( res ) => res.json() )
		.then( ( json ) => {
			if ( ! json.success ) {
				btn.disabled = false;
				progress.value = 0;
				status.textContent =
					'Error: ' + ( json.data || 'Unknown error' );
				return;
			}

			const d = json.data;
			const done = offset + d.count;
			const total = d.total;

			if ( total > 0 ) {
				progress.value = ( done / total ) * 100;
			}

			if ( d.more ) {
				status.textContent = `Syncing ${ done } / ${ total }…`;
				processChunk( offset + d.count );
			} else {
				btn.disabled = false;
				status.textContent = `Done — ${ total } posts synced.`;
			}
		} )
		.catch( ( err ) => {
			btn.disabled = false;
			progress.value = 0;
			status.textContent = 'Error: ' + err.message;
		} );
}

document.addEventListener( 'DOMContentLoaded', () => {
	const btn = document.getElementById(
		'dm-si-replace-btn'
	) as HTMLButtonElement | null;
	const progress = document.getElementById(
		'dm-si-progress'
	) as HTMLProgressElement | null;
	const status = document.getElementById(
		'dm-si-status'
	) as HTMLElement | null;

	if ( ! btn || ! progress || ! status ) {
		return;
	}

	btn.addEventListener( 'click', () => {
		btn.disabled = true;
		progress.style.display = 'block';
		progress.value = 0;
		status.textContent = 'Starting…';
		processChunk( 0 );
	} );
} );
