<?php
/**
 * Plugin Name: FloAuth
 * Version: 1.1.0
 * Description: FloMembers authentication plugin
 * Author: Flo Apps Ltd
 * Author URI: https://floapps.com
 * Text Domain: floauth
 * Domain Path: /languages/
 * License: MIT
 *
 * @package FloAuth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Load plugin textdomain
 *
 * @return void
 */
function floauth_load_plugin_textdomain() {
	load_plugin_textdomain( 'floauth', false, basename( __DIR__ ) . '/languages/' );
}
add_action( 'plugins_loaded', 'floauth_load_plugin_textdomain' );

require_once __DIR__ . '/includes/util.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/extranet.php';
