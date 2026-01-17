jQuery(document).ready(function($) {
    
    // Tab Switching
    $('.ssr-tab').on('click', function() {
        var tab = $(this).data('tab');
        
        $('.ssr-tab').removeClass('active');
        $(this).addClass('active');
        
        $('.ssr-tab-content').removeClass('active');
        $('#tab-' + tab).addClass('active');
    });
    
    // Add URLs from Text
    $('#btn-add-text').on('click', function() {
        var urls = $('#urls-text').val();
        
        if (!urls.trim()) {
            alert('Bitte URLs eingeben');
            return;
        }
        
        showLoading();
        
        $.post(ssrData.ajaxurl, {
            action: 'ssr_process',
            nonce: ssrData.nonce,
            action_type: 'add_urls',
            urls_text: urls
        }, function(response) {
            hideLoading();
            
            if (response.success) {
                alert(response.data.message);
                location.reload();
            } else {
                alert('Fehler: ' + (response.data || 'Unbekannter Fehler'));
            }
        });
    });
    
    // Add URLs from File
    $('#btn-add-file').on('click', function() {
        var fileInput = document.getElementById('urls-file');
        
        if (!fileInput.files.length) {
            alert('Bitte Datei auswählen');
            return;
        }
        
        var formData = new FormData();
        formData.append('action', 'ssr_process');
        formData.append('nonce', ssrData.nonce);
        formData.append('action_type', 'add_urls');
        formData.append('urls_file', fileInput.files[0]);
        
        showLoading();
        
        $.ajax({
            url: ssrData.ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                hideLoading();
                
                if (response.success) {
                    alert(response.data.message);
                    location.reload();
                } else {
                    alert('Fehler: ' + (response.data || 'Unbekannter Fehler'));
                }
            },
            error: function() {
                hideLoading();
                alert('Fehler beim Upload');
            }
        });
    });
    
    // Clear all
    $('#btn-clear').on('click', function() {
        if (!confirm('Wirklich alle URLs löschen?')) return;
        
        showLoading();
        
        $.post(ssrData.ajaxurl, {
            action: 'ssr_process',
            nonce: ssrData.nonce,
            action_type: 'clear'
        }, function(response) {
            hideLoading();
            
            if (response.success) {
                alert(response.data.message);
                location.reload();
            }
        });
    });
    
    // Match
    $('#btn-match').on('click', function() {
        var sitemapUrl = $('#sitemap-url').val();
        
        if (!sitemapUrl.trim()) {
            alert('Bitte Sitemap-URL eingeben');
            return;
        }
        
        if (!confirm('Matching starten? Dies kann einige Minuten dauern.')) return;
        
        showLoading();
        
        $.post(ssrData.ajaxurl, {
            action: 'ssr_process',
            nonce: ssrData.nonce,
            action_type: 'match',
            sitemap_url: sitemapUrl
        }, function(response) {
            hideLoading();
            
            if (response.success) {
                alert(response.data.message);
                location.reload();
            } else {
                alert('Fehler: ' + (response.data || 'Matching fehlgeschlagen'));
            }
        });
    });
    
    // Export
    $('#btn-export').on('click', function() {
        showLoading();
        
        $.post(ssrData.ajaxurl, {
            action: 'ssr_export',
            nonce: ssrData.nonce
        }, function(response) {
            hideLoading();
            
            if (response.success) {
                window.location.href = response.data.file_url;
            } else {
                alert('Fehler: ' + (response.data || 'Export fehlgeschlagen'));
            }
        });
    });
    
    // Loading helpers
    function showLoading() {
        $('#ssr-loading').show();
    }
    
    function hideLoading() {
        $('#ssr-loading').hide();
    }
});
