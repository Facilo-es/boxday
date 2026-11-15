<?php
/**
 * F17 and L12: the waitlist, with timed offers that hold a place.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * Joins, offers, expiry and the owner's controls.
 */
class Waitlist {

	/**
	 * Message for the sign-up page after a waitlist post.
	 *
	 * @var string
	 */
	public static $notice = '';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_post' ), 4 );
		add_action( 'boxday_place_freed', array( __CLASS__, 'offer_next' ) );
	}

	/**
	 * Whether freed places go out by themselves.
	 *
	 * @return bool
	 */
	public static function auto() {
		return 'no' !== get_option( 'boxday_waitlist_auto', 'yes' );
	}

	/**
	 * How long an offer holds a place, in hours.
	 *
	 * @return int
	 */
	public static function hours() {
		return max( 1, (int) get_option( 'boxday_offer_hours', 48 ) );
	}

	/**
	 * The join form, under the full message. Values are escaped here.
	 *
	 * @param array $season Season row.
	 * @return string
	 */
	public static function form_html( $season ) {
		$word = Format::word();
		if ( '' !== self::$notice ) {
			return '<p class="msg ok" role="status">' . esc_html( self::$notice ) . '</p>';
		}
		$html  = '<form class="wl" method="post">';
		$html .= '<input type="hidden" name="boxday_waitlist" value="' . esc_attr( Links::form_token() ) . '"><input type="hidden" name="season_id" value="' . (int) $season['id'] . '">';
		$html .= '<p class="hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>';
		$html .= '<input type="hidden" name="t0" value="' . (int) time() . '">';
		$html .= '<h2>' . esc_html__( 'Join the waitlist', 'boxday' ) . '</h2>';
		$html .= '<label class="fl">' . esc_html__( 'Name', 'boxday' ) . '<input type="text" name="wl_name" required autocomplete="name"></label>';
		$html .= '<label class="fl">' . esc_html__( 'Email', 'boxday' ) . '<input type="email" name="wl_email" required autocomplete="email"></label>';
		$html .= '<label class="fl">' . esc_html__( 'Phone (optional)', 'boxday' ) . '<input type="tel" name="wl_phone" autocomplete="tel"></label>';
		$html .= '<label class="fl">' . esc_html( sprintf( /* translators: %s: word for a share. */ __( 'Which %s?', 'boxday' ), $word ) ) . '<select name="size_id">';
		foreach ( Seasons::shares( (int) $season['id'], 'main' ) as $share ) {
			foreach ( Seasons::sizes( (int) $share['id'] ) as $size ) {
				$html .= '<option value="' . (int) $size['id'] . '">' . esc_html( $share['name'] . ', ' . $size['name'] ) . '</option>';
			}
		}
		$html .= '</select></label><fieldset class="fl"><legend>' . esc_html__( 'Where could you collect it?', 'boxday' ) . '</legend>';
		foreach ( Seasons::sites( (int) $season['id'] ) as $site ) {
			$html .= '<label class="ck"><input type="checkbox" name="sites[]" value="' . (int) $site['id'] . '" checked><i></i>' . esc_html( $site['name'] ) . '</label>';
		}
		$html .= '</fieldset><button class="btn" type="submit">' . esc_html__( 'Join the waitlist', 'boxday' ) . '</button></form>';
		return $html;
	}

	/**
	 * Saves a join from the sign-up page. A honeypot, a minimum fill time and rate limits keep bots out.
	 */
	public static function handle_post() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Links::form_token_ok() does this job, as on the sign-up form.
		if ( empty( $_POST['boxday_waitlist'] ) || ! Links::form_token_ok( sanitize_text_field( wp_unslash( $_POST['boxday_waitlist'] ) ) ) ) {
			return;
		}
		$done = __( 'You\'re on the list. We\'ll email you if a place opens up.', 'boxday' );
		$bot  = ! empty( $_POST['website'] ) || ( time() - ( isset( $_POST['t0'] ) ? absint( $_POST['t0'] ) : 0 ) ) < 3;
		$in   = array(
			'season_id' => isset( $_POST['season_id'] ) ? absint( $_POST['season_id'] ) : 0,
			'size_id'   => isset( $_POST['size_id'] ) ? absint( $_POST['size_id'] ) : 0,
			'sites'     => isset( $_POST['sites'] ) ? array_map( 'absint', (array) $_POST['sites'] ) : array(),
			'name'      => isset( $_POST['wl_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wl_name'] ) ) : '',
			'email'     => isset( $_POST['wl_email'] ) ? sanitize_email( wp_unslash( $_POST['wl_email'] ) ) : '',
			'phone'     => isset( $_POST['wl_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['wl_phone'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( $bot || Rest::limited( 'wl' . $ip, 10, HOUR_IN_SECONDS ) ) {
			self::$notice = $done;
			return;
		}
		if ( ! is_email( $in['email'] ) || '' === $in['name'] ) {
			return;
		}
		self::$notice = self::join( $in ) ? $done : '';
	}

	/**
	 * Adds someone to the list. The same email for the same size joins once.
	 *
	 * @param array $in season_id, size_id, sites, name, email, phone.
	 * @return int Row id, or 0.
	 */
	public static function join( $in ) {
		$size  = Db::get( 'size', $in['size_id'] );
		$share = $size ? Db::get( 'share', (int) $size['share_id'] ) : null;
		if ( ! $share || (int) $share['season_id'] !== (int) $in['season_id'] ) {
			return 0;
		}
		$valid = array_map( 'intval', array_column( Seasons::sites( (int) $in['season_id'] ), 'id' ) );
		$sites = array_values( array_intersect( array_map( 'intval', $in['sites'] ), $valid ) );
		if ( ! $sites ) {
			$sites = $valid;
		}
		$email = strtolower( $in['email'] );
		$old   = Db::value( 'SELECT id FROM ' . Db::t( 'waitlist' ) . " WHERE email = %s AND size_id = %d AND status IN ('waiting','offered')", array( $email, (int) $size['id'] ) );
		if ( $old ) {
			return (int) $old;
		}
		$parts = explode( ' ', trim( $in['name'] ), 2 );
		return Db::insert(
			'waitlist',
			array(
				'season_id'  => (int) $in['season_id'],
				'share_id'   => (int) $share['id'],
				'size_id'    => (int) $size['id'],
				'site_ids'   => implode( ',', $sites ),
				'first_name' => $parts[0],
				'last_name'  => isset( $parts[1] ) ? $parts[1] : '',
				'email'      => $email,
				'phone'      => $in['phone'],
				'status'     => 'waiting',
				'queued_at'  => Clock::mysql(),
				'created_at' => Clock::mysql(),
			)
		);
	}

	/**
	 * The site an offer can hold for this row, or 0 if nothing fits.
	 *
	 * @param array $row Waitlist row.
	 * @return int
	 */
	public static function fitting_site( $row ) {
		$share = Db::get( 'share', (int) $row['share_id'] );
		if ( ! $share || ! Capacity::share_fits( $share ) ) {
			return 0;
		}
		foreach ( array_filter( array_map( 'intval', explode( ',', (string) $row['site_ids'] ) ) ) as $site_id ) {
			$site = Db::get( 'site', $site_id );
			if ( $site && ( Capacity::site_has_room( $site, 'w', '' ) || Capacity::site_has_room( $site, 'b', 'A' ) || Capacity::site_has_room( $site, 'b', 'B' ) ) ) {
				return $site_id;
			}
		}
		return 0;
	}

	/**
	 * L12: ends old offers, then offers each freed place to the oldest row that fits.
	 *
	 * @param int $season_id Season.
	 */
	public static function offer_next( $season_id ) {
		self::expire();
		self::offer_next_only( (int) $season_id );
	}

	/**
	 * Offers a place to one row and emails the link. The owner can do this by hand.
	 *
	 * @param int $id Waitlist row.
	 * @return bool
	 */
	public static function offer( $id ) {
		$row  = Db::get( 'waitlist', $id );
		$site = $row && 'waiting' === $row['status'] ? self::fitting_site( $row ) : 0;
		if ( ! $site ) {
			return false;
		}
		$ends = Clock::ts() + self::hours() * HOUR_IN_SECONDS;
		Db::update(
			'waitlist',
			array(
				'status'       => 'offered',
				'site_id'      => $site,
				'offered_at'   => Clock::mysql(),
				'expires_at'   => gmdate( 'Y-m-d H:i:s', $ends ),
				'link_version' => (int) $row['link_version'] + 1,
			),
			array( 'id' => $id )
		);
		Emails::offer( Db::get( 'waitlist', $id ), $ends );
		return true;
	}

	/**
	 * Offers past their time go back to the end of the list, so the place moves on.
	 */
	public static function expire() {
		$table = Db::t( 'waitlist' );
		$now   = gmdate( 'Y-m-d H:i:s', Clock::ts() );
		$gone  = Db::rows( "SELECT id, season_id FROM {$table} WHERE status = 'offered' AND expires_at <= %s", array( $now ) );
		foreach ( $gone as $row ) {
			Db::update(
				'waitlist',
				array(
					'status'    => 'waiting',
					'site_id'   => 0,
					'queued_at' => Clock::mysql(),
				),
				array( 'id' => $row['id'] )
			);
		}
		if ( $gone ) {
			foreach ( array_unique( array_column( $gone, 'season_id' ) ) as $season_id ) {
				self::offer_next_only( (int) $season_id );
			}
		}
	}

	/**
	 * Offers freed places without expiring again, so expire() can't loop.
	 *
	 * @param int $season_id Season.
	 */
	private static function offer_next_only( $season_id ) {
		$season = Seasons::get( $season_id );
		if ( ! $season || ! self::auto() || 'open' !== Seasons::state( $season ) ) {
			return;
		}
		foreach ( Db::rows( 'SELECT * FROM ' . Db::t( 'waitlist' ) . " WHERE season_id = %d AND status = 'waiting' ORDER BY queued_at, id", array( $season_id ) ) as $row ) {
			if ( self::fitting_site( $row ) ) {
				self::offer( (int) $row['id'] );
			}
		}
	}

	/**
	 * The open offer behind a token, if it's still current.
	 *
	 * @param string $token Token.
	 * @return array|null Waitlist row.
	 */
	public static function from_token( $token ) {
		$data = Links::parse( $token );
		if ( ! $data || 'offer' !== $data['kind'] ) {
			return null;
		}
		$row = Db::get( 'waitlist', (int) $data['id'] );
		if ( ! $row || (int) $row['link_version'] !== $data['version'] ) {
			return null;
		}
		return $row;
	}

	/**
	 * Whether an offer row is open now.
	 *
	 * @param array|null $row Waitlist row.
	 * @return bool
	 */
	public static function is_open( $row ) {
		return $row && 'offered' === $row['status'] && strtotime( $row['expires_at'] . ' UTC' ) > Clock::ts();
	}

	/**
	 * The offer link.
	 *
	 * @param array $row Waitlist row.
	 * @return string
	 */
	public static function offer_url( $row ) {
		return add_query_arg( 'offer', Links::token( 'offer', (string) $row['id'], (int) $row['link_version'] ), Pages::url( 'signup' ) );
	}

	/**
	 * Marks an offer used once its order exists.
	 *
	 * @param int $id Waitlist row.
	 */
	public static function done( $id ) {
		Db::update( 'waitlist', array( 'status' => 'done' ), array( 'id' => (int) $id ) );
	}

	/**
	 * Takes someone off the list, by their own link or the owner's button.
	 *
	 * @param int $id Waitlist row.
	 */
	public static function remove( $id ) {
		$row = Db::get( 'waitlist', (int) $id );
		if ( ! $row ) {
			return;
		}
		Db::update(
			'waitlist',
			array(
				'status'  => 'declined',
				'site_id' => 0,
			),
			array( 'id' => (int) $id )
		);
		if ( 'offered' === $row['status'] ) {
			do_action( 'boxday_place_freed', (int) $row['season_id'] );
		}
	}

	/**
	 * Rows for the owner's list.
	 *
	 * @param int $season_id Season.
	 * @return array
	 */
	public static function rows( $season_id ) {
		return Db::rows(
			'SELECT w.*, sh.name AS share, z.name AS size FROM ' . Db::t( 'waitlist' ) . ' w JOIN ' . Db::t( 'share' ) . ' sh ON sh.id = w.share_id JOIN ' . Db::t( 'size' ) . " z ON z.id = w.size_id
			WHERE w.season_id = %d AND w.status IN ('waiting','offered') ORDER BY w.status = 'offered' DESC, w.queued_at, w.id",
			array( (int) $season_id )
		);
	}
}
