// =============================================================================
// staging-dispatch.test.mjs — coverage for the M4 waiver cross-repo sender
// =============================================================================
//
// Run with:  node --test scripts/staging-dispatch.test.mjs
// (No npm dependencies: node: builtins only, exactly like the script itself.)
//
// Every network call, every clock read and every printed line is injected, so
// the whole retry schedule (225 virtual seconds) runs in milliseconds and the
// assertions can be exact rather than "roughly".
//
// The bar these tests are written to: each one must DIE under a deliberate
// mutation of the thing it claims to pin (see the F3 report's mutation ledger).
// Assertions are therefore about observable behaviour — which requests went
// out, in what order, with which body, after how much virtual time, and what
// did NOT reach the log — not about internal shapes.
//
// The two M4 upgrades get the most adversarial coverage, because they are the
// only behaviour that is NOT inherited live-proven from the M3 wizard sender:
//   * the run-name predicate  (describe "M4 upgrade 1 …")
//   * non-transient fail-fast (describe "M4 upgrade 2 …")
// =============================================================================

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    ADMIN_REPO,
    ADMIN_WORKFLOW_FILE,
    DEFAULT_BACKOFF_WAITS,
    DISPATCH_SKEW_SECONDS,
    EVENT_TYPE,
    EXIT_FAILED,
    EXIT_OK,
    EXIT_USAGE,
    GHCR_IMAGE_RE,
    NON_TRANSIENT_SEND_STATUSES,
    RUN_PAGE_SIZE,
    RUN_TITLE_MARKER,
    SAFE_TOKEN_RE,
    backoffWaits,
    classifyRuns,
    describeRun,
    dispatchPayload,
    dispatchUrl,
    dispatchWithRetry,
    isImageReference,
    isNonTransientSendStatus,
    isSafeToken,
    isSha40,
    main,
    parseArgs,
    runTitleCarriesMarker,
    runsUrl,
    selectRun,
    writeStepSummary
} from './staging-dispatch.mjs';

// --- fixtures ----------------------------------------------------------------

const GOOD_SHA = '07035db1c2d3e4f5061728394a5b6c7d8e9f0a1b';
const OTHER_SHA = 'c95bdcd0102030405060708090a0b0c0d0e0f102';
const GOOD_IMAGE = `ghcr.io/sneakygriff/waiver-system-v2:${GOOD_SHA}`;
const PAT = 'ghp_supersecretpattoken0000000000000000';
const START_MS = Date.parse('2026-08-15T02:00:00.000Z');

/**
 * A GitHub Actions log line can be forged with a newline; this is the payload
 * we must never see echoed.
 *
 * Every character here EXCEPT the newline is inside SAFE_TOKEN_RE's own
 * charset, on purpose: a fixture that also carried a space or an `=` would
 * still be rejected by a charset that had been widened to allow newlines, and
 * the test would pass while the guard it claims to pin was gone.
 */
const FORGED_IMAGE = `ghcr.io/sneakygriff/waiver-system-v2:${GOOD_SHA}\n::error::forged-by-payload`;
const FORGED_MARKER = 'forged-by-payload';

function clock(startMs = START_MS) {
    let current = startMs;
    return {
        now: () => current,
        sleep: async (ms) => {
            current += ms;
        },
        elapsed: () => current - startMs
    };
}

function recorder() {
    const lines = [];
    const sink = (line) => lines.push(String(line));
    sink.lines = lines;
    sink.text = () => lines.join('\n');
    return sink;
}

function jsonResponse(status, body) {
    return { status, json: async () => body };
}

/** `routes` is called with (url, init, callNumber) and returns a response, or throws to simulate a transport failure. */
function fakeFetch(routes) {
    const calls = [];
    const fetchImpl = async (url, init = {}) => {
        calls.push({ url: String(url), init });
        return routes(String(url), init, calls.length);
    };
    fetchImpl.calls = calls;
    fetchImpl.urls = () => calls.map((call) => call.url);
    fetchImpl.sends = () => calls.filter((call) => call.init?.method === 'POST');
    fetchImpl.polls = () => calls.filter((call) => call.init?.method !== 'POST');
    return fetchImpl;
}

