<?php

class SSR_Matcher {
    
    private $catalog = [];
    private $available_locales = [];
    private $locale_normalization_map = []; // Maps: 'en-bg' => 'en', etc.
    
    /**
     * Get base domain from catalog (for FULL URL fallbacks)
     */
    private function get_base_domain_for_locale($locale) {
        // Find a URL from the catalog with this locale
        foreach ($this->catalog as $item) {
            if ($item['locale'] === $locale || ($locale === 'default' && $item['locale'] === 'default')) {
                $parsed = parse_url($item['url']);
                if (isset($parsed['scheme']) && isset($parsed['host'])) {
                    return $parsed['scheme'] . '://' . $parsed['host'];
                }
            }
        }
        
        // Fallback: try to find ANY URL from catalog
        if (!empty($this->catalog)) {
            $parsed = parse_url($this->catalog[0]['url']);
            if (isset($parsed['scheme']) && isset($parsed['host'])) {
                return $parsed['scheme'] . '://' . $parsed['host'];
            }
        }
        
        return ''; // Should never happen
    }
    
    /**
     * Try to find match via LOCALE-DOMAIN preference
     * When old URL has locale on "wrong" domain (e.g. .de/en/), prefer domain with MOST content for that locale
     * Example: .de/en/products/X → .com/products/X (because .com has more 'en' content)
     *
     * SPECIAL CASE: For English ('en'), also consider 'default' locale URLs!
     * Because canapuff.com/products/X (default) is actually English content.
     */
    private function find_via_locale_domain($old_url, $old_handle, $old_type, $normalized_locale) {
        // Extract domain from old URL
        $old_parsed = parse_url($old_url);
        if (!isset($old_parsed['host'])) {
            error_log("SSR LOCALE-DOMAIN: No host in old URL: $old_url");
            return null;
        }

        // Normalize old domain (remove www.)
        $old_domain = strtolower($old_parsed['host']);
        $old_domain = preg_replace('/^www\./', '', $old_domain);

        error_log("SSR LOCALE-DOMAIN: Checking $old_url (domain: $old_domain, locale: $normalized_locale)");

        // SPECIAL: For English, also match 'default' locale (URLs without language prefix)
        // This handles: .com/en-pt/products/X → .com/products/X (default = English on .com)
        $locales_to_match = [$normalized_locale];
        if ($normalized_locale === 'en') {
            $locales_to_match[] = 'default';
        }

        // Count URLs per domain for this locale (including 'default' for English)
        $domain_counts = [];
        foreach ($this->catalog as $item) {
            if (!in_array($item['locale'], $locales_to_match)) {
                continue;
            }

            $item_parsed = parse_url($item['url']);
            if (!isset($item_parsed['host'])) {
                continue;
            }

            $item_domain = strtolower($item_parsed['host']);
            $item_domain = preg_replace('/^www\./', '', $item_domain);

            if (!isset($domain_counts[$item_domain])) {
                $domain_counts[$item_domain] = 0;
            }
            $domain_counts[$item_domain]++;
        }

        // Log domain distribution
        arsort($domain_counts);
        error_log("SSR LOCALE-DOMAIN: Domain distribution for locale '$normalized_locale' (+ default for en): " . json_encode($domain_counts));

        // Find domain with MOST content for this locale
        $primary_domain = null;
        $max_count = 0;
        foreach ($domain_counts as $domain => $count) {
            if ($count > $max_count) {
                $max_count = $count;
                $primary_domain = $domain;
            }
        }

        // If old domain is already the primary domain for English, skip this function!
        // Let find_via_domain() handle it instead (which was already called for English)
        // This prevents: .com/en-pt/products/X → .de/products/X when .com IS primary
        if ($old_domain === $primary_domain && $normalized_locale === 'en') {
            error_log("SSR LOCALE-DOMAIN: Old domain ($old_domain) IS primary for English - skipping (find_via_domain should handle)");
            return null;
        }

        // For non-English: continue and find best match on primary domain
        if ($old_domain === $primary_domain) {
            error_log("SSR LOCALE-DOMAIN: Old domain ($old_domain) IS primary - will search for better URL on same domain");
        }

        if (!$primary_domain) {
            error_log("SSR LOCALE-DOMAIN: No primary domain found");
            return null;
        }

        error_log("SSR LOCALE-DOMAIN: Primary domain for locale '$normalized_locale' is: $primary_domain (with $max_count URLs)");

        // Search for match on PRIMARY domain
        $best_match = null;
        $best_score = 0;

        // Get old URL's locale for comparison
        $old_url_locale = $this->get_locale($old_url);

        foreach ($this->catalog as $item) {
            // Match both normalized locale AND 'default' (for English)
            if (!in_array($item['locale'], $locales_to_match)) {
                continue;
            }

            $item_parsed = parse_url($item['url']);
            if (!isset($item_parsed['host'])) {
                continue;
            }

            $item_domain = strtolower($item_parsed['host']);
            $item_domain = preg_replace('/^www\./', '', $item_domain);

            // CRITICAL: Only consider primary domain
            if ($item_domain !== $primary_domain) {
                continue;
            }

            // CRITICAL: Skip URLs that still have a locale prefix!
            // We want: /en-pt/products/X → /products/X (clean URL without prefix)
            // We DON'T want: /en-pt/products/X → /en/products/X (still has locale prefix)
            // Only accept 'default' locale (no prefix) as valid target for English redirects
            if ($normalized_locale === 'en' && $item['locale'] !== 'default') {
                continue; // Skip /en/ URLs - we want clean /products/ URLs!
            }

            // Calculate handle similarity
            $handle_similarity = $this->string_similarity($old_handle, $item['handle']);

            // Type bonus
            $type_bonus = ($item['type'] === $old_type) ? 10 : 0;

            // BONUS: Prefer 'default' locale (no prefix) over '/en/' prefix
            // Because /products/X is cleaner than /en/products/X
            $default_bonus = ($item['locale'] === 'default') ? 5 : 0;

            $score = ($handle_similarity * 83) + $type_bonus + $default_bonus; // Max 98

            if ($handle_similarity > 0.6 && $score > $best_score) {
                error_log("SSR LOCALE-DOMAIN: Candidate found!");
                error_log("  - Old: $old_url (domain: $old_domain, locale: $old_url_locale)");
                error_log("  - New: {$item['url']} (domain: $item_domain, locale: {$item['locale']} - PRIMARY for '$normalized_locale')");
                error_log("  - Handle similarity: $handle_similarity");
                error_log("  - Score: $score (default_bonus: $default_bonus)");

                $best_score = $score;
                $best_match = $item['url'];
            }
        }

        // Require minimum 60% score
        if ($best_match && $best_score >= 60) {
            error_log("SSR LOCALE-DOMAIN MATCH! $old_url → $best_match (score: $best_score, moved from $old_domain to $primary_domain)");
            return [
                'url' => $best_match,
                'score' => round($best_score)
            ];
        }

        error_log("SSR LOCALE-DOMAIN: No suitable match found on primary domain");
        return null;
    }
    
