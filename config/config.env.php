<?php
// [railway-prod] Environment-driven config. Returns the SAME array shape as
// config/config.php.docker, but every deploy-specific value is read from
// getenv() so the image carries no secrets. The container entrypoint copies
// this file to config/config.php at boot (the four public entrypoints —
// api.php/w.php/admin.php + dev/seed_admin.php — always `require config.php`).
//
// Every key below is actually consumed by the code (verified against src/):
//   db.{host,port,name,user,pass,charset} -> Database::__construct
//   app.{base_url,timezone}               -> WaiverController (guest links, TZ)
//   security.session_name                 -> Auth::{login,require,logout}
//   security.password_algo                -> dev/seed_admin.php (password_hash)
//   security.api_hmac_secret              -> Utils::assertNoPlaceholderSecrets
//   security.inbound_hmac_keys[keyId]     -> api.php envelope verify (BookingV2 -> fork)
//   callback.{base_url,outbound_secret,outbound_key_id,evidence_url}
//                                          -> WaiverController outbound webhooks (fork -> BookingV2)
//   storage.{signatures_path,artifacts_path} -> WaiverController PDF/signature writes
//
// Secrets are DISTINCT per direction and must never be shared (lateral-replay):
//   WAIVER_APP_HMAC_SECRET      = inbound  (BookingV2 signs -> fork verifies)
//   WAIVER_CALLBACK_HMAC_SECRET = outbound (fork signs -> BookingV2 verifies)
// The fail-closed sentinel (Utils::assertNoPlaceholderSecrets) refuses boot if
// either resolves to the CHANGE_ME placeholder, so missing env => empty string
// => not the placeholder, but Database/HMAC will then fail loudly at use.

$appKeyId      = getenv('WAIVER_APP_HMAC_KEY_ID') ?: 'k1';
$appSecret     = getenv('WAIVER_APP_HMAC_SECRET') ?: '';
$callbackKeyId = getenv('WAIVER_CALLBACK_HMAC_KEY_ID') ?: 'k1';

return [
  'db' => [
    'host'    => getenv('MYSQLHOST') ?: 'localhost',
    'port'    => (int)(getenv('MYSQLPORT') ?: 3306),
    'name'    => getenv('MYSQLDATABASE') ?: '',
    'user'    => getenv('MYSQLUSER') ?: '',
    'pass'    => getenv('MYSQLPASSWORD') ?: '',
    'charset' => 'utf8mb4',
  ],
  'app' => [
    'base_url' => getenv('APP_BASE_URL') ?: '',
    'timezone' => 'Europe/Bucharest',
  ],
  'security' => [
    'session_name'   => 'waiver_admin',
    'password_algo'  => PASSWORD_ARGON2ID,
    // api_hmac_secret + inbound_hmac_keys both carry WAIVER_APP_HMAC_SECRET
    // (BookingV2 -> fork direction), keyed for rotation.
    'api_hmac_secret' => $appSecret,
    'inbound_hmac_keys' => [
      $appKeyId => $appSecret,
    ],
  ],
  'callback' => [
    'base_url'        => getenv('CALLBACK_BASE_URL') ?: '',
    'outbound_secret' => getenv('WAIVER_CALLBACK_HMAC_SECRET') ?: '',
    'outbound_key_id' => $callbackKeyId,
    // null => derive "<base_url>/api/waiver/evidence" (see WaiverController).
    'evidence_url'    => null,
  ],
  'storage' => [
    'signatures_path' => '/var/www/html/storage/signatures',
    'artifacts_path'  => '/var/www/html/storage/artifacts',
  ],
];