function harness({ routes, env = {}, startMs = START_MS }) {
    const time = clock(startMs);
    const stdout = recorder();
    const stderr = recorder();
    const fetchImpl = fakeFetch(routes);
    const summary = [];
    return {
        time,
        stdout,
        stderr,
        fetch: fetchImpl,
        summary,
        output: () => `${stdout.text()}\n${stderr.text()}`,
        deps: {
            fetch: fetchImpl,
            sleep: time.sleep,
            now: time.now,
            env,
            stdout,
            stderr,
            appendFile: async (path, data) => {
                summary.push({ path, data });
            }
        }
    };
}

/** A run of the admin staging.yml carrying M4's run-name — i.e. OURS. */
const ourRun = (overrides = {}) => ({
    id: 31900112233,
    html_url: 'https://github.com/sneakygriff/bookingsystemv2/actions/runs/31900112233',
    display_title: `Staging — dispatch: ${EVENT_TYPE} @${GOOD_SHA}`,
    status: 'queued',
    conclusion: null,
    created_at: '2026-08-15T02:00:10Z',
    ...overrides
});

/** A wizard dispatch run: same event, same workflow, same window — NOT ours. */
const wizardRun = (overrides = {}) => ({
    id: 31900445566,
    html_url: 'https://github.com/sneakygriff/bookingsystemv2/actions/runs/31900445566',
    display_title: 'Staging — dispatch: wizard-staging-deployed @deadbeef',
    status: 'in_progress',
    conclusion: null,
    created_at: '2026-08-15T02:00:12Z',
    ...overrides
});

/** What the admin repo looked like BEFORE M4's run-name landed. */
const untitledRun = (overrides = {}) => ({ ...wizardRun(), id: 31900778899, display_title: 'Staging', ...overrides });

const runsBody = (...runs) => ({ total_count: runs.length, workflow_runs: runs });

const dispatchArgs = ['dispatch', '--image-tag', GOOD_IMAGE, '--sha', GOOD_SHA];

/** Routes: POST → `sendStatus`; GET → `runs` page (defaults to "our run"). */
function routesFor({ sendStatus = 204, page = () => runsBody(ourRun()) } = {}) {
    return (url, init, call) => {
        if (init?.method === 'POST') {
            const status = typeof sendStatus === 'function' ? sendStatus(call) : sendStatus;
            if (status === 'throw') throw new Error('socket hang up (https://api.github.com/…)');
            return { status };
        }
        const body = page(call);
        if (body === 'throw') throw new Error('socket hang up');
        return jsonResponse(200, body);
    };
}

// =============================================================================

describe('cross-repo contract constants', () => {
    it('pins exactly what the admin repo listens for', () => {
        assert.equal(EVENT_TYPE, 'waiver-staging-deployed');
        assert.equal(ADMIN_REPO, 'sneakygriff/bookingsystemv2');
        assert.equal(ADMIN_WORKFLOW_FILE, 'staging.yml');
    });

    it('derives the run-name marker from the admin run-name expression, anchored on the trailing " @"', () => {
        // Admin staging.yml (M4/A1):
        //   format('Staging — dispatch: {0} @{1}', github.event.action, github.sha)
        assert.equal(RUN_TITLE_MARKER, 'dispatch: waiver-staging-deployed @');
        assert.equal(`Staging — dispatch: ${EVENT_TYPE} @abc`.includes(RUN_TITLE_MARKER), true);
    });

    it('builds the admin URLs the workflow-side contract describes', () => {
        assert.equal(dispatchUrl(), 'https://api.github.com/repos/sneakygriff/bookingsystemv2/dispatches');
        assert.equal(runsUrl(), `https://api.github.com/repos/sneakygriff/bookingsystemv2/actions/workflows/staging.yml/runs?event=repository_dispatch&per_page=${RUN_PAGE_SIZE}`);
        assert.equal(RUN_PAGE_SIZE, 10);
    });

    it('sends the client_payload key the admin manifest job reads', () => {
        // staging.yml: PAYLOAD_WAIVER_IMAGE_TAG: ${{ github.event.client_payload.waiverImageTag }}
        assert.deepEqual(dispatchPayload(GOOD_IMAGE, GOOD_SHA), {
            event_type: 'waiver-staging-deployed',
            client_payload: { waiverImageTag: GOOD_IMAGE, forkSha: GOOD_SHA }
        });
    });

    it('keeps the runbook §7 retry budget', () => {
        assert.deepEqual([...DEFAULT_BACKOFF_WAITS], [20, 30, 45, 60, 70]);
        assert.equal(DEFAULT_BACKOFF_WAITS.reduce((a, b) => a + b, 0), 225);
        assert.equal(DISPATCH_SKEW_SECONDS, 5);
    });
});

