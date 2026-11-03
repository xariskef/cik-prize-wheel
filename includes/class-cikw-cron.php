<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The email queue worker.
 *
 * Sends pending confirmations in small batches on WP-Cron, with exponential
 * backoff, so a slow or flapping trade-show network never blocks the kiosk
 * and never loses a participation.
 */
final class CIKW_Cron
{
    public const HOOK = 'cikw_process_email_queue';
    public const INTERVAL = 'cikw_five_minutes';

    /** Kept small: WP-Cron runs inside a page request and must not hang it. */
    private const BATCH_SIZE = 20;

    public function init(): void
    {
        add_filter('cron_schedules', [$this, 'register_interval']);
        add_action(self::HOOK, [__CLASS__, 'process_queue']);
        add_action('init', [__CLASS__, 'ensure_scheduled']);
    }

    /**
     * @param array<string, array<string, mixed>> $schedules
     * @return array<string, array<string, mixed>>
     */
    public function register_interval(array $schedules): array
    {
        $schedules[self::INTERVAL] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => __('Every 5 minutes (Prize Wheel Kiosk)', 'cik-prize-wheel'),
        ];

        return $schedules;
    }

    public static function ensure_scheduled(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, self::INTERVAL, self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * Entries waiting to be emailed.
     *
     * Only participations that reached /finish are eligible: email_status
     * defaults to 'pending' at registration, so without the finished_at test
     * this would email everyone who walked away mid-form.
     *
     * @return array<int, int> Entry ids.
     */
    public static function due_entry_ids(int $limit): array
    {
        global $wpdb;

        $table = CIKW_Activator::table_name('entries');
        $now = current_time('mysql');

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table}
              WHERE email_status = 'pending'
                AND finished_at IS NOT NULL
                AND email_attempts < %d
                AND (email_next_attempt_at IS NULL OR email_next_attempt_at <= %s)
              ORDER BY email_next_attempt_at IS NULL DESC, finished_at ASC
              LIMIT %d",
            CIKW_Mailer::MAX_ATTEMPTS,
            $now,
            $limit
        ));

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    /**
     * Send one batch.
     *
     * @return array{sent:int, failed:int, total:int}
     */
    public static function process_queue(int $limit = self::BATCH_SIZE): array
    {
        $ids = self::due_entry_ids($limit);
        $sent = 0;
        $failed = 0;

        foreach ($ids as $entry_id) {
            $result = CIKW_Mailer::send_for_entry($entry_id);

            if (is_wp_error($result)) {
                $failed++;
                continue;
            }

            $sent++;
        }

        if ($sent > 0 || $failed > 0) {
            update_option('cikw_last_queue_run', current_time('mysql'), false);
        }

        return ['sent' => $sent, 'failed' => $failed, 'total' => count($ids)];
    }

    /**
     * How many entries are waiting, for the dashboard widget.
     */
    public static function pending_count(): int
    {
        global $wpdb;

        $table = CIKW_Activator::table_name('entries');

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE email_status = 'pending' AND finished_at IS NOT NULL AND email_attempts < %d",
            CIKW_Mailer::MAX_ATTEMPTS
        ));
    }
}
