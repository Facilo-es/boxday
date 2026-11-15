<?php
/**
 * F19: WordPress's personal data exporter and eraser, and the privacy policy text.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * Members' rights over their data. The farm is the controller.
 */
class Privacy {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy' ) );
	}

	/**
	 * Registers our exporter.
	 *
	 * @param array $items Exporters.
	 * @return array
	 */
	public static function exporters( $items ) {
		$items['boxday'] = array(
			'exporter_friendly_name' => __( 'BoxDay farm share', 'boxday' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $items;
	}

	/**
	 * Registers our eraser.
	 *
	 * @param array $items Erasers.
	 * @return array
	 */
	public static function erasers( $items ) {
		$items['boxday'] = array(
			'eraser_friendly_name' => __( 'BoxDay farm share', 'boxday' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $items;
	}

	/**
	 * Exports a member's rows: details, sign-ups, weeks, swaps and waitlist.
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public static function export( $email ) {
		$email  = strtolower( $email );
		$items  = array();
		$member = Db::row( 'SELECT * FROM ' . Db::t( 'member' ) . ' WHERE email = %s', array( $email ) );
		if ( $member ) {
			$data = array();
			foreach ( array( 'email', 'first_name', 'last_name', 'phone', 'street', 'city', 'zip', 'notes' ) as $key ) {
				$data[] = array(
					'name'  => $key,
					'value' => (string) $member[ $key ],
				);
			}
			$items[] = array(
				'group_id'    => 'boxday-member',
				'group_label' => __( 'Farm share member', 'boxday' ),
				'item_id'     => 'boxday-member-' . $member['id'],
				'data'        => $data,
			);
			foreach ( Db::rows( 'SELECT s.*, sh.name AS share, z.name AS size, si.name AS site FROM ' . Db::t( 'signup' ) . ' s JOIN ' . Db::t( 'share' ) . ' sh ON sh.id = s.share_id JOIN ' . Db::t( 'size' ) . ' z ON z.id = s.size_id JOIN ' . Db::t( 'site' ) . ' si ON si.id = s.site_id WHERE s.member_id = %d', array( $member['id'] ) ) as $signup ) {
				$weeks = Db::rows( 'SELECT w.start_date, sw.skipped, sw.picked_up_at FROM ' . Db::t( 'signup_week' ) . ' sw JOIN ' . Db::t( 'week' ) . ' w ON w.id = sw.week_id WHERE sw.signup_id = %d', array( $signup['id'] ) );
				$notes = array();
				foreach ( $weeks as $week ) {
					$notes[] = $week['start_date'] . ': ' . ( $week['skipped'] ? $week['skipped'] : '' ) . ( $week['picked_up_at'] ? ' picked up' : '' );
				}
				$swaps   = (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'swap' ) . ' WHERE signup_id = %d', array( $signup['id'] ) );
				$items[] = array(
					'group_id'    => 'boxday-signup',
					'group_label' => __( 'Farm share sign-ups', 'boxday' ),
					'item_id'     => 'boxday-signup-' . $signup['id'],
					'data'        => array(
						array(
							'name'  => 'share',
							'value' => $signup['share'] . ', ' . $signup['size'],
						),
						array(
							'name'  => 'site',
							'value' => $signup['site'],
						),
						array(
							'name'  => 'status',
							'value' => $signup['status'] . ', ' . $signup['pay_state'],
						),
						array(
							'name'  => 'agreed_at',
							'value' => (string) $signup['agreed_at'],
						),
						array(
							'name'  => 'weeks',
							'value' => implode( '; ', $notes ),
						),
						array(
							'name'  => 'swaps',
							'value' => (string) $swaps,
						),
					),
				);
			}
		}
		foreach ( Db::rows( 'SELECT * FROM ' . Db::t( 'waitlist' ) . ' WHERE email = %s', array( $email ) ) as $row ) {
			$items[] = array(
				'group_id'    => 'boxday-waitlist',
				'group_label' => __( 'Farm share waitlist', 'boxday' ),
				'item_id'     => 'boxday-waitlist-' . $row['id'],
				'data'        => array(
					array(
						'name'  => 'name',
						'value' => trim( $row['first_name'] . ' ' . $row['last_name'] ),
					),
					array(
						'name'  => 'phone',
						'value' => $row['phone'],
					),
					array(
						'name'  => 'status',
						'value' => $row['status'],
					),
					array(
						'name'  => 'joined',
						'value' => $row['created_at'],
					),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Erases a member: names and contact go, sign-ups stay without them, so totals hold.
	 * Orders stay under WooCommerce's own rules.
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public static function erase( $email ) {
		$email   = strtolower( $email );
		$removed = false;
		$member  = Db::row( 'SELECT * FROM ' . Db::t( 'member' ) . ' WHERE email = %s', array( $email ) );
		if ( $member ) {
			Db::update(
				'member',
				array(
					'email'        => 'erased-' . $member['id'] . '@invalid',
					'first_name'   => '',
					'last_name'    => __( '(erased)', 'boxday' ),
					'phone'        => '',
					'street'       => '',
					'city'         => '',
					'zip'          => '',
					'notes'        => '',
					'user_id'      => 0,
					'link_version' => (int) $member['link_version'] + 1,
				),
				array( 'id' => $member['id'] )
			);
			$removed = true;
		}
		$rows = Db::col( 'SELECT id FROM ' . Db::t( 'waitlist' ) . ' WHERE email = %s', array( $email ) );
		foreach ( $rows as $id ) {
			Db::delete( 'waitlist', array( 'id' => (int) $id ) );
			$removed = true;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Suggested text for the privacy policy page.
	 */
	public static function policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . __( 'When you join our farm share, we keep your name, email, phone, delivery address if you chose delivery, your share, pickup place and schedule, the weeks you skip or swap, and when you agreed to our member agreement. We use these to pack and hand out your box and to email you about it.', 'boxday' ) . '</p>'
			. '<p>' . __( 'If you join our waitlist, we keep your name, email, phone and the share you asked for, to offer you a place.', 'boxday' ) . '</p>'
			. '<p>' . __( 'Payments go through our normal checkout. We never see or store your card. Pickup helpers see only your name, box and swaps.', 'boxday' ) . '</p>';
		wp_add_privacy_policy_content( 'BoxDay', wp_kses_post( $text ) );
	}
}
