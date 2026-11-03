<?php
/**
 * Load the demo dataset and, optionally, simulate a stretch of the trade show.
 *
 * Unlike the activation hook (which only seeds an empty table), this can be
 * re-run as often as you like. Use it for the dry run.
 *
 *   php tools/seed-demo-data.php --wp=/path/to/wordpress
 *       Seed categories and prizes if the wheel is empty, then print a summary.
 *
 *   php tools/seed-demo-data.php --wp=... --reset
 *       Wipe categories, prizes, entries and spins, then re-seed from scratch.
 *
 *   php tools/seed-demo-data.php --wp=... --reset --entries=300
 *       Re-seed, then simulate 300 visitors through the real spin engine and
 *       report the outcome distribution against the configured probabilities.
 *
 *   php tools/seed-demo-data.php --wp=... --wipe
 *       Delete everything and stop.
 *
 * Simulated visitors are written with device_id = 'demo-seed' and
 * email_status = 'skipped', so the cron worker can never email them
 * and --reset can find them again. Their addresses are all @example.test,
 * which is a reserved TLD that cannot receive mail.
 */

if ('cli' !== PHP_SAPI) {
    exit("This script must be run from the command line.\n");
}

$args = cikw_seed_parse_args($argv);
$wp_root = $args['wp'] ?? cikw_seed_find_wp_root(__DIR__);

if (null === $wp_root || !file_exists($wp_root . '/wp-load.php')) {
    exit("Could not find wp-load.php. Pass --wp=/path/to/wordpress\n");
}

define('WP_USE_THEMES', false);
require_once $wp_root . '/wp-load.php';

if (!class_exists('CIKW_Demo_Data')) {
    exit("The cik-prize-wheel plugin is not active on this install.\n");
}

global $wpdb;

$reset = isset($args['reset']);
$wipe_only = isset($args['wipe']);
$visitor_count = isset($args['entries']) ? max(0, (int) $args['entries']) : 0;

echo "Prize Wheel Kiosk - demo data seeder\n";
echo str_repeat('=', 72) . "\n\n";

if ($wipe_only) {
    CIKW_Demo_Data::wipe();
    echo "Wiped all categories, prizes, entries and spins.\n";
    exit(0);
}

/* -------------------------------------------------------------------------
 * Seed the wheel
 * ---------------------------------------------------------------------- */

$result = CIKW_Demo_Data::install($reset);

if ($result['skipped']) {
    echo "Wheel already has data - left untouched. Pass --reset to rebuild it.\n\n";
} else {
    echo "Seeded {$result['categories']} categories and {$result['prizes']} prizes.\n\n";
}

/* -------------------------------------------------------------------------
 * Simulate visitors
 * ---------------------------------------------------------------------- */

if ($visitor_count > 0) {
    $options = CIKW_Settings::get_options();
    $max_spins = max(1, (int) $options["max_spins_per_registration"]);

    echo "Simulating {$visitor_count} visitors (up to {$max_spins} spins each)...\n";

    // Shared with the wp-admin tools page, so the two can never diverge.
    $simulation = CIKW_Demo_Data::simulate_visitors($visitor_count);

    echo "\nSimulation complete.\n";
    echo "  Visitors        : {$simulation["visitors"]}\n";
    echo "  Spins run       : {$simulation["spins"]}\n";
    printf("  Wins            : %d (%.1f%% of spins)\n", $simulation["wins"], $simulation["spins"] > 0 ? 100 * $simulation["wins"] / $simulation["spins"] : 0);
    echo "  Losses          : " . ($simulation["spins"] - $simulation["wins"]) . "\n";
    echo "  Errors          : {$simulation["errors"]}\n\n";
}

/* -------------------------------------------------------------------------
 * Report
 * ---------------------------------------------------------------------- */

$categories_table = CIKW_Activator::table_name('categories');
$prizes_table = CIKW_Activator::table_name('prizes');
$spins_table = CIKW_Activator::table_name('spins');
$entries_table = CIKW_Activator::table_name('entries');

$categories = CIKW_Config::active_categories();

echo "Wheel configuration\n";
echo str_repeat('-', 72) . "\n";
echo cikw_seed_pad('#', 4) . cikw_seed_pad('Category', 30) . cikw_seed_pad('Win %', 8)
    . cikw_seed_pad('Spins', 8) . "Prizes (active)\n";
echo str_repeat('-', 72) . "\n";

