<?php
/**
 * Database tables: spec section 6.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades tables.
 */
class Schema {

	const DB_VERSION = '2';

	/**
	 * Activation: tables and the secret behind private links.
	 */
	public static function activate() {
		self::install();
		if ( ! get_option( 'boxday_secret' ) ) {
			add_option( 'boxday_secret', bin2hex( random_bytes( 32 ) ), '', false );
		}
	}

	/**
	 * Installs tables when the version changes.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'boxday_db_version' ) !== self::DB_VERSION ) {
			self::activate();
		}
	}

	/**
	 * Runs dbDelta on every table.
	 */
	private static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$p = $wpdb->prefix . 'boxday_';
		$s = "is_sample TINYINT(1) NOT NULL DEFAULT 0,\n";

		dbDelta(
			"CREATE TABLE {$p}season (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			status VARCHAR(10) NOT NULL DEFAULT 'draft',
			first_week DATE NOT NULL,
			weeks SMALLINT NOT NULL DEFAULT 22,
			signup_open DATETIME NULL,
			signup_close DATETIME NULL,
			cutoff_days SMALLINT NOT NULL DEFAULT 2,
			cutoff_time TIME NOT NULL DEFAULT '20:00:00',
			skip_rule VARCHAR(10) NOT NULL DEFAULT 'skip',
			donation_partner VARCHAR(190) NOT NULL DEFAULT '',
			max_skips SMALLINT NOT NULL DEFAULT 2,
			makeup_weeks SMALLINT NOT NULL DEFAULT 0,
			swaps_on TINYINT(1) NOT NULL DEFAULT 1,
			swap_limit SMALLINT NOT NULL DEFAULT 2,
			site_change VARCHAR(10) NOT NULL DEFAULT 'both',
			late_join TINYINT(1) NOT NULL DEFAULT 1,
			prorate TINYINT(1) NOT NULL DEFAULT 1,
			plan_mode VARCHAR(10) NOT NULL DEFAULT 'full',
			deposit_type VARCHAR(10) NOT NULL DEFAULT 'amount',
			deposit_value DECIMAL(12,4) NOT NULL DEFAULT 0,
			plan_dates TEXT NULL,
			agreement_text LONGTEXT NULL,
			agreement_version INT NOT NULL DEFAULT 0,
			{$s}PRIMARY KEY  (id)
			) {$c};
			CREATE TABLE {$p}week (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			season_id BIGINT UNSIGNED NOT NULL,
			number SMALLINT NOT NULL,
			start_date DATE NOT NULL,
			state VARCHAR(10) NOT NULL DEFAULT 'open',
			grp CHAR(1) NOT NULL DEFAULT '',
			published_at DATETIME NULL,
			message TEXT NULL,
			photo_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			{$s}PRIMARY KEY  (id),
			UNIQUE KEY season_number (season_id,number)
			) {$c};
			CREATE TABLE {$p}share (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			season_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(190) NOT NULL,
			description TEXT NULL,
			photo_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			kind VARCHAR(10) NOT NULL DEFAULT 'main',
			capacity INT NULL,
			price_mode VARCHAR(10) NOT NULL DEFAULT 'season',
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			hidden TINYINT(1) NOT NULL DEFAULT 0,
			sort INT NOT NULL DEFAULT 0,
			{$s}PRIMARY KEY  (id),
			KEY season (season_id)
			) {$c};
			CREATE TABLE {$p}size (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			share_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(190) NOT NULL,
			note VARCHAR(190) NOT NULL DEFAULT '',
			price_weekly DECIMAL(12,4) NULL,
			price_biweekly DECIMAL(12,4) NULL,
			sort INT NOT NULL DEFAULT 0,
			{$s}PRIMARY KEY  (id),
			KEY share (share_id)
			) {$c};
			CREATE TABLE {$p}site (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			season_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(10) NOT NULL DEFAULT 'pickup',
			name VARCHAR(190) NOT NULL,
			address VARCHAR(255) NOT NULL DEFAULT '',
			weekday TINYINT NOT NULL DEFAULT 2,
			time_from TIME NOT NULL DEFAULT '16:00:00',
			time_to TIME NOT NULL DEFAULT '19:00:00',
			capacity INT NULL,
			cutoff_days SMALLINT NULL,
			cutoff_time TIME NULL,
			note TEXT NULL,
			zip_prefixes VARCHAR(255) NOT NULL DEFAULT '',
			fee_per_box DECIMAL(12,4) NOT NULL DEFAULT 0,
			{$s}PRIMARY KEY  (id),
			KEY season (season_id)
			) {$c};
			CREATE TABLE {$p}member (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(190) NOT NULL,
			first_name VARCHAR(100) NOT NULL DEFAULT '',
			last_name VARCHAR(100) NOT NULL DEFAULT '',
			phone VARCHAR(50) NOT NULL DEFAULT '',
			street VARCHAR(255) NOT NULL DEFAULT '',
			city VARCHAR(100) NOT NULL DEFAULT '',
			zip VARCHAR(20) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			link_version INT NOT NULL DEFAULT 1,
			notes TEXT NULL,
			{$s}PRIMARY KEY  (id),
			UNIQUE KEY email (email)
			) {$c};
			CREATE TABLE {$p}signup (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			season_id BIGINT UNSIGNED NOT NULL,
			share_id BIGINT UNSIGNED NOT NULL,
			size_id BIGINT UNSIGNED NOT NULL,
			site_id BIGINT UNSIGNED NOT NULL,
			schedule CHAR(1) NOT NULL DEFAULT 'w',
			grp CHAR(1) NOT NULL DEFAULT '',
			status VARCHAR(10) NOT NULL DEFAULT 'pending',
			pay_state VARCHAR(10) NOT NULL DEFAULT 'unpaid',
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			order_item_id BIGINT UNSIGNED NULL,
			price_total DECIMAL(12,4) NOT NULL DEFAULT 0,
			boxes_paid SMALLINT NOT NULL DEFAULT 0,
			first_week_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			agreement_version INT NOT NULL DEFAULT 0,
			agreed_at DATETIME NULL,
			over_capacity TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			{$s}PRIMARY KEY  (id),
			UNIQUE KEY order_item (order_item_id),
			KEY season_status (season_id,status),
			KEY member (member_id),
			KEY site (site_id)
			) {$c};
			CREATE TABLE {$p}signup_week (
			signup_id BIGINT UNSIGNED NOT NULL,
			week_id BIGINT UNSIGNED NOT NULL,
			skipped VARCHAR(10) NOT NULL DEFAULT '',
			site_override BIGINT UNSIGNED NOT NULL DEFAULT 0,
			picked_up_at DATETIME NULL,
			box_mail_at DATETIME NULL,
			reminder_mail_at DATETIME NULL,
			{$s}PRIMARY KEY  (signup_id,week_id),
			KEY week (week_id)
			) {$c};
			CREATE TABLE {$p}crop (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			unit VARCHAR(50) NOT NULL DEFAULT '',
			grown_at VARCHAR(190) NOT NULL DEFAULT '',
			{$s}PRIMARY KEY  (id),
			KEY name (name)
			) {$c};
			CREATE TABLE {$p}box_item (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			week_id BIGINT UNSIGNED NOT NULL,
			share_id BIGINT UNSIGNED NOT NULL,
			size_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			role VARCHAR(10) NOT NULL DEFAULT 'box',
			crop_id BIGINT UNSIGNED NOT NULL,
			qty DECIMAL(10,2) NOT NULL DEFAULT 1,
			unit VARCHAR(50) NOT NULL DEFAULT '',
			swappable TINYINT(1) NOT NULL DEFAULT 0,
			available INT NULL,
			taken INT NOT NULL DEFAULT 0,
			{$s}PRIMARY KEY  (id),
			KEY week (week_id,share_id)
			) {$c};
			CREATE TABLE {$p}swap (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			signup_id BIGINT UNSIGNED NOT NULL,
			week_id BIGINT UNSIGNED NOT NULL,
			out_item_id BIGINT UNSIGNED NOT NULL,
			in_item_id BIGINT UNSIGNED NOT NULL,
			{$s}PRIMARY KEY  (id),
			KEY signup_week (signup_id,week_id)
			) {$c};
			CREATE TABLE {$p}instalment (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			signup_id BIGINT UNSIGNED NOT NULL,
			due_date DATE NOT NULL,
			amount DECIMAL(12,4) NOT NULL,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			state VARCHAR(10) NOT NULL DEFAULT 'due',
			reminded_at DATETIME NULL,
			{$s}PRIMARY KEY  (id),
			KEY signup (signup_id),
			KEY due (state,due_date)
			) {$c};
			CREATE TABLE {$p}waitlist (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			season_id BIGINT UNSIGNED NOT NULL,
			share_id BIGINT UNSIGNED NOT NULL,
			size_id BIGINT UNSIGNED NOT NULL,
			site_ids VARCHAR(255) NOT NULL DEFAULT '',
			site_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			first_name VARCHAR(100) NOT NULL DEFAULT '',
			last_name VARCHAR(100) NOT NULL DEFAULT '',
			email VARCHAR(190) NOT NULL,
			phone VARCHAR(50) NOT NULL DEFAULT '',
			status VARCHAR(10) NOT NULL DEFAULT 'waiting',
			offered_at DATETIME NULL,
			expires_at DATETIME NULL,
			link_version INT NOT NULL DEFAULT 1,
			queued_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			{$s}PRIMARY KEY  (id),
			KEY season_status (season_id,status),
			KEY email (email)
			) {$c};
			CREATE TABLE {$p}log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			signup_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			actor VARCHAR(50) NOT NULL DEFAULT '',
			action VARCHAR(50) NOT NULL DEFAULT '',
			detail VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			{$s}PRIMARY KEY  (id),
			KEY signup (signup_id)
			) {$c};"
		);
		update_option( 'boxday_db_version', self::DB_VERSION );
	}
}
