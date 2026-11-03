<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Filtering and reading of participations.
 *
 * The list table and the CSV export share this class deliberately: the
 * export must honour the active filters, and the only way to guarantee that
 * is for both to build their WHERE clause from the same code.
 *
 * The list table shows one row per ENTRY (with the spins aggregated); the
 * export produces one row per SPIN joined to the participant. Both accept
 * the same filter array.
 */
final class CIKW_Entries_Query
{
    /** Rows pulled per batch when streaming the export. */
    private const EXPORT_CHUNK = 500;

    /**
     * Read and sanitise the filters from the current request.
     *
     * @return array<string, mixed>
     */
    public static function filters_from_request(): array
    {
        $get = static fn(string $key): string => isset($_GET[$key])
            ? sanitize_text_field(wp_unslash((string) $_GET[$key]))
            : '';

        $outcome = $get('cikw_outcome');
        $redeemed = $get('cikw_redeemed');
        $email_status = $get('cikw_email_status');

        return [
            'search' => $get('s'),
            'date_from' => self::sanitize_date($get('cikw_date_from')),
            'date_to' => self::sanitize_date($get('cikw_date_to')),
            'category_id' => isset($_GET['cikw_category']) ? absint($_GET['cikw_category']) : 0,
            'outcome' => in_array($outcome, ['win', 'lose'], true) ? $outcome : '',
            'redeemed' => in_array($redeemed, ['yes', 'no'], true) ? $redeemed : '',
            'email_status' => in_array($email_status, ['pending', 'sent', 'failed', 'skipped'], true) ? $email_status : '',
        ];
    }

    /**
     * Which of the filters are actually narrowing the result, for the "showing
     * filtered export" hint in the admin.
     */
    public static function active_filter_count(array $filters): int
    {
        $active = 0;

        foreach ($filters as $value) {
            if ('' !== $value && 0 !== $value) {
                $active++;
            }
        }

        return $active;
    }

    /**
     * Build the shared WHERE fragment.
     *
     * Filters that live on the spins table are applied as EXISTS subqueries
     * rather than join conditions, so narrowing by outcome does not also
     * distort the per-entry aggregates shown alongside it.
     */
    private static function where(array $filters): string
    {
        global $wpdb;

        $spins = CIKW_Activator::table_name('spins');
        $clauses = ['1=1'];

        if ('' !== $filters['search']) {
            $like = '%' . $wpdb->esc_like($filters['search']) . '%';
            $clauses[] = $wpdb->prepare(
                "(e.full_name LIKE %s OR e.email LIKE %s OR e.phone LIKE %s OR e.company LIKE %s OR e.role LIKE %s
                  OR EXISTS (SELECT 1 FROM {$spins} sx WHERE sx.entry_id = e.id AND sx.voucher_code LIKE %s))",
                $like,
                $like,
                $like,
                $like,
                $like,
                $like
            );
        }

        if ('' !== $filters['date_from']) {
            $clauses[] = $wpdb->prepare('e.created_at >= %s', $filters['date_from'] . ' 00:00:00');
        }

        if ('' !== $filters['date_to']) {
            $clauses[] = $wpdb->prepare('e.created_at <= %s', $filters['date_to'] . ' 23:59:59');
        }

        if ($filters['category_id'] > 0) {
            $clauses[] = $wpdb->prepare(
                "EXISTS (SELECT 1 FROM {$spins} sc WHERE sc.entry_id = e.id AND sc.category_id = %d)",
                $filters['category_id']
            );
        }

        if ('win' === $filters['outcome']) {
            $clauses[] = "EXISTS (SELECT 1 FROM {$spins} so WHERE so.entry_id = e.id AND so.outcome = 'win')";
        } elseif ('lose' === $filters['outcome']) {
            // Took part, but never won anything.
            $clauses[] = "EXISTS (SELECT 1 FROM {$spins} so WHERE so.entry_id = e.id)
                          AND NOT EXISTS (SELECT 1 FROM {$spins} sw WHERE sw.entry_id = e.id AND sw.outcome = 'win')";
        }

        if ('yes' === $filters['redeemed']) {
            $clauses[] = "EXISTS (SELECT 1 FROM {$spins} sr WHERE sr.entry_id = e.id AND sr.redeemed_at IS NOT NULL)";
        } elseif ('no' === $filters['redeemed']) {
            $clauses[] = "EXISTS (SELECT 1 FROM {$spins} sr WHERE sr.entry_id = e.id AND sr.outcome = 'win')
                          AND NOT EXISTS (SELECT 1 FROM {$spins} sd WHERE sd.entry_id = e.id AND sd.redeemed_at IS NOT NULL)";
        }

        if ('' !== $filters['email_status']) {
            $clauses[] = $wpdb->prepare('e.email_status = %s', $filters['email_status']);
        }

