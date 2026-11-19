<?php
defined( 'ABSPATH' ) || exit;

/*
 * Reader emails: welcome, reading reminder and payment receipt.
 * One plain, table-based layout that holds up in Gmail, Outlook and Apple Mail.
 */

function bk_sender_name() {
	$o = function_exists( 'bk_mail_opts' ) ? bk_mail_opts() : array();
	return ! empty( $o['from_name'] ) ? $o['from_name'] : get_bloginfo( 'name' );
}

/**
 * Build the HTML for one email.
 *
 * @param array $a {
 *     @type string $preheader Hidden preview line.
 *     @type string $heading   Large title.
 *     @type array  $paras     Plain-text paragraphs (escaped here).
 *     @type array  $rows      Optional label => value pairs shown as a details table.
 *     @type string $kicker    Optional small line above a highlighted title.
 *     @type string $feature   Optional highlighted title (e.g. the next section).
 *     @type array  $cta       Optional array( label, url ).
 *     @type array  $after     Optional paragraphs after the button.
 *     @type array  $tour      Optional list of array( title, text ) shown as numbered steps after the button.
 *     @type string $tour_head Optional heading above the tour.
 *     @type string $foot      Footer HTML (already escaped by the caller).
 * }
 */
function bk_email_html( $a ) {
	$b      = bk_brand();
	$accent = esc_attr( $b['accent'] );
	$ink    = '#1D1418';
	$soft   = '#6E6368';
	$line   = '#ECE5E0';
	$font   = "-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif";
	$p      = 'margin:0 0 16px;font-size:16px;line-height:1.6;color:#3A2F34';

	$h  = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>' . esc_html( $a['heading'] ) . '</title></head>';
	$h .= '<body style="margin:0;padding:0;background:#F6F2EE;font-family:' . $font . ';color:' . $ink . '">';
	if ( ! empty( $a['preheader'] ) ) {
		$h .= '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . esc_html( $a['preheader'] ) . '</div>';
	}
	$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F2EE"><tr><td align="center" style="padding:40px 16px">';
	$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px">';
	$h .= '<tr><td style="padding:0 4px 20px;font-size:17px;font-weight:700;letter-spacing:-0.01em;color:' . $ink . '">' . esc_html( bk_sender_name() ) . '</td></tr>';
	$h .= '<tr><td style="background:#FFFFFF;border:1px solid ' . $line . ';border-radius:14px;padding:40px 36px 36px">';
	$h .= '<h1 style="margin:0 0 20px;font-size:26px;line-height:1.2;font-weight:700;letter-spacing:-0.02em;color:' . $ink . '">' . esc_html( $a['heading'] ) . '</h1>';
	foreach ( (array) ( $a['paras'] ?? array() ) as $t ) {
		$h .= '<p style="' . $p . '">' . esc_html( $t ) . '</p>';
	}
	if ( ! empty( $a['feature'] ) ) {
		$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 24px;border-top:1px solid ' . $line . ';border-bottom:1px solid ' . $line . '"><tr><td style="padding:18px 0">';
		if ( ! empty( $a['kicker'] ) ) {
			$h .= '<p style="margin:0 0 4px;font-size:13px;color:' . $soft . '">' . esc_html( $a['kicker'] ) . '</p>';
		}
		$h .= '<p style="margin:0;font-size:20px;line-height:1.3;font-weight:700;letter-spacing:-0.01em;color:' . $ink . '">' . esc_html( $a['feature'] ) . '</p></td></tr></table>';
	}
	if ( ! empty( $a['rows'] ) ) {
		$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 26px;border-top:1px solid ' . $line . '">';
		foreach ( $a['rows'] as $k => $v ) {
			$h .= '<tr><td style="padding:12px 0;border-bottom:1px solid ' . $line . ';font-size:14px;color:' . $soft . '">' . esc_html( $k ) . '</td><td align="right" style="padding:12px 0;border-bottom:1px solid ' . $line . ';font-size:14px;font-weight:600;color:' . $ink . '">' . esc_html( $v ) . '</td></tr>';
		}
		$h .= '</table>';
	}
	if ( ! empty( $a['cta'] ) ) {
		$h .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 4px"><tr><td style="border-radius:10px;background:' . $accent . '">';
		$h .= '<a href="' . esc_url( $a['cta'][1] ) . '" style="display:inline-block;padding:15px 26px;border-radius:10px;font-size:15px;font-weight:600;color:#FFFFFF;text-decoration:none">' . esc_html( $a['cta'][0] ) . '</a></td></tr></table>';
	}
	if ( ! empty( $a['tour'] ) ) {
		$h .= '<h2 style="margin:36px 0 6px;font-size:18px;line-height:1.3;font-weight:700;letter-spacing:-0.01em;color:' . $ink . '">' . esc_html( $a['tour_head'] ?? '' ) . '</h2>';
		$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0">';
		foreach ( $a['tour'] as $n => $step ) {
			$h .= '<tr><td valign="top" width="34" style="padding:16px 0;border-bottom:1px solid ' . $line . ';font-size:14px;font-weight:700;color:' . $accent . '">' . sprintf( '%02d', $n + 1 ) . '</td>';
			$h .= '<td valign="top" style="padding:16px 0;border-bottom:1px solid ' . $line . '"><p style="margin:0 0 4px;font-size:15px;font-weight:700;color:' . $ink . '">' . esc_html( $step[0] ) . '</p><p style="margin:0;font-size:14px;line-height:1.6;color:#4A3F44">' . esc_html( $step[1] ) . '</p></td></tr>';
		}
		$h .= '</table>';
	}
	foreach ( (array) ( $a['after'] ?? array() ) as $t ) {
		$h .= '<p style="margin:24px 0 0;font-size:14px;line-height:1.6;color:' . $soft . '">' . esc_html( $t ) . '</p>';
	}
	$h .= '</td></tr>';
	if ( ! empty( $a['foot'] ) ) {
		$h .= '<tr><td style="padding:22px 4px 0;font-size:12px;line-height:1.6;color:#8A7F84">' . $a['foot'] . '</td></tr>';
	}
	$h .= '</table></td></tr></table></body></html>';
	return $h;
}

