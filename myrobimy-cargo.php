<?php
/*
Plugin Name: MyRobimy Cargo
Description: Мини-плагин: CPT ride, формы добавления рейса, REST endpoint, уведомления и интеграция WooCommerce для VIP рейсов.
Version: 0.2.0
Author: MyRobimy
*/

if (!defined('ABSPATH')) {
    exit;
}

define('MYROBIMY_CARGO_VERSION', '0.2.0');
define('MYROBIMY_CARGO_FEATURED_DAYS', 30);
define('MYROBIMY_CARGO_FEATURED_PRODUCT_NAME', 'Featured ride — 30 days');
define('MYROBIMY_CARGO_FEATURED_PRODUCT_SKU', 'MR-FEATURED-30');

add_action('wp_enqueue_scripts', 'myrobimy_cargo_enqueue_assets');
function myrobimy_cargo_enqueue_assets(): void
{
    wp_enqueue_style('bootstrap-5-cargo', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css', [], '5.3.0');
    wp_enqueue_script('bootstrap-5-cargo', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js', ['jquery'], '5.3.0', true);
}

add_action('init', 'myrobimy_register_ride_cpt');
function myrobimy_register_ride_cpt(): void
{
    $labels = [
        'name' => __('Rides', 'myrobimy-cargo'),
        'singular_name' => __('Ride', 'myrobimy-cargo'),
    ];

    $args = [
        'labels' => $labels,
        'public' => true,
        'show_ui' => true,
        'has_archive' => true,
        'supports' => ['title', 'custom-fields', 'author'],
    ];

    register_post_type('ride', $args);
}

register_activation_hook(__FILE__, 'myrobimy_cargo_activate');
function myrobimy_cargo_activate(): void
{
    myrobimy_register_ride_cpt();
    flush_rewrite_rules();

    if (class_exists('WooCommerce')) {
        myrobimy_cargo_ensure_featured_product();
    }
}

register_deactivation_hook(__FILE__, 'myrobimy_cargo_deactivate');
function myrobimy_cargo_deactivate(): void
{
    flush_rewrite_rules();
}

add_shortcode('add_ride_form', 'myrobimy_add_ride_form');
function myrobimy_add_ride_form(): string
{
    ob_start();
    $rest_nonce = wp_create_nonce('wp_rest');
    ?>
    <div class="container my-3" id="mr-ride-form-wrapper">
        <form id="mr-ride-form" class="row g-2 needs-validation" novalidate>
            <div class="col-md-4">
                <input class="form-control" name="from" placeholder="<?php esc_attr_e('Откуда', 'myrobimy-cargo'); ?>" required>
            </div>
            <div class="col-md-4">
                <input class="form-control" name="to" placeholder="<?php esc_attr_e('Куда', 'myrobimy-cargo'); ?>" required>
            </div>
            <div class="col-md-4">
                <input type="date" class="form-control" name="date" required>
            </div>
            <div class="col-md-3">
                <input class="form-control" name="capacity" placeholder="<?php esc_attr_e('Вместимость (kg)', 'myrobimy-cargo'); ?>" required>
            </div>
            <div class="col-md-3">
                <input class="form-control" name="phone" placeholder="<?php esc_attr_e('Телефон', 'myrobimy-cargo'); ?>" required>
            </div>
            <div class="col-md-3">
                <input class="form-control" name="telegram" placeholder="<?php esc_attr_e('Telegram (@username или ID)', 'myrobimy-cargo'); ?>">
            </div>
            <div class="col-md-3">
                <input class="form-control" name="email" placeholder="<?php esc_attr_e('E-mail (опционально)', 'myrobimy-cargo'); ?>">
            </div>
            <div class="col-12 text-end">
                <button class="btn btn-primary" type="submit"><?php esc_html_e('Добавить рейс', 'myrobimy-cargo'); ?></button>
            </div>
        </form>
        <div id="mr-ride-result" class="mt-2"></div>
    </div>
    <script>
        (function () {
            const form = document.getElementById('mr-ride-form');
            if (!form) {
                return;
            }

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                const resultNode = document.getElementById('mr-ride-result');
                resultNode.textContent = '';

                const formData = new FormData(form);
                const payload = {
                    from: formData.get('from'),
                    to: formData.get('to'),
                    date: formData.get('date'),
                    capacity: formData.get('capacity'),
                    phone: formData.get('phone'),
                    telegram: formData.get('telegram'),
                    email: formData.get('email'),
                };

                fetch('<?php echo esc_url(rest_url('myapi/v1/add-ride')); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': '<?php echo esc_js($rest_nonce); ?>'
                    },
                    body: JSON.stringify(payload),
                })
                    .then((response) => response.json())
                    .then((data) => {
                        if (data.success) {
                            resultNode.className = 'alert alert-success';
                            resultNode.textContent = data.message || '<?php echo esc_js(__('Рейс создан', 'myrobimy-cargo')); ?>';
                            form.reset();
                        } else {
                            resultNode.className = 'alert alert-warning';
                            resultNode.textContent = data.message || '<?php echo esc_js(__('Не удалось добавить рейс', 'myrobimy-cargo')); ?>';
                        }
                    })
                    .catch(() => {
                        resultNode.className = 'alert alert-danger';
                        resultNode.textContent = '<?php echo esc_js(__('Ошибка отправки формы', 'myrobimy-cargo')); ?>';
                    });
            });
        })();
    </script>
    <?php
    return ob_get_clean();
}

