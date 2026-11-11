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
		add_action( 'admin_post_sox_seo_gtin', array( __CLASS__, 'handle_gtin' ) );
		add_action( 'admin_post_sox_seo_cleanup', array( __CLASS__, 'handle_cleanup' ) );
	}

	public static function menu() {
		add_submenu_page( 'sox-seo', __( 'Bulk Editor', 'sox-seo' ), __( 'Bulk Editor', 'sox-seo' ), 'manage_options', 'sox-seo-editor', array( __CLASS__, 'page_editor' ) );
		add_submenu_page( 'sox-seo', __( 'Speed Report', 'sox-seo' ), __( 'Speed Report', 'sox-seo' ), 'manage_options', 'sox-seo-speed', array( __CLASS__, 'page_speed' ) );
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

		// Weekly history. The first snapshot is taken the first time this
		// screen is opened, then once a week in the background.
		$history = Sox_SEO_GSC::history();
		if ( ! $history ) {
			Sox_SEO_GSC::snapshot();
			$history = Sox_SEO_GSC::history();
		}
		echo '<h2>' . esc_html__( 'Week by week', 'sox-seo' ) . '</h2>';
		echo '<p>' . esc_html__( 'A snapshot is saved every week, so you can see whether changes are working. Each row covers the 28 days before its date. A lower position number is better.', 'sox-seo' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'sox-seo' ) . '</th><th>' . esc_html__( 'Clicks', 'sox-seo' ) . '</th><th>' . esc_html__( 'Impressions', 'sox-seo' ) . '</th><th>' . esc_html__( 'Average position', 'sox-seo' ) . '</th></tr></thead><tbody>';
		$previous = null;
		$rows     = array();
		foreach ( $history as $snapshot ) {
			$cells = array();
			foreach ( array( 'clicks', 'impressions', 'position' ) as $metric ) {
				$change = '';
				if ( $previous ) {
					$delta = $snapshot[ $metric ] - $previous[ $metric ];
					if ( abs( $delta ) >= 0.05 ) {
						$good   = 'position' === $metric ? $delta < 0 : $delta > 0;
						$change = ' <span class="' . ( $good ? 'sox-seo-up' : 'sox-seo-down' ) . '">' . ( $delta > 0 ? '+' : '−' ) . esc_html( number_format_i18n( abs( $delta ), 'position' === $metric ? 1 : 0 ) ) . '</span>';
					}
				}
				$cells[] = '<td>' . esc_html( number_format_i18n( $snapshot[ $metric ], 'position' === $metric ? 1 : 0 ) ) . $change . '</td>';
			}
			$rows[]   = '<tr><td>' . esc_html( $snapshot['date'] ) . '</td>' . implode( '', $cells ) . '</tr>';
			$previous = $snapshot;
		}
		echo implode( '', array_reverse( $rows ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</tbody></table>';

		// Biggest movers between the two most recent snapshots.
		if ( count( $history ) > 1 ) {
			$latest = $history[ count( $history ) - 1 ]['pages'];
			$before = $history[ count( $history ) - 2 ]['pages'];
			$moves  = array();
			foreach ( $latest as $url => $now ) {
				if ( isset( $before[ $url ] ) && abs( $now[2] - $before[ $url ][2] ) >= 1 ) {
					$moves[ $url ] = $before[ $url ][2] - $now[2]; // Positive = moved up.
				}
			}
			arsort( $moves );
			$moves = array_slice( $moves, 0, 10, true ) + array_slice( $moves, -10, null, true );
			if ( $moves ) {
				echo '<h2>' . esc_html__( 'Biggest moves since the previous snapshot', 'sox-seo' ) . '</h2>';
				echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Page', 'sox-seo' ) . '</th><th>' . esc_html__( 'Position', 'sox-seo' ) . '</th><th>' . esc_html__( 'Change', 'sox-seo' ) . '</th></tr></thead><tbody>';
				foreach ( $moves as $url => $move ) {
					echo '<tr><td>';
					self::page_cell( $url, url_to_postid( $url ) );
					echo '</td><td>' . esc_html( number_format_i18n( $latest[ $url ][2], 1 ) ) . '</td><td><span class="' . ( $move > 0 ? 'sox-seo-up' : 'sox-seo-down' ) . '">' . ( $move > 0 ? '▲ ' : '▼ ' ) . esc_html( number_format_i18n( abs( $move ), 1 ) ) . '</span></td></tr>';
				}
				echo '</tbody></table>';
			}
		}
		echo '</div>';
	}

	/* ---- Bulk editor ----------------------------------------------------- */

	public static function page_editor() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$types   = Sox_SEO_Meta::post_types();
		$type    = isset( $_GET['type'] ) && in_array( sanitize_key( $_GET['type'] ), $types, true ) ? sanitize_key( $_GET['type'] ) : ( in_array( 'product', $types, true ) ? 'product' : $types[0] );
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$missing = ! empty( $_GET['missing'] );
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable
		$args = array(
			'post_type'      => $type,
			'post_status'    => 'publish',
			'posts_per_page' => 40,
			'paged'          => $paged,
			'orderby'        => 'title',
			'order'          => 'ASC',
			's'              => $search,
		);
		if ( $missing ) {
			$args['meta_query'] = Sox_SEO_Meta::missing_description_query(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		$query     = new WP_Query( $args );
		$languages = Sox_SEO_Meta::is_native() ? Sox_SEO_Lang::languages() : array();
		$base      = admin_url( 'admin.php?page=sox-seo-editor&type=' . $type . ( $missing ? '&missing=1' : '' ) . ( $search ? '&s=' . rawurlencode( $search ) : '' ) );
		?>
		<div class="wrap sox-seo">
			<h1><?php esc_html_e( 'Bulk Editor', 'sox-seo' ); ?></h1>
			<?php if ( ! Sox_SEO_Meta::is_supported( 'post' ) ) : ?>
				<p><?php esc_html_e( 'Not available while All in One SEO is active.', 'sox-seo' ); ?></p></div>
				<?php
				return;
			endif;
			?>
			<form method="get" class="sox-seo-inline-form">
				<input type="hidden" name="page" value="sox-seo-editor" />
				<?php // Not "post_type": WordPress reserves that name on admin pages. ?>
				<select name="type">
					<?php foreach ( $types as $name ) : ?>
						<?php $object = get_post_type_object( $name ); ?>
						<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $type, $name ); ?>><?php echo esc_html( $object ? $object->labels->name : $name ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search…', 'sox-seo' ); ?>" />
				<label><input type="checkbox" name="missing" value="1" <?php checked( $missing ); ?> /> <?php esc_html_e( 'Only pages without a description', 'sox-seo' ); ?></label>
				<?php submit_button( __( 'Filter', 'sox-seo' ), 'secondary', '', false ); ?>
			</form>
			<p>
				<button type="button" class="button button-primary sox-seo-editor-save"><?php esc_html_e( 'Save changes', 'sox-seo' ); ?></button>
				<span class="sox-seo-status sox-seo-editor-status"></span>
				<span class="sox-seo-muted">
					<?php
					/* translators: %s: number of items */
					printf( esc_html__( '%s items. Title up to 60 characters, description 120 to 155.', 'sox-seo' ), esc_html( number_format_i18n( $query->found_posts ) ) );
					?>
				</span>
			</p>
			<table class="widefat striped sox-seo-editor">
				<thead><tr>
					<th><?php esc_html_e( 'Page', 'sox-seo' ); ?></th>
					<th><?php esc_html_e( 'SEO title', 'sox-seo' ); ?></th>
					<th><?php esc_html_e( 'Meta description', 'sox-seo' ); ?></th>
					<th><?php esc_html_e( 'Focus keyword', 'sox-seo' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $query->posts as $post ) : ?>
					<tr data-id="<?php echo esc_attr( (string) $post->ID ); ?>">
						<td><a href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( wp_strip_all_tags( get_the_title( $post ) ) ); ?></a></td>
						<td>
							<input type="text" data-field="title" value="<?php echo esc_attr( Sox_SEO_Meta::get_post( $post->ID, 'title' ) ); ?>" />
							<?php foreach ( $languages as $lang => $language ) : ?>
								<input type="text" dir="auto" data-lang="<?php echo esc_attr( $lang ); ?>" data-field="title" placeholder="<?php echo esc_attr( $language['label'] ); ?>" value="<?php echo esc_attr( Sox_SEO_Lang::get_post( $post->ID, 'title', $lang ) ); ?>" />
							<?php endforeach; ?>
						</td>
						<td>
							<textarea rows="2" data-field="description"><?php echo esc_textarea( Sox_SEO_Meta::get_post( $post->ID, 'description' ) ); ?></textarea>
							<?php foreach ( $languages as $lang => $language ) : ?>
								<textarea rows="2" dir="auto" data-lang="<?php echo esc_attr( $lang ); ?>" data-field="description" placeholder="<?php echo esc_attr( $language['label'] ); ?>"><?php echo esc_textarea( Sox_SEO_Lang::get_post( $post->ID, 'description', $lang ) ); ?></textarea>
							<?php endforeach; ?>
						</td>
						<td><input type="text" data-field="focus_keyword" value="<?php echo esc_attr( Sox_SEO_Meta::get_post( $post->ID, 'focus_keyword' ) ); ?>" /></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<button type="button" class="button button-primary sox-seo-editor-save"><?php esc_html_e( 'Save changes', 'sox-seo' ); ?></button>
				<?php
				echo wp_kses_post(
					(string) paginate_links(
						array(
							'base'    => $base . '%_%',
							'format'  => '&paged=%#%',
							'current' => $paged,
							'total'   => max( 1, (int) $query->max_num_pages ),
						)
					)
				);
				?>
			</p>
		</div>
		<?php
	}

	/* ---- Speed report ---------------------------------------------------- */

	public static function page_speed() {
		$results = Sox_SEO_Speed::results();
		$urls    = Sox_SEO_Speed::default_urls();
		foreach ( array_keys( $results ) as $url ) {
			if ( ! isset( $urls[ $url ] ) ) {
				$urls[ $url ] = '';
			}
		}
		?>
		<div class="wrap sox-seo">
			<h1><?php esc_html_e( 'Speed Report', 'sox-seo' ); ?></h1>
			<p><?php esc_html_e( 'Google tests each page on a simulated mid-range phone on a slow connection, which is how it judges speed for ranking. A score of 90 or more is good, 50 to 89 needs work, under 50 is poor. "Real visitors" shows what people on Chrome actually experienced, when the page has enough traffic.', 'sox-seo' ); ?></p>
			<p class="sox-seo-inline-form">
				<input type="url" class="regular-text" id="sox-seo-speed-url" placeholder="<?php echo esc_attr( home_url( '/…' ) ); ?>" />
				<button type="button" class="button" id="sox-seo-speed-custom"><?php esc_html_e( 'Test another page', 'sox-seo' ); ?></button>
			</p>
			<table class="widefat striped sox-seo-speed">
				<thead><tr>
					<th><?php esc_html_e( 'Page', 'sox-seo' ); ?></th><th><?php esc_html_e( 'Score', 'sox-seo' ); ?></th><th><?php esc_html_e( 'Main content appears (LCP)', 'sox-seo' ); ?></th><th><?php esc_html_e( 'Server response', 'sox-seo' ); ?></th><th><?php esc_html_e( 'Layout shift (CLS)', 'sox-seo' ); ?></th><th><?php esc_html_e( 'Blocked time', 'sox-seo' ); ?></th><th><?php esc_html_e( 'Real visitors', 'sox-seo' ); ?></th><th><?php esc_html_e( 'Biggest savings', 'sox-seo' ); ?></th><th></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $urls as $url => $label ) : ?>
					<?php $r = isset( $results[ $url ] ) ? $results[ $url ] : null; ?>
					<tr data-url="<?php echo esc_attr( $url ); ?>">
						<td><?php echo $label ? '<strong>' . esc_html( $label ) . '</strong><br />' : ''; ?><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ); ?></a></td>
						<td class="sox-seo-speed-score"><?php echo $r ? '<span class="sox-seo-score sox-seo-' . esc_attr( $r['score'] >= 90 ? 'good' : ( $r['score'] >= 50 ? 'ok' : 'bad' ) ) . '">' . esc_html( (string) $r['score'] ) . '</span>' : '—'; ?></td>
						<td class="sox-seo-speed-lcp"><?php echo esc_html( $r ? $r['lcp'] : '' ); ?></td>
						<td class="sox-seo-speed-ttfb"><?php echo esc_html( $r ? $r['ttfb'] : '' ); ?></td>
						<td class="sox-seo-speed-cls"><?php echo esc_html( $r ? $r['cls'] : '' ); ?></td>
						<td class="sox-seo-speed-tbt"><?php echo esc_html( $r ? $r['tbt'] : '' ); ?></td>
						<td class="sox-seo-speed-field">
							<?php
							if ( $r && $r['field'] ) {
								$parts = array();
								foreach ( $r['field'] as $metric => $rating ) {
									$parts[] = strtoupper( $metric ) . ': ' . $rating;
								}
								echo esc_html( implode( ', ', $parts ) );
							}
							?>
						</td>
						<td class="sox-seo-speed-fixes">
							<?php
							if ( $r ) {
								foreach ( $r['fixes'] as $fix ) {
									echo esc_html( $fix['title'] ) . ' <span class="sox-seo-muted">(' . esc_html( number_format_i18n( $fix['saving'] / 1000, 1 ) ) . ' s)</span><br />';
								}
							}
							?>
						</td>
						<td><button type="button" class="button sox-seo-speed-test"><?php esc_html_e( 'Test', 'sox-seo' ); ?></button> <span class="sox-seo-status"></span></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
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
			<p><?php esc_html_e( 'Copies SEO titles, descriptions, focus keywords, canonical URLs, noindex settings and social text into Sox SEO, for pages and categories, so nothing is lost if you switch that plugin off. The other plugin\'s data is not changed, and values already in Sox SEO are kept, so import your current plugin first and older ones after it.', 'sox-seo' ); ?></p>
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

			<?php if ( class_exists( 'WooCommerce' ) ) : ?>
			<h2><?php esc_html_e( 'Import product barcodes (GTIN)', 'sox-seo' ); ?></h2>
			<p><?php esc_html_e( 'Upload a CSV file with two columns, SKU then barcode number (GTIN, UPC or EAN), one product per line. Each barcode is checked before it is saved; products whose SKU is not in the file are left alone.', 'sox-seo' ); ?></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sox-seo-inline-form">
				<input type="hidden" name="action" value="sox_seo_gtin" />
				<?php wp_nonce_field( 'sox_seo_tools' ); ?>
				<input type="file" name="gtin_csv" accept=".csv,text/csv" required />
				<?php submit_button( __( 'Import barcodes', 'sox-seo' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Leftovers from other SEO plugins', 'sox-seo' ); ?></h2>
			<p><?php esc_html_e( 'Data that SEO plugins you no longer use left in the database. Import what you need first (above), and keep a plugin\'s data for as long as you might switch back to it. Deleting cannot be undone.', 'sox-seo' ); ?></p>
			<table class="widefat striped sox-seo-leftovers">
				<thead><tr><th><?php esc_html_e( 'Plugin', 'sox-seo' ); ?></th><th><?php esc_html_e( 'Tables', 'sox-seo' ); ?></th><th><?php esc_html_e( 'Page and category fields', 'sox-seo' ); ?></th><th><?php esc_html_e( 'Settings', 'sox-seo' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( Sox_SEO_Cleanup::scan() as $id => $row ) : ?>
					<?php
					if ( ! $row['tables'] && ! $row['meta'] && ! $row['options'] ) {
						continue;
					}
					?>
					<tr>
						<td><strong><?php echo esc_html( $row['label'] ); ?></strong></td>
						<td><?php echo esc_html( number_format_i18n( $row['tables'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['meta'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['options'] ) ); ?></td>
						<td>
							<?php if ( $row['active'] ) : ?>
								<span class="sox-seo-muted"><?php esc_html_e( 'Plugin is active: in use', 'sox-seo' ); ?></span>
							<?php else : ?>
								<a class="sox-seo-danger sox-seo-confirm" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sox_seo_cleanup&source=' . $id ), 'sox_seo_cleanup_' . $id ) ); ?>"><?php esc_html_e( 'Delete this data', 'sox-seo' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

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

	public static function handle_gtin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'sox-seo' ) );
		}
		check_admin_referer( 'sox_seo_tools' );
		if ( empty( $_FILES['gtin_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['gtin_csv']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			self::back_to_tools( __( 'No file was uploaded.', 'sox-seo' ) );
		}
		$result = Sox_SEO_Tools::import_gtins( $_FILES['gtin_csv']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( is_wp_error( $result ) ) {
			self::back_to_tools( $result->get_error_message() );
		}
		/* translators: 1: updated, 2: unchanged, 3: unknown SKUs, 4: invalid barcodes */
		$message = sprintf( __( 'Barcodes: %1$d saved, %2$d already set, %3$d SKUs not found, %4$d invalid or already used.', 'sox-seo' ), $result['updated'], $result['unchanged'], $result['unknown_sku'], $result['invalid'] );
		if ( $result['examples'] ) {
			$message .= ' ' . __( 'Rejected:', 'sox-seo' ) . ' ' . implode( ', ', $result['examples'] );
		}
		self::back_to_tools( $message );
	}

	public static function handle_cleanup() {
		$source = isset( $_GET['source'] ) ? sanitize_key( $_GET['source'] ) : '';
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'sox-seo' ) );
		}
		check_admin_referer( 'sox_seo_cleanup_' . $source );
		$removed = Sox_SEO_Cleanup::delete( $source );
		self::back_to_tools(
			is_wp_error( $removed )
				? $removed->get_error_message()
				/* translators: 1: tables, 2: fields, 3: settings */
				: sprintf( __( 'Deleted %1$d tables, %2$d fields and %3$d settings.', 'sox-seo' ), $removed['tables'], $removed['meta'], $removed['options'] )
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
