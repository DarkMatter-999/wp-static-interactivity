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
	}

	/**
	 * Add the search rewrite rule. Map /search/ to an empty search query so WordPress loads the search template.
	 * If a user visits /search/?s=hello, WordPress merges it to index.php?s=&s=hello (which is s=hello)
	 *
	 * @return void
	 */
	public static function add_search_rewrite_rule() {
		$search_slug = get_option( 'dm_si_search_slug', 'search' );
		add_rewrite_rule( '^' . $search_slug . '/?$', 'index.php?s=', 'top' );
	}
}
