<?php

if (!defined('ABSPATH')) {
    exit;
}

final class CIKW_Plugin
{
    private static ?CIKW_Plugin $instance = null;

    private CIKW_Settings $settings;
    private CIKW_Admin_Data $admin_data;
    private CIKW_Admin_Tools $admin_tools;
    private CIKW_REST $rest;
    private CIKW_Cron $cron;
    private CIKW_Dashboard $dashboard;
    private ?CIKW_Admin_Entries $admin_entries = null;
    private ?CIKW_Admin_Redeem $admin_redeem = null;

    private function __construct()
    {
        $this->settings = new CIKW_Settings();
        $this->admin_data = new CIKW_Admin_Data();
        $this->admin_tools = new CIKW_Admin_Tools();
        $this->rest = new CIKW_REST();
        $this->cron = new CIKW_Cron();
        $this->dashboard = new CIKW_Dashboard();

        if (is_admin()) {
            $this->admin_entries = new CIKW_Admin_Entries();
            $this->admin_redeem = new CIKW_Admin_Redeem();
        }
    }

    public static function instance(): CIKW_Plugin
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function init(): void
    {
        add_action('init', [$this, 'load_textdomain']);
        add_action('admin_init', ['CIKW_Activator', 'maybe_upgrade']);

        $this->settings->init();
        $this->admin_data->init();
        $this->admin_tools->init();
        $this->rest->init();
        $this->cron->init();
        $this->dashboard->init();
        $this->admin_entries?->init();
        $this->admin_redeem?->init();

        CIKW_CLI::register();
    }

    public function load_textdomain(): void
    {
        load_plugin_textdomain(
            'cik-prize-wheel',
            false,
            dirname(plugin_basename(CIKW_PLUGIN_FILE)) . '/languages'
        );
    }
}
