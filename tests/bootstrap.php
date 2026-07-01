<?php
// [F1-fork-erasure] phpunit micro-harness bootstrap: loads the App\ autoloader
// and exposes a couple of tiny fixture helpers used by the test cases below.
// This harness is intentionally minimal (per the task spec) -- it does NOT
// attempt to be a full test suite for WaiverController; it exists to pin down
// the two specific correctness properties the erasure/security hardening
// depends on:
//   1. eraseWaiver() rolls back ALL of its DELETEs on a mid-transaction failure
//      (never a partial erasure).
//   2. Utils::verifySignedEnvelope() checks the signature BEFORE consuming the
//      nonce, so a replayed-but-tampered envelope never burns a legitimate
//      nonce slot.
require __DIR__.'/../vendor/autoload.php';
