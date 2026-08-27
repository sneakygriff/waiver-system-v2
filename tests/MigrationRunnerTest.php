<?php
namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * [CI/CD M4 · AC4.2] Behavioural suite for `migrations/run.php` — the
 * per-statement migration ledger runner.
 *
 * HOW THIS TESTS THE RUNNER
 * -------------------------
 * The runner is a standalone CLI script with no autoloader and no injectable
 * seams, and its whole contract is *observable from the outside*: exit code,
 * stdout/stderr lines, ledger rows, and the schema it leaves behind. So every
 * test here SPAWNS IT AS A CHILD PROCESS (`php migrations/run.php --dir=...`)
 * with `STAGING_WAIVER_DB_URL` in the child environment, exactly as CI will,
 * and then asserts against the database it actually touched. Nothing is
 * stubbed; a test can only pass if the real script really ran.
 *
 * ISOLATION
 * ---------
 * Each test gets its own throwaway MySQL schema (`f2mig_<hash-of-test-name>`),
 * created and dropped around the test by a root connection to the same compose
 * `db` service the rest of the suite uses. It deliberately does NOT reuse
 * `waiver_test`: that schema already carries a `schema_migrations` table with
 * real rows, and `TestDatabase::reset()`'s fixed TRUNCATE list knows nothing
 * about the ledger tables.
 *
 * The runner connects as a dedicated, least-privilege account
 * (`f2_migrunner`, granted only on that one scratch schema) rather than as
 * root or as the app's own `app` user. Two reasons, one of them a live trap:
 *   - fidelity: staging runs migrations as a scoped user, and the runner reads
 *     `information_schema`, whose visibility is privilege-filtered;
 *   - historically, the runner's redactor stripped its DB password from every
 *     log line with NO minimum length, and the compose credentials are
 *     literally `app`/`app`, so running as `app` rewrote the runner's own
 *     words ("already applied" became "already <redacted:pass>lied"). `f2501d1`
 *     fixed that with a ≥3-char word-boundary floor (test 15,
 *     `testShortAppCredentialsDoNotMangleWordsButStayMaskedAsCredentials`,
 *     proves an `app`/`app` run is clean today). This suite still uses a long,
 *     distinctive password for its OWN dedicated account regardless: it keeps
 *     every other test's log assertions unambiguous without depending on that
 *     fix holding, and matches the account's least-privilege intent.
 *
 * Fixture migration sets under `tests/fixtures/migrations-*` are COPIED into a
 * temp directory before each run: some tests repair or corrupt a migration
 * mid-flight (that is the whole point of the resume and ledger-drift cases),
 * and the committed fixtures must stay pristine.
 *
 * SKIPPING
 * --------
 * If the compose MySQL is unreachable the tests skip, matching this harness's
 * existing convention. CI runs phpunit with `--fail-on-skipped`, so a skip is
 * a red gate there rather than a silent green.
 */
final class MigrationRunnerTest extends TestCase
{
    /** Normalized statement text of every clean-chain statement, in apply order.
     *  These are hand-written, NOT computed with the runner's own splitter: the
     *  checksum assertions below would be circular if the expectation came from
     *  the code under test. Normalization = comments stripped, whitespace
     *  outside quotes collapsed to single spaces, trailing `;` dropped. */
    private const CLEAN_CHAIN_STATEMENTS = [
        ['001_init',  0, 'CREATE TABLE f2_clean_a (id INT NOT NULL PRIMARY KEY, note VARCHAR(64) NOT NULL) ENGINE=InnoDB'],
        ['001_init',  1, "INSERT INTO f2_clean_a (id, note) VALUES (1, 'first')"],
        ['002_alter', 0, 'ALTER TABLE f2_clean_a ADD COLUMN extra VARCHAR(32) NULL'],
        ['003_index', 0, 'CREATE INDEX f2_clean_a_note_idx ON f2_clean_a (note)'],
    ];

    /** Dedicated migration account: never root, and deliberately not a word the
     *  runner itself ever prints (see the redactor note in the class docblock). */
    private const RUNNER_USER = 'f2_migrunner';
    private const RUNNER_PASS = 'f2-mig-runner-pw-0001';

    private \PDO $root;      // root: creates/drops the scratch schema and account
    private \PDO $db;        // migration account: inspects the scratch schema
    private string $schema = '';
    private string $host;
    private int $port;

    /** @var list<string> temp fixture dirs to remove in tearDown */
    private array $tempDirs = [];

    // -----------------------------------------------------------------------
    // Fixture lifecycle
    // -----------------------------------------------------------------------

