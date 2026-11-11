<?php
/**
 * Plugin settings.
 *
 * @package Sox_SEO
 */

defined( 'ABSPATH' ) || exit;

class Sox_SEO_Options {

	const OPTION     = 'sox_seo_options';
	const KEY_OPTION = 'sox_seo_api_key';

	/**
	 * Claude models offered in the settings screen.
	 *
	 * @return array<string,string>
	 */
	public static function models() {
		return array(
			'claude-opus-5-5'   => __( 'Claude Opus 5.5 — best quality (default)', 'sox-seo' ),
			'claude-sonnet-5-5' => __( 'Claude Sonnet 5.5 — faster, about half the cost', 'sox-seo' ),
			'claude-haiku-5-5'  => __( 'Claude Haiku 5.5 — cheapest, good for large bulk runs', 'sox-seo' ),
			'claude-fable-5-2'  => __( 'Claude Fable 5.2 — most capable, highest cost', 'sox-seo' ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			// AI.
			'model'                 => 'claude-opus-5-5',
			'effort'                => 'medium',
			'language'              => '',
			'brand_voice'           => '',
			'overwrite_existing'    => 0,
			'auto_meta_on_publish'  => 1,
			'auto_alt_on_upload'    => 1,
			'autopilot'             => 0,
			'autopilot_daily_limit' => 20,
			// Titles & meta.
			'separator'             => '-',
			'title_single'          => '%title% %sep% %sitename%',
			'title_term'            => '%term% %sep% %sitename%',
			'home_title'            => '',
			'home_description'      => '',
			'default_og_image'      => '',
			'noindex_search'        => 1,
			'noindex_tags'          => 0,
			// Schema.
			'schema_enabled'        => 1,
			'org_name'              => '',
			'org_logo'              => '',
			'org_social'            => '',
			'default_brand'         => '',
			// Sitemap.
			'sitemap_no_users'      => 1,
			// Images & links.
			'alt_fallback'          => 1,
			'external_new_tab'      => 0,
			'external_nofollow'     => 0,
			// Redirects.
			'redirects_enabled'     => 1,
			'log_404'               => 1,
			// Images.
			'webp'                  => 0,
			'image_quality'         => 82,
			'rename_uploads'        => 1,
			// Crawlers.
			'indexnow'              => 0,
			'llms_txt'              => 1,
			'block_ai_training'     => 0,
			// Search Console and reports.
			'gsc_property'          => '',
			'weekly_report'         => 0,
			'report_email'          => '',
			// Local business schema.
			'local_enabled'         => 0,
			'local_type'            => 'LocalBusiness',
			'local_street'          => '',
			'local_city'            => '',
			'local_region'          => '',
			'local_postcode'        => '',
			'local_country'         => '',
			'local_phone'           => '',
			'local_lat'             => '',
			'local_lng'             => '',
			'local_price_range'     => '',
			'local_hours'           => '',
		);
	}

	/**
	 * @param string $key Option key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * The Anthropic API key. A constant in wp-config.php wins over the value
	 * saved in the database, so the key can be kept out of the DB entirely.
	 *
	 * @return string
	 */
	public static function api_key() {
		if ( defined( 'SOX_SEO_ANTHROPIC_API_KEY' ) && SOX_SEO_ANTHROPIC_API_KEY ) {
			return (string) SOX_SEO_ANTHROPIC_API_KEY;
		}
		$env = getenv( 'ANTHROPIC_API_KEY' );
		if ( $env ) {
			return (string) $env;
		}
		return (string) get_option( self::KEY_OPTION, '' );
	}

	/**
	 * @return bool
	 */
	public static function api_key_is_constant() {
		return ( defined( 'SOX_SEO_ANTHROPIC_API_KEY' ) && SOX_SEO_ANTHROPIC_API_KEY ) || getenv( 'ANTHROPIC_API_KEY' );
	}

	/**
	 * Settings API sanitize callback.
	 *
	 * @param mixed $input Raw form input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$out      = array();

		$checkboxes = array(
			'overwrite_existing',
			'auto_meta_on_publish',
			'auto_alt_on_upload',
			'autopilot',
			'noindex_search',
			'noindex_tags',
			'schema_enabled',
			'sitemap_no_users',
			'alt_fallback',
			'external_new_tab',
			'external_nofollow',
			'redirects_enabled',
			'log_404',
			'webp',
			'rename_uploads',
			'indexnow',
			'llms_txt',
			'block_ai_training',
			'weekly_report',
			'local_enabled',
		);
		foreach ( $checkboxes as $key ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$texts = array( 'language', 'separator', 'title_single', 'title_term', 'home_title', 'org_name', 'default_brand', 'gsc_property', 'local_type', 'local_street', 'local_city', 'local_region', 'local_postcode', 'local_country', 'local_phone', 'local_lat', 'local_lng', 'local_price_range' );
		foreach ( $texts as $key ) {
			$out[ $key ] = isset( $input[ $key ] ) ? sanitize_text_field( wp_unslash( $input[ $key ] ) ) : $defaults[ $key ];
		}
		foreach ( array( 'title_single', 'title_term', 'separator' ) as $key ) {
			if ( '' === $out[ $key ] ) {
				$out[ $key ] = $defaults[ $key ];
			}
		}

		foreach ( array( 'brand_voice', 'home_description', 'org_social', 'local_hours' ) as $key ) {
			$out[ $key ] = isset( $input[ $key ] ) ? sanitize_textarea_field( wp_unslash( $input[ $key ] ) ) : '';
		}
		foreach ( array( 'default_og_image', 'org_logo' ) as $key ) {
			$out[ $key ] = isset( $input[ $key ] ) ? esc_url_raw( wp_unslash( $input[ $key ] ) ) : '';
		}

		$model        = isset( $input['model'] ) ? sanitize_text_field( wp_unslash( $input['model'] ) ) : '';
		$out['model'] = array_key_exists( $model, self::models() ) ? $model : $defaults['model'];

		$effort        = isset( $input['effort'] ) ? sanitize_key( $input['effort'] ) : '';
		$out['effort'] = in_array( $effort, array( 'low', 'medium', 'high' ), true ) ? $effort : $defaults['effort'];

		$limit                        = isset( $input['autopilot_daily_limit'] ) ? absint( $input['autopilot_daily_limit'] ) : $defaults['autopilot_daily_limit'];
		$out['autopilot_daily_limit'] = max( 1, min( 500, $limit ) );

		$out['local_type']    = preg_replace( '/[^A-Za-z]/', '', $out['local_type'] );
		$out['local_type']    = $out['local_type'] ? $out['local_type'] : 'LocalBusiness';
		$out['report_email']  = isset( $input['report_email'] ) ? sanitize_email( wp_unslash( $input['report_email'] ) ) : '';
		$quality              = isset( $input['image_quality'] ) ? absint( $input['image_quality'] ) : $defaults['image_quality'];
		$out['image_quality'] = max( 40, min( 100, $quality ) );

		if ( isset( $input['gsc_key'] ) ) {
			// A JSON key file: only trimmed here, validated and reduced in save_key().
			Sox_SEO_GSC::save_key( (string) wp_unslash( $input['gsc_key'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		// The titles and schema sections are hidden while another SEO plugin
		// is active. Their values were not submitted, so keep what was saved.
		if ( empty( $input['_native'] ) ) {
			$current = self::all();
			$native  = array( 'separator', 'title_single', 'title_term', 'home_title', 'home_description', 'default_og_image', 'noindex_search', 'noindex_tags', 'sitemap_no_users', 'schema_enabled', 'org_name', 'org_logo', 'org_social', 'local_enabled', 'local_type', 'local_street', 'local_city', 'local_region', 'local_postcode', 'local_country', 'local_phone', 'local_lat', 'local_lng', 'local_price_range', 'local_hours' );
			foreach ( $native as $key ) {
				$out[ $key ] = $current[ $key ];
			}
		}

		// The API key lives in its own non-autoloaded option. An empty field
		// means "keep the saved key"; the literal word "delete" removes it.
		if ( isset( $input['api_key'] ) ) {
			$key = trim( sanitize_text_field( wp_unslash( $input['api_key'] ) ) );
			if ( 'delete' === strtolower( $key ) ) {
				delete_option( self::KEY_OPTION );
			} elseif ( '' !== $key ) {
				update_option( self::KEY_OPTION, $key, false );
			}
		}

		return $out;
	}
}
