<?php
defined( 'ABSPATH' ) || exit;

/**
 * Books and chapters. A chapter's sections are the parts between its H2 headings.
 * Interactive parts are Group or Paragraph blocks with one of these classes:
 *   bk-reflect  a reflection prompt (the text is the question)
 *   bk-action   an action-point prompt
 *   bk-quote    a pull quote the reader can save
 */
function bk_register_types() {
	register_post_type(
		'bk_book',
		array(
			'labels'       => array( 'name' => 'Books', 'singular_name' => 'Book', 'menu_name' => 'Bookkeeper' ),
			'public'       => false,
			'show_ui'      => true,
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-book-alt',
			'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
		)
	);
	register_post_type(
		'bk_chapter',
		array(
			'labels'       => array( 'name' => 'Chapters', 'singular_name' => 'Chapter' ),
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => 'edit.php?post_type=bk_book',
			'show_in_rest' => true,
			'hierarchical' => false,
			'supports'     => array( 'title', 'editor', 'page-attributes', 'custom-fields' ),
		)
	);
	register_post_meta( 'bk_chapter', '_bk_book', array( 'type' => 'integer', 'single' => true, 'show_in_rest' => false ) );
	register_post_meta( 'bk_chapter', '_bk_free', array( 'type' => 'boolean', 'single' => true, 'show_in_rest' => false ) );
}
add_action( 'init', 'bk_register_types' );

/** Look and feel per site. Vivere's values are the defaults. */
function bk_brand() {
	$d = array(
		'name'      => get_bloginfo( 'name' ),
		'accent'    => '#8D0452',
		'dark'      => '#3A0222',
		'paper'     => '#FBF8F4',
		'ink'       => '#1D1418',
		'font'      => '"DM Sans", system-ui, sans-serif',
		'font_url'  => get_theme_file_uri( 'assets/fonts/dm-sans-latin-wght-normal.woff2' ),
		'logo'      => '',
		'home'      => home_url( '/' ),
		'buy_url'   => home_url( '/book/' ),
	);
	$logo = get_theme_mod( 'custom_logo' );
	if ( $logo ) {
		$d['logo'] = wp_get_attachment_image_url( $logo, 'medium' );
	}
	return wp_parse_args( (array) get_option( 'bk_brand', array() ), $d );
}

function bk_book_chapters( $book_id ) {
	return get_posts(
		array(
			'post_type'      => 'bk_chapter',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
			'meta_key'       => '_bk_book', // phpcs:ignore
			'meta_value'     => (int) $book_id, // phpcs:ignore
		)
	);
}

/**
 * Split a chapter into sections at each H2.
 * Returns [ [ 'title' => string, 'blocks' => array ] ... ]. Content before the first H2 joins the first section.
 */
function bk_chapter_sections( $chapter ) {
	$chapter = get_post( $chapter );
	if ( ! $chapter ) {
		return array();
	}
	$cache = wp_cache_get( 'bk_sec_' . $chapter->ID . '_' . md5( $chapter->post_modified ), 'bookkeeper' );
	if ( false !== $cache ) {
		return $cache;
	}
	$blocks   = parse_blocks( $chapter->post_content );
	$sections = array();
	$lead     = array();
	foreach ( $blocks as $b ) {
		if ( empty( $b['blockName'] ) && '' === trim( $b['innerHTML'] ) ) {
			continue;
		}
		$is_h2 = 'core/heading' === $b['blockName'] && ( isset( $b['attrs']['level'] ) ? 2 === (int) $b['attrs']['level'] : true );
		if ( $is_h2 ) {
			$sections[] = array(
				'title'  => trim( wp_strip_all_tags( $b['innerHTML'] ) ),
				'blocks' => array(),
			);
			continue;
		}
		if ( ! $sections ) {
			$lead[] = $b;
		} else {
			$sections[ count( $sections ) - 1 ]['blocks'][] = $b;
		}
	}
	if ( ! $sections ) {
		$sections[] = array( 'title' => get_the_title( $chapter ), 'blocks' => array() );
	}
	$sections[0]['blocks'] = array_merge( $lead, $sections[0]['blocks'] );
	foreach ( $sections as $i => $s ) {
		$sections[ $i ]['minutes'] = max( 1, (int) round( str_word_count( wp_strip_all_tags( serialize_blocks( $s['blocks'] ) ) ) / 220 ) );
	}
	wp_cache_set( 'bk_sec_' . $chapter->ID . '_' . md5( $chapter->post_modified ), $sections, 'bookkeeper', HOUR_IN_SECONDS );
	return $sections;
}

function bk_render_section( $chapter, $index ) {
	$sections = bk_chapter_sections( $chapter );
	if ( ! isset( $sections[ $index ] ) ) {
		return null;
	}
	$html = '';
	foreach ( $sections[ $index ]['blocks'] as $b ) {
		$html .= render_block( $b );
	}
	return array(
		'title'   => $sections[ $index ]['title'],
		'html'    => $html,
		'minutes' => $sections[ $index ]['minutes'],
	);
}

/** Table of contents for the reader: titles only, never text. */
function bk_toc( $book_id, $user_id ) {
	$out = array();
	foreach ( bk_book_chapters( $book_id ) as $n => $ch ) {
		$secs = array();
		foreach ( bk_chapter_sections( $ch ) as $s ) {
			$secs[] = array( 'title' => $s['title'], 'minutes' => $s['minutes'] );
		}
		$out[] = array(
			'id'       => $ch->ID,
			'num'      => $n + 1,
			'title'    => get_the_title( $ch ),
			'free'     => (bool) get_post_meta( $ch->ID, '_bk_free', true ),
			'locked'   => ! bk_can_read_chapter( $user_id, $ch->ID ),
			'sections' => $secs,
		);
	}
	return $out;
}
