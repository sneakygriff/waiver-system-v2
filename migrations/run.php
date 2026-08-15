<?php
declare(strict_types=1);

/**
 * migrations/run.php — per-statement migration ledger runner  [CI/CD M4, AC4.2]
 *
 * WHY THIS EXISTS
 * ---------------
 * This fork had NO migration runner: 002/003/004 all carry a "hand-apply via
 * `docker compose exec -T db mysql ... < migrations/00N.sql`" header. That is
 * unrunnable from CI and unauditable afterwards. This script applies pending
 * `NNN*.sql` files in numeric order and records progress PER STATEMENT so a
 * half-applied file is RESUMABLE.
 *
 * NOT ATOMIC — SAY IT OUT LOUD
 * ----------------------------
 * MySQL DDL implicitly commits (and MySQL 8 "atomic DDL" is per-statement, not
 * per-file). A migration file is therefore NOT a transaction and this runner
 * NEVER claims a rollback. When statement k of a file fails, statements 0..k-1
 * are PERMANENT. The ledger records exactly that, and a re-run resumes at k.
 * The contract is "resumable", not "all-or-nothing".
 *
 * There is one irreducible crash window: a statement is executed and THEN its
 * ledger row is written. If the process is killed between the two, the next run
 * re-executes that one statement. Prefer idempotent DDL (`IF NOT EXISTS`,
 * `INSERT ... ON DUPLICATE KEY`) in migrations so a re-execution is harmless.
 * (A statement that FAILS is different: it is not recorded and MySQL 8 does not
 * half-apply a failed DDL, so resume is clean.)
 *
 * LEDGER — EXTENDED, NEVER RECREATED
 * ----------------------------------
 * `schema_migrations(version, applied_at)` ALREADY EXISTS on every initialized
 * DB (001_init.sql creates it on fresh installs; 002 created it on the older
 * ones). This runner NEVER drops or recreates it. It only EXTENDS, idempotently:
 *
 *   1. `schema_migrations.version` is widened VARCHAR(32) -> VARCHAR(191) when
 *      it is still narrow. This is NOT cosmetic: the real version string
 *      '004_erasure_audit_events_backfill' is 33 chars, so under the MySQL 8
 *      default sql_mode (STRICT_TRANS_TABLES) inserting it into VARCHAR(32)
 *      fails with error 1406 "Data too long for column 'version'". Verified
 *      against the fork's own live schema. Widening a PK VARCHAR is lossless.
 *   2. `schema_migration_statements(version, statement_index, checksum,
 *      applied_at)` is created if absent. This is the per-statement progress
 *      ledger; the pre-existing table keeps its exact original meaning
 *      ("this FILE is fully applied").
 *
 * A pre-existing `schema_migrations` row is authoritative: that file is treated
 * as FULLY APPLIED and its statements are never re-run (that is what makes
 * `--baseline` and the 001-baked-into-initdb reality work).
 *
 * BASELINING (required before touching an existing database)
 * ---------------------------------------------------------
 * `php migrations/run.php --baseline` marks every migration file present as
 * applied WITHOUT executing it. Required on any DB whose schema predates this
 * runner (the compose DB, the M5 staging copy, prod later), because 002/003 are
 * baked into 001_init.sql on fresh installs and re-running them raises
 * "Duplicate column name". `--baseline --through=004` bounds it to files up to
 * and including 004 (useful when a genuinely new 005 must still run).
 *
 * Fresh-DB bootstrap stays compose's initdb (001 only). This runner is NEVER
 * expected to make 001 -> 002 succeed on a fresh schema.
 *
 * DB TARGET — ONE CONTRACT: A URL IN AN ENV VAR
 * --------------------------------------------
 * The target is read from the env var `STAGING_WAIVER_DB_URL` (override the
 * NAME, never the value, with `--url-env=OTHER_NAME`). Discrete MYSQL* vars are
 * deliberately NOT supported here — one contract, one place to get it wrong.
 *
 *   STAGING_WAIVER_DB_URL="mysql://user:pass@host:3306/dbname[?charset=utf8mb4]"
 *
 * (`mysql2://` and `mariadb://` are accepted as aliases. Percent-encoding in
 * user/pass is decoded. Query params other than `charset` are ignored.)
 *
 * The URL and its credentials are NEVER printed: no target banner, and every
 * error message is passed through a redactor that strips the password,
 * user, host and db name when >= 3 chars (short values are left out of
 * generic-text matching so redaction cannot mangle unrelated words — e.g. a
 * password "app" inside "applied"), word-boundary-aware so a short value is
 * still masked wherever it appears as a genuine token. The full raw URL is
 * always stripped wholesale, with no length floor, so a short credential
 * stays masked in that context regardless. See redact().
 *
 * USAGE
 * -----
 *   php migrations/run.php                 # apply all pending migrations
 *   php migrations/run.php --dry-run       # read-only: report what WOULD run
 *   php migrations/run.php --baseline      # mark all present files applied
 *   php migrations/run.php --baseline --through=004
 *   php migrations/run.php --dir=tests/fixtures/migrations-clean-chain
 *   php migrations/run.php --verbose       # echo statement text (repo SQL only)
 *
 * EXIT CODES (stable contract — CI and tests depend on these)
 * ----------------------------------------------------------
 *   0  success (including "nothing to do")
 *   1  a migration statement FAILED; ledger holds the exact half-applied state,
 *      re-run to resume at the failed statement index
 *   2  usage / input error (bad flag, bad --dir, unparseable migration file)
 *   3  environment error (env var unset/unparseable, DB unreachable, lock busy)
 *   4  ledger integrity error (pre-existing ledger shape unrecognized, or a
 *      migration file changed after a partial apply — resume would be unsound)
 */

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const EXIT_OK               = 0;
const EXIT_MIGRATION_FAILED = 1;
const EXIT_USAGE            = 2;
const EXIT_ENV              = 3;
const EXIT_LEDGER           = 4;

