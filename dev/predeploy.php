<?php
// One-shot migration + admin seed, run as Railway preDeployCommand.
// Uses config.env.php (env-driven) so it works without the entrypoint's config.php copy.
error_reporting(E_ALL);
require '/var/www/html/vendor/autoload.php';
$cfg = require '/var/www/html/config/config.env.php';
$db = new App\Database($cfg['db']);
$pdo = $db->pdo();

// --- Migration: 001_init.sql (idempotent: CREATE TABLE IF NOT EXISTS) ---
$applied = false;
try {
  $row = $pdo->query("SELECT version FROM schema_migrations WHERE version='001_init'")->fetch();
  if ($row) { $applied = true; echo "[migrate] 001_init already applied\n"; }
} catch (Throwable $e) { /* schema_migrations absent -> fresh DB */ }

if (!$applied) {
  $sql = file_get_contents('/var/www/html/migrations/001_init.sql');
  if ($sql === false) { fwrite(STDERR, "[migrate] cannot read 001_init.sql\n"); exit(1); }
  // Strip the trailing schema_migrations INSERT so we can run it guarded separately
  // (multi-statement exec is fine; the INSERT would dup-error only on re-run, which
  //  the $applied guard above prevents).
  try {
    $pdo->exec($sql);
    echo "[migrate] 001_init applied OK\n";
  } catch (Throwable $e) {
    fwrite(STDERR, "[migrate] EXEC error: ".$e->getMessage()."\n");
    exit(1);
  }
}

// --- Verify tables ---
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "[migrate] tables: ".implode(",", $tables)."\n";

// --- Migrations 002+ : run the full ledger runner, FAIL-FAST -------------------
// [post-incident 2026-08-30] The 001 bootstrap above only ever reaches the
// `001_init` ledger row. Every LATER migration (002..005 today, 006+ tomorrow)
// used to be hand-applied, so a deploy could ship code whose schema had never
// been migrated -- exactly the outage this fixes. So invoke migrations/run.php,
// check its exit code, and ABORT the deploy on any non-zero: never ship code
// against an unmigrated schema.
//
// The runner reads a mysql:// URL from MYSQL_URL (Railway injects it for the
// attached MySQL service; run.php's default env is STAGING_WAIVER_DB_URL, so we
// pass --url-env=MYSQL_URL). It targets the SAME database this predeploy already
// connected to above (App\Database reads the discrete MYSQL* vars; MYSQL_URL is
// Railway's composite for the same service). If MYSQL_URL is absent/unparseable
// the runner exits non-zero and we abort -- fail-safe, never a silent skip.
//
// FRESH vs EXISTING ordering (the load-bearing detail):
//   * FRESH DB (!$applied): 001_init.sql is the WHOLE current schema -- 002/003
//     and 005's evidence_* columns are all baked into it (see its header and the
//     002/003 file headers: "Fresh installs get these baked directly into
//     001_init.sql"). Their DDL is therefore ALREADY physically present the
//     instant 001 runs. Running 002+ in apply-mode here would raise "Duplicate
//     column name" and abort a perfectly good fresh deploy. So we BASELINE the
//     present files instead (mark them applied, execute nothing) -- correct
//     under this repo's standing convention that every migration is baked into
//     001_init.sql (compose's initdb and CI both bootstrap fresh DBs with 001
//     alone for exactly this reason). A genuinely-new future 006 that is NOT yet
//     baked is not part of a fresh bootstrap's schema, so it is added to an
//     EXISTING db and picked up by the apply branch below.
//   * EXISTING DB ($applied, e.g. prod: 001..005 already ledgered): apply-mode
//     is the right tool. It skips every already-recorded file (a no-op for
//     001..005 today) and runs only genuinely-pending files, so a future 006
//     auto-applies on the next deploy. Verified: pointed at a DB that already
//     has 001..005 in schema_migrations, run.php reports each "already applied"
//     and exits 0.
//
// run.php's ledger (schema_migrations) MUST pre-exist; the 001 bootstrap above
// guarantees it on a fresh DB, and it is present on every existing DB.
$runnerArgs = $applied ? '' : ' --baseline';
$runnerCmd  = 'php '.escapeshellarg('/var/www/html/migrations/run.php').' --url-env=MYSQL_URL'.$runnerArgs;
echo "[migrate] running full runner (".($applied ? 'apply' : 'baseline: fresh DB, schema baked into 001')."): $runnerCmd\n";
$rc = 0;
passthru($runnerCmd, $rc);
if ($rc !== 0) {
  fwrite(STDERR, "[migrate] migration runner FAILED (exit $rc) -- ABORTING deploy so unmigrated code never ships\n");
  exit(1);
}
echo "[migrate] runner OK (exit 0)\n";

// --- Seed admin (idempotent) ---
$email = getenv('SEED_ADMIN_EMAIL');
$pass  = getenv('SEED_ADMIN_PASSWORD');
if ($email && $pass) {
  $exists = $pdo->prepare("SELECT id FROM users WHERE email=?");
  $exists->execute([$email]);
  if ($exists->fetch()) {
    echo "[seed] admin $email already exists\n";
  } else {
    $hash = password_hash($pass, PASSWORD_ARGON2ID);
    $pdo->prepare('INSERT INTO users (email, password_hash, role, created_at, updated_at) VALUES (?, ?, "admin", UTC_TIMESTAMP(), UTC_TIMESTAMP())')
        ->execute([$email, $hash]);
    echo "[seed] admin created: $email\n";
  }
} else {
  echo "[seed] SEED_ADMIN_EMAIL/PASSWORD unset -> skipping admin seed\n";
}
echo "[predeploy] DONE\n";
