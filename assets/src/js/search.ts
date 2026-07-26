import { __, sprintf } from '@wordpress/i18n';

interface SearchResult {
	post_id: number;
	title: string;
	excerpt: string;
	featured_image_url: string | null;
	permalink: string;
}

class SearchIsland extends HTMLElement {
	private template: HTMLTemplateElement | null;
	private resultsContainer: HTMLElement;
	private config: Record< string, string >;
	private currentPage = 0;
	private perPage: number;
	private totalResults = 0;
	private currentQuery = '';
	private currentTerms: string[] = [];

	constructor() {
		super();
		this.template = this.querySelector( 'template' );
		this.resultsContainer = this.querySelector(
			'.supabase-search-results'
		) as HTMLElement;
		this.config = ( window as any ).dmSISettings || {};
		this.perPage = parseInt( this.config.per_page, 10 ) || 10;
	}

	async connectedCallback() {
		if ( ! this.config.supabase_url || ! this.config.supabase_anon_key ) {
			this.resultsContainer.innerHTML = `<div class="wp-block-group has-text-align-center" style="padding:2rem 0"><p>${ __(
				'Search is not configured.',
				'dm-static-interactivity'
			) }</p></div>`;
			return;
		}

		const urlParams = new URLSearchParams( window.location.search );
		const query = urlParams.get( 's' ) || urlParams.get( 'q' );

		if ( ! query ) {
			this.resultsContainer.innerHTML = `<div class="wp-block-group has-text-align-center" style="padding:2rem 0"><p>${ __(
				'Please enter a search term.',
				'dm-static-interactivity'
			) }</p></div>`;
			return;
		}

		const searchInput =
			document.querySelector< HTMLInputElement >( 'input[name="s"]' );
		if ( searchInput ) {
			searchInput.value = query;
		}

		const queryTitle = document.querySelector( '.wp-block-query-title' );
		if ( queryTitle ) {
			queryTitle.textContent = sprintf(
				/* translators: %s: the search query. */
				__( 'Search results for: "%s"', 'dm-static-interactivity' ),
				query
			);
		}

		this.currentQuery = query;
		this.currentTerms = query
			.replace( /[^\p{L}\p{N}\s]/gu, '' )
			.trim()
			.split( /\s+/ )
			.filter( Boolean );

		this.resultsContainer.innerHTML = `<div class="wp-block-group has-text-align-center" style="padding:2rem 0"><p>${ __(
			'Searching…',
			'dm-static-interactivity'
		) }</p></div>`;

		await this.fetchPage( 0 );
	}

	private async fetchPage( page: number ) {
		this.currentPage = page;

		try {
			let endpoint: string;

			if ( this.currentTerms.length > 0 ) {
				const ftsQuery = this.currentTerms
					.map( ( t ) => t + ':*' )
					.join( ' & ' );
				endpoint = `${
					this.config.supabase_url
				}/rest/v1/search_index?select=*&search_vector=fts(simple).${ encodeURIComponent(
					ftsQuery
				) }`;
			} else {
				endpoint = `${
					this.config.supabase_url
				}/rest/v1/search_index?select=*&search_vector=wfts(simple).${ encodeURIComponent(
					this.currentQuery
				) }`;
			}

			const start = page * this.perPage;
			const end = start + this.perPage - 1;

			const response = await fetch( endpoint, {
				headers: {
					apikey: this.config.supabase_anon_key,
					Authorization: `Bearer ${ this.config.supabase_anon_key }`,
					'Range-Unit': 'items',
					Range: `${ start }-${ end }`,
					Prefer: 'count=exact',
				},
			} );

			if ( ! response.ok ) {
				throw new Error(
					__( 'Search failed', 'dm-static-interactivity' )
				);
			}

			const contentRange = response.headers.get( 'Content-Range' );
			if ( contentRange ) {
				const match = contentRange.match( /\/(\d+)$/ );
				if ( match ) {
					this.totalResults = parseInt( match[ 1 ], 10 );
				}
			}

			const results: SearchResult[] = await response.json();
			await this.renderResults( results );
		} catch ( error ) {
			this.resultsContainer.innerHTML = `<div class="wp-block-group has-text-align-center" style="padding:2rem 0"><p>${ __(
				'An error occurred while searching.',
				'dm-static-interactivity'
			) }</p></div>`;
		}
	}

	private async fetchSuggestions(): Promise< SearchResult[] > {
		try {
			const endpoint = `${ this.config.supabase_url }/rest/v1/search_index?select=*&order=post_id.desc&limit=3`;
			const response = await fetch( endpoint, {
				headers: {
					apikey: this.config.supabase_anon_key,
					Authorization: `Bearer ${ this.config.supabase_anon_key }`,
				},
			} );
			if ( response.ok ) {
				return await response.json();
			}
		} catch ( e ) {
			// Silently fail.
		}
		return [];
	}

