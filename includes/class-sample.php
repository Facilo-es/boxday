<?php
/**
 * The sample farm: one click in, one click out, and it can't touch real records.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * Loads and removes made-up data.
 */
class Sample {

	/**
	 * Whether the sample farm is loaded.
	 *
	 * @return bool
	 */
	public static function loaded() {
		return (bool) get_option( 'boxday_sample' );
	}

	/**
	 * `wp boxday sample [load|remove]`.
	 *
	 * @param array $args Arguments.
	 */
	public static function cli( $args ) {
		if ( isset( $args[0] ) && 'remove' === $args[0] ) {
			self::remove();
			\WP_CLI::success( 'Sample farm removed.' );
			return;
		}
		self::load();
		\WP_CLI::success( 'Sample farm loaded.' );
	}

	/**
	 * The coming summer, starting on the Monday of the week of June 1.
	 *
	 * @return string Y-m-d.
	 */
	private static function first_week() {
		$today = Clock::today();
		$year  = (int) substr( $today, 0, 4 );
		return Calendar::monday_of( ( substr( $today, 5 ) > '05-01' ? $year + 1 : $year ) . '-06-01' );
	}

	/**
	 * Inserts the sample farm.
	 */
	public static function load() {
		if ( self::loaded() ) {
			return;
		}
		Pages::create();
		$first = self::first_week();
		$year  = substr( $first, 0, 4 );
		$prior = (string) ( (int) $year - 1 );
		$open  = min( $prior . '-12-01 08:00:00', Clock::now()->format( 'Y-m-d H:i:s' ) );

		$season = Db::insert(
			'season',
			array(
				'name'              => sprintf( /* translators: %s: year. */ __( 'Summer %s', 'boxday' ), $year ),
				'status'            => 'live',
				'first_week'        => $first,
				'weeks'             => 22,
				'signup_open'       => $open,
				'signup_close'      => $year . '-08-01 20:00:00',
				'cutoff_days'       => 2,
				'cutoff_time'       => '20:00:00',
				'skip_rule'         => 'makeup',
				'max_skips'         => 2,
				'makeup_weeks'      => 2,
				'plan_mode'         => 'parts',
				'deposit_type'      => 'amount',
				'deposit_value'     => 150,
				'plan_dates'        => wp_json_encode( array( $year . '-03-01', $year . '-04-01', $year . '-05-01' ) ),
				'agreement_text'    => __( 'We share the season. When you join, you share both the good and the hard parts of farming with us. Weather, pests and crop failures can change what\'s in your box and how much you get.', 'boxday' ),
				'agreement_version' => 1,
				'is_sample'         => 1,
			)
		);
		Seasons::build_weeks( $season, array( 6 ) );
		$weeks = Seasons::weeks( $season );

		$veg   = self::share( $season, __( 'Vegetable share', 'boxday' ), 'main', '', 0 );
		$full  = self::size( $veg, __( 'Full', 'boxday' ), __( 'Feeds 3 to 4 people', 'boxday' ), 650, 340 );
		$small = self::size( $veg, __( 'Small', 'boxday' ), __( 'Feeds 1 to 2 people', 'boxday' ), 450, 240 );
		$eggs  = self::share( $season, __( 'Eggs', 'boxday' ), 'addon', __( 'A dozen each box', 'boxday' ), 1 );
		self::size( $eggs, __( 'Eggs', 'boxday' ), '', 126, 66 );
		$flowers = self::share( $season, __( 'Flowers', 'boxday' ), 'addon', __( 'A bunch each box', 'boxday' ), 2 );
		self::size( $flowers, __( 'Flowers', 'boxday' ), '', 168, 88 );

		$stand  = self::site( $season, 'pickup', __( 'Farm Stand', 'boxday' ), 2, '16:00:00', '19:00:00', null, '', 0 );
		$market = self::site( $season, 'pickup', __( 'Market', 'boxday' ), 6, '09:00:00', '12:00:00', 16, '', 0 );
		self::site( $season, 'delivery', __( 'Home delivery', 'boxday' ), 3, '15:00:00', '18:00:00', null, '37', 5 );

		$people = array(
			array( 'Maya', 'Jensen', $full, 'w', '', $stand, 'paid', true ),
			array( 'Chidi', 'Abara', $full, 'w', '', $stand, 'paid' ),
			array( 'Laura', 'Bell', $small, 'w', '', $stand, 'paid' ),
			array( 'Marisol', 'Castillo', $full, 'w', '', $market, 'paid' ),
			array( 'Thuy', 'Dang', $full, 'b', 'A', $stand, 'elsewhere' ),
			array( 'Paul', 'Eriksen', $small, 'b', 'B', $stand, 'elsewhere' ),
			array( 'Naomi', 'Feld', $full, 'w', '', $market, 'paid' ),
			array( 'Oren', 'Grant', $full, 'w', '', $stand, 'paid' ),
			array( 'Sami', 'Haddad', $small, 'w', '', $market, 'paid' ),
			array( 'Keiko', 'Ito', $full, 'b', 'A', $market, 'elsewhere' ),
			array( 'Dan', 'Kowalski', $small, 'b', 'B', $market, 'paid' ),
			array( 'Rosa', 'Peña', $full, 'w', '', $stand, 'paid' ),
		);
		foreach ( $people as $i => $person ) {
			$member = Db::insert(
				'member',
				array(
					'email'      => 'member' . ( $i + 1 ) . '@sample.example',
					'first_name' => $person[0],
					'last_name'  => $person[1],
					'is_sample'  => 1,
				)
			);
			$signup = Db::insert(
				'signup',
				array(
					'member_id'     => $member,
					'season_id'     => $season,
					'share_id'      => $veg,
					'size_id'       => $person[2],
					'site_id'       => $person[5],
					'schedule'      => $person[3],
					'grp'           => $person[4],
					'status'        => 'active',
					'pay_state'     => $person[6],
					'price_total'   => 'b' === $person[3] ? 340 : 650,
					'boxes_paid'    => 'b' === $person[3] ? 11 : 21,
					'first_week_id' => 0,
					'created_at'    => Clock::mysql(),
					'is_sample'     => 1,
				)
			);
			if ( ! empty( $person[7] ) ) {
				foreach ( array( '03-01', '04-01', '05-01' ) as $n => $date ) {
					Db::insert(
						'instalment',
						array(
							'signup_id' => $signup,
							'due_date'  => $year . '-' . $date,
							'amount'    => 2 === $n ? 166.68 : 166.66,
							'state'     => 'paid',
							'is_sample' => 1,
						)
					);
				}
			}
		}

		$crop_rows = array(
			array( __( 'Tomatoes', 'boxday' ), 'lb', 3, 2, 0 ),
			array( __( 'Carrots', 'boxday' ), 'bunch', 2, 1, 1 ),
			array( __( 'Lettuce', 'boxday' ), 'head', 2, 1, 1 ),
			array( __( 'Kale', 'boxday' ), 'bunch', 1, 1, 1 ),
		);
		foreach ( array( 0, 1, 2 ) as $w ) {
			foreach ( $crop_rows as $crop ) {
				self::item( $weeks[ $w ], $veg, $full, 'box', $crop[0], $crop[2], $crop[1], $crop[4], null );
				self::item( $weeks[ $w ], $veg, $small, 'box', $crop[0], $crop[3], $crop[1], $crop[4], null );
			}
			self::item( $weeks[ $w ], $veg, 0, 'swap', __( 'Chard', 'boxday' ), 1, 'bunch', 0, 18 );
			self::item( $weeks[ $w ], $veg, 0, 'swap', __( 'Beets', 'boxday' ), 1, 'bunch', 0, null );
			Db::update(
				'week',
				array(
					'published_at' => Clock::mysql(),
					'message'      => __( 'First tomatoes of the year! The kale loved all that rain.', 'boxday' ),
				),
				array( 'id' => $weeks[ $w ]['id'] )
			);
		}
		update_option( 'boxday_sample', $season );
	}

