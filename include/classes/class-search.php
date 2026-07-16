<?php
/**
 * Search class for handling search functionality.
 *
 * @package DM_Static_Interactivity
 */

namespace DM_Static_Interactivity;

use DM_Static_Interactivity\Traits\Singleton;

/**
 * Search class for handling search functionality.
 *
 * @since 1.0.0
 */
class Search {
	use Singleton;

	/**
	 * Constructor for the Search class.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'add_search_rewrite_rule' ) );
		add_action( 'save_post', array( $this, 'sync_post_to_supabase' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'delete_post_from_supabase' ), 10, 1 );
		add_action( 'wp_trash_post', array( $this, 'delete_post_from_supabase' ), 10, 1 );
	}

	/**
	 * Add the search rewrite rule. Map /search/ to an empty search query so WordPress loads the search template.
	 * If a user visits /search/?s=hello, WordPress merges it to index.php?s=&s=hello (which is s=hello)
	 *
	 * @return void
	 */
	public function add_search_rewrite_rule() {
		$search_slug = get_option( 'dm_si_search_slug', 'search' );
		add_rewrite_rule( '^' . $search_slug . '/?$', 'index.php?s=', 'top' );
	}

	/**
	 * Sync post data to Supabase when a post is saved or updated.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an existing post being updated.
	 *
	 * @return void
	 */
	public static function sync_post_to_supabase( $post_id, $post, $update ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			if ( $update ) {
				self::delete_post_from_supabase( $post_id );
			}
			return;
		}

		$featured_image_url = get_the_post_thumbnail_url( $post_id, 'full' );
		$permalink          = get_permalink( $post_id );

		$parsed_url         = wp_parse_url( $permalink );
		$relative_permalink = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '/';

		$data = array(
			'post_id'            => $post_id,
			'title'              => $post->post_title,
			'excerpt'            => wp_strip_all_tags( $post->post_excerpt ? $post->post_excerpt : wp_trim_words( $post->post_content, apply_filters( 'excerpt_length', 55 ) ) ),
			'featured_image_url' => $featured_image_url ? $featured_image_url : null,
			'permalink'          => $relative_permalink,
		);

		$response = Supabase_API::request(
			'search_index',
			'POST',
			$data,
			array(
				'Prefer' => 'resolution=merge-duplicates,return=representation',
			)
		);
	}

	/**
	 * Delete post data from Supabase when a post is deleted or trashed.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	public static function delete_post_from_supabase( $post_id ) {
		$endpoint = 'search_index?post_id=eq.' . intval( $post_id );

		$response = Supabase_API::request( $endpoint, 'DELETE' );
	}
}