describe('payload charset validation', () => {
    it('refuses every character the admin guard refuses', () => {
        for (const value of [`${GOOD_IMAGE}\n`, `${GOOD_IMAGE}\r\n`, `${GOOD_IMAGE}\ninjected=1`, 'ghcr.io/a b', 'ghcr.io/a%0Ab', 'ghcr.io/a"b', "ghcr.io/a'b", 'ghcr.io/a`b', 'ghcr.io/a$b', 'ghcr.io/a;b', 'ghcr.io/a|b', 'ghcr.io/a<b', 'ghcr.io/ș', '', 'ghcr.io/a\tb']) {
            assert.equal(SAFE_TOKEN_RE.test(value), false, `expected SAFE_TOKEN_RE to reject ${JSON.stringify(value)}`);
        }
    });

    it('accepts the tokens it exists to allow', () => {
        for (const value of [GOOD_IMAGE, 'ghcr.io/sneakygriff/waiver-system-v2:sha-abc123', 'https://github.com/sneakygriff/bookingsystemv2/actions/runs/1', 'a@b', 'A-Z_0.9/:@-']) {
            assert.equal(SAFE_TOKEN_RE.test(value), true, `expected SAFE_TOKEN_RE to accept ${JSON.stringify(value)}`);
        }
    });

    it('treats a ghcr.io/ prefix as no proof at all', () => {
        // Every prefix test is newline-blind; the charset check is the
        // single-line proof (this is the exact hole the wizard sender's
        // `dpl_*` guard documented).
        assert.equal(FORGED_IMAGE.startsWith('ghcr.io/'), true);
        assert.equal(isImageReference(FORGED_IMAGE), false);
        assert.equal(isSafeToken(FORGED_IMAGE), false);
        assert.equal(isImageReference(GOOD_IMAGE), true);
    });

    it('accepts only a ghcr.io <owner>/<name>:<tag> reference', () => {
        assert.equal(isImageReference(GOOD_IMAGE), true);
        assert.equal(isImageReference('ghcr.io/sneakygriff/waiver-system-v2'), false, 'no tag');
        assert.equal(isImageReference('ghcr.io/waiver-system-v2:abc'), false, 'no owner path segment');
        assert.equal(isImageReference('docker.io/sneakygriff/waiver-system-v2:abc'), false, 'the admin discovery step refuses non-ghcr.io images');
        assert.equal(isImageReference('ghcr.io/SneakyGriff/waiver-system-v2:abc'), false, 'GHCR paths are lowercase');
        assert.equal(isImageReference(`ghcr.io/sneakygriff/waiver-system-v2:${GOOD_SHA}\n`), false);
        assert.equal(isImageReference(42), false);
        assert.equal(isImageReference(undefined), false);
        assert.equal(GHCR_IMAGE_RE.test(GOOD_IMAGE), true);
    });

    it('accepts only 40 lowercase hex digits as a sha', () => {
        assert.equal(isSha40(GOOD_SHA), true);
        assert.equal(isSha40(GOOD_SHA.slice(0, 39)), false);
        assert.equal(isSha40(`${GOOD_SHA}a`), false);
        assert.equal(isSha40(GOOD_SHA.toUpperCase()), false);
        assert.equal(isSha40(`${GOOD_SHA}\n`), false);
        assert.equal(isSha40(`${GOOD_SHA}\ninjected=1`), false);
        assert.equal(isSha40('g'.repeat(40)), false);
    });

    it('parses the backoff override, and refuses a malformed one rather than falling back', () => {
        assert.deepEqual(backoffWaits(undefined), [20, 30, 45, 60, 70]);
        assert.deepEqual(backoffWaits('   '), [20, 30, 45, 60, 70]);
        assert.deepEqual(backoffWaits('1,2,3'), [1, 2, 3]);
        for (const bad of ['0', '-1', 'a', '1,,2', '1,x']) {
            assert.throws(() => backoffWaits(bad), /DISPATCH_BACKOFF_WAITS/);
        }
    });
});

