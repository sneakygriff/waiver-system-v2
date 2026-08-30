<?php
declare(strict_types=1);
// dev/predeploy.php — Railway preDeployCommand: migrate THIS service's OWN
// database, then seed the admin. Runs once per deploy, BEFORE the new release
// serves traffic. Exits NON-ZERO to ABORT the deploy on any migration problem
// (fail-safe: never ship code against an unmigrated / odd schema).
//
// [post-incident 2026-08-30] Rewritten to close a cluster of deploy-critical
// review findings. Each is tagged inline (#1..#6 + Grok #2/#4).

// Grok #4: derive the repo root from THIS file's location (dev/), never a
// hardcoded '/var/www/html'. A WORKDIR / mount-path drift would otherwise make
// require/passthru resolve the wrong path and abort every deploy.
$ROOT = dirname(__DIR__);
require $ROOT.'/vendor/autoload.php';

// The highest migration whose DDL is ALREADY BAKED into 001_init.sql. A FRESH
// DB gets 001..this baked physically the instant 001_init.sql runs, so on a
// fresh bootstrap these are BASELINED (marked applied, executed nothing) --
// executing their ALTERs would raise "Duplicate column". BUMP THIS whenever a
// new migration's DDL is folded into 001_init.sql. (Finding #3.)
const MAX_BAKED_MIGRATION = '005_evidence_fields';

// A representative column that PROVES MAX_BAKED_MIGRATION's DDL physically
// landed in the schema. BUMP THIS TOGETHER WITH MAX_BAKED_MIGRATION: pick a
// column introduced by that migration (evidence_object_key is added by
// 005_evidence_fields.sql). The post-migrate schema assertion below uses it as
// belt-and-suspenders so a "complete ledger but hollow schema" -- a lying
// ledger, or a truncated multi-statement 001_init exec -- fails LOUD at deploy
// time (exit 1) instead of re-shipping the incident. (Finding: Grok New #3.)
const MAX_BAKED_ASSERT_TABLE  = 'waiver_responses';
const MAX_BAKED_ASSERT_COLUMN = 'evidence_object_key';

// A core application table whose PRESENCE means "this DB already carries the app
// schema" -- the FRESH vs EXISTING decision (Finding #2). Created by
// 001_init.sql and never dropped, so its presence is authoritative REGARDLESS of
// what the schema_migrations LEDGER contains: a legacy DB can have tables with a
// partial/absent ledger (migrations/README "Local compose note"), and keying off
// the 001_init ledger row would misclassify it as fresh -> baseline-skip 005 ->
// re-ship the incident.
const CORE_TABLE = 'waiver_instances';

// --- Finding #5 (documented, deliberately NOT special-cased in code) ---------
// 004_erasure_audit_events_backfill.sql is COMMENT-ONLY (zero DDL). Its real
// work -- DELETEing the PII left behind by pre-fix erasure calls -- is a MANUAL
// operator step (see that file's header). The runner records 004 as "applied
// (0 statements)"; that marker means only "the migration FILE was processed",
// it does NOT prove the operator's backfill DELETE actually ran. On a FRESH DB
// there is no pre-existing PII to backfill, so baselining 004 here is correct;
// on the EXISTING prod DB 004 is already ledgered (a no-op). We deliberately do
// NOT teach the runner/predeploy to special-case 004 -- that would over-engineer
// and couple this orchestration to one migration's semantics. The guarantee is
// a documented one; see migrations/004_erasure_audit_events_backfill.sql.

function pd_out(string $m): void { fwrite(STDOUT, $m."\n"); }
function pd_err(string $m): void { fwrite(STDERR, $m."\n"); }

/**
 * Run migrations/run.php with the given extra args. ALWAYS targets
 * --url-env=PREDEPLOY_DB_URL (never the runner's STAGING_WAIVER_DB_URL default).
 * Aborts the deploy (exit 1) on ANY failure: a non-zero runner exit, OR --
 * Grok #4 -- passthru() itself returning false on a spawn failure while leaving
 * the initialized $rc at 0 (that would be a silent false PASS).
 */
