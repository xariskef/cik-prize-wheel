<?php
/**
 * The single most important test in the project (spec section 11.3):
 * fire 50 parallel spins at a prize with stock_total = 3 and assert that
 * exactly 3 prizes are awarded. No overselling, no under-awarding.
 *
 * The parallelism is real: 50 concurrent HTTP requests through curl_multi,
 * each hitting a separate PHP worker. Calling the engine 50 times in one
 * PHP process would prove nothing, because the race being tested is between
 * separate database connections.
 *
 * Usage (from anywhere):
 *   php concurrency-spin-test.php --wp=/path/to/wordpress --url=https://example.com
 *
 *   --wp     Path to the WordPress root (the directory holding wp-load.php).
 *            Defaults to walking up from this file.
 *   --url    Site URL the REST API is reachable on. Defaults to home_url().
 *   --spins  Number of parallel requests. Default 50.
 *   --stock  Stock to put on the test prize. Default 3.
 *   --keep   Leave the test rows in place instead of cleaning up.
 *
 * The test creates its own category and prize, deactivates every other
 * category for the duration so the wheel can only land on the test one, and
 * restores everything afterwards. Run it on a staging install, not during
 * the show.
 */

if ('cli' !== PHP_SAPI) {
    exit("This test must be run from the command line.\n");
}

$args = cikw_test_parse_args($argv);

$wp_root = $args['wp'] ?? cikw_test_find_wp_root(__DIR__);

if (null === $wp_root || !file_exists($wp_root . '/wp-load.php')) {
    exit("Could not find wp-load.php. Pass --wp=/path/to/wordpress\n");
}

define('WP_USE_THEMES', false);
require_once $wp_root . '/wp-load.php';

if (!class_exists('CIKW_Spin_Engine')) {
    exit("The cik-prize-wheel plugin is not active on this install.\n");
}

$parallel_spins = max(1, (int) ($args['spins'] ?? 50));
$stock = max(0, (int) ($args['stock'] ?? 3));
$site_url = rtrim((string) ($args['url'] ?? home_url()), '/');
$endpoint = $site_url . '/wp-json/' . CIKW_REST::NAMESPACE . '/spin';
$kiosk_key = CIKW_Settings::kiosk_key();
$keep = isset($args['keep']);

global $wpdb;

$categories_table = CIKW_Activator::table_name('categories');
$prizes_table = CIKW_Activator::table_name('prizes');
$entries_table = CIKW_Activator::table_name('entries');
$spins_table = CIKW_Activator::table_name('spins');

echo "Prize Wheel Kiosk - concurrency test\n";
echo str_repeat('=', 60) . "\n";
echo "Endpoint : {$endpoint}\n";
echo "Requests : {$parallel_spins} parallel\n";
echo "Stock    : {$stock}\n\n";

/* -------------------------------------------------------------------------
 * Set up an isolated fixture
 * ---------------------------------------------------------------------- */

// Park every other active category so the wheel can only land on ours.
$previously_active = $wpdb->get_col($wpdb->prepare(
    'SELECT id FROM %i WHERE is_active = 1',
    $categories_table
));

$wpdb->query($wpdb->prepare('UPDATE %i SET is_active = 0', $categories_table));

$wpdb->insert(
    $categories_table,
    [
        'slug' => 'cikw-concurrency-test-' . time(),
        'name' => 'Concurrency Test',
        'description' => 'Temporary category created by concurrency-spin-test.php.',
        'image_id' => null,
        'color' => '#006eb6',
        'wheel_weight' => 1.000,
        'segment_order' => 999,
        'is_active' => 1,
    ],
    ['%s', '%s', '%s', '%d', '%s', '%f', '%d', '%d']
);
$category_id = (int) $wpdb->insert_id;