const LEDGER_FILES      = 'schema_migrations';
const LEDGER_STATEMENTS = 'schema_migration_statements';
const VERSION_MAX_LEN   = 191;   // utf8mb4 PK-safe; > 33 chars of the 004 version
const DEFAULT_URL_ENV   = 'STAGING_WAIVER_DB_URL';
const LOCK_TIMEOUT_SEC  = 10;
const LOG_PREFIX        = '[migrate] ';

// Values redacted out of every message (populated once the URL is parsed).
$GLOBALS['__redactions'] = [];

// ---------------------------------------------------------------------------
// Output helpers — stdout for progress, stderr for failures, always redacted
// ---------------------------------------------------------------------------

function redact(string $msg): string
{
    foreach ($GLOBALS['__redactions'] as $label => $secret) {
        if ($secret === '') {
            continue;
        }
        if ($label === 'url') {
            // The full raw connection string is matched wholesale — it is not
            // a "word", so word-boundary matching would be meaningless here:
            // strip it verbatim wherever it appears, regardless of how short
            // its component parts are.
            $msg = str_replace($secret, '<redacted:' . $label . '>', $msg);
            continue;
        }
        // Word-boundary-aware: a short credential (pass/user/host/db) must
        // still be masked wherever it appears as a genuine token — e.g.
        // quoted in a MySQL "Access denied for user '<x>'@..." message, or
        // between `:`/`@`/`/` in a DSN-shaped string — but must NOT swallow
        // the same characters when they occur mid-word in ordinary log
        // prose. Without this, a compose-style credential pair like
        // user=app/password=app corrupts the runner's own vocabulary:
        // "already applied" becomes "already <redacted:pass>lied" and
        // "files_applied=0" becomes "files_<redacted:pass>lied=0" (both
        // observed live — see the F2 report). `\b` anchors on a transition
        // between a word character (letter/digit/underscore) and a
        // non-word character, so "app" inside "applied" (no boundary
        // between the second `p` and `l`) is left alone, while "app" in
        // "user 'app'@host" (boundaries on both sides) is still replaced.
        // Known limitation ("where feasible"): `\b` anchors only on the
        // first/last character of the secret, so a secret that itself
        // starts or ends with a non-word character (e.g. a password
        // beginning with `-`) may not get the intended boundary semantics
        // at that end — the length floor below is the backstop for that.
        $pattern = '/\b' . preg_quote($secret, '/') . '\b/';
        $replaced = @preg_replace($pattern, '<redacted:' . $label . '>', $msg);
        $msg = $replaced ?? $msg;
    }
    return $msg;
}

function out(string $line): void
{
    fwrite(STDOUT, LOG_PREFIX . redact($line) . "\n");
}

function errln(string $line): void
{
    fwrite(STDERR, LOG_PREFIX . redact($line) . "\n");
}

/**
 * @return never
 */
function fail(int $code, string $line)
{
    errln($line);
    errln('FAILED (exit ' . $code . ')');
    exit($code);
}

