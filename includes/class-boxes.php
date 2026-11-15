<?php
/**
 * The weekly box: planner data, publishing and what a member gets, spec F8 and F9.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * Box items and crops.
 */
class Boxes {

	/**
	 * The name of an item's crop.
	 *
	 * @param array $item Box item row.
	 * @return string
	 */
	public static function item_name( $item ) {
		return (string) Db::value( 'SELECT name FROM ' . Db::t( 'crop' ) . ' WHERE id = %d', array( $item['crop_id'] ) );
	}

	/**
	 * Finds a crop by name, or saves it, so a crop is typed only once.
	 *
	 * @param string $name     Crop name.
	 * @param string $unit     Unit.
	 * @param string $grown_at Source farm.
	 * @param int    $sample   Sample row.
	 * @return int Crop id.
	 */
	public static function crop( $name, $unit = '', $grown_at = '', $sample = 0 ) {
		$table = Db::t( 'crop' );
		$row   = Db::row( "SELECT * FROM {$table} WHERE name = %s", array( $name ) );
		if ( $row ) {
			if ( '' !== $grown_at && $grown_at !== $row['grown_at'] ) {
				Db::update( 'crop', array( 'grown_at' => $grown_at ), array( 'id' => $row['id'] ) );
			}
			return (int) $row['id'];
		}
		return Db::insert(
			'crop',
			array(
				'name'      => $name,
				'unit'      => $unit,
				'grown_at'  => $grown_at,
				'is_sample' => $sample,
			)
		);
	}

	/**
	 * All crops, for the planner's suggestions.
	 *
	 * @return array
	 */
	public static function crops() {
		return Db::rows( 'SELECT * FROM ' . Db::t( 'crop' ) . ' ORDER BY name' );
	}

	/**
	 * A week's items with crop names.
	 *
	 * @param int    $week_id Week.
	 * @param string $role    `box` or `swap`.
	 * @return array
	 */
	public static function items( $week_id, $role = 'box' ) {
		$item = Db::t( 'box_item' );
		$crop = Db::t( 'crop' );
		return Db::rows(
			"SELECT i.*, c.name, c.grown_at FROM {$item} i JOIN {$crop} c ON c.id = i.crop_id WHERE i.week_id = %d AND i.role = %s ORDER BY i.id",
			array( $week_id, $role )
		);
	}

	/**
	 * The items in a size's box for a week, with a member's swaps applied.
	 *
	 * @param array $signup Sign-up row.
	 * @param array $week   Week row.
	 * @return array
	 */
	public static function box_for( $signup, $week ) {
		$items = array();
		foreach ( self::items( (int) $week['id'] ) as $item ) {
			if ( (int) $item['size_id'] === (int) $signup['size_id'] ) {
				$items[ (int) $item['id'] ] = $item;
			}
		}
		foreach ( self::swaps( (int) $signup['id'], (int) $week['id'] ) as $swap ) {
			$in = Db::get( 'box_item', (int) $swap['in_item_id'] );
			if ( ! $in || ! isset( $items[ (int) $swap['out_item_id'] ] ) ) {
				continue;
			}
			$old                 = $items[ (int) $swap['out_item_id'] ];
			$in['name']          = self::item_name( $in );
			$in['grown_at']      = '';
			$in['swap_id']       = (int) $swap['id'];
			$in['swapped_from']  = $old['name'];
			$items[ $old['id'] ] = $in;
		}
		/**
		 * Filters the box a member gets in a week. The member page, emails, totals and sheets read it.
		 *
		 * @param array $items  Items, each with name, qty and unit.
		 * @param array $signup Sign-up row.
		 * @param array $week   Week row.
		 */
		return array_values( (array) apply_filters( 'boxday_member_box', array_values( $items ), $signup, $week ) );
	}

	/**
	 * A sign-up's swaps in a week.
	 *
	 * @param int $signup_id Sign-up.
	 * @param int $week_id   Week.
	 * @return array
	 */
	public static function swaps( $signup_id, $week_id ) {
		return Db::rows( 'SELECT * FROM ' . Db::t( 'swap' ) . ' WHERE signup_id = %d AND week_id = %d', array( $signup_id, $week_id ) );
	}

	/**
	 * Cancels a swap and gives its item back.
	 *
	 * @param array $swap Swap row.
	 */
	public static function cancel_swap( $swap ) {
		Db::run( 'UPDATE ' . Db::t( 'box_item' ) . ' SET taken = GREATEST( taken - 1, 0 ) WHERE id = %d', array( $swap['in_item_id'] ) );
		Db::delete( 'swap', array( 'id' => $swap['id'] ) );
		Signups::log( (int) $swap['signup_id'], 'member', 'unswap', (string) $swap['week_id'] );
	}

