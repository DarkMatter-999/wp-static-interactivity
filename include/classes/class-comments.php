<?php
/**
 * Comments class for Supabase-backed comments.
 *
 * @package DM_Static_Interactivity
 */

namespace DM_Static_Interactivity;

use DM_Static_Interactivity\Traits\Singleton;

/**
 * Comments class for Supabase-backed comments.
 *
 * @since 1.0.0
 */
class Comments {
	use Singleton;

	/**
	 * Constructor for the Comments class.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'render_block', array( $this, 'hijack_comments_block' ), 10, 2 );
		add_action( 'render_block', array( $this, 'apply_parent_layout_to_wrapper' ), 20, 2 );
		add_action( 'init', array( $this, 'schedule_cron' ) );
		add_action( 'dm_si_sync_comments', array( $this, 'sync_comments_from_supabase' ) );
		add_action( 'wp_set_comment_status', array( $this, 'push_comment_status_update' ), 10, 2 );
		add_action( 'deleted_comment', array( $this, 'push_comment_deletion' ), 10, 1 );
		add_action( 'trashed_comment', array( $this, 'push_comment_trash' ), 10, 1 );
		add_action( 'wp_ajax_dm_si_sync_all_comments', array( $this, 'ajax_sync_all_comments' ) );
	}

	/**
	 * Schedule the daily cron if not already scheduled.
	 *
	 * @return void
	 */
	public function schedule_cron() {
		if ( ! wp_next_scheduled( 'dm_si_sync_comments' ) ) {
			wp_schedule_event( time(), 'daily', 'dm_si_sync_comments' );
		}
	}