add_action('rest_api_init', function () {
    register_rest_route('myapi/v1', '/add-ride', [
        'methods' => 'POST',
        'callback' => 'myrobimy_rest_add_ride',
        'permission_callback' => '__return_true',
    ]);
});

function myrobimy_rest_add_ride(WP_REST_Request $request)
{
    $raw = $request->get_json_params();
    $data = myrobimy_cargo_validate_ride_data($raw);

    if (is_wp_error($data)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => $data->get_error_message(),
        ], 400);
    }

    $title = sprintf(
        '%s → %s (%s)',
        $data['from'],
        $data['to'],
        date_i18n(get_option('date_format', 'd.m.Y'), strtotime($data['date']))
    );

    $post_args = [
        'post_type' => 'ride',
        'post_title' => $title,
        'post_status' => apply_filters('myrobimy_cargo_new_ride_status', 'pending', $data),
        'meta_input' => [
            'from' => $data['from'],
            'to' => $data['to'],
            'date' => $data['date'],
            'capacity' => $data['capacity'],
            'phone' => $data['phone'],
            'telegram' => $data['telegram'],
            'email' => $data['email'],
        ],
    ];

    $post_id = wp_insert_post($post_args, true);

    if (is_wp_error($post_id)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => __('Не удалось сохранить рейс', 'myrobimy-cargo'),
        ], 500);
    }

    /**
     * Action fired right after a new ride was created via REST.
     *
     * @param int   $post_id Ride post ID.
     * @param array $data    Sanitized form data.
     */
    do_action('myrobimy_cargo_ride_created', $post_id, $data);

    myrobimy_cargo_notify_admin($post_id, $data);
    myrobimy_cargo_notify_poster($post_id, $data);

    return rest_ensure_response([
        'success' => true,
        'message' => __('Рейс создан, ожидайте подтверждение', 'myrobimy-cargo'),
        'id' => $post_id,
        'status' => get_post_status($post_id),
    ]);
}

function myrobimy_cargo_validate_ride_data(array $raw)
{
    $required = ['from', 'to', 'date', 'capacity', 'phone'];

    foreach ($required as $key) {
        if (empty($raw[$key])) {
            return new WP_Error('missing_field', sprintf(__('Поле %s обязательно', 'myrobimy-cargo'), $key));
        }
    }

    $date = sanitize_text_field($raw['date']);
    $timestamp = strtotime($date);
    if (!$timestamp) {
        return new WP_Error('invalid_date', __('Неверная дата', 'myrobimy-cargo'));
    }

    return [
        'from' => sanitize_text_field($raw['from']),
        'to' => sanitize_text_field($raw['to']),
        'date' => gmdate('Y-m-d', $timestamp),
        'capacity' => sanitize_text_field($raw['capacity']),
        'phone' => sanitize_text_field($raw['phone']),
        'telegram' => isset($raw['telegram']) ? sanitize_text_field($raw['telegram']) : '',
        'email' => isset($raw['email']) ? sanitize_email($raw['email']) : '',
    ];
}

