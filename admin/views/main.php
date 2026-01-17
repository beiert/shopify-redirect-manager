<?php if (!defined('ABSPATH')) exit; ?>

<div class="wrap ssr-wrap">
    <h1>🔄 Shopify Redirects Generator</h1>
    <p class="description">Einfach URLs hochladen, matchen lassen und Shopify-CSV exportieren.</p>
    
    <div class="ssr-container">
        
        <!-- STEP 1: URLs hinzufügen -->
        <div class="ssr-card">
            <h2>📝 Schritt 1: Alte URLs hinzufügen</h2>
            
            <div class="ssr-tabs">
                <button class="ssr-tab active" data-tab="text">Text einfügen</button>
                <button class="ssr-tab" data-tab="file">Datei hochladen</button>
            </div>
            
            <div class="ssr-tab-content active" id="tab-text">
                <p>Füge deine alten URLs ein (eine pro Zeile):</p>
                <textarea id="urls-text" placeholder="https://alte-domain.com/products/produkt-1
https://alte-domain.com/collections/kategorie-1
https://alte-domain.com/pages/seite-1" rows="10"></textarea>
                <button class="button button-primary button-large" id="btn-add-text">
                    URLs hinzufügen
                </button>
            </div>
            
            <div class="ssr-tab-content" id="tab-file">
                <p>Lade eine Textdatei oder CSV mit URLs hoch:</p>
                <input type="file" id="urls-file" accept=".txt,.csv">
                <button class="button button-primary button-large" id="btn-add-file">
                    Datei hochladen
                </button>
            </div>
            
            <div class="ssr-current-count">
                Aktuell: <strong><?php echo $count; ?> URLs</strong> geladen
                <?php if ($count > 0): ?>
                    <button class="button" id="btn-clear" style="margin-left: 15px;">Alle löschen</button>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- STEP 2: Matching -->
        <div class="ssr-card">
            <h2>🎯 Schritt 2: Neue URLs finden (Matching)</h2>
            <p>Gib die Sitemap deines <strong>neuen</strong> Shops ein:</p>
            
            <div class="ssr-input-group">
                <input type="url" 
                       id="sitemap-url" 
                       placeholder="https://neuer-shop.myshopify.com/sitemap.xml"
                       class="regular-text">
                <button class="button button-primary button-large" id="btn-match">
                    🔍 Jetzt matchen
                </button>
            </div>
            
            <div class="ssr-match-info">
                ✅ Gematched: <strong><?php echo $matched; ?> / <?php echo $count; ?></strong>
            </div>
        </div>
        
        <!-- STEP 3: Export -->
        <div class="ssr-card">
            <h2>💾 Schritt 3: Shopify CSV exportieren</h2>
            
            <?php if ($matched > 0): ?>
                <p>Du hast <strong><?php echo $matched; ?> Redirects</strong> bereit zum Export.</p>
                <button class="button button-primary button-large" id="btn-export">
                    📥 Shopify CSV herunterladen
                </button>
            <?php else: ?>
                <p class="ssr-notice">Erst URLs hinzufügen und matchen, dann exportieren.</p>
            <?php endif; ?>
        </div>
        
        <!-- Results Preview -->
        <?php if ($count > 0): ?>
        <div class="ssr-card">
            <h2>📋 Vorschau (letzte 50 Einträge)</h2>
            <div class="ssr-table-wrapper">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 45%;">Von (Alt)</th>
                            <th style="width: 45%;">Nach (Neu)</th>
                            <th style="width: 10%; text-align: center;">Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($redirects, 0, 50) as $r): ?>
                        <tr>
                            <td style="font-size: 11px; word-break: break-all;">
                                <code><?php echo esc_html($r['old_url']); ?></code>
                            </td>
                            <td style="font-size: 11px; word-break: break-all;">
                                <?php if ($r['new_url']): ?>
                                    <code><?php echo esc_html($r['new_url']); ?></code>
                                <?php else: ?>
                                    <span style="color: #999;">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($r['score'] > 0): ?>
                                    <span class="ssr-score ssr-score-<?php echo $r['score'] >= 80 ? 'high' : ($r['score'] >= 60 ? 'mid' : 'low'); ?>">
                                        <?php echo $r['score']; ?>
                                    </span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if ($count > 50): ?>
                    <p style="margin-top: 15px; color: #666;">... und <?php echo ($count - 50); ?> weitere</p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
    </div>
</div>

<div id="ssr-loading" style="display: none;">
    <div class="ssr-loading-overlay">
        <div class="ssr-loading-spinner"></div>
        <div class="ssr-loading-text">Wird verarbeitet...</div>
    </div>
</div>
