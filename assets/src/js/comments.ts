import { __, _n, sprintf } from '@wordpress/i18n';

interface Comment {
	id: number;
	post_id: number;
	author_name: string;
	author_email: string;
	content: string;
	created_at: string;
	status: string;
}

class CommentsIsland extends HTMLElement {
	private postId: string;
	private template: HTMLTemplateElement | null;
	private wrapper: HTMLElement;
	private config: any;

	constructor() {
		super();
		this.postId = this.getAttribute( 'post-id' ) || '';
		this.template = this.querySelector( 'template' );
		this.wrapper = this.querySelector(
			'.supabase-comments-wrapper'
		) as HTMLElement;
		this.config = ( window as any ).dmSISettings || {};
	}

	async connectedCallback() {
		if (
			! this.config.supabase_url ||
			! this.config.supabase_anon_key ||
			! this.postId
		) {
			return;
		}

		await this.fetchAndRender();
	}

	async fetchAndRender() {
		this.wrapper.innerHTML = `<p>${ __(
			'Loading comments…',
			'dm-static-interactivity'
		) }</p>`;

		try {
			const endpoint = `${ this.config.supabase_url }/rest/v1/comments?post_id=eq.${ this.postId }&status=eq.approved&order=created_at.asc`;
			const response = await fetch( endpoint, {
				headers: {
					apikey: this.config.supabase_anon_key,
					Authorization: `Bearer ${ this.config.supabase_anon_key }`,
				},
			} );

			if ( ! response.ok ) {
				throw new Error(
					__( 'Failed to fetch comments', 'dm-static-interactivity' )
				);
			}

			const comments: Comment[] = await response.json();
			this.render( comments );
		} catch ( error ) {
			this.wrapper.innerHTML = `<p>${ __(
				'Unable to load comments.',
				'dm-static-interactivity'
			) }</p>`;
		}
	}

	async submitComment( e: Event ) {
		e.preventDefault();
		const form = e.target as HTMLFormElement;
		const submitBtn = form.querySelector(
			'#submit, button[type="submit"]'
		) as HTMLButtonElement | HTMLInputElement | null;
		const msgEl = form.querySelector(
			'.comment-submit-msg'
		) as HTMLElement;

		if ( submitBtn ) {
			( submitBtn as HTMLButtonElement ).disabled = true;
			submitBtn.value = __( 'Submitting…', 'dm-static-interactivity' );
		}

		const data = {
			post_id: parseInt( this.postId, 10 ),
			author_name:
				( form.querySelector( '[name="author"]' ) as HTMLInputElement )
					?.value || '',
			author_email:
				( form.querySelector( '[name="email"]' ) as HTMLInputElement )
					?.value || '',
			content:
				(
					form.querySelector(
						'[name="comment"]'
					) as HTMLTextAreaElement
				 )?.value ||
				(
					form.querySelector(
						'[name="content"]'
					) as HTMLTextAreaElement
				 )?.value ||
				'',
			status: 'pending',
		};

		try {
			const response = await fetch(
				`${ this.config.supabase_url }/rest/v1/comments`,
				{
					method: 'POST',
					headers: {
						apikey: this.config.supabase_anon_key,
						Authorization: `Bearer ${ this.config.supabase_anon_key }`,
						'Content-Type': 'application/json',
						Prefer: 'return=minimal',
					},
					body: JSON.stringify( data ),
				}
			);

			if ( ! response.ok ) {
				throw new Error(
					__( 'Failed to submit comment', 'dm-static-interactivity' )
				);
			}

			form.reset();
			if ( msgEl ) {
				msgEl.textContent = __(
					'Your comment has been submitted and is awaiting moderation.',
					'dm-static-interactivity'
				);
				msgEl.className = 'comment-submit-msg comment-submit-success';
			}
		} catch ( error ) {
			if ( msgEl ) {
				msgEl.textContent = __(
					'Failed to submit comment. Please try again.',
					'dm-static-interactivity'
				);
				msgEl.className = 'comment-submit-msg comment-submit-error';
			}
		} finally {
			if ( submitBtn ) {
				( submitBtn as HTMLButtonElement ).disabled = false;
				submitBtn.value = __(
					'Post Comment',
					'dm-static-interactivity'
				);
			}
		}
	}

