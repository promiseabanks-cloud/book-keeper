<?php
defined( 'ABSPATH' ) || exit;

const BK_COLORS = array( 'yellow', 'green', 'blue', 'pink', 'purple' );
const BK_KINDS  = array( 'reflect', 'action', 'journal', 'quote' );

function bk_now() {
	return current_time( 'mysql', true );
}

function bk_err( $code, $msg, $status ) {
	return new WP_Error( $code, $msg, array( 'status' => $status ) );
}

/** The chapter must exist, be readable by this user, and (when given) belong to the book. */
function bk_check_chapter( $chapter_id, $book_id = 0 ) {
	$ch = get_post( (int) $chapter_id );
	if ( ! $ch || 'bk_chapter' !== $ch->post_type || 'publish' !== $ch->post_status ) {
		return bk_err( 'bk_no_chapter', 'Chapter not found.', 404 );
	}
	if ( $book_id && (int) get_post_meta( $ch->ID, '_bk_book', true ) !== (int) $book_id ) {
		return bk_err( 'bk_wrong_book', 'Chapter is not in this book.', 400 );
	}
	if ( ! bk_can_read_chapter( get_current_user_id(), $ch->ID ) ) {
		return bk_err( 'bk_locked', 'Unlock the book to read this chapter.', 403 );
	}
	return $ch;
}

function bk_mark_out( $r ) {
	return array(
		'id'      => (int) $r->id,
		'chapter' => (int) $r->chapter_id,
		'section' => (int) $r->section,
		'start'   => (int) $r->start_at,
		'end'     => (int) $r->end_at,
		'quote'   => $r->quote,
		'color'   => $r->color,
		'note'    => (string) $r->note,
		'updated' => $r->updated,
	);
}

function bk_entry_out( $r ) {
	return array(
		'id'      => (int) $r->id,
		'chapter' => (int) $r->chapter_id,
		'section' => (int) $r->section,
		'key'     => $r->block_key,
		'kind'    => $r->kind,
		'prompt'  => (string) $r->prompt,
		'content' => $r->content,
		'status'  => $r->status,
		'due'     => $r->due,
		'updated' => $r->updated,
	);
}

add_action(
	'rest_api_init',
	function () {
		$ns   = 'bookkeeper/v1';
		$auth = function () {
			return is_user_logged_in() ? true : bk_err( 'bk_login', 'Please sign in.', 401 );
		};

		register_rest_route( $ns, '/book/(?P<id>\d+)', array( 'methods' => 'GET', 'permission_callback' => $auth, 'callback' => 'bk_rest_book' ) );
		register_rest_route( $ns, '/section/(?P<chapter>\d+)/(?P<i>\d+)', array( 'methods' => 'GET', 'permission_callback' => $auth, 'callback' => 'bk_rest_section' ) );
		register_rest_route( $ns, '/progress', array( 'methods' => 'POST', 'permission_callback' => $auth, 'callback' => 'bk_rest_progress' ) );
		register_rest_route(
			$ns,
			'/marks',
			array(
				array( 'methods' => 'GET', 'permission_callback' => $auth, 'callback' => 'bk_rest_marks_list' ),
				array( 'methods' => 'POST', 'permission_callback' => $auth, 'callback' => 'bk_rest_mark_create' ),
			)
		);
		register_rest_route(
			$ns,
			'/marks/(?P<id>\d+)',
			array(
				array( 'methods' => 'PATCH,POST', 'permission_callback' => $auth, 'callback' => 'bk_rest_mark_update' ),
				array( 'methods' => 'DELETE', 'permission_callback' => $auth, 'callback' => 'bk_rest_mark_delete' ),
			)
		);
		register_rest_route(
			$ns,
			'/entries',
			array(
				array( 'methods' => 'GET', 'permission_callback' => $auth, 'callback' => 'bk_rest_entries_list' ),
				array( 'methods' => 'POST', 'permission_callback' => $auth, 'callback' => 'bk_rest_entry_save' ),
			)
		);
		register_rest_route(
			$ns,
			'/entries/(?P<id>\d+)',
			array(
				array( 'methods' => 'PATCH,POST', 'permission_callback' => $auth, 'callback' => 'bk_rest_entry_update' ),
				array( 'methods' => 'DELETE', 'permission_callback' => $auth, 'callback' => 'bk_rest_entry_delete' ),
			)
		);
	}
);