	/**
	 * Inserts a share and its carrier.
	 *
	 * @param int    $season Season.
	 * @param string $name   Name.
	 * @param string $kind   `main` or `addon`.
	 * @param string $about  Description.
	 * @param int    $sort   Order.
	 * @return int
	 */
	private static function share( $season, $name, $kind, $about, $sort ) {
		return Db::insert(
			'share',
			array(
				'season_id'   => $season,
				'name'        => $name,
				'description' => $about,
				'kind'        => $kind,
				'sort'        => $sort,
				'is_sample'   => 1,
			)
		);
	}

	/**
	 * Inserts a size and refreshes the carrier's lowest price.
	 *
	 * @param int    $share  Share.
	 * @param string $name   Name.
	 * @param string $note   Short note.
	 * @param float  $weekly Weekly price.
	 * @param float  $every  Every-other-week price.
	 * @return int
	 */
	private static function size( $share, $name, $note, $weekly, $every ) {
		$id = Db::insert(
			'size',
			array(
				'share_id'       => $share,
				'name'           => $name,
				'note'           => $note,
				'price_weekly'   => $weekly,
				'price_biweekly' => $every,
				'is_sample'      => 1,
			)
		);
		Carrier::ensure( $share );
		return $id;
	}

	/**
	 * Inserts a site.
	 *
	 * @param int      $season   Season.
	 * @param string   $type     `pickup` or `delivery`.
	 * @param string   $name     Name.
	 * @param int      $day      Weekday.
	 * @param string   $from     Start.
	 * @param string   $to       End.
	 * @param int|null $capacity Capacity.
	 * @param string   $zips     ZIP prefixes.
	 * @param float    $fee      Fee per box.
	 * @return int
	 */
	private static function site( $season, $type, $name, $day, $from, $to, $capacity, $zips, $fee ) {
		return Db::insert(
			'site',
			array(
				'season_id'    => $season,
				'type'         => $type,
				'name'         => $name,
				'weekday'      => $day,
				'time_from'    => $from,
				'time_to'      => $to,
				'capacity'     => $capacity,
				'zip_prefixes' => $zips,
				'fee_per_box'  => $fee,
				'is_sample'    => 1,
			)
		);
	}

