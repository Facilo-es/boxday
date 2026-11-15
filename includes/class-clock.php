<?php
/**
 * The current time, in one place, so a fixed clock can run a whole season in tests.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * Time helpers.
 */
class Clock {

	/**
	 * Current Unix time.
	 *
	 * @return int
	 */
	public static function ts() {
		return (int) apply_filters( 'boxday_now', time() );
	}

	/**
	 * Current time in the site's zone.
	 *
	 * @return \DateTimeImmutable
	 */
	public static function now() {
		return ( new \DateTimeImmutable( '@' . self::ts() ) )->setTimezone( wp_timezone() );
	}

	/**
	 * Today in the site's zone.
	 *
	 * @return string Y-m-d.
	 */
	public static function today() {
		return self::now()->format( 'Y-m-d' );
	}

	/**
	 * Current UTC time for a database row.
	 *
	 * @return string
	 */
	public static function mysql() {
		return gmdate( 'Y-m-d H:i:s', self::ts() );
	}
}
