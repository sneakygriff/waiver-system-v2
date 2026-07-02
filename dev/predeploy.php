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
