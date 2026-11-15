<?php
/**
 * Members screens beyond the list: add by hand, import, waitlist, and the owner's changes to a sign-up.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Screens read only which view to show. Actions check their nonce in Admin::action().

/**
 * Screens and actions for F7 and F17.
 */
class Admin_Roster {

	/**
	 * The tabs above the members list.
	 *
	 * @param string $current Current view.
	 */
	public static function tabs( $current ) {
		$views = array(
			''       => __( 'Members', 'boxday' ),
			'add'    => __( 'Add a member', 'boxday' ),
			'import' => __( 'Import CSV', 'boxday' ),
			'wait'   => __( 'Waitlist', 'boxday' ),
		);
		echo '<nav class="nav-tab-wrapper bd-tabs">';
		foreach ( $views as $key => $label ) {
			$url = add_query_arg(
				array_filter(
					array(
						'page' => 'boxday-members',
						'view' => $key,
					)
				),
				admin_url( 'admin.php' )
			);
			echo '<a class="nav-tab' . ( $key === $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Shows a view, if one is asked for.
	 *
	 * @param array $season Season row.
	 * @return bool Whether a view was shown.
	 */
	public static function view( $season ) {
		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : '';
		if ( ! in_array( $view, array( 'add', 'import', 'wait' ), true ) ) {
			return false;
		}
		echo '<div class="wrap bd-wrap"><h1>' . esc_html__( 'Members', 'boxday' ) . '</h1>';
		self::tabs( $view );
		self::notices();
		if ( 'add' === $view ) {
			self::add_form( $season );
		} elseif ( 'import' === $view ) {
			self::import_screen( $season );
		} else {
			self::waitlist( $season );
		}
		echo '</div>';
		return true;
	}

	/**
	 * Result lines after an action.
	 */
	public static function notices() {
		$lines = array(
			'added'    => __( 'Member added. Their order is on hold until you mark it paid.', 'boxday' ),
			'paid'     => __( 'Marked paid.', 'boxday' ),
			'moved'    => __( 'Saved.', 'boxday' ),
			'offered'  => __( 'Offer sent.', 'boxday' ),
			'nofit'    => __( 'No place fits this person right now.', 'boxday' ),
			'removed'  => __( 'Taken off the list.', 'boxday' ),
			'welcomed' => __( 'Welcome emails sent.', 'boxday' ),
		);
		foreach ( $lines as $key => $text ) {
			if ( ! empty( $_GET[ $key ] ) ) {
				echo '<div class="notice notice-success"><p>' . esc_html( $text ) . '</p></div>';
			}
		}
		if ( ! empty( $_GET['imported'] ) ) {
			$parts = array_map( 'absint', explode( '-', sanitize_text_field( wp_unslash( $_GET['imported'] ) ) ) );
			/* translators: 1: added, 2: changed. */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( 'Import done: %1$d added, %2$d changed. Nobody has been emailed yet.', 'boxday' ), $parts[0], isset( $parts[1] ) ? $parts[1] : 0 ) ) . '</p></div>';
		}
		if ( ! empty( $_GET['err'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['err'] ) ) ) . '</p></div>';
		}
	}

	/**
	 * Options for every size in a season.
	 *
	 * @param array $season Season row.
	 * @param int   $chosen Size id.
	 * @param bool  $main   Main shares only.
	 * @return string Escaped options.
	 */
	private static function size_options( $season, $chosen = 0, $main = true ) {
		$html = '';
		foreach ( Seasons::shares( (int) $season['id'], $main ? 'main' : '' ) as $share ) {
			foreach ( Seasons::sizes( (int) $share['id'] ) as $size ) {
				$html .= '<option value="' . (int) $size['id'] . '"' . selected( $chosen, (int) $size['id'], false ) . '>' . esc_html( $share['name'] . ', ' . $size['name'] ) . '</option>';
			}
		}
		return $html;
	}

