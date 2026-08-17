<?php
declare(strict_types=1);

/**
 * scripts/preflight-db-host.php — the URL-provenance pre-flight
 * [CI/CD M5.6, AC4.6 — the each-run mechanical provenance closer]
 *
 * Runs in the DEDICATED `provenance` job (php:8.2-cli container), AFTER "Require
 * the staging secrets". BOTH the `dump` and `migrate` jobs `needs:` that job, so
 * this pre-flight runs BEFORE the first DB touch in the whole DAG — the `dump`
 * job's mysqldump — and gates it as well as the migrate. It refuses to let the
 * run reach the dump OR the migrate of `STAGING_WAIVER_DB_URL` unless that URL
 * provably reaches the SAME endpoint Railway reports for the STAGING MySQL
 * service — so a staging CI run can never dump or migrate the PRODUCTION waiver
 * database. (Gating only the migrate would leave the dump — a full read of every
 * table, uploaded as a 7-day artifact — exposed to a mis-set URL, the
 * PII-exfiltration half of the exact event this gate exists to make impossible.)
 * See src/Preflight/DbHostProvenance.php for the full rationale and the
 * canonical-only / fail-closed / secret-safe host-parse posture.
 *
 * NO COMPOSER AUTOLOAD. Like migrations/run.php, this runs in a container that
 * never `composer install`ed — it requires the ONE self-contained pure-logic
 * class directly by path. All parsing/compare logic lives in that class (unit-
 * tested by tests/DbHostProvenanceTest.php); this file is only the thin I/O
 * shell: read env → POST Railway → validate → verdict → exit.
 *
 * FAIL CLOSED ON EVERYTHING: a missing env value, an unparseable/prod-pointing
 * URL, a Railway API that is down/unreachable/unauthorized, an unexpected query
 * shape, or a host/port mismatch all exit non-zero and BLOCK the dump and the
 * migrate. A red run is re-runnable; a dump/migrate against the wrong database is
 * not.
 *
 * SECRET SAFETY: the Railway token and `STAGING_WAIVER_DB_URL` are read from the
 * environment and NEVER written to a command line, a step output, or the log.
 * The variable map Railway returns contains passwords and full connection URLs;
 * it is handed straight to the pure parser and never echoed. Only status codes
 * and charset-stripped GraphQL error CODES are ever printed on a Railway error.
 */

use App\Preflight\DbHostProvenance;

require __DIR__ . '/../src/Preflight/DbHostProvenance.php';

// Defence-in-depth on a secret-handling path. The php:8.2-cli image loads no
// php.ini, so zend.exception_ignore_args defaults Off: an uncaught throwable
// would print stack frames WITH their scalar argument values (truncated) into
// the CI log. No reachable throw site holds a secret in-frame today (parse_url /
// preg_match / json_decode do not throw on the DSN, file_get_contents returns
// false rather than throwing) — but one future edit (a typed helper that throws
// on $dbUrl) would turn a trace into a first-chars-of-the-DSN leak. Force the
// flag On (PHP_INI_ALL) so a trace can never carry an argument value regardless.
ini_set('zend.exception_ignore_args', '1');

const EXIT_OK   = 0;
const EXIT_FAIL = 1;

/** Emit a GitHub Actions error annotation and exit non-zero (fail closed). */
function preflight_fail(string $message): never
{
    fwrite(STDERR, '::error::' . $message . "\n");
    exit(EXIT_FAIL);
}

function preflight_ok(string $message): never
{
    fwrite(STDOUT, $message . "\n");
    exit(EXIT_OK);
}

/**
 * A non-empty environment value, trimmed — or null. Never returns the value to
 * anywhere it could be logged; the caller only ever tests presence or hands it
 * to the parser / the HTTP layer.
 */
function preflight_env(string $name): ?string
{
    $value = getenv($name);
    if ($value === false) {
        return null;
    }
    $trimmed = trim($value);
    return $trimmed === '' ? null : $trimmed;
}