	/**
	 * Sync new comments from Supabase into WordPress.
	 *
	 * @param string|null $since Optional. ISO 8601 timestamp to fetch comments created after. Defaults to stored last_sync.
	 *
	 * @return array
	 */
	public function sync_comments_from_supabase( $since = null ) {
		$last_sync = null !== $since ? $since : get_option( 'dm_si_last_comment_sync', '2000-01-01T00:00:00Z' );

		$endpoint = 'comments?created_at=gt.' . rawurlencode( $last_sync ) . '&order=created_at.asc';
		$comments = Supabase_API::request( $endpoint, 'GET' );

		if ( is_wp_error( $comments ) ) {
			return array(
				'synced'  => 0,
				'skipped' => 0,
				'error'   => $comments->get_error_message(),
			);
		}

		if ( empty( $comments ) ) {
			return array(
				'synced'    => 0,
				'skipped'   => 0,
				'last_sync' => $last_sync,
			);
		}

		$synced           = 0;
		$skipped          = 0;
		$latest_timestamp = $last_sync;

		foreach ( $comments as $comment ) {
			$existing = get_comments(
				array(
					'meta_key'   => 'supabase_comment_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- deliberate meta lookup.
					'meta_value' => $comment['id'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- see above.
					'number'     => 1,
					'count'      => true,
				)
			);

			if ( $existing > 0 ) {
				++$skipped;
			} elseif ( 'trash' === $comment['status'] ) {
				// Never pulled in before and already trashed at the source - nothing to sync.
				++$skipped;
			} else {
				$comment_data = array(
					'comment_post_ID'      => absint( $comment['post_id'] ),
					'comment_author'       => sanitize_text_field( $comment['author_name'] ),
					'comment_author_email' => sanitize_email( $comment['author_email'] ),
					'comment_content'      => sanitize_textarea_field( $comment['content'] ),
					'comment_date_gmt'     => gmdate( 'Y-m-d H:i:s', strtotime( $comment['created_at'] ) ),
					'comment_approved'     => ( 'approved' === $comment['status'] ) ? 1 : 0,
				);

				$new_comment_id = wp_insert_comment( $comment_data );
				if ( $new_comment_id ) {
					add_comment_meta( $new_comment_id, 'supabase_comment_id', $comment['id'], true );
					++$synced;
				}
			}

			if ( strtotime( $comment['created_at'] ) > strtotime( $latest_timestamp ) ) {
				$latest_timestamp = $comment['created_at'];
			}
		}

		if ( null === $since ) {
			update_option( 'dm_si_last_comment_sync', $latest_timestamp );
		}

		return array(
			'synced'    => $synced,
			'skipped'   => $skipped,
			'last_sync' => $latest_timestamp,
		);
	}

	/**
	 * Push comment status changes to Supabase.
	 *
	 * @param int    $comment_id     The comment ID.
	 * @param string $comment_status The new status.
	 *
	 * @return void
	 */
	public function push_comment_status_update( $comment_id, $comment_status ) {
		$supabase_id = get_comment_meta( $comment_id, 'supabase_comment_id', true );
		if ( ! $supabase_id ) {
			return;
		}

		$status_map = array(
			'approve' => 'approved',
			'hold'    => 'pending',
			'spam'    => 'trash',
			'trash'   => 'trash',
		);

		$supabase_status = isset( $status_map[ $comment_status ] ) ? $status_map[ $comment_status ] : 'pending';

		Supabase_API::request(
			'comments?id=eq.' . $supabase_id,
			'PATCH',
			array( 'status' => $supabase_status )
		);
	}

	/**
	 * Push comment deletion to Supabase.
	 *
	 * @param int $comment_id The comment ID.
	 *
	 * @return void
	 */
	public function push_comment_deletion( $comment_id ) {
		$supabase_id = get_comment_meta( $comment_id, 'supabase_comment_id', true );
		if ( ! $supabase_id ) {
			return;
		}

		Supabase_API::request( 'comments?id=eq.' . $supabase_id, 'DELETE' );
	}

	/**
	 * Push comment trash to Supabase.
	 *
	 * @param int $comment_id The comment ID.
	 *
	 * @return void
	 */
	public function push_comment_trash( $comment_id ) {
		$supabase_id = get_comment_meta( $comment_id, 'supabase_comment_id', true );
		if ( ! $supabase_id ) {
			return;
		}

		Supabase_API::request(
			'comments?id=eq.' . $supabase_id,
			'PATCH',
			array( 'status' => 'trash' )
		);
	}

	/**
	 * Hijack the core/comments block on singular posts.
	 *
	 * Renders the block with 1 comment as a template so the JS can clone
	 * the exact theme HTML structure (wrappers, classes, etc.).
	 *
	 * @param string $block_content The block content.
	 * @param array  $block         The block data.
	 *
	 * @return string
	 */
	public function hijack_comments_block( $block_content, $block ) {
		$comment_block_names = array(
			'core/comments',
			'core/comments-query-loop',
			'core/post-comments',
		);

		if ( in_array( $block['blockName'], $comment_block_names, true ) && is_singular() ) {
			$post_id = get_the_ID();
			if ( ! $post_id ) {
				return $block_content;
			}

			$template_comments = get_comments(
				array(
					'post_id' => $post_id,
					'number'  => 1,
					'status'  => 'approve',
				)
			);

			if ( empty( $template_comments ) ) {
				$template_comments = get_comments(
					array(
						'number' => 1,
						'status' => 'approve',
					)
				);
			}

			$filter_callback = function ( $preempt, $query ) use ( $template_comments, $post_id ) {
				$query_post_id = $query->query_vars['post_id'];

				if ( is_array( $query_post_id ) ) {
					return $preempt;
				}

				if ( (int) $post_id === (int) $query_post_id ) {
					return $template_comments;
				}
				return $preempt;
			};

			add_filter( 'comments_pre_query', $filter_callback, 10, 2 );

			$self = self::get_instance();
			remove_action( 'render_block', array( $self, 'hijack_comments_block' ), 10 );
			$comments_block = new \WP_Block(
				$block,
				array(
					'postId'   => $post_id,
					'postType' => get_post_type(),
				)
			);
			$template_html  = $comments_block->render();
			add_action( 'render_block', array( $self, 'hijack_comments_block' ), 10, 2 );

			remove_filter( 'comments_pre_query', $filter_callback );

			ob_start();
			?>
			<dmsi-comments post-id="<?php echo esc_attr( $post_id ); ?>">
				<template>
					<?php echo $template_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML rendered by WP_Block::render() which escapes its own output. ?>
				</template>
				<div class="supabase-comments-wrapper is-layout-constrained">
					<p>Loading comments...</p>
				</div>
			</dmsi-comments>
			<?php
			return ob_get_clean();
		}

		return $block_content;
	}

	/**
	 * Push a chunk of WordPress comments to Supabase.
	 *
	 * @param int $offset The offset to start from.
	 *
	 * @return array
	 */
	public function push_comments_chunk( $offset = 0 ) {
		$comments = get_comments(
			array(
				'post_type' => array( 'post', 'page' ),
				'status'    => 'all',
				'number'    => 50,
				'offset'    => $offset,
			)
		);

		if ( empty( $comments ) ) {
			// On first call with no comments, we still need to count for total.
			if ( 0 === $offset ) {
				$total_query = get_comments(
					array(
						'post_type' => array( 'post', 'page' ),
						'status'    => 'all',
						'count'     => true,
					)
				);
				$total       = (int) $total_query;
			} else {
				$total = $offset;
			}
			return array(
				'pushed' => 0,
				'total'  => $total,
				'more'   => false,
			);
		}

		$total_query = get_comments(
			array(
				'post_type' => array( 'post', 'page' ),
				'status'    => 'all',
				'count'     => true,
			)
		);
		$total       = (int) $total_query;

		$pushed = 0;
		$batch  = array();
		$errors = array();

		foreach ( $comments as $comment ) {
			$supabase_id = get_comment_meta( $comment->comment_ID, 'supabase_comment_id', true );

			$status_map = array(
				'0'            => 'pending',
				'1'            => 'approved',
				'spam'         => 'trash',
				'trash'        => 'trash',
				'post-trashed' => 'trash',
			);

			$status = isset( $status_map[ $comment->comment_approved ] ) ? $status_map[ $comment->comment_approved ] : 'pending';

			$entry = array(
				'post_id'      => $comment->comment_post_ID,
				'author_name'  => $comment->comment_author,
				'author_email' => $comment->comment_author_email,
				'content'      => $comment->comment_content,
				'created_at'   => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $comment->comment_date_gmt ) ),
				'status'       => $status,
			);

			if ( $supabase_id ) {
				$entry['id'] = (int) $supabase_id;
				$response    = Supabase_API::request(
					'comments?id=eq.' . $supabase_id,
					'PATCH',
					$entry
				);

				if ( is_wp_error( $response ) ) {
					$errors[] = $response->get_error_message() . ' ' . wp_json_encode( $response->get_error_data() );
				} elseif ( is_array( $response ) && empty( $response ) ) {
					// The row this meta points to no longer exists remotely (e.g. the
					// Supabase table was reset) - drop the stale reference and insert fresh.
					delete_comment_meta( $comment->comment_ID, 'supabase_comment_id' );
					$entry['id'] = (int) $comment->comment_ID;
					$batch[]     = $entry;
				} else {
					++$pushed;
				}
			} else {
				$entry['id'] = (int) $comment->comment_ID;
				$batch[]     = $entry;
			}
		}

