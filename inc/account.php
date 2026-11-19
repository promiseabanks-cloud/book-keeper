<?php
defined( 'ABSPATH' ) || exit;

/*
 * Reader accounts: the launch switch for buy buttons on the site, a branded
 * password reset, and account settings (name, password, delete account).
 */

/* ---------- launch switch ---------- */

function bk_on_sale() {
	return (bool) get_option( 'bk_on_sale', 0 );
}

/*
 * Site pages can carry two kinds of button, and the switch decides which show:
 * a link to a reader's #/buy screen shows only while the book is on sale, and a
 * "#join" waitlist link on the book page shows only while it is not.
 */
add_filter(
	'render_block',
	function ( $html, $block ) {
		if ( 'core/button' !== ( $block['blockName'] ?? '' ) || is_admin() ) {
			return $html;
		}
		if ( false !== strpos( $html, '/read/' ) && false !== strpos( $html, '#/buy' ) ) {
			return bk_on_sale() ? $html : '';
		}
		if ( bk_on_sale() && false !== strpos( $html, 'href="#join"' ) && is_page( 'book' ) ) {
			return '';
		}
		return $html;
	},
	10,
	2
);

/* ---------- password reset ---------- */

function bk_reset_email( $user, $book ) {
	$key = get_password_reset_key( $user );
	if ( is_wp_error( $key ) ) {
		return false;
	}
	$url = add_query_arg( array( 'bk_rp' => rawurlencode( $key ), 'login' => rawurlencode( $user->user_login ) ), bk_reader_url( $book ) );
	return bk_send_email(
		$user->user_email,
		'Reset your password',
		array(
			'preheader' => 'Choose a new password for your reading account.',
			'heading'   => 'Reset your password',
			'paras'     => array( 'Hi ' . bk_first_name( $user ) . ', we got a request to reset the password for your reading account. Choose a new one with the button below. The link works for 24 hours.' ),
			'cta'       => array( 'Choose a new password', $url ),
			'after'     => array( 'If you did not ask for this, you can ignore this email. Your password stays the same.' ),
			'foot'      => 'Sent from ' . esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '.',
			'foot_text' => 'Sent from ' . wp_parse_url( home_url(), PHP_URL_HOST ) . '.',
		)
	);
}

function bk_back_book() {
	$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : ''; // phpcs:ignore
	if ( preg_match( '#/read/([^/?\#]+)#', $back, $m ) ) {
		$b = bk_book_by_slug( $m[1] );
		if ( $b ) {
			return $b;
		}
	}
	$all = get_posts( array( 'post_type' => 'bk_book', 'numberposts' => 1 ) );
	return $all ? $all[0] : null;
}

/* Step 1: ask for the link. Always answers the same way, so it never reveals who has an account. */
function bk_handle_lost() {
	if ( ! isset( $_POST['bk_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['bk_nonce'] ), 'bk_auth' ) ) {
		bk_auth_back( 'nonce', 'lost' );
	}
	$user = get_user_by( 'email', sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) );
	$book = bk_back_book();
	if ( $user && $book && ! user_can( $user, 'manage_options' ) ) {
		bk_reset_email( $user, $book );
	}
	bk_auth_back( 'sent', 'lost' );
}
add_action( 'admin_post_nopriv_bk_lost', 'bk_handle_lost' );
add_action( 'admin_post_bk_lost', 'bk_auth_back' );

/* Step 2: choose the new password from the emailed link. */
function bk_handle_reset() {
	if ( ! isset( $_POST['bk_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['bk_nonce'] ), 'bk_auth' ) ) {
		bk_auth_back( 'nonce', 'lost' );
	}
	$login = sanitize_user( wp_unslash( $_POST['login'] ?? '' ) );
	$key   = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );
	$pass  = (string) wp_unslash( $_POST['pass'] ?? '' ); // phpcs:ignore
	$user  = check_password_reset_key( $key, $login );
	if ( is_wp_error( $user ) ) {
		bk_auth_back( 'expired', 'lost' );
	}
	if ( strlen( $pass ) < 8 ) {
		$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : home_url( '/' ); // phpcs:ignore
		wp_safe_redirect( add_query_arg( array( 'bk_rp' => rawurlencode( $key ), 'login' => rawurlencode( $login ), 'bk_err' => 'pass' ), $back ) );
		exit;
	}
	reset_password( $user, $pass );
	wp_set_current_user( $user->ID );
	wp_set_auth_cookie( $user->ID, true, is_ssl() );
	bk_auth_back();
}
add_action( 'admin_post_nopriv_bk_reset', 'bk_handle_reset' );
add_action( 'admin_post_bk_reset', 'bk_handle_reset' );

/* ---------- account settings ---------- */

add_action(
	'rest_api_init',
	function () {
		$auth = function () {
			return is_user_logged_in() ? true : bk_err( 'bk_login', 'Please sign in.', 401 );
		};
		register_rest_route( 'bookkeeper/v1', '/account', array( 'methods' => 'POST', 'permission_callback' => $auth, 'callback' => 'bk_rest_account' ) );
		register_rest_route( 'bookkeeper/v1', '/account/password', array( 'methods' => 'POST', 'permission_callback' => $auth, 'callback' => 'bk_rest_password' ) );
		register_rest_route( 'bookkeeper/v1', '/account/delete', array( 'methods' => 'POST', 'permission_callback' => $auth, 'callback' => 'bk_rest_account_delete' ) );
	}
);

function bk_rest_account( WP_REST_Request $req ) {
	$uid  = get_current_user_id();
	$name = sanitize_text_field( (string) $req['name'] );
	if ( '' === $name ) {
		return bk_err( 'bk_name', 'Please enter your name.', 400 );
	}
	wp_update_user( array( 'ID' => $uid, 'first_name' => $name, 'display_name' => $name ) );
	return array( 'name' => $name );
}

function bk_rest_password( WP_REST_Request $req ) {
	$user = wp_get_current_user();
	$cur  = (string) $req['current'];
	$new  = (string) $req['new'];
	if ( ! wp_check_password( $cur, $user->user_pass, $user->ID ) ) {
		return bk_err( 'bk_pass', 'Your current password is not right.', 400 );
	}
	if ( strlen( $new ) < 8 ) {
		return bk_err( 'bk_pass', 'Use a new password of at least 8 characters.', 400 );
	}
	wp_set_password( $new, $user->ID );
	// wp_set_password signs the user out; sign them straight back in on this device.
	wp_set_current_user( $user->ID );
	wp_set_auth_cookie( $user->ID, true, is_ssl() );
	return array( 'ok' => true, 'nonce' => wp_create_nonce( 'wp_rest' ) );
}

/** Remove everything the reader wrote, then the account. Purchases are kept in the orders table for the books. */
function bk_rest_account_delete( WP_REST_Request $req ) {
	global $wpdb;
	$user = wp_get_current_user();
	if ( user_can( $user, 'manage_options' ) ) {
		return bk_err( 'bk_admin', 'Site admins cannot delete their account from the reader.', 400 );
	}
	if ( ! wp_check_password( (string) $req['password'], $user->user_pass, $user->ID ) ) {
		return bk_err( 'bk_pass', 'Your password is not right.', 400 );
	}
	foreach ( array( 'marks', 'entries', 'progress' ) as $t ) {
		$wpdb->delete( $wpdb->prefix . 'bk_' . $t, array( 'user_id' => $user->ID ) );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_logout();
	wp_delete_user( $user->ID );
	return array( 'deleted' => true, 'home' => home_url( '/' ) );
}
