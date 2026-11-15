<?php
/**
 * The hidden product behind each share: spec L7.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps carriers hidden and unbuyable on their own.
 */
class Carrier {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'refuse_direct' ), 10, 2 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( __CLASS__, 'refuse_store_api' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_page' ) );
	}

	/**
	 * Whether a product is a carrier.
	 *
	 * @param int $product_id Product.
	 * @return bool
	 */
	public static function is_carrier( $product_id ) {
		return (bool) get_post_meta( $product_id, '_boxday_share', true );
	}

	/**
	 * Creates a share's carrier, or refreshes its name and price.
	 *
	 * @param int $share_id Share.
	 * @return int Product id.
	 */
	public static function ensure( $share_id ) {
		$share   = Db::get( 'share', $share_id );
		$season  = Seasons::get( (int) $share['season_id'] );
		$product = $share['product_id'] ? wc_get_product( (int) $share['product_id'] ) : null;
		if ( ! $product ) {
			$product = new \WC_Product_Simple();
		}
		$prices = array();
		foreach ( Seasons::sizes( $share_id ) as $size ) {
			foreach ( array( 'w', 'b' ) as $schedule ) {
				$price = Quote::size_price( $size, $schedule );
				if ( null !== $price ) {
					$prices[] = $price;
				}
			}
		}
		$product->set_name( $season['name'] . ': ' . $share['name'] );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->set_regular_price( $prices ? (string) min( $prices ) : '0' );
		$id = $product->save();
		update_post_meta( $id, '_boxday_share', $share_id );
		Db::update( 'share', array( 'product_id' => $id ), array( 'id' => $share_id ) );
		return $id;
	}

	/**
	 * Refuses a carrier added through a link.
	 *
	 * @param bool $passed     Whether the add goes ahead.
	 * @param int  $product_id Product.
	 * @return bool
	 */
	public static function refuse_direct( $passed, $product_id ) {
		if ( self::is_carrier( $product_id ) && ! Cart::$adding ) {
			wc_add_notice( __( 'Please sign up on our sign-up page.', 'boxday' ), 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * Refuses a carrier added through the Store API.
	 *
	 * @param \WC_Product $product Product.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException Always, for a carrier.
	 */
	public static function refuse_store_api( $product ) {
		if ( self::is_carrier( $product->get_id() ) && ! Cart::$adding ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'boxday_carrier', esc_html__( 'Please sign up on our sign-up page.', 'boxday' ), 400 );
		}
	}

	/**
	 * Sends a carrier's product page to the sign-up page.
	 */
	public static function redirect_page() {
		if ( is_singular( 'product' ) && self::is_carrier( get_queried_object_id() ) ) {
			wp_safe_redirect( Pages::url( 'signup' ) );
			exit;
		}
	}
}
