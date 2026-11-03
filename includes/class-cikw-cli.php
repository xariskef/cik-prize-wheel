<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WP-CLI commands.
 *
 * The email flush exists because WP-Cron only fires when somebody loads a
 * page, and a kiosk sitting on the attract screen generates no page loads at
 * all. During the show the queue can be drained from the command line, or
 * from a real system cron calling `wp cikw flush-emails`.
 */
final class CIKW_CLI
{
    public static function register(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        WP_CLI::add_command('cikw flush-emails', [__CLASS__, 'flush_emails']);
        WP_CLI::add_command('cikw queue-status', [__CLASS__, 'queue_status']);
    }

    /**
     * Send pending confirmation emails.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : How many to send in one pass. Default 50.
     *
     * [--all]
     * : Keep going until the queue is empty.
     *
     * ## EXAMPLES
     *
     *     wp cikw flush-emails
     *     wp cikw flush-emails --all
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assoc_args
     */
    public static function flush_emails(array $args, array $assoc_args): void
    {
        $limit = isset($assoc_args['limit']) ? max(1, (int) $assoc_args['limit']) : 50;
        $all = isset($assoc_args['all']);

        $sent = 0;
        $failed = 0;

        do {
            $result = CIKW_Cron::process_queue($limit);
            $sent += $result['sent'];
            $failed += $result['failed'];

            if ($result['total'] > 0) {
                WP_CLI::log(sprintf('Batch: %d sent, %d failed.', $result['sent'], $result['failed']));
            }

            // Stop when a pass returns nothing, otherwise --all would spin
            // forever on entries that are backing off.
        } while ($all && $result['total'] > 0);

        if (0 === $sent && 0 === $failed) {
            WP_CLI::success('Nothing to send.');

            return;
        }

        if ($failed > 0) {
            WP_CLI::warning(sprintf('%d sent, %d failed. Check email_last_error in wp-admin.', $sent, $failed));

            return;
        }

        WP_CLI::success(sprintf('%d email(s) sent.', $sent));
    }

    /**
     * Show the state of the email queue.
     *
     * ## EXAMPLES
     *
     *     wp cikw queue-status
     */
    public static function queue_status(): void
    {
        global $wpdb;

        $table = CIKW_Activator::table_name('entries');
        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT email_status, COUNT(*) AS total FROM %i GROUP BY email_status', $table),
            ARRAY_A
        );

        $items = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $items[] = ['status' => $row['email_status'], 'entries' => $row['total']];
        }

        $items[] = ['status' => 'due now', 'entries' => count(CIKW_Cron::due_entry_ids(1000))];

        WP_CLI\Utils\format_items('table', $items, ['status', 'entries']);

        $next = wp_next_scheduled(CIKW_Cron::HOOK);
        WP_CLI::log('Next cron run: ' . ($next ? get_date_from_gmt(gmdate('Y-m-d H:i:s', $next)) : 'not scheduled'));
    }
}
