<?php
defined( 'ABSPATH' ) || exit;

/*
 * The reader's account page at /account/: their books with progress, what
 * they have written lately, and links to settings. Signed-out visitors get
 * the sign-in screen and come back here afterwards.
 */

function bk_account_url() {
	return home_url( '/account/' );
}

add_action(
	'init',
	function () {
		add_rewrite_rule( '^account/?$', 'index.php?bk_account=1', 'top' );
	}
);
add_filter(
	'query_vars',
	function ( $v ) {
		$v[] = 'bk_account';
		return $v;
	}
);

add_action(
	'template_redirect',
	function () {
		if ( ! get_query_var( 'bk_account' ) ) {
			return;
		}
		nocache_headers();
		$books = get_posts( array( 'post_type' => 'bk_book', 'post_status' => 'publish', 'numberposts' => 20, 'orderby' => 'menu_order date', 'order' => 'ASC' ) );
		if ( ! is_user_logged_in() ) {
			if ( ! $books ) {
				wp_safe_redirect( wp_login_url( bk_account_url() ) );
				exit;
			}
			bk_page( 'Your account', bk_auth_html( $books[0], bk_account_url() ) );
			exit;
		}
		bk_page( 'Your account', bk_account_html( wp_get_current_user(), $books ) );
		exit;
	}
);

/** Progress numbers for one reader in one book. */
function bk_book_progress( $uid, $book_id ) {
	global $wpdb;
	$total = 0;
	foreach ( bk_book_chapters( $book_id ) as $ch ) {
		$total += count( bk_chapter_sections( $ch ) );
	}
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(done) d, SUM(seconds) s, MAX(updated) u FROM {$wpdb->prefix}bk_progress WHERE user_id=%d AND book_id=%d", $uid, $book_id ) );
	$done = (int) ( $row->d ?? 0 );
	return array(
		'total'   => $total,
		'done'    => $done,
		'pct'     => $total ? (int) round( 100 * min( $done, $total ) / $total ) : 0,
		'minutes' => (int) round( ( $row->s ?? 0 ) / 60 ),
		'last'    => $row->u ?? '',
	);
}

/** The latest things a reader wrote, across every book. */
function bk_recent_activity( $uid, $limit = 8 ) {
	global $wpdb;
	$e = $wpdb->get_results( $wpdb->prepare( "SELECT book_id, chapter_id, section, kind, content AS txt, updated FROM {$wpdb->prefix}bk_entries WHERE user_id=%d AND content<>'' ORDER BY updated DESC LIMIT %d", $uid, $limit ) );
	$m = $wpdb->get_results( $wpdb->prepare( "SELECT book_id, chapter_id, section, 'mark' AS kind, quote AS txt, updated FROM {$wpdb->prefix}bk_marks WHERE user_id=%d ORDER BY updated DESC LIMIT %d", $uid, $limit ) );
	$all = array_merge( $e, $m );
	usort(
		$all,
		function ( $a, $b ) {
			return strcmp( $b->updated, $a->updated );
		}
	);
	return array_slice( $all, 0, $limit );
}

function bk_ring_svg( $pct, $size ) {
	return '<div class="bk-ring" style="--s:' . (int) $size . 'px" role="img" aria-label="' . (int) $pct . '% read">'
		. '<svg class="bk-ring-svg" viewBox="0 0 100 100" aria-hidden="true"><circle class="bk-ring-track" cx="50" cy="50" r="44"/>'
		. '<circle class="bk-ring-val bk-ring-static" cx="50" cy="50" r="44" pathLength="100" stroke-dasharray="100" stroke-dashoffset="' . ( 100 - (int) $pct ) . '"/></svg>'
		. '<div class="bk-ring-in"><strong>' . (int) $pct . '%</strong></div></div>';
}

