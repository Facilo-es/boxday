<?php
/**
 * Harvest totals and pickup sheets: spec L11, F15 and F16.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * Sums for the packing shed.
 */
class Totals {

	/**
	 * Active sign-ups due a box in a week, skipped or not, with their week row.
	 *
	 * @param array $week Week row.
	 * @return array Sign-up rows, plus skipped, picked_up_at and site_override.
	 */
	public static function due_signups( $week ) {
		$signup = Db::t( 'signup' );
		$sw     = Db::t( 'signup_week' );
		$rows   = Db::rows(
			"SELECT s.*, COALESCE(w.skipped,'') AS skipped, w.picked_up_at, COALESCE(w.site_override,0) AS site_override,
			( SELECT COUNT(*) FROM {$sw} x WHERE x.signup_id = s.id AND x.skipped <> '' ) AS skips
			FROM {$signup} s LEFT JOIN {$sw} w ON w.signup_id = s.id AND w.week_id = %d
			WHERE s.season_id = %d AND s.status = 'active'",
			array( $week['id'], $week['season_id'] )
		);
		$out    = array();
		foreach ( $rows as $row ) {
			if ( Signups::due( $row, $week, (int) $row['skips'] ) ) {
				$row['week_site'] = $row['site_override'] ? (int) $row['site_override'] : (int) $row['site_id'];
				$out[]            = $row;
			}
		}
		return $out;
	}

