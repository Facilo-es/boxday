<?php
/**
 * The server's sums for a sign-up. The browser only ever sends IDs and choices.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a quote from IDs.
 */
class Quote {

	/**
	 * Works out the whole sign-up.
	 *
	 * @param array $in season_id, size_id, schedule, site_id, addons, plan, zip. A group is kept if given.
	 * @return array|\WP_Error
	 */
	public static function build( $in ) {
		$season = Seasons::get( (int) $in['season_id'] );
		if ( ! $season ) {
			return new \WP_Error( 'boxday_bad', __( 'Something changed since you opened this page. We\'ve updated your total.', 'boxday' ) );
		}
		$season_id = (int) $season['id'];
		$weeks     = Seasons::weeks( $season_id );
		$size      = Db::get( 'size', (int) $in['size_id'] );
		$share     = $size ? Db::get( 'share', (int) $size['share_id'] ) : null;
		$site      = Db::get( 'site', (int) $in['site_id'] );
		if ( ! $share || (int) $share['season_id'] !== $season_id || ! $site || (int) $site['season_id'] !== $season_id ) {
			return new \WP_Error( 'boxday_bad', __( 'Something changed since you opened this page. We\'ve updated your total.', 'boxday' ) );
		}
		$schedule = isset( $in['schedule'] ) && 'b' === $in['schedule'] ? 'b' : 'w';
		$raw      = self::size_price( $size, $schedule );
		if ( null === $raw ) {
			$schedule = 'w' === $schedule ? 'b' : 'w';
			$raw      = self::size_price( $size, $schedule );
		}
		if ( null === $raw ) {
			return new \WP_Error( 'boxday_bad', __( 'Something changed since you opened this page. We\'ve updated your total.', 'boxday' ) );
		}
		$grp = '';
		if ( 'b' === $schedule ) {
			if ( ! empty( $in['grp'] ) ) {
				$grp = 'B' === $in['grp'] ? 'B' : 'A';
			} else {
				$counts = Capacity::site_counts( $season_id, (int) $site['id'], (int) $share['id'] );
				$grp    = Capacity::smaller_group( $counts['A'], $counts['B'] );
			}
		}
		$now      = Clock::now();
		$decimals = wc_get_price_decimals();
		$boxes    = Calendar::boxes_left( $season, $site, $weeks, $schedule, $grp, $now );
		$price    = Pricing::price( $raw, $share['price_mode'], (bool) $season['prorate'], $boxes['left'], $boxes['total'], $decimals );
		$delivery = 'delivery' === $site['type'] ? Pricing::delivery( (float) $site['fee_per_box'], $boxes['left'], $decimals ) : 0.0;
		$main     = array(
			'share'    => $share,
			'size'     => $size,
			'price'    => $price,
			'delivery' => $delivery,
			'total'    => $price + $delivery,
		);
		$lines    = array( $main );
		foreach ( self::chosen_addons( $season_id, isset( $in['addons'] ) ? (array) $in['addons'] : array() ) as $addon_size ) {
			$addon_raw = self::size_price( $addon_size['size'], $schedule );
			if ( null === $addon_raw ) {
				continue;
			}
			$addon_price = Pricing::price( $addon_raw, $addon_size['share']['price_mode'], (bool) $season['prorate'], $boxes['left'], $boxes['total'], $decimals );
			$lines[]     = array(
				'share'    => $addon_size['share'],
				'size'     => $addon_size['size'],
				'price'    => $addon_price,
				'delivery' => 0.0,
				'total'    => $addon_price,
			);
		}
		$total = round( array_sum( array_column( $lines, 'total' ) ), $decimals );
		$plan  = array(
			'today' => $total,
			'parts' => array(),
		);
		if ( isset( $in['plan'] ) && 'parts' === $in['plan'] && 'parts' === $season['plan_mode'] ) {
			$plan = Pricing::plan( $total, $season['deposit_type'], (float) $season['deposit_value'], Seasons::plan_dates( $season ), Clock::today(), $decimals );
		}
		$weights = array_column( $lines, 'total' );
		$today   = Pricing::allocate( $plan['today'], $weights, $decimals );
		foreach ( $lines as $i => $line ) {
			$lines[ $i ]['today'] = $today[ $i ];
			$lines[ $i ]['parts'] = array();
		}
		foreach ( $plan['parts'] as $part ) {
			foreach ( Pricing::allocate( $part['amount'], $weights, $decimals ) as $i => $amount ) {
				$lines[ $i ]['parts'][] = array(
					'date'   => $part['date'],
					'amount' => $amount,
				);
			}
		}
		return array(
			'season'   => $season,
			'weeks'    => $weeks,
			'site'     => $site,
			'schedule' => $schedule,
			'grp'      => $grp,
			'boxes'    => $boxes,
			'lines'    => $lines,
			'total'    => $total,
			'plan'     => $plan,
		);
	}

	/**
	 * A size's price on a schedule, or null when it isn't offered.
	 *
	 * @param array  $size     Size row.
	 * @param string $schedule `w` or `b`.
	 * @return float|null
	 */
	public static function size_price( $size, $schedule ) {
		$value = 'b' === $schedule ? $size['price_biweekly'] : $size['price_weekly'];
		$value = null === $value || '' === $value ? null : (float) $value;
		/**
		 * Filters a size's price on a schedule. Null means it isn't offered.
		 *
		 * @param float|null $value    Price.
		 * @param array      $size     Size row.
		 * @param string     $schedule `w` or `b`.
		 */
		return null === $value ? null : (float) apply_filters( 'boxday_size_price', $value, $size, $schedule );
	}

	/**
	 * Add-on sizes picked, checked against the season.
	 *
	 * @param int   $season_id Season.
	 * @param int[] $ids       Size ids.
	 * @return array
	 */
	private static function chosen_addons( $season_id, $ids ) {
		$out = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$size  = Db::get( 'size', $id );
			$share = $size ? Db::get( 'share', (int) $size['share_id'] ) : null;
			if ( $share && (int) $share['season_id'] === $season_id && 'addon' === $share['kind'] ) {
				$out[] = array(
					'share' => $share,
					'size'  => $size,
				);
			}
		}
		return $out;
	}

	/**
	 * Why a sign-up can't go ahead, or an empty string.
	 *
	 * @param array $quote A built quote.
	 * @return string
	 */
	public static function refusal( $quote ) {
		$season = $quote['season'];
		if ( ! Seasons::signup_open( $season ) ) {
			return sprintf( /* translators: %s: season name. */ __( 'Sign-ups are closed for %s.', 'boxday' ), $season['name'] );
		}
		if ( $quote['boxes']['left'] < 1 || ( ! $season['late_join'] && $quote['boxes']['left'] < $quote['boxes']['total'] ) ) {
			return sprintf( /* translators: %s: season name. */ __( 'Sign-ups are closed for %s.', 'boxday' ), $season['name'] );
		}
		$main = $quote['lines'][0];
		if ( ! Capacity::share_fits( $main['share'] ) || ! Capacity::site_has_room( $quote['site'], $quote['schedule'], $quote['grp'] ) ) {
			return __( 'That option just filled up. Please choose another.', 'boxday' );
		}
		return '';
	}
}
