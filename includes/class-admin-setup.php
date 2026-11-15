<?php
/**
 * F1: setup in seven steps that save as you go, the look reader, and the setup-only settings.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Screens read only which step to show. Actions check their nonce in Admin::action().

/**
 * The setup wizard.
 */
class Admin_Setup {

	/**
	 * Step keys, in order.
	 *
	 * @return array Key to heading.
	 */
	public static function steps() {
		return array(
			'farm'    => __( 'Tell us about your farm', 'boxday' ),
			'season'  => __( 'Plan your season', 'boxday' ),
			'shares'  => __( 'What are you offering?', 'boxday' ),
			'pickup'  => __( 'Where do members collect?', 'boxday' ),
			'payment' => __( 'How will members pay?', 'boxday' ),
			'look'    => __( 'Make it look like yours', 'boxday' ),
			'ready'   => __( 'You\'re ready for sign-ups', 'boxday' ),
		);
	}

	/**
	 * Short labels for the progress bar.
	 *
	 * @return array
	 */
	private static function labels() {
		return array( __( 'Farm', 'boxday' ), __( 'Season', 'boxday' ), __( 'Shares', 'boxday' ), __( 'Pickup', 'boxday' ), __( 'Payment', 'boxday' ), __( 'Look', 'boxday' ), __( 'Ready', 'boxday' ) );
	}

	/**
	 * Saved progress: the step reached and the season being set up.
	 *
	 * @return array
	 */
	public static function state() {
		return array_merge(
			array(
				'step'   => 0,
				'season' => 0,
			),
			(array) get_option( 'boxday_setup', array() )
		);
	}

