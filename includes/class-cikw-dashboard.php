<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The dashboard widget the booth staff watch during the show.
 *
 * Deliberately read-only and cheap: participations today and in total,
 * prizes awarded against what is left, pending emails, and when something
 * last happened - which is the fastest way to notice the kiosk has stopped
 * talking to the server.
 */
final class CIKW_Dashboard
{
    public function init(): void
    {
        add_action('wp_dashboard_setup', [$this, 'register_widget']);
    }

    public function register_widget(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        wp_add_dashboard_widget(
            'cikw_dashboard_widget',
            __('Prize Wheel - live overview', 'cik-prize-wheel'),
            [$this, 'render']
        );
    }

    /**
     * The live counters, shared by the wp-admin widget and the kiosk's own
     * hidden status panel so the two can never disagree.
     *
     * Aggregates only - no personal data, because the kiosk panel is reachable
     * with the kiosk key, which is public by design.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(): array
    {
        global $wpdb;

        $entries = CIKW_Activator::table_name('entries');
        $spins = CIKW_Activator::table_name('spins');
        $today = current_time('Y-m-d');

        $last_activity = $wpdb->get_var($wpdb->prepare('SELECT MAX(created_at) FROM %i', $spins));

        return [
            'entries_today' => (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE DATE(created_at) = %s',
                $entries,
                $today
            )),
            'entries_total' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $entries)),
            'spins_today' => (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE DATE(created_at) = %s',
                $spins,
                $today
            )),
            'wins_today' => (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE outcome = %s AND DATE(created_at) = %s',
                $spins,
                'win',
                $today
            )),
            'unredeemed' => (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE outcome = %s AND redeemed_at IS NULL',
                $spins,
                'win'
            )),
            'pending_emails' => CIKW_Cron::pending_count(),
            'failed_emails' => (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE email_status = %s',
                $entries,
                'failed'
            )),
            'last_activity' => $last_activity ? (string) $last_activity : null,
            // When the queue worker last did something. If this is hours old
            // while emails are piling up, WP-Cron is not firing - which is
            // exactly what happens on a kiosk that serves no page loads.
            'last_queue_run' => get_option('cikw_last_queue_run') ?: null,
            'server_time' => current_time('mysql'),
            'prizes' => self::prize_stock(),
        ];
    }

    /**
     * Prizes with finite stock, scarcest first - the number booth staff need
     * when someone asks whether a prize is still available.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function prize_stock(int $limit = 20): array
    {
        global $wpdb;

        $prizes = CIKW_Activator::table_name('prizes');
        $categories = CIKW_Activator::table_name('categories');

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT p.id, p.title, p.stock_total, p.stock_remaining, c.name AS category
                   FROM %i p
                   LEFT JOIN %i c ON c.id = p.category_id
                  WHERE p.stock_total > 0 AND p.is_active = 1
                  ORDER BY (p.stock_remaining / NULLIF(p.stock_total, 0)) ASC, p.title ASC
                  LIMIT %d',
                $prizes,
                $categories,
                $limit
            ),
            ARRAY_A
        );

        return array_map(
            static fn(array $row): array => [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'category' => (string) ($row['category'] ?? ''),
                'stock_total' => (int) $row['stock_total'],
                'stock_remaining' => (int) $row['stock_remaining'],
            ],
            is_array($rows) ? $rows : []
        );
    }

    public function render(): void
    {
        $snapshot = self::snapshot();

        $entries_today = $snapshot['entries_today'];
        $entries_total = $snapshot['entries_total'];
        $spins_today = $snapshot['spins_today'];
        $wins_today = $snapshot['wins_today'];
        $unredeemed = $snapshot['unredeemed'];
        $pending_emails = $snapshot['pending_emails'];
        $failed_emails = $snapshot['failed_emails'];
        $last_activity = $snapshot['last_activity'];

        echo '<div class="cikw-dashboard">';

        echo '<ul style="margin:0 0 12px;display:grid;grid-template-columns:1fr 1fr;gap:6px 16px;">';
        $this->stat(__('Entries today', 'cik-prize-wheel'), (string) $entries_today);
        $this->stat(__('Entries total', 'cik-prize-wheel'), (string) $entries_total);
        $this->stat(__('Spins today', 'cik-prize-wheel'), (string) $spins_today);
        $this->stat(__('Prizes today', 'cik-prize-wheel'), (string) $wins_today);
        $this->stat(__('Unredeemed prizes', 'cik-prize-wheel'), (string) $unredeemed);
        $this->stat(__('Emails pending', 'cik-prize-wheel'), (string) $pending_emails);
        echo '</ul>';

        if ($failed_emails > 0) {
            echo '<div class="notice notice-error inline" style="margin:0 0 12px;padding:6px 10px;"><p style="margin:0;">';
            printf(
                /* translators: %d: number of failed emails. */
                esc_html__('%d email(s) failed permanently. See Entries.', 'cik-prize-wheel'),
                $failed_emails
            );
            echo '</p></div>';
        }

        // Last activity is the tell-tale: if the kiosk has crashed or lost
        // the network, this stops moving while the screen still looks fine.
        echo '<p style="margin:0 0 12px;">';
        echo '<strong>' . esc_html__('Last activity:', 'cik-prize-wheel') . '</strong> ';

        if ($last_activity) {
            $ago = human_time_diff(
                (int) mysql2date('U', (string) $last_activity),
                (int) current_time('timestamp')
            );
            printf(
                /* translators: 1: timestamp, 2: human readable time difference. */
                esc_html__('%1$s (%2$s ago)', 'cik-prize-wheel'),
                esc_html(mysql2date('d/m/Y H:i', (string) $last_activity)),
                esc_html($ago)
            );
        } else {
            echo esc_html__('none yet', 'cik-prize-wheel');
        }

        echo '</p>';

        if ($pending_emails > 0) {
            echo '<p style="margin:0 0 12px;">';
            echo '<strong>' . esc_html__('Last email run:', 'cik-prize-wheel') . '</strong> ';
            echo esc_html(
                $snapshot['last_queue_run']
                    ? mysql2date('d/m/Y H:i', (string) $snapshot['last_queue_run'])
                    : __('never', 'cik-prize-wheel')
            );
            echo '<br /><small>' . esc_html__('If this falls behind while emails are pending, WP-Cron is not running: use wp cikw flush-emails.', 'cik-prize-wheel') . '</small>';
            echo '</p>';
        }

        $this->render_prize_stock(array_slice($snapshot['prizes'], 0, 8));

        echo '<p style="margin:12px 0 0;">';
        echo '<a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=cikw-entries')) . '">'
            . esc_html__('Entries', 'cik-prize-wheel') . '</a> ';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=cikw-prizes')) . '">'
            . esc_html__('Prizes', 'cik-prize-wheel') . '</a>';
        echo '</p>';

        echo '</div>';
    }

    private function stat(string $label, string $value): void
    {
        echo '<li style="margin:0;display:flex;justify-content:space-between;gap:8px;border-bottom:1px solid #f0f0f1;padding:3px 0;">';
        echo '<span>' . esc_html($label) . '</span>';
        echo '<strong style="font-size:15px;">' . esc_html($value) . '</strong>';
        echo '</li>';
    }

    /**
     * Remaining stock per prize, from the shared snapshot.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function render_prize_stock(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        echo '<p style="margin:0 0 4px;"><strong>' . esc_html__('Prize stock (lowest first)', 'cik-prize-wheel') . '</strong></p>';
        echo '<table class="widefat striped" style="margin:0;">';
        echo '<tbody>';

        foreach ($rows as $row) {
            $remaining = (int) $row['stock_remaining'];
            $total = max(1, (int) $row['stock_total']);
            $ratio = $remaining / $total;
            $colour = 0 === $remaining ? '#b32d2e' : ($ratio < 0.2 ? '#bd8600' : '#0a7c2f');

            echo '<tr>';
            echo '<td style="padding:4px 8px;">' . esc_html((string) $row['title']);
            echo '<br /><small style="color:#787c82;">' . esc_html((string) $row['category']) . '</small></td>';
            echo '<td style="padding:4px 8px;text-align:right;white-space:nowrap;color:' . esc_attr($colour) . ';font-weight:600;">';
            echo esc_html($remaining . ' / ' . (int) $row['stock_total']);
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
}
