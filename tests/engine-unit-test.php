<?php
/**
 * Unit tests for the spin engine's decision maths.
 *
 * Runs with nothing but a PHP binary - no WordPress, no MySQL, no Composer -
 * by stubbing the handful of globals CIKW_Spin_Engine touches. That makes it
 * cheap enough to run on every change to the engine.
 *
 *   php tests/engine-unit-test.php
 *
 * Scope: the probability maths, the exclusion policies, the roll boundaries,
 * the voucher alphabet, the guards, and the decision_log contents.
 *
 * NOT in scope: real concurrency. Case 12 below drives the stock down to zero
 * sequentially, which proves the downgrade-to-lose path but NOT that the
 * conditional UPDATE is atomic across connections. Only
 * tests/concurrency-spin-test.php can prove that, because it needs separate
 * PHP workers on separate database connections. Run both.
 */

if ('cli' !== PHP_SAPI) {
    exit("This test must be run from the command line.\n");
}

define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);

function __($text, $domain = null) { return $text; }
function current_time($type) { return 'mysql' === $type ? date('Y-m-d H:i:s') : date($type); }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function apply_filters($tag, $value) { return $value; }
function wp_get_attachment_image_url($id, $size) { return null; }
function absint($v) { return abs((int) $v); }
class WP_Error {
    public function __construct(public string $code, public string $message, public array $data = []) {}
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }

/** Minimal $wpdb double backed by in-memory arrays. */
class FakeWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public array $prizes = [];
    public array $categories = [];
    public array $spins = [];
    public array $entries = [];
    public int $claim_attempts = 0;
    public string $last_error = '';

    public function prepare($query, ...$args)
    {
        // Good enough for the harness: substitute placeholders positionally.
        $out = '';
        $i = 0;
        for ($p = 0; $p < strlen($query); $p++) {
            if ('%' === $query[$p] && $p + 1 < strlen($query)) {
                $type = $query[$p + 1];
                if (in_array($type, ['i', 's', 'd', 'f'], true)) {
                    $out .= is_string($args[$i]) ? "'" . $args[$i] . "'" : (string) $args[$i];
                    $i++;
                    $p++;
                    continue;
                }
            }
            $out .= $query[$p];
        }
        return $out;
    }

    public function get_results($query, $mode = null)
    {
        if (str_contains($query, 'cikw_prizes')) {
            preg_match('/category_id = (\d+)/', $query, $m);
            $category_id = (int) ($m[1] ?? 0);
            return array_values(array_filter(
                $this->prizes,
                fn($p) => (int) $p['category_id'] === $category_id && 1 === (int) $p['is_active']
            ));
        }
        if (str_contains($query, 'cikw_categories')) {
            return array_values(array_filter($this->categories, fn($c) => 1 === (int) $c['is_active']));
        }
        return [];
    }

    public function get_var($query)
    {
        if (str_contains($query, 'COUNT(*)') && str_contains($query, 'cikw_spins')) {
            if (str_contains($query, 'DATE(created_at)')) {
                preg_match('/prize_id = (\d+)/', $query, $m);
                $pid = (int) ($m[1] ?? 0);
                return count(array_filter($this->spins, fn($s) => (int) $s['prize_id'] === $pid && 'win' === $s['outcome']));
            }
            preg_match('/entry_id = (\d+)/', $query, $m);
            $eid = (int) ($m[1] ?? 0);
            return count(array_filter($this->spins, fn($s) => (int) $s['entry_id'] === $eid));
        }
        return 0;
    }

    public function get_row($query, $mode = null)
    {
        if (str_contains($query, 'cikw_entries')) {
            preg_match('/id = (\d+)/', $query, $m);
            $id = (int) ($m[1] ?? 0);
            return $this->entries[$id] ?? null;
        }
        return null;
    }

    /** The atomic claim, emulated with the same conditional semantics. */
    public function query($query)
    {
        if (str_contains($query, 'stock_remaining = stock_remaining - 1')) {
            $this->claim_attempts++;
            preg_match('/id = (\d+)/', $query, $m);
            $id = (int) ($m[1] ?? 0);
            foreach ($this->prizes as $index => $prize) {
                if ((int) $prize['id'] !== $id) {
                    continue;
                }
                if (0 === (int) $prize['stock_total'] || (int) $prize['stock_remaining'] > 0) {
                    $this->prizes[$index]['stock_remaining'] = (int) $prize['stock_remaining'] - 1;
                    return 1;
                }
                return 0;
            }
        }
        if (str_contains($query, 'stock_remaining = stock_remaining + 1')) {
            preg_match('/id = (\d+)/', $query, $m);
            $id = (int) ($m[1] ?? 0);
            foreach ($this->prizes as $index => $prize) {
                if ((int) $prize['id'] === $id && (int) $prize['stock_remaining'] < (int) $prize['stock_total']) {
                    $this->prizes[$index]['stock_remaining']++;
                    return 1;
                }
            }
        }
        return 0;
    }

    public function insert($table, $data, $formats = null)
    {
        if (str_contains($table, 'cikw_spins')) {
            $this->last_error = '';
            foreach ($this->spins as $spin) {
                if ((int) $spin['entry_id'] === (int) $data['entry_id'] && (int) $spin['spin_number'] === (int) $data['spin_number']) {
                    $this->last_error = "Duplicate entry for key 'entry_spin'";
                    return false; // UNIQUE (entry_id, spin_number) collision
                }
                if (null !== $spin['voucher_code'] && $spin['voucher_code'] === $data['voucher_code']) {
                    $this->last_error = "Duplicate entry for key 'voucher_code'";
                    return false; // UNIQUE collision
                }
            }
            $this->insert_id = count($this->spins) + 1;
            $data['id'] = $this->insert_id;
            $this->spins[] = $data;
            return 1;
        }
        return 1;
    }

    public function suppress_errors($suppress = true) { return false; }
}

