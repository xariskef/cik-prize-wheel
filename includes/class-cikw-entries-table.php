<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Prize Wheel > Entries.
 *
 * One row per participation, with its spins aggregated. The CSV export is one
 * row per spin - see CIKW_Export.
 */
final class CIKW_Entries_Table extends WP_List_Table
{
    private const PER_PAGE = 30;

    /** @var array<string, mixed> */
    private array $filters = [];

    public function __construct()
    {
        parent::__construct([
            'singular' => 'cikw_entry',
            'plural' => 'cikw_entries',
            'ajax' => false,
        ]);
    }

    public function get_columns(): array
    {
        return [
            'created_at' => __('Date', 'cik-prize-wheel'),
            'full_name' => __('Name', 'cik-prize-wheel'),
            'email' => __('Email', 'cik-prize-wheel'),
            'phone' => __('Phone', 'cik-prize-wheel'),
            'company' => __('Company', 'cik-prize-wheel'),
            'role' => __('Role', 'cik-prize-wheel'),
            'need' => __('Interest', 'cik-prize-wheel'),
            'spins' => __('Spins', 'cik-prize-wheel'),
            'outcome' => __('Outcome', 'cik-prize-wheel'),
            'prize' => __('Prize', 'cik-prize-wheel'),
            'voucher' => __('Code', 'cik-prize-wheel'),
            'redeemed' => __('Redemption', 'cik-prize-wheel'),
            'email_status' => __('Email', 'cik-prize-wheel'),
        ];
    }

    public function get_sortable_columns(): array
    {
        return [
            'created_at' => ['created_at', true],
            'full_name' => ['full_name', false],
            'email' => ['email', false],
            'company' => ['company', false],
            'email_status' => ['email_status', false],
        ];
    }

