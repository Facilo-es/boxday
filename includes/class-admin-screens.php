<?php
/**
 * What each admin screen shows.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Screens read only which season, week or member to show.

/**
 * Renders the screens.
 */
class Admin_Screens {

	/**
	 * Freemius's hosted checkout for BoxDay Pro. A plain link: Free calls nothing outside the site.
	 */
	const PRO_URL = 'https://checkout.freemius.com/plugin/44230/plan/77947/?currency=auto';

	/**
	 * A link to one of our screens.
	 *
	 * @param string $page Slug.
	 * @param array  $args Arguments.
	 * @return string
	 */
	private static function url( $page, $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Today: what needs doing next. The one warm screen in the admin.
	 */
	public static function today() {
		$season = Admin::current_season();
		$farm   = Format::farm();
		echo '<div class="wrap bd-wrap bd-today"><h1>' . esc_html( sprintf( /* translators: %s: farm. */ __( 'Today at %s', 'boxday' ), $farm ) ) . '</h1>';
		if ( ! $season ) {
			echo '<p>' . esc_html__( 'No season yet. Plan your first one and start taking sign-ups.', 'boxday' ) . '</p><p class="bd-acts">';
			echo '<a class="button button-primary button-hero" href="' . esc_url( Admin_Setup::url( (int) Admin_Setup::state()['step'] ? (int) Admin_Setup::state()['step'] : 1 ) ) . '">' . esc_html__( 'Plan a season', 'boxday' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( Admin::action_url( 'sample_load' ) ) . '">' . esc_html__( 'Look around with a sample farm first', 'boxday' ) . '</a></p></div>';
			return;
		}
		$id      = (int) $season['id'];
		$signup  = Db::t( 'signup' );
		$filled  = (int) Db::value( "SELECT COUNT(*) FROM {$signup} s JOIN " . Db::t( 'share' ) . " sh ON sh.id = s.share_id WHERE s.season_id = %d AND s.status = 'active' AND sh.kind = 'main'", array( $id ) );
		$cap     = 0;
		$limited = true;
		foreach ( Seasons::sites( $id ) as $site ) {
			if ( null === $site['capacity'] ) {
				$limited = false;
			}
			$cap += (int) $site['capacity'];
		}
		$soon   = Seasons::next_pickup( $id );
		$week   = $soon ? $soon['week'] : null;
		$next   = $soon ? $soon['date'] : '';
		$plan   = Seasons::next_to_plan( $id );
		$checks = (int) Db::value( "SELECT COUNT(*) FROM {$signup} WHERE season_id = %d AND status = 'active' AND pay_state = 'unpaid'", array( $id ) );
		$over   = (int) Db::value( "SELECT COUNT(*) FROM {$signup} WHERE season_id = %d AND status = 'active' AND pay_state = 'overdue'", array( $id ) );
		$extra  = (int) Db::value( "SELECT COUNT(*) FROM {$signup} WHERE season_id = %d AND status = 'active' AND over_capacity = 1", array( $id ) );
		$cards  = array(
			array( $limited ? $filled . ' / ' . $cap : (string) $filled, $limited ? sprintf( /* translators: 1: filled, 2: capacity. */ __( '%1$d of %2$d places filled', 'boxday' ), $filled, $cap ) : __( 'members', 'boxday' ) ),
			array( $next ? Format::date( $next, 'M j' ) : '–', $next ? sprintf( /* translators: %s: date. */ __( 'Next pickup: %s', 'boxday' ), Format::date( $next ) ) : __( 'No pickups left', 'boxday' ) ),
			array( (string) $checks, sprintf( /* translators: %d: checks. */ __( 'Money waiting: %d checks', 'boxday' ), $checks ) ),
			array( (string) $over, sprintf( /* translators: %d: overdue. */ __( 'Overdue: %d', 'boxday' ), $over ) ),
		);
		echo '<p>' . esc_html( $season['name'] ) . '</p><div class="bd-cards">';
		foreach ( $cards as $card ) {
			echo '<div class="bd-card"><b>' . esc_html( $card[0] ) . '</b><span>' . esc_html( $card[1] ) . '</span></div>';
		}
		echo '</div><ul class="bd-prompts">';
		if ( $plan ) {
			$days = array_map( 'intval', array_column( Seasons::sites( $id ), 'weekday' ) );
			$date = Calendar::pickup_date( $plan['start_date'], $days ? min( $days ) : 1 );
			echo '<li><a href="' . esc_url( self::url( 'boxday-planner', array( 'week' => $plan['id'] ) ) ) . '">' . esc_html( sprintf( /* translators: %s: date. */ __( 'Publish the box for %s', 'boxday' ), Format::date( $date ) ) ) . '</a></li>';
		}
		if ( $week ) {
			echo '<li><a href="' . esc_url( self::url( 'boxday-sheets', array( 'week' => $week['id'] ) ) ) . '">' . esc_html( sprintf( /* translators: %s: date. */ __( 'Print harvest totals for %s', 'boxday' ), Format::date( $next ) ) ) . '</a></li>';
			echo '<li><a href="' . esc_url( self::url( 'boxday-sheets', array( 'week' => $week['id'] ) ) ) . '">' . esc_html__( 'Print pickup sheets', 'boxday' ) . '</a></li>';
		}
		if ( $extra ) {
			echo '<li><a href="' . esc_url( self::url( 'boxday-members' ) ) . '">' . esc_html( sprintf( /* translators: 1: site, 2: boxes. */ __( '%1$s is %2$d over capacity', 'boxday' ), $season['name'], $extra ) ) . '</a></li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Seasons: the list, or one season's form.
	 */
	public static function seasons() {
		if ( isset( $_GET['edit'] ) ) {
			self::season_form();
			return;
		}
		echo '<div class="wrap bd-wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Seasons', 'boxday' ) . '</h1> <a class="page-title-action" href="' . esc_url( self::url( 'boxday-seasons', array( 'edit' => 'new' ) ) ) . '">' . esc_html__( 'Plan a season', 'boxday' ) . '</a>';
		echo '<table class="widefat striped" style="margin-top:12px"><thead><tr><th>' . esc_html__( 'Season', 'boxday' ) . '</th><th>' . esc_html__( 'First pickup week', 'boxday' ) . '</th><th>' . esc_html__( 'Weeks', 'boxday' ) . '</th><th>' . esc_html__( 'State', 'boxday' ) . '</th><th></th></tr></thead><tbody>';
		$names = array(
			'draft'    => __( 'Draft', 'boxday' ),
			'upcoming' => __( 'Open soon', 'boxday' ),
			'open'     => __( 'Open', 'boxday' ),
			'running'  => __( 'Running', 'boxday' ),
			'finished' => __( 'Finished', 'boxday' ),
		);
		foreach ( Seasons::all() as $season ) {
			$state = Seasons::state( $season );
			echo '<tr><td><a href="' . esc_url( self::url( 'boxday-seasons', array( 'edit' => $season['id'] ) ) ) . '"><b>' . esc_html( $season['name'] ) . '</b></a></td><td>' . esc_html( Format::date( $season['first_week'], 'F j, Y' ) ) . '</td><td>' . (int) $season['weeks'] . '</td><td>' . esc_html( $names[ $state ] ) . '</td>';
			echo '<td><a href="' . esc_url( Admin::action_url( 'season_copy', array( 'season' => $season['id'] ) ) ) . '">' . esc_html__( 'Copy season', 'boxday' ) . '</a></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * One labelled field.
	 *
	 * @param string $label Label.
	 * @param string $name  Field name.
	 * @param string $value Value.
	 * @param string $type  Input type.
	 */
	private static function field( $label, $name, $value, $type = 'text' ) {
		$step = 'number' === $type ? ' step="any" min="0"' : '';
		echo '<label>' . esc_html( $label ) . '<input type="' . esc_attr( $type ) . '"' . $step . ' name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"></label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $step is a fixed string.
	}

	/**
	 * A tick field.
	 *
	 * @param string $label Label.
	 * @param string $name  Field name.
	 * @param mixed  $value Value.
	 */
	private static function tick( $label, $name, $value ) {
		echo '<label><span><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( (bool) $value, true, false ) . '> ' . esc_html( $label ) . '</span></label>';
	}

	/**
	 * A select field.
	 *
	 * @param string $label   Label.
	 * @param string $name    Field name.
	 * @param string $value   Value.
	 * @param array  $options Value to label.
	 */
	private static function select( $label, $name, $value, $options ) {
		echo '<label>' . esc_html( $label ) . '<select name="' . esc_attr( $name ) . '">';
		foreach ( $options as $key => $text ) {
			echo '<option value="' . esc_attr( (string) $key ) . '"' . selected( (string) $value, (string) $key, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></label>';
	}

	/**
	 * The season form.
	 */
	private static function season_form() {
		$id  = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$s   = $id ? Seasons::get( $id ) : null;
		$d   = $s ? $s : array(
			'id'               => 0,
			'name'             => '',
			'status'           => 'draft',
			'first_week'       => '',
			'weeks'            => 22,
			'signup_open'      => '',
			'signup_close'     => '',
			'cutoff_days'      => 2,
			'cutoff_time'      => '20:00:00',
			'skip_rule'        => 'skip',
			'donation_partner' => '',
			'max_skips'        => 2,
			'makeup_weeks'     => 0,
			'swaps_on'         => 1,
			'swap_limit'       => 2,
			'site_change'      => 'both',
			'late_join'        => 1,
			'prorate'          => 1,
			'plan_mode'        => 'full',
			'deposit_type'     => 'amount',
			'deposit_value'    => 0,
			'plan_dates'       => '[]',
			'agreement_text'   => '',
		);
		$iso = function ( $value ) {
			return $value ? str_replace( ' ', 'T', substr( $value, 0, 16 ) ) : '';
		};
		echo '<div class="wrap bd-wrap"><h1>' . esc_html( $s ? $s['name'] : __( 'Plan your season', 'boxday' ) ) . '</h1>';
		Admin::form_open( 'season' );
		echo '<input type="hidden" name="id" value="' . (int) $d['id'] . '"><div class="bd-fields">';
		self::field( __( 'Season name', 'boxday' ), 'name', $d['name'] );
		self::field( __( 'First pickup week', 'boxday' ), 'first_week', $d['first_week'], 'date' );
		self::field( __( 'How many weeks?', 'boxday' ), 'weeks', $d['weeks'], 'number' );
		self::field( __( 'Sign-ups open', 'boxday' ), 'signup_open', $iso( $d['signup_open'] ), 'datetime-local' );
		self::field( __( 'Sign-ups close', 'boxday' ), 'signup_close', $iso( $d['signup_close'] ), 'datetime-local' );
		self::field( __( 'Cut-off: days before pickup', 'boxday' ), 'cutoff_days', $d['cutoff_days'], 'number' );
		self::field( __( 'Cut-off: time', 'boxday' ), 'cutoff_time', substr( $d['cutoff_time'], 0, 5 ), 'time' );
		self::select(
			__( 'When a member skips', 'boxday' ),
			'skip_rule',
			$d['skip_rule'],
			array(
				'skip'   => __( 'They miss the box', 'boxday' ),
				'donate' => __( 'The box is donated', 'boxday' ),
				'makeup' => __( 'They get a make-up box', 'boxday' ),
			)
		);
		self::field( __( 'Donation partner', 'boxday' ), 'donation_partner', $d['donation_partner'] );
		self::field( __( 'Most skips a season', 'boxday' ), 'max_skips', $d['max_skips'], 'number' );
		self::field( __( 'Make-up weeks', 'boxday' ), 'makeup_weeks', $d['makeup_weeks'], 'number' );
		self::field( __( 'Swaps per box', 'boxday' ), 'swap_limit', $d['swap_limit'], 'number' );
		self::select(
			__( 'Pickup changes', 'boxday' ),
			'site_change',
			$d['site_change'],
			array(
				'never' => __( 'Never', 'boxday' ),
				'once'  => __( 'One week', 'boxday' ),
				'both'  => __( 'One week and permanent', 'boxday' ),
			)
		);
		self::tick( __( 'Sign-ups are live', 'boxday' ), 'live', 'live' === $d['status'] );
		self::tick( __( 'Members can swap', 'boxday' ), 'swaps_on', $d['swaps_on'] );
		self::tick( __( 'Late joiners welcome', 'boxday' ), 'late_join', $d['late_join'] );
		self::tick( __( 'Late joiners pay only for boxes left', 'boxday' ), 'prorate', $d['prorate'] );
		echo '</div>';

		echo '<h2 class="bd-sec">' . esc_html__( 'How will members pay?', 'boxday' ) . '</h2><div class="bd-fields">';
		self::select(
			__( 'Let members pay in parts?', 'boxday' ),
			'plan_mode',
			$d['plan_mode'],
			array(
				'full'  => __( 'Pay in full', 'boxday' ),
				'parts' => __( 'Pay in parts', 'boxday' ),
			)
		);
		self::select(
			__( 'Deposit is', 'boxday' ),
			'deposit_type',
			$d['deposit_type'],
			array(
				'amount'  => __( 'An amount', 'boxday' ),
				'percent' => __( 'A percent', 'boxday' ),
			)
		);
		self::field( __( 'Deposit', 'boxday' ), 'deposit_value', (float) $d['deposit_value'], 'number' );
		$dates = json_decode( (string) $d['plan_dates'], true );
		for ( $i = 0; $i < Pricing::MAX_LATER_PAYMENTS; $i++ ) {
			self::field( sprintf( /* translators: %d: payment number. */ __( 'Payment %d date', 'boxday' ), $i + 1 ), 'plan_dates[]', isset( $dates[ $i ] ) ? $dates[ $i ] : '', 'date' );
		}
		echo '</div>';

		if ( $id ) {
			echo '<h2 class="bd-sec">' . esc_html__( 'Close a week', 'boxday' ) . '</h2><div class="bd-closed">';
			foreach ( Seasons::weeks( $id ) as $week ) {
				if ( 'makeup' !== $week['state'] ) {
					echo '<label><input type="checkbox" name="closed[]" value="' . (int) $week['number'] . '"' . checked( 'closed', $week['state'], false ) . '> ' . (int) $week['number'] . ' (' . esc_html( Format::date( $week['start_date'], 'M j' ) ) . ')</label>';
				}
			}
			echo '</div>';
		}

		echo '<h2 class="bd-sec">' . esc_html__( 'What are you offering?', 'boxday' ) . '</h2>';
		$shares   = $id ? Db::rows( 'SELECT * FROM ' . Db::t( 'share' ) . ' WHERE season_id = %d ORDER BY sort, id', array( $id ) ) : array();
		$shares[] = array(
			'id'          => 0,
			'name'        => '',
			'description' => '',
			'kind'        => 'main',
			'capacity'    => '',
			'price_mode'  => 'season',
		);
		foreach ( $shares as $i => $share ) {
			$p = 'shares[' . $i . ']';
			echo '<div class="bd-fields" style="margin-bottom:10px"><input type="hidden" name="' . esc_attr( $p ) . '[id]" value="' . (int) $share['id'] . '">';
			self::field( __( 'Share name', 'boxday' ), $p . '[name]', $share['name'] );
			self::select(
				__( 'Kind', 'boxday' ),
				$p . '[kind]',
				$share['kind'],
				array(
					'main'  => __( 'Main', 'boxday' ),
					'addon' => __( 'Add-on', 'boxday' ),
				)
			);
			self::select(
				__( 'Price is', 'boxday' ),
				$p . '[price_mode]',
				$share['price_mode'],
				array(
					'season' => __( 'Per season', 'boxday' ),
					'box'    => __( 'Per box', 'boxday' ),
				)
			);
			self::field( __( 'Capacity (optional)', 'boxday' ), $p . '[capacity]', $share['capacity'], 'number' );
			self::field( __( 'Description', 'boxday' ), $p . '[description]', $share['description'] );
			$sizes   = $share['id'] ? Seasons::sizes( (int) $share['id'] ) : array();
			$sizes[] = array(
				'id'             => 0,
				'name'           => '',
				'note'           => '',
				'price_weekly'   => '',
				'price_biweekly' => '',
			);
			foreach ( $sizes as $n => $size ) {
				$q = $p . '[sizes][' . $n . ']';
				echo '<input type="hidden" name="' . esc_attr( $q ) . '[id]" value="' . (int) $size['id'] . '">';
				self::field( __( 'Size', 'boxday' ), $q . '[name]', $size['name'] );
				self::field( __( 'Note', 'boxday' ), $q . '[note]', $size['note'] );
				self::field( __( 'Weekly price', 'boxday' ), $q . '[weekly]', $size['price_weekly'], 'number' );
				self::field( __( 'Every-other-week price', 'boxday' ), $q . '[biweekly]', $size['price_biweekly'], 'number' );
			}
			echo '</div>';
		}

		echo '<h2 class="bd-sec">' . esc_html__( 'Where do members collect?', 'boxday' ) . '</h2>';
		$sites   = $id ? Seasons::sites( $id ) : array();
		$sites[] = array(
			'id'           => 0,
			'name'         => '',
			'type'         => 'pickup',
			'address'      => '',
			'weekday'      => 2,
			'time_from'    => '16:00:00',
			'time_to'      => '19:00:00',
			'capacity'     => '',
			'note'         => '',
			'zip_prefixes' => '',
			'fee_per_box'  => 0,
		);
		$days    = array();
		for ( $n = 1; $n <= 7; $n++ ) {
			$days[ $n ] = Format::weekday( $n );
		}
		foreach ( $sites as $i => $site ) {
			$p = 'sites[' . $i . ']';
			echo '<div class="bd-fields" style="margin-bottom:10px"><input type="hidden" name="' . esc_attr( $p ) . '[id]" value="' . (int) $site['id'] . '">';
			self::field( __( 'Place name', 'boxday' ), $p . '[name]', $site['name'] );
			self::select(
				__( 'Type', 'boxday' ),
				$p . '[type]',
				$site['type'],
				array(
					'pickup'   => __( 'Pickup', 'boxday' ),
					'delivery' => __( 'Home delivery', 'boxday' ),
				)
			);
			self::field( __( 'Address', 'boxday' ), $p . '[address]', $site['address'] );
			self::select( __( 'Day', 'boxday' ), $p . '[weekday]', $site['weekday'], $days );
			self::field( __( 'From', 'boxday' ), $p . '[time_from]', substr( $site['time_from'], 0, 5 ), 'time' );
			self::field( __( 'To', 'boxday' ), $p . '[time_to]', substr( $site['time_to'], 0, 5 ), 'time' );
			self::field( __( 'How many boxes fit here?', 'boxday' ), $p . '[capacity]', $site['capacity'], 'number' );
			self::field( __( 'Note for members', 'boxday' ), $p . '[note]', $site['note'] );
			self::field( __( 'ZIP prefixes, for delivery', 'boxday' ), $p . '[zip_prefixes]', $site['zip_prefixes'] );
			self::field( __( 'Fee per box', 'boxday' ), $p . '[fee_per_box]', (float) $site['fee_per_box'], 'number' );
			echo '</div>';
		}

		echo '<h2 class="bd-sec">' . esc_html__( 'Member agreement (optional)', 'boxday' ) . '</h2>';
		echo '<p>' . esc_html__( 'This is a starting point written by the BoxDay team, not legal advice. Change it to fit your farm, and have someone qualified check it.', 'boxday' ) . '</p>';
		echo '<p><button type="button" class="button" data-starter="' . esc_attr( self::starter_text( $d ) ) . '" data-confirm="' . esc_attr__( 'Replace your text with our starter text?', 'boxday' ) . '">' . esc_html__( 'Use our starter text', 'boxday' ) . '</button></p>';
		echo '<textarea name="agreement_text" rows="10" class="large-text">' . esc_textarea( (string) $d['agreement_text'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'If you add an agreement, members must tick it to sign up. We save which version they agreed to, and when.', 'boxday' ) . '</p>';
		submit_button( __( 'Save season', 'boxday' ) );
		echo '</form></div>';
	}

	/**
	 * F20: the starter member agreement, filled in from the season.
	 *
	 * @param array $d Season fields.
	 * @return string
	 */
	private static function starter_text( $d ) {
		$look  = Format::look();
		$word  = Format::word();
		$weeks = ! empty( $d['id'] ) ? Seasons::weeks( (int) $d['id'] ) : array();
		$first = $weeks ? Format::date( $weeks[0]['start_date'], 'F j, Y' ) : '';
		$last  = $weeks ? Format::date( gmdate( 'Y-m-d', strtotime( end( $weeks )['start_date'] . ' +6 days' ) ), 'F j, Y' ) : '';
		$parts = array(
			/* translators: %s: word for a share. */
			'<strong>' . sprintf( __( 'Our %s agreement', 'boxday' ), $word ) . '</strong>',
			/* translators: %s: farm. */
			'<strong>' . __( 'We share the season.', 'boxday' ) . '</strong> ' . sprintf( __( 'When you join %s, you share both the good and the hard parts of farming with us. Weather, pests and crop failures can change what\'s in your box and how much you get.', 'boxday' ), $look['farm'] ),
			/* translators: 1: season, 2: first date, 3: last date. */
			'<strong>' . __( 'What you pay for.', 'boxday' ) . '</strong> ' . sprintf( __( 'Your payment covers %1$s, from %2$s to %3$s. Boxes vary from week to week.', 'boxday' ), (string) $d['name'], $first, $last ),
			'<strong>' . __( 'Pickup.', 'boxday' ) . '</strong> ' . __( 'You\'re responsible for collecting your box at your site in the pickup window. Boxes left behind can\'t be replaced.', 'boxday' ) . ' ' . Emails::rule_sentence( $d + array( 'donation_partner' => '' ) ),
			'<strong>' . __( 'Refunds.', 'boxday' ) . '</strong> ' . __( 'Refunds are at the farm\'s discretion. Please talk to us if something goes wrong.', 'boxday' ),
			/* translators: 1: farm email, 2: farm phone. */
			'<strong>' . __( 'Questions.', 'boxday' ) . '</strong> ' . sprintf( __( 'Contact us at %1$s or %2$s.', 'boxday' ), $look['email'], $look['phone'] ),
		);
		return implode( "\n\n", $parts );
	}

	/**
	 * The strip of week circles.
	 *
	 * @param array  $season  Season row.
	 * @param int    $current Current week.
	 * @param string $page    Screen.
	 */
	private static function strip( $season, $current, $page ) {
		$today = Seasons::current_week( (int) $season['id'] );
		$now   = $today ? (int) $today['number'] : 0;
		echo '<div class="bd-strip">';
		foreach ( Seasons::weeks( (int) $season['id'] ) as $week ) {
			if ( 'closed' === $week['state'] ) {
				echo '<span>×</span>';
				continue;
			}
			$class = (int) $week['id'] === $current ? 'cur' : ( (int) $week['number'] < $now ? 'done' : '' );
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( self::url( $page, array( 'week' => $week['id'] ) ) ) . '">' . (int) $week['number'] . '</a>';
		}
		echo '</div>';
	}

	/**
	 * The week a screen works on.
	 *
	 * @param array $season  Season row.
	 * @param bool  $to_plan Default to the next box to plan.
	 * @return array|null
	 */
	private static function pick_week( $season, $to_plan = false ) {
		$id   = isset( $_GET['week'] ) ? absint( $_GET['week'] ) : 0;
		$week = $id ? Db::get( 'week', $id ) : null;
		if ( $week ) {
			return $week;
		}
		$plan = $to_plan ? Seasons::next_to_plan( (int) $season['id'] ) : null;
		return $plan ? $plan : Seasons::current_week( (int) $season['id'] );
	}

	/**
	 * One planner row.
	 *
	 * @param int   $index Row number.
	 * @param array $item  Item row.
	 * @param int   $share Share.
	 * @param int   $size  Size.
	 * @param bool  $swap  On the swap list.
	 */
	private static function plan_row( $index, $item, $share, $size, $swap ) {
		$p = 'items[' . $index . ']';
		echo '<div class="bd-it"><input type="hidden" name="' . esc_attr( $p ) . '[id]" value="' . (int) $item['id'] . '"><input type="hidden" name="' . esc_attr( $p ) . '[share_id]" value="' . (int) $share . '"><input type="hidden" name="' . esc_attr( $p ) . '[size_id]" value="' . (int) $size . '"><input type="hidden" name="' . esc_attr( $p ) . '[role]" value="' . ( $swap ? 'swap' : 'box' ) . '">';
		echo '<input type="text" list="bd-crops" name="' . esc_attr( $p ) . '[name]" value="' . esc_attr( $item['name'] ) . '" aria-label="' . esc_attr__( 'Add an item', 'boxday' ) . '">';
		echo '<input type="number" step="0.25" min="0" name="' . esc_attr( $p ) . '[qty]" value="' . esc_attr( '' === $item['qty'] ? '' : (string) (float) $item['qty'] ) . '" aria-label="' . esc_attr__( 'Quantity', 'boxday' ) . '">';
		echo '<input type="text" name="' . esc_attr( $p ) . '[unit]" value="' . esc_attr( $item['unit'] ) . '" aria-label="' . esc_attr__( 'Unit', 'boxday' ) . '">';
		if ( $swap ) {
			echo '<input type="number" min="0" name="' . esc_attr( $p ) . '[available]" value="' . esc_attr( (string) $item['available'] ) . '" aria-label="' . esc_attr__( 'How many are available? Leave blank for no limit.', 'boxday' ) . '"><span></span>';
		} else {
			echo '<input class="gf" type="text" name="' . esc_attr( $p ) . '[grown_at]" value="' . esc_attr( $item['grown_at'] ) . '" aria-label="' . esc_attr__( 'Grown at (optional)', 'boxday' ) . '">';
			echo '<input type="checkbox" name="' . esc_attr( $p ) . '[swappable]" value="1"' . checked( (bool) $item['swappable'], true, false ) . ' aria-label="' . esc_attr__( 'Can be swapped', 'boxday' ) . '">';
		}
		echo '</div>';
	}

	/**
	 * The box planner.
	 */
	public static function planner() {
		$season = Admin::current_season();
		$week   = $season ? self::pick_week( $season, true ) : null;
		echo '<div class="wrap bd-wrap">';
		if ( ! $week ) {
			echo '<h1>' . esc_html__( 'Plan this week\'s box', 'boxday' ) . '</h1><p>' . esc_html__( 'Nothing planned yet. Start from last week, or add your first item.', 'boxday' ) . '</p></div>';
			return;
		}
		$season = Seasons::get( (int) $week['season_id'] );
		$sites  = Seasons::sites( (int) $season['id'] );
		$days   = array_map( 'intval', array_column( $sites, 'weekday' ) );
		$from   = Calendar::pickup_date( $week['start_date'], $days ? min( $days ) : 1 );
		$to     = Calendar::pickup_date( $week['start_date'], $days ? max( $days ) : 1 );
		$blank  = array(
			'id'        => 0,
			'name'      => '',
			'qty'       => '',
			'unit'      => '',
			'swappable' => 1,
			'available' => '',
			'grown_at'  => '',
		);
		echo '<div class="bd-top"><div><h1>' . esc_html__( 'Plan this week\'s box', 'boxday' ) . '</h1><div>' . esc_html( sprintf( /* translators: 1: week number, 2: first date, 3: last date. */ __( 'Week %1$d, pickups %2$s to %3$s', 'boxday' ), (int) $week['number'], Format::date( $from ), Format::date( $to, 'j' ) ) ) . '</div></div></div>';
		self::strip( $season, (int) $week['id'], 'boxday-planner' );

		if ( ! empty( $_GET['published'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Published. This week\'s email is on its way.', 'boxday' ) . '</p></div>';
		}
		if ( ! empty( $_GET['blocked'] ) ) {
			foreach ( explode( ',', sanitize_text_field( wp_unslash( $_GET['blocked'] ) ) ) as $bit ) {
				$pair = explode( ':', $bit );
				echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( /* translators: %d: members. */ __( '%d members swapped this. Remove anyway?', 'boxday' ), isset( $pair[1] ) ? (int) $pair[1] : 0 ) ) . ' (' . esc_html( $pair[0] ) . ')</p></div>';
			}
		}
		if ( ! empty( $_GET['preview'] ) ) {
			$count = Boxes::recipients( $week );
			echo '<div class="notice notice-info" style="padding:10px 12px"><p><b>' . esc_html( sprintf( /* translators: %d: members. */ __( 'Publish this box? %d members will get this week\'s email.', 'boxday' ), $count ) ) . '</b></p>';
			foreach ( $sites as $site ) {
				$pickup = Calendar::pickup_date( $week['start_date'], $site['weekday'] );
				if ( Clock::now() >= Calendar::cutoff( $season, $site, $pickup, wp_timezone() ) ) {
					echo '<p>' . esc_html( sprintf( /* translators: %s: site. */ __( '%s\'s cut-off has passed, so its members won\'t see swap or skip links.', 'boxday' ), $site['name'] ) ) . '</p>';
				}
			}
			echo '<p><a class="button button-primary" href="' . esc_url( Admin::action_url( 'publish', array( 'week' => $week['id'] ) ) ) . '">' . esc_html__( 'Publish', 'boxday' ) . '</a></p></div>';
		}

		echo '<datalist id="bd-crops">';
		foreach ( Boxes::crops() as $crop ) {
			echo '<option value="' . esc_attr( $crop['name'] ) . '">';
		}
		echo '</datalist>';

		Admin::form_open( 'planner' );
		echo '<input type="hidden" name="week" value="' . (int) $week['id'] . '">';
		if ( ! empty( $_GET['blocked'] ) ) {
			echo '<p><label><input type="checkbox" name="force" value="1"> ' . esc_html__( 'Remove anyway', 'boxday' ) . '</label></p>';
		}
		echo '<div class="bd-acts"><button class="button" name="button" value="copy_last">' . esc_html__( 'Copy last week', 'boxday' ) . '</button><button class="button" name="button" value="save">' . esc_html__( 'Save draft', 'boxday' ) . '</button><button class="button button-primary" name="button" value="preview">' . esc_html__( 'Preview and publish', 'boxday' ) . '</button></div>';
		$items = Boxes::items( (int) $week['id'] );
		$swaps = Boxes::items( (int) $week['id'], 'swap' );
		$index = 0;
		if ( ! $items ) {
			echo '<p>' . esc_html__( 'Nothing planned yet. Start from last week, or add your first item.', 'boxday' ) . '</p>';
		}
		echo '<div class="bd-grid">';
		foreach ( Seasons::shares( (int) $season['id'] ) as $share ) {
			$sizes = Seasons::sizes( (int) $share['id'] );
			foreach ( $sizes as $size ) {
				$title = $size['name'] === $share['name'] ? $share['name'] : $share['name'] . ', ' . $size['name'];
				echo '<section class="bd-pb"><h2>' . esc_html( $title ) . '</h2><div class="bd-it hd"><span>' . esc_html__( 'Add an item', 'boxday' ) . '</span><span>' . esc_html__( 'Quantity', 'boxday' ) . '</span><span>' . esc_html__( 'Unit', 'boxday' ) . '</span><span class="gf">' . esc_html__( 'Grown at (optional)', 'boxday' ) . '</span><span>' . esc_html__( 'Can be swapped', 'boxday' ) . '</span></div><div data-rows>';
				foreach ( $items as $item ) {
					if ( (int) $item['size_id'] === (int) $size['id'] ) {
						self::plan_row( ++$index, $item, (int) $share['id'], (int) $size['id'], false );
					}
				}
				self::plan_row( ++$index, $blank, (int) $share['id'], (int) $size['id'], false );
				echo '</div><button class="button bd-add" data-add-row>' . esc_html__( 'Add an item', 'boxday' ) . '</button>';
				if ( count( $sizes ) > 1 ) {
					echo ' <button class="button bd-add" name="button" value="copy_size_' . (int) $size['id'] . '">' . esc_html__( 'Copy to other sizes', 'boxday' ) . '</button>';
				}
				echo '</section>';
			}
		}
		foreach ( Seasons::shares( (int) $season['id'], 'main' ) as $share ) {
			echo '<section class="bd-pb"><h2>' . esc_html__( 'Swap list', 'boxday' ) . ': ' . esc_html( $share['name'] ) . '</h2><p>' . esc_html__( 'Extra items members can choose instead.', 'boxday' ) . ' ' . esc_html__( 'How many are available? Leave blank for no limit.', 'boxday' ) . '</p><div data-rows>';
			foreach ( $swaps as $item ) {
				if ( (int) $item['share_id'] === (int) $share['id'] ) {
					self::plan_row( ++$index, $item, (int) $share['id'], 0, true );
				}
			}
			self::plan_row( ++$index, $blank, (int) $share['id'], 0, true );
			echo '</div><button class="button bd-add" data-add-row>' . esc_html__( 'Add an item', 'boxday' ) . '</button></section>';
		}
		echo '<section class="bd-pb"><h2>' . esc_html__( 'Message to members (optional)', 'boxday' ) . '</h2><textarea name="message" aria-label="' . esc_attr__( 'Message to members (optional)', 'boxday' ) . '">' . esc_textarea( (string) $week['message'] ) . '</textarea></section></div></form></div>';
	}

	/**
	 * The members list, or one member.
	 */
	public static function members() {
		if ( ! empty( $_GET['member'] ) ) {
			self::member_detail( absint( $_GET['member'] ) );
			return;
		}
		$season = Admin::current_season();
		if ( $season && Admin_Roster::view( $season ) ) {
			return;
		}
		echo '<div class="wrap bd-wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Members', 'boxday' ) . '</h1>';
		if ( ! $season ) {
			echo '</div>';
			return;
		}
		echo ' <a class="page-title-action" href="' . esc_url( Admin_Print::url( 'members_csv', array( 'season' => $season['id'] ) ) ) . '">' . esc_html__( 'Export CSV', 'boxday' ) . '</a>';
		Admin_Roster::tabs( '' );
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$pay    = isset( $_GET['pay'] ) ? sanitize_key( $_GET['pay'] ) : '';
		echo '<form method="get" class="bd-acts"><input type="hidden" name="page" value="boxday-members"><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Find a name', 'boxday' ) . '" aria-label="' . esc_attr__( 'Find a name', 'boxday' ) . '"><select name="pay" aria-label="' . esc_attr__( 'All payments', 'boxday' ) . '"><option value="">' . esc_html__( 'All payments', 'boxday' ) . '</option>';
		$states = array(
			'paid'      => __( 'Paid', 'boxday' ),
			'plan'      => __( 'Paying in parts', 'boxday' ),
			'unpaid'    => __( 'Check waiting', 'boxday' ),
			'overdue'   => __( 'Overdue', 'boxday' ),
			'elsewhere' => __( 'Paid elsewhere', 'boxday' ),
		);
		foreach ( $states as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $pay, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><button class="button">' . esc_html__( 'Filter', 'boxday' ) . '</button></form>';
		echo '<table class="widefat striped bd-members"><thead><tr>';
		foreach ( array( __( 'Name', 'boxday' ), __( 'Share', 'boxday' ), __( 'Size', 'boxday' ), __( 'Site', 'boxday' ), __( 'Schedule', 'boxday' ), __( 'Group', 'boxday' ), __( 'Payments', 'boxday' ) ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( self::member_rows( (int) $season['id'], $search, $pay ) as $row ) {
			echo '<tr><td><a href="' . esc_url( self::url( 'boxday-members', array( 'member' => $row['member_id'] ) ) ) . '"><b>' . esc_html( trim( $row['last_name'] . ', ' . $row['first_name'], ', ' ) ) . '</b></a>' . ( $row['over_capacity'] ? ' <span class="bd-bad">' . esc_html__( 'over capacity', 'boxday' ) . '</span>' : '' ) . '</td><td>' . esc_html( $row['share'] ) . '</td><td>' . esc_html( $row['size'] ) . '</td><td>' . esc_html( $row['site'] ) . '</td><td>' . esc_html( 'b' === $row['schedule'] ? __( 'Every other week', 'boxday' ) : __( 'Every week', 'boxday' ) ) . '</td><td>' . esc_html( $row['grp'] ) . '</td><td>' . esc_html( isset( $states[ $row['pay_state'] ] ) ? $states[ $row['pay_state'] ] : $row['pay_state'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Sign-ups with names for the list and the CSV.
	 *
	 * @param int    $season_id Season.
	 * @param string $search    Name search.
	 * @param string $pay       Payment state.
	 * @return array
	 */
	public static function member_rows( $season_id, $search = '', $pay = '' ) {
		$like = '%' . Db::like( $search ) . '%';
		return Db::rows(
			'SELECT s.*, m.first_name, m.last_name, m.email, m.phone, sh.name AS share, z.name AS size, si.name AS site
			FROM ' . Db::t( 'signup' ) . ' s JOIN ' . Db::t( 'member' ) . ' m ON m.id = s.member_id JOIN ' . Db::t( 'share' ) . ' sh ON sh.id = s.share_id
			JOIN ' . Db::t( 'size' ) . ' z ON z.id = s.size_id JOIN ' . Db::t( 'site' ) . " si ON si.id = s.site_id
			WHERE s.season_id = %d AND s.status <> 'cancelled' AND ( %s = '' OR s.pay_state = %s ) AND ( %s = '' OR CONCAT(m.first_name,' ',m.last_name) LIKE %s )
			ORDER BY m.last_name, m.first_name",
			array( $season_id, $pay, $pay, $search, $like )
		);
	}

	/**
	 * One member: details, history and buttons.
	 *
	 * @param int $id Member.
	 */
	private static function member_detail( $id ) {
		$member = Db::get( 'member', $id );
		if ( ! $member ) {
			return;
		}
		echo '<div class="wrap bd-wrap"><p><a href="' . esc_url( self::url( 'boxday-members' ) ) . '">← ' . esc_html__( 'All members', 'boxday' ) . '</a></p><h1>' . esc_html( trim( $member['first_name'] . ' ' . $member['last_name'] ) ) . '</h1>';
		Admin_Roster::notices();
		if ( ! empty( $_GET['sent'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'If that email belongs to a member, a new link is on its way.', 'boxday' ) . '</p></div>';
		}
		$contact = array_filter( array( $member['email'], $member['phone'], trim( $member['street'] . ' ' . $member['zip'] ) ) );
		echo '<p class="bd-contact">' . esc_html( implode( ' · ', $contact ) ) . '</p>';
		if ( '' !== trim( (string) $member['notes'] ) ) {
			echo '<p class="bd-notes"><b>' . esc_html__( 'Notes', 'boxday' ) . ':</b> ' . esc_html( $member['notes'] ) . '</p>';
		}
		echo '<p class="bd-acts"><a class="button" href="' . esc_url( Admin::action_url( 'member_link', array( 'member' => $id ) ) ) . '">' . esc_html__( 'Send link', 'boxday' ) . '</a></p>';
		foreach ( Db::rows( 'SELECT * FROM ' . Db::t( 'signup' ) . ' WHERE member_id = %d', array( $id ) ) as $signup ) {
			$share = Db::get( 'share', (int) $signup['share_id'] );
			$size  = Db::get( 'size', (int) $signup['size_id'] );
			$site  = Db::get( 'site', (int) $signup['site_id'] );
			echo '<h2 class="bd-sec">' . esc_html( $share['name'] . ', ' . $size['name'] . ', ' . $site['name'] ) . '</h2>';
			$pays  = array(
				'paid'      => __( 'Paid', 'boxday' ),
				'plan'      => __( 'Paying in parts', 'boxday' ),
				'unpaid'    => __( 'Check waiting', 'boxday' ),
				'overdue'   => __( 'Overdue', 'boxday' ),
				'elsewhere' => __( 'Paid elsewhere', 'boxday' ),
			);
			$facts = array(
				__( 'How often?', 'boxday' )   => ( 'b' === $signup['schedule'] ? __( 'Every other week', 'boxday' ) . ', ' . $signup['grp'] : __( 'Every week', 'boxday' ) ),
				__( 'Payments', 'boxday' )     => 'cancelled' === $signup['status'] ? __( 'Cancelled', 'boxday' ) : ( isset( $pays[ $signup['pay_state'] ] ) ? $pays[ $signup['pay_state'] ] : $signup['pay_state'] ),
				__( 'Season price', 'boxday' ) => (float) $signup['price_total'] > 0 ? Format::money( (float) $signup['price_total'] ) : '–',
				__( 'Boxes', 'boxday' )        => (string) (int) $signup['boxes_paid'],
			);
			echo '<dl class="bd-facts">';
			foreach ( $facts as $label => $value ) {
				echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
			}
			echo '</dl>';
			if ( 'active' === $signup['status'] ) {
				echo '<div class="bd-acts">';
				Admin_Roster::signup_tools( $signup, $id );
				echo '</div>';
				echo '<p><a class="button" href="' . esc_url(
					Admin::action_url(
						'member_cancel',
						array(
							'signup' => $signup['id'],
							'member' => $id,
						)
					)
				) . '">' . esc_html__( 'Cancel sign-up', 'boxday' ) . '</a></p>';
			}
			if ( $signup['order_id'] ) {
				$order = wc_get_order( (int) $signup['order_id'] );
				if ( $order ) {
					echo '<p><a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html( sprintf( /* translators: %s: order number. */ __( 'Order %s', 'boxday' ), $order->get_order_number() ) ) . '</a></p>';
				}
			}
			echo '<ul>';
			foreach ( Db::rows( 'SELECT * FROM ' . Db::t( 'log' ) . ' WHERE signup_id = %d ORDER BY id DESC LIMIT 50', array( $signup['id'] ) ) as $log ) {
				echo '<li>' . esc_html( $log['created_at'] . ': ' . $log['action'] . ' ' . $log['detail'] ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/**
	 * Sheets: harvest totals and pickup sheets for a week.
	 */
	public static function sheets() {
		$season = Admin::current_season();
		$week   = $season ? self::pick_week( $season ) : null;
		echo '<div class="wrap bd-wrap"><h1>' . esc_html__( 'Sheets', 'boxday' ) . '</h1>';
		if ( ! $week ) {
			echo '</div>';
			return;
		}
		$season = Seasons::get( (int) $week['season_id'] );
		self::strip( $season, (int) $week['id'], 'boxday-sheets' );
		$totals = Totals::week( $week );
		$sites  = Seasons::sites( (int) $season['id'] );
		$cuts   = array();
		foreach ( $sites as $site ) {
			$cuts[] = Calendar::cutoff( $season, $site, Calendar::pickup_date( $week['start_date'], $site['weekday'] ), wp_timezone() );
		}
		if ( $cuts && Clock::now() < max( $cuts ) ) {
			$last = max( $cuts );
			echo '<p>' . esc_html( sprintf( /* translators: %s: date and time. */ __( 'Final after %s.', 'boxday' ), Format::date( $last->format( 'Y-m-d' ), 'l, F j' ) . ', ' . Format::time( $last->format( 'H:i:s' ) ) ) ) . '</p>';
		}
		echo '<div class="bd-grid"><section class="bd-pb"><h2>' . esc_html__( 'Harvest totals', 'boxday' ) . '</h2><table class="widefat"><tbody>';
		foreach ( $totals['crops'] as $crop ) {
			echo '<tr><td>' . esc_html( $crop['name'] . ( $crop['unit'] ? ', ' . $crop['unit'] : '' ) ) . '</td><td><b>' . esc_html( (string) (float) $crop['after'] ) . '</b></td></tr>';
		}
		echo '</tbody></table><p class="bd-acts"><a class="button" target="_blank" href="' . esc_url( Admin_Print::url( 'totals', array( 'week' => $week['id'] ) ) ) . '">' . esc_html__( 'Print', 'boxday' ) . '</a><a class="button" href="' . esc_url( Admin_Print::url( 'totals_csv', array( 'week' => $week['id'] ) ) ) . '">CSV</a></p></section>';
		echo '<section class="bd-pb"><h2>' . esc_html__( 'Boxes to pack', 'boxday' ) . '</h2><table class="widefat"><tbody>';
		foreach ( $totals['packing'] as $row ) {
			echo '<tr><td>' . esc_html( $row['site'] . ', ' . $row['share'] . ', ' . $row['size'] ) . '</td><td><b>' . (int) $row['boxes'] . '</b>' . ( $row['donated'] ? ' (' . esc_html( sprintf( /* translators: %d: boxes. */ __( '%d donated', 'boxday' ), $row['donated'] ) ) . ')' : '' ) . '</td></tr>';
		}
		echo '</tbody></table></section>';
		echo '<section class="bd-pb" style="grid-column:1/-1"><h2>' . esc_html__( 'Pickup sheets', 'boxday' ) . '</h2><table class="widefat"><tbody>';
		foreach ( $sites as $site ) {
			$pickup = Calendar::pickup_date( $week['start_date'], $site['weekday'] );
			$link   = Links::helper_url( (int) $site['id'], (int) $week['id'] );
			echo '<tr><td>' . esc_html( $site['name'] . ', ' . Format::date( $pickup ) ) . '</td><td><a class="button" target="_blank" href="' . esc_url(
				Admin_Print::url(
					'sheet',
					array(
						'week' => $week['id'],
						'site' => $site['id'],
					)
				)
			) . '">' . esc_html__( 'Print', 'boxday' ) . '</a> <a class="button" href="' . esc_url( $link ) . '" data-copy="' . esc_attr( $link ) . '" target="_blank">' . esc_html__( 'Share with a helper', 'boxday' ) . '</a></td></tr>';
		}
		echo '</tbody></table><p>' . esc_html__( 'Anyone with this link can tick off names for this pickup. It shows names and boxes only.', 'boxday' ) . '</p></section></div>';
		/**
		 * Fires at the end of the Sheets screen, for more print-outs.
		 *
		 * @param array $week   Week row.
		 * @param array $season Season row.
		 */
		do_action( 'boxday_sheets_screen', $week, $season );
		echo '</div>';
	}

	/**
	 * Settings: the farm, its look, the sample farm and the self-test.
	 */
	public static function settings() {
		$look = Format::look();
		echo '<div class="wrap bd-wrap"><h1>' . esc_html__( 'Settings', 'boxday' ) . '</h1>';
		if ( ! empty( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Saved.', 'boxday' ) . '</p></div>';
		}
		Admin::form_open( 'settings' );
		echo '<div class="bd-fields">';
		self::field( __( 'Farm name', 'boxday' ), 'farm', $look['farm'] );
		self::field( __( 'Farm email', 'boxday' ), 'email', $look['email'], 'email' );
		self::field( __( 'Farm phone', 'boxday' ), 'phone', $look['phone'] );
		self::select(
			__( 'What do you call what members get?', 'boxday' ),
			'word',
			$look['word'],
			array(
				'share'  => __( 'Share', 'boxday' ),
				'box'    => __( 'Box', 'boxday' ),
				'basket' => __( 'Basket', 'boxday' ),
			)
		);
		self::field( __( 'Accent color', 'boxday' ), 'accent', $look['accent'], 'color' );
		self::field( __( 'Corners', 'boxday' ), 'radius', $look['radius'], 'number' );
		self::tick( __( 'Capital letters on buttons', 'boxday' ), 'caps', $look['caps'] );
		echo '</div>';
		submit_button( __( 'Save', 'boxday' ) );
		echo '</form>';

		echo '<h2 class="bd-sec">' . esc_html__( 'Waitlist, posts and your data', 'boxday' ) . '</h2>';
		Admin::form_open( 'more_settings' );
		echo '<div class="bd-fields">';
		self::tick( __( 'Offer freed places to the waitlist by themselves', 'boxday' ), 'waitlist_auto', Waitlist::auto() );
		self::field( __( 'Hours an offer holds a place', 'boxday' ), 'offer_hours', Waitlist::hours(), 'number' );
		self::tick( __( 'Also publish a blog post for each box', 'boxday' ), 'week_posts', 'yes' === get_option( 'boxday_week_posts', 'no' ) );
		self::tick( __( 'Delete all BoxDay data on uninstall', 'boxday' ), 'delete_data', 'yes' === get_option( 'boxday_delete_data', 'no' ) );
		echo '</div><p class="description">' . esc_html__( 'Orders always stay in WooCommerce, even if you delete BoxDay\'s data.', 'boxday' ) . '</p>';
		submit_button( __( 'Save', 'boxday' ) );
		echo '</form>';

		echo '<h2 class="bd-sec">' . esc_html__( 'Self-test', 'boxday' ) . '</h2>';
		if ( ! empty( $_GET['tested'] ) ) {
			/* translators: %s: email address. */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( 'Test email sent to %s.', 'boxday' ), $look['email'] ) ) . '</p></div>';
		}
		Admin_Setup::self_test();
		echo '<p class="bd-acts"><a class="button" href="' . esc_url( Admin_Setup::url( 1 ) ) . '">' . esc_html__( 'Run setup again', 'boxday' ) . '</a>';
		if ( Sample::loaded() ) {
			echo '<a class="button" href="' . esc_url( Admin::action_url( 'sample_remove' ) ) . '">' . esc_html__( 'Remove the sample farm', 'boxday' ) . '</a>';
		} else {
			echo '<a class="button" href="' . esc_url( Admin::action_url( 'sample_load' ) ) . '">' . esc_html__( 'Look around with a sample farm first', 'boxday' ) . '</a>';
		}
		echo '</p></div>';
	}

	/**
	 * The Pro tab, the only place in Free that mentions Pro.
	 */
	public static function pro() {
		$points = array(
			array( __( 'Let members build their box.', 'boxday' ), __( 'Happier members stay for next year.', 'boxday' ) ),
			array( __( 'Sell extras with the box.', 'boxday' ), __( 'Eggs, bread and honey, added to their next pickup.', 'boxday' ) ),
			array( __( 'Fill next season early.', 'boxday' ), __( 'Renewal links and early-bird prices.', 'boxday' ) ),
			array( __( 'Print labels and route sheets.', 'boxday' ), __( 'Less time packing and driving.', 'boxday' ) ),
			array( __( 'See your money.', 'boxday' ), __( 'What\'s collected, what\'s owed, who came back.', 'boxday' ) ),
		);
		echo '<div class="wrap bd-wrap"><div class="bd-pro"><h2>' . esc_html__( 'Earn more from every member, and save your Mondays', 'boxday' ) . '</h2><p>' . esc_html__( 'BoxDay runs your whole season for free. Pro helps you sell more and pack faster.', 'boxday' ) . '</p><ul>';
		foreach ( $points as $point ) {
			echo '<li><b>' . esc_html( $point[0] ) . '</b> ' . esc_html( $point[1] ) . '</li>';
		}
		echo '</ul><p><b>' . esc_html__( 'USD 79 or EUR 75 a year, for one farm site. Unlimited members. No share of your sales. 14-day money-back promise.', 'boxday' ) . '</b></p><p>' . esc_html__( 'If you stop paying, everything keeps working. You just stop getting updates and support.', 'boxday' ) . '</p>';
		echo '<p><a class="button button-primary button-hero" href="' . esc_url( self::PRO_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Get BoxDay Pro', 'boxday' ) . '</a></p></div></div>';
	}
}
