<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Demo dataset for the wheel: 12 product categories and 48 prizes for a
 * fictional exhibitor at a consumer-electronics trade show.
 *
 * ASSUMPTION: every category name, description, product name, probability,
 * stock figure and daily cap in this file is plausible placeholder data.
 * Replace this whole dataset with the real event data when it lands -
 * nothing else depends on the specific values.
 *
 * The dataset is deliberately uneven so a dry run exercises every branch of
 * the spin engine rather than one happy path. See edge_case_notes().
 *
 * Used by the activation hook (only when the table is empty) and by
 * tools/seed-demo-data.php (which can reset and re-seed at will).
 */
final class CIKW_Demo_Data
{
    /**
     * Every simulated visitor carries this device_id, so demo rows can always
     * be told apart from real participants and removed on their own.
     */
    public const DEMO_DEVICE_ID = 'demo-seed';

    /**
     * ASSUMPTION: 12 categories. Past roughly 12 segments the wheel labels
     * stop being legible from a metre away, so this sits at the practical
     * ceiling. If an event needs more, the wheel design has to change - do
     * not just shrink the font.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function categories(): array
    {
        return [
            [
                'slug' => 'smart-home',
                'name' => 'Smart Home',
                'description' => 'Connected lighting, sensors and hubs that automate everyday routines at home and in small offices.',
                'color' => '#0b6fb4',
                'prizes' => [
                    ['title' => 'Smart plug', 'description' => 'Wi-Fi smart plug with energy monitoring, ready to try in your own home.', 'probability' => 9.0, 'stock_total' => 40, 'stock_remaining' => 40],
                    ['title' => 'Smart home buyer\'s guide', 'description' => 'Printed guide comparing hubs, protocols and sensors for every room.', 'probability' => 15.0, 'stock_total' => 0, 'stock_remaining' => 0],
                    ['title' => 'Notebook & pen set', 'description' => 'Event notebook and pen.', 'probability' => 11.0, 'stock_total' => 150, 'stock_remaining' => 150],
                    ['title' => 'Smart bulb starter kit', 'description' => 'Two colour smart bulbs and a wireless dimmer switch.', 'probability' => 4.0, 'stock_total' => 25, 'stock_remaining' => 25],
                ],
            ],
            [
                'slug' => 'home-audio',
                'name' => 'Home Audio',
                'description' => 'Wireless speakers, soundbars and streaming amplifiers for living rooms, studios and open-plan spaces.',
                'color' => '#1aa0dc',
                'prizes' => [
                    ['title' => 'Bluetooth mini speaker', 'description' => 'Pocket-sized waterproof speaker with a 10-hour battery.', 'probability' => 8.0, 'stock_total' => 35, 'stock_remaining' => 35],
                    ['title' => 'Home audio setup guide', 'description' => 'Speaker placement and room acoustics explained step by step.', 'probability' => 14.0, 'stock_total' => 0, 'stock_remaining' => 0],
                    ['title' => 'Notebook & pen set', 'description' => 'Event notebook and pen.', 'probability' => 11.0, 'stock_total' => 150, 'stock_remaining' => 150],
                    // Edge case: sold out. Its share moves to "no win" under the
                    // default to_lose policy, or to the other prizes under redistribute.
                    ['title' => 'Studio headphones', 'description' => 'Closed-back studio headphones for mixing and monitoring.', 'probability' => 3.0, 'stock_total' => 10, 'stock_remaining' => 0],
                ],
            ],
            [
                'slug' => 'networking',
                'name' => 'Networking',
                'description' => 'Mesh Wi-Fi, switches and access points for reliable coverage in homes, shops and warehouses.',
                'color' => '#f58220',
                'prizes' => [
                    ['title' => 'Travel router', 'description' => 'Compact router that turns any hotel connection into a private network.', 'probability' => 7.0, 'stock_total' => 30, 'stock_remaining' => 30],
                    ['title' => 'Network planning guide', 'description' => 'Choosing a network layout by floor area, walls and number of devices.', 'probability' => 14.0, 'stock_total' => 0, 'stock_remaining' => 0],
                    ['title' => 'Notebook & pen set', 'description' => 'Event notebook and pen.', 'probability' => 10.0, 'stock_total' => 150, 'stock_remaining' => 150],
                    // Edge case: the headline prize. Low probability, tiny stock,
                    // and a daily cap so it cannot all go on day one.
                    ['title' => 'Mesh Wi-Fi system', 'description' => 'Three-node mesh Wi-Fi system covering a whole house.', 'probability' => 1.5, 'stock_total' => 6, 'stock_remaining' => 6, 'daily_cap' => 2],
                ],
            ],
            [
                'slug' => 'wearables',
                'name' => 'Wearables',
                'description' => 'Fitness trackers and smartwatches with long battery life and accurate health and activity sensors.',
                'color' => '#d94f1e',
                'prizes' => [
                    ['title' => 'Watch strap set', 'description' => 'Three interchangeable straps in different colours and materials.', 'probability' => 10.0, 'stock_total' => 60, 'stock_remaining' => 60],
                    ['title' => 'Wearables buyer\'s guide', 'description' => 'Battery life, sensors and compatibility compared side by side.', 'probability' => 14.0, 'stock_total' => 0, 'stock_remaining' => 0],
                    ['title' => 'Notebook & pen set', 'description' => 'Event notebook and pen.', 'probability' => 10.0, 'stock_total' => 150, 'stock_remaining' => 150],
                    ['title' => 'Event tote bag', 'description' => 'Reusable cotton tote bag.', 'probability' => 6.0, 'stock_total' => 80, 'stock_remaining' => 80],
                ],
            ],
            [
                'slug' => 'mobile-accessories',
                'name' => 'Mobile Accessories',
                'description' => 'Chargers, cables, cases and power banks designed to keep phones and tablets running all day.',
                'color' => '#6c757d',
                'prizes' => [
                    ['title' => 'Power bank', 'description' => '10,000 mAh power bank with USB-C fast charging.', 'probability' => 8.0, 'stock_total' => 40, 'stock_remaining' => 40],
                    ['title' => 'Charging guide', 'description' => 'Cables, wattages and charging standards made simple.', 'probability' => 15.0, 'stock_total' => 0, 'stock_remaining' => 0],
                    ['title' => 'Notebook & pen set', 'description' => 'Event notebook and pen.', 'probability' => 11.0, 'stock_total' => 150, 'stock_remaining' => 150],
                    ['title' => 'Fast wall charger', 'description' => 'Dual-port 65 W wall charger for phones and laptops.', 'probability' => 4.0, 'stock_total' => 30, 'stock_remaining' => 30],
                ],
            ],
            [
                'slug' => 'office-tech',
                'name' => 'Office Tech',
                'description' => 'Docking stations, monitors and peripherals that make hybrid and home-office desks more productive.',
                'color' => '#98a2ac',
                'prizes' => [
                    ['title' => 'Home office guide', 'description' => 'Ergonomics, lighting and connectivity checklists for a better desk.', 'probability' => 16.0, 'stock_total' => 0, 'stock_remaining' => 0],
                    ['title' => 'Notebook & pen set', 'description' => 'Event notebook and pen.', 'probability' => 12.0, 'stock_total' => 150, 'stock_remaining' => 150],
                    ['title' => 'Cable organiser kit', 'description' => 'Reusable cable ties and desk clips.', 'probability' => 7.0, 'stock_total' => 100, 'stock_remaining' => 100],
                    // Edge case: deactivated row. Must never be offered, and its
                    // probability must not count toward the category total.
                    ['title' => 'Wireless mouse', 'description' => 'Withdrawn from the draw - kept as an inactive row.', 'probability' => 9.0, 'stock_total' => 20, 'stock_remaining' => 20, 'is_active' => 0],
                ],
            ],
            [
                'slug' => 'cameras-imaging',
                'name' => 'Cameras & Imaging',
                'description' => 'Action cameras, webcams and accessories for content creators, streamers and video conferencing.',
                'color' => '#8dc63f',
                'prizes' => [
                    ['title' => 'Lens cleaning kit', 'description' => 'Blower, brush and microfibre cloth in a travel pouch.', 'probability' => 8.0, 'stock_total' => 35, 'stock_remaining' => 35],
                    ['title' => 'Creator lighting guide', 'description' => 'Simple lighting setups for video calls and streaming.', 'probability' => 15.0, 'stock_total' => 0, 'stock_remaining' => 0],
                    ['title' => 'Notebook & pen set', 'description' => 'Event notebook and pen.', 'probability' => 11.0, 'stock_total' => 150, 'stock_remaining' => 150],
                    ['title' => 'Action camera', 'description' => '4K action camera with waterproof housing.', 'probability' => 2.0, 'stock_total' => 12, 'stock_remaining' => 12, 'daily_cap' => 4],
                ],
            ],
            [
                'slug' => 'gaming',
                'name' => 'Gaming',
                'description' => 'Controllers, headsets and accessories for console and PC gaming, from casual players to competitive teams.',
                'color' => '#7d3c98',
                'prizes' => [
                    ['title' => 'Controller grip set', 'description' => 'Silicone thumb grips and a protective controller skin.', 'probability' => 12.0, 'stock_total' => 90, 'stock_remaining' => 90],
                    ['title' => 'Gaming setup guide', 'description' => 'Display settings, latency and audio tips for every platform.', 'probability' => 14.0, 'stock_total' => 0, 'stock_remaining' => 0],
                    ['title' => 'Notebook & pen set', 'description' => 'Event notebook and pen.', 'probability' => 10.0, 'stock_total' => 150, 'stock_remaining' => 150],
                    ['title' => 'Gaming mouse pad', 'description' => 'Extra-large stitched-edge mouse pad.', 'probability' => 5.0, 'stock_total' => 50, 'stock_remaining' => 50],
                ],
            ],
            [
                'slug' => 'outdoor-tech',
                'name' => 'Outdoor Tech',
                'description' => 'Rugged lights, solar chargers and weatherproof gear for camping, hiking and outdoor work.',
                'color' => '#c0392b',
                'prizes' => [
                    ['title' => 'Folding solar charger', 'description' => 'Foldable solar panel with a USB output.', 'probability' => 8.0, 'stock_total' => 30, 'stock_remaining' => 30],
                    ['title' => 'Outdoor power guide', 'description' => 'Keeping devices charged off-grid, from day hikes to week-long trips.', 'probability' => 14.0, 'stock_total' => 0, 'stock_remaining' => 0],
                    ['title' => 'Notebook & pen set', 'description' => 'Event notebook and pen.', 'probability' => 11.0, 'stock_total' => 150, 'stock_remaining' => 150],
                    ['title' => 'Rechargeable headlamp', 'description' => 'USB-rechargeable LED headlamp.', 'probability' => 6.0, 'stock_total' => 70, 'stock_remaining' => 70],
                ],
            ],
            [
                'slug' => 'kitchen-appliances',
                'name' => 'Kitchen Appliances',
                'description' => 'Compact, energy-efficient appliances for modern kitchens, from blenders to smart coffee machines.',
                'color' => '#a0522d',
                'prizes' => [
                    ['title' => 'Coffee sample pack', 'description' => 'Three single-origin coffee samples.', 'probability' => 9.0, 'stock_total' => 35, 'stock_remaining' => 35],
                    ['title' => 'Energy-saving kitchen guide', 'description' => 'How to cut appliance running costs without changing how you cook.', 'probability' => 14.0, 'stock_total' => 0, 'stock_remaining' => 0],
                    ['title' => 'Notebook & pen set', 'description' => 'Event notebook and pen.', 'probability' => 11.0, 'stock_total' => 150, 'stock_remaining' => 150],
                    ['title' => 'Event tote bag', 'description' => 'Reusable cotton tote bag.', 'probability' => 6.0, 'stock_total' => 80, 'stock_remaining' => 80],
                ],
            ],
            [
                'slug' => 'fitness-gear',
                'name' => 'Fitness Gear',
                'description' => 'Connected fitness equipment and accessories for home workouts, gyms and personal trainers.',
                'color' => '#16a085',
                'prizes' => [
                    ['title' => 'Resistance band set', 'description' => 'Five resistance bands of increasing strength.', 'probability' => 10.0, 'stock_total' => 60, 'stock_remaining' => 60],
                    ['title' => 'Home workout guide', 'description' => 'Twelve-week training plans that need no gym.', 'probability' => 13.0, 'stock_total' => 0, 'stock_remaining' => 0],
                    ['title' => 'Notebook & pen set', 'description' => 'Event notebook and pen.', 'probability' => 10.0, 'stock_total' => 150, 'stock_remaining' => 150],
                    ['title' => 'Insulated water bottle', 'description' => 'Stainless steel bottle that keeps drinks cold for 24 hours.', 'probability' => 4.0, 'stock_total' => 40, 'stock_remaining' => 40],
                ],
            ],
            [
                'slug' => 'kids-learning',
                'name' => 'Kids & Learning',
                'description' => 'Educational kits, coding robots and tablets that help children learn through play.',
                'color' => '#00a0af',
                'prizes' => [
                    ['title' => 'Mini coding robot', 'description' => 'Screen-free coding robot for ages 5 and up.', 'probability' => 8.0, 'stock_total' => 25, 'stock_remaining' => 25],
                    ['title' => 'STEM activity guide', 'description' => 'Thirty science and coding activities to do at home.', 'probability' => 14.0, 'stock_total' => 0, 'stock_remaining' => 0],
                    ['title' => 'Notebook & pen set', 'description' => 'Event notebook and pen.', 'probability' => 11.0, 'stock_total' => 150, 'stock_remaining' => 150],
                    ['title' => 'Cap & water bottle set', 'description' => 'Summer giveaway set.', 'probability' => 7.0, 'stock_total' => 90, 'stock_remaining' => 90],
                ],
            ],
        ];
    }

    /**
     * Human-readable summary of the deliberate edge cases in this dataset, so
     * whoever runs the dry run knows what they are supposed to see.
     *
     * @return array<int, string>
     */
    public static function edge_case_notes(): array
    {
        return [
            '"Studio headphones" (Home Audio) ships sold out - stock_remaining 0 of 10. Exercises the stock_exhausted_policy branch.',
            '"Mesh Wi-Fi system" (Networking) has stock 6 and daily_cap 2 - the headline prize, capped so it cannot all go on day one.',
            '"Action camera" (Cameras & Imaging) has stock 12 and daily_cap 4.',
            '"Wireless mouse" (Office Tech) is is_active = 0 - must never be awarded and must not count toward the category total.',
            'Every "guide" prize has stock_total 0, i.e. unlimited - the engine must never issue a stock UPDATE for these.',
            'Win rates run 32-41% per category, so roughly 6 in 10 spins are a legitimate no-win.',
        ];
    }

