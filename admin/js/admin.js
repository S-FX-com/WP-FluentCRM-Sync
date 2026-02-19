/* global fcrmWpSync, jQuery */
(function ($) {
    'use strict';

    var nonce   = fcrmWpSync.nonce;
    var ajaxUrl = fcrmWpSync.ajaxUrl;
    var i18n    = fcrmWpSync.i18n;

    // =========================================================================
    // Utility helpers
    // =========================================================================

    function showNotice($el, msg, type) {
        type = type || 'success';
        $el
            .removeClass('fcrm-notice-success fcrm-notice-error fcrm-notice-info')
            .addClass('fcrm-notice-' + type)
            .text(msg)
            .slideDown(200);
        if (type === 'success') {
            setTimeout(function () { $el.slideUp(400); }, 3000);
        }
    }

    function setBtn($btn, label, disabled) {
        $btn.prop('disabled', disabled !== false).text(label);
    }

    // =========================================================================
    // Field Mapping page
    // =========================================================================

    var $mappingTable = $('#fcrm-mapping-table');
    var $tbody        = $('#fcrm-mapping-rows');
    var $notice       = $('#fcrm-mapping-notice');
    var rowTemplate   = document.getElementById('fcrm-row-template');

    // --- Add row ---
    $('#fcrm-add-row').on('click', function () {
        if (!rowTemplate) { return; }
        var $empty = $tbody.find('.fcrm-empty-row');
        $empty.remove();

        var clone = document.importNode(rowTemplate.content, true);
        var newId = 'map_' + Math.random().toString(36).substr(2, 8);
        var $row  = $(clone).find('tr');

        // Give the row its new unique id
        $row.attr('data-id', newId);
        $row.find('select, input').each(function () {
            if ($(this).attr('name')) {
                $(this).attr('name', $(this).attr('name').replace('__TEMPLATE__', newId));
            }
        });

        $tbody.append($row);
        wireRow($row);
        $row.find('.fcrm-wp-field').trigger('change');
    });

    // --- Remove row ---
    $tbody.on('click', '.fcrm-remove-row', function () {
        if (!confirm(i18n.confirmDelete)) { return; }
        $(this).closest('tr').remove();
        if (!$tbody.find('.fcrm-mapping-row').length) {
            $tbody.append('<tr class="fcrm-empty-row"><td colspan="6">' +
                'No mappings yet. Click "Add Mapping Row" to begin.</td></tr>');
        }
    });

    // --- Auto-detect field type when both sides are selected ---
    function wireRow($row) {
        $row.find('.fcrm-wp-field, .fcrm-fcrm-field').on('change', function () {
            autoDetectType($row);
        });
        $row.find('.fcrm-field-type').on('change', function () {
            toggleDateFormatWrap($row);
        });
        autoDetectType($row);
    }

    function autoDetectType($row) {
        var wpType   = $row.find('.fcrm-wp-field   option:selected').data('type') || '';
        var fcrmType = $row.find('.fcrm-fcrm-field  option:selected').data('type') || '';

        // If either side declares a specific type, prefer it (FCRM > WP)
        var detected = fcrmType || wpType || 'text';
        var $typeSelect = $row.find('.fcrm-field-type');

        // Only override if we detected something meaningful
        if (detected && $typeSelect.val() !== detected) {
            if ($typeSelect.find('option[value="' + detected + '"]').length) {
                $typeSelect.val(detected);
            }
        }
        toggleDateFormatWrap($row);
    }

    function toggleDateFormatWrap($row) {
        var isDate = $row.find('.fcrm-field-type').val() === 'date';
        $row.find('.fcrm-date-format-wrap').toggle(isDate);
    }

    // Wire existing rows on page load
    $tbody.find('.fcrm-mapping-row').each(function () {
        wireRow($(this));
    });

    // --- Save mappings ---
    $('#fcrm-save-mappings').on('click', function () {
        var $btn     = $(this);
        var mappings = {};
        var hasError = false;

        $tbody.find('.fcrm-mapping-row').each(function () {
            var $row   = $(this);
            var rowId  = $row.data('id');
            var wpUid  = $row.find('.fcrm-wp-field').val();
            var fcrmUid = $row.find('.fcrm-fcrm-field').val();

            if (!wpUid || !fcrmUid) {
                $row.addClass('fcrm-row-error');
                hasError = true;
                return; // continue
            }
            $row.removeClass('fcrm-row-error');

            mappings[rowId] = {
                wp_uid:         wpUid,
                fcrm_uid:       fcrmUid,
                field_type:     $row.find('.fcrm-field-type').val(),
                sync_direction: $row.find('.fcrm-sync-direction').val(),
                enabled:        $row.find('.fcrm-enabled').is(':checked') ? 1 : 0,
                date_format_wp: $row.find('.fcrm-date-format-wp').val() || 'm/d/Y',
            };
        });

        if (hasError) {
            showNotice($notice, 'Please select both WP and FluentCRM fields for every row.', 'error');
            return;
        }

        setBtn($btn, i18n.saving, true);

        $.post(ajaxUrl, {
            action:   'fcrm_wp_sync_save_mappings',
            nonce:    nonce,
            mappings: mappings,
        })
        .done(function (resp) {
            if (resp.success) {
                showNotice($notice, i18n.saved + ' (' + (resp.data.count || 0) + ' mappings)');
            } else {
                showNotice($notice, i18n.error, 'error');
            }
        })
        .fail(function () {
            showNotice($notice, i18n.error, 'error');
        })
        .always(function () {
            setBtn($btn, 'Save Mappings', false);
        });
    });

    // =========================================================================
    // Sync & Settings page
    // =========================================================================

    // --- Bulk Sync ---
    function runBulkSync(direction) {
        var $progressWrap = $('#fcrm-bulk-progress');
        var $bar          = $('#fcrm-progress-bar');
        var $status       = $('#fcrm-bulk-status');

        $progressWrap.show();
        $bar.css('width', '0%');
        $status.text(i18n.syncing);

        var perPage = 50;
        var offset  = 0;
        var total   = 0;
        var synced  = 0;
        var errList = [];

        function doPage() {
            $.post(ajaxUrl, {
                action:    'fcrm_wp_sync_bulk_sync',
                nonce:     nonce,
                direction: direction,
                per_page:  perPage,
                offset:    offset,
            })
            .done(function (resp) {
                if (!resp.success) {
                    $status.text(i18n.error);
                    return;
                }
                var d = resp.data;
                total  = d.total_users || total;
                synced += d.success || 0;
                errList = errList.concat(d.errors || []);
                offset  = d.next_offset;

                var pct = total > 0 ? Math.min(100, Math.round(synced / total * 100)) : 100;
                $bar.css('width', pct + '%');
                $status.text(i18n.syncing + ' ' + synced + ' / ' + total);

                if (d.has_more) {
                    doPage();
                } else {
                    var msg = i18n.syncDone + ' ' + synced + ' records synced.';
                    if (errList.length) {
                        msg += ' ' + errList.length + ' error(s).';
                    }
                    $status.text(msg);
                    $bar.css('width', '100%');
                }
            })
            .fail(function () {
                $status.text(i18n.error);
            });
        }

        doPage();
    }

    $('#fcrm-bulk-wp-to-fcrm').on('click', function () {
        runBulkSync('wp_to_fcrm');
    });
    $('#fcrm-bulk-fcrm-to-wp').on('click', function () {
        runBulkSync('fcrm_to_wp');
    });

    // --- Settings form ---
    $('#fcrm-settings-form').on('submit', function (e) {
        e.preventDefault();
        var $btn    = $(this).find('[type="submit"]');
        var $notice = $('#fcrm-settings-notice');
        var data    = { action: 'fcrm_wp_sync_save_settings', nonce: nonce };

        $(this).find('input[type="checkbox"]').each(function () {
            data[$(this).attr('name')] = $(this).is(':checked') ? 1 : 0;
        });

        setBtn($btn, i18n.saving, true);

        $.post(ajaxUrl, data)
        .done(function (resp) {
            showNotice($notice, resp.success ? i18n.saved : i18n.error, resp.success ? 'success' : 'error');
        })
        .fail(function () {
            showNotice($notice, i18n.error, 'error');
        })
        .always(function () {
            setBtn($btn, 'Save Settings', false);
        });
    });

    // =========================================================================
    // Mismatch Resolver page
    // =========================================================================

    var mismatchPage = 1;
    var mismatchTotal = 0;
    var mismatchPages = 0;
    var $resolveNotice = $('#fcrm-resolve-notice');

    $('#fcrm-scan-mismatches').on('click', function () {
        mismatchPage = 1;
        loadMismatches();
    });

    $('#fcrm-prev-page').on('click', function () {
        if (mismatchPage > 1) {
            mismatchPage--;
            loadMismatches();
        }
    });

    $('#fcrm-next-page').on('click', function () {
        if (mismatchPage < mismatchPages) {
            mismatchPage++;
            loadMismatches();
        }
    });

    function loadMismatches() {
        var $container  = $('#fcrm-mismatches-container');
        var $status     = $('#fcrm-scan-status');
        var $pagination = $('#fcrm-mismatch-pagination');

        $resolveNotice.hide();

        $container.html('<p>' + i18n.loading + '</p>');
        $status.text(i18n.loading);

        $.get(ajaxUrl, {
            action:   'fcrm_wp_sync_get_mismatches',
            nonce:    nonce,
            page:     mismatchPage,
            per_page: 10,
        })
        .done(function (resp) {
            if (!resp.success) {
                $container.html('<p class="fcrm-error">' + i18n.error + '</p>');
                return;
            }
            var d = resp.data;
            mismatchTotal = d.total || 0;
            mismatchPages = d.pages || 1;

            $status.text(mismatchTotal + ' user(s) with mismatches found.');

            if (!d.items || !d.items.length) {
                $container.html('<p class="fcrm-success">All records are in sync!</p>');
                $pagination.hide();
                return;
            }

            $container.html(renderMismatches(d.items));
            $pagination.toggle(mismatchPages > 1);
            $('#fcrm-page-info').text('Page ' + mismatchPage + ' of ' + mismatchPages);
            $('#fcrm-prev-page').prop('disabled', mismatchPage <= 1);
            $('#fcrm-next-page').prop('disabled', mismatchPage >= mismatchPages);

            // Wire resolve buttons
            $container.find('.fcrm-resolve-btn').on('click', handleResolve);
            $container.find('.fcrm-resolve-all-btn').on('click', handleResolveAll);
        })
        .fail(function () {
            $container.html('<p class="fcrm-error">' + i18n.error + '</p>');
        });
    }

    function renderMismatches(items) {
        var html = '<div class="fcrm-mismatch-list">';

        items.forEach(function (record) {
            html += '<div class="fcrm-mismatch-record" data-user-id="' + record.user_id + '">';
            html += '<div class="fcrm-mismatch-header">';
            html += '<strong>' + escHtml(record.user_display) + '</strong>';
            html += ' <span class="fcrm-mismatch-email">&lt;' + escHtml(record.user_email) + '&gt;</span>';
            html += ' <span class="fcrm-mismatch-count">' + record.fields.length + ' mismatch(es)</span>';
            html += '<span class="fcrm-resolve-all-wrap">';
            html += '<button class="button fcrm-resolve-all-btn" data-user-id="' + record.user_id + '" data-direction="use_wp">Use all WP</button> ';
            html += '<button class="button fcrm-resolve-all-btn" data-user-id="' + record.user_id + '" data-direction="use_fcrm">Use all FCRM</button>';
            html += '</span>';
            html += '</div>'; // .header

            html += '<table class="fcrm-mismatch-fields widefat">';
            html += '<thead><tr><th>Field</th><th>WP Value</th><th>FluentCRM Value</th><th>Action</th></tr></thead>';
            html += '<tbody>';

            record.fields.forEach(function (field) {
                html += '<tr class="fcrm-mismatch-field-row" data-mapping-id="' + escHtml(field.mapping_id) + '">';
                html += '<td><strong>' + escHtml(field.field_label) + '</strong><br><small>' + escHtml(field.field_type) + '</small></td>';
                html += '<td class="fcrm-val-wp">' + escHtml(field.wp_value) + '</td>';
                html += '<td class="fcrm-val-fcrm">' + escHtml(field.fcrm_value) + '</td>';
                html += '<td>';
                html += '<button class="button button-small fcrm-resolve-btn" '
                    + 'data-user-id="' + record.user_id + '" '
                    + 'data-mapping-id="' + escHtml(field.mapping_id) + '" '
                    + 'data-direction="use_wp">Use WP</button> ';
                html += '<button class="button button-small fcrm-resolve-btn" '
                    + 'data-user-id="' + record.user_id + '" '
                    + 'data-mapping-id="' + escHtml(field.mapping_id) + '" '
                    + 'data-direction="use_fcrm">Use FCRM</button>';
                html += '</td>';
                html += '</tr>';
            });

            html += '</tbody></table>';
            html += '</div>'; // .record
        });

        html += '</div>';
        return html;
    }

    function handleResolve() {
        var $btn       = $(this);
        var userId     = $btn.data('user-id');
        var mappingId  = $btn.data('mapping-id');
        var direction  = $btn.data('direction');

        $btn.prop('disabled', true).text(i18n.resolving);

        $.post(ajaxUrl, {
            action:     'fcrm_wp_sync_resolve_mismatch',
            nonce:      nonce,
            user_id:    userId,
            mapping_id: mappingId,
            direction:  direction,
            scope:      'field',
        })
        .done(function (resp) {
            if (resp.success) {
                $btn.closest('tr').addClass('fcrm-resolved').find('td:last-child').html(
                    '<span class="fcrm-resolved-badge">' + i18n.resolved + '</span>'
                );
            } else {
                $btn.prop('disabled', false).text(direction === 'use_wp' ? 'Use WP' : 'Use FCRM');
                var msg = (resp.data && resp.data.message) ? resp.data.message : i18n.error;
                showNotice($resolveNotice, msg, 'error');
            }
        })
        .fail(function () {
            $btn.prop('disabled', false).text(direction === 'use_wp' ? 'Use WP' : 'Use FCRM');
            showNotice($resolveNotice, i18n.error, 'error');
        });
    }

    function handleResolveAll() {
        var $btn      = $(this);
        var userId    = $btn.data('user-id');
        var direction = $btn.data('direction');
        var $record   = $btn.closest('.fcrm-mismatch-record');

        $btn.prop('disabled', true).text(i18n.resolving);

        $.post(ajaxUrl, {
            action:    'fcrm_wp_sync_resolve_mismatch',
            nonce:     nonce,
            user_id:   userId,
            direction: direction,
            scope:     'all',
        })
        .done(function (resp) {
            if (resp.success) {
                $record.find('tr').addClass('fcrm-resolved');
                $record.find('.fcrm-resolve-btn, .fcrm-resolve-all-btn').prop('disabled', true);
                $record.find('tbody tr').each(function () {
                    $(this).find('td:last-child').html(
                        '<span class="fcrm-resolved-badge">' + i18n.resolved + '</span>'
                    );
                });
            } else {
                $btn.prop('disabled', false).text(direction === 'use_wp' ? 'Use all WP' : 'Use all FCRM');
                var msg = (resp.data && resp.data.message) ? resp.data.message : i18n.error;
                showNotice($resolveNotice, msg, 'error');
            }
        })
        .fail(function () {
            $btn.prop('disabled', false).text(direction === 'use_wp' ? 'Use all WP' : 'Use all FCRM');
            showNotice($resolveNotice, i18n.error, 'error');
        });
    }

    // =========================================================================
    // PMP Integration page
    // =========================================================================

    $('#fcrm-save-pmp-settings').on('click', function () {
        var $btn    = $(this);
        var $notice = $('#fcrm-pmp-notice');

        // Collect sync toggle.
        var syncOnChange = $('#fcrm-pmp-sync-on-change').is(':checked') ? 1 : 0;

        // Collect tag mappings: { level_id: [tag_id, ...] }
        var tagMappings = {};
        $('.fcrm-pmp-tag-select').each(function () {
            var levelId = $(this).data('level-id');
            var selected = $(this).val() || [];
            tagMappings[levelId] = selected;
        });

        setBtn($btn, i18n.saving, true);

        $.post(ajaxUrl, {
            action:              'fcrm_wp_sync_save_pmp_settings',
            nonce:               nonce,
            sync_on_pmp_change:  syncOnChange,
            pmp_tag_mappings:    tagMappings,
        })
        .done(function (resp) {
            showNotice($notice, resp.success ? i18n.saved : i18n.error, resp.success ? 'success' : 'error');
        })
        .fail(function () {
            showNotice($notice, i18n.error, 'error');
        })
        .always(function () {
            setBtn($btn, 'Save PMP Settings', false);
        });
    });

    // =========================================================================
    // Utilities
    // =========================================================================

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

}(jQuery));