		if ( ! empty( $batch ) ) {
			$response = Supabase_API::request(
				'comments',
				'POST',
				$batch,
				array(
					'Prefer' => 'resolution=merge-duplicates,return=representation',
				)
			);

			if ( is_wp_error( $response ) ) {
				$errors[] = $response->get_error_message() . ' ' . wp_json_encode( $response->get_error_data() );
			} elseif ( is_array( $response ) ) {
				foreach ( $response as $item ) {
					if ( isset( $item['id'] ) ) {
						$wp_comment = get_comment( (int) $item['id'] );
						if ( $wp_comment ) {
							update_comment_meta( (int) $item['id'], 'supabase_comment_id', $item['id'] );
						}
					}
				}
				$pushed += count( $response );
			} else {
				$errors[] = 'Unexpected response from Supabase (no rows returned - check that the secret key is the service_role key, and that the `id` column allows explicit inserts).';
			}
		}

		return array(
			'pushed' => $pushed,
			'total'  => $total,
			'more'   => ( $offset + count( $comments ) ) < $total,
			'errors' => $errors,
		);
	}

	/**
	 * Handle manual full sync via AJAX (push WP → Supabase, then pull Supabase → WP).
	 *
	 * @return void
	 */
	public function ajax_sync_all_comments() {
		check_ajax_referer( 'dm_si_sync_all_comments' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$phase  = isset( $_POST['phase'] ) ? sanitize_text_field( wp_unslash( $_POST['phase'] ) ) : 'push';
		$offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;

		if ( 'push' === $phase ) {
			$result = $this->push_comments_chunk( $offset );
			wp_send_json_success(
				array(
					'phase'  => 'push',
					'count'  => $result['pushed'],
					'total'  => $result['total'],
					'more'   => $result['more'],
					'errors' => $result['errors'],
				)
			);
		} elseif ( 'pull' === $phase ) {
			$result = $this->sync_comments_from_supabase( '2000-01-01T00:00:00Z' );
			wp_send_json_success(
				array(
					'phase'  => 'pull',
					'pulled' => isset( $result['synced'] ) ? $result['synced'] : 0,
				)
			);
		}
	}

	/**
	 * Propagate the immediate wrapping block's layout classes/styles
	 * (e.g. a core/group with is-layout-constrained, has-global-padding,
	 * wp-container-*, inline padding) onto .supabase-comments-wrapper.
	 *
	 * The render_block filter fires bottom-up: children are rendered (and their
	 * render_block filters run) before their parent's. So by the time an
	 * ancestor block's own render_block call reaches this filter, its
	 * $block_content is the ancestor's *own* opening tag wrapped around
	 * the already-rendered descendant markup - which still contains our
	 * wrapper div verbatim if that ancestor is the one directly wrapping
	 * <dmsi-comments>. We detect that by matching our own opening tag
	 * (a string we control) at the start of $block_content, read *that*
	 * tag's class/style, merge them into the wrapper, and stamp the
	 * wrapper so any further (grand-)ancestor's render_block call is a
	 * no-op - only the immediate wrapper's layout is applied.
	 *
	 * No full-document parsing: only ever operates on the single block's
	 * own $block_content fragment.
	 *
	 * @param string $block_content The block content.
	 * @param array  $block         The block data. Unused - this filter is intentionally
	 *                              registered for every block, not just the wrapping one.
	 *
	 * @return string
	 */
	public function apply_parent_layout_to_wrapper( $block_content, $block ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by the render_block filter signature.
		if ( false === strpos( $block_content, '<div class="supabase-comments-wrapper' )
			|| false !== strpos( $block_content, 'data-dmsi-layout-applied' )
		) {
			return $block_content;
		}

		if ( ! preg_match( '/^\s*<([a-zA-Z][a-zA-Z0-9-]*)((?:\s+[a-zA-Z-]+(?:="[^"]*")?)*)\s*>/', $block_content, $tag_match ) ) {
			return $block_content;
		}

		$outer_attrs = $tag_match[2];

		preg_match( '/\bclass="([^"]*)"/', $outer_attrs, $class_match );
		preg_match( '/\bstyle="([^"]*)"/', $outer_attrs, $style_match );

		$parent_classes = isset( $class_match[1] ) ? trim( $class_match[1] ) : '';
		$parent_style   = isset( $style_match[1] ) ? trim( $style_match[1] ) : '';

		// The outer tag (e.g. <dmsi-comments post-id="...">) has no class/style of its
		// own - this render_block call isn't the wrapping ancestor yet, leave untouched
		// for a later (actual parent) call to handle.
		if ( '' === $parent_classes && '' === $parent_style ) {
			return $block_content;
		}

		return preg_replace_callback(
			'/<div class="supabase-comments-wrapper([^"]*)">/',
			function ( $m ) use ( $parent_classes, $parent_style ) {
				$existing = array_filter( explode( ' ', trim( 'supabase-comments-wrapper' . $m[1] ) ) );
				$parent   = array_filter( explode( ' ', $parent_classes ) );
				$merged   = array_values( array_unique( array_merge( $existing, $parent ) ) );

				$style_attr = $parent_style ? ' style="' . esc_attr( $parent_style ) . '"' : '';

				return '<div class="' . esc_attr( implode( ' ', $merged ) ) . '"' . $style_attr . ' data-dmsi-layout-applied="1">';
			},
			$block_content,
			1
		);
	}
}
