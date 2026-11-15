<?php
/**
 * Print views and CSV. Sheets are black on white, for a farm's cheap printer.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * Serves print pages and files.
 */
class Admin_Print {

	/**
	 * A nonced link.
	 *
	 * @param string $type View.
	 * @param array  $args Arguments.
	 * @return string
	 */
	public static function url( $type, $args = array() ) {
		return wp_nonce_url(
			add_query_arg(
				array_merge(
					array(
						'action' => 'boxday_print',
						'type'   => $type,
					),
					$args
				),
				admin_url( 'admin-post.php' )
			),
			'boxday_print'
		);
	}

	/**
	 * Runs a view.
	 */
	public static function route() {
		if ( ! current_user_can( Admin::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'boxday' ), 403 );
		}
		check_admin_referer( 'boxday_print' );
		$type = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';
		$week = isset( $_GET['week'] ) ? Db::get( 'week', absint( $_GET['week'] ) ) : null;
		$site = isset( $_GET['site'] ) ? Db::get( 'site', absint( $_GET['site'] ) ) : null;
		if ( 'sheet' === $type && $week && $site ) {
			self::sheet( $site, $week );
		} elseif ( 'totals' === $type && $week ) {
			self::totals( $week );
		} elseif ( 'totals_csv' === $type && $week ) {
			self::totals_csv( $week );
		} elseif ( 'members_csv' === $type && isset( $_GET['season'] ) ) {
			self::members_csv( absint( $_GET['season'] ) );
		}
		exit;
	}

	/**
	 * Formula-safe CSV cell, as members type their own names.
	 *
	 * @param string $value Cell.
	 * @return string
	 */
	public static function cell( $value ) {
		$value = (string) $value;
		return '' !== $value && false !== strpos( '=+-@', $value[0] ) ? "'" . $value : $value;
	}

	/**
	 * Sends rows as a CSV file.
	 *
	 * @param string $name File name.
	 * @param array  $rows Rows.
	 */
	private static function csv( $name, $rows ) {
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		$out = fopen( 'php://output', 'w' );
		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( array( __CLASS__, 'cell' ), $row ), ',', '"', '\\' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output.
	}

	/**
	 * The harvest totals as CSV.
	 *
	 * @param array $week Week row.
	 */
	private static function totals_csv( $week ) {
		$rows = array( array( __( 'Item', 'boxday' ), __( 'Unit', 'boxday' ), __( 'Before swaps', 'boxday' ), __( 'After swaps', 'boxday' ) ) );
		foreach ( Totals::week( $week )['crops'] as $crop ) {
			$rows[] = array( $crop['name'], $crop['unit'], (float) $crop['before'], (float) $crop['after'] );
		}
		self::csv( 'harvest-week-' . (int) $week['number'] . '.csv', $rows );
	}

	/**
	 * The members as CSV.
	 *
	 * @param int $season_id Season.
	 */
	private static function members_csv( $season_id ) {
		$rows = array( array( 'email', 'first name', 'last name', 'phone', 'share', 'size', 'site', 'schedule', 'payment' ) );
		foreach ( Admin_Screens::member_rows( $season_id ) as $row ) {
			$rows[] = array( $row['email'], $row['first_name'], $row['last_name'], $row['phone'], $row['share'], $row['size'], $row['site'], $row['schedule'], $row['pay_state'] );
		}
		self::csv( 'members.csv', $rows );
	}

	/**
	 * Starts a print page.
	 *
	 * @param string $title Title.
	 */
	private static function head( $title ) {
		echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html( $title ) . '</title>';
		wp_register_style( 'boxday-print', BOXDAY_URL . 'assets/print.css', array(), BOXDAY_VERSION );
		/**
		 * Runs in the head of a print page, so add-ons can print their own styles.
		 */
		do_action( 'boxday_print_head' );
		wp_print_styles( array( 'boxday-print' ) );
		echo '</head><body>';
	}

	/**
	 * F16: the pickup sheet, one page per 40 names.
	 *
	 * @param array $site Site row.
	 * @param array $week Week row.
	 */
	private static function sheet( $site, $week ) {
		$sheet  = Totals::sheet( (int) $site['id'], $week );
		$date   = Calendar::pickup_date( $week['start_date'], $site['weekday'] );
		$title  = $site['name'] . ', ' . Format::date( $date ) . ', ' . Format::window( $site['time_from'], $site['time_to'] );
		$pages  = $sheet['coming'] ? array_chunk( $sheet['coming'], 40 ) : array( array() );
		$count  = count( $pages );
		$footer = sprintf( /* translators: %d: boxes. */ __( '%d boxes to hand out.', 'boxday' ), count( $sheet['coming'] ) );
		self::head( $title );
		foreach ( $pages as $i => $rows ) {
			echo '<div class="pg"><h1>' . esc_html( $title ) . '</h1><table><thead><tr><th>' . esc_html__( 'Name', 'boxday' ) . '</th><th>' . esc_html__( 'Box', 'boxday' ) . '</th><th>' . esc_html__( 'Swaps', 'boxday' ) . '</th><th>' . esc_html__( 'Note', 'boxday' ) . '</th><th>' . esc_html__( 'Picked up', 'boxday' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				echo '<tr><td>' . esc_html( $row['name'] ) . '</td><td>' . esc_html( $row['box'] ) . '</td><td>' . esc_html( $row['swaps'] ) . '</td><td>' . esc_html( $row['note'] ) . '</td><td><span class="tk"></span></td></tr>';
			}
			echo '</tbody></table>';
			if ( $i === $count - 1 && $sheet['away'] ) {
				echo '<h2>' . esc_html__( 'Not coming this week', 'boxday' ) . '</h2><table style="margin-top:6px"><tbody>';
				foreach ( $sheet['away'] as $row ) {
					echo '<tr><td>' . esc_html( $row['name'] ) . '</td><td>' . esc_html( $row['box'] ) . '</td><td>' . esc_html( $row['reason'] ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
			echo '<div class="f"><span>' . esc_html( $footer ) . '</span><span>' . esc_html( Format::farm() ) . '</span></div></div>';
		}
		echo '</body></html>';
	}

	/**
	 * F15: the harvest totals for print.
	 *
	 * @param array $week Week row.
	 */
	private static function totals( $week ) {
		$totals = Totals::week( $week );
		$title  = sprintf( /* translators: %s: date. */ __( 'Print harvest totals for %s', 'boxday' ), Format::date( $week['start_date'] ) );
		self::head( $title );
		echo '<div class="pg"><h1>' . esc_html( $title ) . '</h1><table><thead><tr><th>' . esc_html__( 'Item', 'boxday' ) . '</th><th>' . esc_html__( 'Total', 'boxday' ) . '</th></tr></thead><tbody>';
		foreach ( $totals['crops'] as $crop ) {
			echo '<tr><td>' . esc_html( $crop['name'] . ( $crop['unit'] ? ', ' . $crop['unit'] : '' ) ) . '</td><td>' . esc_html( (string) (float) $crop['after'] ) . '</td></tr>';
		}
		echo '</tbody></table><h2>' . esc_html__( 'Boxes to pack', 'boxday' ) . '</h2><table><tbody>';
		foreach ( $totals['packing'] as $row ) {
			echo '<tr><td>' . esc_html( $row['site'] . ', ' . $row['share'] . ', ' . $row['size'] ) . '</td><td>' . (int) $row['boxes'] . ( $row['donated'] ? ' (' . esc_html( sprintf( /* translators: %d: boxes. */ __( '%d donated', 'boxday' ), $row['donated'] ) ) . ')' : '' ) . '</td></tr>';
		}
		echo '</tbody></table></div></body></html>';
	}
}
