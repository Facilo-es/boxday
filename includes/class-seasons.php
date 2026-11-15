<?php
/**
 * Seasons, weeks, shares, sizes and sites: reads and writes.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * The farm's set-up data.
 */
class Seasons {

	/**
	 * A season row.
	 *
	 * @param int $id Season.
	 * @return array|null
	 */
	public static function get( $id ) {
		return Db::get( 'season', $id );
	}

	/**
	 * All seasons, newest first.
	 *
	 * @return array
	 */
	public static function all() {
		return Db::rows( 'SELECT * FROM ' . Db::t( 'season' ) . ' ORDER BY first_week DESC, id DESC' );
	}

	/**
	 * Seasons a visitor can join today.
	 *
	 * @return array
	 */
	public static function open() {
		$open = array();
		foreach ( self::all() as $season ) {
			if ( 'open' === self::state( $season ) ) {
				$open[] = $season;
			}
		}
		return array_reverse( $open );
	}

	/**
	 * Draft, upcoming, open, running or finished.
	 *
	 * @param array $season Season row.
	 * @return string
	 */
	public static function state( $season ) {
		if ( 'live' !== $season['status'] ) {
			return 'draft';
		}
		$now = Clock::mysql();
		if ( $season['signup_open'] && $now < self::utc( $season['signup_open'] ) ) {
			return 'upcoming';
		}
		if ( $season['signup_close'] && $now < self::utc( $season['signup_close'] ) ) {
			return 'open';
		}
		$weeks = self::weeks( (int) $season['id'] );
		$last  = $weeks ? end( $weeks ) : null;
		if ( $last && Clock::today() <= gmdate( 'Y-m-d', strtotime( $last['start_date'] . ' +6 days' ) ) ) {
			return 'running';
		}
		return 'finished';
	}

	/**
	 * Whether this visitor may sign up for a season now.
	 *
	 * @param array $season Season row.
	 * @return bool
	 */
	public static function signup_open( $season ) {
		/**
		 * Filters whether the visitor may sign up now, such as early for a returning member.
		 *
		 * @param bool  $open   Whether sign-ups are open.
		 * @param array $season Season row.
		 */
		return (bool) apply_filters( 'boxday_signup_open', 'open' === self::state( $season ), $season );
	}

