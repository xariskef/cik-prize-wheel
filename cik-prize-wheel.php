<?php
/**
 * Plugin Name:       Prize Wheel Kiosk
 * Description:       Touchscreen kiosk registration and prize wheel for trade shows and events.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Cikefallinos
 * Author URI:        https://cikefallinos.com
 * License:           All rights reserved
 * Text Domain:       cik-prize-wheel
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CIKW_PLUGIN_FILE', __FILE__);
define('CIKW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CIKW_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CIKW_VERSION', '1.0.0');
define('CIKW_SCHEMA_VERSION', '1.2.0');

require_once CIKW_PLUGIN_DIR . 'includes/class-cikw-settings.php';
require_once CIKW_PLUGIN_DIR . 'includes/class-cikw-admin-data.php';
require_once CIKW_PLUGIN_DIR . 'includes/class-cikw-admin-tools.php';
require_once CIKW_PLUGIN_DIR . 'includes/class-cikw-demo-data.php';
require_once CIKW_PLUGIN_DIR . 'includes/class-cikw-activator.php';
require_once CIKW_PLUGIN_DIR . 'includes/class-cikw-security.php';
require_once CIKW_PLUGIN_DIR . 'includes/class-cikw-config.php';
require_once CIKW_PLUGIN_DIR . 'includes/class-cikw-spin-engine.php';
require_once CIKW_PLUGIN_DIR . 'includes/class-cikw-rest.php';
require_once CIKW_PLUGIN_DIR . 'includes/class-cikw-vouchers.php';
require_once CIKW_PLUGIN_DIR . 'includes/class-cikw-entries-query.php';
require_once CIKW_PLUGIN_DIR . 'includes/class-cikw-export.php';
require_once CIKW_PLUGIN_DIR . 'includes/class-cikw-mailer.php';
require_once CIKW_PLUGIN_DIR . 'includes/class-cikw-cron.php';
require_once CIKW_PLUGIN_DIR . 'includes/class-cikw-cli.php';
require_once CIKW_PLUGIN_DIR . 'includes/class-cikw-dashboard.php';
require_once CIKW_PLUGIN_DIR . 'includes/class-cikw-plugin.php';

// The list table extends a wp-admin class that does not exist on the front
// end, so it is only pulled in where it can actually be used.
if (is_admin()) {
    require_once CIKW_PLUGIN_DIR . 'includes/class-cikw-entries-table.php';
    require_once CIKW_PLUGIN_DIR . 'includes/class-cikw-admin-entries.php';
    require_once CIKW_PLUGIN_DIR . 'includes/class-cikw-admin-redeem.php';
}

register_activation_hook(__FILE__, ['CIKW_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['CIKW_Activator', 'deactivate']);

add_action('plugins_loaded', static function (): void {
    CIKW_Plugin::instance()->init();
});
