<?php
defined( 'ABSPATH' ) || exit;

/*
 * Google Docs sync. A reader connects their Google account once; the plugin
 * then keeps one Google Doc per book ("My notes on <book>") up to date with
 * their notes. It uses the drive.file scope, so it can only see files it made.
 * The site owner adds an OAuth client (ID and secret) under Bookkeeper settings.
 */

function bk_g_opts() {
	return wp_parse_args( (array) get_option( 'bk_google', array() ), array( 'id' => '', 'secret' => '' ) );
}

function bk_g_ready() {
	$o = bk_g_opts();
	return $o['id'] && $o['secret'];
}

function bk_g_redirect() {
	return rest_url( 'bookkeeper/v1/google/callback' );
}

function bk_g_state( $uid, $book, $t ) {
	return $uid . '.' . $book . '.' . $t . '.' . substr( hash_hmac( 'sha256', 'bk-g|' . $uid . '|' . $book . '|' . $t, wp_salt( 'auth' ) ), 0, 32 );
}

/** What the reader app needs to know. */
function bk_g_config( $uid, $book ) {
	$doc = get_user_meta( $uid, 'bk_gdoc_' . $book, true );
	return array(
		'available' => bk_g_ready(),
		'connected' => (bool) get_user_meta( $uid, 'bk_g_refresh', true ),
		'doc'       => $doc ? 'https://docs.google.com/document/d/' . rawurlencode( $doc ) . '/edit' : '',
		'synced'    => (string) get_user_meta( $uid, 'bk_gdoc_at_' . $book, true ),
	);
}

function bk_g_post( $url, $body, $token = '', $json = true ) {
	$args = array( 'timeout' => 20, 'headers' => array() );
	if ( $token ) {
		$args['headers']['Authorization'] = 'Bearer ' . $token;
	}
	if ( $json ) {
		$args['headers']['Content-Type'] = 'application/json';
		$args['body']                    = wp_json_encode( $body );
	} else {
		$args['body'] = $body;
	}
	$r = wp_remote_post( $url, $args );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	$j = json_decode( wp_remote_retrieve_body( $r ), true );
	$c = wp_remote_retrieve_response_code( $r );
	if ( $c >= 300 ) {
		return new WP_Error( 'bk_google', is_array( $j ) && isset( $j['error']['message'] ) ? $j['error']['message'] : ( is_array( $j ) && isset( $j['error'] ) && is_string( $j['error'] ) ? $j['error'] : 'Google returned ' . $c ), array( 'code' => $c ) );
	}
	return $j;
}

function bk_g_token( $uid ) {
	$cached = get_transient( 'bk_g_at_' . $uid );
	if ( $cached ) {
		return $cached;
	}
	$o       = bk_g_opts();
	$refresh = get_user_meta( $uid, 'bk_g_refresh', true );
	if ( ! $refresh ) {
		return new WP_Error( 'bk_google', 'Google Docs is not connected.' );
	}
	$j = bk_g_post( 'https://oauth2.googleapis.com/token', array( 'client_id' => $o['id'], 'client_secret' => $o['secret'], 'refresh_token' => $refresh, 'grant_type' => 'refresh_token' ), '', false );
	if ( is_wp_error( $j ) ) {
		if ( false !== stripos( $j->get_error_message(), 'invalid_grant' ) ) {
			delete_user_meta( $uid, 'bk_g_refresh' ); // access was revoked in Google
		}
		return $j;
	}
	set_transient( 'bk_g_at_' . $uid, $j['access_token'], max( 60, (int) $j['expires_in'] - 120 ) );
	return $j['access_token'];
}

/** UTF-16 length, which is how Google Docs counts positions. */
function bk_g_len( $s ) {
	return (int) ( strlen( mb_convert_encoding( $s, 'UTF-16LE', 'UTF-8' ) ) / 2 );
}