/** Plain-text twin of an email, for clients that do not show HTML. */
function bk_email_text( $a ) {
	$t = $a['heading'] . "\n\n";
	foreach ( (array) ( $a['paras'] ?? array() ) as $p ) {
		$t .= $p . "\n\n";
	}
	if ( ! empty( $a['feature'] ) ) {
		$t .= ( ! empty( $a['kicker'] ) ? $a['kicker'] . ': ' : '' ) . $a['feature'] . "\n\n";
	}
	foreach ( (array) ( $a['rows'] ?? array() ) as $k => $v ) {
		$t .= $k . ': ' . $v . "\n";
	}
	if ( ! empty( $a['rows'] ) ) {
		$t .= "\n";
	}
	if ( ! empty( $a['cta'] ) ) {
		$t .= $a['cta'][0] . ': ' . $a['cta'][1] . "\n\n";
	}
	if ( ! empty( $a['tour'] ) ) {
		$t .= ( $a['tour_head'] ?? '' ) . "\n\n";
		foreach ( $a['tour'] as $n => $step ) {
			$t .= ( $n + 1 ) . '. ' . $step[0] . "\n" . $step[1] . "\n\n";
		}
	}
	foreach ( (array) ( $a['after'] ?? array() ) as $p ) {
		$t .= $p . "\n\n";
	}
	if ( ! empty( $a['foot_text'] ) ) {
		$t .= $a['foot_text'] . "\n";
	}
	return trim( $t );
}

