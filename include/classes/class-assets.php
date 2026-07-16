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
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_block_assets' ) );
		add_action(
			'enqueue_block_editor_assets',
			array(
				$this,
				'enqueue_block_editor_assets',
			)
		);
	}

	/**
	 * Enqueues styles and scripts for the plugin.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		wp_register_script( 'dm-si-settings', '', array(), '1.0.0', true );
		wp_enqueue_script( 'dm-si-settings' );

		$search_slug = get_option( 'dm_si_search_slug', 'search' );

		wp_add_inline_script(
			'dm-si-settings',
			'window.dmSISettings = ' . wp_json_encode(
				array(
					'search_slug' => $search_slug,
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
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);
	}

	/**
	 * Enqueues styles and scripts for the frontend.
	 *
	 * @return void
	 */
	public function enqueue_block_assets() {
		$style_asset = include SI_PLUGIN_PATH . 'assets/build/css/screen.asset.php';
		wp_enqueue_style(
			'block-css',
			SI_PLUGIN_URL . 'assets/build/css/screen.css',
			$style_asset['dependencies'],
			$style_asset['version']
		);

		$script_asset = include SI_PLUGIN_PATH . 'assets/build/js/screen.asset.php';

		wp_enqueue_script(
			'block-js',
			SI_PLUGIN_URL . 'assets/build/js/screen.js',
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);
	}
}
