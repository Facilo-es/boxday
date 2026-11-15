<?php
/**
 * The member page, the helper page and the public box.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * Renders what members and helpers see. Each method returns escaped HTML.
 */
class Member_Page {

	/**
	 * The next box of a sign-up: the first due week whose pickup hasn't passed.
	 *
	 * @param array $signup Sign-up row.
	 * @return array|null week, site, date.
	 */
	public static function next_box( $signup ) {
		$today = Clock::today();
		$skips = Signups::skips_used( (int) $signup['id'] );
		foreach ( Seasons::weeks( (int) $signup['season_id'] ) as $week ) {
			if ( ! Signups::due( $signup, $week, $skips ) ) {
				continue;
			}
			$site = Signups::week_site( $signup, $week );
			$date = Calendar::pickup_date( $week['start_date'], $site['weekday'] );
			if ( $date >= $today ) {
				return array(
					'week' => $week,
					'site' => $site,
					'date' => $date,
				);
			}
		}
		return null;
	}

	/**
	 * The member page.
	 *
	 * @param array $member Member row.
	 * @return string
	 */
	public static function html( $member ) {
		$look  = Format::look();
		$cards = array();
		foreach ( Signups::of_member( (int) $member['id'] ) as $signup ) {
			$next    = self::next_box( $signup );
			$key     = $next ? $next['date'] : '9999';
			$cards[] = array( $key . '-' . $signup['id'], $signup, $next );
		}
		usort(
			$cards,
			function ( $a, $b ) {
				return strcmp( $a[0], $b[0] );
			}
		);
		$html = '<div class="wrap"><section class="hi"><h1>' . esc_html( sprintf( /* translators: %s: first name. */ __( 'Hi %s', 'boxday' ), $member['first_name'] ) ) . '</h1></section>';
		foreach ( $cards as $card ) {
			$html .= self::card( $card[1], $card[2] );
		}
		/**
		 * Filters more sections on the member page, after the boxes. Escaped HTML.
		 *
		 * @param string $sections HTML.
		 * @param array  $member   Member row.
		 */
		$html .= (string) apply_filters( 'boxday_member_sections', '', $member );
		$html .= self::payments( $member );
		$html .= '<footer class="mfoot"><p>' . esc_html( sprintf( /* translators: %s: farm email. */ __( 'Questions? %s', 'boxday' ), $look['email'] ) ) . '</p>'
			. '<p><button type="button" class="link" data-act="newlink">' . esc_html__( 'Get a new link', 'boxday' ) . '</button></p>'
			. '<p class="help">' . esc_html__( 'Use this if someone else has your link. The old one stops working.', 'boxday' ) . '</p></footer></div>';
		return $html;
	}

