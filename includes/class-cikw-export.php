<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CSV export.
 *
 * One row per spin joined to the participant, honouring whatever filters are
 * active on the entries screen. This file is what the organiser takes away from the event,
 * so two details matter more than anything else here:
 *
 *  - The file starts with a UTF-8 BOM. Without it Excel on Windows reads the
 *    Greek as Latin-1 and every name comes out as mojibake.
 *  - Fields that look like formulas are prefixed, so a name beginning with
 *    "=" cannot execute when the file is opened.
 */
final class CIKW_Export
{
    public static function handle(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'cik-prize-wheel'));
        }

        check_admin_referer('cikw_export_entries');

        $filters = CIKW_Entries_Query::filters_from_request();
        $filename = 'prize-wheel-entries-' . current_time('Y-m-d-His') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        // Flush any buffering the host has enabled, so a long export streams
        // instead of building up in memory.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $out = fopen('php://output', 'w');

        // The BOM. Excel needs it to recognise UTF-8; everything else ignores it.
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, self::headings(), ';');

        foreach (CIKW_Entries_Query::stream_spin_rows($filters) as $row) {
            fputcsv($out, self::format_row($row), ';');
        }

        fclose($out);
        exit;
    }

    /**
     * @return array<int, string>
     */
    private static function headings(): array
    {
        return [
            __('Entry date', 'cik-prize-wheel'),
            __('Full name', 'cik-prize-wheel'),
            __('Email', 'cik-prize-wheel'),
            __('Phone', 'cik-prize-wheel'),
            __('Company', 'cik-prize-wheel'),
            __('Role', 'cik-prize-wheel'),
            __('Stated interest', 'cik-prize-wheel'),
            __('Spin no.', 'cik-prize-wheel'),
            __('Spin time', 'cik-prize-wheel'),
            __('Wheel category', 'cik-prize-wheel'),
            __('Outcome', 'cik-prize-wheel'),
            __('Prize', 'cik-prize-wheel'),
            __('Voucher code', 'cik-prize-wheel'),
            __('Redeemed', 'cik-prize-wheel'),
            __('Redeemed by', 'cik-prize-wheel'),
            __('Marketing consent', 'cik-prize-wheel'),
            __('Consent version', 'cik-prize-wheel'),
            __('Device', 'cik-prize-wheel'),
            __('Email status', 'cik-prize-wheel'),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, string>
     */
    private static function format_row(array $row): array
    {
        $outcome = (string) ($row['outcome'] ?? '');

        $outcome_label = match ($outcome) {
            'win' => __('Won', 'cik-prize-wheel'),
            'lose' => __('Did not win', 'cik-prize-wheel'),
            default => __('Did not play', 'cik-prize-wheel'),
        };

        $email_labels = [
            'pending' => __('Pending', 'cik-prize-wheel'),
            'sent' => __('Sent', 'cik-prize-wheel'),
            'failed' => __('Failed', 'cik-prize-wheel'),
            'skipped' => __('Skipped', 'cik-prize-wheel'),
        ];

        $values = [
            self::date($row['created_at'] ?? null),
            $row['full_name'] ?? '',
            $row['email'] ?? '',
            $row['phone'] ?? '',
            $row['company'] ?? '',
            $row['role'] ?? '',
            $row['need_category_name'] ?? '',
            $row['spin_number'] ?? '',
            self::date($row['spin_created_at'] ?? null),
            $row['category_name'] ?? '',
            $outcome_label,
            $row['prize_title'] ?? '',
            $row['voucher_code'] ?? '',
            self::date($row['redeemed_at'] ?? null),
            $row['redeemed_by'] ?? '',
            !empty($row['consent_marketing']) ? __('Yes', 'cik-prize-wheel') : __('No', 'cik-prize-wheel'),
            $row['consent_text_version'] ?? '',
            $row['device_id'] ?? '',
            $email_labels[(string) ($row['email_status'] ?? '')] ?? '',
        ];

        return array_map([__CLASS__, 'harden'], $values);
    }

    private static function date(mixed $value): string
    {
        if (empty($value)) {
            return '';
        }

        return mysql2date('d/m/Y H:i', (string) $value);
    }

    /**
     * Neutralise spreadsheet formula injection.
     *
     * A participant can type anything into the name field. If it starts with
     * =, +, - or @, Excel treats the cell as a formula when the file opens.
     */
    private static function harden(mixed $value): string
    {
        $value = (string) $value;

        if ('' === $value) {
            return '';
        }

        // Leading spaces are skipped too: some spreadsheet apps trim them
        // before deciding whether a cell is a formula.
        $first = ltrim($value, ' ')[0] ?? '';

        if (in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)
            || in_array($first, ['=', '+', '-', '@'], true)) {
            return "'" . $value;
        }

        return $value;
    }
}