	/**
	 * Options for every site in a season.
	 *
	 * @param array $season Season row.
	 * @param int   $chosen Site id.
	 * @return string Escaped options.
	 */
	private static function site_options( $season, $chosen = 0 ) {
		$html = '';
		foreach ( Seasons::sites( (int) $season['id'] ) as $site ) {
			$html .= '<option value="' . (int) $site['id'] . '"' . selected( $chosen, (int) $site['id'], false ) . '>' . esc_html( $site['name'] ) . '</option>';
		}
		return $html;
	}

	/**
	 * Add a member by hand, for cash, check or other.
	 *
	 * @param array $season Season row.
	 */
	private static function add_form( $season ) {
		echo '<p>' . esc_html__( 'For members who pay by cash, check or another way. This makes a real order on hold, so all your money stays in one place. Mark it paid when the money arrives.', 'boxday' ) . '</p>';
		Admin::form_open( 'member_add' );
		echo '<input type="hidden" name="season" value="' . (int) $season['id'] . '"><div class="bd-fields">';
		$fields = array(
			'first_name' => array( __( 'First name', 'boxday' ), 'text', true ),
			'last_name'  => array( __( 'Last name', 'boxday' ), 'text', true ),
			'email'      => array( __( 'Email', 'boxday' ), 'email', true ),
			'phone'      => array( __( 'Phone (optional)', 'boxday' ), 'tel', false ),
		);
		foreach ( $fields as $name => $field ) {
			echo '<label>' . esc_html( $field[0] ) . '<input type="' . esc_attr( $field[1] ) . '" name="' . esc_attr( $name ) . '"' . ( $field[2] ? ' required' : '' ) . '></label>';
		}
		echo '<label>' . esc_html( Format::word_cap() ) . '<select name="size">' . self::size_options( $season ) . '</select></label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in size_options().
		echo '<label>' . esc_html__( 'Pickup', 'boxday' ) . '<select name="site">' . self::site_options( $season ) . '</select></label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in site_options().
		echo '<label>' . esc_html__( 'How often?', 'boxday' ) . '<select name="schedule"><option value="w">' . esc_html__( 'Every week', 'boxday' ) . '</option><option value="b">' . esc_html__( 'Every other week', 'boxday' ) . '</option></select></label>';
		echo '<label>' . esc_html__( 'How will they pay?', 'boxday' ) . '<select name="pay">';
		foreach ( Roster::pay_ways() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>' . esc_html__( 'Street address, for home delivery', 'boxday' ) . '<input type="text" name="street"></label>';
		echo '<label>' . esc_html__( 'ZIP code, for home delivery', 'boxday' ) . '<input type="text" name="zip"></label>';
		echo '</div>';
		submit_button( __( 'Add member', 'boxday' ) );
		echo '</form>';
	}

	/**
	 * Import: upload, preview with each bad row and why, then import.
	 *
	 * @param array $season Season row.
	 */
	private static function import_screen( $season ) {
		$key     = 'boxday_import_' . get_current_user_id();
		$preview = get_transient( $key );
		$waiting = Roster::unwelcomed( (int) $season['id'] );
		if ( $waiting ) {
			/* translators: %d: members. */
			echo '<div class="bd-pb bd-pad"><p>' . esc_html( sprintf( _n( '%d imported member hasn\'t had a welcome email yet.', '%d imported members haven\'t had a welcome email yet.', count( $waiting ), 'boxday' ), count( $waiting ) ) ) . '</p><p><a class="button button-primary" href="' . esc_url( Admin::action_url( 'import_welcome', array( 'season' => $season['id'] ) ) ) . '">' . esc_html__( 'Send welcome emails', 'boxday' ) . '</a></p></div>';
		}
		if ( is_array( $preview ) && (int) $preview['season'] === (int) $season['id'] ) {
			/* translators: 1: good rows, 2: bad rows. */
			echo '<h2 class="bd-sec">' . esc_html( sprintf( __( '%1$d rows ready, %2$d with a problem', 'boxday' ), count( $preview['good'] ), count( $preview['bad'] ) ) ) . '</h2>';
			if ( $preview['bad'] ) {
				echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Row', 'boxday' ) . '</th><th>' . esc_html__( 'Email', 'boxday' ) . '</th><th>' . esc_html__( 'Problem', 'boxday' ) . '</th></tr></thead><tbody>';
				foreach ( $preview['bad'] as $row ) {
					echo '<tr><td>' . (int) $row['line'] . '</td><td>' . esc_html( $row['email'] ) . '</td><td class="bd-bad">' . esc_html( $row['why'] ) . '</td></tr>';
				}
				echo '</tbody></table><p>' . esc_html__( 'Rows with a problem are skipped. Fix them in your file and import it again: rows already imported won\'t be added twice.', 'boxday' ) . '</p>';
			}
			echo '<p class="bd-acts"><a class="button button-primary" href="' . esc_url( Admin::action_url( 'import_run', array( 'season' => $season['id'] ) ) ) . '">' . esc_html( sprintf( /* translators: %d: rows. */ __( 'Import %d members', 'boxday' ), count( $preview['good'] ) ) ) . '</a> <a class="button" href="' . esc_url( Admin::action_url( 'import_clear' ) ) . '">' . esc_html__( 'Start again', 'boxday' ) . '</a></p>';
			return;
		}
		echo '<div class="bd-pb bd-pad"><p>' . esc_html__( 'Bring last year\'s members, or a list from another system. Use one row per member and these columns:', 'boxday' ) . '</p><p><code>' . esc_html( implode( ', ', Roster::columns() ) ) . '</code></p>';
		echo '<p>' . esc_html__( 'Share, size and pickup must match the names in this season. For every other week, write "every other week" in the schedule column. Importing creates no orders and sends no email: members show as paid elsewhere.', 'boxday' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="boxday"><input type="hidden" name="do" value="import_check"><input type="hidden" name="season" value="' . (int) $season['id'] . '">';
		wp_nonce_field( 'boxday_import_check' );
		echo '<p><input type="file" name="csv" accept=".csv,text/csv" required> <button class="button button-primary">' . esc_html__( 'Check the file', 'boxday' ) . '</button></p></form></div>';
	}

	/**
	 * F17: the owner's waitlist.
	 *
	 * @param array $season Season row.
	 */
	private static function waitlist( $season ) {
		$rows = Waitlist::rows( (int) $season['id'] );
		/* translators: %d: hours. */
		echo '<p>' . esc_html( sprintf( __( 'When a place frees up, the first person who fits gets an email and %d hours to sign up. You can change this in Settings.', 'boxday' ), Waitlist::hours() ) ) . ( Waitlist::auto() ? '' : ' <b>' . esc_html__( 'Automatic offers are off.', 'boxday' ) . '</b>' ) . '</p>';
		if ( ! $rows ) {
			echo '<p class="bd-pb bd-pad">' . esc_html__( 'Nobody is waiting.', 'boxday' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'boxday' ) . '</th><th>' . esc_html__( 'Share', 'boxday' ) . '</th><th>' . esc_html__( 'Pickup', 'boxday' ) . '</th><th>' . esc_html__( 'Since', 'boxday' ) . '</th><th>' . esc_html__( 'Status', 'boxday' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$sites = array();
			foreach ( array_filter( array_map( 'intval', explode( ',', $row['site_ids'] ) ) ) as $site_id ) {
				$site    = Db::get( 'site', $site_id );
				$sites[] = $site ? $site['name'] : '';
			}
			$status = 'offered' === $row['status']
				/* translators: %s: date and time. */
				? sprintf( __( 'Offer open until %s', 'boxday' ), Format::date( get_date_from_gmt( $row['expires_at'], 'Y-m-d' ), 'M j' ) . ', ' . Format::time( get_date_from_gmt( $row['expires_at'], 'H:i:s' ) ) )
				: __( 'Waiting', 'boxday' );
			echo '<tr><td><b>' . esc_html( trim( $row['first_name'] . ' ' . $row['last_name'] ) ) . '</b><br>' . esc_html( $row['email'] . ( $row['phone'] ? ', ' . $row['phone'] : '' ) ) . '</td><td>' . esc_html( $row['share'] . ', ' . $row['size'] ) . '</td><td>' . esc_html( implode( ', ', array_filter( $sites ) ) ) . '</td><td>' . esc_html( Format::date( substr( $row['created_at'], 0, 10 ), 'M j' ) ) . '</td><td>' . esc_html( $status ) . '</td><td class="bd-acts">';
			if ( 'waiting' === $row['status'] ) {
				echo '<a class="button" href="' . esc_url( Admin::action_url( 'wait_offer', array( 'row' => $row['id'] ) ) ) . '">' . esc_html__( 'Offer a place', 'boxday' ) . '</a>';
			}
			echo '<a class="button-link-delete" href="' . esc_url( Admin::action_url( 'wait_remove', array( 'row' => $row['id'] ) ) ) . '">' . esc_html__( 'Remove', 'boxday' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * The owner's buttons on one sign-up: mark paid, and change pickup, schedule or group.
	 *
	 * @param array $signup Sign-up row.
	 * @param int   $member Member id.
	 */
	public static function signup_tools( $signup, $member ) {
		$season = Seasons::get( (int) $signup['season_id'] );
		$order  = $signup['order_id'] ? wc_get_order( (int) $signup['order_id'] ) : null;
		if ( $order && $order->has_status( array( 'on-hold', 'pending' ) ) ) {
			echo '<a class="button button-primary" href="' . esc_url(
				Admin::action_url(
					'member_paid',
					array(
						'signup' => $signup['id'],
						'member' => $member,
					)
				)
			) . '">' . esc_html__( 'Mark paid', 'boxday' ) . '</a> ';
		}
		if ( $signup['agreed_at'] ) {
			/* translators: 1: agreement version, 2: date and time. */
			echo '<span class="bd-note">' . esc_html( sprintf( __( 'Agreed to member agreement version %1$d on %2$s', 'boxday' ), (int) $signup['agreement_version'], get_date_from_gmt( $signup['agreed_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) ) . '</span>';
		}
		echo '<details class="bd-move"><summary class="button">' . esc_html__( 'Change site or schedule', 'boxday' ) . '</summary>';
		Admin::form_open( 'member_move' );
		echo '<input type="hidden" name="signup" value="' . (int) $signup['id'] . '"><input type="hidden" name="member" value="' . (int) $member . '"><div class="bd-fields">';
		echo '<label>' . esc_html__( 'Pickup', 'boxday' ) . '<select name="site">' . self::site_options( $season, (int) $signup['site_id'] ) . '</select></label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in site_options().
		echo '<label>' . esc_html__( 'How often?', 'boxday' ) . '<select name="schedule"><option value="w"' . selected( $signup['schedule'], 'w', false ) . '>' . esc_html__( 'Every week', 'boxday' ) . '</option><option value="b"' . selected( $signup['schedule'], 'b', false ) . '>' . esc_html__( 'Every other week', 'boxday' ) . '</option></select></label>';
		echo '<label>' . esc_html__( 'Group, for every other week', 'boxday' ) . '<select name="grp"><option value="A"' . selected( $signup['grp'], 'A', false ) . '>A</option><option value="B"' . selected( $signup['grp'], 'B', false ) . '>B</option></select></label>';
		echo '</div><p>' . esc_html__( 'This changes boxes from now on. It doesn\'t change what they paid: adjust the order in WooCommerce if it should.', 'boxday' ) . '</p>';
		submit_button( __( 'Save', 'boxday' ), 'secondary', 'submit', false );
		echo '</form></details>';
	}

	/**
	 * Runs one of this screen's actions.
	 *
	 * @param string $task Action.
	 * @param array  $in   Request.
	 * @return array|null Page and arguments to go back to.
	 */
	public static function action( $task, $in ) {
		$members = array( 'page' => 'boxday-members' );
		switch ( $task ) {
			case 'member_add':
				$size  = Db::get( 'size', absint( $in['size'] ) );
				$order = Roster::add_by_hand(
					array(
						'season_id'  => absint( $in['season'] ),
						'size_id'    => $size ? (int) $size['id'] : 0,
						'site_id'    => absint( $in['site'] ),
						'schedule'   => sanitize_key( $in['schedule'] ),
						'first_name' => sanitize_text_field( $in['first_name'] ),
						'last_name'  => sanitize_text_field( $in['last_name'] ),
						'email'      => sanitize_email( $in['email'] ),
						'phone'      => sanitize_text_field( $in['phone'] ),
						'street'     => sanitize_text_field( $in['street'] ),
						'zip'        => preg_replace( '/[^0-9A-Za-z -]/', '', (string) $in['zip'] ),
						'pay'        => sanitize_key( $in['pay'] ),
					)
				);
				if ( is_wp_error( $order ) ) {
					return $members + array(
						'view' => 'add',
						'err'  => rawurlencode( $order->get_error_message() ),
					);
				}
				$member = Db::value( 'SELECT member_id FROM ' . Db::t( 'signup' ) . ' WHERE order_id = %d', array( $order ) );
				return $members + array(
					'member' => (int) $member,
					'added'  => 1,
				);
			case 'member_paid':
				Roster::mark_paid( absint( $in['signup'] ) );
				return $members + array(
					'member' => absint( $in['member'] ),
					'paid'   => 1,
				);
			case 'member_move':
				$signup = Db::get( 'signup', absint( $in['signup'] ) );
				$site   = Db::get( 'site', absint( $in['site'] ) );
				if ( $signup && $site && (int) $site['season_id'] === (int) $signup['season_id'] ) {
					$schedule = 'b' === $in['schedule'] ? 'b' : 'w';
					Db::update(
						'signup',
						array(
							'site_id'  => (int) $site['id'],
							'schedule' => $schedule,
							'grp'      => 'b' === $schedule ? ( 'B' === $in['grp'] ? 'B' : 'A' ) : '',
						),
						array( 'id' => (int) $signup['id'] )
					);
					Signups::log( (int) $signup['id'], 'owner', 'move', $site['name'] );
				}
				return $members + array(
					'member' => absint( $in['member'] ),
					'moved'  => 1,
				);
			case 'import_check':
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- Admin::action() checked the nonce. A path from PHP's own upload handling, checked with is_uploaded_file().
				$tmp = isset( $_FILES['csv']['tmp_name'] ) ? (string) $_FILES['csv']['tmp_name'] : '';
				if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
					return $members + array( 'view' => 'import' );
				}
				$season = absint( $in['season'] );
				$result = Roster::check( Roster::read_csv( $tmp ), $season );
				set_transient( 'boxday_import_' . get_current_user_id(), $result + array( 'season' => $season ), HOUR_IN_SECONDS );
				return $members + array( 'view' => 'import' );
			case 'import_run':
				$key     = 'boxday_import_' . get_current_user_id();
				$preview = get_transient( $key );
				delete_transient( $key );
				if ( ! is_array( $preview ) ) {
					return $members + array( 'view' => 'import' );
				}
				$counts = Roster::import( $preview['good'], (int) $preview['season'] );
				return $members + array(
					'view'     => 'import',
					'imported' => $counts['added'] . '-' . $counts['changed'],
				);
			case 'import_clear':
				delete_transient( 'boxday_import_' . get_current_user_id() );
				return $members + array( 'view' => 'import' );
			case 'import_welcome':
				Roster::send_welcomes( absint( $in['season'] ) );
				return $members + array(
					'view'     => 'import',
					'welcomed' => 1,
				);
			case 'wait_offer':
				$ok = Waitlist::offer( absint( $in['row'] ) );
				return $members + array(
					'view'                        => 'wait',
					( $ok ? 'offered' : 'nofit' ) => 1,
				);
			case 'wait_remove':
				Waitlist::remove( absint( $in['row'] ) );
				return $members + array(
					'view'    => 'wait',
					'removed' => 1,
				);
		}
		return null;
	}
}
