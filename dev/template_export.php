<?php
declare(strict_types=1);

/**
 * dev/template_export.php — export ONE operator-authored waiver template out of
 * a source database into a JSON fixture. [GVS-58 follow-up]
 *
 * READ-ONLY, AND NOT MERELY BY CONVENTION. This is the script an operator points
 * at PRODUCTION, so "it only does SELECTs" is not a strong enough promise. The
 * connection opens a `START TRANSACTION READ ONLY` before it reads anything: the
 * SERVER then rejects any write on this session with ER_CANT_EXECUTE_IN_READ_
 * ONLY_TRANSACTION, so a future edit that added an UPDATE here would fail at the
 * database rather than succeed against production. App\TemplateSeed::export()
 * issues two SELECTs and nothing else; this makes that structural.
 *
 * WHAT IT READS: `waiver_templates` + `waiver_template_versions` for ONE id.
 * Nothing else. Signer data (waiver_instances, waiver_responses) is never
 * touched, so the fixture is PII-FREE BY CONSTRUCTION.
 *
 * TARGET SELECTION — a DEDICATED env var, on purpose:
 *   TEMPLATE_EXPORT_DB_URL=mysql://user:pass@host:3306/dbname
 * NOT `STAGING_WAIVER_DB_URL` (that name belongs to CI's staging secret; reusing
 * it here would make "which database did that run read?" ambiguous exactly where
 * ambiguity is expensive) and NOT `MYSQL_URL`. Naming the source has to be a
 * deliberate act. Same URL contract migrations/run.php parses (migrations/
 * README.md): mysql:// | mysql2:// | mariadb://, user/pass percent-decoded, the
 * database name taken RAW from the path.
 *
 * USAGE
 *   TEMPLATE_EXPORT_DB_URL='mysql://user:pass@prod-host:3306/waiver' \
 *     php dev/template_export.php --template-id=2 --out=dev/seed/staging-waiver-template.json
 *
 * The fixture it writes is consumed by dev/predeploy.php's gated seed
 * (SEED_WAIVER_TEMPLATE_FILE) — see that file and src/TemplateSeed.php.
 *
 * NO COMPOSER AUTOLOAD: like scripts/preflight-db-host.php, this requires the
 * one self-contained class directly by path, so it runs anywhere PHP 8.2 does.
 */

use App\TemplateSeed;

require __DIR__ . '/../src/TemplateSeed.php';

// Defence-in-depth on a credential-handling path (same rationale as
// scripts/preflight-db-host.php): php-cli loads no php.ini here, so
// zend.exception_ignore_args defaults Off and an uncaught throwable would print
// stack frames WITH their scalar argument values — which on this path would mean
// the DSN. Force it On so a trace can never carry one.
ini_set('zend.exception_ignore_args', '1');

const EXPORT_URL_ENV = 'TEMPLATE_EXPORT_DB_URL';

function tx_out(string $m): void { fwrite(STDOUT, $m . "\n"); }
function tx_fail(string $m): never { fwrite(STDERR, '[export] FATAL: ' . $m . "\n"); exit(1); }

// --- args -------------------------------------------------------------------
$templateId = 2;                                        // BookingV2's waiverTemplateId
$outPath    = __DIR__ . '/seed/staging-waiver-template.json';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--template-id=')) {
        $raw = substr($arg, 14);
        if (!ctype_digit($raw) || (int)$raw < 1) { tx_fail('--template-id= must be a positive integer'); }
        $templateId = (int)$raw;
    } elseif (str_starts_with($arg, '--out=')) {
        $outPath = substr($arg, 6);
        if ($outPath === '') { tx_fail('--out= requires a path'); }
    } elseif ($arg === '--stdout') {
        $outPath = '-';
    } elseif ($arg === '-h' || $arg === '--help') {
        tx_out("usage: " . EXPORT_URL_ENV . "='mysql://user:pass@host:3306/db' php dev/template_export.php"
            . " [--template-id=2] [--out=dev/seed/staging-waiver-template.json] [--stdout]");
        exit(0);
    } else {
        tx_fail('unknown argument ' . $arg);
    }
}

// --- source URL -------------------------------------------------------------
$raw = getenv(EXPORT_URL_ENV);
if ($raw === false || trim($raw) === '') {
    tx_fail(EXPORT_URL_ENV . ' is unset or empty. Set it to the READ credentials for the database you '
        . 'want to export FROM, e.g. mysql://user:pass@host:3306/waiver');
}
$raw = trim($raw);

