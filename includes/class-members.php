<?php
/**
 * Members, and what they change: skips, swaps and pickup moves, spec L10, F11 to F13.
 * Every rule here is checked on the request itself, so nothing depends on a scheduled job.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * Member actions. Each returns an empty string or the message to show.
 */
class Members {

	/**
	 * Saves a member by email.
	 *
	 * @param array $data    Columns, with email.
	 * @param bool  $trusted From the farmer's own import, so it may change details.
	 * @return int Member id.
	 */
	public static function upsert( $data, $trusted = false ) {
		$email = strtolower( trim( $data['email'] ) );
		$row   = Db::row( 'SELECT * FROM ' . Db::t( 'member' ) . ' WHERE email = %s', array( $email ) );
		if ( ! $row ) {
			return Db::insert( 'member', array_merge( $data, array( 'email' => $email ) ) );
		}
		// An order's billing email proves nothing, so a later order only fills blanks: it never
		// replaces a member's contact details or links them to another account.
		$data = array_filter(
			$data,
			function ( $value, $key ) use ( $row, $trusted ) {
				return '' !== $value && 0 !== $value && null !== $value && ( $trusted || empty( $row[ $key ] ) );
			},
			ARRAY_FILTER_USE_BOTH
		);
		unset( $data['email'], $data['user_id'] );
		if ( $data ) {
			Db::update( 'member', $data, array( 'id' => $row['id'] ) );
		}
		return (int) $row['id'];
	}

	/**
	 * Whether a week is still open for changes at a site.
	 *
	 * @param array $signup Sign-up row.
	 * @param array $week   Week row.
	 * @param array $site   Site row.
	 * @return bool
	 */
	public static function open_for( $signup, $week, $site ) {
		$season = Seasons::get( (int) $signup['season_id'] );
		$pickup = Calendar::pickup_date( $week['start_date'], $site['weekday'] );
		return Clock::now() < Calendar::cutoff( $season, $site, $pickup, wp_timezone() );
	}

	/**
	 * The message when a cut-off has passed.
	 *
	 * @param array $signup Sign-up row.
	 * @param array $week   Week row.
	 * @param array $site   Site row.
	 * @return string
	 */
	public static function too_late( $signup, $week, $site ) {
		$season = Seasons::get( (int) $signup['season_id'] );
		$cutoff = Calendar::cutoff( $season, $site, Calendar::pickup_date( $week['start_date'], $site['weekday'] ), wp_timezone() );
		return sprintf(
			/* translators: 1: weekday, 2: time. */
			__( 'Too late for this box. The cut-off was %1$s at %2$s.', 'boxday' ),
			Format::day_of( $cutoff ),
			Format::time( $cutoff->format( 'H:i:s' ) )
		);
	}

	/**
	 * Loads a sign-up and week only if they belong to this member and the week is theirs.
	 *
	 * @param array $member    Member row.
	 * @param int   $signup_id Sign-up.
	 * @param int   $week_id   Week.
	 * @return array|null signup, week, site.
	 */
	private static function own( $member, $signup_id, $week_id ) {
		$signup = Db::get( 'signup', $signup_id );
		$week   = Db::get( 'week', $week_id );
		if ( ! $signup || ! $week || (int) $signup['member_id'] !== (int) $member['id'] || 'active' !== $signup['status'] || (int) $week['season_id'] !== (int) $signup['season_id'] ) {
			return null;
		}
		if ( ! Signups::due( $signup, $week, Signups::skips_used( (int) $signup['id'] ) ) ) {
			return null;
		}
		return array(
			'signup' => $signup,
			'week'   => $week,
			'site'   => Signups::week_site( $signup, $week ),
		);
	}

	/**
	 * F11: skip one box.
	 *
	 * @param array $member    Member row.
	 * @param int   $signup_id Sign-up.
	 * @param int   $week_id   Week.
	 * @return string
	 */
	public static function skip( $member, $signup_id, $week_id ) {
		$own = self::own( $member, $signup_id, $week_id );
		if ( ! $own || 'makeup' === $own['week']['state'] ) {
			return __( 'Something changed since you opened this page. We\'ve updated your total.', 'boxday' );
		}
		if ( ! self::open_for( $own['signup'], $own['week'], $own['site'] ) ) {
			return self::too_late( $own['signup'], $own['week'], $own['site'] );
		}
		$row = Signups::week_row( $signup_id, $week_id );
		if ( $row && '' !== $row['skipped'] ) {
			return '';
		}
		$season = Seasons::get( (int) $own['signup']['season_id'] );
		$used   = Signups::skips_used( $signup_id );
		if ( $used >= (int) $season['max_skips'] ) {
			return sprintf( /* translators: %d: skips allowed. */ __( 'You\'ve used all %d skips this season.', 'boxday' ), (int) $season['max_skips'] );
		}
		Signups::set_week( $signup_id, $week_id, array( 'skipped' => 'donate' === $season['skip_rule'] ? 'donate' : 'skip' ) );
		Signups::log( $signup_id, 'member', 'skip', (string) $week_id );
		Emails::skip_confirmation( $member, $own['signup'], $own['week'], $own['site'] );
		return '';
	}

