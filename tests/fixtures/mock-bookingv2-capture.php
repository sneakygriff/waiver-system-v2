<?php
// [GVS-89 / 89-M4.3] Minimal stand-in for BookingV2's evidence relay AND its
// two completion webhooks (POST /api/waiver/complete and
// POST /api/waiver/public-complete), run via `php -S` as a real subprocess --
// same technique as tests/fixtures/mock-evidence-relay.php (M5 gate fold F8),
// extended to also capture the completion-webhook body verbatim so a test can
// assert on the EXACT JSON notifyBookingV2Completion() posts (which endpoint,
// and the body's event/signup_token/binding-id shape) rather than only on
// its side effects inside the fork's own database. Does NOT verify the HMAC
// envelope or otherwise validate the request -- that is
// UtilsVerifySignedEnvelopeTest's job, not this fixture's.
//
// Capture file naming: one file per (port, request path), so a test that
// happens to exercise both endpoints against the SAME `php -S` instance
// (shouldn't happen for one instance -- it fires exactly one completion --
// but a suite reusing a port across cases could) never overwrites the wrong
// capture. Callers compute the same path with the sha1 of the exact request
// path they expect (see WaiverControllerPublicTest::captureFileFor()).
header('Content-Type: application/json');
$uri = $_SERVER['REQUEST_URI'] ?? '';
$path = explode('?', $uri, 2)[0];
$raw = file_get_contents('php://input');
$port = $_SERVER['SERVER_PORT'] ?? 'unknown';

if ($path === '/api/waiver/evidence') {
  echo json_encode([
    'status' => 'stored',
    'blob_key' => 'waiver-evidence/capture-fixture/999/pdf.pdf',
    'blob_url' => 'https://mock-blob-store.example.invalid/waiver-evidence/capture-fixture/999/pdf.pdf',
  ]);
  exit;
}

$captureFile = sys_get_temp_dir().'/waiver-webhook-capture-'.$port.'-'.sha1($path).'.json';
file_put_contents($captureFile, json_encode(['path' => $path, 'body' => $raw]));
echo json_encode(['status' => 'ok']);