$parts = parse_url($raw);
if ($parts === false || !isset($parts['scheme'])) {
    tx_fail(EXPORT_URL_ENV . ' is not a parseable URL (value withheld).');
}
if (!in_array(strtolower($parts['scheme']), ['mysql', 'mysql2', 'mariadb'], true)) {
    tx_fail(EXPORT_URL_ENV . ' does not carry a mysql:// scheme (value withheld).');
}
$host = $parts['host'] ?? '';
$port = isset($parts['port']) ? (int)$parts['port'] : 3306;
// RAW, not rawurldecode()d — the same asymmetry dev/predeploy.php documents:
// migrations/run.php derives the db from ltrim(parse_url()['path'],'/') without
// decoding it, so decoding here would diverge for any reserved character.
$name = isset($parts['path']) ? ltrim($parts['path'], '/') : '';
$user = isset($parts['user']) ? rawurldecode($parts['user']) : '';
$pass = isset($parts['pass']) ? rawurldecode($parts['pass']) : '';
if ($host === '') { tx_fail(EXPORT_URL_ENV . ' names no host (value withheld).'); }
if ($name === '') { tx_fail(EXPORT_URL_ENV . ' names no database (value withheld).'); }

// Coordinates only — never the user, never the password. Printed so the operator
// can SEE which database this run is about to read before it reads it.
tx_out('[export] source: ' . $host . ':' . $port . '/' . $name . ' (read-only transaction)');

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (\Throwable $e) {
    tx_fail('cannot connect to ' . $host . ':' . $port . '/' . $name . ' (' . get_class($e) . ').');
}

// The mechanical read-only guarantee. If the server is too old to understand it,
// ABORT rather than silently continue on a writable session: the whole point of
// this script is that it is safe to aim at production.
try {
    $pdo->exec('START TRANSACTION READ ONLY');
} catch (\Throwable $e) {
    tx_fail('could not open a READ ONLY transaction (' . get_class($e) . '). Refusing to read with a '
        . 'writable session — the read-only guarantee is the reason this script may be pointed at production.');
}

try {
    $payload = TemplateSeed::export($pdo, $templateId);
} catch (\Throwable $e) {
    tx_fail($e->getMessage());
}
$pdo->exec('COMMIT');

// --- fixture ----------------------------------------------------------------
// _README travels WITH the file: JSON carries no comments, and this fixture will
// be read by someone who did not run the export.
$payload = ['_README' => [
    'what'  => 'A COPY of waiver template ' . $templateId . ' exported from another environment by '
        . 'dev/template_export.php. Consumed by dev/predeploy.php when SEED_WAIVER_TEMPLATE_FILE points at it.',
    'not'   => 'NOT the source of truth. The legally-operative document is the one the operator edits by hand '
        . 'in the PRODUCTION admin UI. This copy goes stale the moment that one is edited; re-run the export '
        . 'to refresh it. Nothing reads this file on production, and the seed cannot overwrite an existing '
        . 'template id even if it did (src/TemplateSeed.php, Lock 2).',
    'pii'   => 'PII-free by construction: only waiver_templates + waiver_template_versions are exported. '
        . 'Signer data lives in waiver_instances / waiver_responses and is never read.',
]] + $payload;

$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    tx_fail('could not encode the payload as JSON: ' . json_last_error_msg());
}
$json .= "\n";

if ($outPath === '-') {
    fwrite(STDOUT, $json);
} else {
    $dir = dirname($outPath);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        tx_fail('could not create directory ' . $dir);
    }
    if (file_put_contents($outPath, $json) === false) {
        tx_fail('could not write ' . $outPath);
    }
}

// --- summary ----------------------------------------------------------------
$published = array_values(array_filter(
    $payload['versions'],
    static fn(array $r): bool => (int)$r['is_published'] === 1
));
$versionNumbers  = array_map(static fn(array $r): string => (string)$r['version'], $payload['versions']);
$publishedNumbers = array_map(static fn(array $r): string => (string)$r['version'], $published);

tx_out('[export] template id ' . $payload['template']['id'] . ' "' . $payload['template']['name'] . '"');
tx_out('[export] versions: ' . count($payload['versions']) . ' (' . implode(', ', $versionNumbers) . ')'
    . ' | published: ' . count($published) . ' (' . implode(', ', $publishedNumbers) . ')');
foreach ($payload['versions'] as $v) {
    tx_out('[export]   v' . $v['version'] . ' "' . $v['title'] . '"'
        . ' is_published=' . (int)$v['is_published']
        . ' published_at=' . ($v['published_at'] ?? 'NULL')
        . ' content_html=' . strlen((string)$v['content_html']) . 'B'
        . ' fields_json=' . strlen((string)$v['fields_json']) . 'B');
}
tx_out('[export] wrote ' . ($outPath === '-' ? '(stdout)' : $outPath) . ' (' . strlen($json) . ' bytes)');
tx_out('[export] NEXT: commit that file (or place it on the staging service), then set '
    . 'SEED_WAIVER_TEMPLATE_FILE on the STAGING Railway service ONLY and redeploy.');
