<?php
/**
 * Plugin Name: The Bookkeeper
 * Description: Paid, members-only book reader with journals, reflections, action points, highlights and sticky notes. A Banks Solution product.
 * Version: 0.1.0
 * Author: Banks Solution
 * Requires PHP: 7.4
 * Text Domain: bookkeeper
 */

defined( 'ABSPATH' ) || exit;

define( 'BK_VERSION', '0.1.0' );
define( 'BK_DIR', plugin_dir_path( __FILE__ ) );
define( 'BK_URL', plugin_dir_url( __FILE__ ) );

require_once BK_DIR . 'inc/install.php';
require_once BK_DIR . 'inc/content.php';
require_once BK_DIR . 'inc/access.php';
require_once BK_DIR . 'inc/rest.php';
require_once BK_DIR . 'inc/reader.php';
require_once BK_DIR . 'inc/admin.php';
require_once BK_DIR . 'inc/seed.php';

register_activation_hook( __FILE__, 'bk_activate' );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
