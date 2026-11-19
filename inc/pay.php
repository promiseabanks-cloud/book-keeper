<?php
defined( 'ABSPATH' ) || exit;

/*
 * Paystack checkout. One-off purchase per book, priced in naira and (when the
 * Paystack account allows it) dollars. The reader starts a checkout over REST,
 * pays on Paystack's page, and comes back to the reader. Access is granted only
 * after the transaction is verified with Paystack, from the return visit or the
 * webhook, whichever comes first.
 */

const BK_PAYSTACK = 'https://api.paystack.co/';

function bk_pay_opts() {
	return wp_parse_args(
		(array) get_option( 'bk_pay', array() ),
		array(
			'public' => '',
			'secret' => '',
			'usd'    => 0,
		)
	);
}

function bk_pay_ready() {
	$o = bk_pay_opts();
	return (bool) $o['secret'];
}

function bk_pay_mode() {
	$o = bk_pay_opts();
	return 0 === strpos( $o['secret'], 'sk_live_' ) ? 'live' : 'test';
}

function bk_money( $amount, $cur ) {
	$sym = array( 'NGN' => '₦', 'USD' => '$' );
	$dec = floor( $amount ) == $amount ? 0 : 2; // phpcs:ignore
	return ( $sym[ $cur ] ?? $cur . ' ' ) . number_format( $amount, $dec );
}

/** Prices on offer for a book: currency => array( amount in main units, minor units, label ). */
function bk_prices( $book_id ) {
	$o   = bk_pay_opts();
	$out = array();
	$ngn = (float) get_post_meta( $book_id, '_bk_price_ngn', true );
	$usd = (float) get_post_meta( $book_id, '_bk_price_usd', true );
	if ( $ngn > 0 ) {
		$out['NGN'] = array( 'amount' => $ngn, 'minor' => (int) round( $ngn * 100 ), 'label' => bk_money( $ngn, 'NGN' ) );
	}
	if ( $usd > 0 && $o['usd'] ) {
		$out['USD'] = array( 'amount' => $usd, 'minor' => (int) round( $usd * 100 ), 'label' => bk_money( $usd, 'USD' ) );
	}
	return $out;
}

/** What the reader app needs to show the buy buttons. */
function bk_pay_config( $book_id ) {
	if ( ! bk_pay_ready() ) {
		return array();
	}
	$out = array();
	foreach ( bk_prices( $book_id ) as $cur => $p ) {
		$out[] = array( 'cur' => $cur, 'label' => $p['label'] );
	}
	return $out;
}

function bk_paystack( $method, $path, $body = null ) {
	$o    = bk_pay_opts();
	$args = array(
		'method'  => $method,
		'timeout' => 15,
		'headers' => array( 'Authorization' => 'Bearer ' . $o['secret'], 'Content-Type' => 'application/json' ),
	);
	if ( null !== $body ) {
		$args['body'] = wp_json_encode( $body );
	}
	$r = wp_remote_request( BK_PAYSTACK . $path, $args );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	$j = json_decode( wp_remote_retrieve_body( $r ), true );
	if ( ! is_array( $j ) || empty( $j['status'] ) ) {
		return new WP_Error( 'bk_paystack', is_array( $j ) && ! empty( $j['message'] ) ? $j['message'] : 'Paystack did not respond.' );
	}
	return $j['data'];
}

function bk_order( $ref ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bk_orders WHERE reference=%s", $ref ) );
}

/* ---------- start a checkout ---------- */

function bk_rest_checkout( WP_REST_Request $req ) {
	global $wpdb;
	$uid  = get_current_user_id();
	$book = get_post( (int) $req['book'] );
	$cur  = strtoupper( (string) $req['currency'] );
	if ( ! $book || 'bk_book' !== $book->post_type ) {
		return bk_err( 'bk_no_book', 'Book not found.', 404 );
	}
	if ( bk_user_owns( $uid, $book->ID ) ) {
		return bk_err( 'bk_owned', 'You already own this book.', 400 );
	}
	if ( ! bk_pay_ready() ) {
		return bk_err( 'bk_pay_off', 'Payments are not set up yet.', 503 );
	}
	$prices = bk_prices( $book->ID );
	if ( ! isset( $prices[ $cur ] ) ) {
		return bk_err( 'bk_currency', 'That currency is not available.', 400 );
	}
	$user = wp_get_current_user();
	$ref  = 'bk-' . $book->ID . '-' . $uid . '-' . strtolower( wp_generate_password( 10, false ) );
	$back = bk_reader_url( $book );
	$data = bk_paystack(
		'POST',
		'transaction/initialize',
		array(
			'email'        => $user->user_email,
			'amount'       => $prices[ $cur ]['minor'],
			'currency'     => $cur,
			'reference'    => $ref,
			'callback_url' => add_query_arg( 'bk_paid', 1, $back ),
			'metadata'     => array(
				'user_id'       => $uid,
				'book_id'       => $book->ID,
				'cancel_action' => $back . '#/buy',
				'custom_fields' => array( array( 'display_name' => 'Book', 'variable_name' => 'book', 'value' => get_the_title( $book ) ) ),
			),
		)
	);
	if ( is_wp_error( $data ) || empty( $data['authorization_url'] ) ) {
		return bk_err( 'bk_paystack', 'We could not start the payment. Please try again in a moment.', 502 );
	}
	$wpdb->insert(
		$wpdb->prefix . 'bk_orders',
		array(
			'reference' => $ref,
			'user_id'   => $uid,
			'book_id'   => $book->ID,
			'currency'  => $cur,
			'amount'    => $prices[ $cur ]['minor'],
			'status'    => 'pending',
			'mode'      => bk_pay_mode(),
			'created'   => bk_now(),
			'updated'   => bk_now(),
		)
	);
	return array( 'url' => $data['authorization_url'], 'reference' => $ref );
}

