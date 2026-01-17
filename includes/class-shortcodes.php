<?php

class SSR_Shortcodes {
    
    public function __construct() {
        add_shortcode('shopify_redirects_stats', [$this, 'stats_shortcode']);
        add_shortcode('shopify_redirects_list', [$this, 'list_shortcode']);
        add_shortcode('shopify_redirects_count', [$this, 'count_shortcode']);
        add_shortcode('shopify_redirects_tool', [$this, 'tool_shortcode']);
        
        // Frontend CSS & JS
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        
        // AJAX handlers for frontend
        add_action('wp_ajax_ssr_frontend_add_urls', [$this, 'ajax_frontend_add_urls']);
        add_action('wp_ajax_nopriv_ssr_frontend_add_urls', [$this, 'ajax_frontend_add_urls']);
        
        add_action('wp_ajax_ssr_frontend_match', [$this, 'ajax_frontend_match']);
        add_action('wp_ajax_nopriv_ssr_frontend_match', [$this, 'ajax_frontend_match']);
        
        add_action('wp_ajax_ssr_frontend_export', [$this, 'ajax_frontend_export']);
        add_action('wp_ajax_nopriv_ssr_frontend_export', [$this, 'ajax_frontend_export']);
        
        add_action('wp_ajax_ssr_frontend_clear', [$this, 'ajax_frontend_clear']);
        add_action('wp_ajax_nopriv_ssr_frontend_clear', [$this, 'ajax_frontend_clear']);
        
        add_action('wp_ajax_ssr_frontend_get_stats', [$this, 'ajax_frontend_get_stats']);
        add_action('wp_ajax_nopriv_ssr_frontend_get_stats', [$this, 'ajax_frontend_get_stats']);
    }
    
    public function enqueue_frontend_assets() {
        global $post;
        
        if (!is_a($post, 'WP_Post')) {
            return;
        }
        
        $content = $post->post_content;
        
        if (has_shortcode($content, 'shopify_redirects_stats') ||
            has_shortcode($content, 'shopify_redirects_list') ||
            has_shortcode($content, 'shopify_redirects_count') ||
            has_shortcode($content, 'shopify_redirects_tool')) {
            
            wp_enqueue_style('ssr-frontend', SSR_URL . 'admin/css/frontend.css', [], SSR_VERSION);
            
            // Tool shortcode needs JS
            if (has_shortcode($content, 'shopify_redirects_tool')) {
                wp_enqueue_script('ssr-frontend', SSR_URL . 'admin/js/frontend.js', ['jquery'], SSR_VERSION, true);
                
                wp_localize_script('ssr-frontend', 'ssrFrontend', [
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('ssr_frontend_nonce')
                ]);
            }
        }
    }
    
    /**
     * Full Frontend Tool Shortcode
     * Usage: [shopify_redirects_tool]
     */
    public function tool_shortcode($atts) {
        $atts = shortcode_atts([
            'allow_upload' => 'yes',
            'allow_clear' => 'yes',
            'show_preview' => 'yes',
            'preview_limit' => 1000
        ], $atts);
        
        ob_start();
        include SSR_DIR . 'admin/views/frontend-tool.php';
        return ob_get_clean();
    }
    
    /**
     * AJAX: Frontend add URLs
     */
    public function ajax_frontend_add_urls() {
        check_ajax_referer('ssr_frontend_nonce', 'nonce');
        
        $action_type = sanitize_text_field($_POST['action_type'] ?? '');
        
        if ($action_type === 'text') {
            if (!empty($_POST['urls_text'])) {
                $urls = explode("\n", $_POST['urls_text']);
                $inserted = SSR_DB::insert_urls($urls);
                wp_send_json_success(['message' => "✨ $inserted URLs hinzugefügt", 'inserted' => $inserted]);
            }
        }
        
        if ($action_type === 'file') {
            if (isset($_FILES['urls_file']) && $_FILES['urls_file']['error'] === UPLOAD_ERR_OK) {
                $content = file_get_contents($_FILES['urls_file']['tmp_name']);
                $urls = explode("\n", $content);
                $inserted = SSR_DB::insert_urls($urls);
                wp_send_json_success(['message' => "✨ $inserted URLs aus Datei hinzugefügt", 'inserted' => $inserted]);
            }
        }
        
        wp_send_json_error(['message' => 'Keine URLs gefunden']);
    }
    
