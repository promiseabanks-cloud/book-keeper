<?php
defined( 'ABSPATH' ) || exit;

/* The reader lives at /read/<book-slug>/ and is its own full-screen page. */

function bk_add_rewrites() {
	add_rewrite_rule( '^read/([^/]+)/?$', 'index.php?bk_read=$matches[1]', 'top' );
}
add_action( 'init', 'bk_add_rewrites' );

add_filter(
	'query_vars',
	function ( $v ) {
		$v[] = 'bk_read';
		return $v;
	}
);

function bk_book_by_slug( $slug ) {
	$q = get_posts(
		array(
			'post_type'   => 'bk_book',
			'name'        => sanitize_title( $slug ),
			'post_status' => 'publish',
			'numberposts' => 1,
		)
	);
	return $q ? $q[0] : null;
}

function bk_reader_url( $book ) {
	return home_url( '/read/' . get_post_field( 'post_name', $book ) . '/' );
}

add_action(
	'template_redirect',
	function () {
		$slug = get_query_var( 'bk_read' );
		if ( ! $slug ) {
			return;
		}
		$book = bk_book_by_slug( $slug );
		if ( ! $book ) {
			status_header( 404 );
			nocache_headers();
			bk_page( 'Not found', '<div class="bk-auth"><div class="bk-auth-card"><h1>Book not found</h1><p><a href="' . esc_url( home_url( '/' ) ) . '">Back to the site</a></p></div></div>' );
			exit;
		}
		nocache_headers();
		if ( ! is_user_logged_in() ) {
			bk_page( get_the_title( $book ), bk_auth_html( $book ) );
			exit;
		}
		bk_page( get_the_title( $book ), '<div id="bk-app" class="bk-app" aria-live="polite"><div class="bk-boot"><span></span></div></div>', bk_reader_config( $book ) );
		exit;
	}
);

function bk_reader_config( $book ) {
	$b = bk_brand();
	$u = wp_get_current_user();
	return array(
		'rest'   => esc_url_raw( rest_url( 'bookkeeper/v1/' ) ),
		'nonce'  => wp_create_nonce( 'wp_rest' ),
		'book'   => $book->ID,
		'title'  => get_the_title( $book ),
		'author' => (string) get_post_meta( $book->ID, '_bk_author', true ),
		'home'   => $b['home'],
		'buy'    => $b['buy_url'],
		'logo'   => $b['logo'],
		'logout' => wp_logout_url( bk_reader_url( $book ) ),
		'mark'   => $u->user_email,
		'pay'    => bk_pay_config( $book->ID ),
		'thanks' => isset( $_GET['bk_thanks'] ) ? (int) $_GET['bk_thanks'] : -1, // phpcs:ignore
	);
}

