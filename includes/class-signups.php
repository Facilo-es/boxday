<?php
/**
 * Orders to sign-ups: spec L13. Safe to run any number of times on the same order.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps sign-ups in step with WooCommerce orders.
 */
class Signups {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'from_order' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'from_order' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'status_changed' ), 10, 3 );
	}

	/**
	 * Creates the member and sign-ups for an order, then syncs their state.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function from_order( $order ) {
		$season_ids = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$data = json_decode( (string) $item->get_meta( '_boxday' ), true );
			if ( is_array( $data ) ) {
				$season_ids[ $data['season_id'] ] = true;
			}
		}
		if ( ! $season_ids ) {
			return;
		}
		$lock = 'boxday_capacity_' . key( $season_ids );
		$held = Db::value( 'SELECT GET_LOCK(%s, 5)', array( $lock ) );
		foreach ( $order->get_items() as $item_id => $item ) {
			$data = json_decode( (string) $item->get_meta( '_boxday' ), true );
			if ( ! is_array( $data ) || Db::value( 'SELECT id FROM ' . Db::t( 'signup' ) . ' WHERE order_item_id = %d', array( $item_id ) ) ) {
				continue;
			}
			self::create( $order, $item_id, $data );
		}
		if ( $held ) {
			Db::value( 'SELECT RELEASE_LOCK(%s)', array( $lock ) );
		}
		self::sync( $order );
	}

	/**
	 * Saves one sign-up, flagged rather than refused if the place has gone.
	 *
	 * @param \WC_Order $order   Order.
	 * @param int       $item_id Order line.
	 * @param array     $data    The line's data.
	 */
	private static function create( $order, $item_id, $data ) {
		$member_id = Members::upsert(
			array(
				'email'      => $order->get_billing_email(),
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
				'phone'      => $order->get_billing_phone(),
				'street'     => $data['street'],
				'zip'        => $data['zip'],
				'user_id'    => $order->get_customer_id(),
			)
		);
		$share     = Db::get( 'share', $data['share_id'] );
		$site      = Db::get( 'site', $data['site_id'] );
		$over      = 'main' === $share['kind'] && ( ! Capacity::share_fits( $share ) || ! Capacity::site_has_room( $site, $data['schedule'], $data['grp'] ) );
		$season    = Seasons::get( $data['season_id'] );
		$signup_id = Db::insert(
			'signup',
			array(
				'member_id'         => $member_id,
				'season_id'         => $data['season_id'],
				'share_id'          => $data['share_id'],
				'size_id'           => $data['size_id'],
				'site_id'           => $data['site_id'],
				'schedule'          => $data['schedule'],
				'grp'               => $data['grp'],
				'status'            => 'pending',
				'pay_state'         => 'unpaid',
				'order_id'          => $order->get_id(),
				'order_item_id'     => $item_id,
				'price_total'       => $data['total'],
				'boxes_paid'        => $data['left'],
				'first_week_id'     => $data['first'],
				'agreement_version' => (int) $data['agreed'],
				'agreed_at'         => $data['agreed'] ? Clock::mysql() : null,
				'over_capacity'     => $over ? 1 : 0,
				'created_at'        => Clock::mysql(),
			)
		);
		foreach ( $data['parts'] as $part ) {
			Db::insert(
				'instalment',
				array(
					'signup_id' => $signup_id,
					'due_date'  => $part['date'],
					'amount'    => $part['amount'],
				)
			);
		}
		if ( ! empty( $data['offer'] ) ) {
			Waitlist::done( (int) $data['offer'] );
		}
		self::log( $signup_id, 'member', 'signup', (string) $season['name'] );
	}

	/**
	 * Follows an order's status.
	 *
	 * @param int    $order_id Order.
	 * @param string $from     Old status.
	 * @param string $to       New status.
	 */
	public static function status_changed( $order_id, $from, $to ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$instalments = $order->get_meta( '_boxday_instalments' );
		if ( $instalments ) {
			self::later_payment( $order, (array) $instalments, $to );
			return;
		}
		self::from_order( $order );
		self::sync( $order );
	}

	/**
	 * L13: a later payment was paid.
	 *
	 * @param \WC_Order $order Order.
	 * @param int[]     $ids   Instalments.
	 * @param string    $to    New status.
	 */
	private static function later_payment( $order, $ids, $to ) {
		if ( ! in_array( $to, array( 'processing', 'completed' ), true ) ) {
			return;
		}
		foreach ( $ids as $id ) {
			$part = Db::get( 'instalment', (int) $id );
			if ( ! $part || 'paid' === $part['state'] ) {
				continue;
			}
			Db::update( 'instalment', array( 'state' => 'paid' ), array( 'id' => $part['id'] ) );
			self::refresh_pay_state( (int) $part['signup_id'] );
			self::log( (int) $part['signup_id'], 'member', 'payment', (string) $part['amount'] );
		}
	}

	/**
	 * Sets `plan`, `paid` or `overdue` from what's left to pay.
	 *
	 * @param int $signup_id Sign-up.
	 */
	public static function refresh_pay_state( $signup_id ) {
		$table = Db::t( 'instalment' );
		$open  = (int) Db::value( "SELECT COUNT(*) FROM {$table} WHERE signup_id = %d AND state IN ('due','ordered')", array( $signup_id ) );
		$late  = gmdate( 'Y-m-d', strtotime( Clock::today() . ' -3 days' ) );
		$over  = (int) Db::value( "SELECT COUNT(*) FROM {$table} WHERE signup_id = %d AND state IN ('due','ordered') AND due_date <= %s", array( $signup_id, $late ) );
		$state = $open ? ( $over ? 'overdue' : 'plan' ) : 'paid';
		Db::update( 'signup', array( 'pay_state' => $state ), array( 'id' => $signup_id ) );
	}

	/**
	 * L13: an order's status decides its sign-ups' state.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function sync( $order ) {
		$status  = $order->get_status();
		$signups = Db::rows( 'SELECT * FROM ' . Db::t( 'signup' ) . ' WHERE order_id = %d', array( $order->get_id() ) );
		$welcome = false;
		foreach ( $signups as $signup ) {
			$id = (int) $signup['id'];
			if ( in_array( $status, array( 'processing', 'completed' ), true ) ) {
				if ( 'cancelled' === $signup['status'] ) {
					self::revive( $signup );
				}
				Db::update( 'signup', array( 'status' => 'active' ), array( 'id' => $id ) );
				self::refresh_pay_state( $id );
				$welcome = true;
			} elseif ( 'on-hold' === $status ) {
				Db::update( 'signup', array( 'status' => 'active' ), array( 'id' => $id ) );
				$welcome = true;
			} elseif ( in_array( $status, array( 'cancelled', 'failed', 'refunded' ), true ) ) {
				self::cancel( $id );
			}
		}
		if ( $welcome && $signups && ! $order->get_meta( '_boxday_welcomed' ) ) {
			$order->update_meta_data( '_boxday_welcomed', 1 );
			$order->save_meta_data();
			Emails::welcome( (int) $signups[0]['member_id'], $order->get_id() );
		}
	}

	/**
	 * L13: a cancelled sign-up paid after all comes back, flagged if the place has gone.
	 *
	 * @param array $signup Sign-up row.
	 */
	private static function revive( $signup ) {
		$share = Db::get( 'share', (int) $signup['share_id'] );
		$site  = Db::get( 'site', (int) $signup['site_id'] );
		$over  = 'main' === $share['kind'] && ( ! Capacity::share_fits( $share ) || ! Capacity::site_has_room( $site, $signup['schedule'], $signup['grp'] ) );
		Db::update( 'signup', array( 'over_capacity' => $over ? 1 : 0 ), array( 'id' => $signup['id'] ) );
		Db::update(
			'instalment',
			array( 'state' => 'due' ),
			array(
				'signup_id' => $signup['id'],
				'state'     => 'void',
			)
		);
	}

	/**
	 * Cancels a sign-up, frees its place and voids what's left of its plan. It never touches money.
	 *
	 * @param int $signup_id Sign-up.
	 */
	public static function cancel( $signup_id ) {
		$signup = Db::get( 'signup', $signup_id );
		Db::update( 'signup', array( 'status' => 'cancelled' ), array( 'id' => $signup_id ) );
		if ( $signup && 'cancelled' !== $signup['status'] ) {
			do_action( 'boxday_place_freed', (int) $signup['season_id'] );
		}
		Db::run(
			'UPDATE ' . Db::t( 'instalment' ) . " SET state = 'void' WHERE signup_id = %d AND state IN ('due','ordered')",
			array( $signup_id )
		);
	}

	/**
	 * Adds a history row.
	 *
	 * @param int    $signup_id Sign-up.
	 * @param string $actor     Who.
	 * @param string $action    What.
	 * @param string $detail    Detail.
	 */
	public static function log( $signup_id, $actor, $action, $detail = '' ) {
		Db::insert(
			'log',
			array(
				'signup_id'  => $signup_id,
				'actor'      => $actor,
				'action'     => $action,
				'detail'     => $detail,
				'created_at' => Clock::mysql(),
			)
		);
	}

	/**
	 * Active sign-ups of a member, oldest first.
	 *
	 * @param int $member_id Member.
	 * @return array
	 */
	public static function of_member( $member_id ) {
		return Db::rows(
			'SELECT * FROM ' . Db::t( 'signup' ) . " WHERE member_id = %d AND status = 'active' ORDER BY id",
			array( $member_id )
		);
	}

	/**
	 * Whether a sign-up gets a box in a week: L3, L4 and L10.
	 *
	 * @param array $signup Sign-up row.
	 * @param array $week   Week row.
	 * @param int   $skips  Skips used, for make-up weeks.
	 * @return bool
	 */
	public static function due( $signup, $week, $skips = 0 ) {
		if ( 'closed' === $week['state'] ) {
			return false;
		}
		if ( 'makeup' === $week['state'] ) {
			$season = Seasons::get( (int) $signup['season_id'] );
			return Calendar::has_makeup_box( (int) $week['number'] - (int) $season['weeks'], $skips );
		}
		if ( 'b' === $signup['schedule'] && $week['grp'] !== $signup['grp'] ) {
			return false;
		}
		$first = $signup['first_week_id'] ? Db::get( 'week', (int) $signup['first_week_id'] ) : null;
		return ! $first || (int) $week['number'] >= (int) $first['number'];
	}

	/**
	 * Skips used by a sign-up. Always read with a query, never stored.
	 *
	 * @param int $signup_id Sign-up.
	 * @return int
	 */
	public static function skips_used( $signup_id ) {
		return (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'signup_week' ) . " WHERE signup_id = %d AND skipped <> ''", array( $signup_id ) );
	}

	/**
	 * A sign-up's row for one week, if it has one.
	 *
	 * @param int $signup_id Sign-up.
	 * @param int $week_id   Week.
	 * @return array|null
	 */
	public static function week_row( $signup_id, $week_id ) {
		return Db::row( 'SELECT * FROM ' . Db::t( 'signup_week' ) . ' WHERE signup_id = %d AND week_id = %d', array( $signup_id, $week_id ) );
	}

	/**
	 * Sets values on a sign-up's week, creating the row if needed.
	 *
	 * @param int   $signup_id Sign-up.
	 * @param int   $week_id   Week.
	 * @param array $data      Columns.
	 */
	public static function set_week( $signup_id, $week_id, $data ) {
		if ( self::week_row( $signup_id, $week_id ) ) {
			Db::update(
				'signup_week',
				$data,
				array(
					'signup_id' => $signup_id,
					'week_id'   => $week_id,
				)
			);
			return;
		}
		Db::insert(
			'signup_week',
			array_merge(
				array(
					'signup_id' => $signup_id,
					'week_id'   => $week_id,
				),
				$data
			)
		);
	}

	/**
	 * The site a sign-up uses in a week, after any one-week move.
	 *
	 * @param array $signup Sign-up row.
	 * @param array $week   Week row.
	 * @return array Site row.
	 */
	public static function week_site( $signup, $week ) {
		$row = self::week_row( (int) $signup['id'], (int) $week['id'] );
		$id  = $row && $row['site_override'] ? (int) $row['site_override'] : (int) $signup['site_id'];
		return Db::get( 'site', $id );
	}
}
