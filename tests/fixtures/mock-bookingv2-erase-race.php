<?php
// [GVS-89 / gate 89-M4 P1] `php -S` stand-in for BookingV2's evidence relay
// that makes the resend_evidence <-> erase_waiver race DETERMINISTIC: the
// concurrent erasure is fired from INSIDE the relay request, i.e. at the
// exact moment the fork's uploadEvidence() is in flight -- after
// resendEvidence() has read the retained files and before it records the
// push. That is the window gate 89-M4 flagged. The erasure runs in THIS
// process with its OWN DB connection, so it is a genuinely concurrent MySQL
// session (named locks are per session), not a re-entrant call.
//
// Modes (?mode= on the evidence URL the fork is configured to post to):
//   erase-via-controller   a real WaiverController::eraseWaiver() by the
//                          uploaded link_token, with a 1 s evidence-lock
//                          wait. Correct code -> {error:'evidence_busy'}
//                          (the resend holds the lock) and nothing deleted.
//   delete-bypassing-lock  raw SQL deletion of the instance, its response row
//                          and its audit trail WITHOUT the evidence lock (a
//                          manual/ops deletion) -- exercises resendEvidence's
//                          conditional UPDATE + rowCount check.
//   record-only            [gate 89-M4 r2] does nothing but record the call,
//                          so a test can assert an upload was NEVER attempted
//                          (the capture file must not exist).
// The outcome is written to sys_get_temp_dir()/waiver-race-capture-<port>.json
// for the test to read; the HTTP answer is always the relay's normal success
// shape (blob_key/blob_url), so the fork believes the upload landed.
require __DIR__.'/../../vendor/autoload.php';

$raw = (string)file_get_contents('php://input');
$body = json_decode($raw, true);
$mode = (string)($_GET['mode'] ?? 'erase-via-controller');
$port = $_SERVER['SERVER_PORT'] ?? 'unknown';
$out = ['mode' => $mode, 'link_token' => $body['link_token'] ?? null];

try {
  $cfg = require __DIR__.'/../../config/config.test.php';
  $cfg['evidence_lock'] = ['erase_wait_seconds' => 1];
  $db = new App\Database($cfg['db']);
  if ($mode === 'erase-via-controller') {
    $ctl = new App\WaiverController($cfg, $db);
    $out['erase'] = $ctl->eraseWaiver(['link_tokens' => [(string)($body['link_token'] ?? '')]]);
  } elseif ($mode === 'delete-bypassing-lock') {
    $pdo = $db->pdo();
    $id = (int)($body['waiver_instance_id'] ?? 0);
    $pdo->prepare('DELETE FROM waiver_responses WHERE waiver_instance_id=?')->execute([$id]);
    $pdo->prepare("DELETE FROM audit_events WHERE entity_type IN ('instance','response') AND entity_id=?")->execute([$id]);
    $pdo->prepare('DELETE FROM waiver_instances WHERE id=?')->execute([$id]);
    $out['deleted_instance_id'] = $id;
  } elseif ($mode === 'record-only') {
    $out['recorded'] = true;
  } else {
    $out['unknown_mode'] = true;
  }
} catch (\Throwable $e) {
  $out['exception'] = get_class($e).': '.$e->getMessage();
}

file_put_contents(sys_get_temp_dir().'/waiver-race-capture-'.$port.'.json', json_encode($out));

header('Content-Type: application/json');
echo json_encode([
  'status' => 'stored',
  'blob_key' => 'waiver-evidence/race-fixture/999/pdf.pdf',
  'blob_url' => 'https://mock-blob-store.example.invalid/waiver-evidence/race-fixture/999/pdf.pdf',
]);
