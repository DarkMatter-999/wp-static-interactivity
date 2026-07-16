<?php
/**
 * Plugin Name:       Static Interactivity
 * Plugin URI:        https://github.com/DarkMatter-999/WP-Static-Interactivity
 * Description:       This plugin adds interactivity to static WordPress sites
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            DarkMatter-999
 * Author URI:        https://github.com/DarkMatter-999
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       static-interactivity
 * Domain Path:       /languages
 *
 * @category Plugin
 * @package  DM_Static_Interactivity
 * @author   DarkMatter-999 <darkmatter999official@gmail.com>
 * @license  GPL v2 or later <https://www.gnu.org/licenses/gpl-2.0.html>
 * @link     https://github.com/DarkMatter-999/WP-Static-Interactivity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Plugin base path and URL.
 */
define( 'SI_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SI_PLUGIN_PATH . 'include/helpers/autoloader.php';

use DM_Static_Interactivity\Plugin;

Plugin::get_instance();