function pd_run_runner(string $runner, string $extraArgs, string $label): void {
  $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($runner)
    .' --url-env=PREDEPLOY_DB_URL'.($extraArgs !== '' ? ' '.$extraArgs : '');
  pd_out('[migrate] '.$label.': '.$cmd);
  $rc  = 0;
  $ret = passthru($cmd, $rc);
  if ($ret === false || $rc !== 0) {
    pd_err('[migrate] FAILED ('.$label.'): '
      .($ret === false ? 'passthru could not spawn the runner' : 'runner exited '.$rc)
      .' -- ABORTING deploy so unmigrated/odd schema never ships. exit 1');
    exit(1);
  }
  pd_out('[migrate] OK ('.$label.')');
}

// --- Finding #1: build the DB connection from the DISCRETE MYSQL* vars ONLY ---
// Do NOT `require config/config.env.php` here. That WEB config template calls
// exit('string') (exit STATUS 0 == SUCCESS) when APP_BASE_URL is empty or
// CALLBACK_BASE_URL is set-but-empty -- so a config problem would make THIS
// predeploy report SUCCESS without ever migrating (the original false-success
// bug). We instead read the same discrete vars App\Database consumes and fail
// LOUDLY (exit 1) on any missing/empty required var -- never a bare exit('..').
$host = getenv('MYSQLHOST');
$portRaw = getenv('MYSQLPORT');
$name = getenv('MYSQLDATABASE');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');

$missing = [];
foreach (['MYSQLHOST'=>$host, 'MYSQLDATABASE'=>$name, 'MYSQLUSER'=>$user, 'MYSQLPASSWORD'=>$pass] as $k=>$v) {
  if ($v === false || trim((string)$v) === '') { $missing[] = $k; }
}
if ($missing !== []) {
  pd_err('[predeploy] FATAL: required DB env var(s) missing/empty: '.implode(', ', $missing)
    .' -- refusing to run (a config gap must never report a false-success migrate). exit 1');
  exit(1);
}
$host = trim((string)$host);
$name = trim((string)$name);
$user = (string)$user;   // credentials kept verbatim (no trim)
$pass = (string)$pass;
$port = ($portRaw === false || trim((string)$portRaw) === '') ? 3306 : (int)$portRaw;

$dbCfg = [
  'host'    => $host,
  'port'    => $port,
  'name'    => $name,
  'user'    => $user,
  'pass'    => $pass,
  'charset' => 'utf8mb4',
];

try {
  $db  = new App\Database($dbCfg);
  $pdo = $db->pdo();
} catch (\Throwable $e) {
  // Never leak the password: report only the target coordinates + class.
  pd_err('[predeploy] FATAL: cannot connect to '.$host.':'.$port.'/'.$name.' as user '.$user
    .' ('.get_class($e).'). exit 1');
  exit(1);
}

// --- Finding: DB-name round-trip (Claude #A / Grok New #1), defence-in-depth ---
// PROVE predeploy is actually operating on the DB it was CONFIGURED with. The
// DSN pins dbname=, so a connect against a nonexistent db already throws above;
// this assertion additionally guarantees that predeploy's own target and the
// canonical URL we hand the runner below (both derived from $name) point at the
// physically-selected database. If they ever diverge, ABORT rather than migrate
// the wrong DB.
try {
  $activeDb = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
} catch (\Throwable $e) {
  pd_err('[predeploy] FATAL: could not read SELECT DATABASE() ('.get_class($e).'). exit 1');
  exit(1);
}
if ($activeDb !== $name) {
  pd_err('[predeploy] FATAL: connected database "'.$activeDb.'" != configured MYSQLDATABASE "'.$name
    .'" -- refusing to migrate the wrong DB. exit 1');
  exit(1);
}
pd_out('[predeploy] connected to database "'.$activeDb.'" (matches MYSQLDATABASE)');

