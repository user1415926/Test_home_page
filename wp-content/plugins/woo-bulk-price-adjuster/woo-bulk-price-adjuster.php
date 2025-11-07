<?php
/**
 * Plugin Name: WooCommerce Bulk Price Adjuster
 * Description: Добавляет страницу администратора для массового изменения цен выбранных товаров WooCommerce.
 * Version:     1.0.0
 * Author:      GPT-5 Codex
 * Text Domain: wc-bulk-price-adjuster
 *
 * @package WooCommerceBulkPriceAdjuster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Bulk_Price_Adjuster' ) ) {

	/**
	 * Main plugin class.
	 */
	class WC_Bulk_Price_Adjuster {

		/**
		 * Singleton instance.
		 *
		 * @var WC_Bulk_Price_Adjuster|null
		 */
		private static $instance = null;

		/**
		 * Whether WooCommerce is active.
		 *
		 * @var bool
		 */
		private $is_wc_active = false;

		/**
		 * Holds admin notices.
		 *
		 * @var array<int, array{type:string, message:string}>
		 */
		private $notices = array();

		/**
		 * Get singleton instance.
		 *
		 * @return WC_Bulk_Price_Adjuster
		 */
		public static function instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * WC_Bulk_Price_Adjuster constructor.
		 */
		private function __construct() {
			$this->is_wc_active = class_exists( 'WooCommerce' );

			if ( ! $this->is_wc_active ) {
				add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
				return;
			}

			add_action( 'admin_menu', array( $this, 'register_menu' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}

		/**
		 * Register submenu under WooCommerce.
		 *
		 * @return void
		 */
		public function register_menu() {
			add_submenu_page(
				'woocommerce',
				__( 'Bulk Price Adjuster', 'wc-bulk-price-adjuster' ),
				__( 'Bulk Price Adjuster', 'wc-bulk-price-adjuster' ),
				'manage_woocommerce',
				'wc-bulk-price-adjuster',
				array( $this, 'render_page' )
			);
		}

		/**
		 * Enqueue scripts and styles for admin page.
		 *
		 * @param string $hook Current admin page hook.
		 *
		 * @return void
		 */
		public function enqueue_assets( $hook ) {
			if ( 'woocommerce_page_wc-bulk-price-adjuster' !== $hook ) {
				return;
			}

			if ( function_exists( 'WC' ) ) {
				wp_enqueue_style( 'woocommerce_admin_styles', WC()->plugin_url() . '/assets/css/admin.css', array(), WC_VERSION );
			}

			wp_enqueue_script( 'wc-enhanced-select' );
			wp_enqueue_style( 'wc-enhanced-select' );
			wp_enqueue_script( 'wc-admin-meta-boxes' );
		}

		/**
		 * Render admin page.
		 *
		 * @return void
		 */
		public function render_page() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'У вас недостаточно прав для доступа к этой странице.', 'wc-bulk-price-adjuster' ) );
			}

			if ( ! $this->is_wc_active ) {
				$this->add_notice( 'error', __( 'Для работы плагина активируйте WooCommerce.', 'wc-bulk-price-adjuster' ) );
			}

			$selected_products = array();

			if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$selected_products = $this->handle_form_request();
			}

			if ( ! empty( $this->notices ) ) {
				foreach ( $this->notices as $notice ) {
					printf(
						'<div class="notice notice-%1$s"><p>%2$s</p></div>',
						esc_attr( $notice['type'] ),
						wp_kses_post( $notice['message'] )
					);
				}
			}

			$selected_products = $this->prepare_selected_products( $selected_products );

			?>
			<div class="wrap woocommerce">
				<h1><?php esc_html_e( 'Массовое изменение цен', 'wc-bulk-price-adjuster' ); ?></h1>

				<form method="post">
					<?php wp_nonce_field( 'wc_bpa_update_prices', 'wc_bpa_nonce' ); ?>

					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row">
									<label for="wc_bpa_product_ids"><?php esc_html_e( 'Выберите товары', 'wc-bulk-price-adjuster' ); ?></label>
								</th>
								<td>
									<select
										id="wc_bpa_product_ids"
										name="product_ids[]"
										class="wc-product-search"
										multiple="multiple"
										style="width: 50%;"
										data-placeholder="<?php esc_attr_e( 'Начните вводить название товара…', 'wc-bulk-price-adjuster' ); ?>"
										data-action="woocommerce_json_search_products_and_variations"
									>
										<?php foreach ( $selected_products as $product_id => $product_name ) : ?>
											<option value="<?php echo esc_attr( $product_id ); ?>" selected="selected">
												<?php echo esc_html( $product_name ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p class="description">
										<?php esc_html_e( 'Выберите один или несколько товаров (в том числе вариации), которым нужно изменить цену.', 'wc-bulk-price-adjuster' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Тип изменения', 'wc-bulk-price-adjuster' ); ?></th>
								<td>
									<fieldset>
										<label>
											<input type="radio" name="wc_bpa_direction" value="increase" checked="checked" />
											<?php esc_html_e( 'Увеличить', 'wc-bulk-price-adjuster' ); ?>
										</label><br />
										<label>
											<input type="radio" name="wc_bpa_direction" value="decrease" />
											<?php esc_html_e( 'Уменьшить', 'wc-bulk-price-adjuster' ); ?>
										</label>
									</fieldset>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Способ расчёта', 'wc-bulk-price-adjuster' ); ?></th>
								<td>
									<fieldset>
										<label>
											<input type="radio" name="wc_bpa_mode" value="fixed" checked="checked" />
											<?php esc_html_e( 'На фиксированную величину (в валюте магазина)', 'wc-bulk-price-adjuster' ); ?>
										</label><br />
										<label>
											<input type="radio" name="wc_bpa_mode" value="percent" />
											<?php esc_html_e( 'На процент', 'wc-bulk-price-adjuster' ); ?>
										</label>
									</fieldset>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="wc_bpa_value"><?php esc_html_e( 'Значение', 'wc-bulk-price-adjuster' ); ?></label>
								</th>
								<td>
									<input
										type="number"
										min="0"
										step="0.01"
										id="wc_bpa_value"
										name="wc_bpa_value"
										required
									/>
									<p class="description">
										<?php esc_html_e( 'Введите число. Для процентов допустима дробная часть.', 'wc-bulk-price-adjuster' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Какие цены менять', 'wc-bulk-price-adjuster' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="wc_bpa_targets[]" value="regular" checked="checked" />
										<?php esc_html_e( 'Обычная цена', 'wc-bulk-price-adjuster' ); ?>
									</label><br />
									<label>
										<input type="checkbox" name="wc_bpa_targets[]" value="sale" />
										<?php esc_html_e( 'Цена со скидкой (если задана)', 'wc-bulk-price-adjuster' ); ?>
									</label>
								</td>
							</tr>
						</tbody>
					</table>

					<?php submit_button( __( 'Применить изменения', 'wc-bulk-price-adjuster' ) ); ?>
				</form>
			</div>
			<?php
		}

		/**
		 * Process form submission.
		 *
		 * @return array<int> Product IDs to keep selected.
		 */
		private function handle_form_request() {
			if ( ! isset( $_POST['wc_bpa_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_bpa_nonce'] ) ), 'wc_bpa_update_prices' ) ) {
				$this->add_notice( 'error', __( 'Ошибка безопасности: попробуйте ещё раз.', 'wc-bulk-price-adjuster' ) );
				return array();
			}

			$product_ids = isset( $_POST['product_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['product_ids'] ) ) : array();
			$product_ids = array_filter( $product_ids );

			$direction = isset( $_POST['wc_bpa_direction'] ) ? sanitize_key( wp_unslash( $_POST['wc_bpa_direction'] ) ) : 'increase';
			$mode      = isset( $_POST['wc_bpa_mode'] ) ? sanitize_key( wp_unslash( $_POST['wc_bpa_mode'] ) ) : 'fixed';

			$raw_value = isset( $_POST['wc_bpa_value'] ) ? wc_clean( wp_unslash( $_POST['wc_bpa_value'] ) ) : '';
			$value     = is_numeric( $raw_value ) ? (float) $raw_value : null;

			$targets = isset( $_POST['wc_bpa_targets'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['wc_bpa_targets'] ) ) : array();
			$targets = array_intersect( $targets, array( 'regular', 'sale' ) );

			if ( empty( $product_ids ) ) {
				$this->add_notice( 'error', __( 'Выберите хотя бы один товар.', 'wc-bulk-price-adjuster' ) );
				return array();
			}

			if ( empty( $targets ) ) {
				$this->add_notice( 'error', __( 'Выберите тип цен, которые нужно изменить.', 'wc-bulk-price-adjuster' ) );
				return $product_ids;
			}

			if ( is_null( $value ) || $value < 0 ) {
				$this->add_notice( 'error', __( 'Введите корректное значение изменения цены.', 'wc-bulk-price-adjuster' ) );
				return $product_ids;
			}

			if ( ! in_array( $direction, array( 'increase', 'decrease' ), true ) ) {
				$direction = 'increase';
			}

			if ( ! in_array( $mode, array( 'fixed', 'percent' ), true ) ) {
				$mode = 'fixed';
			}

			$results = $this->adjust_prices(
				$product_ids,
				$direction,
				$mode,
				$value,
				$targets
			);

			if ( $results['updated'] > 0 ) {
				/* translators: %d - number of products */
				$success_message = sprintf(
					_n(
						'Цены успешно обновлены для %d товара.',
						'Цены успешно обновлены для %d товаров.',
						$results['updated'],
						'wc-bulk-price-adjuster'
					),
					$results['updated']
				);

				if ( $results['skipped'] > 0 ) {
					/* translators: %d - number of skipped products */
					$success_message .= ' ' . sprintf(
						_n(
							'Пропущен %d товар (не удалось определить текущую цену).',
							'Пропущено %d товаров (не удалось определить текущую цену).',
							$results['skipped'],
							'wc-bulk-price-adjuster'
						),
						$results['skipped']
					);
				}

				$this->add_notice( 'success', $success_message );
			} else {
				$this->add_notice( 'warning', __( 'Не удалось обновить цены: убедитесь, что у выбранных товаров есть цены для изменения.', 'wc-bulk-price-adjuster' ) );
			}

			return $product_ids;
		}

		/**
		 * Update product prices.
		 *
		 * @param array<int> $product_ids Product IDs.
		 * @param string     $direction   increase|decrease.
		 * @param string     $mode        fixed|percent.
		 * @param float      $value       Value to apply.
		 * @param array<int> $targets     Target price types.
		 *
		 * @return array{updated:int, skipped:int}
		 */
		private function adjust_prices( $product_ids, $direction, $mode, $value, $targets ) {
			$updated = 0;
			$skipped = 0;

			foreach ( $product_ids as $product_id ) {
				$product = wc_get_product( $product_id );

				if ( ! $product ) {
					$skipped ++;
					continue;
				}

				if ( $product->is_type( 'variable' ) ) {
					$child_ids = $product->get_children();

					if ( empty( $child_ids ) ) {
						$skipped ++;
						continue;
					}

					foreach ( $child_ids as $child_id ) {
						$child_product = wc_get_product( $child_id );

						if ( ! $child_product ) {
							$skipped ++;
							continue;
						}

						$adjusted = $this->adjust_product_prices( $child_product, $direction, $mode, $value, $targets );
						if ( $adjusted ) {
							$updated ++;
						} else {
							$skipped ++;
						}
					}

					$product->save();
					wc_delete_product_transients( $product_id );
					continue;
				}

				$adjusted = $this->adjust_product_prices( $product, $direction, $mode, $value, $targets );

				if ( $adjusted ) {
					$updated ++;
				} else {
					$skipped ++;
				}
			}

			return array(
				'updated' => $updated,
				'skipped' => $skipped,
			);
		}

		/**
		 * Adjust prices for a single product instance.
		 *
		 * @param WC_Product $product   Product instance.
		 * @param string     $direction increase|decrease.
		 * @param string     $mode      fixed|percent.
		 * @param float      $value     Value to apply.
		 * @param array<int> $targets   Target price types.
		 *
		 * @return bool
		 */
		private function adjust_product_prices( $product, $direction, $mode, $value, $targets ) {
			$changed          = false;
			$price_decimals   = wc_get_price_decimals();
			$adjustment_sign  = 'increase' === $direction ? 1 : -1;
			$multiplier       = 'percent' === $mode ? ( $value / 100 ) : $value;
			$target_regular   = in_array( 'regular', $targets, true );
			$target_sale      = in_array( 'sale', $targets, true );

			if ( $target_regular ) {
				$current_regular = $product->get_regular_price();

				if ( '' !== $current_regular ) {
					$new_regular = $this->calculate_new_price( (float) $current_regular, $multiplier, $adjustment_sign, $mode );
					$product->set_regular_price( wc_format_decimal( $new_regular, $price_decimals ) );
					$changed = true;
				}
			}

			if ( $target_sale ) {
				$current_sale = $product->get_sale_price();

				if ( '' !== $current_sale ) {
					$new_sale = $this->calculate_new_price( (float) $current_sale, $multiplier, $adjustment_sign, $mode );

					$regular_price = $product->get_regular_price();
					if ( '' !== $regular_price && (float) $regular_price < $new_sale ) {
						$new_sale = (float) $regular_price;
					}

					$product->set_sale_price( wc_format_decimal( $new_sale, $price_decimals ) );
					$changed = true;
				}
			}

			if ( $changed ) {
				$this->refresh_product_price( $product );
				$product->save();
				wc_delete_product_transients( $product->get_id() );
			}

			return $changed;
		}

		/**
		 * Calculate new price.
		 *
		 * @param float  $base_price     Current price.
		 * @param float  $multiplier     Fixed value or percent fraction.
		 * @param int    $adjustment_sign +/-1.
		 * @param string $mode           fixed|percent.
		 *
		 * @return float
		 */
		private function calculate_new_price( $base_price, $multiplier, $adjustment_sign, $mode ) {
			if ( 'percent' === $mode ) {
				$delta = $base_price * $multiplier;
			} else {
				$delta = $multiplier;
			}

			$new_price = $base_price + ( $adjustment_sign * $delta );

			if ( $new_price < 0 ) {
				$new_price = 0;
			}

			return $new_price;
		}

		/**
		 * Force product price sync between regular/sale price and main price.
		 *
		 * @param WC_Product $product Product instance.
		 *
		 * @return void
		 */
		private function refresh_product_price( $product ) {
			if ( $product->get_sale_price() !== '' && $product->get_sale_price() < $product->get_regular_price() ) {
				$product->set_price( $product->get_sale_price() );
			} else {
				$product->set_price( $product->get_regular_price() );
			}
		}

		/**
		 * Add admin notice.
		 *
		 * @param string $type    success|error|warning|info.
		 * @param string $message Notice text.
		 *
		 * @return void
		 */
		private function add_notice( $type, $message ) {
			$this->notices[] = array(
				'type'    => $type,
				'message' => $message,
			);
		}

		/**
		 * Prepare selected products for Select2 control.
		 *
		 * @param array<int> $product_ids Product IDs.
		 *
		 * @return array<int, string>
		 */
		private function prepare_selected_products( $product_ids ) {
			if ( empty( $product_ids ) ) {
				return array();
			}

			$options = array();

			foreach ( $product_ids as $product_id ) {
				$product = wc_get_product( $product_id );

				if ( ! $product ) {
					continue;
				}

				$label = $product->get_name();
				if ( $product->is_type( 'variation' ) ) {
					$attributes = wc_get_formatted_variation( $product, true );
					if ( $attributes ) {
						$label .= ' — ' . $attributes;
					}
				}

				$options[ $product_id ] = $label;
			}

			return $options;
		}

		/**
		 * Admin notice shown when WooCommerce is missing.
		 *
		 * @return void
		 */
		public function woocommerce_missing_notice() {
			if ( current_user_can( 'activate_plugins' ) ) {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'Плагин WooCommerce Bulk Price Adjuster требует активного WooCommerce.', 'wc-bulk-price-adjuster' )
				);
			}
		}
	}

	WC_Bulk_Price_Adjuster::instance();
}
