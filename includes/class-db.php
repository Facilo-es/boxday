<?php
/**
 * Thin wrapper around $wpdb. Table names are ours and every value goes through prepare().
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB

/**
 * Database helpers.
 */
class Db {

	/**
	 * Full name of one of our tables.
	 *
	 * @param string $name Short name, such as `season`.
	 * @return string
	 */
	public static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'boxday_' . $name;
	}

	/**
	 * Fills the placeholders in a query.
	 *
	 * @param string $sql  Query.
	 * @param array  $args Values.
	 * @return string
	 */
	private static function sql( $sql, $args ) {
		global $wpdb;
		return $args ? $wpdb->prepare( $sql, $args ) : $sql;
	}

	/**
	 * All rows of a query, as arrays.
	 *
	 * @param string $sql  Query.
	 * @param array  $args Values.
	 * @return array
	 */
	public static function rows( $sql, $args = array() ) {
		global $wpdb;
		return (array) $wpdb->get_results( self::sql( $sql, $args ), ARRAY_A );
	}

	/**
	 * One row, or null.
	 *
	 * @param string $sql  Query.
	 * @param array  $args Values.
	 * @return array|null
	 */
	public static function row( $sql, $args = array() ) {
		global $wpdb;
		return $wpdb->get_row( self::sql( $sql, $args ), ARRAY_A );
	}

	/**
	 * One value.
	 *
	 * @param string $sql  Query.
	 * @param array  $args Values.
	 * @return string|null
	 */
	public static function value( $sql, $args = array() ) {
		global $wpdb;
		return $wpdb->get_var( self::sql( $sql, $args ) );
	}

	/**
	 * First column of every row.
	 *
	 * @param string $sql  Query.
	 * @param array  $args Values.
	 * @return array
	 */
	public static function col( $sql, $args = array() ) {
		global $wpdb;
		return (array) $wpdb->get_col( self::sql( $sql, $args ) );
	}

	/**
	 * Runs a statement.
	 *
	 * @param string $sql  Statement.
	 * @param array  $args Values.
	 * @return int|bool Rows changed.
	 */
	public static function run( $sql, $args = array() ) {
		global $wpdb;
		return $wpdb->query( self::sql( $sql, $args ) );
	}

	/**
	 * Escapes text for a LIKE match.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function like( $text ) {
		global $wpdb;
		return $wpdb->esc_like( $text );
	}

	/**
	 * A row by id from one of our tables.
	 *
	 * @param string $table Short name.
	 * @param int    $id    Row id.
	 * @return array|null
	 */
	public static function get( $table, $id ) {
		return self::row( 'SELECT * FROM ' . self::t( $table ) . ' WHERE id = %d', array( $id ) );
	}

	/**
	 * Inserts a row.
	 *
	 * @param string $table Short name.
	 * @param array  $data  Column values.
	 * @return int New id.
	 */
	public static function insert( $table, $data ) {
		global $wpdb;
		$wpdb->insert( self::t( $table ), $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates rows.
	 *
	 * @param string $table Short name.
	 * @param array  $data  Column values.
	 * @param array  $where Conditions.
	 * @return int|false
	 */
	public static function update( $table, $data, $where ) {
		global $wpdb;
		return $wpdb->update( self::t( $table ), $data, $where );
	}

	/**
	 * Deletes rows.
	 *
	 * @param string $table Short name.
	 * @param array  $where Conditions.
	 * @return int|false
	 */
	public static function delete( $table, $where ) {
		global $wpdb;
		return $wpdb->delete( self::t( $table ), $where );
	}
}
