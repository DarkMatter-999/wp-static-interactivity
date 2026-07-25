<?php
/**
 * Main Settings File for Plugin.
 *
 * @package DM_Static_Interactivity
 */

namespace DM_Static_Interactivity;

use DM_Static_Interactivity\Traits\Singleton;

/**
 * Main Settings File for Plugin.
 *
 * @since 1.0.0
 */
class Settings {
	use Singleton;

	/**
	 * Constructor for the Settings class.
	 *
	 * @return void
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_dm_si_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_dm_si_health_check', array( $this, 'ajax_health_check' ) );
	}

	/**
	 * Registers the settings for the plugin.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting( 'dm_si_options', 'dm_si_supabase_url', 'esc_url_raw' );
		register_setting( 'dm_si_options', 'dm_si_supabase_publishable_key', 'sanitize_text_field' );
		register_setting( 'dm_si_options', 'dm_si_supabase_secret_key', 'sanitize_text_field' );
		register_setting( 'dm_si_options', 'dm_si_search_slug', 'sanitize_title' );
	}

	/**
	 * Adds the settings page to the WordPress admin menu.
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_options_page(
			'Static Interactivity Settings',
			'Static Interactivity',
			'manage_options',
			'dm_static_interactivity',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Test the Supabase connection.
	 *
	 * @return void
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'dm_si_test_connection' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$url = Options::supabase_url();
		$key = Options::supabase_secret_key();

		if ( empty( $url ) || empty( $key ) ) {
			wp_send_json_error( 'Supabase URL or Secret Key is not configured.' );
		}

		$response = wp_remote_get(
			trailingslashit( $url ) . 'rest/v1/',
			array(
				'headers' => array(
					'apikey'        => $key,
					'Authorization' => 'Bearer ' . $key,
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( 'Network error: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			$message = '';

			$tables          = $this->check_supabase_tables();
			$search_exists   = $tables['search_index'];
			$comments_exists = $tables['comments'];

			if ( ! $search_exists && ! $comments_exists ) {
				$message = __( 'Connected, but required tables (search_index, comments) were not found. Please run the schema setup.', 'dm-static-interactivity' );
			} elseif ( ! $search_exists ) {
				$message = __( 'Connected, but the search_index table was not found.', 'dm-static-interactivity' );
			} elseif ( ! $comments_exists ) {
				$message = __( 'Connected, but the comments table was not found.', 'dm-static-interactivity' );
			} else {
				$message = __( 'All required tables exist.', 'dm-static-interactivity' );
			}

			wp_send_json_success(
				array(
					'message'      => $message,
					'search_index' => $search_exists,
					'comments'     => $comments_exists,
				)
			);
		} elseif ( 401 === $code || 403 === $code ) {
			wp_send_json_error( 'Authentication failed. Check your Supabase URL and keys.' );
		} else {
			wp_send_json_error( 'Unexpected response (HTTP ' . $code . ').' );
		}
	}

	/**
	 * Check if required Supabase tables exist.
	 *
	 * @return array
	 */
	private function check_supabase_tables() {
		$url    = Options::supabase_url();
		$key    = Options::supabase_secret_key();
		$result = array(
			'search_index' => false,
			'comments'     => false,
		);

		foreach ( array_keys( $result ) as $table ) {
			$response = wp_remote_get(
				trailingslashit( $url ) . 'rest/v1/' . rawurlencode( $table ) . '?limit=1',
				array(
					'headers' => array(
						'apikey'        => $key,
						'Authorization' => 'Bearer ' . $key,
					),
					'timeout' => 10,
				)
			);

			if ( ! is_wp_error( $response ) ) {
				$code = wp_remote_retrieve_response_code( $response );
				if ( $code >= 200 && $code < 300 ) {
					$result[ $table ] = true;
				}
			}
		}

		return $result;
	}

