<?php
/**
 * Plugin Name: Drop Importer Suite
 * Description: ??????????? CSV ? WooCommerce ???????? ? ????????????? ???????, ??????? AJAX-??????? ? ????????????.
 * Version: 1.0.0
 * Author: Generated Integration
 * Text Domain: drop-importer-suite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// -----------------------------------------------------------------------------
// Constants & defaults
// -----------------------------------------------------------------------------

define( 'DROP_IMPORTER_SUITE_VERSION', '1.0.0' );
define( 'DROP_IMPORTER_SUITE_SLUG', 'drop-importer-suite' );

// -----------------------------------------------------------------------------
// Logging & debug helpers
// -----------------------------------------------------------------------------

function dropi_log_path() {
    $uploads = wp_upload_dir();
    return trailingslashit( $uploads['basedir'] ) . 'drop-importer-suite.log';
}

function dropi_log( $message ) {
    $entry = sprintf( "%s | %s%s", gmdate( 'c' ), $message, PHP_EOL );
    @file_put_contents( dropi_log_path(), $entry, FILE_APPEND | LOCK_EX );
}

function dropi_debug_enabled() {
    $default = defined( 'DROP_IMPORTER_DEBUG' ) ? (bool) DROP_IMPORTER_DEBUG : false;
    return apply_filters( 'dropi_debug_enabled', $default );
}

function dropi_debug_log( $message ) {
    if ( dropi_debug_enabled() ) {
        dropi_log( '[DEBUG] ' . $message );
    }
}

function dropi_tail_log( $lines = 120 ) {
    $file = dropi_log_path();
    if ( ! file_exists( $file ) ) {
        return 'Log not found: ' . $file;
    }

    $data = @file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
    if ( false === $data ) {
        return 'Cannot read log file.';
    }

    return implode( PHP_EOL, array_slice( $data, -absint( $lines ) ) );
}

// -----------------------------------------------------------------------------
// CSV parsing & import logic
// -----------------------------------------------------------------------------

function dropi_default_options() {
    return array(
        'delimiter'           => ';',
        'encoding'            => 'UTF-8',
        'group_by'            => 'Product ID',
        'dry_run'             => true,
        'skip_images'         => false,
        'update_existing_by'  => 'sku',
        'start_group'         => 0,
        'groups_per_batch'    => 20,
    );
}

function dropi_require_woocommerce() {
    if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
        return new WP_Error( 'woocommerce_missing', __( 'WooCommerce ??????? ??????????. ?????????, ??? WooCommerce ???????.', 'drop-importer-suite' ) );
    }
    return true;
}

function dropi_normalise_number( $value ) {
    if ( '' === $value || null === $value ) {
        return null;
    }
    $value = str_replace( array( ' ', ',' ), array( '', '.' ), trim( $value ) );
    return is_numeric( $value ) ? $value : null;
}

function dropi_sideload_image_get_id( $image_url, $post_id = 0 ) {
    if ( empty( $image_url ) ) {
        return 0;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url( $image_url );
    if ( is_wp_error( $tmp ) ) {
        return 0;
    }

    $file = array(
        'name'     => basename( parse_url( $image_url, PHP_URL_PATH ) ),
        'tmp_name' => $tmp,
    );

    $attachment_id = media_handle_sideload( $file, $post_id );

    if ( is_wp_error( $attachment_id ) ) {
        @unlink( $tmp );
        return 0;
    }

    return $attachment_id;
}

function dropi_prepare_group_key( $value, $row_index ) {
    $key = trim( (string) $value );
    if ( '' === $key ) {
        $key = sprintf( '__row_%05d', $row_index );
    }
    return $key;
}

function dropi_process_group( $group_key, $lines, $opts, &$report ) {
    $first = $lines[0];
    $title = ! empty( $first['Product name'] ) ? $first['Product name'] : sprintf( 'Product %s', $group_key );
    $description = $first['Product description'] ?? '';

    $categories = array();
    if ( ! empty( $first['Category name'] ) ) {
        $parts = preg_split( '/[|,\/]+/', $first['Category name'] );
        foreach ( $parts as $part ) {
            $part = trim( $part );
            if ( '' !== $part ) {
                $categories[] = $part;
            }
        }
    }

    $image_urls = array();
    foreach ( $lines as $line ) {
        if ( empty( $line['Images'] ) ) {
            continue;
        }
        $pieces = preg_split( '/[,;]+/', $line['Images'] );
        foreach ( $pieces as $piece ) {
            $piece = trim( $piece );
            if ( '' !== $piece ) {
                $image_urls[ $piece ] = $piece;
            }
        }
    }
    $image_urls = array_values( $image_urls );
    $is_variable = count( $lines ) > 1;

    $sku_source = isset( $first['Product codes'] ) ? trim( $first['Product codes'] ) : '';
    $existing_post_id = 0;

    if ( 'sku' === $opts['update_existing_by'] && '' !== $sku_source ) {
        $existing_post_id = wc_get_product_id_by_sku( $sku_source );
    }

    if ( $opts['dry_run'] ) {
        $report['preview'][] = array(
            'group_key'    => $group_key,
            'title'        => $title,
            'products'     => count( $lines ),
            'type'         => $is_variable ? 'variable' : 'simple',
            'sku'          => $sku_source,
            'images_count' => count( $image_urls ),
        );
        return array();
    }

    $post_id = 0;
    if ( $existing_post_id ) {
        $post_id = $existing_post_id;
        $result = wp_update_post(
            array(
                'ID'           => $post_id,
                'post_title'   => wp_strip_all_tags( $title ),
                'post_content' => $description,
            ),
            true
        );

        if ( is_wp_error( $result ) ) {
            $report['errors'][] = sprintf( 'Failed to update product %s: %s', $group_key, $result->get_error_message() );
            return array();
        }

        $report['updated_products']++;
    } else {
        $post_id = wp_insert_post(
            array(
                'post_title'   => wp_strip_all_tags( $title ),
                'post_content' => $description,
                'post_status'  => 'publish',
                'post_type'    => 'product',
            ),
            true
        );

        if ( is_wp_error( $post_id ) ) {
            $report['errors'][] = sprintf( 'Failed to create product for group %s: %s', $group_key, $post_id->get_error_message() );
            return array();
        }

        $report['created_products']++;
    }

    if ( ! empty( $categories ) ) {
        $term_ids = array();
        foreach ( $categories as $cname ) {
            $term = term_exists( $cname, 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) {
                $term_ids[] = intval( $term['term_id'] );
            } else {
                $created = wp_insert_term( $cname, 'product_cat' );
                if ( ! is_wp_error( $created ) && isset( $created['term_id'] ) ) {
                    $term_ids[] = intval( $created['term_id'] );
                }
            }
        }
        if ( ! empty( $term_ids ) ) {
            wp_set_object_terms( $post_id, $term_ids, 'product_cat' );
        }
    }

    if ( $is_variable ) {
        wp_set_object_terms( $post_id, 'variable', 'product_type' );
    } else {
        wp_set_object_terms( $post_id, 'simple', 'product_type' );
    }

    if ( ! $opts['skip_images'] ) {
        $gallery_ids = array();
        foreach ( $image_urls as $index => $image_url ) {
            $attachment_id = dropi_sideload_image_get_id( $image_url, $post_id );
            if ( $attachment_id ) {
                if ( 0 === $index ) {
                    set_post_thumbnail( $post_id, $attachment_id );
                } else {
                    $gallery_ids[] = $attachment_id;
                }
            }
        }
        if ( ! empty( $gallery_ids ) ) {
            update_post_meta( $post_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
        }
    }

    $price = dropi_normalise_number( $first['Price'] ?? null );
    if ( null !== $price ) {
        update_post_meta( $post_id, '_regular_price', $price );
        update_post_meta( $post_id, '_price', $price );
    }

    if ( '' !== $sku_source ) {
        update_post_meta( $post_id, '_sku', sanitize_text_field( $sku_source ) );
    }

    if ( isset( $first['Stock'] ) && '' !== $first['Stock'] ) {
        $stock = intval( $first['Stock'] );
        update_post_meta( $post_id, '_manage_stock', 'yes' );
        update_post_meta( $post_id, '_stock', $stock );
        update_post_meta( $post_id, '_stock_status', ( $stock > 0 ) ? 'instock' : 'outofstock' );
    }

    if ( ! empty( $first['URL'] ) ) {
        update_post_meta( $post_id, '_product_url', esc_url_raw( $first['URL'] ) );
    }

    $result_summary = array(
        'post_id'    => $post_id,
        'is_new'     => (bool) $existing_post_id === 0,
        'variations' => array(),
    );

    if ( ! $is_variable ) {
        return $result_summary;
    }

    $existing_variations = get_posts(
        array(
            'post_parent'    => $post_id,
            'post_type'      => 'product_variation',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
        )
    );

    foreach ( $existing_variations as $old_variation_id ) {
        wp_delete_post( $old_variation_id, true );
    }

    $attributes_map = array();
    foreach ( $lines as $line ) {
        if ( ! empty( $line['Parameter name'] ) && ! empty( $line['Parameter value name'] ) ) {
            $attr_name  = trim( $line['Parameter name'] );
            $attr_value = trim( $line['Parameter value name'] );
        } elseif ( ! empty( $line['Name in group'] ) ) {
            $attr_name  = 'Option';
            $attr_value = trim( $line['Name in group'] );
        } else {
            continue;
        }

        if ( '' === $attr_name || '' === $attr_value ) {
            continue;
        }

        if ( ! isset( $attributes_map[ $attr_name ] ) ) {
            $attributes_map[ $attr_name ] = array();
        }
        $attributes_map[ $attr_name ][ $attr_value ] = $attr_value;
    }

    $wc_attributes = array();
    $attr_index    = 0;
    foreach ( $attributes_map as $attr_name => $values ) {
        $taxonomy_key = wc_sanitize_taxonomy_name( $attr_name );
        $wc_attributes[ 'attribute_' . $taxonomy_key ] = array(
            'name'         => $attr_name,
            'value'        => implode( ' | ', array_values( $values ) ),
            'position'     => $attr_index,
            'is_visible'   => 1,
            'is_variation' => 1,
            'is_taxonomy'  => 0,
        );
        $attr_index++;
    }

    update_post_meta( $post_id, '_product_attributes', $wc_attributes );

    foreach ( $lines as $line ) {
        $variation_title = $title;
        if ( ! empty( $line['Name in group'] ) ) {
            $variation_title .= ' ? ' . $line['Name in group'];
        }

        $variation_post = array(
            'post_status' => 'publish',
            'post_parent' => $post_id,
            'post_type'   => 'product_variation',
            'post_title'  => $variation_title,
        );

        $variation_id = wp_insert_post( $variation_post, true );
        if ( is_wp_error( $variation_id ) ) {
            $report['errors'][] = sprintf( 'Failed to create variation in group %s: %s', $group_key, $variation_id->get_error_message() );
            continue;
        }

        $price = dropi_normalise_number( $line['Price'] ?? null );
        if ( null !== $price ) {
            update_post_meta( $variation_id, '_regular_price', $price );
            update_post_meta( $variation_id, '_price', $price );
        }

        if ( ! empty( $line['Product codes'] ) ) {
            update_post_meta( $variation_id, '_sku', sanitize_text_field( $line['Product codes'] ) );
        }

        if ( isset( $line['Stock'] ) && '' !== $line['Stock'] ) {
            $stock = intval( $line['Stock'] );
            update_post_meta( $variation_id, '_manage_stock', 'yes' );
            update_post_meta( $variation_id, '_stock', $stock );
            update_post_meta( $variation_id, '_stock_status', ( $stock > 0 ) ? 'instock' : 'outofstock' );
        }

        foreach ( $attributes_map as $attr_name => $values ) {
            $taxonomy_key = wc_sanitize_taxonomy_name( $attr_name );
            $value        = '';
            if ( ! empty( $line['Parameter value name'] ) ) {
                $value = $line['Parameter value name'];
            } elseif ( ! empty( $line['Name in group'] ) ) {
                $value = $line['Name in group'];
            }

            if ( '' !== $value ) {
                update_post_meta( $variation_id, 'attribute_' . $taxonomy_key, sanitize_text_field( $value ) );
            }
        }

        if ( ! $opts['skip_images'] && ! empty( $line['Images'] ) ) {
            $parts = preg_split( '/[,;]+/', $line['Images'] );
            $parts = array_filter( array_map( 'trim', $parts ) );
            if ( ! empty( $parts ) ) {
                $attachment_id = dropi_sideload_image_get_id( $parts[0], $variation_id );
                if ( $attachment_id ) {
                    update_post_meta( $variation_id, '_thumbnail_id', $attachment_id );
                }
            }
        }

        $result_summary['variations'][] = $variation_id;
    }

    return $result_summary;
}

function dropi_process_csv_chunk( $csv_path, $args = array() ) {
    $opts = wp_parse_args( $args, dropi_default_options() );
    $opts['start_group']      = max( 0, intval( $opts['start_group'] ) );
    $opts['groups_per_batch'] = max( 1, intval( $opts['groups_per_batch'] ) );

    $wc_check = dropi_require_woocommerce();
    if ( is_wp_error( $wc_check ) ) {
        return $wc_check;
    }

    if ( ! file_exists( $csv_path ) ) {
        return new WP_Error( 'dropi_no_file', sprintf( __( 'CSV ???? ?? ??????: %s', 'drop-importer-suite' ), esc_html( $csv_path ) ) );
    }

    $fh = fopen( $csv_path, 'r' );
    if ( ! $fh ) {
        return new WP_Error( 'dropi_cannot_open', __( '?? ??????? ??????? CSV ????.', 'drop-importer-suite' ) );
    }

    $delimiter      = $opts['delimiter'];
    $group_by       = $opts['group_by'];
    $start_group    = $opts['start_group'];
    $limit          = $opts['groups_per_batch'];
    $processed      = 0;
    $total_groups   = 0;
    $row_index      = 0;
    $errors         = array();
    $group_summaries = array();
    $preview        = array();
    $time_limit     = max( 5, (int) apply_filters( 'dropi_runtime_limit_seconds', 20 ) );
    $start_time     = microtime( true );

    $header_row = fgetcsv( $fh, 0, $delimiter );
    if ( false === $header_row ) {
        fclose( $fh );
        return new WP_Error( 'dropi_empty', __( 'CSV ?? ???????? ??????????.', 'drop-importer-suite' ) );
    }

    $header_row[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header_row[0] );
    $headers = array_map( 'trim', $header_row );

    $current_key   = null;
    $current_lines = array();

    $finalise_current_group = function () use ( &$current_lines, &$current_key, &$total_groups, &$processed, $opts, &$errors, &$group_summaries, &$preview, $start_group, $limit, $start_time, $time_limit ) {
        if ( empty( $current_lines ) ) {
            return;
        }

        $group_index = $total_groups;
        $total_groups++;

        $should_process = ( $group_index >= $start_group && $processed < $limit );

        if ( $should_process ) {
            $report = array(
                'preview'          => array(),
                'created_products' => 0,
                'updated_products' => 0,
                'errors'           => array(),
            );

            $result = dropi_process_group( $current_key, $current_lines, $opts, $report );

            if ( ! empty( $report['preview'] ) ) {
                $preview = array_merge( $preview, $report['preview'] );
            }

            $group_summaries[] = $result;
            $errors            = array_merge( $errors, $report['errors'] );
            $processed++;
        }

        $current_key   = null;
        $current_lines = array();

        if ( ( microtime( true ) - $start_time ) > $time_limit ) {
            return 'timeout';
        }

        return null;
    };

    while ( ( $row = fgetcsv( $fh, 0, $delimiter ) ) !== false ) {
        $row_index++;

        if ( count( $row ) < count( $headers ) ) {
            $row = array_pad( $row, count( $headers ), '' );
        }

        $assoc = array();
        foreach ( $headers as $index => $name ) {
            $assoc[ $name ] = isset( $row[ $index ] ) ? trim( $row[ $index ] ) : '';
        }

        $group_key = dropi_prepare_group_key( $assoc[ $group_by ] ?? '', $row_index );

        if ( null === $current_key ) {
            $current_key   = $group_key;
            $current_lines = array( $assoc );
            continue;
        }

        if ( $group_key === $current_key ) {
            $current_lines[] = $assoc;
            continue;
        }

        $finalise = $finalise_current_group();
        if ( 'timeout' === $finalise ) {
            $current_key   = $group_key;
            $current_lines = array( $assoc );
            break;
        }

        $current_key   = $group_key;
        $current_lines = array( $assoc );
    }

    if ( ! empty( $current_lines ) ) {
        $finalise_current_group();
    }

    fclose( $fh );

    $next_offset = $processed > 0 ? $start_group + $processed : null;
    if ( $next_offset !== null && $next_offset >= $total_groups ) {
        $next_offset = null;
    }

    $response = array(
        'processed_groups' => $processed,
        'start_group'      => $start_group,
        'groups_per_batch' => $limit,
        'groups_total'     => $total_groups,
        'next_offset'      => $next_offset,
        'errors'           => $errors,
        'preview'          => $opts['dry_run'] ? $preview : array(),
        'results'          => $group_summaries,
        'dry_run'          => (bool) $opts['dry_run'],
        'runtime'          => microtime( true ) - $start_time,
    );

    return $response;
}

// -----------------------------------------------------------------------------
// Admin UI
// -----------------------------------------------------------------------------

add_action( 'admin_menu', function () {
    add_management_page(
        __( 'Drop Importer Suite', 'drop-importer-suite' ),
        __( 'Drop Importer Suite', 'drop-importer-suite' ),
        'manage_options',
        DROP_IMPORTER_SUITE_SLUG,
        'dropi_render_admin_page'
    );
} );

function dropi_render_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Access denied', 'drop-importer-suite' ) );
    }

    $default_csv = WP_CONTENT_DIR . '/uploads/stock_export_full_for_varvishop.csv';
    $csv         = isset( $_POST['dropi_csv'] ) ? sanitize_text_field( wp_unslash( $_POST['dropi_csv'] ) ) : $default_csv;
    $offset      = isset( $_POST['dropi_offset'] ) ? max( 0, intval( $_POST['dropi_offset'] ) ) : 0;
    $limit       = isset( $_POST['dropi_limit'] ) ? max( 1, intval( $_POST['dropi_limit'] ) ) : 20;
    $dry         = isset( $_POST['dropi_dry'] );
    $skip_images = isset( $_POST['dropi_skip_images'] );

    $message = '';

    if ( isset( $_POST['dropi_run'] ) ) {
        if ( ! check_admin_referer( 'dropi_run_action', 'dropi_run_nonce' ) ) {
            $message = '<div class="notice notice-error"><p>' . esc_html__( 'Nonce ???????? ?? ????????.', 'drop-importer-suite' ) . '</p></div>';
        } else {
            dropi_log( sprintf( 'Manual run start (offset=%d, limit=%d, dry=%d, skip_images=%d, csv=%s)', $offset, $limit, $dry ? 1 : 0, $skip_images ? 1 : 0, $csv ) );

            $result = dropi_process_csv_chunk( $csv, array(
                'dry_run'          => $dry,
                'skip_images'      => $skip_images,
                'start_group'      => $offset,
                'groups_per_batch' => $limit,
            ) );

            if ( is_wp_error( $result ) ) {
                $message = '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
                dropi_log( 'Manual run error: ' . $result->get_error_message() );
            } else {
                $message  = '<div class="notice notice-success"><p>' . esc_html__( '?????? ????????. ?????? ????.', 'drop-importer-suite' ) . '</p></div>';
                $message .= '<div style="background:#fff;border:1px solid #ddd;padding:10px;"><pre>' . esc_html( print_r( $result, true ) ) . '</pre></div>';
                dropi_log( sprintf( 'Manual run success: processed=%d next=%s total=%d runtime=%.3fs', $result['processed_groups'], ( null === $result['next_offset'] ? 'null' : $result['next_offset'] ), $result['groups_total'], $result['runtime'] ) );
            }
        }
    }

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__( 'Drop Importer Suite', 'drop-importer-suite' ) . '</h1>';
    echo '<p>' . esc_html__( '??? ?????????? ?????? ??????????? ???????. ??????????? dry-run ???????, ????? ???????? ?????????? ??? ?????? ????????.', 'drop-importer-suite' ) . '</p>';
    if ( $message ) {
        echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    ?>
    <form method="post" style="background:#fff;padding:12px;border:1px solid #ddd;margin-bottom:20px;">
        <?php wp_nonce_field( 'dropi_run_action', 'dropi_run_nonce' ); ?>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="dropi_csv"><?php esc_html_e( 'CSV ????', 'drop-importer-suite' ); ?></label></th>
                <td><input type="text" name="dropi_csv" id="dropi_csv" value="<?php echo esc_attr( $csv ); ?>" style="width:70%;"></td>
            </tr>
            <tr>
                <th scope="row"><label for="dropi_offset"><?php esc_html_e( '????????? offset', 'drop-importer-suite' ); ?></label></th>
                <td><input type="number" name="dropi_offset" id="dropi_offset" value="<?php echo esc_attr( $offset ); ?>" min="0"></td>
            </tr>
            <tr>
                <th scope="row"><label for="dropi_limit"><?php esc_html_e( '????? ?? ????', 'drop-importer-suite' ); ?></label></th>
                <td><input type="number" name="dropi_limit" id="dropi_limit" value="<?php echo esc_attr( $limit ); ?>" min="1"> <span class="description"><?php esc_html_e( '????????????? 10?30.', 'drop-importer-suite' ); ?></span></td>
            </tr>
            <tr>
                <th scope="row">Dry run</th>
                <td><label><input type="checkbox" name="dropi_dry" value="1" <?php checked( $dry ); ?>> <?php esc_html_e( '?????? ??????, ??? ?????? ? ??', 'drop-importer-suite' ); ?></label></td>
            </tr>
            <tr>
                <th scope="row">Skip images</th>
                <td><label><input type="checkbox" name="dropi_skip_images" value="1" <?php checked( $skip_images ); ?>> <?php esc_html_e( '?? ????????? ??????????? (?????????? ?????)', 'drop-importer-suite' ); ?></label></td>
            </tr>
        </table>
        <p class="submit"><input type="submit" class="button button-primary" name="dropi_run" value="<?php esc_attr_e( '????????? ?????', 'drop-importer-suite' ); ?>"></p>
    </form>

    <div id="dropi_controls" style="background:#fff;padding:12px;border:1px solid #ddd;margin-bottom:20px;"></div>

    <h2><?php esc_html_e( '??? (????????? 200 ?????)', 'drop-importer-suite' ); ?></h2>
    <div style="background:#fff;border:1px solid #ddd;padding:12px;">
        <pre style="max-height:360px;overflow:auto;white-space:pre-wrap;"><?php echo esc_html( dropi_tail_log( 200 ) ); ?></pre>
    </div>
    </div>
    <?php
}

// -----------------------------------------------------------------------------
// AJAX chunk runner
// -----------------------------------------------------------------------------

add_action( 'wp_ajax_dropi_run_chunk', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'no_permission' ), 403 );
    }

    $nonce = isset( $_POST['dropi_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['dropi_nonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'dropi_chunk_nonce' ) ) {
        wp_send_json_error( array( 'message' => 'bad_nonce' ), 400 );
    }

    $csv         = isset( $_POST['csv'] ) ? sanitize_text_field( wp_unslash( $_POST['csv'] ) ) : '';
    $offset      = isset( $_POST['offset'] ) ? max( 0, intval( $_POST['offset'] ) ) : 0;
    $limit       = isset( $_POST['limit'] ) ? max( 1, intval( $_POST['limit'] ) ) : 10;
    $dry         = ! empty( $_POST['dry'] );
    $skip_images = ! empty( $_POST['skip_images'] );
    $debug       = ! empty( $_POST['debug'] );

    $filters_added = false;
    if ( $skip_images ) {
        add_filter(
            'pre_http_request',
            function ( $preempt, $args, $url ) {
                if ( preg_match( '/\.(jpe?g|png|gif|webp)(\?.*)?$/i', $url ) ) {
                    return new WP_Error( 'dropi_skip_image', 'Image download skipped by Drop Importer Suite' );
                }
                return $preempt;
            },
            10,
            3
        );
        $filters_added = true;
    }

    dropi_debug_log( sprintf( 'AJAX chunk start offset=%d limit=%d dry=%d skip_images=%d', $offset, $limit, $dry ? 1 : 0, $skip_images ? 1 : 0 ) );

    $result = dropi_process_csv_chunk( $csv, array(
        'dry_run'          => $dry,
        'skip_images'      => $skip_images,
        'start_group'      => $offset,
        'groups_per_batch' => $limit,
    ) );

    if ( is_wp_error( $result ) ) {
        dropi_log( 'AJAX chunk error: ' . $result->get_error_message() );
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    dropi_log( sprintf( 'AJAX chunk summary offset=%d processed=%d next=%s total=%d runtime=%.3fs dry=%d', $offset, $result['processed_groups'], ( null === $result['next_offset'] ? 'null' : $result['next_offset'] ), $result['groups_total'], $result['runtime'], $dry ? 1 : 0 ) );

    $payload = array(
        'processed'   => $result['processed_groups'],
        'next_offset' => $result['next_offset'],
        'finished'    => ( null === $result['next_offset'] ),
        'total'       => $result['groups_total'],
        'meta'        => array(
            'runtime'      => $result['runtime'],
            'errors'       => $result['errors'],
            'dry_run'      => $result['dry_run'],
            'batch_limit'  => $limit,
            'offset_start' => $offset,
        ),
    );

    if ( $debug ) {
        $payload['debug'] = $result;
    }

    wp_send_json_success( $payload );
} );

// -----------------------------------------------------------------------------
// Assets
// -----------------------------------------------------------------------------

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( 'tools_page_' . DROP_IMPORTER_SUITE_SLUG !== $hook ) {
        return;
    }

    wp_enqueue_script(
        'drop-importer-suite-runner',
        plugin_dir_url( __FILE__ ) . 'drop-importer-suite-runner.js',
        array( 'jquery' ),
        DROP_IMPORTER_SUITE_VERSION,
        true
    );

    wp_localize_script(
        'drop-importer-suite-runner',
        'dropiRunner',
        array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'dropi_chunk_nonce' ),
        )
    );
} );

// -----------------------------------------------------------------------------
// WP-CLI command for unattended imports
// -----------------------------------------------------------------------------

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'dropi import', function ( $args, $assoc_args ) {
        $csv         = isset( $assoc_args['file'] ) ? $assoc_args['file'] : WP_CONTENT_DIR . '/uploads/stock_export_full_for_varvishop.csv';
        $dry         = isset( $assoc_args['dry-run'] );
        $skip_images = isset( $assoc_args['skip-images'] );
        $limit       = isset( $assoc_args['limit'] ) ? max( 1, intval( $assoc_args['limit'] ) ) : 20;

        $offset = 0;

        WP_CLI::log( sprintf( 'Starting import file=%s dry=%d skip_images=%d limit=%d', $csv, $dry ? 1 : 0, $skip_images ? 1 : 0, $limit ) );

        while ( true ) {
            $result = dropi_process_csv_chunk( $csv, array(
                'dry_run'          => $dry,
                'skip_images'      => $skip_images,
                'start_group'      => $offset,
                'groups_per_batch' => $limit,
            ) );

            if ( is_wp_error( $result ) ) {
                WP_CLI::error( $result->get_error_message() );
                break;
            }

            WP_CLI::log( sprintf( 'Processed %d groups (offset %d ? %s) runtime=%.3fs', $result['processed_groups'], $offset, ( null === $result['next_offset'] ? 'done' : $result['next_offset'] ), $result['runtime'] ) );

            if ( null === $result['next_offset'] || 0 === $result['processed_groups'] ) {
                break;
            }

            $offset = $result['next_offset'];
        }

        WP_CLI::success( 'Import finished.' );
    } );
}

