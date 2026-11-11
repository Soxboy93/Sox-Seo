<?php
/**
 * Background automation: write meta when content is published, and an hourly
 * "autopilot" that works through pages and images still missing SEO data.
 *
 * @package Sox_SEO
 */

defined( 'ABSPATH' ) || exit;

class Sox_SEO_Automation {

	const CRON_HOOK     = 'sox_seo_autopilot';
	const COUNT_OPTION  = 'sox_seo_autopilot_count';
	const ITEMS_PER_RUN = 3;

	public static function init() {
		add_action( 'transition_post_status', array( __CLASS__, 'on_publish' ), 10, 3 );
		add_action( 'sox_seo_generate_meta', array( __CLASS__, 'generate_meta' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'autopilot' ) );
		add_filter( 'posts_where', array( __CLASS__, 'where_empty_excerpt' ), 10, 2 );

		// Keep the schedule in step with the setting.
		$scheduled = wp_next_scheduled( self::CRON_HOOK );
		if ( Sox_SEO_Options::get( 'autopilot' ) && ! $scheduled ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
		} elseif ( ! Sox_SEO_Options::get( 'autopilot' ) && $scheduled ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	public static function activate() {
		// Scheduling happens in init() once settings are known.
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * @param string  $new  New status.
	 * @param string  $old  Old status.
	 * @param WP_Post $post Post.
	 */
	public static function on_publish( $new, $old, $post ) {
		if ( 'publish' !== $new || 'publish' === $old ) {
			return;
		}
		if ( ! Sox_SEO_Options::get( 'auto_meta_on_publish' ) || '' === Sox_SEO_Options::api_key() ) {
			return;
		}
		if ( ! in_array( $post->post_type, Sox_SEO_Meta::post_types(), true ) || ! Sox_SEO_Meta::is_supported( 'post' ) ) {
			return;
		}
		// Runs after the editor has finished saving its own SEO fields.
		wp_schedule_single_event( time() + 20, 'sox_seo_generate_meta', array( (int) $post->ID ) );
	}

	/**
	 * Generate and save meta for a post that has no description yet.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error|null Meta written, error, or null if skipped.
	 */
	public static function generate_meta( $post_id ) {
		$post_id = (int) $post_id;
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return null;
		}
		if ( '' !== Sox_SEO_Meta::get_post( $post_id, 'description' ) && ! Sox_SEO_Options::get( 'overwrite_existing' ) ) {
			return null;
		}
		$meta = Sox_SEO_AI::post_meta( $post_id );
		if ( is_wp_error( $meta ) ) {
			update_post_meta( $post_id, '_sox_seo_ai_error', $meta->get_error_message() );
			return $meta;
		}
		Sox_SEO_AI::apply_post_meta( $post_id, $meta );
		delete_post_meta( $post_id, '_sox_seo_ai_error' );
		return $meta;
	}

	/**
	 * IDs of published posts with no meta description.
	 *
	 * @param int      $limit      Max IDs.
	 * @param int[]    $exclude    IDs to skip.
	 * @param string[] $post_types Post types; all managed types by default.
	 * @return int[]
	 */
	public static function missing_meta( $limit = 1, array $exclude = array(), array $post_types = array() ) {
		return get_posts(
			array(
				'post_type'      => $post_types ? $post_types : Sox_SEO_Meta::post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'post__not_in'   => array_merge( $exclude, Sox_SEO_Meta::utility_page_ids() ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => Sox_SEO_Meta::missing_description_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
	}

	/**
	 * Query args for published products with no short description.
	 *
	 * @param int   $limit   Max IDs.
	 * @param int[] $exclude IDs to skip.
	 * @return array
	 */
	private static function products_without_copy_args( $limit, array $exclude = array() ) {
		return array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'posts_per_page'         => $limit,
			'fields'                 => 'ids',
			'post__not_in'           => $exclude, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
			'orderby'                => 'ID',
			'order'                  => 'DESC',
			'sox_seo_empty_excerpt'  => true,
			'suppress_filters'       => false,
		);
	}

	/**
	 * Restrict a query to posts with an empty excerpt.
	 *
	 * @param string   $where SQL WHERE clause.
	 * @param WP_Query $query Query.
	 * @return string
	 */
	public static function where_empty_excerpt( $where, $query ) {
		global $wpdb;
		if ( $query->get( 'sox_seo_empty_excerpt' ) ) {
			$where .= " AND {$wpdb->posts}.post_excerpt = ''";
		}
		return $where;
	}

	/**
	 * @param int   $limit   Max IDs.
	 * @param int[] $exclude IDs to skip.
	 * @return int[]
	 */
	public static function products_without_copy( $limit = 1, array $exclude = array() ) {
		$query = new WP_Query( self::products_without_copy_args( $limit, $exclude ) );
		return array_map( 'intval', $query->posts );
	}

	/**
	 * Write the short description of a product that has none. The full
	 * description is left alone in bulk runs.
	 *
	 * @param int $post_id Product ID.
	 * @return string|WP_Error The short description saved.
	 */
	public static function generate_product_copy( $post_id ) {
		$copy = Sox_SEO_AI::product_copy( $post_id );
		if ( is_wp_error( $copy ) ) {
			return $copy;
		}
		$result = Sox_SEO_AI::apply_product_copy( $post_id, $copy['short_description'], '' );
		return is_wp_error( $result ) ? $result : $copy['short_description'];
	}

	/**
	 * Categories that list something but have no introduction text.
	 *
	 * @param int   $limit   Max IDs.
	 * @param int[] $exclude IDs to skip.
	 * @return int[]
	 */
	public static function terms_without_copy( $limit = 1, array $exclude = array() ) {
		$taxonomies = array_values( array_filter( array( 'product_cat', 'category' ), 'taxonomy_exists' ) );
		$terms      = get_terms(
			array(
				'taxonomy'   => $taxonomies,
				'hide_empty' => true,
				'exclude'    => $exclude,
				'fields'     => 'all',
			)
		);
		$out = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			if ( '' === trim( wp_strip_all_tags( $term->description ) ) && 'uncategorized' !== $term->slug ) {
				$out[] = (int) $term->term_id;
				if ( count( $out ) >= $limit ) {
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Count of items still missing data, for the bulk screen.
	 *
	 * @param string $task meta|alt|product_copy.
	 * @param string $post_type Post type filter for the meta task.
	 * @return int
	 */
	public static function remaining( $task, $post_type = '' ) {
		$args = array(
			'posts_per_page' => 1,
			'fields'         => 'ids',
		);
		if ( 'product_copy' === $task ) {
			$query = new WP_Query( self::products_without_copy_args( 1 ) );
			return (int) $query->found_posts;
		}
		if ( 'alt' === $task ) {
			$args += array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => '_wp_attachment_image_alt',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => '_wp_attachment_image_alt',
						'value' => '',
					),
				),
			);
		} else {
			$args += array(
				'post_type'   => $post_type ? array( $post_type ) : Sox_SEO_Meta::post_types(),
				'post_status' => 'publish',
				'post__not_in' => Sox_SEO_Meta::utility_page_ids(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
				'meta_query'  => Sox_SEO_Meta::missing_description_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			);
		}
		$query = new WP_Query( $args );
		return (int) $query->found_posts;
	}

	/**
	 * Hourly run. Handles a few items each time and stops at the daily limit,
	 * so API spend stays predictable.
	 */
	public static function autopilot() {
		if ( ! Sox_SEO_Options::get( 'autopilot' ) || '' === Sox_SEO_Options::api_key() ) {
			return;
		}
		$today = gmdate( 'Y-m-d' );
		$count = get_option( self::COUNT_OPTION, array() );
		$count = ( is_array( $count ) && isset( $count['day'] ) && $count['day'] === $today ) ? $count : array(
			'day'  => $today,
			'done' => 0,
		);
		$limit = (int) Sox_SEO_Options::get( 'autopilot_daily_limit' );
		$tried = (array) get_transient( 'sox_seo_autopilot_failed' );

		for ( $i = 0; $i < self::ITEMS_PER_RUN && $count['done'] < $limit; $i++ ) {
			$failed_id = 0;
			$ids       = Sox_SEO_Meta::is_supported( 'post' ) ? self::missing_meta( 1, array_map( 'intval', $tried ) ) : array();
			if ( $ids ) {
				$result    = self::generate_meta( $ids[0] );
				$failed_id = is_wp_error( $result ) ? $ids[0] : 0;
			} else {
				$ids = Sox_SEO_Images::missing_alt( 1, array_map( 'intval', $tried ) );
				if ( ! $ids ) {
					break; // Nothing left to do.
				}
				$result    = Sox_SEO_Images::generate( $ids[0] );
				$failed_id = is_wp_error( $result ) ? $ids[0] : 0;
			}
			$count['done']++;
			if ( $failed_id ) {
				// Do not retry a failing item every hour.
				$tried[] = $failed_id;
				set_transient( 'sox_seo_autopilot_failed', array_slice( $tried, -200 ), DAY_IN_SECONDS );
			}
		}
		update_option( self::COUNT_OPTION, $count, false );
	}
}
