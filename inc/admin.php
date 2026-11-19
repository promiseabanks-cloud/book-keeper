<?php
defined( 'ABSPATH' ) || exit;

/* ---------- mail through SMTP (a cPanel mailbox or any SMTP service) ---------- */

function bk_mail_opts() {
	return wp_parse_args(
		(array) get_option( 'bk_mail', array() ),
		array(
			'on'        => 0,
			'host'      => 'smtp-relay.brevo.com',
			'port'      => 587,
			'user'      => '',
			'key'       => '',
			'from'      => '',
			'from_name' => '',
		)
	);
}

add_action(
	'phpmailer_init',
	function ( $m ) {
		$o = bk_mail_opts();
		if ( ! $o['on'] || ! $o['user'] || ! $o['key'] ) {
			return;
		}
		$m->isSMTP();
		$m->Host       = $o['host'];
		$m->Port       = (int) $o['port'];
		$m->SMTPAuth   = true;
		$m->SMTPSecure = 465 === (int) $o['port'] ? 'ssl' : 'tls';
		$m->Username   = $o['user'];
		$m->Password   = $o['key'];
		$m->Timeout    = 15; // never hang a page on a dead mail server
		if ( $o['from'] ) {
			$m->setFrom( $o['from'], $o['from_name'] ?: get_bloginfo( 'name' ), false );
		}
	}
);

/* This host disables PHP mail(). Without SMTP, wp_mail would crash the page, so fail quietly instead. */
add_filter(
	'pre_wp_mail',
	function ( $ret ) {
		$o = bk_mail_opts();
		if ( null === $ret && ! function_exists( 'mail' ) && ( ! $o['on'] || ! $o['user'] || ! $o['key'] ) ) {
			return false;
		}
		return $ret;
	}
);

/* ---------- admin screens ---------- */

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'edit.php?post_type=bk_book', 'Bookkeeper settings', 'Settings', 'manage_options', 'bk-settings', 'bk_settings_page' );
		add_submenu_page( 'edit.php?post_type=bk_book', 'Readers', 'Readers', 'manage_options', 'bk-readers', 'bk_readers_page' );
	}
);

