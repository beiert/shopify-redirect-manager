<?php

class SSR_Sitemap {
    
    private $fast_mode = false; // For locale detection only
    private $fast_mode_sample_size = 50; // URLs per sub-sitemap
    
    /**
     * Enable fast mode: Only load sample of URLs for locale detection
     */
    public function enable_fast_mode($sample_size = 50) {
        $this->fast_mode = true;
        $this->fast_mode_sample_size = $sample_size;
    }
    
    public function parse($url) {
        $all_urls = [];
        $subsitemap_count = 0;
        
        $xml = $this->fetch_xml($url);
        if (!$xml) return $all_urls;
        
        // Check if sitemap index
        if (isset($xml->sitemap)) {
            // Count sub-sitemaps
            $subsitemap_count = count($xml->sitemap);
            
            // AUTO-ENABLE FAST MODE for large sitemaps!
            // If more than 10 sub-sitemaps, enable fast mode to prevent timeout
            if ($subsitemap_count > 10 && !$this->fast_mode) {
                error_log("SSR: Auto-enabling Fast-Mode (detected $subsitemap_count sub-sitemaps)");
                $this->enable_fast_mode(50);
            }
            
            $count = 0;
            foreach ($xml->sitemap as $sitemap) {
                $subsitemap_url = (string) $sitemap->loc;
                
                // Add delay between requests to avoid rate limiting (429)
                // Skip delay for first request
                if ($count > 0) {
                    usleep(200000); // 200ms delay = max 5 requests/second
                }
                
                $urls = $this->parse_regular($subsitemap_url);
                $all_urls = array_merge($all_urls, $urls);
                $count++;
            }
            
            // Log how many sub-sitemaps were loaded
            error_log("SSR: Loaded $subsitemap_count sub-sitemaps" . 
                     ($this->fast_mode ? " (Fast-Mode: {$this->fast_mode_sample_size} URLs per sitemap)" : "") . 
                     ", total URLs: " . count($all_urls));
        } else {
            $all_urls = $this->parse_regular($url, $xml);
        }
        
        return $all_urls;
    }
    
    private function parse_regular($url, $xml = null) {
        if (!$xml) {
            $xml = $this->fetch_xml($url);
        }
        
        if (!$xml) return [];
        
        $urls = [];
        $count = 0;
        
        foreach ($xml->url as $url_entry) {
            if (!isset($url_entry->loc)) continue;
            
            // Fast mode: Stop after sample size
            if ($this->fast_mode && $count >= $this->fast_mode_sample_size) {
                break;
            }
            
            $url_string = (string) $url_entry->loc;
            
            // Parse hreflang alternates for cross-domain redirects
            $hreflang_alternates = [];
            if (isset($url_entry->children('http://www.w3.org/1999/xhtml')->link)) {
                foreach ($url_entry->children('http://www.w3.org/1999/xhtml')->link as $link) {
                    $attrs = $link->attributes();
                    if (isset($attrs['rel']) && (string)$attrs['rel'] === 'alternate' && 
                        isset($attrs['hreflang']) && isset($attrs['href'])) {
                        $hreflang = (string)$attrs['hreflang'];
                        $href = (string)$attrs['href'];
                        $hreflang_alternates[$hreflang] = $href;
                    }
                }
            }
            
            // Log if we found hreflang data
            if (!empty($hreflang_alternates)) {
                error_log("SSR SITEMAP: Found hreflang for $url_string: " . json_encode($hreflang_alternates));
            }
            
            $urls[] = [
                'url' => $url_string,
                'type' => $this->detect_type($url_string),
                'locale' => $this->extract_locale($url_string),
                'hreflang' => $hreflang_alternates
            ];
            
            $count++;
        }
        
        return $urls;
    }
    
    private function fetch_xml($url) {
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'sslverify' => false // Ignore SSL errors
        ]);
        
        if (is_wp_error($response)) {
            error_log('SSR Sitemap Error: ' . $response->get_error_message());
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            error_log('SSR Sitemap HTTP Error: ' . $status_code . ' for URL: ' . $url);
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        
        if (empty($body)) {
            error_log('SSR Sitemap Error: Empty response for URL: ' . $url);
            return false;
        }
        
        // Handle gzip
        if (substr($body, 0, 2) === "\x1f\x8b") {
            $body = gzdecode($body);
        }
        
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        
        if (!$xml) {
            $errors = libxml_get_errors();
            error_log('SSR Sitemap XML Parse Error: ' . print_r($errors, true));
            libxml_clear_errors();
            return false;
        }
        
        return $xml;
    }
    
    private function detect_type($url) {
        if (strpos($url, '/products/') !== false) return 'product';
        if (strpos($url, '/collections/') !== false) return 'collection';
        if (strpos($url, '/pages/') !== false) return 'page';
        if (strpos($url, '/blogs/') !== false) return 'blog';
        return 'other';
    }
    
    private function extract_locale($url) {
        $path = parse_url($url, PHP_URL_PATH);
        if (preg_match('#^/([a-z]{2}(-[a-z]{2})?)/#i', $path, $m)) {
            return strtolower($m[1]);
        }
        return 'default';
    }
}
