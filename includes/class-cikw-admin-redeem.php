<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Prize Wheel > Redemption.
 *
 * The screen booth staff actually use: type the code a visitor is holding,
 * see which prize it is and whether it has already been handed over, and
 * mark it redeemed. Below it, the full list of vouchers so the question
 * "what is still outstanding?" has an answer without hunting.
 *
 * Designed for a phone or tablet at the stand, so the lookup field and the
 * confirm button are deliberately oversized.
 */
final class CIKW_Admin_Redeem
{
    private const PER_PAGE = 50;

    public function init(): void
    {
        add_action('admin_menu', [$this, 'register_menu'], 11);
        add_action('admin_post_cikw_redeem_voucher', [$this, 'handle_redeem']);
        add_action('admin_post_cikw_unredeem_voucher', [$this, 'handle_unredeem']);
    }

    public function register_menu(): void
    {
        add_submenu_page(
            'cikw-dashboard',
            __('Redemption', 'cik-prize-wheel'),
            __('Redemption', 'cik-prize-wheel'),
            'manage_options',
            'cikw-redeem',
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        $this->require_manager();

        $code = isset($_GET['cikw_code']) ? CIKW_Vouchers::normalise(wp_unslash((string) $_GET['cikw_code'])) : '';
        $status = isset($_GET['cikw_status']) ? sanitize_key(wp_unslash((string) $_GET['cikw_status'])) : 'pending';
        $status = in_array($status, ['all', 'pending', 'redeemed'], true) ? $status : 'pending';
        $search = isset($_GET['cikw_search']) ? sanitize_text_field(wp_unslash((string) $_GET['cikw_search'])) : '';
        $page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;

        echo '<div class="wrap cikw-admin-page">';
        echo '<h1>' . esc_html__('Prize redemption', 'cik-prize-wheel') . '</h1>';

        $this->render_notice();
        $this->render_summary();
        $this->render_lookup($code);
        $this->render_list($status, $search, $page);

        echo '</div>';
    }

    /* --------------------------------------------------------------------
     * Lookup
     * ----------------------------------------------------------------- */

    private function render_lookup(string $code): void
    {
        echo '<div class="cikw-redeem-box">';
        echo '<h2>' . esc_html__('Check a code', 'cik-prize-wheel') . '</h2>';

        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="cikw-redeem-lookup">';
        echo '<input type="hidden" name="page" value="cikw-redeem" />';
        printf(
            '<input type="text" name="cikw_code" value="%1$s" maxlength="12" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="%2$s" aria-label="%2$s" style="text-transform:uppercase;font-size:28px;letter-spacing:6px;padding:10px 14px;width:260px;font-family:monospace;" autofocus />',
            esc_attr($code),
            esc_attr__('Code', 'cik-prize-wheel')
        );
        submit_button(__('Search', 'cik-prize-wheel'), 'primary large', 'submit', false);
        echo '</form>';

        if ('' === $code) {
            echo '<p class="description">' . esc_html__('Codes have 6 characters and never contain 0, 1, O, I or L.', 'cik-prize-wheel') . '</p>';
            echo '</div>';

            return;
        }

        $voucher = CIKW_Vouchers::find($code);

        if (null === $voucher) {
            echo '<div class="notice notice-error inline"><p><strong>'
                . esc_html(sprintf(
                    /* translators: %s: voucher code. */
                    __('Code %s was not found.', 'cik-prize-wheel'),
                    $code
                ))
                . '</strong>';

            if (CIKW_Vouchers::has_ambiguous_characters($code)) {
                echo '<br />' . esc_html__('The code contains 0, 1, O, I or L, which are never used. Please check those characters again.', 'cik-prize-wheel');
            }

            echo '</p></div>';
            echo '</div>';

            return;
        }

        $redeemed = null !== $voucher['redeemed_at'];

        echo '<div class="cikw-redeem-result" style="border:3px solid ' . ($redeemed ? '#b32d2e' : '#0a7c2f') . ';border-radius:8px;padding:16px;margin-top:12px;background:#fff;">';

        echo '<p style="margin:0 0 6px;font-size:13px;text-transform:uppercase;letter-spacing:1px;color:#646970;">'
            . esc_html__('Prize', 'cik-prize-wheel') . '</p>';
        echo '<h3 style="margin:0 0 10px;font-size:24px;">' . esc_html((string) $voucher['prize_title']) . '</h3>';

        $image = CIKW_Config::image_url($voucher['prize_image_id'], 'thumbnail');

        if ($image) {
            echo '<img src="' . esc_url($image) . '" alt="" style="max-width:120px;height:auto;border-radius:6px;float:right;margin-left:16px;" />';
        }

        if (!empty($voucher['prize_description'])) {
            echo '<p style="margin:0 0 10px;">' . esc_html((string) $voucher['prize_description']) . '</p>';
        }

        echo '<table class="widefat striped" style="max-width:520px;margin:10px 0;"><tbody>';
        $this->row(__('Code', 'cik-prize-wheel'), '<code style="font-size:18px;letter-spacing:3px;">' . esc_html((string) $voucher['voucher_code']) . '</code>');
        $this->row(__('Category', 'cik-prize-wheel'), esc_html((string) $voucher['category_name']));
        $this->row(__('Winner', 'cik-prize-wheel'), esc_html((string) $voucher['full_name']));
        $this->row(__('Email', 'cik-prize-wheel'), esc_html((string) $voucher['email']));
        $this->row(__('Phone', 'cik-prize-wheel'), esc_html((string) $voucher['phone']));
        $this->row(__('Won on', 'cik-prize-wheel'), esc_html(mysql2date('d/m/Y H:i', (string) $voucher['won_at'])));
        echo '</tbody></table>';

        if ($redeemed) {
            echo '<p style="font-size:18px;color:#b32d2e;font-weight:600;margin:12px 0;">';
            echo esc_html(sprintf(
                /* translators: 1: date, 2: staff name. */
                __('ALREADY REDEEMED on %1$s by %2$s', 'cik-prize-wheel'),
                mysql2date('d/m/Y H:i', (string) $voucher['redeemed_at']),
                '' !== (string) $voucher['redeemed_by'] ? (string) $voucher['redeemed_by'] : __('—', 'cik-prize-wheel')
            ));
            echo '</p>';

            $this->render_unredeem_form((string) $voucher['voucher_code']);
        } else {
            $this->render_redeem_form((string) $voucher['voucher_code']);
        }

        echo '</div>';
        echo '</div>';
    }

    private function render_redeem_form(string $code): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;">';
        wp_nonce_field('cikw_redeem_voucher');
        echo '<input type="hidden" name="action" value="cikw_redeem_voucher" />';
        echo '<input type="hidden" name="voucher_code" value="' . esc_attr($code) . '" />';
        echo '<label style="display:inline-block;margin-right:10px;">'
            . esc_html__('Staff name or initials', 'cik-prize-wheel')
            . ' <input type="text" name="staff" maxlength="100" style="width:160px;" /></label> ';
        echo '<button type="submit" class="button button-primary button-hero">'
            . esc_html__('Hand over prize', 'cik-prize-wheel') . '</button>';
        echo '</form>';
    }

