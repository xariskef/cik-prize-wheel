<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Έκθεση → Εργαλεία δοκιμών.
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
            __('Εργαλεία δοκιμών', 'cik-prize-wheel'),
            __('Εργαλεία δοκιμών', 'cik-prize-wheel'),
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
        echo '<h1>' . esc_html__('Εργαλεία δοκιμών', 'cik-prize-wheel') . '</h1>';

        echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Μόνο για δοκιμές.', 'cik-prize-wheel') . '</strong> ';
        echo esc_html__('Μη χρησιμοποιείτε αυτή τη σελίδα κατά τη διάρκεια της έκθεσης. Οι ενέργειες διαγράφουν δεδομένα οριστικά.', 'cik-prize-wheel');
        echo '</p></div>';

        $this->render_message_notice();

        if ($real_entries > 0) {
            echo '<div class="notice notice-error"><p>';
            printf(
                /* translators: %d: number of real participant entries. */
                esc_html__('Προσοχή: υπάρχουν %d πραγματικές συμμετοχές στη βάση. Οι ενέργειες επαναφοράς και ολικής διαγραφής θα τις σβήσουν.', 'cik-prize-wheel'),
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
        echo '<h2>' . esc_html__('Κατάσταση', 'cik-prize-wheel') . '</h2>';
        echo '<table class="widefat striped" style="max-width:620px">';
        echo '<tbody>';

        $rows = [
            __('Κατηγορίες', 'cik-prize-wheel') => $counts['categories'],
            __('Δώρα', 'cik-prize-wheel') => $counts['prizes'],
            __('Συμμετοχές (σύνολο)', 'cik-prize-wheel') => $counts['entries'],
            __('— από αυτές εικονικές', 'cik-prize-wheel') => $counts['demo_entries'],
            __('— από αυτές πραγματικές', 'cik-prize-wheel') => $real_entries,
            __('Περιστροφές', 'cik-prize-wheel') => $counts['spins'],
            __('Κερδισμένα δώρα', 'cik-prize-wheel') => $counts['wins'],
        ];

        foreach ($rows as $label => $value) {
            echo '<tr><td>' . esc_html($label) . '</td><td><strong>' . esc_html((string) $value) . '</strong></td></tr>';
        }

        if ($counts['spins'] > 0) {
            echo '<tr><td>' . esc_html__('Ποσοστό νίκης', 'cik-prize-wheel') . '</td><td><strong>';
            echo esc_html(number_format(100 * $counts['wins'] / $counts['spins'], 1) . '%');
            echo '</strong></td></tr>';
        }

        echo '</tbody></table>';
    }

    private function render_wheel_section(array $counts): void
    {
        echo '<h2>' . esc_html__('Δεδομένα τροχού', 'cik-prize-wheel') . '</h2>';

        if (0 === $counts['categories']) {
            echo '<p>' . esc_html__('Ο τροχός είναι άδειος. Φορτώστε το demo dataset για να ξεκινήσετε.', 'cik-prize-wheel') . '</p>';
            $this->render_action_button(
                'cikw_seed_demo_data',
                __('Φόρτωση demo δεδομένων (12 κατηγορίες, 48 δώρα)', 'cik-prize-wheel'),
                'button-primary'
            );

            return;
        }

        echo '<p>';
        printf(
            /* translators: 1: category count, 2: prize count. */
            esc_html__('Ο τροχός έχει ήδη %1$d κατηγορίες και %2$d δώρα. Η επαναφορά τα διαγράφει όλα, μαζί με τις συμμετοχές και τις περιστροφές, και ξαναφορτώνει το demo dataset από την αρχή.', 'cik-prize-wheel'),
            (int) $counts['categories'],
            (int) $counts['prizes']
        );
        echo '</p>';

        $this->render_action_button(
            'cikw_reset_wheel',
            __('Επαναφορά τροχού & επαναφόρτωση demo δεδομένων', 'cik-prize-wheel'),
            'button-secondary',
            __('Θα διαγραφούν ΟΛΕΣ οι κατηγορίες, τα δώρα, οι συμμετοχές και οι περιστροφές. Συνέχεια;', 'cik-prize-wheel'),
            true
        );
    }

    private function render_visitors_section(array $counts): void
    {
        echo '<h2>' . esc_html__('Εικονικές συμμετοχές', 'cik-prize-wheel') . '</h2>';
        echo '<p>' . esc_html__('Δημιουργεί εικονικούς επισκέπτες και τους περνά από την πραγματική μηχανή κλήρωσης, ώστε να προκύψουν αληθινές περιστροφές, κωδικοί δώρων και αρχεία ελέγχου.', 'cik-prize-wheel') . '</p>';
        echo '<p class="description">' . esc_html__('Τα εικονικά email είναι @example.test και σημειώνονται ως “skipped”, οπότε δεν πρόκειται ποτέ να σταλεί email σε αυτά.', 'cik-prize-wheel') . '</p>';

        if (0 === $counts['categories']) {
            echo '<p><em>' . esc_html__('Φορτώστε πρώτα δεδομένα στον τροχό.', 'cik-prize-wheel') . '</em></p>';

            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:12px 0">';
        wp_nonce_field('cikw_simulate_visitors');
        echo '<input type="hidden" name="action" value="cikw_simulate_visitors" />';
        echo '<label>' . esc_html__('Πλήθος επισκεπτών', 'cik-prize-wheel') . ' ';
        printf(
            '<input type="number" name="visitor_count" value="50" min="1" max="%1$d" class="small-text" /></label> ',
            (int) self::MAX_SIMULATED_VISITORS
        );
        submit_button(__('Δημιουργία', 'cik-prize-wheel'), 'button-primary', 'submit', false);
        echo '<p class="description">';
        printf(
            /* translators: %d: maximum simulated visitors per run. */
            esc_html__('Μέγιστο %d ανά εκτέλεση, ώστε να μη λήξει το χρονικό όριο του αιτήματος. Τρέξτε το ξανά για περισσότερους.', 'cik-prize-wheel'),
            (int) self::MAX_SIMULATED_VISITORS
        );
        echo '</p>';
        echo '</form>';

        if ($counts['demo_entries'] > 0) {
            $this->render_action_button(
                'cikw_delete_demo_entries',
                sprintf(
                    /* translators: %d: number of simulated entries. */
                    __('Διαγραφή %d εικονικών συμμετοχών', 'cik-prize-wheel'),
                    (int) $counts['demo_entries']
                ),
                'button-secondary',
                __('Θα διαγραφούν μόνο οι εικονικές συμμετοχές. Οι πραγματικές δεν θα πειραχθούν. Συνέχεια;', 'cik-prize-wheel')
            );
            echo '<p class="description">' . esc_html__('Ασφαλές: αφορά μόνο εγγραφές με device_id “demo-seed”.', 'cik-prize-wheel') . '</p>';
        }
    }

    private function render_stock_section(): void
    {
        echo '<h2>' . esc_html__('Απόθεμα δώρων', 'cik-prize-wheel') . '</h2>';
        echo '<p>' . esc_html__('Επαναφέρει το υπόλοιπο κάθε δώρου στο συνολικό του απόθεμα, χωρίς να διαγράψει συμμετοχές. Χρήσιμο μετά από μια δοκιμαστική εκτέλεση.', 'cik-prize-wheel') . '</p>';

        $this->render_action_button(
            'cikw_reset_all_stock',
            __('Επαναφορά αποθέματος σε όλα τα δώρα', 'cik-prize-wheel'),
            'button-secondary',
            __('Θα επαναφερθεί το υπόλοιπο όλων των δώρων στο σύνολό τους. Συνέχεια;', 'cik-prize-wheel')
        );
    }

    private function render_keys_section(): void
    {
        echo '<h2>' . esc_html__('Κλειδιά & endpoints', 'cik-prize-wheel') . '</h2>';
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
        echo '<p class="description">' . esc_html__('Το kiosk key ταξιδεύει μέσα στη σελίδα του kiosk και δεν είναι μυστικό. Το staff key, που χρειάζεται για την εξαργύρωση, δεν εμφανίζεται πουθενά δημόσια.', 'cik-prize-wheel') . '</p>';
    }

    private function render_edge_cases(): void
    {
        echo '<h2>' . esc_html__('Τι να προσέξετε στο demo dataset', 'cik-prize-wheel') . '</h2>';
        echo '<p>' . esc_html__('Τα δεδομένα είναι σκόπιμα ανομοιόμορφα, ώστε μια δοκιμή να περνά από κάθε διαδρομή της μηχανής:', 'cik-prize-wheel') . '</p>';
        echo '<ul class="ul-disc">';

        foreach (CIKW_Demo_Data::edge_case_notes() as $note) {
            echo '<li><code>' . esc_html($note) . '</code></li>';
        }

        echo '</ul>';
    }

    private function render_danger_section(): void
    {
        echo '<hr />';
        echo '<h2 style="color:#b32d2e">' . esc_html__('Ολική διαγραφή', 'cik-prize-wheel') . '</h2>';
        echo '<p>' . esc_html__('Διαγράφει κατηγορίες, δώρα, συμμετοχές και περιστροφές, χωρίς να ξαναφορτώσει τίποτα. Ο τροχός μένει άδειος.', 'cik-prize-wheel') . '</p>';

        $this->render_action_button(
            'cikw_wipe_all',
            __('Διαγραφή όλων των δεδομένων', 'cik-prize-wheel'),
            'button-link-delete',
            __('Θα διαγραφούν ΟΛΑ τα δεδομένα του τροχού, μαζί με τις πραγματικές συμμετοχές. Η ενέργεια δεν αναιρείται. Συνέχεια;', 'cik-prize-wheel'),
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
            echo esc_html__('Καταλαβαίνω ότι θα διαγραφούν δεδομένα οριστικά.', 'cik-prize-wheel');
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
                    __('Φορτώθηκαν %1$d κατηγορίες και %2$d δώρα.', 'cik-prize-wheel'),
                    $categories,
                    $prizes
                );
                break;

            case 'already_seeded':
                $type = 'info';
                $text = __('Ο τροχός έχει ήδη δεδομένα. Δεν άλλαξε τίποτα.', 'cik-prize-wheel');
                break;

            case 'simulated':
                $type = 'success';
                $text = sprintf(
                    /* translators: 1: visitors, 2: spins, 3: wins. */
                    __('Δημιουργήθηκαν %1$d εικονικοί επισκέπτες, %2$d περιστροφές, %3$d κερδισμένα δώρα.', 'cik-prize-wheel'),
                    $visitors,
                    $spins,
                    $wins
                );
                break;

            case 'demo_entries_deleted':
                $type = 'success';
                $text = sprintf(
                    /* translators: %d: number of entries deleted. */
                    __('Διαγράφηκαν %d εικονικές συμμετοχές.', 'cik-prize-wheel'),
                    $deleted
                );
                break;

            case 'stock_reset':
                $type = 'success';
                $text = sprintf(
                    /* translators: %d: number of prizes updated. */
                    __('Επαναφέρθηκε το απόθεμα σε %d δώρα.', 'cik-prize-wheel'),
                    $updated
                );
                break;

            case 'wiped':
                $type = 'success';
                $text = __('Όλα τα δεδομένα διαγράφηκαν.', 'cik-prize-wheel');
                break;

            case 'not_confirmed':
                $type = 'error';
                $text = __('Δεν επιβεβαιώσατε την ενέργεια. Δεν άλλαξε τίποτα.', 'cik-prize-wheel');
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
            wp_die(esc_html__('Δεν έχετε δικαίωμα πρόσβασης.', 'cik-prize-wheel'));
        }
    }
}