function myrobimy_cargo_notify_admin(int $post_id, array $data): void
{
    $admin_email = get_option('admin_email');
    $subject = sprintf(__('Новый рейс: %s → %s', 'myrobimy-cargo'), $data['from'], $data['to']);
    $message_lines = [
        sprintf(__('Маршрут: %s → %s', 'myrobimy-cargo'), $data['from'], $data['to']),
        sprintf(__('Дата: %s', 'myrobimy-cargo'), date_i18n(get_option('date_format', 'd.m.Y'), strtotime($data['date']))),
        sprintf(__('Вместимость: %s', 'myrobimy-cargo'), $data['capacity']),
        sprintf(__('Телефон: %s', 'myrobimy-cargo'), $data['phone']),
        sprintf(__('Telegram: %s', 'myrobimy-cargo'), $data['telegram'] ?: __('не указан', 'myrobimy-cargo')),
        sprintf(__('E-mail: %s', 'myrobimy-cargo'), $data['email'] ?: __('не указан', 'myrobimy-cargo')),
        sprintf(__('Ссылка на рейс: %s', 'myrobimy-cargo'), admin_url('post.php?post=' . $post_id . '&action=edit')),
    ];

    $message = implode("\n", $message_lines);
    wp_mail($admin_email, $subject, $message);

    $admin_chat_id = get_option('myrobimy_cargo_admin_telegram_chat_id');
    if ($admin_chat_id) {
        myrobimy_cargo_send_telegram_message($admin_chat_id, $message);
    }
}

function myrobimy_cargo_notify_poster(int $post_id, array $data): void
{
    $message_lines = [
        __('Ваш рейс получен и отправлен на модерацию.', 'myrobimy-cargo'),
        sprintf(__('Маршрут: %s → %s', 'myrobimy-cargo'), $data['from'], $data['to']),
        sprintf(__('Дата: %s', 'myrobimy-cargo'), date_i18n(get_option('date_format', 'd.m.Y'), strtotime($data['date']))),
        sprintf(__('Вместимость: %s', 'myrobimy-cargo'), $data['capacity']),
        __('Спасибо, команда MyRobimy.', 'myrobimy-cargo'),
    ];

    $message = implode("\n", $message_lines);

    if (!empty($data['telegram'])) {
        myrobimy_cargo_send_telegram_message($data['telegram'], $message);
    }

    if (!empty($data['email'])) {
        wp_mail($data['email'], __('Ваш рейс принят', 'myrobimy-cargo'), $message);
    }

    if (!empty($data['phone'])) {
        /**
         * Позволяет интегрировать сторонние сервисы SMS или мессенджеров.
         *
         * @param string $phone
         * @param string $message
         * @param int    $post_id
         * @param array  $data
         */
        do_action('myrobimy_cargo_send_sms', $data['phone'], $message, $post_id, $data);
    }
}

function myrobimy_cargo_send_telegram_message(string $chat_id_or_username, string $message): bool
{
    $token = get_option('myrobimy_cargo_bot_token');
    if (!$token || !$chat_id_or_username) {
        return false;
    }

    $endpoint = sprintf('https://api.telegram.org/bot%s/sendMessage', $token);
    $response = wp_remote_post($endpoint, [
        'timeout' => 10,
        'body' => [
            'chat_id' => $chat_id_or_username,
            'text' => $message,
            'parse_mode' => 'HTML',
        ],
    ]);

    if (is_wp_error($response)) {
        return false;
    }

    return (int) wp_remote_retrieve_response_code($response) === 200;
}

add_shortcode('rides_list', 'myrobimy_cargo_shortcode_rides_list');
function myrobimy_cargo_shortcode_rides_list($atts): string
{
    $atts = shortcode_atts([
        'count' => 5,
        'status' => 'publish',
    ], $atts, 'rides_list');

    $per_page = max(1, min(100, (int) $atts['count']));
    $page = isset($_GET['mr_list_page']) ? max(1, (int) $_GET['mr_list_page']) : 1;
    $query = new WP_Query([
        'post_type' => 'ride',
        'post_status' => array_map('trim', explode(',', $atts['status'])),
        'posts_per_page' => $per_page,
        'orderby' => 'meta_value',
        'meta_key' => 'date',
        'order' => 'ASC',
        'meta_type' => 'DATE',
        'paged' => $page,
    ]);

    return myrobimy_cargo_render_rides($query, [
        'show_pagination' => true,
        'pagination_param' => 'mr_list_page',
        'featured_top_limit' => 5,
    ]);
}

