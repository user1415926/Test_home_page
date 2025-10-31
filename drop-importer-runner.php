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
        dipf_debug_log( 'AJAX request payload: ' . dipf_debug_serialize( $_POST ) );

        if ( empty( $csv ) || ! file_exists( $csv ) ) {
            dipf_log( 'AJAX CSV missing at offset ' . $offset . ': ' . $csv );
            dipf_set_debug_flag( null );
            wp_send_json_error(
                array(
                    'message' => 'CSV not found: ' . $csv,
                    'debug'   => array(
                        'offset' => $offset,
                        'limit'  => $limit,
                    ),
                ),
                400
            );
        }

        @set_time_limit( 0 );
        @ini_set( 'memory_limit', '1024M' );

        if ( $skip_images ) {
            add_filter( 'pre_http_request', 'dipf_pre_http_request_filter', 10, 3 );
        }

        add_filter( 'http_request_timeout', 'dipf_http_timeout_filter' );

        $result = null;
        $error  = null;

        try {
            if ( $prefer_csv && function_exists( 'drop_import_csv_adapted' ) ) {
                $args   = array(
                    'dry_run'          => $dry,
                    'start_group'      => $offset,
                    'groups_per_batch' => $limit,
                );
                dipf_debug_log( 'AJAX calling drop_import_csv_adapted().' );
                $result = drop_import_csv_adapted( $csv, $args );
            } elseif ( function_exists( 'drop_importer_process_chunk' ) ) {
                dipf_debug_log( 'AJAX calling drop_importer_process_chunk().' );
                try {
                    $result = call_user_func( 'drop_importer_process_chunk', $offset, $limit, $csv );
                } catch ( ArgumentCountError $e ) {
                    dipf_debug_log( 'drop_importer_process_chunk argument mismatch: ' . $e->getMessage() );
                    $result = call_user_func( 'drop_importer_process_chunk', $offset, $limit );
                }
            } elseif ( function_exists( 'drop_import_csv_adapted' ) ) {
                $args   = array(
                    'dry_run'          => $dry,
                    'start_group'      => $offset,
                    'groups_per_batch' => $limit,
                );
                dipf_debug_log( 'AJAX calling drop_import_csv_adapted() fallback.' );
                $result = drop_import_csv_adapted( $csv, $args );
            } else {
                $error = 'Importer functions not found';
            }
        } catch ( Exception $e ) {
            $error = $e->getMessage();
            dipf_log( 'AJAX exception: ' . $error );
            dipf_debug_log( 'AJAX exception trace: ' . dipf_debug_serialize( $e->getTraceAsString() ) );
        } catch ( Error $err ) {
            $error = $err->getMessage();
            dipf_log( 'AJAX fatal error: ' . $error );
            dipf_debug_log( 'AJAX fatal trace: ' . dipf_debug_serialize( $err->getTraceAsString() ) );
        }

        if ( is_wp_error( $result ) ) {
            $error = $result->get_error_message();
            dipf_log( 'AJAX importer WP_Error: ' . $error );
            dipf_debug_log( 'AJAX importer WP_Error detail: ' . dipf_debug_serialize( $result ) );
            $result = null;
        }

        remove_filter( 'http_request_timeout', 'dipf_http_timeout_filter' );
        if ( $skip_images ) {
            remove_filter( 'pre_http_request', 'dipf_pre_http_request_filter', 10 );
        }

        if ( $error ) {
            dipf_log( 'AJAX chunk error offset=' . $offset . ' limit=' . $limit . ' : ' . $error );
            dipf_set_debug_flag( null );
            wp_send_json_error(
                array(
                    'message' => $error,
                    'debug'   => array(
                        'offset'      => $offset,
                        'limit'       => $limit,
                        'dry'         => $dry,
                        'skip_images' => $skip_images,
                        'prefer_csv'  => $prefer_csv,
                    ),
                )
            );
        }

        if ( ! is_array( $result ) ) {
            $result = array();
        }

        dipf_debug_log( 'AJAX success raw result: ' . dipf_debug_serialize( $result ) );

        $processed    = $result['processed_groups'] ?? ( $result['processed'] ?? null );
        $total_groups = $result['groups_total'] ?? ( $result['total_groups'] ?? null );
        $next_offset  = null;

        if ( isset( $result['start_group'], $result['groups_per_batch'] ) ) {
            $next_offset = intval( $result['start_group'] ) + intval( $result['groups_per_batch'] );
        } elseif ( isset( $result['next_offset'] ) ) {
            $next_offset = intval( $result['next_offset'] );
        } elseif ( isset( $result['start_group'] ) ) {
            $next_offset = intval( $result['start_group'] );
        }

        if ( null === $next_offset && null !== $processed ) {
            $next_offset = intval( $offset ) + intval( $processed );
        }

        if ( null === $next_offset && isset( $result['preview'] ) && is_array( $result['preview'] ) ) {
            $next_offset = intval( $offset ) + count( $result['preview'] );
        }

        if ( null === $next_offset ) {
            if ( ! empty( $result ) ) {
                $next_offset = intval( $offset ) + intval( $limit );
            } else {
                $next_offset = null;
            }
        }

        $finished = false;
        if ( null !== $next_offset && null !== $total_groups ) {
            if ( $next_offset >= intval( $total_groups ) ) {
                $finished = true;
            }
        }

        if ( null !== $next_offset ) {
            update_option( 'drop_importer_progress_offset', $next_offset );
            dipf_log( 'AJAX chunk inferred next_offset=' . $next_offset . ' (processed=' . ( null === $processed ? 'n/a' : $processed ) . ')' );
        } else {
            dipf_log( 'AJAX chunk could not infer next_offset (offset=' . $offset . ', limit=' . $limit . ')' );
        }

        $payload = array(
            'processed'   => $processed,
            'next_offset' => $next_offset,
            'finished'    => $finished,
            'raw'         => $result,
        );

        if ( dipf_debug_enabled() ) {
            $payload['debug'] = array(
                'offset'      => $offset,
                'limit'       => $limit,
                'dry'         => $dry,
                'skip_images' => $skip_images,
                'prefer_csv'  => $prefer_csv,
            );
        }

        dipf_set_debug_flag( null );
        wp_send_json_success( $payload );
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
            '1.2.0',
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
