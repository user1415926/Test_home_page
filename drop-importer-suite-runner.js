jQuery(function ($) {
    const controls = $('#dropi_controls');
    if (!controls.length) {
        return;
    }

    const markup = [
        '<div style="margin-bottom:10px;">',
        '<button id="dropi_start" class="button button-primary">Start background run</button> ',
        '<button id="dropi_stop" class="button">Stop</button> ',
        '<label style="margin-left:10px;">Offset: <input id="dropi_offset_input" type="number" value="0" style="width:90px"></label> ',
        '<label style="margin-left:10px;">Limit: <input id="dropi_limit_input" type="number" value="10" style="width:80px"></label> ',
        '<label style="margin-left:10px;">Language: <input id="dropi_language_input" type="text" value="pol" style="width:80px"></label> ',
        '<label style="margin-left:10px;">Dry <input id="dropi_dry_input" type="checkbox"></label> ',
        '<label style="margin-left:10px;">Skip images <input id="dropi_skip_input" type="checkbox"></label> ',
        '<label style="margin-left:10px;">Debug <input id="dropi_debug_input" type="checkbox"></label>',
        '</div>',
        '<pre id="dropi_status" style="height:240px;overflow:auto;background:#f6f7f7;border:1px solid #ccd0d4;padding:8px;"></pre>'
    ].join('');

    controls.html(markup);

    const offsetField = $('#dropi_offset_input');
    const limitField = $('#dropi_limit_input');
    const languageField = $('#dropi_language_input');
    const dryField = $('#dropi_dry_input');
    const skipField = $('#dropi_skip_input');
    const debugField = $('#dropi_debug_input');
    const statusBox = $('#dropi_status');

    const formOffset = $('input[name="dropi_offset"]').val();
    const formLimit = $('input[name="dropi_limit"]').val();
    const formDry = $('input[name="dropi_dry"]').is(':checked');
    const formSkip = $('input[name="dropi_skip_images"]').is(':checked');
    const formLanguage = $('input[name="dropi_language"]').val();

    if (formOffset) {
        offsetField.val(formOffset);
    }
    if (formLimit) {
        limitField.val(formLimit);
    }
    if (formLanguage) {
        languageField.val(formLanguage);
    } else if (typeof dropiRunner !== 'undefined' && dropiRunner.language) {
        languageField.val(dropiRunner.language);
    }
    dryField.prop('checked', !!formDry);
    skipField.prop('checked', !!formSkip);

    let running = false;
    let stopRequested = false;

    function logLine(text) {
        const time = new Date().toLocaleTimeString();
        statusBox.append(document.createTextNode('[' + time + '] ' + text + '\n'));
        statusBox.scrollTop(statusBox[0].scrollHeight);
    }

    function handleAuthCheck(payload) {
        if (payload && typeof payload === 'object' && Object.prototype.hasOwnProperty.call(payload, 'wp-auth-check')) {
            logLine('Session expired. Please reload admin and log in again.');
            running = false;
            return true;
        }
        return false;
    }

    function runNext() {
        if (stopRequested) {
            running = false;
            logLine('Stopped by user.');
            return;
        }

        const offset = parseInt(offsetField.val() || '0', 10);
        const limit = parseInt(limitField.val() || '10', 10);
        const language = (languageField.val() || '').trim() || 'pol';
        const dry = dryField.is(':checked') ? 1 : 0;
        const skip = skipField.is(':checked') ? 1 : 0;
        const debug = debugField.is(':checked') ? 1 : 0;
        const xmlPath = $('input[name="dropi_xml"]').val() || '';

        logLine('Request offset=' + offset + ' limit=' + limit + ' dry=' + dry + ' skip=' + skip + ' language=' + language + ' debug=' + debug);

        $.post(dropiRunner.ajax_url, {
            action: 'dropi_run_chunk',
            dropi_nonce: dropiRunner.nonce,
            xml: xmlPath,
            offset: offset,
            limit: limit,
            dry: dry,
            skip_images: skip,
            debug: debug,
            language: language
        }).done(function (resp) {
            if (!resp) {
                logLine('Empty response');
                running = false;
                return;
            }

            if (handleAuthCheck(resp)) {
                return;
            }

            if (resp.success) {
                const data = resp.data || {};
                logLine('Chunk OK. processed=' + (data.processed ?? 'n/a') + ' next=' + (data.next_offset ?? 'n/a') + ' finished=' + (data.finished ? '1' : '0'));
                if (data.meta) {
                    try {
                        logLine('Meta: ' + JSON.stringify(data.meta));
                    } catch (e) {
                        logLine('Meta unavailable (JSON error)');
                    }
                }
                if (data.debug) {
                    logLine('Debug payload: see console');
                    console.debug('Drop Importer Suite debug', data.debug);
                }

                if (data.next_offset !== null && data.next_offset !== undefined) {
                    offsetField.val(data.next_offset);
                    if (!data.finished) {
                        setTimeout(runNext, 700);
                    } else {
                        logLine('All products processed.');
                        running = false;
                    }
                } else {
                    logLine('Importer exhausted or no next offset. Stopping.');
                    running = false;
                }
            } else {
                const message = (resp.data && resp.data.message) ? resp.data.message : 'Unknown server error';
                logLine('Chunk error: ' + message);
                if (resp.data && resp.data.meta) {
                    try {
                        logLine('Meta: ' + JSON.stringify(resp.data.meta));
                    } catch (e) {
                        logLine('Meta unavailable (JSON error)');
                    }
                }
                running = false;
            }
        }).fail(function (xhr, status, error) {
            if (xhr && xhr.status === 200 && xhr.responseText) {
                try {
                    const authPayload = JSON.parse(xhr.responseText);
                    if (handleAuthCheck(authPayload)) {
                        return;
                    }
                } catch (parseError) {
                    // ignore
                }
            }
            logLine('AJAX failed: ' + status + ' ' + error);
            running = false;
        });
    }

    $('#dropi_start').on('click', function () {
        if (running) {
            return;
        }
        stopRequested = false;
        running = true;
        logLine('Starting background runner.');
        runNext();
    });

    $('#dropi_stop').on('click', function () {
        if (!running) {
            return;
        }
        stopRequested = true;
        logLine('Stop requested. Current batch will finish.');
    });
});
