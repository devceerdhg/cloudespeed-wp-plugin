/**
 * Cloud E Speed — Admin Dashboard JavaScript Controller
 */

function switchCesTab(tabId) {
    jQuery('.ces-tab-btn').removeClass('active');
    jQuery('.ces-tab-btn[data-tab="' + tabId + '"]').addClass('active');
    jQuery('.ces-tab-pane').removeClass('active');
    jQuery('#' + tabId).addClass('active');
    window.location.hash = tabId.replace('tab-', '');
}

jQuery(document).ready(function($) {
    var ajaxurl = cloudespeedData.ajaxUrl;
    var dashNonce = cloudespeedData.dashNonce;
    var testNonce = cloudespeedData.testNonce;

    // Hash based tab activation
    if (window.location.hash) {
        var hashTab = 'tab-' + window.location.hash.replace('#', '');
        if ($('#' + hashTab).length) {
            switchCesTab(hashTab);
        }
    }

    // Tab click event
    $('.ces-tab-btn').on('click', function() {
        var tabId = $(this).data('tab');
        switchCesTab(tabId);
    });

    function showToast(msg, isError) {
        var $t = $('#ces-toast');
        $('#ces-toast-msg').text(msg);
        $('#ces-toast-icon').text(isError ? '✕' : '✓');
        $t.css('background', isError ? '#EF4444' : '#0F172A');
        $t.fadeIn(200);
        setTimeout(function() {
            $t.fadeOut(300);
        }, 4000);
    }

    // Purge All Cache
    $('#btn-purge-all, #btn-purge-all-top').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Purging Cache...');
        $.post(ajaxurl, {
            action: 'cloudespeed_ajax_purge_all',
            nonce: dashNonce
        }, function(res) {
            $btn.prop('disabled', false).text('Purge Entire Cache Now');
            $('#btn-purge-all-top').text('🚀 Purge All FastCGI Cache');
            if (res.success) {
                showToast(res.data.message, false);
            } else {
                showToast(res.data.message || 'Purge failed', true);
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Purge Entire Cache Now');
            $('#btn-purge-all-top').text('🚀 Purge All FastCGI Cache');
            showToast('Server request failed or timed out', true);
        });
    });

    // Purge Targeted URL
    $('#btn-purge-url').on('click', function() {
        var url = $('#ces-purge-url-input').val().trim();
        if (!url) {
            showToast('Please enter a valid URL path (e.g. /shop/)', true);
            return;
        }
        var $btn = $(this);
        $btn.prop('disabled', true).text('Purging...');
        $.post(ajaxurl, {
            action: 'cloudespeed_ajax_purge_url',
            nonce: dashNonce,
            url: url
        }, function(res) {
            $btn.prop('disabled', false).text('Purge URL');
            if (res.success) {
                showToast(res.data.message, false);
                $('#ces-purge-url-input').val('');
            } else {
                showToast(res.data.message || 'URL purge failed', true);
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Purge URL');
            showToast('Server request failed', true);
        });
    });

    // Flush Object Cache
    $('#btn-flush-obj').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Flushing...');
        $.post(ajaxurl, {
            action: 'cloudespeed_ajax_flush_object_cache',
            nonce: dashNonce
        }, function(res) {
            $btn.prop('disabled', false).text('Flush WP Memory Cache');
            if (res.success) {
                showToast(res.data.message, false);
            } else {
                showToast(res.data.message, true);
            }
        });
    });

    // Initial sync of topbar pill with live dashboard state
    var initialDevMode = $('#btn-toggle-devmode').data('active') === 1;
    if (initialDevMode) {
        $('#ab-cloudespeed-pill').removeClass('ces-pill-live').addClass('ces-pill-dev').text('CACHE PAUSED');
    } else {
        $('#ab-cloudespeed-pill').removeClass('ces-pill-dev').addClass('ces-pill-live').text('CACHE ON');
    }

    // Toggle Development Mode function
    function setDevModeState(targetState, $triggerBtn) {
        var $btn = $('#btn-toggle-devmode');
        var $quickBtn = $('#btn-quick-disable-devmode');

        $btn.prop('disabled', true).text('Switching Mode...');
        $quickBtn.prop('disabled', true).text('Turning Off...');

        $.post(ajaxurl, {
            action: 'cloudespeed_ajax_toggle_devmode',
            nonce: dashNonce,
            enable: targetState ? 'true' : 'false',
            hours: 3
        }, function(res) {
            $btn.prop('disabled', false);
            $quickBtn.prop('disabled', false).text('▶️ Resume Caching');
            if (res.success) {
                showToast(res.data.message, false);
                if (targetState) {
                    $btn.data('active', 1).removeClass('ces-btn-amber-light').addClass('ces-btn-blue').text('▶️ Resume Caching Now');
                    $('#badge-cache-engine').removeClass('ces-pill-emerald').addClass('ces-pill-amber').text('● Bypassed (Live Site)');
                    $('#badge-devmode-status').removeClass('ces-pill-emerald').addClass('ces-pill-amber').text('● Active (3h Bypass)');
                    $('#text-devmode-status').css('color', '#D97706').text('Live Site (Bypass)');
                    $('#ces-devmode-warning-banner').slideDown(250);
                    $('#header-devmode-pill').removeClass('ces-pill-emerald').addClass('ces-pill-amber').text('⚠️ Cache Paused (Dev Mode)').css('display', 'inline-flex').hide().fadeIn(200);
                    $('#ab-cloudespeed-pill').removeClass('ces-pill-live').addClass('ces-pill-dev').text('CACHE PAUSED');
                } else {
                    $btn.data('active', 0).removeClass('ces-btn-blue').addClass('ces-btn-amber-light').text('⏸️ Pause Cache / Dev Mode (3h)');
                    $('#badge-cache-engine').removeClass('ces-pill-amber').addClass('ces-pill-emerald').text('● Accelerating (Cached)');
                    $('#badge-devmode-status').removeClass('ces-pill-amber').addClass('ces-pill-emerald').text('● Inactive (Normal)');
                    $('#text-devmode-status').css('color', '#0F172A').text('Accelerated Cache');
                    $('#ces-devmode-warning-banner').slideUp(250);
                    $('#header-devmode-pill').removeClass('ces-pill-amber').addClass('ces-pill-emerald').text('● Cache Enabled').css('display', 'inline-flex');
                    $('#ab-cloudespeed-pill').removeClass('ces-pill-dev').addClass('ces-pill-live').text('CACHE ON');
                }
            } else {
                showToast(res.data.message || 'Failed to toggle Dev Mode', true);
                $btn.text($btn.data('active') === 1 ? '▶️ Resume Caching Now' : '⏸️ Pause Cache / Dev Mode (3h)');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text($btn.data('active') === 1 ? '▶️ Resume Caching Now' : '⏸️ Pause Cache / Dev Mode (3h)');
            $quickBtn.prop('disabled', false).text('▶️ Resume Caching');
            showToast('API request timed out', true);
        });
    }

    $('#btn-toggle-devmode').on('click', function() {
        var currentlyActive = $(this).data('active') === 1;
        setDevModeState(!currentlyActive, $(this));
    });

    $('#btn-quick-disable-devmode').on('click', function() {
        setDevModeState(false, $(this));
    });

    // Live Test Connection
    $('#btn-test-connection').on('click', function() {
        var $btn = $(this);
        var $status = $('#test-connection-status');
        var apiUrl = $('#cloudespeed_api_url').val();
        var apiKey = $('#cloudespeed_api_key').val();

        $btn.prop('disabled', true).text('Testing...');
        $status.html('<span style="color:#0284C7;">Connecting via Standard HTTPS...</span>');

        $.post(ajaxurl, {
            action: 'cloudespeed_test_connection',
            nonce: testNonce,
            api_url: apiUrl,
            api_key: apiKey
        }, function(res) {
            $btn.prop('disabled', false).text('🔍 Test API Connection');
            if (res.success) {
                $status.html('<span style="color:#059669;">✓ ' + res.data.message + '</span>');
            } else {
                $status.html('<span style="color:#DC2626;">✕ ' + (res.data ? res.data.message : 'Error') + '</span>');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('🔍 Test API Connection');
            $status.html('<span style="color:#DC2626;">✕ Server request timed out.</span>');
        });
    });
});