/* ---------- confirm a payment (return visit or webhook) ---------- */

/** Ask Paystack about a reference and unlock the book if it was paid in full. Safe to call twice. */
function bk_confirm_payment( $ref ) {
	global $wpdb;
	$order = bk_order( $ref );
	if ( ! $order ) {
		return false;
	}
	if ( 'paid' === $order->status ) {
		return true;
	}
	$tx = bk_paystack( 'GET', 'transaction/verify/' . rawurlencode( $ref ) );
	if ( is_wp_error( $tx ) ) {
		return false;
	}
	$ok = 'success' === ( $tx['status'] ?? '' )
		&& (int) ( $tx['amount'] ?? 0 ) >= (int) $order->amount
		&& strtoupper( $tx['currency'] ?? '' ) === $order->currency;
	if ( ! $ok ) {
		if ( in_array( $tx['status'] ?? '', array( 'failed', 'abandoned', 'reversed' ), true ) ) {
			$wpdb->update( $wpdb->prefix . 'bk_orders', array( 'status' => $tx['status'], 'updated' => bk_now() ), array( 'id' => $order->id ) );
		}
		return false;
	}
	// Only the first caller to flip the row grants and emails.
	$flipped = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}bk_orders SET status='paid', paystack_id=%s, updated=%s WHERE id=%d AND status<>'paid'", (string) ( $tx['id'] ?? '' ), bk_now(), $order->id ) );
	if ( $flipped ) {
		bk_grant( (int) $order->user_id, (int) $order->book_id, 'paystack:' . $ref );
		bk_receipt_email( $order );
	}
	return true;
}

function bk_receipt_email( $order ) {
	$user = get_userdata( (int) $order->user_id );
	if ( ! $user ) {
		return;
	}
	bk_send_email( $user->user_email, 'Your copy of ' . get_the_title( (int) $order->book_id ) . ' is ready', bk_receipt_args( $user, $order ) );
}

/* Return from Paystack: verify, then land on the reader with a thank-you. */
add_action(
	'template_redirect',
	function () {
		if ( ! get_query_var( 'bk_read' ) || empty( $_GET['bk_paid'] ) ) { // phpcs:ignore
			return;
		}
		$ref  = sanitize_text_field( wp_unslash( $_GET['reference'] ?? '' ) ); // phpcs:ignore
		$book = bk_book_by_slug( get_query_var( 'bk_read' ) );
		if ( ! $book ) {
			return;
		}
		$ok = $ref && bk_confirm_payment( $ref );
		wp_safe_redirect( add_query_arg( 'bk_thanks', $ok ? 1 : 0, bk_reader_url( $book ) ) . ( $ok ? '#/' : '#/buy' ) );
		exit;
	},
	5
);

/* Paystack webhook: signed with the secret key. */
function bk_rest_paystack_hook( WP_REST_Request $req ) {
	$o   = bk_pay_opts();
	$raw = $req->get_body();
	$sig = (string) $req->get_header( 'x-paystack-signature' );
	if ( ! $o['secret'] || ! $sig || ! hash_equals( hash_hmac( 'sha512', $raw, $o['secret'] ), $sig ) ) {
		return new WP_REST_Response( array( 'ok' => false ), 401 );
	}
	$ev = json_decode( $raw, true );
	if ( 'charge.success' === ( $ev['event'] ?? '' ) && ! empty( $ev['data']['reference'] ) ) {
		bk_confirm_payment( sanitize_text_field( $ev['data']['reference'] ) );
	}
	return new WP_REST_Response( array( 'ok' => true ), 200 );
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'bookkeeper/v1',
			'/checkout',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return is_user_logged_in() ? true : bk_err( 'bk_login', 'Please sign in.', 401 );
				},
				'callback'            => 'bk_rest_checkout',
			)
		);
		register_rest_route(
			'bookkeeper/v1',
			'/paystack',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true', // checked by signature
				'callback'            => 'bk_rest_paystack_hook',
			)
		);
	}
);

/* ---------- book prices box ---------- */

