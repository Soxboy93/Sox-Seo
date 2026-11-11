<?php
/**
 * Front-end head output: title, description, canonical, robots, Open Graph
 * and Twitter cards. Loaded only when no other SEO plugin is active.
 *
 * @package Sox_SEO
 */

defined( 'ABSPATH' ) || exit;

class Sox_SEO_Frontend {

	public static function init() {
		add_filter( 'pre_get_document_title', array( __CLASS__, 'document_title' ), 20 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ), 20 );
		remove_action( 'wp_head', 'rel_canonical' );
		add_action( 'wp_head', array( __CLASS__, 'head' ), 1 );
		// The Hello Elementor theme prints its own meta description from the
		// excerpt, which would leave pages with two different descriptions.
		add_filter( 'hello_elementor_description_meta_tag', '__return_false' );
		self::bridge_rank_math_filters();
	}

	/**
	 * Keep site customisations written for Rank Math working after a switch.
	 *
	 * Themes and small site plugins often adjust titles or descriptions
	 * through Rank Math's filters (translated titles on a multilingual site,
	 * for instance). With Rank Math gone those filters would never run, so
	 * each Sox SEO filter also passes its value through the Rank Math filter
	 * of the same meaning. New code should use the sox_seo_* filters.
	 */
	private static function bridge_rank_math_filters() {
		$map = array(
			'sox_seo_title'          => 'rank_math/frontend/title',
			'sox_seo_description'    => 'rank_math/frontend/description',
			'sox_seo_canonical'      => 'rank_math/frontend/canonical',
			'sox_seo_og_title'       => 'rank_math/opengraph/facebook/og_title',
			'sox_seo_og_description' => 'rank_math/opengraph/facebook/og_description',
			'sox_seo_og_locale'      => 'rank_math/opengraph/facebook/og_locale',
			'sox_seo_product_schema' => 'rank_math/snippet/rich_snippet_product_entity',
		);
		foreach ( $map as $ours => $theirs ) {
			add_filter(
				$ours,
				static function ( $value ) use ( $theirs ) {
					return has_filter( $theirs ) ? apply_filters( $theirs, $value ) : $value;
				},
				5
			);
		}
	}

	/**
	 * The queried post on singular views, including the static front page and
	 * the WooCommerce shop page.
	 *
	 * @return WP_Post|null
	 */
	public static function current_post() {
		if ( is_singular() ) {
			return get_queried_object();
		}
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return get_post( wc_get_page_id( 'shop' ) );
		}
		if ( is_home() && ! is_front_page() ) {
			return get_post( (int) get_option( 'page_for_posts' ) );
		}
		return null;
	}

	/**
	 * @return WP_Term|null
	 */
	public static function current_term() {
		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			return $term instanceof WP_Term ? $term : null;
		}
		return null;
	}

	/**
	 * Title or description written for an extra language, for the page
	 * being viewed.
	 *
	 * @param string $field title|description.
	 * @param string $lang  Language slug.
	 * @return string
	 */
	private static function lang_field( $field, $lang ) {
		$post = self::current_post();
		if ( $post ) {
			return Sox_SEO_Lang::get_post( $post->ID, $field, $lang );
		}
		$term = self::current_term();
		return $term ? Sox_SEO_Lang::get_term( $term->term_id, $field, $lang ) : '';
	}

	/**
	 * Expand %placeholders% in a title template.
	 *
	 * @param string $template Template.
	 * @param array  $vars     Extra replacements.
	 * @return string
	 */
	private static function expand( $template, array $vars = array() ) {
		$vars = array_merge(
			array(
				'%sitename%' => get_bloginfo( 'name' ),
				'%tagline%'  => get_bloginfo( 'description' ),
				'%sep%'      => Sox_SEO_Options::get( 'separator' ),
				'%title%'    => '',
				'%term%'     => '',
			),
			$vars
		);
		$out  = strtr( $template, $vars );
		$sep  = preg_quote( $vars['%sep%'], '/' );
		// Tidy up when a placeholder was empty.
		$out = preg_replace( '/\s+/u', ' ', $out );
		$out = preg_replace( '/^(\s*' . $sep . '\s*)+|(\s*' . $sep . '\s*)+$/u', '', $out );
		return trim( $out );
	}

	/**
	 * @param string $title Title computed by core or an earlier filter.
	 * @return string
	 */
	public static function document_title( $title ) {
		$sep   = Sox_SEO_Options::get( 'separator' );
		$paged = max( (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
		$out   = '';
		$lang  = Sox_SEO_Lang::current();
		$local = $lang ? self::lang_field( 'title', $lang ) : '';

		if ( '' !== $local ) {
			// A title written for this language, with the site's name in it.
			$out = self::expand(
				self::current_term() ? Sox_SEO_Options::get( 'title_term' ) : Sox_SEO_Options::get( 'title_single' ),
				array(
					'%title%'    => $local,
					'%term%'     => $local,
					'%sitename%' => Sox_SEO_Lang::site_name( $lang ),
				)
			);
		} elseif ( is_front_page() && Sox_SEO_Options::get( 'home_title' ) ) {
			$out = self::expand( Sox_SEO_Options::get( 'home_title' ) );
		} elseif ( $post = self::current_post() ) { // phpcs:ignore
			$custom = Sox_SEO_Meta::get_post( $post->ID, 'title' );
			$out    = self::expand(
				Sox_SEO_Options::get( 'title_single' ),
				array( '%title%' => '' !== $custom ? $custom : wp_strip_all_tags( get_the_title( $post ) ) )
			);
		} elseif ( $term = self::current_term() ) { // phpcs:ignore
			$custom = Sox_SEO_Meta::get_term( $term->term_id, 'title' );
			$out    = self::expand(
				Sox_SEO_Options::get( 'title_term' ),
				array( '%term%' => '' !== $custom ? $custom : $term->name )
			);
		}

		if ( '' !== $out && $paged > 1 ) {
			/* translators: %d: page number */
			$out .= ' ' . $sep . ' ' . sprintf( __( 'Page %d', 'sox-seo' ), $paged );
		}
		/**
		 * Filter the document title.
		 *
		 * @param string $out Title; empty when Sox SEO has none for this view.
		 */
		$out = (string) apply_filters( 'sox_seo_title', $out );
		if ( '' === $out ) {
			return $title;
		}
		// WordPress prints this value into <title> as-is, so escape it here.
		return esc_html( $out );
	}

	/**
	 * @return string
	 */
	public static function description() {
		$out  = '';
		$lang = Sox_SEO_Lang::current();
		if ( $lang && '' !== self::lang_field( 'description', $lang ) ) {
			$out = self::lang_field( 'description', $lang );
		} elseif ( is_front_page() && Sox_SEO_Options::get( 'home_description' ) ) {
			$out = Sox_SEO_Options::get( 'home_description' );
		} elseif ( $post = self::current_post() ) { // phpcs:ignore
			$out = Sox_SEO_Meta::get_post( $post->ID, 'description' );
			if ( '' === $out ) {
				$out = $post->post_excerpt ? $post->post_excerpt : wp_trim_words( Sox_SEO_AI::plain_content( $post ), 28, '…' );
			}
		} elseif ( $term = self::current_term() ) { // phpcs:ignore
			$out = Sox_SEO_Meta::get_term( $term->term_id, 'description' );
			if ( '' === $out ) {
				$out = wp_trim_words( wp_strip_all_tags( $term->description ), 28, '…' );
			}
		} elseif ( is_front_page() ) {
			$out = get_bloginfo( 'description' );
		}
		$out = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $out ) ) );
		/**
		 * Filter the meta description.
		 *
		 * @param string $out Description.
		 */
		return (string) apply_filters( 'sox_seo_description', $out );
	}

	/**
	 * @return string
	 */
	public static function canonical() {
		/**
		 * Filter the canonical URL.
		 *
		 * @param string $url Canonical URL; empty prints no tag.
		 */
		return (string) apply_filters( 'sox_seo_canonical', self::canonical_url() );
	}

	/**
	 * @return string
	 */
	private static function canonical_url() {
		$paged = (int) get_query_var( 'paged' );
		$post  = self::current_post();

		if ( $post ) {
			$custom = Sox_SEO_Meta::get_post( $post->ID, 'canonical' );
			if ( $custom ) {
				return $custom;
			}
			if ( is_singular() ) {
				return (string) wp_get_canonical_url( $post );
			}
			$url = get_permalink( $post );
		} elseif ( $term = self::current_term() ) { // phpcs:ignore
			$url = get_term_link( $term );
			$url = is_wp_error( $url ) ? '' : $url;
		} elseif ( is_front_page() || is_home() ) {
			$url = home_url( '/' );
		} elseif ( is_post_type_archive() ) {
			$url = get_post_type_archive_link( get_query_var( 'post_type' ) );
		} elseif ( is_author() ) {
			$url = get_author_posts_url( get_queried_object_id() );
		} else {
			return '';
		}

		if ( $url && $paged > 1 ) {
			$url = get_pagenum_link( $paged );
			$url = strtok( $url, '?' );
		}
		return (string) $url;
	}

	/**
	 * @param array $robots Directives.
	 * @return array
	 */
	public static function robots( $robots ) {
		$noindex = false;
		$post    = self::current_post();
		if ( $post && Sox_SEO_Meta::get_post( $post->ID, 'noindex' ) ) {
			$noindex = true;
		}
		$term = self::current_term();
		if ( $term && Sox_SEO_Meta::get_term( $term->term_id, 'noindex' ) ) {
			$noindex = true;
		}
		if ( is_search() && Sox_SEO_Options::get( 'noindex_search' ) ) {
			$noindex = true;
		}
		if ( Sox_SEO_Options::get( 'noindex_tags' ) && ( is_tag() || is_tax( 'product_tag' ) ) ) {
			$noindex = true;
		}
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			$noindex = true;
		}

		if ( $noindex ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['index'], $robots['max-image-preview'] );
			return $robots;
		}
		if ( empty( $robots['noindex'] ) ) {
			$robots['index']             = true;
			$robots['follow']            = true;
			$robots['max-snippet']       = '-1';
			$robots['max-video-preview'] = '-1';
			$robots['max-image-preview'] = 'large';
		}
		return $robots;
	}

	/**
	 * Social image for the current view.
	 *
	 * @return array|null {url, width, height}
	 */
	public static function image() {
		$post = self::current_post();
		if ( $post && Sox_SEO_Meta::get_post( $post->ID, 'og_image' ) ) {
			return array(
				'url'    => Sox_SEO_Meta::get_post( $post->ID, 'og_image' ),
				'width'  => 0,
				'height' => 0,
			);
		}
		if ( $post && has_post_thumbnail( $post ) ) {
			$src = wp_get_attachment_image_src( get_post_thumbnail_id( $post ), 'full' );
			if ( $src ) {
				return array(
					'url'    => $src[0],
					'width'  => (int) $src[1],
					'height' => (int) $src[2],
				);
			}
		}
		$term = self::current_term();
		if ( $term ) {
			$thumb = (int) get_term_meta( $term->term_id, 'thumbnail_id', true ); // WooCommerce category image.
			$src   = $thumb ? wp_get_attachment_image_src( $thumb, 'full' ) : false;
			if ( $src ) {
				return array(
					'url'    => $src[0],
					'width'  => (int) $src[1],
					'height' => (int) $src[2],
				);
			}
		}
		$default = Sox_SEO_Options::get( 'default_og_image' );
		if ( $default ) {
			return array(
				'url'    => $default,
				'width'  => 0,
				'height' => 0,
			);
		}
		return null;
	}

	public static function head() {
		if ( is_404() ) {
			return;
		}

		$title       = html_entity_decode( wp_get_document_title(), ENT_QUOTES, 'UTF-8' );
		$description = self::description();
		$canonical   = self::canonical();
		$image       = self::image();
		$post        = self::current_post();

		echo "\n<!-- Sox SEO " . esc_html( SOX_SEO_VERSION ) . " -->\n";
		if ( $description ) {
			printf( "<meta name=\"description\" content=\"%s\" />\n", esc_attr( $description ) );
		}
		if ( $canonical ) {
			printf( "<link rel=\"canonical\" href=\"%s\" />\n", esc_url( $canonical ) );
		}

		$type = 'website';
		if ( is_singular( 'product' ) ) {
			$type = 'product';
		} elseif ( is_singular() && ! is_front_page() && ! is_page() ) {
			$type = 'article';
		}

		// Optional wording for social shares, set per page.
		$social_title       = $post ? Sox_SEO_Meta::get_post( $post->ID, 'og_title' ) : '';
		$social_description = $post ? Sox_SEO_Meta::get_post( $post->ID, 'og_description' ) : '';
		$social_title       = (string) apply_filters( 'sox_seo_og_title', '' !== $social_title ? $social_title : $title );
		$social_description = (string) apply_filters( 'sox_seo_og_description', '' !== $social_description ? $social_description : $description );

		$og = array(
			'og:locale'      => (string) apply_filters( 'sox_seo_og_locale', Sox_SEO_Lang::current() ? Sox_SEO_Lang::languages()[ Sox_SEO_Lang::current() ]['locale'] : get_locale() ),
			'og:type'        => $type,
			'og:title'       => $social_title,
			'og:description' => $social_description,
			'og:url'         => $canonical,
			'og:site_name'   => get_bloginfo( 'name' ),
		);
		if ( 'article' === $type && $post ) {
			$og['article:published_time'] = get_post_time( 'c', true, $post );
			$og['article:modified_time']  = get_post_modified_time( 'c', true, $post );
		}
		if ( 'product' === $type && $post && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );
			if ( $product && '' !== (string) $product->get_price() ) {
				$og['product:price:amount']   = wc_format_decimal( $product->get_price(), wc_get_price_decimals() );
				$og['product:price:currency'] = get_woocommerce_currency();
				$og['product:availability']   = $product->is_in_stock() ? 'instock' : 'oos';
			}
		}
		if ( $image ) {
			$og['og:image'] = $image['url'];
			if ( $image['width'] ) {
				$og['og:image:width']  = $image['width'];
				$og['og:image:height'] = $image['height'];
			}
		}
		foreach ( $og as $property => $content ) {
			if ( '' !== (string) $content ) {
				printf( "<meta property=\"%s\" content=\"%s\" />\n", esc_attr( $property ), esc_attr( $content ) );
			}
		}
		// Other language versions of this page (Polylang).
		if ( $post && function_exists( 'pll_get_post_translations' ) && function_exists( 'pll_get_post_language' ) ) {
			foreach ( (array) pll_get_post_translations( $post->ID ) as $translation_id ) {
				$locale = (int) $translation_id !== (int) $post->ID ? pll_get_post_language( $translation_id, 'locale' ) : '';
				if ( $locale ) {
					printf( "<meta property=\"og:locale:alternate\" content=\"%s\" />\n", esc_attr( $locale ) );
				}
			}
		}

		$twitter = array(
			'twitter:card'        => $image ? 'summary_large_image' : 'summary',
			'twitter:title'       => $social_title,
			'twitter:description' => $social_description,
			'twitter:image'       => $image ? $image['url'] : '',
		);
		foreach ( $twitter as $name => $content ) {
			if ( '' !== (string) $content ) {
				printf( "<meta name=\"%s\" content=\"%s\" />\n", esc_attr( $name ), esc_attr( $content ) );
			}
		}
		echo "<!-- /Sox SEO -->\n";
	}
}