/** One self-contained document: only the reader's own CSS and JS, no theme or plugin clutter. */
function bk_page( $title, $body, $config = null ) {
	$b   = bk_brand();
	$ver = BK_VERSION;
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( $title . ' | ' . $b['name'] ); ?></title>
<style>
<?php if ( $b['font_url'] ) : ?>
@font-face{font-family:"DM Sans";src:url(<?php echo esc_url( $b['font_url'] ); ?>) format("woff2");font-weight:100 1000;font-display:swap}
<?php endif; ?>
:root{--bk-accent:<?php echo esc_html( $b['accent'] ); ?>;--bk-dark:<?php echo esc_html( $b['dark'] ); ?>;--bk-paper:<?php echo esc_html( $b['paper'] ); ?>;--bk-ink:<?php echo esc_html( $b['ink'] ); ?>;--bk-font:<?php echo wp_strip_all_tags( $b['font'] ); // phpcs:ignore ?>}
</style>
<meta name="theme-color" content="<?php echo esc_attr( $b['paper'] ); ?>">
<script>try{var p=JSON.parse(localStorage.getItem('bk-prefs')||'{}')||{},r=document.documentElement;r.setAttribute('data-mood',p.mood||(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'paper'));if(p.font){r.setAttribute('data-font',p.font);}if(p.size){r.style.setProperty('--bk-scale',p.size);}}catch(e){}</script>
<link rel="stylesheet" href="<?php echo esc_url( BK_URL . 'assets/reader.css?ver=' . $ver ); ?>">
</head>
<body class="bk-body">
<?php echo $body; // phpcs:ignore -- built from escaped parts above ?>
<?php if ( $config ) : ?>
<script>window.BK=<?php echo wp_json_encode( $config ); ?>;</script>
<script src="<?php echo esc_url( BK_URL . 'assets/reader.js?ver=' . $ver ); ?>" defer></script>
<?php endif; ?>
</body>
</html>
	<?php
}

function bk_auth_html( $book ) {
	$b      = bk_brand();
	$back   = bk_reader_url( $book );
	$err    = isset( $_GET['bk_err'] ) ? sanitize_key( $_GET['bk_err'] ) : ''; // phpcs:ignore
	$mode   = isset( $_GET['bk_mode'] ) && 'join' === $_GET['bk_mode'] ? 'join' : 'in'; // phpcs:ignore
	$msgs   = array(
		'creds'  => 'That email and password do not match.',
		'exists' => 'An account with that email already exists. Sign in instead.',
		'email'  => 'Please enter a valid email address.',
		'pass'   => 'Use a password of at least 8 characters.',
		'nonce'  => 'The form expired. Please try again.',
	);
	$action = esc_url( admin_url( 'admin-post.php' ) );
	$logo   = $b['logo'] ? '<img src="' . esc_url( $b['logo'] ) . '" alt="" width="44" height="44">' : '';
	$cover  = get_the_post_thumbnail_url( $book, 'large' );
	ob_start();
	?>
<div class="bk-auth">
	<div class="bk-auth-side" <?php echo $cover ? 'style="--cover:url(' . esc_url( $cover ) . ')"' : ''; ?>>
		<a class="bk-auth-brand" href="<?php echo esc_url( $b['home'] ); ?>"><?php echo $logo; // phpcs:ignore ?></a>
		<div class="bk-auth-pitch">
			<h1><?php echo esc_html( get_the_title( $book ) ); ?></h1>
			<p><?php echo esc_html( get_the_excerpt( $book ) ); ?></p>
			<ul class="bk-auth-list">
				<li>Read chapter by chapter, one short section at a time</li>
				<li>Highlight lines and pin sticky notes to them</li>
				<li>Answer reflections and keep your action points</li>
				<li>Pick up where you left off on any device</li>
			</ul>
		</div>
	</div>
	<div class="bk-auth-main">
		<div class="bk-auth-card" data-mode="<?php echo esc_attr( $mode ); ?>">
			<div class="bk-auth-tabs" role="tablist">
				<button type="button" role="tab" data-tab="in" aria-selected="<?php echo 'in' === $mode ? 'true' : 'false'; ?>">Sign in</button>
				<button type="button" role="tab" data-tab="join" aria-selected="<?php echo 'join' === $mode ? 'true' : 'false'; ?>">Create account</button>
			</div>
			<?php if ( $err && isset( $msgs[ $err ] ) ) : ?>
				<p class="bk-auth-err" role="alert"><?php echo esc_html( $msgs[ $err ] ); ?></p>
			<?php endif; ?>
			<form class="bk-auth-form" data-form="in" method="post" action="<?php echo $action; // phpcs:ignore ?>">
				<input type="hidden" name="action" value="bk_login">
				<input type="hidden" name="back" value="<?php echo esc_url( $back ); ?>">
				<?php wp_nonce_field( 'bk_auth', 'bk_nonce' ); ?>
				<label>Email<input type="email" name="email" autocomplete="email" required></label>
				<label>Password<input type="password" name="pass" autocomplete="current-password" required></label>
				<button class="bk-btn bk-btn--solid" type="submit">Sign in</button>
				<a class="bk-auth-forgot" href="<?php echo esc_url( wp_lostpassword_url( $back ) ); ?>">Forgot your password?</a>
			</form>
			<form class="bk-auth-form" data-form="join" method="post" action="<?php echo $action; // phpcs:ignore ?>">
				<input type="hidden" name="action" value="bk_register">
				<input type="hidden" name="back" value="<?php echo esc_url( $back ); ?>">
				<?php wp_nonce_field( 'bk_auth', 'bk_nonce' ); ?>
				<label>First name<input type="text" name="name" autocomplete="given-name" required></label>
				<label>Email<input type="email" name="email" autocomplete="email" required></label>
				<label>Password<input type="password" name="pass" autocomplete="new-password" minlength="8" required></label>
				<button class="bk-btn bk-btn--solid" type="submit">Create account and start reading</button>
				<p class="bk-auth-fine">Chapter one is free. Unlock the rest whenever you are ready.</p>
			</form>
		</div>
	</div>
</div>
<script>
if(location.hash){document.querySelectorAll('.bk-auth-form input[name=back]').forEach(function(i){i.value=i.value.split('#')[0]+location.hash;});}
document.querySelectorAll('.bk-auth-tabs button').forEach(function(b){b.addEventListener('click',function(){var c=document.querySelector('.bk-auth-card');c.dataset.mode=b.dataset.tab;document.querySelectorAll('.bk-auth-tabs button').forEach(function(x){x.setAttribute('aria-selected',x===b?'true':'false')});});});
</script>
	<?php
	return ob_get_clean();
}

function bk_auth_back( $err = '', $mode = 'in' ) {
	$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : home_url( '/' ); // phpcs:ignore
	$back = wp_validate_redirect( $back, home_url( '/' ) );
	if ( $err ) {
		$back = add_query_arg( array( 'bk_err' => $err, 'bk_mode' => $mode ), $back );
	}
	wp_safe_redirect( $back );
	exit;
}

function bk_handle_login() {
	if ( ! isset( $_POST['bk_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['bk_nonce'] ), 'bk_auth' ) ) {
		bk_auth_back( 'nonce' );
	}
	$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
	$user  = get_user_by( 'email', $email );
	$res   = $user ? wp_signon( array( 'user_login' => $user->user_login, 'user_password' => (string) wp_unslash( $_POST['pass'] ?? '' ), 'remember' => true ), is_ssl() ) : null; // phpcs:ignore
	if ( ! $res || is_wp_error( $res ) ) {
		bk_auth_back( 'creds' );
	}
	bk_auth_back();
}
add_action( 'admin_post_nopriv_bk_login', 'bk_handle_login' );
add_action( 'admin_post_bk_login', 'bk_auth_back' );

function bk_handle_register() {
	if ( ! isset( $_POST['bk_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['bk_nonce'] ), 'bk_auth' ) ) {
		bk_auth_back( 'nonce', 'join' );
	}
	$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
	$pass  = (string) wp_unslash( $_POST['pass'] ?? '' ); // phpcs:ignore
	$name  = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
	if ( ! is_email( $email ) ) {
		bk_auth_back( 'email', 'join' );
	}
	if ( strlen( $pass ) < 8 ) {
		bk_auth_back( 'pass', 'join' );
	}
	if ( email_exists( $email ) ) {
		bk_auth_back( 'exists', 'in' );
	}
	$login = sanitize_user( strtolower( strstr( $email, '@', true ) ), true );
	$base  = $login ?: 'reader';
	$n     = 1;
	while ( username_exists( $login ) ) {
		$login = $base . ( ++$n );
	}
	$uid = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_email'   => $email,
			'user_pass'    => $pass,
			'first_name'   => $name,
			'display_name' => $name ?: $login,
			'role'         => 'subscriber',
		)
	);
	if ( is_wp_error( $uid ) ) {
		bk_auth_back( 'email', 'join' );
	}
	update_user_meta( $uid, 'bk_reader', 1 );
	wp_set_current_user( $uid );
	wp_set_auth_cookie( $uid, true, is_ssl() );
	do_action( 'bk_registered', $uid );
	bk_auth_back();
}
add_action( 'admin_post_nopriv_bk_register', 'bk_handle_register' );
add_action( 'admin_post_bk_register', 'bk_auth_back' );

/* Readers never see the dashboard or the admin bar. */
add_action(
	'admin_init',
	function () {
		if ( wp_doing_ajax() || ! is_user_logged_in() ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), 'bk_reader', true ) && ! current_user_can( 'edit_posts' ) && 'admin-post.php' !== $GLOBALS['pagenow'] ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
	}
);
add_filter(
	'show_admin_bar',
	function ( $show ) {
		return get_user_meta( get_current_user_id(), 'bk_reader', true ) && ! current_user_can( 'edit_posts' ) ? false : $show;
	}
);
