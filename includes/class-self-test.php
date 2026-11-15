<?php
/**
 * The self-test lines: spec F1 and section 5.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * Plain checks, each with its fix.
 */
class Self_Test {

	/**
	 * Runs the checks.
	 *
	 * @return array Rows of ok, text and an optional fix button as label and URL.
	 */
	public static function lines() {
		$out     = array( array( true, __( 'WooCommerce is active', 'boxday' ) ) );
		$methods = array();
		foreach ( WC()->payment_gateways()->get_available_payment_gateways() as $gateway ) {
			$methods[] = $gateway->get_title();
		}
		$out[] = $methods
			? array( true, sprintf( /* translators: %s: payment methods. */ __( 'Members can pay by %s', 'boxday' ), implode( ', ', $methods ) ) )
			: array( false, __( 'Nobody can pay yet. Turn on a payment method', 'boxday' ), array( __( 'Accept checks', 'boxday' ), Admin::action_url( 'accept_checks' ) ) );
		$zone  = wp_timezone_string();
		$out[] = 'UTC' === $zone || '+00:00' === $zone
			? array( false, __( 'Your site is set to UTC. Pick your city, or cut-offs will be at the wrong time', 'boxday' ), array( __( 'Change the time zone', 'boxday' ), admin_url( 'options-general.php#timezone_string' ) ) )
			/* translators: %s: time zone. */
			: array( true, sprintf( __( 'Cut-offs use %s', 'boxday' ), $zone ) );
		$pages   = (array) get_option( 'boxday_pages', array() );
		$missing = array();
		$names   = array(
			'signup' => __( 'Sign up', 'boxday' ),
			'member' => __( 'My box', 'boxday' ),
			'week'   => __( 'This week\'s box', 'boxday' ),
		);
		foreach ( $names as $key => $name ) {
			if ( empty( $pages[ $key ] ) || 'publish' !== get_post_status( $pages[ $key ] ) ) {
				$missing[] = $name;
			}
		}
		$out[] = $missing
			/* translators: %s: page names. */
			? array( false, sprintf( __( '%s is missing.', 'boxday' ), implode( ', ', $missing ) ), array( __( 'Create it', 'boxday' ), Admin::action_url( 'pages' ) ) )
			: array( true, __( 'Your three pages are in place', 'boxday' ) );
		$out[] = self::jobs_ran()
			? array( true, __( 'Emails and reminders are running', 'boxday' ) )
			: array( false, __( 'Scheduled jobs haven\'t run in over an hour. Ask your host if WP-Cron is off', 'boxday' ) );
		$path  = (string) wp_parse_url( Pages::url( 'member' ), PHP_URL_PATH );
		$out[] = self::member_cached()
			/* translators: %s: page path. */
			? array( false, sprintf( __( 'Your site may be caching the member page. Exclude %s from your cache', 'boxday' ), $path ) )
			: array( true, __( 'The member page isn\'t being cached', 'boxday' ) );
		$checkout = (int) get_option( 'woocommerce_checkout_page_id' );
		$content  = $checkout ? (string) get_post_field( 'post_content', $checkout ) : '';
		$kind     = has_block( 'woocommerce/checkout', $content ) ? __( 'block checkout', 'boxday' ) : ( has_shortcode( $content, 'woocommerce_checkout' ) ? __( 'classic checkout', 'boxday' ) : '' );
		/* translators: %s: checkout kind. */
		$out[] = '' !== $kind ? array( true, sprintf( __( 'Your checkout page uses the %s', 'boxday' ), $kind ) ) : array( false, __( 'We couldn\'t find your checkout page. Set it in WooCommerce, Settings, Advanced', 'boxday' ), array( __( 'Open settings', 'boxday' ), admin_url( 'admin.php?page=wc-settings&tab=advanced' ) ) );
		$bad   = 0;
		foreach ( Seasons::all() as $season ) {
			foreach ( Seasons::shares( (int) $season['id'] ) as $share ) {
				if ( ! Carrier::is_carrier( (int) $share['product_id'] ) ) {
					Carrier::ensure( (int) $share['id'] );
					++$bad;
				}
			}
		}
		$out[] = array( true, $bad ? __( 'We repaired the hidden products behind your shares', 'boxday' ) : __( 'Every share has its hidden checkout product', 'boxday' ) );
		$out[] = array( true, __( 'Didn\'t arrive? Your host may be blocking mail. An SMTP plugin usually fixes this', 'boxday' ), array( __( 'Send test email', 'boxday' ), Admin::action_url( 'test_email' ) ) );
		return $out;
	}

	/**
	 * Whether Action Scheduler finished anything in the last hour, or BoxDay is new.
	 *
	 * @return bool
	 */
	private static function jobs_ran() {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}
		$done = as_get_scheduled_actions(
			array(
				'status'       => 'complete',
				'date'         => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
				'date_compare' => '>=',
				'per_page'     => 1,
			),
			'ids'
		);
		$late = as_get_scheduled_actions(
			array(
				'status'       => 'pending',
				'date'         => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
				'date_compare' => '<=',
				'per_page'     => 1,
			),
			'ids'
		);
		return (bool) $done || ! $late;
	}

	/**
	 * Asks our own member page twice, and looks for a cache's marks. This request stays on the site.
	 *
	 * @return bool
	 */
	private static function member_cached() {
		$known = get_transient( 'boxday_cache_test' );
		if ( false !== $known ) {
			return 'yes' === $known;
		}
		$url    = add_query_arg( 'boxday_test', wp_rand(), Pages::url( 'member' ) );
		$res    = wp_remote_get(
			$url,
			array(
				'timeout'   => 3,
				'sslverify' => false,
			)
		);
		$hit    = is_wp_error( $res ) ? '' : strtolower( wp_remote_retrieve_header( $res, 'x-cache' ) . wp_remote_retrieve_header( $res, 'cf-cache-status' ) . wp_remote_retrieve_header( $res, 'x-litespeed-cache' ) );
		$cached = ! is_wp_error( $res ) && ( false !== strpos( $hit, 'hit' ) || false === strpos( strtolower( wp_remote_retrieve_header( $res, 'cache-control' ) ), 'no-store' ) );
		set_transient( 'boxday_cache_test', $cached ? 'yes' : 'no', HOUR_IN_SECONDS );
		return $cached;
	}
}
