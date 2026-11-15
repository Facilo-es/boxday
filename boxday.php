<?php
/**
 * Plugin Name:       BoxDay – CSA Farm Shares for WooCommerce
 * Description:       Fill your CSA from your own website: sign-ups, payment plans, swaps, skips, harvest totals and pickup sheets. No monthly fee, no share of sales.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Facilo
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       boxday
 * Domain Path:       /languages
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

define( 'BOXDAY_VERSION', '1.0.0' );
define( 'BOXDAY_FILE', __FILE__ );
define( 'BOXDAY_DIR', plugin_dir_path( __FILE__ ) );
define( 'BOXDAY_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	function ( $class_name ) {
		if ( 0 !== strpos( $class_name, __NAMESPACE__ . '\\' ) ) {
			return;
		}
		$file = BOXDAY_DIR . 'includes/class-' . str_replace( '_', '-', strtolower( substr( $class_name, strlen( __NAMESPACE__ ) + 1 ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( Schema::class, 'activate' ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		Schema::maybe_upgrade();
		Carrier::init();
		Cart::init();
		Signups::init();
		Waitlist::init();
		Privacy::init();
		Pages::init();
		Rest::init();
		Emails::init();
		Jobs::init();
		Admin::init();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'boxday sample', array( Sample::class, 'cli' ) );
		}
	}
);
