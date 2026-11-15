<?php
/**
 * Public routes under boxday/v1. The token is the login, so no cookie or nonce is needed.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * Member, helper and quote routes.
 */
class Rest {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Registers routes.
	 */
	public static function routes() {
		$routes = array(
			'quote'          => 'quote',
			'link'           => 'lost_link',
			'helper/tick'    => 'helper_tick',
			'member/skip'    => 'member_skip',
			'member/away'    => 'member_away',
			'member/swap'    => 'member_swap',
			'member/site'    => 'member_site',
			'member/newlink' => 'member_newlink',
		);
		foreach ( $routes as $path => $method ) {
			register_rest_route(
				'boxday/v1',
				'/' . $path,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, $method ),
					'permission_callback' => '__return_true',
				)
			);
		}
	}

	/**
	 * Counts a hit and says whether it's over the limit.
	 *
	 * @param string $key   What's being counted.
	 * @param int    $limit Hits allowed.
	 * @param int    $span  Window in seconds.
	 * @return bool
	 */
	public static function limited( $key, $limit, $span = 60 ) {
		// A fixed window: the expiry is set on the first hit only, so steady use can't keep it shut.
		$name = 'boxday_rl_' . md5( $key );
		$now  = time();
		$win  = get_transient( $name );
		if ( ! is_array( $win ) || $win[1] + $span <= $now ) {
			$win = array( 0, $now );
		}
		if ( $win[0] >= $limit ) {
			return true;
		}
		set_transient( $name, array( $win[0] + 1, $win[1] ), max( 1, $win[1] + $span - $now ) );
		return false;
	}

	/**
	 * The visitor's address.
	 *
	 * @return string
	 */
	public static function ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * F5: the summary. The browser sends IDs, and the server does every sum and paints the page.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function quote( $request ) {
		if ( self::limited( 'q' . self::ip(), (int) apply_filters( 'boxday_quote_limit', 30 ) ) ) {
			return new \WP_REST_Response( array( 'error' => __( 'Something changed since you opened this page. We\'ve updated your total.', 'boxday' ) ), 429 );
		}
		$choices = Cart::clean_choices( (array) $request->get_json_params() );
		$season  = Seasons::get( $choices['season_id'] );
		if ( ! $season ) {
			return new \WP_REST_Response( array( 'error' => __( 'Something changed since you opened this page. We\'ve updated your total.', 'boxday' ) ), 400 );
		}
		return new \WP_REST_Response( array( 'html' => Signup_Page::html( $season, $choices ) ) );
	}

	/**
	 * The member behind the request's token, or an error response.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_REST_Response
	 */
	private static function member( $request ) {
		// Counted per visitor when the link is wrong and per member when it's right, so made-up
		// links can't fill the options table.
		$member = Links::member( (string) $request->get_header( 'x-boxday-token' ) );
		if ( ! $member ) {
			return new \WP_REST_Response( array( 'error' => __( 'This link has expired', 'boxday' ) ), self::limited( 'f' . self::ip(), 30 ) ? 429 : 403 );
		}
		if ( self::limited( 't' . $member['id'], 60 ) ) {
			return new \WP_REST_Response( array( 'error' => __( 'This link has expired', 'boxday' ) ), 429 );
		}
		return $member;
	}

	/**
	 * The answer to any member action: the message, and the page painted again.
	 *
	 * @param array  $member Member row.
	 * @param string $error  Message.
	 * @return \WP_REST_Response
	 */
	private static function reply( $member, $error ) {
		return new \WP_REST_Response(
			array(
				'error' => $error,
				'html'  => Member_Page::html( Db::get( 'member', (int) $member['id'] ) ),
			),
			'' === $error ? 200 : 409
		);
	}

	/**
	 * Skip or undo.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function member_skip( $request ) {
		$member = self::member( $request );
		if ( ! is_array( $member ) ) {
			return $member;
		}
		$signup = absint( $request['signup'] );
		$week   = absint( $request['week'] );
		return self::reply( $member, $request['undo'] ? Members::unskip( $member, $signup, $week ) : Members::skip( $member, $signup, $week ) );
	}

	/**
	 * Away dates.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function member_away( $request ) {
		$member = self::member( $request );
		if ( ! is_array( $member ) ) {
			return $member;
		}
		return self::reply( $member, Members::away( $member, absint( $request['signup'] ), sanitize_text_field( (string) $request['from'] ), sanitize_text_field( (string) $request['to'] ) ) );
	}

	/**
	 * Swap or undo.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function member_swap( $request ) {
		$member = self::member( $request );
		if ( ! is_array( $member ) ) {
			return $member;
		}
		if ( $request['swap'] ) {
			return self::reply( $member, Members::unswap( $member, absint( $request['swap'] ) ) );
		}
		return self::reply( $member, Members::swap( $member, absint( $request['signup'] ), absint( $request['week'] ), absint( $request['out'] ), absint( $request['in'] ) ) );
	}

	/**
	 * Change pickup.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function member_site( $request ) {
		$member = self::member( $request );
		if ( ! is_array( $member ) ) {
			return $member;
		}
		$scope = 'always' === $request['scope'] ? 'always' : 'once';
		return self::reply( $member, Members::move( $member, absint( $request['signup'] ), absint( $request['week'] ), absint( $request['site'] ), $scope ) );
	}

	/**
	 * Get a new link: the old one stops working for good.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function member_newlink( $request ) {
		$member = self::member( $request );
		if ( ! is_array( $member ) ) {
			return $member;
		}
		Db::update( 'member', array( 'link_version' => (int) $member['link_version'] + 1 ), array( 'id' => $member['id'] ) );
		Emails::new_link( Db::get( 'member', (int) $member['id'] ) );
		return new \WP_REST_Response( array( 'done' => __( 'If that email belongs to a member, a new link is on its way.', 'boxday' ) ) );
	}

	/**
	 * Lost link: always the same answer, with limits and a honeypot.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function lost_link( $request ) {
		$answer = array( 'done' => __( 'If that email belongs to a member, a new link is on its way.', 'boxday' ) );
		$email  = strtolower( sanitize_email( (string) $request['email'] ) );
		if ( '' === $email || '' !== (string) $request['site_url'] || self::limited( 'ip' . self::ip(), 10, HOUR_IN_SECONDS ) || self::limited( 'e' . $email, 3, HOUR_IN_SECONDS ) ) {
			return new \WP_REST_Response( $answer );
		}
		$member = Db::row( 'SELECT * FROM ' . Db::t( 'member' ) . ' WHERE email = %s', array( $email ) );
		if ( $member ) {
			Emails::new_link( $member );
		}
		return new \WP_REST_Response( $answer );
	}

	/**
	 * F16: tick a name. Two helpers can tick at once, as each tick is its own row.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function helper_tick( $request ) {
		$token = (string) $request->get_header( 'x-boxday-token' );
		$data  = Links::parse( $token );
		$scope = $data && 'helper' === $data['kind'] ? Member_Page::helper_scope( $data['id'] ) : null;
		if ( ! $scope || self::limited( 't' . $token, 120 ) ) {
			return new \WP_REST_Response( array( 'error' => __( 'This pickup is over, so this link has stopped working.', 'boxday' ) ), 403 );
		}
		$signup_id = absint( $request['signup'] );
		$signup    = Db::get( 'signup', $signup_id );
		$valid     = $signup && (int) $signup['season_id'] === (int) $scope['site']['season_id'] && (int) Signups::week_site( $signup, $scope['week'] )['id'] === (int) $scope['site']['id'];
		if ( ! $valid ) {
			return new \WP_REST_Response( array( 'error' => __( 'This pickup is over, so this link has stopped working.', 'boxday' ) ), 403 );
		}
		Signups::set_week( $signup_id, (int) $scope['week']['id'], array( 'picked_up_at' => $request['on'] ? Clock::mysql() : null ) );
		return new \WP_REST_Response( array( 'ok' => true ) );
	}
}
