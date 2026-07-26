<?php
/**
 * Syncs published posts/pages to a Supabase search_index table and serves
 * search results from Supabase on the front end.
 *
 * @package DM_Static_Interactivity
 */

namespace DM_Static_Interactivity;

use DM_Static_Interactivity\Traits\Singleton;

/**
 * Search class.
 *
 * @since 1.0.0
 */
class Search {
	use Singleton;

	/**
	 * Post types that get synced to the Supabase search index.
	 *
	 * @var string[]
	 */
	const SYNCED_POST_TYPES = array( 'post', 'page' );

	/**
	 * Constructor for the Search class.
	 *
	 * @return void
	 */
	private function __construct() {
		add_action( 'save_post', array( $this, 'schedule_post_sync' ), 10, 2 );
		add_action( 'dm_si_sync_post_to_supabase', array( $this, 'sync_post_to_supabase' ) );
		add_action( 'before_delete_post', array( $this, 'delete_post_from_supabase' ), 10, 1 );
		add_action( 'wp_trash_post', array( $this, 'delete_post_from_supabase' ), 10, 1 );
		add_action( 'render_block', array( $this, 'override_search_loop' ), 10, 2 );
		add_action( 'wp_ajax_dm_si_replace_index', array( $this, 'ajax_replace_index' ) );
		add_filter( 'template_include', array( $this, 'load_search_template' ) );
	}

	/**
	 * Check if the current page is the designated search page.
	 *
	 * @return bool
	 */
	public static function is_search_page() {
		$search_slug = Options::search_slug();
		if ( ! $search_slug || ! is_page() ) {
			return false;
		}
		$page = get_queried_object();
		return $page instanceof \WP_Post && $page->post_name === $search_slug;
	}

	/**
	 * Override the template for the search page.
	 *
	 * Loads the theme's search template instead of the page template.
	 *
	 * @param string $template The path to the template file.
	 *
	 * @return string
	 */
	public function load_search_template( $template ) {
		if ( ! self::is_search_page() ) {
			return $template;
		}

		global $wp_query;
		$wp_query->is_search   = true;
		$wp_query->is_page     = false;
		$wp_query->is_singular = false;

		return get_search_template();
	}

