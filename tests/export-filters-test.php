<?php
/**
 * Checks on the export and the entries filters.
 *
 *   php tests/export-filters-test.php
 *
 * Runs with nothing but a PHP binary. These are the places where a bug is
 * silent rather than loud: a filter value that reaches SQL unchecked, or a
 * participant name that turns into a formula when the client opens the CSV
 * in Excel. Neither would ever show up in manual testing.
 */

if ('cli' !== PHP_SAPI) {
    exit("This test must be run from the command line.\n");
}

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);

function __($text, $domain = null) { return $text; }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $value)); }
function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
function absint($value) { return abs((int) $value); }
function mysql2date($format, $date) { return date('d/m/Y H:i', strtotime((string) $date)); }
function current_time($type) { return 'timestamp' === $type ? time() : date('Y-m-d H:i:s'); }

class CIKW_Activator
{
    public static function table_name(string $name): string { return 'wp_cikw_' . $name; }
}
class CIKW_Config {}
class CIKW_Settings {}
class CIKW_Spin_Engine {}
class WP_Error {}

require __DIR__ . '/../includes/class-cikw-export.php';
require __DIR__ . '/../includes/class-cikw-entries-query.php';
require __DIR__ . '/../includes/class-cikw-mailer.php';
require __DIR__ . '/../includes/class-cikw-vouchers.php';

$failures = 0;

function check(string $label, $actual, $expected): void
{
    global $failures;
    $ok = $actual === $expected;
    if (!$ok) { $failures++; }
    printf("  [%s] %-52s %s\n", $ok ? 'OK  ' : 'FAIL', $label,
        $ok ? '' : var_export($actual, true) . ' (expected ' . var_export($expected, true) . ')');
}

function call_private(string $class, string $method, array $args)
{
    return (new ReflectionMethod($class, $method))->invokeArgs(null, $args);
}

function private_const(string $class, string $name)
{
    return (new ReflectionClass($class))->getConstant($name);
}

echo "\n1. CSV formula injection is neutralised\n";
{
    // A visitor can type anything into the name field. Excel executes any
    // cell starting with these, so the export must not hand it one.
    foreach (['=', '+', '-', '@', "\t", "\r"] as $lead) {
        $payload = $lead . 'cmd|calc';
        $result = call_private('CIKW_Export', 'harden', [$payload]);
        check('leading ' . json_encode($lead) . ' is quoted', $result, "'" . $payload);
    }

    check('ordinary Greek text is untouched',
        call_private('CIKW_Export', 'harden', ['Γιώργος Παπαδόπουλος']), 'Γιώργος Παπαδόπουλος');
    check('formula after leading spaces is quoted',
        call_private('CIKW_Export', 'harden', ['  =1+1']), "'  =1+1");
    check('only spaces stays as is',
        call_private('CIKW_Export', 'harden', ['   ']), '   ');
    check('an equals sign mid-string is fine',
        call_private('CIKW_Export', 'harden', ['a=b']), 'a=b');
    check('empty stays empty', call_private('CIKW_Export', 'harden', ['']), '');
    check('null becomes empty', call_private('CIKW_Export', 'harden', [null]), '');
}

echo "\n2. every export column has a heading\n";
{
    $headings = call_private('CIKW_Export', 'headings', []);
    $row = call_private('CIKW_Export', 'format_row', [[
        'created_at' => '2026-10-23 11:30:00',
        'full_name' => 'Μαρία Οικονόμου',
        'email' => 'demo@example.test',
        'phone' => '6900000000',
        'company' => 'Δομική ΑΕ',
        'role' => 'Αρχιτέκτονας',
        'need_category_name' => 'Στεγανωτικά Ταρατσών',
        'spin_number' => 1,
        'spin_created_at' => '2026-10-23 11:31:00',
        'category_name' => 'Βιομηχανικά Δάπεδα',
        'outcome' => 'win',
        'prize_title' => 'Σετ εργαλείων',
        'voucher_code' => 'K7M4QP',
        'redeemed_at' => null,
        'redeemed_by' => '',
        'consent_marketing' => 1,
        'consent_text_version' => 'draft-1',
        'device_id' => 'kiosk-01',
        'email_status' => 'sent',
    ]]);

    check('heading count matches row width', count($row), count($headings));
    check('voucher survives', $row[12], 'K7M4QP');
    check('win is translated', $row[10], 'Κέρδισε');
    check('consent renders as Ναι', $row[15], 'Ναι');
    check('empty redeemed date is blank', $row[13], '');
}