// probability 100 means every spin that reaches the prize roll selects this
// prize, so the only thing that can hold awards down to 3 is the stock claim.
$wpdb->insert(
    $prizes_table,
    [
        'category_id' => $category_id,
        'title' => 'Concurrency Test Prize',
        'description' => 'Temporary prize created by concurrency-spin-test.php.',
        'image_id' => null,
        'probability' => 100.000,
        'stock_total' => $stock,
        'stock_remaining' => $stock,
        'daily_cap' => null,
        'is_active' => 1,
    ],
    ['%d', '%s', '%s', '%d', '%f', '%d', '%d', '%d', '%d']
);
$prize_id = (int) $wpdb->insert_id;

// One entry per request: max_spins_per_registration would otherwise cap us.
$entry_ids = [];
$credentials = [];
$now = current_time('mysql');

for ($i = 0; $i < $parallel_spins; $i++) {
    $token = CIKW_Security::generate_entry_token();

    $wpdb->insert(
        $entries_table,
        [
            'created_at' => $now,
            'full_name' => 'Concurrency Tester ' . $i,
            'email' => 'cikw-concurrency-' . $i . '@example.test',
            'phone' => '2100000000',
            'company' => null,
            'role' => null,
            'need_category_id' => null,
            'consent_marketing' => 0,
            'consent_text_version' => 'test',
            'consent_at' => $now,
            'entry_token' => $token,
            'device_id' => 'concurrency-test',
            'ip_hash' => null,
            'email_status' => 'skipped',
            'email_attempts' => 0,
        ],
        ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
    );

    $entry_id = (int) $wpdb->insert_id;
    $entry_ids[] = $entry_id;
    $credentials[] = ['entry_id' => $entry_id, 'entry_token' => $token];
}

echo "Fixture  : category {$category_id}, prize {$prize_id}, " . count($entry_ids) . " entries\n\n";

/* -------------------------------------------------------------------------
 * Fire everything at once
 * ---------------------------------------------------------------------- */

echo "Firing {$parallel_spins} parallel requests...\n";
$started = microtime(true);
$responses = cikw_test_parallel_post($endpoint, $credentials, $kiosk_key);
$elapsed = microtime(true) - $started;
printf("Done in %.2fs\n\n", $elapsed);

/* -------------------------------------------------------------------------
 * Assert
 * ---------------------------------------------------------------------- */

$reported_wins = 0;
$reported_losses = 0;
$http_errors = [];
$vouchers = [];

foreach ($responses as $index => $response) {
    if (200 !== $response['status']) {
        $http_errors[] = "#{$index}: HTTP {$response['status']} {$response['body']}";
        continue;
    }

    $decoded = json_decode($response['body'], true);

    if (!is_array($decoded)) {
        $http_errors[] = "#{$index}: unparseable body {$response['body']}";
        continue;
    }

    if ('win' === ($decoded['outcome'] ?? null)) {
        $reported_wins++;
        $vouchers[] = $decoded['voucher_code'] ?? '(none)';
    } else {
        $reported_losses++;
    }
}

// The database is the authority; the responses are only corroboration.
$db_wins = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM %i WHERE prize_id = %d AND outcome = %s',
    $spins_table,
    $prize_id,
    'win'
));

$db_spins = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM %i WHERE category_id = %d',
    $spins_table,
    $category_id
));

$distinct_vouchers = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(DISTINCT voucher_code) FROM %i WHERE prize_id = %d AND voucher_code IS NOT NULL',
    $spins_table,
    $prize_id
));

$stock_remaining = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT stock_remaining FROM %i WHERE id = %d',
    $prizes_table,
    $prize_id
));

$failures = [];

cikw_test_assert($failures, 'responses received', count($responses), $parallel_spins);
cikw_test_assert($failures, 'HTTP errors', count($http_errors), 0);
cikw_test_assert($failures, 'spin rows written', $db_spins, $parallel_spins);
cikw_test_assert($failures, 'wins in database', $db_wins, $stock);
cikw_test_assert($failures, 'wins reported by API', $reported_wins, $stock);
cikw_test_assert($failures, 'losses reported by API', $reported_losses, $parallel_spins - $stock);
cikw_test_assert($failures, 'distinct voucher codes', $distinct_vouchers, $stock);
cikw_test_assert($failures, 'stock remaining', $stock_remaining, 0);

