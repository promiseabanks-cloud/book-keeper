<?php
defined( 'ABSPATH' ) || exit;

/*
 * Reading reminders. One email 24 hours after a reader's last reading session,
 * only while the book is unfinished. Reading again starts a new 24-hour clock.
 */

const BK_REMIND_AFTER = DAY_IN_SECONDS;

add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( 'bk_reminders' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'bk_reminders' );
		}
	}
);
add_action( 'bk_reminders', 'bk_send_reminders' );

function bk_reminders_on( $user_id ) {
	return ! get_user_meta( $user_id, 'bk_noremind', true );
}

/** Sections finished and total sections for a reader in a book. */
function bk_book_progress( $user_id, $book_id ) {
	global $wpdb;
	$total = 0;
	foreach ( bk_book_chapters( $book_id ) as $ch ) {
		$total += count( bk_chapter_sections( $ch ) );
	}
	$done = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bk_progress WHERE user_id=%d AND book_id=%d AND done=1", $user_id, $book_id ) );
	return array( min( $done, $total ), $total );
}

function bk_unsub_url( $user_id ) {
	$sig = hash_hmac( 'sha256', 'bk-unsub-' . $user_id, wp_salt( 'auth' ) );
	return add_query_arg( array( 'bk_unsub' => $user_id, 'sig' => substr( $sig, 0, 32 ) ), home_url( '/' ) );
}

/** Runs hourly. Finds readers whose last session was 24h or more ago and who have not had a reminder since. */
function bk_send_reminders() {
	global $wpdb;
	$now  = time();
	$rows = $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key LIKE 'bk\\_last\\_%'" ); // phpcs:ignore
	$sent = 0;
	foreach ( $rows as $r ) {
		$last = maybe_unserialize( $r->meta_value );
		$book = (int) substr( $r->meta_key, 8 );
		$uid  = (int) $r->user_id;
		if ( ! is_array( $last ) || empty( $last['at'] ) || ! $book || 'bk_book' !== get_post_type( $book ) ) {
			continue;
		}
		if ( $now - (int) $last['at'] < BK_REMIND_AFTER || ! bk_reminders_on( $uid ) ) {
			continue;
		}
		if ( (int) get_user_meta( $uid, 'bk_reminded_' . $book, true ) >= (int) $last['at'] ) {
			continue; // already reminded for this absence
		}
		list( $done, $total ) = bk_book_progress( $uid, $book );
		if ( $total && $done >= $total ) {
			continue; // finished the book
		}
		// Mark first so a slow or failed send never repeats every hour.
		update_user_meta( $uid, 'bk_reminded_' . $book, $now );
		if ( bk_reminder_email( $uid, $book, $last, $done, $total ) ) {
			$sent++;
		}
		if ( $sent >= 40 ) {
			break; // stay well inside the host's hourly mail limit; the rest go next hour
		}
	}
	return $sent;
}

function bk_reminder_email( $user_id, $book_id, $last, $done, $total ) {
	$user = get_userdata( $user_id );
	if ( ! $user || ! is_email( $user->user_email ) ) {
		return false;
	}
	$args = bk_reminder_args( $user, $book_id, $last, $done, $total );
	$subj = $args['feature'] ? 'Pick up where you left off: ' . $args['feature'] : 'Your next section of ' . get_the_title( $book_id ) . ' is waiting';
	return bk_send_email( $user->user_email, $subj, $args );
}

/* One-click turn off from the email. */
add_action(
	'template_redirect',
	function () {
		if ( empty( $_GET['bk_unsub'] ) || empty( $_GET['sig'] ) ) { // phpcs:ignore
			return;
		}
		$uid = (int) $_GET['bk_unsub']; // phpcs:ignore
		$sig = substr( hash_hmac( 'sha256', 'bk-unsub-' . $uid, wp_salt( 'auth' ) ), 0, 32 );
		$ok  = hash_equals( $sig, sanitize_key( wp_unslash( $_GET['sig'] ) ) ); // phpcs:ignore
		if ( $ok ) {
			update_user_meta( $uid, 'bk_noremind', 1 );
		}
		nocache_headers();
		bk_page(
			'Reminders',
			'<div class="bk-auth"><div class="bk-auth-card"><h1>' . ( $ok ? 'Reminders are off' : 'That link has expired' ) . '</h1><p class="bk-lead">' . ( $ok ? 'You will not get reading reminders any more. You can turn them back on in My notes, under Progress.' : 'Open the book and turn reminders off in My notes, under Progress.' ) . '</p><p><a class="bk-btn bk-btn--solid" href="' . esc_url( home_url( '/' ) ) . '">Back to the site</a></p></div></div>'
		);
		exit;
	},
	5
);

/* The reader can switch reminders on and off from My notes. */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'bookkeeper/v1',
			'/prefs',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return is_user_logged_in();
				},
				'callback'            => function ( WP_REST_Request $req ) {
					$uid = get_current_user_id();
					if ( null !== $req['remind'] ) {
						if ( $req['remind'] ) {
							delete_user_meta( $uid, 'bk_noremind' );
						} else {
							update_user_meta( $uid, 'bk_noremind', 1 );
						}
					}
					return array( 'remind' => bk_reminders_on( $uid ) );
				},
			)
		);
	}
);

register_deactivation_hook( BK_DIR . 'bookkeeper.php', 'bk_unschedule_reminders' );
function bk_unschedule_reminders() {
	wp_clear_scheduled_hook( 'bk_reminders' );
}
