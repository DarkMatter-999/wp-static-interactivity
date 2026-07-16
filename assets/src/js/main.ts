( function () {
	const search = window.location.search;
	const pathname = window.location.pathname;
	if ( typeof window?.dmSISettings?.search_slug === 'undefined' ) {
		return;
	}

	// Only redirect if we are not already on the /search/ path
	if (
		pathname !== `/${ window?.dmSISettings?.search_slug }/` &&
		pathname !== `/${ window?.dmSISettings?.search_slug }`
	) {
		if (
			search.indexOf( '?s=' ) !== -1 ||
			search.indexOf( '&s=' ) !== -1
		) {
			const urlParams = new URLSearchParams( search );
			const query = urlParams.get( 's' );
			if ( query !== null ) {
				window.location.href =
					`/${ window?.dmSISettings?.search_slug }/?s=` +
					encodeURIComponent( query );
			}
		}
	}
} )();
