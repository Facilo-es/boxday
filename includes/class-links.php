<?php
/**
 * Private links: spec L14. Nothing per link is stored, so a link can be rebuilt for any email.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and checks tokens.
 */
class Links {

	/**
	 * The MAC for a token's fields.
	 *
	 * @param string $kind    `member`, `helper` or `offer`.
	 * @param string $id      Row id, or `site-week` for a helper.
	 * @param int    $version Link version.
	 * @return string
	 */
	private static function mac( $kind, $id, $version ) {
		return hash_hmac( 'sha256', $kind . '|' . $id . '|' . $version, (string) get_option( 'boxday_secret' ) );
	}

	/**
	 * Builds a token.
	 *
	 * @param string $kind    Token kind.
	 * @param string $id      Id.
	 * @param int    $version Link version.
	 * @return string
	 */
	public static function token( $kind, $id, $version = 1 ) {
		$raw = $kind . '.' . $id . '.' . $version . '.' . self::mac( $kind, $id, $version );
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe token, as L14 sets out.
	}

	/**
	 * Reads a token. The caller still compares the version with the row.
	 *
	 * @param string $token Token.
	 * @return array|null kind, id, version.
	 */
	public static function parse( $token ) {
		$raw   = base64_decode( strtr( (string) $token, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- URL-safe token, as L14 sets out.
		$parts = false === $raw ? array() : explode( '.', $raw );
		if ( 4 !== count( $parts ) ) {
			return null;
		}
		list( $kind, $id, $version, $mac ) = $parts;
		if ( ! hash_equals( self::mac( $kind, $id, (int) $version ), $mac ) ) {
			return null;
		}
		return array(
			'kind'    => $kind,
			'id'      => $id,
			'version' => (int) $version,
		);
	}

	/**
	 * The sign-up form's token. It's the same for every visitor, so a cached page and a
	 * logged-in member both pass, which a WordPress nonce can't do here. It only proves the
	 * form came from this site recently; form_token_ok() also turns away other sites' posts.
	 *
	 * @param int $age Days back.
	 * @return string
	 */
	public static function form_token( $age = 0 ) {
		return hash_hmac( 'sha256', 'signup|' . ( (int) floor( time() / DAY_IN_SECONDS ) - $age ), (string) get_option( 'boxday_secret' ) );
	}

	/**
	 * Checks the sign-up form's token, from today or yesterday.
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	public static function form_token_ok( $token ) {
		// Browsers name the posting site in Origin, so a post made from another site is refused.
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		if ( '' !== $origin && wp_parse_url( $origin, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return false;
		}
		return hash_equals( self::form_token(), (string) $token ) || hash_equals( self::form_token( 1 ), (string) $token );
	}

	/**
	 * The member behind a token, if it's current.
	 *
	 * @param string $token Token.
	 * @return array|null Member row.
	 */
	public static function member( $token ) {
		$data = self::parse( $token );
		if ( ! $data || 'member' !== $data['kind'] ) {
			return null;
		}
		$member = Db::get( 'member', (int) $data['id'] );
		return $member && (int) $member['link_version'] === $data['version'] ? $member : null;
	}

	/**
	 * A member's private URL.
	 *
	 * @param array $member Member row.
	 * @return string
	 */
	public static function member_url( $member ) {
		return add_query_arg( 'k', self::token( 'member', (string) $member['id'], (int) $member['link_version'] ), Pages::url( 'member' ) );
	}

	/**
	 * A helper's private URL for one site and one week.
	 *
	 * @param int $site_id Site.
	 * @param int $week_id Week.
	 * @return string
	 */
	public static function helper_url( $site_id, $week_id ) {
		return add_query_arg( 'k', self::token( 'helper', $site_id . '-' . $week_id ), Pages::url( 'member' ) );
	}
}
