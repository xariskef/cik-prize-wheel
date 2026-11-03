<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Voucher lookup and redemption.
 *
 * Shared by the REST endpoint (booth staff with the staff key) and the
 * wp-admin redemption screen, so the two can never apply different rules -
 * the same principle as CIKW_Entries_Query backing both the list and export.
 */
final class CIKW_Vouchers
{
    /**
     * Look a code up and return everything the person holding it needs to
     * see: which prize, who won it, and whether it has already been handed
     * over.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $code): ?array
    {
        global $wpdb;

        $code = self::normalise($code);

        if ('' === $code) {
            return null;
        }

        $spins = CIKW_Activator::table_name('spins');
        $entries = CIKW_Activator::table_name('entries');
        $prizes = CIKW_Activator::table_name('prizes');
        $categories = CIKW_Activator::table_name('categories');

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT s.id AS spin_id, s.voucher_code, s.redeemed_at, s.redeemed_by,
                        s.created_at AS won_at, s.prize_id,
                        p.title AS prize_title, p.description AS prize_description,
                        p.image_id AS prize_image_id,
                        c.name AS category_name,
                        e.id AS entry_id, e.full_name, e.email, e.phone, e.company
                   FROM %i s
                   LEFT JOIN %i p ON p.id = s.prize_id
                   LEFT JOIN %i c ON c.id = s.category_id
                   LEFT JOIN %i e ON e.id = s.entry_id
                  WHERE s.voucher_code = %s",
                $spins,
                $prizes,
                $categories,
                $entries,
                $code
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Mark a voucher as handed over.
     *
     * The `redeemed_at IS NULL` condition in the UPDATE is what makes this
     * safe when two people scan the same code at the same moment: exactly one
     * of them gets the row, the other is told it is already redeemed.
     *
     * @return array<string, mixed>|WP_Error
     */
    public static function redeem(string $code, string $staff): array|WP_Error
    {
        global $wpdb;

        $code = self::normalise($code);
        $staff = mb_substr(trim($staff), 0, 100);
        $voucher = self::find($code);

        if (null === $voucher) {
            return new WP_Error(
                'cikw_voucher_not_found',
                __('Code not found.', 'cik-prize-wheel'),
                ['status' => 404]
            );
        }

        if (null !== $voucher['redeemed_at']) {
            return new WP_Error(
                'cikw_voucher_already_redeemed',
                __('This code has already been redeemed.', 'cik-prize-wheel'),
                [
                    'status' => 409,
                    'redeemed_at' => $voucher['redeemed_at'],
                    'redeemed_by' => $voucher['redeemed_by'],
                ]
            );
        }

        $now = current_time('mysql');

        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE %i SET redeemed_at = %s, redeemed_by = %s WHERE id = %d AND redeemed_at IS NULL',
            CIKW_Activator::table_name('spins'),
            $now,
            $staff,
            (int) $voucher['spin_id']
        ));

        if (1 !== (int) $updated) {
            return new WP_Error(
                'cikw_voucher_already_redeemed',
                __('This code has already been redeemed.', 'cik-prize-wheel'),
                ['status' => 409]
            );
        }

        $voucher['redeemed_at'] = $now;
        $voucher['redeemed_by'] = $staff;

        return $voucher;
    }

    /**
     * Undo a redemption. Booth staff scan the wrong code eventually, and
     * without this the only fix would be editing the database.
     */
    public static function unredeem(string $code): bool|WP_Error
    {
        global $wpdb;

        $voucher = self::find(self::normalise($code));

        if (null === $voucher) {
            return new WP_Error('cikw_voucher_not_found', __('Code not found.', 'cik-prize-wheel'));
        }

        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET redeemed_at = NULL, redeemed_by = NULL WHERE id = %d',
            CIKW_Activator::table_name('spins'),
            (int) $voucher['spin_id']
        ));

        return true;
    }

    /**
     * Every voucher, newest first, for the "what has been handed over" list.
     *
     * @param string $status 'all' | 'pending' | 'redeemed'
     * @return array<int, array<string, mixed>>
     */
    public static function all(string $status = 'all', string $search = '', int $per_page = 50, int $page = 1): array
    {
        global $wpdb;

        $spins = CIKW_Activator::table_name('spins');
        $entries = CIKW_Activator::table_name('entries');
        $prizes = CIKW_Activator::table_name('prizes');

        $where = ["s.outcome = 'win'", 's.voucher_code IS NOT NULL'];

        if ('pending' === $status) {
            $where[] = 's.redeemed_at IS NULL';
        } elseif ('redeemed' === $status) {
            $where[] = 's.redeemed_at IS NOT NULL';
        }

        if ('' !== $search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = $wpdb->prepare(
                '(s.voucher_code LIKE %s OR e.full_name LIKE %s OR e.email LIKE %s OR p.title LIKE %s)',
                $like,
                $like,
                $like,
                $like
            );
        }

        // Table names come from $wpdb->prefix and every user-supplied value in
        // $where was bound through prepare() above.
        $clause = implode(' AND ', $where);
        $offset = max(0, ($page - 1) * $per_page);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.voucher_code, s.redeemed_at, s.redeemed_by, s.created_at AS won_at,
                        p.title AS prize_title,
                        e.full_name, e.email, e.phone
                   FROM {$spins} s
                   LEFT JOIN {$prizes} p ON p.id = s.prize_id
                   LEFT JOIN {$entries} e ON e.id = s.entry_id
                  WHERE {$clause}
                  ORDER BY s.created_at DESC, s.id DESC
                  LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    public static function count(string $status = 'all', string $search = ''): int
    {
        global $wpdb;

        $spins = CIKW_Activator::table_name('spins');
        $entries = CIKW_Activator::table_name('entries');
        $prizes = CIKW_Activator::table_name('prizes');

        $where = ["s.outcome = 'win'", 's.voucher_code IS NOT NULL'];

        if ('pending' === $status) {
            $where[] = 's.redeemed_at IS NULL';
        } elseif ('redeemed' === $status) {
            $where[] = 's.redeemed_at IS NOT NULL';
        }

        if ('' !== $search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = $wpdb->prepare(
                '(s.voucher_code LIKE %s OR e.full_name LIKE %s OR e.email LIKE %s OR p.title LIKE %s)',
                $like,
                $like,
                $like,
                $like
            );
        }

        // Same as all(): identifiers are internal, values were prepared above.
        $clause = implode(' AND ', $where);

        return (int) $wpdb->get_var(
            "SELECT COUNT(*)
               FROM {$spins} s
               LEFT JOIN {$prizes} p ON p.id = s.prize_id
               LEFT JOIN {$entries} e ON e.id = s.entry_id
              WHERE {$clause}"
        );
    }

    /**
     * Totals for the summary strip, and per-prize handed-over counts - the
     * question booth staff actually get asked at the end of a day.
     *
     * @return array{total:int, redeemed:int, pending:int, by_prize:array<int, array<string, mixed>>}
     */
    public static function summary(): array
    {
        global $wpdb;

        $spins = CIKW_Activator::table_name('spins');
        $prizes = CIKW_Activator::table_name('prizes');

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE outcome = 'win' AND voucher_code IS NOT NULL",
            $spins
        ));
        $redeemed = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE outcome = 'win' AND redeemed_at IS NOT NULL",
            $spins
        ));

        $by_prize = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.title,
                        COUNT(s.id) AS won,
                        COUNT(s.redeemed_at) AS redeemed
                   FROM %i s
                   INNER JOIN %i p ON p.id = s.prize_id
                  WHERE s.outcome = 'win'
                  GROUP BY p.id, p.title
                  ORDER BY won DESC, p.title ASC",
                $spins,
                $prizes
            ),
            ARRAY_A
        );

        return [
            'total' => $total,
            'redeemed' => $redeemed,
            'pending' => $total - $redeemed,
            'by_prize' => is_array($by_prize) ? $by_prize : [],
        ];
    }

    /**
     * Codes are generated uppercase, so accept any case and ignore the
     * spaces or dashes someone might type to break the code up.
     *
     * No character is remapped. The generating alphabet excludes 0, O, 1, I
     * and L precisely so there is nothing to disambiguate - if one of those
     * arrives, the code was misread, and silently rewriting it would hand
     * over the wrong prize rather than showing "not found".
     */
    public static function normalise(string $code): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($code))) ?? '';
    }

    /**
     * True when the code contains a character the generator can never
     * produce, so the screen can say "check these characters" instead of a
     * bare "not found".
     */
    public static function has_ambiguous_characters(string $code): bool
    {
        return 1 === preg_match('/[01OIL]/', self::normalise($code));
    }
}