    /**
     * Try to find match via DOMAIN preference
     * When hreflang is not available, prefer URLs from SAME domain
     * Example: canapuff.com/en-pt/products/X → canapuff.com/products/X (not .de!)
     *
     * SPECIAL CASE: For English ('en'), also consider 'default' locale URLs on same domain!
     */
    private function find_via_domain($old_url, $old_handle, $old_type, $normalized_locale) {
        // Extract domain from old URL
        $old_parsed = parse_url($old_url);
        if (!isset($old_parsed['host'])) {
            error_log("SSR DOMAIN: No host in old URL: $old_url");
            return null;
        }

        // Normalize domain (remove www.)
        $old_domain = strtolower($old_parsed['host']);
        $old_domain = preg_replace('/^www\./', '', $old_domain);

        error_log("SSR DOMAIN: Checking $old_url (domain: $old_domain, handle: $old_handle, locale: $normalized_locale)");

        // SPECIAL: For English, also match 'default' locale (URLs without language prefix)
        // This handles: .com/en-pt/products/X → .com/products/X (default = English)
        $locales_to_match = [$normalized_locale];
        if ($normalized_locale === 'en') {
            $locales_to_match[] = 'default';
        }

        $best_match = null;
        $best_score = 0;
        $candidates_checked = 0;

        // Search through catalog for same domain
        foreach ($this->catalog as $item) {
            // Check locale match (including 'default' for English)
            if (!in_array($item['locale'], $locales_to_match)) {
                continue;
            }

            // Extract domain from catalog item
            $item_parsed = parse_url($item['url']);
            if (!isset($item_parsed['host'])) {
                continue;
            }

            // Normalize catalog domain (remove www.)
            $item_domain = strtolower($item_parsed['host']);
            $item_domain = preg_replace('/^www\./', '', $item_domain);

            // CRITICAL: Only consider items from SAME domain
            if ($old_domain !== $item_domain) {
                continue; // Different domain - skip!
            }

            $candidates_checked++;

            // Calculate handle similarity
            $handle_similarity = $this->string_similarity($old_handle, $item['handle']);

            // Type bonus
            $type_bonus = ($item['type'] === $old_type) ? 10 : 0;

            // BONUS: Prefer 'default' locale (no prefix) over '/en/' prefix
            $default_bonus = ($item['locale'] === 'default') ? 2 : 0;

            $score = ($handle_similarity * 83) + $type_bonus + $default_bonus; // Max 95

            if ($handle_similarity > 0.6 && $score > $best_score) {
                error_log("SSR DOMAIN: Candidate found!");
                error_log("  - Old: $old_url (domain: $old_domain)");
                error_log("  - New: {$item['url']} (domain: $item_domain, locale: {$item['locale']})");
                error_log("  - Domain match: YES");
                error_log("  - Handle similarity: $handle_similarity (old: $old_handle vs new: {$item['handle']})");
                error_log("  - Score: $score");

                $best_score = $score;
                $best_match = $item['url'];
            }
        }

        // Require minimum 60% score for domain match
        if ($best_match && $best_score >= 60) {
            error_log("SSR DOMAIN MATCH! $old_url → $best_match (score: $best_score)");
            return [
                'url' => $best_match,
                'score' => round($best_score)
            ];
        }

        error_log("SSR DOMAIN: No match found. Candidates checked: $candidates_checked");
        return null;
    }
    
    /**
     * Try to find perfect match via hreflang
     * Example: canapuff.com/en-pt/products/X → hreflang points to canapuff.com/products/X
     */
    private function find_via_hreflang($old_url, $old_handle, $old_type, $normalized_locale) {
        // Extract domain from old URL
        $old_parsed = parse_url($old_url);
        if (!isset($old_parsed['host'])) {
            error_log("SSR HREFLANG: No host in old URL: $old_url");
            return null; // Can't determine domain
        }
        $old_domain = $old_parsed['host'];
        
        error_log("SSR HREFLANG: Checking $old_url (domain: $old_domain, handle: $old_handle, locale: $normalized_locale)");
        
        $catalog_with_hreflang = 0;
        $total_hreflang_checks = 0;
        
        // Search through catalog for entries with hreflang
        foreach ($this->catalog as $item) {
            if (empty($item['hreflang'])) {
                continue; // No hreflang data
            }
            
            $catalog_with_hreflang++;
            
            // Check each hreflang alternate
            foreach ($item['hreflang'] as $hreflang_locale => $hreflang_url) {
                $total_hreflang_checks++;
                
                $hreflang_parsed = parse_url($hreflang_url);
                if (!isset($hreflang_parsed['host'])) {
                    error_log("SSR HREFLANG: No host in hreflang URL: $hreflang_url");
                    continue;
                }
                
                // Extract handle from hreflang URL
                $hreflang_handle = $this->extract_handle($hreflang_url);
                $hreflang_domain = $hreflang_parsed['host'];
                
                // Perfect match conditions:
                // 1. Domain matches (canapuff.com = canapuff.com)
                // 2. Handle similarity is high (same product!)
                // 3. Locale matches (en = en, or en-pt normalized to en)
                $domain_match = ($old_domain === $hreflang_domain);
                $handle_similarity = $this->string_similarity($old_handle, $hreflang_handle);
                $locale_match = ($normalized_locale === $hreflang_locale || 
                                 $normalized_locale === 'default' || 
                                 $hreflang_locale === 'x-default');
                
                // Debug each condition
                if ($handle_similarity > 0.5) { // Log promising candidates
                    error_log("SSR HREFLANG: Candidate found!");
                    error_log("  - Old: $old_url (domain: $old_domain, locale: $normalized_locale)");
                    error_log("  - Hreflang: $hreflang_url (domain: $hreflang_domain, locale: $hreflang_locale)");
                    error_log("  - Domain match: " . ($domain_match ? 'YES' : 'NO'));
                    error_log("  - Handle similarity: $handle_similarity (old: $old_handle vs new: $hreflang_handle)");
                    error_log("  - Locale match: " . ($locale_match ? 'YES' : 'NO') . " ($normalized_locale vs $hreflang_locale)");
                }
                
                if ($domain_match && $handle_similarity > 0.7 && $locale_match) {
                    error_log("SSR HREFLANG MATCH! $old_url → $hreflang_url (similarity: $handle_similarity)");
                    return [
                        'url' => $hreflang_url,
                        'score' => 100 // Perfect hreflang match!
                    ];
                }
            }
        }
        
        error_log("SSR HREFLANG: No match found. Catalog items with hreflang: $catalog_with_hreflang, Total checks: $total_hreflang_checks");
        return null; // No hreflang match found
    }
    
