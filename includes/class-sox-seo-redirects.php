<?php
/**
 * Redirect manager and 404 monitor.
 *
 * @package Sox_SEO
 */

defined( 'ABSPATH' ) || exit;

class Sox_SEO_Redirects {

	const DB_VERSION = '1';

	public static function init() {
		if ( get_option( 'sox_seo_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
		if ( Sox_SEO_Options::get( 'redirects_enabled' ) ) {
			add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 1 );
		}
		if ( Sox_SEO_Options::get( 'log_404' ) ) {
			add_action( 'template_redirect', array( __CLASS__, 'log_404' ), 99 );
		}
	}

	/**
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sox_seo_redirects';
	}

	/**
	 * @return string
	 */
	public static function table_404() {
		global $wpdb;
		return $wpdb->prefix . 'sox_seo_404';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(191) NOT NULL,
			target text NOT NULL,
			code smallint(3) unsigned NOT NULL DEFAULT 301,
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			created datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source (source)
		) {$charset};"
		);
		dbDelta(
			'CREATE TABLE ' . self::table_404() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url_hash char(32) NOT NULL,
			url text NOT NULL,
			referrer text NULL,
			hits bigint(20) unsigned NOT NULL DEFAULT 1,
			last_hit datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash)
		) {$charset};"
		);
		update_option( 'sox_seo_db_version', self::DB_VERSION );
	}

	/**
	 * Normalise a URL or path to the form redirects are stored and matched in:
	 * a path with a leading slash, no trailing slash, no query string.
	 *
	 * @param string $url URL or path.
	 * @return string
	 */
	public static function normalize( $url ) {
		$path = (string) wp_parse_url( trim( $url ), PHP_URL_PATH );
		$base = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		if ( $base && '/' !== $base && 0 === strpos( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		$path = '/' . trim( rawurldecode( $path ), '/' );
		return mb_substr( $path, 0, 191 );
	}

	public static function maybe_redirect() {
		global $wpdb;
		if ( is_admin() || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = self::normalize( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
		if ( '/' === $path ) {
			return;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, target, code FROM ' . self::table() . ' WHERE source = %s', $path ) ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return;
		}
		$target = 0 === strpos( $row->target, '/' ) ? home_url( $row->target ) : $row->target;
		if ( self::normalize( $target ) === $path && wp_parse_url( $target, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return; // Would loop.
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET hits = hits + 1 WHERE id = %d', $row->id ) ); // phpcs:ignore WordPress.DB
		// Targets are entered by administrators and may point off-site.
		wp_redirect( esc_url_raw( $target ), (int) $row->code, 'Sox SEO' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	public static function log_404() {
		global $wpdb;
		if ( ! is_404() || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$url = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		if ( self::is_noise( $url ) ) {
			return;
		}
		$url      = mb_substr( $url, 0, 2000 );
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? mb_substr( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 2000 ) : '';

		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'INSERT INTO ' . self::table_404() . ' (url_hash, url, referrer, hits, last_hit) VALUES (%s, %s, %s, 1, %s)
				ON DUPLICATE KEY UPDATE hits = hits + 1, last_hit = VALUES(last_hit)', // phpcs:ignore WordPress.DB
				md5( $url ),
				$url,
				$referrer,
				current_time( 'mysql', true )
			)
		);

		// Keep the log bounded.
		if ( 1 === wp_rand( 1, 50 ) ) {
			$wpdb->query( 'DELETE FROM ' . self::table_404() . ' WHERE id NOT IN ( SELECT id FROM ( SELECT id FROM ' . self::table_404() . ' ORDER BY last_hit DESC LIMIT 1000 ) keep )' ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * Whether a missing URL is a bot probing for weaknesses or a missing
	 * asset, as opposed to a page a visitor or search engine wanted.
	 *
	 * @param string $url Request URI.
	 * @return bool
	 */
	public static function is_noise( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		// Hidden files and folders: /.git/config, /.env.backup, /.aws/credentials.
		if ( preg_match( '#(^|/)\.[^/]#', $path ) ) {
			return true;
		}
		// Files, not pages.
		if ( preg_match( '#\.(?:php\d?|aspx?|jsp|cgi|js|css|map|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|eot|xml|rss|txt|json|ya?ml|sql|bak|old|zip|gz|tar|rar|log|ini|conf|env)$#i', $path ) ) {
			return true;
		}
		// Admin panels and APIs of software this site does not run.
		return (bool) preg_match( '#^/(?:wp-admin|wp-includes|wp-content|wp-json|cgi-bin|api|admin|administrator|phpmyadmin|pma|vendor|actuator|_profiler|debug|console|server-status|owa|autodiscover|telescope|solr|druid)(?:/|$)#i', $path );
	}

	/**
	 * Remove bot probes and missing-asset entries from the 404 log.
	 *
	 * @return int Rows removed.
	 */
	public static function prune_noise() {
		global $wpdb;
		$removed = 0;
		foreach ( $wpdb->get_results( 'SELECT id, url FROM ' . self::table_404() ) as $row ) { // phpcs:ignore WordPress.DB
			if ( self::is_noise( $row->url ) ) {
				$wpdb->delete( self::table_404(), array( 'id' => (int) $row->id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
				$removed++;
			}
		}
		return $removed;
	}

	/**
	 * Add or replace a redirect.
	 *
	 * @param string $source Old URL or path.
	 * @param string $target New URL or path.
	 * @param int    $code   301, 302 or 307.
	 * @return true|WP_Error
	 */
	public static function add( $source, $target, $code = 301 ) {
		global $wpdb;
		$source = self::normalize( $source );
		$target = trim( $target );
		$code   = in_array( (int) $code, array( 301, 302, 307 ), true ) ? (int) $code : 301;

		if ( '/' === $source ) {
			return new WP_Error( 'sox_seo_redirect', __( 'The home page cannot be redirected.', 'sox-seo' ) );
		}
		if ( '' === $target ) {
			return new WP_Error( 'sox_seo_redirect', __( 'Enter a destination URL.', 'sox-seo' ) );
		}
		$target = 0 === strpos( $target, '/' ) ? '/' . ltrim( $target, '/' ) : esc_url_raw( $target );
		if ( '' === $target ) {
			return new WP_Error( 'sox_seo_redirect', __( 'The destination is not a valid URL.', 'sox-seo' ) );
		}
		if ( self::normalize( $target ) === $source && ( 0 === strpos( $target, '/' ) || wp_parse_url( $target, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return new WP_Error( 'sox_seo_redirect', __( 'Source and destination are the same page.', 'sox-seo' ) );
		}

		$wpdb->replace( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'source'  => $source,
				'target'  => $target,
				'code'    => $code,
				'created' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%s' )
		);
		// A URL with a redirect is no longer a 404 to chase.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table_404() . ' WHERE url LIKE %s', $wpdb->esc_like( $source ) . '%' ) ); // phpcs:ignore WordPress.DB
		return true;
	}

	/**
	 * @param int $id Redirect ID.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT 500' ); // phpcs:ignore WordPress.DB
	}

	/**
	 * @return array
	 */
	public static function top_404() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . self::table_404() . ' ORDER BY hits DESC, last_hit DESC LIMIT 100' ); // phpcs:ignore WordPress.DB
	}

	public static function clear_404() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::table_404() ); // phpcs:ignore WordPress.DB
	}
}
