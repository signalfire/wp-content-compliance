<?php
/**
 * Uninstall script for Signalfire Content Compliance
 *
 * This file is executed when the plugin is uninstalled via the WordPress admin.
 * It removes all plugin data including database tables, options, and scheduled events.
 *
 * Note: Direct database queries are intentionally used here for complete plugin cleanup.
 * This is the standard and recommended approach for uninstall.php files as per
 * WordPress plugin development guidelines.
 *
 * @package SignalfireContentCompliance
 * @since 1.0.0
 */

// If uninstall not called from WordPress, then exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove plugin options
delete_option('scc_settings');

// Remove any transients or cache data
delete_transient('scc_compliance_check');

// Clear scheduled events
wp_clear_scheduled_hook('scc_compliance_check');

// Remove custom database tables
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
global $wpdb;

$tables_to_drop = array(
    $wpdb->prefix . 'scc_compliance',
    $wpdb->prefix . 'scc_reviews',
    $wpdb->prefix . 'scc_bulk_operations'
);

foreach ($tables_to_drop as $table) {
    // Escape table name and ensure it starts with our expected prefix for security
    if (strpos($table, $wpdb->prefix . 'scc_') === 0) {
        $wpdb->query("DROP TABLE IF EXISTS `" . esc_sql($table) . "`");
    }
}

// Remove any custom meta data - use efficient bulk delete
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$meta_keys = array(
    '_scc_compliance_status',
    '_scc_last_review', 
    '_scc_next_review',
    '_scc_review_token',
    '_scc_maintainer_email'
);

$placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name must be interpolated, placeholders are properly prepared
$query = "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders)";
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Required for complete plugin cleanup during uninstall, query is properly prepared with placeholders
$wpdb->query($wpdb->prepare($query, ...$meta_keys));

// Remove any user meta data related to the plugin
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s",
    'scc_last_login'
));
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

// Clear any remaining cache
wp_cache_flush();