add_shortcode('rides_recent', 'myrobimy_cargo_shortcode_rides_recent');
function myrobimy_cargo_shortcode_rides_recent($atts): string
{
    $atts = shortcode_atts([
        'per_page' => 10,
        'days' => 30,
    ], $atts, 'rides_recent');

    $per_page = max(1, min(50, (int) $atts['per_page']));
    $days = max(1, min(365, (int) $atts['days']));
    $page = isset($_GET['mr_recent_page']) ? max(1, (int) $_GET['mr_recent_page']) : 1;
    $cutoff = gmdate('Y-m-d', strtotime(sprintf('-%d days', $days)));

    $query = new WP_Query([
        'post_type' => 'ride',
        'post_status' => 'publish',
        'posts_per_page' => $per_page,
        'orderby' => 'meta_value',
        'meta_key' => 'date',
        'meta_type' => 'DATE',
        'order' => 'ASC',
        'paged' => $page,
        'meta_query' => [
            [
                'key' => 'date',
                'value' => $cutoff,
                'compare' => '>=',
                'type' => 'DATE',
            ],
        ],
    ]);

    return myrobimy_cargo_render_rides($query, [
        'show_pagination' => true,
        'pagination_param' => 'mr_recent_page',
        'featured_top_limit' => 5,
    ]);
}

add_shortcode('rides_search', 'myrobimy_cargo_shortcode_rides_search');
function myrobimy_cargo_shortcode_rides_search($atts): string
{
    $atts = shortcode_atts([
        'per_page' => 5,
        'show_form_only' => 'no',
    ], $atts, 'rides_search');

    $per_page = max(1, min(50, (int) $atts['per_page']));
    $current_from = isset($_GET['ride_from']) ? sanitize_text_field(wp_unslash($_GET['ride_from'])) : '';
    $current_to = isset($_GET['ride_to']) ? sanitize_text_field(wp_unslash($_GET['ride_to'])) : '';
    $current_date = isset($_GET['ride_date']) ? sanitize_text_field(wp_unslash($_GET['ride_date'])) : '';
    $page = isset($_GET['mr_search_page']) ? max(1, (int) $_GET['mr_search_page']) : 1;

    ob_start();
    ?>
    <form class="mr-ride-search-form row g-2 mb-3" method="get">
        <div class="col-md-3">
            <input type="text" class="form-control" name="ride_from" placeholder="<?php esc_attr_e('Откуда', 'myrobimy-cargo'); ?>" value="<?php echo esc_attr($current_from); ?>">
        </div>
        <div class="col-md-3">
            <input type="text" class="form-control" name="ride_to" placeholder="<?php esc_attr_e('Куда', 'myrobimy-cargo'); ?>" value="<?php echo esc_attr($current_to); ?>">
        </div>
        <div class="col-md-3">
            <input type="date" class="form-control" name="ride_date" value="<?php echo esc_attr($current_date); ?>">
        </div>
        <div class="col-md-3">
            <button class="btn btn-outline-primary w-100" type="submit"><?php esc_html_e('Найти рейсы', 'myrobimy-cargo'); ?></button>
        </div>
    </form>
    <?php

    if ('yes' === strtolower($atts['show_form_only'])) {
        return ob_get_clean();
    }

    $meta_query = ['relation' => 'AND'];
    if ($current_from) {
        $meta_query[] = [
            'key' => 'from',
            'value' => $current_from,
            'compare' => 'LIKE',
        ];
    }
    if ($current_to) {
        $meta_query[] = [
            'key' => 'to',
            'value' => $current_to,
            'compare' => 'LIKE',
        ];
    }
    if ($current_date) {
        $meta_query[] = [
            'key' => 'date',
            'value' => gmdate('Y-m-d', strtotime($current_date)),
            'compare' => '=',
            'type' => 'DATE',
        ];
    }

    if (1 === count($meta_query)) {
        $meta_query = [];
    }

    $query_args = [
        'post_type' => 'ride',
        'post_status' => 'publish',
        'posts_per_page' => $per_page,
        'orderby' => 'meta_value',
        'meta_key' => 'date',
        'meta_type' => 'DATE',
        'order' => 'ASC',
        'paged' => $page,
    ];

    if (!empty($meta_query)) {
        $query_args['meta_query'] = $meta_query;
    }

    $results = new WP_Query($query_args);
    echo myrobimy_cargo_render_rides($results, [
        'show_pagination' => true,
        'pagination_param' => 'mr_search_page',
        'featured_top_limit' => 5,
        'empty_message' => __('Рейсов не найдено. Измените параметры поиска.', 'myrobimy-cargo'),
    ]);

    return ob_get_clean();
}