    protected function setUp(): void
    {
        $cfg = TestDatabase::config()['db'];
        $this->host = (string)$cfg['host'];
        $this->port = (int)$cfg['port'];

        $rootUser = getenv('WAIVER_TEST_DB_ROOT_USER') ?: 'root';
        $rootPass = getenv('WAIVER_TEST_DB_ROOT_PASS') ?: 'rootpw';

        try {
            $this->root = new \PDO(
                sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $this->host, $this->port),
                $rootUser,
                $rootPass,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]
            );
        } catch (\Throwable $e) {
            // Same convention as the rest of this harness: no DB, no run. CI
            // uses --fail-on-skipped so this cannot pass unnoticed there.
            $this->markTestSkipped('compose MySQL unreachable as ' . $rootUser . ': ' . $e->getMessage());
        }

        // One scratch schema per test method — deterministic, so a crashed run
        // leaves a recognizable corpse, and short enough for MySQL's 64-char
        // identifier limit.
        $this->schema = 'f2mig_' . substr(hash('sha256', $this->name()), 0, 16);
        $this->dropSchema();
        $this->root->exec('CREATE DATABASE `' . $this->schema . '` CHARACTER SET utf8mb4');

        // Least-privilege migration account, recreated per test so a crashed run
        // cannot leave a usable credential behind.
        $this->root->exec("DROP USER IF EXISTS '" . self::RUNNER_USER . "'@'%'");
        $this->root->exec("CREATE USER '" . self::RUNNER_USER . "'@'%' IDENTIFIED BY '" . self::RUNNER_PASS . "'");
        $this->root->exec('GRANT ALL PRIVILEGES ON `' . $this->schema . "`.* TO '" . self::RUNNER_USER . "'@'%'");
        $this->root->exec('FLUSH PRIVILEGES');

        $this->db = new \PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->host, $this->port, $this->schema),
            self::RUNNER_USER,
            self::RUNNER_PASS,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]
        );
    }

    protected function tearDown(): void
    {
        unset($this->db);
        if (isset($this->root)) {
            $this->dropSchema();
            try {
                // Dropping the account drops its grants with it.
                $this->root->exec("DROP USER IF EXISTS '" . self::RUNNER_USER . "'@'%'");
                $this->root->exec('FLUSH PRIVILEGES');
            } catch (\Throwable $e) {
                // Best effort: setUp may have skipped before creating anything.
            }
        }
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tempDirs = [];
    }

    // -----------------------------------------------------------------------
    // 1. Statement ledger accuracy
    // -----------------------------------------------------------------------

    public function testLedgerRecordsExactlyTheStatementsExecutedWithIndexAndChecksum(): void
    {
        $dir = $this->stageFixture('clean-chain');
        $r   = $this->migrate(['--dir=' . $dir]);

        $this->assertExit(0, $r, 'a clean 3-file chain must apply end to end');

        // The runner's own narration of what it did, per file and per statement.
        $this->assertStringContainsString('001_init: 2 statement(s)', $r['out']);
        $this->assertStringContainsString('  001_init#0 ok', $r['out']);
        $this->assertStringContainsString('  001_init#1 ok', $r['out']);
        $this->assertStringContainsString('001_init: APPLIED (2 statement(s))', $r['out']);
        $this->assertStringContainsString('002_alter: APPLIED (1 statement(s))', $r['out']);
        $this->assertStringContainsString('003_index: APPLIED (1 statement(s))', $r['out']);
        $this->assertStringContainsString('summary: files_applied=3 statements_executed=4 already_applied=0', $r['out']);

        // Every file recorded once, in the ledger's file table.
        $this->assertSame(['001_init', '002_alter', '003_index'], $this->appliedVersions());

        // And EXACTLY one statement row per executed statement, indexed from 0
        // and carrying the SHA-256 of the normalized statement text. The
        // expected texts are the hand-written constants above, so this pins the
        // ledger's central claim ("row k means statement k, this exact SQL,
        // ran") without re-deriving it from the runner.
        $expected = [];
        foreach (self::CLEAN_CHAIN_STATEMENTS as [$version, $index, $sql]) {
            $expected[] = ['version' => $version, 'statement_index' => $index, 'checksum' => hash('sha256', $sql)];
        }
        $this->assertSame($expected, $this->statementLedger());

        // The statements really ran, in full: table + row from 001, column from
        // 002, index from 003.
        $this->assertSame([['id' => 1, 'note' => 'first']], $this->rows('SELECT id, note FROM f2_clean_a ORDER BY id'));
        $this->assertTrue($this->columnExists('f2_clean_a', 'extra'), '002_alter must have added the `extra` column');
        $this->assertTrue($this->indexExists('f2_clean_a', 'f2_clean_a_note_idx'), '003_index must have created the index');
    }

    // -----------------------------------------------------------------------
    // 2. Numeric ordering (UNPADDED — padded fixtures cannot detect the bug)
    // -----------------------------------------------------------------------

    public function testMigrationsApplyInNumericOrderNotLexicographicOrder(): void
    {
        $dir = $this->stageFixture('numeric-order');

        // Guard the fixture's own discriminating property: if someone ever
        // zero-pads these filenames, lexicographic and numeric order become
        // identical and this test silently stops testing anything.
        $names = array_values(array_diff(scandir($dir), ['.', '..']));
        sort($names);
        $this->assertSame(['10_c.sql', '1_a.sql', '2_b.sql'], $names,
            'the ordering fixture must be UNPADDED so lexicographic != numeric order');

        $r = $this->migrate(['--dir=' . $dir]);
        $this->assertExit(0, $r, 'lexicographic ordering would run 10_c first, against a table 1_a has not created yet');

        // The rows carry an AUTO_INCREMENT id, so their order IS the execution
        // order of the three files.
        $this->assertSame(
            [['tag' => 'a'], ['tag' => 'b'], ['tag' => 'c']],
            $this->rows('SELECT tag FROM f2_order_log ORDER BY id')
        );

        // Same claim, read off the runner's own output ordering.
        $this->assertMatchesRegularExpression(
            '/1_a: APPLIED.*2_b: APPLIED.*10_c: APPLIED/s',
            $r['out'],
            'files must be narrated (and applied) 1, 2, 10 — not 10, 1, 2'
        );
        $this->assertSame(['10_c', '1_a', '2_b'], $this->appliedVersions()); // ledger read back sorted by name
        $this->assertStringContainsString('summary: files_applied=3 statements_executed=4 already_applied=0', $r['out']);
    }

    // -----------------------------------------------------------------------
    // 3. Idempotency
    // -----------------------------------------------------------------------

    public function testSecondRunAppliesNothingAndLeavesTheLedgerUntouched(): void
    {
        $dir = $this->stageFixture('clean-chain');

        $first = $this->migrate(['--dir=' . $dir]);
        $this->assertExit(0, $first, 'first run');
        $ledgerAfterFirst    = $this->statementLedgerWithTimestamps();
        $filesAfterFirst     = $this->appliedVersionsWithTimestamps();

        $second = $this->migrate(['--dir=' . $dir]);
        $this->assertExit(0, $second, 'a re-run over a fully applied chain must be a no-op, not an error');

        $this->assertStringContainsString('001_init: already applied', $second['out']);
        $this->assertStringContainsString('002_alter: already applied', $second['out']);
        $this->assertStringContainsString('003_index: already applied', $second['out']);
        $this->assertStringContainsString('summary: files_applied=0 statements_executed=0 already_applied=3', $second['out']);
        $this->assertStringNotContainsString('#0 ok', $second['out'], 'no statement may be executed on the second run');

        // Byte-identical ledger, applied_at timestamps included: not merely "no
        // new rows" but "no rows rewritten".
        $this->assertSame($ledgerAfterFirst, $this->statementLedgerWithTimestamps());
        $this->assertSame($filesAfterFirst, $this->appliedVersionsWithTimestamps());

        // Data untouched — one row, not two.
        $this->assertSame([['id' => 1, 'note' => 'first']], $this->rows('SELECT id, note FROM f2_clean_a ORDER BY id'));

        // Anti-vacuity: prove the fixture's statements are genuinely
        // non-idempotent, i.e. that a re-execution WOULD have been observable.
        // Without this, "the second run changed nothing" could be true simply
        // because re-running the statements is harmless.
        $threw = false;
        try {
            $this->db->exec(self::CLEAN_CHAIN_STATEMENTS[0][2]);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'clean-chain statement #0 must fail if re-executed, or this test proves nothing');
    }

    // -----------------------------------------------------------------------
    // 4. Resume from a mid-file failure
    // -----------------------------------------------------------------------

    public function testMidFileFailureIsRecordedAndResumedAtTheFailedStatement(): void
    {
        $dir = $this->stageFixture('mid-file-failing');

        // --- run 1: 001 applies, 002 dies at statement #1 -------------------
        $first = $this->migrate(['--dir=' . $dir]);
        $this->assertExit(1, $first, 'a failing statement must exit 1 (resumable), not 0 and not a crash code');

        $this->assertStringContainsString('001: APPLIED (2 statement(s))', $first['out']);
        $this->assertStringContainsString('002: 3 statement(s)', $first['out']);
        $this->assertStringContainsString('  002#0 ok', $first['out']);
        $this->assertStringContainsString('FAILED 002#1', $first['err']);
        $this->assertStringContainsString('NOT ROLLED BACK', $first['err']);
        $this->assertStringContainsString('resumes at index 1', $first['err']);

        // 003 must never be reached: a runner that soldiers on past a failed
        // file applies migrations out of order.
        $this->assertStringNotContainsString('003:', $first['out']);
        $this->assertSame([['id' => 1, 'tag' => 'one']], $this->rows('SELECT id, tag FROM f2_mid_a ORDER BY id'));

        // Ledger: 001 fully applied; 002 has statement 0 ONLY and is not marked
        // applied; the failed statement 1 is not recorded (it never ran).
        $this->assertSame(['001'], $this->appliedVersions());
        $this->assertSame(
            [['version' => '001', 'statement_index' => 0], ['version' => '001', 'statement_index' => 1],
             ['version' => '002', 'statement_index' => 0]],
            $this->statementIndexes()
        );
        // Statement #0 of 002 really executed and is permanent (no rollback).
        $this->assertTrue($this->tableExists('f2_mid_b'), '002#0 executed, so its table must exist and stay');
        $this->assertSame([], $this->rows('SELECT id, tag FROM f2_mid_b ORDER BY id'));

        $ledgerAfterFirst = $this->statementLedgerWithTimestamps();

        // --- run 2: unrepaired — must resume at 1, never restart at 0 -------
        $second = $this->migrate(['--dir=' . $dir]);
        $this->assertExit(1, $second, 'still broken, so still exit 1');
        $this->assertStringContainsString('002: 3 statement(s), resuming at index 1 (1 already applied)', $second['out']);
        $this->assertStringContainsString('FAILED 002#1', $second['err']);
        // The kill shot for "resume" vs "restart": statement #0 is a bare
        // CREATE TABLE, so a restart would have failed at #0 instead of #1.
        $this->assertStringNotContainsString('FAILED 002#0', $second['err']);
        $this->assertStringNotContainsString('  002#0 ok', $second['out']);
        $this->assertSame($ledgerAfterFirst, $this->statementLedgerWithTimestamps(), 'a failing re-run must not rewrite the ledger');

        // --- run 3: repair statement #1, re-run, resume ---------------------
        $path   = $dir . '/002.sql';
        $broken = file_get_contents($path);
        $fixed  = str_replace('(id, nope)', '(id, tag)', $broken);
        $this->assertNotSame($broken, $fixed, 'fixture 002.sql must still contain the deliberately broken column reference');
        file_put_contents($path, $fixed);

        $third = $this->migrate(['--dir=' . $dir]);
        $this->assertExit(0, $third, 'with the unrecorded statement repaired the file must resume and complete');
        $this->assertStringContainsString('002: 3 statement(s), resuming at index 1 (1 already applied)', $third['out']);
        $this->assertStringContainsString('  002#1 ok', $third['out']);
        $this->assertStringContainsString('  002#2 ok', $third['out']);
        $this->assertStringNotContainsString('  002#0 ok', $third['out'], 'statement 0 must never be executed twice');
        $this->assertStringContainsString('002: APPLIED (3 statement(s))', $third['out']);
        $this->assertStringContainsString('003: APPLIED (1 statement(s))', $third['out']);
        $this->assertStringContainsString('summary: files_applied=2 statements_executed=3 already_applied=1', $third['out']);

        $this->assertSame(['001', '002', '003'], $this->appliedVersions());
        $this->assertSame(
            [['version' => '001', 'statement_index' => 0], ['version' => '001', 'statement_index' => 1],
             ['version' => '002', 'statement_index' => 0], ['version' => '002', 'statement_index' => 1],
             ['version' => '002', 'statement_index' => 2],
             ['version' => '003', 'statement_index' => 0]],
            $this->statementIndexes()
        );
        // 002#0's ledger row must be the ORIGINAL one (same applied_at), not a
        // rewrite — the strongest available "it was not re-executed" evidence.
        $this->assertSame(
            $ledgerAfterFirst[2],
            $this->statementLedgerWithTimestamps()[2],
            "002's statement 0 ledger row must be untouched by the resume"
        );

        // Data: #1 and #2 each ran exactly once; #0's table was never recreated.
        $this->assertSame(
            [['id' => 2, 'tag' => 'two'], ['id' => 3, 'tag' => 'three']],
            $this->rows('SELECT id, tag FROM f2_mid_b ORDER BY id')
        );
        $this->assertSame(
            [['id' => 1, 'tag' => 'one'], ['id' => 9, 'tag' => 'after']],
            $this->rows('SELECT id, tag FROM f2_mid_a ORDER BY id'),
            '003 must only run once 002 is complete'
        );
    }

    // -----------------------------------------------------------------------
    // 5. Baselining marks without executing
    // -----------------------------------------------------------------------

    public function testBaselineMarksFilesAppliedWithoutExecutingThem(): void
    {
        $dir = $this->stageFixture('future-005-after-baseline');

        $r = $this->migrate(['--dir=' . $dir, '--baseline', '--through=004']);
        $this->assertExit(0, $r, 'baselining an untouched schema must succeed');

        $this->assertStringContainsString('001: BASELINED (marked applied WITHOUT executing)', $r['out']);
        $this->assertStringContainsString('004_baseline_boundary: BASELINED (marked applied WITHOUT executing)', $r['out']);
        $this->assertStringContainsString('005_future: left PENDING (beyond --through=004)', $r['out']);
        $this->assertStringContainsString('summary: baselined=4 already_applied=0', $r['out']);

        $this->assertSame(['001', '002', '003', '004_baseline_boundary'], $this->appliedVersions());

        // Baselining must NEVER write statement rows: a statement row is a
        // claim that the statement executed, and none did.
        $this->assertSame([], $this->statementIndexes());

        // Nothing but the two ledger tables exists — none of 001..004's DDL ran.
        $this->assertSame(['schema_migration_statements', 'schema_migrations'], $this->tables());
    }

    // -----------------------------------------------------------------------
    // 6. Only the future migration runs after a baseline
    // -----------------------------------------------------------------------

    public function testOnlyTheFutureMigrationRunsAfterBaselining(): void
    {
        $dir = $this->stageFixture('future-005-after-baseline');

        $this->assertExit(0, $this->migrate(['--dir=' . $dir, '--baseline', '--through=004']), 'baseline');

        $r = $this->migrate(['--dir=' . $dir]);
        $this->assertExit(0, $r, 'the pending 005 must apply on top of a baselined 001..004');

        foreach (['001', '002', '003', '004_baseline_boundary'] as $version) {
            $this->assertStringContainsString($version . ': already applied', $r['out']);
        }
        $this->assertStringContainsString('005_future: APPLIED (2 statement(s))', $r['out']);
        $this->assertStringContainsString('summary: files_applied=1 statements_executed=2 already_applied=4', $r['out']);

        // Only 005's statements are recorded...
        $this->assertSame(
            [['version' => '005_future', 'statement_index' => 0], ['version' => '005_future', 'statement_index' => 1]],
            $this->statementIndexes()
        );
        // ...and only 005's schema objects exist. The baselined files stay
        // unexecuted forever, which is the entire point of baselining.
        $this->assertSame(['f2_future_005', 'schema_migration_statements', 'schema_migrations'], $this->tables());
        $this->assertSame([['id' => 1, 'tag' => 'ran']], $this->rows('SELECT id, tag FROM f2_future_005'));
    }

    // -----------------------------------------------------------------------
    // 7. Comment-only file
    // -----------------------------------------------------------------------

    public function testCommentOnlyFileIsRecordedAppliedWithZeroStatements(): void
    {
        $dir = $this->stageFixture('comment-only');

        // Anti-vacuity: the fixture must actually contain semicolons (inside
        // comments), so a comment-blind splitter would emit junk fragments here
        // rather than an empty statement list.
        $this->assertStringContainsString(';', (string)file_get_contents($dir . '/001.sql'));

        $r = $this->migrate(['--dir=' . $dir]);
        $this->assertExit(0, $r, 'a documentation-only migration is valid, not an error');

        $this->assertStringContainsString('001: APPLIED (0 statement(s)', $r['out']);
        $this->assertStringContainsString('comment-only file', $r['out']);
        $this->assertStringContainsString('summary: files_applied=1 statements_executed=0 already_applied=0', $r['out']);

        $this->assertSame(['001'], $this->appliedVersions());
        $this->assertSame([], $this->statementIndexes(), 'zero statements executed means zero statement rows');
        $this->assertSame(['schema_migration_statements', 'schema_migrations'], $this->tables());
    }

    // -----------------------------------------------------------------------
    // 8. A pre-existing per-file ledger row means "fully applied"
    // -----------------------------------------------------------------------

    public function testPreExistingLedgerRowIsTreatedAsFullyAppliedAndNeverReExecuted(): void
    {
        $dir = $this->stageFixture('clean-chain');

        // Reproduce a real pre-runner database: the legacy ledger shape from
        // 001_init.sql, with one migration already recorded by hand.
        $this->createLegacyLedger();
        $this->db->exec("INSERT INTO schema_migrations (version, applied_at) VALUES ('002_alter', NOW())");

        $r = $this->migrate(['--dir=' . $dir]);
        $this->assertExit(0, $r, 'a pre-recorded file must be skipped, not re-run');

        $this->assertStringContainsString('002_alter: already applied', $r['out']);
        $this->assertStringContainsString('001_init: APPLIED (2 statement(s))', $r['out']);
        $this->assertStringContainsString('003_index: APPLIED (1 statement(s))', $r['out']);
        $this->assertStringContainsString('summary: files_applied=2 statements_executed=3 already_applied=1', $r['out']);

        // The decisive assertion: 002's ALTER never executed.
        $this->assertFalse(
            $this->columnExists('f2_clean_a', 'extra'),
            '002_alter was already recorded applied; its ALTER must NOT have run'
        );
        // ...and it never gained statement rows either (nothing executed for it).
        $this->assertSame(
            [['version' => '001_init', 'statement_index' => 0], ['version' => '001_init', 'statement_index' => 1],
             ['version' => '003_index', 'statement_index' => 0]],
            $this->statementIndexes()
        );
        $this->assertSame(['001_init', '002_alter', '003_index'], $this->appliedVersions());
    }

    // -----------------------------------------------------------------------
    // 9. Quote-aware splitting
    // -----------------------------------------------------------------------

    public function testSemicolonsInsideStringLiteralsDoNotSplitStatements(): void
    {
        $dir = $this->stageFixture('string-containing-semicolon');

        $r = $this->migrate(['--dir=' . $dir]);
        $this->assertExit(0, $r, 'a naive explode(";") splitter cuts these literals in half and the run dies');

        $this->assertStringContainsString('001: 3 statement(s)', $r['out']);
        $this->assertStringContainsString('summary: files_applied=1 statements_executed=3 already_applied=0', $r['out']);

        // Payloads survive byte-exact, semicolons/comment markers/quotes and all.
        $this->assertSame(
            [
                ['id' => 1, 'payload' => "semi; colon -- not a comment /* nor block */ and a ' quote"],
                ['id' => 2, 'payload' => 'double " quoted; one statement'],
            ],
            $this->rows('SELECT id, payload FROM f2_semi ORDER BY id')
        );

        // And the ledger's checksums are over the WHOLE statement, not a
        // fragment of it (expected texts hand-written, see the class docblock).
        $stmt1 = <<<'SQL'
        INSERT INTO f2_semi (id, payload) VALUES (1, 'semi; colon -- not a comment /* nor block */ and a '' quote')
        SQL;
        $stmt2 = <<<'SQL'
        INSERT INTO f2_semi (id, payload) VALUES (2, "double \" quoted; one statement")
        SQL;
        $this->assertSame(
            [
                ['version' => '001', 'statement_index' => 0,
                 'checksum' => hash('sha256', 'CREATE TABLE f2_semi (id INT NOT NULL PRIMARY KEY, payload VARCHAR(255) NOT NULL) ENGINE=InnoDB')],
                ['version' => '001', 'statement_index' => 1, 'checksum' => hash('sha256', $stmt1)],
                ['version' => '001', 'statement_index' => 2, 'checksum' => hash('sha256', $stmt2)],
            ],
            $this->statementLedger()
        );
    }

    // -----------------------------------------------------------------------
    // 10. Legacy VARCHAR(32) ledger must be widened for the 33-char version
    // -----------------------------------------------------------------------

    public function testLegacyNarrowVersionColumnIsWidenedSoALongVersionFits(): void
    {
        $dir = $this->stageFixture('long-version');

        // Anti-vacuity: this test only has teeth while the version really does
        // overflow the legacy column.
        $this->assertSame(33, strlen('004_erasure_audit_events_backfill'));
        $this->createLegacyLedger();
        $this->assertSame(32, $this->versionColumnLength());

        $r = $this->migrate(['--dir=' . $dir]);
        $this->assertExit(0, $r, 'a 33-char version into a VARCHAR(32) PK is MySQL error 1406 unless the column is widened first');

        $this->assertStringContainsString('widened schema_migrations.version VARCHAR(32) -> VARCHAR(191)', $r['out']);
        $this->assertSame(191, $this->versionColumnLength());
        $this->assertSame(['004_erasure_audit_events_backfill'], $this->appliedVersions());
        $this->assertSame([['version' => '004_erasure_audit_events_backfill', 'statement_index' => 0]], $this->statementIndexes());
        $this->assertTrue($this->tableExists('f2_long_version'));
        $this->assertStringContainsString('summary: files_applied=1 statements_executed=1 already_applied=0', $r['out']);
    }

    // -----------------------------------------------------------------------
    // 11. Ledger drift: an already-recorded statement may not change
    // -----------------------------------------------------------------------

    public function testEditingAnAlreadyRecordedStatementIsRefusedAsLedgerDrift(): void
    {
        $dir  = $this->stageFixture('mid-file-failing');
        $path = $dir . '/002.sql';

        $this->assertExit(1, $this->migrate(['--dir=' . $dir]), 'set up a half-applied 002');
        $ledger = $this->statementLedgerWithTimestamps();

        // (a) Edit statement #0 — the one the ledger has already recorded.
        $original = (string)file_get_contents($path);
        $edited   = str_replace('tag VARCHAR(16)', 'tag VARCHAR(32)', $original);
        $this->assertNotSame($original, $edited, 'fixture 002.sql must still declare `tag VARCHAR(16)` in statement #0');
        file_put_contents($path, $edited);

        $drift = $this->migrate(['--dir=' . $dir]);
        $this->assertExit(4, $drift, 'resuming into a changed statement would apply a different migration than the recorded one');
        $this->assertStringContainsString('checksum mismatch', $drift['err']);
        $this->assertSame($ledger, $this->statementLedgerWithTimestamps(), 'a refused run must not touch the ledger');

        // (b) Shrink the file below the recorded statement count.
        file_put_contents($path, "-- every statement removed after a partial apply\n");
        $shrunk = $this->migrate(['--dir=' . $dir]);
        $this->assertExit(4, $shrunk, 'a file that lost recorded statements cannot be resumed soundly');
        $this->assertStringContainsString('the migration changed after a partial apply', $shrunk['err']);
        $this->assertSame($ledger, $this->statementLedgerWithTimestamps());
    }

    // -----------------------------------------------------------------------
    // 12. --dry-run writes nothing at all
    // -----------------------------------------------------------------------

    public function testDryRunReportsPendingWorkAndWritesNothing(): void
    {
        $dir = $this->stageFixture('clean-chain');

        $r = $this->migrate(['--dir=' . $dir, '--dry-run']);
        $this->assertExit(0, $r, '--dry-run against an empty schema is a report, not an error');

        $this->assertStringContainsString('ledger absent', $r['out']);
        $this->assertMatchesRegularExpression('/001_init: PENDING .* 2 statement\(s\)/', $r['out']);
        $this->assertStringContainsString('summary: pending=3 already_applied=0', $r['out']);
        $this->assertStringContainsString('dry-run: no changes made', $r['out']);

        // Strictly read-only: not even the ledger tables are created, which is
        // what makes --dry-run safe to point at production.
        $this->assertSame([], $this->tables());
    }

    // -----------------------------------------------------------------------
    // 13. Exit-code contract (CI depends on these being stable)
    // -----------------------------------------------------------------------

    public function testExitCodeContractForUsageAndEnvironmentErrors(): void
    {
        $dir = $this->stageFixture('clean-chain');

        $help = $this->migrate(['--help']);
        $this->assertExit(0, $help, '--help');
        $this->assertStringContainsString('Usage: php migrations/run.php', $help['out']);

        $unknown = $this->migrate(['--dir=' . $dir, '--nope']);
        $this->assertExit(2, $unknown, 'unknown flag');
        $this->assertStringContainsString('unknown argument: --nope', $unknown['err']);

        $through = $this->migrate(['--dir=' . $dir, '--through=004']);
        $this->assertExit(2, $through, '--through without --baseline');

        $both = $this->migrate(['--dir=' . $dir, '--baseline', '--dry-run']);
        $this->assertExit(2, $both, '--baseline with --dry-run');

        $missingDir = $this->migrate(['--dir=' . $dir . '-does-not-exist']);
        $this->assertExit(2, $missingDir, 'missing --dir');
        $this->assertStringContainsString('migrations directory not found', $missingDir['err']);

        // An unversionable .sql file must stop the run, never be skipped: a
        // silently ignored migration is the failure mode this exit code exists
        // to prevent.
        $strayDir = $this->stageFixture('unrecognized');
        $stray    = $this->migrate(['--dir=' . $strayDir]);
        $this->assertExit(2, $stray, 'unrecognized .sql file');
        $this->assertStringContainsString('schema.sql', $stray['err']);
        $this->assertSame([], $this->tables(), 'the valid sibling migration must not have been applied either');

        // Environment errors are 3, and distinct from usage errors.
        $noEnv = $this->migrate(['--dir=' . $dir], null, false);
        $this->assertExit(3, $noEnv, 'STAGING_WAIVER_DB_URL unset');
        $this->assertStringContainsString('STAGING_WAIVER_DB_URL', $noEnv['err']);

        $badScheme = $this->migrate(['--dir=' . $dir], 'postgres://u:p@' . $this->host . ':5432/x');
        $this->assertExit(3, $badScheme, 'non-mysql scheme');

        $noDbName = $this->migrate(['--dir=' . $dir], 'mysql://u:p@' . $this->host . ':3306');
        $this->assertExit(3, $noDbName, 'URL without a database name');

        // --url-env indirection: the same URL under a different variable name.
        $renamed = $this->migrate(
            ['--dir=' . $this->stageFixture('comment-only'), '--url-env=F2_ALT_DB_URL'],
            null,
            false,
            ['F2_ALT_DB_URL' => $this->dbUrl()]
        );
        $this->assertExit(0, $renamed, '--url-env must change which variable is read, not the value format');
        $this->assertSame(['001'], $this->appliedVersions());
    }

    // -----------------------------------------------------------------------
    // 14. Log hygiene: credentials never reach stdout/stderr
    // -----------------------------------------------------------------------

    public function testCredentialsAreNeverPrinted(): void
    {
        $dir      = $this->stageFixture('clean-chain');
        $password = 'f2-secret-pw-never-log-me';
        $url      = $this->dbUrl($password);

        $r = $this->migrate(['--dir=' . $dir], $url);
        $this->assertExit(3, $r, 'a wrong password is an environment error');

        $combined = $r['out'] . "\n" . $r['err'];
        $this->assertStringNotContainsString($password, $combined, 'the password must never appear in a CI log');
        $this->assertStringNotContainsString($url, $combined, 'the connection URL must never appear in a CI log');
        $this->assertStringContainsString('database connection failed', $r['err'], 'the failure itself must still be reported');
    }

    // -----------------------------------------------------------------------
    // 15. Short compose-style credentials (user=app / password=app) must not
    //     mangle ordinary log prose, but must stay masked wherever they ARE
    //     the credential. F2 report follow_up 1 / this fix's regression
    //     guard: redact() previously stripped the password with no minimum
    //     length, so a compose-style password corrupted the runner's own
    //     vocabulary ("already applied" -> "already <redacted:pass>lied",
    //     "files_applied=0" -> "files_<redacted:pass>lied=0").
    // -----------------------------------------------------------------------

    public function testShortAppCredentialsDoNotMangleWordsButStayMaskedAsCredentials(): void
    {
        $dir = $this->stageFixture('clean-chain');

        // Reuse the REAL compose account (docker-compose.yml:
        // MYSQL_USER=app / MYSQL_PASSWORD=app) — that exact 3-char/3-char
        // pair is F2's live repro. We deliberately do NOT drop/recreate
        // 'app'@'%': it is the shared application account and may be in
        // concurrent use elsewhere in this compose stack. Instead grant it
        // access to this test's own scratch schema and revoke that grant
        // afterwards — the same pattern
        // WaiverControllerEraseTest::testEraseWaiverRollsBackOnMidTransactionFailure
        // already uses for the same shared account, restored even if an
        // assertion fails mid-test.
        $this->root->exec('GRANT ALL PRIVILEGES ON `' . $this->schema . "`.* TO 'app'@'%'");
        $this->root->exec('FLUSH PRIVILEGES');
        $appUrl = sprintf('mysql://app:app@%s:%d/%s', $this->host, $this->port, $this->schema);

        try {
            // --- run 1: fresh apply. "APPLIED (2 statement(s))" and the
            // summary line both contain "app" as a substring of
            // "applied"/"statement(s)" — exactly what the bug mangled.
            $first = $this->migrate(['--dir=' . $dir], $appUrl);
            $this->assertExit(0, $first, 'user=app password=app must apply cleanly, same as any other credential');

            $this->assertStringContainsString('001_init: APPLIED (2 statement(s))', $first['out']);
            $this->assertStringContainsString('002_alter: APPLIED (1 statement(s))', $first['out']);
            $this->assertStringContainsString('003_index: APPLIED (1 statement(s))', $first['out']);
            $this->assertStringContainsString(
                'summary: files_applied=3 statements_executed=4 already_applied=0',
                $first['out'],
                'F2\'s live repro printed this exact line as "files_<redacted:pass>lied=0" before the fix'
            );
            $this->assertStringNotContainsString('<redacted:', $first['out'], 'a successful run never needs to print a redaction marker');

            // --- run 2: no-op re-run — F2's repro line, verbatim:
            // "[migrate] 001_init: already <redacted:pass>lied".
            $second = $this->migrate(['--dir=' . $dir], $appUrl);
            $this->assertExit(0, $second, 're-run over an already-applied chain');
            $this->assertStringContainsString('001_init: already applied', $second['out']);
            $this->assertStringContainsString('002_alter: already applied', $second['out']);
            $this->assertStringContainsString('003_index: already applied', $second['out']);
            $this->assertStringContainsString('summary: files_applied=0 statements_executed=0 already_applied=3', $second['out']);
            $this->assertStringNotContainsString('<redacted:', $second['out']);

            // --- credential-context check: the SAME short password, when it
            // truly IS the credential in a failed connection attempt, must
            // still never reach the log unmasked, and the masking must
            // actually have fired (not merely "the text happens to be
            // absent" — assert the positive marker too).
            $wrongUrl = sprintf('mysql://app:wrong-password-guess@%s:%d/%s', $this->host, $this->port, $this->schema);
            $failed   = $this->migrate(['--dir=' . $dir], $wrongUrl);
            $this->assertExit(3, $failed, 'a bad password against a real host is an environment error');

            $combined = $failed['out'] . "\n" . $failed['err'];
            $this->assertStringNotContainsString('wrong-password-guess', $combined, 'the attempted (wrong) password must never be echoed');
            $this->assertStringContainsString('database connection failed', $failed['err']);
            // MySQL's own "Access denied for user 'app'@'host'" text quotes
            // the username literally — prove the redactor still catches it
            // there (word-boundary: quote|app|@ are both boundaries) even
            // though it no longer touches "app" embedded inside "applied".
            $this->assertStringContainsString('<redacted:user>', $combined, 'the redactor must still engage in a genuine credential context');
            $this->assertStringNotContainsString("'app'@", $combined, 'the bare quoted username must not survive redaction');
        } finally {
            $this->root->exec("REVOKE ALL PRIVILEGES ON `" . $this->schema . "`.* FROM 'app'@'%'");
            $this->root->exec('FLUSH PRIVILEGES');
        }
    }

    // -----------------------------------------------------------------------
    // 16. DELIMITER / stored-program syntax is rejected before anything runs
    //     [gate1 batch 2 — codex fork P2]
    // -----------------------------------------------------------------------

    public function testDelimiterStoredProgramSyntaxIsRejectedBeforeAnyStatementRuns(): void
    {
        $dir = $this->stageFixture('delimiter');

        // Anti-vacuity: the fixture's own 002 file must still contain
        // exactly this bare DELIMITER keyword, or this test proves nothing.
        $this->assertMatchesRegularExpression('/^DELIMITER\s/mi', (string)file_get_contents($dir . '/002_stored_proc.sql'));

        $r = $this->migrate(['--dir=' . $dir]);
        $this->assertExit(2, $r, 'a DELIMITER-bearing file must be a usage error (unparseable), never a partial apply');

        $this->assertStringContainsString('001_before: APPLIED', $r['out'], 'the prior, ordinary file must still have applied cleanly');
        $this->assertStringContainsString('cannot parse migration', $r['err']);
        $this->assertStringContainsString('DELIMITER', $r['err']);
        $this->assertStringContainsString('not supported', $r['err']);

        // The decisive assertion: the ORDINARY statement preceding the
        // DELIMITER block in 002 must never have run. A splitter that mangled
        // (rather than rejected) the file could easily have applied this
        // CREATE TABLE and only then failed on the routine body's mis-split
        // remainder -- exactly the partial-application hazard codex flagged.
        $this->assertFalse($this->tableExists('f2_delim_should_never_exist'), '002 must be rejected wholesale, before its leading CREATE TABLE runs');
        $this->assertSame(['001_before'], $this->appliedVersions());
    }

    // -----------------------------------------------------------------------
    // 17. Duplicate numeric prefixes are a hard error, not silent name-order
    //     application [gate1 batch 2 — eng T7]
    // -----------------------------------------------------------------------

    public function testDuplicateNumericPrefixesAreRejectedAsAHardError(): void
    {
        $dir = $this->stageFixture('duplicate-prefix');

        $r = $this->migrate(['--dir=' . $dir]);
        $this->assertExit(2, $r, 'two files sharing a numeric prefix must stop the run, never silently apply both in name order');

        $this->assertStringContainsString('duplicate numeric prefix', $r['err']);
        $this->assertStringContainsString('005_a', $r['err']);
        $this->assertStringContainsString('005_b', $r['err']);

        // Neither file may have applied — discovery itself must refuse before
        // any migration in the directory runs.
        $this->assertFalse($this->tableExists('f2_dup_a'));
        $this->assertFalse($this->tableExists('f2_dup_b'));
        $this->assertSame([], $this->tables());
    }

    // -----------------------------------------------------------------------
    // 18. redact() masks the wholesale URL BEFORE `pass` can fragment it, so
    //     sub-3-char URL components (below the word-boundary floor) cannot
    //     leak [gate1 batch 2 — review P2-5]
    // -----------------------------------------------------------------------

    public function testRedactOrdersTheWholesaleUrlEntryBeforePassSoShortUrlComponentsCannotLeak(): void
    {
        // A 2-char user + 2-char db (both below redact()'s 3-char
        // word-boundary floor) sitting inside a STATEMENT'S OWN SQL text —
        // exercised via --verbose's out()-filtered statement preview, a real
        // log line a migration author could produce (e.g. a seed row whose
        // data happens to embed a URL-shaped value). Neither the 'user' nor
        // the 'db' entry can mask these on their own; only the wholesale
        // `url` entry — matched against the exact, unmutated raw connection
        // string — can. If `pass` were substituted first (the P2-5 bug), it
        // would break that exact-string match and 'ux'/'rd' would leak in
        // full.
        $user   = 'ux';
        $dbName = 'rd';
        $pass   = 'f2-redact-order-secret-pw';
        $host   = $this->host; // 'db' by default in this harness — already < 3 chars itself

        $this->root->exec('DROP DATABASE IF EXISTS `' . $dbName . '`');
        $this->root->exec('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4');
        $this->root->exec("DROP USER IF EXISTS '" . $user . "'@'%'");
        $this->root->exec("CREATE USER '" . $user . "'@'%' IDENTIFIED BY '" . $pass . "'");
        $this->root->exec('GRANT ALL PRIVILEGES ON `' . $dbName . "`.* TO '" . $user . "'@'%'");
        $this->root->exec('FLUSH PRIVILEGES');

        try {
            $url = sprintf('mysql://%s:%s@%s:%d/%s', $user, $pass, $host, $this->port, $dbName);

            $dir  = $this->stageFixture('redact-order');
            $path = $dir . '/001.sql';
            $sql  = str_replace(['__HOST__', '__PORT__'], [$host, (string)$this->port], (string)file_get_contents($path));
            file_put_contents($path, $sql);

            // Sanity: the fixture's own SQL text must literally embed this
            // exact URL, or the test proves nothing.
            $this->assertStringContainsString($url, $sql, 'fixture must embed the exact URL this test connects with');

            $r = $this->migrate(['--dir=' . $dir, '--verbose'], $url);
            $this->assertExit(0, $r, 'the statement is valid SQL — only its DATA happens to look like a connection URL');

            $combined = $r['out'] . "\n" . $r['err'];
            $this->assertStringNotContainsString($url, $combined, 'the raw URL must never survive intact in the log, including the --verbose statement echo');
            $this->assertStringNotContainsString($pass, $combined, 'the password must never survive verbatim, anywhere');
            $this->assertStringContainsString('<redacted:url>', $r['out'], 'the wholesale url entry must actually have fired against the verbose statement preview');

            // The decisive check: user/db are BELOW the word-boundary floor,
            // so the wholesale `url` entry is the ONLY thing that can mask
            // them. If `pass` ran first (P2-5), it would already have masked
            // the password IN PLACE (breaking the url entry's exact-string
            // match) while leaving the scheme+user prefix and the db suffix
            // fully exposed either side of it — e.g. "mysql://ux:<redacted:
            // pass>@db:3306/rd". Checking the password's own absence is not
            // enough to catch that (pass masks itself either way); these two
            // checks specifically target what the BUG leaves behind.
            $this->assertStringNotContainsString('://' . $user . ':', $combined, "the URL's scheme+user prefix must not survive — 'ux' is below redact()'s word-boundary floor and only the wholesale url entry can mask it");
            $this->assertStringNotContainsString('/' . $dbName . "'", $combined, "the 2-char db name must not survive — it is below redact()'s word-boundary floor and only the wholesale url entry can mask it");
        } finally {
            $this->root->exec("DROP USER IF EXISTS '" . $user . "'@'%'");
            $this->root->exec('DROP DATABASE IF EXISTS `' . $dbName . '`');
            $this->root->exec('FLUSH PRIVILEGES');
        }
    }

    // -----------------------------------------------------------------------
    // 19. [T5 / waiver-coverage step 5] The REAL committed 005_evidence_fields.sql
    //     applies cleanly via the ledger runner, on top of a baselined 001..004
    //     -- unlike every test above, this points --dir= at the repo's actual
    //     migrations/ directory rather than a tests/fixtures/migrations-*
    //     copy, so it proves the committed file itself (not a stand-in) is
    //     valid SQL the runner can apply end to end.
    // -----------------------------------------------------------------------

    public function testReal005EvidenceFieldsMigrationAppliesCleanlyOnAnExistingPreO05Database(): void
    {
        // Simulates the REAL ops target T5 describes: staging/prod already
        // has 001-004 applied (the OLD, pre-005 waiver_responses shape --
        // built before 001_init.sql was updated to bake 005's columns in for
        // FRESH installs, per that file's own "[T5] baked in from
        // 005_evidence_fields.sql" comment). A scratch schema bootstrapped by
        // actually EXECUTING the current 001_init.sql would already carry
        // 005's columns and make the real 005 file duplicate-column instead
        // of proving anything -- so this builds the OLD (pre-005)
        // waiver_responses shape by hand and baselines 001..004 (ledger-only,
        // no DDL executed -- mirrors createLegacyLedger()/testBaselineMarks...
        // above), leaving 005 as the one genuinely PENDING file.
        $this->createLegacyWaiverResponsesTableMissingEvidenceColumns();

        $realMigrationsDir = \dirname(__DIR__) . '/migrations';
        $this->assertFileExists($realMigrationsDir . '/005_evidence_fields.sql', 'this test must exercise the real, committed T5 migration file');

        $baseline = $this->migrate(['--dir=' . $realMigrationsDir, '--baseline', '--through=004']);
        $this->assertExit(0, $baseline, 'baselining the real 001..004 against a schema shaped like an existing pre-005 database');
        $this->assertSame(
            ['001_init', '002_waiver_integration', '003_erase_waiver', '004_erasure_audit_events_backfill'],
            $this->appliedVersions()
        );
        foreach (['evidence_sha256', 'evidence_object_key', 'evidence_blob_key', 'evidence_blob_url'] as $col) {
            $this->assertFalse($this->columnExists('waiver_responses', $col), "sanity: $col must NOT exist yet -- baselining executes no DDL");
        }

        $r = $this->migrate(['--dir=' . $realMigrationsDir]);
        $this->assertExit(0, $r, 'the real 005_evidence_fields.sql must apply cleanly on top of a baselined 001..004');
        $this->assertStringContainsString('005_evidence_fields: APPLIED (1 statement(s))', $r['out']);
        $this->assertStringContainsString('summary: files_applied=1 statements_executed=1 already_applied=4', $r['out']);

        $this->assertSame(
            ['001_init', '002_waiver_integration', '003_erase_waiver', '004_erasure_audit_events_backfill', '005_evidence_fields'],
            $this->appliedVersions()
        );
        foreach (['evidence_sha256', 'evidence_object_key', 'evidence_blob_key', 'evidence_blob_url'] as $col) {
            $this->assertTrue($this->columnExists('waiver_responses', $col), "005 must add $col to waiver_responses");
        }
        // The pre-existing columns 005 places its ADD COLUMNs AFTER must
        // survive untouched -- 005 only ever adds, never modifies/drops.
        $this->assertTrue($this->columnExists('waiver_responses', 'signature_path'));
        $this->assertTrue($this->columnExists('waiver_responses', 'hash_sha256'));

        // Idempotent re-run over the now-fully-applied real chain: a no-op,
        // not an error -- same "already applied" contract as every fixture
        // test above, now proven against the real file.
        $second = $this->migrate(['--dir=' . $realMigrationsDir]);
        $this->assertExit(0, $second, 're-running the real migrations dir once fully applied must be a no-op');
        $this->assertStringContainsString('005_evidence_fields: already applied', $second['out']);
        $this->assertStringContainsString('summary: files_applied=0 statements_executed=0 already_applied=5', $second['out']);
    }

    // =======================================================================
    // Helpers
    // =======================================================================

    /**
     * Run the real runner as a child process.
     *
     * @param  list<string>          $args
     * @param  string|null           $url       explicit URL (null = this test's scratch schema)
     * @param  bool                  $withUrl   false leaves STAGING_WAIVER_DB_URL unset
     * @param  array<string,string>  $extraEnv
     * @return array{code:int,out:string,err:string}
     */
    private function migrate(array $args, ?string $url = null, bool $withUrl = true, array $extraEnv = []): array
    {
        $env = ['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'];
        if ($withUrl) {
            $env['STAGING_WAIVER_DB_URL'] = $url ?? $this->dbUrl();
        }
        $env = array_merge($env, $extraEnv);

        $runner = \dirname(__DIR__) . '/migrations/run.php';
        $this->assertFileExists($runner, 'migrations/run.php must exist for this suite to mean anything');

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            array_merge([PHP_BINARY, $runner], $args),
            $descriptors,
            $pipes,
            \dirname(__DIR__),
            $env
        );
        $this->assertIsResource($proc, 'could not spawn the migration runner');

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $err = '';
        $deadline = microtime(true) + 60.0;
        while (true) {
            $read = [];
            if (!feof($pipes[1])) { $read[] = $pipes[1]; }
            if (!feof($pipes[2])) { $read[] = $pipes[2]; }
            if ($read === []) { break; }
            if (microtime(true) > $deadline) {
                proc_terminate($proc);
                $this->fail('migration runner did not finish within 60s');
            }
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 1) === false) { break; }
            foreach ($read as $stream) {
                $chunk = fread($stream, 8192);
                if ($chunk === false || $chunk === '') { continue; }
                if ($stream === $pipes[1]) { $out .= $chunk; } else { $err .= $chunk; }
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($proc), 'out' => $out, 'err' => $err];
    }

    /** @param array{code:int,out:string,err:string} $result */
    private function assertExit(int $expected, array $result, string $what): void
    {
        $this->assertSame(
            $expected,
            $result['code'],
            $what . ": expected exit $expected, got {$result['code']}\n--- stdout ---\n{$result['out']}--- stderr ---\n{$result['err']}"
        );
    }

    private function dbUrl(?string $password = null): string
    {
        return sprintf(
            'mysql://%s:%s@%s:%d/%s',
            rawurlencode(self::RUNNER_USER),
            rawurlencode($password ?? self::RUNNER_PASS),
            $this->host,
            $this->port,
            $this->schema
        );
    }

    /**
     * Copy a committed fixture set into a fresh temp directory. Tests repair and
     * corrupt migrations in place, so they must never operate on the originals.
     */
    private function stageFixture(string $name): string
    {
        $src = __DIR__ . '/fixtures/migrations-' . $name;
        $this->assertDirectoryExists($src, 'fixture set migrations-' . $name . ' must exist');

        $dst = sys_get_temp_dir() . '/f2-migfx-' . bin2hex(random_bytes(6));
        if (!mkdir($dst, 0o777, true) && !is_dir($dst)) {
            $this->fail('could not create temp fixture dir ' . $dst);
        }
        $this->tempDirs[] = $dst;

        $copied = 0;
        foreach (scandir($src) ?: [] as $entry) {
            if (!is_file($src . '/' . $entry)) { continue; }
            copy($src . '/' . $entry, $dst . '/' . $entry);
            $copied++;
        }
        $this->assertGreaterThan(0, $copied, 'fixture set migrations-' . $name . ' must contain files');

        return $dst;
    }

    /** The legacy ledger exactly as 001_init.sql creates it: VARCHAR(32) version. */
    private function createLegacyLedger(): void
    {
        $this->db->exec(
            'CREATE TABLE schema_migrations (
               version VARCHAR(32) PRIMARY KEY,
               applied_at DATETIME NOT NULL
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * [T5] `waiver_responses` exactly as 001_init.sql shaped it BEFORE the T5
     * bake-in (i.e. the real shape of an existing staging/prod database that
     * has 001-004 applied but not yet 005) -- ending at `signature_path` /
     * `created_at`, none of 005's four evidence columns present. Used only by
     * testReal005EvidenceFieldsMigrationAppliesCleanlyOnAnExistingPreO05Database
     * so the real 005_evidence_fields.sql has a genuine pre-005 target to
     * ALTER, rather than a schema that already has the columns (which would
     * make 005 duplicate-column instead of proving anything).
     */
    private function createLegacyWaiverResponsesTableMissingEvidenceColumns(): void
    {
        $ddl = 'CREATE TABLE waiver_responses ('
            . 'id BIGINT PRIMARY KEY AUTO_INCREMENT, '
            . 'waiver_instance_id BIGINT NOT NULL UNIQUE, '
            . 'answers_json JSON NOT NULL, '
            . 'signature_png LONGBLOB NULL, '
            . 'signer_full_name VARCHAR(255) NULL, '
            . 'signer_initials VARCHAR(16) NULL, '
            . 'signed_at DATETIME NOT NULL, '
            . 'signer_ip VARCHAR(45) NULL, '
            . 'signer_user_agent TEXT NULL, '
            . 'hash_sha256 CHAR(64) NOT NULL, '
            . 'pdf_path VARCHAR(512) NULL, '
            . 'signature_path VARCHAR(512) NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'INDEX (signed_at), '
            . 'INDEX (waiver_instance_id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        $this->db->prepare($ddl)->execute();
    }

    /** @return list<string> */
    private function tables(): array
    {
        return array_map(
            static fn (array $r): string => (string)$r['TABLE_NAME'],
            $this->rows(
                'SELECT TABLE_NAME FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = ' . $this->db->quote($this->schema) . '
                  ORDER BY TABLE_NAME'
            )
        );
    }

    private function tableExists(string $table): bool
    {
        return in_array($table, $this->tables(), true);
    }

    private function columnExists(string $table, string $column): bool
    {
        $st = $this->db->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([$this->schema, $table, $column]);
        return (bool)$st->fetchColumn();
    }

    private function indexExists(string $table, string $index): bool
    {
        $st = $this->db->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $st->execute([$this->schema, $table, $index]);
        return (bool)$st->fetchColumn();
    }

    private function versionColumnLength(): ?int
    {
        $st = $this->db->prepare(
            'SELECT CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([$this->schema, 'schema_migrations', 'version']);
        $len = $st->fetchColumn();
        return $len === false || $len === null ? null : (int)$len;
    }

    /**
     * Versions in schema_migrations, byte-sorted in PHP rather than by MySQL —
     * the server's UCA collation orders `_` and digits differently from a byte
     * comparison, which would make the unpadded-ordering expectations depend on
     * the server's collation instead of on the runner.
     *
     * @return list<string>
     */
    private function appliedVersions(): array
    {
        if (!$this->tableExists('schema_migrations')) { return []; }
        $versions = array_map(
            static fn (array $r): string => (string)$r['version'],
            $this->rows('SELECT version FROM schema_migrations')
        );
        sort($versions, SORT_STRING);
        return $versions;
    }

    /** @return list<array{version:string,applied_at:string}> */
    private function appliedVersionsWithTimestamps(): array
    {
        if (!$this->tableExists('schema_migrations')) { return []; }
        $rows = $this->rows('SELECT version, applied_at FROM schema_migrations');
        usort($rows, static fn (array $a, array $b): int => strcmp((string)$a['version'], (string)$b['version']));
        return $rows;
    }

    /** @return list<array{version:string,statement_index:int}> */
    private function statementIndexes(): array
    {
        return array_map(
            static fn (array $r): array => ['version' => $r['version'], 'statement_index' => (int)$r['statement_index']],
            $this->statementRows()
        );
    }

    /** @return list<array{version:string,statement_index:int,checksum:string}> */
    private function statementLedger(): array
    {
        return array_map(
            static fn (array $r): array => [
                'version'         => $r['version'],
                'statement_index' => (int)$r['statement_index'],
                'checksum'        => $r['checksum'],
            ],
            $this->statementRows()
        );
    }

    /** Full rows including applied_at, for byte-identity comparisons. */
    private function statementLedgerWithTimestamps(): array
    {
        return $this->statementRows();
    }

    private function statementRows(): array
    {
        if (!$this->tableExists('schema_migration_statements')) { return []; }
        $rows = $this->rows('SELECT version, statement_index, checksum, applied_at FROM schema_migration_statements');
        usort(
            $rows,
            static fn (array $a, array $b): int
                => [(string)$a['version'], (int)$a['statement_index']] <=> [(string)$b['version'], (int)$b['statement_index']]
        );
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql): array
    {
        return $this->db->query($sql)->fetchAll();
    }

    private function dropSchema(): void
    {
        if ($this->schema === '') { return; }
        try {
            $this->root->exec('DROP DATABASE IF EXISTS `' . $this->schema . '`');
        } catch (\Throwable $e) {
            // A leftover schema we cannot drop would surface as the next
            // CREATE failing loudly; nothing to hide here.
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) { return; }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') { continue; }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