	/**
	 * Local DATETIME to UTC.
	 *
	 * @param string $local Stored local time.
	 * @return string
	 */
	public static function utc( $local ) {
		return ( new \DateTimeImmutable( $local, wp_timezone() ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * A season's week rows in order.
	 *
	 * @param int $season_id Season.
	 * @return array
	 */
	public static function weeks( $season_id ) {
		return Db::rows( 'SELECT * FROM ' . Db::t( 'week' ) . ' WHERE season_id = %d ORDER BY number', array( $season_id ) );
	}

	/**
	 * A season's later payment dates.
	 *
	 * @param array $season Season row.
	 * @return string[]
	 */
	public static function plan_dates( $season ) {
		$dates = json_decode( (string) $season['plan_dates'], true );
		return is_array( $dates ) ? array_slice( array_values( array_filter( $dates ) ), 0, Pricing::MAX_LATER_PAYMENTS ) : array();
	}

	/**
	 * Adds any missing weeks and make-up weeks. Groups already stored stay put, as L3 asks.
	 *
	 * @param int   $season_id Season.
	 * @param int[] $closed    Week numbers to close, or null to leave them.
	 */
	public static function build_weeks( $season_id, $closed = null ) {
		$season = self::get( $season_id );
		$have   = array();
		foreach ( self::weeks( $season_id ) as $week ) {
			$have[ (int) $week['number'] ] = $week;
		}
		$count  = (int) $season['weeks'];
		$makeup = 'makeup' === $season['skip_rule'] ? (int) $season['makeup_weeks'] : 0;
		$rows   = array();
		for ( $n = 1; $n <= $count + $makeup; $n++ ) {
			$row = isset( $have[ $n ] ) ? $have[ $n ] : array(
				'number' => $n,
				'grp'    => '',
				'state'  => 'open',
			);

			$row['start_date'] = Calendar::week_start( $season['first_week'], $n );
			if ( $n > $count ) {
				$row['state'] = 'makeup';
			} elseif ( null !== $closed ) {
				$row['state'] = in_array( $n, $closed, true ) ? 'closed' : 'open';
			} elseif ( 'makeup' === $row['state'] ) {
				$row['state'] = 'open';
			}
			$rows[] = $row;
		}
		foreach ( Calendar::assign_groups( $rows ) as $row ) {
			$data = array(
				'season_id'  => $season_id,
				'number'     => $row['number'],
				'start_date' => $row['start_date'],
				'state'      => $row['state'],
				'grp'        => $row['grp'],
				'is_sample'  => (int) $season['is_sample'],
			);
			if ( isset( $row['id'] ) ) {
				Db::update( 'week', $data, array( 'id' => $row['id'] ) );
			} else {
				Db::insert( 'week', $data );
			}
		}
		foreach ( $have as $n => $week ) {
			if ( $n > $count + $makeup ) {
				Db::delete( 'week', array( 'id' => $week['id'] ) );
			}
		}
	}

	/**
	 * Shares in a season.
	 *
	 * @param int    $season_id Season.
	 * @param string $kind      `main`, `addon` or empty for both.
	 * @return array
	 */
	public static function shares( $season_id, $kind = '' ) {
		$rows = Db::rows( 'SELECT * FROM ' . Db::t( 'share' ) . ' WHERE season_id = %d AND hidden = 0 ORDER BY sort, id', array( $season_id ) );
		if ( '' === $kind ) {
			return $rows;
		}
		return array_values(
			array_filter(
				$rows,
				function ( $row ) use ( $kind ) {
					return $row['kind'] === $kind;
				}
			)
		);
	}

	/**
	 * Sizes of a share.
	 *
	 * @param int $share_id Share.
	 * @return array
	 */
	public static function sizes( $share_id ) {
		return Db::rows( 'SELECT * FROM ' . Db::t( 'size' ) . ' WHERE share_id = %d ORDER BY sort, id', array( $share_id ) );
	}

	/**
	 * Sites in a season.
	 *
	 * @param int    $season_id Season.
	 * @param string $type      `pickup`, `delivery` or empty for both.
	 * @return array
	 */
	public static function sites( $season_id, $type = '' ) {
		$rows = Db::rows( 'SELECT * FROM ' . Db::t( 'site' ) . ' WHERE season_id = %d ORDER BY type DESC, id', array( $season_id ) );
		if ( '' === $type ) {
			return $rows;
		}
		return array_values(
			array_filter(
				$rows,
				function ( $row ) use ( $type ) {
					return $row['type'] === $type;
				}
			)
		);
	}

	/**
	 * F4: the delivery route for a ZIP. The longest matching prefix wins.
	 *
	 * @param int    $season_id Season.
	 * @param string $zip       ZIP code.
	 * @return array|null
	 */
	public static function route_for_zip( $season_id, $zip ) {
		$best = null;
		$size = 0;
		foreach ( self::sites( $season_id, 'delivery' ) as $site ) {
			foreach ( array_filter( array_map( 'trim', explode( ',', $site['zip_prefixes'] ) ) ) as $prefix ) {
				if ( 0 === strpos( $zip, $prefix ) && strlen( $prefix ) > $size ) {
					$best = $site;
					$size = strlen( $prefix );
				}
			}
		}
		return $best;
	}

	/**
	 * The week to plan or pack next: the first open week whose last pickup hasn't passed.
	 *
	 * @param int $season_id Season.
	 * @return array|null
	 */
	public static function current_week( $season_id ) {
		$today = Clock::today();
		foreach ( self::weeks( $season_id ) as $week ) {
			if ( 'closed' !== $week['state'] && gmdate( 'Y-m-d', strtotime( $week['start_date'] . ' +6 days' ) ) >= $today ) {
				return $week;
			}
		}
		return null;
	}

	/**
	 * The next pickup still ahead, across all sites.
	 *
	 * @param int $season_id Season.
	 * @return array|null week, date.
	 */
	public static function next_pickup( $season_id ) {
		$today = Clock::today();
		$best  = null;
		$sites = self::sites( $season_id );
		foreach ( self::weeks( $season_id ) as $week ) {
			if ( 'closed' === $week['state'] ) {
				continue;
			}
			foreach ( $sites as $site ) {
				$date = Calendar::pickup_date( $week['start_date'], $site['weekday'] );
				if ( $date >= $today && ( ! $best || $date < $best['date'] ) ) {
					$best = array(
						'week' => $week,
						'date' => $date,
					);
				}
			}
		}
		return $best;
	}

	/**
	 * The first week from the next pickup on with no published box.
	 *
	 * @param int $season_id Season.
	 * @return array|null Week row.
	 */
	public static function next_to_plan( $season_id ) {
		$next = self::next_pickup( $season_id );
		if ( ! $next ) {
			return null;
		}
		foreach ( self::weeks( $season_id ) as $week ) {
			if ( 'open' === $week['state'] && (int) $week['number'] >= (int) $next['week']['number'] && ! $week['published_at'] ) {
				return $week;
			}
		}
		return null;
	}

	/**
	 * Copies a season forward 52 weeks with its set-up. Members aren't copied.
	 *
	 * @param int $season_id Season.
	 * @return int New season.
	 */
	public static function copy( $season_id ) {
		$from = self::get( $season_id );
		$new  = $from;
		unset( $new['id'] );
		$shift = function ( $date ) {
			return $date ? gmdate( 'Y-m-d H:i:s', strtotime( $date . ' +364 days' ) ) : null;
		};

		$new['name']         = sprintf( /* translators: %s: season name. */ __( '%s (copy)', 'boxday' ), $from['name'] );
		$new['status']       = 'draft';
		$new['first_week']   = substr( $shift( $from['first_week'] ), 0, 10 );
		$new['signup_open']  = $shift( $from['signup_open'] );
		$new['signup_close'] = $shift( $from['signup_close'] );
		$new['plan_dates']   = wp_json_encode(
			array_map(
				function ( $date ) use ( $shift ) {
					return substr( $shift( $date ), 0, 10 );
				},
				self::plan_dates( $from )
			)
		);
		$new_id              = Db::insert( 'season', $new );
		foreach ( Db::rows( 'SELECT * FROM ' . Db::t( 'share' ) . ' WHERE season_id = %d', array( $season_id ) ) as $share ) {
			$sizes = self::sizes( (int) $share['id'] );
			unset( $share['id'] );
			$share['season_id']  = $new_id;
			$share['product_id'] = 0;
			$share_id            = Db::insert( 'share', $share );
			foreach ( $sizes as $size ) {
				unset( $size['id'] );
				$size['share_id'] = $share_id;
				Db::insert( 'size', $size );
			}
			Carrier::ensure( $share_id );
		}
		foreach ( self::sites( $season_id ) as $site ) {
			unset( $site['id'] );
			$site['season_id'] = $new_id;
			Db::insert( 'site', $site );
		}
		$closed = array();
		foreach ( self::weeks( $season_id ) as $week ) {
			if ( 'closed' === $week['state'] ) {
				$closed[] = (int) $week['number'];
			}
		}
		self::build_weeks( $new_id, $closed );
		return $new_id;
	}
}
