import { __, _n, sprintf } from '@wordpress/i18n';

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
				status.textContent = sprintf(
					/* translators: %s: error message */
					__( 'Error: %s', 'dm-static-interactivity' ),
					json.data ||
						__( 'Unknown error', 'dm-static-interactivity' )
				);
				return;
			}

			const d = json.data;
			const done = offset + d.count;
			const total = d.total;

			if ( total > 0 ) {
				progress.value = ( done / total ) * 100;
			}

			if ( d.more ) {
				status.textContent = sprintf(
					/* translators: %1$d: number of items done, %2$d: total items */
					__( 'Syncing %1$d / %2$d…', 'dm-static-interactivity' ),
					done,
					total
				);
				processChunk( offset + d.count );
			} else {
				btn.disabled = false;
				status.textContent = sprintf(
					/* translators: %d: number of posts synced */
					_n(
						'Done — %d post synced.',
						'Done — %d posts synced.',
						total,
						'dm-static-interactivity'
					),
					total
				);
			}
		} )
		.catch( ( err ) => {
			btn.disabled = false;
			progress.value = 0;
			status.textContent = sprintf(
				/* translators: %s: error message */
				__( 'Error: %s', 'dm-static-interactivity' ),
				err.message
			);
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
		status.textContent = __( 'Starting…', 'dm-static-interactivity' );
		processChunk( 0 );
	} );
} );
