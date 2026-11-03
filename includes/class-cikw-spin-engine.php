<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The spin engine, implementing spec section 5 step for step.
 *
 * Two independent rolls: one picks the category the wheel lands on, one
 * decides whether a prize is won inside that category. Landing on a category
 * is not winning.
 *
 * Every random draw goes through random_int(). Never rand()/mt_rand().
 */
final class CIKW_Spin_Engine
{
    /**
     * Probabilities are DECIMAL(6,3) percentages, so scaling by 1000 turns
     * the whole distribution into integers over a 100000-unit space with no
     * float comparison anywhere in the draw.
     */
    private const UNIT_SCALE = 1000;
    private const TOTAL_UNITS = 100000;

    /** No 0/O/1/I/L - they are misread off a screen (spec section 5 step 7). */
    private const VOUCHER_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const VOUCHER_LENGTH = 6;
    private const VOUCHER_MAX_ATTEMPTS = 5;

    /**
     * Run one spin for an entry.
     *
     * @return array|WP_Error The /spin response payload, or an error with a
     *                        403 (token), 409 (no spins left) or 500 status.
     */
    public static function spin(int $entry_id, string $entry_token): array|WP_Error
    {
        global $wpdb;

        $entries_table = CIKW_Activator::table_name('entries');
        $spins_table = CIKW_Activator::table_name('spins');

        // --- Step 1: load the entry and compare the token in constant time.
        $entry = $wpdb->get_row(
            $wpdb->prepare('SELECT id, entry_token FROM %i WHERE id = %d', $entries_table, $entry_id),
            ARRAY_A
        );

        if (null === $entry) {
            return new WP_Error(
                'cikw_entry_not_found',
                __('Η συμμετοχή δεν βρέθηκε.', 'cik-prize-wheel'),
                ['status' => 404]
            );
        }

        if (!hash_equals((string) $entry['entry_token'], $entry_token)) {
            return new WP_Error(
                'cikw_token_mismatch',
                __('Μη έγκυρο token συμμετοχής.', 'cik-prize-wheel'),
                ['status' => 403]
            );
        }

        // --- Step 2: spin budget.
        $options = CIKW_Settings::get_options();
        $max_spins = max(1, (int) $options['max_spins_per_registration']);
        $spins_used = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE entry_id = %d',
            $spins_table,
            $entry_id
        ));

        if ($spins_used >= $max_spins) {
            return new WP_Error(
                'cikw_no_spins_left',
                __('Δεν έχεις άλλες προσπάθειες.', 'cik-prize-wheel'),
                ['status' => 409]
            );
        }

        // --- Step 3: pick the category the wheel lands on.
        $categories = CIKW_Config::active_categories();

        if (empty($categories)) {
            return new WP_Error(
                'cikw_no_categories',
                __('Δεν υπάρχουν ενεργές κατηγορίες.', 'cik-prize-wheel'),
                ['status' => 500]
            );
        }

        [$segment_index, $category_log] = self::pick_category($categories);
        $category = $categories[$segment_index];
        $category_id = (int) $category['id'];

        // --- Steps 4-5: build the prize distribution and roll once.
        $policy = (string) ($options['stock_exhausted_policy'] ?? 'to_lose');
        $distribution = self::build_distribution($category_id, $policy);
        $roll = random_int(1, self::TOTAL_UNITS);
        $selected_prize_id = self::resolve_roll($roll, $distribution['eligible_units']);

        $outcome = 'lose';
        $prize = null;
        $downgraded = false;
        $claim_affected = null;

        // --- Step 6: claim the stock atomically before committing to a win.
        if (null !== $selected_prize_id) {
            $prize = $distribution['prizes_by_id'][$selected_prize_id];
            $claim_affected = self::claim_stock($prize);

            if ($claim_affected) {
                $outcome = 'win';
            } else {
                // Another kiosk took the last unit between our read and our
                // write. Do NOT re-roll - that would bias the distribution.
                $downgraded = true;
                $prize = null;
            }
        }

        $decision_log = [
            'schema' => 1,
            'decided_at' => current_time('mysql'),
            'policy' => $policy,
            'category' => $category_log,
            'prizes' => $distribution['log'],
            'no_win_units' => $distribution['no_win_units'],
            'total_units' => self::TOTAL_UNITS,
            'roll' => $roll,
            'selected_prize_id' => $selected_prize_id,
            'stock_claimed' => $claim_affected,
            'downgraded_to_lose' => $downgraded,
            'outcome' => $outcome,
            'voucher_attempts' => 0,
        ];

        // --- Steps 7-8: voucher code and the spin row, retrying on collision.
        $spin_number = $spins_used + 1;
        $inserted = self::insert_spin(
            $entry_id,
            $spin_number,
            $category_id,
            'win' === $outcome ? (int) $prize['id'] : null,
            $outcome,
            $decision_log
        );

        if (is_wp_error($inserted)) {
            // The stock is claimed but no spin row exists to account for it.
            // Hand the unit back rather than silently losing inventory.
            if ('win' === $outcome) {
                self::release_stock($prize);
            }

            return $inserted;
        }

        // --- Step 9: the response payload.
        return [
            'spin_id' => $inserted['spin_id'],
            'spin_number' => $spin_number,
            'segment_index' => $segment_index,
            'category' => [
                'id' => $category_id,
                'name' => (string) $category['name'],
                'description' => (string) $category['description'],
                'image' => CIKW_Config::image_url($category['image_id']),
            ],
            'outcome' => $outcome,
            'prize' => 'win' === $outcome ? [
                'id' => (int) $prize['id'],
                'title' => (string) $prize['title'],
                'description' => (string) $prize['description'],
                'image' => CIKW_Config::image_url($prize['image_id']),
            ] : null,
            'voucher_code' => $inserted['voucher_code'],
            'spins_remaining' => max(0, $max_spins - $spin_number),
        ];
    }

    /**
     * Step 3 - weighted pick over wheel_weight.
     *
     * @param array<int, array<string, mixed>> $categories Ordered active list.
     * @return array{0:int, 1:array} [segment_index, audit log]
     */
    private static function pick_category(array $categories): array
    {
        $units = [];
        $total = 0;
        $candidates = [];

        foreach ($categories as $index => $category) {
            $weight = (float) $category['wheel_weight'];
            $scaled = max(0, (int) round($weight * self::UNIT_SCALE));
            $units[$index] = $scaled;
            $total += $scaled;

            $candidates[] = [
                'index' => $index,
                'id' => (int) $category['id'],
                'name' => (string) $category['name'],
                'weight' => $weight,
                'units' => $scaled,
            ];
        }

        $fallback = false;

        if ($total <= 0) {
            // Every active category is weighted zero. Rather than refuse to
            // spin, treat them as equally likely - which is what the equal
            // visual segments promise anyway.
            $fallback = true;
            foreach ($units as $index => $unused) {
                $units[$index] = self::UNIT_SCALE;
                $candidates[$index]['units'] = self::UNIT_SCALE;
            }
            $total = self::UNIT_SCALE * count($units);
        }

        $roll = random_int(1, $total);
        $cursor = 0;
        $chosen = array_key_last($units);

        foreach ($units as $index => $scaled) {
            $cursor += $scaled;

            // A zero-weight category never advances the cursor, so it can
            // never be selected.
            if ($roll <= $cursor) {
                $chosen = $index;
                break;
            }
        }

        return [
            (int) $chosen,
            [
                'candidates' => $candidates,
                'total_units' => $total,
                'roll' => $roll,
                'chosen_index' => (int) $chosen,
                'equal_weight_fallback' => $fallback,
            ],
        ];
    }

    /**
     * Step 4 - the prize distribution for one category, as integer units.
     *
     * @return array{eligible_units:array<int,int>, no_win_units:int, prizes_by_id:array<int,array>, log:array}
     */
    private static function build_distribution(int $category_id, string $policy): array
    {
        global $wpdb;

        $table = CIKW_Activator::table_name('prizes');
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE category_id = %d AND is_active = 1 ORDER BY id ASC',
                $table,
                $category_id
            ),
            ARRAY_A
        );

        $rows = is_array($rows) ? $rows : [];

        $eligible_units = [];
        $prizes_by_id = [];
        $excluded_units = 0;
        $log = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $prizes_by_id[$id] = $row;

            $base_units = max(0, min(self::TOTAL_UNITS, (int) round(((float) $row['probability']) * self::UNIT_SCALE)));
            $stock_total = (int) $row['stock_total'];
            $stock_remaining = (int) $row['stock_remaining'];
            $daily_cap = null === $row['daily_cap'] ? null : (int) $row['daily_cap'];
            $awards_today = null;
            $exclusion = null;

            if ($stock_total > 0 && $stock_remaining <= 0) {
                $exclusion = 'out_of_stock';
            } elseif (null !== $daily_cap) {
                $awards_today = self::awards_today($id);

                if ($awards_today >= $daily_cap) {
                    $exclusion = 'daily_cap_reached';
                }
            }

            if (null === $exclusion) {
                $eligible_units[$id] = $base_units;
            } else {
                $excluded_units += $base_units;
            }

            $log[] = [
                'id' => $id,
                'title' => (string) $row['title'],
                'probability' => (float) $row['probability'],
                'base_units' => $base_units,
                'stock_total' => $stock_total,
                'stock_remaining' => $stock_remaining,
                'daily_cap' => $daily_cap,
                'awards_today' => $awards_today,
                'eligible' => null === $exclusion,
                'exclusion_reason' => $exclusion,
            ];
        }

        $redistributed = false;

        if ('redistribute' === $policy && $excluded_units > 0) {
            $eligible_total = array_sum($eligible_units);

            if ($eligible_total > 0) {
                $redistributed = true;

                foreach ($eligible_units as $id => $units) {
                    // floor() keeps the redistributed total from creeping
                    // over the excluded share through rounding.
                    $eligible_units[$id] = $units + (int) floor($excluded_units * $units / $eligible_total);
                }
            }
        }

        $prize_units_total = array_sum($eligible_units);
        $clamped = false;

        if ($prize_units_total > self::TOTAL_UNITS) {
            // The admin screen blocks saving over 100% per category, but rows
            // predating that check (or edited straight in the DB) must not be
            // able to push the "no win" share negative.
            $clamped = true;
            $scale = self::TOTAL_UNITS / $prize_units_total;

            foreach ($eligible_units as $id => $units) {
                $eligible_units[$id] = (int) floor($units * $scale);
            }

            $prize_units_total = array_sum($eligible_units);
        }

        foreach ($log as $index => $entry) {
            $log[$index]['final_units'] = $eligible_units[$entry['id']] ?? 0;
        }

        return [
            'eligible_units' => $eligible_units,
            'no_win_units' => max(0, self::TOTAL_UNITS - $prize_units_total),
            'prizes_by_id' => $prizes_by_id,
            'log' => [
                'rows' => $log,
                'excluded_units' => $excluded_units,
                'redistributed' => $redistributed,
                'clamped' => $clamped,
            ],
        ];
    }

    /**
     * Step 5 - walk the cumulative distribution. A roll past the last prize
     * falls into the "no win" remainder.
     *
     * @param array<int, int> $eligible_units
     */
    private static function resolve_roll(int $roll, array $eligible_units): ?int
    {
        $cursor = 0;

        foreach ($eligible_units as $prize_id => $units) {
            if ($units <= 0) {
                continue;
            }

            $cursor += $units;

            if ($roll <= $cursor) {
                return (int) $prize_id;
            }
        }

        return null;
    }

    /**
     * Step 6 - the atomic claim. This conditional UPDATE is the lock: it is
     * atomic in InnoDB with no explicit transaction, which is the whole point.
     * Never replace it with a read-then-write.
     *
     * @return bool True when this request won the unit.
     */
    private static function claim_stock(array $prize): bool
    {
        global $wpdb;

        // Unlimited stock has nothing to claim, and decrementing it would
        // drive stock_remaining negative for no reason.
        if (0 === (int) $prize['stock_total']) {
            return true;
        }

        $table = CIKW_Activator::table_name('prizes');
        $affected = $wpdb->query($wpdb->prepare(
            'UPDATE %i SET stock_remaining = stock_remaining - 1 WHERE id = %d AND (stock_total = 0 OR stock_remaining > 0)',
            $table,
            (int) $prize['id']
        ));

        return is_numeric($affected) && (int) $affected === 1;
    }

    /**
     * Undo a claim when the spin row could not be written.
     */
    private static function release_stock(array $prize): void
    {
        global $wpdb;

        if (0 === (int) $prize['stock_total']) {
            return;
        }

        $table = CIKW_Activator::table_name('prizes');
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET stock_remaining = stock_remaining + 1 WHERE id = %d AND stock_remaining < stock_total',
            $table,
            (int) $prize['id']
        ));
    }

    /**
     * Step 8 - insert the spin row, regenerating the voucher on a UNIQUE
     * collision. The UNIQUE index on voucher_code is what makes this safe
     * under concurrency; a SELECT-then-INSERT check would not be.
     *
     * @return array{spin_id:int, voucher_code:?string}|WP_Error
     */
    private static function insert_spin(
        int $entry_id,
        int $spin_number,
        int $category_id,
        ?int $prize_id,
        string $outcome,
        array $decision_log
    ): array|WP_Error {
        global $wpdb;

        $table = CIKW_Activator::table_name('spins');
        $is_win = 'win' === $outcome;
        $max_attempts = $is_win ? self::VOUCHER_MAX_ATTEMPTS : 1;
        $suppress = $wpdb->suppress_errors(true);

        try {
            for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
                $voucher = $is_win ? self::generate_voucher_code() : null;
                $decision_log['voucher_attempts'] = $attempt;

                $result = $wpdb->insert(
                    $table,
                    [
                        'entry_id' => $entry_id,
                        'spin_number' => $spin_number,
                        'category_id' => $category_id,
                        'prize_id' => $prize_id,
                        'outcome' => $outcome,
                        'voucher_code' => $voucher,
                        'decision_log' => (string) wp_json_encode($decision_log, JSON_UNESCAPED_UNICODE),
                        'created_at' => current_time('mysql'),
                    ],
                    ['%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s']
                );

                if (false !== $result) {
                    return [
                        'spin_id' => (int) $wpdb->insert_id,
                        'voucher_code' => $voucher,
                    ];
                }
            }
        } finally {
            $wpdb->suppress_errors($suppress);
        }

        return new WP_Error(
            'cikw_spin_write_failed',
            __('Η περιστροφή δεν καταγράφηκε. Δοκίμασε ξανά.', 'cik-prize-wheel'),
            ['status' => 500]
        );
    }

    /**
     * Step 7 - 6 characters from an alphabet with no visually ambiguous glyphs.
     */
    private static function generate_voucher_code(): string
    {
        $alphabet = self::VOUCHER_ALPHABET;
        $max_index = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < self::VOUCHER_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $max_index)];
        }

        return $code;
    }

    /**
     * Awards of one prize on the current calendar day.
     *
     * created_at is written with current_time('mysql'), i.e. the WordPress
     * timezone, so the day boundary is compared against the WordPress date
     * rather than the database server's CURDATE().
     */
    private static function awards_today(int $prize_id): int
    {
        global $wpdb;

        $table = CIKW_Activator::table_name('spins');

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE prize_id = %d AND outcome = %s AND DATE(created_at) = %s',
            $table,
            $prize_id,
            'win',
            current_time('Y-m-d')
        ));
    }
}
