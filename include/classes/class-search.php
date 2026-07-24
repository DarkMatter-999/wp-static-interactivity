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
		add_action( 'render_block', array( $this, 'override_search_loop' ), 10, 2 );
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

	/**
	 * Override the search query block on search results pages.
	 *
	 * Replaces the default query block with a custom element that fetches results
	 * from Supabase and renders them using the theme's template structure.
	 *
	 * @param string $block_content The block content.
	 * @param array  $block         The block data.
	 *
	 * @return string
	 */
	public static function override_search_loop( $block_content, $block ) {
		if ( 'core/search' === $block['blockName'] ) {
			$home_url      = home_url( '/' );
			$search_slug   = get_option( 'dm_si_search_slug', 'search' );
			$search_url    = home_url( "/$search_slug/" );
			$block_content = str_replace( 'action="' . esc_url( $home_url ) . '"', 'action="' . esc_url( $search_url ) . '"', $block_content );
			$block_content = str_replace( 'action="/"', 'action="/' . $search_slug . '/"', $block_content );
		}

		if ( 'core/query' === $block['blockName'] && is_search() ) {

			$is_main_query = false;
			if ( isset( $block['attrs']['query']['inherit'] ) && $block['attrs']['query']['inherit'] ) {
				$is_main_query = true;
			} elseif ( isset( $block['attrs']['namespace'] ) && 'core/search' === $block['attrs']['namespace'] ) {
				$is_main_query = true;
			}

			if ( $is_main_query ) {
				$saved_posts      = $GLOBALS['wp_query']->posts;
				$saved_post_count = $GLOBALS['wp_query']->post_count;
				$saved_max_pages  = $GLOBALS['wp_query']->max_num_pages;

				$GLOBALS['wp_query']->posts         = array_slice( $saved_posts, 0, 1 );
				$GLOBALS['wp_query']->post_count    = count( $GLOBALS['wp_query']->posts );
				$GLOBALS['wp_query']->max_num_pages = 1;

				$search_instance = self::get_instance();
				remove_action( 'render_block', array( $search_instance, 'override_search_loop' ), 10 );
				$query_block   = new \WP_Block( $block );
				$template_html = $query_block->render();
				add_action( 'render_block', array( $search_instance, 'override_search_loop' ), 10, 2 );

				$GLOBALS['wp_query']->posts         = $saved_posts;
				$GLOBALS['wp_query']->post_count    = $saved_post_count;
				$GLOBALS['wp_query']->max_num_pages = $saved_max_pages;

				ob_start();
				?>
				<dmsi-search>
					<template>
						<?php echo $template_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML rendered by WP_Block::render() which escapes its own output. ?>
					</template>
					<div class="supabase-search-results wp-block-query alignwide">
						<div class="wp-block-group has-text-align-center" style="padding:2rem 0"><p>Searching...</p></div>
					</div>
				</dmsi-search>
				<?php
				return ob_get_clean();
			}
		}

		return $block_content;
	}
}
