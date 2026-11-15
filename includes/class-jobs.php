<?php
/**
 * Scheduled jobs: every one is safe to run twice, and nothing about money or cut-offs waits on them.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler jobs, in group `boxday`.
 */
class Jobs {

	const BATCH = 50;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'boxday_daily', array( __CLASS__, 'daily' ) );
		add_action( 'boxday_send_batch', array( __CLASS__, 'send_batch' ), 10, 3 );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Keeps the daily job booked at 6:00 am, site time.
	 */
	public static function schedule() {
		if ( as_has_scheduled_action( 'boxday_daily', array(), 'boxday' ) ) {
			return;
		}
		$next = Clock::now()->setTime( 6, 0 );
		if ( $next->getTimestamp() <= Clock::ts() ) {
			$next = $next->modify( '+1 day' );
		}
		as_schedule_recurring_action( $next->getTimestamp(), DAY_IN_SECONDS, 'boxday_daily', array(), 'boxday' );
	}

	/**
	 * Queues a week's email.
	 *
	 * @param int $week_id Week.
	 */
	public static function queue_box_mail( $week_id ) {
		as_enqueue_async_action( 'boxday_send_batch', array( 'box', $week_id, 0 ), 'boxday' );
	}

	/**
	 * The daily job.
	 */
	public static function daily() {
		static $running = false;
		if ( $running ) {
			return;
		}
		$running = true;
		self::create_payment_orders();
		self::payment_mail();
		self::cancel_stale();
		foreach ( Seasons::open() as $season ) {
			Waitlist::offer_next( (int) $season['id'] );
		}
		Waitlist::expire();
		self::queue_reminders();
		// The scheduler already fires boxday_daily, which runs this method, so other
		// listeners such as Pro run then. Fire it here only for a direct call.
		if ( ! doing_action( 'boxday_daily' ) ) {
			/**
			 * Fires BoxDay's daily job for other listeners.
			 */
			do_action( 'boxday_daily' );
		}
		$running = false;
	}

	/**
	 * F6: one order per member per date, created 7 days before its date.
	 */
	public static function create_payment_orders() {
		$limit = gmdate( 'Y-m-d', strtotime( Clock::today() . ' +7 days' ) );
		$rows  = Db::rows(
			'SELECT i.*, s.member_id FROM ' . Db::t( 'instalment' ) . ' i JOIN ' . Db::t( 'signup' ) . " s ON s.id = i.signup_id
			WHERE i.state = 'due' AND i.order_id = 0 AND i.is_sample = 0 AND i.due_date <= %s AND s.status = 'active' ORDER BY s.member_id, i.due_date, i.id",
			array( $limit )
		);
		$sets  = array();
		foreach ( $rows as $row ) {
			$sets[ $row['member_id'] . '|' . $row['due_date'] ][] = $row;
		}
		foreach ( $sets as $parts ) {
			self::payment_order( $parts );
		}
	}

	/**
	 * Which payment this is, and how many a sign-up has.
	 *
	 * @param array $part Instalment row.
	 * @return array n, count.
	 */
	private static function position( $part ) {
		$dates = Db::col( 'SELECT due_date FROM ' . Db::t( 'instalment' ) . ' WHERE signup_id = %d ORDER BY due_date', array( $part['signup_id'] ) );
		return array(
			'n'     => (int) array_search( $part['due_date'], $dates, true ) + 1,
			'count' => count( $dates ),
		);
	}

	/**
	 * Creates a pending order with a guest pay link. Nothing is ever charged by itself.
	 *
	 * @param array $parts Instalments of one member and date.
	 */
	private static function payment_order( $parts ) {
		$member = Db::get( 'member', (int) $parts[0]['member_id'] );
		$order  = wc_create_order(
			array(
				'status'      => 'pending',
				'customer_id' => 0,
				'created_via' => 'boxday',
			)
		);
		foreach ( $parts as $part ) {
			$signup  = Db::get( 'signup', (int) $part['signup_id'] );
			$share   = Db::get( 'share', (int) $signup['share_id'] );
			$season  = Seasons::get( (int) $signup['season_id'] );
			$product = wc_get_product( (int) $share['product_id'] );
			$pos     = self::position( $part );
			$item    = new \WC_Order_Item_Product();
			if ( $product ) {
				$item->set_product( $product );
			}
			$item->set_name(
				sprintf(
					/* translators: 1: season, 2: share, 3: payment number, 4: payments. */
					__( '%1$s: %2$s, payment %3$d of %4$d', 'boxday' ),
					$season['name'],
					$share['name'],
					$pos['n'],
					$pos['count']
				)
			);
			$item->set_quantity( 1 );
			$item->set_subtotal( (float) $part['amount'] );
			$item->set_total( (float) $part['amount'] );
			$order->add_item( $item );
		}
		$order->set_address(
			array(
				'first_name' => $member['first_name'],
				'last_name'  => $member['last_name'],
				'email'      => $member['email'],
				'phone'      => $member['phone'],
			),
			'billing'
		);
		$order->calculate_totals( false );
		$order->update_meta_data( '_boxday_instalments', array_map( 'intval', array_column( $parts, 'id' ) ) );
		$order->update_meta_data( '_boxday_due', $parts[0]['due_date'] );
		$order->save();
		foreach ( $parts as $part ) {
			Db::update(
				'instalment',
				array(
					'state'    => 'ordered',
					'order_id' => $order->get_id(),
				),
				array( 'id' => $part['id'] )
			);
		}
	}

	/**
	 * Sends each stage once: 7 days before, on the date, 3 days after. Overdue changes nothing else.
	 */
	public static function payment_mail() {
		$today = Clock::today();
		$rows  = Db::rows(
			'SELECT order_id, MIN(id) AS id, MIN(signup_id) AS signup_id, MIN(due_date) AS due_date FROM ' . Db::t( 'instalment' ) . " WHERE state = 'ordered' AND order_id > 0 GROUP BY order_id"
		);
		foreach ( $rows as $row ) {
			$order = wc_get_order( (int) $row['order_id'] );
			if ( ! $order || ! $order->needs_payment() ) {
				continue;
			}
			$date  = $row['due_date'];
			$late  = gmdate( 'Y-m-d', strtotime( $date . ' +3 days' ) );
			$done  = (int) $order->get_meta( '_boxday_stage' );
			$stage = $today >= $late ? 3 : ( $today >= $date ? 2 : 1 );
			if ( $stage <= $done ) {
				continue;
			}
			$pos = self::position( $row );
			Emails::payment(
				$order,
				array(
					1 => 'soon',
					2 => 'today',
					3 => 'late',
				)[ $stage ],
				$date,
				$pos['n'],
				$pos['count']
			);
			$order->update_meta_data( '_boxday_stage', $stage );
			$order->save_meta_data();
			Db::update( 'instalment', array( 'reminded_at' => Clock::mysql() ), array( 'order_id' => $row['order_id'] ) );
			if ( 3 === $stage ) {
				foreach ( Db::col( 'SELECT signup_id FROM ' . Db::t( 'instalment' ) . ' WHERE order_id = %d', array( $row['order_id'] ) ) as $signup_id ) {
					Signups::refresh_pay_state( (int) $signup_id );
				}
			}
		}
	}

	/**
	 * L13: a sign-up still pending after 24 hours is cancelled. If paid later, it comes back.
	 */
	public static function cancel_stale() {
		$limit = gmdate( 'Y-m-d H:i:s', Clock::ts() - DAY_IN_SECONDS );
		foreach ( Db::col( 'SELECT id FROM ' . Db::t( 'signup' ) . " WHERE status = 'pending' AND created_at < %s", array( $limit ) ) as $id ) {
			Signups::cancel( (int) $id );
		}
	}

	/**
	 * Books tomorrow's reminders for 8:00 am the day before pickup, which is today.
	 */
	public static function queue_reminders() {
		$tomorrow = gmdate( 'Y-m-d', strtotime( Clock::today() . ' +1 day' ) );
		$sent_at  = Clock::now()->setTime( 8, 0 )->getTimestamp();
		foreach ( Db::rows( 'SELECT * FROM ' . Db::t( 'season' ) . " WHERE status = 'live'" ) as $season ) {
			foreach ( Seasons::sites( (int) $season['id'] ) as $site ) {
				$week = Db::row(
					'SELECT * FROM ' . Db::t( 'week' ) . " WHERE season_id = %d AND start_date = %s AND state <> 'closed'",
					array( $season['id'], Calendar::monday_of( $tomorrow ) )
				);
				if ( $week && Calendar::pickup_date( $week['start_date'], $site['weekday'] ) === $tomorrow ) {
					as_schedule_single_action( max( $sent_at, Clock::ts() ), 'boxday_send_batch', array( 'reminder', (int) $week['id'], (int) $site['id'] ), 'boxday' );
				}
			}
		}
	}

	/**
	 * F14: sends up to 50 emails, then queues the next batch. Sent marks stop any repeat.
	 *
	 * @param string $kind    `box` or `reminder`.
	 * @param int    $week_id Week.
	 * @param int    $site_id Site, for a reminder.
	 */
	public static function send_batch( $kind, $week_id, $site_id = 0 ) {
		$week = Db::get( 'week', $week_id );
		if ( ! $week ) {
			return;
		}
		$column = 'box' === $kind ? 'box_mail_at' : 'reminder_mail_at';
		$sw     = Db::t( 'signup_week' );
		$people = array();
		foreach ( Totals::due_signups( $week ) as $signup ) {
			if ( 'skip' === $signup['skipped'] || ( $site_id && $signup['week_site'] !== $site_id ) ) {
				continue;
			}
			$sent = Db::value( "SELECT {$column} FROM {$sw} WHERE signup_id = %d AND week_id = %d", array( $signup['id'], $week_id ) );
			if ( ! $sent ) {
				$people[ $signup['member_id'] ][] = $signup;
			}
		}
		$now   = Clock::mysql();
		$count = 0;
		foreach ( $people as $member_id => $signups ) {
			if ( self::BATCH === $count ) {
				as_enqueue_async_action( 'boxday_send_batch', array( $kind, $week_id, $site_id ), 'boxday' );
				return;
			}
			$member = Db::get( 'member', (int) $member_id );
			foreach ( $signups as $signup ) {
				Signups::set_week( (int) $signup['id'], $week_id, array( $column => $now ) );
			}
			if ( 'box' === $kind ) {
				Emails::box( $member, $signups[0], $week );
			} else {
				Emails::reminder( $member, Db::get( 'site', $signups[0]['week_site'] ), $week );
			}
			++$count;
		}
	}
}
