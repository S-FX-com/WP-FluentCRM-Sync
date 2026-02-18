<?php
/**
 * Plugin Name:  FluentCRM WordPress Sync
 * Plugin URI:   https://github.com/iapsnj/fluentcrm-wp-sync
 * Description:  Bidirectional sync between FluentCRM contacts and WordPress users with configurable field mapping, ACF support, and mismatch resolution.
 * Version:      1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author:       IAPSNJ
 * License:      GPL-2.0+
 * Text Domain:  fcrm-wp-sync
 */

defined( 'ABSPATH' ) || exit;

define( 'FCRM_WP_SYNC_VERSION', '1.0.0' );
define( 'FCRM_WP_SYNC_DIR',     plugin_dir_path( __FILE__ ) );
define( 'FCRM_WP_SYNC_URL',     plugin_dir_url( __FILE__ ) );
define( 'FCRM_WP_SYNC_FILE',    __FILE__ );

// ---------------------------------------------------------------------------
// Autoloader
// ---------------------------------------------------------------------------
spl_autoload_register( function ( $class ) {
    $prefix = 'FCRM_WP_Sync_';
    if ( strpos( $class, $prefix ) !== 0 ) {
        return;
    }
    $short = substr( $class, strlen( $prefix ) ); // e.g. "Field_Mapper"
    $file  = FCRM_WP_SYNC_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $short ) ) . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// ---------------------------------------------------------------------------
// Activation / Deactivation
// ---------------------------------------------------------------------------
register_activation_hook( __FILE__, [ 'FCRM_WP_Sync_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'FCRM_WP_Sync_Plugin', 'deactivate' ] );

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
add_action( 'plugins_loaded', [ 'FCRM_WP_Sync_Plugin', 'get_instance' ] );

/**
 * Main plugin bootstrap class.
 */
final class FCRM_WP_Sync_Plugin {

    /** @var self|null */
    private static $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Guard: FluentCRM must be active.
        if ( ! class_exists( '\FluentCrm\App\Models\Subscriber' ) ) {
            add_action( 'admin_notices', [ $this, 'notice_fluentcrm_missing' ] );
            return;
        }

        FCRM_WP_Sync_Engine::get_instance();
        FCRM_WP_Sync_Admin::get_instance();
        FCRM_WP_Sync_REST_API::get_instance();
    }

    public function notice_fluentcrm_missing(): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__( 'FluentCRM WP Sync requires FluentCRM to be installed and activated.', 'fcrm-wp-sync' )
            . '</p></div>';
    }

    // -----------------------------------------------------------------------
    // Activation: create default option rows
    // -----------------------------------------------------------------------
    public static function activate(): void {
        if ( get_option( 'fcrm_wp_sync_field_mappings' ) === false ) {
            add_option( 'fcrm_wp_sync_field_mappings', [] );
        }
        if ( get_option( 'fcrm_wp_sync_settings' ) === false ) {
            add_option( 'fcrm_wp_sync_settings', [
                'default_sync_direction' => 'both',
                'sync_on_user_register'  => true,
                'sync_on_profile_update' => true,
                'sync_on_user_delete'    => true,
                'sync_on_fcrm_update'    => true,
            ] );
        }
    }

    public static function deactivate(): void {
        // Intentionally keep data on deactivation.
    }
}
