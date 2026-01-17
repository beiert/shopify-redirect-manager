<?php
/**
 * Plugin Name: Simple Shopify Redirects
 * Description: Einfaches Tool um URLs hochzuladen und Shopify-Redirects zu generieren
 * Version: 4.9.1
 * Author: Thilo Huellmann
 * Text Domain: simple-redirects
 */

if (!defined('ABSPATH')) exit;

define('SSR_VERSION', '4.9.1');
define('SSR_DIR', plugin_dir_path(__FILE__));
define('SSR_URL', plugin_dir_url(__FILE__));

// Load classes
require_once SSR_DIR . 'includes/class-db.php';
require_once SSR_DIR . 'includes/class-matcher.php';
require_once SSR_DIR . 'includes/class-sitemap.php';
require_once SSR_DIR . 'includes/class-shortcodes.php';
require_once SSR_DIR . 'admin/class-admin.php';

// Start session for visitor isolation
add_action('init', function() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
});

// Daily cleanup of old sessions (24h+)
add_action('ssr_daily_cleanup', 'ssr_cleanup_old_sessions');
function ssr_cleanup_old_sessions() {
    global $wpdb;
    $table = $wpdb->prefix . 'simple_redirects';
    $wpdb->query("DELETE FROM $table WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
}

// Schedule cleanup if not already scheduled
if (!wp_next_scheduled('ssr_daily_cleanup')) {
    wp_schedule_event(time(), 'daily', 'ssr_daily_cleanup');
}

// Activation
register_activation_hook(__FILE__, 'ssr_activate');
function ssr_activate() {
    SSR_DB::create_tables();
}

// Init admin
if (is_admin()) {
    new SSR_Admin();
}

// Init shortcodes
new SSR_Shortcodes();
