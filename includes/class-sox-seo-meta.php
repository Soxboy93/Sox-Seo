<?php
/**
 * SEO meta storage.
 *
 * Reads and writes the SEO title, description and focus keyword. When Rank
 * Math, Yoast SEO or SEOPress is active, the values go into that plugin's own
 * meta keys, so its editor UI, analysis and front-end output pick them up and
 * nothing is printed twice.
 *
 * @package Sox_SEO
 */

defined( 'ABSPATH' ) || exit;

class Sox_SEO_Meta {

	const PREFIX = '_sox_seo_';

	/**
	 * Which SEO plugin owns the front end.
	 *
	 * @return string native|rankmath|yoast|seopress|aioseo
	 */
	public static function provider() {
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'seopress';
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return 'aioseo';
		}
		return 'native';
	}

	/**
	 * @return bool
	 */
	public static function is_native() {
		return 'native' === self::provider();
	}

	/**
	 * @return string Human-readable provider name.
	 */
	public static function provider_label() {
		$labels = array(
			'native'   => 'Sox SEO',
			'rankmath' => 'Rank Math',
			'yoast'    => 'Yoast SEO',
			'seopress' => 'SEOPress',
			'aioseo'   => 'All in One SEO',
		);
		return $labels[ self::provider() ];
	}

	/**
	 * Whether AI output for this object type lands somewhere that is actually
	 * printed on the front end.
	 *
	 * @param string $object post|term.
	 * @return bool
	 */
	public static function is_supported( $object = 'post' ) {
		$provider = self::provider();
		if ( 'aioseo' === $provider ) {
			return false; // Custom tables, no stable meta keys.
		}
		if ( 'yoast' === $provider && 'term' === $object ) {
			return false; // Yoast keeps term meta in a single serialized option.
		}
		return true;
	}

	/**
	 * Meta key for a field under the active provider.
	 *
	 * @param string $field Field name.
	 * @return string
	 */
	public static function key( $field ) {
		$map = array(
			'rankmath' => array(
				'title'         => 'rank_math_title',
				'description'   => 'rank_math_description',
				'focus_keyword' => 'rank_math_focus_keyword',
				'canonical'     => 'rank_math_canonical_url',
			),
			'yoast'    => array(
				'title'         => '_yoast_wpseo_title',
				'description'   => '_yoast_wpseo_metadesc',
				'focus_keyword' => '_yoast_wpseo_focuskw',
				'canonical'     => '_yoast_wpseo_canonical',
			),
			'seopress' => array(
				'title'         => '_seopress_titles_title',
				'description'   => '_seopress_titles_desc',
				'focus_keyword' => '_seopress_analysis_target_kw',
				'canonical'     => '_seopress_robots_canonical',
			),
		);
		$provider = self::provider();
		if ( isset( $map[ $provider ][ $field ] ) ) {
			return $map[ $provider ][ $field ];
		}
		return self::PREFIX . $field;
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $field   Field name.
	 * @return string
	 */
	public static function get_post( $post_id, $field ) {
		return (string) get_post_meta( $post_id, self::key( $field ), true );
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $field   Field name.
	 * @param string $value   Value; empty deletes.
	 */
	public static function set_post( $post_id, $field, $value ) {
		$value = is_string( $value ) ? trim( $value ) : $value;
		if ( '' === $value || null === $value ) {
			delete_post_meta( $post_id, self::key( $field ) );
		} else {
			update_post_meta( $post_id, self::key( $field ), wp_slash( $value ) );
		}
	}

	/**
	 * @param int    $term_id Term ID.
	 * @param string $field   Field name.
	 * @return string
	 */
	public static function get_term( $term_id, $field ) {
		return (string) get_term_meta( $term_id, self::key( $field ), true );
	}

	/**
	 * @param int    $term_id Term ID.
	 * @param string $field   Field name.
	 * @param string $value   Value; empty deletes.
	 */
	public static function set_term( $term_id, $field, $value ) {
		$value = is_string( $value ) ? trim( $value ) : $value;
		if ( '' === $value || null === $value ) {
			delete_term_meta( $term_id, self::key( $field ) );
		} else {
			update_term_meta( $term_id, self::key( $field ), wp_slash( $value ) );
		}
	}

	/**
	 * Post types Sox SEO manages.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		/**
		 * Filter the post types Sox SEO manages.
		 *
		 * @param string[] $types Post type names.
		 */
		return array_values( apply_filters( 'sox_seo_post_types', $types ) );
	}

	/**
	 * Language of a post on a multilingual site (Polylang or WPML), as an
	 * English name Claude can be told to write in. Empty on a single-language
	 * site.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function post_language( $post_id ) {
		$locale = '';
		if ( function_exists( 'pll_get_post_language' ) ) {
			$locale = (string) pll_get_post_language( $post_id, 'locale' );
		} elseif ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$details = apply_filters( 'wpml_post_language_details', null, $post_id );
			$locale  = is_array( $details ) && ! empty( $details['locale'] ) ? (string) $details['locale'] : '';
		}
		if ( '' === $locale ) {
			return '';
		}
		if ( class_exists( 'Locale' ) ) {
			$name = Locale::getDisplayLanguage( $locale, 'en' );
			if ( $name && $name !== $locale ) {
				return $name;
			}
		}
		$names = array( 'ar' => 'Arabic', 'en' => 'English', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish', 'tr' => 'Turkish', 'it' => 'Italian', 'ru' => 'Russian' );
		$code  = strtolower( substr( $locale, 0, 2 ) );
		return isset( $names[ $code ] ) ? $names[ $code ] : $locale;
	}

	/**
	 * WooCommerce cart, checkout and account pages. They are kept out of
	 * search, so they need no SEO copy and should not be linked to.
	 *
	 * @return int[]
	 */
	public static function utility_page_ids() {
		$ids = array();
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				$id = (int) wc_get_page_id( $page );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}
		return $ids;
	}

	/**
	 * Meta query matching indexable posts with no SEO description yet.
	 *
	 * @return array
	 */
	public static function missing_description_query() {
		$key     = self::key( 'description' );
		$missing = array(
			'relation' => 'OR',
			array(
				'key'     => $key,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'   => $key,
				'value' => '',
			),
		);
		if ( ! self::is_native() ) {
			return $missing;
		}
		// Pages hidden from search engines do not need a description.
		return array(
			'relation' => 'AND',
			$missing,
			array(
				'relation' => 'OR',
				array(
					'key'     => self::key( 'noindex' ),
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => self::key( 'noindex' ),
					'value'   => '1',
					'compare' => '!=',
				),
			),
		);
	}
}
