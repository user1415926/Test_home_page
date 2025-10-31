// dipr-runner.js
jQuery(function ($) {
    let running = false;
    let stopRequested = false;

    function logLine(message) {
        const el = $('#dipr_status');
        if (!el.length) {
            return;
        }
        el.append(document.createTextNode(message + '\n'));
        el.scrollTop(el[0].scrollHeight);
    }

    function handleAuthCheckPayload(payload) {
        if (
            payload &&
            typeof payload === 'object' &&
            Object.prototype.hasOwnProperty.call(payload, 'wp-auth-check')
        ) {
            logLine('Session expired or nonce invalid. Please reload the page and log in again.');
            running = false;
            return true;
        }
        return false;
    }

    function handleDebugBlock(debugData) {
        if (!debugData) {
            return;
        }

        try {
            const serialized = typeof debugData === 'string' ? debugData : JSON.stringify(debugData);
            logLine('Debug: ' + serialized);
        } catch (err) {
            logLine('Debug: [unserializable payload]');
        }
    }

    // Add controls to page if not present
    if (!$('#dipr_controls').length) {
        const controls = $('<div id="dipr_controls" style="background:#fff;padding:10px;border:1px solid #ddd;margin-bottom:10px;"></div>');
        controls.append('<button id="dipr_start" class="button button-primary">Start background run</button> ');
        controls.append('<button id="dipr_stop" class="button">Stop</button> ');
        controls.append('<span style="margin-left:10px">Offset: <input id="dipr_offset_input" type="number" value="0" style="width:90px"></span> ');
        controls.append('<span style="margin-left:10px">Limit: <input id="dipr_limit_input" type="number" value="20" style="width:70px"></span> ');
        controls.append('<span style="margin-left:10px">Dry run: <input id="dipr_dry_input" type="checkbox"></span> ');
        controls.append('<span style="margin-left:10px">Skip images: <input id="dipr_skip_input" type="checkbox"></span> ');
        controls.append('<span style="margin-left:10px">Prefer CSV importer: <input id="dipr_prefer_input" type="checkbox"></span> ');
        controls.append('<span style="margin-left:10px">Debug: <input id="dipr_debug_input" type="checkbox"></span> ');
        controls.append('<pre id="dipr_status" style="height:200px;overflow:auto;margin-top:10px;"></pre>');
        $('.wrap h1').first().after(controls);

        // Prefill with form values if present
        const formOffset = $('input[name="dipf_offset"]').val();
        const formLimit = $('input[name="dipf_limit"]').val();
        const formDry = $('input[name="dipf_dry"]').is(':checked');
        const formSkip = $('input[name="dipf_skip_images"]').is(':checked');
        const formPrefer = $('input[name="dipf_pref_csv"]').is(':checked');
        const formDebug = $('input[name="dipf_debug"]').is(':checked');

        if (formOffset) {
            $('#dipr_offset_input').val(formOffset);
        }
        if (formLimit) {
            $('#dipr_limit_input').val(formLimit);
        }
        $('#dipr_dry_input').prop('checked', !!formDry);
        $('#dipr_skip_input').prop('checked', !!formSkip);
        $('#dipr_prefer_input').prop('checked', !!formPrefer);
        $('#dipr_debug_input').prop('checked', !!formDebug);
    }

    $('#dipr_start').on('click', function () {
        if (running) {
            return;
        }
        stopRequested = false;
        running = true;
        logLine('Starting background runner...');
        runNext();
    });

    $('#dipr_stop').on('click', function () {
        stopRequested = true;
        logLine('Stop requested; current request will finish...');
    });

    function runNext() {
        if (stopRequested) {
            running = false;
            logLine('Stopped by user.');
            return;
        }

        const offset = parseInt($('#dipr_offset_input').val() || 0, 10);
        const limit = parseInt($('#dipr_limit_input').val() || 20, 10);
        const dry = $('#dipr_dry_input').is(':checked') ? 1 : 0;
        const skip = $('#dipr_skip_input').is(':checked') ? 1 : 0;
        const prefer = $('#dipr_prefer_input').is(':checked') ? 1 : 0;
        const debugFlag = $('#dipr_debug_input').is(':checked') ? 1 : 0;
        const csv = $('input[name="dipf_csv"]').val() || '';

        logLine(`Requesting offset=${offset} limit=${limit} dry=${dry} skip=${skip} prefer=${prefer} debug=${debugFlag}`);

        $.post(
            dipr_params.ajax_url,
            {
                action: 'dipr_run_chunk',
                dipr_nonce: dipr_params.nonce,
                csv: csv,
                offset: offset,
                limit: limit,
                dry: dry,
                skip_images: skip,
                prefer_csv: prefer,
                debug: debugFlag,
            }
        )
            .done(function (resp) {
                if (!resp) {
                    logLine('Empty response from server');
                    running = false;
                    return;
                }

                if (handleAuthCheckPayload(resp)) {
                    return;
                }

                if (resp.success) {
                    const data = resp.data || {};
                    logLine(
                        'Chunk OK. processed=' +
                            (data.processed ?? 'n/a') +
                            ' next_offset=' +
                            (data.next_offset ?? 'n/a') +
                            ' finished=' +
                            (data.finished ? '1' : '0')
                    );

                    if (data.raw !== undefined) {
                        try {
                            logLine('Raw: ' + JSON.stringify(data.raw));
                        } catch (err) {
                            logLine('Raw: [unserializable payload]');
                        }
                    }

                    if (data.debug) {
                        handleDebugBlock(data.debug);
                    }

                    if (data.next_offset !== null && data.next_offset !== undefined) {
                        $('#dipr_offset_input').val(data.next_offset);
                        if (!data.finished) {
                            setTimeout(runNext, 800);
                        } else {
                            logLine('Import finished by server signal.');
                            running = false;
                        }
                    } else {
                        logLine('Importer did not return next_offset and none could be inferred. Stopping to avoid repeats.');
                        running = false;
                    }
                } else {
                    const message = (resp.data && resp.data.message) ? resp.data.message : 'Unknown server error';
                    logLine('Chunk error: ' + message);
                    if (resp.data && resp.data.debug) {
                        handleDebugBlock(resp.data.debug);
                    }
                    running = false;
                }
            })
            .fail(function (xhr, status, err) {
                if (xhr && xhr.status === 200 && xhr.responseText) {
                    try {
                        const authPayload = JSON.parse(xhr.responseText);
                        if (handleAuthCheckPayload(authPayload)) {
                            return;
                        }
                    } catch (parseErr) {
                        // ignore parse errors
                    }
                }

                const payload = xhr && xhr.responseText ? ` :: ${xhr.responseText}` : '';
                logLine('AJAX failed: ' + status + ' ' + err + payload);
                running = false;
            });
    }
});