function usage(): string
{
    return <<<TXT
    Usage: php migrations/run.php [options]

      (no options)        Apply every unapplied migration, resuming any file
                          left half-applied by a previous run.
      --dry-run           Read-only. Report what would run; write nothing
                          (does not even create the ledger tables).
      --baseline          Mark migration files applied WITHOUT executing them.
                          Required on any pre-existing database.
      --through=VERSION   With --baseline: stop at VERSION (a numeric prefix
                          like 004, or a full version like 004_erasure_audit).
      --dir=PATH          Migrations directory (default: this script's dir).
      --url-env=NAME      Env var holding the DB URL (default: STAGING_WAIVER_DB_URL).
      --verbose           Echo each statement's SQL text and extra detail.
      -h, --help          This help.

    Target DB: \$STAGING_WAIVER_DB_URL = mysql://user:pass@host:port/dbname
    The URL is never printed. Exit codes: 0 ok, 1 migration failed (resumable),
    2 usage, 3 environment, 4 ledger integrity.
    TXT;
}

// ---------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------

/**
 * @param  list<string> $argv
 * @return array{dir:string,baseline:bool,through:?string,dryRun:bool,verbose:bool,urlEnv:string}
 */
function parseArgs(array $argv): array
{
    $opts = [
        'dir'      => __DIR__,
        'baseline' => false,
        'through'  => null,
        'dryRun'   => false,
        'verbose'  => false,
        'urlEnv'   => DEFAULT_URL_ENV,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '-h' || $arg === '--help') {
            fwrite(STDOUT, usage() . "\n");
            exit(EXIT_OK);
        }
        if ($arg === '--baseline') { $opts['baseline'] = true; continue; }
        if ($arg === '--dry-run')  { $opts['dryRun']   = true; continue; }
        if ($arg === '--verbose')  { $opts['verbose']  = true; continue; }

        if (str_starts_with($arg, '--dir=')) {
            $opts['dir'] = substr($arg, 6);
            continue;
        }
        if (str_starts_with($arg, '--through=')) {
            $opts['through'] = substr($arg, 10);
            continue;
        }
        if (str_starts_with($arg, '--url-env=')) {
            $opts['urlEnv'] = substr($arg, 10);
            continue;
        }

        errln('unknown argument: ' . $arg);
        fwrite(STDERR, usage() . "\n");
        exit(EXIT_USAGE);
    }

    if ($opts['through'] !== null && !$opts['baseline']) {
        fail(EXIT_USAGE, '--through=... is only meaningful together with --baseline');
    }
    if ($opts['baseline'] && $opts['dryRun']) {
        fail(EXIT_USAGE, '--baseline and --dry-run are mutually exclusive');
    }
    if ($opts['urlEnv'] === '') {
        fail(EXIT_USAGE, '--url-env= requires a non-empty env var name');
    }

    return $opts;
}

// ---------------------------------------------------------------------------
// Connection
// ---------------------------------------------------------------------------

/**
 * @return array{0:PDO,1:string}  [pdo, database name]
 */
function connect(string $envName): array
{
    $raw = getenv($envName);
    if ($raw === false || trim($raw) === '') {
        fail(EXIT_ENV, $envName . ' is unset or empty — nothing to migrate against.');
    }
    $raw = trim($raw);

    $parts = parse_url($raw);
    if ($parts === false || !isset($parts['host'], $parts['scheme'])) {
        fail(EXIT_ENV, $envName . ' is not a parseable mysql:// URL (value withheld).');
    }

    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, ['mysql', 'mysql2', 'mariadb'], true)) {
        fail(EXIT_ENV, $envName . ' has unsupported scheme "' . $scheme . '://" (expected mysql://).');
    }

    $db = isset($parts['path']) ? ltrim($parts['path'], '/') : '';
    if ($db === '') {
        fail(EXIT_ENV, $envName . ' has no database name in its path (expected mysql://host/dbname).');
    }

    $host = $parts['host'];
    $port = (int)($parts['port'] ?? 3306);
    $user = isset($parts['user']) ? rawurldecode($parts['user']) : '';
    $pass = isset($parts['pass']) ? rawurldecode($parts['pass']) : '';

    $charset = 'utf8mb4';
    if (isset($parts['query'])) {
        parse_str($parts['query'], $q);
        if (isset($q['charset']) && is_string($q['charset']) && $q['charset'] !== '') {
            $charset = preg_replace('/[^A-Za-z0-9_]/', '', $q['charset']) ?: 'utf8mb4';
        }
    }

    // Arm the redactor BEFORE the first thing that can throw with details in it.
    // Every short-credential entry (pass/user/host/db) is stripped only when
    // long enough (>= 3 chars) that redact()'s word-boundary matching has a
    // meaningful boundary to anchor on; a value below that floor is left out
    // of blind matching entirely (1-2 char values are too likely to collide
    // with ordinary prose even with boundary-awareness — e.g. the standalone
    // English word "a"). The PASSWORD gets the SAME floor as user/host/db
    // (previously it had none at all — see redact()'s docblock: a compose
    // credential pair like user=app/password=app corrupted the runner's own
    // output). This is defence in depth, not the only guard: 'url' below
    // covers the full raw connection string unconditionally, so the
    // password is still masked wherever it is genuinely part of the
    // credential/DSN context, regardless of its length.
    //
    // ORDER MATTERS: 'url' MUST come before 'pass' here — redact() iterates
    // this array in insertion order, and its wholesale url match is a
    // str_replace against the RAW, UNMUTATED connection string. If 'pass' ran
    // first it would already have replaced the password inside any message
    // that happens to embed the full URL (e.g. --verbose's statement-preview
    // echo, when a statement's own SQL text contains a URL-shaped value), so
    // the message no longer contains an exact copy of $raw and the wholesale
    // str_replace silently no-ops — leaving the URL's OTHER components (a
    // sub-3-char user/host/db, which have no standalone entry to fall back
    // on) fully exposed. Url first means the whole string is masked in one
    // shot before anything else gets a chance to fragment it.
    $GLOBALS['__redactions'] = array_filter([
        'url'  => $raw,
        'pass' => strlen($pass) >= 3 ? $pass : '',
        'user' => strlen($user) >= 3 ? $user : '',
        'host' => strlen($host) >= 3 ? $host : '',
        'db'   => strlen($db)   >= 3 ? $db   : '',
    ]);

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    // Defence in depth for the ledger's central claim ("row k == statement k
    // ran"): with multi-statements OFF, a splitter bug that leaves two
    // statements glued together is rejected by MySQL instead of silently
    // executing both under one ledger row.
    if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
        $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $db, $charset);

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        fail(EXIT_ENV, 'database connection failed: ' . $e->getMessage());
    }

    return [$pdo, $db];
}