if (!empty($http_errors)) {
    echo "\nHTTP errors:\n";
    foreach (array_slice($http_errors, 0, 10) as $error) {
        echo "  {$error}\n";
    }
    if (count($http_errors) > 10) {
        echo '  ... and ' . (count($http_errors) - 10) . " more\n";
    }
}

if (!empty($vouchers)) {
    echo "\nVouchers awarded: " . implode(', ', $vouchers) . "\n";
}

/* -------------------------------------------------------------------------
 * Tear down
 * ---------------------------------------------------------------------- */

if ($keep) {
    echo "\n--keep given: fixture left in place (category {$category_id}).\n";
} else {
    $placeholders = implode(',', array_fill(0, count($entry_ids), '%d'));
    $wpdb->query($wpdb->prepare(
        "DELETE FROM %i WHERE entry_id IN ({$placeholders})",
        $spins_table,
        ...$entry_ids
    ));
    $wpdb->query($wpdb->prepare(
        "DELETE FROM %i WHERE id IN ({$placeholders})",
        $entries_table,
        ...$entry_ids
    ));
    $wpdb->delete($prizes_table, ['id' => $prize_id], ['%d']);
    $wpdb->delete($categories_table, ['id' => $category_id], ['%d']);

    if (!empty($previously_active)) {
        $restore = implode(',', array_fill(0, count($previously_active), '%d'));
        $wpdb->query($wpdb->prepare(
            "UPDATE %i SET is_active = 1 WHERE id IN ({$restore})",
            $categories_table,
            ...array_map('intval', $previously_active)
        ));
    }

    echo "\nFixture cleaned up; category activation restored.\n";
}

echo "\n" . str_repeat('=', 60) . "\n";

if (empty($failures)) {
    echo "PASS - exactly {$stock} prizes awarded across {$parallel_spins} parallel spins.\n";
    exit(0);
}

echo 'FAIL - ' . count($failures) . " assertion(s) failed.\n";
exit(1);

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/**
 * POST every payload at the same time and collect the responses in order.
 *
 * @param array<int, array<string, mixed>> $payloads
 * @return array<int, array{status:int, body:string}>
 */
function cikw_test_parallel_post(string $url, array $payloads, string $kiosk_key): array
{
    $multi = curl_multi_init();
    $handles = [];

    foreach ($payloads as $index => $payload) {
        $handle = curl_init();

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => wp_json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                CIKW_Security::KIOSK_KEY_HEADER . ': ' . $kiosk_key,
                'Expect:',
            ],
        ]);

        curl_multi_add_handle($multi, $handle);
        $handles[$index] = $handle;
    }

    // Kick every request off before reading any of them back.
    do {
        $status = curl_multi_exec($multi, $running);

        if ($running) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running && CURLM_OK === $status);

    $responses = [];

    foreach ($handles as $index => $handle) {
        $body = (string) curl_multi_getcontent($handle);
        $error = curl_error($handle);

        $responses[$index] = [
            'status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
            'body' => '' !== $error ? 'curl error: ' . $error : $body,
        ];

        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }

    curl_multi_close($multi);

    return $responses;
}

/**
 * Record and print one assertion.
 */
function cikw_test_assert(array &$failures, string $label, int $actual, int $expected): void
{
    $ok = $actual === $expected;

    if (!$ok) {
        $failures[] = $label;
    }

    printf("  [%s] %-24s %d (expected %d)\n", $ok ? 'OK  ' : 'FAIL', $label, $actual, $expected);
}

/**
 * @return array<string, string|bool>
 */
function cikw_test_parse_args(array $argv): array
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

/**
 * Walk up from the plugin directory looking for a WordPress root.
 */
function cikw_test_find_wp_root(string $start): ?string
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
