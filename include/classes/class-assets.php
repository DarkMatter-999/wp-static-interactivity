<?php
/**
 * Enqueues the plugin's front-end and admin scripts/styles.
 *
 * @package DM_Static_Interactivity
 **/

namespace DM_Static_Interactivity;

use DM_Static_Interactivity\Traits\Singleton;

/**
 * Assets class.
 *
 * @since 1.0.0
 **/
class Assets {

	use Singleton;

	/**
	 * Constructor for the Assets class.
	 *
	 * @return void
	 */
	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Enqueues styles and scripts for the plugin.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		wp_register_script( 'dm-si-settings', '', array(), '1.0.0', true );
		wp_enqueue_script( 'dm-si-settings' );

		$search_slug  = Options::search_slug();
		$supabase_url = Options::supabase_url();
		$supabase_key = Options::supabase_publishable_key();

		wp_add_inline_script(
			'dm-si-settings',
			'window.dmSISettings = ' . wp_json_encode(
				array(
					'search_slug'       => $search_slug,
					'supabase_url'      => untrailingslashit( $supabase_url ),
					'supabase_anon_key' => $supabase_key,
					'per_page'          => absint( get_option( 'posts_per_page', 10 ) ),
				)
			) . ';',
			'before'
		);

		$style_asset = include SI_PLUGIN_PATH . 'assets/build/css/main.asset.php';
		wp_enqueue_style(
			'main-css',
			SI_PLUGIN_URL . 'assets/build/css/main.css',
			$style_asset['dependencies'],
			$style_asset['version']
		);

		$script_asset = include SI_PLUGIN_PATH . 'assets/build/js/main.asset.php';

		wp_enqueue_script(
			'main-js',
			SI_PLUGIN_URL . 'assets/build/js/main.js',
			array_merge( $script_asset['dependencies'], array( 'dm-si-settings' ) ),
			$script_asset['version'],
			true
		);

		if ( is_search() ) {
			$search_script_asset = include SI_PLUGIN_PATH . 'assets/build/js/search.asset.php';

			wp_enqueue_script(
				'search-js',
				SI_PLUGIN_URL . 'assets/build/js/search.js',
				array_merge( $search_script_asset['dependencies'], array( 'dm-si-settings' ) ),
				$search_script_asset['version'],
				true
			);
		}

		if ( is_singular() && post_type_supports( get_post_type(), 'comments' ) ) {
			$comments_script_asset = include SI_PLUGIN_PATH . 'assets/build/js/comments.asset.php';

			wp_enqueue_script(
				'comments-js',
				SI_PLUGIN_URL . 'assets/build/js/comments.js',
				array_merge( $comments_script_asset['dependencies'], array( 'dm-si-settings' ) ),
				$comments_script_asset['version'],
				true
			);
		}
	}

	/**
	 * Enqueue admin assets for the settings page.
	 *
	 * @param string $hook The current admin page hook.
	 *
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'settings_page_dm_static_interactivity' !== $hook ) {
			return;
		}

		$script_asset = include SI_PLUGIN_PATH . 'assets/build/js/admin-sync.asset.php';

		wp_enqueue_script(
			'dm-si-admin-sync',
			SI_PLUGIN_URL . 'assets/build/js/admin-sync.js',
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);
	}
}
