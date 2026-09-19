<?php
// [GVS-89 / gate 89-M4 r2] A SECOND MySQL session, run as a real subprocess,
// for the submit <-> erase race tests in WaiverControllerPublicTest. The test
// process is single-threaded: while it is blocked inside submitGuestForm()
// (waiting on the evidence lock) or eraseWaiver() (waiting on a row lock),
// only another process can make the concurrent move. Each mode writes
// <ready-file> once its first move is in place (the test waits for it) and
// <ready-file>.done when it has finished (or <ready-file>.error on failure).
//
// Usage: php concurrent-db-session.php <mode> <instance-id> <hold-ms> <ready-file> [<pdf-path>]
//   hold-lock-then-delete  takes the instance's evidence lock (standing in for
//                          an erase that holds it), holds it <hold-ms>, then
//                          deletes the instance's rows (as that erase would)
//                          and releases the lock.
//   uncommitted-response   opens a transaction and INSERTs a waiver_responses
//                          row for the instance pointing at <pdf-path> (a
//                          writer that does NOT hold the evidence lock, e.g. a
//                          submit on its lock-busy path), holds it uncommitted
//                          <hold-ms>, then commits.
//   insert-response-bypassing-lock  [gate 89-M4 r3 P2-1] ONE autocommit
//                          INSERT of a waiver_responses row (pointing at
//                          <pdf-path>) for an instance eraseLockedInstances
//                          has ALREADY read zero response rows for -- raw
//                          SQL, no evidence lock, no existence check, unlike
//                          any real submit path. Under REPEATABLE READ that
//                          prior SELECT ... FOR UPDATE left a gap lock on the
//                          UNIQUE(waiver_instance_id) index, so this INSERT
//                          BLOCKS until the erasure's transaction ends; under
//                          READ COMMITTED it lands immediately, mid-erasure.
//                          Writes <ready>.connected the instant the DB
//                          connection is up (BEFORE attempting the INSERT --
//                          lets a caller wait out this process's own PHP/
//                          autoload/connect startup cost, which is unrelated
//                          to what is under test, before timing the race with
//                          a short, fixed grace period). <ready> itself is
//                          written the INSTANT the INSERT returns -- not
//                          before -- so the caller can tell whether it landed
//                          while that transaction was still open.
require __DIR__.'/../../vendor/autoload.php';

[, $mode, $id, $holdMs, $ready] = $argv + [null, '', '0', '0', ''];
$id = (int)$id;
$holdMs = (int)$holdMs;
$pdfPath = $argv[5] ?? null;

try {
  $cfg = require __DIR__.'/../../config/config.test.php';
  $pdo = (new App\Database($cfg['db']))->pdo();
  if ($mode === 'insert-response-bypassing-lock') {
    // Signal readiness BEFORE the (possibly blocking) INSERT, so the caller
    // can wait out this process's own startup cost separately from the
    // actual race.
    file_put_contents($ready.'.connected', 'connected');
  }
  $lockName = "CONCAT('wvr_evidence:', LEFT(SHA1(DATABASE()), 16), ':', ?)";
  if ($mode === 'hold-lock-then-delete') {
    $q = $pdo->prepare('SELECT GET_LOCK('.$lockName.', 0)');
    $q->execute([(string)$id]);
    if ((int)$q->fetchColumn() !== 1) throw new RuntimeException('could not take the evidence lock');
    file_put_contents($ready, 'locked');
    usleep($holdMs * 1000);
    $pdo->prepare('DELETE FROM waiver_responses WHERE waiver_instance_id=?')->execute([$id]);
    $pdo->prepare("DELETE FROM audit_events WHERE entity_type IN ('instance','response') AND entity_id=?")->execute([$id]);
    $pdo->prepare('DELETE FROM waiver_instances WHERE id=?')->execute([$id]);
    $pdo->prepare('SELECT RELEASE_LOCK('.$lockName.')')->execute([(string)$id]);
  } elseif ($mode === 'uncommitted-response') {
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO waiver_responses (waiver_instance_id, answers_json, signed_at, hash_sha256, pdf_path, created_at) VALUES (?, ?, UTC_TIMESTAMP(), ?, ?, UTC_TIMESTAMP())')
      ->execute([$id, json_encode(['full_name' => 'Unlocked Writer']), hash('sha256', 'x'), $pdfPath]);
    file_put_contents($ready, 'inserted');
    usleep($holdMs * 1000);
    $pdo->commit();
  } elseif ($mode === 'insert-response-bypassing-lock') {
    $pdo->prepare('INSERT INTO waiver_responses (waiver_instance_id, answers_json, signed_at, hash_sha256, pdf_path, created_at) VALUES (?, ?, UTC_TIMESTAMP(), ?, ?, UTC_TIMESTAMP())')
      ->execute([$id, json_encode(['full_name' => 'Unlocked Race Writer']), hash('sha256', 'race-p2-1'), $pdfPath]);
    file_put_contents($ready, 'landed');
  } else {
    throw new RuntimeException('unknown mode '.$mode);
  }
  file_put_contents($ready.'.done', 'done');
} catch (\Throwable $e) {
  file_put_contents($ready.'.error', get_class($e).': '.$e->getMessage());
  exit(1);
}
