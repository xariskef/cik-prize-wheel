<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The confirmation email (spec section 8).
 *
 * Responsive HTML with a plain-text alternative: thank you for
 * visiting the stand, a recap of the category or categories the wheel landed
 * on, and - when they won - the prize and the voucher code to show at the
 * stand.
 *
 * Nothing here is ever called from a visitor-facing request. Sending happens
 * in the cron worker, because the trade-show network will be slow and the
 * kiosk must not block on it.
 */
final class CIKW_Mailer
{
    /** Give up after this many tries (spec section 8). */
    public const MAX_ATTEMPTS = 5;

    /**
     * Minutes to wait before each retry. The first send is immediate; index 1
     * is the wait after one failure, and so on.
     */
    private const BACKOFF_MINUTES = [0, 5, 15, 60, 360];

    /**
     * Send the confirmation for one entry and record the outcome.
     *
     * @param bool $force Send even if it has already gone out (the "resend"
     *                    row action), and ignore the attempt cap.
     * @return true|WP_Error
     */
    public static function send_for_entry(int $entry_id, bool $force = false): bool|WP_Error
    {
        global $wpdb;

        $entries_table = CIKW_Activator::table_name('entries');
        $entry = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $entries_table, $entry_id),
            ARRAY_A
        );

        if (null === $entry) {
            return new WP_Error('cikw_entry_not_found', __('Entry not found.', 'cik-prize-wheel'));
        }

        if (!$force && 'sent' === $entry['email_status']) {
            return true;
        }

        if ('' === (string) $entry['email'] || !is_email($entry['email'])) {
            self::mark_failed($entry_id, (int) $entry['email_attempts'] + 1, __('Invalid email address.', 'cik-prize-wheel'));

            return new WP_Error('cikw_invalid_email', __('Invalid email address.', 'cik-prize-wheel'));
        }

        $spins = self::spins_for_entry($entry_id);
        $options = CIKW_Settings::get_options();
        $subject = self::render_placeholders((string) $options['email_subject'], $entry, $spins);

        $html = self::render_html($entry, $spins, $options);
        $text = self::render_text($entry, $spins, $options);

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
        ];

        // wp_mail has no plain-text alternative parameter, so it is attached
        // to PHPMailer directly. Removed again straight after the send so it
        // cannot leak into any other mail this request sends.
        $attach_alt = static function ($phpmailer) use ($text): void {
            $phpmailer->AltBody = $text;
        };

        add_action('phpmailer_init', $attach_alt);
        $sent = wp_mail($entry['email'], $subject, $html, $headers);
        remove_action('phpmailer_init', $attach_alt);

        $attempts = (int) $entry['email_attempts'] + 1;

        if ($sent) {
            $wpdb->update(
                $entries_table,
                [
                    'email_status' => 'sent',
                    'email_attempts' => $attempts,
                    'email_last_error' => null,
                    'email_next_attempt_at' => null,
                ],
                ['id' => $entry_id],
                ['%s', '%d', '%s', '%s'],
                ['%d']
            );

            return true;
        }

        $error = self::last_mail_error();
        self::mark_failed($entry_id, $attempts, $error);

        return new WP_Error('cikw_mail_failed', $error);
    }

    /**
     * Record a failure and schedule the retry, or give up at the cap.
     */
    private static function mark_failed(int $entry_id, int $attempts, string $error): void
    {
        global $wpdb;

        $exhausted = $attempts >= self::MAX_ATTEMPTS;
        $next = null;

        if (!$exhausted) {
            $minutes = self::BACKOFF_MINUTES[min($attempts, count(self::BACKOFF_MINUTES) - 1)];
            $next = gmdate('Y-m-d H:i:s', (int) current_time('timestamp') + $minutes * MINUTE_IN_SECONDS);
        }

        $wpdb->update(
            CIKW_Activator::table_name('entries'),
            [
                // 'failed' is terminal; 'pending' means the worker will try
                // again once email_next_attempt_at has passed.
                'email_status' => $exhausted ? 'failed' : 'pending',
                'email_attempts' => $attempts,
                'email_last_error' => mb_substr($error, 0, 500),
                'email_next_attempt_at' => $next,
            ],
            ['id' => $entry_id],
            ['%s', '%d', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * The most recent wp_mail failure, if WordPress recorded one.
     */
    private static function last_mail_error(): string
    {
        global $phpmailer;

        if (isset($phpmailer) && is_object($phpmailer) && !empty($phpmailer->ErrorInfo)) {
            return (string) $phpmailer->ErrorInfo;
        }

        return __('Sending failed without an error description.', 'cik-prize-wheel');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function spins_for_entry(int $entry_id): array
    {
        global $wpdb;

        $spins = CIKW_Activator::table_name('spins');
        $prizes = CIKW_Activator::table_name('prizes');
        $categories = CIKW_Activator::table_name('categories');

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.*, c.name AS category_name, c.description AS category_description,
                        p.title AS prize_title, p.description AS prize_description, p.image_id AS prize_image_id
                   FROM {$spins} s
                   LEFT JOIN {$categories} c ON c.id = s.category_id
                   LEFT JOIN {$prizes} p ON p.id = s.prize_id
                  WHERE s.entry_id = %d
                  ORDER BY s.spin_number ASC, s.id ASC",
                $entry_id
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Substitute the placeholders the settings screen documents.
     */
    private static function render_placeholders(string $template, array $entry, array $spins): string
    {
        $categories = [];
        $prizes = [];
        $vouchers = [];

        foreach ($spins as $spin) {
            if (!empty($spin['category_name'])) {
                $categories[] = $spin['category_name'];
            }

            if ('win' === $spin['outcome']) {
                $prizes[] = (string) $spin['prize_title'];
                $vouchers[] = (string) $spin['voucher_code'];
            }
        }

        $replacements = [
            '{full_name}' => (string) $entry['full_name'],
            '{first_name}' => strtok((string) $entry['full_name'], ' ') ?: (string) $entry['full_name'],
            '{category_recap}' => $categories
                ? sprintf(
                    /* translators: %s: category names. */
                    __('The wheel landed on: %s', 'cik-prize-wheel'),
                    implode(', ', array_unique($categories))
                )
                : '',
            '{prize_details}' => $prizes
                ? sprintf(
                    /* translators: 1: prize titles, 2: voucher codes. */
                    __('You won: %1$s. Code: %2$s', 'cik-prize-wheel'),
                    implode(', ', $prizes),
                    implode(', ', $vouchers)
                )
                : __('You did not win a prize this time, but it was great to meet you.', 'cik-prize-wheel'),
            '{voucher_codes}' => implode(', ', $vouchers),
        ];

        return strtr($template, $replacements);
    }

    /**
     * Responsive HTML. Tables and inline styles on purpose - email clients
     * still have no reliable support for anything else.
     */
    private static function render_html(array $entry, array $spins, array $options): string
    {
        $intro = self::render_placeholders((string) $options['email_body'], $entry, $spins);
        $wins = array_filter($spins, static fn(array $spin): bool => 'win' === $spin['outcome']);

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?php echo esc_html((string) $options['email_subject']); ?></title>
</head>
<body style="margin:0;padding:0;background:#f2f5f7;font-family:Arial,Helvetica,sans-serif;color:#1c2733;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f5f7;padding:24px 12px;">
<tr><td align="center">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;">

        <tr><td style="background:#04121f;padding:28px 24px;text-align:center;">
            <div style="font-size:26px;font-weight:bold;color:#ffffff;letter-spacing:1px;"><?php echo esc_html(get_bloginfo('name')); ?></div>
            <div style="font-size:15px;color:#9db4c7;margin-top:6px;"><?php echo esc_html__('Spin the wheel & win', 'cik-prize-wheel'); ?></div>
        </td></tr>

        <tr><td style="padding:28px 24px 8px;">
            <p style="margin:0 0 16px;font-size:19px;font-weight:bold;">
                <?php
                printf(
                    /* translators: %s: participant name. */
                    esc_html__('Hi %s,', 'cik-prize-wheel'),
                    esc_html((string) $entry['full_name'])
                );
                ?>
            </p>
            <?php foreach (preg_split('/\n{2,}/', $intro) ?: [] as $paragraph) : ?>
                <?php if ('' !== trim($paragraph)) : ?>
                    <p style="margin:0 0 14px;font-size:16px;line-height:1.6;"><?php echo nl2br(esc_html(trim($paragraph))); ?></p>
                <?php endif; ?>
            <?php endforeach; ?>
        </td></tr>

        <?php foreach ($spins as $spin) : ?>
            <?php if (empty($spin['category_name'])) { continue; } ?>
            <tr><td style="padding:8px 24px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f5f7;border-radius:10px;">
                    <tr><td style="padding:16px 18px;">
                        <div style="font-size:13px;color:#5b6b7a;text-transform:uppercase;letter-spacing:1px;">
                            <?php echo esc_html__('Your category', 'cik-prize-wheel'); ?>
                        </div>
                        <div style="font-size:18px;font-weight:bold;color:#0b6fb4;margin-top:4px;">
                            <?php echo esc_html((string) $spin['category_name']); ?>
                        </div>
                        <?php if (!empty($spin['category_description'])) : ?>
                            <div style="font-size:15px;line-height:1.6;margin-top:8px;">
                                <?php echo esc_html((string) $spin['category_description']); ?>
                            </div>
                        <?php endif; ?>
                    </td></tr>
                </table>
            </td></tr>
        <?php endforeach; ?>

        <?php foreach ($wins as $spin) : ?>
            <tr><td style="padding:12px 24px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:2px solid #f58220;border-radius:10px;">
                    <tr><td style="padding:20px 18px;text-align:center;">
                        <div style="font-size:20px;font-weight:bold;color:#f58220;">
                            <?php echo esc_html__('You won!', 'cik-prize-wheel'); ?>
                        </div>
                        <?php $image = CIKW_Config::image_url($spin['prize_image_id'], 'medium'); ?>
                        <?php if ($image) : ?>
                            <img src="<?php echo esc_url($image); ?>" alt="" width="240" style="max-width:100%;height:auto;margin:14px 0;border-radius:8px;" />
                        <?php endif; ?>
                        <div style="font-size:18px;font-weight:bold;margin-top:10px;">
                            <?php echo esc_html((string) $spin['prize_title']); ?>
                        </div>
                        <?php if (!empty($spin['prize_description'])) : ?>
                            <div style="font-size:15px;line-height:1.6;margin-top:6px;color:#3d4a57;">
                                <?php echo esc_html((string) $spin['prize_description']); ?>
                            </div>
                        <?php endif; ?>
                        <div style="font-size:13px;color:#5b6b7a;text-transform:uppercase;letter-spacing:1px;margin-top:16px;">
                            <?php echo esc_html__('Your code', 'cik-prize-wheel'); ?>
                        </div>
                        <div style="display:inline-block;margin-top:8px;padding:12px 26px;background:#04121f;color:#ffffff;border-radius:8px;font-size:28px;font-weight:bold;letter-spacing:5px;">
                            <?php echo esc_html((string) $spin['voucher_code']); ?>
                        </div>
                        <div style="font-size:14px;color:#3d4a57;margin-top:14px;line-height:1.6;">
                            <?php echo esc_html__('Show this code at the booth to collect your prize.', 'cik-prize-wheel'); ?>
                        </div>
                    </td></tr>
                </table>
            </td></tr>
        <?php endforeach; ?>

        <tr><td style="padding:20px 24px 30px;">
            <p style="margin:0;font-size:15px;line-height:1.6;color:#3d4a57;">
                <?php echo esc_html__('Thanks for visiting our booth. Enjoy the rest of the event!', 'cik-prize-wheel'); ?>
            </p>
        </td></tr>

        <tr><td style="background:#f2f5f7;padding:18px 24px;text-align:center;font-size:12px;color:#5b6b7a;line-height:1.6;">
            <?php echo esc_html(get_bloginfo('name')); ?>
            <?php if (!empty($options['privacy_policy_url'])) : ?>
                <br /><a href="<?php echo esc_url((string) $options['privacy_policy_url']); ?>" style="color:#0b6fb4;">
                    <?php echo esc_html__('Privacy policy', 'cik-prize-wheel'); ?>
                </a>
            <?php endif; ?>
        </td></tr>

    </table>
</td></tr>
</table>
</body>
</html>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Plain-text alternative. Not a stripped copy of the HTML - it is written
     * to read properly on its own.
     */
    private static function render_text(array $entry, array $spins, array $options): string
    {
        $lines = [];
        $lines[] = sprintf(
            /* translators: %s: participant name. */
            __('Hi %s,', 'cik-prize-wheel'),
            (string) $entry['full_name']
        );
        $lines[] = '';
        $lines[] = self::render_placeholders((string) $options['email_body'], $entry, $spins);
        $lines[] = '';

        foreach ($spins as $spin) {
            if (empty($spin['category_name'])) {
                continue;
            }

            $lines[] = str_repeat('-', 48);
            $lines[] = __('Your category:', 'cik-prize-wheel') . ' ' . $spin['category_name'];

            if (!empty($spin['category_description'])) {
                $lines[] = (string) $spin['category_description'];
            }

            if ('win' === $spin['outcome']) {
                $lines[] = '';
                $lines[] = __('YOU WON:', 'cik-prize-wheel') . ' ' . $spin['prize_title'];

                if (!empty($spin['prize_description'])) {
                    $lines[] = (string) $spin['prize_description'];
                }

                $lines[] = __('Code:', 'cik-prize-wheel') . ' ' . $spin['voucher_code'];
                $lines[] = __('Show this code at the booth to collect your prize.', 'cik-prize-wheel');
            }

            $lines[] = '';
        }

        $lines[] = str_repeat('-', 48);
        $lines[] = __('Thanks for visiting our booth. Enjoy the rest of the event!', 'cik-prize-wheel');
        $lines[] = '';
        $lines[] = get_bloginfo('name');

        if (!empty($options['privacy_policy_url'])) {
            $lines[] = __('Privacy policy:', 'cik-prize-wheel') . ' ' . $options['privacy_policy_url'];
        }

        return implode("\n", $lines);
    }
}