describe('argument parsing', () => {
    it('accepts the CI invocation in both flag spellings', () => {
        assert.deepEqual(parseArgs(dispatchArgs), { command: 'dispatch', options: { 'image-tag': GOOD_IMAGE, sha: GOOD_SHA } });
        assert.deepEqual(parseArgs(['dispatch', `--image-tag=${GOOD_IMAGE}`, `--sha=${GOOD_SHA}`]), {
            command: 'dispatch',
            options: { 'image-tag': GOOD_IMAGE, sha: GOOD_SHA }
        });
    });

    it('refuses anything else, and never echoes a value', () => {
        for (const [argv, pattern] of [
            [[], /no subcommand/],
            [['await-alias'], /unknown subcommand/],
            [['dispatch', '--nope', 'x'], /--nope is not a flag/],
            [['dispatch', 'positional'], /--flag values only/],
            [['dispatch', '--image-tag'], /--image-tag needs a value/],
            [['dispatch', '--image-tag', 'a', '--image-tag', 'b'], /given more than once/],
            [['dispatch', '--Bad', 'x'], /flag names must match/]
        ]) {
            assert.throws(() => parseArgs(argv), pattern);
        }
        assert.deepEqual(parseArgs(['--help']), { command: 'help', options: {} });
    });
});

describe('M4 upgrade 1 — the run-name predicate is what makes a run OURS', () => {
    it('recognises only a run whose display_title names this event type', () => {
        assert.equal(runTitleCarriesMarker(ourRun()), true);
        assert.equal(runTitleCarriesMarker(wizardRun()), false);
        assert.equal(runTitleCarriesMarker(untitledRun()), false);
        assert.equal(runTitleCarriesMarker({}), false);
        assert.equal(runTitleCarriesMarker({ display_title: null }), false);
        assert.equal(runTitleCarriesMarker(null), false);
    });

    it('does not false-match a dispatch type that merely EXTENDS this one as a string — the unanchored-substring class the trailing " @" anchor closes', () => {
        // Under the OLD, unanchored marker ("dispatch: waiver-staging-deployed",
        // no trailing " @") this title WOULD have matched, because that string
        // is a genuine substring of "dispatch: waiver-staging-deployed-v2 @...".
        // Eng T6.
        const extendedTypeRun = ourRun({ display_title: `Staging — dispatch: ${EVENT_TYPE}-v2 @${GOOD_SHA}` });
        assert.equal(`Staging — dispatch: ${EVENT_TYPE}-v2 @${GOOD_SHA}`.includes(`dispatch: ${EVENT_TYPE}`), true, "sanity: the OLD unanchored marker WOULD have matched this title");
        assert.equal(runTitleCarriesMarker(extendedTypeRun), false, 'the anchored marker must refuse a dispatch type that only extends ours as a string');

        const sentAt = Date.parse('2026-08-15T02:00:00Z');
        assert.equal(selectRun([extendedTypeRun], sentAt), null, 'and it must never be selected as delivery either');
    });

    it('selects our run and ignores a wizard dispatch in the same window', () => {
        const sentAt = Date.parse('2026-08-15T02:00:00Z');
        const { run, unmarkedInWindow } = classifyRuns([wizardRun(), ourRun()], sentAt);
        assert.equal(run?.id, ourRun().id);
        assert.equal(unmarkedInWindow, 1);
    });

    it('reports NOTHING ours when only a wizard dispatch landed — the M3 ambiguity, closed', () => {
        const sentAt = Date.parse('2026-08-15T02:00:00Z');
        const { run, unmarkedInWindow } = classifyRuns([wizardRun(), untitledRun()], sentAt);
        assert.equal(run, null);
        assert.equal(unmarkedInWindow, 2);
        assert.equal(selectRun([wizardRun()], sentAt), null);
    });

    it('still refuses a run older than the accepted send, marker or not', () => {
        const sentAt = Date.parse('2026-08-15T02:00:30Z');
        assert.equal(selectRun([ourRun({ created_at: '2026-08-15T02:00:29Z' })], sentAt), null);
        assert.equal(selectRun([ourRun({ created_at: '2026-08-15T02:00:30Z' })], sentAt)?.id, ourRun().id);
    });

    it('takes the NEWEST of several of our own runs, and refuses unusable input', () => {
        const sentAt = Date.parse('2026-08-15T02:00:00Z');
        const newest = ourRun({ id: 999, created_at: '2026-08-15T02:00:40Z' });
        assert.equal(selectRun([ourRun(), newest, ourRun({ id: 3, created_at: '2026-08-15T02:00:20Z' })], sentAt)?.id, 999);
        assert.equal(selectRun([ourRun({ created_at: 'not-a-date' })], sentAt), null);
        assert.equal(selectRun([null, 'x', 7], sentAt), null);
        assert.equal(selectRun('not an array', sentAt), null);
        // No accepted send ⇒ no attribution is possible, even for a marked run.
        assert.equal(selectRun([ourRun()], null), null);
        assert.equal(selectRun([ourRun()], undefined), null);
    });

    it('never confirms delivery from a wizard dispatch, across the FULL schedule', async () => {
        // The whole point of upgrade 1: five sends, five polls, a wizard run
        // visible in every one of them, and still a red result.
        const h = harness({ routes: routesFor({ page: () => runsBody(wizardRun(), untitledRun()) }) });
        const result = await dispatchWithRetry({ ...h.deps, token: PAT, imageTag: GOOD_IMAGE, sha: GOOD_SHA });

        assert.equal(result.ok, false);
        assert.equal(result.attempts, 5);
        assert.equal(result.accepted, true);
        assert.equal(result.unmarkedInWindow, 2);
        assert.equal(h.fetch.sends().length, 5);
        assert.equal(h.fetch.polls().length, 5);
        assert.equal(h.time.elapsed(), 225_000);
        assert.match(h.stdout.text(), /none was run-named "dispatch: waiver-staging-deployed @"/);
    });
});