foreach ($categories as $index => $category) {
    $category_id = (int) $category['id'];

    $prizes = $wpdb->get_results($wpdb->prepare(
        'SELECT title, probability, stock_total, stock_remaining, daily_cap, is_active FROM %i WHERE category_id = %d ORDER BY id ASC',
        $prizes_table,
        $category_id
    ), ARRAY_A);

    $win_share = 0.0;
    $active_prizes = 0;

    foreach ($prizes as $prize) {
        if (1 === (int) $prize['is_active']) {
            $win_share += (float) $prize['probability'];
            $active_prizes++;
        }
    }

    $spin_count = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM %i WHERE category_id = %d',
        $spins_table,
        $category_id
    ));

    echo cikw_seed_pad((string) $index, 4)
        . cikw_seed_pad((string) $category['name'], 30)
        . cikw_seed_pad(sprintf('%.1f%%', $win_share), 8)
        . cikw_seed_pad((string) $spin_count, 8)
        . $active_prizes . "\n";
}

echo "\nPrize stock\n";
echo str_repeat('-', 72) . "\n";
echo cikw_seed_pad('Prize', 44) . cikw_seed_pad('Prob', 8) . cikw_seed_pad('Stock', 9)
    . cikw_seed_pad('Cap', 6) . "Awarded\n";
echo str_repeat('-', 72) . "\n";

$prize_rows = $wpdb->get_results($wpdb->prepare(
    'SELECT p.id, p.title, p.probability, p.stock_total, p.stock_remaining, p.daily_cap, p.is_active, c.name AS category
       FROM %i p INNER JOIN %i c ON c.id = p.category_id
      ORDER BY c.segment_order ASC, p.id ASC',
    $prizes_table,
    $categories_table
), ARRAY_A);

foreach ($prize_rows as $prize) {
    $awarded = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM %i WHERE prize_id = %d AND outcome = %s',
        $spins_table,
        (int) $prize['id'],
        'win'
    ));

    $stock = 0 === (int) $prize['stock_total']
        ? '∞'
        : $prize['stock_remaining'] . '/' . $prize['stock_total'];

    $flags = [];
    if (1 !== (int) $prize['is_active']) {
        $flags[] = 'INACTIVE';
    }
    if (0 !== (int) $prize['stock_total'] && 0 === (int) $prize['stock_remaining']) {
        $flags[] = 'SOLD OUT';
    }

    echo cikw_seed_pad((string) $prize['title'], 44)
        . cikw_seed_pad(sprintf('%.1f%%', (float) $prize['probability']), 8)
        . cikw_seed_pad($stock, 9)
        . cikw_seed_pad(null === $prize['daily_cap'] ? '-' : (string) $prize['daily_cap'], 6)
        . $awarded
        . ($flags ? '  <- ' . implode(', ', $flags) : '')
        . "\n";
}

$total_entries = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $entries_table));
$total_spins = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $spins_table));
$total_wins = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE outcome = %s', $spins_table, 'win'));

echo "\nTotals\n";
echo str_repeat('-', 72) . "\n";
echo "  Entries : {$total_entries}\n";
echo "  Spins   : {$total_spins}\n";
printf("  Wins    : %d (%.1f%%)\n", $total_wins, $total_spins > 0 ? 100 * $total_wins / $total_spins : 0);

echo "\nDeliberate edge cases in this dataset:\n";
foreach (CIKW_Demo_Data::edge_case_notes() as $note) {
    echo "  - {$note}\n";
}

echo "\nKiosk key: " . CIKW_Settings::kiosk_key() . "\n";
echo "Staff key: " . CIKW_Settings::staff_key() . "\n";
echo "\nDone.\n";
exit(0);

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/**
 * Truncate and pad to a visible width.
 *
 * printf's %-20s pads by BYTES, so Greek text (2 bytes per character in
 * UTF-8) would shred the column alignment of every table in this report.
 */
function cikw_seed_pad(string $text, int $width): string
{
    if (mb_strlen($text) > $width) {
        $text = mb_substr($text, 0, $width - 1) . '…';
    }

    return $text . str_repeat(' ', max(0, $width - mb_strlen($text)));
}

/**
 * @return array<string, string|bool>
 */
function cikw_seed_parse_args(array $argv): array
{
    $args = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--')) {
            continue;
        }

        $argument = substr($argument, 2);

        if (str_contains($argument, '=')) {
            [$key, $value] = explode('=', $argument, 2);
            $args[$key] = $value;
            continue;
        }

        $args[$argument] = true;
    }

    return $args;
}

function cikw_seed_find_wp_root(string $start): ?string
{
    $directory = $start;

    for ($depth = 0; $depth < 8; $depth++) {
        if (file_exists($directory . '/wp-load.php')) {
            return $directory;
        }

        $parent = dirname($directory);

        if ($parent === $directory) {
            break;
        }

        $directory = $parent;
    }

    return null;
}