function bk_rest_book( WP_REST_Request $req ) {
	global $wpdb;
	$book = get_post( (int) $req['id'] );
	if ( ! $book || 'bk_book' !== $book->post_type ) {
		return bk_err( 'bk_no_book', 'Book not found.', 404 );
	}
	$uid  = get_current_user_id();
	$done = $wpdb->get_results( $wpdb->prepare( "SELECT chapter_id, section, done, seconds FROM {$wpdb->prefix}bk_progress WHERE user_id=%d AND book_id=%d", $uid, $book->ID ) );
	$prog = array();
	$secs = 0;
	foreach ( $done as $d ) {
		$secs += (int) $d->seconds;
		if ( $d->done ) {
			$prog[] = $d->chapter_id . ':' . $d->section;
		}
	}
	$counts = $wpdb->get_results( $wpdb->prepare( "SELECT kind, COUNT(*) n FROM {$wpdb->prefix}bk_entries WHERE user_id=%d AND book_id=%d AND content<>'' GROUP BY kind", $uid, $book->ID ), OBJECT_K );
	$marks  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bk_marks WHERE user_id=%d AND book_id=%d", $uid, $book->ID ) );
	$user   = wp_get_current_user();
	return array(
		'id'       => $book->ID,
		'title'    => get_the_title( $book ),
		'owns'     => bk_user_owns( $uid, $book->ID ),
		'chapters' => bk_toc( $book->ID, $uid ),
		'done'     => $prog,
		'last'     => get_user_meta( $uid, 'bk_last_' . $book->ID, true ) ?: null,
		'stats'    => array(
			'seconds'  => $secs,
			'reflects' => isset( $counts['reflect'] ) ? (int) $counts['reflect']->n : 0,
			'actions'  => isset( $counts['action'] ) ? (int) $counts['action']->n : 0,
			'journal'  => isset( $counts['journal'] ) ? (int) $counts['journal']->n : 0,
			'marks'    => $marks,
		),
		'user'     => array( 'name' => $user->display_name, 'email' => $user->user_email ),
	);
}

function bk_rest_section( WP_REST_Request $req ) {
	global $wpdb;
	$ch = bk_check_chapter( $req['chapter'] );
	if ( is_wp_error( $ch ) ) {
		return $ch;
	}
	$i   = (int) $req['i'];
	$sec = bk_render_section( $ch, $i );
	if ( ! $sec ) {
		return bk_err( 'bk_no_section', 'Section not found.', 404 );
	}
	$uid     = get_current_user_id();
	$marks   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bk_marks WHERE user_id=%d AND chapter_id=%d AND section=%d ORDER BY start_at", $uid, $ch->ID, $i ) );
	$entries = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bk_entries WHERE user_id=%d AND chapter_id=%d AND section=%d AND block_key<>''", $uid, $ch->ID, $i ) );
	$res     = rest_ensure_response(
		array(
			'chapter' => $ch->ID,
			'section' => $i,
			'title'   => $sec['title'],
			'minutes' => $sec['minutes'],
			'html'    => $sec['html'],
			'marks'   => array_map( 'bk_mark_out', $marks ),
			'entries' => array_map( 'bk_entry_out', $entries ),
		)
	);
	$res->header( 'Cache-Control', 'no-store, private' );
	return $res;
}

