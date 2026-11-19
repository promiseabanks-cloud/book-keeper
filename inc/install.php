<?php
defined( 'ABSPATH' ) || exit;

/** Custom tables: reader data stays on this site. */
function bk_install_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();
	$p = $wpdb->prefix . 'bk_';

	dbDelta( "CREATE TABLE {$p}marks (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL,
		book_id bigint(20) unsigned NOT NULL,
		chapter_id bigint(20) unsigned NOT NULL,
		section smallint(5) unsigned NOT NULL DEFAULT 0,
		start_at int(10) unsigned NOT NULL DEFAULT 0,
		end_at int(10) unsigned NOT NULL DEFAULT 0,
		quote text NOT NULL,
		color varchar(20) NOT NULL DEFAULT 'yellow',
		note text NULL,
		created datetime NOT NULL,
		updated datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY user_book (user_id,book_id),
		KEY user_chapter (user_id,chapter_id,section)
	) $c;" );

	dbDelta( "CREATE TABLE {$p}entries (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL,
		book_id bigint(20) unsigned NOT NULL,
		chapter_id bigint(20) unsigned NOT NULL DEFAULT 0,
		section smallint(5) unsigned NOT NULL DEFAULT 0,
		block_key varchar(80) NOT NULL DEFAULT '',
		kind varchar(20) NOT NULL DEFAULT 'journal',
		prompt text NULL,
		content longtext NOT NULL,
		status varchar(20) NOT NULL DEFAULT 'open',
		due date NULL,
		created datetime NOT NULL,
		updated datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY user_book (user_id,book_id,kind),
		KEY user_key (user_id,block_key)
	) $c;" );

	dbDelta( "CREATE TABLE {$p}progress (
		user_id bigint(20) unsigned NOT NULL,
		book_id bigint(20) unsigned NOT NULL,
		chapter_id bigint(20) unsigned NOT NULL,
		section smallint(5) unsigned NOT NULL DEFAULT 0,
		done tinyint(1) NOT NULL DEFAULT 0,
		seconds int(10) unsigned NOT NULL DEFAULT 0,
		updated datetime NOT NULL,
		PRIMARY KEY  (user_id,chapter_id,section),
		KEY user_book (user_id,book_id)
	) $c;" );

	update_option( 'bk_db_version', BK_VERSION );
}

function bk_activate() {
	bk_install_tables();
	bk_register_types();
	bk_add_rewrites();
	flush_rewrite_rules();
}

add_action(
	'plugins_loaded',
	function () {
		if ( get_option( 'bk_db_version' ) !== BK_VERSION ) {
			bk_install_tables();
		}
	}
);
