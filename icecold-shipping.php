<?php
/**
 * Plugin Name:       ICECOLD Shipping
 * Plugin URI:        https://it.icecoldtrans.cz/integrace#woocommerce
 * Description:       Chlazená a mražená doprava ICECOLD pro WooCommerce — zásilky přes API, štítky 100 × 150 mm a stavy zpět přes webhooky.
 * Version:           2.2.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * WC requires at least: 8.0
 * WC tested up to:   11.3
 * Requires Plugins:  woocommerce
 * Author:            ICECOLD Transport
 * Author URI:        https://icecoldtrans.cz
 * License:           GPL-2.0-or-later
 * Text Domain:       icecold-shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ICECOLD_SHIPPING_VERSION', '2.2.0' );
define( 'ICECOLD_SHIPPING_FILE', __FILE__ );
define( 'ICECOLD_SHIPPING_DIR', plugin_dir_path( __FILE__ ) );
define( 'ICECOLD_SHIPPING_URL', plugin_dir_url( __FILE__ ) );

require_once ICECOLD_SHIPPING_DIR . 'includes/class-settings.php';
require_once ICECOLD_SHIPPING_DIR . 'includes/class-api.php';
require_once ICECOLD_SHIPPING_DIR . 'includes/class-order-shipment.php';
require_once ICECOLD_SHIPPING_DIR . 'includes/class-webhook.php';
require_once ICECOLD_SHIPPING_DIR . 'includes/class-admin.php';

/**
 * HPOS (vlastní tabulky objednávek) — plugin je s nimi kompatibilní.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-warning"><p>';
					echo esc_html__( 'ICECOLD Shipping vyžaduje aktivní WooCommerce.', 'icecold-shipping' );
					echo '</p></div>';
				}
			);
			return;
		}

		ICECOLD_Webhook::init();
		ICECOLD_Admin::init();
		ICECOLD_Order_Shipment::init();
	},
	20
);

add_filter(
	'woocommerce_shipping_methods',
	static function ( array $methods ): array {
		/* Třída dědí z WC_Shipping_Method, proto se načítá až tady —
		   při načtení pluginů ještě WooCommerce není inicializované. */
		require_once ICECOLD_SHIPPING_DIR . 'includes/class-shipping-method.php';
		$methods['icecold'] = 'ICECOLD_Shipping_Method';

		return $methods;
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( array $links ): array {
		$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=icecold-shipping' ) ) . '">'
			. esc_html__( 'Nastavení', 'icecold-shipping' ) . '</a>';
		$links[] = '<a href="https://it.icecoldtrans.cz/integrace#woocommerce" target="_blank" rel="noopener">'
			. esc_html__( 'Manuál', 'icecold-shipping' ) . '</a>';

		return $links;
	}
);