function bk_rest_progress( WP_REST_Request $req ) {
	global $wpdb;
	$ch = bk_check_chapter( $req['chapter'] );
	if ( is_wp_error( $ch ) ) {
		return $ch;
	}
	$uid  = get_current_user_id();
	$book = (int) get_post_meta( $ch->ID, '_bk_book', true );
	$i    = max( 0, (int) $req['section'] );
	$add  = min( 600, max( 0, (int) $req['seconds'] ) );
	$done = $req['done'] ? 1 : 0;
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}bk_progress (user_id,book_id,chapter_id,section,done,seconds,updated) VALUES (%d,%d,%d,%d,%d,%d,%s)
			ON DUPLICATE KEY UPDATE done=GREATEST(done,VALUES(done)), seconds=seconds+VALUES(seconds), updated=VALUES(updated)",
			$uid,
			$book,
			$ch->ID,
			$i,
			$done,
			$add,
			bk_now()
		)
	);
	update_user_meta( $uid, 'bk_last_' . $book, array( 'chapter' => $ch->ID, 'section' => $i, 'at' => time() ) );
	if ( ! get_user_meta( $uid, 'bk_started_' . $book, true ) ) {
		update_user_meta( $uid, 'bk_started_' . $book, time() );
	}
	return array( 'ok' => true );
}

/* ---------- highlights and sticky notes ---------- */

function bk_own_row( $table, $id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bk_{$table} WHERE id=%d", (int) $id ) ); // phpcs:ignore
	if ( ! $row || (int) $row->user_id !== get_current_user_id() ) {
		return bk_err( 'bk_not_found', 'Not found.', 404 );
	}
	return $row;
}

function bk_rest_marks_list( WP_REST_Request $req ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bk_marks WHERE user_id=%d AND book_id=%d ORDER BY chapter_id, section, start_at", get_current_user_id(), (int) $req['book'] ) );
	return array_map( 'bk_mark_out', $rows );
}

function bk_rest_mark_create( WP_REST_Request $req ) {
	global $wpdb;
	$ch = bk_check_chapter( $req['chapter'], $req['book'] );
	if ( is_wp_error( $ch ) ) {
		return $ch;
	}
	$start = (int) $req['start'];
	$end   = (int) $req['end'];
	$quote = sanitize_textarea_field( (string) $req['quote'] );
	if ( $end <= $start || '' === trim( $quote ) ) {
		return bk_err( 'bk_bad_range', 'Select some text first.', 400 );
	}
	$color = in_array( $req['color'], BK_COLORS, true ) ? $req['color'] : 'yellow';
	$wpdb->insert(
		$wpdb->prefix . 'bk_marks',
		array(
			'user_id'    => get_current_user_id(),
			'book_id'    => (int) get_post_meta( $ch->ID, '_bk_book', true ),
			'chapter_id' => $ch->ID,
			'section'    => max( 0, (int) $req['section'] ),
			'start_at'   => $start,
			'end_at'     => $end,
			'quote'      => mb_substr( $quote, 0, 2000 ),
			'color'      => $color,
			'note'       => sanitize_textarea_field( (string) $req['note'] ),
			'created'    => bk_now(),
			'updated'    => bk_now(),
		)
	);
	return bk_mark_out( bk_own_row( 'marks', $wpdb->insert_id ) );
}

function bk_rest_mark_update( WP_REST_Request $req ) {
	global $wpdb;
	$row = bk_own_row( 'marks', $req['id'] );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	$set = array( 'updated' => bk_now() );
	if ( null !== $req['color'] && in_array( $req['color'], BK_COLORS, true ) ) {
		$set['color'] = $req['color'];
	}
	if ( null !== $req['note'] ) {
		$set['note'] = sanitize_textarea_field( (string) $req['note'] );
	}
	$wpdb->update( $wpdb->prefix . 'bk_marks', $set, array( 'id' => $row->id ) );
	return bk_mark_out( bk_own_row( 'marks', $row->id ) );
}

