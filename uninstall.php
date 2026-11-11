<?php
/**
 * Uninstall: remove settings, the API key, the Search Console key, usage
 * counters, link audit results and the redirect tables. SEO titles and descriptions stored on posts are left in place so
 * the site's search listings do not change if the plugin is removed.
 *
 * @package Sox_SEO
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'sox_seo_options', 'sox_seo_api_key', 'sox_seo_usage', 'sox_seo_db_version', 'sox_seo_autopilot_count', 'sox_seo_gsc_key', 'sox_seo_link_audit', 'sox_seo_indexnow_key', 'sox_seo_gsc_history', 'sox_seo_speed' ) as $sox_seo_option ) {
	delete_option( $sox_seo_option );
}
delete_transient( 'sox_seo_autopilot_failed' );
delete_transient( 'sox_seo_gsc_token' );
wp_clear_scheduled_hook( 'sox_seo_autopilot' );
wp_clear_scheduled_hook( 'sox_seo_weekly_report' );
wp_clear_scheduled_hook( 'sox_seo_gsc_snapshot' );
wp_clear_scheduled_hook( 'sox_seo_link_audit_cron' );

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sox_seo_redirects" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sox_seo_404" ); // phpcs:ignore WordPress.DB
