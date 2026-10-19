<?php
/**
 * Plugin Name: Directorist - Invoices
 * Plugin URI: https://wpxplore.com/tools/directorist-invoice/
 * Update URI: https://wpxplore.com/tools/directorist-invoice/
 * Description: Download PDF invoices for Directorist purchases and booking payments.
 * Author: wpXplore
 * Author URI: https://wpxplore.com
 * Version: 1.5.2
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: directorist
 * Text Domain: directorist-invoice
 *
 * @package Directorist_Invoice
 */

defined( 'ABSPATH' ) || exit;

define( 'DIRECTORIST_INVOICE_PATH', plugin_dir_path( __FILE__ ) );
define( 'DIRECTORIST_INVOICE_FILE', __FILE__ );
define( 'DIRECTORIST_INVOICE_VERSION', '1.5.2' );

/**
 * Prevent activation without an active Directorist installation.
 *
 * @param bool $network_wide Whether the plugin is being activated network-wide.
 */
function directorist_invoices_activate( $network_wide ): void {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$directorist_plugin = 'directorist/directorist-base.php';
	$directorist_active = $network_wide
		? is_plugin_active_for_network( $directorist_plugin )
		: is_plugin_active( $directorist_plugin );

	if ( ! $directorist_active || ! function_exists( 'directorist_order_repository' ) ) {
		wp_die(
			esc_html__( 'Directorist Invoices requires an active Directorist plugin. Activate Directorist first.', 'directorist-invoice' ),
			esc_html__( 'Plugin dependency missing', 'directorist-invoice' ),
			array( 'back_link' => true )
		);
	}
}

register_activation_hook( __FILE__, 'directorist_invoices_activate' );

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! function_exists( 'directorist_order_repository' ) ) {
			return;
		}

		require_once DIRECTORIST_INVOICE_PATH . 'includes/class-directorist-invoices.php';
		Directorist_Invoices::init();
	},
	30
);
