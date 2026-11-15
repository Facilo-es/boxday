<?php
/**
 * Capacity: spec L9.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * Counts places. Pending sign-ups hold a place for 60 minutes.
 */
class Capacity {

	/**
	 * The waitlist offer the current visitor holds, if any.
	 *
	 * @var int
	 */
	public static $own_offer = 0;

	const PENDING_MINUTES = 60;

	/**
	 * L9: whether a site with these counts takes one more member.
	 *
	 * @param int    $capacity Boxes in any one week.
	 * @param int    $weekly   Weekly members.
	 * @param int    $a        Group A members.
	 * @param int    $b        Group B members.
	 * @param string $schedule `w` or `b`.
	 * @param string $grp      Group of a new every-other member.
	 * @return bool
	 */
	public static function site_fits( $capacity, $weekly, $a, $b, $schedule, $grp ) {
		if ( 'w' === $schedule ) {
			return $weekly + max( $a, $b ) + 1 <= $capacity;
		}
		return $weekly + ( 'A' === $grp ? $a : $b ) + 1 <= $capacity;
	}

	/**
	 * L3: a new every-other member joins the smaller group, and a tie goes to A.
	 *
	 * @param int $a Group A members.
	 * @param int $b Group B members.
	 * @return string
	 */
	public static function smaller_group( $a, $b ) {
		return $b < $a ? 'B' : 'A';
	}

	/**
	 * Counts members holding a place, for a site and optionally one share.
	 *
	 * @param int $season_id Season.
	 * @param int $site_id   Site.
	 * @param int $share_id  Share, or 0 for all main shares.
	 * @return array weekly, A, B.
	 */
	public static function site_counts( $season_id, $site_id, $share_id = 0 ) {
		$signup = Db::t( 'signup' );
		$share  = Db::t( 'share' );
		$where  = self::holds_place_sql();
		$rows   = Db::rows(
			"SELECT s.schedule, s.grp, COUNT(*) AS n FROM {$signup} s JOIN {$share} sh ON sh.id = s.share_id
			WHERE s.season_id = %d AND s.site_id = %d AND sh.kind = 'main' AND ( %d = 0 OR s.share_id = %d ) AND {$where}
			GROUP BY s.schedule, s.grp",
			array( $season_id, $site_id, $share_id, $share_id )
		);
		$counts = array(
			'weekly' => self::offers_held( 'site_id', $site_id, $share_id ),
			'A'      => 0,
			'B'      => 0,
		);
		foreach ( $rows as $row ) {
			if ( 'w' === $row['schedule'] ) {
				$counts['weekly'] += (int) $row['n'];
			} elseif ( isset( $counts[ $row['grp'] ] ) ) {
				$counts[ $row['grp'] ] += (int) $row['n'];
			}
		}
		return $counts;
	}

	/**
	 * L9: a share's capacity is a plain count of sign-ups.
	 *
	 * @param int $share_id Share.
	 * @return int
	 */
	public static function share_count( $share_id ) {
		$signup = Db::t( 'signup' );
		$where  = self::holds_place_sql();
		return (int) Db::value( "SELECT COUNT(*) FROM {$signup} s WHERE s.share_id = %d AND {$where}", array( $share_id ) ) + self::offers_held( 'share_id', $share_id );
	}

	/**
	 * Places left at a site for a new member on this schedule. Null means no limit.
	 *
	 * @param array $site Site row.
	 * @return int|null
	 */
	public static function site_left( $site ) {
		if ( '' === (string) $site['capacity'] || null === $site['capacity'] ) {
			return null;
		}
		$counts = self::site_counts( (int) $site['season_id'], (int) $site['id'] );
		return max( 0, (int) $site['capacity'] - $counts['weekly'] - max( $counts['A'], $counts['B'] ) );
	}

	/**
	 * Whether a share has a place. A share with no capacity always does.
	 *
	 * @param array $share Share row.
	 * @return bool
	 */
	public static function share_fits( $share ) {
		if ( '' === (string) $share['capacity'] || null === $share['capacity'] ) {
			return true;
		}
		return self::share_count( (int) $share['id'] ) < (int) $share['capacity'];
	}

	/**
	 * Whether a site can take a new main-share member.
	 *
	 * @param array  $site     Site row.
	 * @param string $schedule `w` or `b`.
	 * @param string $grp      Group for `b`.
	 * @return bool
	 */
	public static function site_has_room( $site, $schedule, $grp ) {
		if ( '' === (string) $site['capacity'] || null === $site['capacity'] ) {
			return true;
		}
		$counts = self::site_counts( (int) $site['season_id'], (int) $site['id'] );
		return self::site_fits( (int) $site['capacity'], $counts['weekly'], $counts['A'], $counts['B'], $schedule, $grp );
	}

	/**
	 * L12: an open waitlist offer holds a place, as a weekly box at its site. The visitor's own offer doesn't count against them.
	 *
	 * @param string $column   `site_id` or `share_id`.
	 * @param int    $id       Its value.
	 * @param int    $share_id Share filter for sites, or 0.
	 * @return int
	 */
	private static function offers_held( $column, $id, $share_id = 0 ) {
		$column = 'site_id' === $column ? 'site_id' : 'share_id';
		return (int) Db::value(
			'SELECT COUNT(*) FROM ' . Db::t( 'waitlist' ) . " WHERE {$column} = %d AND status = 'offered' AND expires_at > %s AND id <> %d AND ( %d = 0 OR share_id = %d )",
			array( $id, gmdate( 'Y-m-d H:i:s', Clock::ts() ), self::$own_offer, $share_id, $share_id )
		);
	}

	/**
	 * SQL for a sign-up row aliased `s` that holds a place: active, or pending and under an hour old.
	 *
	 * @return string
	 */
	private static function holds_place_sql() {
		$limit = gmdate( 'Y-m-d H:i:s', Clock::ts() - self::PENDING_MINUTES * MINUTE_IN_SECONDS );
		return "( s.status = 'active' OR ( s.status = 'pending' AND s.created_at > '" . esc_sql( $limit ) . "' ) )";
	}
}