describe('M4 upgrade 2 — non-transient sends fail fast', () => {
    it('classifies exactly 401/403/404/422 as non-transient', () => {
        assert.deepEqual([...NON_TRANSIENT_SEND_STATUSES], [401, 403, 404, 422]);
        for (const status of [401, 403, 404, 422]) assert.equal(isNonTransientSendStatus(status), true);
        for (const status of [0, 200, 204, 400, 429, 500, 502, 503]) assert.equal(isNonTransientSendStatus(status), false);
    });

    for (const status of [401, 403, 404, 422]) {
        it(`abandons the schedule immediately on HTTP ${status} (one request, no waiting, no poll)`, async () => {
            const h = harness({ routes: routesFor({ sendStatus: status }) });
            const result = await dispatchWithRetry({ ...h.deps, token: PAT, imageTag: GOOD_IMAGE, sha: GOOD_SHA });

            assert.equal(result.ok, false);
            assert.equal(result.attempts, 1);
            assert.equal(result.accepted, false);
            assert.equal(result.nonTransientStatus, status);
            assert.equal(h.fetch.calls.length, 1, 'exactly one request: the refused send. No poll — nothing of ours is queued.');
            assert.equal(h.fetch.polls().length, 0);
            assert.equal(h.time.elapsed(), 0, 'not one second of the 225s budget is spent on a refusal no retry can fix');
            assert.match(h.stdout.text(), /NON-TRANSIENT refusal/);
        });
    }

    it('still spends the full schedule on a 5xx — those ARE transient', async () => {
        const h = harness({ routes: routesFor({ sendStatus: 503, page: () => runsBody() }) });
        const result = await dispatchWithRetry({ ...h.deps, token: PAT, imageTag: GOOD_IMAGE, sha: GOOD_SHA });

        assert.equal(result.ok, false);
        assert.equal(result.attempts, 5);
        assert.equal(result.nonTransientStatus, 0);
        assert.equal(h.fetch.sends().length, 5);
        assert.equal(h.fetch.polls().length, 0, 'no send was ever accepted, so no run may be attributed to us');
        assert.equal(h.time.elapsed(), 225_000);
    });

    it('treats 429 and a transport failure as transient, and recovers on a later send', async () => {
        const h = harness({
            routes: routesFor({
                sendStatus: (call) => (call === 1 ? 429 : call === 3 ? 'throw' : 204),
                page: () => runsBody(ourRun({ created_at: '2026-08-15T02:00:55Z' }))
            })
        });
        const result = await dispatchWithRetry({ ...h.deps, token: PAT, imageTag: GOOD_IMAGE, sha: GOOD_SHA });

        // call 1 = send 429 (t=0) → wait 20 → no accepted send, no poll
        // call 2 = send 204 (t=20) → wait 30 → call 3 is the POLL at t=50
        assert.equal(result.ok, true);
        assert.equal(result.attempts, 2);
        assert.equal(h.time.elapsed(), 50_000);
        assert.equal(result.run.id, ourRun().id);
    });

    it('having ALREADY had a send accepted, a later refusal still honours the poll it waited for, then stops', async () => {
        // The AC4.5 revoked-token drill: the first POST lands, the credential
        // dies, and the events already queued may still produce our run.
        const h = harness({
            routes: routesFor({ sendStatus: (call) => (call === 1 ? 204 : 403), page: () => runsBody() })
        });
        const result = await dispatchWithRetry({ ...h.deps, token: PAT, imageTag: GOOD_IMAGE, sha: GOOD_SHA });

        assert.equal(result.ok, false);
        assert.equal(result.accepted, true, 'a send WAS accepted, so polling was legitimate');
        assert.equal(result.nonTransientStatus, 403);
        assert.equal(result.attempts, 2);
        assert.equal(h.fetch.sends().length, 2, 'sends 3–5 abandoned');
        assert.equal(h.fetch.polls().length, 2, 'both polls the accepted send earned were performed');
        assert.equal(h.time.elapsed(), 50_000);
        assert.match(h.stdout.text(), /stopping rather than polling a queue nothing more will be added to/);
    });

    it('and if that final poll DOES find our run, the refusal never becomes a failure', async () => {
        const h = harness({
            routes: routesFor({
                sendStatus: (call) => (call === 1 ? 204 : 401),
                page: (call) => (call <= 2 ? runsBody() : runsBody(ourRun({ created_at: '2026-08-15T02:00:55Z' })))
            })
        });
        const result = await dispatchWithRetry({ ...h.deps, token: PAT, imageTag: GOOD_IMAGE, sha: GOOD_SHA });

        assert.equal(result.ok, true);
        assert.equal(result.attempts, 2);
        assert.equal(result.nonTransientStatus, 401);
    });
});

