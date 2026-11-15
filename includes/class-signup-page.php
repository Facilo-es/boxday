<?php
/**
 * The sign-up page, Option B: every date the member gets lights up. All sums come from Quote.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the sign-up form for a set of choices.
 */
class Signup_Page {

	/**
	 * The season to show.
	 *
	 * @param int $id Season from the shortcode, or 0.
	 * @return array|null
	 */
	public static function pick_season( $id ) {
		if ( ! $id ) {
			/**
			 * Filters which season the sign-up page shows when the page doesn't name one.
			 *
			 * @param int $id Season id, or 0 for the first open season.
			 */
			$id = (int) apply_filters( 'boxday_signup_season', 0 );
		}
		if ( $id ) {
			return Seasons::get( $id );
		}
		$open = Seasons::open();
		if ( $open ) {
			return $open[0];
		}
		foreach ( array_reverse( Seasons::all() ) as $season ) {
			if ( 'draft' !== Seasons::state( $season ) && 'finished' !== Seasons::state( $season ) ) {
				return $season;
			}
		}
		return null;
	}

	/**
	 * Fills blanks in the choices with the first thing on offer.
	 *
	 * @param array $season  Season row.
	 * @param array $choices Choices so far.
	 * @return array|null Null when nothing can be sold.
	 */
	private static function defaults( $season, $choices ) {
		$sizes = self::main_sizes( $season );
		$sites = self::open_sites( $season );
		if ( ! $sizes || ! $sites ) {
			return null;
		}
		$choices = array_merge(
			array(
				'season_id' => (int) $season['id'],
				'size_id'   => 0,
				'schedule'  => 'w',
				'site'      => '',
				'addons'    => array(),
				'plan'      => 'parts' === $season['plan_mode'] ? 'parts' : 'full',
				'zip'       => '',
				'street'    => '',
				'agree'     => false,
				'offer'     => '',
			),
			$choices
		);
		if ( ! $choices['season_id'] ) {
			$choices['season_id'] = (int) $season['id'];
		}
		if ( ! in_array( (string) $choices['size_id'], array_map( 'strval', array_column( $sizes, 'id' ) ), true ) ) {
			$choices['size_id'] = (int) $sizes[0]['id'];
		}
		$ids = array_map( 'strval', array_column( $sites, 'id' ) );
		if ( 'delivery' !== $choices['site'] && ! in_array( (string) $choices['site'], $ids, true ) ) {
			$choices['site'] = 'delivery' === $sites[0]['type'] ? 'delivery' : (string) $sites[0]['id'];
		}
		return $choices;
	}

	/**
	 * Sizes of the season's main shares that still have a place.
	 *
	 * @param array $season Season row.
	 * @return array Size rows with their share.
	 */
	private static function main_sizes( $season ) {
		$out = array();
		foreach ( Seasons::shares( (int) $season['id'], 'main' ) as $share ) {
			if ( ! Capacity::share_fits( $share ) ) {
				continue;
			}
			foreach ( Seasons::sizes( (int) $share['id'] ) as $size ) {
				$size['share'] = $share;
				$out[]         = $size;
			}
		}
		return $out;
	}

	/**
	 * Sites with room for anyone at all.
	 *
	 * @param array $season Season row.
	 * @return array
	 */
	private static function open_sites( $season ) {
		$out = array();
		foreach ( Seasons::sites( (int) $season['id'] ) as $site ) {
			if ( Capacity::site_has_room( $site, 'b', 'A' ) || Capacity::site_has_room( $site, 'b', 'B' ) || Capacity::site_has_room( $site, 'w', '' ) ) {
				$out[] = $site;
			}
		}
		return $out;
	}

