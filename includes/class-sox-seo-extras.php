<?php
/**
 * Smaller features that work in every mode: IndexNow, llms.txt, AI-crawler
 * rules, visible breadcrumbs and FAQ schema output.
 *
 * @package Sox_SEO
 */

defined( 'ABSPATH' ) || exit;

class Sox_SEO_Extras {

	const INDEXNOW_KEY = 'sox_seo_indexnow_key';

	/**
	 * Crawlers that collect pages to train AI models. Search crawlers
	 * (Googlebot, Bingbot) and AI assistants fetching a page for a user are
	 * deliberately not listed.
	 *
	 * @var string[]
	 */
	private static $training_bots = array( 'GPTBot', 'ClaudeBot', 'anthropic-ai', 'CCBot', 'Google-Extended', 'Applebot-Extended', 'Bytespider', 'meta-externalagent', 'cohere-ai' );

	public static function init() {
		// After every post type is registered, so products are included.
		add_action( 'wp_loaded', array( __CLASS__, 'serve_files' ) );
		add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 30, 2 );
		add_shortcode( 'sox_seo_breadcrumbs', array( __CLASS__, 'breadcrumbs_shortcode' ) );
		add_action( 'wp_head', array( __CLASS__, 'faq_schema' ), 21 );

		if ( Sox_SEO_Options::get( 'indexnow' ) ) {
			add_action( 'transition_post_status', array( __CLASS__, 'indexnow_on_change' ), 20, 3 );
		}
	}

	/* ---- /llms.txt and the IndexNow key file ---------------------------- */

	public static function serve_files() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$file = ltrim( substr( $path, strlen( $base ) ), '/' );

		if ( 'llms.txt' === $file && Sox_SEO_Options::get( 'llms_txt' ) ) {
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo self::llms_txt(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		if ( Sox_SEO_Options::get( 'indexnow' ) && self::indexnow_key() . '.txt' === $file ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html( self::indexnow_key() );
			exit;
		}
	}

	/**
	 * A plain-text guide to the site for AI assistants.
	 *
	 * @return string
	 */
	public static function llms_txt() {
		$lines   = array( '# ' . get_bloginfo( 'name' ), '' );
		$tagline = Sox_SEO_Options::get( 'home_description' ) ? Sox_SEO_Options::get( 'home_description' ) : get_bloginfo( 'description' );
		if ( $tagline ) {
			$lines[] = '> ' . trim( preg_replace( '/\s+/u', ' ', $tagline ) );
			$lines[] = '';
		}

		$sections = array(
			'page'    => array( __( 'Pages', 'sox-seo' ), 30 ),
			'product' => array( __( 'Products', 'sox-seo' ), 50 ),
			'post'    => array( __( 'Articles', 'sox-seo' ), 30 ),
		);
		foreach ( $sections as $type => $section ) {
			if ( ! post_type_exists( $type ) ) {
				continue;
			}
			$ids = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'publish',
					'posts_per_page' => $section[1],
					'fields'         => 'ids',
					'orderby'        => 'modified',
					'post__not_in'   => Sox_SEO_Meta::utility_page_ids(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
					'has_password'   => false,
				)
			);
			$items = array();
			foreach ( $ids as $id ) {
				if ( Sox_SEO_Meta::get_post( $id, 'noindex' ) ) {
					continue;
				}
				$description = Sox_SEO_Meta::get_post( $id, 'description' );
				$description = ( $description && false === strpos( $description, '%' ) ) ? ': ' . $description : '';
				$items[]     = '- [' . wp_strip_all_tags( get_the_title( $id ) ) . '](' . get_permalink( $id ) . ')' . $description;
			}
			if ( $items ) {
				$lines[] = '## ' . $section[0];
				$lines   = array_merge( $lines, $items, array( '' ) );
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * @param string $output robots.txt body.
	 * @param bool   $public Whether the site is visible to search engines.
	 * @return string
	 */
	public static function robots_txt( $output, $public ) {
		if ( ! $public || ! Sox_SEO_Options::get( 'block_ai_training' ) ) {
			return $output;
		}
		$block = '';
		foreach ( self::$training_bots as $bot ) {
			$block .= "\nUser-agent: {$bot}\nDisallow: /\n";
		}
		return rtrim( $output ) . "\n" . $block;
	}

	/* ---- IndexNow -------------------------------------------------------- */

	/**
	 * @return string
	 */
	public static function indexnow_key() {
		$key = get_option( self::INDEXNOW_KEY );
		if ( ! $key ) {
			$key = strtolower( wp_generate_password( 32, false ) );
			update_option( self::INDEXNOW_KEY, $key, false );
		}
		return $key;
	}

	/**
	 * Tell Bing, Yandex and other IndexNow engines that a page changed.
	 *
	 * @param string  $new  New status.
	 * @param string  $old  Old status.
	 * @param WP_Post $post Post.
	 */
	public static function indexnow_on_change( $new, $old, $post ) {
		// Published, updated, or just taken down: all are worth a re-crawl.
		if ( 'publish' !== $new && 'publish' !== $old ) {
			return;
		}
		if ( ! in_array( $post->post_type, Sox_SEO_Meta::post_types(), true ) || wp_is_post_revision( $post ) ) {
			return;
		}
		if ( get_transient( 'sox_seo_indexnow_' . $post->ID ) ) {
			return; // Editors save repeatedly; one ping per ten minutes is enough.
		}
		set_transient( 'sox_seo_indexnow_' . $post->ID, 1, 10 * MINUTE_IN_SECONDS );
		self::indexnow_submit( array( get_permalink( $post ) ) );
	}

	/**
	 * @param string[] $urls URLs on this site.
	 */
	public static function indexnow_submit( array $urls ) {
		$key = self::indexnow_key();
		wp_remote_post(
			'https://api.indexnow.org/indexnow',
			array(
				'timeout'  => 5,
				'blocking' => false,
				'headers'  => array( 'content-type' => 'application/json; charset=utf-8' ),
				'body'     => wp_json_encode(
					array(
						'host'        => wp_parse_url( home_url(), PHP_URL_HOST ),
						'key'         => $key,
						'keyLocation' => home_url( '/' . $key . '.txt' ),
						'urlList'     => array_values( array_filter( $urls ) ),
					)
				),
			)
		);
	}

	/* ---- Breadcrumbs ----------------------------------------------------- */

	/**
	 * [sox_seo_breadcrumbs separator="›"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function breadcrumbs_shortcode( $atts ) {
		$atts   = shortcode_atts( array( 'separator' => '›' ), $atts, 'sox_seo_breadcrumbs' );
		$crumbs = Sox_SEO_Schema::breadcrumbs();
		if ( count( $crumbs ) < 2 ) {
			return '';
		}
		$last  = count( $crumbs ) - 1;
		$items = array();
		foreach ( $crumbs as $i => $crumb ) {
			$items[] = $i === $last
				? '<span aria-current="page">' . esc_html( $crumb['name'] ) . '</span>'
				: '<a href="' . esc_url( $crumb['url'] ) . '">' . esc_html( $crumb['name'] ) . '</a>';
		}
		$separator = ' <span class="sox-seo-breadcrumbs-sep" aria-hidden="true">' . esc_html( $atts['separator'] ) . '</span> ';
		return '<nav class="sox-seo-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'sox-seo' ) . '">' . implode( $separator, $items ) . '</nav>';
	}

	/* ---- FAQ schema ------------------------------------------------------ */

	/**
	 * Print FAQPage markup for questions found on the page by the editor's
	 * "Detect FAQs" button.
	 */
	public static function faq_schema() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		$faqs = get_post_meta( $post->ID, '_sox_seo_faq', true );
		if ( ! is_array( $faqs ) || ! $faqs ) {
			return;
		}
		// Rank Math and Yoast FAQ blocks print this markup themselves.
		if ( false !== strpos( $post->post_content, 'wp:rank-math/faq-block' ) || false !== strpos( $post->post_content, 'wp:yoast/faq-block' ) ) {
			return;
		}
		$entities = array();
		foreach ( $faqs as $faq ) {
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $faq['question'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $faq['answer'],
				),
			);
		}
		$json = wp_json_encode(
			array(
				'@context'   => 'https://schema.org',
				'@type'      => 'FAQPage',
				'mainEntity' => $entities,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
		);
		echo '<script type="application/ld+json" class="sox-seo-faq">' . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
