<?php
/**
 * Prices and payment plans: spec L5, L6 and L8. No WordPress in here.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * Pure money logic. All sums run in whole cents of the store's currency, so they add up exactly.
 */
class Pricing {

	const MAX_LATER_PAYMENTS = 4;

	/**
	 * L5: rounds half up to the store's decimals.
	 *
	 * @param float $amount   Amount.
	 * @param int   $decimals Decimals.
	 * @return float
	 */
	public static function round_up( $amount, $decimals = 2 ) {
		return round( (float) $amount + 1e-9, $decimals, PHP_ROUND_HALF_UP );
	}

	/**
	 * L5: the price of a share for the boxes left.
	 *
	 * @param float  $price    The size's price for this schedule.
	 * @param string $mode     `season` or `box`.
	 * @param bool   $prorate  Prorate a season price.
	 * @param int    $left     Boxes left.
	 * @param int    $total    Boxes in the whole schedule.
	 * @param int    $decimals Store decimals.
	 * @return float
	 */
	public static function price( $price, $mode, $prorate, $left, $total, $decimals = 2 ) {
		if ( 'box' === $mode ) {
			return self::round_up( $price * $left, $decimals );
		}
		if ( ! $prorate || $total < 1 ) {
			return self::round_up( $price, $decimals );
		}
		return self::round_up( $price * $left / $total, $decimals );
	}

	/**
	 * L6: delivery for the boxes left.
	 *
	 * @param float $fee      Fee per box.
	 * @param int   $left     Boxes left.
	 * @param int   $decimals Store decimals.
	 * @return float
	 */
	public static function delivery( $fee, $left, $decimals = 2 ) {
		return self::round_up( $fee * $left, $decimals );
	}

	/**
	 * L8: splits a total into today and later parts. Dates already passed fold into today.
	 *
	 * @param float    $total        Total owed.
	 * @param string   $deposit_type `amount` or `percent`.
	 * @param float    $deposit      Deposit value.
	 * @param string[] $dates        Later dates, Y-m-d, at most 4.
	 * @param string   $today        Today, Y-m-d.
	 * @param int      $decimals     Store decimals.
	 * @return array today, parts (date, amount).
	 */
	public static function plan( $total, $deposit_type, $deposit, $dates, $today, $decimals = 2 ) {
		$unit        = pow( 10, $decimals );
		$total_cents = (int) round( $total * $unit );
		$base        = 'percent' === $deposit_type ? $total * $deposit / 100 : $deposit;
		$dep_cents   = (int) round( self::round_up( $base, $decimals ) * $unit );
		$dates       = array_slice( array_values( $dates ), 0, self::MAX_LATER_PAYMENTS );
		sort( $dates );
		if ( ! $dates || $dep_cents >= $total_cents ) {
			return array(
				'today' => (float) $total,
				'parts' => array(),
			);
		}
		$rest        = $total_cents - $dep_cents;
		$count       = count( $dates );
		$each        = (int) floor( $rest / $count );
		$parts       = array();
		$today_cents = $dep_cents;
		foreach ( $dates as $i => $date ) {
			$cents = $i === $count - 1 ? $rest - $each * ( $count - 1 ) : $each;
			if ( $date <= $today ) {
				$today_cents += $cents;
				continue;
			}
			$parts[] = array(
				'date'   => $date,
				'amount' => (float) ( $cents / $unit ),
			);
		}
		return array(
			'today' => (float) ( $today_cents / $unit ),
			'parts' => $parts,
		);
	}

	/**
	 * Shares one sum out across lines in proportion to their totals, to the cent.
	 *
	 * @param float   $amount   Sum to share out.
	 * @param float[] $weights  One total per line.
	 * @param int     $decimals Store decimals.
	 * @return float[] Same keys as $weights.
	 */
	public static function allocate( $amount, $weights, $decimals = 2 ) {
		$unit  = pow( 10, $decimals );
		$cents = (int) round( $amount * $unit );
		$whole = array_sum( $weights );
		$out   = array();
		$given = 0;
		$keys  = array_keys( $weights );
		$last  = end( $keys );
		foreach ( $weights as $key => $weight ) {
			if ( $key === $last ) {
				$share = $cents - $given;
			} else {
				$share  = $whole > 0 ? (int) floor( $cents * $weight / $whole ) : 0;
				$given += $share;
			}
			$out[ $key ] = (float) ( $share / $unit );
		}
		return $out;
	}
}
