<?php
/**
 * Uninstall script
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Delete table
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}simple_redirects");

// Delete uploaded files
$upload_dir = wp_upload_dir();
$files = glob($upload_dir['basedir'] . '/shopify-redirects-*.csv');
foreach ($files as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}
