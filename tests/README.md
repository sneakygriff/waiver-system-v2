# phpunit micro-harness

Minimal harness (not a full test suite) pinning down two specific correctness
properties from the F1-fork-erasure hardening pass:

1. `WaiverController::eraseWaiver()` rolls back ALL of its DELETEs
   (waiver_responses, audit_events, waiver_instances) atomically on a
   mid-transaction failure — see `WaiverControllerEraseTest.php`.
2. `Utils::verifySignedEnvelope()` checks the HMAC signature BEFORE consuming
   the nonce, so a bad-signature replay never burns a legitimate nonce — see
   `UtilsVerifySignedEnvelopeTest.php`.

## One-time setup

Requires the Docker stack already running (`docker compose up -d`) and a
dedicated `waiver_test` MySQL schema — separate from `waiver_db` so tests can
freely `TRUNCATE`/seed fixtures without touching real data:

```bash
docker compose exec db mysql -u root -prootpw -e "
  CREATE DATABASE IF NOT EXISTS waiver_test;
  GRANT ALL PRIVILEGES ON waiver_test.* TO 'app'@'%';
  FLUSH PRIVILEGES;"

docker compose exec -T db mysql -u root -prootpw waiver_test < migrations/001_init.sql
```

(`002_waiver_integration.sql` / `003_erase_waiver.sql` are already baked into
`001_init.sql` per their own headers — applying them after 001 on a fresh
schema will emit harmless "Duplicate column" errors and can be skipped.)

Install dev dependencies (phpunit) once:

```bash
docker compose exec php composer install
```

## Running

```bash
docker compose exec php vendor/bin/phpunit
```

Tests that need the DB (`WaiverControllerEraseTest`,
`UtilsVerifySignedEnvelopeTest`) call `$this->markTestSkipped(...)` if
`waiver_test` is unreachable, so the suite still runs cleanly (skipped, not
failed/erroring) in an environment with no DB up.

## Notes on `testEraseWaiverRollsBackOnMidTransactionFailure`

This test forces the transaction's middle DELETE (`audit_events`) to fail by
temporarily narrowing the `app` DB user's privileges on that one table (no
DELETE), while restoring full access in `tearDown()` even if an assertion
fails mid-test. It deliberately opens a **fresh** `Database`/`WaiverController`
connection AFTER the privilege change — an already-open PDO session's
server-side privilege snapshot is not retroactively narrowed by a `REVOKE` +
`FLUSH PRIVILEGES` on another connection, so reusing the harness's long-lived
`setUp()` connection would not reproduce the failure.