	/**
	 * One sign-up: the next box, the buttons, the sheets and the season.
	 *
	 * @param array      $signup Sign-up row.
	 * @param array|null $next   Next box.
	 * @return string
	 */
	private static function card( $signup, $next ) {
		$share = Db::get( 'share', (int) $signup['share_id'] );
		if ( ! $next ) {
			return '<p class="msg">' . esc_html( $share['name'] ) . '</p>';
		}
		$week   = $next['week'];
		$site   = $next['site'];
		$sid    = (int) $signup['id'];
		$wid    = (int) $week['id'];
		$season = Seasons::get( (int) $signup['season_id'] );
		$open   = Members::open_for( $signup, $week, $site );
		$row    = Signups::week_row( $sid, $wid );
		$status = $row ? (string) $row['skipped'] : '';
		$cutoff = Calendar::cutoff( $season, $site, $next['date'], wp_timezone() );
		$items  = $week['published_at'] ? Boxes::box_for( $signup, $week ) : array();

		$html  = '<section class="nx"><p class="nb">' . esc_html__( 'Your next box', 'boxday' ) . ( 'addon' === $share['kind'] ? ': ' . esc_html( $share['name'] ) : '' ) . '</p>';
		$html .= '<p class="when">' . esc_html( Format::date( $next['date'], 'l, F j' ) ) . '</p>';
		$html .= '<p class="where">' . esc_html( $site['name'] . ', ' . Format::window( $site['time_from'], $site['time_to'] ) ) . '</p>';
		if ( '' !== $status ) {
			$html .= '<p class="state">' . esc_html( 'donate' === $status ? __( 'Donated', 'boxday' ) : __( 'Skipped', 'boxday' ) ) . '</p>';
		} elseif ( $items ) {
			$html .= '<ul>';
			foreach ( $items as $item ) {
				$html .= '<li>' . Signup_Page::picture( array(), $item['name'] ) . '<span><b>' . esc_html( $item['name'] ) . '</b>' . esc_html( Boxes::amount( $item ) ) . '</span></li>';
			}
			$html .= '</ul>';
		} else {
			$html .= '<p class="tiny">' . esc_html__( 'We\'ll post what\'s in your box soon.', 'boxday' ) . '</p>';
		}
		if ( $open ) {
			/* translators: 1: weekday, 2: time. */
			$html .= '<p class="tiny">' . esc_html( sprintf( __( 'Change anything until %1$s at %2$s.', 'boxday' ), Format::day_of( $cutoff ), Format::time( $cutoff->format( 'H:i:s' ) ) ) ) . '</p>';
		} else {
			/* translators: %s: weekday. */
			$html .= '<p class="tiny">' . esc_html( sprintf( __( 'This box is locked in. See you %s!', 'boxday' ), Format::date( $next['date'], 'l' ) ) ) . '</p>';
		}
		$html .= '</section>';

		$sheets = '';
		$html  .= '<div class="acts">';
		if ( $open && '' === $status ) {
			$swaps = $season['swaps_on'] && 'main' === $share['kind'] && $week['published_at'] && self::swappable( $signup, $week );
			/**
			 * Filters whether a member sees Swap something for this box.
			 *
			 * @param bool  $swaps  Whether swaps show.
			 * @param array $signup Sign-up row.
			 * @param array $week   Week row.
			 */
			$swaps = (bool) apply_filters( 'boxday_member_swaps_on', $swaps, $signup, $week );
			if ( $swaps ) {
				$html   .= self::open_button( 'swap-' . $sid, __( 'Swap something', 'boxday' ), 'act main' );
				$sheets .= self::swap_sheets( $signup, $week, $items, $season );
			}
			$html   .= self::open_button( 'skip-' . $sid, __( 'Skip this box', 'boxday' ), $swaps ? 'act' : 'act main' );
			$sheets .= self::skip_sheet( $signup, $week, $next, $season );
			if ( 'never' !== $season['site_change'] && 'pickup' === $site['type'] ) {
				$html   .= self::open_button( 'site-' . $sid, __( 'Change pickup', 'boxday' ), 'act' );
				$sheets .= self::site_sheet( $signup, $week, $site, $season );
			}
			$html   .= self::open_button( 'away-' . $sid, __( 'I\'ll be away', 'boxday' ), 'act' );
			$sheets .= self::away_sheet( $signup );
			/**
			 * Filters more buttons and sheets for a box that can still change. Buttons open sheets with data-open.
			 *
			 * @param array $extra  buttons and sheets, as escaped HTML.
			 * @param array $signup Sign-up row.
			 * @param array $next   week, site and date of the next box.
			 * @param array $items  The member's box.
			 */
			$extra   = (array) apply_filters(
				'boxday_member_card_actions',
				array(
					'buttons' => '',
					'sheets'  => '',
				),
				$signup,
				$next,
				$items
			);
			$html   .= isset( $extra['buttons'] ) ? $extra['buttons'] : '';
			$sheets .= isset( $extra['sheets'] ) ? $extra['sheets'] : '';
		} elseif ( $open && '' !== $status ) {
			$html .= '<button type="button" class="act main" data-act="unskip" data-signup="' . $sid . '" data-week="' . $wid . '">' . esc_html__( 'Undo', 'boxday' ) . '</button>';
		}
		$html .= '<button type="button" class="act" data-act="season">' . esc_html__( 'See my season', 'boxday' ) . '</button></div>';
		$html .= '<section class="cal m" style="margin-top:12px">' . self::calendar( $signup, $wid ) . '</section>';
		return $html . $sheets;
	}

