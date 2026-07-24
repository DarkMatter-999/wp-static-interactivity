<?php
/**
 * Central accessor for the plugin's stored options, so the option name and
 * default value for a given setting only need to be defined once.
 *
 * @package DM_Static_Interactivity
 */

namespace DM_Static_Interactivity;

/**
 * Options class.
 *
 * @since 1.0.0
 */
class Options {

	/**
	 * The default search slug used when none is configured.
	 *
	 * @var string
	 */
	const DEFAULT_SEARCH_SLUG = 'search';

	/**
	 * Get the configured search slug (e.g. "search" for /search/).
	 *
	 * @return string
	 */
	public static function search_slug() {
		return get_option( 'dm_si_search_slug', self::DEFAULT_SEARCH_SLUG );
	}

	/**
	 * Get the configured Supabase project URL.
	 *
	 * @return string
	 */
	public static function supabase_url() {
		return get_option( 'dm_si_supabase_url', '' );
	}

	/**
	 * Get the configured Supabase publishable (anon) key, safe to expose to the browser.
	 *
	 * @return string
	 */
	public static function supabase_publishable_key() {
		return get_option( 'dm_si_supabase_publishable_key', '' );
	}

	/**
	 * Get the configured Supabase secret key. Server-side use only - never expose this to the browser.
	 *
	 * @return string
	 */
	public static function supabase_secret_key() {
		return get_option( 'dm_si_supabase_secret_key', '' );
	}
}
