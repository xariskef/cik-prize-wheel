<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Belt and braces: WordPress deactivates before uninstalling, so the
// deactivation hook has already cleared this. Clearing it again costs
// nothing and guards against an install whose deactivation was interrupted.
wp_clear_scheduled_hook('cikw_process_email_queue');

$settings = get_option('cikw_settings', []);

if (!is_array($settings) || empty($settings['delete_data_on_uninstall'])) {
    // Spec section 10: tables are only ever dropped when the site owner has
    // explicitly opted in. Participation data outlives the plugin otherwise.
    return;
}

global $wpdb;

$tables = [
    $wpdb->prefix . 'cikw_spins',
    $wpdb->prefix . 'cikw_entries',
    $wpdb->prefix . 'cikw_prizes',
    $wpdb->prefix . 'cikw_categories',
];

foreach ($tables as $table) {
    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
}

delete_option('cikw_settings');
delete_option('cikw_schema_version');
delete_option('cikw_last_queue_run');

// Created on demand by wp_salt('cikw_ip_hash') the first time an IP was
// hashed. Left behind these are harmless but meaningless.
delete_site_option('cikw_ip_hash_key');
delete_site_option('cikw_ip_hash_salt');