    public function load_catalog_from_sitemap($sitemap_url) {
        $sitemap = new SSR_Sitemap();
        // Sitemap parser auto-enables fast mode if >10 sub-sitemaps!
        $urls = $sitemap->parse($sitemap_url);
        
        foreach ($urls as $url_data) {
            $this->catalog[] = [
                'url' => $url_data['url'],
                'handle' => $this->extract_handle($url_data['url']),
                'type' => $url_data['type'],
                'locale' => $url_data['locale'],
                'hreflang' => isset($url_data['hreflang']) ? $url_data['hreflang'] : []
            ];
            
            // Track available locales
            if (!in_array($url_data['locale'], $this->available_locales)) {
                $this->available_locales[] = $url_data['locale'];
            }
        }
        
        return count($this->catalog);
    }
    
    /**
     * Get locale statistics
     * Returns info about detected locales from sitemap
     */
    public function get_locale_stats() {
        return [
            'available_locales' => $this->available_locales,
            'locale_count' => count($this->available_locales),
            'normalization_map' => $this->locale_normalization_map
        ];
    }
    
    public function match_all() {
        $redirects = SSR_DB::get_all();
        $matched = 0;
        
        // STEP 1: Analyze locales from OLD URLs
        $old_url_locales = $this->analyze_old_url_locales($redirects);
        
        // STEP 2: Build smart locale map combining:
        // - Locales from Sitemap (available_locales)
        // - Locales from old URLs (old_url_locales)
        $this->build_locale_normalization_map($old_url_locales);
        
        // Detect if this is a multi-domain setup
        $is_multi_domain = $this->is_multi_domain_setup();
        if ($is_multi_domain) {
            error_log("SSR: Multi-domain setup detected - will skip locale-only paths");
        }

        foreach ($redirects as $redirect) {
            $old_url = $redirect['old_url'];

            // Extract info from old URL
            $old_handle = $this->extract_handle($old_url);
            $old_type = $this->get_type($old_url);
            $old_locale = $this->get_locale($old_url);

            // CRITICAL: Normalize locale IMMEDIATELY for matching!
            $normalized_locale = $this->normalize_locale($old_locale);

            // MULTI-DOMAIN CHECK: Skip URLs that are ONLY a locale prefix (e.g., /tr, /bg, /es)
            // In multi-domain setups, domain-level redirects handle: .pt/tr → .com/tr
            // Creating a redirect for /tr → / would cause loops or wrong behavior!
            if ($is_multi_domain && $this->is_locale_only_path($old_url)) {
                error_log("SSR: SKIPPING locale-only path in multi-domain setup: $old_url (domain redirect handles this)");
                continue;
            }

            // PRIORITY 1: Try hreflang matching FIRST!
            // Example: canapuff.com/en-pt/products/X → canapuff.com/products/X (via hreflang)
            $hreflang_match = $this->find_via_hreflang($old_url, $old_handle, $old_type, $normalized_locale);
            if ($hreflang_match) {
                error_log("SSR: Using HREFLANG match for $old_url");
                SSR_DB::update_match($redirect['id'], $hreflang_match['url'], $hreflang_match['score']);
                $matched++;
                continue; // Skip normal matching - hreflang is PERFECT!
            }

            // PRIORITY 2: Try SAME DOMAIN matching (ALWAYS prefer same domain!)
            // CRITICAL: Never redirect to a different domain/language!
            // Example: .com/en-pt/products/X → .com/products/X (stay on same domain!)
            $domain_match = $this->find_via_domain($old_url, $old_handle, $old_type, $normalized_locale);
            if ($domain_match) {
                error_log("SSR: Using DOMAIN match (same-domain priority) for $old_url");
                SSR_DB::update_match($redirect['id'], $domain_match['url'], $domain_match['score']);
                $matched++;
                continue;
            }

            // PRIORITY 3: Check if IDENTICAL PATH exists on .com domain
            // If yes, NO redirect needed - domain-level redirect handles it!
            // This prevents: .pt/cs/collections/xyz → .com/cs/collections (wrong!)
            // Instead: .pt/cs/collections/xyz → (no redirect, domain handles .pt → .com)
            if ($is_multi_domain && $this->identical_path_exists_on_com($old_url)) {
                error_log("SSR: SKIPPING - identical path exists on .com: $old_url (domain redirect handles this)");
                continue;
            }

            // PRIORITY 4: Try CROSS-DOMAIN matching for SIMILAR path (different handle)
            // If no match on same domain, check if a similar product/page exists on another domain
            // This handles: .pt/fr/pages/old-code → .com/fr/pages/new-code
            $cross_domain_match = $this->find_via_cross_domain($old_url, $old_handle, $old_type, $normalized_locale);
            if ($cross_domain_match) {
                error_log("SSR: Using CROSS-DOMAIN match for $old_url");
                SSR_DB::update_match($redirect['id'], $cross_domain_match['url'], $cross_domain_match['score']);
                $matched++;
                continue;
            }

            // PRIORITY 5: FALLBACK - No exact match found anywhere
            // Redirect to category page (prefer .com if same domain doesn't have it)
            $fallback = $this->find_fallback_same_domain($old_url, $old_type, $normalized_locale);
            if ($fallback) {
                error_log("SSR: Using FALLBACK for $old_url");
                SSR_DB::update_match($redirect['id'], $fallback['url'], $fallback['score']);
                $matched++;
            }
        }

        return $matched;
    }
    