	/**
	 * Whether a week has anything to swap.
	 *
	 * @param array $signup Sign-up row.
	 * @param array $week   Week row.
	 * @return bool
	 */
	private static function swappable( $signup, $week ) {
		foreach ( Boxes::items( (int) $week['id'], 'swap' ) as $item ) {
			if ( (int) $item['share_id'] === (int) $signup['share_id'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A button that opens a sheet.
	 *
	 * @param string $sheet Sheet id.
	 * @param string $text  Label.
	 * @param string $css   CSS class.
	 * @return string
	 */
	private static function open_button( $sheet, $text, $css ) {
		return '<button type="button" class="' . esc_attr( $css ) . '" data-open="' . esc_attr( $sheet ) . '">' . esc_html( $text ) . '</button>';
	}

	/**
	 * A sheet's frame.
	 *
	 * @param string $id    Sheet id.
	 * @param string $title Title.
	 * @param string $body  Escaped body.
	 * @return string
	 */
	private static function sheet( $id, $title, $body ) {
		return '<div class="scrim" hidden data-sheet="' . esc_attr( $id ) . '"><div class="sheet" role="dialog" aria-label="' . esc_attr( $title ) . '"><h3>' . esc_html( $title ) . '</h3>' . $body . '</div></div>';
	}

	/**
	 * The swap sheets: first the item to give up, then what to get.
	 *
	 * @param array $signup Sign-up row.
	 * @param array $week   Week row.
	 * @param array $items  Box items.
	 * @param array $season Season row.
	 * @return string
	 */
	private static function swap_sheets( $signup, $week, $items, $season ) {
		$sid   = (int) $signup['id'];
		$wid   = (int) $week['id'];
		$used  = Boxes::swaps( $sid, $wid );
		$limit = (int) $season['swap_limit'];
		$first = '';
		$more  = '';
		foreach ( $items as $item ) {
			if ( ! empty( $item['swap_id'] ) ) {
				$first .= '<button type="button" class="btn ghost" data-act="unswap" data-swap="' . (int) $item['swap_id'] . '">' . esc_html( sprintf( /* translators: 1: item, 2: item. */ __( 'Undo: %1$s for %2$s', 'boxday' ), $item['name'], $item['swapped_from'] ) ) . '</button>';
				continue;
			}
			if ( ! $item['swappable'] || count( $used ) >= $limit ) {
				continue;
			}
			$sheet  = 'swap-' . $sid . '-' . (int) $item['id'];
			$first .= '<button type="button" class="btn ghost" data-open="' . esc_attr( $sheet ) . '">' . esc_html( $item['name'] ) . '</button>';
			$opts   = '';
			foreach ( Boxes::items( $wid, 'swap' ) as $i => $in ) {
				if ( (int) $in['share_id'] !== (int) $signup['share_id'] ) {
					continue;
				}
				$gone  = null !== $in['available'] && (int) $in['taken'] >= (int) $in['available'];
				$opts .= '<label class="swap-opt"><input type="radio" name="in-' . (int) $item['id'] . '" value="' . (int) $in['id'] . '"' . ( $gone ? ' disabled' : '' ) . '>' . Signup_Page::picture( array(), $in['name'] )
					. '<span><b>' . esc_html( $in['name'] ) . '</b><small>' . esc_html( Boxes::amount( $in ) ) . '</small></span><i class="tick"></i></label>';
			}
			$more .= self::sheet(
				$sheet,
				/* translators: %s: item. */
				sprintf( __( 'Swap %s for...', 'boxday' ), $item['name'] ),
				'<div class="swap-opts">' . $opts . '</div><button type="button" class="btn" data-act="swap" data-signup="' . $sid . '" data-week="' . $wid . '" data-out="' . (int) $item['id'] . '">' . esc_html__( 'Confirm swap', 'boxday' ) . '</button>'
			);
		}
		if ( count( $used ) >= $limit ) {
			/* translators: %d: swaps allowed. */
			$first .= '<p>' . esc_html( sprintf( __( 'You\'ve used all %d swaps for this box.', 'boxday' ), $limit ) ) . '</p>';
		}
		$first .= '<button type="button" class="btn ghost" data-close>' . esc_html__( 'Keep my box', 'boxday' ) . '</button>';
		return self::sheet( 'swap-' . $sid, __( 'Swap something', 'boxday' ), $first ) . $more;
	}

	/**
	 * The skip sheet, with the season's rule.
	 *
	 * @param array $signup Sign-up row.
	 * @param array $week   Week row.
	 * @param array $next   Next box.
	 * @param array $season Season row.
	 * @return string
	 */
	private static function skip_sheet( $signup, $week, $next, $season ) {
		$sid  = (int) $signup['id'];
		$left = max( 0, (int) $season['max_skips'] - Signups::skips_used( $sid ) );
		$body = '<p>' . esc_html( Emails::rule_sentence( $season ) ) . '</p><p><b>' . esc_html( sprintf( /* translators: %d: skips left. */ __( 'You have %d skips left.', 'boxday' ), $left ) ) . '</b></p>';

		$body .= '<div class="btns"><button type="button" class="btn" data-act="skip" data-signup="' . $sid . '" data-week="' . (int) $week['id'] . '">' . esc_html__( 'Skip it', 'boxday' ) . '</button>'
			. '<button type="button" class="btn ghost" data-close>' . esc_html__( 'Keep my box', 'boxday' ) . '</button></div>';
		/* translators: %s: date. */
		return self::sheet( 'skip-' . $sid, sprintf( __( 'Skip your box on %s?', 'boxday' ), Format::date( $next['date'] ) ), $body );
	}

	/**
	 * The change pickup sheet.
	 *
	 * @param array $signup Sign-up row.
	 * @param array $week   Week row.
	 * @param array $site   Current site.
	 * @param array $season Season row.
	 * @return string
	 */
	private static function site_sheet( $signup, $week, $site, $season ) {
		$sid  = (int) $signup['id'];
		$opts = '';
		foreach ( Seasons::sites( (int) $signup['season_id'], 'pickup' ) as $other ) {
			if ( (int) $other['id'] === (int) $site['id'] ) {
				continue;
			}
			$opts .= '<label class="swap-opt"><input type="radio" name="site-' . $sid . '" value="' . (int) $other['id'] . '"><span><b>' . esc_html( $other['name'] ) . '</b><small>'
				. esc_html( sprintf( '%1$s %2$s', Format::weekdays( (int) $other['weekday'] ), Format::window( $other['time_from'], $other['time_to'] ) ) ) . '</small></span><i class="tick"></i></label>';
		}
		$btn  = function ( $scope, $label ) use ( $sid, $week ) {
			return '<button type="button" class="btn" data-act="site" data-signup="' . $sid . '" data-week="' . (int) $week['id'] . '" data-scope="' . esc_attr( $scope ) . '">' . esc_html( $label ) . '</button>';
		};
		$body = '<div class="swap-opts">' . $opts . '</div><div class="btns">' . $btn( 'once', __( 'Just this week', 'boxday' ) );
		if ( 'both' === $season['site_change'] ) {
			$body .= $btn( 'always', __( 'From now on', 'boxday' ) );
		}
		$body .= '</div><button type="button" class="btn ghost" data-close>' . esc_html__( 'Keep my box', 'boxday' ) . '</button>';
		return self::sheet( 'site-' . $sid, __( 'Change pickup', 'boxday' ), $body );
	}

	/**
	 * The away dates sheet.
	 *
	 * @param array $signup Sign-up row.
	 * @return string
	 */
	private static function away_sheet( $signup ) {
		$sid  = (int) $signup['id'];
		$body = '<label class="f">' . esc_html__( 'From', 'boxday' ) . '<input class="fld2" type="date" name="from"></label><label class="f">' . esc_html__( 'To', 'boxday' ) . '<input class="fld2" type="date" name="to"></label>'
			. '<div class="btns"><button type="button" class="btn" data-act="away" data-signup="' . $sid . '">' . esc_html__( 'Skip these boxes', 'boxday' ) . '</button>'
			. '<button type="button" class="btn ghost" data-close>' . esc_html__( 'Keep my box', 'boxday' ) . '</button></div>';
		return self::sheet( 'away-' . $sid, __( 'When are you away?', 'boxday' ), $body );
	}

	/**
	 * The member's season as Option B's calendar. Past boxes show as collected.
	 *
	 * @param array $signup  Sign-up row.
	 * @param int   $next_id Week of the next box.
	 * @return string
	 */
	private static function calendar( $signup, $next_id ) {
		$today = Clock::today();
		$rows  = array();
		$skips = Signups::skips_used( (int) $signup['id'] );
		foreach ( Seasons::weeks( (int) $signup['season_id'] ) as $week ) {
			$site  = Signups::week_site( $signup, $week );
			$date  = Calendar::pickup_date( $week['start_date'], $site['weekday'] );
			$month = substr( $date, 0, 7 );
			if ( 'closed' === $week['state'] ) {
				$rows[ $month ][] = '<span class="d shut">×</span>';
				continue;
			}
			if ( ! Signups::due( $signup, $week, $skips ) ) {
				continue;
			}
			$row   = Signups::week_row( (int) $signup['id'], (int) $week['id'] );
			$class = $date < $today ? 'got' : 'gap';
			if ( $row && '' !== $row['skipped'] ) {
				$class = 'off';
			} elseif ( (int) $week['id'] === $next_id ) {
				$class = 'mine first';
			}
			$rows[ $month ][] = '<span class="d ' . $class . '">' . (int) substr( $date, 8, 2 ) . '</span>';
		}
		$html  = '';
		$shown = 0;
		$now   = substr( $today, 0, 7 );
		foreach ( $rows as $month => $cells ) {
			$more  = $month < $now || ++$shown > 2;
			$html .= '<div class="mo' . ( $more ? ' more' : '' ) . '"' . ( $more ? ' hidden' : '' ) . '><span>' . esc_html( Format::date( $month . '-15', 'M' ) ) . '</span><div class="ds">' . implode( '', $cells ) . '</div></div>';
		}
		return $html;
	}

	/**
	 * The payment rows.
	 *
	 * @param array $member Member row.
	 * @return string
	 */
	private static function payments( $member ) {
		$today = Clock::today();
		$rows  = '';
		$seen  = array();
		foreach ( Signups::of_member( (int) $member['id'] ) as $signup ) {
			foreach ( Db::rows( 'SELECT * FROM ' . Db::t( 'instalment' ) . ' WHERE signup_id = %d ORDER BY due_date', array( $signup['id'] ) ) as $part ) {
				$key = $part['due_date'];
				if ( ! isset( $seen[ $key ] ) ) {
					$seen[ $key ] = array(
						'amount' => 0.0,
						'state'  => $part['state'],
						'order'  => 0,
					);
				}
				$seen[ $key ]['amount'] += (float) $part['amount'];
				$seen[ $key ]['order']   = (int) $part['order_id'] ? (int) $part['order_id'] : $seen[ $key ]['order'];
				$seen[ $key ]['state']   = 'paid' === $part['state'] ? $seen[ $key ]['state'] : $part['state'];
			}
		}
		ksort( $seen );
		foreach ( $seen as $date => $part ) {
			if ( 'void' === $part['state'] ) {
				continue;
			}
			$label = 'paid' === $part['state'] ? __( 'Paid', 'boxday' ) : ( $date < $today ? __( 'Overdue', 'boxday' ) : sprintf( /* translators: %s: date. */ __( 'Due %s', 'boxday' ), Format::date( $date ) ) );
			$rows .= '<div class="row"><span>' . esc_html( Format::date( $date ) ) . '</span><b>' . esc_html( Format::money( $part['amount'] ) ) . '</b><span>' . esc_html( $label ) . '</span></div>';
			$order = 'paid' !== $part['state'] && $part['order'] ? wc_get_order( $part['order'] ) : null;
			if ( $order && $order->needs_payment() ) {
				$rows .= '<a class="btn" href="' . esc_url( $order->get_checkout_payment_url() ) . '">' . esc_html( sprintf( /* translators: %s: amount. */ __( 'Pay %s', 'boxday' ), Format::money( $part['amount'] ) ) ) . '</a>';
			}
		}
		return '' === $rows ? '' : '<div class="pay"><h2>' . esc_html__( 'Payments', 'boxday' ) . '</h2>' . $rows . '</div>';
	}

	/**
	 * The expired link page.
	 *
	 * @return string
	 */
	public static function expired_html() {
		return '<div class="wrap"><section class="hi"><h1>' . esc_html__( 'This link has expired', 'boxday' ) . '</h1><p class="note">' . esc_html__( 'Enter your email and we\'ll send you a new one.', 'boxday' ) . '</p>'
			. '<form data-lost style="display:grid;gap:10px;margin:14px 0 24px"><input class="fld2" type="email" name="email" required autocomplete="email" aria-label="' . esc_attr__( 'Email', 'boxday' ) . '">'
			. '<input type="text" name="site_url" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true"><button type="submit" class="btn">' . esc_html__( 'Send my link', 'boxday' ) . '</button></form></section></div>';
	}

	/**
	 * Reads a helper token's site and week. Null once the pickup is 7 days gone.
	 *
	 * @param string $id `site-week`.
	 * @return array|null site, week, date.
	 */
	public static function helper_scope( $id ) {
		$parts = array_map( 'intval', explode( '-', (string) $id ) );
		$site  = 2 === count( $parts ) ? Db::get( 'site', $parts[0] ) : null;
		$week  = 2 === count( $parts ) ? Db::get( 'week', $parts[1] ) : null;
		if ( ! $site || ! $week || (int) $site['season_id'] !== (int) $week['season_id'] ) {
			return null;
		}
		$date = Calendar::pickup_date( $week['start_date'], $site['weekday'] );
		if ( Clock::today() > gmdate( 'Y-m-d', strtotime( $date . ' +7 days' ) ) ) {
			return null;
		}
		return array(
			'site' => $site,
			'week' => $week,
			'date' => $date,
		);
	}

	/**
	 * F16: the helper page. Names, boxes and swaps only: no email, phone or money.
	 *
	 * @param string $id `site-week`.
	 * @return string
	 */
	public static function helper_html( $id ) {
		$scope = self::helper_scope( $id );
		if ( ! $scope ) {
			return '<p class="msg">' . esc_html__( 'This pickup is over, so this link has stopped working.', 'boxday' ) . '</p>';
		}
		$sheet  = Totals::sheet( (int) $scope['site']['id'], $scope['week'] );
		$total  = count( $sheet['coming'] );
		$picked = count( array_filter( array_column( $sheet['coming'], 'picked' ) ) );
		$pct    = $total ? (int) round( 100 * $picked / $total ) : 0;
		$html   = '<div class="wrap hlp"><h1>' . esc_html( $scope['site']['name'] . ', ' . Format::date( $scope['date'] ) ) . '</h1>'
			/* translators: 1: boxes picked up, 2: boxes in all. */
			. '<p class="note" data-count="' . $total . '">' . esc_html( sprintf( __( '%1$d of %2$d picked up', 'boxday' ), $picked, $total ) ) . '</p>'
			. '<div class="bar"><i style="width:' . $pct . '%"></i></div>'
			. '<input class="fld2" type="search" data-find placeholder="' . esc_attr__( 'Find a name', 'boxday' ) . '" aria-label="' . esc_attr__( 'Find a name', 'boxday' ) . '">';
		foreach ( $sheet['coming'] as $row ) {
			$sub   = $row['box'] . ( '' !== $row['swaps'] ? ', ' . $row['swaps'] : '' );
			$html .= '<label class="tick"><input type="checkbox" data-tick="' . (int) $row['signup_id'] . '"' . checked( $row['picked'], true, false ) . '><i></i><span><b>' . esc_html( $row['name'] ) . '</b><small>' . esc_html( $sub ) . '</small></span></label>';
		}
		return $html . '</div>';
	}

	/**
	 * F9: the latest published box, by share and size.
	 *
	 * @return string
	 */
	public static function this_week_html() {
		$week = Boxes::latest_published();
		if ( ! $week ) {
			return '<p class="msg">' . esc_html__( 'This week\'s box will be posted here soon.', 'boxday' ) . '</p>';
		}
		$days  = array_map( 'intval', array_column( Seasons::sites( (int) $week['season_id'] ), 'weekday' ) );
		$from  = Calendar::pickup_date( $week['start_date'], $days ? min( $days ) : 1 );
		$to    = Calendar::pickup_date( $week['start_date'], $days ? max( $days ) : 1 );
		$html  = '<div class="this"><section class="nx"><p class="nb">' . esc_html__( 'This week\'s box', 'boxday' ) . '</p>';
		$html .= '<p class="when">' . esc_html( sprintf( /* translators: 1: week number, 2: first date, 3: last date. */ __( 'Week %1$d, pickups %2$s to %3$s', 'boxday' ), (int) $week['number'], Format::date( $from ), Format::date( $to, 'j' ) ) ) . '</p>';
		if ( ! empty( $week['message'] ) ) {
			$html .= '<p class="tiny">' . esc_html( $week['message'] ) . '</p>';
		}
		$html .= '</section>';
		if ( ! empty( $week['photo_id'] ) ) {
			$html .= wp_get_attachment_image( (int) $week['photo_id'], 'large', false, array( 'class' => 'shot' ) );
		}
		$items = Boxes::items( (int) $week['id'] );
		$html .= '<div class="sizes">';
		foreach ( Seasons::shares( (int) $week['season_id'] ) as $share ) {
			foreach ( Seasons::sizes( (int) $share['id'] ) as $size ) {
				$list = '';
				foreach ( $items as $item ) {
					if ( (int) $item['size_id'] === (int) $size['id'] ) {
						$from  = '' !== $item['grown_at'] ? sprintf( /* translators: %s: farm. */ __( 'grown at %s', 'boxday' ), $item['grown_at'] ) : '';
						$list .= '<li>' . Signup_Page::picture( array(), $item['name'] ) . '<span><b>' . esc_html( $item['name'] ) . '</b>' . esc_html( Boxes::amount( $item ) ) . ( '' !== $from ? '<small>' . esc_html( $from ) . '</small>' : '' ) . '</span></li>';
					}
				}
				if ( '' !== $list ) {
					$html .= '<section class="size"><h2>' . esc_html( $share['name'] . ', ' . $size['name'] ) . '</h2><ul>' . $list . '</ul></section>';
				}
			}
		}
		$html .= '</div>';
		return $html . '</div>';
	}
}
