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
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Registers the settings for the plugin.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting( 'dm_si_options', 'dm_si_supabase_url', 'esc_raw_url' );
		register_setting( 'dm_si_options', 'dm_si_supabase_publishable_key', 'sanitize_text_field' );
		register_setting( 'dm_si_options', 'dm_si_supabase_secret_key', 'sanitize_text_field' );
		register_setting( 'dm_si_options', 'dm_si_search_slug', 'sanitize_url' );
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
						<td><input type="text" name="dm_si_supabase_url" value="<?php echo esc_attr( get_option( 'dm_si_supabase_url' ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Supabase Publishable Key', 'dm-static-interactivity' ); ?></th>
						<td><input type="password" name="dm_si_supabase_publishable_key" value="<?php echo esc_attr( get_option( 'dm_si_supabase_publishable_key' ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Supabase Secret Key', 'dm-static-interactivity' ); ?></th>
						<td><input type="password" name="dm_si_supabase_secret_key" value="<?php echo esc_attr( get_option( 'dm_si_supabase_secret_key' ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Search Slug', 'dm-static-interactivity' ); ?></th>
						<td><input type="text" name="dm_si_search_slug" value="<?php echo esc_attr( get_option( 'dm_si_search_slug', 'search' ) ); ?>" class="regular-text" /></td>
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
			</textarea>
		</div>
		<?php
	}
}
