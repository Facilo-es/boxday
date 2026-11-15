<?php
/**
 * The admin screens. Every action needs manage_woocommerce and a nonce.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Screens read only which season, week or member to show.

/**
 * Menu, actions and shared parts.
 */
class Admin {

	const CAP = 'manage_woocommerce';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_boxday', array( __CLASS__, 'action' ) );
		add_action( 'admin_post_boxday_print', array( Admin_Print::class, 'route' ) );
	}

	/**
	 * The BoxDay menu.
	 */
	public static function menu() {
		add_menu_page( __( 'BoxDay', 'boxday' ), __( 'BoxDay', 'boxday' ), self::CAP, 'boxday', array( Admin_Screens::class, 'today' ), 'dashicons-carrot', 56 );
		$pages = array(
			'boxday'          => array( __( 'Today', 'boxday' ), 'today' ),
			'boxday-seasons'  => array( __( 'Seasons', 'boxday' ), 'seasons' ),
			'boxday-planner'  => array( __( 'Box planner', 'boxday' ), 'planner' ),
			'boxday-members'  => array( __( 'Members', 'boxday' ), 'members' ),
			'boxday-sheets'   => array( __( 'Sheets', 'boxday' ), 'sheets' ),
			'boxday-settings' => array( __( 'Settings', 'boxday' ), 'settings' ),
			'boxday-pro'      => array( __( 'Pro', 'boxday' ), 'pro' ),
		);
		/**
		 * Filters the BoxDay admin tabs.
		 *
		 * @param array $pages Slug to array( title, screen ). The screen is an Admin_Screens method name or a callable array.
		 */
		$pages = apply_filters( 'boxday_admin_pages', $pages );
		foreach ( $pages as $slug => $page ) {
			add_submenu_page( 'boxday', $page[0], $page[0], self::CAP, $slug, is_array( $page[1] ) ? $page[1] : array( Admin_Screens::class, $page[1] ) );
		}
		add_submenu_page( '', __( 'Setup', 'boxday' ), __( 'Setup', 'boxday' ), self::CAP, 'boxday-setup', array( Admin_Setup::class, 'screen' ) );
	}

	/**
	 * Our admin files, on our screens only.
	 *
	 * @param string $hook Screen.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( $hook, 'boxday' ) ) {
			return;
		}
		wp_enqueue_style( 'boxday-admin', BOXDAY_URL . 'assets/admin.css', array(), BOXDAY_VERSION );
		wp_enqueue_script( 'boxday-admin', BOXDAY_URL . 'assets/admin.js', array(), BOXDAY_VERSION, true );
	}

	/**
	 * The season the screens work on.
	 *
	 * @return array|null
	 */
	public static function current_season() {
		$id = isset( $_GET['season'] ) ? absint( $_GET['season'] ) : 0;
		if ( $id ) {
			return Seasons::get( $id );
		}
		$all = Seasons::all();
		foreach ( $all as $season ) {
			if ( 'live' === $season['status'] ) {
				return $season;
			}
		}
		return $all ? $all[0] : null;
	}

	/**
	 * A nonced URL for an action.
	 *
	 * @param string $task Action.
	 * @param array  $args More arguments.
	 * @return string
	 */
	public static function action_url( $task, $args = array() ) {
		return wp_nonce_url(
			add_query_arg(
				array_merge(
					array(
						'action' => 'boxday',
						'do'     => $task,
					),
					$args
				),
				admin_url( 'admin-post.php' )
			),
			'boxday_' . $task
		);
	}

	/**
	 * Opens a form for an action.
	 *
	 * @param string $task Action.
	 */
	public static function form_open( $task ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="boxday"><input type="hidden" name="do" value="' . esc_attr( $task ) . '">';
		wp_nonce_field( 'boxday_' . $task );
	}

	/**
	 * Back to a screen.
	 *
	 * @param string $page Admin page slug.
	 * @param array  $args Arguments.
	 */
	private static function back( $page, $args = array() ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Runs one admin action.
	 */
	public static function action() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'boxday' ), 403 );
		}
		$do = isset( $_REQUEST['do'] ) ? sanitize_key( $_REQUEST['do'] ) : '';
		check_admin_referer( 'boxday_' . $do );
		$in = wp_unslash( $_REQUEST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized where it's read.
		switch ( $do ) {
			case 'sample_load':
				Sample::load();
				self::back( 'boxday' );
				break;
			case 'sample_remove':
				Sample::remove();
				self::back( 'boxday-settings' );
				break;
			case 'pages':
				Pages::create();
				self::back( 'boxday-settings' );
				break;
			case 'settings':
				self::save_settings( $in );
				break;
			case 'season':
				self::save_season( $in );
				break;
			case 'season_copy':
				$id = Seasons::copy( absint( $in['season'] ) );
				self::back( 'boxday-seasons', array( 'edit' => $id ) );
				break;
			case 'planner':
				self::save_planner( $in );
				break;
			case 'publish':
				$week = Db::get( 'week', absint( $in['week'] ) );
				Boxes::publish( $week );
				self::back(
					'boxday-planner',
					array(
						'week'      => $week['id'],
						'published' => 1,
					)
				);
				break;
			case 'member_cancel':
				Signups::cancel( absint( $in['signup'] ) );
				Signups::log( absint( $in['signup'] ), 'owner', 'cancel' );
				self::back( 'boxday-members', array( 'member' => absint( $in['member'] ) ) );
				break;
			case 'member_link':
				Emails::new_link( Db::get( 'member', absint( $in['member'] ) ) );
				self::back(
					'boxday-members',
					array(
						'member' => absint( $in['member'] ),
						'sent'   => 1,
					)
				);
				break;
			default:
				$back = Admin_Roster::action( $do, $in );
				if ( ! $back ) {
					$back = Admin_Setup::action( $do, $in );
				}
				if ( $back ) {
					wp_safe_redirect( add_query_arg( $back, admin_url( 'admin.php' ) ) );
					exit;
				}
		}
		self::back( 'boxday' );
	}

	/**
	 * Saves the farm's details and look.
	 *
	 * @param array $in Request.
	 */
	private static function save_settings( $in ) {
		$accent = sanitize_hex_color( isset( $in['accent'] ) ? $in['accent'] : '' );
		update_option(
			'boxday_look',
			array(
				'farm'   => sanitize_text_field( $in['farm'] ),
				'email'  => sanitize_email( $in['email'] ),
				'phone'  => sanitize_text_field( $in['phone'] ),
				'word'   => in_array( $in['word'], array( 'share', 'box', 'basket' ), true ) ? $in['word'] : 'share',
				'accent' => $accent ? $accent : '#2f6b3f',
				'radius' => min( 30, absint( $in['radius'] ) ),
				'caps'   => empty( $in['caps'] ) ? 0 : 1,
			)
		);
		self::back( 'boxday-settings', array( 'saved' => 1 ) );
	}

	/**
	 * A money field, or null when blank.
	 *
	 * @param array  $row Row.
	 * @param string $key Field.
	 * @return float|null
	 */
	private static function price( $row, $key ) {
		return ! isset( $row[ $key ] ) || '' === trim( (string) $row[ $key ] ) ? null : max( 0.0, (float) $row[ $key ] );
	}

	/**
	 * Saves a season with its closed weeks, shares, sizes and sites.
	 *
	 * @param array $in Request.
	 */
	private static function save_season( $in ) {
		$id     = isset( $in['id'] ) ? absint( $in['id'] ) : 0;
		$old    = $id ? Seasons::get( $id ) : null;
		$dates  = array_slice( array_values( array_filter( array_map( 'sanitize_text_field', (array) $in['plan_dates'] ) ) ), 0, Pricing::MAX_LATER_PAYMENTS );
		$first  = Calendar::monday_of( sanitize_text_field( $in['first_week'] ) );
		$taken  = $id && Db::value( 'SELECT id FROM ' . Db::t( 'signup' ) . ' WHERE season_id = %d LIMIT 1', array( $id ) );
		$text   = wp_kses_post( (string) $in['agreement_text'] );
		$rule   = in_array( $in['skip_rule'], array( 'skip', 'donate', 'makeup' ), true ) ? $in['skip_rule'] : 'skip';
		$skips  = absint( $in['max_skips'] );
		$makeup = absint( $in['makeup_weeks'] );
		$data   = array(
			'name'              => sanitize_text_field( $in['name'] ),
			'status'            => empty( $in['live'] ) ? 'draft' : 'live',
			'first_week'        => $taken ? $old['first_week'] : $first,
			'weeks'             => max( 1, min( 52, absint( $in['weeks'] ) ) ),
			'signup_open'       => sanitize_text_field( $in['signup_open'] ) ? str_replace( 'T', ' ', sanitize_text_field( $in['signup_open'] ) ) : null,
			'signup_close'      => sanitize_text_field( $in['signup_close'] ) ? str_replace( 'T', ' ', sanitize_text_field( $in['signup_close'] ) ) : null,
			'cutoff_days'       => absint( $in['cutoff_days'] ),
			'cutoff_time'       => sanitize_text_field( $in['cutoff_time'] ),
			'skip_rule'         => $rule,
			'donation_partner'  => sanitize_text_field( $in['donation_partner'] ),
			'max_skips'         => 'makeup' === $rule ? min( $skips, $makeup ) : $skips,
			'makeup_weeks'      => $makeup,
			'swaps_on'          => empty( $in['swaps_on'] ) ? 0 : 1,
			'swap_limit'        => absint( $in['swap_limit'] ),
			'site_change'       => in_array( $in['site_change'], array( 'never', 'once', 'both' ), true ) ? $in['site_change'] : 'both',
			'late_join'         => empty( $in['late_join'] ) ? 0 : 1,
			'prorate'           => empty( $in['prorate'] ) ? 0 : 1,
			'plan_mode'         => 'parts' === $in['plan_mode'] && $dates ? 'parts' : 'full',
			'deposit_type'      => 'percent' === $in['deposit_type'] ? 'percent' : 'amount',
			'deposit_value'     => max( 0.0, (float) $in['deposit_value'] ),
			'plan_dates'        => wp_json_encode( $dates ),
			'agreement_text'    => $text,
			'agreement_version' => '' === trim( $text ) ? 0 : ( $old && $old['agreement_text'] === $text ? (int) $old['agreement_version'] : ( $old ? (int) $old['agreement_version'] + 1 : 1 ) ),
		);
		if ( $id ) {
			Db::update( 'season', $data, array( 'id' => $id ) );
		} else {
			$id = Db::insert( 'season', $data );
		}
		Seasons::build_weeks( $id, array_map( 'intval', isset( $in['closed'] ) ? (array) $in['closed'] : array() ) );

		foreach ( isset( $in['shares'] ) ? (array) $in['shares'] : array() as $row ) {
			self::save_share( $id, $row );
		}
		foreach ( isset( $in['sites'] ) ? (array) $in['sites'] : array() as $row ) {
			self::save_site( $id, $row );
		}
		self::back( 'boxday-seasons', array( 'edit' => $id ) );
	}

	/**
	 * Saves one share and its sizes.
	 *
	 * @param int   $season_id Season.
	 * @param array $row       Fields.
	 */
	private static function save_share( $season_id, $row ) {
		$name = sanitize_text_field( $row['name'] );
		if ( '' === $name ) {
			return;
		}
		$capacity = '' === trim( (string) $row['capacity'] ) ? null : absint( $row['capacity'] );
		$data     = array(
			'season_id'   => $season_id,
			'name'        => $name,
			'description' => sanitize_text_field( $row['description'] ),
			'kind'        => 'addon' === $row['kind'] ? 'addon' : 'main',
			'capacity'    => $capacity,
			'price_mode'  => 'box' === $row['price_mode'] ? 'box' : 'season',
		);
		$share_id = absint( $row['id'] );
		if ( $share_id ) {
			Db::update( 'share', $data, array( 'id' => $share_id ) );
		} else {
			$share_id = Db::insert( 'share', $data );
		}
		foreach ( isset( $row['sizes'] ) ? (array) $row['sizes'] : array() as $size ) {
			$size_name = sanitize_text_field( $size['name'] );
			if ( '' === $size_name ) {
				continue;
			}
			$fields = array(
				'share_id'       => $share_id,
				'name'           => $size_name,
				'note'           => sanitize_text_field( $size['note'] ),
				'price_weekly'   => self::price( $size, 'weekly' ),
				'price_biweekly' => self::price( $size, 'biweekly' ),
			);
			if ( absint( $size['id'] ) ) {
				Db::update( 'size', $fields, array( 'id' => absint( $size['id'] ) ) );
			} else {
				Db::insert( 'size', $fields );
			}
		}
		Carrier::ensure( $share_id );
	}

	/**
	 * Saves one site.
	 *
	 * @param int   $season_id Season.
	 * @param array $row       Fields.
	 */
	private static function save_site( $season_id, $row ) {
		$name = sanitize_text_field( $row['name'] );
		if ( '' === $name ) {
			return;
		}
		$data = array(
			'season_id'    => $season_id,
			'name'         => $name,
			'type'         => 'delivery' === $row['type'] ? 'delivery' : 'pickup',
			'address'      => sanitize_text_field( $row['address'] ),
			'weekday'      => max( 1, min( 7, absint( $row['weekday'] ) ) ),
			'time_from'    => sanitize_text_field( $row['time_from'] ),
			'time_to'      => sanitize_text_field( $row['time_to'] ),
			'capacity'     => '' === trim( (string) $row['capacity'] ) ? null : absint( $row['capacity'] ),
			'note'         => sanitize_text_field( $row['note'] ),
			'zip_prefixes' => preg_replace( '/[^0-9, ]/', '', (string) $row['zip_prefixes'] ),
			'fee_per_box'  => max( 0.0, (float) $row['fee_per_box'] ),
		);
		if ( absint( $row['id'] ) ) {
			Db::update( 'site', $data, array( 'id' => absint( $row['id'] ) ) );
		} else {
			Db::insert( 'site', $data );
		}
	}

	/**
	 * Saves the planner, then runs the button that was pressed.
	 *
	 * @param array $in Request.
	 */
	private static function save_planner( $in ) {
		$week    = Db::get( 'week', absint( $in['week'] ) );
		$week_id = (int) $week['id'];
		$rows    = array();
		foreach ( isset( $in['items'] ) ? (array) $in['items'] : array() as $row ) {
			$rows[] = array(
				'id'        => absint( $row['id'] ),
				'share_id'  => absint( $row['share_id'] ),
				'size_id'   => absint( $row['size_id'] ),
				'role'      => 'swap' === $row['role'] ? 'swap' : 'box',
				'name'      => sanitize_text_field( $row['name'] ),
				'qty'       => '' === (string) $row['qty'] ? 1 : (float) $row['qty'],
				'unit'      => sanitize_text_field( $row['unit'] ),
				'swappable' => ! empty( $row['swappable'] ),
				'available' => isset( $row['available'] ) ? (string) $row['available'] : '',
				'grown_at'  => isset( $row['grown_at'] ) ? sanitize_text_field( $row['grown_at'] ) : '',
			);
		}
		Db::update( 'week', array( 'message' => sanitize_textarea_field( $in['message'] ) ), array( 'id' => $week_id ) );
		$button = isset( $in['button'] ) ? sanitize_key( $in['button'] ) : 'save';
		if ( 'copy_last' === $button ) {
			Boxes::copy_last( $week );
			self::back( 'boxday-planner', array( 'week' => $week_id ) );
		}
		$blocked = Boxes::save( $week_id, array_filter( $rows, array( __CLASS__, 'has_name' ) ), ! empty( $in['force'] ) );
		if ( $blocked ) {
			$names = array();
			foreach ( $blocked as $name => $count ) {
				$names[] = $name . ':' . $count;
			}
			self::back(
				'boxday-planner',
				array(
					'week'    => $week_id,
					'blocked' => rawurlencode( implode( ',', $names ) ),
				)
			);
		}
		if ( 0 === strpos( $button, 'copy_size_' ) ) {
			Boxes::copy_to_sizes( $week_id, absint( substr( $button, 10 ) ) );
		}
		$args = array( 'week' => $week_id );
		if ( 'preview' === $button ) {
			$args['preview'] = 1;
		}
		self::back( 'boxday-planner', $args );
	}

	/**
	 * Keeps rows with a crop name.
	 *
	 * @param array $row Planner row.
	 * @return bool
	 */
	public static function has_name( $row ) {
		return '' !== $row['name'];
	}
}