describe('the send/poll schedule (M3 contract, unchanged)', () => {
    it('sends the documented request, then proves a run appeared', async () => {
        const h = harness({ routes: routesFor() });
        const result = await dispatchWithRetry({ ...h.deps, token: PAT, imageTag: GOOD_IMAGE, sha: GOOD_SHA });

        assert.equal(result.ok, true);
        assert.equal(result.attempts, 1);
        assert.equal(h.time.elapsed(), 20_000, 'one wait of 20s before the first poll');
        assert.equal(h.fetch.calls.length, 2);

        const [send, poll] = h.fetch.calls;
        assert.equal(send.url, dispatchUrl());
        assert.equal(send.init.method, 'POST');
        assert.deepEqual(JSON.parse(send.init.body), dispatchPayload(GOOD_IMAGE, GOOD_SHA));
        assert.equal(send.init.headers.Authorization, `Bearer ${PAT}`);
        assert.equal(send.init.headers['X-GitHub-Api-Version'], '2022-11-28');
        assert.equal(poll.url, runsUrl());
        assert.notEqual(poll.init.method, 'POST');

        for (const call of h.fetch.calls) {
            assert.ok(call.init.signal, 'every request carries a deadline; a hung read must not eat the retry budget');
        }
    });

    it('stamps the send time from BEFORE the POST, minus the skew, and never moves it forward', async () => {
        // A run created 3s before our POST (GitHub's clock, 1s granularity) is
        // still ours; one created 30s before is not.
        const h = harness({
            routes: routesFor({
                sendStatus: 204,
                page: (call) => (call === 2 ? runsBody(ourRun({ created_at: '2026-08-15T01:59:57Z' })) : runsBody(ourRun({ created_at: '2026-08-15T02:00:30Z' })))
            })
        });
        const result = await dispatchWithRetry({ ...h.deps, token: PAT, imageTag: GOOD_IMAGE, sha: GOOD_SHA });
        assert.equal(result.ok, true);
        assert.equal(result.sentAtMs, START_MS - DISPATCH_SKEW_SECONDS * 1000);

        const tooOld = harness({ routes: routesFor({ page: () => runsBody(ourRun({ created_at: '2026-08-15T01:59:30Z' })) }) });
        const missed = await dispatchWithRetry({ ...tooOld.deps, token: PAT, imageTag: GOOD_IMAGE, sha: GOOD_SHA });
        assert.equal(missed.ok, false, '30s before our POST is somebody else’s run, skew or no skew');
    });

    it('never polls while no send has been accepted', async () => {
        const h = harness({ routes: routesFor({ sendStatus: 500, page: () => runsBody(ourRun()) }) });
        const result = await dispatchWithRetry({ ...h.deps, token: PAT, imageTag: GOOD_IMAGE, sha: GOOD_SHA });
        assert.equal(result.ok, false);
        assert.equal(h.fetch.polls().length, 0);
        assert.match(h.stdout.text(), /nothing to attribute/);
    });

    it('keeps retrying when the RUN LOOKUP fails, and delivers once it recovers', async () => {
        let pollCall = 0;
        const h = harness({
            routes: (url, init) => {
                if (init?.method === 'POST') return { status: 204 };
                pollCall += 1;
                if (pollCall === 1) return jsonResponse(502, {});
                if (pollCall === 2) throw new Error('socket hang up');
                return jsonResponse(200, runsBody(ourRun({ created_at: '2026-08-15T02:01:35Z' })));
            }
        });
        const result = await dispatchWithRetry({ ...h.deps, token: PAT, imageTag: GOOD_IMAGE, sha: GOOD_SHA });
        assert.equal(result.ok, true);
        assert.equal(result.attempts, 3);
        assert.equal(h.time.elapsed(), 95_000, '20 + 30 + 45');
    });

    it('honours a DISPATCH_BACKOFF_WAITS override end to end', async () => {
        const h = harness({ routes: routesFor({ page: () => runsBody() }), env: { ADMIN_DISPATCH_PAT: PAT, DISPATCH_BACKOFF_WAITS: '1,2' } });
        const code = await main(dispatchArgs, h.deps);
        assert.equal(code, EXIT_FAILED);
        assert.equal(h.fetch.sends().length, 2);
        assert.equal(h.time.elapsed(), 3_000);
    });
});

