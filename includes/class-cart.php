<?php
/**
 * Cart lines: spec L7. The server recomputes every price from IDs, in memory, on every load.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * From the sign-up button to the order line.
 */
class Cart {

	/**
	 * True while our own code adds a carrier.
	 *
	 * @var bool
	 */
	public static $adding = false;

	/**
	 * A message for the sign-up page after a refused post.
	 *
	 * @var string
	 */
	public static $error = '';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_post' ), 5 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'set_prices' ), 20 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_items' ) );
		add_action( 'woocommerce_cart_has_errors', 'wc_print_notices' );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'labels' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_line' ), 10, 3 );
	}

	/**
	 * Reads the sign-up form's choices from a request.
	 *
	 * @param array $in Raw values.
	 * @return array
	 */
	public static function clean_choices( $in ) {
		$site = isset( $in['site'] ) ? sanitize_key( $in['site'] ) : '';
		return array(
			'season_id' => isset( $in['season_id'] ) ? absint( $in['season_id'] ) : 0,
			'size_id'   => isset( $in['size_id'] ) ? absint( $in['size_id'] ) : 0,
			'schedule'  => isset( $in['schedule'] ) && 'b' === $in['schedule'] ? 'b' : 'w',
			'site'      => $site,
			'addons'    => isset( $in['addons'] ) ? array_map( 'absint', (array) $in['addons'] ) : array(),
			'plan'      => isset( $in['plan'] ) && 'parts' === $in['plan'] ? 'parts' : 'full',
			'zip'       => isset( $in['zip'] ) ? substr( preg_replace( '/[^0-9A-Za-z -]/', '', (string) $in['zip'] ), 0, 12 ) : '',
			'street'    => isset( $in['street'] ) ? sanitize_text_field( (string) $in['street'] ) : '',
			'agree'     => ! empty( $in['agree'] ),
			'offer'     => isset( $in['offer'] ) ? self::claim_offer( (string) $in['offer'] ) : '',
		);
	}

	/**
	 * L12: an open waitlist offer lets its holder use the place it holds.
	 *
	 * @param string $token Offer token.
	 * @return string The token if it's open, else empty.
	 */
	public static function claim_offer( $token ) {
		$token = preg_replace( '/[^A-Za-z0-9_-]/', '', $token );
		$row   = '' === $token ? null : Waitlist::from_token( $token );
		if ( ! Waitlist::is_open( $row ) ) {
			return '';
		}
		Capacity::$own_offer = (int) $row['id'];
		return $token;
	}

	/**
	 * Turns the delivery choice into a real route, by ZIP.
	 *
	 * @param array $choices Clean choices.
	 * @return array Choices with site_id set. No route gives site_id 0.
	 */
	public static function resolve_site( $choices ) {
		$choices['site_id'] = 0;
		if ( 'delivery' === $choices['site'] ) {
			$route = '' !== $choices['zip'] ? Seasons::route_for_zip( $choices['season_id'], $choices['zip'] ) : null;
			if ( $route ) {
				$choices['site_id'] = (int) $route['id'];
			}
		} else {
			$choices['site_id'] = absint( $choices['site'] );
		}
		return $choices;
	}

	/**
	 * The sign-up button: check everything again, add the lines, go to the checkout.
	 */
	public static function handle_post() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Links::form_token_ok() does this job, as explained there.
		if ( empty( $_POST['boxday_signup'] ) ) {
			return;
		}
		if ( ! Links::form_token_ok( sanitize_text_field( wp_unslash( $_POST['boxday_signup'] ) ) ) ) {
			self::$error = __( 'Something changed since you opened this page. We\'ve updated your total.', 'boxday' );
			return;
		}
		$choices = self::resolve_site( self::clean_choices( wp_unslash( $_POST ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! $choices['site_id'] ) {
			self::$error = __( 'Please choose where you\'ll collect your box.', 'boxday' );
			return;
		}
		$quote = Quote::build( $choices );
		if ( is_wp_error( $quote ) ) {
			self::$error = $quote->get_error_message();
			return;
		}
		$refusal = Quote::refusal( $quote );
		$season  = $quote['season'];
		if ( '' === $refusal && 'delivery' === $quote['site']['type'] && '' === $choices['street'] ) {
			$refusal = __( 'Please choose where you\'ll collect your box.', 'boxday' );
		}
		if ( '' === $refusal && $season['agreement_version'] && ! $choices['agree'] ) {
			$refusal = __( 'Please tick the box to agree to the member agreement.', 'boxday' );
		}
		if ( '' !== $refusal ) {
			self::$error = $refusal;
			return;
		}
		$gid          = wp_generate_uuid4();
		self::$adding = true;
		foreach ( $quote['lines'] as $index => $line ) {
			WC()->cart->add_to_cart(
				(int) $line['share']['product_id'],
				1,
				0,
				array(),
				array(
					'boxday' => array(
						'gid'       => $gid,
						'idx'       => $index,
						'season_id' => (int) $season['id'],
						'size_id'   => $choices['size_id'],
						'addons'    => $choices['addons'],
						'site_id'   => $choices['site_id'],
						'schedule'  => $quote['schedule'],
						'grp'       => $quote['grp'],
						'plan'      => $choices['plan'],
						'zip'       => $choices['zip'],
						'street'    => $choices['street'],
						'agreed'    => $choices['agree'] ? (int) $season['agreement_version'] : 0,
						'offer'     => $choices['offer'],
					),
				)
			);
		}
		self::$adding = false;
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * The quote behind a cart line. One group's sums run once per request.
	 *
	 * @param array $data The line's BoxDay data.
	 * @return array|\WP_Error
	 */
	private static function quote_for( $data ) {
		static $cache = array();
		$key          = $data['gid'] . '|' . Clock::ts();
		if ( ! isset( $cache[ $key ] ) ) {
			Capacity::$own_offer = 0;
			if ( ! empty( $data['offer'] ) ) {
				self::claim_offer( $data['offer'] );
			}
			$cache[ $key ] = Quote::build( $data );
		}
		return $cache[ $key ];
	}

	/**
	 * Prices and names, in memory only, so a cart left past a cut-off falls to the right price.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public static function set_prices( $cart ) {
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['boxday'] ) ) {
				continue;
			}
			$quote = self::quote_for( $item['boxday'] );
			$line  = is_wp_error( $quote ) || ! isset( $quote['lines'][ $item['boxday']['idx'] ] ) ? null : $quote['lines'][ $item['boxday']['idx'] ];
			if ( ! $line ) {
				continue;
			}
			$item['data']->set_price( $line['today'] );
			$item['data']->set_name( $quote['season']['name'] . ': ' . $line['share']['name'] . ', ' . $line['size']['name'] );
		}
	}

	/**
	 * Removes carriers with no data, and lines whose season closed or whose site filled.
	 */
	public static function check_items() {
		$cart = WC()->cart;
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item['boxday'] ) ) {
				if ( Carrier::is_carrier( $item['product_id'] ) ) {
					$cart->remove_cart_item( $key );
				}
				continue;
			}
			$quote = self::quote_for( $item['boxday'] );
			if ( is_wp_error( $quote ) ) {
				$cart->remove_cart_item( $key );
				wc_add_notice( $quote->get_error_message(), 'error' );
				continue;
			}
			$left = $quote['boxes']['left'];
			if ( ! Seasons::signup_open( $quote['season'] ) || $left < 1 ) {
				$cart->remove_cart_item( $key );
				wc_add_notice( sprintf( /* translators: %s: season name. */ __( 'Sign-ups for %s have closed, so we\'ve taken this out of your cart.', 'boxday' ), $quote['season']['name'] ), 'error' );
			} elseif ( ! Capacity::site_has_room( $quote['site'], $quote['schedule'], $quote['grp'] ) ) {
				wc_add_notice( sprintf( /* translators: %s: pickup site. */ __( '%s just filled up. Please go back and pick another place.', 'boxday' ), $quote['site']['name'] ), 'error' );
			}
		}
	}

	/**
	 * The label rows for a line.
	 *
	 * @param array $data The line's BoxDay data.
	 * @return array Label to value.
	 */
	public static function line_labels( $data ) {
		$quote = self::quote_for( $data );
		if ( is_wp_error( $quote ) ) {
			return array();
		}
		$site   = $quote['site'];
		$labels = array(
			__( 'Pickup', 'boxday' )    => sprintf( '%1$s, %2$s %3$s', $site['name'], Format::weekdays( (int) $site['weekday'] ), Format::window( $site['time_from'], $site['time_to'] ) ),
			__( 'First box', 'boxday' ) => $quote['boxes']['first_date'] ? Format::date( $quote['boxes']['first_date'], 'F j, Y' ) : '',
			__( 'Boxes', 'boxday' )     => (string) $quote['boxes']['left'],
			__( 'Schedule', 'boxday' )  => 'b' === $quote['schedule'] ? __( 'Every other week', 'boxday' ) : __( 'Every week', 'boxday' ),
		);
		$line   = $quote['lines'][ $data['idx'] ];
		if ( $line['parts'] ) {
			$labels[ __( 'Paid today', 'boxday' ) ] = sprintf( /* translators: 1: paid today, 2: total. */ __( '%1$s of %2$s', 'boxday' ), Format::money( $line['today'] ), Format::money( $line['total'] ) );
		}
		return $labels;
	}

	/**
	 * Shows the labels in the cart and at checkout.
	 *
	 * @param array $rows Existing rows.
	 * @param array $item Cart item.
	 * @return array
	 */
	public static function labels( $rows, $item ) {
		if ( ! empty( $item['boxday'] ) ) {
			foreach ( self::line_labels( $item['boxday'] ) as $label => $value ) {
				$rows[] = array(
					'name'  => $label,
					'value' => esc_html( $value ),
				);
			}
		}
		return $rows;
	}

	/**
	 * Saves the labels and our data on the order line.
	 *
	 * @param \WC_Order_Item_Product $item     Order line.
	 * @param string                 $cart_key Cart key.
	 * @param array                  $values   Cart item.
	 */
	public static function save_line( $item, $cart_key, $values ) {
		if ( empty( $values['boxday'] ) ) {
			return;
		}
		$data  = $values['boxday'];
		$quote = self::quote_for( $data );
		if ( is_wp_error( $quote ) ) {
			return;
		}
		foreach ( self::line_labels( $data ) as $label => $value ) {
			$item->add_meta_data( $label, $value, true );
		}
		$item->add_meta_data( '_boxday', wp_json_encode( self::line_data( $quote, $data ) ), true );
	}

	/**
	 * What a sign-up needs from an order line. Hand-added orders use it too.
	 *
	 * @param array $quote Quote.
	 * @param array $data  idx, zip, street, agreed and offer.
	 * @return array
	 */
	public static function line_data( $quote, $data ) {
		$line  = $quote['lines'][ $data['idx'] ];
		$offer = empty( $data['offer'] ) ? null : Waitlist::from_token( $data['offer'] );
		return array(
			'season_id' => (int) $quote['season']['id'],
			'share_id'  => (int) $line['share']['id'],
			'size_id'   => (int) $line['size']['id'],
			'site_id'   => (int) $quote['site']['id'],
			'schedule'  => $quote['schedule'],
			'grp'       => $quote['grp'],
			'total'     => $line['total'],
			'left'      => $quote['boxes']['left'],
			'first'     => $quote['boxes']['first'] ? (int) $quote['boxes']['first']['id'] : 0,
			'parts'     => $line['parts'],
			'zip'       => $data['zip'],
			'street'    => $data['street'],
			'agreed'    => $data['agreed'],
			'offer'     => $offer ? (int) $offer['id'] : 0,
		);
	}
}