$GLOBALS['wpdb'] = new FakeWpdb();

class CIKW_Activator
{
    public static function table_name(string $name): string { return 'wp_cikw_' . $name; }
}

class CIKW_Settings
{
    public static array $options = [];
    public static function get_options(): array { return self::$options; }
}

class CIKW_Config
{
    public static function active_categories(): array
    {
        global $wpdb;
        return $wpdb->get_results('SELECT * FROM wp_cikw_categories', ARRAY_A);
    }
    public static function image_url($id, $size = 'large'): ?string { return null; }
}

define('ARRAY_A', 'ARRAY_A');

require __DIR__ . '/../includes/class-cikw-spin-engine.php';

/* ------------------------------------------------------------------ */

$failures = 0;
function check(string $label, $actual, $expected): void
{
    global $failures;
    $ok = $actual === $expected;
    if (!$ok) { $failures++; }
    printf("  [%s] %-46s %s (expected %s)\n", $ok ? 'OK  ' : 'FAIL', $label,
        var_export($actual, true), var_export($expected, true));
}

/**
 * PHP 8.1+ reaches private methods through reflection without setAccessible().
 */
function call_private(string $method, array $args)
{
    return (new ReflectionMethod('CIKW_Spin_Engine', $method))->invokeArgs(null, $args);
}

CIKW_Settings::$options = [
    'max_spins_per_registration' => 2,
    'stock_exhausted_policy' => 'to_lose',
];

echo "\n1. build_distribution - to_lose policy\n";
$wpdb = $GLOBALS['wpdb'];
$wpdb->prizes = [
    ['id' => 1, 'category_id' => 7, 'title' => 'A', 'description' => '', 'image_id' => null,
     'probability' => '10.000', 'stock_total' => 5, 'stock_remaining' => 5, 'daily_cap' => null, 'is_active' => 1],
    ['id' => 2, 'category_id' => 7, 'title' => 'B', 'description' => '', 'image_id' => null,
     'probability' => '20.000', 'stock_total' => 5, 'stock_remaining' => 5, 'daily_cap' => null, 'is_active' => 1],
];
$d = call_private('build_distribution', [7, 'to_lose']);
check('prize A units', $d['eligible_units'][1], 10000);
check('prize B units', $d['eligible_units'][2], 20000);
check('no-win units (example: 70%)', $d['no_win_units'], 70000);