	/**
	 * Renders the page. All values are escaped here.
	 *
	 * @param array  $season  Season row.
	 * @param array  $choices Choices.
	 * @param string $error   Message to show.
	 * @return string
	 */
	public static function html( $season, $choices = array(), $error = '' ) {
		$state = Seasons::signup_open( $season ) ? 'open' : Seasons::state( $season );
		if ( 'upcoming' === $state ) {
			return self::message( $season, sprintf( /* translators: %s: date. */ __( 'Sign-ups open %s.', 'boxday' ), Format::date( substr( $season['signup_open'], 0, 10 ) ) ) );
		}
		if ( 'open' !== $state ) {
			return self::message( $season, sprintf( /* translators: %s: season name. */ __( 'Sign-ups are closed for %s.', 'boxday' ), $season['name'] ) );
		}
		$choices = self::defaults( $season, $choices );
		if ( ! $choices ) {
			return self::message( $season, self::full_line() ) . '<div class="wrap">' . Waitlist::form_html( $season ) . '</div>';
		}
		$resolved = Cart::resolve_site( $choices );
		$no_route = false;
		if ( ! $resolved['site_id'] ) {
			$routes              = Seasons::sites( (int) $season['id'], 'delivery' );
			$no_route            = '' !== $choices['zip'];
			$resolved['site_id'] = $routes ? (int) $routes[0]['id'] : 0;
		}
		$q = Quote::build( $resolved );
		if ( is_wp_error( $q ) ) {
			return self::message( $season, $q->get_error_message() );
		}
		if ( $q['boxes']['left'] < 1 || ( ! $season['late_join'] && $q['boxes']['left'] < $q['boxes']['total'] ) ) {
			return self::message( $season, sprintf( /* translators: %s: season name. */ __( 'Sign-ups are closed for %s.', 'boxday' ), $season['name'] ) );
		}
		$word = Format::word();
		$main = $q['lines'][0];

		ob_start();
		$offer = '' !== $choices['offer'] ? Waitlist::from_token( $choices['offer'] ) : null;
		if ( Waitlist::is_open( $offer ) ) {
			/* translators: %s: date and time. */
			echo '<p class="msg ok" role="status">' . esc_html( sprintf( __( 'We\'re holding this place for you until %s.', 'boxday' ), Format::date( get_date_from_gmt( $offer['expires_at'], 'Y-m-d' ), 'l, F j' ) . ', ' . Format::time( get_date_from_gmt( $offer['expires_at'], 'H:i:s' ) ) ) ) . '</p>';
		}
		if ( '' !== $error ) {
			echo '<p class="err" role="alert">' . esc_html( $error ) . '</p>';
		}
		?>
		<div class="wrap">
			<section class="top"><h1><?php echo esc_html( $season['name'] ); ?></h1><p class="sub"><?php echo esc_html( self::season_line( $season, $q ) ); ?></p></section>
			<section class="cal">
				<div class="hl">
					<div class="n"><?php echo (int) $q['boxes']['left']; ?><em><?php esc_html_e( 'boxes', 'boxday' ); ?></em>
						<small><?php echo esc_html( sprintf( /* translators: %s: date. */ __( 'First box: %s.', 'boxday' ), Format::date( $q['boxes']['first_date'] ) ) ); ?></small></div>
					<div class="m2"><?php esc_html_e( 'Total', 'boxday' ); ?><b><?php echo esc_html( Format::money( $q['total'] ) ); ?></b></div>
				</div>
				<?php self::calendar( $q ); ?>
			</section>
			<section class="step"><h2><?php echo esc_html( sprintf( /* translators: %s: word for a share. */ __( 'Choose your %s', 'boxday' ), $word ) ); ?></h2>
				<?php self::size_rows( $season, $choices, $q ); ?>
			</section>
			<section class="step"><h2><?php esc_html_e( 'How often?', 'boxday' ); ?></h2>
				<?php self::schedule_rows( $main, $q ); ?>
			</section>
			<section class="step"><h2><?php esc_html_e( 'Where will you collect it?', 'boxday' ); ?></h2>
				<?php self::site_rows( $season, $choices, $q, $no_route ); ?>
			</section>
			<?php self::addon_rows( $season, $choices, $q ); ?>
			<section class="step" id="total"><h2><?php esc_html_e( 'Your total', 'boxday' ); ?></h2>
				<?php self::summary( $season, $choices, $q ); ?>
			</section>
			<?php if ( '' === $choices['offer'] && self::something_full( $season ) ) : ?>
				<details class="step wld"><summary><?php echo esc_html( self::full_line() ); ?></summary><?php self::out( Waitlist::form_html( $season ) ); ?></details>
			<?php endif; ?>
		</div>
		<?php
		return '<div data-state="' . esc_attr( (string) wp_json_encode( $choices ) ) . '">' . ob_get_clean() . '</div>';
	}

	/**
	 * F17's full line.
	 *
	 * @return string
	 */
	private static function full_line() {
		return sprintf( /* translators: %s: word for a share, capitalized. */ __( '%s is full for now. Join the waitlist and we\'ll email you if a place opens up.', 'boxday' ), Format::word_cap() );
	}

