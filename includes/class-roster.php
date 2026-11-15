<?php
/**
 * F7: members added by hand, marked paid, imported from a spreadsheet, and welcomed.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * The owner's member tools.
 */
class Roster {

	const MAX_ROWS = 5000;

	/**
	 * How a hand-added member pays, as WooCommerce payment methods.
	 *
	 * @return array Key to label.
	 */
	public static function pay_ways() {
		return array(
			'cheque' => __( 'Check', 'boxday' ),
			'cod'    => __( 'Cash', 'boxday' ),
			'other'  => __( 'Other', 'boxday' ),
		);
	}

	/**
	 * Adds a member by hand as a real order on hold, so all the money stays in one place.
	 *
	 * @param array $in season_id, size_id, site_id, schedule, first_name, last_name, email, phone, street, zip, pay.
	 * @return int|\WP_Error Order id.
	 */
	public static function add_by_hand( $in ) {
		if ( ! is_email( $in['email'] ) ) {
			return new \WP_Error( 'boxday_email', __( 'Please enter a valid email.', 'boxday' ) );
		}
		$data  = array(
			'gid'       => wp_generate_uuid4(),
			'idx'       => 0,
			'season_id' => (int) $in['season_id'],
			'size_id'   => (int) $in['size_id'],
			'site_id'   => (int) $in['site_id'],
			'schedule'  => 'b' === $in['schedule'] ? 'b' : 'w',
			'addons'    => array(),
			'plan'      => 'full',
			'zip'       => (string) $in['zip'],
			'street'    => (string) $in['street'],
			'agreed'    => 0,
			'offer'     => '',
		);
		$quote = Quote::build( $data );
		if ( is_wp_error( $quote ) ) {
			return $quote;
		}
		$line  = $quote['lines'][0];
		$order = wc_create_order(
			array(
				'customer_id' => 0,
				'created_via' => 'boxday',
			)
		);
		$item  = new \WC_Order_Item_Product();
		$item->set_props(
			array(
				'product_id' => (int) $line['share']['product_id'],
				'quantity'   => 1,
				'subtotal'   => $line['today'],
				'total'      => $line['today'],
				'name'       => $quote['season']['name'] . ': ' . $line['share']['name'] . ', ' . $line['size']['name'],
			)
		);
		foreach ( Cart::line_labels( $data ) as $label => $value ) {
			$item->add_meta_data( $label, $value, true );
		}
		$item->add_meta_data( '_boxday', wp_json_encode( Cart::line_data( $quote, $data ) ), true );
		$order->add_item( $item );
		$order->set_billing_first_name( $in['first_name'] );
		$order->set_billing_last_name( $in['last_name'] );
		$order->set_billing_email( $in['email'] );
		$order->set_billing_phone( $in['phone'] );
		$ways = self::pay_ways();
		$way  = isset( $ways[ $in['pay'] ] ) ? $in['pay'] : 'cheque';
		$order->set_payment_method( $way );
		$order->set_payment_method_title( $ways[ $way ] );
		$order->calculate_totals( false );
		$order->save();
		Signups::from_order( $order );
		$order->update_status( 'on-hold', __( 'Added by hand in BoxDay.', 'boxday' ) );
		return $order->get_id();
	}

	/**
	 * Mark paid: completes the order behind a hand-added sign-up.
	 *
	 * @param int $signup_id Sign-up.
	 * @return bool
	 */
	public static function mark_paid( $signup_id ) {
		$signup = Db::get( 'signup', $signup_id );
		$order  = $signup && $signup['order_id'] ? wc_get_order( (int) $signup['order_id'] ) : null;
		if ( ! $order || ! $order->has_status( array( 'on-hold', 'pending' ) ) ) {
			return false;
		}
		$order->payment_complete();
		if ( ! $order->has_status( 'completed' ) ) {
			$order->update_status( 'completed', __( 'Marked paid in BoxDay.', 'boxday' ) );
		}
		Signups::log( $signup_id, 'owner', 'paid' );
		return true;
	}

	/**
	 * The CSV's columns, in order.
	 *
	 * @return array
	 */
	public static function columns() {
		return array( 'email', 'first_name', 'last_name', 'phone', 'share', 'size', 'site', 'schedule', 'note' );
	}