describe('log hygiene — nothing external is echoed unproven', () => {
    it('never echoes an API-supplied display_title, even when it IS ours', async () => {
        // The title is free text (spaces, an em dash) that can carry a newline;
        // only the BOOLEAN "did it carry the marker" may ever be reported.
        const forgedTitle = `Staging — dispatch: ${EVENT_TYPE} @${GOOD_SHA}\n::error::${FORGED_MARKER}`;
        const h = harness({ routes: routesFor({ page: () => runsBody(ourRun({ display_title: forgedTitle })) }), env: { ADMIN_DISPATCH_PAT: PAT } });
        const code = await main(dispatchArgs, h.deps);

        assert.equal(code, EXIT_OK, 'the run is still ours — the marker is present');
        assert.equal(h.output().includes(FORGED_MARKER), false, 'the forged title reached the log');
        assert.equal(h.summary.some((entry) => String(entry.data).includes(FORGED_MARKER)), false);
    });

    it('withholds a run id / URL that is not safe to print, and still counts the delivery', async () => {
        const h = harness({
            routes: routesFor({ page: () => runsBody(ourRun({ id: `1\n::error::${FORGED_MARKER}`, html_url: `https://github.com/x\n::error::${FORGED_MARKER}` })) }),
            env: { ADMIN_DISPATCH_PAT: PAT }
        });
        const code = await main(dispatchArgs, h.deps);

        assert.equal(code, EXIT_OK);
        assert.equal(h.output().includes(FORGED_MARKER), false);
        assert.match(h.output(), /id withheld/);
        assert.match(h.output(), /run URL withheld/);
        assert.deepEqual(describeRun({ id: 5, html_url: 'http://evil.example/x', status: 'queued', conclusion: null }), {
            id: '5',
            url: null,
            status: 'queued',
            conclusion: null
        });
    });

    it('never echoes the PAT, on any path', async () => {
        for (const routes of [routesFor(), routesFor({ sendStatus: 401 }), routesFor({ page: () => runsBody(wizardRun()) })]) {
            const h = harness({ routes, env: { ADMIN_DISPATCH_PAT: PAT } });
            await main(dispatchArgs, h.deps);
            assert.equal(h.output().includes(PAT), false);
        }
    });

    it('refuses a forged --image-tag before any network call at all', async () => {
        const h = harness({ routes: routesFor(), env: { ADMIN_DISPATCH_PAT: PAT } });
        const code = await main(['dispatch', '--image-tag', FORGED_IMAGE, '--sha', GOOD_SHA], h.deps);

        assert.equal(code, EXIT_USAGE);
        assert.equal(h.fetch.calls.length, 0, 'nothing was sent');
        assert.equal(h.output().includes(FORGED_MARKER), false);
        assert.match(h.output(), /--image-tag must be/);
    });
});

