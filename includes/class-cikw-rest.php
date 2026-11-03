<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The cikw/v1 REST surface (spec section 4).
 *
 * GET  /config   public, cacheable
 * POST /register kiosk key
 * POST /spin     kiosk key
 * POST /finish   kiosk key
 * POST /redeem   staff only
 */
final class CIKW_REST
{
    public const NAMESPACE = 'cikw/v1';

    public function init(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, '/config', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'handle_config'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/status', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'handle_status'],
            'permission_callback' => [$this, 'require_kiosk_key'],
        ]);

        register_rest_route(self::NAMESPACE, '/register', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle_register'],
            'permission_callback' => [$this, 'require_kiosk_key'],
        ]);

        register_rest_route(self::NAMESPACE, '/spin', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle_spin'],
            'permission_callback' => [$this, 'require_kiosk_key'],
            'args' => $this->entry_credential_args(),
        ]);

        register_rest_route(self::NAMESPACE, '/finish', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle_finish'],
            'permission_callback' => [$this, 'require_kiosk_key'],
            'args' => $this->entry_credential_args(),
        ]);

        register_rest_route(self::NAMESPACE, '/redeem', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle_redeem'],
            'permission_callback' => [$this, 'require_staff'],
            'args' => [
                'voucher_code' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => static fn($value) => strtoupper(sanitize_text_field((string) $value)),
                ],
                'staff' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /* --------------------------------------------------------------------
     * Permissions
     * ----------------------------------------------------------------- */

    public function require_kiosk_key(WP_REST_Request $request): bool|WP_Error
    {
        if (CIKW_Security::verify_kiosk_key($request)) {
            return true;
        }

        return new WP_Error(
            'cikw_invalid_kiosk_key',
            __('Μη έγκυρο κλειδί kiosk.', 'cik-prize-wheel'),
            ['status' => 401]
        );
    }

    public function require_staff(WP_REST_Request $request): bool|WP_Error
    {
        if (CIKW_Security::verify_staff($request)) {
            return true;
        }

        return new WP_Error(
            'cikw_forbidden',
            __('Απαιτούνται δικαιώματα προσωπικού.', 'cik-prize-wheel'),
            ['status' => 403]
        );
    }

    /* --------------------------------------------------------------------
     * GET /config
     * ----------------------------------------------------------------- */

    public function handle_config(WP_REST_Request $request): WP_REST_Response
    {
        $response = rest_ensure_response(CIKW_Config::payload());
        $response->header('Cache-Control', 'public, max-age=60');

        return $response;
    }

    /* --------------------------------------------------------------------
     * GET /status
     * ----------------------------------------------------------------- */

    /**
     * Live counters for the kiosk's own hidden status panel (spec section 6).
     *
     * Gated by the kiosk key rather than the staff key: the panel is opened
     * by a hidden gesture on the machine standing at the booth, and the staff
     * key deliberately never appears in the page. The payload is aggregates
     * and stock only - no participant data - so the kiosk key, which is
     * public by design, is a proportionate gate.
     */
    public function handle_status(WP_REST_Request $request): WP_REST_Response
    {
        $response = rest_ensure_response(CIKW_Dashboard::snapshot());
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    /* --------------------------------------------------------------------
     * POST /register
     * ----------------------------------------------------------------- */

    public function handle_register(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;

        $options = CIKW_Settings::get_options();
        $required = (array) $options['required_fields'];

        $device_id = sanitize_text_field((string) ($request->get_param('device_id') ?? ''));
        // ASSUMPTION: a single kiosk. Multiple devices just pass their own id.
        $device_id = '' === $device_id ? 'kiosk-01' : substr($device_id, 0, 64);

        $ip_hash = CIKW_Security::hash_ip(CIKW_Security::client_ip());
        $rate_limit = CIKW_Security::check_rate_limit($device_id, $ip_hash);

        if (is_wp_error($rate_limit)) {
            return $rate_limit;
        }

        // Validated server-side regardless of what the client already checked.
        $errors = [];

        $full_name = sanitize_text_field((string) ($request->get_param('full_name') ?? ''));
        if ('' === $full_name && in_array('name', $required, true)) {
            $errors['full_name'] = 'required';
        }

        $email = sanitize_email((string) ($request->get_param('email') ?? ''));
        if ('' === $email) {
            if (in_array('email', $required, true)) {
                $errors['email'] = 'required';
            }
        } elseif (!is_email($email)) {
            $errors['email'] = 'invalid';
        }

        $raw_phone = (string) ($request->get_param('phone') ?? '');
        $phone = $this->normalize_phone($raw_phone);
        if ('' === trim($raw_phone)) {
            if (in_array('phone', $required, true)) {
                $errors['phone'] = 'required';
            }
            $phone = '';
        } elseif (null === $phone) {
            $errors['phone'] = 'invalid';
            $phone = '';
        }

        $need_category_id = absint($request->get_param('need_category_id') ?? 0);
        if ($need_category_id > 0 && !$this->category_is_active($need_category_id)) {
            $errors['need_category_id'] = 'invalid';
        } elseif (0 === $need_category_id && in_array('need', $required, true)) {
            $errors['need_category_id'] = 'required';
        }

        $company = sanitize_text_field((string) ($request->get_param('company') ?? ''));
        if ('' === $company && in_array('company', $required, true)) {
            $errors['company'] = 'required';
        }

        $role = sanitize_text_field((string) ($request->get_param('role') ?? ''));
        if ('' === $role && in_array('role', $required, true)) {
            $errors['role'] = 'required';
        }

        if (!empty($errors)) {
            return new WP_Error(
                'cikw_validation_failed',
                __('Ελέγξτε τα στοιχεία της φόρμας.', 'cik-prize-wheel'),
                ['status' => 400, 'fields' => $errors]
            );
        }

        $now = current_time('mysql');
        $entry_token = CIKW_Security::generate_entry_token();

        $inserted = $wpdb->insert(
            CIKW_Activator::table_name('entries'),
            [
                'created_at' => $now,
                'full_name' => $full_name,
                'email' => $email,
                'phone' => $phone,
                'company' => '' === $company ? null : $company,
                'role' => '' === $role ? null : $role,
                'need_category_id' => $need_category_id > 0 ? $need_category_id : null,
                'consent_marketing' => rest_sanitize_boolean($request->get_param('consent_marketing')) ? 1 : 0,
                // The version pins which wording this visitor actually agreed to.
                'consent_text_version' => substr((string) $options['consent_version'], 0, 20),
                'consent_at' => $now,
                'entry_token' => $entry_token,
                'device_id' => $device_id,
                'ip_hash' => $ip_hash,
                'email_status' => 'pending',
                'email_attempts' => 0,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
        );

        if (false === $inserted) {
            return new WP_Error(
                'cikw_register_failed',
                __('Η εγγραφή δεν αποθηκεύτηκε. Δοκιμάστε ξανά.', 'cik-prize-wheel'),
                ['status' => 500]
            );
        }

        $response = rest_ensure_response([
            'entry_id' => (int) $wpdb->insert_id,
            'entry_token' => $entry_token,
            'spins_remaining' => max(1, (int) $options['max_spins_per_registration']),
        ]);
        $response->set_status(201);

        return $response;
    }

    /* --------------------------------------------------------------------
     * POST /spin
     * ----------------------------------------------------------------- */

    public function handle_spin(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $result = CIKW_Spin_Engine::spin(
            absint($request->get_param('entry_id')),
            (string) $request->get_param('entry_token')
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /* --------------------------------------------------------------------
     * POST /finish
     * ----------------------------------------------------------------- */

    /**
     * Marks the participation complete and queues the email. Idempotent:
     * calling it twice returns the same state and never re-queues.
     *
     * The email itself is sent by the Phase 5 cron worker, never inside this
     * request - the trade-show network is slow and the UI must not block.
     */
    public function handle_finish(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;

        $entry_id = absint($request->get_param('entry_id'));
        $entry = $this->authenticate_entry($entry_id, (string) $request->get_param('entry_token'));

        if (is_wp_error($entry)) {
            return $entry;
        }

        $table = CIKW_Activator::table_name('entries');
        $already_finished = null !== $entry['finished_at'];

        if (!$already_finished) {
            $wpdb->update(
                $table,
                ['finished_at' => current_time('mysql')],
                ['id' => $entry_id, 'finished_at' => null],
                ['%s'],
                ['%d', '%s']
            );
        }

        $fresh = $wpdb->get_row($wpdb->prepare(
            'SELECT finished_at, email_status FROM %i WHERE id = %d',
            $table,
            $entry_id
        ), ARRAY_A);

        return rest_ensure_response([
            'entry_id' => $entry_id,
            'finished' => true,
            'already_finished' => $already_finished,
            'finished_at' => $fresh['finished_at'] ?? null,
            'email_status' => $fresh['email_status'] ?? 'pending',
        ]);
    }

    /* --------------------------------------------------------------------
     * POST /redeem
     * ----------------------------------------------------------------- */

    /**
     * Redemption logic lives in CIKW_Vouchers so this endpoint and the
     * wp-admin screen can never apply different rules.
     */
    public function handle_redeem(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $result = CIKW_Vouchers::redeem(
            (string) $request->get_param('voucher_code'),
            (string) ($request->get_param('staff') ?? '')
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response([
            'voucher_code' => $result['voucher_code'],
            'redeemed_at' => $result['redeemed_at'],
            'redeemed_by' => $result['redeemed_by'],
            'prize_id' => null === $result['prize_id'] ? null : (int) $result['prize_id'],
            'prize_title' => $result['prize_title'],
            'full_name' => $result['full_name'],
        ]);
    }

    /* --------------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------- */

    private function entry_credential_args(): array
    {
        return [
            'entry_id' => [
                'required' => true,
                'type' => 'integer',
                'sanitize_callback' => 'absint',
            ],
            'entry_token' => [
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => static fn($value) => trim((string) $value),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|WP_Error The entry row, or 404/403.
     */
    private function authenticate_entry(int $entry_id, string $token): array|WP_Error
    {
        global $wpdb;

        $entry = $wpdb->get_row($wpdb->prepare(
            'SELECT id, entry_token, finished_at FROM %i WHERE id = %d',
            CIKW_Activator::table_name('entries'),
            $entry_id
        ), ARRAY_A);

        if (null === $entry) {
            return new WP_Error(
                'cikw_entry_not_found',
                __('Η συμμετοχή δεν βρέθηκε.', 'cik-prize-wheel'),
                ['status' => 404]
            );
        }

        if (!hash_equals((string) $entry['entry_token'], $token)) {
            return new WP_Error(
                'cikw_token_mismatch',
                __('Μη έγκυρο token συμμετοχής.', 'cik-prize-wheel'),
                ['status' => 403]
            );
        }

        return $entry;
    }

    private function category_is_active(int $category_id): bool
    {
        global $wpdb;

        $found = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE id = %d AND is_active = 1',
            CIKW_Activator::table_name('categories'),
            $category_id
        ));

        return null !== $found;
    }

    /**
     * Permissive Greek phone validation: 10 digits, optionally prefixed with
     * +30 / 0030 / 30, and tolerant of spaces, dots, dashes and brackets.
     *
     * @return string|null The bare 10 digits, or null when unparseable.
     */
    private function normalize_phone(string $phone): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        if (12 === strlen($digits) && str_starts_with($digits, '30')) {
            $digits = substr($digits, 2);
        } elseif (14 === strlen($digits) && str_starts_with($digits, '0030')) {
            $digits = substr($digits, 4);
        }

        return 1 === preg_match('/^\d{10}$/', $digits) ? $digits : null;
    }
}