add_action(
	'add_meta_boxes_bk_book',
	function () {
		add_meta_box( 'bk-price', 'Price', 'bk_price_box', 'bk_book', 'side' );
	}
);

function bk_price_box( $post ) {
	wp_nonce_field( 'bk_price', 'bk_price_nonce' );
	$ngn = get_post_meta( $post->ID, '_bk_price_ngn', true );
	$usd = get_post_meta( $post->ID, '_bk_price_usd', true );
	echo '<p><label>Naira (₦)<br><input type="number" step="0.01" min="0" name="bk_price_ngn" value="' . esc_attr( $ngn ) . '" style="width:100%"></label></p>';
	echo '<p><label>Dollars ($)<br><input type="number" step="0.01" min="0" name="bk_price_usd" value="' . esc_attr( $usd ) . '" style="width:100%"></label></p>';
	echo '<p class="description">One-off price to unlock every chapter. Dollars only show once they are switched on under Bookkeeper settings.</p>';
}

add_action(
	'save_post_bk_book',
	function ( $id ) {
		if ( ! isset( $_POST['bk_price_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['bk_price_nonce'] ), 'bk_price' ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		foreach ( array( 'ngn', 'usd' ) as $c ) {
			$v = isset( $_POST[ 'bk_price_' . $c ] ) ? round( (float) $_POST[ 'bk_price_' . $c ], 2 ) : 0;
			update_post_meta( $id, '_bk_price_' . $c, $v > 0 ? $v : '' );
		}
	}
);

/* ---------- admin: payment settings and recent orders ---------- */

function bk_pay_settings_save() {
	$o        = bk_pay_opts();
	$o['usd'] = empty( $_POST['pay_usd'] ) ? 0 : 1; // phpcs:ignore -- nonce checked by the caller
	foreach ( array( 'public', 'secret' ) as $k ) {
		$v = trim( sanitize_text_field( wp_unslash( $_POST[ 'pay_' . $k ] ?? '' ) ) ); // phpcs:ignore
		if ( '' !== $v ) {
			$o[ $k ] = $v;
		}
	}
	update_option( 'bk_pay', $o, false );
}

function bk_pay_settings_fields() {
	$o    = bk_pay_opts();
	$hook = rest_url( 'bookkeeper/v1/paystack' );
	?>
	<h2>Payments (Paystack)</h2>
	<p>Find the keys in Paystack under Settings, then API Keys &amp; Webhooks. <?php echo $o['secret'] ? 'Currently in <strong>' . esc_html( bk_pay_mode() ) . ' mode</strong>.' : 'Not set up yet.'; ?></p>
	<table class="form-table" role="presentation">
		<tr><th scope="row"><label for="bk-pk">Public key</label></th><td><input id="bk-pk" class="regular-text" name="pay_public" autocomplete="off" placeholder="<?php echo $o['public'] ? esc_attr( substr( $o['public'], 0, 12 ) . '… saved. Leave blank to keep it.' ) : 'pk_test_… or pk_live_…'; ?>"></td></tr>
		<tr><th scope="row"><label for="bk-sk">Secret key</label></th><td><input id="bk-sk" class="regular-text" name="pay_secret" type="password" autocomplete="new-password" placeholder="<?php echo $o['secret'] ? 'Saved. Leave blank to keep it.' : 'sk_test_… or sk_live_…'; ?>"></td></tr>
		<tr><th scope="row">Dollar payments</th><td><label><input type="checkbox" name="pay_usd" value="1" <?php checked( $o['usd'] ); ?>> Offer the dollar price</label><p class="description">Turn this on only after Paystack has enabled USD on the account. Until then, Paystack refuses dollar charges.</p></td></tr>
		<tr><th scope="row">Webhook URL</th><td><code><?php echo esc_html( $hook ); ?></code><p class="description">Paste this into Paystack as the webhook URL, so a purchase unlocks even if the buyer closes the tab before returning.</p></td></tr>
	</table>
	<?php
}

function bk_orders_table() {
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}bk_orders ORDER BY id DESC LIMIT 30" ); // phpcs:ignore
	echo '<h2>Recent payments</h2>';
	if ( ! $rows ) {
		echo '<p>No payments yet.</p>';
		return;
	}
	echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Reader</th><th>Book</th><th>Amount</th><th>Status</th><th>Reference</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$u = get_userdata( (int) $r->user_id );
		echo '<tr><td>' . esc_html( get_date_from_gmt( $r->created, 'j M Y, H:i' ) ) . '</td><td>' . esc_html( $u ? $u->user_email : '#' . $r->user_id ) . '</td><td>' . esc_html( get_the_title( (int) $r->book_id ) ) . '</td><td>' . esc_html( bk_money( $r->amount / 100, $r->currency ) ) . '</td><td>' . esc_html( $r->status . ( 'test' === $r->mode ? ' (test)' : '' ) ) . '</td><td><code>' . esc_html( $r->reference ) . '</code></td></tr>';
	}
	echo '</tbody></table>';
}
