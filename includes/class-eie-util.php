<?php
/**
 * Shared helpers.
 */

defined( 'ABSPATH' ) || exit;

class EIE_Util {

	const BUNDLE_VERSION = 1;

	/**
	 * Media file extensions that are picked up from plain URLs inside content and settings.
	 */
	const MEDIA_EXTENSIONS = 'jpe?g|png|gif|webp|avif|svg|bmp|ico|mp4|webm|ogv|mp3|wav|pdf|json|lottie';

	/**
	 * Post types offered for export.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = array_merge(
			array( 'page', 'post', 'elementor_library', 'e-landing-page' ),
			(array) get_option( 'elementor_cpt_support', array() )
		);

		$types = array_values( array_filter( array_unique( $types ), 'post_type_exists' ) );

		return apply_filters( 'eie_post_types', $types );
	}

	/**
	 * Meta that is cache, lock or bookkeeping data and must not travel between sites.
	 *
	 * @return string[]
	 */
	public static function skipped_meta_keys() {
		return apply_filters(
			'eie_skipped_meta_keys',
			array(
				'_edit_lock',
				'_edit_last',
				'_thumbnail_id',
				'_wp_old_slug',
				'_wp_old_date',
				'_elementor_data',
				'_elementor_css',
				'_elementor_element_cache',
				'_elementor_page_assets',
				'_elementor_controls_usage',
				'_elementor_screenshot',
				'_elementor_screenshot_failed',
				'_eie_source',
			)
		);
	}

	/**
	 * Walk a nested value depth-first. The callback sees every array (before its children)
	 * and every scalar, and returns the replacement.
	 *
	 * @param mixed    $data Value to walk.
	 * @param callable $cb   Callback.
	 * @return mixed
	 */
	public static function map_deep( $data, callable $cb ) {
		if ( is_array( $data ) ) {
			$data = $cb( $data );
			if ( is_array( $data ) ) {
				foreach ( $data as $key => $value ) {
					$data[ $key ] = self::map_deep( $value, $cb );
				}
			}
			return $data;
		}

		return $cb( $data );
	}

	/**
	 * Strip the scheme so http/https/protocol-relative forms compare equal.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function schemeless( $url ) {
		return preg_replace( '#^(?:https?:)?//#i', '//', $url );
	}

	/**
	 * Add http, https and protocol-relative forms of one URL pair to a replacement map.
	 *
	 * @param array  $map  Map to extend.
	 * @param string $from Old URL.
	 * @param string $to   New URL.
	 */
	public static function add_url_pair( array &$map, $from, $to ) {
		if ( '' === $from || $from === $to ) {
			return;
		}

		$from = self::schemeless( $from );

		// Protocol-relative stays protocol-relative; absolute old URLs get this site's real URL.
		$map[ $from ]            = self::schemeless( $to );
		$map[ 'http:' . $from ]  = $to;
		$map[ 'https:' . $from ] = $to;

		// Escaped slashes, as found in JSON stored inside strings.
		$map[ str_replace( '/', '\/', 'http:' . $from ) ]  = str_replace( '/', '\/', $to );
		$map[ str_replace( '/', '\/', 'https:' . $from ) ] = str_replace( '/', '\/', $to );
	}

	/**
	 * Try to give PHP enough room for large bundles.
	 */
	public static function raise_limits() {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}