/** Write the reader's notes into their Google Doc, creating it the first time. */
function bk_g_sync( $uid, $book ) {
	$token = bk_g_token( $uid );
	if ( is_wp_error( $token ) ) {
		return $token;
	}
	$lines = bk_notes_outline( $uid, $book );
	$title = 'My notes on ' . get_the_title( $book );
	$doc   = get_user_meta( $uid, 'bk_gdoc_' . $book, true );
	$end   = 1;
	if ( $doc ) {
		$r = wp_remote_get( 'https://docs.googleapis.com/v1/documents/' . rawurlencode( $doc ) . '?fields=body(content(endIndex))', array( 'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . $token ) ) );
		$c = is_wp_error( $r ) ? 0 : wp_remote_retrieve_response_code( $r );
		if ( 200 === $c ) {
			$j    = json_decode( wp_remote_retrieve_body( $r ), true );
			$last = end( $j['body']['content'] );
			$end  = (int) ( $last['endIndex'] ?? 1 );
		} elseif ( 404 === $c || 403 === $c ) {
			$doc = ''; // the reader deleted the doc; make a new one
		} else {
			return new WP_Error( 'bk_google', 'Could not reach Google Docs. Please try again.' );
		}
	}
	if ( ! $doc ) {
		$j = bk_g_post( 'https://docs.googleapis.com/v1/documents', array( 'title' => $title ), $token );
		if ( is_wp_error( $j ) ) {
			return $j;
		}
		$doc = $j['documentId'];
		update_user_meta( $uid, 'bk_gdoc_' . $book, $doc );
		$end = 1;
	}
	$text   = '';
	$styles = array();
	$map    = array( 'h1' => 'TITLE', 'h2' => 'HEADING_1', 'h3' => 'HEADING_2' );
	$pos    = 1;
	foreach ( $lines as $l ) {
		$chunk = str_replace( "\r", '', ( 'quote' === $l[0] ? '"' . $l[1] . '"' : $l[1] ) ) . "\n";
		$len   = bk_g_len( $chunk );
		$styles[] = array( 'start' => $pos, 'end' => $pos + $len, 'kind' => $l[0] );
		$text    .= $chunk;
		$pos     += $len;
	}
	$reqs = array();
	if ( $end > 2 ) {
		$reqs[] = array( 'deleteContentRange' => array( 'range' => array( 'startIndex' => 1, 'endIndex' => $end - 1 ) ) );
	}
	$reqs[] = array( 'insertText' => array( 'location' => array( 'index' => 1 ), 'text' => $text ) );
	$reqs[] = array( 'deleteParagraphBullets' => array( 'range' => array( 'startIndex' => 1, 'endIndex' => $pos ) ) );
	$reqs[] = array(
		'updateParagraphStyle' => array(
			'range'          => array( 'startIndex' => 1, 'endIndex' => $pos ),
			'paragraphStyle' => array( 'namedStyleType' => 'NORMAL_TEXT' ),
			'fields'         => 'namedStyleType',
		),
	);
	foreach ( $styles as $s ) {
		if ( isset( $map[ $s['kind'] ] ) ) {
			$reqs[] = array(
				'updateParagraphStyle' => array(
					'range'          => array( 'startIndex' => $s['start'], 'endIndex' => $s['end'] ),
					'paragraphStyle' => array( 'namedStyleType' => $map[ $s['kind'] ] ),
					'fields'         => 'namedStyleType',
				),
			);
		} elseif ( 'quote' === $s['kind'] ) {
			$reqs[] = array(
				'updateParagraphStyle' => array(
					'range'          => array( 'startIndex' => $s['start'], 'endIndex' => $s['end'] ),
					'paragraphStyle' => array( 'indentStart' => array( 'magnitude' => 18, 'unit' => 'PT' ), 'indentFirstLine' => array( 'magnitude' => 18, 'unit' => 'PT' ) ),
					'fields'         => 'indentStart,indentFirstLine',
				),
			);
		} elseif ( 'item' === $s['kind'] ) {
			$reqs[] = array( 'createParagraphBullets' => array( 'range' => array( 'startIndex' => $s['start'], 'endIndex' => $s['end'] ), 'bulletPreset' => 'BULLET_DISC_CIRCLE_SQUARE' ) );
		}
	}
	$r = bk_g_post( 'https://docs.googleapis.com/v1/documents/' . rawurlencode( $doc ) . ':batchUpdate', array( 'requests' => $reqs ), $token );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	update_user_meta( $uid, 'bk_gdoc_at_' . $book, current_time( 'mysql', true ) );
	return array( 'doc' => 'https://docs.google.com/document/d/' . rawurlencode( $doc ) . '/edit' );
}

/* Keep the doc fresh: a sync runs a couple of minutes after the reader saves something. */
add_action(
	'bk_gdoc_sync',
	function ( $uid, $book ) {
		bk_g_sync( (int) $uid, (int) $book );
	},
	10,
	2
);

add_filter(
	'rest_post_dispatch',
	function ( $res, $server, $req ) {
		$route = $req->get_route();
		if ( 'GET' === $req->get_method() || ! preg_match( '#^/bookkeeper/v1/(entries|marks)#', $route ) || $res->is_error() ) {
			return $res;
		}
		$uid = get_current_user_id();
		if ( ! $uid || ! bk_g_ready() || ! get_user_meta( $uid, 'bk_g_refresh', true ) ) {
			return $res;
		}
		$data = $res->get_data();
		$book = (int) ( $req['book'] ?: 0 );
		if ( ! $book && is_array( $data ) && ! empty( $data['chapter'] ) ) {
			$book = (int) get_post_meta( (int) $data['chapter'], '_bk_book', true );
		}
		if ( ! $book && preg_match( '#/(entries|marks)/(\d+)#', $route, $m ) ) {
			global $wpdb;
			$book = (int) $wpdb->get_var( $wpdb->prepare( "SELECT book_id FROM {$wpdb->prefix}bk_" . $m[1] . ' WHERE id=%d', (int) $m[2] ) ); // phpcs:ignore
		}
		if ( $book && ! wp_next_scheduled( 'bk_gdoc_sync', array( $uid, $book ) ) ) {
			wp_schedule_single_event( time() + 120, 'bk_gdoc_sync', array( $uid, $book ) );
		}
		return $res;
	},
	10,
	3
);

/* ---------- connect, callback, sync, disconnect ---------- */

add_action(
	'rest_api_init',
	function () {
		$auth = function () {
			return is_user_logged_in() ? true : bk_err( 'bk_login', 'Please sign in.', 401 );
		};
		register_rest_route( 'bookkeeper/v1', '/google/connect', array( 'methods' => 'GET', 'permission_callback' => $auth, 'callback' => 'bk_rest_g_connect' ) );
		register_rest_route( 'bookkeeper/v1', '/google/callback', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'bk_rest_g_callback' ) );
		register_rest_route( 'bookkeeper/v1', '/google/sync', array( 'methods' => 'POST', 'permission_callback' => $auth, 'callback' => 'bk_rest_g_sync' ) );
		register_rest_route( 'bookkeeper/v1', '/google/disconnect', array( 'methods' => 'POST', 'permission_callback' => $auth, 'callback' => 'bk_rest_g_disconnect' ) );
	}
);

/* Returns the Google consent URL; the reader app sends the browser there. */
function bk_rest_g_connect( WP_REST_Request $req ) {
	if ( ! bk_g_ready() ) {
		return bk_err( 'bk_google', 'Google Docs is not set up on this site yet.', 503 );
	}
	$book = (int) $req['book'];
	if ( 'bk_book' !== get_post_type( $book ) ) {
		return bk_err( 'bk_no_book', 'Book not found.', 404 );
	}
	$o = bk_g_opts();
	return array(
		'url' => add_query_arg(
			array(
				'client_id'     => rawurlencode( $o['id'] ),
				'redirect_uri'  => rawurlencode( bk_g_redirect() ),
				'response_type' => 'code',
				'scope'         => rawurlencode( 'https://www.googleapis.com/auth/drive.file' ),
				'access_type'   => 'offline',
				'prompt'        => 'consent',
				'state'         => rawurlencode( bk_g_state( get_current_user_id(), $book, time() ) ),
			),
			'https://accounts.google.com/o/oauth2/v2/auth'
		),
	);
}

/* Google sends the browser back here. The signed state says which reader and book it is for. */
function bk_rest_g_callback( WP_REST_Request $req ) {
	$parts = explode( '.', (string) $req['state'] );
	$ok    = 4 === count( $parts ) && hash_equals( bk_g_state( (int) $parts[0], (int) $parts[1], (int) $parts[2] ), (string) $req['state'] ) && time() - (int) $parts[2] < HOUR_IN_SECONDS;
	$uid   = $ok ? (int) $parts[0] : 0;
	$book  = $ok ? (int) $parts[1] : 0;
	$back  = $book ? bk_reader_url( $book ) : home_url( '/' );
	$flag  = 'denied';
	if ( $ok && $req['code'] ) {
		$o = bk_g_opts();
		$j = bk_g_post( 'https://oauth2.googleapis.com/token', array( 'code' => (string) $req['code'], 'client_id' => $o['id'], 'client_secret' => $o['secret'], 'redirect_uri' => bk_g_redirect(), 'grant_type' => 'authorization_code' ), '', false );
		if ( ! is_wp_error( $j ) && ! empty( $j['refresh_token'] ) ) {
			update_user_meta( $uid, 'bk_g_refresh', $j['refresh_token'] );
			set_transient( 'bk_g_at_' . $uid, $j['access_token'], max( 60, (int) $j['expires_in'] - 120 ) );
			$s    = bk_g_sync( $uid, $book );
			$flag = is_wp_error( $s ) ? 'error' : 'connected';
		} else {
			$flag = 'error';
		}
	}
	wp_safe_redirect( add_query_arg( 'bk_google', $flag, $back ) . '#/notes' );
	exit;
}

function bk_rest_g_sync( WP_REST_Request $req ) {
	$book = (int) $req['book'];
	if ( 'bk_book' !== get_post_type( $book ) ) {
		return bk_err( 'bk_no_book', 'Book not found.', 404 );
	}
	$r = bk_g_sync( get_current_user_id(), $book );
	if ( is_wp_error( $r ) ) {
		return bk_err( 'bk_google', 'Sync failed: ' . $r->get_error_message(), 502 );
	}
	return array_merge( $r, bk_g_config( get_current_user_id(), $book ) );
}

function bk_rest_g_disconnect( WP_REST_Request $req ) {
	$uid     = get_current_user_id();
	$refresh = get_user_meta( $uid, 'bk_g_refresh', true );
	if ( $refresh ) {
		wp_remote_post( 'https://oauth2.googleapis.com/revoke', array( 'timeout' => 10, 'body' => array( 'token' => $refresh ) ) );
	}
	delete_user_meta( $uid, 'bk_g_refresh' );
	delete_transient( 'bk_g_at_' . $uid );
	return bk_g_config( $uid, (int) $req['book'] );
}

/* ---------- admin settings ---------- */

function bk_g_settings_save() {
	$o = bk_g_opts();
	$i = trim( sanitize_text_field( wp_unslash( $_POST['g_id'] ?? '' ) ) ); // phpcs:ignore -- nonce checked by the caller
	$s = trim( sanitize_text_field( wp_unslash( $_POST['g_secret'] ?? '' ) ) ); // phpcs:ignore
	if ( '' !== $i ) {
		$o['id'] = $i;
	}
	if ( '' !== $s ) {
		$o['secret'] = $s;
	}
	update_option( 'bk_google', $o, false );
}

function bk_g_settings_fields() {
	$o = bk_g_opts();
	?>
	<h2>Google Docs sync</h2>
	<p>Lets readers keep their notes in a Google Doc. Create an OAuth client in Google Cloud (APIs &amp; Services, Credentials, type "Web application"), enable the Google Docs API, and add this as an authorised redirect URI:</p>
	<p><code><?php echo esc_html( bk_g_redirect() ); ?></code></p>
	<table class="form-table" role="presentation">
		<tr><th scope="row"><label for="bk-gid">Client ID</label></th><td><input id="bk-gid" class="regular-text" name="g_id" autocomplete="off" placeholder="<?php echo $o['id'] ? 'Saved. Leave blank to keep it.' : '….apps.googleusercontent.com'; ?>"></td></tr>
		<tr><th scope="row"><label for="bk-gsec">Client secret</label></th><td><input id="bk-gsec" class="regular-text" name="g_secret" type="password" autocomplete="new-password" placeholder="<?php echo $o['secret'] ? 'Saved. Leave blank to keep it.' : 'GOCSPX-…'; ?>"></td></tr>
	</table>
	<?php
}