echo "\n3. filters are allow-listed before they reach SQL\n";
{
    $_GET = [
        's' => 'Παπαδ',
        'cikw_date_from' => '2026-10-23',
        'cikw_date_to' => "2026-10-25' OR 1=1 --",
        'cikw_category' => '7abc',
        'cikw_outcome' => 'win',
        'cikw_redeemed' => 'DROP TABLE',
        'cikw_email_status' => 'sent',
    ];

    $filters = CIKW_Entries_Query::filters_from_request();

    check('valid date kept', $filters['date_from'], '2026-10-23');
    check('malformed date dropped', $filters['date_to'], '');
    check('category coerced to int', $filters['category_id'], 7);
    check('valid outcome kept', $filters['outcome'], 'win');
    check('bogus redeemed dropped', $filters['redeemed'], '');
    check('valid email status kept', $filters['email_status'], 'sent');
    check('search passes through', $filters['search'], 'Παπαδ');
}

echo "\n4. ORDER BY cannot be injected\n";
{
    check('known column kept', call_private('CIKW_Entries_Query', 'sanitize_orderby', ['full_name']), 'full_name');
    check('unknown column falls back',
        call_private('CIKW_Entries_Query', 'sanitize_orderby', ['id; DROP TABLE wp_users']), 'created_at');
    check('empty falls back', call_private('CIKW_Entries_Query', 'sanitize_orderby', ['']), 'created_at');
}

echo "\n5. active filter count drives the export hint\n";
{
    $none = ['search' => '', 'date_from' => '', 'date_to' => '', 'category_id' => 0,
             'outcome' => '', 'redeemed' => '', 'email_status' => ''];
    check('no filters', CIKW_Entries_Query::active_filter_count($none), 0);
    check('two filters', CIKW_Entries_Query::active_filter_count(
        array_merge($none, ['outcome' => 'win', 'category_id' => 3])), 2);
}

echo "\n6. email retry backoff\n";
{
    $backoff = private_const('CIKW_Mailer', 'BACKOFF_MINUTES');
    $max = CIKW_Mailer::MAX_ATTEMPTS;

    check('cap is 5 attempts (spec section 8)', $max, 5);
    check('first send is immediate', $backoff[0], 0);
    check('one entry per attempt', count($backoff), $max);

    $monotonic = true;
    for ($i = 1; $i < count($backoff); $i++) {
        if ($backoff[$i] <= $backoff[$i - 1]) { $monotonic = false; }
    }
    check('waits grow with each failure', $monotonic, true);

    // The whole point of backing off: the last retry must land far enough
    // out that a network outage lasting hours is still recovered from.
    check('final wait is at least an hour', end($backoff) >= 60, true);
    printf("       schedule: %s minutes\n", implode(', ', $backoff));

    $total = array_sum($backoff);
    check('queue spans several hours before giving up', $total >= 400, true);
    printf("       total span before failing: %.1f hours\n", $total / 60);
}

echo "\n7. voucher codes are read forgivingly but never rewritten\n";
{
    check('lowercase is accepted', CIKW_Vouchers::normalise('k7m4qp'), 'K7M4QP');
    check('spaces are ignored', CIKW_Vouchers::normalise(' K7M 4QP '), 'K7M4QP');
    check('dashes are ignored', CIKW_Vouchers::normalise('K7M-4QP'), 'K7M4QP');
    check('punctuation is stripped', CIKW_Vouchers::normalise('K7M4QP.'), 'K7M4QP');
    check('empty stays empty', CIKW_Vouchers::normalise('   '), '');

    // The generator excludes 0/O/1/I/L, so there is nothing to disambiguate.
    // Silently rewriting one would hand over the WRONG prize instead of
    // showing "not found" - so the characters must survive untouched.
    check('a zero is not turned into O', CIKW_Vouchers::normalise('K0M4QP'), 'K0M4QP');
    check('a one is not turned into I', CIKW_Vouchers::normalise('K1M4QP'), 'K1M4QP');

    check('zero is flagged as impossible', CIKW_Vouchers::has_ambiguous_characters('K0M4QP'), true);
    check('letter O is flagged', CIKW_Vouchers::has_ambiguous_characters('KOM4QP'), true);
    check('letter L is flagged', CIKW_Vouchers::has_ambiguous_characters('KLM4QP'), true);
    check('a real code is not flagged', CIKW_Vouchers::has_ambiguous_characters('K7M4QP'), false);
    check('lowercase l is caught too', CIKW_Vouchers::has_ambiguous_characters('k7m4ql'), true);
}

echo "\n" . str_repeat('=', 70) . "\n";

if (0 === $failures) {
    echo "ALL EXPORT / FILTER CHECKS PASSED\n";
    exit(0);
}

echo "{$failures} CHECK(S) FAILED\n";
exit(1);