        return implode(' AND ', $clauses);
    }

    public static function count(array $filters): int
    {
        global $wpdb;

        $entries = CIKW_Activator::table_name('entries');
        $where = self::where($filters);

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$entries} e WHERE {$where}");
    }

    /**
     * One row per entry, with the spins aggregated for display.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_entries(array $filters, int $per_page, int $page, string $orderby = 'created_at', string $order = 'DESC'): array
    {
        global $wpdb;

        $entries = CIKW_Activator::table_name('entries');
        $spins = CIKW_Activator::table_name('spins');
        $prizes = CIKW_Activator::table_name('prizes');
        $categories = CIKW_Activator::table_name('categories');

        $where = self::where($filters);
        $orderby = self::sanitize_orderby($orderby);
        $order = 'ASC' === strtoupper($order) ? 'ASC' : 'DESC';
        $offset = max(0, ($page - 1) * $per_page);

        // GROUP_CONCAT has no ORDER BY here on purpose: combining it with
        // DISTINCT is not portable across MySQL and MariaDB.
        $sql = $wpdb->prepare(
            "SELECT e.*,
                    COUNT(DISTINCT s.id) AS spin_count,
                    COUNT(DISTINCT CASE WHEN s.outcome = 'win' THEN s.id END) AS win_count,
                    GROUP_CONCAT(DISTINCT c.name SEPARATOR ' | ') AS category_names,
                    GROUP_CONCAT(DISTINCT p.title SEPARATOR ' | ') AS prize_titles,
                    GROUP_CONCAT(DISTINCT s.voucher_code SEPARATOR ' | ') AS voucher_codes,
                    MAX(s.redeemed_at) AS redeemed_at,
                    nc.name AS need_category_name
               FROM {$entries} e
               LEFT JOIN {$spins} s ON s.entry_id = e.id
               LEFT JOIN {$prizes} p ON p.id = s.prize_id
               LEFT JOIN {$categories} c ON c.id = s.category_id
               LEFT JOIN {$categories} nc ON nc.id = e.need_category_id
              WHERE {$where}
              GROUP BY e.id
              ORDER BY e.{$orderby} {$order}, e.id {$order}
              LIMIT %d OFFSET %d",
            $per_page,
            $offset
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * One row per spin, joined to the participant, for the CSV export.
     *
     * Yields in chunks so a three-day show's worth of rows never has to sit
     * in memory at once.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public static function stream_spin_rows(array $filters): Generator
    {
        global $wpdb;

        $entries = CIKW_Activator::table_name('entries');
        $spins = CIKW_Activator::table_name('spins');
        $prizes = CIKW_Activator::table_name('prizes');
        $categories = CIKW_Activator::table_name('categories');

        $where = self::where($filters);
        $last_entry = 0;
        $last_spin = 0;

        while (true) {
            // Keyset pagination: OFFSET would get slower with every chunk and
            // can skip rows if anything is written while the export runs.
            $sql = $wpdb->prepare(
                "SELECT e.id AS entry_id, e.created_at, e.full_name, e.email, e.phone,
                        e.company, e.role, e.consent_marketing, e.consent_text_version,
                        e.device_id, e.email_status, e.finished_at,
                        nc.name AS need_category_name,
                        s.id AS spin_id, s.spin_number, s.outcome, s.voucher_code,
                        s.redeemed_at, s.redeemed_by, s.created_at AS spin_created_at,
                        c.name AS category_name,
                        p.title AS prize_title
                   FROM {$entries} e
                   LEFT JOIN {$spins} s ON s.entry_id = e.id
                   LEFT JOIN {$prizes} p ON p.id = s.prize_id
                   LEFT JOIN {$categories} c ON c.id = s.category_id
                   LEFT JOIN {$categories} nc ON nc.id = e.need_category_id
                  WHERE {$where}
                    AND (e.id > %d OR (e.id = %d AND COALESCE(s.id, 0) > %d))
                  ORDER BY e.id ASC, COALESCE(s.id, 0) ASC
                  LIMIT %d",
                $last_entry,
                $last_entry,
                $last_spin,
                self::EXPORT_CHUNK
            );

            $rows = $wpdb->get_results($sql, ARRAY_A);

            if (empty($rows)) {
                return;
            }

            foreach ($rows as $row) {
                yield $row;
            }

            $tail = end($rows);
            $last_entry = (int) $tail['entry_id'];
            $last_spin = (int) ($tail['spin_id'] ?? 0);
        }
    }

    /**
     * Categories for the filter dropdown, including inactive ones - old
     * participations still point at them.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function filter_categories(): array
    {
        global $wpdb;

        $table = CIKW_Activator::table_name('categories');
        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT id, name FROM %i ORDER BY segment_order ASC, id ASC', $table),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    private static function sanitize_date(string $value): string
    {
        return 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    private static function sanitize_orderby(string $orderby): string
    {
        $allowed = ['created_at', 'full_name', 'email', 'company', 'email_status'];

        return in_array($orderby, $allowed, true) ? $orderby : 'created_at';
    }
}