    /**
     * Analyze locales used in old URLs
     * Returns array of unique locales found
     */
    private function analyze_old_url_locales($redirects) {
        $locales = [];
        
        foreach ($redirects as $redirect) {
            $locale = $this->get_locale($redirect['old_url']);
            if (!in_array($locale, $locales)) {
                $locales[] = $locale;
            }
        }
        
        return $locales;
    }
    
    /**
     * Build smart locale normalization map
     * CRITICAL: Locales that DON'T exist in new sitemap are ALWAYS normalized!
     */
    private function build_locale_normalization_map($old_url_locales) {
        $this->locale_normalization_map = [];
        
        // Collect ALL unique locales from old URLs
        foreach ($old_url_locales as $locale) {
            // Skip if locale already in available_locales (exists in new sitemap)
            if (in_array($locale, $this->available_locales)) {
                continue;
            }
            
            // Locale from old URL does NOT exist in new sitemap!
            if (strpos($locale, '-') !== false) {
                // Doppel-Locale (e.g., 'de-de', 'en-bg')
                $base = explode('-', $locale)[0];
                
                // ALWAYS normalize to base
                // (Even if base also doesn't exist in new sitemap - it's better than keeping de-de!)
                $this->locale_normalization_map[$locale] = $base;
            }
            // Single locale (e.g., 'de') that doesn't exist in new sitemap
            // → Don't add to map, normalize_locale() will handle it
        }
    }
    
