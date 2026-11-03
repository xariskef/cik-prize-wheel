<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gatekeeping for the public kiosk endpoints: the shared kiosk key, the
 * staff key, IP hashing, entry tokens, and the per-device rate limit.
 */
final class CIKW_Security
{
    public const KIOSK_KEY_HEADER = 'X-CIKW-Kiosk-Key';
    public const STAFF_KEY_HEADER = 'X-CIKW-Staff-Key';

    /**
     * 32 random bytes, base64url encoded without padding, which is exactly
     * the 43 characters the entry_token CHAR(43) column expects.
     */
    public static function generate_entry_token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Best-effort client IP. Only ever used to build a hash - the raw value
     * is never stored (spec section 3).
     */
    public static function client_ip(): string
    {
        $candidates = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        ];

        foreach ($candidates as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }

            $value = sanitize_text_field(wp_unslash((string) $_SERVER[$key]));
            $first = trim(explode(',', $value)[0]);

            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }

        return '';
    }

    /**
     * HMAC the IP with a site salt so the stored value cannot be reversed
     * but stays stable enough to rate limit against.
     */
    public static function hash_ip(string $ip): ?string
    {
        if ('' === $ip) {
            return null;
        }

        return hash_hmac('sha256', $ip, wp_salt('cikw_ip_hash'));
    }

    /**
     * Constant-time check of the kiosk key header on every write endpoint.
     */
    public static function verify_kiosk_key(WP_REST_Request $request): bool
    {
        $expected = CIKW_Settings::kiosk_key();
        $provided = (string) $request->get_header(self::KIOSK_KEY_HEADER);

        if ('' === $expected || '' === $provided) {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    /**
     * Redemption is staff-only: either a logged-in administrator (WordPress
     * already requires a valid wp_rest nonce for cookie-authenticated REST
     * requests) or the separate staff key handed to booth staff.
     */
    public static function verify_staff(WP_REST_Request $request): bool
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        $expected = CIKW_Settings::staff_key();
        $provided = (string) $request->get_header(self::STAFF_KEY_HEADER);

        if ('' === $expected || '' === $provided) {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    /**
     * Registrations from this (device_id, ip_hash) pair within the last hour.
     *
     * Counted straight off cikw_entries rather than a transient: the count has
     * to survive an object-cache flush mid-show, and the device_created index
     * exists for exactly this query. created_at is stored in WordPress local
     * time, so the threshold is computed in local time too.
     */
    public static function registrations_in_last_hour(string $device_id, ?string $ip_hash): int
    {
        global $wpdb;

        $table = CIKW_Activator::table_name('entries');
        $threshold = gmdate('Y-m-d H:i:s', (int) current_time('timestamp') - HOUR_IN_SECONDS);

        if (null === $ip_hash) {
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE device_id = %s AND created_at >= %s',
                $table,
                $device_id,
                $threshold
            ));
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE device_id = %s AND ip_hash = %s AND created_at >= %s',
            $table,
            $device_id,
            $ip_hash,
            $threshold
        ));
    }

    /**
     * @return true|WP_Error True when under the limit, WP_Error 429 otherwise.
     */
    public static function check_rate_limit(string $device_id, ?string $ip_hash): bool|WP_Error
    {
        $options = CIKW_Settings::get_options();
        $limit = max(1, (int) ($options['rate_limit_per_hour'] ?? 40));
        $used = self::registrations_in_last_hour($device_id, $ip_hash);

        if ($used >= $limit) {
            return new WP_Error(
                'cikw_rate_limited',
                __('Πολλές συμμετοχές σε σύντομο διάστημα. Δοκιμάστε ξανά σε λίγο.', 'cik-prize-wheel'),
                ['status' => 429, 'retry_after' => HOUR_IN_SECONDS]
            );
        }

        return true;
    }
}