    /**
     * AJAX: Frontend matching
     */
    public function ajax_frontend_match() {
        check_ajax_referer('ssr_frontend_nonce', 'nonce');

        $session_id = SSR_DB::get_session_id();
        error_log("SSR MATCH START: Session=$session_id");

        // NEW: Accept multiple sitemap URLs
        $sitemap_urls = isset($_POST['sitemap_urls']) ? $_POST['sitemap_urls'] : [];

        // Fallback: single sitemap_url for backwards compatibility
        if (empty($sitemap_urls) && isset($_POST['sitemap_url'])) {
            $sitemap_urls = [sanitize_text_field($_POST['sitemap_url'])];
        }

        if (empty($sitemap_urls)) {
            wp_send_json_error(['message' => 'Bitte mindestens eine Sitemap-URL angeben']);
        }

        // Sanitize all URLs
        $sitemap_urls = array_map('sanitize_text_field', $sitemap_urls);

        // Validate all URLs
        foreach ($sitemap_urls as $url) {
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                wp_send_json_error(['message' => 'Ungültige URL: ' . esc_html($url)]);
            }
        }

        // Check how many URLs we have BEFORE matching
        $urls_before = SSR_DB::get_all();
        error_log("SSR MATCH: URLs in DB before matching: " . count($urls_before));

        $matcher = new SSR_Matcher();

        // Load catalogs from ALL sitemaps (combined catalog!)
        $total_catalog_count = 0;
        foreach ($sitemap_urls as $sitemap_url) {
            $count = $matcher->load_catalog_from_sitemap($sitemap_url);
            $total_catalog_count += $count;
        }

        if ($total_catalog_count === 0) {
            wp_send_json_error([
                'message' => 'Katalog konnte nicht geladen werden. Mögliche Ursachen:<br>' .
                            '• Sitemap-URL ist nicht erreichbar<br>' .
                            '• SSL-Zertifikat-Problem<br>' .
                            '• Firewall blockiert Zugriff<br>' .
                            '• Sitemap hat falsches Format<br>' .
                            '• Rate Limiting (429 Error)<br>' .
                            '<br>Prüfe die URLs: ' . esc_html(implode(', ', $sitemap_urls)) . '<br>' .
                            'Siehe PHP Error-Log für Details.'
            ]);
        }

        $matched = $matcher->match_all();

        // Check how many URLs we have AFTER matching
        $urls_after_matched = SSR_DB::get_matched();
        error_log("SSR MATCH END: Session=$session_id, Matched returned=$matched, Actually in DB=" . count($urls_after_matched));

        // Get locale statistics
        $locale_stats = $matcher->get_locale_stats();

        // Build locale message
        $locale_msg = '';
        if ($locale_stats['locale_count'] > 0) {
            $locales_list = implode(', ', $locale_stats['available_locales']);
            $locale_msg = "<br>🌍 <strong>{$locale_stats['locale_count']} Sprachen erkannt:</strong> {$locales_list}";
        }

        // Build sitemap info
        $sitemap_info = count($sitemap_urls) === 1
            ? ""
            : "<br>📂 <strong>" . count($sitemap_urls) . " Sitemaps</strong> kombiniert";