    /**
     * Check if any URLs exist for given category path
     * Example: find_category_urls('/collections', 'en') checks if any /en/collections/* URLs exist
     */
    private function category_path_exists($category_path, $locale) {
        $locale_prefix = $locale !== 'default' ? '/' . $locale : '';
        $full_prefix = $locale_prefix . $category_path . '/';
        
        foreach ($this->catalog as $item) {
            if ($item['locale'] !== $locale) {
                continue;
            }
            
            // Check if URL starts with category prefix
            if (strpos($item['url'], $full_prefix) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Try to find match via CROSS-DOMAIN matching
     * When no match found on same domain, check if exact path exists on another domain
     * Example: canapuff.pt/fr/pages/old-code → canapuff.com/fr/pages/new-code
     *
     * IMPORTANT: If the path is IDENTICAL on both domains, NO redirect is needed!
     * Shopify's domain-level redirect already handles: .pt/es → .com/es
     * Creating a path redirect for identical paths would cause ERR_TOO_MANY_REDIRECTS!
     *
     * PRIORITY: .com domain is preferred over country-specific TLDs
     */
    private function find_via_cross_domain($old_url, $old_handle, $old_type, $normalized_locale) {
        // Extract path from old URL (without domain)
        $old_parsed = parse_url($old_url);
        if (!isset($old_parsed['host']) || !isset($old_parsed['path'])) {
            error_log("SSR CROSS-DOMAIN: Cannot parse old URL: $old_url");
            return null;
        }

        $old_domain = strtolower($old_parsed['host']);
        $old_domain = preg_replace('/^www\./', '', $old_domain);
        $old_path = $old_parsed['path'];

        error_log("SSR CROSS-DOMAIN: Checking $old_url (domain: $old_domain, path: $old_path, handle: $old_handle)");

        // Build list of candidate domains, prioritizing .com
        $candidate_domains = [];
        $com_domain = null;

        foreach ($this->catalog as $item) {
            $item_parsed = parse_url($item['url']);
            if (!isset($item_parsed['host'])) {
                continue;
            }

            $item_domain = strtolower($item_parsed['host']);
            $item_domain = preg_replace('/^www\./', '', $item_domain);

            // Skip same domain
            if ($item_domain === $old_domain) {
                continue;
            }

            // Track unique domains
            if (!in_array($item_domain, $candidate_domains)) {
                $candidate_domains[] = $item_domain;

                // Identify .com domain
                if (preg_match('/\.com$/', $item_domain)) {
                    $com_domain = $item_domain;
                }
            }
        }

        // Prioritize .com domain (move to front)
        if ($com_domain) {
            $candidate_domains = array_diff($candidate_domains, [$com_domain]);
            array_unshift($candidate_domains, $com_domain);
        }

        error_log("SSR CROSS-DOMAIN: Candidate domains (prioritized): " . json_encode($candidate_domains));

        // Search for exact path match on other domains
        $best_match = null;
        $best_score = 0;

        foreach ($this->catalog as $item) {
            // Must match locale
            if ($item['locale'] !== $normalized_locale) {
                continue;
            }

            $item_parsed = parse_url($item['url']);
            if (!isset($item_parsed['host']) || !isset($item_parsed['path'])) {
                continue;
            }

            $item_domain = strtolower($item_parsed['host']);
            $item_domain = preg_replace('/^www\./', '', $item_domain);
            $item_path = $item_parsed['path'];

            // Skip same domain
            if ($item_domain === $old_domain) {
                continue;
            }

            // CRITICAL: Skip if path is IDENTICAL!
            // Shopify's domain-level redirect already handles: .pt/es → .com/es
            // Creating a redirect for /es → /es would cause ERR_TOO_MANY_REDIRECTS!
            if ($old_path === $item_path) {
                error_log("SSR CROSS-DOMAIN: SKIPPING identical path! $old_path exists on $item_domain - no redirect needed (domain redirect handles this)");
                continue;
            }

            // Calculate path similarity
            $path_similarity = $this->string_similarity($old_path, $item_path);

            // Calculate handle similarity
            $handle_similarity = $this->string_similarity($old_handle, $item['handle']);

            // Type bonus
            $type_bonus = ($item['type'] === $old_type) ? 10 : 0;

            // .com bonus (prefer .com over other TLDs)
            $com_bonus = preg_match('/\.com$/', $item_domain) ? 5 : 0;

            $score = ($handle_similarity * 60) + ($path_similarity * 10) + $type_bonus + $com_bonus;

            // Log promising candidates
            if ($handle_similarity > 0.7 || $path_similarity > 0.8) {
                error_log("SSR CROSS-DOMAIN: Candidate found!");
                error_log("  - Old: $old_url (domain: $old_domain, path: $old_path)");
                error_log("  - New: {$item['url']} (domain: $item_domain, path: $item_path)");
                error_log("  - Handle similarity: $handle_similarity");
                error_log("  - Path similarity: $path_similarity");
                error_log("  - Score: $score (type_bonus: $type_bonus, com_bonus: $com_bonus)");
            }

            if ($score > $best_score && $handle_similarity > 0.7) {
                $best_score = $score;
                $best_match = $item['url'];
            }
        }

        // Require minimum score for cross-domain match (higher threshold since it's a domain change)
        if ($best_match && $best_score >= 55) {
            error_log("SSR CROSS-DOMAIN MATCH! $old_url → $best_match (score: $best_score)");
            return [
                'url' => $best_match,
                'score' => round($best_score)
            ];
        }

        error_log("SSR CROSS-DOMAIN: No suitable match found on other domains");
        return null;
    }

    /**
     * Smart Fallback-Strategie for Multi-Domain Setups
     *
     * IMPORTANT: In multi-domain setups, category pages may only exist on .com domain!
     * Example: canapuff.pt/bg/collections does NOT exist, but canapuff.com/bg/collections DOES
     *
     * Strategy:
     * 1. First try to find fallback on SAME domain (if it exists in catalog)
     * 2. If not found, try .com domain (primary domain for most content)
     * 3. Only return URLs that actually exist in the catalog!
     */
    private function find_fallback_same_domain($old_url, $type, $locale) {
        // Extract domain from old URL
        $old_parsed = parse_url($old_url);
        if (!isset($old_parsed['host'])) {
            error_log("SSR FALLBACK: No host in old URL: $old_url");
            return null;
        }

        $old_domain = strtolower($old_parsed['host']);
        $old_domain = preg_replace('/^www\./', '', $old_domain);

        error_log("SSR FALLBACK: Finding fallback for $old_url (domain: $old_domain, type: $type, locale: $locale)");

        // Build locale prefix
        $locale_prefix = '';
        if ($locale !== 'default' && $locale !== 'en') {
            $locale_prefix = '/' . $locale;
        }

        // Determine fallback paths based on type
        $fallback_paths = $this->get_fallback_paths_for_type($type, $locale_prefix);

        // Find .com domain from catalog (for cross-domain fallback)
        $com_domain = $this->find_com_domain();

        $old_path = parse_url($old_url, PHP_URL_PATH);

        // Try each fallback path
        foreach ($fallback_paths as $fallback_info) {
            $path = $fallback_info['path'];
            $score = $fallback_info['score'];

            // PRIORITY 1: Try same domain first
            $same_domain_url = $this->find_url_in_catalog_by_path($path, $old_domain, $locale);
            if ($same_domain_url) {
                error_log("SSR FALLBACK: Found on SAME domain: $same_domain_url");
                return ['url' => $same_domain_url, 'score' => $score];
            }

            // PRIORITY 2: Try .com domain (if different from old domain)
            if ($com_domain && $com_domain !== $old_domain) {
                $com_url = $this->find_url_in_catalog_by_path($path, $com_domain, $locale);
                if ($com_url) {
                    // CRITICAL: Check if path is identical - if so, skip (domain redirect handles it)
                    $com_path = parse_url($com_url, PHP_URL_PATH);
                    if ($com_path === $old_path) {
                        error_log("SSR FALLBACK: SKIPPING identical path on .com: $com_url (domain redirect handles this)");
                        continue;
                    }
                    error_log("SSR FALLBACK: Found on .COM domain: $com_url");
                    return ['url' => $com_url, 'score' => $score - 5]; // Slightly lower score for cross-domain
                }
            }
        }

        // PRIORITY 3: If no exact path found, find ANY URL of the same type and locale on .com
        // This handles cases where /cs/collections doesn't exist but /cs/collections/all does
        if ($com_domain && $com_domain !== $old_domain) {
            $any_matching_url = $this->find_any_url_by_type_and_locale($type, $locale, $com_domain, $old_path);
            if ($any_matching_url) {
                error_log("SSR FALLBACK: Found ANY matching URL on .COM domain: $any_matching_url");
                return ['url' => $any_matching_url, 'score' => 20];
            }
        }

        error_log("SSR FALLBACK: No valid fallback found in catalog for $old_url");
        return null;
    }

    /**
     * Get ordered list of fallback paths for a given type
     */
    private function get_fallback_paths_for_type($type, $locale_prefix) {
        switch ($type) {
            case 'product':
                return [
                    ['path' => $locale_prefix . '/collections/all', 'score' => 40],
                    ['path' => $locale_prefix . '/collections', 'score' => 35],
                    ['path' => $locale_prefix . '/', 'score' => 25]
                ];

            case 'collection':
                return [
                    ['path' => $locale_prefix . '/collections', 'score' => 35],
                    ['path' => $locale_prefix . '/collections/all', 'score' => 30],
                    ['path' => $locale_prefix . '/', 'score' => 25]
                ];

            case 'page':
                return [
                    ['path' => $locale_prefix . '/', 'score' => 25]
                ];

            case 'blog':
            case 'article':
                return [
                    ['path' => $locale_prefix . '/blogs', 'score' => 30],
                    ['path' => $locale_prefix . '/', 'score' => 25]
                ];

            default:
                return [
                    ['path' => $locale_prefix . '/', 'score' => 20]
                ];
        }
    }

    /**
     * Find a URL in the catalog by path and domain
     * Returns the full URL if found, null otherwise
     */
    private function find_url_in_catalog_by_path($path, $target_domain, $locale) {
        // Normalize path (remove trailing slash for comparison)
        $path = rtrim($path, '/');
        if (empty($path)) {
            $path = '/';
        }

        foreach ($this->catalog as $item) {
            $item_parsed = parse_url($item['url']);
            if (!isset($item_parsed['host']) || !isset($item_parsed['path'])) {
                continue;
            }

            $item_domain = strtolower($item_parsed['host']);
            $item_domain = preg_replace('/^www\./', '', $item_domain);

            // Must match target domain
            if ($item_domain !== $target_domain) {
                continue;
            }

            // Compare paths (normalize both)
            $item_path = rtrim($item_parsed['path'], '/');
            if (empty($item_path)) {
                $item_path = '/';
            }

            if ($item_path === $path) {
                return $item['url'];
            }
        }

        return null;
    }

    /**
     * Find the .com domain from the catalog
     * Returns the domain (without www) or null if not found
     */
    private function find_com_domain() {
        foreach ($this->catalog as $item) {
            $parsed = parse_url($item['url']);
            if (!isset($parsed['host'])) {
                continue;
            }

            $domain = strtolower($parsed['host']);
            $domain = preg_replace('/^www\./', '', $domain);

            if (preg_match('/\.com$/', $domain)) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * Find ANY URL in the catalog that matches the given type, locale, and domain
     * Used as a last resort fallback when exact paths don't exist
     *
     * Priority order:
     * 1. /locale/collections/all (for products/collections)
     * 2. /locale/collections (index page)
     * 3. Any collection URL in that locale
     * 4. Homepage with locale
     */
    private function find_any_url_by_type_and_locale($type, $locale, $target_domain, $old_path) {
        $locale_prefix = '';
        if ($locale !== 'default' && $locale !== 'en') {
            $locale_prefix = '/' . $locale;
        }

        // Define what type of URLs we're looking for based on original type
        $target_type = $type;
        if ($type === 'product') {
            $target_type = 'collection'; // Products should fallback to collections
        }

        $best_url = null;
        $best_priority = 999;

        foreach ($this->catalog as $item) {
            // Must match locale
            if ($item['locale'] !== $locale) {
                continue;
            }

            $item_parsed = parse_url($item['url']);
            if (!isset($item_parsed['host']) || !isset($item_parsed['path'])) {
                continue;
            }

            $item_domain = strtolower($item_parsed['host']);
            $item_domain = preg_replace('/^www\./', '', $item_domain);

            // Must match target domain
            if ($item_domain !== $target_domain) {
                continue;
            }

            $item_path = $item_parsed['path'];

            // CRITICAL: Skip if path is identical to old path (would cause redirect loop)
            if ($item_path === $old_path || rtrim($item_path, '/') === rtrim($old_path, '/')) {
                continue;
            }

            // Assign priority based on URL structure
            $priority = 999;

            if ($type === 'product' || $type === 'collection') {
                // For products/collections, prefer collections URLs
                if (preg_match('#' . preg_quote($locale_prefix, '#') . '/collections/all/?$#', $item_path)) {
                    $priority = 1; // Best: /cs/collections/all
                } elseif (preg_match('#' . preg_quote($locale_prefix, '#') . '/collections/?$#', $item_path)) {
                    $priority = 2; // Good: /cs/collections
                } elseif ($item['type'] === 'collection') {
                    $priority = 3; // OK: any collection in this locale
                } elseif (preg_match('#^' . preg_quote($locale_prefix, '#') . '/?$#', $item_path)) {
                    $priority = 10; // Last resort: homepage /cs/
                }
            } elseif ($type === 'blog' || $type === 'article') {
                if (preg_match('#' . preg_quote($locale_prefix, '#') . '/blogs/?$#', $item_path)) {
                    $priority = 1;
                } elseif ($item['type'] === 'blog') {
                    $priority = 2;
                } elseif (preg_match('#^' . preg_quote($locale_prefix, '#') . '/?$#', $item_path)) {
                    $priority = 10;
                }
            } elseif ($type === 'page') {
                if (preg_match('#^' . preg_quote($locale_prefix, '#') . '/?$#', $item_path)) {
                    $priority = 1; // Homepage for pages
                }
            }

            if ($priority < $best_priority) {
                $best_priority = $priority;
                $best_url = $item['url'];
            }
        }

        return $best_url;
    }

    /**
     * Intelligente Fallback-Strategie
     * NOTE: This function may redirect to different domains - use find_fallback_same_domain instead!
     */
    private function find_fallback($old_url, $type, $locale) {
        // Locale is already normalized when passed in
        
        // CRITICAL: Verify normalized locale actually EXISTS in sitemap!
        // Example: en-hr → en, but if 'en' doesn't exist in sitemap, use homepage instead
        if ($locale !== 'default' && !in_array($locale, $this->available_locales)) {
            error_log("SSR: Normalized locale '$locale' not found in sitemap. Using homepage fallback.");
            $locale = 'default'; // Fall back to homepage (no locale prefix)
        }
        
        $locale_prefix = $locale !== 'default' ? '/' . $locale : '';
        
        // Get base domain for FULL URLs (Option A)
        $base_domain = $this->get_base_domain_for_locale($locale);
        
        // Extrahiere Keywords aus alter URL für intelligente Zuordnung
        $keywords = $this->extract_keywords($old_url);
        
        switch ($type) {
            case 'product':
                // 1. Versuche Collection aus Keywords zu finden
                if (!empty($keywords)) {
                    $collection_match = $this->find_collection_by_keywords($keywords, $locale);
                    if ($collection_match) {
                        return ['url' => $collection_match, 'score' => 45];
                    }
                }
                
                // 2. CRITICAL: Old URL was /products/X → Try /products FIRST!
                // This makes sense: product → products category (not collections)
                if ($this->url_exists_in_catalog($locale_prefix . '/products', $locale)) {
                    return ['url' => $base_domain . $locale_prefix . '/products', 'score' => 40];
                }
                // If /products not in sitemap, but product URLs exist, use it anyway!
                if ($this->category_path_exists('/products', $locale)) {
                    return ['url' => $base_domain . $locale_prefix . '/products', 'score' => 40];
                }
                
                // 3. Fallback auf /collections/all (if exists)
                if ($this->url_exists_in_catalog($locale_prefix . '/collections/all', $locale)) {
                    return ['url' => $base_domain . $locale_prefix . '/collections/all', 'score' => 35];
                }
                
                // 4. Fallback auf /collections (if exists OR if ANY collections exist!)
                // Check exact URL first
                if ($this->url_exists_in_catalog($locale_prefix . '/collections', $locale)) {
                    return ['url' => $base_domain . $locale_prefix . '/collections', 'score' => 30];
                }
                // If /collections URL not in sitemap, but collection URLs exist, use it anyway!
                if ($this->category_path_exists('/collections', $locale)) {
                    return ['url' => $base_domain . $locale_prefix . '/collections', 'score' => 30];
                }
                
                // 5. Als letztes: Startseite
                return ['url' => $base_domain . $locale_prefix . '/', 'score' => 25];
                
            case 'collection':
                // 1. Versuche ähnliche Collection zu finden
                $similar = $this->find_similar_collection($old_url, $locale);
                if ($similar) {
                    return ['url' => $similar, 'score' => 42];
                }
                
                // 2. Fallback auf /collections/all (if exists)
                if ($this->url_exists_in_catalog($locale_prefix . '/collections/all', $locale)) {
                    return ['url' => $base_domain . $locale_prefix . '/collections/all', 'score' => 35];
                }
                
                // 3. Fallback auf /collections (if exists OR if ANY collections exist!)
                if ($this->url_exists_in_catalog($locale_prefix . '/collections', $locale)) {
                    return ['url' => $base_domain . $locale_prefix . '/collections', 'score' => 30];
                }
                // If /collections not in sitemap, but collection URLs exist, use it anyway!
                if ($this->category_path_exists('/collections', $locale)) {
                    return ['url' => $base_domain . $locale_prefix . '/collections', 'score' => 30];
                }
                
                // 4. Als letztes: Startseite
                return ['url' => $base_domain . $locale_prefix . '/', 'score' => 25];
                
            case 'page':
                // 1. Versuche häufige Pages zu finden
                $common_pages = ['contact', 'about', 'about-us', 'impressum', 'datenschutz', 'privacy', 'terms'];
                foreach ($common_pages as $page_slug) {
                    $page_url = $locale_prefix . '/pages/' . $page_slug;
                    if ($this->url_exists_in_catalog($page_url, $locale)) {
                        return ['url' => $base_domain . $page_url, 'score' => 30];
                    }
                }
                
                // 2. Fallback auf Startseite (pages overview rarely exists)
                return ['url' => $base_domain . $locale_prefix . '/', 'score' => 25];
                
            case 'blog':
            case 'article':
                // 1. Versuche den SPEZIFISCHEN Blog zu finden (z.B. /blogs/novini/)
                $blog_slug = $this->extract_blog_slug($old_url);
                if ($blog_slug) {
                    $blog_url = $locale_prefix . '/blogs/' . $blog_slug;
                    if ($this->url_exists_in_catalog($blog_url, $locale)) {
                        return ['url' => $base_domain . $blog_url, 'score' => 40];
                    }
                }
                
                // 2. Versuche IRGENDEINEN Blog zu finden (erster verfügbarer)
                $any_blog = $this->find_any_blog($locale);
                if ($any_blog) {
                    return ['url' => $any_blog, 'score' => 35];
                }
                
                // 3. Fallback auf /blogs (if exists OR if ANY blogs exist!)
                if ($this->url_exists_in_catalog($locale_prefix . '/blogs', $locale)) {
                    return ['url' => $base_domain . $locale_prefix . '/blogs', 'score' => 30];
                }
                // If /blogs not in sitemap, but blog URLs exist, use it anyway!
                if ($this->category_path_exists('/blogs', $locale)) {
                    return ['url' => $base_domain . $locale_prefix . '/blogs', 'score' => 30];
                }
                
                // 4. Als letztes: Startseite
                return ['url' => $base_domain . $locale_prefix . '/', 'score' => 25];
                
            default:
                // Fallback auf Startseite
                return ['url' => $base_domain . $locale_prefix . '/', 'score' => 20];
        }
    }
    
    /**
     * Extrahiere Keywords aus URL für intelligentes Matching
     */
    private function extract_keywords($url) {
        $path = parse_url($url, PHP_URL_PATH);
        $path = strtolower($path);
        
        // Bekannte Keywords mit hoher Relevanz
        $keyword_map = [
            'vape' => 'vapes',
            'vaping' => 'vapes',
            'cbd' => 'cbd',
            'thc' => 'thc',
            'hhc' => 'hhc',
            'flower' => 'flowers',
            'kvet' => 'flowers',
            'blute' => 'flowers',
            'sale' => 'sale',
            'deal' => 'deals',
            'new' => 'new',
            'grow' => 'grow',
            'kratom' => 'kratom'
        ];
        
        $found_keywords = [];
        foreach ($keyword_map as $search => $collection) {
            if (strpos($path, $search) !== false) {
                $found_keywords[] = $collection;
            }
        }
        
        return array_unique($found_keywords);
    }
    
    /**
     * Finde Collection basierend auf Keywords
     */
    private function find_collection_by_keywords($keywords, $locale) {
        foreach ($this->catalog as $item) {
            if ($item['type'] !== 'collection' || $item['locale'] !== $locale) {
                continue;
            }
            
            $item_url = strtolower($item['url']);
            
            foreach ($keywords as $keyword) {
                if (strpos($item_url, $keyword) !== false) {
                    return $item['url'];
                }
            }
        }
        
        return null;
    }
    
    /**
     * Finde ähnliche Collection
     */
    private function find_similar_collection($old_url, $locale) {
        $old_handle = $this->extract_handle($old_url);
        $best_match = null;
        $best_similarity = 0;
        
        foreach ($this->catalog as $item) {
            if ($item['type'] !== 'collection' || $item['locale'] !== $locale) {
                continue;
            }
            
            $similarity = $this->string_similarity($old_handle, $item['handle']);
            
            if ($similarity > $best_similarity && $similarity >= 0.4) {
                $best_similarity = $similarity;
                $best_match = $item['url'];
            }
        }
        
        return $best_match;
    }
    
    /**
     * Prüfe ob URL im Katalog existiert
     */
    private function url_exists_in_catalog($url_path, $locale) {
        foreach ($this->catalog as $item) {
            if ($item['locale'] !== $locale) {
                continue;
            }
            
            $item_path = parse_url($item['url'], PHP_URL_PATH);
            if ($item_path === $url_path) {
                return true;
            }
        }
        
        return false;
    }
    
    private function extract_handle($url) {
        $path = parse_url($url, PHP_URL_PATH);
        $path = preg_replace('#^/[a-z]{2}(-[a-z]{2})?/#i', '/', $path);
        
        if (preg_match('#/(products|collections|pages|blogs)/([^/\?]+)#', $path, $m)) {
            return $m[2];
        }
        
        return basename($path);
    }
    
    private function get_type($url) {
        if (strpos($url, '/products/') !== false) return 'product';
        if (strpos($url, '/collections/') !== false) return 'collection';
        if (strpos($url, '/pages/') !== false) return 'page';
        if (strpos($url, '/blogs/') !== false) return 'blog';
        return 'other';
    }
    
    /**
     * Extract blog slug from blog/article URL
     * Example: /el/blogs/novini/cbd-article → 'novini'
     */
    private function extract_blog_slug($url) {
        $path = parse_url($url, PHP_URL_PATH);
        // Remove locale prefix
        $path = preg_replace('#^/[a-z]{2}(-[a-z]{2})?/#i', '/', $path);
        
        // Match /blogs/{blog-slug}/...
        if (preg_match('#/blogs/([^/\?]+)#', $path, $m)) {
            return $m[1];
        }
        
        return null;
    }
    
    /**
     * Find any available blog in the catalog for given locale
     * Returns first blog URL found
     */
    private function find_any_blog($locale) {
        $locale_prefix = $locale !== 'default' ? '/' . $locale : '';
        
        foreach ($this->catalog as $item) {
            if ($item['locale'] !== $locale) {
                continue;
            }
            
            $path = parse_url($item['url'], PHP_URL_PATH);
            // Match /blogs/{blog-slug} (not /blogs/{blog}/{article})
            if (preg_match('#^' . preg_quote($locale_prefix, '#') . '/blogs/[^/]+/?$#', $path)) {
                return $item['url'];
            }
        }
        
        return null;
    }
    
    /**
     * Normalize locale intelligently using normalization map
     * Example: 'el-gr' → 'el' (if 'el' exists)
     */
    private function normalize_locale($locale) {
        // Check normalization map first (built from sitemap + old URLs)
        if (isset($this->locale_normalization_map[$locale])) {
            return $this->locale_normalization_map[$locale];
        }
        
        // If locale is in available_locales (from sitemap), use as-is
        if (in_array($locale, $this->available_locales)) {
            return $locale;
        }
        
        // Fallback: If contains hyphen, try base locale
        if (strpos($locale, '-') !== false) {
            $base_locale = explode('-', $locale)[0];
            // Check if base exists in sitemap
            if (in_array($base_locale, $this->available_locales)) {
                return $base_locale;
            }
            // Otherwise return base anyway (best guess)
            return $base_locale;
        }
        
        // Single locale without hyphen, return as-is
        return $locale;
    }
    
    private function get_locale($url) {
        $path = parse_url($url, PHP_URL_PATH);
        if (preg_match('#^/([a-z]{2}(-[a-z]{2})?)/#i', $path, $m)) {
            return strtolower($m[1]);
        }
        // URLs without locale in path = 'default' (matches sitemap behavior!)
        // This ensures consistency between sitemap parsing and URL matching
        return 'default';
    }
    
    private function string_similarity($str1, $str2) {
        $str1 = strtolower($str1);
        $str2 = strtolower($str2);

        if ($str1 === $str2) return 1.0;

        similar_text($str1, $str2, $percent);
        return $percent / 100;
    }

    /**
     * Check if the IDENTICAL path exists on the .com domain
     * Used to determine if we should skip creating a redirect (domain redirect handles it)
     *
     * Example: canapuff.pt/cs/collections/konopny-caj
     * If canapuff.com/cs/collections/konopny-caj exists → return true (no redirect needed)
     * If it doesn't exist → return false (need fallback redirect)
     */
    private function identical_path_exists_on_com($old_url) {
        $old_parsed = parse_url($old_url);
        if (!isset($old_parsed['path'])) {
            return false;
        }

        $old_path = $old_parsed['path'];
        $old_domain = isset($old_parsed['host']) ? strtolower($old_parsed['host']) : '';
        $old_domain = preg_replace('/^www\./', '', $old_domain);

        // Find .com domain
        $com_domain = $this->find_com_domain();
        if (!$com_domain || $com_domain === $old_domain) {
            return false; // No .com domain or already on .com
        }

        // Check if identical path exists on .com
        foreach ($this->catalog as $item) {
            $item_parsed = parse_url($item['url']);
            if (!isset($item_parsed['host']) || !isset($item_parsed['path'])) {
                continue;
            }

            $item_domain = strtolower($item_parsed['host']);
            $item_domain = preg_replace('/^www\./', '', $item_domain);

            // Must be on .com domain
            if ($item_domain !== $com_domain) {
                continue;
            }

            // Check if path is identical
            $item_path = $item_parsed['path'];
            if ($item_path === $old_path || rtrim($item_path, '/') === rtrim($old_path, '/')) {
                error_log("SSR: Identical path found on .com: $old_path → {$item['url']}");
                return true;
            }
        }

        return false;
    }

    /**
     * Check if this is a multi-domain setup
     * Returns true if catalog contains URLs from multiple different domains
     * Example: canapuff.com, canapuff.pt, canapuff.de = multi-domain
     */
    private function is_multi_domain_setup() {
        $domains = [];

        foreach ($this->catalog as $item) {
            $parsed = parse_url($item['url']);
            if (!isset($parsed['host'])) {
                continue;
            }

            $domain = strtolower($parsed['host']);
            $domain = preg_replace('/^www\./', '', $domain);

            if (!in_array($domain, $domains)) {
                $domains[] = $domain;
            }

            // Early exit: if we found more than 1 domain, it's multi-domain
            if (count($domains) > 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if URL path is ONLY a locale prefix (e.g., /tr, /bg, /es, /fr/)
     * These paths should be skipped in multi-domain setups because:
     * - Domain-level redirect handles: .pt/tr → .com/tr
     * - Creating /tr → / would cause redirect loops
     *
     * Matches: /tr, /tr/, /bg, /es/, /fr, /de-de, /en-gb/
     * Does NOT match: /tr/products, /bg/collections/xyz, /es/pages/about
     */
    private function is_locale_only_path($url) {
        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) {
            return false;
        }

        // Normalize: remove trailing slash for comparison
        $path = rtrim($path, '/');

        // Check if path matches ONLY a locale pattern
        // Matches: /xx or /xx-xx (ISO language codes)
        if (preg_match('#^/[a-z]{2}(-[a-z]{2})?$#i', $path)) {
            return true;
        }

        return false;
    }
}