	async hashEmail( email: string ): Promise< string > {
		if ( ! email || ! window.crypto?.subtle ) {
			return '';
		}
		const encoder = new TextEncoder();
		const data = encoder.encode( email.trim().toLowerCase() );
		const hashBuffer = await crypto.subtle.digest( 'SHA-256', data );
		const hashArray = Array.from( new Uint8Array( hashBuffer ) );
		return hashArray
			.map( ( b ) => b.toString( 16 ).padStart( 2, '0' ) )
			.join( '' );
	}

	escapeHTML( str: string ): string {
		if ( ! str ) {
			return '';
		}
		const div = document.createElement( 'div' );
		div.textContent = str;
		return div.innerHTML;
	}

	async render( comments: Comment[] ) {
		if ( ! this.template ) {
			return;
		}

		const wrapper = this.template.content.cloneNode(
			true
		) as DocumentFragment;
		const commentList = wrapper.querySelector(
			'.wp-block-comment-template'
		) as HTMLElement;
		const itemTemplate = commentList?.children[ 0 ] as HTMLElement;

		const countTitle = wrapper.querySelector( '.wp-block-comments-title' );
		if ( countTitle ) {
			const count = comments.length;
			countTitle.textContent = sprintf(
				/* translators: %d: number of comments */
				_n(
					'%d Comment',
					'%d Comments',
					count,
					'dm-static-interactivity'
				),
				count
			);
		}

		if ( commentList ) {
			commentList.innerHTML = '';

			if ( comments.length > 0 && itemTemplate ) {
				for ( const comment of comments ) {
					const clone = itemTemplate.cloneNode( true ) as HTMLElement;

					const avatar = clone.querySelector(
						'.wp-block-avatar img'
					);
					if ( avatar ) {
						const emailHash = await this.hashEmail(
							comment.author_email
						);
						(
							avatar as HTMLImageElement
						 ).src = `https://www.gravatar.com/avatar/${ emailHash }?d=mp&s=50&r=g`;
						( avatar as HTMLImageElement ).srcset = '';
						( avatar as HTMLImageElement ).alt =
							comment.author_name;
					}

					const authorName = clone.querySelector(
						'.wp-block-comment-author-name'
					);
					if ( authorName ) {
						authorName.textContent = comment.author_name;
					}

					const dateEl = clone.querySelector(
						'.wp-block-comment-date time'
					);
					if ( dateEl ) {
						const date = new Date( comment.created_at );
						const dateStr = date.toLocaleDateString( undefined, {
							year: 'numeric',
							month: 'long',
							day: 'numeric',
						} );
						dateEl.setAttribute( 'datetime', date.toISOString() );
						dateEl.textContent = dateStr;
					}

					const contentEl = clone.querySelector(
						'.wp-block-comment-content'
					);
					if ( contentEl ) {
						contentEl.innerHTML = `<p>${ this.escapeHTML(
							comment.content
						).replace( /\n/g, '<br>' ) }</p>`;
					}

					const editLinks = clone.querySelectorAll(
						'.wp-block-comment-edit-link, .wp-block-comment-reply-link'
					);
					editLinks.forEach(
						( el ) =>
							( ( el as HTMLElement ).style.display = 'none' )
					);

					commentList.appendChild( clone );
				}
			}
		}

		const existingForm = this.wrapper.querySelector( '#respond' );
		if ( existingForm ) {
			existingForm.remove();
		}

		this.wrapper.innerHTML = '';
		this.wrapper.appendChild( wrapper );

		const form = this.wrapper.querySelector(
			'#respond form, .comment-form'
		);
		if ( form ) {
			form.removeAttribute( 'action' );
			const nonceFields = form.querySelectorAll(
				'input[name="_wp_unfiltered_html_comment"], input[name="_ajax_nonce"], input[name="_wp_nonce"]'
			);
			nonceFields.forEach( ( el ) => el.remove() );

			form.addEventListener( 'submit', this.submitComment.bind( this ) );
		}
	}
}

customElements.define( 'dmsi-comments', CommentsIsland );
