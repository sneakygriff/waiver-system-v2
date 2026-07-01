<?php
namespace Tests;

use App\WaiverController;
use PHPUnit\Framework\TestCase;

/**
 * [F1-fork-erasure] Micro-harness: eraseWaiver() must roll back ALL of its
 * DELETEs (waiver_responses, audit_events, waiver_instances) atomically if
 * any one of them fails mid-transaction -- never a partial erasure.
 *
 * REQUIRES: the `waiver_test` MySQL schema (see tests/README.md) reachable at
 * config/config.test.php's db settings. Skips itself (marked incomplete) if
 * the DB is unreachable, so `vendor/bin/phpunit` still runs cleanly in an
 * environment with no Docker DB up (matches this repo's "DB-gated" test
 * convention on the BookingV2 side).
 */
final class WaiverControllerEraseTest extends TestCase {
  private \PDO $rootPdo; // separate root connection used only to toggle privileges
  private \PDO $pdo;
  private WaiverController $ctl;

  protected function setUp(): void {
    $cfg = TestDatabase::config();
    try {
      $db = TestDatabase::connect();
    } catch (\Throwable $e) {
      $this->markTestSkipped('waiver_test DB unreachable: '.$e->getMessage());
    }
    $this->pdo = $db->pdo();
    TestDatabase::reset($this->pdo);
    $this->ctl = new WaiverController($cfg, $db);

    // Root connection for privilege toggling (see testEraseWaiverRollsBackOnMidTransactionFailure).
    $this->rootPdo = new \PDO(
      sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['db']['host'], $cfg['db']['port']),
      'root', 'rootpw',
      [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
    );
  }

  protected function tearDown(): void {
    // Always restore full privileges, even if a test assertion failed
    // mid-way, so a later test (or a later run) is never left in the revoked
    // state. Also strip the table-level grants the rollback test adds (they
    // are otherwise harmless once the schema-wide wildcard is back -- it
    // supersedes them -- but leaving them around clutters `SHOW GRANTS` for
    // anyone inspecting this shared Docker DB between runs).
    if (isset($this->rootPdo)) {
      foreach (['waiver_instances', 'waiver_responses', 'waiver_template_versions', 'waiver_templates', 'users', 'webhook_nonces', 'audit_events', 'schema_migrations'] as $table) {
        try { $this->rootPdo->exec("REVOKE ALL PRIVILEGES ON waiver_test.`$table` FROM 'app'@'%'"); } catch (\Throwable $e) { /* nothing to revoke -- fine */ }
      }
      $this->rootPdo->exec("GRANT ALL PRIVILEGES ON waiver_test.* TO 'app'@'%'");
      $this->rootPdo->exec('FLUSH PRIVILEGES');
    }
  }

  public function testEraseWaiverDeletesInstancesResponsesAndAuditEvents(): void {
    $versionId = TestDatabase::seedPublishedTemplateVersion($this->pdo);
    $instanceId = TestDatabase::seedInstance($this->pdo, $versionId, ['customer_id'=>'cust-erase-1', 'status'=>'completed']);
    TestDatabase::seedResponse($this->pdo, $instanceId);
    TestDatabase::seedAuditEvent($this->pdo, 'instance', $instanceId, 'created', ['customer_id'=>'cust-erase-1']);
    TestDatabase::seedAuditEvent($this->pdo, 'response', $instanceId, 'submitted', ['answers'=>['full_name'=>'Real Name','medical'=>'PII here']]);

    $result = $this->ctl->eraseWaiver(['customer_id' => 'cust-erase-1']);

    $this->assertArrayNotHasKey('error', $result);
    $this->assertSame(1, $result['instances_deleted']);
    $this->assertSame(1, $result['responses_deleted']);
    $this->assertSame(2, $result['audit_events_deleted']);

    $this->assertSame(0, $this->countRows('waiver_instances', 'id=?', [$instanceId]));
    $this->assertSame(0, $this->countRows('waiver_responses', 'waiver_instance_id=?', [$instanceId]));
    $this->assertSame(0, $this->countRows('audit_events', "entity_type IN ('instance','response') AND entity_id=?", [$instanceId]));

    // The erasure's own audit row (entity_type='erasure') must survive --
    // it's the durable "this subject was erased" record, written after the
    // cleanup DELETE above executes.
    $this->assertGreaterThanOrEqual(1, $this->countRows('audit_events', "entity_type='erasure'", []));
  }

  public function testEraseWaiverIsIdempotentOnSecondCall(): void {
    $versionId = TestDatabase::seedPublishedTemplateVersion($this->pdo);
    $instanceId = TestDatabase::seedInstance($this->pdo, $versionId, ['customer_id'=>'cust-erase-2']);

    $first = $this->ctl->eraseWaiver(['customer_id' => 'cust-erase-2']);
    $this->assertSame(1, $first['instances_deleted']);

    $second = $this->ctl->eraseWaiver(['customer_id' => 'cust-erase-2']);
    $this->assertArrayNotHasKey('error', $second);
    $this->assertSame(0, $second['instances_deleted']);
    $this->assertSame(0, $second['responses_deleted']);
    $this->assertSame(0, $second['audit_events_deleted']);
  }

