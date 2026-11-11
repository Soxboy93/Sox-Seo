<?php
/**
 * Second-language SEO for TranslatePress sites.
 *
 * TranslatePress serves every language from the same post, so a page has
 * one set of SEO fields. This adds a title and description per extra
 * language, written by hand or by Claude, and a sitemap of the translated
 * URLs. Polylang and WPML keep each language as its own post, so they need
 * none of this.
 *
 * @package Sox_SEO
 */

defined( 'ABSPATH' ) || exit;

class Sox_SEO_Lang {

	public static function init() {
		if ( self::languages() && Sox_SEO_Meta::is_native() && Sox_SEO_Options::get( 'lang_sitemap' ) ) {
			add_action( 'wp_sitemaps_init', array( __CLASS__, 'register_sitemap' ) );
		}
	}

	/**
	 * TranslatePress settings, or null when it is not in use.
	 *
	 * @return array|null
	 */
	private static function trp() {
		if ( ! class_exists( 'TRP_Translate_Press' ) ) {
			return null;
		}
		$settings = get_option( 'trp_settings' );
		return ( is_array( $settings ) && ! empty( $settings['translation-languages'] ) ) ? $settings : null;
	}

	/**
	 * Extra languages, keyed by URL slug.
	 *
	 * @return array<string,array{locale:string,label:string}>
	 */
	public static function languages() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache    = array();
		$settings = self::trp();
		if ( ! $settings ) {
			return $cache;
		}
		$default = isset( $settings['default-language'] ) ? $settings['default-language'] : '';
		foreach ( (array) $settings['translation-languages'] as $locale ) {
			if ( $locale === $default ) {
				continue;
			}
			$slug  = isset( $settings['url-slugs'][ $locale ] ) ? $settings['url-slugs'][ $locale ] : strtolower( substr( $locale, 0, 2 ) );
			$slug  = sanitize_key( $slug );
			$label = class_exists( 'Locale' ) ? (string) Locale::getDisplayLanguage( $locale, 'en' ) : '';
			if ( '' === $label || $label === $locale ) {
				$names = array( 'ar' => 'Arabic', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish', 'tr' => 'Turkish', 'it' => 'Italian', 'ru' => 'Russian', 'en' => 'English' );
				$code  = strtolower( substr( $locale, 0, 2 ) );
				$label = isset( $names[ $code ] ) ? $names[ $code ] : $locale;
			}
			$cache[ $slug ] = array(
				'locale' => $locale,
				'label'  => $label,
			);
		}
		return $cache;
	}

	/**
	 * Slug of the extra language being viewed, or '' on the main language.
	 *
	 * @return string
	 */
	public static function current() {
		global $TRP_LANGUAGE; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		if ( empty( $TRP_LANGUAGE ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			return '';
		}
		foreach ( self::languages() as $slug => $language ) {
			if ( $language['locale'] === $TRP_LANGUAGE ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				return $slug;
			}
		}
		return '';
	}

	/**
	 * Meta key of a per-language field.
	 *
	 * @param string $field title|description.
	 * @param string $lang  Language slug.
	 * @return string
	 */
	public static function key( $field, $lang ) {
		return Sox_SEO_Meta::PREFIX . $field . '_' . sanitize_key( $lang );
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $field   title|description.
	 * @param string $lang    Language slug.
	 * @return string
	 */
	public static function get_post( $post_id, $field, $lang ) {
		return (string) get_post_meta( $post_id, self::key( $field, $lang ), true );
	}

	/**
	 * @param int    $term_id Term ID.
	 * @param string $field   title|description.
	 * @param string $lang    Language slug.
	 * @return string
	 */
	public static function get_term( $term_id, $field, $lang ) {
		return (string) get_term_meta( $term_id, self::key( $field, $lang ), true );
	}

	/**
	 * @param string $kind  post|term.
	 * @param int    $id    Object ID.
	 * @param string $field title|description.
	 * @param string $lang  Language slug.
	 * @param string $value Value; empty deletes.
	 */
	public static function set( $kind, $id, $field, $lang, $value ) {
		if ( ! isset( self::languages()[ $lang ] ) ) {
			return;
		}
		$key   = self::key( $field, $lang );
		$value = trim( (string) $value );
		if ( 'post' === $kind ) {
			'' === $value ? delete_post_meta( $id, $key ) : update_post_meta( $id, $key, wp_slash( $value ) );
		} else {
			'' === $value ? delete_term_meta( $id, $key ) : update_term_meta( $id, $key, wp_slash( $value ) );
		}
	}

	/**
	 * The site's name in a language, for the end of titles.
	 *
	 * @param string $lang Language slug.
	 * @return string
	 */
	public static function site_name( $lang ) {
		$names = (array) Sox_SEO_Options::get( 'lang_sitenames' );
		return ! empty( $names[ $lang ] ) ? (string) $names[ $lang ] : trim( get_bloginfo( 'name' ) );
	}

	/**
	 * The address of a page in another language.
	 *
	 * @param string $url  URL in the main language.
	 * @param string $lang Language slug.
	 * @return string
	 */
	public static function url( $url, $lang ) {
		$languages = self::languages();
		if ( ! isset( $languages[ $lang ] ) ) {
			return $url;
		}
		$home = trailingslashit( home_url() );
		if ( 0 === strpos( $url, $home ) ) {
			return $home . $lang . '/' . substr( $url, strlen( $home ) );
		}
		return $url;
	}

	/**
	 * Published posts with no description in a language yet.
	 *
	 * @param string $lang    Language slug.
	 * @param int    $limit   Max IDs.
	 * @param int[]  $exclude IDs to skip.
	 * @return WP_Query
	 */
	public static function missing_query( $lang, $limit = 1, array $exclude = array() ) {
		$key = self::key( 'description', $lang );
		return new WP_Query(
			array(
				'post_type'      => Sox_SEO_Meta::post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'post__not_in'   => array_merge( $exclude, Sox_SEO_Meta::utility_page_ids() ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'relation' => 'OR',
						array(
							'key'     => $key,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'   => $key,
							'value' => '',
						),
					),
					array(
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
				),
			)
		);
	}

	/**
	 * @param WP_Sitemaps $sitemaps Core sitemaps object.
	 */
	public static function register_sitemap( $sitemaps ) {
		require_once SOX_SEO_DIR . 'includes/class-sox-seo-lang-sitemap.php';
		$sitemaps->registry->add_provider( 'languages', new Sox_SEO_Lang_Sitemap() );
	}
}
