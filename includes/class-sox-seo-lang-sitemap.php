<?php
/**
 * Sitemap of translated URLs, one section per extra language.
 *
 * @package Sox_SEO
 */

defined( 'ABSPATH' ) || exit;

class Sox_SEO_Lang_Sitemap extends WP_Sitemaps_Provider {

	const PER_PAGE = 2000;

	public function __construct() {
		$this->name        = 'languages';
		$this->object_type = 'language';
	}

	/**
	 * @return array<string,object>
	 */
	public function get_object_subtypes() {
		$out = array();
		foreach ( array_keys( Sox_SEO_Lang::languages() ) as $slug ) {
			$out[ $slug ] = (object) array( 'name' => $slug );
		}
		return $out;
	}

	/**
	 * @param int $page_num Page number.
	 * @return array
	 */
	private function query_args( $page_num ) {
		return array(
			'post_type'              => Sox_SEO_Meta::post_types(),
			'post_status'            => 'publish',
			'posts_per_page'         => self::PER_PAGE,
			'paged'                  => $page_num,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'post__not_in'           => Sox_SEO_Meta::utility_page_ids(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
			'has_password'           => false,
			'update_post_term_cache' => false,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array(
					'key'     => Sox_SEO_Meta::key( 'noindex' ),
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => Sox_SEO_Meta::key( 'noindex' ),
					'value'   => '1',
					'compare' => '!=',
				),
			),
		);
	}

	/**
	 * @param int    $page_num       Page number.
	 * @param string $object_subtype Language slug.
	 * @return array
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		$lang = $object_subtype;
		if ( ! isset( Sox_SEO_Lang::languages()[ $lang ] ) ) {
			return array();
		}
		$urls = array();
		if ( 1 === (int) $page_num ) {
			$urls[] = array( 'loc' => Sox_SEO_Lang::url( home_url( '/' ), $lang ) );
			foreach ( array( 'product_cat', 'category' ) as $taxonomy ) {
				if ( ! taxonomy_exists( $taxonomy ) ) {
					continue;
				}
				$terms = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => true,
					)
				);
				foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) && ! Sox_SEO_Meta::get_term( $term->term_id, 'noindex' ) ) {
						$urls[] = array( 'loc' => Sox_SEO_Lang::url( $link, $lang ) );
					}
				}
			}
		}
		$front = (int) get_option( 'page_on_front' );
		$query = new WP_Query( $this->query_args( $page_num ) );
		foreach ( $query->posts as $id ) {
			if ( (int) $id === $front ) {
				continue;
			}
			$urls[] = array(
				'loc'     => Sox_SEO_Lang::url( get_permalink( $id ), $lang ),
				'lastmod' => get_post_modified_time( 'c', true, $id ),
			);
		}
		return $urls;
	}

	/**
	 * @param string $object_subtype Language slug.
	 * @return int
	 */
	public function get_max_num_pages( $object_subtype = '' ) {
		$args                   = $this->query_args( 1 );
		$args['posts_per_page'] = 1;
		$query                  = new WP_Query( $args );
		return max( 1, (int) ceil( $query->found_posts / self::PER_PAGE ) );
	}
}
