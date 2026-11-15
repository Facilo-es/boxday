<?php
/**
 * Weeks, pickups, cut-offs, groups and boxes left: spec L1 to L4. No WordPress in here.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * Pure date logic. Seasons, sites and weeks are plain arrays.
 */
class Calendar {

	/**
	 * L1: the date a site hands out its boxes in a week.
	 *
	 * @param string $week_start Monday of the week, Y-m-d.
	 * @param int    $weekday    1 Monday to 7 Sunday.
	 * @return string Y-m-d.
	 */
	public static function pickup_date( $week_start, $weekday ) {
		return gmdate( 'Y-m-d', strtotime( $week_start . ' 12:00:00 UTC' ) + ( (int) $weekday - 1 ) * DAY_IN_SECONDS );
	}

	/**
	 * The Monday of the week holding a date.
	 *
	 * @param string $date Y-m-d.
	 * @return string Y-m-d.
	 */
	public static function monday_of( $date ) {
		$time = strtotime( $date . ' 12:00:00 UTC' );
		return gmdate( 'Y-m-d', $time - ( (int) gmdate( 'N', $time ) - 1 ) * DAY_IN_SECONDS );
	}

	/**
	 * L1: the Monday of a numbered week.
	 *
	 * @param string $first_week Monday of week 1.
	 * @param int    $number     Week number, from 1.
	 * @return string Y-m-d.
	 */
	public static function week_start( $first_week, $number ) {
		return gmdate( 'Y-m-d', strtotime( $first_week . ' 12:00:00 UTC' ) + ( (int) $number - 1 ) * 7 * DAY_IN_SECONDS );
	}

	/**
	 * L2: wall-clock cut-off, with the site's override winning over the season.
	 *
	 * @param array         $season Needs cutoff_days and cutoff_time.
	 * @param array         $site   May carry cutoff_days and cutoff_time.
	 * @param string        $pickup Pickup date, Y-m-d.
	 * @param \DateTimeZone $tz     Site time zone.
	 * @return \DateTimeImmutable
	 */
	public static function cutoff( $season, $site, $pickup, $tz ) {
		$has_days = isset( $site['cutoff_days'] ) && '' !== (string) $site['cutoff_days'];
		$days     = $has_days ? (int) $site['cutoff_days'] : (int) $season['cutoff_days'];
		$time     = ! empty( $site['cutoff_time'] ) ? $site['cutoff_time'] : $season['cutoff_time'];
		$date     = gmdate( 'Y-m-d', strtotime( $pickup . ' 12:00:00 UTC' ) - $days * DAY_IN_SECONDS );
		return new \DateTimeImmutable( $date . ' ' . $time, $tz );
	}

	/**
	 * L3: gives open weeks their A or B group, leaving any stored group alone.
	 *
	 * @param array $weeks Rows with number, state and grp.
	 * @return array
	 */
	public static function assign_groups( $weeks ) {
		$next = 'A';
		foreach ( $weeks as $i => $week ) {
			if ( 'open' !== $week['state'] ) {
				$weeks[ $i ]['grp'] = isset( $week['grp'] ) ? $week['grp'] : '';
				if ( '' !== $weeks[ $i ]['grp'] ) {
					$next = 'A' === $weeks[ $i ]['grp'] ? 'B' : 'A';
				}
				continue;
			}
			if ( empty( $week['grp'] ) ) {
				$weeks[ $i ]['grp'] = $next;
			}
			$next = 'A' === $weeks[ $i ]['grp'] ? 'B' : 'A';
		}
		return $weeks;
	}

	/**
	 * The open weeks a schedule gets, leaving out make-up weeks.
	 *
	 * @param array  $weeks    Rows with state and grp.
	 * @param string $schedule `w` or `b`.
	 * @param string $grp      `A` or `B`, for `b`.
	 * @return array
	 */
	public static function schedule_weeks( $weeks, $schedule, $grp ) {
		$out = array();
		foreach ( $weeks as $week ) {
			if ( 'open' === $week['state'] && ( 'w' === $schedule || $week['grp'] === $grp ) ) {
				$out[] = $week;
			}
		}
		return $out;
	}

	/**
	 * L4: the weeks still ahead of their cut-off.
	 *
	 * @param array              $season   Season row.
	 * @param array              $site     Site row.
	 * @param array              $weeks    Week rows.
	 * @param string             $schedule `w` or `b`.
	 * @param string             $grp      `A` or `B`.
	 * @param \DateTimeImmutable $now      Current time.
	 * @return array left, total, first (week row or null), first_date, weeks.
	 */
	public static function boxes_left( $season, $site, $weeks, $schedule, $grp, $now ) {
		$all  = self::schedule_weeks( $weeks, $schedule, $grp );
		$left = array();
		foreach ( $all as $week ) {
			$pickup = self::pickup_date( $week['start_date'], $site['weekday'] );
			if ( $now < self::cutoff( $season, $site, $pickup, $now->getTimezone() ) ) {
				$left[] = $week;
			}
		}
		return array(
			'left'       => count( $left ),
			'total'      => count( $all ),
			'first'      => $left ? $left[0] : null,
			'first_date' => $left ? self::pickup_date( $left[0]['start_date'], $site['weekday'] ) : '',
			'weeks'      => $left,
		);
	}

	/**
	 * L10: whether a member gets a box in a make-up week.
	 *
	 * @param int $makeup_index 1 for the first make-up week.
	 * @param int $skips        Skips the sign-up has used.
	 * @return bool
	 */
	public static function has_makeup_box( $makeup_index, $skips ) {
		return $skips >= $makeup_index;
	}
}
