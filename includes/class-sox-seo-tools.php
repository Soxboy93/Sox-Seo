<?php
/**
 * Tools: import from other SEO plugins, WooCommerce product feed check and
 * the weekly e-mail report.
 *
 * @package Sox_SEO
 */

defined( 'ABSPATH' ) || exit;

class Sox_SEO_Tools {

	const REPORT_HOOK = 'sox_seo_weekly_report';

	public static function init() {
		add_action( self::REPORT_HOOK, array( __CLASS__, 'send_report' ) );

		$scheduled = wp_next_scheduled( self::REPORT_HOOK );
		if ( Sox_SEO_Options::get( 'weekly_report' ) && ! $scheduled ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::REPORT_HOOK );
		} elseif ( ! Sox_SEO_Options::get( 'weekly_report' ) && $scheduled ) {
			wp_clear_scheduled_hook( self::REPORT_HOOK );
		}
	}

	/* ---- Import ---------------------------------------------------------- */

	/**
	 * Meta keys used by other SEO plugins.
	 *
	 * @return array<string,array{label:string,keys:array<string,string>}>
	 */
	public static function import_sources() {
		return array(
			'rankmath' => array(
				'label' => 'Rank Math',
				'keys'  => array(
					'title'         => 'rank_math_title',
					'description'   => 'rank_math_description',
					'focus_keyword' => 'rank_math_focus_keyword',
					'canonical'     => 'rank_math_canonical_url',
				),
			),
			'yoast'    => array(
				'label' => 'Yoast SEO',
				'keys'  => array(
					'title'         => '_yoast_wpseo_title',
					'description'   => '_yoast_wpseo_metadesc',
					'focus_keyword' => '_yoast_wpseo_focuskw',
					'canonical'     => '_yoast_wpseo_canonical',
				),
			),
			'seopress' => array(
				'label' => 'SEOPress',
				'keys'  => array(
					'title'         => '_seopress_titles_title',
					'description'   => '_seopress_titles_desc',
					'focus_keyword' => '_seopress_analysis_target_kw',
					'canonical'     => '_seopress_robots_canonical',
				),
			),
		);
	}

	/**
	 * Copy titles, descriptions, keywords and canonicals from another SEO
	 * plugin into Sox SEO's own fields. Existing Sox SEO values are kept, and
	 * the other plugin's data is left untouched.
	 *
	 * @param string $source rankmath|yoast|seopress.
	 * @return int|WP_Error Number of values copied.
	 */
	public static function import( $source ) {
		global $wpdb;
		$sources = self::import_sources();
		if ( ! isset( $sources[ $source ] ) ) {
			return new WP_Error( 'sox_seo_import', __( 'Unknown import source.', 'sox-seo' ) );
		}
		$copied = 0;
		foreach ( $sources[ $source ]['keys'] as $field => $their_key ) {
			$our_key = Sox_SEO_Meta::PREFIX . $field;
			$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''", $their_key ) ); // phpcs:ignore WordPress.DB
			foreach ( $rows as $row ) {
				$value = (string) $row->meta_value;
				// Template variables such as %title% or %%sitename%% belong to
				// the other plugin and would be printed literally here.
				if ( false !== strpos( $value, '%' ) ) {
					continue;
				}
				if ( 'focus_keyword' === $field ) {
					$value = trim( (string) strtok( $value, ',' ) );
				}
				if ( '' === (string) get_post_meta( $row->post_id, $our_key, true ) ) {
					update_post_meta( $row->post_id, $our_key, wp_slash( $value ) );
					$copied++;
				}
			}
		}
		return $copied;
	}

	/* ---- Product feed check ---------------------------------------------- */

	/**
	 * Check products against what Google Merchant Center asks for.
	 *
	 * @param int $limit Max products to inspect.
	 * @return array {checked:int, total:int, counts:array, rows:array}
	 */
	public static function product_check( $limit = 1000 ) {
		$result = array(
			'checked' => 0,
			'total'   => 0,
			'counts'  => array(),
			'rows'    => array(),
		);
		if ( ! function_exists( 'wc_get_product' ) ) {
			return $result;
		}
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);
		$result['total'] = (int) $query->found_posts;

		$labels = array(
			'image'       => __( 'No image', 'sox-seo' ),
			'price'       => __( 'No price', 'sox-seo' ),
			'description' => __( 'No description', 'sox-seo' ),
			'brand'       => __( 'No brand', 'sox-seo' ),
			'gtin'        => __( 'No GTIN / barcode', 'sox-seo' ),
			'title'       => __( 'Title over 150 characters', 'sox-seo' ),
		);
		$result['counts'] = array_fill_keys( array_keys( $labels ), 0 );
		$result['labels'] = $labels;

		foreach ( $query->posts as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$result['checked']++;
			$issues = array();
			if ( ! $product->get_image_id() ) {
				$issues[] = 'image';
			}
			if ( '' === (string) $product->get_price() ) {
				$issues[] = 'price';
			}
			if ( '' === trim( wp_strip_all_tags( $product->get_description() . $product->get_short_description() ) ) ) {
				$issues[] = 'description';
			}
			if ( '' === Sox_SEO_WooCommerce::brand( $product ) ) {
				$issues[] = 'brand';
			}
			if ( method_exists( $product, 'get_global_unique_id' ) && '' === (string) $product->get_global_unique_id() ) {
				$issues[] = 'gtin';
			}
			if ( mb_strlen( $product->get_name() ) > 150 ) {
				$issues[] = 'title';
			}
			foreach ( $issues as $issue ) {
				$result['counts'][ $issue ]++;
			}
			if ( $issues && count( $result['rows'] ) < 300 ) {
				$result['rows'][] = array(
					'id'     => $id,
					'name'   => $product->get_name(),
					'issues' => $issues,
				);
			}
		}
		return $result;
	}

	/* ---- Weekly report --------------------------------------------------- */

	/**
	 * @return string Plain-text report body.
	 */
	public static function report_body() {
		global $wpdb;
		$week_ago = time() - WEEK_IN_SECONDS;
		$lines    = array();

		/* translators: %s: site name */
		$lines[] = sprintf( __( 'Weekly SEO report for %s', 'sox-seo' ), get_bloginfo( 'name' ) );
		$lines[] = home_url( '/' );
		$lines[] = '';

		$written = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_sox_seo_ai_generated' AND CAST(meta_value AS UNSIGNED) > %d", $week_ago ) ); // phpcs:ignore WordPress.DB
		$lines[] = __( 'CLAUDE THIS WEEK', 'sox-seo' );
		/* translators: %d: number of pages */
		$lines[] = sprintf( __( '- Titles and descriptions written: %d', 'sox-seo' ), $written );
		$usage   = get_option( Sox_SEO_Anthropic::USAGE_OPTION, array() );
		$month   = gmdate( 'Y-m' );
		if ( isset( $usage[ $month ] ) ) {
			/* translators: 1: requests, 2: input tokens, 3: output tokens */
			$lines[] = sprintf( __( '- API use this month: %1$s requests, %2$s input tokens, %3$s output tokens', 'sox-seo' ), number_format_i18n( $usage[ $month ]['requests'] ), number_format_i18n( $usage[ $month ]['input'] ), number_format_i18n( $usage[ $month ]['output'] ) );
		}
		$lines[] = '';

		$lines[] = __( 'STILL TO DO', 'sox-seo' );
		if ( Sox_SEO_Meta::is_supported( 'post' ) ) {
			/* translators: %d: number of pages */
			$lines[] = sprintf( __( '- Pages without a meta description: %d', 'sox-seo' ), Sox_SEO_Automation::remaining( 'meta' ) );
		}
		/* translators: %d: number of images */
		$lines[] = sprintf( __( '- Images without alt text: %d', 'sox-seo' ), Sox_SEO_Automation::remaining( 'alt' ) );

		$audit = Sox_SEO_Link_Audit::state();
		if ( $audit['finished'] ) {
			/* translators: %d: number of links */
			$lines[] = sprintf( __( '- Broken links: %d', 'sox-seo' ), count( $audit['broken'] ) );
			/* translators: %d: number of pages */
			$lines[] = sprintf( __( '- Pages nothing links to: %d', 'sox-seo' ), count( Sox_SEO_Link_Audit::orphans() ) );
			/* translators: %s: date */
			$lines[] = sprintf( __( '  (link audit last run %s)', 'sox-seo' ), wp_date( get_option( 'date_format' ), $audit['finished'] ) );
		} else {
			$lines[] = __( '- Link audit has not been run yet.', 'sox-seo' );
		}
		$lines[] = '';

		$missing = $wpdb->get_results( $wpdb->prepare( 'SELECT url, hits FROM ' . Sox_SEO_Redirects::table_404() . ' WHERE last_hit > %s ORDER BY hits DESC LIMIT 10', gmdate( 'Y-m-d H:i:s', $week_ago ) ) ); // phpcs:ignore WordPress.DB
		if ( $missing ) {
			$lines[] = __( 'PAGES NOT FOUND THIS WEEK (redirect the ones that matter)', 'sox-seo' );
			foreach ( $missing as $row ) {
				$lines[] = sprintf( '- %s (%d)', $row->url, $row->hits );
			}
			$lines[] = '';
		}

		if ( Sox_SEO_GSC::is_connected() ) {
			$pages = Sox_SEO_GSC::top_pages();
			if ( ! is_wp_error( $pages ) && $pages ) {
				$clicks      = array_sum( wp_list_pluck( $pages, 'clicks' ) );
				$impressions = array_sum( wp_list_pluck( $pages, 'impressions' ) );
				$lines[]     = __( 'GOOGLE SEARCH, LAST 28 DAYS', 'sox-seo' );
				/* translators: 1: clicks, 2: impressions */
				$lines[] = sprintf( __( '- %1$s clicks from %2$s impressions', 'sox-seo' ), number_format_i18n( $clicks ), number_format_i18n( $impressions ) );
				$chances = Sox_SEO_GSC::opportunities();
				if ( ! is_wp_error( $chances ) ) {
					/* translators: %d: number of pages */
					$lines[] = sprintf( __( '- Pages close to ranking higher: %d', 'sox-seo' ), count( $chances ) );
				}
				$lines[] = '';
			}
		}

		$lines[] = admin_url( 'admin.php?page=sox-seo' );
		return implode( "\n", $lines );
	}

	/**
	 * @return bool Whether the mail was handed to the mailer.
	 */
	public static function send_report() {
		$to = trim( (string) Sox_SEO_Options::get( 'report_email' ) );
		$to = is_email( $to ) ? $to : get_option( 'admin_email' );
		/* translators: %s: site name */
		return wp_mail( $to, sprintf( __( '[%s] Weekly SEO report', 'sox-seo' ), get_bloginfo( 'name' ) ), self::report_body() );
	}
}