function bk_send_email( $to, $subject, $a ) {
	$text = bk_email_text( $a );
	$alt  = function ( $m ) use ( $text ) {
		$m->AltBody = $text;
	};
	add_action( 'phpmailer_init', $alt );
	$ok = wp_mail( $to, $subject, bk_email_html( $a ), array( 'Content-Type: text/html; charset=UTF-8' ) );
	remove_action( 'phpmailer_init', $alt );
	return $ok;
}

function bk_first_name( $user ) {
	return $user->first_name ?: $user->display_name;
}

/* ---------- welcome ---------- */

function bk_welcome_args( $user, $book_id ) {
	$title  = get_the_title( $book_id );
	$prices = function_exists( 'bk_prices' ) ? bk_prices( $book_id ) : array();
	$price  = isset( $prices['NGN'] ) ? $prices['NGN']['label'] : '';
	return array(
		'preheader' => 'Chapter one is open. Here is how to get the most from it.',
		'heading'   => 'Welcome to ' . $title,
		'paras'     => array(
			'Hi ' . bk_first_name( $user ) . ', your account is ready and chapter one is yours to read now.',
			'This is more than a book. Each chapter is a short read with a workbook built in, so the ideas turn into decisions. Everything you write is private and saved to your account, on any device.',
		),
		'cta'       => array( 'Start chapter one', bk_reader_url( $book_id ) ),
		'tour_head' => 'A quick tour of the reader',
		'tour'      => array(
			array( 'Read one section at a time', 'Every chapter is split into short sections of a few minutes each. Use Next at the bottom to move on, or open the menu at the top left to jump to any chapter. The reader remembers where you stopped.' ),
			array( 'Highlight and pin notes', 'Select any line to highlight it in one of five colours. Tap the note button on the toolbar to pin a sticky note to that line, like writing in the margin.' ),
			array( 'Answer the reflections', 'Reflection questions sit inside the chapters. Type your answer right there. It saves as you write and goes into your journal.' ),
			array( 'Commit to action points', 'Where a chapter asks what you will do next, write the step and pick a date. Tick it off when it is done.' ),
			array( 'Find everything in My notes', 'The My notes button at the top right gathers your highlights, journal, action points and reading progress in one place. You can also write free journal entries there.' ),
			array( 'Make it comfortable', 'Tap Aa to switch the reading mood (paper, white, warm, dark or night), change the text size or choose a serif typeface.' ),
		),
		'after'     => array( $price ? 'When you are ready for the rest, you can unlock the whole book for ' . $price . ', once.' : 'When you are ready for the rest, you can unlock the whole book from inside the reader.' ),
		'foot'      => 'You are getting this because you created an account at ' . esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '.',
		'foot_text' => 'You are getting this because you created an account at ' . wp_parse_url( home_url(), PHP_URL_HOST ) . '.',
	);
}

function bk_welcome_email( $user_id, $book_id ) {
	$user = get_userdata( $user_id );
	if ( ! $user || ! $book_id ) {
		return false;
	}
	return bk_send_email( $user->user_email, 'Welcome to ' . get_the_title( $book_id ), bk_welcome_args( $user, $book_id ) );
}

/* Send the welcome email for the book the reader signed up on. */
add_action(
	'bk_registered',
	function ( $uid ) {
		$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : ''; // phpcs:ignore
		$book = null;
		if ( preg_match( '#/read/([^/?\#]+)#', $back, $m ) ) {
			$book = bk_book_by_slug( $m[1] );
		}
		if ( ! $book ) {
			$all  = get_posts( array( 'post_type' => 'bk_book', 'numberposts' => 1 ) );
			$book = $all ? $all[0] : null;
		}
		if ( $book ) {
			bk_welcome_email( $uid, $book->ID );
		}
	}
);

/* ---------- reminder ---------- */