	/**
	 * Boxes packed at a site in a week: due minus skipped. Donated boxes are packed.
	 *
	 * @param int   $site_id Site.
	 * @param array $week    Week row.
	 * @return int
	 */
	public static function packed_at( $site_id, $week ) {
		$count = 0;
		foreach ( self::due_signups( $week ) as $row ) {
			$share = Db::get( 'share', (int) $row['share_id'] );
			if ( $row['week_site'] === $site_id && 'skip' !== $row['skipped'] && 'main' === $share['kind'] ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * L11: what to harvest and how many boxes to pack.
	 *
	 * @param array $week Week row.
	 * @return array crops, packing, donated, pending.
	 */
	public static function week( $week ) {
		$due    = self::due_signups( $week );
		$packed = array();
		foreach ( $due as $row ) {
			if ( 'skip' !== $row['skipped'] ) {
				$size            = (int) $row['size_id'];
				$packed[ $size ] = isset( $packed[ $size ] ) ? $packed[ $size ] + 1 : 1;
			}
		}
		$crops = array();
		foreach ( Boxes::items( (int) $week['id'] ) as $item ) {
			$key = $item['name'] . '|' . $item['unit'];
			if ( ! isset( $crops[ $key ] ) ) {
				$crops[ $key ] = array(
					'name'   => $item['name'],
					'unit'   => $item['unit'],
					'before' => 0.0,
					'after'  => 0.0,
				);
			}
			$sum                      = (float) $item['qty'] * ( isset( $packed[ (int) $item['size_id'] ] ) ? $packed[ (int) $item['size_id'] ] : 0 );
			$crops[ $key ]['before'] += $sum;
			$crops[ $key ]['after']  += $sum;
		}
		$table = Db::t( 'swap' );
		$swaps = Db::rows( "SELECT * FROM {$table} WHERE week_id = %d", array( $week['id'] ) );
		$live  = array();
		foreach ( $due as $row ) {
			if ( 'skip' !== $row['skipped'] ) {
				$live[ (int) $row['id'] ] = true;
			}
		}
		foreach ( $swaps as $swap ) {
			if ( empty( $live[ (int) $swap['signup_id'] ] ) ) {
				continue;
			}
			$out = Db::get( 'box_item', (int) $swap['out_item_id'] );
			$in  = Db::get( 'box_item', (int) $swap['in_item_id'] );
			if ( ! $out || ! $in ) {
				continue;
			}
			$key = Boxes::item_name( $out ) . '|' . $out['unit'];
			if ( isset( $crops[ $key ] ) ) {
				$crops[ $key ]['after'] -= (float) $out['qty'];
			}
			$key = Boxes::item_name( $in ) . '|' . $in['unit'];
			if ( ! isset( $crops[ $key ] ) ) {
				$crops[ $key ] = array(
					'name'   => Boxes::item_name( $in ),
					'unit'   => $in['unit'],
					'before' => 0.0,
					'after'  => 0.0,
				);
			}
			$crops[ $key ]['after'] += (float) $in['qty'];
		}
		$packing = array();
		foreach ( $due as $row ) {
			$key = $row['week_site'] . '|' . $row['size_id'];
			if ( ! isset( $packing[ $key ] ) ) {
				$size            = Db::get( 'size', (int) $row['size_id'] );
				$share           = Db::get( 'share', (int) $row['share_id'] );
				$site            = Db::get( 'site', $row['week_site'] );
				$packing[ $key ] = array(
					'site'    => $site['name'],
					'share'   => $share['name'],
					'size'    => $size['name'],
					'boxes'   => 0,
					'donated' => 0,
				);
			}
			if ( 'skip' !== $row['skipped'] ) {
				++$packing[ $key ]['boxes'];
			}
			if ( 'donate' === $row['skipped'] ) {
				++$packing[ $key ]['donated'];
			}
		}
		ksort( $packing );
		/**
		 * Filters a week's totals rows.
		 *
		 * @param array $totals crops (name, unit, before, after) and packing rows.
		 * @param array $week   Week row.
		 * @param array $due    Sign-ups due that week, from due_signups().
		 */
		return apply_filters(
			'boxday_totals',
			array(
				'crops'   => array_values( $crops ),
				'packing' => array_values( $packing ),
			),
			$week,
			$due
		);
	}

	/**
	 * The people on a site's sheet for a week, sorted by last name.
	 *
	 * @param int   $site_id Site.
	 * @param array $week    Week row.
	 * @return array coming, away.
	 */
	public static function sheet( $site_id, $week ) {
		$member = Db::t( 'member' );
		$coming = array();
		$away   = array();
		foreach ( self::due_signups( $week ) as $row ) {
			if ( $row['week_site'] !== $site_id ) {
				continue;
			}
			$person = Db::row( "SELECT first_name, last_name, notes FROM {$member} WHERE id = %d", array( $row['member_id'] ) );
			$size   = Db::get( 'size', (int) $row['size_id'] );
			$share  = Db::get( 'share', (int) $row['share_id'] );
			$swaps  = array();
			foreach ( Boxes::swaps( (int) $row['id'], (int) $week['id'] ) as $swap ) {
				$out     = Db::get( 'box_item', (int) $swap['out_item_id'] );
				$in      = Db::get( 'box_item', (int) $swap['in_item_id'] );
				$swaps[] = ( $out ? Boxes::item_name( $out ) : '' ) . ' → ' . ( $in ? Boxes::item_name( $in ) : '' );
			}
			$entry = array(
				'signup_id' => (int) $row['id'],
				'name'      => trim( $person['last_name'] . ', ' . $person['first_name'], ', ' ),
				'box'       => 'main' === $share['kind'] ? $size['name'] : $share['name'],
				'swaps'     => implode( ', ', $swaps ),
				'note'      => (string) $person['notes'],
				'picked'    => ! empty( $row['picked_up_at'] ),
			);
			/**
			 * Filters one member's line on a pickup sheet.
			 *
			 * @param array $entry signup_id, name, box, swaps, note and picked.
			 * @param array $row   Sign-up row from due_signups().
			 * @param array $week  Week row.
			 */
			$entry = apply_filters( 'boxday_sheet_row', $entry, $row, $week );
			if ( 'skip' === $row['skipped'] ) {
				$entry['reason'] = __( 'Skipped', 'boxday' );
				$away[]          = $entry;
			} elseif ( 'donate' === $row['skipped'] ) {
				$entry['reason'] = __( 'Donated', 'boxday' );
				$away[]          = $entry;
			} else {
				$coming[] = $entry;
			}
		}
		$by_name = function ( $a, $b ) {
			return strcasecmp( $a['name'], $b['name'] );
		};
		usort( $coming, $by_name );
		usort( $away, $by_name );
		return array(
			'coming' => $coming,
			'away'   => $away,
		);
	}
}
