<?php
/**
 * Removes data left in the database by SEO plugins that are no longer used.
 *
 * @package Sox_SEO
 */

defined( 'ABSPATH' ) || exit;

class Sox_SEO_Cleanup {

	/**
	 * Where each plugin keeps its data. LIKE patterns; "_" is escaped.
	 *
	 * @return array<string,array>
	 */
	private static function map() {
		return array(
			'rankmath' => array(
				'label'   => 'Rank Math',
				'active'  => defined( 'RANK_MATH_VERSION' ),
				'tables'  => 'rank\_math\_%',
				'meta'    => array( 'rank\_math\_%' ),
				'options' => array( 'rank\_math%', 'rank-math%' ),
			),
			'aioseo'   => array(
				'label'   => 'All in One SEO',
				'active'  => defined( 'AIOSEO_VERSION' ),
				'tables'  => 'aioseo\_%',
				'meta'    => array( '\_aioseo\_%', '\_aioseop\_%' ),
				'options' => array( 'aioseo%', 'aioseop%' ),
			),
			'yoast'    => array(
				'label'   => 'Yoast SEO',
				'active'  => defined( 'WPSEO_VERSION' ),
				'tables'  => 'yoast\_%',
				'meta'    => array( '\_yoast\_wpseo\_%' ),
				'options' => array( 'wpseo%', 'yoast\_%' ),
			),
			'seopress' => array(
				'label'   => 'SEOPress',
				'active'  => defined( 'SEOPRESS_VERSION' ),
				'tables'  => 'seopress\_%',
				'meta'    => array( '\_seopress\_%' ),
				'options' => array( 'seopress%' ),
			),
		);
	}

	/**
	 * What each plugin has left behind.
	 *
	 * @return array<string,array{label:string,active:bool,tables:int,meta:int,options:int}>
	 */
	public static function scan() {
		global $wpdb;
		$out = array();
		foreach ( self::map() as $id => $source ) {
			$meta = 0;
			foreach ( $source['meta'] as $pattern ) {
				$meta += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $pattern ) ); // phpcs:ignore WordPress.DB
				$meta += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", $pattern ) ); // phpcs:ignore WordPress.DB
			}
			$options = 0;
			foreach ( $source['options'] as $pattern ) {
				$options += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) ); // phpcs:ignore WordPress.DB
			}
			$out[ $id ] = array(
				'label'   => $source['label'],
				'active'  => $source['active'],
				'tables'  => count( self::tables( $source['tables'] ) ),
				'meta'    => $meta,
				'options' => $options,
			);
		}
		return $out;
	}

	/**
	 * @param string $pattern LIKE pattern without the table prefix.
	 * @return string[]
	 */
	private static function tables( $pattern ) {
		global $wpdb;
		return (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . $pattern ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Permanently delete one plugin's leftover data. Refused while that
	 * plugin is active.
	 *
	 * @param string $id Source ID.
	 * @return array|WP_Error Counts removed.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$map = self::map();
		if ( ! isset( $map[ $id ] ) ) {
			return new WP_Error( 'sox_seo_cleanup', __( 'Unknown plugin.', 'sox-seo' ) );
		}
		$source = $map[ $id ];
		if ( $source['active'] ) {
			/* translators: %s: plugin name */
			return new WP_Error( 'sox_seo_cleanup', sprintf( __( '%s is active, so its data is in use and was not touched.', 'sox-seo' ), $source['label'] ) );
		}

		$removed = array(
			'tables'  => 0,
			'meta'    => 0,
			'options' => 0,
		);
		foreach ( self::tables( $source['tables'] ) as $table ) {
			// Table names come from SHOW TABLES and are limited to this site's prefix.
			if ( 0 === strpos( $table, $wpdb->prefix ) && preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB
				$removed['tables']++;
			}
		}
		foreach ( $source['meta'] as $pattern ) {
			$removed['meta'] += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $pattern ) ); // phpcs:ignore WordPress.DB
			$removed['meta'] += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", $pattern ) ); // phpcs:ignore WordPress.DB
		}
		foreach ( $source['options'] as $pattern ) {
			$removed['options'] += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) ); // phpcs:ignore WordPress.DB
		}
		wp_cache_flush();
		return $removed;
	}
}