// ---------------------------------------------------------------------------
// Statement splitter
// ---------------------------------------------------------------------------

/**
 * Split a `;`-separated SQL file into executable statements.
 *
 * Handles: single/double-quoted strings (backslash escapes + doubled quotes),
 * backtick identifiers, `-- ` and `#` line comments, `/* *\/` block comments,
 * and `/*! ... *\/` version comments (preserved verbatim — MySQL parses them).
 * Comments are stripped and whitespace OUTSIDE quotes is collapsed to single
 * spaces, so the emitted text (and therefore its checksum) is stable against
 * reindentation and comment edits. A file with no executable statements (004 is
 * exactly that) yields an empty array — that is a valid migration, not an error.
 *
 * DELIMITER / stored programs are intentionally NOT supported: no migration in
 * this fork uses them, and pretending to handle them would be the kind of
 * silent half-support that corrupts a real database. A bare `DELIMITER`
 * keyword found outside a quoted literal or comment therefore REJECTS THE
 * WHOLE FILE right here, during parsing -- before any of its statements have
 * run -- instead of being mis-split on the stored-program body's own internal
 * `;`s. That distinction matters: `runApply`/dry-run both call this function
 * before executing (or even previewing) a single statement of the file, so a
 * migration with ordinary statements before a DELIMITER block can never
 * partially apply them and then fail on the mangled remainder.
 *
 * @return list<string>
 * @throws RuntimeException on an unterminated literal/block comment, or a
 *         DELIMITER directive (stored programs are not supported -- see above)
 */
function splitStatements(string $sql): array
{
    $stmts = [];
    $buf   = '';
    $len   = strlen($sql);
    $i     = 0;

    $space = static function (string &$b): void {
        if ($b !== '' && substr($b, -1) !== ' ') {
            $b .= ' ';
        }
    };

    while ($i < $len) {
        $c    = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        // Line comments: `#...` and `-- ...` (MySQL requires whitespace/EOL
        // after `--`, so `a--b` is arithmetic, not a comment).
        if ($c === '#'
            || ($c === '-' && $next === '-'
                && ($i + 2 >= $len || strpos(" \t\r\n", $sql[$i + 2]) !== false))) {
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
            $space($buf);
            continue;
        }

        // Block comments.
        if ($c === '/' && $next === '*') {
            $versioned = ($i + 2 < $len && $sql[$i + 2] === '!');
            $end = strpos($sql, '*/', $i + 2);
            if ($end === false) {
                throw new RuntimeException('unterminated /* block comment');
            }
            if ($versioned) {
                $buf .= substr($sql, $i, $end + 2 - $i);
            } else {
                $space($buf);
            }
            $i = $end + 2;
            continue;
        }

        // Quoted string / quoted identifier: copied verbatim, `;` inside is data.
        if ($c === "'" || $c === '"' || $c === '`') {
            $quote = $c;
            $buf  .= $c;
            $i++;
            while ($i < $len) {
                $ch = $sql[$i];
                if ($ch === '\\' && $quote !== '`') {
                    $buf .= $ch;
                    if ($i + 1 < $len) {
                        $buf .= $sql[$i + 1];
                        $i += 2;
                    } else {
                        $i++;
                    }
                    continue;
                }
                if ($ch === $quote) {
                    if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                        $buf .= $quote . $quote;   // doubled quote = literal
                        $i += 2;
                        continue;
                    }
                    $buf .= $quote;
                    $i++;
                    continue 2;                    // back to the outer scanner
                }
                $buf .= $ch;
                $i++;
            }
            throw new RuntimeException('unterminated quoted literal (' . $quote . ')');
        }

        if ($c === ';') {
            $s = trim($buf);
            if ($s !== '') {
                $stmts[] = $s;
            }
            $buf = '';
            $i++;
            continue;
        }

        if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n") {
            $space($buf);
            $i++;
            continue;
        }

        // DELIMITER changes what `;` means (it introduces a stored-program
        // body terminated by a custom token), and this splitter has no notion
        // of that: left undetected, it would slice the body on its own
        // internal `;`s and hand back syntactically broken fragments. This
        // check only ever sees text OUTSIDE quotes/comments (both branches
        // above `continue`), and only fires at the START of a token ($buf
        // empty or ending in the space `$space()` just inserted), so it can
        // never mistrigger mid-identifier (e.g. "SOMEDELIMITERX") or inside a
        // string literal that merely contains the word.
        if (($c === 'D' || $c === 'd')
            && ($buf === '' || substr($buf, -1) === ' ')
            && preg_match('/^DELIMITER(?=\s|$)/i', substr($sql, $i))) {
            throw new RuntimeException(
                'DELIMITER / stored-program syntax is not supported by this splitter (found at byte offset '
                . $i . '). Rewrite this migration as plain statements, or apply the stored program by hand '
                . 'outside this runner.'
            );
        }

        $buf .= $c;
        $i++;
    }

    $s = trim($buf);
    if ($s !== '') {
        $stmts[] = $s;   // final statement without a trailing `;`
    }

    return $stmts;
}