function bk_rest_mark_delete( WP_REST_Request $req ) {
	global $wpdb;
	$row = bk_own_row( 'marks', $req['id'] );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	$wpdb->delete( $wpdb->prefix . 'bk_marks', array( 'id' => $row->id ) );
	return array( 'deleted' => (int) $row->id );
}

/* ---------- journal, reflections, action points ---------- */

function bk_rest_entries_list( WP_REST_Request $req ) {
	global $wpdb;
	$sql  = "SELECT * FROM {$wpdb->prefix}bk_entries WHERE user_id=%d AND book_id=%d AND content<>''";
	$args = array( get_current_user_id(), (int) $req['book'] );
	if ( $req['kind'] && in_array( $req['kind'], BK_KINDS, true ) ) {
		$sql   .= ' AND kind=%s';
		$args[] = $req['kind'];
	}
	$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY updated DESC', $args ) ); // phpcs:ignore
	return array_map( 'bk_entry_out', $rows );
}

/** Saves an entry. Entries tied to a prompt (key) are one per user and prompt, so typing updates in place. */
function bk_rest_entry_save( WP_REST_Request $req ) {
	global $wpdb;
	$book = (int) $req['book'];
	$chid = (int) $req['chapter'];
	if ( $chid ) {
		$ch = bk_check_chapter( $chid, $book );
		if ( is_wp_error( $ch ) ) {
			return $ch;
		}
	} elseif ( 'bk_book' !== get_post_type( $book ) ) {
		return bk_err( 'bk_no_book', 'Book not found.', 404 );
	}
	$kind = in_array( $req['kind'], BK_KINDS, true ) ? $req['kind'] : 'journal';
	$key  = preg_replace( '/[^a-z0-9:_-]/i', '', (string) $req['key'] );
	$data = array(
		'user_id'    => get_current_user_id(),
		'book_id'    => $book,
		'chapter_id' => $chid,
		'section'    => max( 0, (int) $req['section'] ),
		'block_key'  => substr( $key, 0, 80 ),
		'kind'       => $kind,
		'prompt'     => sanitize_textarea_field( (string) $req['prompt'] ),
		'content'    => sanitize_textarea_field( (string) $req['content'] ),
		'status'     => 'done' === $req['status'] ? 'done' : 'open',
		'due'        => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $req['due'] ) ? $req['due'] : null,
		'updated'    => bk_now(),
	);
	$existing = $key ? $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bk_entries WHERE user_id=%d AND block_key=%s", $data['user_id'], $key ) ) : null;
	if ( $existing ) {
		$wpdb->update( $wpdb->prefix . 'bk_entries', $data, array( 'id' => $existing ) );
		$id = $existing;
	} else {
		$data['created'] = bk_now();
		$wpdb->insert( $wpdb->prefix . 'bk_entries', $data );
		$id = $wpdb->insert_id;
	}
	return bk_entry_out( bk_own_row( 'entries', $id ) );
}

function bk_rest_entry_update( WP_REST_Request $req ) {
	global $wpdb;
	$row = bk_own_row( 'entries', $req['id'] );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	$set = array( 'updated' => bk_now() );
	if ( null !== $req['content'] ) {
		$set['content'] = sanitize_textarea_field( (string) $req['content'] );
	}
	if ( null !== $req['status'] ) {
		$set['status'] = 'done' === $req['status'] ? 'done' : 'open';
	}
	if ( null !== $req['due'] ) {
		$set['due'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $req['due'] ) ? $req['due'] : null;
	}
	$wpdb->update( $wpdb->prefix . 'bk_entries', $set, array( 'id' => $row->id ) );
	return bk_entry_out( bk_own_row( 'entries', $row->id ) );
}

function bk_rest_entry_delete( WP_REST_Request $req ) {
	global $wpdb;
	$row = bk_own_row( 'entries', $req['id'] );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	$wpdb->delete( $wpdb->prefix . 'bk_entries', array( 'id' => $row->id ) );
	return array( 'deleted' => (int) $row->id );
}
