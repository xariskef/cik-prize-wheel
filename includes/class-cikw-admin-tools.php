<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Prize Wheel > Test tools.
 *
 * Everything tools/seed-demo-data.php does, but from wp-admin - the show is
 * deployed to managed hosting where there may be no shell access.
 *
 * These actions destroy data, so each one is behind a nonce, a capability
 * check, a JavaScript confirm, and - for the two that can delete real
 * participants - an explicit "I understand" checkbox in the same submission.
 */
final class CIKW_Admin_Tools
{
    /** A single web request cannot simulate an unbounded number of visitors. */
    private const MAX_SIMULATED_VISITORS = 200;

    public function init(): void
    {
        add_action('admin_menu', [$this, 'register_menu'], 30);
        add_action('admin_post_cikw_seed_demo_data', [$this, 'handle_seed']);
        add_action('admin_post_cikw_reset_wheel', [$this, 'handle_reset_wheel']);
        add_action('admin_post_cikw_simulate_visitors', [$this, 'handle_simulate']);
        add_action('admin_post_cikw_delete_demo_entries', [$this, 'handle_delete_demo_entries']);
        add_action('admin_post_cikw_reset_all_stock', [$this, 'handle_reset_all_stock']);
        add_action('admin_post_cikw_wipe_all', [$this, 'handle_wipe_all']);
    }

