<?php
/**
 * Main Plugin File for Plugin.
 *
 * @package DM_Static_Interactivity
 */

namespace DM_Static_Interactivity;

use DM_Static_Interactivity\Traits\Singleton;

/**
 * Main Plugin File for the Plugin.
 */
class Plugin {


	use Singleton;

	/**
	 * Constructor for the Plugin.
	 *
	 * @return void
	 */
	public function __construct() {
		Assets::get_instance();
		Settings::get_instance();
		Search::get_instance();
	}
}