// ---------------------------------------------------------------------------
// Migration discovery
// ---------------------------------------------------------------------------

/**
 * @return list<array{version:string,path:string,seq:int}>
 */
function discoverMigrations(string $dir): array
{
    $real = realpath($dir);
    if ($real === false || !is_dir($real)) {
        fail(EXIT_USAGE, 'migrations directory not found: ' . $dir);
    }

    $entries = scandir($real);
    if ($entries === false) {
        fail(EXIT_USAGE, 'migrations directory is unreadable: ' . $real);
    }

    $found = [];
    $bad   = [];
    foreach ($entries as $name) {
        $path = $real . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path) || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'sql') {
            continue;
        }
        $version = (string)pathinfo($name, PATHINFO_FILENAME);
        if (!preg_match('/^(\d+)(?:_.*)?$/', $version, $m)) {
            $bad[] = $name;
            continue;
        }
        if (strlen($version) > VERSION_MAX_LEN) {
            $bad[] = $name . ' (version name longer than ' . VERSION_MAX_LEN . ' chars)';
            continue;
        }
        $found[] = ['version' => $version, 'path' => $path, 'seq' => (int)$m[1]];
    }

    if ($bad !== []) {
        // Never silently ignore a .sql file: a skipped migration is worse than
        // a loud stop.
        fail(EXIT_USAGE, 'unrecognized .sql file(s) in ' . $real . ' (expected NNN[_name].sql): '
            . implode(', ', $bad));
    }

    // Two files sharing a numeric prefix (e.g. 005_a.sql + 005_b.sql) would
    // otherwise both apply, ordered only by name (usort's [seq, version]
    // tiebreak below) -- an ambiguous, almost certainly accidental apply
    // order (a merge collision, not intent). Same "loud stop over silent
    // tolerance" philosophy as the unrecognized-file check above.
    $bySeq = [];
    foreach ($found as $m) {
        $bySeq[$m['seq']][] = $m['version'];
    }
    $dupes = [];
    foreach ($bySeq as $seq => $versions) {
        if (count($versions) > 1) {
            $dupes[] = $seq . ' (' . implode(', ', $versions) . ')';
        }
    }
    if ($dupes !== []) {
        fail(EXIT_USAGE, 'duplicate numeric prefix(es) in ' . $real . ' -- each NNN must be unique '
            . '(applying both in name order is almost always a merge accident, not intent): '
            . implode('; ', $dupes));
    }

    usort($found, static function (array $a, array $b): int {
        return [$a['seq'], $a['version']] <=> [$b['seq'], $b['version']];
    });

    return $found;
}

// ---------------------------------------------------------------------------
// Ledger
// ---------------------------------------------------------------------------

function tableExists(PDO $pdo, string $db, string $table): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1'
    );
    $st->execute([$db, $table]);
    return (bool)$st->fetchColumn();
}

/**
 * @return array<string,array{data_type:string,len:?int}>
 */
