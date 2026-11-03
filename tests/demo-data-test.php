<?php
/**
 * Sanity checks on the demo dataset in CIKW_Demo_Data.
 *
 * Runs with nothing but a PHP binary:
 *
 *   php tests/demo-data-test.php
 *
 * The point is to keep the dataset honest when the real event data replaces
 * it: no category over 100%, no duplicate slug/colour/name, wheel labels
 * short enough to read from a metre away, stock figures that make sense, and
 * enough awkward rows left in to actually exercise the spin engine's
 * branches (sold out, daily caps, inactive, unlimited).
 */

if ('cli' !== PHP_SAPI) {
    exit("This test must be run from the command line.\n");
}

define('ABSPATH', __DIR__);
require __DIR__ . '/../includes/class-cikw-demo-data.php';

$categories = CIKW_Demo_Data::categories();
$fail = 0;

function bad(string $msg): void { global $fail; $fail++; echo "  FAIL: {$msg}\n"; }

/** printf pads by bytes; Greek is 2 bytes per char, so pad by width instead. */
function pad(string $text, int $width): string
{
    return $text . str_repeat(' ', max(1, $width - mb_strlen($text)));
}

echo "Categories: " . count($categories) . "\n";
if (count($categories) < 10 || count($categories) > 12) {
    bad('category count outside the 10-12 legible range (spec 12)');
}

$slugs = [];
$colors = [];
$names = [];
$total_prizes = 0;
$unlimited = 0;
$sold_out = 0;
$inactive = 0;
$capped = 0;

echo "\n" . pad('#', 4) . pad('Name', 26) . pad('Color', 10) . pad('Win%', 8) . pad('NoWin%', 9) . "Prizes\n";
echo str_repeat('-', 78) . "\n";

foreach ($categories as $i => $c) {
    foreach (['slug', 'name', 'description', 'color', 'prizes'] as $key) {
        if (!isset($c[$key])) { bad("category {$i} missing '{$key}'"); }
    }

    if (isset($slugs[$c['slug']])) { bad("duplicate slug {$c['slug']}"); }
    $slugs[$c['slug']] = true;

    if (isset($names[$c['name']])) { bad("duplicate name {$c['name']}"); }
    $names[$c['name']] = true;

    if (isset($colors[$c['color']])) { bad("duplicate color {$c['color']} on {$c['name']}"); }
    $colors[$c['color']] = true;

    if (!preg_match('/^#[0-9a-f]{6}$/', $c['color'])) {
        bad("color {$c['color']} is not lowercase 6-digit hex (admin sanitize_hex would rewrite it)");
    }

    if (!preg_match('/^[a-z0-9-]+$/', $c['slug'])) {
        bad("slug {$c['slug']} would be altered by sanitize_title()");
    }

    // Wheel labels have to fit on a segment and read from a metre away.
    if (mb_strlen($c['name']) > 30) {
        bad("name too long for a wheel segment: {$c['name']} (" . mb_strlen($c['name']) . ' chars)');
    }

    if (mb_strlen($c['description']) < 40 || mb_strlen($c['description']) > 220) {
        bad("description length out of range on {$c['name']}: " . mb_strlen($c['description']));
    }

    $win = 0.0;
    $active = 0;
    $prize_titles = [];

    foreach ($c['prizes'] as $p) {
        $total_prizes++;

        foreach (['title', 'description', 'probability', 'stock_total', 'stock_remaining'] as $key) {
            if (!isset($p[$key])) { bad("prize '{$p['title']}' missing '{$key}'"); }
        }

        $is_active = $p['is_active'] ?? 1;
        if (1 === $is_active) { $win += (float) $p['probability']; $active++; }
        else { $inactive++; }

        if (0 === (int) $p['stock_total']) { $unlimited++; }
        if (0 !== (int) $p['stock_total'] && 0 === (int) $p['stock_remaining']) { $sold_out++; }
        if (isset($p['daily_cap']) && null !== $p['daily_cap']) { $capped++; }

        if ((int) $p['stock_total'] > 0 && (int) $p['stock_remaining'] > (int) $p['stock_total']) {
            bad("stock_remaining exceeds stock_total on '{$p['title']}'");
        }

        if ((float) $p['probability'] <= 0 || (float) $p['probability'] > 100) {
            bad("probability out of range on '{$p['title']}'");
        }

        // Duplicate titles inside one category would confuse the admin screen.
        if (isset($prize_titles[$p['title']])) {
            bad("duplicate prize title '{$p['title']}' in {$c['name']}");
        }
        $prize_titles[$p['title']] = true;
    }

    // The admin screen refuses to save a category over 100%.
    if ($win > 100.0) {
        bad("active probability sum {$win} exceeds 100 in {$c['name']}");
    }

    // A trade show wheel that wins more than half the time drains stock fast.
    if ($win > 55.0) {
        bad("win rate {$win}% unrealistically high in {$c['name']}");
    }

    if ($win < 20.0) {
        bad("win rate {$win}% unrealistically low in {$c['name']}");
    }

    echo pad((string) $i, 4) . pad($c['name'], 26) . pad($c['color'], 10)
        . pad(sprintf('%.1f%%', $win), 8) . pad(sprintf('%.1f%%', 100 - $win), 9) . $active . "\n";
}

echo str_repeat('-', 78) . "\n";
echo "\nPrize rows      : {$total_prizes}\n";
echo "Unlimited stock : {$unlimited}\n";
echo "Sold out        : {$sold_out}\n";
echo "Inactive        : {$inactive}\n";
echo "With daily cap  : {$capped}\n";

// The dataset must actually exercise the engine's branches.
if ($sold_out < 1) { bad('no sold-out prize - stock_exhausted_policy branch untested'); }
if ($inactive < 1) { bad('no inactive prize - is_active filter untested'); }
if ($capped < 2) { bad('fewer than 2 daily caps - daily_cap branch barely tested'); }
if ($unlimited < 1) { bad('no unlimited-stock prize'); }

echo "\n" . str_repeat('=', 78) . "\n";
if (0 === $fail) { echo "DATASET VALID\n"; exit(0); }
echo "{$fail} PROBLEM(S)\n";
exit(1);