// --- Finding #6 + operator DB-ISOLATION mandate ------------------------------
// Derive ONE canonical mysql:// URL from the SAME discrete vars we just
// connected with, hand it to the runner under a DEDICATED env name, and ALWAYS
// invoke run.php with --url-env=PREDEPLOY_DB_URL. This makes predeploy and the
// runner PROVABLY hit the same database, and means the runner can NEVER fall
// back to its STAGING_WAIVER_DB_URL default.
//
// ASSERTION (contract): the migrate target is derived SOLELY from this service's
// own MYSQL* vars above. It must NEVER read STAGING_WAIVER_DB_URL. A prod deploy
// must never migrate against -- or receive DDL/data destined for -- staging.
// (Migrations are DDL-only; this pins the target to prod's own DB.)
//
// Built by concatenation (not one credential-shaped literal). user/pass are
// rawurlencode()d so reserved characters survive parse_url() in run.php, which
// rawurldecode()s them back.
//
// [Finding: DB-name round-trip — Claude #A / Grok New #1] The db NAME is
// DELIBERATELY passed RAW (NOT rawurlencode()d). run.php's connect() derives the
// db from `ltrim(parse_url()['path'],'/')` and -- unlike user/pass -- does NOT
// rawurldecode() the path. So if we encoded a reserved char in the name (e.g.
// '$' -> '%24'), predeploy would connect to '$' while the runner targeted the
// literal '%24' -> "unknown database" -> a FALSE-abort of a healthy deploy.
// Passing $name raw makes predeploy's target and the runner's byte-identical for
// any legal db name. (Inert for the real prod name "waiver"; correct in general.
// The SELECT DATABASE() assertion above independently pins predeploy's own end.)
$predeployUrl = 'mysql://'.rawurlencode($user).':'.rawurlencode($pass)
  .'@'.$host.':'.$port.'/'.$name.'?charset=utf8mb4';
putenv('PREDEPLOY_DB_URL='.$predeployUrl);
// Belt-and-suspenders isolation: force the runner's DEFAULT env name empty in
// this process, so even a future edit that dropped the --url-env flag could not
// silently connect the runner to a real staging DB -- run.php would see an empty
// STAGING_WAIVER_DB_URL and exit 3 (environment error), which we turn into an
// abort. "Wrong DB" becomes impossible, not merely unlikely.
putenv('STAGING_WAIVER_DB_URL=');

$runner = $ROOT.'/migrations/run.php';
if (!is_file($runner)) {
  pd_err('[predeploy] FATAL: migration runner not found at '.$runner.'. exit 1');
  exit(1);
}

// --- Finding #2: FRESH vs EXISTING by PHYSICAL SCHEMA, not the ledger --------
$showTables = $pdo->query('SHOW TABLES LIKE '.$pdo->quote(CORE_TABLE));
$coreTablePresent = $showTables->fetch() !== false;

if (!$coreTablePresent) {
  // ===== FRESH DB (no app tables) — Finding #3 ===============================
  // 1) Apply 001_init.sql: the WHOLE current schema (001..MAX_BAKED DDL baked
  //    in), and it creates schema_migrations + inserts the 001_init row.
  $initSql = file_get_contents($ROOT.'/migrations/001_init.sql');
  if ($initSql === false) { pd_err('[migrate] FATAL: cannot read 001_init.sql. exit 1'); exit(1); }
  // [Finding: multi-statement truncation — Grok New #2] 001_init.sql is a
  // MULTI-STATEMENT script (8x CREATE TABLE + a trailing schema_migrations
  // INSERT). PDO_MYSQL runs EVERY statement of a single exec() ONLY while
  // MYSQL_ATTR_MULTI_STATEMENTS is enabled. It IS on by default, but
  // App\Database does not set it explicitly and migrations/run.php deliberately
  // DISABLES it for its own connection -- so relying on the inherited default
  // here is fragile: a future default flip, or a copy of run.php's disable, would
  // silently truncate the schema to just the first CREATE. Open a DEDICATED
  // connection with the flag EXPLICITLY ON for this one full-schema exec so the
  // guarantee is version-controlled, not inherited. (The post-migrate schema
  // assertion further down is the independent backstop that catches a truncated
  // exec regardless -- a hollow schema fails that assertion and aborts.)
  $initOpts = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ];
  if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
    $initOpts[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = true;
  }
  try {
    $initDsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, 'utf8mb4');
    $initPdo = new PDO($initDsn, $user, $pass, $initOpts);
    $initPdo->exec($initSql);
  } catch (\Throwable $e) {
    pd_err('[migrate] FATAL: 001_init.sql failed ('.get_class($e).'). exit 1');
    exit(1);
  }
  pd_out('[migrate] FRESH DB: applied 001_init.sql (full current schema, multi-statement)');

  // 2) BASELINE the baked migrations (001..MAX_BAKED): mark them applied WITHOUT
  //    executing -- their DDL is already physically present from 001_init.sql,
  //    so running their ALTERs would raise "Duplicate column". Bounded by
  //    --through=MAX_BAKED so anything ABOVE it stays PENDING for step 3.
  pd_run_runner($runner, '--baseline --through='.MAX_BAKED_MIGRATION,
    'fresh baseline through '.MAX_BAKED_MIGRATION);

  // 3) APPLY: a genuinely-new migration whose DDL is NOT yet baked into 001_init
  //    (006+) is left PENDING by step 2 and is EXECUTED FOR REAL here (scenario
  //    e). A fresh DB carrying only 001..005 files no-ops (all baselined).
  pd_run_runner($runner, '', 'fresh apply (un-baked 006+)');
} else {
  // ===== EXISTING DB (tables present) — Finding #4 ==========================
  // Apply-mode ONLY. NEVER auto-baseline an unverified existing DB. For prod
  // (001..005 already ledgered) this is a clean no-op: the runner reports each
  // "already applied" and exits 0. A legacy/odd DB that cannot cleanly apply
  // (e.g. a baked ALTER hits "Duplicate column" because its ledger is missing
  // that row) makes the runner exit non-zero -> we exit 1 and BLOCK the deploy
  // for a human. That fail-SAFE is the whole point: the original bug silently
  // baseline-skipped on an existing DB and re-shipped the incident.
  pd_out('[migrate] EXISTING DB (core table '.CORE_TABLE.' present): apply-mode, fail-fast');
  pd_run_runner($runner, '', 'existing apply');
}