	/**
	 * Reads a CSV file into rows, in UTF-8 or Windows-1252.
	 *
	 * @param string $path File.
	 * @return array Rows of cells.
	 */
	public static function read_csv( $path ) {
		$text = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The owner's own upload.
		$text = preg_replace( '/^\xEF\xBB\xBF/', '', $text );
		if ( ! preg_match( '//u', $text ) ) {
			$text = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' ) : (string) iconv( 'Windows-1252', 'UTF-8//IGNORE', $text );
		}
		$lines = preg_split( '/\r\n|\r|\n/', $text );
		$sep   = isset( $lines[0] ) && substr_count( $lines[0], ';' ) > substr_count( $lines[0], ',' ) ? ';' : ',';
		$rows  = array();
		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$rows[] = array_map( 'trim', str_getcsv( $line, $sep ) );
			if ( count( $rows ) > self::MAX_ROWS + 1 ) {
				break;
			}
		}
		return $rows;
	}

	/**
	 * Checks every row against the season. Bad rows come back with the reason.
	 *
	 * @param array $rows      Rows of cells, header first if it has one.
	 * @param int   $season_id Season.
	 * @return array good, bad.
	 */
	public static function check( $rows, $season_id ) {
		if ( $rows && false !== stripos( implode( ',', $rows[0] ), 'email' ) ) {
			array_shift( $rows );
		}
		$shares = array();
		foreach ( Seasons::shares( $season_id ) as $share ) {
			$shares[ strtolower( $share['name'] ) ] = $share;
		}
		$sites = array();
		foreach ( Seasons::sites( $season_id ) as $site ) {
			$sites[ strtolower( $site['name'] ) ] = $site;
		}
		$out = array(
			'good' => array(),
			'bad'  => array(),
		);
		foreach ( array_slice( $rows, 0, self::MAX_ROWS ) as $n => $cells ) {
			$cells = array_pad( $cells, 9, '' );
			$row   = array_combine( self::columns(), array_slice( $cells, 0, 9 ) );
			$why   = '';
			$share = isset( $shares[ strtolower( $row['share'] ) ] ) ? $shares[ strtolower( $row['share'] ) ] : null;
			$size  = null;
			if ( $share ) {
				foreach ( Seasons::sizes( (int) $share['id'] ) as $candidate ) {
					if ( 0 === strcasecmp( $candidate['name'], $row['size'] ) ) {
						$size = $candidate;
					}
				}
			}
			$site = isset( $sites[ strtolower( $row['site'] ) ] ) ? $sites[ strtolower( $row['site'] ) ] : null;
			if ( ! is_email( $row['email'] ) ) {
				$why = __( 'The email isn\'t valid.', 'boxday' );
			} elseif ( ! $share ) {
				/* translators: %s: share name from the file. */
				$why = sprintf( __( 'There\'s no share called "%s" in this season.', 'boxday' ), $row['share'] );
			} elseif ( ! $size ) {
				/* translators: %s: size name from the file. */
				$why = sprintf( __( 'There\'s no size called "%s" for that share.', 'boxday' ), $row['size'] );
			} elseif ( ! $site ) {
				/* translators: %s: site name from the file. */
				$why = sprintf( __( 'There\'s no pickup place called "%s" in this season.', 'boxday' ), $row['site'] );
			}
			$row['line'] = $n + 1;
			if ( '' !== $why ) {
				$row['why']   = $why;
				$out['bad'][] = $row;
				continue;
			}
			$row['schedule'] = preg_match( '/other|bi|b$|2/i', $row['schedule'] ) ? 'b' : 'w';
			$row['share_id'] = (int) $share['id'];
			$row['size_id']  = (int) $size['id'];
			$row['site_id']  = (int) $site['id'];
			$out['good'][]   = $row;
		}
		return $out;
	}

	/**
	 * Imports checked rows. The key is email, season and share, so the same file twice changes nothing.
	 * No orders, no emails: members show as paid elsewhere until the owner sends welcomes.
	 *
	 * @param array $good      Checked rows.
	 * @param int   $season_id Season.
	 * @return array added, changed.
	 */
	public static function import( $good, $season_id ) {
		$season = Seasons::get( $season_id );
		$weeks  = Seasons::weeks( $season_id );
		$counts = array(
			'added'   => 0,
			'changed' => 0,
		);
		foreach ( $good as $row ) {
			$member_id = Members::upsert(
				array(
					'email'      => $row['email'],
					'first_name' => sanitize_text_field( $row['first_name'] ),
					'last_name'  => sanitize_text_field( $row['last_name'] ),
					'phone'      => sanitize_text_field( $row['phone'] ),
				),
				true
			);
			$member    = Db::get( 'member', $member_id );
			if ( '' !== $row['note'] && false === strpos( (string) $member['notes'], $row['note'] ) ) {
				Db::update( 'member', array( 'notes' => trim( $member['notes'] . "\n" . sanitize_text_field( $row['note'] ) ) ), array( 'id' => $member_id ) );
			}
			$old  = Db::row( 'SELECT * FROM ' . Db::t( 'signup' ) . " WHERE member_id = %d AND season_id = %d AND share_id = %d AND status <> 'cancelled'", array( $member_id, $season_id, $row['share_id'] ) );
			$site = Db::get( 'site', $row['site_id'] );
			if ( $old ) {
				$same = (int) $old['size_id'] === $row['size_id'] && (int) $old['site_id'] === $row['site_id'] && $old['schedule'] === $row['schedule'];
				if ( ! $same ) {
					Db::update(
						'signup',
						array(
							'size_id'  => $row['size_id'],
							'site_id'  => $row['site_id'],
							'schedule' => $row['schedule'],
						),
						array( 'id' => $old['id'] )
					);
					++$counts['changed'];
				}
				continue;
			}
			$grp = '';
			if ( 'b' === $row['schedule'] ) {
				$c   = Capacity::site_counts( $season_id, $row['site_id'], $row['share_id'] );
				$grp = Capacity::smaller_group( $c['A'], $c['B'] );
			}
			$boxes = Calendar::boxes_left( $season, $site, $weeks, $row['schedule'], $grp, new \DateTimeImmutable( '1970-01-02', wp_timezone() ) );
			$id    = Db::insert(
				'signup',
				array(
					'member_id'     => $member_id,
					'season_id'     => $season_id,
					'share_id'      => $row['share_id'],
					'size_id'       => $row['size_id'],
					'site_id'       => $row['site_id'],
					'schedule'      => $row['schedule'],
					'grp'           => $grp,
					'status'        => 'active',
					'pay_state'     => 'elsewhere',
					'boxes_paid'    => $boxes['total'],
					'first_week_id' => $boxes['first'] ? (int) $boxes['first']['id'] : 0,
					'created_at'    => Clock::mysql(),
				)
			);
			Signups::log( $id, 'owner', 'import' );
			++$counts['added'];
		}
		return $counts;
	}

	/**
	 * Imported members not yet welcomed.
	 *
	 * @param int $season_id Season.
	 * @return array Sign-up rows.
	 */
	public static function unwelcomed( $season_id ) {
		$log = Db::t( 'log' );
		return Db::rows(
			'SELECT s.* FROM ' . Db::t( 'signup' ) . " s WHERE s.season_id = %d AND s.status = 'active' AND s.order_id = 0 AND s.is_sample = 0
			AND NOT EXISTS ( SELECT 1 FROM {$log} l WHERE l.signup_id = s.id AND l.action = 'welcome' ) ORDER BY s.id",
			array( $season_id )
		);
	}

	/**
	 * "Send welcome emails": once per imported sign-up, never twice.
	 *
	 * @param int $season_id Season.
	 * @return int Emails sent.
	 */
	public static function send_welcomes( $season_id ) {
		$sent = 0;
		foreach ( self::unwelcomed( $season_id ) as $signup ) {
			$member = Db::get( 'member', (int) $signup['member_id'] );
			if ( $member ) {
				Emails::welcome_signup( $member, $signup );
				Signups::log( (int) $signup['id'], 'owner', 'welcome' );
				++$sent;
			}
		}
		return $sent;
	}
}
