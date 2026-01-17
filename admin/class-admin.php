<?php

class SSR_Admin {
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_ssr_process', [$this, 'ajax_process']);
        add_action('wp_ajax_ssr_export', [$this, 'ajax_export']);
    }
    
    public function add_menu() {
        add_menu_page(
            'Shopify Redirects',
            'Redirects',
            'manage_options',
            'simple-redirects',
            [$this, 'render_page'],
            'dashicons-update'
        );
    }
    
    public function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_simple-redirects') return;
        
        wp_enqueue_style('ssr-admin', SSR_URL . 'admin/css/admin.css', [], SSR_VERSION);
        wp_enqueue_script('ssr-admin', SSR_URL . 'admin/js/admin.js', ['jquery'], SSR_VERSION, true);
        
        wp_localize_script('ssr-admin', 'ssrData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ssr_nonce')
        ]);
    }
    
    public function render_page() {
        $redirects = SSR_DB::get_all();
        $count = count($redirects);
        $matched = count(array_filter($redirects, function($r) { return !empty($r['new_url']); }));
        
        include SSR_DIR . 'admin/views/main.php';
    }
    
    public function ajax_process() {
        check_ajax_referer('ssr_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        $action_type = sanitize_text_field($_POST['action_type'] ?? '');
        
        if ($action_type === 'clear') {
            SSR_DB::clear_all();
            wp_send_json_success(['message' => 'Alle URLs gelöscht']);
        }
        
        if ($action_type === 'add_urls') {
            // Text input
            if (!empty($_POST['urls_text'])) {
                $urls = explode("\n", $_POST['urls_text']);
                $inserted = SSR_DB::insert_urls($urls);
                wp_send_json_success(['message' => "$inserted URLs hinzugefügt"]);
            }
            
            // File upload
            if (isset($_FILES['urls_file']) && $_FILES['urls_file']['error'] === UPLOAD_ERR_OK) {
                $content = file_get_contents($_FILES['urls_file']['tmp_name']);
                $urls = explode("\n", $content);
                $inserted = SSR_DB::insert_urls($urls);
                wp_send_json_success(['message' => "$inserted URLs aus Datei hinzugefügt"]);
            }
            
            wp_send_json_error('Keine URLs gefunden');
        }
        
        if ($action_type === 'match') {
            $sitemap_url = sanitize_text_field($_POST['sitemap_url'] ?? '');
            
            if (empty($sitemap_url)) {
                wp_send_json_error('Bitte Sitemap-URL angeben');
            }
            
            $matcher = new SSR_Matcher();
            
            // Load catalog
            $catalog_count = $matcher->load_catalog_from_sitemap($sitemap_url);
            
            if ($catalog_count === 0) {
                wp_send_json_error('Katalog konnte nicht geladen werden');
            }
            
            // Match
            $matched = $matcher->match_all();
            
            wp_send_json_success([
                'message' => "$matched URLs gematched (Katalog: $catalog_count Einträge)"
            ]);
        }
        
        wp_send_json_error('Ungültige Aktion');
    }
    
    public function ajax_export() {
        check_ajax_referer('ssr_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        $redirects = SSR_DB::get_matched();
        
        if (empty($redirects)) {
            wp_send_json_error('Keine Redirects zum Exportieren');
        }
        
        // Create CSV
        $upload_dir = wp_upload_dir();
        $filename = 'shopify-redirects-' . date('Y-m-d-His') . '.csv';
        $filepath = $upload_dir['basedir'] . '/' . $filename;
        
        $fp = fopen($filepath, 'w');
        
        // UTF-8 BOM
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Header
        fputcsv($fp, ['Redirect from', 'Redirect to']);
        
        // Data
        foreach ($redirects as $redirect) {
            $from = $this->url_to_path($redirect['old_url']);
            $to = $this->url_to_path($redirect['new_url']);
            fputcsv($fp, [$from, $to]);
        }
        
        fclose($fp);
        
        wp_send_json_success([
            'file_url' => $upload_dir['baseurl'] . '/' . $filename
        ]);
    }
    
    private function url_to_path($url) {
        $parsed = parse_url($url);
        $path = isset($parsed['path']) ? $parsed['path'] : '/';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        
        return $path . $query;
    }
}
