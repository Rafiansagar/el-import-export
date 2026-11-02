<?php
/**
 * Plugin Name: EL Import Exporter
 * Description: Export and import Elementor pages, posts and templates as a ZIP bundle that carries every image they use.
 * Version:     1.0.0
 * Author:      Rafiansagar
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License:     GPL-2.0-or-later
 * Text Domain: el-import-exporter
 */

defined( 'ABSPATH' ) || exit;

define( 'EIE_VERSION', '1.0.0' );
define( 'EIE_FILE', __FILE__ );
define( 'EIE_DIR', plugin_dir_path( __FILE__ ) );
define( 'EIE_URL', plugin_dir_url( __FILE__ ) );

require_once EIE_DIR . 'includes/class-eie-util.php';
require_once EIE_DIR . 'includes/class-eie-exporter.php';
require_once EIE_DIR . 'includes/class-eie-importer.php';
require_once EIE_DIR . 'includes/class-eie-admin.php';

add_action( 'plugins_loaded', array( 'EIE_Admin', 'init' ) );