	/**
	 * The setup screen.
	 */
	public static function screen() {
		$state = self::state();
		$keys  = array_keys( self::steps() );
		$step  = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : (int) $state['step'];
		echo '<div class="wrap bd-wrap bd-setup">';
		if ( ! $step ) {
			echo '<div class="bd-hero"><h1>' . esc_html__( 'Let\'s get your farm ready for sign-ups', 'boxday' ) . '</h1><p>' . esc_html__( 'Most farms finish in under 10 minutes. You can change everything later.', 'boxday' ) . '</p><p class="bd-acts">';
			echo '<a class="button button-primary button-hero" href="' . esc_url( self::url( 1 ) ) . '">' . esc_html__( 'Start setup', 'boxday' ) . '</a>';
			if ( ! Sample::loaded() ) {
				echo '<a class="button button-hero" href="' . esc_url( Admin::action_url( 'sample_load' ) ) . '">' . esc_html__( 'Look around with a sample farm first', 'boxday' ) . '</a>';
			}
			echo '</p></div></div>';
			return;
		}
		$step = max( 1, min( 7, $step ) );
		echo '<ol class="bd-steps">';
		foreach ( self::labels() as $i => $label ) {
			$n     = $i + 1;
			$class = $n === $step ? 'cur' : ( $n <= (int) $state['step'] ? 'done' : '' );
			$link  = $n <= max( 1, (int) $state['step'] ) ? '<a href="' . esc_url( self::url( $n ) ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label );
			echo '<li class="' . esc_attr( $class ) . '"><b>' . (int) $n . '</b>' . $link . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
		}
		echo '</ol><div class="bd-card-w"><h1>' . esc_html( self::steps()[ $keys[ $step - 1 ] ] ) . '</h1>';
		if ( ! empty( $_GET['err'] ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['err'] ) ) ) . '</p></div>';
		}
		$season = $state['season'] ? Seasons::get( (int) $state['season'] ) : null;
		if ( 7 === $step ) {
			self::ready( $season );
		} else {
			Admin::form_open( 'setup' );
			echo '<input type="hidden" name="step" value="' . (int) $step . '">';
			call_user_func( array( __CLASS__, 'step_' . $keys[ $step - 1 ] ), $season );
			echo '<p class="bd-acts bd-nav">';
			if ( $step > 1 ) {
				echo '<a class="button button-hero" href="' . esc_url( self::url( $step - 1 ) ) . '">' . esc_html__( 'Back', 'boxday' ) . '</a>';
			}
			echo '<button class="button button-primary button-hero">' . esc_html__( 'Save and continue', 'boxday' ) . '</button></p></form>';
		}
		echo '</div></div>';
	}

	/**
	 * The URL of a step.
	 *
	 * @param int $step Step.
	 * @return string
	 */
	public static function url( $step = 0 ) {
		return add_query_arg(
			array(
				'page' => 'boxday-setup',
				'step' => $step,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * A labelled input.
	 *
	 * @param string $label Label.
	 * @param string $name  Name.
	 * @param string $value Value.
	 * @param array  $more  type, placeholder, help, required.
	 */
	private static function input( $label, $name, $value, $more = array() ) {
		$more = array_merge(
			array(
				'type'        => 'text',
				'placeholder' => '',
				'help'        => '',
				'required'    => false,
			),
			$more
		);
		echo '<label>' . esc_html( $label ) . '<input type="' . esc_attr( $more['type'] ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" placeholder="' . esc_attr( $more['placeholder'] ) . '"' . ( $more['required'] ? ' required' : '' ) . ( 'number' === $more['type'] ? ' min="0" step="any"' : '' ) . '>';
		if ( '' !== $more['help'] ) {
			echo '<small>' . esc_html( $more['help'] ) . '</small>';
		}
		echo '</label>';
	}

	/**
	 * Step 1: the farm, the word for a share and the time zone.
	 */
	private static function step_farm() {
		$look = Format::look();
		echo '<div class="bd-fields">';
		self::input( __( 'Farm name', 'boxday' ), 'farm', $look['farm'] ? $look['farm'] : get_bloginfo( 'name' ), array( 'required' => true ) );
		self::input( __( 'Farm email', 'boxday' ), 'email', $look['email'], array( 'type' => 'email' ) );
		self::input( __( 'Farm phone', 'boxday' ), 'phone', $look['phone'] );
		echo '<label>' . esc_html__( 'What do you call what members get?', 'boxday' ) . '<select name="word">';
		foreach ( array(
			'share'  => __( 'Share', 'boxday' ),
			'box'    => __( 'Box', 'boxday' ),
			'basket' => __( 'Basket', 'boxday' ),
		) as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $look['word'], $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></div>';
		$logo = get_custom_logo();
		if ( $logo ) {
			echo '<p class="bd-logo">' . wp_kses_post( $logo ) . ' <span>' . esc_html__( 'We\'ll use your site\'s logo.', 'boxday' ) . '</span></p>';
		}
		$zone = wp_timezone_string();
		$bad  = 'UTC' === $zone || '+00:00' === $zone;
		/* translators: %s: time zone. */
		echo '<p class="bd-tz' . ( $bad ? ' bd-bad' : '' ) . '">' . esc_html( sprintf( __( 'Pickups and cut-offs use %s. Is that right?', 'boxday' ), $zone ) ) . ' <a href="' . esc_url( admin_url( 'options-general.php#timezone_string' ) ) . '">' . esc_html__( 'Change the time zone', 'boxday' ) . '</a></p>';
	}

	/**
	 * Step 2: the season's dates.
	 *
	 * @param array|null $season Season being set up.
	 */
	private static function step_season( $season ) {
		$year  = (int) wp_date( 'Y' ) + ( (int) wp_date( 'n' ) > 5 ? 1 : 0 );
		$first = $season ? $season['first_week'] : $year . '-06-01';
		echo '<div class="bd-fields">';
		self::input(
			__( 'Season name', 'boxday' ),
			'name',
			$season ? $season['name'] : '',
			array(
				/* translators: %d: year. */
				'placeholder' => sprintf( __( 'Summer %d', 'boxday' ), $year ),
				'required'    => true,
			)
		);
		self::input( __( 'First pickup week', 'boxday' ), 'first_week', $first, array( 'type' => 'date' ) );
		self::input( __( 'How many weeks?', 'boxday' ), 'weeks', $season ? $season['weeks'] : 20, array( 'type' => 'number' ) );
		self::input( __( 'Sign-ups open', 'boxday' ), 'signup_open', $season && $season['signup_open'] ? substr( $season['signup_open'], 0, 10 ) : wp_date( 'Y-m-d' ), array( 'type' => 'date' ) );
		self::input( __( 'Sign-ups close', 'boxday' ), 'signup_close', $season && $season['signup_close'] ? substr( $season['signup_close'], 0, 10 ) : '', array( 'type' => 'date' ) );
		echo '</div><p class="description">' . esc_html__( 'You can close any week later, like a holiday.', 'boxday' ) . '</p>';
	}

	/**
	 * Step 3: one share and its sizes.
	 *
	 * @param array|null $season Season being set up.
	 */
	private static function step_shares( $season ) {
		$shares = $season ? Seasons::shares( (int) $season['id'], 'main' ) : array();
		$share  = $shares ? $shares[0] : null;
		$sizes  = $share ? Seasons::sizes( (int) $share['id'] ) : array();
		$word   = Format::word_cap();
		echo '<div class="bd-fields">';
		/* translators: %s: word for a share. */
		self::input( sprintf( __( '%s name', 'boxday' ), $word ), 'share', $share ? $share['name'] : '', array( 'placeholder' => __( 'Vegetable share', 'boxday' ) ) );
		echo '<label>' . esc_html__( 'Price is', 'boxday' ) . '<select name="price_mode"><option value="season">' . esc_html__( 'per season', 'boxday' ) . '</option><option value="box"' . selected( $share ? $share['price_mode'] : '', 'box', false ) . '>' . esc_html__( 'per box', 'boxday' ) . '</option></select></label></div>';
		echo '<table class="widefat bd-sizes"><thead><tr><th>' . esc_html__( 'Size', 'boxday' ) . '</th><th>' . esc_html__( 'Weekly price', 'boxday' ) . '</th><th>' . esc_html__( 'Every-other-week price', 'boxday' ) . '</th></tr></thead><tbody>';
		$holders = array( __( 'Full', 'boxday' ), __( 'Small', 'boxday' ), '' );
		for ( $i = 0; $i < 3; $i++ ) {
			$size = isset( $sizes[ $i ] ) ? $sizes[ $i ] : null;
			echo '<tr><td><input type="hidden" name="sizes[' . (int) $i . '][id]" value="' . ( $size ? (int) $size['id'] : 0 ) . '"><input type="text" name="sizes[' . (int) $i . '][name]" aria-label="' . esc_attr__( 'Size', 'boxday' ) . '" value="' . esc_attr( $size ? $size['name'] : '' ) . '" placeholder="' . esc_attr( $holders[ $i ] ) . '"></td>';
			foreach ( array(
				'weekly'   => array( 'price_weekly', __( 'Weekly price', 'boxday' ) ),
				'biweekly' => array( 'price_biweekly', __( 'Every-other-week price', 'boxday' ) ),
			) as $key => $pair ) {
				$col = $pair[0];
				echo '<td><input type="number" min="0" step="0.01" name="sizes[' . (int) $i . '][' . esc_attr( $key ) . ']" aria-label="' . esc_attr( $pair[1] ) . '" value="' . esc_attr( $size && null !== $size[ $col ] ? (string) (float) $size[ $col ] : '' ) . '"></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table><p class="description">' . esc_html__( 'Leave a price blank if you don\'t offer it. Add-ons like eggs, more sizes and sliding-scale prices go in Seasons, after setup.', 'boxday' ) . '</p>';
	}

	/**
	 * Step 4: pickup places, and home delivery.
	 *
	 * @param array|null $season Season being set up.
	 */
	private static function step_pickup( $season ) {
		$sites = $season ? Seasons::sites( (int) $season['id'] ) : array();
		$rows  = array_values( array_filter( $sites, array( __CLASS__, 'is_pickup' ) ) );
		$route = array_values( array_diff_key( $sites, array_filter( $sites, array( __CLASS__, 'is_pickup' ) ) ) );
		echo '<div class="bd-places" data-rows>';
		$count = max( 1, count( $rows ) );
		for ( $i = 0; $i < $count; $i++ ) {
			self::place_row( $i, isset( $rows[ $i ] ) ? $rows[ $i ] : null );
		}
		echo '</div><p class="bd-acts"><button type="button" class="button" data-add-place>' . esc_html__( 'Add another place', 'boxday' ) . '</button></p>';
		$on = (bool) $route;
		echo '<details class="bd-deliv"' . ( $on ? ' open' : '' ) . '><summary class="button">' . esc_html__( 'Add home delivery', 'boxday' ) . '</summary><div class="bd-fields">';
		$r = $on ? $route[0] : null;
		echo '<input type="hidden" name="route[id]" value="' . ( $r ? (int) $r['id'] : 0 ) . '">';
		self::input( __( 'Name', 'boxday' ), 'route[name]', $r ? $r['name'] : '', array( 'placeholder' => __( 'Home delivery', 'boxday' ) ) );
		self::input( __( 'ZIP codes or their first digits, with commas', 'boxday' ), 'route[zip_prefixes]', $r ? $r['zip_prefixes'] : '', array( 'placeholder' => '37064, 3706' ) );
		self::input( __( 'Fee a box', 'boxday' ), 'route[fee_per_box]', $r ? (float) $r['fee_per_box'] : '', array( 'type' => 'number' ) );
		self::weekday( 'route[weekday]', $r ? (int) $r['weekday'] : 3 );
		self::input( __( 'From', 'boxday' ), 'route[time_from]', $r ? substr( $r['time_from'], 0, 5 ) : '14:00', array( 'type' => 'time' ) );
		self::input( __( 'To', 'boxday' ), 'route[time_to]', $r ? substr( $r['time_to'], 0, 5 ) : '18:00', array( 'type' => 'time' ) );
		self::input( __( 'How many boxes fit here?', 'boxday' ), 'route[capacity]', $r ? $r['capacity'] : '', array( 'type' => 'number' ) );
		echo '</div></details>';
	}

	/**
	 * Whether a site is a pickup place.
	 *
	 * @param array $site Site row.
	 * @return bool
	 */
	public static function is_pickup( $site ) {
		return 'delivery' !== $site['type'];
	}

	/**
	 * A weekday picker.
	 *
	 * @param string $name  Field.
	 * @param int    $value 1 Monday to 7 Sunday.
	 */
	private static function weekday( $name, $value ) {
		echo '<label>' . esc_html__( 'Day', 'boxday' ) . '<select name="' . esc_attr( $name ) . '">';
		for ( $d = 1; $d <= 7; $d++ ) {
			echo '<option value="' . (int) $d . '"' . selected( $value, $d, false ) . '>' . esc_html( Format::weekday( $d ) ) . '</option>';
		}
		echo '</select></label>';
	}

	/**
	 * One pickup place.
	 *
	 * @param int        $i    Row.
	 * @param array|null $site Site row.
	 */
	private static function place_row( $i, $site ) {
		echo '<div class="bd-fields bd-place"><input type="hidden" name="sites[' . (int) $i . '][id]" value="' . ( $site ? (int) $site['id'] : 0 ) . '">';
		self::input( __( 'Place name', 'boxday' ), 'sites[' . $i . '][name]', $site ? $site['name'] : '', array( 'placeholder' => __( 'Farm stand', 'boxday' ) ) );
		self::input( __( 'Address', 'boxday' ), 'sites[' . $i . '][address]', $site ? $site['address'] : '' );
		self::weekday( 'sites[' . $i . '][weekday]', $site ? (int) $site['weekday'] : 2 );
		self::input( __( 'From', 'boxday' ), 'sites[' . $i . '][time_from]', $site ? substr( $site['time_from'], 0, 5 ) : '16:00', array( 'type' => 'time' ) );
		self::input( __( 'To', 'boxday' ), 'sites[' . $i . '][time_to]', $site ? substr( $site['time_to'], 0, 5 ) : '19:00', array( 'type' => 'time' ) );
		self::input(
			__( 'How many boxes fit here?', 'boxday' ),
			'sites[' . $i . '][capacity]',
			$site ? $site['capacity'] : '',
			array(
				'type' => 'number',
				'help' => __( 'Optional', 'boxday' ),
			)
		);
		echo '</div>';
	}

	/**
	 * Step 5: payment methods and payment plans.
	 *
	 * @param array|null $season Season being set up.
	 */
	private static function step_payment( $season ) {
		echo '<p>' . esc_html__( 'Members pay at your normal checkout.', 'boxday' ) . '</p>';
		$methods = WC()->payment_gateways()->get_available_payment_gateways();
		if ( $methods ) {
			echo '<ul class="bd-methods">';
			foreach ( $methods as $gateway ) {
				echo '<li class="bd-ok">✓ ' . esc_html( $gateway->get_title() ) . '</li>';
			}
			echo '</ul>';
		} else {
			echo '<div class="bd-pb bd-pad"><p class="bd-bad">' . esc_html__( 'Nobody can pay yet. Turn on a payment method', 'boxday' ) . '</p>';
			echo '<p class="bd-acts"><a class="button button-primary" href="' . esc_url( Admin::action_url( 'accept_checks' ) ) . '">' . esc_html__( 'Accept checks', 'boxday' ) . '</a><a class="button" href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) . '">' . esc_html__( 'Set up card payments in WooCommerce', 'boxday' ) . '</a></p></div>';
		}
		$parts = $season && 'parts' === $season['plan_mode'];
		$dates = $season ? Seasons::plan_dates( $season ) : array();
		echo '<label class="bd-tick"><input type="checkbox" name="parts" value="1"' . checked( $parts, true, false ) . '> ' . esc_html__( 'Let members pay in parts?', 'boxday' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Members pay a deposit, then up to 4 more payments on dates you pick. No fees, and we never store a card.', 'boxday' ) . '</p><div class="bd-fields">';
		self::input( __( 'Deposit', 'boxday' ), 'deposit_value', $season ? (float) $season['deposit_value'] : 100, array( 'type' => 'number' ) );
		echo '<label>' . esc_html__( 'Deposit is', 'boxday' ) . '<select name="deposit_type"><option value="amount">' . esc_html__( 'an amount', 'boxday' ) . '</option><option value="percent"' . selected( $season ? $season['deposit_type'] : '', 'percent', false ) . '>' . esc_html__( 'a percent', 'boxday' ) . '</option></select></label>';
		for ( $i = 0; $i < Pricing::MAX_LATER_PAYMENTS; $i++ ) {
			/* translators: %d: payment number. */
			self::input( sprintf( __( 'Payment %d date', 'boxday' ), $i + 2 ), 'plan_dates[]', isset( $dates[ $i ] ) ? $dates[ $i ] : '', array( 'type' => 'date' ) );
		}
		echo '</div>';
	}

	/**
	 * Step 6: the look, read from the site, with a live preview.
	 */
	private static function step_look() {
		$look = Format::look();
		$read = ! get_option( 'boxday_look_set' );
		wp_enqueue_script( 'boxday-look', BOXDAY_URL . 'assets/look.js', array(), BOXDAY_VERSION, true );
		echo '<p>' . esc_html__( 'We took these from your site. Change anything you like.', 'boxday' ) . '</p>';
		echo '<div class="bd-look" data-look-read="' . ( $read ? esc_url( self::sample_url() ) : '' ) . '"><div class="bd-fields">';
		self::input( __( 'Accent color', 'boxday' ), 'accent', $look['accent'], array( 'type' => 'color' ) );
		echo '<label>' . esc_html__( 'Corners', 'boxday' ) . '<span class="bd-range"><input type="range" name="radius" min="0" max="30" value="' . (int) $look['radius'] . '"><output>' . (int) $look['radius'] . ' px</output></span></label>';
		echo '<label class="bd-tick"><input type="checkbox" name="caps" value="1"' . checked( (bool) $look['caps'], true, false ) . '> ' . esc_html__( 'Capital letters on buttons', 'boxday' ) . '</label></div>';
		echo '<div class="bd-prev" aria-hidden="true"><b>' . esc_html( Format::word_cap() ) . '</b><span class="bd-chip">' . esc_html__( 'Every week', 'boxday' ) . '</span><span class="bd-chip">' . esc_html__( 'Every other week', 'boxday' ) . '</span><span class="bd-btn">' . esc_html__( 'Continue to checkout', 'boxday' ) . '</span></div></div>';
	}

	/**
	 * A page of the site with its buttons on show, for the look reader.
	 *
	 * @return string
	 */
	private static function sample_url() {
		$ids = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => 1,
				'return' => 'ids',
			)
		);
		return $ids ? (string) get_permalink( $ids[0] ) : home_url( '/' );
	}

	/**
	 * Step 7: the pages go live and the self-test runs.
	 *
	 * @param array|null $season Season set up.
	 */
	private static function ready( $season ) {
		$url = Pages::url( 'signup' );
		/* translators: %s: link. */
		echo '<p class="bd-live">' . esc_html( sprintf( __( 'Your sign-up page is live: %s', 'boxday' ), '' ) ) . '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $url ) . '</a></p>';
		self::self_test();
		echo '<p class="bd-acts"><a class="button button-primary button-hero" href="' . esc_url( admin_url( 'admin.php?page=boxday' ) ) . '">' . esc_html__( 'Go to Today', 'boxday' ) . '</a>';
		if ( $season ) {
			echo '<a class="button button-hero" href="' . esc_url( admin_url( 'admin.php?page=boxday-seasons&edit=' . (int) $season['id'] ) ) . '">' . esc_html__( 'Fine-tune the season', 'boxday' ) . '</a>';
		}
		echo '</p>';
	}

	/**
	 * The self-test table, with each fix.
	 */
	public static function self_test() {
		echo '<table class="widefat bd-test"><tbody>';
		foreach ( Self_Test::lines() as $line ) {
			echo '<tr><td class="' . ( $line[0] ? 'bd-ok' : 'bd-bad' ) . '">' . ( $line[0] ? '✓' : '!' ) . '</td><td>' . esc_html( $line[1] );
			if ( ! empty( $line[2] ) ) {
				echo ' <a class="button" href="' . esc_url( $line[2][1] ) . '">' . esc_html( $line[2][0] ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Saves a step and moves on. Each step saves on its own, so setup can be left and resumed.
	 *
	 * @param int   $step Step.
	 * @param array $in   Request.
	 * @return array Where to go next.
	 */
	private static function save_step( $step, $in ) {
		$state = self::state();
		$id    = (int) $state['season'];
		if ( 1 === $step ) {
			$look          = Format::look();
			$look['farm']  = sanitize_text_field( $in['farm'] );
			$look['email'] = sanitize_email( $in['email'] );
			$look['phone'] = sanitize_text_field( $in['phone'] );
			$look['word']  = in_array( $in['word'], array( 'share', 'box', 'basket' ), true ) ? $in['word'] : 'share';
			update_option( 'boxday_look', $look );
		} elseif ( 2 === $step ) {
			$data = array(
				'name'         => sanitize_text_field( $in['name'] ),
				'weeks'        => max( 1, min( 52, absint( $in['weeks'] ) ) ),
				'signup_open'  => self::date( $in['signup_open'] ) ? self::date( $in['signup_open'] ) . ' 00:00:00' : null,
				'signup_close' => self::date( $in['signup_close'] ) ? self::date( $in['signup_close'] ) . ' 23:59:00' : null,
			);
			if ( '' === $data['name'] ) {
				return self::back( 2, __( 'Please give your season a name.', 'boxday' ) );
			}
			$taken = $id && Db::value( 'SELECT id FROM ' . Db::t( 'signup' ) . ' WHERE season_id = %d LIMIT 1', array( $id ) );
			if ( ! $taken && self::date( $in['first_week'] ) ) {
				$data['first_week'] = Calendar::monday_of( self::date( $in['first_week'] ) );
			}
			if ( $id && Seasons::get( $id ) ) {
				Db::update( 'season', $data, array( 'id' => $id ) );
			} else {
				$id = Db::insert(
					'season',
					$data + array(
						'status'      => 'draft',
						'first_week'  => Calendar::monday_of( self::date( $in['first_week'] ) ? self::date( $in['first_week'] ) : wp_date( 'Y-m-d' ) ),
						'cutoff_days' => 2,
						'cutoff_time' => '20:00:00',
						'skip_rule'   => 'skip',
						'max_skips'   => 2,
						'swaps_on'    => 1,
						'swap_limit'  => 2,
						'site_change' => 'both',
						'late_join'   => 1,
						'prorate'     => 1,
						'plan_mode'   => 'full',
						'plan_dates'  => '[]',
					)
				);
			}
			$state['season'] = $id;
			Seasons::build_weeks( $id );
		} elseif ( 3 === $step && $id ) {
			$name = sanitize_text_field( $in['share'] );
			if ( '' === $name ) {
				return self::back( 3, __( 'Please name what you\'re offering.', 'boxday' ) );
			}
			$shares   = Seasons::shares( $id, 'main' );
			$share_id = $shares ? (int) $shares[0]['id'] : 0;
			$data     = array(
				'season_id'  => $id,
				'name'       => $name,
				'kind'       => 'main',
				'price_mode' => 'box' === $in['price_mode'] ? 'box' : 'season',
			);
			if ( $share_id ) {
				Db::update( 'share', $data, array( 'id' => $share_id ) );
			} else {
				$share_id = Db::insert( 'share', $data );
			}
			foreach ( (array) $in['sizes'] as $size ) {
				$size_name = sanitize_text_field( $size['name'] );
				$prices    = array(
					'price_weekly'   => '' === trim( (string) $size['weekly'] ) ? null : max( 0.0, (float) $size['weekly'] ),
					'price_biweekly' => '' === trim( (string) $size['biweekly'] ) ? null : max( 0.0, (float) $size['biweekly'] ),
				);
				if ( '' === $size_name || ( null === $prices['price_weekly'] && null === $prices['price_biweekly'] ) ) {
					continue;
				}
				$fields = array(
					'share_id' => $share_id,
					'name'     => $size_name,
				) + $prices;
				if ( absint( $size['id'] ) ) {
					Db::update( 'size', $fields, array( 'id' => absint( $size['id'] ) ) );
				} else {
					Db::insert( 'size', $fields );
				}
			}
			if ( ! Seasons::sizes( $share_id ) ) {
				return self::back( 3, __( 'Please add at least one size with a price.', 'boxday' ) );
			}
			Carrier::ensure( $share_id );
		} elseif ( 4 === $step && $id ) {
			$rows = (array) $in['sites'];
			if ( ! empty( $in['route']['name'] ) || ! empty( $in['route']['zip_prefixes'] ) ) {
				$route         = (array) $in['route'];
				$route['type'] = 'delivery';
				$route['name'] = '' !== trim( (string) $route['name'] ) ? $route['name'] : __( 'Home delivery', 'boxday' );
				$rows[]        = $route;
			}
			foreach ( $rows as $row ) {
				$name = sanitize_text_field( $row['name'] );
				if ( '' === $name ) {
					continue;
				}
				$data = array(
					'season_id'    => $id,
					'type'         => isset( $row['type'] ) && 'delivery' === $row['type'] ? 'delivery' : 'pickup',
					'name'         => $name,
					'address'      => isset( $row['address'] ) ? sanitize_text_field( $row['address'] ) : '',
					'weekday'      => max( 1, min( 7, absint( $row['weekday'] ) ) ),
					'time_from'    => sanitize_text_field( $row['time_from'] ),
					'time_to'      => sanitize_text_field( $row['time_to'] ),
					'capacity'     => '' === trim( (string) $row['capacity'] ) ? null : absint( $row['capacity'] ),
					'zip_prefixes' => isset( $row['zip_prefixes'] ) ? preg_replace( '/[^0-9, ]/', '', (string) $row['zip_prefixes'] ) : '',
					'fee_per_box'  => isset( $row['fee_per_box'] ) ? max( 0.0, (float) $row['fee_per_box'] ) : 0,
				);
				if ( absint( $row['id'] ) ) {
					Db::update( 'site', $data, array( 'id' => absint( $row['id'] ) ) );
				} else {
					Db::insert( 'site', $data );
				}
			}
			if ( ! Seasons::sites( $id ) ) {
				return self::back( 4, __( 'Please choose where you\'ll collect your box.', 'boxday' ) );
			}
		} elseif ( 5 === $step && $id ) {
			$dates = array_slice( array_values( array_filter( array_map( array( __CLASS__, 'date' ), (array) $in['plan_dates'] ) ) ), 0, Pricing::MAX_LATER_PAYMENTS );
			sort( $dates );
			Db::update(
				'season',
				array(
					'plan_mode'     => ! empty( $in['parts'] ) && $dates ? 'parts' : 'full',
					'deposit_type'  => 'percent' === $in['deposit_type'] ? 'percent' : 'amount',
					'deposit_value' => max( 0.0, (float) $in['deposit_value'] ),
					'plan_dates'    => wp_json_encode( $dates ),
				),
				array( 'id' => $id )
			);
		} elseif ( 6 === $step ) {
			$look           = Format::look();
			$accent         = sanitize_hex_color( $in['accent'] );
			$look['accent'] = $accent ? $accent : $look['accent'];
			$look['radius'] = min( 30, absint( $in['radius'] ) );
			$look['caps']   = empty( $in['caps'] ) ? 0 : 1;
			update_option( 'boxday_look', $look );
			update_option( 'boxday_look_set', 1 );
			Pages::create();
			if ( $id ) {
				Db::update( 'season', array( 'status' => 'live' ), array( 'id' => $id ) );
			}
		}
		$state['step'] = max( (int) $state['step'], $step + 1 );
		update_option( 'boxday_setup', $state, false );
		return array(
			'page' => 'boxday-setup',
			'step' => $step + 1,
		);
	}

	/**
	 * A clean Y-m-d, or an empty string.
	 *
	 * @param mixed $value Input.
	 * @return string
	 */
	public static function date( $value ) {
		$value = sanitize_text_field( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/**
	 * Back to a step with a message.
	 *
	 * @param int    $step Step.
	 * @param string $err  Message.
	 * @return array
	 */
	private static function back( $step, $err ) {
		return array(
			'page' => 'boxday-setup',
			'step' => $step,
			'err'  => rawurlencode( $err ),
		);
	}

	/**
	 * Runs setup and settings actions.
	 *
	 * @param string $task Action.
	 * @param array  $in   Request.
	 * @return array|null Where to go back to.
	 */
	public static function action( $task, $in ) {
		switch ( $task ) {
			case 'setup':
				return self::save_step( max( 1, min( 6, absint( $in['step'] ) ) ), $in );
			case 'accept_checks':
				// The owner's own click turns on WooCommerce's Check payments. Nothing is installed.
				$settings            = (array) get_option( 'woocommerce_cheque_settings', array() );
				$settings['enabled'] = 'yes';
				update_option( 'woocommerce_cheque_settings', $settings );
				return array(
					'page' => 'boxday-setup',
					'step' => 5,
				);
			case 'test_email':
				$to = Format::look()['email'];
				wp_mail( $to, __( 'BoxDay test email', 'boxday' ), __( 'If you can read this, your site can send BoxDay\'s emails.', 'boxday' ) );
				return array(
					'page'   => 'boxday-settings',
					'tested' => 1,
				);
			case 'more_settings':
				update_option( 'boxday_waitlist_auto', empty( $in['waitlist_auto'] ) ? 'no' : 'yes' );
				update_option( 'boxday_offer_hours', max( 1, min( 336, absint( $in['offer_hours'] ) ) ) );
				update_option( 'boxday_week_posts', empty( $in['week_posts'] ) ? 'no' : 'yes' );
				update_option( 'boxday_delete_data', empty( $in['delete_data'] ) ? 'no' : 'yes' );
				return array(
					'page'  => 'boxday-settings',
					'saved' => 1,
				);
		}
		return null;
	}
}