function bk_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$msg = '';
	if ( isset( $_POST['bk_settings_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['bk_settings_nonce'] ), 'bk_settings' ) ) {
		$o              = bk_mail_opts();
		$o['on']        = empty( $_POST['on'] ) ? 0 : 1;
		$o['host']      = sanitize_text_field( wp_unslash( $_POST['host'] ?? $o['host'] ) );
		$o['port']      = (int) ( $_POST['port'] ?? 587 );
		$o['user']      = sanitize_text_field( wp_unslash( $_POST['user'] ?? '' ) );
		$o['from']      = sanitize_email( wp_unslash( $_POST['from'] ?? '' ) );
		$o['from_name'] = sanitize_text_field( wp_unslash( $_POST['from_name'] ?? '' ) );
		$key            = trim( (string) wp_unslash( $_POST['key'] ?? '' ) ); // phpcs:ignore
		if ( '' !== $key ) {
			$o['key'] = $key;
		}
		update_option( 'bk_mail', $o, false );
		bk_pay_settings_save();
		$msg = 'Settings saved.';
		if ( ! empty( $_POST['test'] ) ) {
			$to  = wp_get_current_user()->user_email;
			$ok  = wp_mail( $to, 'Bookkeeper test email', "This is a test from The Bookkeeper.\n\nIf you can read this, email delivery works." );
			$msg = $ok ? 'Settings saved. A test email was sent to ' . $to . '.' : 'Settings saved, but the test email failed. Check the SMTP login and key.';
		}
	}
	$o = bk_mail_opts();
	?>
	<div class="wrap">
		<h1>Bookkeeper settings</h1>
		<?php if ( $msg ) : ?><div class="notice notice-info"><p><?php echo esc_html( $msg ); ?></p></div><?php endif; ?>
		<form method="post">
			<?php wp_nonce_field( 'bk_settings', 'bk_settings_nonce' ); ?>
			<h2>Email delivery (SMTP)</h2>
			<p>Use a mailbox from cPanel (Email Accounts, then Connect Devices shows the server and port), or any SMTP service such as Brevo. All site mail will then go through it.</p>
			<table class="form-table" role="presentation">
				<tr><th scope="row">Send mail through SMTP</th><td><label><input type="checkbox" name="on" value="1" <?php checked( $o['on'] ); ?>> On</label></td></tr>
				<tr><th scope="row"><label for="bk-host">SMTP server</label></th><td><input id="bk-host" class="regular-text" name="host" value="<?php echo esc_attr( $o['host'] ); ?>"> Port <input name="port" type="number" class="small-text" value="<?php echo esc_attr( $o['port'] ); ?>"></td></tr>
				<tr><th scope="row"><label for="bk-user">SMTP login</label></th><td><input id="bk-user" class="regular-text" name="user" autocomplete="off" value="<?php echo esc_attr( $o['user'] ); ?>"></td></tr>
				<tr><th scope="row"><label for="bk-key">Password or key</label></th><td><input id="bk-key" class="regular-text" name="key" type="password" autocomplete="new-password" placeholder="<?php echo $o['key'] ? 'Saved. Leave blank to keep it.' : 'The mailbox password or SMTP key'; ?>"></td></tr>
				<tr><th scope="row"><label for="bk-from">From address</label></th><td><input id="bk-from" class="regular-text" name="from" type="email" value="<?php echo esc_attr( $o['from'] ); ?>"><p class="description">Use the same mailbox address as the login.</p></td></tr>
				<tr><th scope="row"><label for="bk-fromname">From name</label></th><td><input id="bk-fromname" class="regular-text" name="from_name" value="<?php echo esc_attr( $o['from_name'] ); ?>" placeholder="Vivere"></td></tr>
			</table>
			<?php bk_pay_settings_fields(); ?>
			<p class="submit">
				<button class="button button-primary" type="submit">Save</button>
				<button class="button" type="submit" name="test" value="1">Save and send me a test email</button>
			</p>
		</form>
	</div>
	<?php
}

/** Give or remove access by hand (gifts, testers) until payments are live. */
function bk_readers_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$books = get_posts( array( 'post_type' => 'bk_book', 'numberposts' => -1 ) );
	$msg   = '';
	if ( isset( $_POST['bk_grant_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['bk_grant_nonce'] ), 'bk_grant' ) ) {
		$user = get_user_by( 'email', sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) );
		$book = (int) ( $_POST['book'] ?? 0 );
		if ( ! $user || 'bk_book' !== get_post_type( $book ) ) {
			$msg = 'No account with that email, or no book picked.';
		} elseif ( ! empty( $_POST['revoke'] ) ) {
			bk_revoke( $user->ID, $book );
			$msg = 'Access removed for ' . $user->user_email . '.';
		} else {
			bk_grant( $user->ID, $book, 'manual' );
			$msg = 'Access given to ' . $user->user_email . '.';
		}
	}
	?>
	<div class="wrap">
		<h1>Readers</h1>
		<?php if ( $msg ) : ?><div class="notice notice-info"><p><?php echo esc_html( $msg ); ?></p></div><?php endif; ?>
		<p>Readers create their own account on the reading page and unlock the book with Paystack. Use this to unlock a book for someone without payment, such as a gift or a tester.</p>
		<form method="post">
			<?php wp_nonce_field( 'bk_grant', 'bk_grant_nonce' ); ?>
			<p><input type="email" name="email" class="regular-text" placeholder="reader@example.com" required>
			<select name="book"><?php foreach ( $books as $b ) : ?><option value="<?php echo (int) $b->ID; ?>"><?php echo esc_html( get_the_title( $b ) ); ?></option><?php endforeach; ?></select>
			<button class="button button-primary" type="submit">Give access</button>
			<button class="button" type="submit" name="revoke" value="1">Remove access</button></p>
		</form>
		<?php bk_orders_table(); ?>
	</div>
	<?php
}

/* ---------- chapter settings box ---------- */

add_action(
	'add_meta_boxes_bk_chapter',
	function () {
		add_meta_box( 'bk-chapter', 'Chapter settings', 'bk_chapter_box', 'bk_chapter', 'side' );
	}
);

function bk_chapter_box( $post ) {
	$books = get_posts( array( 'post_type' => 'bk_book', 'numberposts' => -1 ) );
	$cur   = (int) get_post_meta( $post->ID, '_bk_book', true );
	wp_nonce_field( 'bk_chapter', 'bk_chapter_nonce' );
	echo '<p><label>Book<br><select name="bk_book" style="width:100%">';
	foreach ( $books as $b ) {
		echo '<option value="' . (int) $b->ID . '" ' . selected( $cur, $b->ID, false ) . '>' . esc_html( get_the_title( $b ) ) . '</option>';
	}
	echo '</select></label></p>';
	echo '<p><label><input type="checkbox" name="bk_free" value="1" ' . checked( (bool) get_post_meta( $post->ID, '_bk_free', true ), true, false ) . '> Free preview chapter</label></p>';
	echo '<p class="description">Each H2 heading starts a new section. Give a Group or Paragraph the class <code>bk-reflect</code>, <code>bk-action</code> or <code>bk-quote</code> to make it interactive. Set the chapter order under Order.</p>';
}

add_action(
	'save_post_bk_chapter',
	function ( $id ) {
		if ( ! isset( $_POST['bk_chapter_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['bk_chapter_nonce'] ), 'bk_chapter' ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		update_post_meta( $id, '_bk_book', (int) ( $_POST['bk_book'] ?? 0 ) );
		update_post_meta( $id, '_bk_free', empty( $_POST['bk_free'] ) ? 0 : 1 );
	}
);
