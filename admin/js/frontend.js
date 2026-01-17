jQuery(document).ready(function($) {
    
    // Store all redirects for filtering
    let allRedirects = [];
    
    // Multi-Sitemap functionality is now handled inline in the HTML template
    // See admin/views/frontend-tool.php for vanilla JS implementation
    
    // === Tab Switching ===
    $('.ssr-tab-btn').on('click', function() {
        const tab = $(this).data('tab');
        
        $('.ssr-tab-btn').removeClass('active');
        $(this).addClass('active');
        
        $('.ssr-tab-panel').removeClass('active');
        $('#tab-' + tab).addClass('active');
    });
    
    // === Drag & Drop for File Upload ===
    const dropZone = $('#drop-zone');
    
    dropZone.on('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).addClass('ssr-drag-over');
    });
    
    dropZone.on('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('ssr-drag-over');
    });
    
    dropZone.on('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('ssr-drag-over');
        
        const files = e.originalEvent.dataTransfer.files;
        if (files.length > 0) {
            const file = files[0];
            
            // Validate file
            if (!validateFile(file)) {
                return;
            }
            
            // Set file to input
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            document.getElementById('urls-file').files = dataTransfer.files;
            
            $('.ssr-file-text').text(file.name);
            showMessage('📁 Datei bereit: ' + file.name, 'success');
        }
    });
    
    // === File Validation ===
    function validateFile(file) {
        // Check file type
        const validTypes = ['.txt', '.csv', 'text/plain', 'text/csv'];
        const fileExt = '.' + file.name.split('.').pop().toLowerCase();
        
        if (!validTypes.includes(fileExt) && !validTypes.includes(file.type)) {
            showMessage('❌ Nur .txt oder .csv Dateien erlaubt!', 'error');
            return false;
        }
        
        // Check file size (max 5MB)
        const maxSize = 5 * 1024 * 1024;
        if (file.size > maxSize) {
            showMessage('❌ Datei zu groß! Maximum: 5MB', 'error');
            return false;
        }
        
        return true;
    }
    
    // Store error URLs
    let errorUrls = [];
    
    // === URL Validation ===
    function validateUrls(urls) {
        const validUrls = [];
        const invalidUrls = [];
        
        urls.forEach((url, index) => {
            url = url.trim();
            if (!url) return;
            
            let reason = '';
            
            // Check if valid URL
            if (url.startsWith('http://') || url.startsWith('https://')) {
                validUrls.push(url);
            } else if (url.startsWith('www.')) {
                reason = 'Kein Protokoll (http:// oder https://)';
                invalidUrls.push({url, reason});
            } else if (url.startsWith('/')) {
                reason = 'Relative URL (benötigt vollständige URL mit Domain)';
                invalidUrls.push({url, reason});
            } else {
                reason = 'Ungültiges Format';
                invalidUrls.push({url, reason});
            }
        });
        
        // Update error URLs
        if (invalidUrls.length > 0) {
            errorUrls = errorUrls.concat(invalidUrls);
            updateErrorTab();
            showMessage(`⚠️ ${invalidUrls.length} ungültige URLs übersprungen`, 'error');
        }
        
        return validUrls;
    }
    
    // === Update Error Tab ===
    function updateErrorTab() {
        if (errorUrls.length > 0) {
            // Show tab
            $('#tab-btn-errors').show();
            $('#error-count-badge').text(errorUrls.length);
            
            // Update list
            const errorList = $('#error-urls-list');
            errorList.empty();
            
            errorUrls.forEach((error, index) => {
                const errorItem = `
                    <div class="ssr-error-item">
                        <div class="ssr-error-url">
                            <span class="ssr-error-icon">❌</span>
                            <code>${escapeHtml(error.url)}</code>
                        </div>
                        <div class="ssr-error-reason">${error.reason}</div>
                    </div>
                `;
                errorList.append(errorItem);
            });
        } else {
            $('#tab-btn-errors').hide();
        }
    }
    
    // === Clear Errors ===
    $('#btn-clear-errors').on('click', function() {
        errorUrls = [];
        updateErrorTab();
        showMessage('✅ Fehler-Liste geleert', 'success');
    });
    
    // === Copy Errors ===
    $('#btn-copy-errors').on('click', function() {
        const errorText = errorUrls.map(e => `${e.url} - ${e.reason}`).join('\n');
        copyToClipboard(errorText);
    });
    
    // === Preview Filter ===
    $('#preview-filter-select').on('change', function() {
        const filter = $(this).val();
        filterPreview(filter);
    });
    
    function filterPreview(filter) {
        if (allRedirects.length === 0) return;
        
        let filtered = allRedirects;
        
        if (filter === 'excellent') {
            filtered = allRedirects.filter(r => r.score >= 90);
        } else if (filter === 'good') {
            filtered = allRedirects.filter(r => r.score >= 70 && r.score < 90);
        } else if (filter === 'fair') {
            filtered = allRedirects.filter(r => r.score >= 50 && r.score < 70);
        } else if (filter === 'fallback') {
            filtered = allRedirects.filter(r => r.score < 50);
        }
        
        updatePreviewTable(filtered);
        
        // Update count
        $('#preview-count-text').text(`(${filtered.length} von ${allRedirects.length} Einträgen)`);
    }
    
    // === Add URLs from Text ===
    $('#btn-add-text').on('click', function() {
        const urls = $('#urls-text').val();
        
        if (!urls.trim()) {
            showMessage('Bitte URLs eingeben', 'error');
            return;
        }
        
        // Validate URLs
        const urlArray = urls.split('\n');
        const validUrls = validateUrls(urlArray);
        
        if (validUrls.length === 0) {
            showMessage('❌ Keine gültigen URLs gefunden!', 'error');
            return;
        }
        
        // Chunked upload for large datasets
        if (validUrls.length > 100) {
            uploadUrlsInChunks(validUrls);
        } else {
            uploadUrls(validUrls);
        }
    });
    
    // === Upload URLs (single request) ===
    function uploadUrls(urls) {
        showLoading();
        
        $.post(ssrFrontend.ajaxurl, {
            action: 'ssr_frontend_add_urls',
            nonce: ssrFrontend.nonce,
            action_type: 'text',
            urls_text: urls.join('\n')
        }, function(response) {
            hideLoading();
            
            if (response.success) {
                showMessage(response.data.message, 'success');
                $('#urls-text').val('');
                refreshStats();
            } else {
                showMessage('Fehler: ' + (response.data.message || 'Unbekannter Fehler'), 'error');
            }
        }).fail(function(xhr, status, error) {
            hideLoading();
            showMessage('Upload-Fehler: ' + error + '. Versuche es mit weniger URLs.', 'error');
        });
    }
    
    // === Upload URLs in Chunks ===
    function uploadUrlsInChunks(urls) {
        const chunkSize = 100;
        const chunks = [];
        
        // Split into chunks
        for (let i = 0; i < urls.length; i += chunkSize) {
            chunks.push(urls.slice(i, i + chunkSize));
        }
        
        let uploaded = 0;
        const total = urls.length;
        
        showMessage(`📦 ${total} URLs werden in ${chunks.length} Paketen hochgeladen...`, 'info');
        
        // Upload chunks sequentially
        function uploadNextChunk(index) {
            if (index >= chunks.length) {
                hideLoading();
                showMessage(`✅ ${total} URLs erfolgreich hochgeladen!`, 'success');
                $('#urls-text').val('');
                refreshStats();
                return;
            }
            
            const chunk = chunks[index];
            const progress = Math.round(((index + 1) / chunks.length) * 100);
            
            showLoading(`Uploade Paket ${index + 1}/${chunks.length} (${progress}%)...`);
            
            $.post(ssrFrontend.ajaxurl, {
                action: 'ssr_frontend_add_urls',
                nonce: ssrFrontend.nonce,
                action_type: 'text',
                urls_text: chunk.join('\n')
            }, function(response) {
                if (response.success) {
                    uploaded += chunk.length;
                    // Upload next chunk
                    uploadNextChunk(index + 1);
                } else {
                    hideLoading();
                    showMessage(`Fehler bei Paket ${index + 1}: ` + response.data.message, 'error');
                }
            }).fail(function(xhr, status, error) {
                hideLoading();
                showMessage(`Upload-Fehler bei Paket ${index + 1}: ${error}`, 'error');
            });
        }
        
        // Start upload
        uploadNextChunk(0);
    }
    
    // === Add URLs from File ===
    $('#btn-add-file').on('click', function() {
        const fileInput = document.getElementById('urls-file');
        
        if (!fileInput.files.length) {
            showMessage('Bitte Datei auswählen', 'error');
            return;
        }
        
        const formData = new FormData();
        formData.append('action', 'ssr_frontend_add_urls');
        formData.append('nonce', ssrFrontend.nonce);
        formData.append('action_type', 'file');
        formData.append('urls_file', fileInput.files[0]);
        
        showLoading();
        
        $.ajax({
            url: ssrFrontend.ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                hideLoading();
                
                if (response.success) {
                    showMessage(response.data.message, 'success');
                    fileInput.value = '';
                    $('.ssr-file-text').text('Datei auswählen');
                    refreshStats();
                } else {
                    showMessage('Fehler: ' + (response.data.message || 'Unbekannter Fehler'), 'error');
                }
            },
            error: function(xhr, status, error) {
                hideLoading();
                let errorMsg = 'Fehler beim Hochladen';
                
                if (xhr.status === 413) {
                    errorMsg = 'Datei zu groß! Bitte in kleinere Dateien aufteilen oder Text-Upload nutzen.';
                } else if (xhr.status === 500) {
                    errorMsg = 'Server-Fehler. Bitte weniger URLs pro Datei oder Text-Upload nutzen.';
                } else if (error) {
                    errorMsg += ': ' + error;
                }
                
                showMessage(errorMsg, 'error');
            }
        });
    });
    
    // === File Input Label Update ===
    $('#urls-file').on('change', function() {
        const fileName = this.files[0]?.name || 'Datei auswählen';
        $('.ssr-file-text').text(fileName);
    });
    
    // === Clear All ===
    $('#btn-clear').on('click', function() {
        if (!confirm('Wirklich alle URLs löschen? Diese Aktion kann nicht rückgängig gemacht werden.')) {
            return;
        }
        
        showLoading();
        
        $.post(ssrFrontend.ajaxurl, {
            action: 'ssr_frontend_clear',
            nonce: ssrFrontend.nonce
        }, function(response) {
            hideLoading();
            
            if (response.success) {
                showMessage(response.data.message, 'success');
                $('#urls-text').val('');
                refreshStats();
            } else {
                showMessage('Fehler beim Löschen', 'error');
            }
        }).fail(function() {
            hideLoading();
            showMessage('Fehler beim Löschen', 'error');
        });
    });
    
    // === Matching ===
    $('#btn-match').on('click', function() {
        // Collect all sitemap URLs (works with dynamically added inputs)
        const sitemapUrls = [];
        
        // Use vanilla JS to be sure we get all inputs
        document.querySelectorAll('.sitemap-url').forEach(function(input) {
            const url = input.value ? input.value.trim() : '';
            if (url) {
                sitemapUrls.push(url);
            }
        });
        
        console.log('Collected sitemap URLs:', sitemapUrls); // Debug
        
        if (sitemapUrls.length === 0) {
            showMessage('Bitte mindestens eine Sitemap-URL eingeben', 'error');
            return;
        }
        
        if (!confirm('Matching starten? Dies kann einige Minuten dauern, besonders bei großen Shops.')) {
            return;
        }
        
        // Show progress bar
        $('#progress-container').show();
        $('#btn-match').prop('disabled', true);
        
        // Simulate progress
        let progress = 0;
        const progressInterval = setInterval(function() {
            progress += Math.random() * 15;
            if (progress > 90) progress = 90;
            
            updateProgress(progress, `${sitemapUrls.length} Sitemap(s) werden geladen...`);
        }, 500);
        
        $.post(ssrFrontend.ajaxurl, {
            action: 'ssr_frontend_match',
            nonce: ssrFrontend.nonce,
            sitemap_urls: sitemapUrls // Array of URLs!
        }, function(response) {
            clearInterval(progressInterval);

            console.log('=== SSR MATCH RESPONSE ===', response);

            if (response.success) {
                console.log('SSR Match Debug:', {
                    matched: response.data.matched,
                    db_matched: response.data.debug_db_matched,
                    session: response.data.debug_session
                });
                updateProgress(100, '✅ Matching abgeschlossen!');
                
                setTimeout(function() {
                    $('#progress-container').hide();
                    $('#btn-match').prop('disabled', false);
                    showMessage(response.data.message, 'success');
                    refreshStats();
                    
                    // Show locale statistics if available
                    if (response.data.locale_stats && response.data.locale_stats.locale_count > 0) {
                        const stats = response.data.locale_stats;
                        const localeText = stats.locale_count === 1 ? 'Sprache' : 'Sprachen';
                        $('#locale-count-text').text(`${stats.locale_count} ${localeText} erkannt`);
                        $('#locale-list').html(`<div style="margin-top: 8px; font-family: monospace;">${stats.available_locales.join(', ')}</div>`);
                        $('#locale-info').slideDown();
                    } else {
                        $('#locale-info').hide();
                    }
                    
                    // Auto-Scroll zum CSV-Export nach 1 Sekunde
                    setTimeout(function() {
                        $('html, body').animate({
                            scrollTop: $('.ssr-step[data-step="3"]').offset().top - 100
                        }, 800);
                    }, 1000);
                }, 1000);
            } else {
                clearInterval(progressInterval);
                $('#progress-container').hide();
                $('#btn-match').prop('disabled', false);
                showMessage('Fehler: ' + (response.data.message || 'Matching fehlgeschlagen'), 'error');
            }
        }).fail(function() {
            clearInterval(progressInterval);
            $('#progress-container').hide();
            $('#btn-match').prop('disabled', false);
            showMessage('Fehler beim Matching. Prüfe die Sitemap-URLs.', 'error');
        });
    });
    
    // === Update Progress ===
    function updateProgress(percent, status) {
        percent = Math.min(100, Math.max(0, percent));
        $('#progress-fill').css('width', percent + '%');
        $('#progress-text').text(Math.round(percent) + '%');
        $('#progress-status').text(status);
    }
    
    // === Export ===
    $('#btn-export').on('click', function() {
        showLoading('CSV wird erstellt...');
        
        $.post(ssrFrontend.ajaxurl, {
            action: 'ssr_frontend_export',
            nonce: ssrFrontend.nonce
        }, function(response) {
            hideLoading();
            
            if (response.success) {
                // Show success message
                $('#export-success').slideDown();
                
                // Trigger download
                window.location.href = response.data.file_url;
                
                showMessage('CSV erfolgreich erstellt! Download startet...', 'success');
                
                // Hide success message after 10 seconds
                setTimeout(function() {
                    $('#export-success').slideUp();
                }, 10000);
            } else {
                showMessage('Fehler: ' + (response.data.message || 'Export fehlgeschlagen'), 'error');
            }
        }).fail(function() {
            hideLoading();
            showMessage('Fehler beim Export', 'error');
        });
    });
    
    // === Refresh Stats ===
    function refreshStats() {
        $.post(ssrFrontend.ajaxurl, {
            action: 'ssr_frontend_get_stats',
            nonce: ssrFrontend.nonce,
            preview_limit: 1000
        }, function(response) {
            console.log('=== SSR STATS RESPONSE ===', response);

            if (response.success) {
                const data = response.data;

                console.log('SSR Stats:', {
                    total: data.total,
                    matched: data.matched,
                    excellent: data.excellent,
                    session: data.debug_session
                });

                // Update stats
                $('#stat-total').text(data.total);
                $('#stat-matched').text(data.matched);
                $('#stat-excellent').text(data.excellent);
                $('#stat-fallback').text(data.fallback);

                // Show stats bar if we have data
                if (data.total > 0) {
                    $('#ssr-stats-bar').slideDown();
                }

                // Update export button
                if (data.matched > 0) {
                    console.log('SSR: Enabling export button (matched=' + data.matched + ')');
                    $('#btn-export').prop('disabled', false);
                    $('#export-info').show();
                    $('#export-count').text(data.matched);

                    // Quality breakdown
                    const qualityText = `
                        🟢 ${data.excellent} Perfekt |
                        🟢 ${data.good} Gut |
                        🟡 ${data.fair} OK |
                        🟡 ${data.fallback} Fallback
                    `;
                    $('#export-quality').text(qualityText);
                } else {
                    console.log('SSR: Disabling export button (matched=0)');
                    $('#btn-export').prop('disabled', true);
                    $('#export-info').hide();
                }

                // Update preview table
                if (data.preview && data.preview.length > 0) {
                    // Store all redirects for filtering
                    allRedirects = data.preview;
                    updatePreviewTable(data.preview);
                    $('#preview-section').slideDown();
                } else {
                    $('#preview-section').slideUp();
                }
            }
        });
    }
    
    // === Update Preview Table ===
    function updatePreviewTable(redirects) {
        const tbody = $('#preview-tbody');
        tbody.empty();
        
        // DEBUG: Log first URL to console
        if (redirects.length > 0) {
            console.log('=== REDIRECT DEBUG ===');
            console.log('First redirect old_url:', redirects[0].old_url);
            console.log('First redirect new_url:', redirects[0].new_url);
            console.log('URL length old:', redirects[0].old_url.length);
            console.log('URL length new:', redirects[0].new_url.length);
        }
        
        redirects.forEach(function(redirect) {
            const scoreClass = getScoreClass(redirect.score);
            const row = `
                <tr>
                    <td>
                        <code class="ssr-copyable" data-url="${escapeHtml(redirect.old_url)}" title="Klicken zum Kopieren">
                            ${escapeHtml(redirect.old_url)}
                        </code>
                    </td>
                    <td>
                        <code class="ssr-copyable" data-url="${escapeHtml(redirect.new_url)}" title="Klicken zum Kopieren">
                            ${escapeHtml(redirect.new_url)}
                        </code>
                    </td>
                    <td><span class="ssr-preview-score ${scoreClass}">${redirect.score}</span></td>
                </tr>
            `;
            tbody.append(row);
        });
        
        // DEBUG: Log rendered HTML
        console.log('First code element text:', tbody.find('code').first().text());
    }
    
    // === Copy to Clipboard (Event Delegation) ===
    $(document).on('click', '.ssr-copyable', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const url = $(this).data('url');
        if (url) {
            copyToClipboard(url);
        }
    });
    
    // === Reset Button ===
    $('#btn-reset').on('click', function() {
        if (!confirm('🔄 Neues Projekt starten?\n\nAlle URLs und Matches werden gelöscht!\n\nBist du sicher?')) {
            return;
        }
        
        showLoading('Alle Daten werden gelöscht...');
        
        $.post(ssrFrontend.ajaxurl, {
            action: 'ssr_frontend_clear',
            nonce: ssrFrontend.nonce
        }, function(response) {
            hideLoading();
            
            if (response.success) {
                // Reset UI
                $('#urls-text').val('');
                $('#urls-file').val('');
                $('.ssr-file-text').text('Datei auswählen oder hier ablegen');
                
                // Reset Multi-Sitemaps
                $('#sitemaps-container').html(`
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
                `);
                
                // Reset stats
                $('#stat-total').text('0');
                $('#stat-matched').text('0');
                $('#stat-excellent').text('0');
                $('#stat-fallback').text('0');
                
                // Hide sections
                $('#ssr-stats-bar').slideUp();
                $('#preview-section').slideUp();
                $('#locale-info').slideUp();
                
                // Reset error URLs
                errorUrls = [];
                updateErrorTab();
                
                // Success message
                showMessage('✅ ' + response.data.message + ' - Bereit für neues Projekt!', 'success');
                
                // Scroll to top
                $('html, body').animate({ scrollTop: 0 }, 500);
            } else {
                showMessage('Fehler beim Zurücksetzen: ' + (response.data.message || 'Unbekannter Fehler'), 'error');
            }
        }).fail(function(xhr, status, error) {
            hideLoading();
            showMessage('Fehler beim Zurücksetzen: ' + error, 'error');
        });
    });
    
    // === Copy to Clipboard ===
    function copyToClipboard(text) {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function() {
                showMessage('✅ URL kopiert!', 'success');
            });
        } else {
            // Fallback
            const textarea = document.createElement('textarea');
            textarea.value = text;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            showMessage('✅ URL kopiert!', 'success');
        }
    }
    
    // === Get Score Class ===
    function getScoreClass(score) {
        if (score >= 90) return 'ssr-score-excellent';
        if (score >= 70) return 'ssr-score-good';
        if (score >= 50) return 'ssr-score-fair';
        return 'ssr-score-fallback';
    }
    
    // === Shorten URL ===
    // === Escape HTML ===
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
    
    // === Show Message ===
    function showMessage(text, type) {
        const icon = type === 'success' ? '✅' : '❌';
        const message = $(`
            <div class="ssr-message ssr-message-${type}">
                <div class="ssr-message-icon">${icon}</div>
                <div class="ssr-message-text">${text}</div>
            </div>
        `);
        
        $('#ssr-messages').append(message);
        
        // Auto-remove after 5 seconds
        setTimeout(function() {
            message.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }
    
    // === Show Loading ===
    function showLoading(text) {
        if (text) {
            $('#loading-overlay .ssr-loading-text').text(text);
        } else {
            $('#loading-overlay .ssr-loading-text').text('Wird verarbeitet...');
        }
        $('#loading-overlay').fadeIn(200);
    }
    
    // === Hide Loading ===
    function hideLoading() {
        $('#loading-overlay').fadeOut(200);
    }
    
    // === Initial Stats Load ===
    refreshStats();
    
});