    public function prepare_items(): void
    {
        $this->filters = CIKW_Entries_Query::filters_from_request();

        $orderby = isset($_GET['orderby']) ? sanitize_key(wp_unslash((string) $_GET['orderby'])) : 'created_at';
        $order = isset($_GET['order']) ? sanitize_key(wp_unslash((string) $_GET['order'])) : 'desc';
        $page = $this->get_pagenum();

        $total = CIKW_Entries_Query::count($this->filters);

        $this->items = CIKW_Entries_Query::get_entries($this->filters, self::PER_PAGE, $page, $orderby, $order);
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page' => self::PER_PAGE,
            'total_pages' => (int) ceil($total / self::PER_PAGE),
        ]);
    }

    public function no_items(): void
    {
        esc_html_e('No entries found.', 'cik-prize-wheel');
    }

    /**
     * The filter controls above the table.
     */
    protected function extra_tablenav($which): void
    {
        if ('top' !== $which) {
            return;
        }

        $filters = $this->filters ?: CIKW_Entries_Query::filters_from_request();

        echo '<div class="alignleft actions cikw-entry-filters">';

        printf(
            '<input type="date" name="cikw_date_from" value="%s" aria-label="%s" /> ',
            esc_attr((string) $filters['date_from']),
            esc_attr__('From date', 'cik-prize-wheel')
        );
        printf(
            '<input type="date" name="cikw_date_to" value="%s" aria-label="%s" /> ',
            esc_attr((string) $filters['date_to']),
            esc_attr__('To date', 'cik-prize-wheel')
        );

        echo '<select name="cikw_category">';
        printf('<option value="0">%s</option>', esc_html__('All categories', 'cik-prize-wheel'));
        foreach (CIKW_Entries_Query::filter_categories() as $category) {
            printf(
                '<option value="%1$d" %2$s>%3$s</option>',
                (int) $category['id'],
                selected((int) $filters['category_id'], (int) $category['id'], false),
                esc_html((string) $category['name'])
            );
        }
        echo '</select> ';

        $this->render_select('cikw_outcome', (string) $filters['outcome'], [
            '' => __('Any outcome', 'cik-prize-wheel'),
            'win' => __('Won', 'cik-prize-wheel'),
            'lose' => __('Did not win', 'cik-prize-wheel'),
        ]);

        $this->render_select('cikw_redeemed', (string) $filters['redeemed'], [
            '' => __('Any redemption status', 'cik-prize-wheel'),
            'yes' => __('Redeemed', 'cik-prize-wheel'),
            'no' => __('Not yet redeemed', 'cik-prize-wheel'),
        ]);

        $this->render_select('cikw_email_status', (string) $filters['email_status'], [
            '' => __('Any email status', 'cik-prize-wheel'),
            'pending' => __('Pending', 'cik-prize-wheel'),
            'sent' => __('Sent', 'cik-prize-wheel'),
            'failed' => __('Failed', 'cik-prize-wheel'),
            'skipped' => __('Skipped', 'cik-prize-wheel'),
        ]);

        submit_button(__('Filter', 'cik-prize-wheel'), '', 'filter_action', false);

        echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=cikw-entries')) . '">'
            . esc_html__('Clear', 'cik-prize-wheel') . '</a>';

        echo '</div>';
    }

    /**
     * @param array<string, string> $choices
     */
    private function render_select(string $name, string $current, array $choices): void
    {
        echo '<select name="' . esc_attr($name) . '">';

        foreach ($choices as $value => $label) {
            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr((string) $value),
                selected($current, (string) $value, false),
                esc_html($label)
            );
        }

        echo '</select> ';
    }

    /** @param array<string, mixed> $item */
    public function column_created_at($item): string
    {
        $stamp = mysql2date('d/m/Y H:i', (string) $item['created_at']);

        $actions = [];

        if ('sent' === $item['email_status']) {
            $actions['resend'] = $this->action_link($item, __('Resend email', 'cik-prize-wheel'));
        } else {
            $actions['resend'] = $this->action_link($item, __('Send email now', 'cik-prize-wheel'));
        }

        return '<strong>' . esc_html($stamp) . '</strong>' . $this->row_actions($actions);
    }

    /** @param array<string, mixed> $item */
    private function action_link(array $item, string $label): string
    {
        $url = wp_nonce_url(
            add_query_arg(
                ['action' => 'cikw_resend_email', 'entry_id' => (int) $item['id']],
                admin_url('admin-post.php')
            ),
            'cikw_resend_email_' . (int) $item['id']
        );

        return '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    /** @param array<string, mixed> $item */
    public function column_full_name($item): string
    {
        return esc_html((string) $item['full_name']);
    }

    /** @param array<string, mixed> $item */
    public function column_email($item): string
    {
        return '<a href="mailto:' . esc_attr((string) $item['email']) . '">' . esc_html((string) $item['email']) . '</a>';
    }

    /** @param array<string, mixed> $item */
    public function column_spins($item): string
    {
        return esc_html((string) (int) $item['spin_count']);
    }

    /** @param array<string, mixed> $item */
    public function column_outcome($item): string
    {
        if (0 === (int) $item['spin_count']) {
            return '<span style="color:#787c82;">' . esc_html__('Did not play', 'cik-prize-wheel') . '</span>';
        }

        if ((int) $item['win_count'] > 0) {
            return '<span style="color:#0a7c2f;font-weight:600;">' . esc_html__('Won', 'cik-prize-wheel') . '</span>';
        }

        return esc_html__('Did not win', 'cik-prize-wheel');
    }

    /** @param array<string, mixed> $item */
    public function column_voucher($item): string
    {
        $codes = (string) ($item['voucher_codes'] ?? '');

        return '' === $codes ? '—' : '<code>' . esc_html($codes) . '</code>';
    }

    /** @param array<string, mixed> $item */
    public function column_redeemed($item): string
    {
        if (0 === (int) $item['win_count']) {
            return '—';
        }

        if (empty($item['redeemed_at'])) {
            return '<span style="color:#b32d2e;">' . esc_html__('Pending', 'cik-prize-wheel') . '</span>';
        }

        return esc_html(mysql2date('d/m/Y H:i', (string) $item['redeemed_at']));
    }

    /** @param array<string, mixed> $item */
    public function column_email_status($item): string
    {
        $labels = [
            'pending' => __('Pending', 'cik-prize-wheel'),
            'sent' => __('Sent', 'cik-prize-wheel'),
            'failed' => __('Failed', 'cik-prize-wheel'),
            'skipped' => __('Skipped', 'cik-prize-wheel'),
        ];

        $status = (string) $item['email_status'];
        $label = $labels[$status] ?? $status;
        $colour = 'failed' === $status ? '#b32d2e' : ('sent' === $status ? '#0a7c2f' : '#787c82');

        $out = '<span style="color:' . esc_attr($colour) . ';">' . esc_html($label) . '</span>';

        if ((int) $item['email_attempts'] > 1) {
            $out .= ' <small>(' . esc_html((string) (int) $item['email_attempts']) . ')</small>';
        }

        if ('failed' === $status && !empty($item['email_last_error'])) {
            $out .= '<br /><small title="' . esc_attr((string) $item['email_last_error']) . '">'
                . esc_html(mb_strimwidth((string) $item['email_last_error'], 0, 60, '…'))
                . '</small>';
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $item
     * @param string               $column_name
     */
    public function column_default($item, $column_name): string
    {
        $value = match ($column_name) {
            'need' => (string) ($item['need_category_name'] ?? ''),
            'prize' => (string) ($item['prize_titles'] ?? ''),
            default => (string) ($item[$column_name] ?? ''),
        };

        return '' === $value ? '—' : esc_html($value);
    }
}
