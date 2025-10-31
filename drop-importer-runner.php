<?php
/*
Plugin Name: Drop Importer Runner (Fixed)
Description: Safe admin runner for batch CSV import with optional debug logging and AJAX chunk runner.
Version: 1.4
Author: Generated Fixed
Text Domain: drop-importer-runner-fixed
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// -----------------------------------------------------------------------------
// Debug helpers
// -----------------------------------------------------------------------------

/**
 * Set debug flag for the current request lifecycle.
 *
 * @param bool|null $value True enables debug, false disables, null resets to default behaviour.
 */
function dipf_set_debug_flag( $value ) {
    $GLOBALS['dipf_debug_flag'] = null === $value ? null : (bool) $value;
}

/**
 * Whether debug logging is currently enabled.
 *
 * Order of precedence:
 * 1. Per-request flag set via dipf_set_debug_flag().
 * 2. Filter `dipf_debug_enabled`.
 * 3. Constant `DIPF_DEBUG`.
 *
 * @return bool
 */
function dipf_debug_enabled() {
    if ( array_key_exists( 'dipf_debug_flag', $GLOBALS ) && null !== $GLOBALS['dipf_debug_flag'] ) {
        return (bool) $GLOBALS['dipf_debug_flag'];
    }

    $default = defined( 'DIPF_DEBUG' ) ? (bool) DIPF_DEBUG : false;

    /**
     * Filter the debug state for Drop Importer Runner.
     *
     * @param bool $enabled Whether debug is enabled by default.
     */
    return apply_filters( 'dipf_debug_enabled', $default );
}

/**
 * Write a debug line to the log if debug is enabled.
 *
 * @param string $message Message to log.
 */
function dipf_debug_log( $message ) {
    if ( dipf_debug_enabled() ) {
        dipf_log( '[DEBUG] ' . $message );
    }
}

/**
 * Produce a JSON-friendly string for debugging.
 *
 * @param mixed $value Value to encode.
 * @param int   $limit Maximum number of characters to return (0 = unlimited).
 * @return string
 */
