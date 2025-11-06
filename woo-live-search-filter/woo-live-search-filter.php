<?php
/**
 * Plugin Name: WooCommerce Live Search Filter
 * Plugin URI:  https://example.com/
 * Description: Adds a shortcode that renders a live WooCommerce product search with category filter and AJAX-powered results.
 * Version:     1.0.0
 * Author:      Your Name
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: woo-live-search-filter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

if ( ! class_exists( 'Woo_Live_Search_Filter' ) ) {
    /**
     * Core plugin class.
     */
    class Woo_Live_Search_Filter {
        /**
         * The nonce action used for AJAX requests.
         */
        const NONCE_ACTION = 'woo_live_search_filter_nonce';

        /**
         * Script handle used for enqueueing.
         */
        const SCRIPT_HANDLE = 'woo-live-search-filter';

        /**
         * Constructor: set up hooks.
         */
        public function __construct() {
            add_action( 'init', [ $this, 'register_shortcode' ] );
            add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );

            add_action( 'wp_ajax_woo_live_search_suggestions', [ $this, 'handle_suggestions' ] );
            add_action( 'wp_ajax_nopriv_woo_live_search_suggestions', [ $this, 'handle_suggestions' ] );

            add_action( 'wp_ajax_woo_live_search_results', [ $this, 'handle_results' ] );
            add_action( 'wp_ajax_nopriv_woo_live_search_results', [ $this, 'handle_results' ] );
        }

        /**
         * Register the `[woo_live_search]` shortcode.
         */
        public function register_shortcode(): void {
            add_shortcode( 'woo_live_search', [ $this, 'render_shortcode' ] );
        }

        /**
         * Register and enqueue frontend assets when shortcode is present.
         */
        public function register_assets(): void {
            $plugin_url = plugin_dir_url( __FILE__ );
            $version    = defined( 'WP_DEBUG' ) && WP_DEBUG ? time() : '1.0.0';

            wp_register_style(
                self::SCRIPT_HANDLE,
                $plugin_url . 'assets/css/woo-live-search-filter.css',
                [],
                $version
            );

            wp_register_script(
                self::SCRIPT_HANDLE,
                $plugin_url . 'assets/js/woo-live-search-filter.js',
                [],
                $version,
                true
            );

            wp_localize_script(
                self::SCRIPT_HANDLE,
                'WooLiveSearchFilter',
                [
                    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                    'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
                    'texts'   => [
                        'noResults' => __( 'No products found.', 'woo-live-search-filter' ),
                        'view'      => __( 'View', 'woo-live-search-filter' ),
                        'loading'   => __( 'Searching…', 'woo-live-search-filter' ),
                    ],
                ]
            );
        }

        /**
         * Shortcode renderer.
         */
        public function render_shortcode( array $atts = [] ): string {
            if ( ! wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) ) {
                wp_enqueue_style( self::SCRIPT_HANDLE );
                wp_enqueue_script( self::SCRIPT_HANDLE );
            }

            $atts = shortcode_atts(
                [
                    'placeholder'      => __( 'Search products…', 'woo-live-search-filter' ),
                    'button_label'     => __( 'Find', 'woo-live-search-filter' ),
                    'category_default' => __( 'All categories', 'woo-live-search-filter' ),
                ],
                $atts,
                'woo_live_search'
            );

            $categories = get_terms(
                [
                    'taxonomy'   => 'product_cat',
                    'hide_empty' => true,
                ]
            );

            ob_start();
            ?>
            <div class="woo-live-search" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>">
                <form class="woo-live-search__form" novalidate>
                    <label class="woo-live-search__label" for="woo-live-search-keyword">
                        <span class="screen-reader-text"><?php esc_html_e( 'Search for products', 'woo-live-search-filter' ); ?></span>
                        <input
                            type="search"
                            id="woo-live-search-keyword"
                            class="woo-live-search__input"
                            name="keyword"
                            placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"
                            autocomplete="off"
                        />
                    </label>

                    <div class="woo-live-search__suggestions" role="listbox" aria-label="<?php esc_attr_e( 'Product suggestions', 'woo-live-search-filter' ); ?>"></div>

                    <label class="woo-live-search__label" for="woo-live-search-category">
                        <span class="screen-reader-text"><?php esc_html_e( 'Select product category', 'woo-live-search-filter' ); ?></span>
                        <select id="woo-live-search-category" name="category" class="woo-live-search__select">
                            <option value="">
                                <?php echo esc_html( $atts['category_default'] ); ?>
                            </option>
                            <?php foreach ( $categories as $category ) : ?>
                                <option value="<?php echo esc_attr( $category->term_id ); ?>">
                                    <?php echo esc_html( $category->name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <button type="submit" class="woo-live-search__submit">
                        <?php echo esc_html( $atts['button_label'] ); ?>
                    </button>
                </form>

                <div class="woo-live-search__status" role="status" aria-live="polite"></div>
                <div class="woo-live-search__results" aria-live="polite"></div>
            </div>
            <?php
            return (string) ob_get_clean();
        }

        /**
         * Handle AJAX suggestions requests.
         */
        public function handle_suggestions(): void {
            $this->verify_request();

            $search   = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
            $category = isset( $_POST['category'] ) ? absint( $_POST['category'] ) : 0;

            if ( '' === $search ) {
                wp_send_json_success( [] );
            }

            $query_args = [
                'post_type'      => 'product',
                'post_status'    => 'publish',
                's'              => $search,
                'posts_per_page' => 5,
                'orderby'        => 'relevance',
            ];

            if ( $category > 0 ) {
                $query_args['tax_query'] = [
                    [
                        'taxonomy' => 'product_cat',
                        'field'    => 'term_id',
                        'terms'    => $category,
                    ],
                ];
            }

            $query = new WP_Query( $query_args );

            $suggestions = [];
            foreach ( $query->posts as $product_post ) {
                $suggestions[] = [
                    'id'    => $product_post->ID,
                    'title' => html_entity_decode( get_the_title( $product_post ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
                    'url'   => get_permalink( $product_post ),
                ];
            }

            wp_send_json_success( $suggestions );
        }

        /**
         * Handle AJAX search result requests.
         */
        public function handle_results(): void {
            $this->verify_request();

            $search     = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
            $category   = isset( $_POST['category'] ) ? absint( $_POST['category'] ) : 0;
            $per_page   = apply_filters( 'woo_live_search_results_per_page', 12 );
            $query_args = [
                'post_type'      => 'product',
                'post_status'    => 'publish',
                's'              => $search,
                'posts_per_page' => $per_page,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ];

            if ( $category > 0 ) {
                $query_args['tax_query'] = [
                    [
                        'taxonomy' => 'product_cat',
                        'field'    => 'term_id',
                        'terms'    => $category,
                    ],
                ];
            }

            $query = new WP_Query( $query_args );

            ob_start();

            if ( $query->have_posts() ) {
                echo '<div class="woo-live-search__grid">';

                while ( $query->have_posts() ) {
                    $query->the_post();
                    global $product;

                    if ( ! $product instanceof WC_Product ) {
                        $product = wc_get_product( get_the_ID() );
                    }

                    if ( ! $product ) {
                        continue;
                    }

                    echo '<article class="woo-live-search__item">';
                    echo '<a class="woo-live-search__item-link" href="' . esc_url( get_the_permalink() ) . '">';
                    echo woocommerce_get_product_thumbnail( 'woocommerce_thumbnail' );
                    echo '<h3 class="woo-live-search__item-title">' . esc_html( get_the_title() ) . '</h3>';
                    echo '<span class="woo-live-search__item-price">' . wp_kses_post( $product->get_price_html() ) . '</span>';
                    echo '</a>';
                    echo '</article>';
                }

                echo '</div>';
            } else {
                echo '<p class="woo-live-search__empty">' . esc_html__( 'No products found.', 'woo-live-search-filter' ) . '</p>';
            }

            wp_reset_postdata();

            $html = ob_get_clean();
            wp_send_json_success(
                [
                    'html'      => $html,
                    'count'     => (int) $query->found_posts,
                    'search'    => $search,
                    'category'  => $category,
                ]
            );
        }

        /**
         * Verify the AJAX request nonce.
         */
        private function verify_request(): void {
            $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

            if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
                wp_send_json_error( [ 'message' => __( 'Invalid request.', 'woo-live-search-filter' ) ], 400 );
            }
        }
    }

    new Woo_Live_Search_Filter();
}
