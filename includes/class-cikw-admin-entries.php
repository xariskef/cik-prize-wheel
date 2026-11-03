<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Έκθεση → Συμμετοχές: the list screen, the export button and the
 * per-row resend action.
 */
final class CIKW_Admin_Entries
{
    public function init(): void
    {
        add_action('admin_menu', [$this, 'register_menu'], 12);
        add_action('admin_post_cikw_export_entries', [CIKW_Export::class, 'handle']);
        add_action('admin_post_cikw_resend_email', [$this, 'handle_resend']);
    }

    public function register_menu(): void
    {
        add_submenu_page(
            'cikw-dashboard',
            __('Συμμετοχές', 'cik-prize-wheel'),
            __('Συμμετοχές', 'cik-prize-wheel'),
            'manage_options',
            'cikw-entries',
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Δεν έχετε δικαίωμα πρόσβασης.', 'cik-prize-wheel'));
        }

        $table = new CIKW_Entries_Table();
        $table->prepare_items();

        $filters = CIKW_Entries_Query::filters_from_request();
        $active = CIKW_Entries_Query::active_filter_count($filters);

        echo '<div class="wrap cikw-admin-page">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Συμμετοχές', 'cik-prize-wheel') . '</h1>';
        $this->render_notice();

        // The export carries the current filters through, so what you see is
        // what you get (spec section 6.4).
        $export_url = wp_nonce_url(
            add_query_arg(
                array_merge(
                    ['action' => 'cikw_export_entries'],
                    array_filter([
                        's' => $filters['search'],
                        'cikw_date_from' => $filters['date_from'],
                        'cikw_date_to' => $filters['date_to'],
                        'cikw_category' => $filters['category_id'] ?: '',
                        'cikw_outcome' => $filters['outcome'],
                        'cikw_redeemed' => $filters['redeemed'],
                        'cikw_email_status' => $filters['email_status'],
                    ])
                ),
                admin_url('admin-post.php')
            ),
            'cikw_export_entries'
        );

        echo '<a href="' . esc_url($export_url) . '" class="page-title-action">'
            . esc_html__('Εξαγωγή CSV', 'cik-prize-wheel') . '</a>';

        if ($active > 0) {
            echo '<p class="description">' . esc_html__('Η εξαγωγή ακολουθεί τα ενεργά φίλτρα. Μία γραμμή ανά περιστροφή.', 'cik-prize-wheel') . '</p>';
        } else {
            echo '<p class="description">' . esc_html__('Η εξαγωγή περιλαμβάνει όλες τις συμμετοχές. Μία γραμμή ανά περιστροφή.', 'cik-prize-wheel') . '</p>';
        }

        echo '<hr class="wp-header-end" />';

        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="cikw-entries" />';
        $table->search_box(__('Αναζήτηση', 'cik-prize-wheel'), 'cikw-entry-search');
        $table->display();
        echo '</form>';

        echo '</div>';
    }

    public function handle_resend(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Δεν έχετε δικαίωμα πρόσβασης.', 'cik-prize-wheel'));
        }

        $entry_id = isset($_GET['entry_id']) ? absint($_GET['entry_id']) : 0;
        check_admin_referer('cikw_resend_email_' . $entry_id);

        if ($entry_id <= 0) {
            $this->redirect(['cikw_message' => 'resend_failed']);
        }

        // force: the admin asked for it explicitly, so send even when the
        // entry is already marked sent or has burned through its attempts.
        $result = CIKW_Mailer::send_for_entry($entry_id, true);

        if (is_wp_error($result)) {
            // The error text goes through a short-lived per-user transient
            // rather than the URL, so a crafted link cannot put arbitrary
            // text into an admin notice.
            set_transient(self::error_transient_key(), $result->get_error_message(), MINUTE_IN_SECONDS);
            $this->redirect(['cikw_message' => 'resend_failed']);
        }

        $this->redirect(['cikw_message' => 'resent']);
    }

    private function render_notice(): void
    {
        $message = isset($_GET['cikw_message']) ? sanitize_key(wp_unslash((string) $_GET['cikw_message'])) : '';

        if ('resent' === $message) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Το email στάλθηκε.', 'cik-prize-wheel') . '</p></div>';

            return;
        }

        if ('resend_failed' === $message) {
            $error = (string) get_transient(self::error_transient_key());
            delete_transient(self::error_transient_key());

            echo '<div class="notice notice-error is-dismissible"><p>'
                . esc_html__('Η αποστολή απέτυχε.', 'cik-prize-wheel')
                . ('' !== $error ? ' ' . esc_html($error) : '')
                . '</p></div>';
        }
    }

    private static function error_transient_key(): string
    {
        return 'cikw_resend_error_' . get_current_user_id();
    }

    /** @param array<string, string> $args */
    private function redirect(array $args): void
    {
        wp_safe_redirect(add_query_arg(array_merge(['page' => 'cikw-entries'], $args), admin_url('admin.php')));
        exit;
    }
}