function myrobimy_cargo_render_rides(WP_Query $query, array $args = []): string
{
    $defaults = [
        'show_pagination' => false,
        'pagination_param' => 'mr_page',
        'featured_top_limit' => 5,
        'empty_message' => __('Пока нет рейсов.', 'myrobimy-cargo'),
    ];
    $args = wp_parse_args($args, $defaults);

    $posts = $query->posts;
    if (!empty($posts)) {
        $posts = myrobimy_cargo_sort_featured($posts, (int) $args['featured_top_limit']);
        wp_reset_postdata();
    }

    ob_start();

    if (empty($posts)) {
        ?>
        <div class="alert alert-info"><?php echo esc_html($args['empty_message']); ?></div>
        <?php
        return ob_get_clean();
    }

    $featured_product_id = myrobimy_cargo_get_featured_product_id();
    ?>
    <div class="list-group myrobimy-rides-list">
        <?php foreach ($posts as $post): ?>
            <?php
            $ride_id = $post->ID;
            $from = get_post_meta($ride_id, 'from', true);
            $to = get_post_meta($ride_id, 'to', true);
            $date = get_post_meta($ride_id, 'date', true);
            $capacity = get_post_meta($ride_id, 'capacity', true);
            $phone = get_post_meta($ride_id, 'phone', true);
            $telegram = get_post_meta($ride_id, 'telegram', true);
            $email = get_post_meta($ride_id, 'email', true);
            $is_featured = myrobimy_cargo_is_featured($ride_id);
            $vip_url = '';
            if (!$is_featured && $featured_product_id) {
                $vip_url = add_query_arg(
                    [
                        'add-to-cart' => $featured_product_id,
                        'ride_id' => $ride_id,
                    ],
                    get_permalink($featured_product_id)
                );
            }
            ?>
            <div class="list-group-item list-group-item-action flex-column align-items-start">
                <div class="d-flex w-100 justify-content-between">
                    <h5 class="mb-1">
                        <?php echo esc_html($from . ' → ' . $to); ?>
                        <?php if ($is_featured): ?>
                            <span class="badge bg-warning text-dark ms-2"><?php esc_html_e('VIP', 'myrobimy-cargo'); ?></span>
                        <?php endif; ?>
                    </h5>
                    <small><?php echo esc_html(date_i18n(get_option('date_format', 'd.m.Y'), strtotime($date))); ?></small>
                </div>
                <p class="mb-1"><?php printf('%s %s', esc_html__('Вместимость:', 'myrobimy-cargo'), esc_html($capacity)); ?></p>
                <small>
                    <?php if ($phone): ?>
                        <span class="me-3"><?php printf('%s %s', esc_html__('Телефон:', 'myrobimy-cargo'), esc_html($phone)); ?></span>
                    <?php endif; ?>
                    <?php if ($telegram): ?>
                        <span class="me-3"><?php printf('%s %s', esc_html__('Telegram:', 'myrobimy-cargo'), esc_html($telegram)); ?></span>
                    <?php endif; ?>
                    <?php if ($email): ?>
                        <span class="me-3"><?php printf('%s %s', esc_html__('E-mail:', 'myrobimy-cargo'), esc_html($email)); ?></span>
                    <?php endif; ?>
                </small>
                <?php if ($vip_url): ?>
                    <div class="mt-2 text-end">
                        <a class="btn btn-warning btn-sm" href="<?php echo esc_url($vip_url); ?>">
                            <?php esc_html_e('Сделать VIP', 'myrobimy-cargo'); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php

    if (!empty($args['show_pagination']) && $query->max_num_pages > 1) {
        $current = isset($_GET[$args['pagination_param']]) ? max(1, (int) $_GET[$args['pagination_param']]) : 1;
        ?>
        <nav class="mt-3">
            <ul class="pagination">
                <?php for ($i = 1; $i <= $query->max_num_pages; $i++): ?>
                    <li class="page-item <?php echo $i === $current ? 'active' : ''; ?>">
                        <a class="page-link" href="<?php echo esc_url(add_query_arg($args['pagination_param'], $i)); ?>">
                            <?php echo esc_html($i); ?>
                        </a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php
    }

    return ob_get_clean();
}

