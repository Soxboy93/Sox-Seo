<?php
/**
 * Dashboard: the site's SEO health at a glance, and the branded header
 * shown on every Sox SEO screen.
 *
 * @package Sox_SEO
 */

defined( 'ABSPATH' ) || exit;

class Sox_SEO_Dashboard {

	const CACHE = 'sox_seo_dashboard';

	public static function init() {
		add_action( 'in_admin_header', array( __CLASS__, 'header' ) );
		add_action( 'admin_post_sox_seo_dashboard_refresh', array( __CLASS__, 'handle_refresh' ) );
		add_action( 'admin_post_sox_seo_prune_404', array( __CLASS__, 'handle_prune' ) );
	}

	/**
	 * Screens of the plugin, in menu order.
	 *
	 * @return array<string,string> Page slug => label.
	 */
	public static function tabs() {
		$tabs = array(
			'sox-seo'           => __( 'Dashboard', 'sox-seo' ),
			'sox-seo-bulk'      => __( 'Bulk AI', 'sox-seo' ),
			'sox-seo-editor'    => __( 'Bulk Editor', 'sox-seo' ),
			'sox-seo-search'    => __( 'Search', 'sox-seo' ),
			'sox-seo-links'     => __( 'Links', 'sox-seo' ),
			'sox-seo-speed'     => __( 'Speed', 'sox-seo' ),
			'sox-seo-products'  => __( 'Products', 'sox-seo' ),
			'sox-seo-redirects' => __( 'Redirects', 'sox-seo' ),
			'sox-seo-tools'     => __( 'Tools', 'sox-seo' ),
			'sox-seo-settings'  => __( 'Settings', 'sox-seo' ),
		);
		if ( ! class_exists( 'WooCommerce' ) ) {
			unset( $tabs['sox-seo-products'] );
		}
		return $tabs;
	}

