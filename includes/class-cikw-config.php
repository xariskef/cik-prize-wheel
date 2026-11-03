<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Everything the frontend needs before a visitor exists: wheel segments,
 * the "interest" dropdown, timings, consent, and all kiosk UI copy.
 *
 * Served by GET /config. A kiosk front end rendered by WordPress can also
 * inline the same payload into the page, so the first screen paints without
 * a round trip.
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
     * All user-facing kiosk copy, as translatable English source strings.
     *
     * Kept server-side so wording can be changed (via translations or the
     * cikw_config_strings filter) during an event without a frontend rebuild.
     */
    public static function strings(): array
    {
        $strings = [
            // ATTRACT
            'attract_title' => __('Spin the wheel & win', 'cik-prize-wheel'),
            'attract_cta' => __('Touch the screen to start', 'cik-prize-wheel'),

            // FORM
            'form_title' => __('Fill in your details', 'cik-prize-wheel'),
            'form_subtitle' => __('We need a few details to send you your confirmation.', 'cik-prize-wheel'),
            'field_full_name' => __('Full name', 'cik-prize-wheel'),
            'field_email' => __('Email', 'cik-prize-wheel'),
            'field_phone' => __('Phone', 'cik-prize-wheel'),
            'field_company' => __('Company', 'cik-prize-wheel'),
            'field_role' => __('Role', 'cik-prize-wheel'),
            'field_need' => __('What are you interested in?', 'cik-prize-wheel'),
            'field_need_placeholder' => __('Choose a category', 'cik-prize-wheel'),
            'field_optional' => __('optional', 'cik-prize-wheel'),
            'consent_marketing' => __('I would like to receive updates about your products.', 'cik-prize-wheel'),
            'privacy_link' => __('Privacy policy', 'cik-prize-wheel'),
            'form_submit' => __('Continue', 'cik-prize-wheel'),
            'form_back' => __('Back', 'cik-prize-wheel'),

            // WHEEL
            'wheel_title' => __('The wheel is ready', 'cik-prize-wheel'),
            'wheel_cta' => __('Tap to spin the wheel', 'cik-prize-wheel'),
            'wheel_spinning' => __('The wheel is spinning…', 'cik-prize-wheel'),
            'spins_remaining_one' => __('You have 1 spin left', 'cik-prize-wheel'),
            /* translators: %d: number of spins left. */
            'spins_remaining_many' => __('You have %d spins left', 'cik-prize-wheel'),

            // RESULT
            'result_title' => __('You landed on', 'cik-prize-wheel'),
            'result_win_cta' => __('See your prize', 'cik-prize-wheel'),
            'result_retry_cta' => __('Try again', 'cik-prize-wheel'),
            'result_continue_cta' => __('Continue', 'cik-prize-wheel'),
            'result_lose_title' => __('No prize this time', 'cik-prize-wheel'),

            // PRIZE
            'prize_title' => __('You won!', 'cik-prize-wheel'),
            'prize_voucher_label' => __('Your code', 'cik-prize-wheel'),
            'prize_voucher_hint' => __('Show this code at the booth to collect your prize.', 'cik-prize-wheel'),
            'prize_cta' => __('Finish', 'cik-prize-wheel'),

            // THANK YOU
            'thankyou_title' => __('Thank you!', 'cik-prize-wheel'),
            'thankyou_body' => __('You will receive a confirmation email at the address you entered.', 'cik-prize-wheel'),
            'thankyou_footer' => __('Enjoy the rest of the event!', 'cik-prize-wheel'),

            // IDLE
            'idle_warning_title' => __('Are you still there?', 'cik-prize-wheel'),
            /* translators: %d: seconds until the kiosk resets. */
            'idle_warning_body' => __('The screen will return to the start in %d seconds.', 'cik-prize-wheel'),
            'idle_warning_cta' => __('Yes, continue', 'cik-prize-wheel'),

            // KEYBOARD
            'keyboard_greek' => _x('EL', 'on-screen keyboard: Greek layout toggle', 'cik-prize-wheel'),
            'keyboard_latin' => _x('EN', 'on-screen keyboard: Latin layout toggle', 'cik-prize-wheel'),
            'keyboard_numeric' => _x('123', 'on-screen keyboard: numeric layout toggle', 'cik-prize-wheel'),

            // ERRORS
            'error_generic' => __('Something went wrong. Please try again.', 'cik-prize-wheel'),
            'error_network' => __('No connection. Please ask a member of the booth staff.', 'cik-prize-wheel'),
            'error_required' => __('This field is required.', 'cik-prize-wheel'),
            'error_email' => __('Please enter a valid email address.', 'cik-prize-wheel'),
            'error_phone' => __('Please enter a valid phone number (10 digits).', 'cik-prize-wheel'),
            'error_need' => __('Please choose a category.', 'cik-prize-wheel'),
            'error_no_spins' => __('You have no spins left.', 'cik-prize-wheel'),
            'error_not_configured' => __('The app has not been configured yet.', 'cik-prize-wheel'),
        ];

        /**
         * Filter the UI copy served to the kiosk.
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