function columnsOf(PDO $pdo, string $db, string $table): array
{
    $st = $pdo->prepare(
        'SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $st->execute([$db, $table]);
    $cols = [];
    foreach ($st->fetchAll() as $row) {
        $cols[strtolower((string)$row['COLUMN_NAME'])] = [
            'data_type' => strtolower((string)$row['DATA_TYPE']),
            'len'       => $row['CHARACTER_MAXIMUM_LENGTH'] === null
                ? null
                : (int)$row['CHARACTER_MAXIMUM_LENGTH'],
        ];
    }
    return $cols;
}

/**
 * Bring the ledger to the shape this runner needs — by EXTENSION only.
 * Never DROPs, never recreates, never narrows.
 */
function ensureLedger(PDO $pdo, string $db, bool $verbose): void
{
    if (!tableExists($pdo, $db, LEDGER_FILES)) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `' . LEDGER_FILES . '` (
               `version` VARCHAR(' . VERSION_MAX_LEN . ') NOT NULL,
               `applied_at` DATETIME NOT NULL,
               PRIMARY KEY (`version`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        out('created ledger table ' . LEDGER_FILES);
    } else {
        $cols = columnsOf($pdo, $db, LEDGER_FILES);
        if (!isset($cols['version']) || !isset($cols['applied_at'])) {
            fail(EXIT_LEDGER, LEDGER_FILES . ' exists but lacks version/applied_at columns; '
                . 'refusing to guess (this runner never drops or recreates it). Fix by hand.');
        }
        if (!in_array($cols['version']['data_type'], ['varchar', 'char'], true)) {
            fail(EXIT_LEDGER, LEDGER_FILES . '.version has unexpected type "'
                . $cols['version']['data_type'] . '"; refusing to modify it.');
        }
        $len = $cols['version']['len'] ?? 0;
        if ($len < VERSION_MAX_LEN) {
            // The 33-char version '004_erasure_audit_events_backfill' does not
            // fit VARCHAR(32) and STRICT_TRANS_TABLES turns that into error
            // 1406 rather than a silent truncation. Widen, never narrow.
            $pdo->exec('ALTER TABLE `' . LEDGER_FILES . '` MODIFY COLUMN `version` VARCHAR('
                . VERSION_MAX_LEN . ') NOT NULL');
            out('widened ' . LEDGER_FILES . '.version VARCHAR(' . $len . ') -> VARCHAR('
                . VERSION_MAX_LEN . ') (a 33-char version name did not fit)');
        } elseif ($verbose) {
            out(LEDGER_FILES . '.version already VARCHAR(' . $len . '); no change');
        }
    }

    if (!tableExists($pdo, $db, LEDGER_STATEMENTS)) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `' . LEDGER_STATEMENTS . '` (
               `version` VARCHAR(' . VERSION_MAX_LEN . ') NOT NULL,
               `statement_index` INT NOT NULL,
               `checksum` CHAR(64) NOT NULL,
               `applied_at` DATETIME NOT NULL,
               PRIMARY KEY (`version`, `statement_index`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        out('created ledger table ' . LEDGER_STATEMENTS);
    } elseif ($verbose) {
        out(LEDGER_STATEMENTS . ' already present; no change');
    }
}

/**
 * @return array<string,true>
 */
function appliedVersions(PDO $pdo, string $db): array
{
    if (!tableExists($pdo, $db, LEDGER_FILES)) {
        return [];
    }
    $rows = $pdo->query('SELECT `version` FROM `' . LEDGER_FILES . '`')
        ->fetchAll(PDO::FETCH_COLUMN);
    $set = [];
    foreach ($rows as $v) {
        $set[(string)$v] = true;
    }
    return $set;
}

/**
 * Recorded per-statement progress for one version.
 *
 * @return array<int,string> statement_index => checksum
 */
function statementProgress(PDO $pdo, string $db, string $version): array
{
    if (!tableExists($pdo, $db, LEDGER_STATEMENTS)) {
        return [];
    }
    $st = $pdo->prepare(
        'SELECT `statement_index`, `checksum` FROM `' . LEDGER_STATEMENTS . '`
          WHERE `version` = ? ORDER BY `statement_index`'
    );
    $st->execute([$version]);
    $progress = [];
    foreach ($st->fetchAll() as $row) {
        $progress[(int)$row['statement_index']] = (string)$row['checksum'];
    }
    return $progress;
}

function checksumOf(string $statement): string
{
    return hash('sha256', $statement);
}

function preview(string $statement, int $max = 200): string
{
    return strlen($statement) > $max ? substr($statement, 0, $max) . ' …' : $statement;
}

/**
 * Validate recorded progress against the file as it is TODAY. A migration file
 * edited after a partial apply makes "resume at index k" meaningless, so this
 * stops instead of guessing.
 *
 * @param  list<string>      $statements
 * @param  array<int,string> $progress
 * @return int resume index
 */
function resumeIndex(string $version, array $statements, array $progress): int
{
    $done = count($progress);
    if ($done === 0) {
        return 0;
    }
    if ($done > count($statements)) {
        fail(EXIT_LEDGER, $version . ': ledger records ' . $done . ' applied statement(s) but the '
            . 'file now has only ' . count($statements) . ' — the migration changed after a '
            . 'partial apply; resume would be unsound.');
    }
    for ($i = 0; $i < $done; $i++) {
        if (!array_key_exists($i, $progress)) {
            fail(EXIT_LEDGER, $version . ': ledger has a hole at statement index ' . $i
                . ' (indexes must be contiguous from 0); refusing to resume.');
        }
        if ($progress[$i] !== checksumOf($statements[$i])) {
            fail(EXIT_LEDGER, $version . ': statement ' . $i . ' changed since it was applied '
                . '(checksum mismatch); resume would apply a different migration than the one '
                . 'recorded. Reconcile by hand.');
        }
    }
    return $done;
}

// ---------------------------------------------------------------------------
// Modes
// ---------------------------------------------------------------------------

/**
 * @param list<array{version:string,path:string,seq:int}> $migrations
 */
function runBaseline(PDO $pdo, string $db, array $migrations, ?string $through): int
{
    $cutoff = PHP_INT_MAX;
    if ($through !== null) {
        $cutoff = null;
        foreach ($migrations as $m) {
            if ($m['version'] === $through) {
                $cutoff = $m['seq'];
                break;
            }
        }
        if ($cutoff === null) {
            if (preg_match('/^\d+$/', $through)) {
                $cutoff = (int)$through;
            } else {
                fail(EXIT_USAGE, '--through=' . $through . ' matches no migration file '
                    . '(use a full version name or a numeric prefix like 004)');
            }
        }
    }

    $applied  = appliedVersions($pdo, $db);
    $marked   = 0;
    $skipped  = 0;
    $insert   = $pdo->prepare(
        'INSERT INTO `' . LEDGER_FILES . '` (`version`, `applied_at`) VALUES (?, NOW())'
    );

    foreach ($migrations as $m) {
        if ($m['seq'] > $cutoff) {
            out($m['version'] . ': left PENDING (beyond --through=' . (string)$through . ')');
            continue;
        }
        if (isset($applied[$m['version']])) {
            out($m['version'] . ': already applied (no change)');
            $skipped++;
            continue;
        }
        $partial = statementProgress($pdo, $db, $m['version']);
        if ($partial !== []) {
            out('WARNING ' . $m['version'] . ': ' . count($partial) . ' statement(s) were '
                . 'previously applied by this runner; baselining marks the WHOLE file applied '
                . 'and its remaining statements will never run.');
        }
        try {
            $insert->execute([$m['version']]);
        } catch (PDOException $e) {
            fail(EXIT_LEDGER, $m['version'] . ': could not write the ledger row ('
                . $e->getMessage() . ').');
        }
        out($m['version'] . ': BASELINED (marked applied WITHOUT executing)');
        $marked++;
    }

    out('summary: baselined=' . $marked . ' already_applied=' . $skipped);
    out('OK');
    return EXIT_OK;
}

/**
 * Mark a file fully applied. A failure here means every statement ran but the
 * file is not recorded — the next run would replay the whole file, so stop loud.
 */
function recordFileApplied(PDOStatement $stmt, string $version): void
{
    try {
        $stmt->execute([$version]);
    } catch (PDOException $e) {
        fail(EXIT_LEDGER, $version . ': all statements executed but the file could NOT be marked '
            . 'applied (' . $e->getMessage() . '). Re-running would replay this file; reconcile '
            . 'by hand.');
    }
}

/**
 * @param list<array{version:string,path:string,seq:int}> $migrations
 */
function runApply(PDO $pdo, string $db, array $migrations, bool $dryRun, bool $verbose): int
{
    $applied         = appliedVersions($pdo, $db);
    $filesApplied    = 0;
    $stmtsExecuted   = 0;
    $alreadyApplied  = 0;
    $pending         = 0;

    $recordStmt = $dryRun ? null : $pdo->prepare(
        'INSERT INTO `' . LEDGER_STATEMENTS . '`
             (`version`, `statement_index`, `checksum`, `applied_at`)
         VALUES (?, ?, ?, NOW())'
    );
    $recordFile = $dryRun ? null : $pdo->prepare(
        'INSERT INTO `' . LEDGER_FILES . '` (`version`, `applied_at`) VALUES (?, NOW())'
    );

    foreach ($migrations as $m) {
        $version = $m['version'];

        if (isset($applied[$version])) {
            out($version . ': already applied');
            $alreadyApplied++;
            continue;
        }

        $sql = file_get_contents($m['path']);
        if ($sql === false) {
            fail(EXIT_USAGE, $version . ': cannot read ' . $m['path']);
        }
        try {
            $statements = splitStatements($sql);
        } catch (RuntimeException $e) {
            fail(EXIT_USAGE, $version . ': cannot parse migration — ' . $e->getMessage());
        }

        $progress = statementProgress($pdo, $db, $version);
        $from     = resumeIndex($version, $statements, $progress);
        $total    = count($statements);

        if ($dryRun) {
            $pending++;
            out($version . ': PENDING — ' . $total . ' statement(s)'
                . ($from > 0 ? ', would resume at index ' . $from : '')
                . ($total === 0 ? ' (comment-only; would be recorded applied with 0 statements)' : ''));
            continue;
        }

        if ($total === 0) {
            // 004 is exactly this: documentation + a marker row, zero DDL.
            recordFileApplied($recordFile, $version);
            out($version . ': APPLIED (0 statement(s) — comment-only file)');
            $filesApplied++;
            continue;
        }

        out($version . ': ' . $total . ' statement(s)'
            . ($from > 0 ? ', resuming at index ' . $from . ' (' . $from . ' already applied)' : ''));

        for ($i = $from; $i < $total; $i++) {
            $statement = $statements[$i];
            if ($verbose) {
                out('  ' . $version . '#' . $i . ' sql: ' . preview($statement));
            }
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                errln('FAILED ' . $version . '#' . $i . ': ' . $e->getMessage());
                errln('ledger state: ' . $version . ' has statements 0..' . ($i - 1) . ' recorded '
                    . 'applied (' . $i . ' of ' . $total . '); the file is NOT marked applied.');
                errln('NOT ROLLED BACK: MySQL DDL implicitly commits, so those statements are '
                    . 'permanent. Fix the cause and re-run — this runner resumes at index ' . $i . '.');
                errln('summary: files_applied=' . $filesApplied . ' statements_executed='
                    . $stmtsExecuted . ' already_applied=' . $alreadyApplied);
                errln('FAILED (exit ' . EXIT_MIGRATION_FAILED . ')');
                return EXIT_MIGRATION_FAILED;
            }

            try {
                $recordStmt->execute([$version, $i, checksumOf($statement)]);
            } catch (PDOException $e) {
                // Executed but unrecorded: the DB is ahead of the ledger and a
                // re-run would re-execute this statement. Say so plainly.
                fail(EXIT_LEDGER, $version . '#' . $i . ' EXECUTED but its ledger row could not '
                    . 'be written (' . $e->getMessage() . '). The database is ahead of the ledger; '
                    . 're-running would re-execute this statement. Reconcile by hand.');
            }
            $stmtsExecuted++;
            out('  ' . $version . '#' . $i . ' ok');
        }

        recordFileApplied($recordFile, $version);
        out($version . ': APPLIED (' . $total . ' statement(s))');
        $filesApplied++;
    }

    if ($dryRun) {
        out('summary: pending=' . $pending . ' already_applied=' . $alreadyApplied);
        out('dry-run: no changes made');
        out('OK');
        return EXIT_OK;
    }

    out('summary: files_applied=' . $filesApplied . ' statements_executed=' . $stmtsExecuted
        . ' already_applied=' . $alreadyApplied);
    out('OK');
    return EXIT_OK;
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

function main(array $argv): int
{
    if (PHP_SAPI !== 'cli') {
        // The fork's docroot is public/, so this file is not web-reachable —
        // but a docroot mistake must never turn it into a remote DDL endpoint.
        http_response_code(404);
        return EXIT_USAGE;
    }

    $opts = parseArgs($argv);
    $migrations = discoverMigrations($opts['dir']);

    out('mode=' . ($opts['baseline'] ? 'baseline' : ($opts['dryRun'] ? 'dry-run' : 'apply'))
        . ' dir=' . (realpath($opts['dir']) ?: $opts['dir'])
        . ' files=' . count($migrations));

    if ($migrations === []) {
        out('no NNN[_name].sql migration files found — nothing to do');
        out('OK');
        return EXIT_OK;
    }

    [$pdo, $db] = connect($opts['urlEnv']);

    // Advisory lock scoped to the target schema: two concurrent runners would
    // interleave statements and corrupt the ledger's per-statement claim.
    $lockName = substr('waiver_migrations:' . $db, 0, 64);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, ?)');
    $lock->execute([$lockName, LOCK_TIMEOUT_SEC]);
    if ((int)$lock->fetchColumn() !== 1) {
        fail(EXIT_ENV, 'another migration run holds the advisory lock on this database '
            . '(waited ' . LOCK_TIMEOUT_SEC . 's); refusing to run concurrently.');
    }

    try {
        if ($opts['dryRun']) {
            if (!tableExists($pdo, $db, LEDGER_FILES)) {
                out('ledger absent — a real run would create ' . LEDGER_FILES . ' and '
                    . LEDGER_STATEMENTS);
            } elseif (!tableExists($pdo, $db, LEDGER_STATEMENTS)) {
                out('ledger partially present — a real run would create ' . LEDGER_STATEMENTS);
            }
        } else {
            ensureLedger($pdo, $db, $opts['verbose']);
        }

        return $opts['baseline']
            ? runBaseline($pdo, $db, $migrations, $opts['through'])
            : runApply($pdo, $db, $migrations, $opts['dryRun'], $opts['verbose']);
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
}

try {
    exit(main($argv));
} catch (Throwable $e) {
    // Catch-all so no PHP fatal/stack trace ever escapes UNREDACTED into a CI
    // log. Everything expected exits through fail(); reaching here is a bug or
    // a genuinely broken environment.
    errln('unexpected ' . get_class($e) . ': ' . $e->getMessage());
    errln('FAILED (exit ' . EXIT_ENV . ')');
    exit(EXIT_ENV);
}