function myrobimy_cargo_sort_featured(array $posts, int $top_limit = 5): array
{
    if (empty($posts)) {
        return $posts;
    }

    $featured = [];
    $regular = [];

    foreach ($posts as $post) {
        if (myrobimy_cargo_is_featured($post->ID)) {
            $featured[] = $post;
        } else {
            $regular[] = $post;
        }
    }

    $top_featured = array_slice($featured, 0, $top_limit);
    $remaining_featured = array_slice($featured, $top_limit);

    return array_merge($top_featured, $regular, $remaining_featured);
}

function myrobimy_cargo_is_featured(int $ride_id): bool
{
    $featured_until = (int) get_post_meta($ride_id, '_mr_featured_until', true);
    if (!$featured_until) {
        return false;
    }

    $now = current_time('timestamp');
    if ($featured_until < $now) {
        delete_post_meta($ride_id, '_mr_featured_until');
        return false;
    }

    return true;
}

function myrobimy_cargo_set_featured_until(int $ride_id, int $order_id = 0): void
{
    $timestamp = strtotime(sprintf('+%d days', MYROBIMY_CARGO_FEATURED_DAYS), current_time('timestamp'));
    update_post_meta($ride_id, '_mr_featured_until', $timestamp);
    update_post_meta($ride_id, '_mr_featured_order', $order_id);
}

add_action('admin_menu', 'myrobimy_cargo_register_settings_page');
function myrobimy_cargo_register_settings_page(): void
{
    add_options_page(
        __('MyRobimy Cargo', 'myrobimy-cargo'),
        __('MyRobimy Cargo', 'myrobimy-cargo'),
        'manage_options',
        'myrobimy-cargo',
        'myrobimy_cargo_render_settings_page'
    );
}

add_action('admin_init', 'myrobimy_cargo_register_settings');
function myrobimy_cargo_register_settings(): void
{
    register_setting('myrobimy_cargo', 'myrobimy_cargo_bot_token');
    register_setting('myrobimy_cargo', 'myrobimy_cargo_admin_telegram_chat_id');

    add_settings_section(
        'myrobimy_cargo_notifications',
        __('Уведомления', 'myrobimy-cargo'),
        '__return_false',
        'myrobimy_cargo'
    );

    add_settings_field(
        'myrobimy_cargo_bot_token',
        __('Telegram Bot Token', 'myrobimy-cargo'),
        function () {
            $value = esc_attr(get_option('myrobimy_cargo_bot_token', ''));
            echo '<input type="text" name="myrobimy_cargo_bot_token" value="' . $value . '" class="regular-text" />';
        },
        'myrobimy_cargo',
        'myrobimy_cargo_notifications'
    );

    add_settings_field(
        'myrobimy_cargo_admin_telegram_chat_id',
        __('Telegram Chat ID администратора', 'myrobimy-cargo'),
        function () {
            $value = esc_attr(get_option('myrobimy_cargo_admin_telegram_chat_id', ''));
            echo '<input type="text" name="myrobimy_cargo_admin_telegram_chat_id" value="' . $value . '" class="regular-text" />';
        },
        'myrobimy_cargo',
        'myrobimy_cargo_notifications'
    );
}

function myrobimy_cargo_render_settings_page(): void
{
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('MyRobimy Cargo настройки', 'myrobimy-cargo'); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('myrobimy_cargo');
            do_settings_sections('myrobimy_cargo');
            submit_button();
            ?>
        </form>
        <hr>
        <h2><?php esc_html_e('Интеграция SMS', 'myrobimy-cargo'); ?></h2>
        <p><?php esc_html_e('Для отправки SMS подключите свой сервис к хуку myrobimy_cargo_send_sms.', 'myrobimy-cargo'); ?></p>
        <pre><code>add_action( 'myrobimy_cargo_send_sms', function( $phone, $message, $ride_id ) {
    // Вызовите вашу интеграцию здесь.
} );</code></pre>
    </div>
    <?php
}