	/**
	 * Whether any main share or any site is full, so the waitlist is worth showing.
	 *
	 * @param array $season Season row.
	 * @return bool
	 */
	private static function something_full( $season ) {
		foreach ( Seasons::shares( (int) $season['id'], 'main' ) as $share ) {
			if ( ! Capacity::share_fits( $share ) ) {
				return true;
			}
		}
		return count( self::open_sites( $season ) ) < count( Seasons::sites( (int) $season['id'] ) );
	}

	/**
	 * A page with only a message under the season's name.
	 *
	 * @param array  $season Season row.
	 * @param string $text   Message.
	 * @return string
	 */
	private static function message( $season, $text ) {
		return '<div class="wrap"><section class="top"><h1>' . esc_html( $season['name'] ) . '</h1></section><p class="msg">' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * The subheading: first and last pickup across sites, and open weeks.
	 *
	 * @param array $season Season row.
	 * @param array $q      Quote.
	 * @return string
	 */
	private static function season_line( $season, $q ) {
		$open  = Calendar::schedule_weeks( $q['weeks'], 'w', '' );
		$days  = array_map( 'intval', array_column( Seasons::sites( (int) $season['id'] ), 'weekday' ) );
		$first = Calendar::pickup_date( $open[0]['start_date'], min( $days ) );
		$last  = Calendar::pickup_date( $open[ count( $open ) - 1 ]['start_date'], max( $days ) );
		return sprintf( /* translators: 1: first date, 2: last date, 3: weeks. */ __( '%1$s to %2$s, %3$d weeks.', 'boxday' ), Format::date( $first ), Format::date( $last ), count( $open ) );
	}

	/**
	 * Option B's calendar: a row per month, and a circle per week.
	 *
	 * @param array $q Quote.
	 */
	private static function calendar( $q ) {
		$mine = array_column( $q['boxes']['weeks'], 'number' );
		$rows = array();
		foreach ( $q['weeks'] as $week ) {
			if ( 'makeup' === $week['state'] ) {
				continue;
			}
			$date  = Calendar::pickup_date( $week['start_date'], $q['site']['weekday'] );
			$month = substr( $date, 0, 7 );
			$shut  = 'closed' === $week['state'];
			$is    = in_array( $week['number'], $mine, true );
			$class = $shut ? 'shut' : ( $is ? 'mine' : 'gap' );
			if ( $is && (int) $week['number'] === (int) $mine[0] ) {
				$class .= ' first';
			}
			$rows[ $month ][] = '<span class="d ' . esc_attr( $class ) . '">' . ( $shut ? '×' : (int) substr( $date, 8, 2 ) ) . '</span>';
		}
		foreach ( $rows as $month => $cells ) {
			echo '<div class="mo"><span>' . esc_html( Format::date( $month . '-15', 'M' ) ) . '</span><div class="ds">' . wp_kses_post( implode( '', $cells ) ) . '</div></div>';
		}
	}

	/**
	 * One row button.
	 *
	 * @param string $key  State key.
	 * @param string $val  State value.
	 * @param bool   $on   Selected.
	 * @param string $name Title.
	 * @param string $sub  Small line.
	 * @param string $end  Right side, already escaped.
	 * @param string $img  Image HTML, already escaped.
	 * @return string
	 */
	private static function row( $key, $val, $on, $name, $sub, $end, $img = '' ) {
		return '<button type="button" class="ro' . ( $on ? ' on' : '' ) . '" data-k="' . esc_attr( $key ) . '" data-v="' . esc_attr( $val ) . '" aria-pressed="' . ( $on ? 'true' : 'false' ) . '"><i></i>' . $img
			. '<div><b>' . esc_html( $name ) . '</b>' . ( '' !== $sub ? '<small>' . esc_html( $sub ) . '</small>' : '' ) . '</div>' . $end . '</button>';
	}

	/**
	 * A share's picture: its photo, a bundled crop, or its first letter.
	 *
	 * @param array  $share Share row.
	 * @param string $name  Name for matching.
	 * @return string
	 */
	public static function picture( $share, $name ) {
		if ( ! empty( $share['photo_id'] ) ) {
			return wp_get_attachment_image( (int) $share['photo_id'], 'thumbnail', false, array( 'alt' => '' ) );
		}
		$url = Format::crop_photo( $name );
		if ( '' !== $url ) {
			return '<img src="' . esc_url( $url ) . '" alt="" loading="lazy">';
		}
		return '<span class="init" aria-hidden="true">' . esc_html( mb_strtoupper( mb_substr( $name, 0, 1 ) ) ) . '</span>';
	}

	/**
	 * Share and size rows.
	 *
	 * @param array $season  Season row.
	 * @param array $choices Choices.
	 * @param array $q       Quote.
	 */
	private static function size_rows( $season, $choices, $q ) {
		$sizes = self::main_sizes( $season );
		$many  = count( array_unique( array_column( array_column( $sizes, 'share' ), 'id' ) ) ) > 1;
		$html  = '';
		foreach ( $sizes as $i => $size ) {
			$price = Quote::size_price( $size, $q['schedule'] );
			$price = null === $price ? Quote::size_price( $size, 'w' === $q['schedule'] ? 'b' : 'w' ) : $price;
			$end   = null === $price ? '' : '<span class="pr">' . esc_html( Format::money( $price ) ) . '</span>';
			$name  = $many ? $size['share']['name'] . ', ' . $size['name'] : $size['name'];
			$match = Format::crop_photo( $size['name'] ) ? $size['name'] : ( $i ? 'kale' : 'tomato' );
			$img   = empty( $size['share']['photo_id'] ) ? self::picture( array(), $match ) : self::picture( $size['share'], $size['name'] );
			$html .= self::row( 'size_id', (string) $size['id'], (int) $size['id'] === (int) $choices['size_id'], $name, (string) $size['note'], $end, $img );
		}
		self::out( $html );
	}

	/**
	 * Echoes a row list built by this class from escaped parts.
	 *
	 * @param string $html HTML.
	 */
	private static function out( $html ) {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each part is escaped where it's built.
	}

	/**
	 * Schedule rows. A blank price means that schedule isn't offered.
	 *
	 * @param array $main Main line.
	 * @param array $q    Quote.
	 */
	private static function schedule_rows( $main, $q ) {
		$html  = '';
		$size  = $main['size'];
		$week  = Quote::size_price( $size, 'w' );
		$every = Quote::size_price( $size, 'b' );
		if ( null !== $week ) {
			$html .= self::row( 'schedule', 'w', 'w' === $q['schedule'], __( 'Every week', 'boxday' ), '', '<span class="pr">' . esc_html( Format::money( $week ) ) . '</span>' );
		}
		if ( null !== $every ) {
			$grp   = 'b' === $q['schedule'] ? $q['grp'] : Capacity::smaller_group( 0, 0 );
			$boxes = Calendar::boxes_left( $q['season'], $q['site'], $q['weeks'], 'b', $grp, Clock::now() );
			$help  = $boxes['left'] ? sprintf( /* translators: 1: boxes, 2: date. */ __( 'You\'ll get %1$d boxes. Your first is %2$s.', 'boxday' ), $boxes['left'], Format::date( $boxes['first_date'] ) ) : '';
			$html .= self::row( 'schedule', 'b', 'b' === $q['schedule'], __( 'Every other week', 'boxday' ), $help, '<span class="pr">' . esc_html( Format::money( $every ) ) . '</span>' );
		}
		self::out( $html );
	}

	/**
	 * Site rows, with one Home delivery row for all routes.
	 *
	 * @param array $season   Season row.
	 * @param array $choices  Choices.
	 * @param array $q        Quote.
	 * @param bool  $no_route The ZIP has no route.
	 */
	private static function site_rows( $season, $choices, $q, $no_route ) {
		$html  = '';
		$sites = self::open_sites( $season );
		foreach ( $sites as $site ) {
			if ( 'pickup' !== $site['type'] ) {
				continue;
			}
			$left  = Capacity::site_left( $site );
			$end   = null !== $left && $left <= 5 ? '<em>' . esc_html( sprintf( /* translators: %d: places left. */ __( '%d left', 'boxday' ), $left ) ) . '</em>' : '';
			$sub   = sprintf( '%1$s %2$s', Format::weekdays( (int) $site['weekday'] ), Format::window( $site['time_from'], $site['time_to'] ) );
			$html .= self::row( 'site', (string) $site['id'], (string) $site['id'] === (string) $choices['site'], $site['name'], $sub, $end );
		}
		$routes = array_values(
			array_filter(
				$sites,
				function ( $site ) {
					return 'delivery' === $site['type'];
				}
			)
		);
		if ( $routes ) {
			$fee   = 'delivery' === $q['site']['type'] ? $q['site']['fee_per_box'] : $routes[0]['fee_per_box'];
			$html .= self::row( 'site', 'delivery', 'delivery' === $choices['site'], __( 'Home delivery', 'boxday' ), sprintf( /* translators: %s: fee. */ __( '%s a box', 'boxday' ), Format::money( (float) $fee ) ), '' );
		}
		self::out( $html );
		if ( 'delivery' !== $choices['site'] ) {
			return;
		}
		?>
		<div class="zip">
			<label><?php esc_html_e( 'Your ZIP code', 'boxday' ); ?><input name="zip" inputmode="numeric" autocomplete="postal-code" value="<?php echo esc_attr( $choices['zip'] ); ?>" data-field="zip"></label>
			<label><?php esc_html_e( 'Street address', 'boxday' ); ?><input name="street" autocomplete="street-address" value="<?php echo esc_attr( $choices['street'] ); ?>" data-field="street"></label>
		</div>
		<?php
		if ( $no_route ) {
			echo '<p class="note" role="alert">' . esc_html( sprintf( /* translators: %s: ZIP code. */ __( 'We don\'t deliver to %s yet. You can pick up at one of these places.', 'boxday' ), $choices['zip'] ) ) . '</p>';
		}
	}

	/**
	 * Add-on rows. Each add-on share sells its first size.
	 *
	 * @param array $season  Season row.
	 * @param array $choices Choices.
	 * @param array $q       Quote.
	 */
	private static function addon_rows( $season, $choices, $q ) {
		$html = '';
		foreach ( Seasons::shares( (int) $season['id'], 'addon' ) as $share ) {
			$sizes = Seasons::sizes( (int) $share['id'] );
			if ( ! $sizes || ! Capacity::share_fits( $share ) ) {
				continue;
			}
			$size  = $sizes[0];
			$price = Quote::size_price( $size, $q['schedule'] );
			if ( null === $price ) {
				continue;
			}
			$on    = in_array( (int) $size['id'], array_map( 'intval', (array) $choices['addons'] ), true );
			$html .= self::row( 'addon', (string) $size['id'], $on, $share['name'], (string) $share['description'], '<span class="pr">' . esc_html( Format::money( $price ) ) . '</span>', self::picture( $share, $share['name'] ) );
		}
		if ( '' !== $html ) {
			echo '<section class="step"><h2>' . esc_html__( 'Add something extra?', 'boxday' ) . '</h2>';
			self::out( $html );
			echo '</section>';
		}
	}

	/**
	 * The total, the plan, the agreement and the button.
	 *
	 * @param array $season  Season row.
	 * @param array $choices Choices.
	 * @param array $q       Quote.
	 */
	private static function summary( $season, $choices, $q ) {
		$main  = $q['lines'][0];
		$site  = $q['site'];
		$boxes = $q['boxes'];
		$sched = 'b' === $q['schedule'] ? __( 'Every other week', 'boxday' ) : __( 'Every week', 'boxday' );
		?>
		<div class="total">
			<b><?php echo esc_html( $main['share']['name'] . ', ' . $main['size']['name'] . ', ' . $sched ); ?></b>
			<span><?php echo esc_html( sprintf( '%1$s, %2$s %3$s', $site['name'], Format::weekdays( (int) $site['weekday'] ), Format::window( $site['time_from'], $site['time_to'] ) ) ); ?></span>
			<span><?php echo esc_html( sprintf( /* translators: 1: date, 2: boxes. */ __( 'First box: %1$s. %2$d boxes.', 'boxday' ), Format::date( $boxes['first_date'] ), $boxes['left'] ) ); ?></span>
			<?php if ( $boxes['left'] < $boxes['total'] ) : ?>
				<span><?php echo esc_html( sprintf( /* translators: 1: boxes left, 2: boxes in all, 3: boxes left. */ __( '%1$d of %2$d boxes left, so you pay for %3$d.', 'boxday' ), $boxes['left'], $boxes['total'], $boxes['left'] ) ); ?></span>
			<?php endif; ?>
			<div class="ln">
				<div class="sum-row"><span><?php esc_html_e( 'Season price', 'boxday' ); ?></span><b><?php echo esc_html( Format::money( $main['price'] ) ); ?></b></div>
				<?php foreach ( array_slice( $q['lines'], 1 ) as $line ) : ?>
					<div class="sum-row"><span><?php echo esc_html( $line['share']['name'] ); ?></span><b><?php echo esc_html( Format::money( $line['price'] ) ); ?></b></div>
				<?php endforeach; ?>
				<?php if ( $main['delivery'] ) : ?>
					<div class="sum-row"><span><?php echo esc_html( sprintf( /* translators: 1: boxes, 2: fee. */ __( 'Delivery: %1$d boxes at %2$s', 'boxday' ), $boxes['left'], Format::money( (float) $site['fee_per_box'] ) ) ); ?></span><b><?php echo esc_html( Format::money( $main['delivery'] ) ); ?></b></div>
				<?php endif; ?>
			</div>
			<div class="big"><?php echo esc_html( sprintf( /* translators: %s: amount. */ __( 'Total: %s', 'boxday' ), Format::money( $q['total'] ) ) ); ?></div>
			<?php if ( 'parts' === $season['plan_mode'] && Seasons::plan_dates( $season ) ) : ?>
				<div class="seg">
					<button type="button" class="<?php echo 'full' === $choices['plan'] ? 'on' : ''; ?>" data-k="plan" data-v="full"><?php esc_html_e( 'Pay in full', 'boxday' ); ?></button>
					<button type="button" class="<?php echo 'parts' === $choices['plan'] ? 'on' : ''; ?>" data-k="plan" data-v="parts"><?php esc_html_e( 'Pay in parts', 'boxday' ); ?></button>
				</div>
			<?php endif; ?>
			<?php if ( $q['plan']['parts'] ) : ?>
				<div class="plan">
					<div class="sum-row tod"><span><?php echo esc_html( sprintf( /* translators: %s: amount. */ __( 'Today: %s', 'boxday' ), Format::money( $q['plan']['today'] ) ) ); ?></span></div>
					<?php foreach ( $q['plan']['parts'] as $part ) : ?>
						<div class="sum-row"><span><?php echo esc_html( Format::date( $part['date'] ) ); ?></span><b><?php echo esc_html( Format::money( $part['amount'] ) ); ?></b></div>
					<?php endforeach; ?>
					<p><?php esc_html_e( 'No fees, no interest. We\'ll email you a link before each payment.', 'boxday' ); ?></p>
				</div>
			<?php endif; ?>
			<form method="post">
				<input type="hidden" name="boxday_signup" value="<?php echo esc_attr( Links::form_token() ); ?>">
				<input type="hidden" name="season_id" value="<?php echo (int) $season['id']; ?>">
				<input type="hidden" name="size_id" value="<?php echo (int) $choices['size_id']; ?>">
				<input type="hidden" name="schedule" value="<?php echo esc_attr( $q['schedule'] ); ?>">
				<input type="hidden" name="site" value="<?php echo esc_attr( $choices['site'] ); ?>">
				<input type="hidden" name="plan" value="<?php echo esc_attr( $choices['plan'] ); ?>">
				<input type="hidden" name="zip" value="<?php echo esc_attr( $choices['zip'] ); ?>">
				<input type="hidden" name="street" value="<?php echo esc_attr( $choices['street'] ); ?>">
				<input type="hidden" name="offer" value="<?php echo esc_attr( $choices['offer'] ); ?>">
				<?php foreach ( (array) $choices['addons'] as $addon ) : ?>
					<input type="hidden" name="addons[]" value="<?php echo (int) $addon; ?>">
				<?php endforeach; ?>
				<?php if ( $season['agreement_version'] ) : ?>
					<button type="button" class="agree<?php echo $choices['agree'] ? ' on' : ''; ?>" data-k="agree" data-v="1"><i></i><span><?php esc_html_e( 'I\'ve read and agree to the member agreement.', 'boxday' ); ?></span></button>
					<input type="hidden" name="agree" value="<?php echo $choices['agree'] ? '1' : ''; ?>">
					<details class="note"><summary><?php esc_html_e( 'Read the member agreement', 'boxday' ); ?></summary><?php echo wp_kses_post( wpautop( (string) $season['agreement_text'] ) ); ?></details>
				<?php endif; ?>
				<button type="submit" class="btn"><?php esc_html_e( 'Continue to checkout', 'boxday' ); ?></button>
			</form>
		</div>
		<?php
	}
}
