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

function testConnection(): void {
	const btn = document.getElementById(
		'dm-si-test-connection-btn'
	) as HTMLButtonElement | null;
	const status = document.getElementById(
		'dm-si-connection-status'
	) as HTMLElement | null;

	if ( ! btn || ! status ) {
		return;
	}

	const nonce = btn.dataset.nonce || '';

	btn.disabled = true;
	status.textContent = __( 'Testing connection…', 'dm-static-interactivity' );
	status.style.color = '';

	const formData = new FormData();
	formData.append( 'action', 'dm_si_test_connection' );
	formData.append( '_wpnonce', nonce );

	fetch( ajaxurl, {
		method: 'POST',
		body: formData,
	} )
		.then( ( res ) => res.json() )
		.then( ( json ) => {
			btn.disabled = false;
			if ( json.success ) {
				status.innerHTML =
					'<span style="color:green">' +
					__( 'Connection successful!', 'dm-static-interactivity' ) +
					'</span>';
				if ( json.data.message ) {
					status.innerHTML +=
						'<br><small>' + json.data.message + '</small>';
				}
			} else {
				status.innerHTML =
					'<span style="color:red">' +
					sprintf(
						/* translators: %s: error message */
						__(
							'Connection failed: %s',
							'dm-static-interactivity'
						),
						json.data ||
							__( 'Unknown error', 'dm-static-interactivity' )
					) +
					'</span>';
			}
		} )
		.catch( ( err ) => {
			btn.disabled = false;
			status.innerHTML =
				'<span style="color:red">' +
				sprintf(
					/* translators: %s: error message */
					__( 'Network error: %s', 'dm-static-interactivity' ),
					err.message
				) +
				'</span>';
		} );
}

function runHealthCheck(): void {
	const btn = document.getElementById(
		'dm-si-health-check-btn'
	) as HTMLButtonElement | null;
	const progress = document.getElementById(
		'dm-si-health-progress'
	) as HTMLProgressElement | null;
	const status = document.getElementById(
		'dm-si-health-status'
	) as HTMLElement | null;

	if ( ! btn || ! progress || ! status ) {
		return;
	}

	const nonce = btn.dataset.nonce || '';

	btn.disabled = true;
	progress.style.display = 'block';
	progress.value = 0;
	status.textContent = __(
		'Running health check…',
		'dm-static-interactivity'
	);
	status.style.color = '';

	const formData = new FormData();
	formData.append( 'action', 'dm_si_health_check' );
	formData.append( '_wpnonce', nonce );

	fetch( ajaxurl, {
		method: 'POST',
		body: formData,
	} )
		.then( ( res ) => res.json() )
		.then( ( json ) => {
			btn.disabled = false;
			progress.value = 100;

			if ( json.success ) {
				const d = json.data;
				const wpTotal = d.wp_count;
				const sbTotal = d.supabase_count;
				let color = 'green';
				let message = sprintf(
					/* translators: %1$d: WP count, %2$d: Supabase count */
					__(
						'WordPress: %1$d posts | Supabase: %2$d rows',
						'dm-static-interactivity'
					),
					wpTotal,
					sbTotal
				);

				if ( d.difference > 0 ) {
					color = 'orange';
					message +=
						'<br>' +
						sprintf(
							/* translators: %d: difference count */
							_n(
								'%d post is missing from the search index.',
								'%d posts are missing from the search index.',
								d.difference,
								'dm-static-interactivity'
							),
							d.difference
						);
				} else if ( d.difference < 0 ) {
					color = 'orange';
					message +=
						'<br>' +
						sprintf(
							/* translators: %d: count */
							__(
								'Supabase has %d more rows than WordPress.',
								'dm-static-interactivity'
							),
							Math.abs( d.difference )
						);
				} else {
					message +=
						'<br>' +
						__(
							'The search index is healthy.',
							'dm-static-interactivity'
						);
				}

				status.innerHTML =
					'<span style="color:' + color + '">' + message + '</span>';
			} else {
				progress.style.display = 'none';
				status.innerHTML =
					'<span style="color:red">' +
					sprintf(
						/* translators: %s: error message */
						__(
							'Health check failed: %s',
							'dm-static-interactivity'
						),
						json.data ||
							__( 'Unknown error', 'dm-static-interactivity' )
					) +
					'</span>';
			}
		} )
		.catch( ( err ) => {
			btn.disabled = false;
			progress.style.display = 'none';
			status.innerHTML =
				'<span style="color:red">' +
				sprintf(
					/* translators: %s: error message */
					__( 'Network error: %s', 'dm-static-interactivity' ),
					err.message
				) +
				'</span>';
		} );
}

document.addEventListener( 'DOMContentLoaded', () => {
	const replaceBtn = document.getElementById(
		'dm-si-replace-btn'
	) as HTMLButtonElement | null;
	const replaceProgress = document.getElementById(
		'dm-si-progress'
	) as HTMLProgressElement | null;
	const replaceStatus = document.getElementById(
		'dm-si-status'
	) as HTMLElement | null;

	if ( replaceBtn && replaceProgress && replaceStatus ) {
		replaceBtn.addEventListener( 'click', () => {
			replaceBtn.disabled = true;
			replaceProgress.style.display = 'block';
			replaceProgress.value = 0;
			replaceStatus.textContent = __(
				'Starting…',
				'dm-static-interactivity'
			);
			processChunk( 0 );
		} );
	}

	const testBtn = document.getElementById(
		'dm-si-test-connection-btn'
	) as HTMLButtonElement | null;

	if ( testBtn ) {
		testBtn.addEventListener( 'click', testConnection );
	}

	const healthBtn = document.getElementById(
		'dm-si-health-check-btn'
	) as HTMLButtonElement | null;

	if ( healthBtn ) {
		healthBtn.addEventListener( 'click', runHealthCheck );
	}
} );