	/**
	 * F11: undo a skip until the cut-off. With L10, a make-up box goes with it and needs no clean-up.
	 *
	 * @param array $member    Member row.
	 * @param int   $signup_id Sign-up.
	 * @param int   $week_id   Week.
	 * @return string
	 */
	public static function unskip( $member, $signup_id, $week_id ) {
		$own = self::own( $member, $signup_id, $week_id );
		if ( ! $own ) {
			return __( 'Something changed since you opened this page. We\'ve updated your total.', 'boxday' );
		}
		if ( ! self::open_for( $own['signup'], $own['week'], $own['site'] ) ) {
			return self::too_late( $own['signup'], $own['week'], $own['site'] );
		}
		Signups::set_week( $signup_id, $week_id, array( 'skipped' => '' ) );
		Signups::log( $signup_id, 'member', 'unskip', (string) $week_id );
		return '';
	}

	/**
	 * F11: skip every box between two dates in one step, or nothing at all.
	 *
	 * @param array  $member    Member row.
	 * @param int    $signup_id Sign-up.
	 * @param string $from      Y-m-d.
	 * @param string $to        Y-m-d.
	 * @return string
	 */
	public static function away( $member, $signup_id, $from, $to ) {
		$signup = Db::get( 'signup', $signup_id );
		if ( ! $signup || (int) $signup['member_id'] !== (int) $member['id'] || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from . '' ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to . '' ) ) {
			return __( 'Something changed since you opened this page. We\'ve updated your total.', 'boxday' );
		}
		$season = Seasons::get( (int) $signup['season_id'] );
		$hits   = array();
		foreach ( Seasons::weeks( (int) $signup['season_id'] ) as $week ) {
			if ( 'open' !== $week['state'] || ! Signups::due( $signup, $week ) ) {
				continue;
			}
			$site   = Signups::week_site( $signup, $week );
			$pickup = Calendar::pickup_date( $week['start_date'], $site['weekday'] );
			$row    = Signups::week_row( $signup_id, (int) $week['id'] );
			if ( $pickup >= $from && $pickup <= $to && self::open_for( $signup, $week, $site ) && ! ( $row && '' !== $row['skipped'] ) ) {
				$hits[] = (int) $week['id'];
			}
		}
		$left = max( 0, (int) $season['max_skips'] - Signups::skips_used( $signup_id ) );
		if ( count( $hits ) > $left ) {
			return sprintf( /* translators: 1: boxes, 2: skips left. */ __( 'Those dates cover %1$d boxes, and you have %2$d skips left this season.', 'boxday' ), count( $hits ), $left );
		}
		foreach ( $hits as $week_id ) {
			Signups::set_week( $signup_id, $week_id, array( 'skipped' => 'donate' === $season['skip_rule'] ? 'donate' : 'skip' ) );
			Signups::log( $signup_id, 'member', 'skip', (string) $week_id );
		}
		return '';
	}

	/**
	 * F12: swap one box item for one on the swap list. Numbers are taken with an atomic update.
	 *
	 * @param array $member    Member row.
	 * @param int   $signup_id Sign-up.
	 * @param int   $week_id   Week.
	 * @param int   $out_id    Item given up.
	 * @param int   $in_id     Item wanted.
	 * @return string
	 */
	public static function swap( $member, $signup_id, $week_id, $out_id, $in_id ) {
		$own = self::own( $member, $signup_id, $week_id );
		if ( ! $own ) {
			return __( 'Something changed since you opened this page. We\'ve updated your total.', 'boxday' );
		}
		if ( ! self::open_for( $own['signup'], $own['week'], $own['site'] ) ) {
			return self::too_late( $own['signup'], $own['week'], $own['site'] );
		}
		$season = Seasons::get( (int) $own['signup']['season_id'] );
		$out    = Db::get( 'box_item', $out_id );
		$in     = Db::get( 'box_item', $in_id );
		$good   = $season['swaps_on'] && $out && $in && (int) $out['week_id'] === $week_id && (int) $in['week_id'] === $week_id
			&& 'box' === $out['role'] && $out['swappable'] && 'swap' === $in['role']
			&& (int) $out['size_id'] === (int) $own['signup']['size_id'] && (int) $in['share_id'] === (int) $own['signup']['share_id'];
		if ( ! $good ) {
			return __( 'Something changed since you opened this page. We\'ve updated your total.', 'boxday' );
		}
		$table = Db::t( 'swap' );
		$used  = (int) Db::value( "SELECT COUNT(*) FROM {$table} WHERE signup_id = %d AND week_id = %d", array( $signup_id, $week_id ) );
		if ( $used >= (int) $season['swap_limit'] ) {
			return sprintf( /* translators: %d: swaps allowed. */ __( 'You\'ve used all %d swaps for this box.', 'boxday' ), (int) $season['swap_limit'] );
		}
		if ( Db::value( "SELECT id FROM {$table} WHERE signup_id = %d AND out_item_id = %d", array( $signup_id, $out_id ) ) ) {
			return __( 'Something changed since you opened this page. We\'ve updated your total.', 'boxday' );
		}
		$items = Db::t( 'box_item' );
		$won   = Db::run( "UPDATE {$items} SET taken = taken + 1 WHERE id = %d AND ( available IS NULL OR taken < available )", array( $in_id ) );
		if ( ! $won ) {
			return sprintf( /* translators: %s: item. */ __( '%s just ran out. Pick another.', 'boxday' ), Boxes::item_name( $in ) );
		}
		Db::insert(
			'swap',
			array(
				'signup_id'   => $signup_id,
				'week_id'     => $week_id,
				'out_item_id' => $out_id,
				'in_item_id'  => $in_id,
			)
		);
		Signups::log( $signup_id, 'member', 'swap', Boxes::item_name( $out ) . ' > ' . Boxes::item_name( $in ) );
		return '';
	}

	/**
	 * F12: undo a swap until the cut-off.
	 *
	 * @param array $member  Member row.
	 * @param int   $swap_id Swap.
	 * @return string
	 */
	public static function unswap( $member, $swap_id ) {
		$swap = Db::get( 'swap', $swap_id );
		$own  = $swap ? self::own( $member, (int) $swap['signup_id'], (int) $swap['week_id'] ) : null;
		if ( ! $own ) {
			return __( 'Something changed since you opened this page. We\'ve updated your total.', 'boxday' );
		}
		if ( ! self::open_for( $own['signup'], $own['week'], $own['site'] ) ) {
			return self::too_late( $own['signup'], $own['week'], $own['site'] );
		}
		Boxes::cancel_swap( $swap );
		return '';
	}

	/**
	 * F13: move one box, or the whole sign-up, to another pickup site.
	 *
	 * @param array  $member    Member row.
	 * @param int    $signup_id Sign-up.
	 * @param int    $week_id   The box to move first.
	 * @param int    $site_id   Target site.
	 * @param string $scope     `once` or `always`.
	 * @return string
	 */
	public static function move( $member, $signup_id, $week_id, $site_id, $scope ) {
		$own    = self::own( $member, $signup_id, $week_id );
		$target = Db::get( 'site', $site_id );
		$bad    = __( 'Something changed since you opened this page. We\'ve updated your total.', 'boxday' );
		if ( ! $own || ! $target || (int) $target['season_id'] !== (int) $own['signup']['season_id'] ) {
			return $bad;
		}
		$season = Seasons::get( (int) $own['signup']['season_id'] );
		$allow  = $season['site_change'];
		if ( 'pickup' !== $target['type'] || 'pickup' !== $own['site']['type'] || 'never' === $allow || ( 'always' === $scope && 'both' !== $allow ) ) {
			return $bad;
		}
		if ( ! self::open_for( $own['signup'], $own['week'], $own['site'] ) ) {
			return self::too_late( $own['signup'], $own['week'], $own['site'] );
		}
		if ( ! self::open_for( $own['signup'], $own['week'], $target ) ) {
			return self::too_late( $own['signup'], $own['week'], $target );
		}
		if ( 'always' === $scope ) {
			if ( ! Capacity::site_has_room( $target, $own['signup']['schedule'], $own['signup']['grp'] ) ) {
				return __( 'That option just filled up. Please choose another.', 'boxday' );
			}
			Db::update( 'signup', array( 'site_id' => $site_id ), array( 'id' => $signup_id ) );
			Signups::log( $signup_id, 'member', 'move', (string) $target['name'] );
			return '';
		}
		if ( '' !== (string) $target['capacity'] && null !== $target['capacity'] && Totals::packed_at( (int) $target['id'], $own['week'] ) + 1 > (int) $target['capacity'] ) {
			return __( 'That option just filled up. Please choose another.', 'boxday' );
		}
		Signups::set_week( $signup_id, $week_id, array( 'site_override' => $site_id ) );
		Signups::log( $signup_id, 'member', 'move_once', (string) $target['name'] );
		return '';
	}
}
