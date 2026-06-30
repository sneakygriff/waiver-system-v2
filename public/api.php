<?php
require __DIR__.'/../vendor/autoload.php';
$cfg = require __DIR__.'/../config/config.php';
use App\{Database, WaiverController, Utils};

$body = file_get_contents('php://input') ?: '{}';
$hmac = $_SERVER['HTTP_X_HMAC'] ?? null;
if (!Utils::hmacValid($body, $cfg['security']['api_hmac_secret'], $hmac)) {
  Utils::jsonResponse(401, ['error'=>'invalid hmac']);
}

$payload = json_decode($body, true) ?: [];
$db = new Database($cfg['db']);
$ctl = new WaiverController($cfg, $db);

$action = $payload['action'] ?? '';
if ($action === 'create_waiver') {
  $res = $ctl->createInstance($payload);
  if (!empty($res['error'])) Utils::jsonResponse(400, $res);
  Utils::jsonResponse(200, $res);
}

if ($action === 'create_walkin_group') {
  $token = \App\Utils::randomToken(8);
  \App\Utils::jsonResponse(200, ['group_token'=>$token]);
}

if ($action === 'link_waivers') {
  $reservationId = $payload['reservation_id'] ?? '';
  $waiverIds = $payload['waiver_ids'] ?? [];
  $groupToken = $payload['group_token'] ?? null;
  $includePending = (bool)($payload['include_pending'] ?? false);
  $res = $ctl->linkWaiversToReservation($reservationId, $waiverIds, $groupToken, $includePending);
  if (!empty($res['error'])) \App\Utils::jsonResponse(400, $res);
  \App\Utils::jsonResponse(200, $res);
}

Utils::jsonResponse(404, ['error'=>'unknown action']);