        wp_send_json_success([
            'message' => "$matched URLs gematched (Katalog: $total_catalog_count Einträge){$sitemap_info}{$locale_msg}",
            'matched' => $matched,
            'catalog_count' => $total_catalog_count,
            'sitemap_count' => count($sitemap_urls),
            'locale_stats' => $locale_stats,
            'debug_session' => $session_id,
            'debug_db_matched' => count($urls_after_matched)
        ]);
    }
    
    /**
     * AJAX: Frontend export
     */
    public function ajax_frontend_export() {
        check_ajax_referer('ssr_frontend_nonce', 'nonce');
        
        $redirects = SSR_DB::get_matched();
        
        if (empty($redirects)) {
            wp_send_json_error(['message' => 'Keine Redirects zum Exportieren']);
        }
        
        $upload_dir = wp_upload_dir();
        $filename = 'shopify-redirects-' . date('Y-m-d-His') . '.csv';
        $filepath = $upload_dir['basedir'] . '/' . $filename;
        
        $fp = fopen($filepath, 'w');
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($fp, ['Redirect from', 'Redirect to']);
        
        foreach ($redirects as $redirect) {
            // FROM: Always relative path (Shopify format)
            $from = $this->url_to_path($redirect['old_url']);
            
            // TO: ALWAYS FULL URL (Option A)
            // This works for both same-domain and cross-domain redirects
            $to = $redirect['new_url'];
            
            // Ensure full URL format
            if (!parse_url($to, PHP_URL_SCHEME)) {
                // If no scheme, it's relative - should not happen, but fallback
                $to = $this->url_to_path($to);
            }
            
            fputcsv($fp, [$from, $to]);
        }
        
        fclose($fp);
        
        wp_send_json_success([
            'file_url' => $upload_dir['baseurl'] . '/' . $filename,
            'count' => count($redirects)
        ]);
    }
    
    /**
     * AJAX: Frontend clear
     */
    public function ajax_frontend_clear() {
        check_ajax_referer('ssr_frontend_nonce', 'nonce');
        
        SSR_DB::clear_all();
        
        wp_send_json_success(['message' => 'Alle URLs gelöscht']);
    }
    
    /**
     * AJAX: Frontend get stats
     */
    public function ajax_frontend_get_stats() {
        check_ajax_referer('ssr_frontend_nonce', 'nonce');

        $session_id = SSR_DB::get_session_id();
        $all = SSR_DB::get_all();
        $matched = SSR_DB::get_matched();

        $total = count($all);
        $matched_count = count($matched);

        // DEBUG: Log to PHP error log
        error_log("SSR GET_STATS: Session=$session_id, Total=$total, Matched=$matched_count");

        $excellent = count(array_filter($matched, fn($r) => $r['score'] >= 90));
        $good = count(array_filter($matched, fn($r) => $r['score'] >= 70 && $r['score'] < 90));
        $fair = count(array_filter($matched, fn($r) => $r['score'] >= 50 && $r['score'] < 70));
        $fallback = count(array_filter($matched, fn($r) => $r['score'] < 50));

        $preview_limit = intval($_POST['preview_limit'] ?? 1000);
        $preview = array_slice($matched, 0, $preview_limit);

        wp_send_json_success([
            'total' => $total,
            'matched' => $matched_count,
            'pending' => $total - $matched_count,
            'excellent' => $excellent,
            'good' => $good,
            'fair' => $fair,
            'fallback' => $fallback,
            'preview' => $preview,
            'debug_session' => $session_id
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
    
    /**
     * Statistics Shortcode
     * Usage: [shopify_redirects_stats]
     */
    public function stats_shortcode($atts) {
        $atts = shortcode_atts([
            'style' => 'cards' // cards, compact, simple
        ], $atts);
        
        $all = SSR_DB::get_all();
        $matched = SSR_DB::get_matched();
        
        $total = count($all);
        $matched_count = count($matched);
        $pending = $total - $matched_count;
        
        // Score-Verteilung
        $excellent = 0; // 90-100
        $good = 0;      // 70-89
        $fair = 0;      // 50-69
        $fallback = 0;  // <50
        
        foreach ($matched as $r) {
            $score = $r['score'];
            if ($score >= 90) $excellent++;
            elseif ($score >= 70) $good++;
            elseif ($score >= 50) $fair++;
            else $fallback++;
        }
        
        ob_start();
        
        if ($atts['style'] === 'cards') {
            $this->render_stats_cards($total, $matched_count, $pending, $excellent, $good, $fair, $fallback);
        } elseif ($atts['style'] === 'compact') {
            $this->render_stats_compact($total, $matched_count, $pending);
        } else {
            $this->render_stats_simple($total, $matched_count);
        }
        
        return ob_get_clean();
    }
    
    /**
     * List Shortcode
     * Usage: [shopify_redirects_list limit="50" min_score="60"]
     */
    public function list_shortcode($atts) {
        $atts = shortcode_atts([
            'limit' => 50,
            'min_score' => 0,
            'show_score' => 'yes',
            'show_type' => 'yes'
        ], $atts);
        
        $redirects = SSR_DB::get_matched();
        
        // Filter by min_score
        if ($atts['min_score'] > 0) {
            $redirects = array_filter($redirects, function($r) use ($atts) {
                return $r['score'] >= $atts['min_score'];
            });
        }
        
        // Limit
        $redirects = array_slice($redirects, 0, intval($atts['limit']));
        
        if (empty($redirects)) {
            return '<p class="ssr-no-data">Noch keine Redirects vorhanden.</p>';
        }
        
        ob_start();
        ?>
        <div class="ssr-redirect-list">
            <table class="ssr-table">
                <thead>
                    <tr>
                        <th class="ssr-col-from">Von (Alt)</th>
                        <th class="ssr-col-to">Nach (Neu)</th>
                        <?php if ($atts['show_score'] === 'yes'): ?>
                        <th class="ssr-col-score">Score</th>
                        <?php endif; ?>
                        <?php if ($atts['show_type'] === 'yes'): ?>
                        <th class="ssr-col-type">Typ</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($redirects as $r): ?>
                    <tr>
                        <td class="ssr-url-cell">
                            <code><?php echo esc_html($r['old_url']); ?></code>
                        </td>
                        <td class="ssr-url-cell">
                            <code><?php echo esc_html($r['new_url']); ?></code>
                        </td>
                        <?php if ($atts['show_score'] === 'yes'): ?>
                        <td class="ssr-score-cell">
                            <?php echo $this->render_score_badge($r['score']); ?>
                        </td>
                        <?php endif; ?>
                        <?php if ($atts['show_type'] === 'yes'): ?>
                        <td class="ssr-type-cell">
                            <?php echo $this->get_type_from_url($r['old_url']); ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Count Shortcode
     * Usage: [shopify_redirects_count type="total"]
     * Types: total, matched, pending, excellent, good, fair, fallback
     */
    public function count_shortcode($atts) {
        $atts = shortcode_atts([
            'type' => 'total',
            'label' => ''
        ], $atts);
        
        $all = SSR_DB::get_all();
        $matched = SSR_DB::get_matched();
        
        $count = 0;
        
        switch ($atts['type']) {
            case 'total':
                $count = count($all);
                break;
            case 'matched':
                $count = count($matched);
                break;
            case 'pending':
                $count = count($all) - count($matched);
                break;
            case 'excellent':
                $count = count(array_filter($matched, fn($r) => $r['score'] >= 90));
                break;
            case 'good':
                $count = count(array_filter($matched, fn($r) => $r['score'] >= 70 && $r['score'] < 90));
                break;
            case 'fair':
                $count = count(array_filter($matched, fn($r) => $r['score'] >= 50 && $r['score'] < 70));
                break;
            case 'fallback':
                $count = count(array_filter($matched, fn($r) => $r['score'] < 50));
                break;
        }
        
        $output = '<span class="ssr-count" data-type="' . esc_attr($atts['type']) . '">';
        
        if (!empty($atts['label'])) {
            $output .= '<span class="ssr-count-label">' . esc_html($atts['label']) . '</span> ';
        }
        
        $output .= '<span class="ssr-count-number">' . number_format($count) . '</span>';
        $output .= '</span>';
        
        return $output;
    }
    
    // Helper methods
    
    private function render_stats_cards($total, $matched, $pending, $excellent, $good, $fair, $fallback) {
        ?>
        <div class="ssr-stats-cards">
            <div class="ssr-stat-card ssr-card-total">
                <div class="ssr-stat-icon">📊</div>
                <div class="ssr-stat-content">
                    <div class="ssr-stat-number"><?php echo number_format($total); ?></div>
                    <div class="ssr-stat-label">URLs Total</div>
                </div>
            </div>
            
            <div class="ssr-stat-card ssr-card-matched">
                <div class="ssr-stat-icon">✅</div>
                <div class="ssr-stat-content">
                    <div class="ssr-stat-number"><?php echo number_format($matched); ?></div>
                    <div class="ssr-stat-label">Gematched</div>
                    <div class="ssr-stat-percent"><?php echo $total > 0 ? round(($matched / $total) * 100) : 0; ?>%</div>
                </div>
            </div>
            
            <div class="ssr-stat-card ssr-card-excellent">
                <div class="ssr-stat-icon">🟢</div>
                <div class="ssr-stat-content">
                    <div class="ssr-stat-number"><?php echo number_format($excellent); ?></div>
                    <div class="ssr-stat-label">Perfekt (90+)</div>
                </div>
            </div>
            
            <div class="ssr-stat-card ssr-card-good">
                <div class="ssr-stat-icon">🟢</div>
                <div class="ssr-stat-content">
                    <div class="ssr-stat-number"><?php echo number_format($good); ?></div>
                    <div class="ssr-stat-label">Gut (70-89)</div>
                </div>
            </div>
            
            <div class="ssr-stat-card ssr-card-fair">
                <div class="ssr-stat-icon">🟡</div>
                <div class="ssr-stat-content">
                    <div class="ssr-stat-number"><?php echo number_format($fair); ?></div>
                    <div class="ssr-stat-label">OK (50-69)</div>
                </div>
            </div>
            
            <div class="ssr-stat-card ssr-card-fallback">
                <div class="ssr-stat-icon">🟡</div>
                <div class="ssr-stat-content">
                    <div class="ssr-stat-number"><?php echo number_format($fallback); ?></div>
                    <div class="ssr-stat-label">Fallback (<50)</div>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function render_stats_compact($total, $matched, $pending) {
        ?>
        <div class="ssr-stats-compact">
            <div class="ssr-stat-row">
                <span class="ssr-stat-label">Total URLs:</span>
                <span class="ssr-stat-value"><?php echo number_format($total); ?></span>
            </div>
            <div class="ssr-stat-row">
                <span class="ssr-stat-label">Gematched:</span>
                <span class="ssr-stat-value"><?php echo number_format($matched); ?></span>
            </div>
            <div class="ssr-stat-row">
                <span class="ssr-stat-label">Erfolgsrate:</span>
                <span class="ssr-stat-value"><?php echo $total > 0 ? round(($matched / $total) * 100) : 0; ?>%</span>
            </div>
        </div>
        <?php
    }
    
    private function render_stats_simple($total, $matched) {
        ?>
        <div class="ssr-stats-simple">
            <strong><?php echo number_format($matched); ?></strong> von <strong><?php echo number_format($total); ?></strong> URLs gematched
            (<?php echo $total > 0 ? round(($matched / $total) * 100) : 0; ?>%)
        </div>
        <?php
    }
    
    private function render_score_badge($score) {
        $class = 'ssr-score-badge';
        if ($score >= 90) {
            $class .= ' ssr-score-excellent';
            $label = 'Perfekt';
        } elseif ($score >= 70) {
            $class .= ' ssr-score-good';
            $label = 'Gut';
        } elseif ($score >= 50) {
            $class .= ' ssr-score-fair';
            $label = 'OK';
        } else {
            $class .= ' ssr-score-fallback';
            $label = 'Fallback';
        }
        
        return '<span class="' . $class . '" title="' . $label . '">' . $score . '</span>';
    }
    
    private function get_type_from_url($url) {
        if (strpos($url, '/products/') !== false) return 'Product';
        if (strpos($url, '/collections/') !== false) return 'Collection';
        if (strpos($url, '/pages/') !== false) return 'Page';
        if (strpos($url, '/blogs/') !== false) return 'Blog';
        return 'Other';
    }
}