  /**
   * The core correctness property this task asks for: force the
   * audit_events DELETE step (the middle of the three DELETEs inside the
   * transaction: responses -> audit_events -> instances) to fail by
   * temporarily revoking the `app` DB user's DELETE privilege on
   * audit_events specifically. waiver_responses' DELETE (issued first) will
   * have already run before the failure -- if eraseWaiver did NOT wrap this
   * in a transaction, that responses row would stay deleted even though the
   * call as a whole failed. Assert the response row (and the instance row)
   * are BOTH still present after the thrown exception, proving the whole
   * attempt rolled back atomically.
   */
  public function testEraseWaiverRollsBackOnMidTransactionFailure(): void {
    $versionId = TestDatabase::seedPublishedTemplateVersion($this->pdo);
    $instanceId = TestDatabase::seedInstance($this->pdo, $versionId, ['customer_id'=>'cust-erase-rollback', 'status'=>'completed']);
    TestDatabase::seedResponse($this->pdo, $instanceId);
    TestDatabase::seedAuditEvent($this->pdo, 'instance', $instanceId, 'created', ['customer_id'=>'cust-erase-rollback']);

    // Force the audit_events DELETE to fail: the 'app' user's baseline grant
    // is schema-wide (GRANT ALL PRIVILEGES ON waiver_test.*), and MySQL will
    // not let a table-level REVOKE subtract from a wildcard grant directly.
    // So: first re-grant explicit table-level privileges on every OTHER app
    // table (still leaving 'app' fully able to do everything it needs
    // EXCEPT DELETE on audit_events specifically), which implicitly narrows
    // it off the wildcard.
    $this->rootPdo->exec("REVOKE ALL PRIVILEGES ON waiver_test.* FROM 'app'@'%'");
    foreach (['waiver_instances', 'waiver_responses', 'waiver_template_versions', 'waiver_templates', 'users', 'webhook_nonces', 'schema_migrations'] as $table) {
      $this->rootPdo->exec("GRANT ALL PRIVILEGES ON waiver_test.`$table` TO 'app'@'%'");
    }
    // audit_events: everything except DELETE.
    $this->rootPdo->exec("GRANT SELECT, INSERT, UPDATE ON waiver_test.audit_events TO 'app'@'%'");
    $this->rootPdo->exec('FLUSH PRIVILEGES');

    // A pre-existing PDO connection's server-side privilege cache is snapshot
    // at authentication time and is NOT invalidated by a subsequent REVOKE +
    // FLUSH PRIVILEGES for that already-open session (this is normal MySQL
    // behavior -- FLUSH PRIVILEGES reloads the grant tables for NEW
    // connections/re-checks, but does not retroactively downgrade sessions
    // already authenticated with the wider privilege). So exercise the call
    // through a genuinely FRESH connection/controller instance opened AFTER
    // the revoke above, matching how a real mid-flight privilege change would
    // actually manifest (a newly-opened request connection, not a
    // long-lived one this test happens to hold open from setUp()).
    $freshDb = TestDatabase::connect();
    $freshCtl = new WaiverController(TestDatabase::config(), $freshDb);

    $threw = false;
    try {
      $freshCtl->eraseWaiver(['customer_id' => 'cust-erase-rollback']);
    } catch (\Throwable $e) {
      $threw = true;
    }
    $this->assertTrue($threw, 'eraseWaiver() must let the mid-transaction failure propagate (never swallow it)');
    $this->assertFalse($freshDb->pdo()->inTransaction(), 'the failing connection must not be left inside a dangling transaction either');

    // Restore privileges immediately so the verification queries below (and
    // any later test) are not themselves blocked.
    $this->rootPdo->exec("GRANT ALL PRIVILEGES ON waiver_test.* TO 'app'@'%'");
    $this->rootPdo->exec('FLUSH PRIVILEGES');

    // The whole attempt must have rolled back: waiver_responses row (deleted
    // BEFORE the failing audit_events DELETE in program order) must be back,
    // and waiver_instances (deleted AFTER, never reached) was never removed
    // either. This is the crux: without beginTransaction/rollBack, the
    // responses row would be gone here even though the call overall failed.
    $this->assertSame(1, $this->countRows('waiver_instances', 'id=?', [$instanceId]), 'waiver_instances row must survive a rolled-back erase');
    $this->assertSame(1, $this->countRows('waiver_responses', 'waiver_instance_id=?', [$instanceId]), 'waiver_responses row must be restored by rollback');
    $this->assertSame(1, $this->countRows('audit_events', "entity_type='instance' AND entity_id=?", [$instanceId]), 'audit_events row must be restored by rollback');

    // And the PDO connection itself must not be left inside a dangling
    // transaction after the rollback (would break every subsequent call on
    // this connection/request).
    $this->assertFalse($this->pdo->inTransaction());
  }

  private function countRows(string $table, string $where, array $params): int {
    $stmt = $this->pdo->prepare("SELECT COUNT(*) AS c FROM `$table` WHERE $where");
    $stmt->execute($params);
    return (int)$stmt->fetch()['c'];
  }
}
