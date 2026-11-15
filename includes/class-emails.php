<?php
/**
 * The emails, with the spec's words: section 5, F14.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and sends each email.
 */
class Emails {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register' ) );
	}

	/**
	 * Lists ours in WooCommerce's email settings.
	 *
	 * @param array $emails WooCommerce's emails.
	 * @return array
	 */
	public static function register( $emails ) {
		$emails['boxday_welcome']  = new Mail( 'boxday_welcome', __( 'BoxDay: welcome', 'boxday' ), __( 'The private link, sent when a sign-up is paid or on hold.', 'boxday' ) );
		$emails['boxday_box']      = new Mail( 'boxday_box', __( 'BoxDay: this week\'s box', 'boxday' ), __( 'Sent to members when you publish a box.', 'boxday' ) );
		$emails['boxday_reminder'] = new Mail( 'boxday_reminder', __( 'BoxDay: pickup reminder', 'boxday' ), __( 'The day before pickup at 8:00 am.', 'boxday' ), true );
		$emails['boxday_skip']     = new Mail( 'boxday_skip', __( 'BoxDay: skip confirmation', 'boxday' ), __( 'Sent when a member skips a box.', 'boxday' ), true );
		$emails['boxday_offer']    = new Mail( 'boxday_offer', __( 'BoxDay: waitlist offer', 'boxday' ), __( 'Sent when a place frees up for the next person on the waitlist.', 'boxday' ) );
		$emails['boxday_payment']  = new Mail( 'boxday_payment', __( 'BoxDay: payment due', 'boxday' ), __( 'With the pay link: 7 days before, on the date and 3 days after.', 'boxday' ) );
		/**
		 * Filters the email types BoxDay adds to WooCommerce's email settings.
		 *
		 * @param array $emails WooCommerce's emails, with ours added.
		 */
		return apply_filters( 'boxday_email_types', $emails );
	}

	/**
	 * Sends through one of our emails.
	 *
	 * @param string $id      Email key.
	 * @param string $to      Address.
	 * @param string $subject Subject.
	 * @param string $body    Escaped HTML.
	 */
	private static function send( $id, $to, $subject, $body ) {
		$emails = WC()->mailer()->get_emails();
		if ( isset( $emails[ $id ] ) ) {
			$emails[ $id ]->deliver( $to, $subject, $body );
		}
	}

	/**
	 * A button for an email.
	 *
	 * @param string $url   Link.
	 * @param string $label Label.
	 * @return string
	 */
	private static function button( $url, $label ) {
		return '<p><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 20px;background:#1c1a17;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold">' . esc_html( $label ) . '</a></p>';
	}

	/**
	 * The sentence for a season's skip rule.
	 *
	 * @param array $season Season row.
	 * @return string
	 */
	public static function rule_sentence( $season ) {
		if ( 'donate' === $season['skip_rule'] ) {
			return sprintf( /* translators: %s: donation partner. */ __( 'We\'ll give this box to %s.', 'boxday' ), $season['donation_partner'] );
		}
		if ( 'makeup' === $season['skip_rule'] ) {
			return __( 'You\'ll get an extra box at the end of the season.', 'boxday' );
		}
		return __( 'You won\'t get this box back.', 'boxday' );
	}

	/**
	 * Welcome, with the private link. Goes once per order.
	 *
	 * @param int $member_id Member.
	 * @param int $order_id  Order.
	 */
	public static function welcome( $member_id, $order_id ) {
		$member = Db::get( 'member', $member_id );
		$rows   = Db::rows( 'SELECT * FROM ' . Db::t( 'signup' ) . ' WHERE order_id = %d ORDER BY id', array( $order_id ) );
		if ( ! $member || ! $rows ) {
			return;
		}
		self::welcome_signup( $member, $rows[0] );
	}

	/**
	 * Welcome for one member and sign-up.
	 *
	 * @param array $member Member row.
	 * @param array $signup Sign-up row.
	 */
	public static function welcome_signup( $member, $signup ) {
		$site  = Db::get( 'site', (int) $signup['site_id'] );
		$week  = Db::get( 'week', (int) $signup['first_week_id'] );
		$first = $week ? Calendar::pickup_date( $week['start_date'], $site['weekday'] ) : '';
		$farm  = Format::farm();
		$word  = Format::word();
		$body  = '<p>' . esc_html(
			sprintf(
				/* translators: 1: farm, 2: date, 3: site, 4: time window. */
				__( 'Thanks for joining %1$s. Your first box is %2$s at %3$s, %4$s.', 'boxday' ),
				$farm,
				Format::date( $first ),
				$site['name'],
				Format::window( $site['time_from'], $site['time_to'] )
			)
		) . '</p>';
		$parts = Db::rows(
			'SELECT i.due_date, SUM(i.amount) AS amount FROM ' . Db::t( 'instalment' ) . ' i JOIN ' . Db::t( 'signup' ) . " s ON s.id = i.signup_id WHERE s.order_id = %d AND i.state = 'due' GROUP BY i.due_date ORDER BY i.due_date",
			array( $signup['order_id'] )
		);
		if ( $parts ) {
			$body .= '<ul>';
			foreach ( $parts as $part ) {
				$body .= '<li>' . esc_html( Format::date( $part['due_date'] ) . ': ' . Format::money( (float) $part['amount'] ) ) . '</li>';
			}
			$body .= '</ul>';
		}
		$body .= '<p>' . esc_html__( 'Use your private link to see each box, swap, skip or change your pickup. You don\'t need a password.', 'boxday' ) . '</p>';
		/* translators: %s: word for a share. */
		$body .= self::button( Links::member_url( $member ), sprintf( __( 'See my %s', 'boxday' ), $word ) );
		/* translators: 1: farm, 2: word for a share. */
		self::send( 'boxday_welcome', $member['email'], sprintf( __( 'You\'re in! Your %1$s %2$s', 'boxday' ), $farm, $word ), $body );
	}

	/**
	 * A fresh private link.
	 *
	 * @param array $member Member row.
	 */
	public static function new_link( $member ) {
		$body = '<p>' . esc_html__( 'Use your private link to see each box, swap, skip or change your pickup. You don\'t need a password.', 'boxday' ) . '</p>'
			/* translators: %s: word for a share. */
			. self::button( Links::member_url( $member ), sprintf( __( 'See my %s', 'boxday' ), Format::word() ) );
		/* translators: 1: farm, 2: word for a share. */
		self::send( 'boxday_welcome', $member['email'], sprintf( __( 'Your %1$s %2$s link', 'boxday' ), Format::farm(), Format::word() ), $body );
	}

	/**
	 * This week's box. After the cut-off, the swap and skip lines stay out.
	 *
	 * @param array $member Member row.
	 * @param array $signup Sign-up row.
	 * @param array $week   Week row.
	 */
	public static function box( $member, $signup, $week ) {
		$site   = Signups::week_site( $signup, $week );
		$date   = Calendar::pickup_date( $week['start_date'], $site['weekday'] );
		$season = Seasons::get( (int) $signup['season_id'] );
		$body   = '' !== (string) $week['message'] ? '<p>' . esc_html( $week['message'] ) . '</p>' : '';
		$body  .= '<ul>';
		foreach ( Boxes::box_for( $signup, $week ) as $item ) {
			$body .= '<li>' . esc_html( $item['name'] . ', ' . Boxes::amount( $item ) ) . '</li>';
		}
		$body .= '</ul><p>' . esc_html(
			sprintf(
				/* translators: 1: date, 2: site, 3: time window. */
				__( 'Pickup: %1$s, %2$s, %3$s.', 'boxday' ),
				Format::date( $date ),
				$site['name'],
				Format::window( $site['time_from'], $site['time_to'] )
			)
		) . '</p>';
		if ( Members::open_for( $signup, $week, $site ) ) {
			$cutoff = Calendar::cutoff( $season, $site, $date, wp_timezone() );
			$link   = Links::member_url( $member );
			/* translators: 1: weekday, 2: time. */
			$body .= '<p>' . esc_html( sprintf( __( 'Want to change something? You have until %1$s at %2$s.', 'boxday' ), Format::day_of( $cutoff ), Format::time( $cutoff->format( 'H:i:s' ) ) ) ) . '</p>';
			$body .= self::button( $link, __( 'Swap something', 'boxday' ) ) . self::button( $link, __( 'Skip this box', 'boxday' ) );
		}
		self::send( 'boxday_box', $member['email'], __( 'What\'s in your box this week', 'boxday' ), $body );
	}

	/**
	 * The day-before reminder.
	 *
	 * @param array $member Member row.
	 * @param array $site   Site row.
	 * @param array $week   Week row.
	 */
	public static function reminder( $member, $site, $week ) {
		$date = Calendar::pickup_date( $week['start_date'], $site['weekday'] );
		$day  = Format::date( $date, 'l' );
		/* translators: 1: weekday, 2: site, 3: time window. */
		$body = '<p>' . esc_html( sprintf( __( 'See you %1$s at %2$s, %3$s.', 'boxday' ), $day, $site['name'], Format::window( $site['time_from'], $site['time_to'] ) ) ) . '</p>';
		if ( '' !== (string) $site['note'] ) {
			$body .= '<p>' . esc_html( $site['note'] ) . '</p>';
		}
		/* translators: %s: weekday. */
		self::send( 'boxday_reminder', $member['email'], sprintf( __( 'Your box is ready %s', 'boxday' ), $day ), $body );
	}

	/**
	 * Skip confirmation, with an undo link.
	 *
	 * @param array $member Member row.
	 * @param array $signup Sign-up row.
	 * @param array $week   Week row.
	 * @param array $site   Site row.
	 */
	public static function skip_confirmation( $member, $signup, $week, $site ) {
		$season = Seasons::get( (int) $signup['season_id'] );
		$date   = Calendar::pickup_date( $week['start_date'], $site['weekday'] );
		$cutoff = Calendar::cutoff( $season, $site, $date, wp_timezone() );
		$day    = Format::date( $date );
		$body   = '<p>' . esc_html(
			sprintf(
				/* translators: 1: date, 2: rule sentence, 3: weekday, 4: time. */
				__( 'No box for you on %1$s. %2$s Changed your mind? You can undo this until %3$s at %4$s.', 'boxday' ),
				$day,
				self::rule_sentence( $season ),
				Format::day_of( $cutoff ),
				Format::time( $cutoff->format( 'H:i:s' ) )
			)
		) . '</p>' . self::button( Links::member_url( $member ), __( 'Undo', 'boxday' ) );
		/* translators: %s: date. */
		self::send( 'boxday_skip', $member['email'], sprintf( __( 'You\'re skipping %s', 'boxday' ), $day ), $body );
	}

	/**
	 * Payment due, on the date, or overdue.
	 *
	 * @param \WC_Order $order Later payment order.
	 * @param string    $stage `soon`, `today` or `late`.
	 * @param string    $date  Due date.
	 * @param int       $n     Payment number.
	 * @param int       $count Payments in all.
	 */
	public static function payment( $order, $stage, $date, $n, $count ) {
		$farm   = Format::farm();
		$amount = Format::money( (float) $order->get_total() );
		if ( 'today' === $stage ) {
			/* translators: %s: farm. */
			$subject = sprintf( __( 'Your %s payment is due today', 'boxday' ), $farm );
		} elseif ( 'late' === $stage ) {
			/* translators: %s: farm. */
			$subject = sprintf( __( 'Your %s payment is overdue', 'boxday' ), $farm );
		} else {
			/* translators: 1: farm, 2: amount, 3: date. */
			$subject = sprintf( __( 'Your %1$s payment of %2$s is due %3$s', 'boxday' ), $farm, $amount, Format::date( $date ) );
		}
		/* translators: 1: payment number, 2: payments, 3: word for a share. */
		$body = '<p>' . esc_html( sprintf( __( 'This is payment %1$d of %2$d for your %3$s. No fees, no interest.', 'boxday' ), $n, $count, Format::word() ) ) . '</p>';
		/* translators: %s: amount. */
		$body .= self::button( $order->get_checkout_payment_url(), sprintf( __( 'Pay %s', 'boxday' ), $amount ) );
		if ( 'late' === $stage ) {
			/* translators: %s: farm. */
			$body .= '<p>' . esc_html( sprintf( __( 'Can\'t pay right now? Reply to this email and %s will work it out with you.', 'boxday' ), $farm ) ) . '</p>';
		}
		self::send( 'boxday_payment', $order->get_billing_email(), $subject, $body );
	}

	/**
	 * Waitlist offer, with the private link and a way off the list.
	 *
	 * @param array $row  Waitlist row.
	 * @param int   $ends When the hold ends, as a timestamp.
	 */
	public static function offer( $row, $ends ) {
		$when = wp_date( 'l, F j', $ends ) . ', ' . Format::time( wp_date( 'H:i:s', $ends ) );
		$url  = Waitlist::offer_url( $row );
		/* translators: 1: word for a share, 2: date and time. */
		$body  = '<p>' . esc_html( sprintf( __( 'Good news: there\'s a %1$s for you. We\'re holding it until %2$s.', 'boxday' ), Format::word(), $when ) ) . '</p>';
		$body .= self::button( $url, __( 'Sign up now', 'boxday' ) );
		$body .= '<p>' . esc_html__( 'Changed your mind?', 'boxday' ) . ' <a href="' . esc_url( add_query_arg( 'leave', 1, $url ) ) . '">' . esc_html__( 'Take me off the list', 'boxday' ) . '</a></p>';
		/* translators: %s: farm. */
		self::send( 'boxday_offer', $row['email'], sprintf( __( 'A place opened up at %s', 'boxday' ), Format::farm() ), $body );
	}
}