// ---------------------------------------------------------------------------
// 1. Required inputs (names only in any error — never the values).
// ---------------------------------------------------------------------------
$dbUrl          = preflight_env('STAGING_WAIVER_DB_URL');
$railwayToken   = preflight_env('RAILWAY_TOKEN');
$railwayApiUrl  = preflight_env('RAILWAY_API_URL');
$environmentId  = preflight_env('RAILWAY_STAGING_ENVIRONMENT_ID');
$mysqlServiceId = preflight_env('RAILWAY_STAGING_MYSQL_SERVICE_ID');

$missing = [];
if ($dbUrl === null) {
    $missing[] = 'STAGING_WAIVER_DB_URL(Actions secret)';
}
if ($railwayToken === null) {
    $missing[] = 'RAILWAY_STAGING_TOKEN(Actions secret)';
}
if ($railwayApiUrl === null) {
    $missing[] = 'RAILWAY_API_URL(workflow env constant)';
}
if ($environmentId === null) {
    $missing[] = 'RAILWAY_STAGING_ENVIRONMENT_ID(workflow env constant)';
}
if ($mysqlServiceId === null) {
    $missing[] = 'RAILWAY_STAGING_MYSQL_SERVICE_ID(workflow env constant)';
}
if ($missing !== []) {
    preflight_fail(
        'URL-provenance pre-flight cannot run — missing: ' . implode(', ', $missing) . '. '
        . 'Set the Actions secrets and fill the staging Railway id triple in this workflow\'s env block '
        . '(M4/M5 operator runbook §5). Nothing was dumped or migrated.'
    );
}

// A Railway PROJECT token authenticates with the `Project-Access-Token` header.
// Validate its charset before it reaches an HTTP header so a stray CR/LF can
// never inject a header line. The value itself is NEVER printed.
if (!preg_match('/^[A-Za-z0-9._\-]+$/', $railwayToken)) {
    preflight_fail('RAILWAY_STAGING_TOKEN contains characters outside [A-Za-z0-9._-] (value withheld) — refusing to build a request with it.');
}

// The Railway Project-Access-Token is a bearer credential the request below
// places in an HTTP header. Refuse to send it over anything but https://: a
// mis-set or later-overridden RAILWAY_API_URL carrying an http:// (or any
// non-https) scheme would put the staging token on the wire in cleartext.
// RAILWAY_API_URL is a fixed https default in the workflow env with no override
// path wired today — this guard is here for OVERRIDE-SAFETY, so a future env
// edit can never silently downgrade the token's transport. Fail CLOSED with a
// distinct, value-free code, BEFORE the token is placed in a header below.
if (!DbHostProvenance::isHttpsApiUrl($railwayApiUrl)) {
    preflight_fail('[ac4.6.waiver-db-url-provenance.railway-api-url-insecure] RAILWAY_API_URL is not an https:// endpoint (value withheld) — refusing to send the Railway Project-Access-Token over an insecure scheme. Refusing to access the staging DB.');
}

// Transport (https) is not enough: a valid https URL whose host is an attacker's
// (`https://user:pass@attacker/`) would still receive the bearer token. Pin the
// DESTINATION to the Railway API host the workflow's hardcoded RAILWAY_API_URL
// default names. The comparison uses the PARSED host only (userinfo stripped), so
// a credential-smuggled authority cannot spoof it. Fail CLOSED with a distinct,
// value-free code — still BEFORE the token is placed in a header below.
if (!DbHostProvenance::apiUrlHostIsExpected($railwayApiUrl)) {
    preflight_fail('[ac4.6.waiver-db-url-provenance.railway-api-url-host-unexpected] RAILWAY_API_URL does not point at the expected Railway API host (value withheld) — refusing to send the Railway Project-Access-Token to an unexpected host. Refusing to access the staging DB.');
}

// ---------------------------------------------------------------------------
// 2. The two authorities.
// ---------------------------------------------------------------------------
$urlAuthority = DbHostProvenance::parseAuthorityFromUrl($dbUrl);

