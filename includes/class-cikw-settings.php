<?php

if (!defined('ABSPATH')) {
    exit;
}

final class CIKW_Settings
{
    public const OPTION_NAME = 'cikw_settings';

    private const REQUIRED_FIELDS = [
        'name' => 'Όνομα',
        'email' => 'Email',
        'phone' => 'Τηλέφωνο',
        'need' => 'Ανάγκη',
        'company' => 'Εταιρεία',
        'role' => 'Ρόλος',
    ];

    public function init(): void
    {
        // The parent menu must exist before anything can hang off it, hence
        // the early priority. Ρυθμίσεις is registered separately and late, so
        // the submenu reads in the order booth staff need during the show
        // rather than the order the classes happen to load in.
        add_action('admin_menu', [$this, 'register_menu'], 5);
        add_action('admin_menu', [$this, 'register_settings_page'], 28);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public static function defaults(): array
    {
        return [
            'max_spins_per_registration' => 2,
            'stock_exhausted_policy' => 'to_lose',
            'idle_timeout_seconds' => 60,
            'idle_warning_seconds' => 10,
            'thankyou_auto_return_seconds' => 20,
            'required_fields' => ['name', 'email', 'phone', 'need'],
            'spin_duration_ms' => 4500,
            'sound_enabled' => true,
            // Confirmed with the client: the show runs on a landscape
            // 1920x1080 panel. Portrait is kept selectable because the panel
            // was not final when this was built.
            'screen_orientation' => 'landscape',
            // Both keys default to empty and are minted on first use by
            // kiosk_key()/staff_key(). Generating them here would hand out a
            // different key on every call for installs whose saved options
            // predate the setting.
            'kiosk_key' => '',
            'staff_key' => '',
            'rate_limit_per_hour' => 40,
            // ASSUMPTION: final legal consent wording is supplied by the event organiser.
            'consent_text' => 'Συναινώ στην επεξεργασία των στοιχείων μου για τη συμμετοχή στην ενέργεια της έκθεσης.',
            'consent_version' => 'draft-1',
            'privacy_policy_url' => '',
            'delete_data_on_uninstall' => false,
            'email_subject' => 'Ευχαριστούμε για τη συμμετοχή σας',
            'email_body' => "Σας ευχαριστούμε που επισκεφθήκατε το περίπτερό μας.\n\n{category_recap}\n\n{prize_details}",
        ];
    }

    public static function get_options(): array
    {
        $options = get_option(self::OPTION_NAME, []);

        if (!is_array($options)) {
            $options = [];
        }

        return array_merge(self::defaults(), $options);
    }

    /**
     * The shared key every kiosk write endpoint requires.
     *
     * Public by nature - it ships inside the kiosk page, which is a public
     * URL. It is a gate against random traffic, not a secret (spec section 4).
     */
    public static function kiosk_key(): string
    {
        return self::ensure_key('kiosk_key');
    }

    /**
     * Separate key for the staff-only redemption endpoint, so booth staff
     * can redeem vouchers without a wp-admin login.
     */
    public static function staff_key(): string
    {
        return self::ensure_key('staff_key');
    }

    /**
     * Return a stored key, minting and persisting one the first time it is
     * asked for. Clearing the field in the settings screen rotates the key.
     */
    private static function ensure_key(string $name): string
    {
        $options = get_option(self::OPTION_NAME, []);
        $options = is_array($options) ? $options : [];

        if (!empty($options[$name]) && is_string($options[$name])) {
            return $options[$name];
        }

        $options[$name] = self::generate_kiosk_key();
        update_option(self::OPTION_NAME, $options, false);

        return $options[$name];
    }

    public function register_menu(): void
    {
        add_menu_page(
            __('Έκθεση', 'cik-prize-wheel'),
            __('Έκθεση', 'cik-prize-wheel'),
            'manage_options',
            'cikw-dashboard',
            [$this, 'render_dashboard_placeholder'],
            'dashicons-tickets-alt',
            56
        );
    }

    public function register_settings_page(): void
    {
        add_submenu_page(
            'cikw-dashboard',
            __('Ρυθμίσεις', 'cik-prize-wheel'),
            __('Ρυθμίσεις', 'cik-prize-wheel'),
            'manage_options',
            'cikw-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings(): void
    {
        register_setting(
            'cikw_settings_group',
            self::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_options'],
                'default' => self::defaults(),
            ]
        );

        add_settings_section(
            'cikw_settings_main',
            __('Βασικές ρυθμίσεις kiosk', 'cik-prize-wheel'),
            static function (): void {
                echo '<p>' . esc_html__('Ρυθμίσεις λειτουργίας του kiosk. Οι συμμετοχές, το export και τα email προστίθενται σε επόμενη φάση.', 'cik-prize-wheel') . '</p>';
            },
            'cikw-settings'
        );

        $fields = [
            'max_spins_per_registration' => __('Μέγιστες προσπάθειες ανά συμμετοχή', 'cik-prize-wheel'),
            'idle_timeout_seconds' => __('Idle timeout (δευτερόλεπτα)', 'cik-prize-wheel'),
            'idle_warning_seconds' => __('Προειδοποίηση idle (δευτερόλεπτα)', 'cik-prize-wheel'),
            'thankyou_auto_return_seconds' => __('Αυτόματη επιστροφή από thank you', 'cik-prize-wheel'),
            'spin_duration_ms' => __('Διάρκεια περιστροφής (ms)', 'cik-prize-wheel'),
            'rate_limit_per_hour' => __('Όριο εγγραφών ανά ώρα / συσκευή', 'cik-prize-wheel'),
        ];

        foreach ($fields as $key => $label) {
            add_settings_field($key, $label, [$this, 'render_number_field'], 'cikw-settings', 'cikw_settings_main', ['key' => $key]);
        }

        add_settings_field('stock_exhausted_policy', __('Πολιτική εξαντλημένου stock', 'cik-prize-wheel'), [$this, 'render_policy_field'], 'cikw-settings', 'cikw_settings_main');
        add_settings_field('screen_orientation', __('Προσανατολισμός οθόνης', 'cik-prize-wheel'), [$this, 'render_orientation_field'], 'cikw-settings', 'cikw_settings_main');
        add_settings_field('required_fields', __('Υποχρεωτικά πεδία φόρμας', 'cik-prize-wheel'), [$this, 'render_required_fields'], 'cikw-settings', 'cikw_settings_main');
        add_settings_field('sound_enabled', __('Ήχος', 'cik-prize-wheel'), [$this, 'render_checkbox_field'], 'cikw-settings', 'cikw_settings_main', ['key' => 'sound_enabled']);
        add_settings_field('kiosk_key', __('Kiosk key', 'cik-prize-wheel'), [$this, 'render_key_field'], 'cikw-settings', 'cikw_settings_main', ['key' => 'kiosk_key']);
        add_settings_field('staff_key', __('Staff key (εξαργύρωση)', 'cik-prize-wheel'), [$this, 'render_key_field'], 'cikw-settings', 'cikw_settings_main', ['key' => 'staff_key']);
        add_settings_field('consent_text', __('Κείμενο συγκατάθεσης', 'cik-prize-wheel'), [$this, 'render_textarea_field'], 'cikw-settings', 'cikw_settings_main', ['key' => 'consent_text']);
        add_settings_field('consent_version', __('Έκδοση συγκατάθεσης', 'cik-prize-wheel'), [$this, 'render_text_field'], 'cikw-settings', 'cikw_settings_main', ['key' => 'consent_version', 'class' => 'regular-text']);
        add_settings_field('privacy_policy_url', __('URL πολιτικής απορρήτου', 'cik-prize-wheel'), [$this, 'render_url_field'], 'cikw-settings', 'cikw_settings_main', ['key' => 'privacy_policy_url']);
        add_settings_field('email_subject', __('Θέμα email', 'cik-prize-wheel'), [$this, 'render_text_field'], 'cikw-settings', 'cikw_settings_main', ['key' => 'email_subject', 'class' => 'large-text']);
        add_settings_field(
            'email_body',
            __('Πρότυπο email', 'cik-prize-wheel'),
            [$this, 'render_textarea_field'],
            'cikw-settings',
            'cikw_settings_main',
            [
                'key' => 'email_body',
                'description' => __('Το κείμενο εισαγωγής. Η κατηγορία, το δώρο και ο κωδικός προστίθενται αυτόματα από κάτω. Διαθέσιμα πεδία: <code>{full_name}</code>, <code>{first_name}</code>, <code>{category_recap}</code>, <code>{prize_details}</code>, <code>{voucher_codes}</code>.', 'cik-prize-wheel'),
            ]
        );
        add_settings_field('delete_data_on_uninstall', __('Διαγραφή δεδομένων στο uninstall', 'cik-prize-wheel'), [$this, 'render_checkbox_field'], 'cikw-settings', 'cikw_settings_main', ['key' => 'delete_data_on_uninstall']);
    }

    public function sanitize_options(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $defaults = self::defaults();

        $output = $defaults;
        $output['max_spins_per_registration'] = max(1, absint($input['max_spins_per_registration'] ?? $defaults['max_spins_per_registration']));
        $output['idle_timeout_seconds'] = max(10, absint($input['idle_timeout_seconds'] ?? $defaults['idle_timeout_seconds']));
        $output['idle_warning_seconds'] = max(3, absint($input['idle_warning_seconds'] ?? $defaults['idle_warning_seconds']));
        $output['thankyou_auto_return_seconds'] = max(3, absint($input['thankyou_auto_return_seconds'] ?? $defaults['thankyou_auto_return_seconds']));
        $output['spin_duration_ms'] = max(1000, absint($input['spin_duration_ms'] ?? $defaults['spin_duration_ms']));
        $output['rate_limit_per_hour'] = max(1, absint($input['rate_limit_per_hour'] ?? $defaults['rate_limit_per_hour']));

        $policy = sanitize_key((string) ($input['stock_exhausted_policy'] ?? $defaults['stock_exhausted_policy']));
        $output['stock_exhausted_policy'] = in_array($policy, ['to_lose', 'redistribute'], true) ? $policy : 'to_lose';

        $orientation = sanitize_key((string) ($input['screen_orientation'] ?? $defaults['screen_orientation']));
        $output['screen_orientation'] = in_array($orientation, ['portrait', 'landscape'], true) ? $orientation : 'landscape';

        $required = $input['required_fields'] ?? $defaults['required_fields'];
        $required = is_array($required) ? array_map('sanitize_key', $required) : [];
        $output['required_fields'] = array_values(array_intersect(array_keys(self::REQUIRED_FIELDS), $required));

        $output['sound_enabled'] = !empty($input['sound_enabled']);
        $output['delete_data_on_uninstall'] = !empty($input['delete_data_on_uninstall']);
        // Clearing either field is the "rotate this key" gesture.
        $output['kiosk_key'] = sanitize_text_field((string) ($input['kiosk_key'] ?? ''));
        $output['staff_key'] = sanitize_text_field((string) ($input['staff_key'] ?? ''));

        if ('' === $output['kiosk_key']) {
            $output['kiosk_key'] = self::generate_kiosk_key();
        }

        if ('' === $output['staff_key']) {
            $output['staff_key'] = self::generate_kiosk_key();
        }

        $output['consent_text'] = sanitize_textarea_field((string) ($input['consent_text'] ?? $defaults['consent_text']));
        $output['consent_version'] = sanitize_text_field((string) ($input['consent_version'] ?? $defaults['consent_version']));
        $output['privacy_policy_url'] = esc_url_raw((string) ($input['privacy_policy_url'] ?? ''));
        $output['email_subject'] = sanitize_text_field((string) ($input['email_subject'] ?? $defaults['email_subject']));
        $output['email_body'] = sanitize_textarea_field((string) ($input['email_body'] ?? $defaults['email_body']));

        return $output;
    }

    public function render_dashboard_placeholder(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Δεν έχετε δικαίωμα πρόσβασης.', 'cik-prize-wheel'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Έκθεση', 'cik-prize-wheel') . '</h1>';
        echo '<p>' . esc_html__('Διαχείριση της ενέργειας της έκθεσης. Οι συμμετοχές, το export και ο πίνακας ελέγχου προστίθενται σε επόμενη φάση.', 'cik-prize-wheel') . '</p>';
        echo '<p>';
        echo '<a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=cikw-categories')) . '">' . esc_html__('Κατηγορίες', 'cik-prize-wheel') . '</a> ';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=cikw-prizes')) . '">' . esc_html__('Δώρα', 'cik-prize-wheel') . '</a> ';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=cikw-settings')) . '">' . esc_html__('Ρυθμίσεις', 'cik-prize-wheel') . '</a>';
        echo '</p>';
        echo '</div>';
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Δεν έχετε δικαίωμα πρόσβασης.', 'cik-prize-wheel'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Ρυθμίσεις Prize Wheel Kiosk', 'cik-prize-wheel') . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('cikw_settings_group');
        do_settings_sections('cikw-settings');
        submit_button(__('Αποθήκευση ρυθμίσεων', 'cik-prize-wheel'));
        echo '</form>';
        echo '</div>';
    }

    public function render_number_field(array $args): void
    {
        $key = $args['key'];
        $options = self::get_options();

        printf(
            '<input type="number" min="0" class="small-text" name="%1$s[%2$s]" value="%3$s" />',
            esc_attr(self::OPTION_NAME),
            esc_attr($key),
            esc_attr((string) ($options[$key] ?? ''))
        );
    }

    public function render_policy_field(): void
    {
        $options = self::get_options();
        $current = (string) ($options['stock_exhausted_policy'] ?? 'to_lose');

        echo '<select name="' . esc_attr(self::OPTION_NAME) . '[stock_exhausted_policy]">';
        foreach (['to_lose' => __('Μεταφορά στο “καμία νίκη”', 'cik-prize-wheel'), 'redistribute' => __('Αναδιανομή στα διαθέσιμα δώρα', 'cik-prize-wheel')] as $value => $label) {
            printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr($value), selected($current, $value, false), esc_html($label));
        }
        echo '</select>';
    }

    /**
     * The kiosk ships two real layouts, not one stretched layout, so the
     * orientation has to be told to the frontend rather than sniffed - a
     * desktop browser used for testing would otherwise guess wrong.
     */
    public function render_orientation_field(): void
    {
        $options = self::get_options();
        $current = (string) ($options['screen_orientation'] ?? 'landscape');

        $choices = [
            'landscape' => __('Οριζόντια (1920×1080)', 'cik-prize-wheel'),
            'portrait' => __('Κάθετη (1080×1920)', 'cik-prize-wheel'),
        ];

        echo '<select name="' . esc_attr(self::OPTION_NAME) . '[screen_orientation]">';
        foreach ($choices as $value => $label) {
            printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr($value), selected($current, $value, false), esc_html($label));
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Καθορίζει τη διάταξη του kiosk. Αλλάξτε το αν αλλάξει η οθόνη της έκθεσης.', 'cik-prize-wheel') . '</p>';
    }

    public function render_required_fields(): void
    {
        $options = self::get_options();
        $required = is_array($options['required_fields'] ?? null) ? $options['required_fields'] : [];

        foreach (self::REQUIRED_FIELDS as $key => $label) {
            printf(
                '<label style="display:block;margin:0 0 6px;"><input type="checkbox" name="%1$s[required_fields][]" value="%2$s" %3$s /> %4$s</label>',
                esc_attr(self::OPTION_NAME),
                esc_attr($key),
                checked(in_array($key, $required, true), true, false),
                esc_html($label)
            );
        }
    }

    public function render_checkbox_field(array $args): void
    {
        $key = $args['key'];
        $options = self::get_options();

        printf(
            '<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
            esc_attr(self::OPTION_NAME),
            esc_attr($key),
            checked(!empty($options[$key]), true, false),
            esc_html__('Ενεργό', 'cik-prize-wheel')
        );
    }

    public function render_text_field(array $args): void
    {
        $key = $args['key'];
        $class = $args['class'] ?? 'regular-text';
        $options = self::get_options();

        printf(
            '<input type="text" class="%1$s" name="%2$s[%3$s]" value="%4$s" />',
            esc_attr($class),
            esc_attr(self::OPTION_NAME),
            esc_attr($key),
            esc_attr((string) ($options[$key] ?? ''))
        );
    }

    /**
     * Key fields resolve through the accessors so the box is never blank on
     * a fresh install, and note that clearing the field rotates the key.
     */
    public function render_key_field(array $args): void
    {
        $key = $args['key'];
        $value = 'staff_key' === $key ? self::staff_key() : self::kiosk_key();

        printf(
            '<input type="text" class="regular-text code" name="%1$s[%2$s]" value="%3$s" /><p class="description">%4$s</p>',
            esc_attr(self::OPTION_NAME),
            esc_attr($key),
            esc_attr($value),
            esc_html__('Αδειάστε το πεδίο και αποθηκεύστε για να δημιουργηθεί νέο κλειδί.', 'cik-prize-wheel')
        );
    }

    public function render_url_field(array $args): void
    {
        $key = $args['key'];
        $options = self::get_options();

        printf(
            '<input type="url" class="regular-text" name="%1$s[%2$s]" value="%3$s" />',
            esc_attr(self::OPTION_NAME),
            esc_attr($key),
            esc_attr((string) ($options[$key] ?? ''))
        );
    }

    public function render_textarea_field(array $args): void
    {
        $key = $args['key'];
        $options = self::get_options();

        printf(
            '<textarea class="large-text" rows="5" name="%1$s[%2$s]">%3$s</textarea>',
            esc_attr(self::OPTION_NAME),
            esc_attr($key),
            esc_textarea((string) ($options[$key] ?? ''))
        );

        if (!empty($args['description'])) {
            echo '<p class="description">' . wp_kses_post($args['description']) . '</p>';
        }
    }

    private static function generate_kiosk_key(): string
    {
        return wp_generate_password(40, false, false);
    }
}
