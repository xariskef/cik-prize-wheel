<?php

if (!defined('ABSPATH')) {
    exit;
}

final class CIKW_Activator
{
    public static function activate(): void
    {
        self::create_tables();
        self::create_default_options();
        self::seed_placeholder_data();
        CIKW_Cron::ensure_scheduled();
    }

    public static function deactivate(): void
    {
        // Leaving the event scheduled would have WP-Cron firing a hook with
        // no listener on every request for the life of the install.
        CIKW_Cron::unschedule();
    }

    /**
     * Bring an already-activated install up to the current schema.
     *
     * dbDelta is additive, so re-running the CREATE TABLE statements adds
     * any new column without touching existing rows. Hooked to admin_init
     * rather than plugins_loaded so a frontend request never pays for it.
     */
    public static function maybe_upgrade(): void
    {
        if (get_option('cikw_schema_version') === CIKW_SCHEMA_VERSION) {
            return;
        }

        self::create_tables();
        self::create_default_options();
    }

    public static function table_name(string $name): string
    {
        global $wpdb;

        return $wpdb->prefix . 'cikw_' . $name;
    }

    private static function create_tables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $collation = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';

        $categories = self::table_name('categories');
        $prizes = self::table_name('prizes');
        $entries = self::table_name('entries');
        $spins = self::table_name('spins');

        $sql = [];

        $sql[] = "CREATE TABLE {$categories} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(100) NOT NULL,
            name VARCHAR(190) NOT NULL,
            description TEXT NOT NULL,
            image_id BIGINT UNSIGNED NULL,
            color VARCHAR(9) NOT NULL,
            wheel_weight DECIMAL(6,3) NOT NULL DEFAULT 1.000,
            segment_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY is_active_order (is_active, segment_order)
        ) {$collation};";

        $sql[] = "CREATE TABLE {$prizes} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            category_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(190) NOT NULL,
            description TEXT NULL,
            image_id BIGINT UNSIGNED NULL,
            probability DECIMAL(6,3) NOT NULL DEFAULT 0.000,
            stock_total INT NOT NULL DEFAULT 0,
            stock_remaining INT NOT NULL DEFAULT 0,
            daily_cap INT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            KEY category_id (category_id),
            KEY active_category (is_active, category_id)
        ) {$collation};";

        $sql[] = "CREATE TABLE {$entries} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            full_name VARCHAR(190) NOT NULL,
            email VARCHAR(190) NOT NULL,
            phone VARCHAR(40) NOT NULL,
            company VARCHAR(190) NULL,
            role VARCHAR(120) NULL,
            need_category_id BIGINT UNSIGNED NULL,
            consent_marketing TINYINT(1) NOT NULL DEFAULT 0,
            consent_text_version VARCHAR(20) NOT NULL,
            consent_at DATETIME NULL,
            entry_token CHAR(43) NOT NULL,
            device_id VARCHAR(64) NOT NULL,
            ip_hash CHAR(64) NULL,
            finished_at DATETIME NULL,
            email_status ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
            email_attempts INT NOT NULL DEFAULT 0,
            email_last_error TEXT NULL,
            email_next_attempt_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY email (email),
            KEY entry_token (entry_token),
            KEY email_status (email_status),
            KEY device_created (device_id, created_at),
            KEY finished_at (finished_at),
            KEY email_queue (email_status, email_next_attempt_at)
        ) {$collation};";

        $sql[] = "CREATE TABLE {$spins} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entry_id BIGINT UNSIGNED NOT NULL,
            spin_number INT NOT NULL,
            category_id BIGINT UNSIGNED NOT NULL,
            prize_id BIGINT UNSIGNED NULL,
            outcome ENUM('win','lose') NOT NULL,
            voucher_code VARCHAR(12) NULL,
            redeemed_at DATETIME NULL,
            redeemed_by VARCHAR(100) NULL,
            decision_log JSON NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY entry_id (entry_id),
            KEY created_at (created_at),
            KEY prize_id (prize_id),
            KEY category_id (category_id),
            UNIQUE KEY voucher_code (voucher_code)
        ) {$collation};";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        // Autoloaded: maybe_upgrade() reads it on every admin request.
        update_option('cikw_schema_version', CIKW_SCHEMA_VERSION, true);
    }

    private static function seed_placeholder_data(): void
    {
        // The dataset lives in CIKW_Demo_Data so the activation hook and
        // tools/seed-demo-data.php cannot drift apart. install() is a no-op
        // when categories already exist, so reactivating never duplicates rows.
        CIKW_Demo_Data::install(false);
    }

    private static function create_default_options(): void
    {
        $defaults = CIKW_Settings::defaults();
        $existing = get_option(CIKW_Settings::OPTION_NAME);

        if (!is_array($existing)) {
            add_option(CIKW_Settings::OPTION_NAME, $defaults, '', false);
            return;
        }

        update_option(
            CIKW_Settings::OPTION_NAME,
            array_merge($defaults, $existing),
            false
        );
    }
}