describe('main() — validation, exit codes and the operator-facing errors', () => {
    it('refuses a payload whose image tag and sha describe different builds', async () => {
        const h = harness({ routes: routesFor(), env: { ADMIN_DISPATCH_PAT: PAT } });
        const code = await main(['dispatch', '--image-tag', GOOD_IMAGE, '--sha', OTHER_SHA], h.deps);

        assert.equal(code, EXIT_USAGE);
        assert.equal(h.fetch.calls.length, 0);
        assert.match(h.output(), /is not tagged with --sha/);
    });

    it('refuses a missing/invalid flag, a missing PAT and a malformed backoff — always before sending', async () => {
        const cases = [
            [['dispatch', '--sha', GOOD_SHA], { ADMIN_DISPATCH_PAT: PAT }, /--image-tag is required/],
            [['dispatch', '--image-tag', GOOD_IMAGE], { ADMIN_DISPATCH_PAT: PAT }, /--sha is required/],
            [['dispatch', '--image-tag', GOOD_IMAGE, '--sha', 'nope'], { ADMIN_DISPATCH_PAT: PAT }, /--sha must be/],
            [['dispatch', '--image-tag', 'ghcr.io/x:1', '--sha', GOOD_SHA], { ADMIN_DISPATCH_PAT: PAT }, /--image-tag must be/],
            [dispatchArgs, {}, /ADMIN_DISPATCH_PAT is not set/],
            [dispatchArgs, { ADMIN_DISPATCH_PAT: PAT, DISPATCH_BACKOFF_WAITS: 'soon' }, /DISPATCH_BACKOFF_WAITS/]
        ];
        for (const [argv, env, pattern] of cases) {
            const h = harness({ routes: routesFor(), env });
            const code = await main(argv, h.deps);
            assert.equal(code, EXIT_USAGE, `expected a usage exit for ${JSON.stringify(argv)}`);
            assert.equal(h.fetch.calls.length, 0, `expected NOTHING to be sent for ${JSON.stringify(argv)}`);
            assert.match(h.output(), pattern);
        }
    });

    it('exits 0 and records the pin in the step summary on delivery', async () => {
        const h = harness({ routes: routesFor(), env: { ADMIN_DISPATCH_PAT: PAT, GITHUB_STEP_SUMMARY: '/tmp/summary.md' } });
        const code = await main(dispatchArgs, h.deps);

        assert.equal(code, EXIT_OK);
        assert.equal(h.summary.length, 1);
        assert.equal(h.summary[0].path, '/tmp/summary.md');
        assert.match(h.summary[0].data, new RegExp(`${EVENT_TYPE}`));
        assert.ok(h.summary[0].data.includes(GOOD_IMAGE), 'the summary names the exact pinned reference');
        assert.match(h.summary[0].data, /admin run 31900112233/);
    });

    it('a step-summary write that fails never turns a delivered dispatch red', async () => {
        const h = harness({ routes: routesFor(), env: { ADMIN_DISPATCH_PAT: PAT, GITHUB_STEP_SUMMARY: '/tmp/summary.md' } });
        h.deps.appendFile = async () => {
            throw new Error('EACCES');
        };
        assert.equal(await main(dispatchArgs, h.deps), EXIT_OK);
        assert.equal(await writeStepSummary({ appendFile: async () => {}, path: '', line: 'x' }), false);
    });

    it('names the stale-run-name cause when runs appeared but none was ours', async () => {
        const h = harness({ routes: routesFor({ page: () => runsBody(untitledRun()) }), env: { ADMIN_DISPATCH_PAT: PAT } });
        const code = await main(dispatchArgs, h.deps);

        assert.equal(code, EXIT_FAILED);
        assert.match(h.stderr.text(), /does not yet carry M4's `run-name:` expression/);
        assert.match(h.stderr.text(), /run-name marker "dispatch: waiver-staging-deployed @"/);
    });

    it('names the credential cause on a non-transient refusal', async () => {
        const h = harness({ routes: routesFor({ sendStatus: 403 }), env: { ADMIN_DISPATCH_PAT: PAT } });
        const code = await main(dispatchArgs, h.deps);

        assert.equal(code, EXIT_FAILED);
        assert.match(h.stderr.text(), /refused with HTTP 403, which no retry can change/);
        assert.match(h.stderr.text(), /No send was ever accepted/);
        assert.equal(h.time.elapsed(), 0);
    });

    it('prints usage for --help without touching the network', async () => {
        const h = harness({ routes: routesFor(), env: {} });
        assert.equal(await main(['--help'], h.deps), EXIT_OK);
        assert.equal(h.fetch.calls.length, 0);
        assert.match(h.stdout.text(), /--image-tag <ghcr.io\/owner\/name:sha>/);
    });
});