echo "\n2. build_distribution - out of stock, to_lose\n";
$wpdb->prizes[1]['stock_remaining'] = 0;
$d = call_private('build_distribution', [7, 'to_lose']);
check('B excluded', isset($d['eligible_units'][2]), false);
check("B's share went to no-win", $d['no_win_units'], 90000);
check('exclusion reason', $d['log']['rows'][1]['exclusion_reason'], 'out_of_stock');

echo "\n3. build_distribution - out of stock, redistribute\n";
$d = call_private('build_distribution', [7, 'redistribute']);
check('A absorbed the excluded share', $d['eligible_units'][1], 30000);
check('no-win unchanged at 70%', $d['no_win_units'], 70000);

echo "\n4. build_distribution - daily cap\n";
$wpdb->prizes[1]['stock_remaining'] = 5;
$wpdb->prizes[1]['daily_cap'] = 2;
$wpdb->spins = [
    ['entry_id' => 1, 'prize_id' => 2, 'outcome' => 'win', 'voucher_code' => 'AAAAAA'],
    ['entry_id' => 2, 'prize_id' => 2, 'outcome' => 'win', 'voucher_code' => 'BBBBBB'],
];
$d = call_private('build_distribution', [7, 'to_lose']);
check('B capped out', isset($d['eligible_units'][2]), false);
check('cap reason logged', $d['log']['rows'][1]['exclusion_reason'], 'daily_cap_reached');
check('awards_today counted', $d['log']['rows'][1]['awards_today'], 2);
$wpdb->prizes[1]['daily_cap'] = null;
$wpdb->spins = [];

echo "\n5. build_distribution - over-100% rows are clamped\n";
$wpdb->prizes[0]['probability'] = '80.000';
$wpdb->prizes[1]['probability'] = '80.000';
$d = call_private('build_distribution', [7, 'to_lose']);
check('total units clamped to 100000', array_sum($d['eligible_units']), 100000);
check('no-win cannot go negative', $d['no_win_units'], 0);
check('clamp flagged in log', $d['log']['clamped'], true);
$wpdb->prizes[0]['probability'] = '10.000';
$wpdb->prizes[1]['probability'] = '20.000';

echo "\n6. resolve_roll - cumulative boundaries\n";
$units = [1 => 10000, 2 => 20000];
check('roll 1 -> prize 1', call_private('resolve_roll', [1, $units]), 1);
check('roll 10000 -> prize 1 (inclusive)', call_private('resolve_roll', [10000, $units]), 1);
check('roll 10001 -> prize 2', call_private('resolve_roll', [10001, $units]), 2);
check('roll 30000 -> prize 2 (inclusive)', call_private('resolve_roll', [30000, $units]), 2);
check('roll 30001 -> no win', call_private('resolve_roll', [30001, $units]), null);
check('roll 100000 -> no win', call_private('resolve_roll', [100000, $units]), null);
check('zero-unit prizes are skipped', call_private('resolve_roll', [1, [9 => 0, 1 => 10000]]), 1);

echo "\n7. pick_category - equal weights are uniform\n";
$categories = [];
for ($i = 1; $i <= 6; $i++) {
    $categories[] = ['id' => $i, 'name' => "C{$i}", 'wheel_weight' => '1.000',
                     'description' => '', 'image_id' => null, 'segment_order' => $i, 'is_active' => 1];
}
$counts = array_fill(0, 6, 0);
for ($i = 0; $i < 60000; $i++) {
    [$index] = call_private('pick_category', [$categories]);
    $counts[$index]++;
}
$min = min($counts);
$max = max($counts);
check('every segment reachable', $min > 0, true);
// 60k draws over 6 equal segments: ~10000 each. +/-5% is a very loose bound.
check('distribution within +/-5% of uniform', $min > 9500 && $max < 10500, true);
printf("       counts: %s\n", implode(', ', $counts));