	private async renderResults( results: SearchResult[] ) {
		this.resultsContainer.innerHTML = '';

		if ( results.length === 0 ) {
			await this.renderNoResults();
			return;
		}

		const wrapper = this.template.content.cloneNode( true );
		const root = wrapper.children[ 0 ] as HTMLElement;
		if ( ! root ) {
			this.resultsContainer.innerHTML = `<div class="wp-block-group has-text-align-center" style="padding:2rem 0"><p>${ __(
				'An error occurred while searching.',
				'dm-static-interactivity'
			) }</p></div>`;
			return;
		}
		const postList =
			root.querySelector( '.wp-block-post-template' ) ||
			root.children[ 0 ];
		if ( ! postList ) {
			this.resultsContainer.innerHTML = `<div class="wp-block-group has-text-align-center" style="padding:2rem 0"><p>${ __(
				'An error occurred while searching.',
				'dm-static-interactivity'
			) }</p></div>`;
			return;
		}
		const itemTemplate =
			postList.querySelector( '.wp-block-post' ) ||
			( postList.children[ 0 ] as HTMLElement );
		if ( ! itemTemplate ) {
			this.resultsContainer.innerHTML = `<div class="wp-block-group has-text-align-center" style="padding:2rem 0"><p>${ __(
				'An error occurred while searching.',
				'dm-static-interactivity'
			) }</p></div>`;
			return;
		}
		postList.innerHTML = '';

		this.renderPostItems( results, itemTemplate, postList );

		this.resultsContainer.appendChild( wrapper );

		this.renderPagination();
	}

	private renderPostItems(
		items: SearchResult[],
		itemTemplate: Element,
		postList: Element
	) {
		items.forEach( ( result ) => {
			const clone = itemTemplate.cloneNode( true ) as HTMLElement;

			const titleLinks = clone.querySelectorAll( 'h2 a, h3 a' );
			titleLinks.forEach( ( a ) => {
				( a as HTMLAnchorElement ).href = result.permalink;
				( a as HTMLAnchorElement ).textContent = result.title;
			} );

			const excerpts = clone.querySelectorAll(
				'.wp-block-post-excerpt__excerpt'
			);
			excerpts.forEach( ( p ) => {
				p.textContent = result.excerpt || '';
			} );

			const figures = clone.querySelectorAll(
				'.wp-block-post-featured-image'
			);
			figures.forEach( ( figure ) => {
				if ( result.featured_image_url ) {
					const img = figure.querySelector( 'img' );
					if ( img ) {
						img.src = result.featured_image_url;
						img.srcset = '';
						img.style.display = '';
						figure.style.display = '';
					}
				} else {
					figure.remove();
				}
			} );

			postList.appendChild( clone );
		} );
	}

	private async renderNoResults() {
		this.resultsContainer.innerHTML = `<div class="wp-block-group has-text-align-center" style="padding:2rem 0"><p>${ __(
			'No results found.',
			'dm-static-interactivity'
		) }</p></div>`;

		if ( this.config.enable_suggestions !== false ) {
			const suggestions = await this.fetchSuggestions();
			if ( suggestions.length > 0 ) {
				this.renderSuggestionResults( suggestions );
			}
		}
	}

	private renderSuggestionResults( suggestions: SearchResult[] ) {
		const wrapper = this.template.content.cloneNode( true );
		const root = wrapper.children[ 0 ] as HTMLElement;
		if ( ! root ) {
			return;
		}
		const postList =
			root.querySelector( '.wp-block-post-template' ) ||
			root.children[ 0 ];
		if ( ! postList ) {
			return;
		}
		const itemTemplate =
			postList.querySelector( '.wp-block-post' ) ||
			( postList.children[ 0 ] as HTMLElement );
		if ( ! itemTemplate ) {
			return;
		}
		postList.innerHTML = '';

		this.renderPostItems( suggestions, itemTemplate, postList );

		this.resultsContainer.appendChild( wrapper );
	}

	private renderPagination() {
		const totalPages = Math.ceil( this.totalResults / this.perPage );
		if ( totalPages <= 1 ) {
			return;
		}

		const nav = document.createElement( 'nav' );
		nav.className = 'wp-block-group supabase-pagination';
		nav.style.cssText =
			'display:flex;justify-content:space-between;align-items:center;margin-top:1rem';

		const prevBtn = document.createElement( 'button' );
		prevBtn.className = 'wp-element-button';
		prevBtn.textContent = __( 'Previous', 'dm-static-interactivity' );
		prevBtn.disabled = this.currentPage === 0;
		prevBtn.addEventListener( 'click', () => {
			if ( this.currentPage > 0 ) {
				this.resultsContainer.innerHTML = `<div class="wp-block-group has-text-align-center" style="padding:2rem 0"><p>${ __(
					'Searching…',
					'dm-static-interactivity'
				) }</p></div>`;
				this.fetchPage( this.currentPage - 1 );
				this.scrollIntoView( { behavior: 'smooth' } );
			}
		} );

		const pageInfo = document.createElement( 'span' );
		pageInfo.textContent = sprintf(
			/* translators: %1$d: current page number, %2$d: total pages */
			__( 'Page %1$d of %2$d', 'dm-static-interactivity' ),
			this.currentPage + 1,
			totalPages
		);

		const nextBtn = document.createElement( 'button' );
		nextBtn.className = 'wp-element-button';
		nextBtn.textContent = __( 'Next', 'dm-static-interactivity' );
		nextBtn.disabled = this.currentPage >= totalPages - 1;
		nextBtn.addEventListener( 'click', () => {
			if ( this.currentPage < totalPages - 1 ) {
				this.resultsContainer.innerHTML = `<div class="wp-block-group has-text-align-center" style="padding:2rem 0"><p>${ __(
					'Searching…',
					'dm-static-interactivity'
				) }</p></div>`;
				this.fetchPage( this.currentPage + 1 );
				this.scrollIntoView( { behavior: 'smooth' } );
			}
		} );

		nav.appendChild( prevBtn );
		nav.appendChild( pageInfo );
		nav.appendChild( nextBtn );
		this.resultsContainer.appendChild( nav );
	}
}

customElements.define( 'dmsi-search', SearchIsland );
