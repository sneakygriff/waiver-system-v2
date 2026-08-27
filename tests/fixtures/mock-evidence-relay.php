<?php
// [M5 gate fold F8] Minimal stand-in for BookingV2's POST /api/waiver/evidence
// relay, run via `php -S` as a real subprocess by
// WaiverControllerSubmitEvidenceTest::startMockRelay(). Returns the exact
// top-level shape uploadEvidence() reads back (`blob_key`, `blob_url` --
// src/WaiverController.php's uploadEvidence()), matching what
// src/app/api/waiver/evidence/route.ts's real response carries. Does NOT
// verify the HMAC envelope or parse the request body at all -- this fixture
// exists to exercise the FORK's OWN persistence of whatever the relay
// returns (submitGuestForm's INSERT, uploadEvidence's widened 4-key return),
// not the envelope round-trip itself (UtilsVerifySignedEnvelopeTest already
// covers HMAC verification in isolation).
header('Content-Type: application/json');
echo json_encode([
    'status' => 'stored',
    'blob_key' => 'waiver-evidence/mock-relay-fixture/999/pdf.pdf',
    'blob_url' => 'https://mock-blob-store.example.invalid/waiver-evidence/mock-relay-fixture/999/pdf.pdf',
    'artifacts' => [
        'pdf' => [
            'key' => 'waiver-evidence/mock-relay-fixture/999/pdf.pdf',
            'url' => 'https://mock-blob-store.example.invalid/waiver-evidence/mock-relay-fixture/999/pdf.pdf',
            'sha256' => str_repeat('0', 64),
        ],
    ],
]);
