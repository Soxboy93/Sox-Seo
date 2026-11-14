<?php
/**
 * Google Search Console connection.
 *
 * Authenticates with a Google service account (a JSON key pasted into the
 * settings page), so there is no OAuth consent screen or redirect to set up.
 * The service account's e-mail address must be added as a user on the Search
 * Console property.
 *
 * @package Sox_SEO
 */

defined( 'ABSPATH' ) || exit;

class Sox_SEO_GSC {

	const KEY_OPTION = 'sox_seo_gsc_key';
	const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
	const API_BASE   = 'https://www.googleapis.com/webmasters/v3/sites/';
	const SCOPE      = 'https://www.googleapis.com/auth/webmasters.readonly';

	/**
	 * @return array|null Decoded service-account key.
	 */
	public static function credentials() {
		$raw = get_option( self::KEY_OPTION, '' );
		if ( ! $raw ) {
			return null;
		}
		$key = json_decode( $raw, true );
		if ( ! is_array( $key ) || empty( $key['client_email'] ) || empty( $key['private_key'] ) ) {
			return null;
		}
		return $key;
	}

	/**
	 * @return bool
	 */
	public static function is_connected() {
		return '' !== self::source();
	}

	/**
	 * Where Search Console data comes from: the site's own service-account
	 * key, or the Google login that the Site Kit plugin already holds.
	 *
	 * @return string key|sitekit|'' (not connected).
	 */
	public static function source() {
		if ( null !== self::credentials() ) {
			return 'key';
		}
		if ( defined( 'GOOGLESITEKIT_PLUGIN_MAIN_FILE' ) && (int) get_option( 'sox_seo_sitekit_user' ) ) {
			return 'sitekit';
		}
		return '';
	}

