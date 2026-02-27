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
        var $row   = $(this).closest('tr');
        var $vmRow = $row.next('.fcrm-value-map-row');
        $vmRow.remove(); // remove value-map sub-row if present
        $row.remove();
        if (!$tbody.find('.fcrm-mapping-row').length) {
            $tbody.append('<tr class="fcrm-empty-row"><td colspan="6">' +
                'No rows. Use "+ Add Row" to add a custom mapping.</td></tr>');
        }
    });

    // --- Auto-detect field type when both sides are selected ---
    function wireRow($row) {
        $row.find('.fcrm-wp-field').on('change', function () {
            autoDetectType($row);
            updateReadOnly($row);
            updateValueMapRow($row);
            updateFieldHints($row);
        });
        $row.find('.fcrm-fcrm-field').on('change', function () {
            autoDetectType($row);
            updateValueMapRow($row);
            updateFieldHints($row);
        });
        $row.find('.fcrm-field-type').on('change', function () {
            toggleDateFormatWrap($row);
            toggleValueMapRow($row);
        });
        autoDetectType($row);
        updateReadOnly($row);
        updateFieldHints($row);
        // Restore value-map sub-row for existing select-type rows
        if ($row.find('.fcrm-field-type').val() === 'select') {
            toggleValueMapRow($row);
        }
    }

    /**
     * Update the small hint text under each dropdown to show the field's
     * source system and type (e.g. "ACF: Date Picker", "FluentCRM: Text").
     * Reads data-source-label and data-type-label from the selected option.
     */
    function updateFieldHints($row) {
        var $fcrmOpt = $row.find('.fcrm-fcrm-field option:selected');
        var $wpOpt   = $row.find('.fcrm-wp-field option:selected');

        var fcrmSrc  = $fcrmOpt.data('source-label') || '';
        var fcrmType = $fcrmOpt.data('type-label')   || '';
        var wpSrc    = $wpOpt.data('source-label')   || '';
        var wpType   = $wpOpt.data('type-label')     || '';

        $row.find('.fcrm-fcrm-hint').text(fcrmType ? fcrmSrc + ': ' + fcrmType : '');
        $row.find('.fcrm-wp-hint').text(wpType ? wpSrc + ': ' + wpType : '');
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
        // Auto-fill the WP date format when type becomes 'date'.
        if ($typeSelect.val() === 'date') {
            var $fmtInput = $row.find('.fcrm-date-format-wp');
            // Prefer the ACF-provided format stored on the option, then fall
            // back to the site's date_format setting passed from PHP.
            var wpFmt = $row.find('.fcrm-wp-field option:selected').data('date-format') || '';
            if (!$fmtInput.val() || $fmtInput.val() === 'm/d/Y') {
                $fmtInput.val(wpFmt || (fcrmWpSync.dateFormat || 'm/d/Y'));
            }
        }
        toggleDateFormatWrap($row);
        toggleValueMapRow($row);
    }

    function toggleDateFormatWrap($row) {
        var isDate = $row.find('.fcrm-field-type').val() === 'date';
        $row.find('.fcrm-date-format-wrap').toggle(isDate);
    }

    /**
     * Lock the sync direction to WP→FluentCRM when the selected WP field is
     * read-only (e.g. User ID, PMPro dates, PMPro level fields).
     */
    function updateReadOnly($row) {
        var $wpOpt   = $row.find('.fcrm-wp-field option:selected');
        var isRO     = parseInt($wpOpt.data('readonly') || 0, 10) === 1;
        var $dirSel  = $row.find('.fcrm-sync-direction');
        var $hint    = $row.find('.fcrm-readonly-hint');

        $dirSel.prop('disabled', isRO);
        if (isRO) {
            $dirSel.val('wp_to_fcrm');
            if (!$hint.length) {
                $dirSel.after('<small class="fcrm-readonly-hint" style="display:block;color:#888">' +
                    'Read-only field: WP\u2192FluentCRM only</small>');
            }
        } else {
            $hint.remove();
        }
    }

    // -----------------------------------------------------------------------
    // Value-map sub-row (shown when field type is 'select' / 'radio')
    // -----------------------------------------------------------------------

    /**
     * Show or hide the value-map sub-row depending on the current type.
     */
    function toggleValueMapRow($row) {
        var isSelect = $row.find('.fcrm-field-type').val() === 'select';
        var $vmRow   = $row.next('.fcrm-value-map-row');

        if (isSelect) {
            if (!$vmRow.length) {
                $vmRow = buildValueMapRow($row);
                $row.after($vmRow);
            }
            $vmRow.show();
            populateValueMapRow($row, $vmRow);
        } else {
            $vmRow.hide();
        }
    }

    /** Alias called when fields change while value map is already visible. */
    function updateValueMapRow($row) {
        var $vmRow = $row.next('.fcrm-value-map-row');
        if ($vmRow.length && $vmRow.is(':visible')) {
            populateValueMapRow($row, $vmRow);
        }
    }

    /**
     * Create the empty value-map sub-row DOM element.
     */
    function buildValueMapRow($row) {
        var rowId  = $row.data('id');
        var $vmRow = $(
            '<tr class="fcrm-value-map-row">' +
              '<td colspan="6">' +
                '<div class="fcrm-value-map-container">' +
                  '<strong style="display:block;margin-bottom:6px">Value Mapping ' +
                    '<small style="font-weight:normal;color:#666">' +
                      '(optional – map WP option values to FluentCRM option values)' +
                    '</small>' +
                  '</strong>' +
                  '<table class="fcrm-value-map-table">' +
                    '<thead><tr>' +
                      '<th>WordPress Value</th>' +
                      '<th style="padding-left:16px">\u2192 FluentCRM Value</th>' +
                    '</tr></thead>' +
                    '<tbody class="fcrm-vm-tbody"></tbody>' +
                  '</table>' +
                '</div>' +
              '</td>' +
            '</tr>'
        );
        $vmRow.attr('data-parent-id', rowId);
        return $vmRow;
    }

    /**
     * Rebuild the value-map table rows from the current WP / FCRM field options
     * and the previously saved value_map JSON stored in the hidden input.
     */
    function populateValueMapRow($row, $vmRow) {
        var $tbody = $vmRow.find('.fcrm-vm-tbody');
        $tbody.empty();

        // Retrieve WP field options
        var wpOptionsRaw = $row.find('.fcrm-wp-field option:selected').data('options') || '[]';
        var wpOptions    = [];
        try { wpOptions = JSON.parse(typeof wpOptionsRaw === 'string' ? wpOptionsRaw : JSON.stringify(wpOptionsRaw)); } catch (e) {}

        // Retrieve FluentCRM field options
        var fcrmOptionsRaw = $row.find('.fcrm-fcrm-field option:selected').data('options') || '[]';
        var fcrmOptions    = [];
        try { fcrmOptions = JSON.parse(typeof fcrmOptionsRaw === 'string' ? fcrmOptionsRaw : JSON.stringify(fcrmOptionsRaw)); } catch (e) {}

        // Load previously saved map
        var savedMapRaw = $row.find('.fcrm-value-map-json').val() || '{}';
        var savedMap    = {};
        try { savedMap = JSON.parse(savedMapRaw); } catch (e) {}

        if (!wpOptions.length && !fcrmOptions.length) {
            $tbody.append(
                '<tr><td colspan="2" style="color:#888;font-style:italic">' +
                'No option lists detected. Enter values manually or choose fields with defined options.' +
                '</td></tr>'
            );
            return;
        }

        // Use WP options as the source rows when available; otherwise use FCRM options.
        var sourceOptions = wpOptions.length ? wpOptions : fcrmOptions;

        sourceOptions.forEach(function (opt) {
            var wpVal    = opt.value;
            var wpLabel  = opt.label || opt.value;
            var mappedTo = savedMap[wpVal] !== undefined ? savedMap[wpVal] : '';

            // Build the FCRM side: dropdown if FCRM has options, else text input
            var $input;
            if (fcrmOptions.length) {
                $input = $('<select class="fcrm-vm-select" data-wp-value="' + escHtml(wpVal) + '">');
                $input.append('<option value="">— same as WP —</option>');
                fcrmOptions.forEach(function (fopt) {
                    var $o = $('<option>').val(fopt.value).text(fopt.label || fopt.value);
                    if (mappedTo === fopt.value) { $o.prop('selected', true); }
                    $input.append($o);
                });
            } else {
                $input = $('<input type="text" class="regular-text fcrm-vm-select" data-wp-value="' +
                    escHtml(wpVal) + '" placeholder="FluentCRM value (leave blank to pass through)">');
                $input.val(mappedTo);
            }

            var $tr = $('<tr>');
            $tr.append($('<td style="padding:4px 8px">').text(wpLabel + ' (' + wpVal + ')'));
            $tr.append($('<td style="padding:4px 8px 4px 16px">').append($input));
            $tbody.append($tr);
        });
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
            var $row    = $(this);
            var rowId   = $row.data('id');
            var wpUid   = $row.find('.fcrm-wp-field').val();
            var fcrmUid = $row.find('.fcrm-fcrm-field').val();

            // A missing FCRM uid is a true error (shouldn't happen with auto-populated rows).
            if (!fcrmUid) {
                $row.addClass('fcrm-row-error');
                hasError = true;
                return; // continue
            }
            // Empty WP uid = "Don't map" — skip silently, not an error.
            if (!wpUid) {
                $row.removeClass('fcrm-row-error');
                return; // continue
            }
            $row.removeClass('fcrm-row-error');

            // Sync direction: respect readonly lock
            var isRO      = parseInt($row.find('.fcrm-wp-field option:selected').data('readonly') || 0, 10) === 1;
            var direction = isRO ? 'wp_to_fcrm' : $row.find('.fcrm-sync-direction').val();

            // Collect value_map from the optional sub-row
            var valueMap = {};
            var $vmRow   = $row.next('.fcrm-value-map-row');
            if ($vmRow.length) {
                $vmRow.find('.fcrm-vm-select').each(function () {
                    var wpVal   = $(this).data('wp-value');
                    var fcrmVal = $(this).val();
                    if (wpVal !== undefined && wpVal !== '' && fcrmVal !== undefined && fcrmVal !== '') {
                        valueMap[String(wpVal)] = String(fcrmVal);
                    }
                });
            }

            // Persist the current value_map into the hidden input so it survives re-renders
            $row.find('.fcrm-value-map-json').val(JSON.stringify(valueMap));

            mappings[rowId] = {
                wp_uid:         wpUid,
                fcrm_uid:       fcrmUid,
                field_type:     $row.find('.fcrm-field-type').val(),
                sync_direction: direction,
                enabled:        $row.find('.fcrm-enabled').is(':checked') ? 1 : 0,
                date_format_wp: $row.find('.fcrm-date-format-wp').val() || 'm/d/Y',
                value_map:      valueMap,
            };
        });

        if (hasError) {
            showNotice($notice, 'Please select a FluentCRM field for every row (highlighted in red).', 'error');
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
    // Sample Data Preview (Field Mapping page)
    // =========================================================================

    var $previewInput   = $('#fcrm-preview-user-input');
    var $previewLoad    = $('#fcrm-preview-load');
    var $previewSuggs   = $('#fcrm-user-suggestions');
    var $previewResults = $('#fcrm-preview-results');
    var selectedUserId  = 0;
    var searchTimer;

    // Debounced user search autocomplete
    $previewInput.on('input', function () {
        clearTimeout(searchTimer);
        var q = $(this).val().trim();
        selectedUserId = 0;
        $previewLoad.prop('disabled', true);

        if (q.length < 2) {
            $previewSuggs.hide().empty();
            return;
        }

        searchTimer = setTimeout(function () {
            $.post(ajaxUrl, {
                action: 'fcrm_wp_sync_search_users',
                nonce:  nonce,
                query:  q,
            }).done(function (resp) {
                $previewSuggs.empty();
                if (!resp.success || !resp.data.length) {
                    $previewSuggs.hide();
                    return;
                }
                $.each(resp.data, function (i, u) {
                    $('<div class="fcrm-user-suggestion-item"></div>')
                        .text(u.label)
                        .attr('data-id', u.id)
                        .appendTo($previewSuggs);
                });
                $previewSuggs.show();
            });
        }, 300);
    });

    // Pick a suggestion
    $previewSuggs.on('click', '.fcrm-user-suggestion-item', function () {
        selectedUserId = parseInt($(this).data('id'), 10);
        $previewInput.val($(this).text());
        $previewSuggs.hide().empty();
        $previewLoad.prop('disabled', false);
    });

    // Dismiss suggestions on outside click
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.fcrm-user-search-wrap').length) {
            $previewSuggs.hide();
        }
    });

    // Load preview data
    $previewLoad.on('click', function () {
        if (!selectedUserId) { return; }
        $previewResults.show().html('<p>' + i18n.loading + '</p>');

        $.post(ajaxUrl, {
            action:  'fcrm_wp_sync_sample_data',
            nonce:   nonce,
            user_id: selectedUserId,
        }).done(function (resp) {
            if (!resp.success) {
                $previewResults.html('<p class="fcrm-error">' + i18n.error + '</p>');
                return;
            }
            var d   = resp.data;
            var html = '<div class="fcrm-preview-user-info">'
                + '<strong>' + $('<span>').text(d.user.display_name).html() + '</strong>'
                + ' &lt;' + $('<span>').text(d.user.email).html() + '&gt;'
                + ' <span class="fcrm-preview-uid">#' + d.user.id + '</span>'
                + '</div>';

            if (!d.rows.length) {
                html += '<p>' + i18n.noMappings + '</p>';
            } else {
                html += '<table class="widefat fcrm-preview-table striped"><thead><tr>'
                    + '<th>' + i18n.previewWpField   + '</th>'
                    + '<th>' + i18n.previewWpVal     + '</th>'
                    + '<th>' + i18n.previewFcrmField  + '</th>'
                    + '<th>' + i18n.previewFcrmVal   + '</th>'
                    + '<th>' + i18n.previewMatch     + '</th>'
                    + '</tr></thead><tbody>';

                $.each(d.rows, function (i, row) {
                    var matchCell = row.match
                        ? '<td class="fcrm-match-yes">&#10003;</td>'
                        : '<td class="fcrm-match-no">&#10007;</td>';
                    html += '<tr>'
                        + '<td>' + $('<span>').text(row.wp_label).html()   + '</td>'
                        + '<td class="fcrm-preview-value">'  + $('<span>').text(row.wp_value   || '—').html() + '</td>'
                        + '<td>' + $('<span>').text(row.fcrm_label).html() + '</td>'
                        + '<td class="fcrm-preview-value">'  + $('<span>').text(row.fcrm_value || '—').html() + '</td>'
                        + matchCell
                        + '</tr>';
                });

                html += '</tbody></table>';
            }
            $previewResults.html(html);
        }).fail(function () {
            $previewResults.html('<p class="fcrm-error">' + i18n.error + '</p>');
        });
    });

    // =========================================================================
    // Sync & Settings page
    // =========================================================================

    // --- Field selection toggles ---
    $('#fcrm-field-sel-all').on('click', function (e) {
        e.preventDefault();
        $('.fcrm-field-sel-cb').prop('checked', true);
    });
    $('#fcrm-field-sel-none').on('click', function (e) {
        e.preventDefault();
        $('.fcrm-field-sel-cb').prop('checked', false);
    });

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

        var fieldIds = $('.fcrm-field-sel-cb:checked').map(function () {
            return $(this).val();
        }).get();

        function doPage() {
            var payload = {
                action:    'fcrm_wp_sync_bulk_sync',
                nonce:     nonce,
                direction: direction,
                per_page:  perPage,
                offset:    offset,
            };
            if (fieldIds.length) {
                payload['field_ids[]'] = fieldIds;
            }
            $.post(ajaxUrl, payload)
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
            $container.find('.fcrm-resolve-empty-btn').on('click', handleResolveEmpty);
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
            html += '<button class="button fcrm-resolve-all-btn" data-user-id="' + record.user_id + '" data-direction="use_fcrm">Use all FCRM</button> ';
            html += '<button class="button fcrm-resolve-empty-btn" data-user-id="' + record.user_id + '">Sync All Empty</button>';
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

    function handleResolveEmpty() {
        var $btn    = $(this);
        var userId  = $btn.data('user-id');
        var $record = $btn.closest('.fcrm-mismatch-record');

        $btn.prop('disabled', true).text(i18n.resolving);

        $.post(ajaxUrl, {
            action:  'fcrm_wp_sync_resolve_mismatch',
            nonce:   nonce,
            user_id: userId,
            scope:   'empty',
        })
        .done(function (resp) {
            if (resp.success) {
                // Mark only the rows where one side was empty — leave true
                // two-sided conflicts (both values present but different) intact.
                $record.find('tbody tr').each(function () {
                    var $row    = $(this);
                    var wpVal   = $.trim($row.find('.fcrm-val-wp').text());
                    var fcrmVal = $.trim($row.find('.fcrm-val-fcrm').text());
                    if (wpVal === '(empty)' || fcrmVal === '(empty)') {
                        $row.addClass('fcrm-resolved').find('td:last-child').html(
                            '<span class="fcrm-resolved-badge">' + i18n.resolved + '</span>'
                        );
                    }
                });
                $btn.prop('disabled', true);
            } else {
                $btn.prop('disabled', false).text('Sync All Empty');
                var msg = (resp.data && resp.data.message) ? resp.data.message : i18n.error;
                showNotice($resolveNotice, msg, 'error');
            }
        })
        .fail(function () {
            $btn.prop('disabled', false).text('Sync All Empty');
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