// Railway read — UNVERIFIED-LIVE query shape (see the class const docblock).
// file_get_contents keeps the token in an in-process header, off the argv/proc
// table entirely (strictly less exposure than a curl command line).
$payload = json_encode([
    'query'     => DbHostProvenance::RAILWAY_SERVICE_VARIABLES_QUERY,
    'variables' => ['environmentId' => $environmentId, 'serviceId' => $mysqlServiceId],
], JSON_UNESCAPED_SLASHES);
if ($payload === false) {
    preflight_fail('Could not encode the Railway request (internal) — refusing to access the staging DB.');
}

$context = stream_context_create([
    'http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\n"
                         . 'Project-Access-Token: ' . $railwayToken . "\r\n"
                         . "Accept: application/json\r\n",
        'content'       => $payload,
        'timeout'       => 30,
        'ignore_errors' => true, // read a non-2xx BODY rather than throwing, so we can classify
        // NEVER follow a redirect. PHP's http wrapper re-sends the full custom
        // header block — INCLUDING the Project-Access-Token — to a redirect
        // target, cross-host included, so a server-directed 3xx could forward the
        // staging token off the pinned Railway host. With following OFF, a 3xx is
        // left in place and the status check below (< 200 || >= 300) fails closed
        // on it. RAILWAY_API_URL is a fixed https endpoint; a redirect off it is
        // anomalous by definition, so refusing it costs nothing legitimate.
        'follow_location' => 0,
        'max_redirects'   => 0,
    ],
    'ssl' => [
        'verify_peer'      => true,
        'verify_peer_name' => true,
    ],
]);

$body = @file_get_contents($railwayApiUrl, false, $context);

// $http_response_header is set by the http stream wrapper after the call.
$status = 0;
if (isset($http_response_header) && is_array($http_response_header) && isset($http_response_header[0])) {
    if (preg_match('#\bHTTP/\S+\s+(\d{3})\b#', $http_response_header[0], $m) === 1) {
        $status = (int) $m[1];
    }
}

if ($body === false || $status < 200 || $status >= 300) {
    // Never the body, never the token. Status only (0 = transport failure / no response).
    preflight_fail(
        'The Railway API did not return a usable response reading the staging MySQL variables '
        . '(HTTP status: ' . $status . '; body withheld). Usually a network failure or an auth rejection — '
        . 'check RAILWAY_STAGING_TOKEN\'s project scope and the staging id triple. Refusing to access the staging DB (re-runnable).'
    );
}

// Surface GraphQL error CODES (charset-stripped) before folding to indeterminate,
// so the first live run can tell NOT_AUTHORIZED from a bad field name.
$decoded = json_decode($body, true);
if (is_array($decoded) && isset($decoded['errors']) && is_array($decoded['errors']) && count($decoded['errors']) > 0) {
    $codes = [];
    foreach ($decoded['errors'] as $error) {
        $code = (is_array($error) && isset($error['extensions']['code']) && is_string($error['extensions']['code']))
            ? $error['extensions']['code']
            : 'none';
        $codes[] = $code;
    }
    $safeCodes = substr((string) preg_replace('/[^A-Za-z0-9,_\-]/', '', implode(',', $codes)), 0, 120);
    preflight_fail(
        'The Railway API returned GraphQL error(s) reading the staging MySQL variables '
        . '(messages withheld; codes: ' . ($safeCodes !== '' ? $safeCodes : 'none') . '). '
        . 'If this is the first live run, confirm the variables(...) query shape and the staging MySQL service id '
        . '(M5 operator runbook). Refusing to access the staging DB.'
    );
}

$variables       = DbHostProvenance::parseRailwayVariables($body);
$railwayAuthority = $variables === null ? null : DbHostProvenance::extractRailwayAuthority($variables);

// ---------------------------------------------------------------------------
// 3. Verdict — PASS opens the gate; every other outcome blocks the migrate.
// ---------------------------------------------------------------------------
$verdict = DbHostProvenance::verdict($urlAuthority, $railwayAuthority);
if ($verdict['ok'] !== true) {
    preflight_fail('[' . $verdict['code'] . '] ' . $verdict['reason']);
}
preflight_ok('[' . $verdict['code'] . '] ' . $verdict['reason']);