echo "\n8. pick_category - zero weight is never selected\n";
$weighted = [
    ['id' => 1, 'name' => 'never', 'wheel_weight' => '0.000', 'description' => '', 'image_id' => null, 'segment_order' => 1, 'is_active' => 1],
    ['id' => 2, 'name' => 'always', 'wheel_weight' => '1.000', 'description' => '', 'image_id' => null, 'segment_order' => 2, 'is_active' => 1],
];
$zero_hits = 0;
for ($i = 0; $i < 5000; $i++) {
    [$index] = call_private('pick_category', [$weighted]);
    if (0 === $index) { $zero_hits++; }
}
check('zero-weight category never picked', $zero_hits, 0);

echo "\n9. pick_category - 3:1 weighting is honoured\n";
$weighted = [
    ['id' => 1, 'name' => 'heavy', 'wheel_weight' => '3.000', 'description' => '', 'image_id' => null, 'segment_order' => 1, 'is_active' => 1],
    ['id' => 2, 'name' => 'light', 'wheel_weight' => '1.000', 'description' => '', 'image_id' => null, 'segment_order' => 2, 'is_active' => 1],
];
$heavy = 0;
for ($i = 0; $i < 40000; $i++) {
    [$index] = call_private('pick_category', [$weighted]);
    if (0 === $index) { $heavy++; }
}
$ratio = $heavy / 40000;
check('heavy category near 75%', $ratio > 0.735 && $ratio < 0.765, true);
printf("       heavy share: %.3f\n", $ratio);

echo "\n10. pick_category - all-zero weights fall back to uniform\n";
$all_zero = [
    ['id' => 1, 'name' => 'a', 'wheel_weight' => '0.000', 'description' => '', 'image_id' => null, 'segment_order' => 1, 'is_active' => 1],
    ['id' => 2, 'name' => 'b', 'wheel_weight' => '0.000', 'description' => '', 'image_id' => null, 'segment_order' => 2, 'is_active' => 1],
];
[$index, $log] = call_private('pick_category', [$all_zero]);
check('fallback flagged', $log['equal_weight_fallback'], true);
check('a valid index is still returned', in_array($index, [0, 1], true), true);

echo "\n11. voucher codes avoid ambiguous glyphs\n";
$seen = [];
for ($i = 0; $i < 3000; $i++) {
    $code = call_private('generate_voucher_code', []);
    $seen[$code] = true;
    if (6 !== strlen($code) || preg_match('/[01OIL]/', $code)) {
        check('malformed voucher: ' . $code, false, true);
        break;
    }
}
check('6 chars, no 0/O/1/I/L in 3000 codes', true, true);
check('codes vary', count($seen) > 2900, true);

echo "\n12. full spin - stock of 3 exhausts and downgrades to lose\n";
$wpdb->prizes = [
    ['id' => 1, 'category_id' => 7, 'title' => 'Only prize', 'description' => '', 'image_id' => null,
     'probability' => '100.000', 'stock_total' => 3, 'stock_remaining' => 3, 'daily_cap' => null, 'is_active' => 1],
];
$wpdb->categories = [
    ['id' => 7, 'slug' => 't', 'name' => 'Test', 'description' => '', 'image_id' => null,
     'color' => '#000000', 'wheel_weight' => '1.000', 'segment_order' => 1, 'is_active' => 1],
];
$wpdb->spins = [];
$wpdb->entries = [];
for ($i = 1; $i <= 50; $i++) {
    $wpdb->entries[$i] = ['id' => $i, 'entry_token' => 'token-' . $i, 'finished_at' => null];
}

$wins = 0;
$losses = 0;
for ($i = 1; $i <= 50; $i++) {
    $result = CIKW_Spin_Engine::spin($i, 'token-' . $i);
    if ($result instanceof WP_Error) {
        check('unexpected error: ' . $result->code, false, true);
        break;
    }
    if ('win' === $result['outcome']) { $wins++; } else { $losses++; }
}
check('wins', $wins, 3);
check('losses', $losses, 47);
check('stock drained to 0', (int) $wpdb->prizes[0]['stock_remaining'], 0);
check('never negative stock', (int) $wpdb->prizes[0]['stock_remaining'] >= 0, true);
check('spin rows written', count($wpdb->spins), 50);
$vouchers = array_filter(array_column($wpdb->spins, 'voucher_code'));
check('one voucher per win', count($vouchers), 3);
check('vouchers unique', count(array_unique($vouchers)), 3);