	/**
	 * Compare WordPress post count with Supabase search index row count.
	 *
	 * @return void
	 */
	public function ajax_health_check() {
		check_ajax_referer( 'dm_si_health_check' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$sync_post_types = array( 'post', 'page' );

		$wp_count = (int) wp_count_posts( 'post' )->publish + (int) wp_count_posts( 'page' )->publish;

		$url = Options::supabase_url();
		$key = Options::supabase_secret_key();

		if ( empty( $url ) || empty( $key ) ) {
			wp_send_json_error( 'Supabase is not configured.' );
		}

		$response = wp_remote_get(
			trailingslashit( $url ) . 'rest/v1/search_index?select=post_id&limit=0',
			array(
				'headers' => array(
					'apikey'        => $key,
					'Authorization' => 'Bearer ' . $key,
					'Prefer'        => 'count=exact',
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( 'Supabase request failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 400 ) {
			wp_send_json_error( 'Supabase returned HTTP ' . $code );
		}

		$sb_count      = 0;
		$content_range = wp_remote_retrieve_header( $response, 'content-range' );
		if ( $content_range && preg_match( '/\/(\d+)$/', $content_range, $m ) ) {
			$sb_count = (int) $m[1];
		}

		wp_send_json_success(
			array(
				'wp_count'       => $wp_count,
				'supabase_count' => $sb_count,
				'difference'     => $wp_count - $sb_count,
			)
		);
	}

	/**
	 * Renders the settings page for the plugin.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Static Interactivity Settings', 'dm-static-interactivity' ); ?></h1>
			<p><?php esc_html_e( 'Configure your Supabase connection details below. The Secret Key will be kept secure on the backend, while the Publishable Key will be exposed to the frontend.', 'dm-static-interactivity' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'dm_si_options' ); ?>
				<?php do_settings_sections( 'dm_si_options' ); ?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Supabase URL', 'dm-static-interactivity' ); ?></th>
						<td><input type="text" name="dm_si_supabase_url" value="<?php echo esc_attr( Options::supabase_url() ); ?>" class="regular-text" /></td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Supabase Publishable Key', 'dm-static-interactivity' ); ?></th>
						<td><input type="password" name="dm_si_supabase_publishable_key" value="<?php echo esc_attr( Options::supabase_publishable_key() ); ?>" class="regular-text" /></td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Supabase Secret Key', 'dm-static-interactivity' ); ?></th>
						<td><input type="password" name="dm_si_supabase_secret_key" value="<?php echo esc_attr( Options::supabase_secret_key() ); ?>" class="regular-text" /></td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Search Slug', 'dm-static-interactivity' ); ?></th>
						<td><input type="text" name="dm_si_search_slug" value="<?php echo esc_attr( Options::search_slug() ); ?>" class="regular-text" /></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Database Schema Setup', 'dm-static-interactivity' ); ?></h2>
			<p><?php esc_html_e( 'Run the following SQL in your Supabase SQL Editor to create the necessary tables.', 'dm-static-interactivity' ); ?></p>
			<textarea readonly style="width: 100%; height: 300px; font-family: monospace;">
-- Create Search Index Table
CREATE TABLE public.search_index (
	post_id BIGINT PRIMARY KEY,
	title TEXT,
	excerpt TEXT,
	featured_image_url TEXT,
	permalink TEXT,
	search_vector tsvector GENERATED ALWAYS AS (
		setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
		setweight(to_tsvector('english', coalesce(excerpt, '')), 'B')
	) STORED
);

-- Index for full-text search
CREATE INDEX search_index_search_vector_idx ON public.search_index USING GIN (search_vector);

-- Enable RLS for search_index
ALTER TABLE public.search_index ENABLE ROW LEVEL SECURITY;
CREATE POLICY "Allow public read-only access to search_index" ON public.search_index FOR SELECT USING (true);

-- Create Comments Table
CREATE TABLE public.comments (
	id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
	post_id BIGINT NOT NULL,
	author_name TEXT,
	author_email TEXT,
	content TEXT NOT NULL,
	created_at TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now()) NOT NULL,
	status TEXT DEFAULT 'pending'::text
);

-- Enable RLS for comments
ALTER TABLE public.comments ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Anyone can read approved comments" ON public.comments
	FOR SELECT TO anon USING (status = 'approved');

CREATE POLICY "Anyone can insert comments" ON public.comments
	FOR INSERT TO anon WITH CHECK (true);

GRANT USAGE ON SCHEMA public TO anon;
GRANT SELECT, INSERT ON public.comments TO anon;
			</textarea>

			<hr>
			<h2><?php esc_html_e( 'Replace Search Index', 'dm-static-interactivity' ); ?></h2>
			<p><?php esc_html_e( 'Delete all existing search data in Supabase and re-sync all published posts and pages.', 'dm-static-interactivity' ); ?></p>
			<button id="dm-si-replace-btn" class="button button-secondary" data-nonce="<?php echo esc_attr( wp_create_nonce( 'dm_si_replace_index' ) ); ?>">
				<?php esc_html_e( 'Replace All Search Data', 'dm-static-interactivity' ); ?>
			</button>
			<progress id="dm-si-progress" max="100" value="0" style="width:100%;margin-top:1em;display:none"></progress>
			<p id="dm-si-status" style="margin-top:0.5em;"></p>

			<hr>
			<h2><?php esc_html_e( 'Connection Test', 'dm-static-interactivity' ); ?></h2>
			<p><?php esc_html_e( 'Verify that your Supabase project is reachable and the required tables exist.', 'dm-static-interactivity' ); ?></p>
			<button id="dm-si-test-connection-btn" class="button button-secondary" data-nonce="<?php echo esc_attr( wp_create_nonce( 'dm_si_test_connection' ) ); ?>">
				<?php esc_html_e( 'Test Connection', 'dm-static-interactivity' ); ?>
			</button>
			<p id="dm-si-connection-status" style="margin-top:0.5em;"></p>

			<hr>
			<h2><?php esc_html_e( 'Search Index Health Check', 'dm-static-interactivity' ); ?></h2>
			<p><?php esc_html_e( 'Compare the number of published posts in WordPress against the Supabase search index to find discrepancies.', 'dm-static-interactivity' ); ?></p>
			<button id="dm-si-health-check-btn" class="button button-secondary" data-nonce="<?php echo esc_attr( wp_create_nonce( 'dm_si_health_check' ) ); ?>">
				<?php esc_html_e( 'Check Health', 'dm-static-interactivity' ); ?>
			</button>
			<progress id="dm-si-health-progress" max="100" value="0" style="width:100%;margin-top:1em;display:none"></progress>
			<p id="dm-si-health-status" style="margin-top:0.5em;"></p>

			<hr>
			<h2><?php esc_html_e( 'Sync Comments', 'dm-static-interactivity' ); ?></h2>
			<p><?php esc_html_e( 'Push all WordPress comments to Supabase and pull new comments from Supabase into WordPress.', 'dm-static-interactivity' ); ?></p>
			<button id="dm-si-sync-comments-btn" class="button button-secondary" data-nonce="<?php echo esc_attr( wp_create_nonce( 'dm_si_sync_all_comments' ) ); ?>">
				<?php esc_html_e( 'Sync Comments Now', 'dm-static-interactivity' ); ?>
			</button>
			<progress id="dm-si-comments-progress" max="100" value="0" style="width:100%;margin-top:1em;display:none"></progress>
			<p id="dm-si-sync-comments-status" style="margin-top:0.5em;"></p>
			<script>
			(function() {
				var btn = document.getElementById('dm-si-sync-comments-btn');
				var progress = document.getElementById('dm-si-comments-progress');
				var status = document.getElementById('dm-si-sync-comments-status');
				if (!btn || !progress || !status) return;

				function processChunk(offset) {
					var formData = new FormData();
					formData.append('action', 'dm_si_sync_all_comments');
					formData.append('_wpnonce', btn.dataset.nonce);
					formData.append('phase', 'push');
					formData.append('offset', String(offset));

					fetch(ajaxurl, { method: 'POST', body: formData })
					.then(function(r) { return r.json(); })
					.then(function(data) {
						if (!data.success) {
							btn.disabled = false;
							progress.value = 0;
							progress.style.display = 'none';
							status.textContent = 'Push failed.';
							return;
						}

						var d = data.data;
						var done = offset + d.count;
						var total = d.total;

						if (d.errors && d.errors.length) {
							btn.disabled = false;
							progress.style.display = 'none';
							status.textContent = 'Supabase error: ' + d.errors.join(' | ');
							return;
						}

						if (total > 0) {
							progress.value = (done / total) * 50;
						}

						if (d.more) {
							status.textContent = 'Pushing ' + done + ' / ' + total + '...';
							processChunk(offset + d.count);
						} else {
							status.textContent = 'Push complete. Pulling from Supabase...';
							doPull(total);
						}
					})
					.catch(function() {
						btn.disabled = false;
						progress.value = 0;
						progress.style.display = 'none';
						status.textContent = 'Network error during push.';
					});
				}

				function doPull(total) {
					var formData = new FormData();
					formData.append('action', 'dm_si_sync_all_comments');
					formData.append('_wpnonce', btn.dataset.nonce);
					formData.append('phase', 'pull');

					fetch(ajaxurl, { method: 'POST', body: formData })
					.then(function(r) { return r.json(); })
					.then(function(data) {
						if (data.success) {
							progress.value = 100;
							status.textContent = 'Done! ' + (total || 0) + ' pushed to Supabase, ' + (data.data.pulled || 0) + ' pulled into WordPress.';
						} else {
							status.textContent = 'Pull failed.';
						}
					})
					.catch(function() {
						status.textContent = 'Network error during pull.';
					})
					.finally(function() {
						btn.disabled = false;
					});
				}

				btn.addEventListener('click', function() {
					btn.disabled = true;
					progress.style.display = 'block';
					progress.value = 0;
					status.textContent = 'Starting...';
					processChunk(0);
				});
			})();
			</script>
		</div>
		<?php
	}
}
