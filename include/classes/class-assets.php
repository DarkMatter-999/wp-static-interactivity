<?php
/**
 * Main Assets Class File
 *
 * Main Theme Asset class file for the Plugin. This class enqueues the necessary scripts and styles.
 *
 * @package DM_Static_Interactivity
 **/

namespace DM_Static_Interactivity;

use DM_Static_Interactivity\Traits\Singleton;

/**
 * Main Assets Class File
 *
 * Main Theme Asset class file for the Plugin. This class enqueues the necessary scripts and styles.
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
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueues styles and scripts for the plugin.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		wp_register_script( 'dm-si-settings', '', array(), '1.0.0', true );
		wp_enqueue_script( 'dm-si-settings' );

		$search_slug  = get_option( 'dm_si_search_slug', 'search' );
		$supabase_url = get_option( 'dm_si_supabase_url', '' );
		$supabase_key = get_option( 'dm_si_supabase_publishable_key', '' );

		wp_add_inline_script(
			'dm-si-settings',
			'window.dmSISettings = ' . wp_json_encode(
				array(
					'search_slug'       => $search_slug,
					'supabase_url'      => untrailingslashit( $supabase_url ),
					'supabase_anon_key' => $supabase_key,
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

		$search_script_asset = include SI_PLUGIN_PATH . 'assets/build/js/search.asset.php';

		wp_enqueue_script(
			'search-js',
			SI_PLUGIN_URL . 'assets/build/js/search.js',
			array_merge( $search_script_asset['dependencies'], array( 'dm-si-settings' ) ),
			$search_script_asset['version'],
			true
		);
	}
}