add_action('plugins_loaded', 'myrobimy_cargo_init_woocommerce_features');
function myrobimy_cargo_init_woocommerce_features(): void
{
    if (!class_exists('WooCommerce')) {
        return;
    }

    add_filter('woocommerce_add_cart_item_data', 'myrobimy_cargo_attach_ride_to_cart', 10, 3);
    add_filter('woocommerce_get_item_data', 'myrobimy_cargo_display_cart_item_data', 10, 2);
    add_action('woocommerce_checkout_create_order_line_item', 'myrobimy_cargo_save_order_item_meta', 10, 4);
    add_action('woocommerce_order_status_completed', 'myrobimy_cargo_handle_completed_order');
}

function myrobimy_cargo_get_featured_product_id(): int
{
    $product_id = (int) get_option('myrobimy_cargo_featured_product_id', 0);

    if ($product_id > 0) {
        return $product_id;
    }

    $product_id = wc_get_product_id_by_sku(MYROBIMY_CARGO_FEATURED_PRODUCT_SKU);
    if ($product_id) {
        update_option('myrobimy_cargo_featured_product_id', $product_id);
        return (int) $product_id;
    }

    return 0;
}

function myrobimy_cargo_ensure_featured_product(): void
{
    if (!class_exists('WooCommerce')) {
        return;
    }

    $product_id = myrobimy_cargo_get_featured_product_id();
    if ($product_id > 0) {
        return;
    }

    $product = new WC_Product_Simple();
    $product->set_name(MYROBIMY_CARGO_FEATURED_PRODUCT_NAME);
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_virtual(true);
    $product->set_regular_price(apply_filters('myrobimy_cargo_featured_product_price', 49));
    $product->set_sku(MYROBIMY_CARGO_FEATURED_PRODUCT_SKU);
    $product->set_sold_individually(true);
    $product->set_downloadable(false);
    $product->save();

    update_option('myrobimy_cargo_featured_product_id', $product->get_id());
}

function myrobimy_cargo_attach_ride_to_cart(array $cart_item_data, int $product_id, int $variation_id): array
{
    $featured_product_id = myrobimy_cargo_get_featured_product_id();
    if ($featured_product_id <= 0) {
        return $cart_item_data;
    }

    if ((int) $product_id !== $featured_product_id) {
        return $cart_item_data;
    }

    if (empty($_REQUEST['ride_id'])) {
        wc_add_notice(__('Для покупки VIP необходимо выбрать рейс.', 'myrobimy-cargo'), 'error');
        return $cart_item_data;
    }

    $ride_id = (int) $_REQUEST['ride_id'];
    if ('ride' !== get_post_type($ride_id)) {
        wc_add_notice(__('Выбран некорректный рейс.', 'myrobimy-cargo'), 'error');
        return $cart_item_data;
    }

    $cart_item_data['mr_ride_id'] = $ride_id;
    $cart_item_data['unique_key'] = md5(microtime() . $ride_id);

    return $cart_item_data;
}

function myrobimy_cargo_display_cart_item_data(array $data, array $cart_item): array
{
    if (!empty($cart_item['mr_ride_id'])) {
        $data[] = [
            'name' => __('Рейс', 'myrobimy-cargo'),
            'value' => get_the_title((int) $cart_item['mr_ride_id']),
        ];
    }

    return $data;
}

function myrobimy_cargo_save_order_item_meta(WC_Order_Item_Product $item, string $cart_item_key, array $values, WC_Order $order): void
{
    if (!empty($values['mr_ride_id'])) {
        $item->add_meta_data('_mr_ride_id', (int) $values['mr_ride_id'], true);
        $item->add_meta_data(__('Рейс', 'myrobimy-cargo'), get_the_title((int) $values['mr_ride_id']), true);
    }
}

function myrobimy_cargo_handle_completed_order(int $order_id): void
{
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    foreach ($order->get_items() as $item) {
        $ride_id = (int) $item->get_meta('_mr_ride_id');
        $product_id = $item->get_product_id();

        if (!$ride_id || $product_id !== myrobimy_cargo_get_featured_product_id()) {
            continue;
        }

        myrobimy_cargo_set_featured_until($ride_id, $order_id);

        /**
         * Allow integrations to react when a ride becomes featured.
         *
         * @param int      $ride_id
         * @param int      $order_id
         * @param WC_Order $order
         */
        do_action('myrobimy_cargo_ride_upgraded', $ride_id, $order_id, $order);
    }
}

