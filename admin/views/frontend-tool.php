<?php if (!defined('ABSPATH')) exit; ?>

<div class="ssr-frontend-tool">
    
    <!-- Stats Bar -->
    <div class="ssr-stats-bar" id="ssr-stats-bar" style="display: none;">
        <div class="ssr-stat-item">
            <div class="ssr-stat-icon">📊</div>
            <div class="ssr-stat-content">
                <div class="ssr-stat-number" id="stat-total">0</div>
                <div class="ssr-stat-label">URLs Total</div>
            </div>
        </div>
        <div class="ssr-stat-item">
            <div class="ssr-stat-icon">✅</div>
            <div class="ssr-stat-content">
                <div class="ssr-stat-number" id="stat-matched">0</div>
                <div class="ssr-stat-label">Gematched</div>
            </div>
        </div>
        <div class="ssr-stat-item">
            <div class="ssr-stat-icon">🟢</div>
            <div class="ssr-stat-content">
                <div class="ssr-stat-number" id="stat-excellent">0</div>
                <div class="ssr-stat-label">Perfekt</div>
            </div>
        </div>
        <div class="ssr-stat-item">
            <div class="ssr-stat-icon">🟡</div>
            <div class="ssr-stat-content">
                <div class="ssr-stat-number" id="stat-fallback">0</div>
                <div class="ssr-stat-label">Fallback</div>
            </div>
        </div>
        
        <!-- Reset Button -->
        <div class="ssr-stat-item ssr-reset-item">
            <button class="ssr-btn ssr-btn-reset" id="btn-reset" title="Alles zurücksetzen und neues Projekt starten">
                <span class="ssr-btn-icon">🔄</span>
                Neues Projekt
            </button>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="ssr-steps">
        
        <!-- Step 1: URLs hinzufügen -->
        <div class="ssr-step" data-step="1">
            <div class="ssr-step-header">
                <div class="ssr-step-number">1</div>
                <div class="ssr-step-title">URLs hinzufügen</div>
            </div>
            
            <div class="ssr-step-content">
                <!-- Tabs -->
                <div class="ssr-tabs">
                    <button class="ssr-tab-btn active" data-tab="text">
                        <span class="ssr-tab-icon">📝</span>
                        Text einfügen
                    </button>
                    <button class="ssr-tab-btn" data-tab="file">
                        <span class="ssr-tab-icon">📁</span>
                        Datei hochladen
                    </button>
                    <button class="ssr-tab-btn" data-tab="errors" id="tab-btn-errors" style="display: none;">
                        <span class="ssr-tab-icon">❌</span>
                        Fehler <span class="ssr-error-badge" id="error-count-badge">0</span>
                    </button>
                </div>
                
                <!-- Tab Content: Text -->
                <div class="ssr-tab-panel active" id="tab-text">
                    <div class="ssr-form-group">
                        <label class="ssr-label">Alte URLs (eine pro Zeile)</label>
                        <textarea 
                            id="urls-text" 
                            class="ssr-textarea" 
                            rows="10"
                            placeholder="https://alte-domain.com/products/produkt-1&#10;https://alte-domain.com/collections/kategorie-1&#10;https://alte-domain.com/pages/seite-1"
                        ></textarea>
                        <div class="ssr-help-text">
                            💡 Füge deine alten Shop-URLs ein, eine pro Zeile
                        </div>
                    </div>
                    <button class="ssr-btn ssr-btn-primary" id="btn-add-text">
                        <span class="ssr-btn-icon">➕</span>
                        URLs hinzufügen
                    </button>
                </div>
                
                <!-- Tab Content: File -->
                <div class="ssr-tab-panel" id="tab-file">
                    <div class="ssr-form-group">
                        <label class="ssr-label">CSV oder TXT Datei</label>
                        <div class="ssr-file-upload">
                            <input type="file" id="urls-file" accept=".txt,.csv" class="ssr-file-input">
                            <label for="urls-file" class="ssr-file-label" id="drop-zone">
                                <span class="ssr-file-icon">📤</span>
                                <span class="ssr-file-text">Datei auswählen oder hier ablegen</span>
                                <span class="ssr-file-hint">Drag & Drop unterstützt</span>
                            </label>
                        </div>
                        <div class="ssr-help-text">
                            💡 Unterstützt .txt und .csv Dateien (max 5MB)
                        </div>
                    </div>
                    <button class="ssr-btn ssr-btn-primary" id="btn-add-file">
                        <span class="ssr-btn-icon">⬆️</span>
                        Datei hochladen
                    </button>
                </div>
                
                <!-- Tab Content: Errors -->
                <div class="ssr-tab-panel" id="tab-errors">
                    <div class="ssr-error-header">
                        <h4>❌ Ungültige URLs</h4>
                        <p>Diese URLs wurden übersprungen, weil sie nicht dem richtigen Format entsprechen.</p>
                    </div>
                    
                    <div class="ssr-error-list" id="error-urls-list">
                        <!-- Filled by JS -->
                    </div>
                    
                    <div class="ssr-error-actions">
                        <button class="ssr-btn ssr-btn-outline" id="btn-clear-errors">
                            <span class="ssr-btn-icon">🗑️</span>
                            Fehler-Liste leeren
                        </button>
                        <button class="ssr-btn ssr-btn-primary" id="btn-copy-errors">
                            <span class="ssr-btn-icon">📋</span>
                            Alle Fehler kopieren
                        </button>
                    </div>
                </div>
                
                <?php if ($atts['allow_clear'] === 'yes'): ?>
                <div class="ssr-clear-section">
                    <button class="ssr-btn ssr-btn-outline" id="btn-clear">
                        <span class="ssr-btn-icon">🗑️</span>
                        Alle URLs löschen
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Step 2: Matching -->
        <div class="ssr-step" data-step="2">
            <div class="ssr-step-header">
                <div class="ssr-step-number">2</div>
                <div class="ssr-step-title">Neue URLs finden (Matching)</div>
            </div>
            
            <div class="ssr-step-content">
                <div class="ssr-form-group">
                    <label class="ssr-label">
                        Sitemaps des neuen Shops
                        <span class="ssr-badge">Multi-Domain Support</span>
                    </label>
                    
                    <div id="sitemaps-container">
                        <!-- Sitemap 1 (required) -->
                        <div class="ssr-sitemap-row" data-index="0">
                            <div class="ssr-input-group">
                                <span class="ssr-input-icon">🌐</span>
                                <input 
                                    type="url" 
                                    class="ssr-input sitemap-url" 
                                    placeholder="https://shop.com/sitemap.xml"
                                    data-index="0"
                                    required
                                >
                                <span class="ssr-sitemap-label">Haupt-Sitemap</span>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" class="ssr-btn ssr-btn-secondary" id="btn-add-sitemap">
                        <span class="ssr-btn-icon">➕</span>
                        Weitere Sitemap hinzufügen
                    </button>
                    
                    <script>
                    // Direct vanilla JS - no jQuery dependency
                    document.addEventListener('DOMContentLoaded', function() {
                        var btn = document.getElementById('btn-add-sitemap');
                        var container = document.getElementById('sitemaps-container');
                        
                        if (btn && container) {
                            btn.addEventListener('click', function(e) {
                                e.preventDefault();
                                
                                // Calculate counter from existing rows (dynamic!)
                                var existingRows = container.querySelectorAll('.ssr-sitemap-row');
                                var counter = existingRows.length;
                                
                                console.log('Adding sitemap row...', counter);
                                
                                var newRow = document.createElement('div');
                                newRow.className = 'ssr-sitemap-row';
                                newRow.setAttribute('data-index', counter);
                                newRow.innerHTML = `
                                    <div class="ssr-input-group">
                                        <span class="ssr-input-icon">🌐</span>
                                        <input 
                                            type="url" 
                                            class="ssr-input sitemap-url" 
                                            placeholder="https://shop${counter > 1 ? counter : ''}.com/sitemap.xml"
                                            data-index="${counter}"
                                        >
                                        <span class="ssr-sitemap-label">Sitemap ${counter + 1}</span>
                                        <button type="button" class="ssr-sitemap-remove" data-index="${counter}">❌</button>
                                    </div>
                                `;
                                
                                container.appendChild(newRow);
                            });
                        }
                        
                        // Remove sitemap handler (event delegation)
                        if (container) {
                            container.addEventListener('click', function(e) {
                                if (e.target.classList.contains('ssr-sitemap-remove') || 
                                    e.target.closest('.ssr-sitemap-remove')) {
                                    var row = e.target.closest('.ssr-sitemap-row');
                                    if (row && row.getAttribute('data-index') !== '0') {
                                        row.style.opacity = '0';
                                        row.style.transition = 'opacity 0.3s';
                                        setTimeout(function() {
                                            row.remove();
                                        }, 300);
                                    }
                                }
                            });
                        }
                    });
                    </script>
                    
                    <div class="ssr-help-text">
                        💡 Mehrere Sitemaps ermöglichen Cross-Domain Redirects (z.B. .de → .com)<br>
                        🔄 Sub-Sitemaps werden automatisch geladen
                    </div>
                </div>
                
                <button class="ssr-btn ssr-btn-primary ssr-btn-large" id="btn-match">
                    <span class="ssr-btn-icon">🎯</span>
                    Jetzt matchen
                </button>
                
                <!-- Progress Bar -->
                <div class="ssr-progress-container" id="progress-container" style="display: none;">
                    <div class="ssr-progress-bar">
                        <div class="ssr-progress-fill" id="progress-fill">
                            <span class="ssr-progress-text" id="progress-text">0%</span>
                        </div>
                    </div>
                    <div class="ssr-progress-status" id="progress-status">Katalog wird geladen...</div>
                </div>
                
                <!-- Locale Statistics -->
                <div class="ssr-info-box ssr-info-success" id="locale-info" style="display: none;">
                    <div class="ssr-info-icon">🌍</div>
                    <div class="ssr-info-content">
                        <strong id="locale-count-text">0 Sprachen erkannt</strong>
                        <div id="locale-list"></div>
                    </div>
                </div>
                
                <div class="ssr-info-box">
                    <div class="ssr-info-icon">ℹ️</div>
                    <div class="ssr-info-content">
                        <strong>Was passiert beim Matching?</strong>
                        <ul>
                            <li>✓ Lädt deinen neuen Shop-Katalog</li>
                            <li>✓ Findet beste Matches für jede URL</li>
                            <li>✓ Erstellt intelligente Fallbacks</li>
                            <li>✓ 100% Match-Garantie!</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Step 3: Export -->
        <div class="ssr-step" data-step="3">
            <div class="ssr-step-header">
                <div class="ssr-step-number">3</div>
                <div class="ssr-step-title">Shopify CSV exportieren</div>
            </div>
            
            <div class="ssr-step-content">
                <div class="ssr-export-info" id="export-info" style="display: none;">
                    <div class="ssr-export-icon">📥</div>
                    <div class="ssr-export-text">
                        <strong id="export-count">0</strong> Redirects bereit zum Export
                        <div class="ssr-export-quality" id="export-quality"></div>
                    </div>
                </div>
                
                <button class="ssr-btn ssr-btn-success ssr-btn-large" id="btn-export" disabled>
                    <span class="ssr-btn-icon">💾</span>
                    CSV herunterladen
                </button>
                
                <div class="ssr-info-box ssr-info-success" style="display: none;" id="export-success">
                    <div class="ssr-info-icon">✅</div>
                    <div class="ssr-info-content">
                        <strong>Download erfolgreich!</strong>
                        <p>Importiere die CSV in Shopify:</p>
                        <ol>
                            <li>Shopify Admin öffnen</li>
                            <li>Navigation → URL Redirects → Import</li>
                            <li>Deine CSV-Datei hochladen</li>
                            <li>Fertig! 🎉</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
        
    </div>
    
    <!-- Preview Section -->
    <?php if ($atts['show_preview'] === 'yes'): ?>
    <div class="ssr-preview-section" id="preview-section" style="display: none;">
        <div class="ssr-preview-header">
            <h3>
                📋 Vorschau 
                <span class="ssr-preview-count" id="preview-count-text">(erste <?php echo $atts['preview_limit']; ?> Einträge)</span>
            </h3>
            
            <!-- Filter -->
            <div class="ssr-preview-filter">
                <label for="preview-filter-select">Filter:</label>
                <select id="preview-filter-select" class="ssr-filter-select">
                    <option value="all">Alle anzeigen</option>
                    <option value="excellent">🟢 Nur Perfekt (90+)</option>
                    <option value="good">🟢 Nur Gut (70-89)</option>
                    <option value="fair">🟡 Nur OK (50-69)</option>
                    <option value="fallback">🟡 Nur Fallback (<50)</option>
                </select>
            </div>
        </div>
        
        <div class="ssr-preview-table-wrap">
            <table class="ssr-preview-table">
                <thead>
                    <tr>
                        <th>Von (Alt)</th>
                        <th>Nach (Neu)</th>
                        <th>Score</th>
                    </tr>
                </thead>
                <tbody id="preview-tbody">
                    <!-- Filled by JS -->
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Messages -->
    <div class="ssr-messages" id="ssr-messages"></div>
    
    <!-- Loading Overlay -->
    <div class="ssr-loading-overlay" id="loading-overlay" style="display: none;">
        <div class="ssr-loading-content">
            <div class="ssr-loader"></div>
            <div class="ssr-loading-text">Wird verarbeitet...</div>
        </div>
    </div>
    
</div>
