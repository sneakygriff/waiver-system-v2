<?php
namespace Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * [CI/CD M4, AC4.1 / AC4.3 / AC4.4 / AC4.5] Pure structural pins on
 * .github/workflows/ci.yml (F3, commit 1c09a97) -- the fork's first and
 * only workflow, the staging pipeline:
 *
 *   phpunit --+--> image  (GHCR build+push, immutability guard)
 *             +--> dump --> migrate --> deploy --> health --> dispatch
 *
 * No DB, no network, no shell-out to git/grep: the workflow is parsed ONCE
 * with symfony/yaml and every assertion below reads the PARSED tree, never
 * the raw file text.
 *
 * That distinction is load-bearing, not stylistic. This very file legitimately
 * contains the substring "prod" inside prose that is NOT a production
 * identifier -- "never production" (twice, inside run: blocks), "reproduces"
 * (inside a YAML comment). A raw grep for "prod", or for continue-on-error,
 * or for --fail-on-skipped, would either false-positive on text that merely
 * mentions the word, or (worse) miss a real one hiding behind a comment that
 * *looks* like a match but isn't structurally a key. Parsing first turns every
 * check into "is this an actual YAML key/value at this exact structural
 * position" -- something no comment or prose string can fake, because
 * symfony/yaml strips every YAML comment before this suite ever sees the tree,
 * and every check below that touches free text is scoped to a specific,
 * named field (an `if:`, a named step's `run:`), never a blind sweep.
 *
 * This suite needs no DB and no compose stack; it runs standalone, and it is
 * NOT DB-backed, so tests/README.md's markTestSkipped() convention does not
 * apply here -- there is nothing for these tests to skip.
 */
final class WorkflowStructureTest extends TestCase {
  private const WORKFLOW_PATH = __DIR__.'/../.github/workflows/ci.yml';
  private const WORKFLOWS_DIR = __DIR__.'/../.github/workflows';
  private const REPO_ROOT = __DIR__.'/..';

  /** Directory names never treated as part of "the repo tree" by the UUID
   *  scan below: vendor (third-party code), .git (VCS internals, binary
   *  objects), storage (git-ignored runtime artifacts -- thousands of signed
   *  PDFs, per .gitignore), node_modules (none today, defensive). */
  private const UUID_SCAN_EXCLUDED_DIRS = ['vendor', '.git', 'storage', 'node_modules'];

  /** Specific git-ignored files that are never part of the committed tree. */
  private const UUID_SCAN_EXCLUDED_FILES = ['.phpunit.result.cache', '.DS_Store'];

  /** Canonical 8-4-4-4-12 hex UUID shape -- what a Railway environment/service
   *  id looks like. */
  private const UUID_LITERAL_RE = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

  /** @var array<string,mixed>|null */
  private static ?array $workflow = null;

  /** @return array<string,mixed> */
  private static function workflow(): array {
    if (self::$workflow === null) {
      $parsed = Yaml::parseFile(self::WORKFLOW_PATH);
      self::$workflow = is_array($parsed) ? $parsed : [];
    }
    return self::$workflow;
  }

  /** @return list<string> every *.yml/*.yaml file directly under .github/workflows/ */
  private static function allWorkflowFiles(): array {
    $files = array_merge(
      glob(self::WORKFLOWS_DIR.'/*.yml') ?: [],
      glob(self::WORKFLOWS_DIR.'/*.yaml') ?: []
    );
    sort($files);
    return $files;
  }

  /**
   * Flat list of [jobId, stepIndex, step] across every job's steps: in
   * ci.yml. A "step" is whatever symfony/yaml parsed for one list entry
   * under a job's `steps:` -- an associative array of its keys (name, id,
   * run, uses, if, env, continue-on-error, ...), never raw text.
   *
   * @return list<array{0:string,1:int,2:array<string,mixed>}>
   */
  private static function allSteps(): array {
    $out = [];
    foreach (self::workflow()['jobs'] as $jobId => $job) {
      foreach (($job['steps'] ?? []) as $i => $step) {
        $out[] = [$jobId, $i, $step];
      }
    }
    return $out;
  }

  /**
   * Every mapping found under a key literally named "env" anywhere in a
   * parsed workflow tree (the top-level workflow env:, every step's env:,
   * the mysql service's env:), flattened to [key, value] pairs.
   *
   * This is a STRUCTURAL walk over the PARSED array -- it can never pick up
   * a comment or a run: script's shell prose, because those are not "env"
   * mapping keys in the tree; they are the *value* of a "run" key, which
   * this walk does not open up and pattern-match against.
   *
   * @param array<mixed> $node
   * @param list<array{0:string,1:mixed}> $out
   */
  private static function walkForEnv($node, array &$out): void {
    if (!is_array($node)) {
      return;
    }
    foreach ($node as $key => $value) {
      if ($key === 'env' && is_array($value)) {
        foreach ($value as $envKey => $envValue) {
          $out[] = [(string) $envKey, $envValue];
        }
      }
      if (is_array($value)) {
        self::walkForEnv($value, $out);
      }
    }
  }

  /**
   * Every env [key, value] pair across the WHOLE .github/workflows/ tree
   * (every *.yml/*.yaml file directly in that directory, not just ci.yml) --
   * "no other Railway ID literal exists anywhere in the .github/workflows/
   * tree" is a claim about the tree, not about one file, so a second
   * workflow file added later (e.g. by a future milestone) is covered by
   * this same check without needing to be named here.
   *
   * @return list<array{0:string,1:mixed}>
   */
  private static function allEnvPairsAcrossWorkflowsTree(): array {
    $out = [];
    foreach (self::allWorkflowFiles() as $file) {
      $parsed = Yaml::parseFile($file);
      if (is_array($parsed)) {
        self::walkForEnv($parsed, $out);
      }
    }
    return $out;
  }

  /**
   * Every UUID-shaped literal found ANYWHERE in the repo tree (vendor/.git/
   * storage/node_modules excluded -- see UUID_SCAN_EXCLUDED_DIRS/_FILES),
   * lowercased, alongside the path it was found in relative to the repo
   * root. Unlike allEnvPairsAcrossWorkflowsTree(), this is a raw byte scan of
   * every file's contents -- it is what makes AC4.4's "no OTHER Railway ID
   * literal exists anywhere in the repo" a claim about the whole tree, not
   * just about env: mappings under .github/workflows/*.
   *
   * @return list<array{0:string,1:string}> [lowercased uuid, relative path]
   */
  private static function repoUuidLiterals(): array {
    $root = realpath(self::REPO_ROOT);
    if ($root === false) {
      throw new \RuntimeException('WorkflowStructureTest::REPO_ROOT does not resolve -- the UUID scan cannot run.');
    }

    $found = [];
    $dirIterator = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
    $filtered = new \RecursiveCallbackFilterIterator($dirIterator, static function (\SplFileInfo $file): bool {
      if ($file->isDir()) {
        return !in_array($file->getFilename(), self::UUID_SCAN_EXCLUDED_DIRS, true);
      }
      return !in_array($file->getFilename(), self::UUID_SCAN_EXCLUDED_FILES, true);
    });
    $iterator = new \RecursiveIteratorIterator($filtered);

    foreach ($iterator as $fileInfo) {
      /** @var \SplFileInfo $fileInfo */
      if (!$fileInfo->isFile() || !$fileInfo->isReadable()) {
        continue;
      }
      $contents = @file_get_contents($fileInfo->getPathname());
      if ($contents === false || $contents === '') {
        continue;
      }
      if (preg_match_all(self::UUID_LITERAL_RE, $contents, $matches) > 0) {
        $relative = ltrim(substr($fileInfo->getPathname(), strlen($root)), '/');
        foreach ($matches[0] as $uuid) {
          $found[] = [strtolower($uuid), $relative];
        }
      }
    }

    return $found;
  }

  // ==========================================================================
  // Sanity: the file this whole suite is about actually exists and parses.
  // ==========================================================================

  public function testWorkflowFileExistsAndParsesToANonEmptyMapping(): void {
    $this->assertFileExists(self::WORKFLOW_PATH, 'the fork\'s staging workflow must exist at this exact path.');
    $workflow = self::workflow();
    $this->assertNotEmpty($workflow, '.github/workflows/ci.yml parsed to an empty structure -- it is either empty or not valid YAML.');
    $this->assertArrayHasKey('jobs', $workflow);
  }

  public function testWorkflowsTreeGlobFindsCiYml(): void {
    // A defensive check on the glob itself: if WORKFLOWS_DIR were ever wrong,
    // every tree-wide check below would silently pass over zero files.
    $this->assertContains(
      self::WORKFLOW_PATH,
      self::allWorkflowFiles(),
      'the .github/workflows/ tree-wide glob did not find ci.yml -- WORKFLOWS_DIR is wrong.'
    );
  }

  // ==========================================================================
  // 1. Trigger scope: push to master, exactly (brief assertion #1).
  // ==========================================================================

  public function testTriggerIsExactlyPushToMasterNothingElse(): void {
    $on = self::workflow()['on'] ?? null;
    $this->assertIsArray($on, '"on:" must parse to a mapping (symfony/yaml correctly keeps the literal key "on", not the YAML-1.1 boolean gotcha -- verified separately).');
    $this->assertSame(
      ['push'],
      array_keys($on),
      '"on:" must declare exactly one trigger kind (push) -- no pull_request, workflow_dispatch, schedule, or tag trigger. '.
      'This workflow carries STAGING_WAIVER_DB_URL, RAILWAY_STAGING_TOKEN and ADMIN_DISPATCH_PAT; any additional trigger '.
      'widens who/what can cause it to run.'
    );
    $this->assertSame(
      ['branches' => ['master']],
      $on['push'],
      'push must be scoped to EXACTLY branches: [master] -- the fork\'s release branch (the admin repo\'s is "main"; '.
      'getting this wrong once already cost a runbook reality-sync). No tags, no branch globs, no second branch.'
    );
  }

  // ==========================================================================
  // 2. Job graph: the 7 pinned ids exist, and every non-gate job names
  //    `phpunit` DIRECTLY in needs: (F3 report follow-up #5; brief #2).
  // ==========================================================================

  public function testJobIdSetIsPinnedExactly(): void {
    $this->assertEqualsCanonicalizing(
      ['phpunit', 'image', 'dump', 'migrate', 'deploy', 'health', 'dispatch'],
      array_keys(self::workflow()['jobs']),
      'the job id set has drifted from the pinned chain: phpunit -> {image, dump -> migrate -> deploy -> health -> dispatch}.'
    );
  }

  public function testPhpunitIsTheGateAndNeedsNothing(): void {
    $this->assertArrayNotHasKey(
      'needs',
      self::workflow()['jobs']['phpunit'],
      'phpunit is THE GATE -- it must not need anything, or it would no longer be the first thing that runs.'
    );
  }

  public function testEveryNonGateJobNamesPhpunitDirectlyInNeeds(): void {
    foreach (self::workflow()['jobs'] as $jobId => $job) {
      if ($jobId === 'phpunit') {
        continue;
      }
      $needs = $job['needs'] ?? [];
      $this->assertIsArray($needs, "job '$jobId' must declare needs: as a list.");
      $this->assertContains(
        'phpunit',
        $needs,
        "job '$jobId' must name phpunit DIRECTLY in needs: -- not merely transitively through another job -- so the ".
        "gate is readable one job at a time, both by a human and by this test."
      );
    }
  }

  // ==========================================================================
  // 3. Concurrency (D13 policy; brief assertion #3).
  // ==========================================================================

  public function testConcurrencyGroupIsWaiverStagingWithCancelInProgressFalse(): void {
    $this->assertSame(
      ['group' => 'waiver-staging', 'cancel-in-progress' => false],
      self::workflow()['concurrency'] ?? null,
      'concurrency: must be EXACTLY {group: waiver-staging, cancel-in-progress: false} -- a running pipeline '.
      '(mid-migration, mid-deploy) must never be cancelled by a later push; a half-applied migration plus a '.
      'half-flipped Railway image is worse than a late run (D13 policy).'
    );
  }

  // ==========================================================================
  // 4. Deploy-chain job order IS the mechanism (AC4.3; brief assertion #4):
  //    dump -> migrate -> deploy -> health -> dispatch, each naming the one
  //    before it DIRECTLY in needs:.
  // ==========================================================================

  public function testDeployChainOrderDumpMigrateDeployHealthDispatch(): void {
    $jobs = self::workflow()['jobs'];
    $chain = [
      'migrate'  => 'dump',
      'deploy'   => 'migrate',
      'health'   => 'deploy',
      'dispatch' => 'health',
    ];
    foreach ($chain as $job => $mustNeedDirectly) {
      $needs = $jobs[$job]['needs'] ?? [];
      $this->assertContains(
        $mustNeedDirectly,
        $needs,
        "job '$job' must name '$mustNeedDirectly' DIRECTLY in needs: -- the deploy chain's step order ".
        "(dump -> migrate -> deploy -> health -> dispatch) IS the mechanism that keeps a failed migration ".
        "from ever reaching a deploy, and a failed deploy from ever reaching dispatch (AC4.3)."
      );
    }
    $this->assertEqualsCanonicalizing(
      ['phpunit'],
      $jobs['dump']['needs'] ?? [],
      'dump is the FIRST link in the deploy chain -- it must depend on nothing but the gate.'
    );
  }

  // ==========================================================================
  // 5. GHCR immutability guard precedes AND gates the build/push step
  //    (AC4.1; brief assertion #5). "Gates" is checked via the actual `if:`
  //    expression, not merely step order -- an ordered-but-ungated push
  //    would run unconditionally regardless of what the probe found.
  // ==========================================================================

  public function testGhcrProbeGuardPrecedesAndGatesTheBuildAndPushStep(): void {
    $steps = self::workflow()['jobs']['image']['steps'];
    $probeIndex = null;
    $buildIndex = null;
    foreach ($steps as $i => $step) {
      if (($step['id'] ?? null) === 'probe') {
        $probeIndex = $i;
      }
      if (($step['id'] ?? null) === 'build') {
        $buildIndex = $i;
      }
    }
    $this->assertNotNull($probeIndex, "the image job must have a step with id: probe (the GHCR tag-exists guard).");
    $this->assertNotNull($buildIndex, "the image job must have a step with id: build (the actual docker build+push).");
    $this->assertLessThan(
      $buildIndex,
      $probeIndex,
      'the probe step must run BEFORE the build/push step -- an existing tag must be discovered before, not '.
      'after, a push could overwrite it.'
    );
    $this->assertSame(
      "\${{ steps.probe.outputs.exists == 'false' }}",
      $steps[$buildIndex]['if'] ?? null,
      "the build step must be GATED on the probe's own result (if: steps.probe.outputs.exists == 'false'), ".
      "not merely ordered after it."
    );
  }

  // ==========================================================================
  // 6. NO continue-on-error anywhere in the file (AC4.5; brief assertion #6),
  //    verified by walking every PARSED step -- never by grepping raw text,
  //    since a comment could carry the literal string without it being a
  //    real YAML key at all.
  // ==========================================================================

  public function testNoStepAnywhereInTheFileHasContinueOnError(): void {
    $steps = self::allSteps();
    $this->assertNotEmpty($steps, 'sanity: allSteps() found no steps at all -- the walk is broken.');
    foreach ($steps as [$jobId, $i, $step]) {
      $this->assertArrayNotHasKey(
        'continue-on-error',
        $step,
        "job '$jobId' step #$i (".($step['name'] ?? '(unnamed)').") must not carry continue-on-error -- ".
        "this pipeline fails fast, everywhere, by design; nothing here is allowed to swallow a failure."
      );
    }
  }

  public function testDispatchStepSpecificallyHasNoContinueOnError(): void {
    $steps = self::workflow()['jobs']['dispatch']['steps'];
    $dispatchStep = null;
    foreach ($steps as $step) {
      if (($step['name'] ?? null) === 'Dispatch to the admin repo and confirm our run appeared') {
        $dispatchStep = $step;
      }
    }
    $this->assertNotNull($dispatchStep, 'the dispatch job must have its named dispatch-and-confirm step.');
    $this->assertArrayNotHasKey(
      'continue-on-error',
      $dispatchStep,
      'AC4.5: the dispatch step must fail its job (and therefore the whole run) on any non-2xx response, '.
      'network failure, or admin run that never appears. continue-on-error would silently swallow exactly '.
      'that failure.'
    );
  }

  // ==========================================================================
  // 7. Railway id scoping -- AC4.4 static analysis (brief assertion #7).
  // ==========================================================================

  public function testExactlyOneStagingRailwayIdPairAndNoOtherRailwayIdAnywhere(): void {
    $railwayIdKeys = [];
    foreach (self::allEnvPairsAcrossWorkflowsTree() as [$key, $value]) {
      if (preg_match('/^RAILWAY(?:_[A-Za-z0-9]+)*_ID$/', $key)) {
        $railwayIdKeys[] = $key;
      }
    }
    sort($railwayIdKeys);
    $this->assertSame(
      ['RAILWAY_STAGING_ENVIRONMENT_ID', 'RAILWAY_STAGING_SERVICE_ID'],
      $railwayIdKeys,
      'exactly ONE Railway environment id env key and ONE Railway service id env key must exist anywhere in '.
      'the .github/workflows/ tree, and they must be these two STAGING names -- no additional Railway id '.
      '(renamed, duplicated, or prod) may be declared anywhere.'
    );
  }

  public function testNoProdFamilyIdentifierInAnyWorkflowEnvKeyOrValue(): void {
    $pairs = self::allEnvPairsAcrossWorkflowsTree();
    $this->assertNotEmpty($pairs, 'sanity: no env pairs were collected at all -- the walk is broken.');
    foreach ($pairs as [$key, $value]) {
      $this->assertDoesNotMatchRegularExpression(
        '/PROD/i',
        $key,
        "env key '$key' (in an env: mapping somewhere under .github/workflows/) looks prod-flavoured. This ".
        "fork's CI deploys to staging and to nothing else; production waiver deploys are M5's promote lane, ".
        "from the admin repo -- no PROD_* env key belongs here."
      );
      if (is_string($value)) {
        $this->assertDoesNotMatchRegularExpression(
          '/PROD/i',
          $value,
          "env value for '$key' looks prod-flavoured (value: ".var_export($value, true).')'
        );
      }
    }
  }

  // ==========================================================================
  // 7b. Repo-tree UUID-literal scan: AC4.4 "no OTHER Railway ID literal
  //     exists anywhere in the repo", as specified -- not narrowed to
  //     .github/workflows/ env: mappings (eng T2, gate1 batch 2). The two
  //     tests above only ever look at env: mappings; a Railway UUID inlined
  //     in a run: script body, a shell script, or any other committed file
  //     would evade both of them -- a UUID doesn't spell "PROD" and isn't a
  //     RAILWAY*_ID env key. This test globs the WHOLE repo tree instead.
  // ==========================================================================

  public function testNoRailwayUuidLiteralAnywhereInTheRepoTreeOutsideTheAllowedStagingPair(): void {
    // The allowlist is DERIVED from the same staging pair
    // testExactlyOneStagingRailwayIdPairAndNoOtherRailwayIdAnywhere() pins --
    // never hardcoded -- so this test needs no edit on the day those env
    // values are actually filled in at provisioning. Today both
    // RAILWAY_STAGING_ENVIRONMENT_ID and RAILWAY_STAGING_SERVICE_ID are ""
    // (unprovisioned), so the allowlist is empty and this test asserts ZERO
    // UUID-shaped literals exist anywhere in the tree -- a real, non-vacuous
    // guard today, verified by a mutation-kill: planting a UUID literal in a
    // `run:` block must make this test die.
    $allowlist = [];
    foreach (self::allEnvPairsAcrossWorkflowsTree() as [$key, $value]) {
      $isStagingIdKey = $key === 'RAILWAY_STAGING_ENVIRONMENT_ID' || $key === 'RAILWAY_STAGING_SERVICE_ID';
      if ($isStagingIdKey && is_string($value) && preg_match(self::UUID_LITERAL_RE, $value)) {
        $allowlist[] = strtolower($value);
      }
    }

    $disallowed = [];
    foreach (self::repoUuidLiterals() as [$uuid, $path]) {
      if (!in_array($uuid, $allowlist, true)) {
        $disallowed[] = $uuid.' ('.$path.')';
      }
    }
    sort($disallowed);

    $this->assertSame(
      [],
      $disallowed,
      'a Railway-id-shaped UUID literal exists somewhere in the repo tree outside the allowed staging pair -- '.
      'AC4.4 requires no OTHER Railway id (renamed, duplicated, or prod) anywhere in the repo, not just in an '.
      '.github/workflows/ env: mapping.'
    );
  }

  // ==========================================================================
  // 8. phpunit invocation carries --fail-on-skipped (brief assertion #8;
  //    vacuous-test discipline: a DB-less runner must go RED, not silently
  //    green having asserted nothing).
  // ==========================================================================

  public function testPhpunitInvocationCarriesFailOnSkipped(): void {
    $steps = self::workflow()['jobs']['phpunit']['steps'];
    $runStep = null;
    foreach ($steps as $step) {
      if (($step['name'] ?? null) === 'Run the phpunit suite') {
        $runStep = $step;
      }
    }
    $this->assertNotNull($runStep, 'the phpunit job must have its named "Run the phpunit suite" step.');
    $this->assertMatchesRegularExpression(
      '/(^|\s)--fail-on-skipped(\s|$)/',
      $runStep['run'] ?? '',
      'the phpunit invocation must carry --fail-on-skipped as a standalone flag -- without it, a DB-less '.
      'runner turns every DB-backed test into a silent markTestSkipped() and the gate goes green having '.
      'asserted nothing.'
    );
  }

  // ==========================================================================
  // Permissions: top-level {} with exactly one packages: write exception
  // (the image job, which alone pushes to GHCR).
  // ==========================================================================

  public function testTopLevelPermissionsIsTheEmptyMapping(): void {
    $this->assertSame(
      [],
      self::workflow()['permissions'] ?? null,
      'top-level permissions: must be the empty mapping {} -- least privilege by default; every job states '.
      'its own grant explicitly.'
    );
  }

  public function testExactlyOneJobGrantsPackagesWriteAndItIsImage(): void {
    $jobsWithPackagesWrite = [];
    foreach (self::workflow()['jobs'] as $jobId => $job) {
      $permissions = $job['permissions'] ?? [];
      if (is_array($permissions) && (($permissions['packages'] ?? null) === 'write')) {
        $jobsWithPackagesWrite[] = $jobId;
      }
    }
    $this->assertSame(
      ['image'],
      $jobsWithPackagesWrite,
      'packages: write must be granted to EXACTLY one job -- image, which alone pushes to GHCR. No other job '.
      'needs it, and it must never silently spread to a job that does not push an image.'
    );
  }
}
