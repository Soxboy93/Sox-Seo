<?php
/**
 * Admin screens: settings, editor meta box, term fields, bulk optimizer,
 * redirects and 404 monitor.
 *
 * @package Sox_SEO
 */

defined( 'ABSPATH' ) || exit;

class Sox_SEO_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post', array( __CLASS__, 'save_post' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_post_sox_seo_redirect_add', array( __CLASS__, 'handle_redirect_add' ) );
		add_action( 'admin_post_sox_seo_redirect_delete', array( __CLASS__, 'handle_redirect_delete' ) );
		add_action( 'admin_post_sox_seo_404_clear', array( __CLASS__, 'handle_404_clear' ) );
		add_action( 'admin_post_sox_seo_alt', array( __CLASS__, 'handle_alt' ) );
		add_filter( 'media_row_actions', array( __CLASS__, 'media_row_action' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( SOX_SEO_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_init', array( __CLASS__, 'term_hooks' ) );
		add_action( 'admin_init', array( __CLASS__, 'column_hooks' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Menu, assets, notices                                                  */
	/* --------------------------------------------------------------------- */

	public static function menu() {
		add_menu_page( 'Sox SEO', 'Sox SEO', 'manage_options', 'sox-seo', array( __CLASS__, 'page_settings' ), 'dashicons-chart-line', 81 );
		add_submenu_page( 'sox-seo', __( 'Settings', 'sox-seo' ), __( 'Settings', 'sox-seo' ), 'manage_options', 'sox-seo', array( __CLASS__, 'page_settings' ) );
		add_submenu_page( 'sox-seo', __( 'Bulk AI Optimizer', 'sox-seo' ), __( 'Bulk AI Optimizer', 'sox-seo' ), 'manage_options', 'sox-seo-bulk', array( __CLASS__, 'page_bulk' ) );
		add_submenu_page( 'sox-seo', __( 'Redirects & 404s', 'sox-seo' ), __( 'Redirects & 404s', 'sox-seo' ), 'manage_options', 'sox-seo-redirects', array( __CLASS__, 'page_redirects' ) );
	}

	/**
	 * @param string[] $links Plugin row links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=sox-seo' ) ) . '">' . esc_html__( 'Settings', 'sox-seo' ) . '</a>' );
		return $links;
	}

	/**
	 * @param string $hook Admin page hook.
	 */
	public static function assets( $hook ) {
		$screen  = get_current_screen();
		$is_ours = false !== strpos( $hook, 'sox-seo' );
		$is_edit = $screen && in_array( $screen->base, array( 'post', 'term', 'edit' ), true );
		if ( ! $is_ours && ! $is_edit ) {
			return;
		}
		wp_enqueue_style( 'sox-seo-admin', SOX_SEO_URL . 'assets/admin.css', array(), SOX_SEO_VERSION );
		wp_enqueue_script( 'sox-seo-admin', SOX_SEO_URL . 'assets/admin.js', array( 'wp-api-fetch' ), SOX_SEO_VERSION, true );
		wp_localize_script(
			'sox-seo-admin',
			'soxSeo',
			array(
				'native'   => Sox_SEO_Meta::is_native(),
				'provider' => Sox_SEO_Meta::provider_label(),
				'i18n'     => array(
					'working'      => __( 'Asking Claude…', 'sox-seo' ),
					'generate'     => __( 'Generate with Claude', 'sox-seo' ),
					'saved'        => __( 'Saved. Reload this page before making other edits so the new values show.', 'sox-seo' ),
					'filled'       => __( 'Fields filled in. Review them, then save.', 'sox-seo' ),
					'noLinks'      => __( 'No good internal link opportunities found on this page.', 'sox-seo' ),
					'apply'        => __( 'Add selected links', 'sox-seo' ),
					/* translators: %d: number of links */
					'linksAdded'   => __( '%d link(s) added and saved as a revision. Reload the page to see them.', 'sox-seo' ),
					'start'        => __( 'Start', 'sox-seo' ),
					'stop'         => __( 'Stop', 'sox-seo' ),
					'done'         => __( 'All done. Nothing left to optimize.', 'sox-seo' ),
					'stopped'      => __( 'Stopped.', 'sox-seo' ),
					/* translators: %d: number of items */
					'remaining'    => __( '%d remaining', 'sox-seo' ),
					'connected'    => __( 'Connected. Claude replied successfully.', 'sox-seo' ),
					'chars'        => __( 'characters', 'sox-seo' ),
					'noFaq'        => __( 'No question-and-answer content found on this page, so no FAQ markup was added.', 'sox-seo' ),
					/* translators: %d: number of questions */
					'faqSaved'     => __( '%d question(s) found and marked up as FAQ schema.', 'sox-seo' ),
					'faqCleared'   => __( 'FAQ schema removed from this page.', 'sox-seo' ),
					'copySaved'    => __( 'Saved. Reload the page to see the new description.', 'sox-seo' ),
					'saveCopy'     => __( 'Save selected', 'sox-seo' ),
					'shortDesc'    => __( 'Short description', 'sox-seo' ),
					'fullDesc'     => __( 'Full description', 'sox-seo' ),
					/* translators: 1: scanned, 2: total */
					'scanning'     => __( 'Scanned %1$d of %2$d…', 'sox-seo' ),
					'scanDone'     => __( 'Scan finished. Reloading…', 'sox-seo' ),
					'priority'     => array(
						'high'   => __( 'High', 'sox-seo' ),
						'medium' => __( 'Medium', 'sox-seo' ),
						'low'    => __( 'Low', 'sox-seo' ),
					),
				),
			)
		);
	}

	public static function notices() {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'sox-seo' ) ) {
			if ( isset( $_GET['sox_seo_alt'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$message = sanitize_text_field( wp_unslash( $_GET['sox_seo_alt'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				echo '<div class="notice notice-info is-dismissible"><p><strong>Sox SEO:</strong> ' . esc_html( $message ) . '</p></div>';
			}
			return;
		}
		if ( '' === Sox_SEO_Options::api_key() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Add your Anthropic API key below to switch on the AI features.', 'sox-seo' ) . '</p></div>';
		}
		if ( ! Sox_SEO_Meta::is_native() ) {
			$provider = Sox_SEO_Meta::provider_label();
			if ( Sox_SEO_Meta::is_supported( 'post' ) ) {
				/* translators: %s: SEO plugin name */
				$text = sprintf( __( '%1$s is active, so Sox SEO runs as its AI layer: titles, descriptions and focus keywords written by Claude are saved into %1$s, which keeps printing the meta tags, schema and sitemap. Sox SEO prints none of its own, so nothing is duplicated.', 'sox-seo' ), $provider );
			} else {
				/* translators: %s: SEO plugin name */
				$text = sprintf( __( '%s is active. Sox SEO cannot write into its storage, so AI titles and descriptions are switched off. Image alt text, internal links and redirects still work.', 'sox-seo' ), $provider );
			}
			echo '<div class="notice notice-info"><p>' . esc_html( $text ) . '</p></div>';
		}
	}

	/* --------------------------------------------------------------------- */
	/* Settings                                                               */
	/* --------------------------------------------------------------------- */

	public static function register_settings() {
		register_setting(
			'sox_seo',
			Sox_SEO_Options::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Sox_SEO_Options', 'sanitize' ),
			)
		);
	}

	/**
	 * @param string $key   Option key.
	 * @param string $label Label.
	 * @param string $help  Help text.
	 */
	private static function checkbox( $key, $label, $help = '' ) {
		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
			esc_attr( Sox_SEO_Options::OPTION ),
			esc_attr( $key ),
			checked( Sox_SEO_Options::get( $key ), 1, false ),
			esc_html( $label )
		);
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
	}

	/**
	 * @param string $key  Option key.
	 * @param string $help Help text.
	 * @param string $type Input type.
	 */
	private static function text( $key, $help = '', $type = 'text' ) {
		printf(
			'<input type="%4$s" class="regular-text" name="%1$s[%2$s]" value="%3$s" />',
			esc_attr( Sox_SEO_Options::OPTION ),
			esc_attr( $key ),
			esc_attr( (string) Sox_SEO_Options::get( $key ) ),
			esc_attr( $type )
		);
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
	}

	/**
	 * @param string $key  Option key.
	 * @param string $help Help text.
	 */
	private static function textarea( $key, $help = '' ) {
		printf(
			'<textarea class="large-text" rows="3" name="%1$s[%2$s]">%3$s</textarea>',
			esc_attr( Sox_SEO_Options::OPTION ),
			esc_attr( $key ),
			esc_textarea( (string) Sox_SEO_Options::get( $key ) )
		);
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
	}

	public static function page_settings() {
		$native = Sox_SEO_Meta::is_native();
		$option = Sox_SEO_Options::OPTION;
		$key    = Sox_SEO_Options::api_key();
		$usage  = get_option( Sox_SEO_Anthropic::USAGE_OPTION, array() );
		?>
		<div class="wrap sox-seo">
			<h1>Sox SEO</h1>
			<?php settings_errors( 'sox_seo' ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'sox_seo' ); ?>

				<h2><?php esc_html_e( 'Claude (Anthropic API)', 'sox-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'API key', 'sox-seo' ); ?></th>
						<td>
							<?php if ( Sox_SEO_Options::api_key_is_constant() ) : ?>
								<p><code>••••<?php echo esc_html( substr( $key, -4 ) ); ?></code> <?php esc_html_e( 'Set in wp-config.php or the server environment.', 'sox-seo' ); ?></p>
							<?php else : ?>
								<input type="password" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $option ); ?>[api_key]" value="" placeholder="<?php echo $key ? esc_attr( '••••••••' . substr( $key, -4 ) ) : 'sk-ant-…'; ?>" />
								<p class="description">
									<?php esc_html_e( 'Create a key at console.anthropic.com. Leave blank to keep the saved key; type "delete" to remove it.', 'sox-seo' ); ?><br />
									<?php esc_html_e( 'Safer: keep it out of the database by adding this line to wp-config.php instead:', 'sox-seo' ); ?>
									<code>define( 'SOX_SEO_ANTHROPIC_API_KEY', 'sk-ant-…' );</code>
								</p>
							<?php endif; ?>
							<p>
								<button type="button" class="button" id="sox-seo-test"><?php esc_html_e( 'Test connection', 'sox-seo' ); ?></button>
								<span class="sox-seo-status" id="sox-seo-test-status"></span>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Model', 'sox-seo' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( $option ); ?>[model]">
								<?php foreach ( Sox_SEO_Options::models() as $id => $label ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( Sox_SEO_Options::get( 'model' ), $id ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Effort', 'sox-seo' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( $option ); ?>[effort]">
								<?php foreach ( array( 'low', 'medium', 'high' ) as $effort ) : ?>
									<option value="<?php echo esc_attr( $effort ); ?>" <?php selected( Sox_SEO_Options::get( 'effort' ), $effort ); ?>><?php echo esc_html( ucfirst( $effort ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'How hard Claude thinks before answering. Higher is slower and costs more.', 'sox-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Language', 'sox-seo' ); ?></th>
						<td><?php self::text( 'language', __( 'For example "Arabic" or "English". Leave blank to match each page\'s own language.', 'sox-seo' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Brand voice & rules', 'sox-seo' ); ?></th>
						<td><?php self::textarea( 'brand_voice', __( 'Optional. Tell Claude about your business, audience, tone, and anything it must always or never say.', 'sox-seo' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Automation', 'sox-seo' ); ?></th>
						<td>
							<fieldset>
								<?php self::checkbox( 'auto_meta_on_publish', __( 'Write the SEO title and description when a page, post or product is published without one', 'sox-seo' ) ); ?><br />
								<?php self::checkbox( 'auto_alt_on_upload', __( 'Write alt text for images as they are uploaded', 'sox-seo' ) ); ?><br />
								<?php self::checkbox( 'autopilot', __( 'Autopilot: every hour, work through existing pages and images that are still missing SEO data', 'sox-seo' ) ); ?><br />
								<label>
									<?php esc_html_e( 'Autopilot daily limit:', 'sox-seo' ); ?>
									<input type="number" min="1" max="500" class="small-text" name="<?php echo esc_attr( $option ); ?>[autopilot_daily_limit]" value="<?php echo esc_attr( (string) Sox_SEO_Options::get( 'autopilot_daily_limit' ) ); ?>" />
									<?php esc_html_e( 'items per day (caps API spend)', 'sox-seo' ); ?>
								</label><br />
								<?php self::checkbox( 'overwrite_existing', __( 'Let automation replace titles and descriptions that already exist', 'sox-seo' ), __( 'Off by default: text you wrote yourself is never touched.', 'sox-seo' ) ); ?>
							</fieldset>
						</td>
					</tr>
					<?php if ( is_array( $usage ) && $usage ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'API usage', 'sox-seo' ); ?></th>
						<td>
							<table class="widefat striped sox-seo-usage">
								<thead><tr><th><?php esc_html_e( 'Month', 'sox-seo' ); ?></th><th><?php esc_html_e( 'Requests', 'sox-seo' ); ?></th><th><?php esc_html_e( 'Input tokens', 'sox-seo' ); ?></th><th><?php esc_html_e( 'Output tokens', 'sox-seo' ); ?></th></tr></thead>
								<tbody>
								<?php foreach ( array_reverse( $usage, true ) as $month => $row ) : ?>
									<tr><td><?php echo esc_html( $month ); ?></td><td><?php echo esc_html( number_format_i18n( $row['requests'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $row['input'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $row['output'] ) ); ?></td></tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</td>
					</tr>
					<?php endif; ?>
				</table>

				<h2><?php esc_html_e( 'Google Search Console', 'sox-seo' ); ?></h2>
				<p><?php esc_html_e( 'Connect Search Console and Claude sees the real searches each page appears for, so titles, keywords and content audits are based on data.', 'sox-seo' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Service account key', 'sox-seo' ); ?></th>
						<td>
							<?php $gsc = Sox_SEO_GSC::credentials(); ?>
							<?php if ( $gsc ) : ?>
								<p><strong><?php esc_html_e( 'Connected as', 'sox-seo' ); ?></strong> <code><?php echo esc_html( $gsc['client_email'] ); ?></code></p>
							<?php endif; ?>
							<textarea class="large-text code" rows="3" name="<?php echo esc_attr( $option ); ?>[gsc_key]" placeholder="<?php echo $gsc ? esc_attr__( 'Paste a new JSON key to replace, or type delete to disconnect', 'sox-seo' ) : '{ "type": "service_account", … }'; ?>"></textarea>
							<p class="description">
								<?php esc_html_e( '1. In Google Cloud Console, create a project, enable the "Google Search Console API", create a service account and download its JSON key. 2. Paste the whole file here. 3. In Search Console → Settings → Users and permissions, add the service account\'s e-mail address as a user.', 'sox-seo' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Property', 'sox-seo' ); ?></th>
						<td>
							<?php
							/* translators: %s: site URL */
							self::text( 'gsc_property', sprintf( __( 'Leave blank for %s. For a domain property enter sc-domain:example.com', 'sox-seo' ), home_url( '/' ) ) );
							?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Crawlers & reports', 'sox-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'IndexNow', 'sox-seo' ); ?></th>
						<td><?php self::checkbox( 'indexnow', __( 'Tell Bing, Yandex and other IndexNow search engines the moment a page is published or changed', 'sox-seo' ), __( 'Google does not use IndexNow; it relies on the sitemap.', 'sox-seo' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'AI assistants', 'sox-seo' ); ?></th>
						<td>
							<?php
							/* translators: %s: llms.txt URL */
							self::checkbox( 'llms_txt', sprintf( __( 'Publish %s, a plain-text guide to the site for AI assistants', 'sox-seo' ), home_url( '/llms.txt' ) ) );
							?>
							<br />
							<?php self::checkbox( 'block_ai_training', __( 'Ask AI companies not to use this site to train their models (robots.txt)', 'sox-seo' ), __( 'Blocks training crawlers such as GPTBot, ClaudeBot and Google-Extended. Search engines are not affected.', 'sox-seo' ) ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Weekly report', 'sox-seo' ); ?></th>
						<td>
							<?php self::checkbox( 'weekly_report', __( 'E-mail a weekly SEO summary', 'sox-seo' ) ); ?><br />
							<?php
							/* translators: %s: admin e-mail */
							self::text( 'report_email', sprintf( __( 'Leave blank to send to %s.', 'sox-seo' ), get_option( 'admin_email' ) ), 'email' );
							?>
						</td>
					</tr>
				</table>

				<?php if ( $native ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $option ); ?>[_native]" value="1" />
				<h2><?php esc_html_e( 'Titles & meta', 'sox-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Separator', 'sox-seo' ); ?></th>
						<td><input type="text" class="small-text" name="<?php echo esc_attr( $option ); ?>[separator]" value="<?php echo esc_attr( (string) Sox_SEO_Options::get( 'separator' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Page / post / product title', 'sox-seo' ); ?></th>
						<td><?php self::text( 'title_single', __( 'Placeholders: %title% %sep% %sitename% %tagline%', 'sox-seo' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Category / tag title', 'sox-seo' ); ?></th>
						<td><?php self::text( 'title_term', __( 'Placeholders: %term% %sep% %sitename%', 'sox-seo' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Home page title', 'sox-seo' ); ?></th>
						<td><?php self::text( 'home_title', __( 'Leave blank to use the home page\'s own SEO title.', 'sox-seo' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Home page description', 'sox-seo' ); ?></th>
						<td><?php self::textarea( 'home_description' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default share image', 'sox-seo' ); ?></th>
						<td><?php self::text( 'default_og_image', __( 'Image URL used on Facebook, WhatsApp and X when a page has no featured image. 1200×630 works best.', 'sox-seo' ), 'url' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Indexing', 'sox-seo' ); ?></th>
						<td>
							<?php self::checkbox( 'noindex_search', __( 'Keep internal search result pages out of Google', 'sox-seo' ) ); ?><br />
							<?php self::checkbox( 'sitemap_no_users', __( 'Leave author archives out of the XML sitemap', 'sox-seo' ) ); ?>
							<p class="description">
								<?php
								/* translators: %s: sitemap URL */
								printf( esc_html__( 'Your sitemap: %s — submit it once in Google Search Console.', 'sox-seo' ), '<a href="' . esc_url( home_url( '/wp-sitemap.xml' ) ) . '" target="_blank" rel="noopener">' . esc_html( home_url( '/wp-sitemap.xml' ) ) . '</a>' );
								?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Schema (structured data)', 'sox-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Schema', 'sox-seo' ); ?></th>
						<td><?php self::checkbox( 'schema_enabled', __( 'Output Organization, WebSite, WebPage, Breadcrumb and Article schema', 'sox-seo' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Organization name', 'sox-seo' ); ?></th>
						<td><?php self::text( 'org_name', __( 'Defaults to the site title.', 'sox-seo' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Logo URL', 'sox-seo' ); ?></th>
						<td><?php self::text( 'org_logo', __( 'Defaults to the theme\'s site logo.', 'sox-seo' ), 'url' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Social profiles', 'sox-seo' ); ?></th>
						<td><?php self::textarea( 'org_social', __( 'One URL per line: Facebook, Instagram, LinkedIn, YouTube…', 'sox-seo' ) ); ?></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Local business', 'sox-seo' ); ?></h2>
				<p><?php esc_html_e( 'For a business with a shop, showroom or office customers can visit. Helps the site appear in Google Maps and "near me" searches. Use exactly the details shown on your Google Business Profile.', 'sox-seo' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Local business', 'sox-seo' ); ?></th>
						<td><?php self::checkbox( 'local_enabled', __( 'Add local business details to the schema', 'sox-seo' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Business type', 'sox-seo' ); ?></th>
						<td><?php self::text( 'local_type', __( 'A schema.org type such as Store, SportingGoodsStore, HomeGoodsStore, Restaurant or LocalBusiness.', 'sox-seo' ) ); ?></td>
					</tr>
					<tr><th scope="row"><?php esc_html_e( 'Street address', 'sox-seo' ); ?></th><td><?php self::text( 'local_street' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'City', 'sox-seo' ); ?></th><td><?php self::text( 'local_city' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Region / governorate', 'sox-seo' ); ?></th><td><?php self::text( 'local_region' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Postcode', 'sox-seo' ); ?></th><td><?php self::text( 'local_postcode' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Country code', 'sox-seo' ); ?></th><td><?php self::text( 'local_country', __( 'Two letters, for example JO.', 'sox-seo' ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Phone', 'sox-seo' ); ?></th><td><?php self::text( 'local_phone', __( 'With country code, for example +962 6 123 4567.', 'sox-seo' ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Latitude', 'sox-seo' ); ?></th><td><?php self::text( 'local_lat' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Longitude', 'sox-seo' ); ?></th><td><?php self::text( 'local_lng' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Price range', 'sox-seo' ); ?></th><td><?php self::text( 'local_price_range', __( 'For example $$ or JOD 10–500.', 'sox-seo' ) ); ?></td></tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Opening hours', 'sox-seo' ); ?></th>
						<td><?php self::textarea( 'local_hours', __( 'One line per rule, for example: Sa-Th 10:00-22:00', 'sox-seo' ) ); ?></td>
					</tr>
				</table>
				<?php endif; ?>

				<h2><?php esc_html_e( 'Images, links & WooCommerce', 'sox-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Images', 'sox-seo' ); ?></th>
						<td>
							<?php self::checkbox( 'alt_fallback', __( 'When an image has no alt text yet, use the page or product title', 'sox-seo' ) ); ?><br />
							<?php self::checkbox( 'rename_uploads', __( 'Rename camera-style uploads (IMG_4471.jpg) after the page they are added to', 'sox-seo' ) ); ?><br />
							<?php self::checkbox( 'webp', __( 'Convert new JPEG uploads to WebP (smaller files, faster pages)', 'sox-seo' ), __( 'Applies to images uploaded from now on. The original JPEG is kept on the server.', 'sox-seo' ) ); ?>
							<p>
								<label><?php esc_html_e( 'Compression quality for resized images:', 'sox-seo' ); ?>
									<input type="number" min="40" max="100" class="small-text" name="<?php echo esc_attr( $option ); ?>[image_quality]" value="<?php echo esc_attr( (string) Sox_SEO_Options::get( 'image_quality' ) ); ?>" />
								</label>
								<span class="description"><?php esc_html_e( '82 is WordPress\'s default; 75 is noticeably smaller with little visible loss.', 'sox-seo' ); ?></span>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'External links', 'sox-seo' ); ?></th>
						<td>
							<?php self::checkbox( 'external_new_tab', __( 'Open links to other websites in a new tab', 'sox-seo' ) ); ?><br />
							<?php self::checkbox( 'external_nofollow', __( 'Add rel="nofollow" to links to other websites', 'sox-seo' ) ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default product brand', 'sox-seo' ); ?></th>
						<td><?php self::text( 'default_brand', __( 'Added to product schema when a product has no brand of its own. Google asks for a brand on product results.', 'sox-seo' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Redirects', 'sox-seo' ); ?></th>
						<td>
							<?php self::checkbox( 'redirects_enabled', __( 'Enable the redirect manager', 'sox-seo' ) ); ?><br />
							<?php self::checkbox( 'log_404', __( 'Log "page not found" errors so broken URLs can be redirected', 'sox-seo' ) ); ?>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------- */
	/* Bulk optimizer                                                         */
	/* --------------------------------------------------------------------- */

	public static function page_bulk() {
		$meta_ok = Sox_SEO_Meta::is_supported( 'post' );
		?>
		<div class="wrap sox-seo">
			<h1><?php esc_html_e( 'Bulk AI Optimizer', 'sox-seo' ); ?></h1>
			<p><?php esc_html_e( 'Claude works through everything that is still missing SEO data, one item at a time. Keep this tab open while it runs; press Stop at any time. Existing text is never overwritten here.', 'sox-seo' ); ?></p>

			<div class="sox-seo-cards">
				<?php if ( $meta_ok ) : ?>
				<div class="sox-seo-card" data-task="meta">
					<h2><?php esc_html_e( 'Titles & meta descriptions', 'sox-seo' ); ?></h2>
					<p>
						<label><?php esc_html_e( 'Content type:', 'sox-seo' ); ?>
							<select class="sox-seo-bulk-type">
								<option value=""><?php esc_html_e( 'Everything', 'sox-seo' ); ?></option>
								<?php foreach ( Sox_SEO_Meta::post_types() as $type ) : ?>
									<?php $object = get_post_type_object( $type ); ?>
									<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $object ? $object->labels->name : $type ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</p>
					<p class="sox-seo-count">
						<?php
						/* translators: %s: number of items */
						printf( esc_html__( '%s published items have no meta description.', 'sox-seo' ), '<strong>' . esc_html( number_format_i18n( Sox_SEO_Automation::remaining( 'meta' ) ) ) . '</strong>' );
						?>
					</p>
					<p><button type="button" class="button button-primary sox-seo-bulk-start"><?php esc_html_e( 'Start', 'sox-seo' ); ?></button> <span class="sox-seo-status"></span></p>
					<ol class="sox-seo-log"></ol>
				</div>
				<?php endif; ?>

				<?php if ( class_exists( 'WooCommerce' ) ) : ?>
				<div class="sox-seo-card" data-task="product_copy">
					<h2><?php esc_html_e( 'Product short descriptions', 'sox-seo' ); ?></h2>
					<p><?php esc_html_e( 'Claude writes the short description for products that have none. Full descriptions are only written from the product editor, where you can preview them first.', 'sox-seo' ); ?></p>
					<p class="sox-seo-count">
						<?php
						/* translators: %s: number of products */
						printf( esc_html__( '%s products have no short description.', 'sox-seo' ), '<strong>' . esc_html( number_format_i18n( Sox_SEO_Automation::remaining( 'product_copy' ) ) ) . '</strong>' );
						?>
					</p>
					<p><button type="button" class="button button-primary sox-seo-bulk-start"><?php esc_html_e( 'Start', 'sox-seo' ); ?></button> <span class="sox-seo-status"></span></p>
					<ol class="sox-seo-log"></ol>
				</div>
				<?php endif; ?>

				<div class="sox-seo-card" data-task="alt">
					<h2><?php esc_html_e( 'Image alt text', 'sox-seo' ); ?></h2>
					<p><?php esc_html_e( 'Claude looks at each image and describes it for screen readers and Google Images.', 'sox-seo' ); ?></p>
					<p class="sox-seo-count">
						<?php
						/* translators: %s: number of images */
						printf( esc_html__( '%s images have no alt text.', 'sox-seo' ), '<strong>' . esc_html( number_format_i18n( Sox_SEO_Automation::remaining( 'alt' ) ) ) . '</strong>' );
						?>
					</p>
					<p><button type="button" class="button button-primary sox-seo-bulk-start"><?php esc_html_e( 'Start', 'sox-seo' ); ?></button> <span class="sox-seo-status"></span></p>
					<ol class="sox-seo-log"></ol>
				</div>
			</div>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------- */
	/* Redirects & 404s                                                       */
	/* --------------------------------------------------------------------- */

	public static function page_redirects() {
		$redirects = Sox_SEO_Redirects::all();
		$missing   = Sox_SEO_Redirects::top_404();
		$prefill   = isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message   = isset( $_GET['sox_seo_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['sox_seo_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap sox-seo">
			<h1><?php esc_html_e( 'Redirects & 404s', 'sox-seo' ); ?></h1>
			<?php if ( $message ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Add a redirect', 'sox-seo' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sox-seo-inline-form">
				<input type="hidden" name="action" value="sox_seo_redirect_add" />
				<?php wp_nonce_field( 'sox_seo_redirect' ); ?>
				<label><?php esc_html_e( 'Old URL', 'sox-seo' ); ?><br /><input type="text" name="source" class="regular-text" placeholder="/old-page" value="<?php echo esc_attr( $prefill ); ?>" required /></label>
				<label><?php esc_html_e( 'New URL', 'sox-seo' ); ?><br /><input type="text" name="target" class="regular-text" placeholder="/new-page" required /></label>
				<label><?php esc_html_e( 'Type', 'sox-seo' ); ?><br />
					<select name="code">
						<option value="301"><?php esc_html_e( '301 Permanent', 'sox-seo' ); ?></option>
						<option value="302"><?php esc_html_e( '302 Temporary', 'sox-seo' ); ?></option>
						<option value="307"><?php esc_html_e( '307 Temporary', 'sox-seo' ); ?></option>
					</select>
				</label>
				<?php submit_button( __( 'Add redirect', 'sox-seo' ), 'primary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Redirects', 'sox-seo' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Old URL', 'sox-seo' ); ?></th><th><?php esc_html_e( 'New URL', 'sox-seo' ); ?></th><th><?php esc_html_e( 'Type', 'sox-seo' ); ?></th><th><?php esc_html_e( 'Hits', 'sox-seo' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $redirects ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No redirects yet.', 'sox-seo' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $redirects as $row ) : ?>
					<tr>
						<td><code><?php echo esc_html( $row->source ); ?></code></td>
						<td><code><?php echo esc_html( $row->target ); ?></code></td>
						<td><?php echo esc_html( $row->code ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row->hits ) ); ?></td>
						<td><a class="sox-seo-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sox_seo_redirect_delete&id=' . (int) $row->id ), 'sox_seo_redirect' ) ); ?>"><?php esc_html_e( 'Delete', 'sox-seo' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( '404 monitor', 'sox-seo' ); ?></h2>
			<p><?php esc_html_e( 'URLs visitors and search engines asked for that do not exist. Redirect the ones that matter to the closest live page so their ranking value is not lost.', 'sox-seo' ); ?></p>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'URL', 'sox-seo' ); ?></th><th><?php esc_html_e( 'Hits', 'sox-seo' ); ?></th><th><?php esc_html_e( 'Last seen (UTC)', 'sox-seo' ); ?></th><th><?php esc_html_e( 'Came from', 'sox-seo' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $missing ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No 404 errors logged.', 'sox-seo' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $missing as $row ) : ?>
					<tr>
						<td><code><?php echo esc_html( $row->url ); ?></code></td>
						<td><?php echo esc_html( number_format_i18n( $row->hits ) ); ?></td>
						<td><?php echo esc_html( $row->last_hit ); ?></td>
						<td><?php echo esc_html( (string) $row->referrer ); ?></td>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=sox-seo-redirects&source=' . rawurlencode( $row->url ) ) ); ?>"><?php esc_html_e( 'Redirect', 'sox-seo' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $missing ) : ?>
				<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sox_seo_404_clear' ), 'sox_seo_redirect' ) ); ?>"><?php esc_html_e( 'Clear 404 log', 'sox-seo' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param string $message Notice text.
	 */
	private static function back_to_redirects( $message ) {
		wp_safe_redirect( add_query_arg( 'sox_seo_msg', rawurlencode( $message ), admin_url( 'admin.php?page=sox-seo-redirects' ) ) );
		exit;
	}

	public static function handle_redirect_add() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'sox-seo' ) );
		}
		check_admin_referer( 'sox_seo_redirect' );
		$result = Sox_SEO_Redirects::add(
			isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '',
			isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '',
			isset( $_POST['code'] ) ? absint( $_POST['code'] ) : 301
		);
		self::back_to_redirects( is_wp_error( $result ) ? $result->get_error_message() : __( 'Redirect saved.', 'sox-seo' ) );
	}

	public static function handle_redirect_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'sox-seo' ) );
		}
		check_admin_referer( 'sox_seo_redirect' );
		Sox_SEO_Redirects::delete( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		self::back_to_redirects( __( 'Redirect deleted.', 'sox-seo' ) );
	}

	public static function handle_404_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'sox-seo' ) );
		}
		check_admin_referer( 'sox_seo_redirect' );
		Sox_SEO_Redirects::clear_404();
		self::back_to_redirects( __( '404 log cleared.', 'sox-seo' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Media library                                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * @param string[] $actions Row actions.
	 * @param WP_Post  $post    Attachment.
	 * @return string[]
	 */
	public static function media_row_action( $actions, $post ) {
		if ( wp_attachment_is_image( $post ) && current_user_can( 'edit_post', $post->ID ) ) {
			$url                    = wp_nonce_url( admin_url( 'admin-post.php?action=sox_seo_alt&id=' . $post->ID ), 'sox_seo_alt_' . $post->ID );
			$actions['sox_seo_alt'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Write alt text with Claude', 'sox-seo' ) . '</a>';
		}
		return $actions;
	}

	public static function handle_alt() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'sox_seo_alt_' . $id );
		if ( ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'sox-seo' ) );
		}
		$alt     = Sox_SEO_Images::generate( $id, true );
		$message = is_wp_error( $alt )
			? $alt->get_error_message()
			/* translators: %s: alt text */
			: sprintf( __( 'Alt text saved: "%s"', 'sox-seo' ), $alt );
		wp_safe_redirect( add_query_arg( 'sox_seo_alt', rawurlencode( $message ), admin_url( 'upload.php?mode=list' ) ) );
		exit;
	}

	/* --------------------------------------------------------------------- */
	/* Editor meta box                                                        */
	/* --------------------------------------------------------------------- */

	public static function meta_boxes() {
		foreach ( Sox_SEO_Meta::post_types() as $type ) {
			add_meta_box( 'sox-seo', 'Sox SEO', array( __CLASS__, 'meta_box' ), $type, 'normal', 'high' );
		}
	}

	/**
	 * @param WP_Post $post Post being edited.
	 */
	public static function meta_box( $post ) {
		$native   = Sox_SEO_Meta::is_native();
		$has_key  = '' !== Sox_SEO_Options::api_key();
		$saved    = 'auto-draft' !== $post->post_status;
		$analysis = $saved ? Sox_SEO_Analyzer::analyze( $post->ID ) : null;
		wp_nonce_field( 'sox_seo_meta_box', 'sox_seo_nonce' );
		?>
		<div class="sox-seo-box" data-post="<?php echo esc_attr( (string) $post->ID ); ?>">
			<?php if ( ! $has_key ) : ?>
				<p class="sox-seo-muted">
					<?php
					/* translators: %s: settings link */
					printf( esc_html__( 'Add an Anthropic API key in %s to use the AI buttons.', 'sox-seo' ), '<a href="' . esc_url( admin_url( 'admin.php?page=sox-seo' ) ) . '">' . esc_html__( 'Sox SEO settings', 'sox-seo' ) . '</a>' );
					?>
				</p>
			<?php elseif ( ! $saved ) : ?>
				<p class="sox-seo-muted"><?php esc_html_e( 'Save a draft first, then Claude can read the content.', 'sox-seo' ); ?></p>
			<?php endif; ?>

			<?php if ( $native ) : ?>
				<div class="sox-seo-preview" aria-hidden="true">
					<div class="sox-seo-preview-url"><?php echo esc_html( (string) get_permalink( $post ) ); ?></div>
					<div class="sox-seo-preview-title" data-fallback="<?php echo esc_attr( get_the_title( $post ) ); ?>"></div>
					<div class="sox-seo-preview-desc"></div>
				</div>

				<p>
					<label for="sox-seo-field-focus_keyword"><strong><?php esc_html_e( 'Focus keyword', 'sox-seo' ); ?></strong></label>
					<input type="text" class="widefat" id="sox-seo-field-focus_keyword" name="sox_seo[focus_keyword]" value="<?php echo esc_attr( Sox_SEO_Meta::get_post( $post->ID, 'focus_keyword' ) ); ?>" />
				</p>
				<p>
					<label for="sox-seo-field-title"><strong><?php esc_html_e( 'SEO title', 'sox-seo' ); ?></strong></label>
					<span class="sox-seo-counter" data-for="sox-seo-field-title" data-max="60"></span>
					<input type="text" class="widefat" id="sox-seo-field-title" name="sox_seo[title]" value="<?php echo esc_attr( Sox_SEO_Meta::get_post( $post->ID, 'title' ) ); ?>" />
				</p>
				<p>
					<label for="sox-seo-field-description"><strong><?php esc_html_e( 'Meta description', 'sox-seo' ); ?></strong></label>
					<span class="sox-seo-counter" data-for="sox-seo-field-description" data-max="160"></span>
					<textarea class="widefat" rows="3" id="sox-seo-field-description" name="sox_seo[description]"><?php echo esc_textarea( Sox_SEO_Meta::get_post( $post->ID, 'description' ) ); ?></textarea>
				</p>
				<details class="sox-seo-advanced">
					<summary><?php esc_html_e( 'Advanced', 'sox-seo' ); ?></summary>
					<p>
						<label for="sox-seo-field-canonical"><?php esc_html_e( 'Canonical URL (leave blank unless this page copies another)', 'sox-seo' ); ?></label>
						<input type="url" class="widefat" id="sox-seo-field-canonical" name="sox_seo[canonical]" value="<?php echo esc_attr( Sox_SEO_Meta::get_post( $post->ID, 'canonical' ) ); ?>" />
					</p>
					<p>
						<label><input type="checkbox" name="sox_seo[noindex]" value="1" <?php checked( Sox_SEO_Meta::get_post( $post->ID, 'noindex' ), '1' ); ?> /> <?php esc_html_e( 'Hide this page from search engines (noindex)', 'sox-seo' ); ?></label>
					</p>
				</details>
				<details class="sox-seo-advanced">
					<summary><?php esc_html_e( 'Social sharing (Facebook, WhatsApp, X)', 'sox-seo' ); ?></summary>
					<p class="sox-seo-muted"><?php esc_html_e( 'Leave blank to share with the SEO title, description and featured image.', 'sox-seo' ); ?></p>
					<p>
						<label for="sox-seo-field-og_title"><?php esc_html_e( 'Social title', 'sox-seo' ); ?></label>
						<input type="text" class="widefat" id="sox-seo-field-og_title" name="sox_seo[og_title]" value="<?php echo esc_attr( Sox_SEO_Meta::get_post( $post->ID, 'og_title' ) ); ?>" />
					</p>
					<p>
						<label for="sox-seo-field-og_description"><?php esc_html_e( 'Social description', 'sox-seo' ); ?></label>
						<textarea class="widefat" rows="2" id="sox-seo-field-og_description" name="sox_seo[og_description]"><?php echo esc_textarea( Sox_SEO_Meta::get_post( $post->ID, 'og_description' ) ); ?></textarea>
					</p>
					<p>
						<label for="sox-seo-field-og_image"><?php esc_html_e( 'Social image URL (1200×630 works best)', 'sox-seo' ); ?></label>
						<input type="url" class="widefat" id="sox-seo-field-og_image" name="sox_seo[og_image]" value="<?php echo esc_attr( Sox_SEO_Meta::get_post( $post->ID, 'og_image' ) ); ?>" />
					</p>
				</details>
			<?php else : ?>
				<p class="sox-seo-muted">
					<?php
					/* translators: %s: SEO plugin name */
					printf( esc_html__( 'Titles and descriptions are managed by %s. Claude writes them straight into it.', 'sox-seo' ), esc_html( Sox_SEO_Meta::provider_label() ) );
					?>
				</p>
			<?php endif; ?>

			<?php if ( $has_key && $saved ) : ?>
				<p class="sox-seo-actions">
					<?php if ( Sox_SEO_Meta::is_supported( 'post' ) ) : ?>
						<button type="button" class="button button-primary sox-seo-generate"><?php esc_html_e( 'Generate with Claude', 'sox-seo' ); ?></button>
					<?php endif; ?>
					<button type="button" class="button sox-seo-links"><?php esc_html_e( 'Suggest internal links', 'sox-seo' ); ?></button>
					<button type="button" class="button sox-seo-audit"><?php esc_html_e( 'Audit content', 'sox-seo' ); ?></button>
					<?php if ( 'product' === $post->post_type ) : ?>
						<button type="button" class="button sox-seo-copy"><?php esc_html_e( 'Write product description', 'sox-seo' ); ?></button>
					<?php endif; ?>
					<button type="button" class="button sox-seo-faq"><?php esc_html_e( 'Detect FAQs', 'sox-seo' ); ?></button>
					<?php
					$faqs      = get_post_meta( $post->ID, '_sox_seo_faq', true );
					$faq_count = is_array( $faqs ) ? count( $faqs ) : 0;
					?>
					<button type="button" class="button-link sox-seo-danger sox-seo-faq-clear" <?php echo $faq_count > 0 ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove FAQ schema', 'sox-seo' ); ?></button>
					<span class="sox-seo-status" role="status"></span>
				</p>
				<div class="sox-seo-link-results"></div>
				<div class="sox-seo-copy-results"></div>
				<?php $audit = get_post_meta( $post->ID, '_sox_seo_audit', true ); ?>
				<div class="sox-seo-audit-results" data-audit="<?php echo esc_attr( is_array( $audit ) ? wp_json_encode( $audit ) : '' ); ?>"></div>
			<?php endif; ?>

			<?php if ( $analysis ) : ?>
				<div class="sox-seo-analysis">
					<p><strong><?php esc_html_e( 'On-page check', 'sox-seo' ); ?></strong> <?php echo self::score_badge( $analysis['score'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
					<ul>
						<?php foreach ( $analysis['checks'] as $check ) : ?>
							<li class="sox-seo-check sox-seo-<?php echo esc_attr( $check['status'] ); ?>"><?php echo esc_html( $check['label'] ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param int $score 0–100.
	 * @return string Escaped HTML.
	 */
	private static function score_badge( $score ) {
		$class = $score >= 80 ? 'good' : ( $score >= 50 ? 'ok' : 'bad' );
		return '<span class="sox-seo-score sox-seo-' . esc_attr( $class ) . '">' . esc_html( (string) $score ) . '/100</span>';
	}

	/**
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function save_post( $post_id, $post ) {
		if ( ! Sox_SEO_Meta::is_native() ) {
			return; // The active SEO plugin saves its own fields.
		}
		if ( ! isset( $_POST['sox_seo_nonce'], $_POST['sox_seo'] ) || ! wp_verify_nonce( sanitize_key( $_POST['sox_seo_nonce'] ), 'sox_seo_meta_box' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$input = (array) wp_unslash( $_POST['sox_seo'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		foreach ( array( 'focus_keyword', 'title' ) as $field ) {
			Sox_SEO_Meta::set_post( $post_id, $field, isset( $input[ $field ] ) ? sanitize_text_field( $input[ $field ] ) : '' );
		}
		Sox_SEO_Meta::set_post( $post_id, 'description', isset( $input['description'] ) ? sanitize_textarea_field( $input['description'] ) : '' );
		Sox_SEO_Meta::set_post( $post_id, 'canonical', isset( $input['canonical'] ) ? esc_url_raw( $input['canonical'] ) : '' );
		Sox_SEO_Meta::set_post( $post_id, 'noindex', empty( $input['noindex'] ) ? '' : '1' );
		Sox_SEO_Meta::set_post( $post_id, 'og_title', isset( $input['og_title'] ) ? sanitize_text_field( $input['og_title'] ) : '' );
		Sox_SEO_Meta::set_post( $post_id, 'og_description', isset( $input['og_description'] ) ? sanitize_textarea_field( $input['og_description'] ) : '' );
		Sox_SEO_Meta::set_post( $post_id, 'og_image', isset( $input['og_image'] ) ? esc_url_raw( $input['og_image'] ) : '' );
	}

	/* --------------------------------------------------------------------- */
	/* Taxonomy terms                                                         */
	/* --------------------------------------------------------------------- */

	public static function term_hooks() {
		if ( ! Sox_SEO_Meta::is_supported( 'term' ) ) {
			return;
		}
		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $taxonomy ) {
			add_action( $taxonomy . '_edit_form_fields', array( __CLASS__, 'term_fields' ), 20 );
			add_action( 'edited_' . $taxonomy, array( __CLASS__, 'save_term' ) );
		}
	}

	/**
	 * @param WP_Term $term Term being edited.
	 */
	public static function term_fields( $term ) {
		$native  = Sox_SEO_Meta::is_native();
		$has_key = '' !== Sox_SEO_Options::api_key();
		wp_nonce_field( 'sox_seo_term', 'sox_seo_term_nonce' );
		?>
		<tr class="form-field sox-seo-term" data-term="<?php echo esc_attr( (string) $term->term_id ); ?>">
			<th scope="row">Sox SEO</th>
			<td>
				<?php if ( $native ) : ?>
					<p><label for="sox-seo-field-title"><?php esc_html_e( 'SEO title', 'sox-seo' ); ?></label><br />
					<input type="text" id="sox-seo-field-title" name="sox_seo[title]" value="<?php echo esc_attr( Sox_SEO_Meta::get_term( $term->term_id, 'title' ) ); ?>" /></p>
					<p><label for="sox-seo-field-description"><?php esc_html_e( 'Meta description', 'sox-seo' ); ?></label><br />
					<textarea id="sox-seo-field-description" name="sox_seo[description]" rows="3"><?php echo esc_textarea( Sox_SEO_Meta::get_term( $term->term_id, 'description' ) ); ?></textarea></p>
					<p><label><input type="checkbox" name="sox_seo[noindex]" value="1" <?php checked( Sox_SEO_Meta::get_term( $term->term_id, 'noindex' ), '1' ); ?> /> <?php esc_html_e( 'Hide this archive from search engines (noindex)', 'sox-seo' ); ?></label></p>
					<input type="hidden" id="sox-seo-field-focus_keyword" name="sox_seo[focus_keyword]" value="<?php echo esc_attr( Sox_SEO_Meta::get_term( $term->term_id, 'focus_keyword' ) ); ?>" />
				<?php endif; ?>
				<?php if ( $has_key ) : ?>
					<p><button type="button" class="button sox-seo-generate-term"><?php esc_html_e( 'Generate with Claude', 'sox-seo' ); ?></button> <span class="sox-seo-status" role="status"></span></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * @param int $term_id Term ID.
	 */
	public static function save_term( $term_id ) {
		if ( ! Sox_SEO_Meta::is_native() ) {
			return;
		}
		if ( ! isset( $_POST['sox_seo_term_nonce'], $_POST['sox_seo'] ) || ! wp_verify_nonce( sanitize_key( $_POST['sox_seo_term_nonce'] ), 'sox_seo_term' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}
		$input = (array) wp_unslash( $_POST['sox_seo'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		foreach ( array( 'focus_keyword', 'title' ) as $field ) {
			Sox_SEO_Meta::set_term( $term_id, $field, isset( $input[ $field ] ) ? sanitize_text_field( $input[ $field ] ) : '' );
		}
		Sox_SEO_Meta::set_term( $term_id, 'description', isset( $input['description'] ) ? sanitize_textarea_field( $input['description'] ) : '' );
		Sox_SEO_Meta::set_term( $term_id, 'noindex', empty( $input['noindex'] ) ? '' : '1' );
	}

	/* --------------------------------------------------------------------- */
	/* List table column                                                      */
	/* --------------------------------------------------------------------- */

	public static function column_hooks() {
		foreach ( Sox_SEO_Meta::post_types() as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'add_column' ) );
			add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'render_column' ), 10, 2 );
		}
	}

	/**
	 * @param string[] $columns Columns.
	 * @return string[]
	 */
	public static function add_column( $columns ) {
		$columns['sox_seo'] = __( 'SEO', 'sox-seo' );
		return $columns;
	}

	/**
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function render_column( $column, $post_id ) {
		if ( 'sox_seo' !== $column ) {
			return;
		}
		$analysis = Sox_SEO_Analyzer::analyze( $post_id );
		echo self::score_badge( $analysis['score'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