    private function render_unredeem_form(string $code): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('cikw_unredeem_voucher');
        echo '<input type="hidden" name="action" value="cikw_unredeem_voucher" />';
        echo '<input type="hidden" name="voucher_code" value="' . esc_attr($code) . '" />';
        printf(
            '<button type="submit" class="button" onclick="return confirm(%s)">%s</button>',
            esc_attr(wp_json_encode(__('Undo this redemption? Only use this if it was recorded by mistake.', 'cik-prize-wheel'))),
            esc_html__('Undo redemption', 'cik-prize-wheel')
        );
        echo '</form>';
    }

    /**
     * @param string $value Already-escaped HTML; every caller escapes it.
     */
    private function row(string $label, string $value): void
    {
        echo '<tr><td style="width:150px;color:#646970;">' . esc_html($label) . '</td><td><strong>' . $value . '</strong></td></tr>';
    }

    /* --------------------------------------------------------------------
     * Summary + list
     * ----------------------------------------------------------------- */

    private function render_summary(): void
    {
        $summary = CIKW_Vouchers::summary();

        echo '<div class="cikw-redeem-summary" style="display:flex;gap:16px;flex-wrap:wrap;margin:12px 0;">';

        foreach ([
            __('Prizes in total', 'cik-prize-wheel') => [$summary['total'], '#1d2327'],
            __('Handed over', 'cik-prize-wheel') => [$summary['redeemed'], '#0a7c2f'],
            __('Pending', 'cik-prize-wheel') => [$summary['pending'], '#b32d2e'],
        ] as $label => [$value, $colour]) {
            echo '<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:10px 18px;min-width:130px;">';
            echo '<div style="font-size:12px;color:#646970;text-transform:uppercase;letter-spacing:1px;">' . esc_html($label) . '</div>';
            echo '<div style="font-size:28px;font-weight:700;color:' . esc_attr($colour) . ';">' . esc_html((string) $value) . '</div>';
            echo '</div>';
        }

        echo '</div>';

        if (empty($summary['by_prize'])) {
            return;
        }

        echo '<details style="margin:0 0 16px;"><summary style="cursor:pointer;">'
            . esc_html__('Breakdown by prize', 'cik-prize-wheel') . '</summary>';
        echo '<table class="widefat striped" style="max-width:560px;margin-top:8px;"><thead><tr>';
        echo '<th>' . esc_html__('Prize', 'cik-prize-wheel') . '</th>';
        echo '<th style="width:110px;">' . esc_html__('Won', 'cik-prize-wheel') . '</th>';
        echo '<th style="width:110px;">' . esc_html__('Handed over', 'cik-prize-wheel') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($summary['by_prize'] as $row) {
            $outstanding = (int) $row['won'] - (int) $row['redeemed'];
            echo '<tr>';
            echo '<td>' . esc_html((string) $row['title']) . '</td>';
            echo '<td>' . esc_html((string) (int) $row['won']) . '</td>';
            echo '<td>' . esc_html((string) (int) $row['redeemed']);
            if ($outstanding > 0) {
                echo ' <small style="color:#b32d2e;">(' . esc_html((string) $outstanding) . ' ' . esc_html__('pending', 'cik-prize-wheel') . ')</small>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></details>';
    }

    private function render_list(string $status, string $search, int $page): void
    {
        $total = CIKW_Vouchers::count($status, $search);
        $rows = CIKW_Vouchers::all($status, $search, self::PER_PAGE, $page);
        $pages = (int) ceil($total / self::PER_PAGE);

        echo '<h2>' . esc_html__('All codes', 'cik-prize-wheel') . '</h2>';

        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin-bottom:10px;">';
        echo '<input type="hidden" name="page" value="cikw-redeem" />';
        echo '<select name="cikw_status">';
        foreach ([
            'pending' => __('Pending', 'cik-prize-wheel'),
            'redeemed' => __('Handed over', 'cik-prize-wheel'),
            'all' => __('All', 'cik-prize-wheel'),
        ] as $value => $label) {
            printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr($value), selected($status, $value, false), esc_html($label));
        }
        echo '</select> ';
        printf(
            '<input type="search" name="cikw_search" value="%1$s" placeholder="%2$s" />',
            esc_attr($search),
            esc_attr__('Code, name or prize', 'cik-prize-wheel')
        );
        submit_button(__('Filter', 'cik-prize-wheel'), '', 'submit', false);
        echo '</form>';

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th style="width:110px;">' . esc_html__('Code', 'cik-prize-wheel') . '</th>';
        echo '<th>' . esc_html__('Prize', 'cik-prize-wheel') . '</th>';
        echo '<th>' . esc_html__('Winner', 'cik-prize-wheel') . '</th>';
        echo '<th style="width:140px;">' . esc_html__('Won on', 'cik-prize-wheel') . '</th>';
        echo '<th style="width:200px;">' . esc_html__('Status', 'cik-prize-wheel') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($rows)) {
            echo '<tr><td colspan="5">' . esc_html__('No codes found.', 'cik-prize-wheel') . '</td></tr>';
        }

        foreach ($rows as $row) {
            $lookup = add_query_arg(
                ['page' => 'cikw-redeem', 'cikw_code' => (string) $row['voucher_code']],
                admin_url('admin.php')
            );

            echo '<tr>';
            echo '<td><a href="' . esc_url($lookup) . '"><code style="font-size:15px;letter-spacing:2px;">'
                . esc_html((string) $row['voucher_code']) . '</code></a></td>';
            echo '<td>' . esc_html((string) $row['prize_title']) . '</td>';
            echo '<td>' . esc_html((string) $row['full_name']) . '<br /><small>' . esc_html((string) $row['email']) . '</small></td>';
            echo '<td>' . esc_html(mysql2date('d/m/Y H:i', (string) $row['won_at'])) . '</td>';
            echo '<td>';

            if (null === $row['redeemed_at']) {
                echo '<span style="color:#b32d2e;font-weight:600;">' . esc_html__('Pending', 'cik-prize-wheel') . '</span>';
            } else {
                echo '<span style="color:#0a7c2f;font-weight:600;">' . esc_html__('Handed over', 'cik-prize-wheel') . '</span>';
                echo '<br /><small>' . esc_html(mysql2date('d/m/Y H:i', (string) $row['redeemed_at']));
                if ('' !== (string) $row['redeemed_by']) {
                    echo ' — ' . esc_html((string) $row['redeemed_by']);
                }
                echo '</small>';
            }

            echo '</td></tr>';
        }

        echo '</tbody></table>';

        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo wp_kses_post((string) paginate_links([
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'current' => $page,
                'total' => $pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ]));
            echo '</div></div>';
        }
    }

    /* --------------------------------------------------------------------
     * Actions
     * ----------------------------------------------------------------- */

    public function handle_redeem(): void
    {
        $this->require_manager();
        check_admin_referer('cikw_redeem_voucher');

        $code = CIKW_Vouchers::normalise(wp_unslash((string) ($_POST['voucher_code'] ?? '')));
        $staff = sanitize_text_field(wp_unslash((string) ($_POST['staff'] ?? '')));

        $result = CIKW_Vouchers::redeem($code, $staff);

        if (is_wp_error($result)) {
            $this->redirect(['cikw_code' => $code, 'cikw_message' => 'redeem_failed']);
        }

        $this->redirect(['cikw_code' => $code, 'cikw_message' => 'redeemed']);
    }

    public function handle_unredeem(): void
    {
        $this->require_manager();
        check_admin_referer('cikw_unredeem_voucher');

        $code = CIKW_Vouchers::normalise(wp_unslash((string) ($_POST['voucher_code'] ?? '')));
        CIKW_Vouchers::unredeem($code);

        $this->redirect(['cikw_code' => $code, 'cikw_message' => 'unredeemed']);
    }

    private function render_notice(): void
    {
        $message = isset($_GET['cikw_message']) ? sanitize_key(wp_unslash((string) $_GET['cikw_message'])) : '';

        $notices = [
            'redeemed' => ['success', __('Prize handed over and recorded.', 'cik-prize-wheel')],
            'redeem_failed' => ['error', __('The redemption did not go through. See the code status below.', 'cik-prize-wheel')],
            'unredeemed' => ['warning', __('Redemption undone. The code counts as pending again.', 'cik-prize-wheel')],
        ];

        if (!isset($notices[$message])) {
            return;
        }

        [$type, $text] = $notices[$message];
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($text) . '</p></div>';
    }

    /** @param array<string, string> $args */
    private function redirect(array $args): void
    {
        wp_safe_redirect(add_query_arg(array_merge(['page' => 'cikw-redeem'], $args), admin_url('admin.php')));
        exit;
    }

    private function require_manager(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'cik-prize-wheel'));
        }
    }
}
