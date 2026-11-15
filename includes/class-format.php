<?php
/**
 * Dates, times and money as members read them, and the farm's look.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * Text helpers.
 */
class Format {

	const INK = '#1c1a17';

	/**
	 * Plain money text, such as $650.00.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public static function money( $amount ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * A stored date in any format, in a fixed zone so the day can't move.
	 *
	 * @param string $date   Y-m-d.
	 * @param string $format Date format.
	 * @return string
	 */
	public static function date( $date, $format = 'F j' ) {
		$local = array(
			/* translators: PHP date format for a day and month, such as June 1. */
			'F j'    => _x( 'F j', 'date format', 'boxday' ),
			/* translators: PHP date format for a weekday, day and month, such as Tuesday, June 1. */
			'l, F j' => _x( 'l, F j', 'date format', 'boxday' ),
			/* translators: PHP date format for a short day and month, such as Jun 1. */
			'M j'    => _x( 'M j', 'date format', 'boxday' ),
			/* translators: PHP date format for a full date, such as June 1, 2027. */
			'F j, Y' => _x( 'F j, Y', 'date format', 'boxday' ),
		);
		return wp_date( isset( $local[ $format ] ) ? $local[ $format ] : $format, strtotime( $date . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * A weekday from a date and time, in the site's language.
	 *
	 * @param \DateTimeInterface $when Date and time.
	 * @return string
	 */
	public static function day_of( $when ) {
		return wp_date( 'l', $when->getTimestamp(), $when->getTimezone() );
	}

	/**
	 * A clock time such as 4:00 pm, or noon.
	 *
	 * @param string $time H:i:s.
	 * @return string
	 */
	public static function time( $time ) {
		if ( 0 === strpos( $time, '12:00' ) ) {
			return __( 'noon', 'boxday' );
		}
		/* translators: PHP time format, such as 4:00 pm. Use H:i for a 24-hour clock. */
		return wp_date( _x( 'g:i a', 'time format', 'boxday' ), strtotime( '2000-01-01 ' . $time . ' UTC' ), new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * A time window such as 4:00 to 7:00 pm.
	 *
	 * @param string $from Start, H:i:s.
	 * @param string $to   End, H:i:s.
	 * @return string
	 */
	public static function window( $from, $to ) {
		$start = self::time( $from );
		$end   = self::time( $to );
		if ( preg_match( '/[ap]\.?m\.?$/i', $end ) && substr( $start, -2 ) === substr( $end, -2 ) ) {
			$start = trim( substr( $start, 0, -2 ) );
		}
		return sprintf( /* translators: 1: start time, 2: end time. */ __( '%1$s to %2$s', 'boxday' ), $start, $end );
	}

	/**
	 * The name of a weekday.
	 *
	 * @param int $number 1 Monday to 7 Sunday.
	 * @return string
	 */
	public static function weekday( $number ) {
		return wp_date( 'l', strtotime( '2024-01-0' . (int) $number . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * The farm's word for what members get.
	 *
	 * @return string share, box or basket.
	 */
	public static function word() {
		$words = array(
			'share'  => _x( 'share', 'what members get, in a sentence', 'boxday' ),
			'box'    => _x( 'box', 'what members get, in a sentence', 'boxday' ),
			'basket' => _x( 'basket', 'what members get, in a sentence', 'boxday' ),
		);
		return $words[ self::word_key() ];
	}

	/**
	 * The farm's word for what members get, at the start of a sentence or as a label.
	 *
	 * @return string
	 */
	public static function word_cap() {
		$words = array(
			'share'  => _x( 'Share', 'what members get, as a label', 'boxday' ),
			'box'    => _x( 'Box', 'what members get, as a label', 'boxday' ),
			'basket' => _x( 'Basket', 'what members get, as a label', 'boxday' ),
		);
		return $words[ self::word_key() ];
	}

	/**
	 * The farm's choice: share, box or basket.
	 *
	 * @return string
	 */
	public static function word_key() {
		$look = self::look();
		return in_array( $look['word'], array( 'box', 'basket' ), true ) ? $look['word'] : 'share';
	}

	/**
	 * A weekday as a repeating day, such as Tuesdays.
	 *
	 * @param int $number 1 Monday to 7 Sunday.
	 * @return string
	 */
	public static function weekdays( $number ) {
		$days = array(
			1 => __( 'Mondays', 'boxday' ),
			2 => __( 'Tuesdays', 'boxday' ),
			3 => __( 'Wednesdays', 'boxday' ),
			4 => __( 'Thursdays', 'boxday' ),
			5 => __( 'Fridays', 'boxday' ),
			6 => __( 'Saturdays', 'boxday' ),
			7 => __( 'Sundays', 'boxday' ),
		);
		return isset( $days[ (int) $number ] ) ? $days[ (int) $number ] : '';
	}

	/**
	 * The farm's name.
	 *
	 * @return string
	 */
	public static function farm() {
		$look = self::look();
		return '' !== $look['farm'] ? $look['farm'] : get_bloginfo( 'name' );
	}

	/**
	 * The saved look, over defaults read from the site.
	 *
	 * @return array
	 */
	public static function look() {
		$saved = (array) get_option( 'boxday_look', array() );
		return array_merge(
			array(
				'farm'   => '',
				'email'  => get_option( 'admin_email' ),
				'phone'  => '',
				'word'   => 'share',
				'accent' => self::site_accent(),
				'radius' => 12,
				'caps'   => 0,
			),
			$saved
		);
	}

	/**
	 * The accent from the block theme's styles, or a neutral green.
	 *
	 * @return string
	 */
	private static function site_accent() {
		$color = function_exists( 'wp_get_global_styles' ) ? wp_get_global_styles( array( 'elements', 'button', 'color', 'background' ) ) : '';
		return is_string( $color ) && preg_match( '/^#[0-9a-f]{6}$/i', $color ) ? $color : '#2f6b3f';
	}

	/**
	 * Contrast ratio of two #rrggbb colors.
	 *
	 * @param string $a Color.
	 * @param string $b Color.
	 * @return float
	 */
	public static function contrast( $a, $b ) {
		$lum = function ( $hex ) {
			$sum = 0;
			foreach ( array(
				1 => 0.2126,
				3 => 0.7152,
				5 => 0.0722,
			) as $at => $weight ) {
				$c    = hexdec( substr( $hex, $at, 2 ) ) / 255;
				$sum += $weight * ( $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 ) );
			}
			return $sum;
		};
		$x   = $lum( $a );
		$y   = $lum( $b );
		return ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 );
	}

	/**
	 * The look as CSS variables for the sealed box.
	 *
	 * @return string
	 */
	public static function css_vars() {
		$look   = self::look();
		$accent = sanitize_hex_color( $look['accent'] );
		$accent = $accent && 7 === strlen( $accent ) ? $accent : '#2f6b3f';
		$on     = self::contrast( $accent, '#ffffff' ) >= self::contrast( $accent, self::INK ) ? '#ffffff' : self::INK;
		$ink    = self::contrast( $accent, '#ffffff' ) >= 3 ? $accent : self::INK;
		$font   = function_exists( 'wp_get_global_styles' ) ? wp_get_global_styles( array( 'elements', 'heading', 'typography', 'fontFamily' ) ) : '';
		$font   = is_string( $font ) && preg_match( '/^[\w\s,"\'()\-]+$/', $font ) ? $font : 'inherit';
		return sprintf(
			'.boxday-root{--accent:%1$s;--on:%2$s;--accent-ink:%3$s;--r:%4$dpx;--tt:%5$s;--fh:%6$s;--fb:inherit}',
			$accent,
			$on,
			$ink,
			(int) $look['radius'],
			$look['caps'] ? 'uppercase' : 'none',
			$font
		);
	}

	/**
	 * A bundled crop photo matched by name, or an empty string.
	 *
	 * @param string $name Crop or share name.
	 * @return string
	 */
	public static function crop_photo( $name ) {
		$key = strtolower( $name );
		foreach ( array( 'carrot', 'tomato', 'lettuce', 'chard', 'beet', 'kale', 'egg', 'flower' ) as $crop ) {
			if ( false !== strpos( $key, $crop ) ) {
				return BOXDAY_URL . 'assets/crops/' . $crop . '.webp';
			}
		}
		return '';
	}
}
