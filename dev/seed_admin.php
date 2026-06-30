<?php
require __DIR__.'/../vendor/autoload.php';
$cfg = require __DIR__.'/../config/config.php';
use App\Database;
$db = new Database($cfg['db']);
$email = $argv[1] ?? 'admin@example.com';
$pass  = $argv[2] ?? 'changeme';
$hash = password_hash($pass, PASSWORD_ARGON2ID);
$db->pdo()->prepare('INSERT INTO users (email, password_hash, role, created_at, updated_at) VALUES (?, ?, "admin", UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute([$email, $hash]);
echo "Admin created: $email\n";
