<?php
/**
 * F19: removes BoxDay's tables, options and hidden products only if the owner ticked
 * "Delete all BoxDay data on uninstall". Orders are never deleted.
 *
 * @package BoxDay
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( 'yes' !== get_option( 'boxday_delete_data', 'no' ) ) {
	return;
}

global $wpdb;
$boxday_prefix = esc_sql( $wpdb->prefix . 'boxday_' );
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Uninstall removes our own tables.
foreach ( array( 'season', 'week', 'share', 'size', 'site', 'member', 'signup', 'signup_week', 'box_item', 'swap', 'crop', 'instalment', 'waitlist', 'log' ) as $boxday_table ) {
	$boxday_carriers = 'share' === $boxday_table ? $wpdb->get_col( "SELECT product_id FROM {$boxday_prefix}share" ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table name.
	foreach ( (array) $boxday_carriers as $boxday_product ) {
		wp_delete_post( (int) $boxday_product, true );
	}
	$boxday_name = esc_sql( $boxday_prefix . $boxday_table );
	$wpdb->query( "DROP TABLE IF EXISTS {$boxday_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Our own table name.
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery
foreach ( array( 'boxday_secret', 'boxday_db_version', 'boxday_look', 'boxday_look_set', 'boxday_pages', 'boxday_setup', 'boxday_waitlist_auto', 'boxday_offer_hours', 'boxday_week_posts', 'boxday_delete_data', 'boxday_sample' ) as $boxday_option ) {
	delete_option( $boxday_option );
}
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'boxday' );
}