echo "\n13. unlimited stock never goes negative\n";
$wpdb->prizes = [
    ['id' => 1, 'category_id' => 7, 'title' => 'Unlimited', 'description' => '', 'image_id' => null,
     'probability' => '100.000', 'stock_total' => 0, 'stock_remaining' => 0, 'daily_cap' => null, 'is_active' => 1],
];
$wpdb->spins = [];
$wpdb->claim_attempts = 0;
$wins = 0;
for ($i = 1; $i <= 20; $i++) {
    $result = CIKW_Spin_Engine::spin($i, 'token-' . $i);
    if (!($result instanceof WP_Error) && 'win' === $result['outcome']) { $wins++; }
}
check('all 20 win', $wins, 20);
check('stock_remaining untouched', (int) $wpdb->prizes[0]['stock_remaining'], 0);
check('no UPDATE issued for unlimited stock', $wpdb->claim_attempts, 0);

echo "\n14. token and spin-budget guards\n";
$wpdb->spins = [];
$bad = CIKW_Spin_Engine::spin(1, 'wrong-token');
check('bad token rejected', $bad instanceof WP_Error ? $bad->data['status'] : 0, 403);
$missing = CIKW_Spin_Engine::spin(9999, 'x');
check('unknown entry rejected', $missing instanceof WP_Error ? $missing->data['status'] : 0, 404);
CIKW_Spin_Engine::spin(1, 'token-1');
CIKW_Spin_Engine::spin(1, 'token-1');
$third = CIKW_Spin_Engine::spin(1, 'token-1');
check('third spin blocked (max 2)', $third instanceof WP_Error ? $third->data['status'] : 0, 409);

echo "\n15. decision_log captures the audit trail\n";
$log = json_decode($wpdb->spins[0]['decision_log'], true);
check('policy recorded', $log['policy'], 'to_lose');
check('roll recorded', is_int($log['roll']) && $log['roll'] >= 1 && $log['roll'] <= 100000, true);
check('category candidates recorded', count($log['category']['candidates']), 1);
check('prize rows recorded', count($log['prizes']['rows']), 1);
check('outcome recorded', isset($log['outcome']), true);
check('no_win_units recorded', isset($log['no_win_units']), true);

echo "\n16. the real demo dataset, driven through the engine\n";
require __DIR__ . '/../includes/class-cikw-demo-data.php';

// Flatten CIKW_Demo_Data into the shape the fake $wpdb serves.
$wpdb->categories = [];
$wpdb->prizes = [];
$wpdb->spins = [];
$category_id = 0;
$prize_id = 0;
$inactive_prize_id = null;
$soldout_prize_id = null;
$capped_prize_ids = [];

foreach (CIKW_Demo_Data::categories() as $order => $category) {
    $category_id++;
    $wpdb->categories[] = [
        'id' => $category_id, 'slug' => $category['slug'], 'name' => $category['name'],
        'description' => $category['description'], 'image_id' => null, 'color' => $category['color'],
        'wheel_weight' => '1.000', 'segment_order' => $order + 1, 'is_active' => 1,
    ];

    foreach ($category['prizes'] as $prize) {
        $prize_id++;
        $is_active = $prize['is_active'] ?? 1;
        $cap = $prize['daily_cap'] ?? null;

        if (0 === $is_active) { $inactive_prize_id = $prize_id; }
        if (0 !== (int) $prize['stock_total'] && 0 === (int) $prize['stock_remaining']) { $soldout_prize_id = $prize_id; }
        if (null !== $cap) { $capped_prize_ids[$prize_id] = $cap; }

        $wpdb->prizes[] = [
            'id' => $prize_id, 'category_id' => $category_id, 'title' => $prize['title'],
            'description' => $prize['description'], 'image_id' => null,
            'probability' => number_format((float) $prize['probability'], 3, '.', ''),
            'stock_total' => $prize['stock_total'], 'stock_remaining' => $prize['stock_remaining'],
            'daily_cap' => $cap, 'is_active' => $is_active,
        ];
    }
}

