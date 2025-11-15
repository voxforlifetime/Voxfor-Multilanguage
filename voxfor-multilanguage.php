<?php
/**
 * Plugin Name: Voxfor Multilanguage
 * Plugin URI: https://voxfor.com/voxfor-multilanguage
 * Description: Free, community WordPress multilingual plugin using the DeepL API (BYO API key). Simple setup with English as default, SEO-friendly path prefixes, translation memory, and visual editing.
 * Version: 2.2.4
 * Author: Voxfor
 * Author URI: https://www.voxfor.com
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: voxfor-multilanguage
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 8.1
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'VOXFOR_ML_VERSION', '2.2.4' );
define( 'VOXFOR_ML_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VOXFOR_ML_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'VOXFOR_ML_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'VOXFOR_ML_DEFAULT_LANG', 'en' );
define( 'VOXFOR_ML_COOKIE_NAME', 'voxfor_ml_lang' );
define( 'VOXFOR_ML_TABLE_PREFIX', 'voxfor_ml_' );

// Register admin hooks for proper timing
if ( is_admin() ) {
	// Register admin_enqueue_scripts hook
	add_action(
		'admin_enqueue_scripts',
		function ( $hook ) {
		// Only load on our admin pages
		if ( strpos( $hook, 'voxfor-ml' ) === false && strpos( $hook, 'voxfor-multilanguage' ) === false ) {
			return;
		}

			// Admin CSS
			wp_enqueue_style(
				'voxfor-ml-admin',
				VOXFOR_ML_PLUGIN_URL . 'public/css/admin.css',
				array(),
				VOXFOR_ML_VERSION
			);

			// Admin JS
			wp_enqueue_script(
				'voxfor-ml-admin',
				VOXFOR_ML_PLUGIN_URL . 'public/js/admin.js',
				array( 'jquery' ),
				VOXFOR_ML_VERSION,
				true
			);

			// Localize script
			wp_localize_script(
				'voxfor-ml-admin',
				'voxforMLAdmin',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'voxfor_ml_admin' ),
					'strings' => array(
						'processing' => __( 'Processing...', 'voxfor-multilanguage' ),
						'error'      => __( 'Error occurred', 'voxfor-multilanguage' ),
						'success'    => __( 'Success!', 'voxfor-multilanguage' ),
					),
				)
			);
		},
		5
	);
}

// Autoloader
spl_autoload_register(
	function ( $class ) {
		$prefix   = 'VoxforML\\';
		$base_dir = VOXFOR_ML_PLUGIN_DIR . 'includes/';

		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class, $len );
		$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

// Load required files
require_once VOXFOR_ML_PLUGIN_DIR . 'includes/Core/Plugin.php';
require_once VOXFOR_ML_PLUGIN_DIR . 'includes/Core/Activator.php';
require_once VOXFOR_ML_PLUGIN_DIR . 'includes/Core/Deactivator.php';

// Activation hook
register_activation_hook( __FILE__, array( 'VoxforML\Core\Activator', 'activate' ) );

// Deactivation hook
register_deactivation_hook( __FILE__, array( 'VoxforML\Core\Deactivator', 'deactivate' ) );

// Force admin context detection
if ( isset( $_SERVER['REQUEST_URI'] ) && strpos( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '/wp-admin/' ) !== false ) {
	if ( ! defined( 'WP_ADMIN' ) ) {
		define( 'WP_ADMIN', true );
	}
}

// Initialize plugin on WordPress init
add_action(
	'init',
	function () {
		$plugin = VoxforML\Core\Plugin::getInstance();
		$plugin->init();
	},
	1
);