	/**
	 * Brand bar and tabs at the top of every Sox SEO screen.
	 */
	public static function header() {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = self::tabs();
		if ( ! isset( $tabs[ $page ] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$has_key = '' !== Sox_SEO_Options::api_key();
		?>
		<div class="sox-seo-top">
			<div class="sox-seo-brand">
				<span class="sox-seo-logo" aria-hidden="true">
					<svg viewBox="0 0 32 32" width="32" height="32"><path d="M5 22l6-7 5 4 8-11" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><circle cx="24" cy="8" r="3" fill="currentColor"/></svg>
				</span>
				<span class="sox-seo-name">Sox SEO</span>
				<span class="sox-seo-pill"><?php echo esc_html( SOX_SEO_VERSION ); ?></span>
				<span class="sox-seo-pill <?php echo $has_key ? 'sox-seo-pill-on' : 'sox-seo-pill-off'; ?>">
					<?php echo $has_key ? esc_html__( 'Claude connected', 'sox-seo' ) : esc_html__( 'No API key', 'sox-seo' ); ?>
				</span>
				<?php if ( ! Sox_SEO_Meta::is_native() ) : ?>
					<span class="sox-seo-pill">
						<?php
						/* translators: %s: SEO plugin name */
						printf( esc_html__( 'AI layer for %s', 'sox-seo' ), esc_html( Sox_SEO_Meta::provider_label() ) );
						?>
					</span>
				<?php endif; ?>
			</div>
			<nav class="sox-seo-tabs" aria-label="<?php esc_attr_e( 'Sox SEO sections', 'sox-seo' ); ?>">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>" <?php echo $slug === $page ? 'class="is-current" aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
		</div>
		<?php
	}

	/**
	 * Everything the dashboard shows. Scoring every page takes a few
	 * seconds on a large site, so the result is kept for half an hour.
	 *
	 * @param bool $fresh Recalculate now.
	 * @return array
	 */
	public static function stats( $fresh = false ) {
		$cached = $fresh ? false : get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$ids = get_posts(
			array(
				'post_type'      => Sox_SEO_Meta::post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => 2000,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'post__not_in'   => Sox_SEO_Meta::utility_page_ids(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => Sox_SEO_Meta::key( 'noindex' ),
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$scores  = array();
		$by_type = array();
		$issues  = array();
		foreach ( $ids as $id ) {
			$analysis      = Sox_SEO_Analyzer::analyze( $id );
			$scores[ $id ] = $analysis['score'];
			$type          = get_post_type( $id );
			$by_type[ $type ][] = $analysis['score'];
			foreach ( $analysis['checks'] as $check ) {
				if ( 'good' !== $check['status'] ) {
					$label            = preg_replace( '/\d+(?:[.,]\d+)?/', 'N', $check['label'] );
					$issues[ $label ] = isset( $issues[ $label ] ) ? $issues[ $label ] + 1 : 1;
				}
			}
		}
		asort( $scores );
		arsort( $issues );

		$types = array();
		foreach ( $by_type as $type => $list ) {
			$object         = get_post_type_object( $type );
			$types[ $type ] = array(
				'label' => $object ? $object->labels->name : $type,
				'count' => count( $list ),
				'avg'   => (int) round( array_sum( $list ) / count( $list ) ),
			);
		}

		$good = 0;
		$ok   = 0;
		$bad  = 0;
		foreach ( $scores as $score ) {
			if ( $score >= 80 ) {
				$good++;
			} elseif ( $score >= 50 ) {
				$ok++;
			} else {
				$bad++;
			}
		}

		$audit = Sox_SEO_Link_Audit::state();
		global $wpdb;

		$stats = array(
			'time'   => time(),
			'count'  => count( $scores ),
			'avg'    => $scores ? (int) round( array_sum( $scores ) / count( $scores ) ) : 0,
			'good'   => $good,
			'ok'     => $ok,
			'bad'    => $bad,
			'types'  => $types,
			'worst'  => array_slice( $scores, 0, 8, true ),
			'issues' => array_slice( $issues, 0, 6, true ),
			'todo'   => array(
				'meta'         => Sox_SEO_Meta::is_supported( 'post' ) ? Sox_SEO_Automation::remaining( 'meta' ) : 0,
				'alt'          => Sox_SEO_Automation::remaining( 'alt' ),
				'product_copy' => class_exists( 'WooCommerce' ) ? Sox_SEO_Automation::remaining( 'product_copy' ) : 0,
				'term_copy'    => count( Sox_SEO_Automation::terms_without_copy( 500 ) ),
				'refine'       => Sox_SEO_Meta::is_native() ? (int) Sox_SEO_Automation::unrefined_query( 1 )->found_posts : 0,
				'broken'       => $audit['finished'] ? count( $audit['broken'] ) : -1,
				'orphans'      => $audit['finished'] ? count( Sox_SEO_Link_Audit::orphans() ) : -1,
				'not_found'    => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Sox_SEO_Redirects::table_404() ), // phpcs:ignore WordPress.DB
			),
			'audit'  => (int) $audit['finished'],
		);
		set_transient( self::CACHE, $stats, 30 * MINUTE_IN_SECONDS );
		return $stats;
	}

	/**
	 * @param int $score 0–100.
	 * @return string good|ok|bad
	 */
	private static function grade( $score ) {
		return $score >= 80 ? 'good' : ( $score >= 50 ? 'ok' : 'bad' );
	}

	/**
	 * One "needs attention" tile.
	 *
	 * @param int    $count  Items outstanding; -1 means not measured yet.
	 * @param string $label  What is outstanding.
	 * @param string $done   Text when nothing is outstanding.
	 * @param string $url    Where to act.
	 * @param string $action Button label.
	 * @param string $icon   Dashicon name without the prefix.
	 */
	private static function tile( $count, $label, $done, $url, $action, $icon ) {
		$state = $count < 0 ? 'unknown' : ( 0 === $count ? 'done' : 'todo' );
		?>
		<div class="sox-seo-tile sox-seo-tile-<?php echo esc_attr( $state ); ?>">
			<span class="sox-seo-tile-icon dashicons dashicons-<?php echo esc_attr( 'done' === $state ? 'yes-alt' : $icon ); ?>" aria-hidden="true"></span>
			<div class="sox-seo-tile-body">
				<?php if ( 'todo' === $state ) : ?>
					<strong class="sox-seo-tile-count"><?php echo esc_html( number_format_i18n( $count ) ); ?></strong>
					<span class="sox-seo-tile-label"><?php echo esc_html( $label ); ?></span>
				<?php elseif ( 'done' === $state ) : ?>
					<span class="sox-seo-tile-label"><?php echo esc_html( $done ); ?></span>
				<?php else : ?>
					<span class="sox-seo-tile-label"><?php echo esc_html( $label ); ?>: <?php esc_html_e( 'not checked yet', 'sox-seo' ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( 'done' !== $state ) : ?>
				<a class="button" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $action ); ?></a>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function page() {
		Sox_SEO_GSC::discover_site_kit();
		$stats   = self::stats();
		$todo    = $stats['todo'];
		$grade   = self::grade( $stats['avg'] );
		$usage   = Sox_SEO_Anthropic::usage();
		$month   = gmdate( 'Y-m' );
		$models  = Sox_SEO_Options::models();
		$model   = Sox_SEO_Options::get( 'model' );
		$circle  = 2 * M_PI * 52;
		$bulk    = admin_url( 'admin.php?page=sox-seo-bulk' );
		$message = isset( $_GET['sox_seo_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['sox_seo_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap sox-seo sox-seo-dashboard">
			<h1 class="screen-reader-text"><?php esc_html_e( 'Sox SEO dashboard', 'sox-seo' ); ?></h1>
			<?php if ( $message ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>

			<div class="sox-seo-grid sox-seo-grid-3">
				<section class="sox-seo-panel sox-seo-hero sox-seo-hero-<?php echo esc_attr( $grade ); ?>">
					<svg class="sox-seo-ring" viewBox="0 0 120 120" width="132" height="132" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: score */ __( 'Average on-page score %d out of 100', 'sox-seo' ), $stats['avg'] ) ); ?>">
						<circle cx="60" cy="60" r="52" class="sox-seo-ring-track" />
						<circle cx="60" cy="60" r="52" class="sox-seo-ring-value" stroke-dasharray="<?php echo esc_attr( round( $circle * $stats['avg'] / 100, 1 ) . ' ' . round( $circle, 1 ) ); ?>" transform="rotate(-90 60 60)" />
						<text x="60" y="58" text-anchor="middle" class="sox-seo-ring-number"><?php echo esc_html( (string) $stats['avg'] ); ?></text>
						<text x="60" y="76" text-anchor="middle" class="sox-seo-ring-of"><?php esc_html_e( 'out of 100', 'sox-seo' ); ?></text>
					</svg>
					<div>
						<h2><?php esc_html_e( 'On-page score', 'sox-seo' ); ?></h2>
						<p class="sox-seo-muted">
							<?php
							/* translators: %s: number of pages */
							printf( esc_html__( 'Average across %s pages that Google may index.', 'sox-seo' ), esc_html( number_format_i18n( $stats['count'] ) ) );
							?>
						</p>
						<div class="sox-seo-split" aria-hidden="true">
							<?php foreach ( array( 'good', 'ok', 'bad' ) as $band ) : ?>
								<?php if ( $stats[ $band ] ) : ?>
									<span class="sox-seo-split-<?php echo esc_attr( $band ); ?>" style="flex-grow:<?php echo (int) $stats[ $band ]; ?>"></span>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
						<ul class="sox-seo-legend">
							<li><i class="sox-seo-dot sox-seo-dot-good"></i><?php echo esc_html( number_format_i18n( $stats['good'] ) ); ?> <?php esc_html_e( 'good', 'sox-seo' ); ?></li>
							<li><i class="sox-seo-dot sox-seo-dot-ok"></i><?php echo esc_html( number_format_i18n( $stats['ok'] ) ); ?> <?php esc_html_e( 'need work', 'sox-seo' ); ?></li>
							<li><i class="sox-seo-dot sox-seo-dot-bad"></i><?php echo esc_html( number_format_i18n( $stats['bad'] ) ); ?> <?php esc_html_e( 'poor', 'sox-seo' ); ?></li>
						</ul>
					</div>
				</section>

				<section class="sox-seo-panel">
					<h2><span class="dashicons dashicons-google" aria-hidden="true"></span> <?php esc_html_e( 'Google Search, last 28 days', 'sox-seo' ); ?></h2>
					<?php
					$pages = Sox_SEO_GSC::is_connected() ? Sox_SEO_GSC::top_pages() : null;
					if ( is_array( $pages ) ) :
						$clicks      = array_sum( wp_list_pluck( $pages, 'clicks' ) );
						$impressions = array_sum( wp_list_pluck( $pages, 'impressions' ) );
						$weighted    = 0;
						foreach ( $pages as $row ) {
							$weighted += $row['position'] * $row['impressions'];
						}
						?>
						<div class="sox-seo-figures">
							<div><strong><?php echo esc_html( number_format_i18n( $clicks ) ); ?></strong><span><?php esc_html_e( 'clicks', 'sox-seo' ); ?></span></div>
							<div><strong><?php echo esc_html( number_format_i18n( $impressions ) ); ?></strong><span><?php esc_html_e( 'times shown', 'sox-seo' ); ?></span></div>
							<div><strong><?php echo esc_html( $impressions ? number_format_i18n( $weighted / $impressions, 1 ) : '–' ); ?></strong><span><?php esc_html_e( 'average position', 'sox-seo' ); ?></span></div>
						</div>
						<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=sox-seo-search' ) ); ?>"><?php esc_html_e( 'See pages and trends', 'sox-seo' ); ?></a></p>
					<?php elseif ( is_wp_error( $pages ) ) : ?>
						<p class="sox-seo-error"><?php echo esc_html( $pages->get_error_message() ); ?></p>
					<?php else : ?>
						<p class="sox-seo-muted"><?php esc_html_e( 'Connect Search Console to see clicks and positions here, and to let Claude write from real search data.', 'sox-seo' ); ?></p>
						<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=sox-seo-settings' ) ); ?>"><?php esc_html_e( 'Connect', 'sox-seo' ); ?></a></p>
					<?php endif; ?>
				</section>

				<section class="sox-seo-panel">
					<h2><span class="dashicons dashicons-superhero" aria-hidden="true"></span> <?php esc_html_e( 'Claude this month', 'sox-seo' ); ?></h2>
					<div class="sox-seo-figures">
						<div><strong><?php echo esc_html( number_format_i18n( isset( $usage[ $month ] ) ? $usage[ $month ]['requests'] : 0 ) ); ?></strong><span><?php esc_html_e( 'requests', 'sox-seo' ); ?></span></div>
						<div><strong><?php echo esc_html( isset( $usage[ $month ] ) ? number_format_i18n( ( $usage[ $month ]['input'] + $usage[ $month ]['output'] ) / 1000 ) . 'k' : '0' ); ?></strong><span><?php esc_html_e( 'tokens', 'sox-seo' ); ?></span></div>
					</div>
					<p class="sox-seo-muted"><?php echo esc_html( isset( $models[ $model ] ) ? strtok( $models[ $model ], '—' ) : $model ); ?></p>
					<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=sox-seo-settings' ) ); ?>"><?php esc_html_e( 'Model and automation', 'sox-seo' ); ?></a></p>
				</section>
			</div>

			<h2 class="sox-seo-section"><?php esc_html_e( 'What needs attention', 'sox-seo' ); ?></h2>
			<div class="sox-seo-grid sox-seo-grid-4">
				<?php
				self::tile( $todo['meta'], __( 'pages without a meta description', 'sox-seo' ), __( 'Every page has a meta description', 'sox-seo' ), $bulk, __( 'Write them', 'sox-seo' ), 'edit-large' );
				self::tile( $todo['refine'], __( 'pages whose focus keyword is not reviewed', 'sox-seo' ), __( 'Every focus keyword is reviewed', 'sox-seo' ), $bulk, __( 'Review', 'sox-seo' ), 'search' );
				self::tile( $todo['alt'], __( 'images without alt text', 'sox-seo' ), __( 'Every image has alt text', 'sox-seo' ), $bulk, __( 'Describe them', 'sox-seo' ), 'format-image' );
				if ( class_exists( 'WooCommerce' ) ) {
					self::tile( $todo['product_copy'], __( 'products without a short description', 'sox-seo' ), __( 'Every product has a short description', 'sox-seo' ), $bulk, __( 'Write them', 'sox-seo' ), 'cart' );
				}
				self::tile( $todo['term_copy'], __( 'categories without an introduction', 'sox-seo' ), __( 'Every category has an introduction', 'sox-seo' ), $bulk, __( 'Write them', 'sox-seo' ), 'category' );
				self::tile( $todo['broken'], __( 'broken links', 'sox-seo' ), __( 'No broken links', 'sox-seo' ), admin_url( 'admin.php?page=sox-seo-links' ), $todo['broken'] < 0 ? __( 'Scan', 'sox-seo' ) : __( 'Fix', 'sox-seo' ), 'editor-unlink' );
				self::tile( $todo['orphans'], __( 'pages nothing links to', 'sox-seo' ), __( 'Every page is linked from another', 'sox-seo' ), admin_url( 'admin.php?page=sox-seo-links' ), $todo['orphans'] < 0 ? __( 'Scan', 'sox-seo' ) : __( 'See them', 'sox-seo' ), 'admin-links' );
				self::tile( $todo['not_found'], __( 'missing addresses visitors asked for', 'sox-seo' ), __( 'No missing addresses logged', 'sox-seo' ), admin_url( 'admin.php?page=sox-seo-redirects' ), __( 'Redirect', 'sox-seo' ), 'randomize' );
				?>
			</div>

			<div class="sox-seo-grid sox-seo-grid-2">
				<section class="sox-seo-panel">
					<h2><?php esc_html_e( 'Pages to improve first', 'sox-seo' ); ?></h2>
					<table class="sox-seo-list">
						<tbody>
						<?php foreach ( $stats['worst'] as $id => $score ) : ?>
							<tr>
								<td><span class="sox-seo-score sox-seo-<?php echo esc_attr( self::grade( $score ) ); ?>"><?php echo esc_html( (string) $score ); ?></span></td>
								<td><a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( wp_strip_all_tags( get_the_title( $id ) ) ); ?></a></td>
								<td class="sox-seo-muted"><?php echo esc_html( get_post_type( $id ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</section>

				<section class="sox-seo-panel">
					<h2><?php esc_html_e( 'By content type', 'sox-seo' ); ?></h2>
					<?php foreach ( $stats['types'] as $type ) : ?>
						<div class="sox-seo-bar-row">
							<span><?php echo esc_html( $type['label'] ); ?> <em class="sox-seo-muted">(<?php echo esc_html( number_format_i18n( $type['count'] ) ); ?>)</em></span>
							<span class="sox-seo-bar"><i class="sox-seo-bar-<?php echo esc_attr( self::grade( $type['avg'] ) ); ?>" style="width:<?php echo (int) $type['avg']; ?>%"></i></span>
							<strong><?php echo esc_html( (string) $type['avg'] ); ?></strong>
						</div>
					<?php endforeach; ?>

					<?php if ( $stats['issues'] ) : ?>
						<h3><?php esc_html_e( 'Most common open checks', 'sox-seo' ); ?></h3>
						<ul class="sox-seo-issues">
							<?php foreach ( $stats['issues'] as $label => $count ) : ?>
								<li><strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong> <?php echo esc_html( $label ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</section>
			</div>

			<p class="sox-seo-muted sox-seo-foot">
				<?php
				/* translators: %s: time ago */
				printf( esc_html__( 'Calculated %s ago.', 'sox-seo' ), esc_html( human_time_diff( $stats['time'] ) ) );
				?>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sox_seo_dashboard_refresh' ), 'sox_seo_dashboard' ) ); ?>"><?php esc_html_e( 'Recalculate now', 'sox-seo' ); ?></a>
			</p>
		</div>
		<?php
	}

	public static function handle_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'sox-seo' ) );
		}
		check_admin_referer( 'sox_seo_dashboard' );
		delete_transient( self::CACHE );
		Sox_SEO_GSC::discover_site_kit( true );
		wp_safe_redirect( admin_url( 'admin.php?page=sox-seo' ) );
		exit;
	}

	public static function handle_prune() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'sox-seo' ) );
		}
		check_admin_referer( 'sox_seo_redirect' );
		$removed = Sox_SEO_Redirects::prune_noise();
		delete_transient( self::CACHE );
		/* translators: %d: number of entries */
		wp_safe_redirect( add_query_arg( 'sox_seo_msg', rawurlencode( sprintf( __( '%d bot probes and missing-file entries removed.', 'sox-seo' ), $removed ) ), admin_url( 'admin.php?page=sox-seo-redirects' ) ) );
		exit;
	}
}
