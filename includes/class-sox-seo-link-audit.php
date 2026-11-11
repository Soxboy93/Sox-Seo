<?php
/**
 * Link audit: finds broken links and pages no other page links to.
 *
 * The scan is driven from the admin screen a few posts at a time, so it
 * never runs into a server time limit however large the site is.
 *
 * @package Sox_SEO
 */

defined( 'ABSPATH' ) || exit;

class Sox_SEO_Link_Audit {

	const OPTION        = 'sox_seo_link_audit';
	const POSTS_PER_RUN = 3;

	/**
	 * @return array Scan state and results.
	 */
	public static function state() {
		$state = get_option( self::OPTION, array() );
		return array_merge(
			array(
				'inbound'  => array(), // post ID => number of pages linking to it.
				'broken'   => array(), // list of {post_id, url, status}.
				'checked'  => array(), // url => status, so each URL is fetched once.
				'scanned'  => 0,
				'total'    => 0,
				'finished' => 0,
			),
			is_array( $state ) ? $state : array()
		);
	}

	/**
	 * Post types whose content is scanned.
	 *
	 * @return string[]
	 */
	private static function post_types() {
		return Sox_SEO_Meta::post_types();
	}

	/**
	 * Scan the next few posts.
	 *
	 * @param bool $restart Start a new scan.
	 * @return array {scanned, total, done}
	 */
	public static function step( $restart = false ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$state = self::state();
		if ( $restart || $state['finished'] ) {
			$counts = 0;
			foreach ( self::post_types() as $type ) {
				$counts += (int) wp_count_posts( $type )->publish;
			}
			$state = array(
				'inbound'  => array(),
				'broken'   => array(),
				'checked'  => array(),
				'scanned'  => 0,
				'total'    => $counts,
				'finished' => 0,
			);
		}

		$posts = get_posts(
			array(
				'post_type'      => self::post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => self::POSTS_PER_RUN,
				'offset'         => $state['scanned'],
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( $posts as $post ) {
			$targets = array();
			foreach ( self::extract_links( $post ) as $url ) {
				$is_internal = wp_parse_url( $url, PHP_URL_HOST ) === $host;
				$target_id   = $is_internal ? url_to_postid( $url ) : 0;
				if ( $target_id ) {
					if ( $target_id !== $post->ID ) {
						$targets[ $target_id ] = true;
					}
					continue; // Resolves to a live post: not broken.
				}
				if ( ! isset( $state['checked'][ $url ] ) ) {
					if ( count( $state['checked'] ) > 3000 ) {
						continue;
					}
					$state['checked'][ $url ] = self::http_status( $url, $is_internal );
				}
				$status = $state['checked'][ $url ];
				// "No response" from this site's own URLs usually means the
				// server cannot call itself, not that the page is gone.
				$broken = $is_internal ? array( 404, 410 ) : array( 0, 404, 410 );
				if ( in_array( $status, $broken, true ) && count( $state['broken'] ) < 1000 ) {
					$state['broken'][] = array(
						'post_id' => $post->ID,
						'url'     => $url,
						'status'  => $status,
					);
				}
			}
			foreach ( array_keys( $targets ) as $target_id ) {
				$state['inbound'][ $target_id ] = isset( $state['inbound'][ $target_id ] ) ? $state['inbound'][ $target_id ] + 1 : 1;
			}
			$state['scanned']++;
		}

		if ( count( $posts ) < self::POSTS_PER_RUN ) {
			$state['finished'] = time();
			$state['checked']  = array(); // Only needed during a scan.
		}
		update_option( self::OPTION, $state, false );

		return array(
			'scanned' => $state['scanned'],
			'total'   => max( $state['total'], $state['scanned'] ),
			'done'    => (bool) $state['finished'],
		);
	}

	/**
	 * Absolute http(s) links found in a post's content.
	 *
	 * @param WP_Post $post Post.
	 * @return string[]
	 */
	public static function extract_links( $post ) {
		$html = $post->post_content;
		// Elementor keeps the real page markup in its own field.
		$elementor = get_post_meta( $post->ID, '_elementor_data', true );
		if ( $elementor && is_string( $elementor ) ) {
			$html .= ' ' . stripslashes( str_replace( '\\/', '/', $elementor ) );
		}
		if ( ! preg_match_all( '#<a\b[^>]*?\bhref=\\\\?["\']([^"\'\\\\]+)#i', $html, $matches ) ) {
			return array();
		}
		$out = array();
		foreach ( $matches[1] as $href ) {
			$href = html_entity_decode( trim( $href ), ENT_QUOTES );
			if ( '' === $href || '#' === $href[0] || preg_match( '#^(mailto|tel|sms|javascript|data|whatsapp):#i', $href ) ) {
				continue;
			}
			if ( 0 === strpos( $href, '//' ) ) {
				$href = ( is_ssl() ? 'https:' : 'http:' ) . $href;
			} elseif ( '/' === $href[0] ) {
				$href = home_url( $href );
			}
			if ( ! preg_match( '#^https?://#i', $href ) ) {
				continue;
			}
			$out[ strtok( $href, '#' ) ] = true;
		}
		return array_keys( $out );
	}

	/**
	 * HTTP status for a URL; 0 when it cannot be reached at all.
	 *
	 * @param string $url         URL.
	 * @param bool   $is_internal Whether the URL is on this site.
	 * @return int
	 */
	private static function http_status( $url, $is_internal ) {
		$args = array(
			'timeout'     => 8,
			'redirection' => 3,
			'user-agent'  => 'Mozilla/5.0 (compatible; link checker; +' . home_url( '/' ) . ')',
		);
		// The "safe" variants refuse private addresses, which protects against
		// links pointing into the server's own network.
		$response = $is_internal ? wp_remote_head( $url, $args ) : wp_safe_remote_head( $url, $args );
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		// Some servers refuse HEAD requests; ask again properly before judging.
		if ( in_array( $code, array( 0, 403, 404, 405, 501 ), true ) ) {
			$args['limit_response_size'] = 2048;
			$response                    = $is_internal ? wp_remote_get( $url, $args ) : wp_safe_remote_get( $url, $args );
			$code                        = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		}
		return $code;
	}

	/**
	 * Published posts and pages that no other page's content links to.
	 * Products are left out: they are reached through category pages.
	 *
	 * @param int $limit Max IDs.
	 * @return int[]
	 */
	public static function orphans( $limit = 200 ) {
		$state = self::state();
		if ( ! $state['finished'] ) {
			return array();
		}
		$types   = array_diff( self::post_types(), array( 'product' ) );
		$exclude = array_merge(
			array_map( 'intval', array_keys( $state['inbound'] ) ),
			Sox_SEO_Meta::utility_page_ids(),
			array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) )
		);
		if ( function_exists( 'wc_get_page_id' ) ) {
			$exclude[] = (int) wc_get_page_id( 'shop' );
		}
		$ids = get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'post__not_in'   => array_filter( $exclude ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
				'orderby'        => 'modified',
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			if ( ! Sox_SEO_Meta::get_post( $id, 'noindex' ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}
}
