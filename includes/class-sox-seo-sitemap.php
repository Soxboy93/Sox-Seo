<?php
/**
 * Sitemap and robots.txt tuning.
 *
 * WordPress already serves an XML sitemap at /wp-sitemap.xml. Rather than
 * build a second one, this keeps it clean: pages marked noindex are left
 * out, author archives are optional, and robots.txt keeps crawlers out of
 * WooCommerce's sorting and filter URLs (WooCommerce itself already
 * blocks add-to-cart links) so crawl budget goes to pages that can rank.
 *
 * @package Sox_SEO
 */

defined( 'ABSPATH' ) || exit;

class Sox_SEO_Sitemap {

	public static function init() {
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'exclude_noindex_posts' ) );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( __CLASS__, 'exclude_noindex_terms' ) );
		add_filter( 'wp_sitemaps_add_provider', array( __CLASS__, 'providers' ), 10, 2 );
		add_filter( 'wp_sitemaps_taxonomies', array( __CLASS__, 'taxonomies' ) );
		add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 20, 2 );
	}

	/**
	 * @param array $args WP_Query args.
	 * @return array
	 */
	public static function exclude_noindex_posts( $args ) {
		$args['meta_query']   = isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$args['meta_query'][] = self::not_noindex();
		return $args;
	}

	/**
	 * @param array $args WP_Term_Query args.
	 * @return array
	 */
	public static function exclude_noindex_terms( $args ) {
		$args['meta_query']   = isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$args['meta_query'][] = self::not_noindex();
		return $args;
	}

	/**
	 * @return array
	 */
	private static function not_noindex() {
		$key = Sox_SEO_Meta::key( 'noindex' );
		return array(
			'relation' => 'OR',
			array(
				'key'     => $key,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => $key,
				'value'   => '1',
				'compare' => '!=',
			),
		);
	}

	/**
	 * Leave tag archives out of the sitemap when they are set to noindex.
	 *
	 * @param WP_Taxonomy[] $taxonomies Taxonomies keyed by name.
	 * @return WP_Taxonomy[]
	 */
	public static function taxonomies( $taxonomies ) {
		if ( Sox_SEO_Options::get( 'noindex_tags' ) ) {
			unset( $taxonomies['post_tag'], $taxonomies['product_tag'] );
		}
		return $taxonomies;
	}

	/**
	 * @param WP_Sitemaps_Provider $provider Provider.
	 * @param string               $name     Provider name.
	 * @return WP_Sitemaps_Provider|false
	 */
	public static function providers( $provider, $name ) {
		if ( 'users' === $name && Sox_SEO_Options::get( 'sitemap_no_users' ) ) {
			return false;
		}
		return $provider;
	}

	/**
	 * @param string $output robots.txt body.
	 * @param bool   $public Whether the site is visible to search engines.
	 * @return string
	 */
	public static function robots_txt( $output, $public ) {
		if ( ! $public || ! class_exists( 'WooCommerce' ) ) {
			return $output;
		}
		// Parameter URLs only. Cart, checkout and account pages are handled
		// with a noindex tag instead: a page blocked here could never be
		// crawled, so search engines would never see that tag.
		$rules = array(
			'Disallow: /*?orderby=',
			'Disallow: /*&orderby=',
			'Disallow: /*?filter_',
			'Disallow: /*&filter_',
		);
		return rtrim( $output ) . "\n\nUser-agent: *\n" . implode( "\n", $rules ) . "\n";
	}
}
