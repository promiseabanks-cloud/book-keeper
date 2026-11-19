<?php
defined( 'ABSPATH' ) || exit;

/*
 * A reader's notes, gathered per chapter: summary, highlights, reflections,
 * action points and journal entries. Used for the "email me my notes" recap,
 * the download, Send to Notes and the Google Docs sync.
 */

/** Everything one reader wrote in a book, grouped by chapter in reading order. Chapter 0 holds loose journal entries. */
function bk_notes_collect( $user_id, $book_id, $only_chapter = 0 ) {
	global $wpdb;
	$entries = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bk_entries WHERE user_id=%d AND book_id=%d AND content<>'' ORDER BY section ASC, created ASC", $user_id, $book_id ) );
	$marks   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bk_marks WHERE user_id=%d AND book_id=%d ORDER BY section ASC, start_at ASC", $user_id, $book_id ) );
	$out     = array();
	$num     = 0;
	foreach ( bk_book_chapters( $book_id ) as $ch ) {
		$num++;
		$out[ $ch->ID ] = array( 'num' => $num, 'title' => get_the_title( $ch ), 'summary' => '', 'marks' => array(), 'reflect' => array(), 'action' => array(), 'journal' => array() );
	}
	$out[0] = array( 'num' => 0, 'title' => 'Journal', 'summary' => '', 'marks' => array(), 'reflect' => array(), 'action' => array(), 'journal' => array() );
	foreach ( $marks as $m ) {
		if ( isset( $out[ $m->chapter_id ] ) ) {
			$out[ $m->chapter_id ]['marks'][] = array( 'quote' => $m->quote, 'note' => (string) $m->note );
		}
	}
	foreach ( $entries as $e ) {
		$c = isset( $out[ $e->chapter_id ] ) ? (int) $e->chapter_id : 0;
		if ( 'summary' === $e->kind ) {
			$out[ $c ]['summary'] = $e->content;
		} elseif ( 'reflect' === $e->kind ) {
			$out[ $c ]['reflect'][] = array( 'q' => (string) $e->prompt, 'a' => $e->content );
		} elseif ( 'action' === $e->kind ) {
			$out[ $c ]['action'][] = array( 'text' => $e->content, 'done' => 'done' === $e->status, 'due' => (string) $e->due );
		} else {
			$out[ $c ]['journal'][] = array( 'text' => $e->content, 'label' => 'Saved quote' === $e->prompt ? 'Saved quote' : '' );
		}
	}
	// Loose journal entries go last.
	$loose = $out[0];
	unset( $out[0] );
	$out[0] = $loose;
	if ( $only_chapter ) {
		$out = isset( $out[ $only_chapter ] ) ? array( $only_chapter => $out[ $only_chapter ] ) : array();
	}
	return array_filter(
		$out,
		function ( $c ) {
			return $c['summary'] || $c['marks'] || $c['reflect'] || $c['action'] || $c['journal'];
		}
	);
}

function bk_notes_chapter_label( $c ) {
	return $c['num'] ? 'Chapter ' . $c['num'] . ': ' . $c['title'] : $c['title'];
}

/**
 * Notes as an outline: a list of lines, each array( style, text ). Styles: h1, h2, h3, p, quote, item.
 * Plain text, Markdown and Google Docs are all built from this.
 */
function bk_notes_outline( $user_id, $book_id, $only_chapter = 0 ) {
	$title = get_the_title( $book_id );
	$lines = array( array( 'h1', 'My notes on ' . $title ) );
	$lines[] = array( 'p', 'Updated ' . wp_date( 'j F Y, g:i a' ) );
	foreach ( bk_notes_collect( $user_id, $book_id, $only_chapter ) as $c ) {
		$lines[] = array( 'h2', bk_notes_chapter_label( $c ) );
		if ( $c['summary'] ) {
			$lines[] = array( 'h3', 'My summary' );
			$lines[] = array( 'p', $c['summary'] );
		}
		if ( $c['marks'] ) {
			$lines[] = array( 'h3', 'Highlights' );
			foreach ( $c['marks'] as $m ) {
				$lines[] = array( 'quote', $m['quote'] );
				if ( $m['note'] ) {
					$lines[] = array( 'p', 'My note: ' . $m['note'] );
				}
			}
		}
		if ( $c['reflect'] ) {
			$lines[] = array( 'h3', 'Reflections' );
			foreach ( $c['reflect'] as $r ) {
				if ( $r['q'] ) {
					$lines[] = array( 'item', $r['q'] );
				}
				$lines[] = array( 'p', $r['a'] );
			}
		}
		if ( $c['action'] ) {
			$lines[] = array( 'h3', 'Action points' );
			foreach ( $c['action'] as $a ) {
				$lines[] = array( 'item', ( $a['done'] ? '[done] ' : '' ) . $a['text'] . ( $a['due'] && ! $a['done'] ? ' (by ' . $a['due'] . ')' : '' ) );
			}
		}
		if ( $c['journal'] ) {
			$lines[] = array( 'h3', $c['num'] ? 'Journal' : 'Entries' );
			foreach ( $c['journal'] as $j ) {
				$lines[] = array( $j['label'] ? 'quote' : 'p', $j['text'] );
			}
		}
	}
	return $lines;
}

function bk_notes_markdown( $lines ) {
	$md = '';
	foreach ( $lines as $l ) {
		switch ( $l[0] ) {
			case 'h1':
				$md .= '# ' . $l[1] . "\n\n";
				break;
			case 'h2':
				$md .= '## ' . $l[1] . "\n\n";
				break;
			case 'h3':
				$md .= '### ' . $l[1] . "\n\n";
				break;
			case 'quote':
				$md .= '> ' . str_replace( "\n", "\n> ", $l[1] ) . "\n\n";
				break;
			case 'item':
				$md .= '- ' . $l[1] . "\n\n";
				break;
			default:
				$md .= $l[1] . "\n\n";
		}
	}
	return trim( $md ) . "\n";
}