	/**
	 * Saves the planner: rows keep their id, so members' swaps survive an edit.
	 *
	 * @param int   $week_id Week.
	 * @param array $rows    Rows with id, share_id, size_id, role, name, qty, unit, swappable, available, grown_at.
	 * @param bool  $force   Remove items people swapped.
	 * @return array Names of items blocked by swaps, with counts. Empty when saved.
	 */
	public static function save( $week_id, $rows, $force = false ) {
		$keep = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row['id'] ) ) {
				$keep[] = (int) $row['id'];
			}
		}
		$blocked = array();
		$remove  = array();
		$table   = Db::t( 'swap' );
		foreach ( array_merge( self::items( $week_id ), self::items( $week_id, 'swap' ) ) as $item ) {
			if ( in_array( (int) $item['id'], $keep, true ) ) {
				continue;
			}
			$swaps = Db::rows( "SELECT * FROM {$table} WHERE out_item_id = %d OR in_item_id = %d", array( $item['id'], $item['id'] ) );
			if ( $swaps && ! $force ) {
				$blocked[ $item['name'] ] = count( $swaps );
			}
			$remove[ (int) $item['id'] ] = $swaps;
		}
		if ( $blocked ) {
			return $blocked;
		}
		foreach ( $remove as $item_id => $swaps ) {
			foreach ( $swaps as $swap ) {
				self::cancel_swap( $swap );
			}
			Db::delete( 'box_item', array( 'id' => $item_id ) );
		}
		foreach ( $rows as $row ) {
			$name = trim( $row['name'] );
			if ( '' === $name ) {
				continue;
			}
			$data = array(
				'week_id'   => $week_id,
				'share_id'  => (int) $row['share_id'],
				'size_id'   => (int) $row['size_id'],
				'role'      => 'swap' === $row['role'] ? 'swap' : 'box',
				'crop_id'   => self::crop( $name, $row['unit'], isset( $row['grown_at'] ) ? $row['grown_at'] : '' ),
				'qty'       => (float) $row['qty'],
				'unit'      => $row['unit'],
				'swappable' => empty( $row['swappable'] ) ? 0 : 1,
				'available' => isset( $row['available'] ) && '' !== $row['available'] ? (int) $row['available'] : null,
			);
			if ( empty( $row['id'] ) ) {
				Db::insert( 'box_item', $data );
			} else {
				Db::update( 'box_item', $data, array( 'id' => (int) $row['id'] ) );
			}
		}
		return array();
	}

	/**
	 * T30: copies the items of the week before. The message stays behind.
	 *
	 * @param array $week Week row.
	 * @return bool
	 */
	public static function copy_last( $week ) {
		$before = Db::row(
			'SELECT w.* FROM ' . Db::t( 'week' ) . ' w WHERE w.season_id = %d AND w.number < %d AND w.id IN ( SELECT week_id FROM ' . Db::t( 'box_item' ) . ' ) ORDER BY w.number DESC LIMIT 1',
			array( $week['season_id'], $week['number'] )
		);
		if ( ! $before ) {
			return false;
		}
		Db::delete( 'box_item', array( 'week_id' => $week['id'] ) );
		foreach ( Db::rows( 'SELECT * FROM ' . Db::t( 'box_item' ) . ' WHERE week_id = %d', array( $before['id'] ) ) as $item ) {
			unset( $item['id'] );
			$item['week_id'] = $week['id'];
			$item['taken']   = 0;
			Db::insert( 'box_item', $item );
		}
		return true;
	}

	/**
	 * Copies one size's box items to the other sizes of its share.
	 *
	 * @param int $week_id Week.
	 * @param int $size_id Size to copy from.
	 */
	public static function copy_to_sizes( $week_id, $size_id ) {
		$from = Db::get( 'size', $size_id );
		if ( ! $from ) {
			return;
		}
		$source = Db::rows( 'SELECT * FROM ' . Db::t( 'box_item' ) . " WHERE week_id = %d AND size_id = %d AND role = 'box'", array( $week_id, $size_id ) );
		foreach ( Seasons::sizes( (int) $from['share_id'] ) as $size ) {
			if ( (int) $size['id'] === $size_id ) {
				continue;
			}
			$have = Db::col( 'SELECT crop_id FROM ' . Db::t( 'box_item' ) . " WHERE week_id = %d AND size_id = %d AND role = 'box'", array( $week_id, $size['id'] ) );
			foreach ( $source as $item ) {
				if ( in_array( (string) $item['crop_id'], $have, true ) ) {
					continue;
				}
				unset( $item['id'] );
				$item['size_id'] = $size['id'];
				$item['taken']   = 0;
				Db::insert( 'box_item', $item );
			}
		}
	}

	/**
	 * Members who'd get this week's email.
	 *
	 * @param array $week Week row.
	 * @return int
	 */
	public static function recipients( $week ) {
		$count = array();
		foreach ( Totals::due_signups( $week ) as $row ) {
			$count[ $row['member_id'] ] = true;
		}
		return count( $count );
	}

	/**
	 * Publishes a week and queues its email.
	 *
	 * @param array $week Week row.
	 */
	public static function publish( $week ) {
		Db::update( 'week', array( 'published_at' => Clock::mysql() ), array( 'id' => $week['id'] ) );
		Jobs::queue_box_mail( (int) $week['id'] );
		if ( 'yes' === get_option( 'boxday_week_posts', 'no' ) ) {
			self::week_post( Db::get( 'week', (int) $week['id'] ) );
		}
	}

	/**
	 * F9: the week's box as a normal blog post, once per week. Later publishes update it.
	 *
	 * @param array $week Week row.
	 * @return int Post id.
	 */
	public static function week_post( $week ) {
		$html = '' !== (string) $week['message'] ? '<!-- wp:paragraph --><p>' . esc_html( $week['message'] ) . '</p><!-- /wp:paragraph -->' : '';
		foreach ( Seasons::shares( (int) $week['season_id'] ) as $share ) {
			foreach ( Seasons::sizes( (int) $share['id'] ) as $size ) {
				$items = Db::rows( 'SELECT * FROM ' . Db::t( 'box_item' ) . " WHERE week_id = %d AND share_id = %d AND size_id = %d AND role = 'box' ORDER BY id", array( $week['id'], $share['id'], $size['id'] ) );
				if ( ! $items ) {
					continue;
				}
				$html .= '<!-- wp:heading {"level":3} --><h3>' . esc_html( $share['name'] . ', ' . $size['name'] ) . '</h3><!-- /wp:heading --><!-- wp:list --><ul>';
				foreach ( $items as $item ) {
					$crop  = Db::get( 'crop', (int) $item['crop_id'] );
					$from  = $crop && '' !== $crop['grown_at'] ? ' (' . sprintf( /* translators: %s: farm. */ __( 'grown at %s', 'boxday' ), $crop['grown_at'] ) . ')' : '';
					$html .= '<li>' . esc_html( self::item_name( $item ) . ', ' . self::amount( $item ) . $from ) . '</li>';
				}
				$html .= '</ul><!-- /wp:list -->';
			}
		}
		$found = get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => 'any',
				'meta_key'    => '_boxday_week', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One lookup on publish.
				'meta_value'  => (int) $week['id'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One lookup on publish.
				'fields'      => 'ids',
			)
		);
		$days  = array_map( 'intval', array_column( Seasons::sites( (int) $week['season_id'] ), 'weekday' ) );
		$post  = array(
			'post_title'   => sprintf( /* translators: %s: date. */ __( 'What\'s in the box, %s', 'boxday' ), Format::date( Calendar::pickup_date( $week['start_date'], $days ? min( $days ) : 1 ), 'F j' ) ),
			'post_content' => $html,
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'meta_input'   => array( '_boxday_week' => (int) $week['id'] ),
		);
		if ( $found ) {
			$post['ID'] = (int) $found[0];
			return (int) wp_update_post( $post );
		}
		return (int) wp_insert_post( $post );
	}

	/**
	 * The newest published week across seasons.
	 *
	 * @return array|null
	 */
	public static function latest_published() {
		return Db::row( 'SELECT * FROM ' . Db::t( 'week' ) . ' WHERE published_at IS NOT NULL ORDER BY start_date DESC LIMIT 1' );
	}

	/**
	 * Formats a quantity and unit, such as 2 bunches.
	 *
	 * @param array $item Item row.
	 * @return string
	 */
	public static function amount( $item ) {
		$qty  = rtrim( rtrim( number_format( (float) $item['qty'], 2, '.', '' ), '0' ), '.' );
		$unit = $item['unit'];
		if ( (float) $item['qty'] > 1 && '' !== $unit && 'lb' !== $unit && 0 === strpos( determine_locale(), 'en' ) ) {
			$unit = preg_match( '/(s|x|ch|sh)$/', $unit ) ? $unit . 'es' : $unit . 's';
		}
		return trim( $qty . ' ' . $unit );
	}
}