// --- Post-migrate SCHEMA ASSERTION (Grok New #3) -----------------------------
// The migration LEDGER now says the schema is at MAX_BAKED_MIGRATION. PROVE the
// PHYSICAL schema agrees BEFORE seeding / declaring success: assert that a
// representative column from MAX_BAKED_MIGRATION actually EXISTS. This is the
// belt-and-suspenders that turns a "complete ledger but hollow schema" -- a
// lying ledger, OR a truncated multi-statement 001_init exec -- into a LOUD
// deploy-time abort (exit 1) instead of silently re-shipping the 16h incident
// (code serving against a schema missing 005's columns). Runs on BOTH the fresh
// and existing paths (control only reaches here after a clean migrate). $name is
// the physically-selected DB (asserted == SELECT DATABASE() above).
try {
  $chk = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.COLUMNS '
    .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
  $chk->execute([$name, MAX_BAKED_ASSERT_TABLE, MAX_BAKED_ASSERT_COLUMN]);
  $colPresent = (int)$chk->fetchColumn();
} catch (\Throwable $e) {
  pd_err('[migrate] FATAL: post-migrate schema assertion query failed ('.get_class($e).'). exit 1');
  exit(1);
}
if ($colPresent < 1) {
  pd_err('[migrate] FATAL: schema assertion FAILED -- '.MAX_BAKED_ASSERT_TABLE.'.'.MAX_BAKED_ASSERT_COLUMN
    .' (represents '.MAX_BAKED_MIGRATION.') is MISSING from the physical schema even though the '
    .'migration ledger reports success. Refusing to ship code against a hollow schema. exit 1');
  exit(1);
}
pd_out('[migrate] schema assertion OK: '.MAX_BAKED_ASSERT_TABLE.'.'.MAX_BAKED_ASSERT_COLUMN
  .' present (represents '.MAX_BAKED_MIGRATION.')');

// --- Verify tables (diagnostic) ---
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
pd_out('[migrate] tables: '.implode(',', $tables));

// --- Seed admin (idempotent) — only AFTER a successful migrate ---
$email = getenv('SEED_ADMIN_EMAIL');
$adminPass = getenv('SEED_ADMIN_PASSWORD');
if ($email && $adminPass) {
  $exists = $pdo->prepare('SELECT id FROM users WHERE email=?');
  $exists->execute([$email]);
  if ($exists->fetch()) {
    pd_out('[seed] admin '.$email.' already exists');
  } else {
    $hash = password_hash($adminPass, PASSWORD_ARGON2ID);
    $pdo->prepare('INSERT INTO users (email, password_hash, role, created_at, updated_at) VALUES (?, ?, "admin", UTC_TIMESTAMP(), UTC_TIMESTAMP())')
        ->execute([$email, $hash]);
    pd_out('[seed] admin created: '.$email);
  }
} else {
  pd_out('[seed] SEED_ADMIN_EMAIL/PASSWORD unset -> skipping admin seed');
}
pd_out('[predeploy] DONE');
