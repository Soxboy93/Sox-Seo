<?php
/**
 * Schema.org JSON-LD graph: Organization, WebSite, WebPage, BreadcrumbList
 * and Article. Product markup is left to WooCommerce and enriched in
 * Sox_SEO_WooCommerce, so products are never described twice.
 *
 * @package Sox_SEO
 */

defined( 'ABSPATH' ) || exit;

class Sox_SEO_Schema {

	public static function init() {
		if ( Sox_SEO_Options::get( 'schema_enabled' ) ) {
			add_action( 'wp_head', array( __CLASS__, 'output' ), 20 );
		}
	}

	public static function output() {
		if ( is_404() || is_search() ) {
			return;
		}
		$graph = self::graph();
		if ( ! $graph ) {
			return;
		}
		$json = wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $graph,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
		);
		echo '<script type="application/ld+json" class="sox-seo-schema">' . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * @return array
	 */
	public static function graph() {
		$home    = home_url( '/' );
		$url     = Sox_SEO_Frontend::canonical();
		$url     = $url ? $url : $home;
		$org_id  = $home . '#organization';
		$site_id = $home . '#website';
		$page_id = $url . '#webpage';
		$graph   = array();

		// Organization.
		$name = Sox_SEO_Options::get( 'org_name' );
		$org  = array(
			'@type' => 'Organization',
			'@id'   => $org_id,
			'name'  => $name ? $name : get_bloginfo( 'name' ),
			'url'   => $home,
		);
		$logo = Sox_SEO_Options::get( 'org_logo' );
		if ( ! $logo && has_custom_logo() ) {
			$logo = wp_get_attachment_image_url( (int) get_theme_mod( 'custom_logo' ), 'full' );
		}
		if ( $logo ) {
			$org['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => $logo,
			);
		}
		$social = array_filter( array_map( 'esc_url_raw', preg_split( '/\s+/', (string) Sox_SEO_Options::get( 'org_social' ) ) ) );
		if ( $social ) {
			$org['sameAs'] = array_values( $social );
		}
		$graph[] = self::local_business( $org );

		// WebSite, with the sitelinks search box action.
		$graph[] = array_filter( array(
			'@type'           => 'WebSite',
			'@id'             => $site_id,
			'url'             => $home,
			'name'            => get_bloginfo( 'name' ),
			'description'     => get_bloginfo( 'description' ),
			'inLanguage'      => get_bloginfo( 'language' ),
			'publisher'       => array( '@id' => $org_id ),
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => home_url( '/?s={search_term_string}' ),
				),
				'query-input' => 'required name=search_term_string',
			),
		) );

		// WebPage.
		$post = Sox_SEO_Frontend::current_post();
		$page = array(
			'@type'      => ( is_category() || is_tag() || is_tax() || is_post_type_archive() || is_home() ) ? 'CollectionPage' : 'WebPage',
			'@id'        => $page_id,
			'url'        => $url,
			'name'       => wp_get_document_title(),
			'isPartOf'   => array( '@id' => $site_id ),
			'inLanguage' => get_bloginfo( 'language' ),
		);
		$description = Sox_SEO_Frontend::description();
		if ( $description ) {
			$page['description'] = $description;
		}
		if ( $post && is_singular() ) {
			$page['datePublished'] = get_post_time( 'c', true, $post );
			$page['dateModified']  = get_post_modified_time( 'c', true, $post );
		}
		$image = Sox_SEO_Frontend::image();
		if ( $image ) {
			$page['primaryImageOfPage'] = array(
				'@type' => 'ImageObject',
				'url'   => $image['url'],
			);
		}

		// Breadcrumbs. WooCommerce prints its own on shop pages.
		$is_woo = function_exists( 'is_woocommerce' ) && is_woocommerce();
		$crumbs = $is_woo ? array() : self::breadcrumbs();
		if ( count( $crumbs ) > 1 ) {
			$items = array();
			foreach ( $crumbs as $i => $crumb ) {
				$items[] = array(
					'@type'    => 'ListItem',
					'position' => $i + 1,
					'name'     => $crumb['name'],
					'item'     => $crumb['url'],
				);
			}
			$graph[]            = array(
				'@type'           => 'BreadcrumbList',
				'@id'             => $url . '#breadcrumb',
				'itemListElement' => $items,
			);
			$page['breadcrumb'] = array( '@id' => $url . '#breadcrumb' );
		}
		$graph[] = $page;

		// Article, for posts and other non-page, non-product singular content.
		if ( $post && is_singular() && ! is_page() && ! is_front_page() && 'product' !== $post->post_type ) {
			$author  = get_userdata( (int) $post->post_author );
			$article = array(
				'@type'            => 'post' === $post->post_type ? 'BlogPosting' : 'Article',
				'@id'              => $url . '#article',
				'headline'         => wp_strip_all_tags( get_the_title( $post ) ),
				'datePublished'    => get_post_time( 'c', true, $post ),
				'dateModified'     => get_post_modified_time( 'c', true, $post ),
				'mainEntityOfPage' => array( '@id' => $page_id ),
				'publisher'        => array( '@id' => $org_id ),
				'inLanguage'       => get_bloginfo( 'language' ),
			);
			if ( $author ) {
				$article['author'] = array(
					'@type' => 'Person',
					'name'  => $author->display_name,
					'url'   => get_author_posts_url( $author->ID ),
				);
			}
			if ( $image ) {
				$article['image'] = $image['url'];
			}
			if ( $description ) {
				$article['description'] = $description;
			}
			$graph[] = $article;
		}

		/**
		 * Filter the JSON-LD graph before output.
		 *
		 * @param array $graph Graph nodes.
		 */
		return apply_filters( 'sox_seo_schema_graph', $graph );
	}

	/**
	 * Turn the Organization node into a local business when a physical
	 * location is configured, so the site can appear in map and "near me"
	 * results.
	 *
	 * @param array $org Organization node.
	 * @return array
	 */
	private static function local_business( array $org ) {
		$o = Sox_SEO_Options::all();
		if ( empty( $o['local_enabled'] ) ) {
			return $org;
		}
		$org['@type'] = array( 'Organization', $o['local_type'] ? $o['local_type'] : 'LocalBusiness' );

		$address = array_filter(
			array(
				'streetAddress'   => $o['local_street'],
				'addressLocality' => $o['local_city'],
				'addressRegion'   => $o['local_region'],
				'postalCode'      => $o['local_postcode'],
				'addressCountry'  => $o['local_country'],
			)
		);
		if ( $address ) {
			$org['address'] = array_merge( array( '@type' => 'PostalAddress' ), $address );
		}
		if ( $o['local_phone'] ) {
			$org['telephone'] = $o['local_phone'];
		}
		if ( is_numeric( $o['local_lat'] ) && is_numeric( $o['local_lng'] ) ) {
			$org['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $o['local_lat'],
				'longitude' => (float) $o['local_lng'],
			);
		}
		if ( $o['local_price_range'] ) {
			$org['priceRange'] = $o['local_price_range'];
		}
		if ( ! empty( $org['logo']['url'] ) ) {
			$org['image'] = $org['logo']['url'];
		}
		// One line per rule, in schema.org form: "Mo-Fr 09:00-18:00".
		$hours = array_values( array_filter( array_map( 'trim', preg_split( '/\R/', (string) $o['local_hours'] ) ) ) );
		if ( $hours ) {
			$org['openingHours'] = $hours;
		}
		return $org;
	}

	/**
	 * Breadcrumb trail for the current view.
	 *
	 * @return array<int,array{name:string,url:string}>
	 */
	public static function breadcrumbs() {
		$crumbs = array(
			array(
				'name' => __( 'Home', 'sox-seo' ),
				'url'  => home_url( '/' ),
			),
		);
		if ( is_front_page() ) {
			return $crumbs;
		}

		$add_term_trail = static function ( $term ) use ( &$crumbs ) {
			$ancestors = array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) );
			foreach ( $ancestors as $ancestor_id ) {
				$ancestor = get_term( $ancestor_id, $term->taxonomy );
				$link     = $ancestor && ! is_wp_error( $ancestor ) ? get_term_link( $ancestor ) : null;
				if ( $link && ! is_wp_error( $link ) ) {
					$crumbs[] = array(
						'name' => $ancestor->name,
						'url'  => $link,
					);
				}
			}
			$link = get_term_link( $term );
			if ( ! is_wp_error( $link ) ) {
				$crumbs[] = array(
					'name' => $term->name,
					'url'  => $link,
				);
			}
		};

		if ( is_singular() ) {
			$post = get_queried_object();
			if ( 'post' === $post->post_type ) {
				$categories = get_the_category( $post->ID );
				if ( $categories ) {
					$add_term_trail( $categories[0] );
				}
			} elseif ( is_post_type_hierarchical( $post->post_type ) ) {
				foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor_id ) {
					$crumbs[] = array(
						'name' => wp_strip_all_tags( get_the_title( $ancestor_id ) ),
						'url'  => get_permalink( $ancestor_id ),
					);
				}
			} else {
				$archive = get_post_type_archive_link( $post->post_type );
				$object  = get_post_type_object( $post->post_type );
				if ( $archive && $object ) {
					$crumbs[] = array(
						'name' => $object->labels->name,
						'url'  => $archive,
					);
				}
				// Products and other types: follow their category, if any.
				foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
					if ( $taxonomy->hierarchical && $taxonomy->public ) {
						$terms = wp_get_post_terms( $post->ID, $taxonomy->name );
						if ( ! is_wp_error( $terms ) && $terms ) {
							$add_term_trail( $terms[0] );
							break;
						}
					}
				}
			}
			$crumbs[] = array(
				'name' => wp_strip_all_tags( get_the_title( $post ) ),
				'url'  => get_permalink( $post ),
			);
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$add_term_trail( get_queried_object() );
		} elseif ( is_post_type_archive() ) {
			$object = get_queried_object();
			if ( $object ) {
				$crumbs[] = array(
					'name' => $object->labels->name,
					'url'  => get_post_type_archive_link( $object->name ),
				);
			}
		}
		return $crumbs;
	}
}
