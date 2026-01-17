<?php

class SSR_DB {
    
    public static function create_tables() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'simple_redirects';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(64) NOT NULL,
            old_url TEXT NOT NULL,
            new_url TEXT,
            score INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY session_id (session_id),
            KEY created_at (created_at)
        ) $charset;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Auto-Cleanup: Lösche Daten älter als 24 Stunden
        $wpdb->query("DELETE FROM $table WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    }
    
    /**
     * Get or create session ID for this visitor
     */
    public static function get_session_id() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['ssr_session_id'])) {
            // Neue eindeutige Session-ID für diesen Besucher
            $_SESSION['ssr_session_id'] = 'ssr_' . bin2hex(random_bytes(16));
        }
        
        return $_SESSION['ssr_session_id'];
    }
    
    public static function insert_urls($urls) {
        global $wpdb;
        $table = $wpdb->prefix . 'simple_redirects';
        $session_id = self::get_session_id();
        
        $inserted = 0;
        foreach ($urls as $url) {
            $url = trim($url);
            if (empty($url) || strpos($url, 'http') !== 0) continue;
            
            $wpdb->insert($table, [
                'session_id' => $session_id,
                'old_url' => $url,
                'score' => 0
            ]);
            $inserted++;
        }
        
        return $inserted;
    }
    
    public static function get_all() {
        global $wpdb;
        $table = $wpdb->prefix . 'simple_redirects';
        $session_id = self::get_session_id();
        
        // Nur URLs dieser Session!
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table WHERE session_id = %s ORDER BY score DESC, id DESC", $session_id), 
            ARRAY_A
        );
    }
    
    public static function update_match($id, $new_url, $score) {
        global $wpdb;
        $table = $wpdb->prefix . 'simple_redirects';
        $session_id = self::get_session_id();
        
        // Nur Updates für eigene Session!
        $wpdb->update($table, [
            'new_url' => $new_url,
            'score' => $score
        ], [
            'id' => $id,
            'session_id' => $session_id
        ]);
    }
    
    public static function clear_all() {
        global $wpdb;
        $table = $wpdb->prefix . 'simple_redirects';
        $session_id = self::get_session_id();
        
        // Nur eigene Daten löschen!
        $wpdb->delete($table, ['session_id' => $session_id]);
    }
    
    public static function get_matched() {
        global $wpdb;
        $table = $wpdb->prefix . 'simple_redirects';
        $session_id = self::get_session_id();
        
        // Nur Matches dieser Session!
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE session_id = %s AND new_url IS NOT NULL AND new_url != '' ORDER BY score DESC",
                $session_id
            ), 
            ARRAY_A
        );
    }
}