	/**
	 * Schedule an async Supabase sync for a saved post instead of doing it
	 * inline on save_post, so a slow/unreachable Supabase never holds up
	 * saving content in wp-admin.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 *
	 * @return void
	 */
	public function schedule_post_sync( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, self::SYNCED_POST_TYPES, true ) ) {
			return;
		}

		if ( ! wp_next_scheduled( 'dm_si_sync_post_to_supabase', array( $post_id ) ) ) {
			wp_schedule_single_event( time(), 'dm_si_sync_post_to_supabase', array( $post_id ) );
		}
	}

	/**
	 * Sync post data to Supabase. Runs on the dm_si_sync_post_to_supabase
	 * cron event scheduled by schedule_post_sync(), not directly on save_post.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	public function sync_post_to_supabase( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			$this->delete_post_from_supabase( $post_id );
			return;
		}

		$response = Supabase_API::request(
			'search_index',
			'POST',
			self::build_search_index_row( $post ),
			array(
				'Prefer' => 'resolution=merge-duplicates,return=representation',
			)
		);

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional: surface sync failures since this runs in the background with no admin-visible feedback.
			error_log( '[DM Static Interactivity] Failed to sync post ' . $post_id . ' to Supabase: ' . $response->get_error_message() );
		}
	}

	/**
	 * Delete post data from Supabase when a post is deleted or trashed.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	public function delete_post_from_supabase( $post_id ) {
		$endpoint = 'search_index?post_id=eq.' . intval( $post_id );

		Supabase_API::request( $endpoint, 'DELETE' );
	}

	/**
	 * Build the Supabase search_index row for a post.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return array
	 */
	private static function build_search_index_row( $post ) {
		$featured_image_url = get_the_post_thumbnail_url( $post->ID, 'full' );
		$permalink          = get_permalink( $post->ID );
		$parsed_url         = wp_parse_url( $permalink );
		$relative_permalink = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '/';

		$relative_featured_image_url = null;
		if ( $featured_image_url ) {
			$parsed_featured             = wp_parse_url( $featured_image_url );
			$relative_featured_image_url = isset( $parsed_featured['path'] ) ? $parsed_featured['path'] : $featured_image_url;
		}

		return array(
			'post_id'            => $post->ID,
			'title'              => $post->post_title,
			'excerpt'            => wp_strip_all_tags( $post->post_excerpt ? $post->post_excerpt : wp_trim_words( $post->post_content, apply_filters( 'excerpt_length', 55 ) ) ),
			'featured_image_url' => $relative_featured_image_url,
			'permalink'          => $relative_permalink,
		);
	}

	/**
	 * Process a batch for the replace-index AJAX action.
	 *
	 * @return void
	 */
	public function ajax_replace_index() {
		check_ajax_referer( 'dm_si_replace_index' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;

		if ( 0 === $offset ) {
			if ( get_transient( 'dm_si_replace_index_lock' ) ) {
				wp_send_json_error( 'A search index rebuild is already in progress. Please wait for it to finish.' );
			}
			set_transient( 'dm_si_replace_index_lock', true, 5 * MINUTE_IN_SECONDS );

			$delete_result = Supabase_API::request(
				'search_index?post_id=gte.0',
				'DELETE'
			);

			if ( is_wp_error( $delete_result ) ) {
				delete_transient( 'dm_si_replace_index_lock' );
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional: surface sync failures in background context.
				error_log( '[DM Static Interactivity] Failed to clear search index: ' . $delete_result->get_error_message() );
				wp_send_json_error( 'Failed to clear existing search index. Please check your Supabase configuration and try again.' );
			}
		}

		$query = new \WP_Query(
			array(
				'post_type'      => self::SYNCED_POST_TYPES,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);

		$post_ids = $query->posts;
		$total    = (int) $query->found_posts;

		if ( empty( $post_ids ) ) {
			delete_transient( 'dm_si_replace_index_lock' );
			wp_send_json_success(
				array(
					'count' => 0,
					'total' => $total,
					'more'  => false,
				)
			);
		}

		$data = array();
		foreach ( $post_ids as $post_id ) {
			$data[] = self::build_search_index_row( get_post( $post_id ) );
		}

		$response = Supabase_API::request(
			'search_index',
			'POST',
			$data,
			array(
				'Prefer' => 'resolution=merge-duplicates,return=representation',
			)
		);

		$more = count( $post_ids ) >= 50;

		if ( ! $more || is_wp_error( $response ) ) {
			delete_transient( 'dm_si_replace_index_lock' );
		}

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- surface the real error for debugging without exposing infra details to the AJAX response.
			error_log( '[DM Static Interactivity] Search index rebuild failed at offset ' . $offset . ': ' . $response->get_error_message() );
			wp_send_json_error( 'Failed to sync search index. Please check your Supabase configuration and try again.' );
		}

		wp_send_json_success(
			array(
				'count' => count( $post_ids ),
				'total' => $total,
				'more'  => $more,
			)
		);
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
	public function override_search_loop( $block_content, $block ) {
		if ( 'core/search' === $block['blockName'] ) {
			$home_url      = home_url( '/' );
			$search_slug   = Options::search_slug();
			$search_url    = home_url( "/$search_slug/" );
			$block_content = str_replace( 'action="' . esc_url( $home_url ) . '"', 'action="' . esc_url( $search_url ) . '"', $block_content );
			$block_content = str_replace( 'action="/"', 'action="/' . $search_slug . '/"', $block_content );
		}

		if ( 'core/query' === $block['blockName'] && ( is_search() || self::is_search_page() ) ) {

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

				$template_posts = array_slice( $saved_posts, 0, 1 );
				if ( empty( $template_posts ) ) {
					$template_posts = array( self::get_dummy_post() );
				}

				$GLOBALS['wp_query']->posts         = $template_posts;
				$GLOBALS['wp_query']->post_count    = count( $GLOBALS['wp_query']->posts );
				$GLOBALS['wp_query']->max_num_pages = 1;

				remove_action( 'render_block', array( $this, 'override_search_loop' ), 10 );

				try {
					$query_block   = new \WP_Block( $block );
					$template_html = $query_block->render();
				} finally {
					add_action( 'render_block', array( $this, 'override_search_loop' ), 10, 2 );

					$GLOBALS['wp_query']->posts         = $saved_posts;
					$GLOBALS['wp_query']->post_count    = $saved_post_count;
					$GLOBALS['wp_query']->max_num_pages = $saved_max_pages;
				}

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

	/**
	 * Get a dummy WP_Post to use as a template placeholder when no real posts exist.
	 *
	 * @return \WP_Post
	 */
	private static function get_dummy_post() {
		return new \WP_Post(
			(object) array(
				'ID'            => 0,
				'post_title'    => __( 'Search Result', 'dm-static-interactivity' ),
				'post_excerpt'  => '',
				'post_content'  => '',
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => 0,
				'post_date'     => current_time( 'mysql' ),
				'post_date_gmt' => current_time( 'mysql', true ),
				'filter'        => 'raw',
			)
		);
	}
}
