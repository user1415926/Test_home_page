<?php
/**
 * Plugin Name: Drop Importer Suite
 * Description: Full XML (IOF) to WooCommerce importer with batched runner, logging, and CLI support.
 * Version: 2.0.0
 * Author: Generated Integration
 * Text Domain: drop-importer-suite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// -----------------------------------------------------------------------------
// Constants & defaults
// -----------------------------------------------------------------------------

define( 'DROP_IMPORTER_SUITE_VERSION', '2.0.0' );
define( 'DROP_IMPORTER_SUITE_SLUG', 'drop-importer-suite' );

// -----------------------------------------------------------------------------
// Logging & debug helpers
// -----------------------------------------------------------------------------

function dropi_log_path() {
    $uploads = wp_upload_dir();
    return trailingslashit( $uploads['basedir'] ) . 'drop-importer-suite.log';
}

function dropi_log( $message ) {
    $entry = sprintf( '%s | %s%s', gmdate( 'c' ), $message, PHP_EOL );
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
// Utilities
// -----------------------------------------------------------------------------

function dropi_default_options() {
    return array(
        'dry_run'            => true,
        'skip_images'        => false,
        'update_existing_by' => 'sku',
        'start_group'        => 0,
        'groups_per_batch'   => 20,
        'language'           => 'pol',
    );
}

function dropi_require_woocommerce() {
    if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
        return new WP_Error( 'woocommerce_missing', __( 'WooCommerce functions are unavailable. Please ensure WooCommerce is active.', 'drop-importer-suite' ) );
    }
    return true;
}

function dropi_normalise_number( $value ) {
    if ( '' === $value || null === $value ) {
        return null;
    }

    if ( is_numeric( $value ) ) {
        return (float) $value;
    }

    $value = str_replace( array( ' ', '&nbsp;' ), '', (string) $value );
    $value = strtr( $value, array( ',' => '.' ) );

    return is_numeric( $value ) ? (float) $value : null;
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

function dropi_xmlreader_skip_current( XMLReader $reader ) {
    if ( $reader->isEmptyElement ) {
        return;
    }

    $depth = 1;

    while ( $depth > 0 && $reader->read() ) {
        if ( XMLReader::ELEMENT === $reader->nodeType && ! $reader->isEmptyElement ) {
            $depth++;
        } elseif ( XMLReader::END_ELEMENT === $reader->nodeType ) {
            $depth--;
        }
    }
}

function dropi_xml_attr( SimpleXMLElement $element = null, $name = '', $namespace = null ) {
    if ( ! $element || '' === $name ) {
        return '';
    }

    $attributes = $namespace ? $element->attributes( $namespace, true ) : $element->attributes();

    if ( isset( $attributes[ $name ] ) ) {
        return trim( (string) $attributes[ $name ] );
    }

    return '';
}

function dropi_xml_localized_child( SimpleXMLElement $parent = null, $tag = '', $preferred_lang = 'pol', $fallbacks = array() ) {
    if ( ! $parent || '' === $tag || ! isset( $parent->{$tag} ) ) {
        return '';
    }

    $candidates = array();
    foreach ( $parent->{$tag} as $node ) {
        $lang = dropi_xml_attr( $node, 'lang', 'xml' );
        $value = trim( (string) $node );
        if ( '' === $value ) {
            continue;
        }
        if ( $lang === $preferred_lang ) {
            return $value;
        }
        $candidates[ $lang ?: '__default' ] = $value;
    }

    $fallbacks = array_merge( (array) $fallbacks, array( 'eng', 'en', 'pol', '__default' ) );
    foreach ( $fallbacks as $fallback ) {
        if ( isset( $candidates[ $fallback ] ) ) {
            return $candidates[ $fallback ];
        }
    }

    if ( ! empty( $candidates ) ) {
        return reset( $candidates );
    }

    return '';
}

function dropi_xml_collect_images( SimpleXMLElement $product ) {
    $images = array();

    if ( isset( $product->images->large->image ) ) {
        foreach ( $product->images->large->image as $image ) {
            $preferred = dropi_xml_attr( $image, 'url2', 'iaiext' );
            if ( '' === $preferred ) {
                $preferred = dropi_xml_attr( $image, 'url' );
            }
            if ( '' !== $preferred ) {
                $images[ $preferred ] = $preferred;
            }
        }
    }

    if ( empty( $images ) && isset( $product->images->icons->icon ) ) {
        foreach ( $product->images->icons->icon as $image ) {
            $preferred = dropi_xml_attr( $image, 'url2', 'iaiext' );
            if ( '' === $preferred ) {
                $preferred = dropi_xml_attr( $image, 'url' );
            }
            if ( '' !== $preferred ) {
                $images[ $preferred ] = $preferred;
            }
        }
    }

    return array_values( $images );
}

function dropi_xml_collect_shared_attributes( SimpleXMLElement $product, $lang ) {
    $attributes = array();

    if ( isset( $product->producer ) ) {
        $producer = dropi_xml_attr( $product->producer, 'name' );
        if ( '' !== $producer ) {
            $attributes[ __( 'Producer', 'drop-importer-suite' ) ] = $producer;
        }
    }

    if ( isset( $product->group ) ) {
        foreach ( $product->group as $group_node ) {
            if ( ! isset( $group_node->group_by_parameter ) ) {
                continue;
            }

            $param_name = dropi_xml_localized_child( $group_node->group_by_parameter, 'name', $lang );
            $value      = '';
            if ( isset( $group_node->group_by_parameter->product_value ) ) {
                $value = dropi_xml_localized_child( $group_node->group_by_parameter->product_value, 'name', $lang );
            }

            if ( '' !== $param_name && '' !== $value ) {
                $attributes[ $param_name ] = $value;
            }
        }
    }

    if ( isset( $product->parameters->parameter ) ) {
        foreach ( $product->parameters->parameter as $parameter ) {
            $name = trim( (string) $parameter['name'] );
            if ( '' === $name || isset( $attributes[ $name ] ) ) {
                continue;
            }

            $values = array();
            if ( isset( $parameter->value ) ) {
                foreach ( $parameter->value as $value_node ) {
                    $value_name = dropi_xml_attr( $value_node, 'name' );
                    if ( '' !== $value_name ) {
                        $values[ $value_name ] = $value_name;
                    }
                }
            }

            if ( ! empty( $values ) ) {
                $attributes[ $name ] = implode( ' | ', array_values( $values ) );
            }
        }
    }

    return $attributes;
}

function dropi_xml_collect_variations( SimpleXMLElement $product, $lang ) {
    $variations = array();

    if ( isset( $product->sizes->size ) ) {
        foreach ( $product->sizes->size as $size ) {
            $label = dropi_xml_attr( $size, 'name' );
            $code  = dropi_xml_attr( $size, 'code' );
            $sku   = $code;

            if ( '' === $sku ) {
                $sku = dropi_xml_attr( $size, 'code_producer' );
            }
            if ( '' === $sku ) {
                $sku = dropi_xml_attr( $size, 'code_external', 'iaiext' );
            }

            $price_value = null;
            if ( isset( $size->price ) ) {
                $price_value = dropi_normalise_number( dropi_xml_attr( $size->price, 'gross' ) );
            }

            $stock_value = null;
            if ( isset( $size->stock ) ) {
                $stock_attr = dropi_xml_attr( $size->stock, 'stock_quantity' );
                if ( '' === $stock_attr ) {
                    $stock_attr = dropi_xml_attr( $size->stock, 'available_stock_quantity' );
                }
                if ( '' === $stock_attr ) {
                    $stock_attr = dropi_xml_attr( $size->stock, 'quantity' );
                }
                if ( '' !== $stock_attr ) {
                    $parsed = dropi_normalise_number( $stock_attr );
                    $stock_value = ( null !== $parsed ) ? (int) round( $parsed ) : null;
                }
            }

            $weight_value = dropi_normalise_number( dropi_xml_attr( $size, 'weight' ) );
            if ( null === $weight_value ) {
                $weight_value = dropi_normalise_number( dropi_xml_attr( $size, 'weight_net', 'iaiext' ) );
            }

            if ( '' === $label && '' === $sku && null === $price_value && null === $stock_value ) {
                continue;
            }

            $variations[] = array(
                'label'      => $label,
                'sku'        => $sku,
                'price'      => $price_value,
                'stock'      => $stock_value,
                'attributes' => array( __( 'Size', 'drop-importer-suite' ) => ( '' !== $label ? $label : __( 'Default', 'drop-importer-suite' ) ) ),
                'weight'     => $weight_value,
            );
        }
    }

    return $variations;
}

function dropi_build_group_from_xml( SimpleXMLElement $product, $lang ) {
    $group_id   = dropi_xml_attr( $product, 'id' );
    $group_key  = $group_id ? 'product-' . $group_id : dropi_xml_attr( $product, 'code_on_card' );
    $base_sku   = dropi_xml_attr( $product, 'code_on_card' );
    $title      = '';
    $short_desc = '';
    $long_desc  = '';

    if ( isset( $product->description ) ) {
        $title      = dropi_xml_localized_child( $product->description, 'name', $lang );
        $short_desc = dropi_xml_localized_child( $product->description, 'short_desc', $lang );
        $long_desc  = dropi_xml_localized_child( $product->description, 'long_desc', $lang );
    }

    if ( '' === $title ) {
        $title = dropi_xml_attr( $product, 'code_on_card' );
    }

    $categories = array();
    if ( isset( $product->category ) ) {
        $category_name = dropi_xml_attr( $product->category, 'name' );
        if ( '' !== $category_name ) {
            $categories[] = $category_name;
        }
    }

    $external_url = '';
    if ( isset( $product->card ) ) {
        $external_url = dropi_xml_attr( $product->card, 'url' );
    }

    $images             = dropi_xml_collect_images( $product );
    $shared_attributes  = dropi_xml_collect_shared_attributes( $product, $lang );
    $variations         = dropi_xml_collect_variations( $product, $lang );
    $base_price         = null;
    $product_price_node = isset( $product->price ) ? $product->price : null;

    if ( null !== $product_price_node ) {
        $base_price = dropi_normalise_number( dropi_xml_attr( $product_price_node, 'gross' ) );
    }

    if ( empty( $variations ) ) {
        $variations[] = array(
            'label'      => __( 'Default', 'drop-importer-suite' ),
            'sku'        => $base_sku,
            'price'      => $base_price,
            'stock'      => null,
            'attributes' => array(),
            'weight'     => null,
        );
    }

    if ( '' === $base_sku && ! empty( $variations ) ) {
        foreach ( $variations as $variation ) {
            if ( ! empty( $variation['sku'] ) ) {
                $base_sku = $variation['sku'];
                break;
            }
        }
    }

    return array(
        'group_key'         => $group_key ?: uniqid( 'dropi_', true ),
        'title'             => $title,
        'short_description' => $short_desc,
        'description'       => $long_desc,
        'categories'        => $categories,
        'images'            => $images,
        'variations'        => $variations,
        'shared_attributes' => $shared_attributes,
        'base_price'        => $base_price,
        'base_sku'          => $base_sku,
        'external_url'      => $external_url,
        'language'          => $lang,
    );
}

function dropi_process_group( $group, $opts, &$report ) {
    $group_key   = $group['group_key'];
    $title       = $group['title'] ?: sprintf( 'Product %s', $group_key );
    $description = $group['description'];
    $excerpt     = $group['short_description'];
    $categories  = (array) $group['categories'];
    $image_urls  = (array) $group['images'];
    $variations  = (array) $group['variations'];
    $attributes  = (array) $group['shared_attributes'];
    $base_sku    = $group['base_sku'];
    $external_url = $group['external_url'];

    $is_variable = ( count( $variations ) > 1 );

    if ( $opts['dry_run'] ) {
        $report['preview'][] = array(
            'group_key'    => $group_key,
            'title'        => $title,
            'products'     => count( $variations ),
            'type'         => $is_variable ? 'variable' : 'simple',
            'sku'          => $base_sku,
            'images_count' => count( $image_urls ),
        );
        return array();
    }

    $existing_post_id = 0;
    if ( 'sku' === $opts['update_existing_by'] && '' !== $base_sku ) {
        $existing_post_id = wc_get_product_id_by_sku( $base_sku );
    }

    $post_data = array(
        'post_title'   => wp_strip_all_tags( $title ),
        'post_content' => wp_kses_post( $description ),
        'post_excerpt' => wp_kses_post( $excerpt ),
        'post_status'  => 'publish',
        'post_type'    => 'product',
    );

    if ( $existing_post_id ) {
        $post_data['ID'] = $existing_post_id;
        $result          = wp_update_post( $post_data, true );
        if ( is_wp_error( $result ) ) {
            $report['errors'][] = sprintf( 'Failed to update product %s: %s', $group_key, $result->get_error_message() );
            return array();
        }
        $post_id = $existing_post_id;
        $report['updated_products']++;
    } else {
        $post_id = wp_insert_post( $post_data, true );
        if ( is_wp_error( $post_id ) ) {
            $report['errors'][] = sprintf( 'Failed to create product for group %s: %s', $group_key, $post_id->get_error_message() );
            return array();
        }
        $report['created_products']++;
    }

    if ( ! empty( $categories ) ) {
        $term_ids = array();
        foreach ( $categories as $name ) {
            $name = trim( $name );
            if ( '' === $name ) {
                continue;
            }
            $term = term_exists( $name, 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) {
                $term_ids[] = (int) $term['term_id'];
            } else {
                $created = wp_insert_term( $name, 'product_cat' );
                if ( ! is_wp_error( $created ) && isset( $created['term_id'] ) ) {
                    $term_ids[] = (int) $created['term_id'];
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

    if ( '' !== $base_sku ) {
        update_post_meta( $post_id, '_sku', sanitize_text_field( $base_sku ) );
    }

    if ( '' !== $external_url ) {
        update_post_meta( $post_id, '_product_url', esc_url_raw( $external_url ) );
    }

    $variation_attributes = array();
    foreach ( $variations as $variation ) {
        foreach ( $variation['attributes'] as $attr_name => $attr_value ) {
            $variation_attributes[ $attr_name ][ $attr_value ] = $attr_value;
        }
    }

    $wc_attributes = array();
    $position      = 0;

    foreach ( $variation_attributes as $attr_name => $values ) {
        $taxonomy_key = wc_sanitize_taxonomy_name( $attr_name );
        $wc_attributes[ 'attribute_' . $taxonomy_key ] = array(
            'name'         => $attr_name,
            'value'        => implode( ' | ', array_values( $values ) ),
            'position'     => $position,
            'is_visible'   => 1,
            'is_variation' => $is_variable ? 1 : 0,
            'is_taxonomy'  => 0,
        );
        $position++;
    }

    foreach ( $attributes as $attr_name => $attr_value ) {
        if ( '' === $attr_value ) {
            continue;
        }
        $taxonomy_key = wc_sanitize_taxonomy_name( $attr_name );
        if ( isset( $wc_attributes[ 'attribute_' . $taxonomy_key ] ) ) {
            continue;
        }
        $wc_attributes[ 'attribute_' . $taxonomy_key ] = array(
            'name'         => $attr_name,
            'value'        => $attr_value,
            'position'     => $position,
            'is_visible'   => 1,
            'is_variation' => 0,
            'is_taxonomy'  => 0,
        );
        $position++;
    }

    if ( ! empty( $wc_attributes ) ) {
        update_post_meta( $post_id, '_product_attributes', $wc_attributes );
    } else {
        delete_post_meta( $post_id, '_product_attributes' );
    }

    $result_summary = array(
        'post_id'    => $post_id,
        'is_new'     => ( $existing_post_id === 0 ),
        'variations' => array(),
    );

    if ( ! $is_variable ) {
        $variation = $variations[0];
        if ( null !== $variation['price'] ) {
            update_post_meta( $post_id, '_regular_price', $variation['price'] );
            update_post_meta( $post_id, '_price', $variation['price'] );
        } elseif ( null !== $group['base_price'] ) {
            update_post_meta( $post_id, '_regular_price', $group['base_price'] );
            update_post_meta( $post_id, '_price', $group['base_price'] );
        }

        if ( ! empty( $variation['sku'] ) && '' === $base_sku ) {
            update_post_meta( $post_id, '_sku', sanitize_text_field( $variation['sku'] ) );
        }

        if ( null !== $variation['stock'] ) {
            update_post_meta( $post_id, '_manage_stock', 'yes' );
            update_post_meta( $post_id, '_stock', $variation['stock'] );
            update_post_meta( $post_id, '_stock_status', ( $variation['stock'] > 0 ) ? 'instock' : 'outofstock' );
        } else {
            update_post_meta( $post_id, '_manage_stock', 'no' );
            update_post_meta( $post_id, '_stock_status', 'instock' );
        }

        if ( null !== $variation['weight'] ) {
            update_post_meta( $post_id, '_weight', $variation['weight'] );
        }

        return $result_summary;
    }

    // Variable product specific handling.
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

    foreach ( $variations as $variation ) {
        $variation_title = $title;
        if ( ! empty( $variation['label'] ) ) {
            $variation_title .= ' - ' . $variation['label'];
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

        if ( null !== $variation['price'] ) {
            update_post_meta( $variation_id, '_regular_price', $variation['price'] );
            update_post_meta( $variation_id, '_price', $variation['price'] );
        }

        if ( ! empty( $variation['sku'] ) ) {
            update_post_meta( $variation_id, '_sku', sanitize_text_field( $variation['sku'] ) );
        }

        if ( null !== $variation['stock'] ) {
            update_post_meta( $variation_id, '_manage_stock', 'yes' );
            update_post_meta( $variation_id, '_stock', $variation['stock'] );
            update_post_meta( $variation_id, '_stock_status', ( $variation['stock'] > 0 ) ? 'instock' : 'outofstock' );
        } else {
            update_post_meta( $variation_id, '_manage_stock', 'no' );
            update_post_meta( $variation_id, '_stock_status', 'instock' );
        }

        if ( null !== $variation['weight'] ) {
            update_post_meta( $variation_id, '_weight', $variation['weight'] );
        }

        foreach ( $variation['attributes'] as $attr_name => $attr_value ) {
            $taxonomy_key = wc_sanitize_taxonomy_name( $attr_name );
            update_post_meta( $variation_id, 'attribute_' . $taxonomy_key, sanitize_text_field( $attr_value ) );
        }

        $result_summary['variations'][] = $variation_id;
    }

    if ( class_exists( 'WC_Product_Variable' ) && method_exists( 'WC_Product_Variable', 'sync' ) ) {
        WC_Product_Variable::sync( $post_id );
    }

    return $result_summary;
}

function dropi_process_feed_chunk( $xml_path, $args = array() ) {
    $opts = wp_parse_args( $args, dropi_default_options() );
    $opts['start_group']      = max( 0, intval( $opts['start_group'] ) );
    $opts['groups_per_batch'] = max( 1, intval( $opts['groups_per_batch'] ) );
    $lang                     = isset( $opts['language'] ) ? $opts['language'] : 'pol';

    $wc_check = dropi_require_woocommerce();
    if ( is_wp_error( $wc_check ) ) {
        return $wc_check;
    }

    if ( ! file_exists( $xml_path ) ) {
        return new WP_Error( 'dropi_no_file', sprintf( __( 'XML file not found: %s', 'drop-importer-suite' ), esc_html( $xml_path ) ) );
    }

    $reader = new XMLReader();
    if ( ! $reader->open( $xml_path ) ) {
        return new WP_Error( 'dropi_cannot_open', __( 'Unable to open the XML file.', 'drop-importer-suite' ) );
    }

    $start_group    = $opts['start_group'];
    $limit          = $opts['groups_per_batch'];
    $processed      = 0;
    $total_groups   = 0;
    $errors         = array();
    $group_summaries = array();
    $preview        = array();
    $time_limit     = max( 5, (int) apply_filters( 'dropi_runtime_limit_seconds', 20 ) );
    $start_time     = microtime( true );
    $timed_out      = false;
    $has_more       = false;

    while ( $reader->read() ) {
        if ( XMLReader::ELEMENT !== $reader->nodeType || 'product' !== $reader->localName ) {
            continue;
        }

        $group_index = $total_groups;
        $total_groups++;

        if ( ( microtime( true ) - $start_time ) > $time_limit ) {
            $timed_out = true;
            break;
        }

        if ( $group_index < $start_group ) {
            dropi_debug_log( sprintf( 'Dropi: skipping group %d before start %d', $group_index, $start_group ) );
            dropi_xmlreader_skip_current( $reader );
            continue;
        }

        $node = $reader->expand();
        if ( ! $node ) {
            dropi_debug_log( sprintf( 'Dropi: unable to expand node for group %d', $group_index ) );
            dropi_xmlreader_skip_current( $reader );
            continue;
        }

        $dom     = new DOMDocument();
        $import  = $dom->importNode( $node, true );
        if ( ! $import ) {
            dropi_debug_log( sprintf( 'Dropi: DOM import failed for group %d', $group_index ) );
            dropi_xmlreader_skip_current( $reader );
            continue;
        }

        $dom->appendChild( $import );

        $libxml_previous = libxml_use_internal_errors( true );
        $simple          = simplexml_import_dom( $dom );
        $libxml_errors   = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors( $libxml_previous );

        if ( ! $simple ) {
            dropi_debug_log( sprintf( 'Dropi: simplexml_import_dom failed for group %d errors=%d', $group_index, count( $libxml_errors ) ) );
            dropi_xmlreader_skip_current( $reader );
            continue;
        }

        $group_data = dropi_build_group_from_xml( $simple, $lang );

        dropi_debug_log( sprintf(
            'Dropi: processing group idx=%d key=%s sku=%s variations=%d dry=%d',
            $group_index,
            isset( $group_data['group_key'] ) ? $group_data['group_key'] : 'n/a',
            isset( $group_data['base_sku'] ) ? $group_data['base_sku'] : '',
            isset( $group_data['variations'] ) ? count( (array) $group_data['variations'] ) : 0,
            $opts['dry_run'] ? 1 : 0
        ) );

        $report = array(
            'preview'          => array(),
            'created_products' => 0,
            'updated_products' => 0,
            'errors'           => array(),
        );

        $result = dropi_process_group( $group_data, $opts, $report );

        if ( ! empty( $report['preview'] ) ) {
            $preview = array_merge( $preview, $report['preview'] );
        }

        if ( ! empty( $result ) ) {
            $group_summaries[] = $result;
        }

        $errors = array_merge( $errors, $report['errors'] );

        $processed++;

        dropi_debug_log( sprintf(
            'Dropi: completed group idx=%d processed_count=%d created_in_batch=%d updated_in_batch=%d errors_in_group=%d',
            $group_index,
            $processed,
            $report['created_products'],
            $report['updated_products'],
            count( $report['errors'] )
        ) );

        dropi_xmlreader_skip_current( $reader );

        if ( $processed >= $limit ) {
            dropi_debug_log( sprintf( 'Dropi: processed limit reached (%d)', $limit ) );
            $has_more = true;
            break;
        }

        if ( ( microtime( true ) - $start_time ) > $time_limit ) {
            $timed_out = true;
            dropi_debug_log( 'Dropi: runtime limit reached' );
            $has_more = true;
            break;
        }
    }

    $reader->close();

    dropi_debug_log( sprintf(
        'Dropi: chunk finished processed=%d total_groups=%d start_group=%d next_offset=%s dry=%d',
        $processed,
        $total_groups,
        $start_group,
        ( ( $processed > 0 ) ? (string) ( $start_group + $processed ) : 'null' ),
        $opts['dry_run'] ? 1 : 0
    ) );

    $next_offset = null;
    if ( $processed > 0 && $has_more ) {
        $next_offset = $start_group + $processed;
    }

    if ( $processed === 0 && $has_more ) {
        $next_offset = $start_group;
    }

    $created_count = 0;
    $updated_count = 0;
    foreach ( $group_summaries as $summary ) {
        if ( isset( $summary['is_new'] ) ) {
            if ( $summary['is_new'] ) {
                $created_count++;
            } else {
                $updated_count++;
            }
        }
    }

    $reported_total = $total_groups;
    if ( $has_more ) {
        $reported_total = max( $total_groups, $start_group + $processed + 1 );
    }

    return array(
        'processed_groups' => $processed,
        'start_group'      => $start_group,
        'groups_per_batch' => $limit,
        'groups_total'     => $reported_total,
        'next_offset'      => $next_offset,
        'errors'           => $errors,
        'preview'          => $opts['dry_run'] ? $preview : array(),
        'results'          => $group_summaries,
        'dry_run'          => (bool) $opts['dry_run'],
        'runtime'          => microtime( true ) - $start_time,
        'created_products' => $created_count,
        'updated_products' => $updated_count,
        'timed_out'        => $timed_out,
    );
}

function dropi_process_csv_chunk( $xml_path, $args = array() ) { // Backwards compatibility wrapper.
    return dropi_process_feed_chunk( $xml_path, $args );
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

    $default_xml = WP_CONTENT_DIR . '/uploads/stock_export_full_for_varvishop.xml';
    $xml_path    = isset( $_POST['dropi_xml'] ) ? sanitize_text_field( wp_unslash( $_POST['dropi_xml'] ) ) : $default_xml;
    $offset      = isset( $_POST['dropi_offset'] ) ? max( 0, intval( $_POST['dropi_offset'] ) ) : 0;
    $limit       = isset( $_POST['dropi_limit'] ) ? max( 1, intval( $_POST['dropi_limit'] ) ) : 20;
    $dry         = isset( $_POST['dropi_dry'] );
    $skip_images = isset( $_POST['dropi_skip_images'] );
    $language    = isset( $_POST['dropi_language'] ) ? sanitize_text_field( wp_unslash( $_POST['dropi_language'] ) ) : 'pol';

    $message = '';

    if ( isset( $_POST['dropi_run'] ) ) {
        if ( ! check_admin_referer( 'dropi_run_action', 'dropi_run_nonce' ) ) {
            $message = '<div class="notice notice-error"><p>' . esc_html__( 'Nonce check failed.', 'drop-importer-suite' ) . '</p></div>';
        } else {
            dropi_log( sprintf( 'Manual run start (offset=%d, limit=%d, dry=%d, skip_images=%d, language=%s, xml=%s)', $offset, $limit, $dry ? 1 : 0, $skip_images ? 1 : 0, $language, $xml_path ) );

            $result = dropi_process_feed_chunk( $xml_path, array(
                'dry_run'          => $dry,
                'skip_images'      => $skip_images,
                'start_group'      => $offset,
                'groups_per_batch' => $limit,
                'language'         => $language,
            ) );

            if ( is_wp_error( $result ) ) {
                $message = '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
                dropi_log( 'Manual run error: ' . $result->get_error_message() );
            } else {
                $message  = '<div class="notice notice-success"><p>' . esc_html__( 'Import complete. Summary below.', 'drop-importer-suite' ) . '</p></div>';
                $message .= '<div style="background:#fff;border:1px solid #ddd;padding:10px;"><pre>' . esc_html( print_r( $result, true ) ) . '</pre></div>';
                dropi_log( sprintf( 'Manual run success: processed=%d next=%s total=%d runtime=%.3fs created=%d updated=%d dry=%d', $result['processed_groups'], ( null === $result['next_offset'] ? 'null' : $result['next_offset'] ), $result['groups_total'], $result['runtime'], $result['created_products'], $result['updated_products'], $result['dry_run'] ? 1 : 0 ) );
            }
        }
    }

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__( 'Drop Importer Suite', 'drop-importer-suite' ) . '</h1>';
    echo '<p>' . esc_html__( 'Import runs in batches for reliability. Start with a dry run, then enable the background runner for full sync.', 'drop-importer-suite' ) . '</p>';
    if ( $message ) {
        echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    ?>
    <form method="post" style="background:#fff;padding:12px;border:1px solid #ddd;margin-bottom:20px;">
        <?php wp_nonce_field( 'dropi_run_action', 'dropi_run_nonce' ); ?>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="dropi_xml"><?php esc_html_e( 'XML path', 'drop-importer-suite' ); ?></label></th>
                <td><input type="text" name="dropi_xml" id="dropi_xml" value="<?php echo esc_attr( $xml_path ); ?>" style="width:70%;"></td>
            </tr>
            <tr>
                <th scope="row"><label for="dropi_offset"><?php esc_html_e( 'Starting offset', 'drop-importer-suite' ); ?></label></th>
                <td><input type="number" name="dropi_offset" id="dropi_offset" value="<?php echo esc_attr( $offset ); ?>" min="0"></td>
            </tr>
            <tr>
                <th scope="row"><label for="dropi_limit"><?php esc_html_e( 'Products per batch', 'drop-importer-suite' ); ?></label></th>
                <td><input type="number" name="dropi_limit" id="dropi_limit" value="<?php echo esc_attr( $limit ); ?>" min="1"> <span class="description"><?php esc_html_e( 'Recommended 10-30.', 'drop-importer-suite' ); ?></span></td>
            </tr>
            <tr>
                <th scope="row"><label for="dropi_language"><?php esc_html_e( 'Preferred language', 'drop-importer-suite' ); ?></label></th>
                <td><input type="text" name="dropi_language" id="dropi_language" value="<?php echo esc_attr( $language ); ?>" style="width:120px;"> <span class="description"><?php esc_html_e( 'IOF language code, e.g. pol, eng.', 'drop-importer-suite' ); ?></span></td>
            </tr>
            <tr>
                <th scope="row">Dry run</th>
                <td><label><input type="checkbox" name="dropi_dry" value="1" <?php checked( $dry ); ?>> <?php esc_html_e( 'Enable dry run (no database changes).', 'drop-importer-suite' ); ?></label></td>
            </tr>
            <tr>
                <th scope="row">Skip images</th>
                <td><label><input type="checkbox" name="dropi_skip_images" value="1" <?php checked( $skip_images ); ?>> <?php esc_html_e( 'Skip image downloads (faster, safer).', 'drop-importer-suite' ); ?></label></td>
            </tr>
        </table>
        <p class="submit"><input type="submit" class="button button-primary" name="dropi_run" value="<?php esc_attr_e( 'Run batch', 'drop-importer-suite' ); ?>"></p>
    </form>

    <div id="dropi_controls" style="background:#fff;padding:12px;border:1px solid #ddd;margin-bottom:20px;"></div>

    <h2><?php esc_html_e( 'Log (last 200 lines)', 'drop-importer-suite' ); ?></h2>
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

    $xml_path    = isset( $_POST['xml'] ) ? sanitize_text_field( wp_unslash( $_POST['xml'] ) ) : '';
    $offset      = isset( $_POST['offset'] ) ? max( 0, intval( $_POST['offset'] ) ) : 0;
    $limit       = isset( $_POST['limit'] ) ? max( 1, intval( $_POST['limit'] ) ) : 10;
    $dry         = ! empty( $_POST['dry'] );
    $skip_images = ! empty( $_POST['skip_images'] );
    $debug       = ! empty( $_POST['debug'] );
    $language    = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : 'pol';

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
    }

    dropi_debug_log( sprintf( 'AJAX chunk start offset=%d limit=%d dry=%d skip_images=%d language=%s', $offset, $limit, $dry ? 1 : 0, $skip_images ? 1 : 0, $language ) );

    $result = dropi_process_feed_chunk( $xml_path, array(
        'dry_run'          => $dry,
        'skip_images'      => $skip_images,
        'start_group'      => $offset,
        'groups_per_batch' => $limit,
        'language'         => $language,
    ) );

    if ( is_wp_error( $result ) ) {
        dropi_log( 'AJAX chunk error: ' . $result->get_error_message() );
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    dropi_log( sprintf( 'AJAX chunk summary offset=%d processed=%d next=%s total=%d runtime=%.3fs dry=%d', $offset, $result['processed_groups'], ( null === $result['next_offset'] ? 'null' : $result['next_offset'] ), $result['groups_total'], $result['runtime'], $result['dry_run'] ? 1 : 0 ) );

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
            'created'      => $result['created_products'],
            'updated'      => $result['updated_products'],
            'timed_out'    => $result['timed_out'],
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
            'ajax_url'  => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'dropi_chunk_nonce' ),
            'language'  => 'pol',
        )
    );
} );

// -----------------------------------------------------------------------------
// WP-CLI command for unattended imports
// -----------------------------------------------------------------------------

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'dropi import', function ( $args, $assoc_args ) {
        $xml_path    = isset( $assoc_args['file'] ) ? $assoc_args['file'] : WP_CONTENT_DIR . '/uploads/stock_export_full_for_varvishop.xml';
        $dry         = isset( $assoc_args['dry-run'] );
        $skip_images = isset( $assoc_args['skip-images'] );
        $limit       = isset( $assoc_args['limit'] ) ? max( 1, intval( $assoc_args['limit'] ) ) : 20;
        $language    = isset( $assoc_args['language'] ) ? $assoc_args['language'] : 'pol';

        $offset = 0;

        WP_CLI::log( sprintf( 'Starting import file=%s dry=%d skip_images=%d limit=%d language=%s', $xml_path, $dry ? 1 : 0, $skip_images ? 1 : 0, $limit, $language ) );

        while ( true ) {
            $result = dropi_process_feed_chunk( $xml_path, array(
                'dry_run'          => $dry,
                'skip_images'      => $skip_images,
                'start_group'      => $offset,
                'groups_per_batch' => $limit,
                'language'         => $language,
            ) );

            if ( is_wp_error( $result ) ) {
                WP_CLI::error( $result->get_error_message() );
                break;
            }

            WP_CLI::log( sprintf( 'Processed %d products (offset %d -> %s) runtime=%.3fs created=%d updated=%d', $result['processed_groups'], $offset, ( null === $result['next_offset'] ? 'done' : $result['next_offset'] ), $result['runtime'], $result['created_products'], $result['updated_products'] ) );

            if ( null === $result['next_offset'] || 0 === $result['processed_groups'] ) {
                break;
            }

            $offset = $result['next_offset'];
        }

        WP_CLI::success( 'Import finished.' );
    } );
}
