<?php
defined( 'ABSPATH' ) || exit;

/** Owns the book: a paid purchase, a manual grant, or a site admin. */
function bk_user_owns( $user_id, $book_id ) {
	if ( ! $user_id ) {
		return false;
	}
	if ( user_can( $user_id, 'manage_options' ) ) {
		return true;
	}
	return (bool) get_user_meta( $user_id, 'bk_owns_' . (int) $book_id, true );
}

function bk_grant( $user_id, $book_id, $source = 'manual' ) {
	update_user_meta( $user_id, 'bk_owns_' . (int) $book_id, array( 'source' => $source, 'at' => current_time( 'mysql', true ) ) );
	do_action( 'bk_granted', $user_id, $book_id, $source );
}

function bk_revoke( $user_id, $book_id ) {
	delete_user_meta( $user_id, 'bk_owns_' . (int) $book_id );
}

/** Every chapter needs an account. Free preview chapters need nothing more. */
function bk_can_read_chapter( $user_id, $chapter_id ) {
	if ( ! $user_id ) {
		return false;
	}
	$book = (int) get_post_meta( $chapter_id, '_bk_book', true );
	if ( get_post_meta( $chapter_id, '_bk_free', true ) ) {
		return true;
	}
	return bk_user_owns( $user_id, $book );
}
