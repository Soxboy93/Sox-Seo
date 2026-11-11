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
		add_action( 'sox_seo_gsc_snapshot', array( 'Sox_SEO_GSC', 'snapshot' ) );

		$snapshot = wp_next_scheduled( 'sox_seo_gsc_snapshot' );
		if ( Sox_SEO_GSC::is_connected() && ! $snapshot ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'weekly', 'sox_seo_gsc_snapshot' );
		} elseif ( ! Sox_SEO_GSC::is_connected() && $snapshot ) {
			wp_clear_scheduled_hook( 'sox_seo_gsc_snapshot' );
		}

		$scheduled = wp_next_scheduled( self::REPORT_HOOK );
		if ( Sox_SEO_Options::get( 'weekly_report' ) && ! $scheduled ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::REPORT_HOOK );
		} elseif ( ! Sox_SEO_Options::get( 'weekly_report' ) && $scheduled ) {
			wp_clear_scheduled_hook( self::REPORT_HOOK );
		}
	}

	/* ---- Import ---------------------------------------------------------- */

	/**
	 * SEO plugins whose saved data can be imported.
	 *
	 * @return array<string,array{label:string}>
	 */
	public static function import_sources() {
		return array(
			'rankmath' => array( 'label' => 'Rank Math' ),
			'aioseo'   => array( 'label' => 'All in One SEO' ),
			'yoast'    => array( 'label' => 'Yoast SEO' ),
			'seopress' => array( 'label' => 'SEOPress' ),
		);
	}

	/**
	 * Turn another plugin's stored title or description into plain text.
	 *
	 * Those plugins store templates such as "%title% %sep% %sitename%" or
	 * "#post_title #separator_sa #site_title". Site name and separator are
	 * added by Sox SEO's own title template, so they are removed from titles;
	 * a value that is only a template, or that still contains a variable this
	 * cannot resolve, returns '' and the page falls back to its default.
	 *
	 * @param string $value        Stored value.
	 * @param string $object_title Post or term title.
	 * @param string $field        title|description.
	 * @return string
	 */
	public static function resolve_template( $value, $object_title, $field ) {
		$site  = trim( get_bloginfo( 'name' ) );
		$value = str_replace( array( '&nbsp;', "\xC2\xA0" ), ' ', (string) $value );
		$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );

		$site_tokens  = array( '%%sitename%%', '%sitename%', '#site_title' );
		$sep_tokens   = array( '%%sep%%', '%sep%', '#separator_sa' );
		$title_tokens = array( '%%title%%', '%title%', '#post_title', '%%term_title%%', '%term%', '#taxonomy_title' );
		$body_tokens  = array( '%%excerpt%%', '%excerpt%', '%%excerpt_only%%', '%excerpt_only%', '#post_excerpt', '#post_content', '%%searchphrase%%', '%search_query%', '#search_term', '%%page%%', '%page%', '#page_number' );

		if ( 'description' === $field ) {
			// "Use the excerpt" is what Sox SEO does by default anyway.
			foreach ( $body_tokens as $token ) {
				if ( false !== strpos( $value, $token ) ) {
					return '';
				}
			}
			$value = str_replace( $site_tokens, $site, $value );
			$value = str_replace( $sep_tokens, Sox_SEO_Options::get( 'separator' ), $value );
			$value = str_replace( $title_tokens, $object_title, $value );
		} else {
			$value = str_replace( $body_tokens, '', $value );
			$value = str_replace( $site_tokens, "\x01", $value );
			$value = str_replace( $sep_tokens, "\x02", $value );
			// Drop "<sep> <site name>" at either end, written as variables or typed out.
			$value = preg_replace( '/^\s*\x01\s*\x02\s*|\s*\x02\s*\x01\s*$/u', '', $value );
			$value = str_replace( array( "\x01", "\x02" ), array( $site, Sox_SEO_Options::get( 'separator' ) ), $value );
			$value = str_replace( $title_tokens, $object_title, $value );
			if ( '' !== $site ) {
				$value = preg_replace( '/\s*[|\-–—·•:]\s*' . preg_quote( $site, '/' ) . '\s*$/iu', '', $value );
			}
		}

		$value = trim( preg_replace( '/\s+/u', ' ', $value ) );
		if ( preg_match( '/%%?[a-z_]+%%?|#[a-z]+_[a-z_]+/i', $value ) ) {
			return ''; // A variable this importer does not know.
		}
		if ( 0 === strcasecmp( $value, trim( $object_title ) ) ) {
			return ''; // Same as the page's own title: nothing custom to keep.
		}
		return $value;
	}

	/**
	 * Read another plugin's saved SEO data into a common shape.
	 *
	 * @param string $source Source ID.
	 * @return array{posts:array<int,array<string,string>>,terms:array<int,array<string,string>>}
	 */
	private static function read_source( $source ) {
		global $wpdb;
		$posts = array();
		$terms = array();

		$meta_maps = array(
			'rankmath' => array(
				'rank_math_title'                => 'title',
				'rank_math_description'          => 'description',
				'rank_math_focus_keyword'        => 'focus_keyword',
				'rank_math_canonical_url'        => 'canonical',
				'rank_math_robots'               => 'robots',
				'rank_math_facebook_title'       => 'og_title',
				'rank_math_facebook_description' => 'og_description',
				'rank_math_facebook_image'       => 'og_image',
			),
			'yoast'    => array(
				'_yoast_wpseo_title'                 => 'title',
				'_yoast_wpseo_metadesc'              => 'description',
				'_yoast_wpseo_focuskw'               => 'focus_keyword',
				'_yoast_wpseo_canonical'             => 'canonical',
				'_yoast_wpseo_meta-robots-noindex'   => 'robots',
				'_yoast_wpseo_opengraph-title'       => 'og_title',
				'_yoast_wpseo_opengraph-description' => 'og_description',
				'_yoast_wpseo_opengraph-image'       => 'og_image',
			),
			'seopress' => array(
				'_seopress_titles_title'         => 'title',
				'_seopress_titles_desc'          => 'description',
				'_seopress_analysis_target_kw'   => 'focus_keyword',
				'_seopress_robots_canonical'     => 'canonical',
				'_seopress_robots_index'         => 'robots',
				'_seopress_social_fb_title'      => 'og_title',
				'_seopress_social_fb_desc'       => 'og_description',
				'_seopress_social_fb_img'        => 'og_image',
			),
			'aioseo'   => array(
				'_aioseo_title'          => 'title',
				'_aioseo_description'    => 'description',
				'_aioseo_og_title'       => 'og_title',
				'_aioseo_og_description' => 'og_description',
			),
		);
		if ( ! isset( $meta_maps[ $source ] ) ) {
			return compact( 'posts', 'terms' );
		}
		$map = $meta_maps[ $source ];

		foreach ( array( 'posts' => $wpdb->postmeta, 'terms' => $wpdb->termmeta ) as $kind => $table ) {
			$id_column    = 'posts' === $kind ? 'post_id' : 'term_id';
			$placeholders = implode( ',', array_fill( 0, count( $map ), '%s' ) );
			$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT {$id_column} AS object_id, meta_key, meta_value FROM {$table} WHERE meta_key IN ({$placeholders}) AND meta_value <> ''", array_keys( $map ) ) ); // phpcs:ignore WordPress.DB
			foreach ( $rows as $row ) {
				$field = $map[ $row->meta_key ];
				$value = (string) $row->meta_value;
				if ( 'robots' === $field ) {
					// Rank Math: serialized list containing "noindex". Yoast: "1". SEOPress: "yes".
					$noindex = false !== strpos( $value, 'noindex' ) || '1' === $value || 'yes' === $value;
					if ( ! $noindex ) {
						continue;
					}
					$field = 'noindex';
					$value = '1';
				}
				if ( 'posts' === $kind ) {
					$posts[ (int) $row->object_id ][ $field ] = $value;
				} else {
					$terms[ (int) $row->object_id ][ $field ] = $value;
				}
			}
		}

		// All in One SEO 4 keeps its data in its own tables.
		if ( 'aioseo' === $source ) {
			$table = $wpdb->prefix . 'aioseo_posts';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) { // phpcs:ignore WordPress.DB
				$rows = $wpdb->get_results( "SELECT post_id, title, description, canonical_url, og_title, og_description, keyphrases, robots_default, robots_noindex FROM {$table}" ); // phpcs:ignore WordPress.DB
				foreach ( $rows as $row ) {
					$data = array(
						'title'          => (string) $row->title,
						'description'    => (string) $row->description,
						'canonical'      => (string) $row->canonical_url,
						'og_title'       => (string) $row->og_title,
						'og_description' => (string) $row->og_description,
					);
					$keyphrases = json_decode( (string) $row->keyphrases, true );
					if ( ! empty( $keyphrases['focus']['keyphrase'] ) ) {
						$data['focus_keyword'] = (string) $keyphrases['focus']['keyphrase'];
					}
					if ( ! $row->robots_default && $row->robots_noindex ) {
						$data['noindex'] = '1';
					}
					$posts[ (int) $row->post_id ] = array_merge( isset( $posts[ (int) $row->post_id ] ) ? $posts[ (int) $row->post_id ] : array(), array_filter( $data, 'strlen' ) );
				}
			}
			$table = $wpdb->prefix . 'aioseo_terms';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) { // phpcs:ignore WordPress.DB
				$rows = $wpdb->get_results( "SELECT term_id, title, description FROM {$table}" ); // phpcs:ignore WordPress.DB
				foreach ( $rows as $row ) {
					$data                        = array_filter(
						array(
							'title'       => (string) $row->title,
							'description' => (string) $row->description,
						),
						'strlen'
					);
					$terms[ (int) $row->term_id ] = array_merge( isset( $terms[ (int) $row->term_id ] ) ? $terms[ (int) $row->term_id ] : array(), $data );
				}
			}
		}
		return compact( 'posts', 'terms' );
	}

	/**
	 * Copy SEO data from another plugin into Sox SEO's own fields: titles,
	 * descriptions, focus keywords, canonicals, noindex flags and social
	 * text, for posts and for categories. Values already set in Sox SEO are
	 * kept, and the other plugin's data is left untouched, so sources can be
	 * imported one after another with the most trusted first.
	 *
	 * @param string $source rankmath|aioseo|yoast|seopress.
	 * @return int|WP_Error Number of values copied.
	 */
	public static function import( $source ) {
		if ( ! array_key_exists( $source, self::import_sources() ) ) {
			return new WP_Error( 'sox_seo_import', __( 'Unknown import source.', 'sox-seo' ) );
		}
		$data = self::read_source( $source );

		// The same description pasted on many pages is boilerplate. Duplicate
		// descriptions hurt, so leave those pages empty for Claude to write.
		$seen = array();
		foreach ( $data['posts'] as $fields ) {
			if ( isset( $fields['description'] ) ) {
				$key          = md5( $fields['description'] );
				$seen[ $key ] = isset( $seen[ $key ] ) ? $seen[ $key ] + 1 : 1;
			}
		}

		$copied = 0;
		foreach ( array( 'posts', 'terms' ) as $kind ) {
			foreach ( $data[ $kind ] as $id => $fields ) {
				if ( 'posts' === $kind ) {
					$post = get_post( $id );
					if ( ! $post || 'revision' === $post->post_type ) {
						continue;
					}
					$object_title = $post->post_title;
				} else {
					$term = get_term( $id );
					if ( ! $term || is_wp_error( $term ) ) {
						continue;
					}
					$object_title = $term->name;
				}

				foreach ( $fields as $field => $value ) {
					if ( in_array( $field, array( 'title', 'og_title' ), true ) ) {
						$value = self::resolve_template( $value, $object_title, 'title' );
					} elseif ( in_array( $field, array( 'description', 'og_description' ), true ) ) {
						if ( 'description' === $field && 'posts' === $kind && $seen[ md5( $value ) ] > 3 ) {
							continue;
						}
						$value = self::resolve_template( $value, $object_title, 'description' );
					} elseif ( 'focus_keyword' === $field ) {
						$value = trim( (string) strtok( $value, ',' ) );
					} elseif ( in_array( $field, array( 'canonical', 'og_image' ), true ) ) {
						$value = esc_url_raw( $value );
					}
					if ( '' === $value ) {
						continue;
					}

					$key      = Sox_SEO_Meta::PREFIX . $field;
					$existing = 'posts' === $kind ? get_post_meta( $id, $key, true ) : get_term_meta( $id, $key, true );
					if ( '' !== (string) $existing ) {
						continue;
					}
					if ( 'posts' === $kind ) {
						update_post_meta( $id, $key, wp_slash( $value ) );
					} else {
						update_term_meta( $id, $key, wp_slash( $value ) );
					}
					$copied++;
				}
			}
		}
		return $copied;
	}

	/* ---- GTIN import ----------------------------------------------------- */

	/**
	 * Whether a number is a valid GTIN-8, UPC-12, EAN-13 or GTIN-14,
	 * including its check digit.
	 *
	 * @param string $gtin Digits only.
	 * @return bool
	 */
	public static function valid_gtin( $gtin ) {
		if ( ! preg_match( '/^(\d{8}|\d{12}|\d{13}|\d{14})$/', $gtin ) ) {
			return false;
		}
		$digits = array_reverse( str_split( $gtin ) );
		$check  = (int) array_shift( $digits );
		$sum    = 0;
		foreach ( $digits as $i => $digit ) {
			$sum += (int) $digit * ( 0 === $i % 2 ? 3 : 1 );
		}
		return ( 10 - $sum % 10 ) % 10 === $check;
	}

	/**
	 * Set product barcodes from a CSV with two columns: SKU and GTIN.
	 *
	 * @param string $file Path to the uploaded CSV.
	 * @return array|WP_Error {updated, unknown_sku, invalid, unchanged, examples}
	 */
	public static function import_gtins( $file ) {
		if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			return new WP_Error( 'sox_seo_gtin', __( 'WooCommerce is not active.', 'sox-seo' ) );
		}
		$handle = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return new WP_Error( 'sox_seo_gtin', __( 'The file could not be read.', 'sox-seo' ) );
		}
		$result = array(
			'updated'     => 0,
			'unchanged'   => 0,
			'unknown_sku' => 0,
			'invalid'     => 0,
			'examples'    => array(),
		);
		$first     = fgets( $handle );
		$delimiter = ( false !== $first && substr_count( $first, ';' ) > substr_count( $first, ',' ) ) ? ';' : ',';
		rewind( $handle );

		while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter, '"', '\\' ) ) ) { // phpcs:ignore
			if ( count( $row ) < 2 ) {
				continue;
			}
			$sku  = trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $row[0] ) );
			$gtin = preg_replace( '/\D/', '', (string) $row[1] );
			if ( '' === $sku || '' === $gtin ) {
				continue; // Header row or blank line.
			}
			if ( ! self::valid_gtin( $gtin ) ) {
				$result['invalid']++;
				if ( count( $result['examples'] ) < 5 ) {
					$result['examples'][] = $sku . ' → ' . $row[1];
				}
				continue;
			}
			$product = wc_get_product( wc_get_product_id_by_sku( $sku ) );
			if ( ! $product || ! method_exists( $product, 'set_global_unique_id' ) ) {
				$result['unknown_sku']++;
				continue;
			}
			if ( $product->get_global_unique_id() === $gtin ) {
				$result['unchanged']++;
				continue;
			}
			try {
				$product->set_global_unique_id( $gtin );
				$product->save();
				$result['updated']++;
			} catch ( Exception $e ) {
				$result['invalid']++; // WooCommerce refuses a GTIN already used by another product.
				if ( count( $result['examples'] ) < 5 ) {
					$result['examples'][] = $sku . ' → ' . $gtin;
				}
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $result;
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
