class SearchIsland extends HTMLElement {
	constructor() {
		super();
		this.template = this.querySelector( 'template' );
		this.resultsContainer = this.querySelector(
			'.supabase-search-results'
		);
		this.config = window.dmSISettings || {};
	}

	async connectedCallback() {
		if ( ! this.config.supabase_url || ! this.config.supabase_anon_key ) {
			this.resultsContainer.innerHTML =
				'<div class="wp-block-group has-text-align-center" style="padding:2rem 0"><p>Search is not configured.</p></div>';
			return;
		}

		const urlParams = new URLSearchParams( window.location.search );
		const query = urlParams.get( 's' ) || urlParams.get( 'q' );

		if ( ! query ) {
			this.resultsContainer.innerHTML =
				'<div class="wp-block-group has-text-align-center" style="padding:2rem 0"><p>Please enter a search term.</p></div>';
			return;
		}

		this.resultsContainer.innerHTML =
			'<div class="wp-block-group has-text-align-center" style="padding:2rem 0"><p>Searching...</p></div>';

		try {
			const terms = query
				.replace( /[^\w\s]/g, '' )
				.trim()
				.split( /\s+/ )
				.filter( Boolean );
			let endpoint;

			if ( terms.length > 0 ) {
				const ftsQuery = terms.map( ( t ) => t + ':*' ).join( ' & ' );
				endpoint = `${
					this.config.supabase_url
				}/rest/v1/search_index?select=*&search_vector=fts(english).${ encodeURIComponent(
					ftsQuery
				) }`;
			} else {
				endpoint = `${
					this.config.supabase_url
				}/rest/v1/search_index?select=*&search_vector=wfts(english).${ encodeURIComponent(
					query
				) }`;
			}

			const response = await fetch( endpoint, {
				headers: {
					apikey: this.config.supabase_anon_key,
					Authorization: `Bearer ${ this.config.supabase_anon_key }`,
				},
			} );

			if ( ! response.ok ) {
				throw new Error( 'Search failed' );
			}

			const results = await response.json();
			this.renderResults( results );
		} catch ( error ) {
			this.resultsContainer.innerHTML =
				'<div class="wp-block-group has-text-align-center" style="padding:2rem 0"><p>An error occurred while searching.</p></div>';
		}
	}

	renderResults( results ) {
		this.resultsContainer.innerHTML = '';

		if ( results.length === 0 ) {
			this.resultsContainer.innerHTML =
				'<div class="wp-block-group has-text-align-center" style="padding:2rem 0"><p>No results found.</p></div>';
			return;
		}

		const wrapper = this.template.content.cloneNode( true );
		const root = wrapper.children[ 0 ];
		const postList =
			root.querySelector( '.wp-block-post-template' ) ||
			root.children[ 0 ];
		const itemTemplate =
			postList.querySelector( '.wp-block-post' ) ||
			postList.children[ 0 ];
		postList.innerHTML = '';

		results.forEach( ( result ) => {
			const clone = itemTemplate.cloneNode( true );

			const titleLinks = clone.querySelectorAll( 'h2 a, h3 a' );
			titleLinks.forEach( ( a ) => {
				a.href = result.permalink;
				a.innerHTML = result.title;
			} );

			const excerpts = clone.querySelectorAll(
				'.wp-block-post-excerpt__excerpt'
			);
			excerpts.forEach( ( p ) => {
				p.innerHTML = result.excerpt || '';
			} );

			const images = clone.querySelectorAll(
				'.wp-block-post-featured-image img'
			);
			images.forEach( ( img ) => {
				if ( result.featured_image_url ) {
					img.src = result.featured_image_url;
					img.srcset = '';
				} else {
					img.style.display = 'none';
				}
			} );

			postList.appendChild( clone );
		} );

		this.resultsContainer.appendChild( wrapper );
	}
}

customElements.define( 'dmsi-search', SearchIsland );