function bk_reminder_args( $user, $book_id, $last, $done, $total ) {
	$title = get_the_title( $book_id );
	$secs  = bk_chapter_sections( (int) $last['chapter'] );
	$here  = isset( $secs[ (int) $last['section'] ] ) ? $secs[ (int) $last['section'] ]['title'] : '';
	$url   = bk_reader_url( $book_id ) . '#/c/' . (int) $last['chapter'] . '/' . (int) $last['section'];
	$pct   = $total ? (int) round( 100 * $done / $total ) : 0;
	$unsub = bk_unsub_url( $user->ID );
	return array(
		'preheader' => $here ? 'Next up: ' . $here : 'Your next section is waiting.',
		'heading'   => 'Pick up where you left off',
		'paras'     => array(
			'Hi ' . bk_first_name( $user ) . ',',
			$done
				? sprintf( 'You are %d%% of the way through %s. Your next section takes a few minutes, and your notes and highlights are right where you left them.', $pct, $title )
				: sprintf( 'You started %s yesterday. Your next section takes a few minutes, and everything you saved is waiting for you.', $title ),
		),
		'kicker'    => 'Next up',
		'feature'   => $here,
		'cta'       => array( 'Continue reading', $url ),
		'foot'      => 'You get one reminder a day after you stop reading, until you finish the book. <a href="' . esc_url( $unsub ) . '" style="color:#8A7F84">Turn off reminders</a>.',
		'foot_text' => 'Turn off reminders: ' . $unsub,
	);
}

/* ---------- receipt ---------- */

function bk_receipt_args( $user, $order ) {
	$book  = (int) $order->book_id;
	$title = get_the_title( $book );
	return array(
		'preheader' => 'Every chapter of ' . $title . ' is now open to you.',
		'heading'   => 'Your copy is ready',
		'paras'     => array(
			'Hi ' . bk_first_name( $user ) . ', thank you for buying ' . $title . '. Every chapter is now open to you, along with your journal, highlights and notes.',
		),
		'rows'      => array(
			'Book'      => $title,
			'Amount'    => bk_money( $order->amount / 100, $order->currency ),
			'Date'      => get_date_from_gmt( $order->updated ?: $order->created, 'j F Y' ),
			'Reference' => $order->reference,
		),
		'cta'       => array( 'Start reading', bk_reader_url( $book ) ),
		'after'     => array( 'Keep this email as your receipt. If anything looks wrong, reply and we will sort it out.' ),
		'foot'      => 'Payment processed securely by Paystack.',
		'foot_text' => 'Payment processed securely by Paystack.',
	);
}

/* ---------- samples for review ---------- */

/** Send one of each email, filled with sample data, to an address (the site admin by default). */
function bk_email_samples( $to = '' ) {
	$to   = $to ?: get_option( 'admin_email' );
	$book = get_posts( array( 'post_type' => 'bk_book', 'numberposts' => 1 ) );
	if ( ! $book ) {
		return array();
	}
	$book_id = $book[0]->ID;
	$user    = (object) array( 'ID' => 0, 'first_name' => 'Ada', 'display_name' => 'Ada', 'user_email' => $to );
	$chs     = bk_book_chapters( $book_id );
	$last    = array( 'chapter' => $chs ? $chs[0]->ID : 0, 'section' => 1, 'at' => time() - DAY_IN_SECONDS );
	$order   = (object) array( 'book_id' => $book_id, 'amount' => 2599900, 'currency' => 'NGN', 'reference' => 'bk-sample-0001', 'created' => current_time( 'mysql', true ), 'updated' => current_time( 'mysql', true ) );
	$title   = get_the_title( $book_id );
	return array(
		'welcome'  => bk_send_email( $to, '[Sample] Welcome to ' . $title, bk_welcome_args( $user, $book_id ) ),
		'reminder' => bk_send_email( $to, '[Sample] Pick up where you left off', bk_reminder_args( $user, $book_id, $last, 2, 9 ) ),
		'receipt'  => bk_send_email( $to, '[Sample] Your copy of ' . $title . ' is ready', bk_receipt_args( $user, $order ) ),
	);
}
