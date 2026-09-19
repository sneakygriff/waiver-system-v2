# phpunit micro-harness

Minimal harness (not a full test suite) pinning down specific correctness
properties:

1. `WaiverController::eraseWaiver()` rolls back ALL of its DELETEs
   (waiver_responses, audit_events, waiver_instances) atomically on a
   mid-transaction failure — see `WaiverControllerEraseTest.php`.
2. `Utils::verifySignedEnvelope()` checks the HMAC signature BEFORE consuming
   the nonce, so a bad-signature replay never burns a legitimate nonce — see
   `UtilsVerifySignedEnvelopeTest.php`.
3. `migrations/run.php` applies migrations in numeric order, records progress
   per statement, resumes a half-applied file at the exact failed statement,
   and treats a pre-existing ledger row as "file fully applied" — see
   `MigrationRunnerTest.php` (CI/CD M4, AC4.2).
4. `App\TemplateSeed` copies ONE operator-authored waiver template between
   environments **preserving its id**, flips the publish gate, and cannot
   overwrite or duplicate an existing template — see `TemplateSeedTest.php`
   (GVS-58 follow-up; runbook in `dev/staging-repair-runbook.md`). The
   id-preservation case is the load-bearing one: `waiver_templates.id` is
   `AUTO_INCREMENT`, so an import into an empty database lands at 1 while
   BookingV2 staging points at "2" — a seed that "worked" but renumbered would
   leave staging just as broken. `testSeedNeverOverwritesAnExistingTemplate`
   is the non-vacuous half of the safety argument: it mutates a seeded row and
   re-seeds, asserting the mutation SURVIVES, which an `UPDATE`-based seed
   fails and every other case in the file passes.
5. GVS-89 reception-QR PUBLIC instances — `create_public_instance` writes an
   `is_public=1` row with every reservation binding NULL and is idempotent per
   `link_token`; neither create action ever reuses the OTHER kind's token;
   `public_status` is `get_status`'s single-token row plus `is_public` /
   `expires_at` and answers a reservation-bound token exactly like an unknown
   one; an expired public instance never renders a form — see
   `WaiverControllerPublicTest.php`. The real `006_public_instances.sql` (and
   its bake into `001_init.sql`) is pinned by `MigrationRunnerTest` tests 21/22.
   The same file also pins 89-M4.3/M4.4 (adults-only launch decision Q12): a
   `submitGuestForm()` signer under 18 on a `is_public=1` instance is ALWAYS
   refused as `minor_requires_staff` (even the age-7-17-with-parental-consent
   case a reservation-bound instance would accept), before any claim/notify;
   `notifyBookingV2Completion()` posts a public completion to
   `POST /api/waiver/public-complete` with `event: waiver.public_completed`
   and `signup_token` added (reservation-bound completions are unchanged:
   `POST /api/waiver/complete`, `event: waiver.completed`, no `signup_token`);
   and the new `resend_evidence {link_token}` action re-pushes ONLY the
   retained local PDF/signature of a `completed` instance whose original
   relay upload never confirmed, no-ops (`pushed:false`, never an error) for
   every other state (pending/void, no response row, already durably stored,
   file missing, relay still down), and 404s `token_unknown` for an unknown
   token.
6. GVS-89 gate 89-M4 fixes. **Adults-only fails CLOSED**: `create_public_instance`
   refuses (`template_missing_dob`) a published version with no `type=date`
   field, and a public submit whose age cannot be computed is refused
   (`age_unverifiable`) before any claim/notify. **`resend_evidence` is
   serialized against `erase_waiver`** by a per-instance MySQL named lock: the
   race is made deterministic by `tests/fixtures/mock-bookingv2-erase-race.php`,
   a relay stand-in that fires a real `eraseWaiver()` from its own DB session
   INSIDE the in-flight upload (it must get `evidence_busy`), or deletes the
   rows bypassing the lock (the resend must then write no audit event and
   drop the orphaned files). **One clock**: create/render/submit all judge
   `expires_at` by `UTC_TIMESTAMP()`, pinned by skewing the DB session clock
   with `SET TIMESTAMP`. `link_waivers` never binds a public instance; a public
   completion never carries a binding. All in `WaiverControllerPublicTest.php`.
   The entrypoint halves are pinned over real HTTP by
   `GuestPageAndApiHttpTest.php` (copies the current `public/w.php` +
   `public/api.php` into a throwaway app root served by `php -S` against
   `waiver_test`): inside the 60-min submit grace a rejected POST re-renders
   the form with its error (not a 410); a POST past the grace gets the
   byte-identical localized 410 page a GET gets; `erase_waiver` answers 503
   `evidence_busy`. `MigrationRunnerTest` tests 23/24 pin the runner's
   session-state replay on resume (a resume at an `EXECUTE` re-runs the
   preceding `SET @` / `PREPARE`) and the real 006's bounded metadata-lock
   wait (`lock_wait_timeout = 10`: a blocked ALTER fails in ~10 s, changes
   nothing, and the next run resumes cleanly). Test 23 takes ~10 s by design.