function bk_account_html( $user, $books ) {
	$b     = bk_brand();
	$uid   = $user->ID;
	$logo  = $b['logo'] ? '<img src="' . esc_url( $b['logo'] ) . '" alt="" width="32" height="32">' : '';
	$kinds = array(
		'mark'    => 'Highlight',
		'reflect' => 'Reflection',
		'action'  => 'Action point',
		'summary' => 'Chapter summary',
		'journal' => 'Journal',
	);
	$first = $books ? $books[0] : null;

	// Totals across all books.
	global $wpdb;
	$counts = $wpdb->get_results( $wpdb->prepare( "SELECT kind, COUNT(*) n FROM {$wpdb->prefix}bk_entries WHERE user_id=%d AND content<>'' GROUP BY kind", $uid ), OBJECT_K );
	$marks  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bk_marks WHERE user_id=%d", $uid ) );
	$n      = function ( $k ) use ( $counts ) {
		return isset( $counts[ $k ] ) ? (int) $counts[ $k ]->n : 0;
	};

	ob_start();
	?>
<div class="bk-lib">
	<header class="bk-lib-bar">
		<a class="bk-lib-brand" href="<?php echo esc_url( $b['home'] ); ?>"><?php echo $logo; // phpcs:ignore ?><span><?php echo esc_html( $b['name'] ); ?></span></a>
		<div class="bk-lib-bar-r">
			<a class="bk-btn bk-btn--sm" href="<?php echo esc_url( $b['home'] ); ?>"><span class="bk-lib-long">Back to the </span>website</a>
			<a class="bk-btn bk-btn--sm" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">Sign out</a>
		</div>
	</header>
	<main class="bk-lib-main">
		<div class="bk-lib-head">
			<h1>Hi, <?php echo esc_html( bk_first_name( $user ) ); ?></h1>
			<p>Your books, your progress and everything you have written, in one place.</p>
		</div>

		<h2 class="bk-lib-h">Your books</h2>
		<div class="bk-lib-books">
		<?php
		foreach ( $books as $book ) :
			$owns  = bk_user_owns( $uid, $book->ID );
			$p     = bk_book_progress( $uid, $book->ID );
			$cover = get_the_post_thumbnail_url( $book, 'medium_large' );
			$by    = (string) get_post_meta( $book->ID, '_bk_author', true );
			$url   = bk_reader_url( $book );
			?>
			<article class="bk-lib-book">
				<div class="bk-lib-cover"<?php echo $cover ? ' style="background-image:url(' . esc_url( $cover ) . ')"' : ''; ?>><?php echo $cover ? '' : '<span>' . esc_html( get_the_title( $book ) ) . '</span>'; ?></div>
				<div class="bk-lib-info">
					<p class="bk-lib-status"><?php echo $owns ? 'In your library' : 'Free preview'; ?></p>
					<h3><?php echo esc_html( get_the_title( $book ) ); ?></h3>
					<?php if ( $by ) : ?><p class="bk-lib-by">by <?php echo esc_html( $by ); ?></p><?php endif; ?>
					<p class="bk-lib-meta"><?php echo (int) $p['done']; ?> of <?php echo (int) $p['total']; ?> sections<?php echo $p['minutes'] ? ' · ' . (int) $p['minutes'] . ' min read' : ''; ?><?php echo $p['last'] ? ' · last read ' . esc_html( human_time_diff( strtotime( $p['last'] . ' UTC' ) ) ) . ' ago' : ''; ?></p>
					<div class="bk-lib-actions">
						<a class="bk-btn bk-btn--solid" href="<?php echo esc_url( $url ); ?>"><?php echo $p['done'] ? 'Continue reading' : 'Start reading'; ?></a>
						<?php if ( ! $owns && function_exists( 'bk_on_sale' ) && bk_on_sale() ) : ?>
							<a class="bk-btn" href="<?php echo esc_url( $url . '#/buy' ); ?>">Unlock the full book</a>
						<?php else : ?>
							<a class="bk-btn" href="<?php echo esc_url( $url . '#/notes' ); ?>">My notes</a>
						<?php endif; ?>
					</div>
				</div>
				<?php echo bk_ring_svg( $p['pct'], 96 ); // phpcs:ignore ?>
			</article>
		<?php endforeach; ?>
		</div>

		<h2 class="bk-lib-h">Your activity</h2>
		<div class="bk-tiles bk-lib-tiles">
			<div class="bk-tile"><strong><?php echo (int) $marks; ?></strong><span class="bk-tile-l">highlights</span></div>
			<div class="bk-tile"><strong><?php echo (int) $n( 'reflect' ); ?></strong><span class="bk-tile-l">reflections</span></div>
			<div class="bk-tile"><strong><?php echo (int) $n( 'action' ); ?></strong><span class="bk-tile-l">action points</span></div>
			<div class="bk-tile"><strong><?php echo (int) ( $n( 'journal' ) + $n( 'summary' ) ); ?></strong><span class="bk-tile-l">journal entries</span></div>
		</div>

		<div class="bk-lib-cols">
			<section class="bk-panel">
				<h2 class="bk-panel-t">Recently</h2>
				<?php
				$rows = bk_recent_activity( $uid );
				if ( ! $rows ) :
					?>
					<p class="bk-panel-empty">Nothing yet. Your highlights, reflections and action points will show up here as you read.</p>
				<?php else : ?>
					<ul class="bk-lib-feed">
					<?php
					foreach ( $rows as $r ) :
						$book = get_post( $r->book_id );
						if ( ! $book ) {
							continue;
						}
						$link = bk_reader_url( $book ) . ( $r->chapter_id ? '#/c/' . (int) $r->chapter_id . '/' . (int) $r->section : '' );
						$txt  = wp_html_excerpt( wp_strip_all_tags( $r->txt ), 140, '…' );
						?>
						<li><a href="<?php echo esc_url( $link ); ?>">
							<span class="bk-lib-kind"><?php echo esc_html( $kinds[ $r->kind ] ?? 'Note' ); ?> · <?php echo esc_html( human_time_diff( strtotime( $r->updated . ' UTC' ) ) ); ?> ago</span>
							<span class="bk-lib-txt"><?php echo esc_html( 'mark' === $r->kind ? '"' . $txt . '"' : $txt ); ?></span>
						</a></li>
					<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>
			<section class="bk-panel">
				<h2 class="bk-panel-t">Account</h2>
				<dl class="bk-lib-dl">
					<div><dt>Name</dt><dd><?php echo esc_html( $user->display_name ); ?></dd></div>
					<div><dt>Email</dt><dd><?php echo esc_html( $user->user_email ); ?></dd></div>
					<div><dt>Member since</dt><dd><?php echo esc_html( wp_date( 'j F Y', strtotime( $user->user_registered . ' UTC' ) ) ); ?></dd></div>
				</dl>
				<?php if ( $first ) : ?>
					<a class="bk-btn bk-lib-edit" href="<?php echo esc_url( bk_reader_url( $first ) . '#/account' ); ?>">Change your name or password</a>
				<?php endif; ?>
			</section>
		</div>
	</main>
</div>
	<?php
	return ob_get_clean();
}
