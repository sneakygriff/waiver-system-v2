<?php
require __DIR__.'/../vendor/autoload.php';
$cfg = require __DIR__.'/../config/config.php';
use App\{Database, WaiverController, Utils};

// API responses are JSON only: never let a PHP warning/notice leak into the body.
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Verify HMAC over the EXACT raw bytes received, before any defaulting — so a
// caller that honestly signs an empty body isn't rejected, and signing '{}'
// while sending nothing isn't silently accepted.
$raw = file_get_contents('php://input'); if ($raw === false) $raw = '';
$hmac = $_SERVER['HTTP_X_HMAC'] ?? null;
if (!Utils::hmacValid($raw, $cfg['security']['api_hmac_secret'], $hmac)) {
  Utils::jsonResponse(401, ['error'=>'invalid hmac']);
}

$payload = json_decode($raw !== '' ? $raw : '{}', true);
if (!is_array($payload)) $payload = [];
$action = is_string($payload['action'] ?? null) ? $payload['action'] : '';

try {
  $db = new Database($cfg['db']);
  $ctl = new WaiverController($cfg, $db);

  if ($action === 'create_waiver') {
    $res = $ctl->createInstance($payload);
    Utils::jsonResponse(empty($res['error']) ? 200 : 400, $res);
  }

  if ($action === 'create_walkin_group') {
    Utils::jsonResponse(200, ['group_token'=>Utils::randomToken(8)]);
  }

  if ($action === 'link_waivers') {
    $reservationId = $payload['reservation_id'] ?? '';
    $waiverIds = $payload['waiver_ids'] ?? [];
    $groupToken = $payload['group_token'] ?? null;
    if (!is_string($reservationId)) Utils::jsonResponse(400, ['error'=>'reservation_id must be a string']);
    if (!is_array($waiverIds)) Utils::jsonResponse(400, ['error'=>'waiver_ids must be an array']);
    if ($groupToken !== null && !is_scalar($groupToken)) Utils::jsonResponse(400, ['error'=>'group_token must be a string']);
    $includePending = (bool)($payload['include_pending'] ?? false);
    $res = $ctl->linkWaiversToReservation($reservationId, $waiverIds, $groupToken !== null ? (string)$groupToken : null, $includePending);
    Utils::jsonResponse(empty($res['error']) ? 200 : 400, $res);
  }

  Utils::jsonResponse(404, ['error'=>'unknown action']);
} catch (\Throwable $e) {
  // Never leak a stack trace / server paths to the caller.
  Utils::jsonResponse(500, ['error'=>'internal server error']);
}
