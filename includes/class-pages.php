<?php
/**
 * The public pages: sign-up, member, helper and this week's box, in a sealed box inside the farm's theme.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks, shortcodes and page furniture.
 */
class Pages {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'template_redirect', array( __CLASS__, 'member_headers' ), 1 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_tab' ) );
		add_action( 'init', array( __CLASS__, 'account_endpoint' ) );
		add_action( 'woocommerce_account_my-box_endpoint', array( __CLASS__, 'account_view' ) );
	}

	/**
	 * Registers blocks and shortcodes.
	 */
	public static function register() {
		wp_register_script( 'boxday-blocks', BOXDAY_URL . 'assets/blocks-editor.js', array( 'wp-blocks', 'wp-element' ), BOXDAY_VERSION, true );
		foreach ( array( 'signup', 'member', 'this-week' ) as $block ) {
			register_block_type( BOXDAY_DIR . 'blocks/' . $block, array( 'render_callback' => array( __CLASS__, 'render_' . str_replace( '-', '_', $block ) ) ) );
		}
		add_shortcode( 'boxday_signup', array( __CLASS__, 'render_signup' ) );
		add_shortcode( 'boxday_member', array( __CLASS__, 'render_member' ) );
		add_shortcode( 'boxday_this_week', array( __CLASS__, 'render_this_week' ) );
	}

	/**
	 * Registers the My Box endpoint.
	 */
	public static function account_endpoint() {
		add_rewrite_endpoint( 'my-box', EP_PAGES );
	}

	/**
	 * Adds the My Box tab to My Account.
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public static function account_tab( $items ) {
		$logout = isset( $items['customer-logout'] ) ? $items['customer-logout'] : null;
		unset( $items['customer-logout'] );
		$items['my-box'] = __( 'My Box', 'boxday' );
		if ( $logout ) {
			$items['customer-logout'] = $logout;
		}
		return $items;
	}

	/**
	 * Shows the member page in My Account, for the logged-in member only.
	 */
	public static function account_view() {
		$member = self::member_from_user();
		if ( $member ) {
			self::print_html( self::sealed( 'member', Member_Page::html( $member ), array( 'token' => self::member_token( $member ) ) ) );
			return;
		}
		echo '<p>' . esc_html__( 'We\'ll post what\'s in your box soon.', 'boxday' ) . '</p>';
	}

	/**
	 * The member row for the current user.
	 *
	 * @return array|null
	 */
	private static function member_from_user() {
		$user = wp_get_current_user();
		if ( ! $user->ID ) {
			return null;
		}
		// By account only: shop accounts don't verify their email. Others use their emailed link.
		return Db::row( 'SELECT * FROM ' . Db::t( 'member' ) . ' WHERE user_id = %d', array( $user->ID ) );
	}

	/**
	 * A member's current token.
	 *
	 * @param array $member Member row.
	 * @return string
	 */
	private static function member_token( $member ) {
		return Links::token( 'member', (string) $member['id'], (int) $member['link_version'] );
	}

	/**
	 * Creates the three pages if they aren't there.
	 */
	public static function create() {
		$pages = (array) get_option( 'boxday_pages', array() );
		$list  = array(
			'signup' => array( __( 'Sign up', 'boxday' ), '<!-- wp:boxday/signup /-->' ),
			'member' => array( __( 'My box', 'boxday' ), '<!-- wp:boxday/member /-->' ),
			'week'   => array( __( 'This week\'s box', 'boxday' ), '<!-- wp:boxday/this-week /-->' ),
		);
		foreach ( $list as $key => $page ) {
			if ( ! empty( $pages[ $key ] ) && get_post( $pages[ $key ] ) ) {
				continue;
			}
			$pages[ $key ] = wp_insert_post(
				array(
					'post_title'   => $page[0],
					'post_content' => $page[1],
					'post_status'  => 'publish',
					'post_type'    => 'page',
				)
			);
		}
		update_option( 'boxday_pages', $pages );
		flush_rewrite_rules();
	}

	/**
	 * The URL of one of our pages.
	 *
	 * @param string $key signup, member or week.
	 * @return string
	 */
	public static function url( $key ) {
		$pages = (array) get_option( 'boxday_pages', array() );
		return ! empty( $pages[ $key ] ) ? (string) get_permalink( $pages[ $key ] ) : home_url( '/' );
	}

	/**
	 * Whether the current request is the member page.
	 *
	 * @return bool
	 */
	private static function is_member_page() {
		$pages = (array) get_option( 'boxday_pages', array() );
		return ! empty( $pages['member'] ) && is_page( $pages['member'] );
	}

	/**
	 * F10: private pages are never cached, indexed or leaked through a referrer.
	 */
	public static function member_headers() {
		if ( ! self::is_member_page() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The constant cache plugins read.
		}
		nocache_headers();
		header( 'Cache-Control: no-store, private' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow' );
	}

	/**
	 * Adds noindex to the member page.
	 *
	 * @param array $robots Directives.
	 * @return array
	 */
	public static function robots( $robots ) {
		if ( self::is_member_page() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	/**
	 * Wraps a page in the sealed box.
	 *
	 * @param string $kind  signup, member, helper or week.
	 * @param string $inner Escaped HTML.
	 * @param array  $data  Data attributes.
	 * @return string
	 */
	public static function sealed( $kind, $inner, $data = array() ) {
		wp_enqueue_script( 'boxday', BOXDAY_URL . 'assets/boxday.js', array(), BOXDAY_VERSION, true );
		wp_add_inline_script( 'boxday', 'window.boxdayApi=' . wp_json_encode( rest_url( 'boxday/v1/' ) ) . ';window.boxdayCss=' . wp_json_encode( BOXDAY_URL . 'assets/boxday.css?ver=' . BOXDAY_VERSION ) . ';', 'before' );
		// The farm's look rides on the host as custom properties, which reach into the sealed box.
		wp_register_style( 'boxday-vars', false, array(), BOXDAY_VERSION );
		wp_enqueue_style( 'boxday-vars' );
		if ( ! wp_styles()->get_data( 'boxday-vars', 'after' ) ) {
			wp_add_inline_style( 'boxday-vars', Format::css_vars() );
		}
		$attrs = '';
		foreach ( $data as $key => $value ) {
			$attrs .= ' data-' . esc_attr( $key ) . '="' . esc_attr( $value ) . '"';
		}
		// The box stays hidden until boxday.js has loaded our stylesheet into it.
		return '<div class="boxday-root" data-kind="' . esc_attr( $kind ) . '"' . $attrs . '><template shadowrootmode="open"><div class="boxday" hidden>' . $inner . '</div></template></div>'
			. '<noscript>' . esc_html( sprintf( /* translators: %s: farm email. */ __( 'Questions? %s', 'boxday' ), Format::look()['email'] ) ) . '</noscript>';
	}

	/**
	 * Echoes HTML we built and escaped ourselves.
	 *
	 * @param string $html HTML.
	 */
	private static function print_html( $html ) {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in this class.
	}

	/**
	 * Block and shortcode: the sign-up page.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function render_signup( $atts = array() ) {
		$season = Signup_Page::pick_season( is_array( $atts ) && ! empty( $atts['season'] ) ? (int) $atts['season'] : 0 );
		if ( ! $season ) {
			return self::sealed( 'week', '<p class="msg">' . esc_html__( 'Sign-ups will open here soon.', 'boxday' ) . '</p>' );
		}
		Waitlist::offer_next( (int) $season['id'] );
		$choices = array();
		// phpcs:disable WordPress.Security.NonceVerification -- Cart::handle_post already checked a post, and an offer link is its own login.
		if ( ! empty( $_POST['boxday_signup'] ) ) {
			$choices = Cart::clean_choices( wp_unslash( $_POST ) );
		} elseif ( isset( $_GET['offer'] ) ) {
			$token = sanitize_text_field( wp_unslash( $_GET['offer'] ) );
			$row   = Waitlist::from_token( $token );
			if ( $row && ! empty( $_GET['leave'] ) ) {
				// Mail scanners open links, so leaving takes a button press, not just the link.
				if ( empty( $_POST['boxday_leave'] ) ) {
					return self::sealed( 'week', '<form method="post" class="msg"><p>' . esc_html__( 'Take yourself off the waitlist?', 'boxday' ) . '</p><p><button class="btn" name="boxday_leave" value="1">' . esc_html__( 'Take me off the list', 'boxday' ) . '</button></p></form>' );
				}
				Waitlist::remove( (int) $row['id'] );
				return self::sealed( 'week', '<p class="msg">' . esc_html__( 'You\'re off the list. Thanks for letting us know.', 'boxday' ) . '</p>' );
			}
			if ( ! Waitlist::is_open( $row ) ) {
				return self::sealed( 'signup', '<div class="wrap"><section class="top"><h1>' . esc_html( $season['name'] ) . '</h1></section><p class="msg">' . esc_html__( 'This offer has ended, and the place has gone to the next person. You\'re still on the waitlist.', 'boxday' ) . '</p></div>' );
			}
			$choices = Cart::clean_choices(
				array(
					'season_id' => $row['season_id'],
					'size_id'   => $row['size_id'],
					'site'      => $row['site_id'],
					'offer'     => $token,
				)
			);
		} else {
			/**
			 * Filters the first choices on the sign-up page, such as a returning member's share.
			 *
			 * @param array $choices Choices, in Cart::clean_choices() form, or empty.
			 * @param array $season  Season row.
			 */
			$choices = (array) apply_filters( 'boxday_signup_choices', array(), $season );
		}
		// phpcs:enable WordPress.Security.NonceVerification
		return self::sealed( 'signup', Signup_Page::html( $season, $choices, Cart::$error ) );
	}

	/**
	 * Block and shortcode: the member or helper page, opened by a private link.
	 *
	 * @return string
	 */
	public static function render_member() {
		$token = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( $_GET['k'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The token is the login.
		$data  = '' !== $token ? Links::parse( $token ) : null;
		if ( $data && 'helper' === $data['kind'] ) {
			return self::sealed( 'helper', Member_Page::helper_html( $data['id'] ), array( 'token' => $token ) );
		}
		$member = '' !== $token ? Links::member( $token ) : self::member_from_user();
		if ( ! $member ) {
			return self::sealed( 'member', Member_Page::expired_html() );
		}
		return self::sealed( 'member', Member_Page::html( $member ), array( 'token' => self::member_token( $member ) ) );
	}

	/**
	 * Block and shortcode: the latest published box.
	 *
	 * @return string
	 */
	public static function render_this_week() {
		return self::sealed( 'week', Member_Page::this_week_html() );
	}
}