function dipf_debug_serialize( $value, $limit = 4000 ) {
    $encoded = null;

    if ( function_exists( 'wp_json_encode' ) ) {
        $encoded = wp_json_encode( $value );
    }

    if ( false === $encoded || null === $encoded ) {
        $encoded = json_encode( $value );
    }

    if ( false === $encoded || null === $encoded ) {
        ob_start();
        print_r( $value ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
        $encoded = ob_get_clean();
    }

    if ( ! is_string( $encoded ) ) {
        $encoded = (string) $encoded;
    }

    if ( $limit > 0 && strlen( $encoded ) > $limit ) {
        $encoded = substr( $encoded, 0, $limit ) . ' ?';
    }

    return $encoded;
}

// -----------------------------------------------------------------------------
// Logging helpers
// -----------------------------------------------------------------------------

/**
 * Path to log file in uploads directory.
 *
 * @return string
 */
function dipf_log_path() {
    $upl = wp_upload_dir();
    return trailingslashit( $upl['basedir'] ) . 'drop-importer-run.log';
}

/**
 * Append a message to the plugin log.
 *
 * @param string $msg Message to log.
 */
function dipf_log( $msg ) {
    $file  = dipf_log_path();
    $entry = date( 'c' ) . ' | ' . $msg . PHP_EOL;
    @file_put_contents( $file, $entry, FILE_APPEND | LOCK_EX );
}

/**
 * Tail the plugin log file.
 *
 * @param int $lines Number of lines to fetch from the end of the log.
 * @return string
 */
function dipf_tail_log( $lines = 80 ) {
    $file = dipf_log_path();
    if ( ! file_exists( $file ) ) {
        return 'Log not found: ' . $file;
    }

    $data = @file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
    if ( false === $data ) {
        return 'Cannot read log file.';
    }

    $tail = array_slice( $data, -$lines );
    return implode( PHP_EOL, $tail );
}

// -----------------------------------------------------------------------------
// Importer invocation helpers
// -----------------------------------------------------------------------------

/**
 * Call the underlying importer once with the provided parameters.
 *
 * @param int    $offset     Start offset/group.
 * @param int    $limit      Groups per batch.
 * @param string $csv        CSV path.
 * @param bool   $dry        Dry-run flag.
 * @param bool   $prefer_csv Whether CSV importer is preferred.
 *
 * @return array {
 *     @type mixed       $result   Raw importer result (may be null or array).
 *     @type string|null $error    Error message on failure.
 *     @type string|null $used     Name of the importer function used.
 *     @type float       $duration Duration of the call in seconds.
 * }
 */
function dipf_call_importer_once( $offset, $limit, $csv, $dry, $prefer_csv ) {
    $result   = null;
    $error    = null;
    $used     = null;
    $start    = microtime( true );
    $limit    = max( 1, intval( $limit ) );
    $offset   = max( 0, intval( $offset ) );
    $args_csv = array(
        'dry_run'          => (bool) $dry,
        'start_group'      => $offset,
        'groups_per_batch' => $limit,
    );

    try {
        if ( $prefer_csv && function_exists( 'drop_import_csv_adapted' ) ) {
            dipf_debug_log( 'Calling drop_import_csv_adapted() with limit ' . $limit . ' (preferred).' );
            $result = drop_import_csv_adapted( $csv, $args_csv );
            $used   = 'drop_import_csv_adapted';
        } elseif ( function_exists( 'drop_importer_process_chunk' ) ) {
            dipf_debug_log( 'Calling drop_importer_process_chunk() with limit ' . $limit . '.' );
            $used = 'drop_importer_process_chunk';
            try {
                $result = call_user_func( 'drop_importer_process_chunk', $offset, $limit, $csv );
            } catch ( ArgumentCountError $e ) {
                dipf_debug_log( 'drop_importer_process_chunk signature mismatch, retrying without CSV argument.' );
                $result = call_user_func( 'drop_importer_process_chunk', $offset, $limit );
            }
        } elseif ( function_exists( 'drop_import_csv_adapted' ) ) {
            dipf_debug_log( 'Calling drop_import_csv_adapted() fallback with limit ' . $limit . '.' );
            $result = drop_import_csv_adapted( $csv, $args_csv );
            $used   = 'drop_import_csv_adapted';
        } else {
            $error = 'Importer functions not found';
        }
    } catch ( Exception $e ) {
        $error = $e->getMessage();
    } catch ( Error $err ) {
        $error = $err->getMessage();
    }

    if ( is_wp_error( $result ) ) {
        $error = $result->get_error_message();
    }

    $duration = microtime( true ) - $start;

    return array(
        'result'   => $result,
        'error'    => $error,
        'used'     => $used,
        'duration' => $duration,
    );
}

/**
 * Normalise importer output so callers can reason about progress.
 *
 * @param mixed $result Raw importer result.
 * @param int   $offset Start offset that was requested.
 * @param int   $limit  Limit that was requested for this call.
 *
 * @return array {
 *     @type int|null $processed    How many groups/items were processed (best effort).
 *     @type int|null $next_offset  Next offset to continue from, if known.
 *     @type int|null $total_groups Total groups reported by importer, if any.
 *     @type bool     $finished     Whether importer reports completion.
 * }
 */
function dipf_normalize_importer_summary( $result, $offset, $limit ) {
    $offset        = intval( $offset );
    $limit         = max( 1, intval( $limit ) );
    $processed     = null;
    $next_offset   = null;
    $total_groups  = null;
    $finished      = false;

    if ( is_array( $result ) ) {
        if ( isset( $result['processed_groups'] ) ) {
            $processed = intval( $result['processed_groups'] );
        } elseif ( isset( $result['processed'] ) ) {
            $processed = intval( $result['processed'] );
        }

        if ( isset( $result['next_offset'] ) ) {
            $next_offset = intval( $result['next_offset'] );
        } elseif ( isset( $result['start_group'], $result['groups_per_batch'] ) ) {
            $next_offset = intval( $result['start_group'] ) + intval( $result['groups_per_batch'] );
        } elseif ( isset( $result['start_group'] ) ) {
            $next_offset = intval( $result['start_group'] );
        } elseif ( isset( $result['offset'] ) ) {
            $next_offset = intval( $result['offset'] );
        }

        if ( isset( $result['groups_total'] ) ) {
            $total_groups = intval( $result['groups_total'] );
        } elseif ( isset( $result['total_groups'] ) ) {
            $total_groups = intval( $result['total_groups'] );
        }

        if ( isset( $result['finished'] ) ) {
            $finished = (bool) $result['finished'];
        }
    }

    if ( null === $processed && null !== $next_offset ) {
        $processed = max( 0, intval( $next_offset ) - $offset );
    }

    if ( null === $processed && is_array( $result ) && isset( $result['preview'] ) && is_array( $result['preview'] ) ) {
        $processed = count( $result['preview'] );
    }

    if ( null === $processed ) {
        $processed = 0;
    }

    if ( null === $next_offset && $processed > 0 ) {
        $next_offset = $offset + $processed;
    }

    if ( null !== $total_groups && null !== $next_offset ) {
        if ( $next_offset >= $total_groups ) {
            $finished    = true;
            $next_offset = $total_groups;
        }
    }

    if ( ! $finished && 0 === $processed && ( null === $next_offset || $next_offset <= $offset ) ) {
        if ( ! is_array( $result ) || empty( $result ) ) {
            $finished = true;
        }
    }

    return array(
        'processed'    => max( 0, intval( $processed ) ),
        'next_offset'  => null === $next_offset ? null : intval( $next_offset ),
        'total_groups' => null === $total_groups ? null : intval( $total_groups ),
        'finished'     => (bool) $finished,
    );
}

// -----------------------------------------------------------------------------
// HTTP filters
// -----------------------------------------------------------------------------

/**
 * Override HTTP timeout during import.
 *
 * @param int|float $timeout Timeout value.
 * @return int
 */
function dipf_http_timeout_filter( $timeout ) {
    return 20;
}

/**
 * Optionally prevent image downloads when skip_images is enabled.
 *
 * @param mixed       $pre  Preempt value.
 * @param array|false $args Request args.
 * @param string      $url  Request URL.
 * @return mixed
 */
function dipf_pre_http_request_filter( $pre, $args, $url ) {
    if ( is_string( $url ) && preg_match( '/\.(jpe?g|png|gif|webp)(\?.*)?$/i', $url ) ) {
        return new WP_Error( 'dipf_skip_image', 'Image download skipped to avoid timeouts during batch import' );
    }

    return $pre;
}

// -----------------------------------------------------------------------------
// Admin page
// -----------------------------------------------------------------------------

add_action(
    'admin_menu',
    function () {
        add_management_page(
            'Drop Importer Runner (Fixed)',
            'Drop Importer Runner',
            'manage_options',
            'drop-importer-runner-fixed',
            'dipf_admin_page'
        );
    }
);

/**
 * Render the admin runner page.
 */
function dipf_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Access denied' );
    }

    $default_csv         = WP_CONTENT_DIR . '/uploads/stock_export_full_for_varvishop.csv';
    $csv                 = isset( $_POST['dipf_csv'] ) ? sanitize_text_field( wp_unslash( $_POST['dipf_csv'] ) ) : $default_csv; // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $offset              = isset( $_POST['dipf_offset'] ) ? intval( wp_unslash( $_POST['dipf_offset'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $limit               = isset( $_POST['dipf_limit'] ) ? intval( wp_unslash( $_POST['dipf_limit'] ) ) : 100; // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $dry_run             = isset( $_POST['dipf_dry'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $skip_images         = isset( $_POST['dipf_skip_images'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $prefer_csv_importer = isset( $_POST['dipf_pref_csv'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $debug_mode          = isset( $_POST['dipf_debug'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

    echo '<div class="wrap"><h1>Drop Importer Runner (Fixed)</h1>';
    echo '<p>Use small limits first (10-50). CSV default: <code>' . esc_html( $default_csv ) . '</code></p>';

    if ( isset( $_POST['dipf_run'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! check_admin_referer( 'dipf_run_action', 'dipf_run_nonce' ) ) {
            echo '<div class="notice notice-error"><p>Nonce failed.</p></div>';
        } else {
            dipf_set_debug_flag( $debug_mode );
            dipf_debug_log( 'Admin run POST payload: ' . dipf_debug_serialize( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

            if ( empty( $csv ) || ! file_exists( $csv ) ) {
                echo '<div class="notice notice-error"><p>CSV not found: ' . esc_html( $csv ) . '</p></div>';
                dipf_log( 'CSV not found: ' . $csv );
            } else {
                @set_time_limit( 0 );
                @ini_set( 'memory_limit', '1024M' );

                add_filter( 'http_request_timeout', 'dipf_http_timeout_filter' );

                if ( $skip_images ) {
                    add_filter( 'pre_http_request', 'dipf_pre_http_request_filter', 10, 3 );
                }

                dipf_log(
                    'START offset=' . $offset .
                    ' limit=' . $limit .
                    ' dry_run=' . ( $dry_run ? '1' : '0' ) .
                    ' skip_images=' . ( $skip_images ? '1' : '0' ) .
                    ' prefer_csv=' . ( $prefer_csv_importer ? '1' : '0' ) .
                    ' debug=' . ( dipf_debug_enabled() ? '1' : '0' ) .
                    ' csv=' . $csv
                );

                $res           = null;
                $error_message = null;

                ob_start();

                try {
                    if ( $prefer_csv_importer && function_exists( 'drop_import_csv_adapted' ) ) {
                        $args = array(
                            'dry_run'          => $dry_run,
                            'start_group'      => $offset,
                            'groups_per_batch' => $limit,
                        );
                        dipf_debug_log( 'Calling drop_import_csv_adapted via admin form.' );
                        $res = drop_import_csv_adapted( $csv, $args );
                        echo '<h2>Called drop_import_csv_adapted()</h2>';
                        print_r( $res ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
                    } elseif ( function_exists( 'drop_importer_process_chunk' ) ) {
                        dipf_debug_log( 'Calling drop_importer_process_chunk via admin form.' );
                        try {
                            $res = call_user_func( 'drop_importer_process_chunk', $offset, $limit, $csv );
                        } catch ( ArgumentCountError $e ) {
                            dipf_debug_log( 'drop_importer_process_chunk threw ArgumentCountError: ' . $e->getMessage() );
                            $res = call_user_func( 'drop_importer_process_chunk', $offset, $limit );
                        }
                        echo '<h2>Called drop_importer_process_chunk()</h2>';
                        print_r( $res ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
                    } elseif ( function_exists( 'drop_import_csv_adapted' ) ) {
                        dipf_debug_log( 'Calling drop_import_csv_adapted fallback via admin form.' );
                        $args = array(
                            'dry_run'          => $dry_run,
                            'start_group'      => $offset,
                            'groups_per_batch' => $limit,
                        );
                        $res = drop_import_csv_adapted( $csv, $args );
                        echo '<h2>Called drop_import_csv_adapted()</h2>';
                        print_r( $res ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
                    } else {
                        echo '<div class="notice notice-error"><p>Importer functions not found. Ensure your importer plugin exposes drop_import_csv_adapted() or drop_importer_process_chunk().</p></div>';
                        dipf_log( 'Importer functions not found when attempting admin run.' );
                    }
                } catch ( Exception $e ) {
                    $error_message = $e->getMessage();
                    dipf_log( 'Exception during admin import: ' . $error_message );
                } catch ( Error $err ) {
                    $error_message = $err->getMessage();
                    dipf_log( 'Fatal error during admin import: ' . $error_message );
                }

                $out = ob_get_clean();

                if ( is_wp_error( $res ) ) {
                    $error_message = $res->get_error_message();
                    dipf_log( 'Admin importer returned WP_Error: ' . $error_message );
                    dipf_debug_log( 'Admin importer WP_Error debug: ' . dipf_debug_serialize( $res ) );
                    $res = null;
                }

                if ( $error_message ) {
                    $out .= PHP_EOL . 'ERROR: ' . $error_message;
                }

                echo '<div style="background:#fff;border:1px solid #ddd;padding:10px;margin-top:10px;"><pre>' . esc_html( $out ) . '</pre></div>';

                if ( is_array( $res ) ) {
                    dipf_debug_log( 'Admin importer result: ' . dipf_debug_serialize( $res ) );

                    $processed = $res['processed_groups'] ?? ( $res['processed'] ?? 'n/a' );
                    $next      = null;

                    if ( isset( $res['start_group'], $res['groups_per_batch'] ) ) {
                        $next = intval( $res['start_group'] ) + intval( $res['groups_per_batch'] );
                    } elseif ( isset( $res['next_offset'] ) ) {
                        $next = intval( $res['next_offset'] );
                    } elseif ( isset( $res['start_group'] ) ) {
                        $next = intval( $res['start_group'] );
                    }

                    dipf_log( 'END processed=' . $processed . ' next_offset=' . ( null === $next ? 'n/a' : $next ) );

                    if ( null !== $next ) {
                        update_option( 'drop_importer_progress_offset', intval( $next ) );
                    }
                } else {
                    dipf_log( 'END result not array' );
                }

                remove_filter( 'http_request_timeout', 'dipf_http_timeout_filter' );

                if ( $skip_images ) {
                    remove_filter( 'pre_http_request', 'dipf_pre_http_request_filter', 10 );
                }
            }

            dipf_set_debug_flag( null );
        }
    }

    ?>
    <form method="post" style="background:#fff;padding:10px;border:1px solid #ddd;">
        <?php wp_nonce_field( 'dipf_run_action', 'dipf_run_nonce' ); ?>
        <table class="form-table">
            <tr>
                <th><label for="dipf_csv">CSV path</label></th>
                <td><input id="dipf_csv" name="dipf_csv" type="text" style="width:70%" value="<?php echo esc_attr( $csv ); ?>"></td>
            </tr>
            <tr>
                <th><label for="dipf_offset">Offset</label></th>
                <td><input id="dipf_offset" name="dipf_offset" type="number" min="0" value="<?php echo esc_attr( $offset ); ?>"></td>
            </tr>
            <tr>
                <th><label for="dipf_limit">Limit (groups per batch)</label></th>
                <td><input id="dipf_limit" name="dipf_limit" type="number" min="1" value="<?php echo esc_attr( $limit ); ?>"> <span class="description">Recommended 10-200</span></td>
            </tr>
            <tr>
                <th>Dry run</th>
                <td><label><input name="dipf_dry" type="checkbox" value="1" <?php checked( $dry_run ); ?>> Dry run (no DB changes)</label></td>
            </tr>
            <tr>
                <th>Skip images</th>
                <td>
                    <label><input name="dipf_skip_images" type="checkbox" value="1" <?php checked( $skip_images ); ?>> Skip image downloads (safe mode)</label>
                    <p class="description">If checked, images will be skipped during import (useful if server times out). You will need a separate step to import images later.</p>
                </td>
            </tr>
            <tr>
                <th>Prefer CSV-based importer</th>
                <td>
                    <label><input name="dipf_pref_csv" type="checkbox" value="1" <?php checked( $prefer_csv_importer ); ?>> Prefer drop_import_csv_adapted()</label>
                    <p class="description">Check if your importer is CSV-first. If unsure, leave unchecked to auto-detect.</p>
                </td>
            </tr>
            <tr>
                <th>Enable debug logging</th>
                <td>
                    <label><input name="dipf_debug" type="checkbox" value="1" <?php checked( $debug_mode ); ?>> Extra logging (writes request/result payloads to log)</label>
                    <p class="description">Enable when troubleshooting. Disable in production to keep logs compact.</p>
                </td>
            </tr>
        </table>
        <p class="submit"><input type="submit" name="dipf_run" id="dipf_run" class="button button-primary" value="Run batch"></p>
    </form>

    <h2>Log (last 100 lines)</h2>
    <div style="background:#fff;border:1px solid #ddd;padding:10px;">
        <pre><?php echo esc_html( dipf_tail_log( 100 ) ); ?></pre>
    </div>
    <?php
    echo '</div>';
}

// -----------------------------------------------------------------------------
// AJAX chunk runner
// -----------------------------------------------------------------------------

add_action(
    'wp_ajax_dipr_run_chunk',
    function () {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'no_permission' ), 403 );
        }

        if ( isset( $_POST['dipr_nonce'] ) && ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dipr_nonce'] ) ), 'dipr_chunk_nonce' ) ) {
            wp_send_json_error( array( 'message' => 'bad_nonce' ), 400 );
        }

        $csv         = isset( $_POST['csv'] ) ? sanitize_text_field( wp_unslash( $_POST['csv'] ) ) : ( WP_CONTENT_DIR . '/uploads/stock_export_full_for_varvishop.csv' );
        $offset      = isset( $_POST['offset'] ) ? intval( wp_unslash( $_POST['offset'] ) ) : 0;
        $limit       = isset( $_POST['limit'] ) ? max( 1, intval( wp_unslash( $_POST['limit'] ) ) ) : 20;
        $dry         = ! empty( $_POST['dry'] );
        $skip_images = ! empty( $_POST['skip_images'] );
        $prefer_csv  = ! empty( $_POST['prefer_csv'] );
        $debug_post  = ! empty( $_POST['debug'] );

        dipf_set_debug_flag( $debug_post );
        $debug_enabled = dipf_debug_enabled();
        dipf_debug_log( 'AJAX request payload: ' . dipf_debug_serialize( $_POST ) );

        $error_message   = null;
        $error_code      = 200;
        $error_meta      = array();
        $error_debug     = array();
        $success_payload = null;

        if ( empty( $csv ) || ! file_exists( $csv ) ) {
            $error_message = 'CSV not found: ' . $csv;
            $error_code    = 400;
            $error_meta    = array(
                'offset' => intval( $offset ),
                'limit'  => intval( $limit ),
                'csv'    => $csv,
            );
            dipf_log( 'AJAX CSV missing at offset ' . $offset . ': ' . $csv );
        } else {
            @set_time_limit( 0 );
            @ini_set( 'memory_limit', '1024M' );

            $timeout_filter_added = false;
            $skip_filter_added    = false;

            if ( $skip_images ) {
                add_filter( 'pre_http_request', 'dipf_pre_http_request_filter', 10, 3 );
                $skip_filter_added = true;
            }

            add_filter( 'http_request_timeout', 'dipf_http_timeout_filter' );
            $timeout_filter_added = true;

            $runtime_limit  = max( 5, (int) apply_filters( 'dipf_ajax_runtime_limit_seconds', 20 ) );
            $per_call_limit = max( 1, (int) apply_filters( 'dipf_ajax_subchunk_limit', 20 ) );
            $max_iterations = max( 1, (int) apply_filters( 'dipf_ajax_max_iterations', 10 ) );

            dipf_debug_log(
                'AJAX limits runtime=' . $runtime_limit . 's per_call=' . $per_call_limit . ' max_iter=' . $max_iterations
            );

            $start_time        = microtime( true );
            $processed_total   = 0;
            $current_offset    = intval( $offset );
            $loops             = 0;
            $finished          = false;
            $stalled           = false;
            $time_cap_hit      = false;
            $iteration_cap_hit = false;
            $used_functions    = array();
            $substeps          = array();
            $last_raw          = null;
            $last_summary      = null;

            while ( $processed_total < $limit ) {
                $elapsed = microtime( true ) - $start_time;
                if ( $elapsed >= $runtime_limit ) {
                    $time_cap_hit = true;
                    break;
                }

                if ( $loops >= $max_iterations ) {
                    $iteration_cap_hit = true;
                    break;
                }

                $remaining = max( 0, $limit - $processed_total );
                if ( $remaining <= 0 ) {
                    break;
                }

                $batch_limit = min( $remaining, $per_call_limit );
                $loops++;

                $call = dipf_call_importer_once( $current_offset, $batch_limit, $csv, $dry, $prefer_csv );
                $call_meta = array(
                    'offset'   => $current_offset,
                    'limit'    => $batch_limit,
                    'duration' => $call['duration'],
                );

                if ( $call['used'] ) {
                    $call_meta['used'] = $call['used'];
                    $used_functions[ $call['used'] ] = true;
                }

                if ( $call['error'] ) {
                    $error_message = $call['error'];
                    $error_meta    = array(
                        'offset'           => $current_offset,
                        'limit'            => $batch_limit,
                        'requested_offset' => intval( $offset ),
                        'requested_limit'  => intval( $limit ),
                        'per_call_limit'   => $per_call_limit,
                        'loops_completed'  => $loops - 1,
                        'runtime'          => $elapsed,
                        'runtime_limit'    => $runtime_limit,
                        'max_iterations'   => $max_iterations,
                        'used_functions'   => array_keys( $used_functions ),
                    );

                    if ( $debug_enabled ) {
                        $error_debug['call']     = $call_meta;
                        $error_debug['raw']      = $call['result'];
                        $error_debug['subcalls'] = $substeps;
                    }
                    break;
                }

                $result = $call['result'];

                if ( is_wp_error( $result ) ) {
                    $error_message = $result->get_error_message();
                    $error_meta    = array(
                        'offset'           => $current_offset,
                        'limit'            => $batch_limit,
                        'requested_offset' => intval( $offset ),
                        'requested_limit'  => intval( $limit ),
                        'per_call_limit'   => $per_call_limit,
                        'loops_completed'  => $loops - 1,
                        'runtime'          => $elapsed,
                        'runtime_limit'    => $runtime_limit,
                        'max_iterations'   => $max_iterations,
                        'used_functions'   => array_keys( $used_functions ),
                    );

                    if ( $debug_enabled ) {
                        $error_debug['call']     = $call_meta;
                        $error_debug['raw']      = $result;
                        $error_debug['subcalls'] = $substeps;
                    }
                    break;
                }

                $summary = dipf_normalize_importer_summary( $result, $current_offset, $batch_limit );
                $call_meta['processed']   = $summary['processed'];
                $call_meta['next_offset'] = $summary['next_offset'];
                $call_meta['finished']    = $summary['finished'];

                $substeps[]   = $call_meta;
                $last_raw     = $result;
                $last_summary = $summary;

                $processed_batch = intval( $summary['processed'] );
                $next_offset     = $summary['next_offset'];
                $finished        = (bool) $summary['finished'];

                if ( $processed_batch <= 0 && ( null === $next_offset || intval( $next_offset ) <= $current_offset ) ) {
                    $stalled = true;
                    break;
                }

                $processed_total += $processed_batch;

                if ( null !== $next_offset ) {
                    $current_offset = intval( $next_offset );
                } else {
                    $current_offset = $current_offset + $processed_batch;
                }

                if ( $finished ) {
                    break;
                }
            }

            $total_runtime = microtime( true ) - $start_time;

            if ( $timeout_filter_added ) {
                remove_filter( 'http_request_timeout', 'dipf_http_timeout_filter' );
            }
            if ( $skip_filter_added ) {
                remove_filter( 'pre_http_request', 'dipf_pre_http_request_filter', 10 );
            }

            if ( ! $error_message ) {
                $next_offset_response = $finished ? null : $current_offset;

                if ( $stalled ) {
                    $next_offset_response = null;
                }

                if ( null !== $next_offset_response ) {
                    update_option( 'drop_importer_progress_offset', intval( $next_offset_response ) );
                }

                $meta = array(
                    'requested_offset'   => intval( $offset ),
                    'requested_limit'    => intval( $limit ),
                    'per_call_limit'     => $per_call_limit,
                    'runtime_limit'      => $runtime_limit,
                    'runtime'            => $total_runtime,
                    'loops'              => $loops,
                    'runtime_capped'     => $time_cap_hit,
                    'max_iterations_hit' => $iteration_cap_hit,
                    'stalled'            => $stalled,
                    'final_offset'       => $next_offset_response,
                    'used_functions'     => array_keys( $used_functions ),
                );

                if ( $debug_enabled ) {
                    $meta['subcalls']     = $substeps;
                    $meta['last_summary'] = $last_summary;
                }

                $success_payload = array(
                    'processed'   => intval( $processed_total ),
                    'next_offset' => $next_offset_response,
                    'finished'    => (bool) ( $finished || $stalled ),
                    'raw'         => $debug_enabled ? $last_raw : null,
                    'meta'        => $meta,
                );

                $log_msg  = 'AJAX summary offset=' . intval( $offset );
                $log_msg .= ' processed=' . intval( $processed_total );
                $log_msg .= ' next=' . ( null === $next_offset_response ? 'null' : intval( $next_offset_response ) );
                $log_msg .= ' loops=' . $loops;
                $log_msg .= ' runtime=' . round( $total_runtime, 3 ) . 's';
                $log_msg .= ' finished=' . ( $finished ? '1' : '0' );
                if ( $time_cap_hit ) {
                    $log_msg .= ' runtime_cap=1';
                }
                if ( $iteration_cap_hit ) {
                    $log_msg .= ' iteration_cap=1';
                }
                if ( $stalled ) {
                    $log_msg .= ' stalled=1';
                }
                dipf_log( $log_msg );
            } else {
                if ( ! $error_meta ) {
                    $error_meta = array(
                        'requested_offset' => intval( $offset ),
                        'requested_limit'  => intval( $limit ),
                        'per_call_limit'   => $per_call_limit,
                        'runtime_limit'    => $runtime_limit,
                        'runtime'          => microtime( true ) - $start_time,
                        'loops_completed'  => $loops,
                        'used_functions'   => array_keys( $used_functions ),
                        'stalled'          => $stalled,
                    );
                }
            }
        }

        dipf_set_debug_flag( null );

        if ( $error_message ) {
            dipf_log( 'AJAX chunk error offset=' . intval( $offset ) . ' limit=' . intval( $limit ) . ' : ' . $error_message );

            $payload = array(
                'message' => $error_message,
                'meta'    => $error_meta,
            );

            if ( $debug_enabled && ! empty( $error_debug ) ) {
                $payload['debug'] = $error_debug;
            }

            wp_send_json_error( $payload, $error_code );
        }

        if ( ! $success_payload ) {
            $success_payload = array(
                'processed'   => 0,
                'next_offset' => null,
                'finished'    => true,
                'raw'         => null,
                'meta'        => array(
                    'requested_offset' => intval( $offset ),
                    'requested_limit'  => intval( $limit ),
                    'per_call_limit'   => isset( $per_call_limit ) ? $per_call_limit : null,
                    'runtime'          => isset( $total_runtime ) ? $total_runtime : 0,
                    'loops'            => isset( $loops ) ? $loops : 0,
                    'stalled'          => true,
                ),
            );
        }

        wp_send_json_success( $success_payload );
    }
);

// -----------------------------------------------------------------------------
// Assets
// -----------------------------------------------------------------------------

add_action(
    'admin_enqueue_scripts',
    function ( $hook ) {
        if (
            'tools_page_drop-importer-runner-fixed' !== $hook &&
            'tools_page_drop-importer-runner' !== $hook &&
            'tools_page_drop-importer-runner-safe' !== $hook
        ) {
            return;
        }

        wp_enqueue_script(
            'dipr-runner-js',
            plugin_dir_url( __FILE__ ) . 'dipr-runner.js',
            array( 'jquery' ),
            '1.3.0',
            true
        );

        wp_localize_script(
            'dipr-runner-js',
            'dipr_params',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'dipr_chunk_nonce' ),
            )
        );
    }
);

// -----------------------------------------------------------------------------
// End plugin
// -----------------------------------------------------------------------------