	/**
	 * Look for a Site Kit user whose Google login can read Search Console,
	 * and remember them. Site Kit keeps one login per administrator, and an
	 * old one may have expired, so each is tried. Run from admin screens
	 * only: it is too slow for every page load.
	 *
	 * @param bool $force Look again even if a user is already remembered.
	 * @return int User ID, 0 if none.
	 */
	public static function discover_site_kit( $force = false ) {
		if ( ! defined( 'GOOGLESITEKIT_PLUGIN_MAIN_FILE' ) || ! class_exists( '\\Google\\Site_Kit\\Core\\Authentication\\Authentication' ) ) {
			delete_option( 'sox_seo_sitekit_user' );
			return 0;
		}
		$known = (int) get_option( 'sox_seo_sitekit_user' );
		if ( $known && ! $force && get_transient( 'sox_seo_sitekit_checked' ) ) {
			return $known;
		}
		global $wpdb;
		$found = 0;
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s ORDER BY umeta_id DESC", $wpdb->get_blog_prefix() . 'googlesitekit_access_token' ) ); // phpcs:ignore WordPress.DB
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $user_id ) {
			if ( ! user_can( $user_id, 'manage_options' ) ) {
				continue;
			}
			try {
				$auth = self::site_kit_auth( $user_id );
				if ( $auth && $auth->is_authenticated() ) {
					$found = $user_id;
					break;
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}
		update_option( 'sox_seo_sitekit_user', $found, false );
		set_transient( 'sox_seo_sitekit_checked', 1, DAY_IN_SECONDS );
		return $found;
	}

	/**
	 * @param int $user_id User whose Site Kit login to use.
	 * @return object|null Site Kit's Authentication object.
	 */
	private static function site_kit_auth( $user_id ) {
		$context = new \Google\Site_Kit\Context( GOOGLESITEKIT_PLUGIN_MAIN_FILE );
		return new \Google\Site_Kit\Core\Authentication\Authentication(
			$context,
			new \Google\Site_Kit\Core\Storage\Options( $context ),
			new \Google\Site_Kit\Core\Storage\User_Options( $context, $user_id )
		);
	}

	/**
	 * Run a Search Analytics query through Site Kit's Google client.
	 *
	 * These are Site Kit's internal classes, not a published interface, so
	 * every failure (including a future Site Kit changing them) is caught
	 * and reported as an ordinary error.
	 *
	 * @param array $body Query body with dates.
	 * @return array|WP_Error Rows in the REST API's shape.
	 */
	private static function site_kit_query( array $body ) {
		try {
			$auth = self::site_kit_auth( (int) get_option( 'sox_seo_sitekit_user' ) );
			if ( ! $auth || ! $auth->is_authenticated() ) {
				delete_transient( 'sox_seo_sitekit_checked' );
				return new WP_Error( 'sox_seo_gsc', __( 'Site Kit\'s Google login has expired. Open Site Kit and sign in again, or add a service account key.', 'sox-seo' ) );
			}
			$base    = '\\Google\\Site_Kit_Dependencies\\Google\\Service\\SearchConsole';
			$request = $base . '\\SearchAnalyticsQueryRequest';
			$service = new $base( $auth->get_oauth_client()->get_client() );
			$result  = $service->searchanalytics->query( self::property(), new $request( $body ) );
			$rows    = array();
			foreach ( (array) $result->getRows() as $row ) {
				$rows[] = array(
					'keys'        => (array) $row->getKeys(),
					'clicks'      => $row->getClicks(),
					'impressions' => $row->getImpressions(),
					'ctr'         => $row->getCtr(),
					'position'    => $row->getPosition(),
				);
			}
			return $rows;
		} catch ( \Throwable $e ) {
			$message = $e->getMessage();
			$decoded = json_decode( $message, true );
			if ( isset( $decoded['error']['message'] ) ) {
				$message = $decoded['error']['message'];
			}
			return new WP_Error( 'sox_seo_gsc', mb_substr( wp_strip_all_tags( (string) $message ), 0, 300 ) );
		}
	}

	/**
	 * The Search Console property: a URL prefix such as https://example.com/
	 * or a domain property such as sc-domain:example.com.
	 *
	 * @return string
	 */
	public static function property() {
		$property = trim( (string) Sox_SEO_Options::get( 'gsc_property' ) );
		if ( $property ) {
			return $property;
		}
		// Site Kit knows which property the site is verified as.
		$site_kit = get_option( 'googlesitekit_search-console_settings' );
		if ( is_array( $site_kit ) && ! empty( $site_kit['propertyID'] ) ) {
			return (string) $site_kit['propertyID'];
		}
		return home_url( '/' );
	}

	/**
	 * @param string $data Raw bytes.
	 * @return string
	 */
	private static function b64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * Exchange a signed JWT for a short-lived access token.
	 *
	 * @return string|WP_Error
	 */
	private static function token() {
		$cached = get_transient( 'sox_seo_gsc_token' );
		if ( $cached ) {
			return $cached;
		}
		$key = self::credentials();
		if ( ! $key ) {
			return new WP_Error( 'sox_seo_gsc', __( 'Search Console is not connected.', 'sox-seo' ) );
		}
		if ( ! function_exists( 'openssl_sign' ) ) {
			return new WP_Error( 'sox_seo_gsc', __( 'The PHP OpenSSL extension is required for Search Console.', 'sox-seo' ) );
		}

		$now    = time();
		$header = self::b64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims = self::b64url(
			wp_json_encode(
				array(
					'iss'   => $key['client_email'],
					'scope' => self::SCOPE,
					'aud'   => self::TOKEN_URL,
					'iat'   => $now,
					'exp'   => $now + 3600,
				)
			)
		);
		$signature = '';
		if ( ! openssl_sign( $header . '.' . $claims, $signature, $key['private_key'], OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'sox_seo_gsc', __( 'The service account key could not be used to sign a request. Paste the full JSON key file again.', 'sox-seo' ) );
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $header . '.' . $claims . '.' . self::b64url( $signature ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'sox_seo_gsc', $response->get_error_message() );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			$detail = isset( $data['error_description'] ) ? $data['error_description'] : __( 'Google rejected the service account key.', 'sox-seo' );
			return new WP_Error( 'sox_seo_gsc', $detail );
		}
		set_transient( 'sox_seo_gsc_token', $data['access_token'], 50 * MINUTE_IN_SECONDS );
		return $data['access_token'];
	}

	/**
	 * Run a Search Analytics query. Results are cached for 12 hours; Search
	 * Console data itself only updates daily.
	 *
	 * @param array $body  Query body without dates.
	 * @param bool  $fresh Skip the cache.
	 * @return array|WP_Error Rows.
	 */
	public static function query( array $body, $fresh = false ) {
		// Search Console data lags by two to three days.
		$body['startDate'] = gmdate( 'Y-m-d', time() - 31 * DAY_IN_SECONDS );
		$body['endDate']   = gmdate( 'Y-m-d', time() - 3 * DAY_IN_SECONDS );

		$cache_key = 'sox_seo_gsc_' . md5( self::property() . wp_json_encode( $body ) );
		$cached    = $fresh ? false : get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( 'sitekit' === self::source() ) {
			$rows = self::site_kit_query( $body );
			if ( ! is_wp_error( $rows ) ) {
				set_transient( $cache_key, $rows, 12 * HOUR_IN_SECONDS );
			}
			return $rows;
		}

		$token = self::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$response = wp_remote_post(
			self::API_BASE . rawurlencode( self::property() ) . '/searchAnalytics/query',
			array(
				'timeout' => 30,
				'headers' => array(
					'authorization' => 'Bearer ' . $token,
					'content-type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'sox_seo_gsc', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code ) {
			$detail = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Unexpected response from Google.', 'sox-seo' );
			if ( 403 === $code ) {
				$key    = self::credentials();
				/* translators: 1: service account e-mail, 2: property */
				$detail = sprintf( __( 'Google refused access. In Search Console, open Settings → Users and permissions for %2$s and add %1$s as a user.', 'sox-seo' ), $key ? $key['client_email'] : '', self::property() );
			}
			return new WP_Error( 'sox_seo_gsc', $detail );
		}
		$rows = isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : array();
		set_transient( $cache_key, $rows, 12 * HOUR_IN_SECONDS );
		return $rows;
	}

	/**
	 * Search queries a page was shown for in the last 28 days.
	 *
	 * @param string $url   Page URL.
	 * @param int    $limit Max rows.
	 * @return array|WP_Error Rows of {query, clicks, impressions, ctr, position}.
	 */
	public static function page_queries( $url, $limit = 20 ) {
		$rows = self::query(
			array(
				'dimensions'            => array( 'query' ),
				'dimensionFilterGroups' => array(
					array(
						'filters' => array(
							array(
								'dimension'  => 'page',
								'operator'   => 'equals',
								'expression' => $url,
							),
						),
					),
				),
				'rowLimit'              => $limit,
			)
		);
		return is_wp_error( $rows ) ? $rows : self::shape( $rows, 'query' );
	}

	/**
	 * Pages ranked by impressions.
	 *
	 * @param int  $limit Max rows.
	 * @param bool $fresh Skip the cache.
	 * @return array|WP_Error Rows of {page, clicks, impressions, ctr, position}.
	 */
	public static function top_pages( $limit = 200, $fresh = false ) {
		$rows = self::query(
			array(
				'dimensions' => array( 'page' ),
				'rowLimit'   => $limit,
			),
			$fresh
		);
		return is_wp_error( $rows ) ? $rows : self::shape( $rows, 'page' );
	}

	/**
	 * @param array  $rows API rows.
	 * @param string $key  Name for the dimension value.
	 * @return array
	 */
	private static function shape( array $rows, $key ) {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				$key          => isset( $row['keys'][0] ) ? (string) $row['keys'][0] : '',
				'clicks'      => isset( $row['clicks'] ) ? (int) $row['clicks'] : 0,
				'impressions' => isset( $row['impressions'] ) ? (int) $row['impressions'] : 0,
				'ctr'         => isset( $row['ctr'] ) ? round( (float) $row['ctr'] * 100, 1 ) : 0.0,
				'position'    => isset( $row['position'] ) ? round( (float) $row['position'], 1 ) : 0.0,
			);
		}
		return $out;
	}

	/**
	 * Pages that are close to earning more traffic: shown often, ranking on
	 * page one or two, but clicked less than their position should earn.
	 *
	 * @return array|WP_Error
	 */
	public static function opportunities() {
		$pages = self::top_pages();
		if ( is_wp_error( $pages ) ) {
			return $pages;
		}
		// Rough click-through rate a result earns at each position.
		$expected = array( 1 => 28, 2 => 15, 3 => 10, 4 => 7, 5 => 5, 6 => 4, 7 => 3, 8 => 2.5, 9 => 2, 10 => 1.8 );
		$out      = array();
		foreach ( $pages as $page ) {
			if ( $page['impressions'] < 30 ) {
				continue;
			}
			$position = (int) round( $page['position'] );
			$reason   = '';
			if ( $position >= 4 && $position <= 20 ) {
				$reason = $position <= 10
					? __( 'On page one but below the top three. Strengthen the content and add internal links.', 'sox-seo' )
					: __( 'On page two. A content refresh and internal links can lift it onto page one.', 'sox-seo' );
			}
			if ( isset( $expected[ $position ] ) && $page['ctr'] < $expected[ $position ] * 0.6 ) {
				$reason = __( 'Ranks well but few people click. Rewrite the title and description.', 'sox-seo' );
			}
			if ( $reason ) {
				$page['reason']  = $reason;
				$page['post_id'] = url_to_postid( $page['page'] );
				$out[]           = $page;
			}
		}
		return $out;
	}

	/**
	 * Search data for a page, formatted for a prompt. Empty when Search
	 * Console is not connected or has nothing for the page.
	 *
	 * @param string $url Page URL.
	 * @return string
	 */
	public static function prompt_block( $url ) {
		if ( ! self::is_connected() ) {
			return '';
		}
		$queries = self::page_queries( $url, 15 );
		if ( is_wp_error( $queries ) || ! $queries ) {
			return '';
		}
		$lines = array();
		foreach ( $queries as $row ) {
			$lines[] = sprintf( '%s | impressions %d | clicks %d | ctr %s%% | position %s', $row['query'], $row['impressions'], $row['clicks'], $row['ctr'], $row['position'] );
		}
		return "<search_console_last_28_days note=\"Real Google searches this page was shown for\">\n" . implode( "\n", $lines ) . "\n</search_console_last_28_days>";
	}

	/**
	 * Record this week's totals and each top page's position, so progress
	 * can be seen over time. Search Console itself keeps 16 months but shows
	 * no per-page history side by side.
	 *
	 * @return array|WP_Error The snapshot.
	 */
	public static function snapshot() {
		$pages = self::top_pages( 200, true );
		if ( is_wp_error( $pages ) ) {
			return $pages;
		}
		$clicks      = 0;
		$impressions = 0;
		$weighted    = 0.0;
		$top         = array();
		foreach ( $pages as $page ) {
			$clicks      += $page['clicks'];
			$impressions += $page['impressions'];
			$weighted    += $page['position'] * $page['impressions'];
			if ( count( $top ) < 100 ) {
				$top[ $page['page'] ] = array( $page['clicks'], $page['impressions'], $page['position'] );
			}
		}
		$snapshot = array(
			'date'        => gmdate( 'Y-m-d' ),
			'clicks'      => $clicks,
			'impressions' => $impressions,
			'position'    => $impressions ? round( $weighted / $impressions, 1 ) : 0,
			'pages'       => $top,
		);
		$history = self::history();
		// One snapshot per day at most.
		if ( $history && end( $history )['date'] === $snapshot['date'] ) {
			array_pop( $history );
		}
		$history[] = $snapshot;
		update_option( 'sox_seo_gsc_history', array_slice( $history, -26 ), false );
		return $snapshot;
	}

	/**
	 * @return array Snapshots, oldest first.
	 */
	public static function history() {
		$history = get_option( 'sox_seo_gsc_history', array() );
		return is_array( $history ) ? array_values( $history ) : array();
	}

	/**
	 * Save or clear the service-account key from the settings form.
	 *
	 * @param string $raw Pasted JSON, 'delete', or empty to keep.
	 */
	public static function save_key( $raw ) {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return;
		}
		if ( 'delete' === strtolower( $raw ) ) {
			delete_option( self::KEY_OPTION );
			delete_transient( 'sox_seo_gsc_token' );
			return;
		}
		$key = json_decode( $raw, true );
		if ( is_array( $key ) && ! empty( $key['client_email'] ) && ! empty( $key['private_key'] ) ) {
			// Keep only what is needed to sign requests.
			update_option(
				self::KEY_OPTION,
				wp_json_encode(
					array(
						'client_email' => sanitize_email( $key['client_email'] ),
						'private_key'  => $key['private_key'],
					)
				),
				false
			);
			delete_transient( 'sox_seo_gsc_token' );
		} else {
			add_settings_error( 'sox_seo', 'gsc_key', __( 'The Search Console key was not saved: paste the whole JSON key file for the service account.', 'sox-seo' ) );
		}
	}
}