check('12 categories loaded', count($wpdb->categories), 12);
check('48 prize rows loaded', count($wpdb->prizes), 48);
check('an inactive prize exists', null !== $inactive_prize_id, true);
check('a sold-out prize exists', null !== $soldout_prize_id, true);
check('two capped prizes exist', count($capped_prize_ids), 2);

// One entry with an effectively unlimited spin budget stands in for a stream
// of visitors; the engine does not care which entry a spin belongs to.
CIKW_Settings::$options = ['max_spins_per_registration' => 999999, 'stock_exhausted_policy' => 'to_lose'];
$wpdb->entries = [1 => ['id' => 1, 'entry_token' => 'token-1', 'finished_at' => null]];

$total = 2400;
$wins = 0;
$segments = array_fill(0, 12, 0);
$awards = [];

for ($i = 0; $i < $total; $i++) {
    $result = CIKW_Spin_Engine::spin(1, 'token-1');
    if ($result instanceof WP_Error) {
        check('unexpected error: ' . $result->code, false, true);
        break;
    }
    $segments[$result['segment_index']]++;
    if ('win' === $result['outcome']) {
        $wins++;
        $awards[$result['prize']['id']] = ($awards[$result['prize']['id']] ?? 0) + 1;
    }
}

$win_rate = 100 * $wins / $total;
printf("       %d spins, %.1f%% win rate\n", $total, $win_rate);

// Configured average is ~37.8%, pulled down slightly by the sold-out prize
// and the two daily caps. A wide band keeps this from being flaky.
check('win rate in the 33-41% band', $win_rate > 33 && $win_rate < 41, true);
check('every segment was landed on', min($segments) > 0, true);
$expected_share = $total / 12;
check('segments within +/-40% of uniform',
    min($segments) > $expected_share * 0.6 && max($segments) < $expected_share * 1.4, true);
check('inactive prize never awarded', $awards[$inactive_prize_id] ?? 0, 0);
check('sold-out prize never awarded', $awards[$soldout_prize_id] ?? 0, 0);

foreach ($capped_prize_ids as $id => $cap) {
    check("capped prize {$id} respected its cap of {$cap}", ($awards[$id] ?? 0) <= $cap, true);
}

$oversold = [];
foreach ($wpdb->prizes as $prize) {
    if ((int) $prize['stock_remaining'] < 0) {
        $oversold[] = $prize['title'];
    }
    if (0 !== (int) $prize['stock_total']) {
        $given = $awards[$prize['id']] ?? 0;
        $started = 0;
        foreach (CIKW_Demo_Data::categories() as $c) {
            foreach ($c['prizes'] as $p) {
                if ($p['title'] === $prize['title'] && $p['stock_total'] === $prize['stock_total']) {
                    $started = $p['stock_remaining'];
                }
            }
        }
        if ($given > $started) {
            $oversold[] = $prize['title'] . " ({$given} > {$started})";
        }
    }
}
check('nothing oversold', count($oversold), 0);
if ($oversold) { echo '       oversold: ' . implode(', ', $oversold) . "\n"; }

echo "\nDuplicate spin number (two requests racing for the same entry)\n";
$first = call_private('insert_spin', [999001, 1, 1, null, 'lose', []]);
$second = call_private('insert_spin', [999001, 1, 1, null, 'lose', []]);
check('first write succeeds', is_array($first), true);
check('second write is refused as no spins left',
    $second instanceof WP_Error ? $second->code : null, 'cikw_no_spins_left');
check('refusal carries HTTP 409',
    $second instanceof WP_Error ? ($second->data['status'] ?? null) : null, 409);

echo "\n" . str_repeat('=', 68) . "\n";
if (0 === $failures) {
    echo "ALL ENGINE CHECKS PASSED\n";
    exit(0);
}
echo "{$failures} CHECK(S) FAILED\n";
exit(1);
