<?php
/**
 * Admin report screens: search performance, link audit, product feed check
 * and tools.
 *
 * @package Sox_SEO
 */

defined( 'ABSPATH' ) || exit;

class Sox_SEO_Admin_Pages {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_sox_seo_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_sox_seo_report', array( __CLASS__, 'handle_report' ) );
	}

	public static function menu() {
		add_submenu_page( 'sox-seo', __( 'Search Performance', 'sox-seo' ), __( 'Search Performance', 'sox-seo' ), 'manage_options', 'sox-seo-search', array( __CLASS__, 'page_search' ) );
		add_submenu_page( 'sox-seo', __( 'Link Audit', 'sox-seo' ), __( 'Link Audit', 'sox-seo' ), 'manage_options', 'sox-seo-links', array( __CLASS__, 'page_links' ) );
		if ( class_exists( 'WooCommerce' ) ) {
			add_submenu_page( 'sox-seo', __( 'Product Feed Check', 'sox-seo' ), __( 'Product Feed Check', 'sox-seo' ), 'manage_options', 'sox-seo-products', array( __CLASS__, 'page_products' ) );
		}
		add_submenu_page( 'sox-seo', __( 'Tools', 'sox-seo' ), __( 'Tools', 'sox-seo' ), 'manage_options', 'sox-seo-tools', array( __CLASS__, 'page_tools' ) );
	}

	/**
	 * Link to a page's editor, or the bare URL when it is not a post.
	 *
	 * @param string $url     Page URL.
	 * @param int    $post_id Post ID, 0 if unknown.
	 */
	private static function page_cell( $url, $post_id ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( rawurldecode( $path ? $path : $url ) ) . '</a>';
		if ( $post_id ) {
			echo ' <span class="sox-seo-muted">· <a href="' . esc_url( (string) get_edit_post_link( $post_id ) ) . '">' . esc_html__( 'Edit', 'sox-seo' ) . '</a></span>';
		}
	}

	/* ---- Search performance ---------------------------------------------- */

	public static function page_search() {
		echo '<div class="wrap sox-seo"><h1>' . esc_html__( 'Search Performance', 'sox-seo' ) . '</h1>';
		if ( ! Sox_SEO_GSC::is_connected() ) {
			echo '<p>' . esc_html__( 'Connect Google Search Console to see which pages are close to ranking higher and what people search to find them.', 'sox-seo' ) . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=sox-seo' ) ) . '">' . esc_html__( 'Connect in settings', 'sox-seo' ) . '</a></p></div>';
			return;
		}

		$fresh = isset( $_GET['refresh'] ) && check_admin_referer( 'sox_seo_gsc_refresh' );
		$pages = Sox_SEO_GSC::top_pages( 200, $fresh );
		if ( is_wp_error( $pages ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $pages->get_error_message() ) . '</p></div></div>';
			return;
		}

		$clicks      = array_sum( wp_list_pluck( $pages, 'clicks' ) );
		$impressions = array_sum( wp_list_pluck( $pages, 'impressions' ) );
		echo '<p>';
		/* translators: 1: clicks, 2: impressions, 3: property */
		printf( esc_html__( 'Last 28 days for %3$s: %1$s clicks from %2$s impressions across the top pages.', 'sox-seo' ), '<strong>' . esc_html( number_format_i18n( $clicks ) ) . '</strong>', '<strong>' . esc_html( number_format_i18n( $impressions ) ) . '</strong>', '<code>' . esc_html( Sox_SEO_GSC::property() ) . '</code>' );
		echo ' <a href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=sox-seo-search&refresh=1' ), 'sox_seo_gsc_refresh' ) ) . '">' . esc_html__( 'Refresh', 'sox-seo' ) . '</a></p>';

		$chances = Sox_SEO_GSC::opportunities();
		echo '<h2>' . esc_html__( 'Pages close to earning more traffic', 'sox-seo' ) . '</h2>';
		echo '<p>' . esc_html__( 'These already appear in Google. Open each one and use "Generate with Claude" or "Audit content": Claude is given this search data.', 'sox-seo' ) . '</p>';
		self::search_table( is_wp_error( $chances ) ? array() : $chances, true );

		echo '<h2>' . esc_html__( 'Top pages', 'sox-seo' ) . '</h2>';
		self::search_table( array_slice( $pages, 0, 50 ), false );
		echo '</div>';
	}

	/**
	 * @param array $rows        Page rows.
	 * @param bool  $with_reason Whether rows carry a reason and post ID.
	 */
	private static function search_table( array $rows, $with_reason ) {
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Page', 'sox-seo' ) . '</th><th>' . esc_html__( 'Position', 'sox-seo' ) . '</th><th>' . esc_html__( 'Impressions', 'sox-seo' ) . '</th><th>' . esc_html__( 'Clicks', 'sox-seo' ) . '</th><th>' . esc_html__( 'Click rate', 'sox-seo' ) . '</th>';
		if ( $with_reason ) {
			echo '<th>' . esc_html__( 'What to do', 'sox-seo' ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'Nothing to show yet.', 'sox-seo' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			echo '<tr><td>';
			self::page_cell( $row['page'], $with_reason ? (int) $row['post_id'] : url_to_postid( $row['page'] ) );
			echo '</td><td>' . esc_html( number_format_i18n( $row['position'], 1 ) ) . '</td><td>' . esc_html( number_format_i18n( $row['impressions'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['clicks'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['ctr'], 1 ) ) . '%</td>';
			if ( $with_reason ) {
				echo '<td>' . esc_html( $row['reason'] ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/* ---- Link audit ------------------------------------------------------ */

	public static function page_links() {
		$state   = Sox_SEO_Link_Audit::state();
		$orphans = Sox_SEO_Link_Audit::orphans();
		?>
		<div class="wrap sox-seo">
			<h1><?php esc_html_e( 'Link Audit', 'sox-seo' ); ?></h1>
			<p><?php esc_html_e( 'Scans every published page for links that lead nowhere, and finds pages that no other page links to. Keep this tab open while it runs.', 'sox-seo' ); ?></p>
			<p>
				<button type="button" class="button button-primary" id="sox-seo-scan"><?php esc_html_e( 'Scan now', 'sox-seo' ); ?></button>
				<span class="sox-seo-status" id="sox-seo-scan-status">
					<?php
					if ( $state['finished'] ) {
						/* translators: 1: date, 2: number of pages */
						printf( esc_html__( 'Last scan: %1$s, %2$d pages.', 'sox-seo' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $state['finished'] ) ), (int) $state['scanned'] );
					}
					?>
				</span>
			</p>

			<?php if ( $state['finished'] ) : ?>
			<h2>
				<?php
				/* translators: %d: number of links */
				printf( esc_html__( 'Broken links (%d)', 'sox-seo' ), count( $state['broken'] ) );
				?>
			</h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Found on', 'sox-seo' ); ?></th><th><?php esc_html_e( 'Broken link', 'sox-seo' ); ?></th><th><?php esc_html_e( 'Problem', 'sox-seo' ); ?></th></tr></thead>
				<tbody>
				<?php if ( ! $state['broken'] ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'No broken links found.', 'sox-seo' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $state['broken'] as $row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( (string) get_edit_post_link( $row['post_id'] ) ); ?>"><?php echo esc_html( wp_strip_all_tags( get_the_title( $row['post_id'] ) ) ); ?></a></td>
						<td><code><?php echo esc_html( $row['url'] ); ?></code></td>
						<td><?php echo $row['status'] ? esc_html( (string) $row['status'] ) : esc_html__( 'No response', 'sox-seo' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2>
				<?php
				/* translators: %d: number of pages */
				printf( esc_html__( 'Pages nothing links to (%d)', 'sox-seo' ), count( $orphans ) );
				?>
			</h2>
			<p><?php esc_html_e( 'Search engines find and value pages through links. These have none from the content of other pages (menus and category listings are not counted; products are not listed because shop categories link to them). Claude now favours these pages when it suggests internal links elsewhere.', 'sox-seo' ); ?></p>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Page', 'sox-seo' ); ?></th><th><?php esc_html_e( 'Type', 'sox-seo' ); ?></th></tr></thead>
				<tbody>
				<?php if ( ! $orphans ) : ?>
					<tr><td colspan="2"><?php esc_html_e( 'Every page has at least one link pointing to it.', 'sox-seo' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $orphans as $id ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( wp_strip_all_tags( get_the_title( $id ) ) ); ?></a></td>
						<td><?php echo esc_html( get_post_type( $id ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---- Product feed check ---------------------------------------------- */

	public static function page_products() {
		$check = Sox_SEO_Tools::product_check();
		?>
		<div class="wrap sox-seo">
			<h1><?php esc_html_e( 'Product Feed Check', 'sox-seo' ); ?></h1>
			<p><?php esc_html_e( 'Google Shopping and product rich results need certain details on every product. Products missing them are shown less often or rejected by Google Merchant Center.', 'sox-seo' ); ?></p>
			<p>
				<?php
				/* translators: 1: products checked, 2: total products */
				printf( esc_html__( 'Checked %1$s of %2$s published products.', 'sox-seo' ), '<strong>' . esc_html( number_format_i18n( $check['checked'] ) ) . '</strong>', esc_html( number_format_i18n( $check['total'] ) ) );
				?>
			</p>
			<?php if ( $check['checked'] ) : ?>
			<ul class="sox-seo-tally">
				<?php foreach ( $check['counts'] as $issue => $count ) : ?>
					<li class="<?php echo $count ? 'sox-seo-has-issues' : ''; ?>"><strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong> <?php echo esc_html( $check['labels'][ $issue ] ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p class="sox-seo-muted"><?php esc_html_e( 'A brand can be set once for the whole shop under Sox SEO → Settings → Default product brand. GTIN is the barcode number (WooCommerce: product → Inventory → GTIN, UPC, EAN or ISBN); products you make yourself do not need one.', 'sox-seo' ); ?></p>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Product', 'sox-seo' ); ?></th><th><?php esc_html_e( 'Missing', 'sox-seo' ); ?></th></tr></thead>
				<tbody>
				<?php if ( ! $check['rows'] ) : ?>
					<tr><td colspan="2"><?php esc_html_e( 'Every product has what Google asks for.', 'sox-seo' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $check['rows'] as $row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( (string) get_edit_post_link( $row['id'] ) ); ?>"><?php echo esc_html( $row['name'] ); ?></a></td>
						<td>
							<?php
							$missing = array();
							foreach ( $row['issues'] as $issue ) {
								$missing[] = $check['labels'][ $issue ];
							}
							echo esc_html( implode( ', ', $missing ) );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---- Tools ----------------------------------------------------------- */

	public static function page_tools() {
		$message = isset( $_GET['sox_seo_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['sox_seo_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap sox-seo">
			<h1><?php esc_html_e( 'Tools', 'sox-seo' ); ?></h1>
			<?php if ( $message ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Import from another SEO plugin', 'sox-seo' ); ?></h2>
			<p><?php esc_html_e( 'Copies SEO titles, descriptions, focus keywords and canonical URLs into Sox SEO, so nothing is lost if you switch that plugin off. The other plugin\'s data is not changed, and values you already set in Sox SEO are kept.', 'sox-seo' ); ?></p>
			<?php if ( ! Sox_SEO_Meta::is_native() ) : ?>
				<p class="sox-seo-muted">
					<?php
					/* translators: %s: SEO plugin name */
					printf( esc_html__( '%s is active and in charge of the meta tags, so there is no need to import now. Import just before you deactivate it.', 'sox-seo' ), esc_html( Sox_SEO_Meta::provider_label() ) );
					?>
				</p>
			<?php endif; ?>
			<p>
				<?php foreach ( Sox_SEO_Tools::import_sources() as $id => $source ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sox_seo_import&source=' . $id ), 'sox_seo_tools' ) ); ?>">
						<?php
						/* translators: %s: SEO plugin name */
						printf( esc_html__( 'Import from %s', 'sox-seo' ), esc_html( $source['label'] ) );
						?>
					</a>
				<?php endforeach; ?>
			</p>

			<h2><?php esc_html_e( 'Weekly report', 'sox-seo' ); ?></h2>
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sox_seo_report' ), 'sox_seo_tools' ) ); ?>"><?php esc_html_e( 'Send the report now', 'sox-seo' ); ?></a></p>
			<pre class="sox-seo-report"><?php echo esc_html( Sox_SEO_Tools::report_body() ); ?></pre>

			<h2><?php esc_html_e( 'Breadcrumbs', 'sox-seo' ); ?></h2>
			<p>
				<?php
				/* translators: %s: shortcode */
				printf( esc_html__( 'Add %s to a page, a template or an Elementor Shortcode widget to show a breadcrumb trail (Home › Category › Page).', 'sox-seo' ), '<code>[sox_seo_breadcrumbs]</code>' );
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * @param string $message Notice text.
	 */
	private static function back_to_tools( $message ) {
		wp_safe_redirect( add_query_arg( 'sox_seo_msg', rawurlencode( $message ), admin_url( 'admin.php?page=sox-seo-tools' ) ) );
		exit;
	}

	public static function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'sox-seo' ) );
		}
		check_admin_referer( 'sox_seo_tools' );
		$copied = Sox_SEO_Tools::import( isset( $_GET['source'] ) ? sanitize_key( $_GET['source'] ) : '' );
		self::back_to_tools(
			is_wp_error( $copied )
				? $copied->get_error_message()
				/* translators: %d: number of values */
				: sprintf( __( '%d values imported.', 'sox-seo' ), $copied )
		);
	}

	public static function handle_report() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'sox-seo' ) );
		}
		check_admin_referer( 'sox_seo_tools' );
		self::back_to_tools( Sox_SEO_Tools::send_report() ? __( 'Report sent.', 'sox-seo' ) : __( 'The report could not be sent: this site cannot send e-mail.', 'sox-seo' ) );
	}
}