function bk_notes_plain( $lines ) {
	$t = '';
	foreach ( $lines as $l ) {
		if ( 'h1' === $l[0] || 'h2' === $l[0] ) {
			$t .= strtoupper( $l[1] ) . "\n\n";
		} elseif ( 'quote' === $l[0] ) {
			$t .= '"' . $l[1] . "\"\n\n";
		} elseif ( 'item' === $l[0] ) {
			$t .= '- ' . $l[1] . "\n";
		} else {
			$t .= $l[1] . "\n\n";
		}
	}
	return trim( $t ) . "\n";
}

/* ---------- recap email ---------- */

function bk_notes_email_args( $user, $book_id, $chapter = 0 ) {
	$title = get_the_title( $book_id );
	$data  = bk_notes_collect( $user->ID, $book_id, $chapter );
	$blocks = array();
	foreach ( $data as $cid => $c ) {
		$items = array();
		if ( $c['summary'] ) {
			$items[] = array( 'My summary', $c['summary'] );
		}
		foreach ( $c['marks'] as $m ) {
			$items[] = array( 'Highlight', '"' . $m['quote'] . '"' . ( $m['note'] ? "\nMy note: " . $m['note'] : '' ) );
		}
		foreach ( $c['reflect'] as $r ) {
			$items[] = array( $r['q'] ?: 'Reflection', $r['a'] );
		}
		foreach ( $c['action'] as $a ) {
			$items[] = array( $a['done'] ? 'Action point, done' : 'Action point', $a['text'] . ( $a['due'] && ! $a['done'] ? ' (by ' . $a['due'] . ')' : '' ) );
		}
		foreach ( $c['journal'] as $j ) {
			$items[] = array( $j['label'] ?: 'Journal', $j['text'] );
		}
		$blocks[] = array( 'title' => bk_notes_chapter_label( $c ), 'items' => $items );
	}
	$first = $chapter && isset( $data[ $chapter ] ) ? $data[ $chapter ] : null;
	return array(
		'preheader' => $first ? 'Your summary and notes from ' . bk_notes_chapter_label( $first ) . '.' : 'Everything you have written in ' . $title . ', in one place.',
		'heading'   => $first ? 'Your notes from chapter ' . $first['num'] : 'Your notes on ' . $title,
		'paras'     => array( 'Hi ' . bk_first_name( $user ) . ', here is what you wrote' . ( $first ? ' in ' . bk_notes_chapter_label( $first ) : ' in ' . $title ) . ', so you can come back to it any time.' ),
		'blocks'    => $blocks,
		'cta'       => array( 'Keep reading', bk_reader_url( $book_id ) ),
		'foot'      => 'You asked for this email from the reader at ' . esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '.',
		'foot_text' => 'You asked for this email from the reader at ' . wp_parse_url( home_url(), PHP_URL_HOST ) . '.',
	);
}

add_action(
	'rest_api_init',
	function () {
		$auth = function () {
			return is_user_logged_in() ? true : bk_err( 'bk_login', 'Please sign in.', 401 );
		};
		register_rest_route( 'bookkeeper/v1', '/notes/email', array( 'methods' => 'POST', 'permission_callback' => $auth, 'callback' => 'bk_rest_notes_email' ) );
		register_rest_route( 'bookkeeper/v1', '/notes/export', array( 'methods' => 'GET', 'permission_callback' => $auth, 'callback' => 'bk_rest_notes_export' ) );
	}
);

function bk_rest_notes_email( WP_REST_Request $req ) {
	$book    = (int) $req['book'];
	$chapter = (int) $req['chapter'];
	$user    = wp_get_current_user();
	if ( 'bk_book' !== get_post_type( $book ) ) {
		return bk_err( 'bk_no_book', 'Book not found.', 404 );
	}
	// At most one recap a minute, so a double tap does not send two.
	$key = 'bk_notes_mail_' . $user->ID;
	if ( get_transient( $key ) ) {
		return bk_err( 'bk_slow', 'We just sent that. Check your inbox in a moment.', 429 );
	}
	$args = bk_notes_email_args( $user, $book, $chapter );
	if ( ! $args['blocks'] ) {
		return bk_err( 'bk_empty', $chapter ? 'Write a summary or a note in this chapter first.' : 'You have not written anything yet.', 400 );
	}
	set_transient( $key, 1, MINUTE_IN_SECONDS );
	$ok = bk_send_email( $user->user_email, $args['heading'], $args );
	return $ok ? array( 'sent' => $user->user_email ) : bk_err( 'bk_mail', 'The email could not be sent. Please try again later.', 500 );
}

function bk_rest_notes_export( WP_REST_Request $req ) {
	$book = (int) $req['book'];
	if ( 'bk_book' !== get_post_type( $book ) ) {
		return bk_err( 'bk_no_book', 'Book not found.', 404 );
	}
	$lines = bk_notes_outline( get_current_user_id(), $book );
	return array(
		'title'    => 'My notes on ' . get_the_title( $book ),
		'filename' => sanitize_file_name( 'my-notes-' . get_post_field( 'post_name', $book ) . '.md' ),
		'markdown' => bk_notes_markdown( $lines ),
		'text'     => bk_notes_plain( $lines ),
		'empty'    => count( $lines ) <= 2,
	);
}