    public function register_menu(): void
    {
        add_submenu_page(
            'cikw-dashboard',
            __('Test tools', 'cik-prize-wheel'),
            __('Test tools', 'cik-prize-wheel'),
            'manage_options',
            'cikw-tools',
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        $this->require_manager();

        $counts = CIKW_Demo_Data::counts();
        $real_entries = $counts['entries'] - $counts['demo_entries'];

        echo '<div class="wrap cikw-admin-page">';
        echo '<h1>' . esc_html__('Test tools', 'cik-prize-wheel') . '</h1>';

        echo '<div class="notice notice-warning"><p><strong>' . esc_html__('For testing only.', 'cik-prize-wheel') . '</strong> ';
        echo esc_html__('Do not use this page while the event is running. These actions permanently delete data.', 'cik-prize-wheel');
        echo '</p></div>';

        $this->render_message_notice();

        if ($real_entries > 0) {
            echo '<div class="notice notice-error"><p>';
            printf(
                /* translators: %d: number of real participant entries. */
                esc_html__('Warning: there are %d real entries in the database. The reset and delete-everything actions will remove them.', 'cik-prize-wheel'),
                (int) $real_entries
            );
            echo '</p></div>';
        }

        $this->render_status($counts, $real_entries);
        $this->render_wheel_section($counts);
        $this->render_visitors_section($counts);
        $this->render_stock_section();
        $this->render_keys_section();
        $this->render_edge_cases();
        $this->render_danger_section();

        echo '</div>';
    }

    private function render_status(array $counts, int $real_entries): void
    {
        echo '<h2>' . esc_html__('Status', 'cik-prize-wheel') . '</h2>';
        echo '<table class="widefat striped" style="max-width:620px">';
        echo '<tbody>';

        $rows = [
            __('Categories', 'cik-prize-wheel') => $counts['categories'],
            __('Prizes', 'cik-prize-wheel') => $counts['prizes'],
            __('Entries (total)', 'cik-prize-wheel') => $counts['entries'],
            __('- of which simulated', 'cik-prize-wheel') => $counts['demo_entries'],
            __('- of which real', 'cik-prize-wheel') => $real_entries,
            __('Spins', 'cik-prize-wheel') => $counts['spins'],
            __('Prizes won', 'cik-prize-wheel') => $counts['wins'],
        ];

        foreach ($rows as $label => $value) {
            echo '<tr><td>' . esc_html($label) . '</td><td><strong>' . esc_html((string) $value) . '</strong></td></tr>';
        }

        if ($counts['spins'] > 0) {
            echo '<tr><td>' . esc_html__('Win rate', 'cik-prize-wheel') . '</td><td><strong>';
            echo esc_html(number_format(100 * $counts['wins'] / $counts['spins'], 1) . '%');
            echo '</strong></td></tr>';
        }

        echo '</tbody></table>';
    }

    private function render_wheel_section(array $counts): void
    {
        echo '<h2>' . esc_html__('Wheel data', 'cik-prize-wheel') . '</h2>';

        if (0 === $counts['categories']) {
            echo '<p>' . esc_html__('The wheel is empty. Load the demo dataset to get started.', 'cik-prize-wheel') . '</p>';
            $this->render_action_button(
                'cikw_seed_demo_data',
                __('Load demo data (12 categories, 48 prizes)', 'cik-prize-wheel'),
                'button-primary'
            );

            return;
        }

        echo '<p>';
        printf(
            /* translators: 1: category count, 2: prize count. */
            esc_html__('The wheel already has %1$d categories and %2$d prizes. Resetting deletes them all, together with entries and spins, and reloads the demo dataset from scratch.', 'cik-prize-wheel'),
            (int) $counts['categories'],
            (int) $counts['prizes']
        );
        echo '</p>';

        $this->render_action_button(
            'cikw_reset_wheel',
            __('Reset wheel & reload demo data', 'cik-prize-wheel'),
            'button-secondary',
            __('ALL categories, prizes, entries and spins will be deleted. Continue?', 'cik-prize-wheel'),
            true
        );
    }

    private function render_visitors_section(array $counts): void
    {
        echo '<h2>' . esc_html__('Simulated entries', 'cik-prize-wheel') . '</h2>';
        echo '<p>' . esc_html__('Creates simulated visitors and runs them through the real spin engine, producing genuine spins, voucher codes and decision logs.', 'cik-prize-wheel') . '</p>';
        echo '<p class="description">' . esc_html__('Simulated addresses use @example.test and are marked "skipped", so no email is ever sent to them.', 'cik-prize-wheel') . '</p>';

        if (0 === $counts['categories']) {
            echo '<p><em>' . esc_html__('Load wheel data first.', 'cik-prize-wheel') . '</em></p>';

            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:12px 0">';
        wp_nonce_field('cikw_simulate_visitors');
        echo '<input type="hidden" name="action" value="cikw_simulate_visitors" />';
        echo '<label>' . esc_html__('Number of visitors', 'cik-prize-wheel') . ' ';
        printf(
            '<input type="number" name="visitor_count" value="50" min="1" max="%1$d" class="small-text" /></label> ',
            (int) self::MAX_SIMULATED_VISITORS
        );
        submit_button(__('Create', 'cik-prize-wheel'), 'button-primary', 'submit', false);
        echo '<p class="description">';
        printf(
            /* translators: %d: maximum simulated visitors per run. */
            esc_html__('Maximum %d per run, so the request does not time out. Run it again for more.', 'cik-prize-wheel'),
            (int) self::MAX_SIMULATED_VISITORS
        );
        echo '</p>';
        echo '</form>';

        if ($counts['demo_entries'] > 0) {
            $this->render_action_button(
                'cikw_delete_demo_entries',
                sprintf(
                    /* translators: %d: number of simulated entries. */
                    __('Delete %d simulated entries', 'cik-prize-wheel'),
                    (int) $counts['demo_entries']
                ),
                'button-secondary',
                __('Only simulated entries will be deleted. Real entries are not touched. Continue?', 'cik-prize-wheel')
            );
            echo '<p class="description">' . esc_html__('Safe: only affects rows with device_id "demo-seed".', 'cik-prize-wheel') . '</p>';
        }
    }

    private function render_stock_section(): void
    {
        echo '<h2>' . esc_html__('Prize stock', 'cik-prize-wheel') . '</h2>';
        echo '<p>' . esc_html__('Resets every prize’s remaining stock to its total, without deleting entries. Useful after a dry run.', 'cik-prize-wheel') . '</p>';

        $this->render_action_button(
            'cikw_reset_all_stock',
            __('Reset stock on all prizes', 'cik-prize-wheel'),
            'button-secondary',
            __('Remaining stock on every prize will be reset to its total. Continue?', 'cik-prize-wheel')
        );
    }

    private function render_keys_section(): void
    {
        echo '<h2>' . esc_html__('Keys & endpoints', 'cik-prize-wheel') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';

        $fields = [
            __('Kiosk key', 'cik-prize-wheel') => CIKW_Settings::kiosk_key(),
            __('Staff key', 'cik-prize-wheel') => CIKW_Settings::staff_key(),
            __('REST base', 'cik-prize-wheel') => rest_url(CIKW_REST::NAMESPACE . '/'),
        ];

        foreach ($fields as $label => $value) {
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td>';
            echo '<input type="text" class="large-text code" readonly value="' . esc_attr((string) $value) . '" onfocus="this.select()" />';
            echo '</td></tr>';
        }

        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__('The kiosk key ships inside the kiosk page and is not a secret. The staff key, required for redemption, is never shown publicly.', 'cik-prize-wheel') . '</p>';
    }

    private function render_edge_cases(): void
    {
        echo '<h2>' . esc_html__('What to look for in the demo dataset', 'cik-prize-wheel') . '</h2>';
        echo '<p>' . esc_html__('The data is deliberately uneven, so a dry run exercises every branch of the engine:', 'cik-prize-wheel') . '</p>';
        echo '<ul class="ul-disc">';

        foreach (CIKW_Demo_Data::edge_case_notes() as $note) {
            echo '<li><code>' . esc_html($note) . '</code></li>';
        }

        echo '</ul>';
    }

    private function render_danger_section(): void
    {
        echo '<hr />';
        echo '<h2 style="color:#b32d2e">' . esc_html__('Delete everything', 'cik-prize-wheel') . '</h2>';
        echo '<p>' . esc_html__('Deletes categories, prizes, entries and spins without reloading anything. The wheel is left empty.', 'cik-prize-wheel') . '</p>';

        $this->render_action_button(
            'cikw_wipe_all',
            __('Delete all data', 'cik-prize-wheel'),
            'button-link-delete',
            __('ALL wheel data will be deleted, including real entries. This cannot be undone. Continue?', 'cik-prize-wheel'),
            true
        );
    }

    /**
     * One button, one form, one nonce.
     *
     * @param string $action  admin-post action name, also the nonce name.
     * @param string $confirm JavaScript confirm text; empty for no prompt.
     * @param bool   $guard   Require an explicit "I understand" checkbox too.
     */
    private function render_action_button(
        string $action,
        string $label,
        string $class = 'button-secondary',
        string $confirm = '',
        bool $guard = false
    ): void {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:12px 0">';
        wp_nonce_field($action);
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '" />';

        if ($guard) {
            echo '<p><label><input type="checkbox" name="confirm_destructive" value="1" /> ';
            echo esc_html__('I understand that data will be permanently deleted.', 'cik-prize-wheel');
            echo '</label></p>';
        }

        printf(
            '<button type="submit" class="button %1$s"%2$s>%3$s</button>',
            esc_attr($class),
            '' === $confirm ? '' : ' onclick="return confirm(' . esc_attr(wp_json_encode($confirm)) . ')"',
            esc_html($label)
        );

        echo '</form>';
    }

    /* --------------------------------------------------------------------
     * Handlers
     * ----------------------------------------------------------------- */

    public function handle_seed(): void
    {
        $this->require_manager();
        check_admin_referer('cikw_seed_demo_data');

        $result = CIKW_Demo_Data::install(false);

        if ($result['skipped']) {
            $this->redirect(['cikw_message' => 'already_seeded']);
        }

        $this->redirect([
            'cikw_message' => 'seeded',
            'cikw_categories' => $result['categories'],
            'cikw_prizes' => $result['prizes'],
        ]);
    }

    public function handle_reset_wheel(): void
    {
        $this->require_manager();
        check_admin_referer('cikw_reset_wheel');
        $this->require_destructive_confirmation();

        $result = CIKW_Demo_Data::install(true);

        $this->redirect([
            'cikw_message' => 'reset',
            'cikw_categories' => $result['categories'],
            'cikw_prizes' => $result['prizes'],
        ]);
    }

    public function handle_simulate(): void
    {
        $this->require_manager();
        check_admin_referer('cikw_simulate_visitors');

        $count = isset($_POST['visitor_count']) ? absint(wp_unslash($_POST['visitor_count'])) : 0;
        $count = max(1, min(self::MAX_SIMULATED_VISITORS, $count));

        // Each visitor costs a handful of queries; give the request room.
        // Hosts often disable set_time_limit, hence the guard and the cap on
        // MAX_SIMULATED_VISITORS rather than relying on this working.
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $result = CIKW_Demo_Data::simulate_visitors($count);

        $this->redirect([
            'cikw_message' => 'simulated',
            'cikw_visitors' => $result['visitors'],
            'cikw_spins' => $result['spins'],
            'cikw_wins' => $result['wins'],
        ]);
    }

    public function handle_delete_demo_entries(): void
    {
        $this->require_manager();
        check_admin_referer('cikw_delete_demo_entries');

        $deleted = CIKW_Demo_Data::delete_demo_entries();

        $this->redirect(['cikw_message' => 'demo_entries_deleted', 'cikw_deleted' => $deleted]);
    }

    public function handle_reset_all_stock(): void
    {
        $this->require_manager();
        check_admin_referer('cikw_reset_all_stock');

        $updated = CIKW_Demo_Data::reset_all_stock();

        $this->redirect(['cikw_message' => 'stock_reset', 'cikw_updated' => $updated]);
    }

    public function handle_wipe_all(): void
    {
        $this->require_manager();
        check_admin_referer('cikw_wipe_all');
        $this->require_destructive_confirmation();

        CIKW_Demo_Data::wipe();

        $this->redirect(['cikw_message' => 'wiped']);
    }

    /* --------------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------- */

    /**
     * The checkbox is a second, deliberate action - a nonce alone only proves
     * the request came from this page, not that the click was intentional.
     */
    private function require_destructive_confirmation(): void
    {
        if (empty($_POST['confirm_destructive'])) {
            $this->redirect(['cikw_message' => 'not_confirmed']);
        }
    }

    private function render_message_notice(): void
    {
        $message = isset($_GET['cikw_message']) ? sanitize_key(wp_unslash((string) $_GET['cikw_message'])) : '';

        if ('' === $message) {
            return;
        }

        $categories = isset($_GET['cikw_categories']) ? absint($_GET['cikw_categories']) : 0;
        $prizes = isset($_GET['cikw_prizes']) ? absint($_GET['cikw_prizes']) : 0;
        $visitors = isset($_GET['cikw_visitors']) ? absint($_GET['cikw_visitors']) : 0;
        $spins = isset($_GET['cikw_spins']) ? absint($_GET['cikw_spins']) : 0;
        $wins = isset($_GET['cikw_wins']) ? absint($_GET['cikw_wins']) : 0;
        $deleted = isset($_GET['cikw_deleted']) ? absint($_GET['cikw_deleted']) : 0;
        $updated = isset($_GET['cikw_updated']) ? absint($_GET['cikw_updated']) : 0;

        switch ($message) {
            case 'seeded':
            case 'reset':
                $type = 'success';
                $text = sprintf(
                    /* translators: 1: category count, 2: prize count. */
                    __('Loaded %1$d categories and %2$d prizes.', 'cik-prize-wheel'),
                    $categories,
                    $prizes
                );
                break;

            case 'already_seeded':
                $type = 'info';
                $text = __('The wheel already has data. Nothing was changed.', 'cik-prize-wheel');
                break;

            case 'simulated':
                $type = 'success';
                $text = sprintf(
                    /* translators: 1: visitors, 2: spins, 3: wins. */
                    __('Created %1$d simulated visitors, %2$d spins, %3$d prizes won.', 'cik-prize-wheel'),
                    $visitors,
                    $spins,
                    $wins
                );
                break;

            case 'demo_entries_deleted':
                $type = 'success';
                $text = sprintf(
                    /* translators: %d: number of entries deleted. */
                    __('Deleted %d simulated entries.', 'cik-prize-wheel'),
                    $deleted
                );
                break;

            case 'stock_reset':
                $type = 'success';
                $text = sprintf(
                    /* translators: %d: number of prizes updated. */
                    __('Stock reset on %d prizes.', 'cik-prize-wheel'),
                    $updated
                );
                break;

            case 'wiped':
                $type = 'success';
                $text = __('All data deleted.', 'cik-prize-wheel');
                break;

            case 'not_confirmed':
                $type = 'error';
                $text = __('The action was not confirmed. Nothing was changed.', 'cik-prize-wheel');
                break;

            default:
                return;
        }

        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($text) . '</p></div>';
    }

    private function redirect(array $args): void
    {
        wp_safe_redirect(add_query_arg(
            array_merge(['page' => 'cikw-tools'], $args),
            admin_url('admin.php')
        ));
        exit;
    }

    private function require_manager(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'cik-prize-wheel'));
        }
    }
}