    /**
     * Insert the dataset.
     *
     * @param bool $reset Wipe categories, prizes, entries and spins first.
     * @return array{categories:int, prizes:int, skipped:bool}
     */
    public static function install(bool $reset = false): array
    {
        global $wpdb;

        $categories_table = CIKW_Activator::table_name('categories');
        $prizes_table = CIKW_Activator::table_name('prizes');

        if ($reset) {
            self::wipe();
        }

        $existing = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $categories_table));

        if ($existing > 0) {
            return ['categories' => 0, 'prizes' => 0, 'skipped' => true];
        }

        $category_count = 0;
        $prize_count = 0;

        foreach (self::categories() as $index => $category) {
            $wpdb->insert(
                $categories_table,
                [
                    'slug' => sanitize_title($category['slug']),
                    'name' => $category['name'],
                    'description' => $category['description'],
                    'image_id' => null,
                    'color' => $category['color'],
                    // Equal weights: the segments are equal in size, so equal
                    // odds are the only honest configuration.
                    'wheel_weight' => 1.000,
                    'segment_order' => $index + 1,
                    'is_active' => $category['is_active'] ?? 1,
                ],
                ['%s', '%s', '%s', '%d', '%s', '%f', '%d', '%d']
            );

            $category_id = (int) $wpdb->insert_id;

            if ($category_id <= 0) {
                continue;
            }

            $category_count++;

            foreach ($category['prizes'] as $prize) {
                $wpdb->insert(
                    $prizes_table,
                    [
                        'category_id' => $category_id,
                        'title' => $prize['title'],
                        'description' => $prize['description'],
                        'image_id' => null,
                        'probability' => $prize['probability'],
                        'stock_total' => $prize['stock_total'],
                        'stock_remaining' => $prize['stock_remaining'],
                        'daily_cap' => $prize['daily_cap'] ?? null,
                        'is_active' => $prize['is_active'] ?? 1,
                    ],
                    ['%d', '%s', '%s', '%d', '%f', '%d', '%d', '%d', '%d']
                );

                $prize_count++;
            }
        }

        return ['categories' => $category_count, 'prizes' => $prize_count, 'skipped' => false];
    }

    /**
     * Delete all wheel data. Only ever called explicitly - never on activation.
     */
    public static function wipe(): void
    {
        global $wpdb;

        foreach (['spins', 'entries', 'prizes', 'categories'] as $table) {
            $wpdb->query($wpdb->prepare('DELETE FROM %i', CIKW_Activator::table_name($table)));
        }
    }

    /**
     * Row counts for the admin status panel.
     *
     * @return array{categories:int, prizes:int, entries:int, demo_entries:int, spins:int, wins:int}
     */
    public static function counts(): array
    {
        global $wpdb;

        $entries = CIKW_Activator::table_name('entries');
        $spins = CIKW_Activator::table_name('spins');

        return [
            'categories' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', CIKW_Activator::table_name('categories'))),
            'prizes' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', CIKW_Activator::table_name('prizes'))),
            'entries' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $entries)),
            'demo_entries' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE device_id = %s', $entries, self::DEMO_DEVICE_ID)),
            'spins' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $spins)),
            'wins' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE outcome = %s', $spins, 'win')),
        ];
    }

    /**
     * Create simulated visitors and run their spins through the real engine,
     * so a dry run produces genuine spin rows, vouchers and decision_log
     * snapshots rather than hand-written fixtures.
     *
     * Note on daily caps: the engine stamps every spin with the current time,
     * so all simulated spins count as "today" no matter how the entries are
     * dated. Caps will therefore bind quickly during a large simulation -
     * that is the cap logic working, not a bug.
     *
     * @return array{visitors:int, spins:int, wins:int, errors:int}
     */
    public static function simulate_visitors(int $count): array
    {
        global $wpdb;

        $options = CIKW_Settings::get_options();
        $max_spins = max(1, (int) $options['max_spins_per_registration']);
        $category_ids = array_map(
            static fn(array $category): int => (int) $category['id'],
            CIKW_Config::active_categories()
        );

        $spins = 0;
        $wins = 0;
        $errors = 0;
        $created_visitors = 0;
        $now = time();

        for ($i = 0; $i < $count; $i++) {
            $token = CIKW_Security::generate_entry_token();

            // Spread entries across the three show days so the date
            // filters and CSV export have something realistic to work with.
            $created = gmdate('Y-m-d H:i:s', $now - random_int(0, 3 * DAY_IN_SECONDS));

            $inserted = $wpdb->insert(
                CIKW_Activator::table_name('entries'),
                [
                    'created_at' => $created,
                    'full_name' => self::person_name(),
                    'email' => 'demo-' . $now . '-' . $i . '@example.test',
                    'phone' => self::phone(),
                    'company' => 0 === $i % 5 ? null : self::company(),
                    'role' => self::role(),
                    'need_category_id' => $category_ids ? $category_ids[array_rand($category_ids)] : null,
                    'consent_marketing' => random_int(0, 100) < 65 ? 1 : 0,
                    'consent_text_version' => substr((string) $options['consent_version'], 0, 20),
                    'consent_at' => $created,
                    'entry_token' => $token,
                    'device_id' => self::DEMO_DEVICE_ID,
                    'ip_hash' => null,
                    // 'skipped' so the cron worker can never email
                    // these fabricated addresses.
                    'email_status' => 'skipped',
                    'email_attempts' => 0,
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
            );

            if (false === $inserted) {
                $errors++;
                continue;
            }

            $entry_id = (int) $wpdb->insert_id;
            $created_visitors++;

            // Most visitors use every spin; some walk away after one.
            $take = random_int(0, 100) < 80 ? $max_spins : 1;

            for ($s = 0; $s < $take; $s++) {
                $spin = CIKW_Spin_Engine::spin($entry_id, $token);

                if (is_wp_error($spin)) {
                    $errors++;
                    break;
                }

                $spins++;

                if ('win' === $spin['outcome']) {
                    $wins++;
                }
            }

            $wpdb->update(
                CIKW_Activator::table_name('entries'),
                ['finished_at' => $created],
                ['id' => $entry_id],
                ['%s'],
                ['%d']
            );
        }

        return ['visitors' => $created_visitors, 'spins' => $spins, 'wins' => $wins, 'errors' => $errors];
    }

    /**
     * Remove only the simulated visitors and their spins, leaving real
     * participants untouched. This is the safe cleanup after a dry run.
     *
     * @return int Entries deleted.
     */
    public static function delete_demo_entries(): int
    {
        global $wpdb;

        $entries_table = CIKW_Activator::table_name('entries');
        $spins_table = CIKW_Activator::table_name('spins');

        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM %i WHERE device_id = %s',
            $entries_table,
            self::DEMO_DEVICE_ID
        ));

        if (empty($ids)) {
            return 0;
        }

        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM %i WHERE entry_id IN ({$placeholders})",
            $spins_table,
            ...$ids
        ));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM %i WHERE id IN ({$placeholders})",
            $entries_table,
            ...$ids
        ));

        return count($ids);
    }

    /**
     * Put every prize's remaining stock back to its total, for a fresh dry run.
     *
     * @return int Rows updated.
     */
    public static function reset_all_stock(): int
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare(
            'UPDATE %i SET stock_remaining = stock_total',
            CIKW_Activator::table_name('prizes')
        ));
    }

    /* ------------------------------------------------------------------
     * Fabricated personal details for simulated visitors.
     *
     * ASSUMPTION: all names and companies below are invented. They exist
     * only so the entries list and CSV export look realistic during a dry
     * run - none of them refer to real people or businesses.
     * --------------------------------------------------------------- */

    private static function person_name(): string
    {
        $first = ['Alex', 'Maria', 'Daniel', 'Helen', 'Nick', 'Kate', 'Thomas',
                  'Sophia', 'Chris', 'Anna', 'Peter', 'Christina', 'Steven', 'Laura',
                  'Anthony', 'Diana', 'Michael', 'Joanna', 'George', 'Angela'];
        $last = ['Parker', 'Evans', 'Morgan', 'Nicholson', 'Bennett', 'Dawson',
                 'Hughes', 'Carter', 'Mitchell', 'Stevens', 'Palmer', 'Walsh',
                 'Turner', 'Spencer', 'Lambert', 'Kennedy'];

        return $first[array_rand($first)] . ' ' . $last[array_rand($last)];
    }

    private static function company(): string
    {
        $names = ['Acme Retail Ltd', 'Bluepeak Distribution', 'Harbour Electronics',
                  'Summit Trading Co', 'Oakline Systems', 'Riverside Stores',
                  'Lumen Interiors', 'Polar Logistics', 'Greenfield Supplies',
                  'Nova Office Solutions', 'Brightway Installers', 'Keystone Consulting'];

        return $names[array_rand($names)];
    }

    private static function role(): string
    {
        $roles = ['Retail buyer', 'Store owner', 'Installer', 'IT manager',
                  'Distributor', 'Procurement officer', 'Architect', 'Project manager',
                  'Consumer', 'Engineer'];

        return $roles[array_rand($roles)];
    }

    private static function phone(): string
    {
        // Ten digits, matching the kiosk's phone validation.
        return (random_int(0, 1) ? '69' : '21')
            . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }
}
