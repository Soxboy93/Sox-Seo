<?php
/**
 * Page speed report, using Google's PageSpeed Insights.
 *
 * @package Sox_SEO
 */

defined( 'ABSPATH' ) || exit;

class Sox_SEO_Speed {

	const OPTION   = 'sox_seo_speed';
	const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

	/**
	 * Pages worth testing: one of each template.
	 *
	 * @return array<string,string> URL => label.
	 */
	public static function default_urls() {
		$urls = array( home_url( '/' ) => __( 'Home page', 'sox-seo' ) );
		if ( function_exists( 'wc_get_page_id' ) ) {
			$shop = wc_get_page_id( 'shop' );
			if ( $shop > 0 ) {
				$urls[ get_permalink( $shop ) ] = __( 'Shop', 'sox-seo' );
			}
			$products = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			if ( $products ) {
				$urls[ get_permalink( $products[0] ) ] = __( 'A product page', 'sox-seo' );
			}
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => true,
					'number'     => 1,
					'orderby'    => 'count',
					'order'      => 'DESC',
				)
			);
			if ( ! is_wp_error( $terms ) && $terms ) {
				$link = get_term_link( $terms[0] );
				if ( ! is_wp_error( $link ) ) {
					$urls[ $link ] = __( 'A category page', 'sox-seo' );
				}
			}
		} else {
			$posts = get_posts(
				array(
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			if ( $posts ) {
				$urls[ get_permalink( $posts[0] ) ] = __( 'Latest post', 'sox-seo' );
			}
		}
		return $urls;
	}

	/**
	 * @return array<string,array> Saved results keyed by URL.
	 */
	public static function results() {
		$results = get_option( self::OPTION, array() );
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Test one page on a simulated phone and save the result.
	 *
	 * @param string $url Page on this site.
	 * @return array|WP_Error
	 */
	public static function test( $url ) {
		$url = esc_url_raw( $url );
		if ( wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return new WP_Error( 'sox_seo_speed', __( 'Only pages on this site can be tested.', 'sox-seo' ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$args = array(
			'url'      => $url,
			'strategy' => 'mobile',
			'category' => 'performance',
		);
		$key  = trim( (string) Sox_SEO_Options::get( 'psi_key' ) );
		if ( $key ) {
			$args['key'] = $key;
		}
		$response = wp_remote_get( add_query_arg( array_map( 'rawurlencode', $args ), self::ENDPOINT ), array( 'timeout' => 90 ) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'sox_seo_speed', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || empty( $data['lighthouseResult'] ) ) {
			$detail = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Google could not test this page.', 'sox-seo' );
			if ( 429 === $code ) {
				$detail = __( 'Google\'s free test quota is used up for now. Try again later, or add a PageSpeed API key in settings.', 'sox-seo' );
			}
			return new WP_Error( 'sox_seo_speed', $detail );
		}

		$lighthouse = $data['lighthouseResult'];
		$audits     = isset( $lighthouse['audits'] ) ? $lighthouse['audits'] : array();
		$display    = static function ( $id ) use ( $audits ) {
			return isset( $audits[ $id ]['displayValue'] ) ? (string) $audits[ $id ]['displayValue'] : '';
		};

		$fixes = array();
		foreach ( $audits as $audit ) {
			$saving = isset( $audit['details']['overallSavingsMs'] ) ? (float) $audit['details']['overallSavingsMs'] : 0;
			if ( $saving >= 100 && isset( $audit['score'] ) && $audit['score'] < 0.9 ) {
				$fixes[] = array(
					'title'  => wp_strip_all_tags( (string) $audit['title'] ),
					'saving' => (int) round( $saving ),
				);
			}
		}
		usort(
			$fixes,
			static function ( $a, $b ) {
				return $b['saving'] - $a['saving'];
			}
		);

		// What real Chrome visitors experienced over the last 28 days, when
		// the page has enough traffic for Google to report it.
		$field   = array();
		$metrics = isset( $data['loadingExperience']['metrics'] ) ? $data['loadingExperience']['metrics'] : array();
		$names   = array(
			'LARGEST_CONTENTFUL_PAINT_MS'  => 'lcp',
			'INTERACTION_TO_NEXT_PAINT'    => 'inp',
			'CUMULATIVE_LAYOUT_SHIFT_SCORE' => 'cls',
		);
		foreach ( $names as $api => $short ) {
			if ( isset( $metrics[ $api ]['category'] ) ) {
				$field[ $short ] = strtolower( str_replace( '_', ' ', (string) $metrics[ $api ]['category'] ) );
			}
		}

		$result = array(
			'score' => isset( $lighthouse['categories']['performance']['score'] ) ? (int) round( $lighthouse['categories']['performance']['score'] * 100 ) : 0,
			'lcp'   => $display( 'largest-contentful-paint' ),
			'cls'   => $display( 'cumulative-layout-shift' ),
			'tbt'   => $display( 'total-blocking-time' ),
			// Google words this one as a sentence; keep just the time.
			'ttfb'  => preg_match( '/[\d.,]+\s*m?s/', $display( 'server-response-time' ), $match ) ? $match[0] : '',
			'fixes' => array_slice( $fixes, 0, 6 ),
			'field' => $field,
			'time'  => time(),
		);

		$results         = self::results();
		$results[ $url ] = $result;
		update_option( self::OPTION, array_slice( $results, -20, null, true ), false );
		return $result;
	}
}