7. GVS-89 gate 89-M4 round-2 fixes, same files. **The public DOB field is
   resolved strictly**: an explicitly keyed date field (`date_of_birth`, `dob`,
   `birth_date`, `birthdate`, `data_nasterii`; case/`-` insensitive) or the
   template's only date field; several unmarked date fields refuse create
   (`template_ambiguous_dob`) and fail submit closed (`age_unverifiable`). A
   reservation-bound instance keeps the unchanged first-date-field rule (pinned
   on purpose). **`submitGuestForm()`'s first upload + record takes the same
   evidence lock**: an erase fired inside the upload gets `evidence_busy`; a
   deletion that bypasses the lock leaves no orphan response row; with the
   lock held elsewhere the waiver still completes but the evidence is retained
   for `resend_evidence`; a submit that waits out an erase which deletes the
   instance uploads nothing. The waiting/uncommitted second MySQL session is a
   real subprocess, `tests/fixtures/concurrent-db-session.php`, which also pins
   erase's locking read of the evidence paths. `MigrationRunnerTest` test 25:
   a `DEALLOCATE PREPARE` in the replay window is walked over, never re-run.

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

A `waiver_test` built BEFORE a later bake (e.g. before GVS-89 folded
`006_public_instances.sql`'s columns into `001_init.sql`) lacks those columns
and the newer suites will error, not skip. Rebuild it: `DROP DATABASE
waiver_test;`, then repeat the two commands above. (CI builds it fresh every
run.)

Install dev dependencies (phpunit) once:

```bash
docker compose exec php composer install
```

## Running

```bash
docker compose exec php vendor/bin/phpunit
```

Tests that need the DB (`WaiverControllerEraseTest`,
`UtilsVerifySignedEnvelopeTest`, `MigrationRunnerTest`) call
`$this->markTestSkipped(...)` if their schema is unreachable, so the suite still
runs cleanly (skipped, not failed/erroring) in an environment with no DB up.

CI must run it with `--fail-on-skipped`, which turns that convenience back into
a gate: a pipeline whose database never came up then goes red instead of
silently green.

```bash
docker compose exec php vendor/bin/phpunit --fail-on-skipped
```

## Docker-based E2E / image smoke scripts (not part of the phpunit suite)

Two standalone Docker scripts verify things the phpunit micro-harness cannot
(a whole deploy orchestration, and the built image's effective config). Both use
only DISPOSABLE local containers and NEVER touch prod. They need a php+pdo_mysql
image (`waiver-system-v2-php:latest` by default) and the docker daemon.

- **`tests/predeploy_e2e.sh`** — end-to-end verification of `dev/predeploy.php`
  against a throwaway MySQL, across all six deploy scenarios: (a) fresh empty DB,
  (b) prod-like (ledgered through `MAX_BAKED_MIGRATION`), (b2) the one-time
  GVS-89 upgrade (ledgered through 005, pre-006 schema → 006 genuinely
  executes), (c) legacy DB with a partial ledger → apply-mode fails → predeploy
  exits 1 (fail-safe), (d) a new pending probe migration on an existing DB,
  (e) a fresh DB with an un-baked probe → its DDL is EXECUTED (not
  baseline-skipped), (f) a missing required `MYSQL*` var → exit 1, no false
  success. The probe takes the NEXT UNUSED numeric prefix (derived from
  `migrations/`, `007` today) — the runner rejects two files sharing a prefix.
  Runs `dev/predeploy.php` in the image with discrete `MYSQL*` env vars.

  Scenario **(g)** additionally drops each of 005's four evidence columns and
  006's three public-instance columns independently to prove the post-migrate
  schema assertion is non-vacuous, and
  **(h)** covers the GVS-58 waiver-template seed end-to-end through the shipped
  image: a re-provisioned empty DB self-heals to template id 2 with the publish
  gate true, a redeploy is an idempotent no-op, an operator edit SURVIVES a
  redeploy, an unarmed service writes nothing, a missing fixture warns without
  aborting the deploy, and a database holding signed waivers is refused.

  ```bash
  bash tests/predeploy_e2e.sh                 # PHP_IMG / MYSQL_IMG overridable
  ```

- **`tests/image_error_config_smoke.sh`** — builds the image and asserts the
  container's error-visibility contract: effective `display_errors=Off` /
  `display_startup_errors=Off` / `log_errors=On` / `error_log=/dev/stderr`,
  `php-fpm -tt` passes AND pins `display_errors=0` at the `[www]` pool level
  (`php_admin_flag`, not overridable by `ini_set`), and a request that triggers a
  controlled PHP error has the error text ABSENT from the HTTP body but PRESENT
  on the container's stderr (`docker logs`).

  ```bash
  bash tests/image_error_config_smoke.sh                          # builds first
  SKIP_BUILD=1 IMG=waiver-system-v2-php:latest \
    bash tests/image_error_config_smoke.sh                        # reuse an image
  ```

## Notes on `MigrationRunnerTest`

This one needs no `waiver_test` setup, but it does need a MySQL account that can
`CREATE`/`DROP DATABASE` and `CREATE USER`: every test builds its own throwaway
schema (`f2mig_<hash>`) plus a least-privilege `f2_migrunner` account granted
only on that schema, spawns `php migrations/run.php --dir=<staged fixture>` as a
child process with `STAGING_WAIVER_DB_URL` in its environment, and drops both
afterwards. Defaults are compose's `root` / `rootpw`; override with
`WAIVER_TEST_DB_ROOT_USER` / `WAIVER_TEST_DB_ROOT_PASS` (host and port come from
`config/config.test.php`, i.e. `WAIVER_TEST_DB_HOST` / `WAIVER_TEST_DB_PORT`).

It deliberately does not run the migrations as the `app` user, even though
`redact()`'s ≥3-char word-boundary floor (`f2501d1`, regression-pinned by
`MigrationRunnerTest::testShortAppCredentialsDoNotMangleWordsButStayMaskedAsCredentials`)
now keeps a compose-style `app`/`app` credential from mangling the runner's
own vocabulary (`already applied` no longer becomes `already
<redacted:pass>lied`). Using a long, distinctive password for THIS suite's own
dedicated `f2_migrunner` account is still the right default: it keeps every
other test's log assertions unambiguous without depending on that fix, and
matches the account's least-privilege intent (a real credential, not a
throwaway "same as everything else" one).

Fixture migration sets live in `tests/fixtures/migrations-*/` and are COPIED to
a temp dir before each run — the resume and ledger-drift tests repair and
corrupt migrations mid-flight, so the committed fixtures must stay pristine.
Two fixture properties are load-bearing and are asserted by the tests
themselves, because losing them would silently turn a test green forever:
`migrations-numeric-order/` uses UNPADDED numbers (`1`, `2`, `10`) so
lexicographic and numeric order actually differ, and
`migrations-long-version/`'s filename is 33 characters so it overflows the
legacy `VARCHAR(32)` version column.

## Notes on `testEraseWaiverRollsBackOnMidTransactionFailure`

This test forces the transaction's middle DELETE (`audit_events`) to fail by
temporarily narrowing the `app` DB user's privileges on that one table (no
DELETE), while restoring full access in `tearDown()` even if an assertion
fails mid-test. It deliberately opens a **fresh** `Database`/`WaiverController`
connection AFTER the privilege change — an already-open PDO session's
server-side privilege snapshot is not retroactively narrowed by a `REVOKE` +
`FLUSH PRIVILEGES` on another connection, so reusing the harness's long-lived
`setUp()` connection would not reproduce the failure.
