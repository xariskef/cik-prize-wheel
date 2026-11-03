<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Everything the frontend needs before a visitor exists: wheel segments,
 * the "Ανάγκη" dropdown, timings, consent, and all Greek UI copy.
 *
 * Shared by GET /config and by the companion theme, which inlines the same
 * payload into data-props so the first screen paints without a round trip.
 */
final class CIKW_Config
{
    /**
     * Active categories in wheel order.
     *
     * The ordering here IS the contract behind segment_index: the frontend
     * receives this list from /config and POST /spin returns an index into
     * it. Every query that touches wheel order must use this method so the
     * two can never drift apart.
     *
     * @return array<int, array<string, mixed>> Raw DB rows, reindexed 0..n-1.
     */
    public static function active_categories(): array
    {
        global $wpdb;

        $table = CIKW_Activator::table_name('categories');
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE is_active = 1 ORDER BY segment_order ASC, id ASC',
                $table
            ),
            ARRAY_A
        );

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * Full /config payload.
     */
    public static function payload(): array
    {
        $options = CIKW_Settings::get_options();
        $categories = self::active_categories();

        $segments = [];
        $need_options = [];

        foreach ($categories as $index => $category) {
            $segments[] = [
                'index' => $index,
                'id' => (int) $category['id'],
                'slug' => (string) $category['slug'],
                'name' => (string) $category['name'],
                'color' => (string) $category['color'],
                'segment_order' => (int) $category['segment_order'],
            ];

            $need_options[] = [
                'id' => (int) $category['id'],
                'name' => (string) $category['name'],
            ];
        }

        $payload = [
            'segments' => $segments,
            'need_options' => $need_options,
            'max_spins_per_registration' => max(1, (int) $options['max_spins_per_registration']),
            'required_fields' => array_values((array) $options['required_fields']),
            'timings' => [
                'idle_timeout_seconds' => (int) $options['idle_timeout_seconds'],
                'idle_warning_seconds' => (int) $options['idle_warning_seconds'],
                'thankyou_auto_return_seconds' => (int) $options['thankyou_auto_return_seconds'],
                'spin_duration_ms' => (int) $options['spin_duration_ms'],
            ],
            'sound_enabled' => (bool) $options['sound_enabled'],
            // The frontend ships two distinct layouts; this picks one. It is
            // configured rather than sniffed from the window so a desktop
            // browser used for testing shows the real kiosk layout.
            'screen_orientation' => in_array($options['screen_orientation'] ?? '', ['portrait', 'landscape'], true)
                ? $options['screen_orientation']
                : 'landscape',
            'consent' => [
                'text' => (string) $options['consent_text'],
                'version' => (string) $options['consent_version'],
                'privacy_policy_url' => (string) $options['privacy_policy_url'],
            ],
            'strings' => self::strings(),
        ];

        /**
         * Filter the payload handed to the kiosk frontend.
         *
         * @param array $payload
         */
        return apply_filters('cikw_config_payload', $payload);
    }

    /**
     * All user-facing copy, in Greek (spec section 1: Greek only).
     *
     * Kept server-side so booth staff can have wording changed during the
     * show without a frontend rebuild.
     */
    public static function strings(): array
    {
        $strings = [
            // ATTRACT
            'attract_title' => __('Γύρνα τον τροχό & κέρδισε', 'cik-prize-wheel'),
            'attract_cta' => __('Άγγιξε την οθόνη για να ξεκινήσεις', 'cik-prize-wheel'),

            // FORM
            'form_title' => __('Συμπλήρωσε τα στοιχεία σου', 'cik-prize-wheel'),
            'form_subtitle' => __('Χρειαζόμαστε λίγα στοιχεία για να σου στείλουμε την επιβεβαίωση.', 'cik-prize-wheel'),
            'field_full_name' => __('Ονοματεπώνυμο', 'cik-prize-wheel'),
            'field_email' => __('Email', 'cik-prize-wheel'),
            'field_phone' => __('Τηλέφωνο', 'cik-prize-wheel'),
            'field_company' => __('Εταιρεία', 'cik-prize-wheel'),
            'field_role' => __('Ιδιότητα', 'cik-prize-wheel'),
            'field_need' => __('Τι σε ενδιαφέρει;', 'cik-prize-wheel'),
            'field_need_placeholder' => __('Επίλεξε κατηγορία', 'cik-prize-wheel'),
            'field_optional' => __('προαιρετικό', 'cik-prize-wheel'),
            'consent_marketing' => __('Θέλω να λαμβάνω ενημερώσεις για τα προϊόντα σας.', 'cik-prize-wheel'),
            'privacy_link' => __('Πολιτική απορρήτου', 'cik-prize-wheel'),
            'form_submit' => __('Συνέχεια', 'cik-prize-wheel'),
            'form_back' => __('Πίσω', 'cik-prize-wheel'),

            // WHEEL
            'wheel_title' => __('Ο τροχός είναι έτοιμος', 'cik-prize-wheel'),
            'wheel_cta' => __('Πάτησε για να γυρίσεις τον τροχό', 'cik-prize-wheel'),
            'wheel_spinning' => __('Ο τροχός γυρίζει…', 'cik-prize-wheel'),
            'spins_remaining_one' => __('Σου απομένει 1 προσπάθεια', 'cik-prize-wheel'),
            'spins_remaining_many' => __('Σου απομένουν %d προσπάθειες', 'cik-prize-wheel'),

            // RESULT
            'result_title' => __('Έπεσες στην κατηγορία', 'cik-prize-wheel'),
            'result_win_cta' => __('Δες το δώρο σου', 'cik-prize-wheel'),
            'result_retry_cta' => __('Ξαναπροσπάθησε', 'cik-prize-wheel'),
            'result_continue_cta' => __('Συνέχεια', 'cik-prize-wheel'),
            'result_lose_title' => __('Δεν κέρδισες αυτή τη φορά', 'cik-prize-wheel'),

            // PRIZE
            'prize_title' => __('Κέρδισες!', 'cik-prize-wheel'),
            'prize_voucher_label' => __('Ο κωδικός σου', 'cik-prize-wheel'),
            'prize_voucher_hint' => __('Δείξε τον κωδικό στο περίπτερο για να παραλάβεις το δώρο σου.', 'cik-prize-wheel'),
            'prize_cta' => __('Ολοκλήρωση', 'cik-prize-wheel'),

            // THANK YOU
            'thankyou_title' => __('Ευχαριστούμε!', 'cik-prize-wheel'),
            'thankyou_body' => __('Θα λάβεις email επιβεβαίωσης στη διεύθυνση που δήλωσες.', 'cik-prize-wheel'),
            'thankyou_footer' => __('Καλή συνέχεια στην έκθεση!', 'cik-prize-wheel'),

            // IDLE
            'idle_warning_title' => __('Είσαι ακόμα εδώ;', 'cik-prize-wheel'),
            'idle_warning_body' => __('Η οθόνη θα επιστρέψει στην αρχή σε %d δευτερόλεπτα.', 'cik-prize-wheel'),
            'idle_warning_cta' => __('Ναι, συνέχισε', 'cik-prize-wheel'),

            // KEYBOARD
            'keyboard_greek' => __('ΕΛ', 'cik-prize-wheel'),
            'keyboard_latin' => __('EN', 'cik-prize-wheel'),
            'keyboard_numeric' => __('123', 'cik-prize-wheel'),

            // ERRORS
            'error_generic' => __('Κάτι πήγε στραβά. Δοκίμασε ξανά.', 'cik-prize-wheel'),
            'error_network' => __('Δεν υπάρχει σύνδεση. Κάλεσε κάποιον από το περίπτερο.', 'cik-prize-wheel'),
            'error_required' => __('Το πεδίο είναι υποχρεωτικό.', 'cik-prize-wheel'),
            'error_email' => __('Δώσε ένα έγκυρο email.', 'cik-prize-wheel'),
            'error_phone' => __('Δώσε ένα έγκυρο τηλέφωνο (10 ψηφία).', 'cik-prize-wheel'),
            'error_need' => __('Επίλεξε μια κατηγορία.', 'cik-prize-wheel'),
            'error_no_spins' => __('Δεν έχεις άλλες προσπάθειες.', 'cik-prize-wheel'),
            'error_not_configured' => __('Η εφαρμογή δεν έχει ρυθμιστεί ακόμη.', 'cik-prize-wheel'),
        ];

        /**
         * Filter the Greek UI copy served to the kiosk.
         *
         * @param array $strings
         */
        return apply_filters('cikw_config_strings', $strings);
    }

    /**
     * Attachment URL for a stored image_id, or null when unset/missing.
     */
    public static function image_url(mixed $image_id, string $size = 'large'): ?string
    {
        $image_id = (int) $image_id;

        if ($image_id <= 0) {
            return null;
        }

        $url = wp_get_attachment_image_url($image_id, $size);

        return $url ?: null;
    }
}
