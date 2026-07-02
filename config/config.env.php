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
// FAIL CLOSED: when a critical secret env var is UNSET/empty, fall back to the
// literal placeholder Utils::PLACEHOLDER_SECRET rather than ''. The boot
// sentinel (Utils::assertNoPlaceholderSecrets) rejects the placeholder and
// refuses to boot (500) -- so a deploy that forgets a secret fails LOUDLY at
// startup instead of quietly running with an empty (world-known) HMAC secret
// that would authenticate/sign every request. Keep this string identical to
// Utils::PLACEHOLDER_SECRET. (Local var, not a const, so a defensive
// double-require of this file can never fatal on redefinition.)
$placeholderSecret = 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET';

$appKeyId      = getenv('WAIVER_APP_HMAC_KEY_ID') ?: 'k1';
$appSecret     = getenv('WAIVER_APP_HMAC_SECRET') ?: $placeholderSecret;
$callbackKeyId = getenv('WAIVER_CALLBACK_HMAC_KEY_ID') ?: 'k1';
$callbackSecret = getenv('WAIVER_CALLBACK_HMAC_SECRET') ?: $placeholderSecret;

// app.base_url is load-bearing but NOT a secret, so the sentinel never checks
// it. It builds every guest signing link (WaiverController: rtrim(base_url)
// .'/w.php?token='). An empty base_url would silently mint dead links
// ("/w.php?token=..."), so guard it explicitly and fail LOUDLY at boot.
$appBaseUrl = getenv('APP_BASE_URL') ?: '';
if ($appBaseUrl === '') {
  http_response_code(500);
  exit('Server misconfigured: APP_BASE_URL is empty -- guest waiver links would '
     . 'be dead. Set APP_BASE_URL (e.g. https://waiver.example.com) before boot.');
}

// callback.base_url distinguishes "integration disabled" from "misconfigured":
//   - UNSET  => integration intentionally OFF; outbound webhook/evidence upload
//               are no-ops (WaiverController::notifyBookingV2Completion /
//               uploadEvidence bail on empty base_url). outbound_secret then
//               isn't sentinel-checked (Utils: only when base_url non-empty).
//   - SET-but-EMPTY-string => almost certainly a misconfigured deploy (someone
//               set CALLBACK_BASE_URL="" by mistake); fail LOUDLY rather than
//               silently disabling the completion callback to BookingV2.
$callbackBaseEnv = getenv('CALLBACK_BASE_URL');
if ($callbackBaseEnv !== false && trim($callbackBaseEnv) === '') {
  http_response_code(500);
  exit('Server misconfigured: CALLBACK_BASE_URL is set but empty. Unset it to '
     . 'disable the BookingV2 callback integration, or set a real URL.');
}
$callbackBaseUrl = $callbackBaseEnv !== false ? $callbackBaseEnv : '';

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
    'base_url' => $appBaseUrl,
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
    'base_url'        => $callbackBaseUrl,
    'outbound_secret' => $callbackSecret,
    'outbound_key_id' => $callbackKeyId,
    // null => derive "<base_url>/api/waiver/evidence" (see WaiverController).
    'evidence_url'    => null,
  ],
  'storage' => [
    'signatures_path' => '/var/www/html/storage/signatures',
    'artifacts_path'  => '/var/www/html/storage/artifacts',
  ],
];
