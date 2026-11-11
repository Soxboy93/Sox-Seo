<?php
/**
 * Plugin Name:       Sox SEO
 * Plugin URI:        https://github.com/soxboy93/sox-seo
 * Description:       AI-powered SEO for WordPress and WooCommerce. Uses Claude (Anthropic API) to write titles, meta descriptions, image alt text and internal links, and ships the technical SEO layer: meta tags, Open Graph, schema, sitemap tuning, redirects and 404 monitoring. Works standalone or as an AI layer on top of Rank Math, Yoast SEO or SEOPress.
 * Version:           1.2.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Sox
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sox-seo
 *
 * @package Sox_SEO
 */

defined( 'ABSPATH' ) || exit;

define( 'SOX_SEO_VERSION', '1.2.0' );
define( 'SOX_SEO_FILE', __FILE__ );
define( 'SOX_SEO_DIR', plugin_dir_path( __FILE__ ) );
define( 'SOX_SEO_URL', plugin_dir_url( __FILE__ ) );

require_once SOX_SEO_DIR . 'includes/class-sox-seo-options.php';
require_once SOX_SEO_DIR . 'includes/class-sox-seo-meta.php';
require_once SOX_SEO_DIR . 'includes/class-sox-seo-anthropic.php';
require_once SOX_SEO_DIR . 'includes/class-sox-seo-ai.php';
require_once SOX_SEO_DIR . 'includes/class-sox-seo-analyzer.php';
require_once SOX_SEO_DIR . 'includes/class-sox-seo-frontend.php';
require_once SOX_SEO_DIR . 'includes/class-sox-seo-schema.php';
require_once SOX_SEO_DIR . 'includes/class-sox-seo-sitemap.php';
require_once SOX_SEO_DIR . 'includes/class-sox-seo-images.php';
require_once SOX_SEO_DIR . 'includes/class-sox-seo-links.php';
require_once SOX_SEO_DIR . 'includes/class-sox-seo-redirects.php';
require_once SOX_SEO_DIR . 'includes/class-sox-seo-woocommerce.php';
require_once SOX_SEO_DIR . 'includes/class-sox-seo-automation.php';
require_once SOX_SEO_DIR . 'includes/class-sox-seo-gsc.php';
require_once SOX_SEO_DIR . 'includes/class-sox-seo-link-audit.php';
require_once SOX_SEO_DIR . 'includes/class-sox-seo-extras.php';
require_once SOX_SEO_DIR . 'includes/class-sox-seo-tools.php';
require_once SOX_SEO_DIR . 'includes/class-sox-seo-lang.php';
require_once SOX_SEO_DIR . 'includes/class-sox-seo-speed.php';
require_once SOX_SEO_DIR . 'includes/class-sox-seo-cleanup.php';
require_once SOX_SEO_DIR . 'includes/class-sox-seo-rest.php';
require_once SOX_SEO_DIR . 'includes/class-sox-seo-admin.php';
require_once SOX_SEO_DIR . 'includes/class-sox-seo-admin-pages.php';

register_activation_hook( __FILE__, array( 'Sox_SEO_Redirects', 'install' ) );
register_activation_hook( __FILE__, array( 'Sox_SEO_Automation', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Sox_SEO_Automation', 'deactivate' ) );
register_deactivation_hook( __FILE__, 'sox_seo_clear_report_schedule' );

/**
 * Stop the weekly report when the plugin is switched off.
 */
function sox_seo_clear_report_schedule() {
	wp_clear_scheduled_hook( 'sox_seo_weekly_report' );
	wp_clear_scheduled_hook( 'sox_seo_gsc_snapshot' );
	wp_clear_scheduled_hook( 'sox_seo_link_audit_cron' );
}

/**
 * Boot the plugin once every other plugin is loaded, so that detection of
 * Rank Math / Yoast / SEOPress / WooCommerce is reliable.
 */
function sox_seo_boot() {
	// Always on: AI, automation, image and link helpers, redirects, admin.
	Sox_SEO_Images::init();
	Sox_SEO_Links::init();
	Sox_SEO_Redirects::init();
	Sox_SEO_Automation::init();
	Sox_SEO_Rest::init();
	Sox_SEO_WooCommerce::init();
	Sox_SEO_Extras::init();
	Sox_SEO_Tools::init();
	Sox_SEO_Lang::init();
	Sox_SEO_Link_Audit::init();

	// Only when Sox SEO is the site's SEO plugin. Printing a second set of
	// titles, canonicals and schema next to another SEO plugin hurts rankings.
	if ( Sox_SEO_Meta::is_native() ) {
		Sox_SEO_Frontend::init();
		Sox_SEO_Schema::init();
		Sox_SEO_Sitemap::init();
	}

	if ( is_admin() ) {
		Sox_SEO_Admin::init();
		Sox_SEO_Admin_Pages::init();
	}
}
add_action( 'plugins_loaded', 'sox_seo_boot', 20 );