	/**
	 * Inserts a box item.
	 *
	 * @param array    $week      Week row.
	 * @param int      $share     Share.
	 * @param int      $size      Size.
	 * @param string   $role      `box` or `swap`.
	 * @param string   $name      Crop name.
	 * @param float    $qty       Quantity.
	 * @param string   $unit      Unit.
	 * @param int      $swappable Can be swapped.
	 * @param int|null $available Number available.
	 */
	private static function item( $week, $share, $size, $role, $name, $qty, $unit, $swappable, $available ) {
		Db::insert(
			'box_item',
			array(
				'week_id'   => $week['id'],
				'share_id'  => $share,
				'size_id'   => $size,
				'role'      => $role,
				'crop_id'   => Boxes::crop( $name, $unit, '', 1 ),
				'qty'       => $qty,
				'unit'      => $unit,
				'swappable' => $swappable,
				'available' => $available,
				'is_sample' => 1,
			)
		);
	}

	/**
	 * Removes every sample row, and sign-ups made on the sample season. Real orders stay.
	 */
	public static function remove() {
		foreach ( Db::rows( 'SELECT * FROM ' . Db::t( 'share' ) . ' WHERE is_sample = 1' ) as $share ) {
			if ( $share['product_id'] ) {
				wp_delete_post( (int) $share['product_id'], true );
			}
		}
		$season = (int) get_option( 'boxday_sample' );
		if ( $season ) {
			$extra = Db::col( 'SELECT id FROM ' . Db::t( 'signup' ) . ' WHERE season_id = %d', array( $season ) );
			foreach ( $extra as $id ) {
				Db::update( 'signup', array( 'is_sample' => 1 ), array( 'id' => $id ) );
				Db::update( 'instalment', array( 'is_sample' => 1 ), array( 'signup_id' => $id ) );
				Db::delete( 'signup_week', array( 'signup_id' => $id ) );
				Db::delete( 'swap', array( 'signup_id' => $id ) );
			}
		}
		foreach ( array( 'instalment', 'signup_week', 'swap', 'box_item', 'crop', 'signup', 'member', 'site', 'size', 'share', 'week', 'season' ) as $table ) {
			Db::delete( $table, array( 'is_sample' => 1 ) );
		}
		delete_option( 'boxday_sample' );
	}
}
