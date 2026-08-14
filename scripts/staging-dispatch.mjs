#!/usr/bin/env node
// =============================================================================
// staging-dispatch.mjs — the waiver fork's cross-repo staging sender (CI/CD M4)
// =============================================================================
//
// ONE SUBCOMMAND, ONE JOB.
//
//   dispatch   Tell the admin repo that a new waiver-fork image is live on
//              Railway staging, by sending a `waiver-staging-deployed`
//              repository_dispatch and then PROVING that OUR run appeared.
//              AC4.5 "dispatch fidelity, failure propagation".
//
// This is a port of the wizard repo's proven M3 sender
// (vr-studio-web-rework/scripts/staging-dispatch.mjs @ master a10b78c) with the
// wizard-only `await-alias` subcommand removed (there is no Vercel alias in
// this repo) and the two M4-ledger upgrades added — see "WHAT M4 ADDS" below.
// Everything else — the 5-attempt schedule, the [20,30,45,60,70]s waits, the
// "delivery = run EXISTENCE at or after the first ACCEPTED send" predicate, the
// 5 s skew, the withhold-don't-echo log discipline — is deliberately unchanged,
// because it is live-proven behaviour and not this milestone's variable.
//
// WHY THE DISPATCH POLLS INSTEAD OF TRUSTING THE 204.
// `POST /repos/{repo}/dispatches` answers 204 No Content the moment GitHub
// ACCEPTS the event — not when a workflow run exists. A 204 is compatible with
// no run at all: the workflow may not list the event type, the workflow file
// may not be on the default branch, or the run may be displaced while pending
// in its concurrency group (`staging-dispatch` in the admin repo — a dispatch
// that lands while another dispatch is still pending can lose its slot before
// it ever executes). The operator runbook's §7 row for exactly this
// ("repository_dispatch senders (M3 wizard CI, M4 waiver CI) need their own
// retry logic") is explicit: treat this as an at-least-once webhook — poll for
// the resulting run and re-dispatch with backoff if none appears.
//
// WHAT COUNTS AS DELIVERED (D-m3-5, tightened by M4's upgrade 1 below). An
// `event=repository_dispatch` run of the admin `staging.yml`, WHOSE RUN-NAME
// NAMES OUR EVENT TYPE, existing at or after the first send GitHub ACCEPTED
// (2xx), less a small clock-skew allowance. Its status and conclusion are
// deliberately NOT consulted: a dispatch-triggered admin run is EXPECTED to end
// red while the waiver staging service is only half-provisioned. That accepted
// red is not a delivery failure and must not make this sender retry (which
// would only pile up more red runs).
//
// WHAT NEVER COUNTS: any run at all, while no send has been accepted. If every
// POST is refused — a dead PAT answering 401 five times — then none of our
// events is in GitHub's queue, so no run can be OURS: the poll is skipped
// outright and the job fails red.
//
// ── WHAT M4 ADDS (the two ledger items M3 deferred to this milestone) ───────
//
//   1. RUN-NAME PREDICATE. The M3 sender could only filter server-side by
//      `event=repository_dispatch`, and its own comments called a wizard
//      dispatch and a waiver dispatch INDISTINGUISHABLE: a wizard deploy
//      landing inside our poll window would have been reported as our delivery.
//      The admin `staging.yml` now carries a `run-name:` that embeds the
//      dispatch event type (M4 task A1), so every dispatch run's
//      `display_title` is self-identifying — `Staging — dispatch:
//      waiver-staging-deployed @<sha>`. A candidate run must carry
//      RUN_TITLE_MARKER or it is somebody else's dispatch.
//
//      CONSEQUENCE, stated plainly: this sender REQUIRES the admin default
//      branch to carry that run-name. Against an older admin `staging.yml`
//      every run is titled "Staging", nothing matches, and this job goes red
//      with a message naming that cause. That is the intended failure — a
//      sender that fell back to "any dispatch run" would silently restore the
//      very ambiguity this closes.
//
//   2. NON-TRANSIENT FAIL-FAST. 401/403/404/422 on the dispatch POST are
//      refusals no retry can change (bad/expired/mis-scoped PAT; wrong or
//      invisible repo; a payload GitHub will not parse). M3 retried all five
//      attempts against them, burning 225 s to reach the same red. Those
//      statuses now abandon the remaining sends immediately. 5xx, 429 and
//      transport failures stay transient and keep their full schedule.
//
//      One refinement the AC4.5 drill depends on: if some EARLIER send was
//      already accepted (the operator revokes the PAT mid-run), the events in
//      GitHub's queue can still produce our run — so that case abandons the
//      remaining SENDS but still performs the one poll it already waited for,
//      then stops. Only "nothing was ever accepted" aborts with no poll at all.
//
// SECURITY POSTURE (mirrors the admin `staging.yml` guards).
//   * Every value that comes back from an API is untrusted text. This script's
//     stdout is read by a GitHub Actions step, where a newline can forge
//     `::workflow commands::` and `key=value` step outputs. So an API-sourced
//     value is echoed ONLY after it proves it is a single-line safe token
//     (SAFE_TOKEN_RE); otherwise it is WITHHELD and described, never printed.
//   * `display_title` is NEVER echoed, not even when it matches: it is free
//     text containing spaces and an em dash, so it cannot pass SAFE_TOKEN_RE at
//     all. Only the BOOLEAN "did it carry the marker" is ever reported.
//   * A `ghcr.io/` prefix test is NOT proof of a single line — a prefix match
//     says nothing about the rest of the string, newlines included. The charset
//     check is.
//   * ADMIN_DISPATCH_PAT is never logged, and API error bodies are never
//     echoed — status codes only.
//   * CLI values are validated BEFORE the first network call, so a malformed
//     payload can never reach the admin repo's dispatch endpoint at all.
//
// TESTABILITY. Every unit of behaviour is an exported function taking its
// effects (`fetch`, `sleep`, `now`, `stdout`, `stderr`, `appendFile`) as
// injected dependencies; `main()` at the bottom is a thin wiring layer, and the
// module only runs itself when executed directly (importing it — which
// `scripts/staging-dispatch.test.mjs` does — has no side effects).
// =============================================================================

import { appendFile as fsAppendFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';

// --- The cross-repo contract (the admin repo's staging.yml is the other half) ---

// Listed in the admin staging.yml's `repository_dispatch.types:` since M2.
export const EVENT_TYPE = 'waiver-staging-deployed';
export const ADMIN_REPO = 'sneakygriff/bookingsystemv2';
export const ADMIN_WORKFLOW_FILE = 'staging.yml';
// The admin `run-name:` is
//   format('Staging — dispatch: {0} @{1}', github.event.action, github.sha)
// so every dispatch run's display_title contains "dispatch: <event type>".
// Matching that pair (not the bare event type) is what keeps the check honest:
// the bare name could appear in a run title for unrelated reasons, the pair is
// produced by exactly one expression, in exactly one workflow.
export const RUN_TITLE_MARKER = `dispatch: ${EVENT_TYPE}`;
// The admin repo's manifest job refuses any client_payload value containing a
// character outside this set (`*[!A-Za-z0-9._/:@-]*` in staging.yml). Sending
// one would only earn a red run there, so the same rule is enforced here first.
export const SAFE_TOKEN_RE = /^[A-Za-z0-9._/:@-]+$/;

// --- Shapes and budgets ---

// The pin key the admin manifest consumes (AC2.4/AC4.4): the EXACT reference
// this run pushed. The admin's discovery step hard-fails on anything that is
// not `ghcr.io/*`, so a payload that could never be pinned is refused here
// first. Registry paths are lowercase by GHCR rule; the tag is this fork's SHA.
export const IMAGE_REGISTRY_PREFIX = 'ghcr.io/';
export const GHCR_IMAGE_RE = /^ghcr\.io\/[a-z0-9][a-z0-9._-]*(?:\/[a-z0-9][a-z0-9._-]*)+:[A-Za-z0-9][A-Za-z0-9._-]*$/;
// Lowercase-only, exactly like the admin `is_sha40()` guard: a SHA this sender
// accepts must be one the admin manifest job also accepts.
export const SHA40_RE = /^[0-9a-f]{40}$/;

// Runbook §7: "5×, 20–70 s, 225s worst-case". Five send attempts, one poll after
// each wait — 225 s of waiting plus request time.
export const DEFAULT_BACKOFF_WAITS = Object.freeze([20, 30, 45, 60, 70]);
// The send time is recorded MINUS this skew: our clock and GitHub's
// `created_at` clock are not the same clock, and GitHub stamps `created_at`
// with one-second granularity, so a run marked a moment "before" the POST we
// made is still our run. Five seconds covers exactly that — clock offset plus
// rounding across the POST boundary. It deliberately does NOT reach a minute
// into the past: every second of skew is a second in which somebody else's
// `waiver-staging-deployed` dispatch could be mistaken for ours.
export const DISPATCH_SKEW_SECONDS = 5;
export const RUN_PAGE_SIZE = 10;
// Every request carries this deadline. Without one, a hung read blocks until
// the runner's job timeout: the retry budget is silently eaten while the
// `waiver-staging` concurrency slot stays held. An aborted request is just a
// failed attempt (`status: 0`, like any other transport failure).
export const FETCH_TIMEOUT_MS = 30_000;

// Refusals no retry can fix. 401 (bad/expired credential), 403 (insufficient
// scope, or the PAT cannot see the repo), 404 (wrong repo — GitHub answers 404
// rather than 403 for repositories a token may not see), 422 (GitHub parsed the
// request and rejected the payload). Everything else — 5xx, 429, and transport
// failures reported as status 0 — keeps the full retry schedule.
export const NON_TRANSIENT_SEND_STATUSES = Object.freeze([401, 403, 404, 422]);

export const EXIT_OK = 0;
export const EXIT_FAILED = 1;
export const EXIT_USAGE = 2;

const USER_AGENT = 'waiver-system-v2-staging-dispatch';

// --- Rule strings (an error must state the rule it enforced, never the value) ---

export const SAFE_TOKEN_RULE = 'a single-line token made only of [A-Za-z0-9._/:@-]';
export const IMAGE_RULE = `${SAFE_TOKEN_RULE} shaped like ${IMAGE_REGISTRY_PREFIX}<owner>/<name>:<tag>`;
export const SHA_RULE = 'exactly 40 lowercase hexadecimal digits ([0-9a-f]{40})';

export const USAGE = [
    'Usage:',
    '  node scripts/staging-dispatch.mjs dispatch --image-tag <ghcr.io/owner/name:sha> --sha <40 hex>',
    '',
    'Environment:',
    `  ADMIN_DISPATCH_PAT      (required) fine-grained PAT on ${ADMIN_REPO}: Contents R/W + Actions Read`,
    `  DISPATCH_BACKOFF_WAITS  (optional) comma-separated seconds, default ${DEFAULT_BACKOFF_WAITS.join(',')}`,
    '  GITHUB_STEP_SUMMARY     (optional) appended with the delivered run line',
    '',
    'Exit codes:',
    '  0  the dispatch produced an admin run carrying this event type',
    '  1  no such admin run appeared (or every send was refused)',
    '  2  usage or validation error (nothing was sent)'
].join('\n');

export class UsageError extends Error {
    constructor(message) {
        super(message);
        this.name = 'UsageError';
        this.exitCode = EXIT_USAGE;
    }
}

// -----------------------------------------------------------------------------
// Validation
// -----------------------------------------------------------------------------

/** True when `value` is a string safe to echo into a GitHub Actions log. */
export function isSafeToken(value) {
    return typeof value === 'string' && SAFE_TOKEN_RE.test(value);
}

/** The value if it is safe to echo, otherwise null (callers print a placeholder). */
export function safeOrNull(value) {
    return isSafeToken(value) ? value : null;
}

/**
 * A GHCR image reference the admin manifest can actually pin.
 * The charset check is applied FIRST and independently: `GHCR_IMAGE_RE` already
 * excludes newlines, but the house rule is that every value on its way into a
 * log line or a payload passes the same single-line proof the admin guard uses.
 */
export function isImageReference(value) {
    return isSafeToken(value) && value.length <= 512 && GHCR_IMAGE_RE.test(value);
}

export function isSha40(value) {
    return typeof value === 'string' && SHA40_RE.test(value);
}

/** True when this HTTP status means "retrying cannot change the answer". */
export function isNonTransientSendStatus(status) {
    return NON_TRANSIENT_SEND_STATUSES.includes(Number(status));
}

/**
 * `DISPATCH_BACKOFF_WAITS` → seconds[]. Unset/blank keeps the §7 default.
 * Anything else must be a comma-separated list of positive finite numbers;
 * a malformed override is a usage error, never a silent fallback (a typo'd
 * override that quietly became the default would make the schedule unknowable).
 */
export function backoffWaits(raw) {
    if (raw === undefined || raw === null || String(raw).trim() === '') return [...DEFAULT_BACKOFF_WAITS];
    const parts = String(raw)
        .split(',')
        .map((part) => part.trim());
    return parts.map((part) => {
        const seconds = Number(part);
        if (part === '' || !Number.isFinite(seconds) || seconds <= 0) {
            throw new UsageError('DISPATCH_BACKOFF_WAITS must be a comma-separated list of positive numbers of seconds (value withheld)');
        }
        return seconds;
    });
}

// -----------------------------------------------------------------------------
// Argument parsing
// -----------------------------------------------------------------------------

const COMMAND_FLAGS = {
    dispatch: ['image-tag', 'sha']
};

/**
 * `["dispatch", "--sha", "abc"]` → `{ command: "dispatch", options: { sha: "abc" } }`.
 * Flag NAMES are shape-checked before they are quoted back in an error message
 * (argv is CI input, but an error line still must not be forgeable); flag
 * VALUES are never echoed here — the per-command validators own that.
 */
export function parseArgs(argv) {
    const list = Array.isArray(argv) ? argv : [];
    const command = list[0];
    if (command === undefined) throw new UsageError('no subcommand given');
    if (command === '--help' || command === '-h' || command === 'help') return { command: 'help', options: {} };
    if (!Object.prototype.hasOwnProperty.call(COMMAND_FLAGS, command)) {
        throw new UsageError(`unknown subcommand (expected one of: ${Object.keys(COMMAND_FLAGS).join(', ')}; value withheld)`);
    }

    const allowed = COMMAND_FLAGS[command];
    const options = {};
    for (let index = 1; index < list.length; index += 1) {
        const token = list[index];
        if (typeof token !== 'string' || !token.startsWith('--')) throw new UsageError(`${command} takes --flag values only (value withheld)`);
        let name = token.slice(2);
        let value;
        const equals = name.indexOf('=');
        if (equals >= 0) {
            value = name.slice(equals + 1);
            name = name.slice(0, equals);
        }
        if (!/^[a-z][a-z0-9-]*$/.test(name)) throw new UsageError('flag names must match [a-z][a-z0-9-]* (value withheld)');
        if (!allowed.includes(name)) throw new UsageError(`--${name} is not a flag of ${command} (accepted: ${allowed.map((flag) => `--${flag}`).join(', ')})`);
        if (Object.prototype.hasOwnProperty.call(options, name)) throw new UsageError(`--${name} was given more than once`);
        if (value === undefined) {
            index += 1;
            value = list[index];
        }
        if (value === undefined) throw new UsageError(`--${name} needs a value`);
        options[name] = value;
    }
    return { command, options };
}

// -----------------------------------------------------------------------------
// GitHub — send the dispatch, then prove OUR run exists
// -----------------------------------------------------------------------------

function githubHeaders(token) {
    return {
        Accept: 'application/vnd.github+json',
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
        'User-Agent': USER_AGENT,
        'X-GitHub-Api-Version': '2022-11-28'
    };
}

/**
 * The payload the admin manifest job reads. `waiverImageTag` is the key its
 * `PAYLOAD_WAIVER_IMAGE_TAG` env maps to (staging.yml); `forkSha` is carried
 * for the run log and for future consumers — the admin repo reads no SHA from
 * any client_payload today (it resolves its own identity SHA from artifacts).
 */
export function dispatchPayload(imageTag, sha) {
    return { event_type: EVENT_TYPE, client_payload: { waiverImageTag: imageTag, forkSha: sha } };
}

export function dispatchUrl(repo = ADMIN_REPO) {
    return `https://api.github.com/repos/${repo}/dispatches`;
}

export function runsUrl(repo = ADMIN_REPO, workflowFile = ADMIN_WORKFLOW_FILE) {
    return `https://api.github.com/repos/${repo}/actions/workflows/${workflowFile}/runs?event=repository_dispatch&per_page=${RUN_PAGE_SIZE}`;
}

/** One dispatch send. GitHub documents 204; any 2xx counts as accepted. */
export async function sendDispatch({ fetch, token, imageTag, sha, repo = ADMIN_REPO }) {
    let response;
    try {
        response = await fetch(dispatchUrl(repo), {
            method: 'POST',
            headers: githubHeaders(token),
            body: JSON.stringify(dispatchPayload(imageTag, sha)),
            signal: AbortSignal.timeout(FETCH_TIMEOUT_MS)
        });
    } catch {
        return { ok: false, status: 0 };
    }
    const status = Number(response?.status) || 0;
    return { ok: status >= 200 && status < 300, status };
}

/**
 * M4 upgrade 1. Does this run's name identify it as OUR event type?
 * The title itself is never returned or printed — only this boolean — because
 * it is API-sourced free text (spaces, an em dash) that could carry a newline.
 */
export function runTitleCarriesMarker(run) {
    const title = run?.display_title;
    return typeof title === 'string' && title.includes(RUN_TITLE_MARKER);
}

/**
 * Split the page into "ours" and "somebody else's, inside our window".
 *
 * Ours: created at or after the send GitHub ACCEPTED, AND run-named for this
 * event type. (`runsUrl` has already narrowed the page to
 * `event=repository_dispatch` runs of the admin `staging.yml`, server-side — a
 * `workflow_run` run from somebody's merge is never a candidate here.)
 *
 * Consulting `conclusion` would be a bug, not a stricter check: a
 * dispatch-triggered admin run is expected to end red while waiver staging is
 * half-provisioned. Requiring green would make this sender re-dispatch five
 * times against a run that already arrived.
 *
 * `unmarkedInWindow` exists purely for the failure message: "runs appeared but
 * none was named for us" and "nothing appeared at all" have different causes
 * (a stale admin `run-name:` vs. a lost dispatch), and the error must say which
 * one was observed.
 */
export function classifyRuns(runs, sentAtMs) {
    // A null/absent send time means no send was ever accepted. Guarding it here
    // too (the caller already skips the poll) keeps the predicate honest on its
    // own terms: `createdAt < null` is a silent `< 0`, which would accept every
    // run on the page.
    if (!Array.isArray(runs) || !Number.isFinite(sentAtMs)) return { run: null, unmarkedInWindow: 0 };
    let best = null;
    let bestAt = -Infinity;
    let unmarkedInWindow = 0;
    for (const run of runs) {
        if (!run || typeof run !== 'object') continue;
        const createdAt = Date.parse(run.created_at);
        if (!Number.isFinite(createdAt)) continue; // missing or unparseable timestamp: cannot be attributed to us
        if (createdAt < sentAtMs) continue; // older than our accepted send: somebody else's dispatch
        if (!runTitleCarriesMarker(run)) {
            unmarkedInWindow += 1;
            continue; // a wizard dispatch (or a pre-M4 admin staging.yml) — not ours
        }
        if (createdAt > bestAt) {
            best = run;
            bestAt = createdAt;
        }
    }
    return { run: best, unmarkedInWindow };
}

/** The delivery predicate, as a single value (see classifyRuns). */
export function selectRun(runs, sentAtMs) {
    return classifyRuns(runs, sentAtMs).run;
}

export async function pollForRun({ fetch, token, sentAtMs, repo = ADMIN_REPO, workflowFile = ADMIN_WORKFLOW_FILE }) {
    let response;
    try {
        response = await fetch(runsUrl(repo, workflowFile), { headers: githubHeaders(token), signal: AbortSignal.timeout(FETCH_TIMEOUT_MS) });
    } catch {
        return { ok: false, status: 0, run: null, unmarkedInWindow: 0 };
    }
    const status = Number(response?.status) || 0;
    if (status < 200 || status >= 300) return { ok: false, status, run: null, unmarkedInWindow: 0 };
    let body;
    try {
        body = await response.json();
    } catch {
        return { ok: false, status, run: null, unmarkedInWindow: 0 };
    }
    const { run, unmarkedInWindow } = classifyRuns(body?.workflow_runs, sentAtMs);
    return { ok: true, status, run, unmarkedInWindow };
}

/**
 * A run, reduced to values that are safe to print. Anything that fails the
 * charset check comes back null and the caller prints a placeholder — the run
 * still counts as delivered, because delivery is about EXISTENCE, not about
 * whether GitHub's JSON was echoable. `display_title` is deliberately absent:
 * it can never be echoed, only tested (see runTitleCarriesMarker).
 */
export function describeRun(run) {
    const id = Number.isSafeInteger(run?.id) ? String(run.id) : null;
    const url = typeof run?.html_url === 'string' && run.html_url.startsWith('https://github.com/') ? safeOrNull(run.html_url) : null;
    return {
        id,
        url,
        status: safeOrNull(run?.status),
        conclusion: safeOrNull(run?.conclusion)
    };
}

export function formatRunLine(run) {
    const { id, url, status, conclusion } = describeRun(run);
    return [`admin run ${id ?? 'id withheld (not a plain integer)'}`, `status ${status ?? 'withheld'}/${conclusion ?? 'none'}`, url ?? 'run URL withheld (not a safe single-line https://github.com/ token)'].join(' — ');
}

/**
 * Send, wait, poll — up to one attempt per configured wait.
 *
 * `sentAt` is stamped from the clock reading taken immediately BEFORE a POST,
 * and only once that POST comes back 2xx — GitHub accepting the event is what
 * makes a subsequent run attributable to us. It is stamped only ONCE: a later
 * accepted send does NOT move it forward, so a run produced by an earlier
 * accepted attempt still counts when a later poll finds it.
 *
 * Until some send is accepted there is nothing to attribute, so the poll is
 * skipped entirely — an unrelated `repository_dispatch` run appearing in the
 * window must never be reported as this job's delivery.
 *
 * A non-transient refusal (M4 upgrade 2) abandons the remaining SENDS. With
 * nothing yet accepted that ends the function immediately — no wait, no poll,
 * because no run could be ours. With an earlier send accepted it still honours
 * the wait and the one poll it was already committed to, then stops.
 */
export async function dispatchWithRetry({ fetch, sleep, now, stdout, token, imageTag, sha, waits = DEFAULT_BACKOFF_WAITS, repo = ADMIN_REPO, workflowFile = ADMIN_WORKFLOW_FILE, skewSeconds = DISPATCH_SKEW_SECONDS }) {
    const attempts = waits.length;
    let sentAtMs = null;
    let lastSendStatus = 0;
    let lastPollStatus = 0;
    let nonTransientStatus = 0;
    let unmarkedInWindow = 0;

    for (let index = 0; index < attempts; index += 1) {
        const attempt = index + 1;
        // Read the clock BEFORE the POST: GitHub can only create the run after
        // it has received the request, so the pre-POST instant is the earliest
        // moment our own run could carry.
        const sendStartMs = now();
        const send = await sendDispatch({ fetch, token, imageTag, sha, repo });
        lastSendStatus = send.status;
        if (send.ok && sentAtMs === null) sentAtMs = sendStartMs - skewSeconds * 1000;
        const sendNote = send.status === 0 ? 'transport failed (no HTTP status)' : `HTTP ${send.status}`;
        stdout(`attempt ${attempt}/${attempts}: POST ${EVENT_TYPE} to ${repo} — ${sendNote}${send.ok ? '' : ' (attempt failed)'}`);

        if (!send.ok && isNonTransientSendStatus(send.status)) {
            nonTransientStatus = send.status;
            stdout(`attempt ${attempt}/${attempts}: HTTP ${send.status} is a NON-TRANSIENT refusal (credential, scope, target repo or payload) — retrying cannot change it, so the remaining ${attempts - attempt} send(s) are abandoned.`);
            if (sentAtMs === null) {
                // Nothing of ours is in GitHub's queue, so no run can be ours:
                // waiting and polling would only be theatre.
                return { ok: false, attempts: attempt, run: null, sentAtMs: null, accepted: false, lastSendStatus, lastPollStatus, nonTransientStatus, unmarkedInWindow };
            }
        }

        await sleep(waits[index] * 1000);

        if (sentAtMs === null) {
            stdout(`attempt ${attempt}/${attempts}: no accepted send yet — nothing to attribute, so no ${repo} run is examined`);
            continue;
        }

        const poll = await pollForRun({ fetch, token, sentAtMs, repo, workflowFile });
        lastPollStatus = poll.status;
        if (!poll.ok) {
            stdout(`attempt ${attempt}/${attempts}: run lookup on ${repo} ${workflowFile} answered HTTP ${poll.status} (body withheld)`);
        } else {
            unmarkedInWindow = poll.unmarkedInWindow;
            if (poll.run) {
                stdout(`attempt ${attempt}/${attempts}: delivered — ${formatRunLine(poll.run)}`);
                return { ok: true, attempts: attempt, run: poll.run, sentAtMs, accepted: true, lastSendStatus, lastPollStatus, nonTransientStatus, unmarkedInWindow };
            }
            const note = unmarkedInWindow > 0 ? ` (${unmarkedInWindow} repository_dispatch run(s) did appear in that window, but none was run-named "${RUN_TITLE_MARKER}" — those are somebody else's dispatch)` : '';
            stdout(`attempt ${attempt}/${attempts}: no ${repo} ${workflowFile} run named "${RUN_TITLE_MARKER}" created at or after our accepted send yet${note}`);
        }

        if (nonTransientStatus !== 0) {
            stdout(`attempt ${attempt}/${attempts}: sends were abandoned after HTTP ${nonTransientStatus} and this poll found nothing — stopping rather than polling a queue nothing more will be added to.`);
            return { ok: false, attempts: attempt, run: null, sentAtMs, accepted: true, lastSendStatus, lastPollStatus, nonTransientStatus, unmarkedInWindow };
        }
    }

    return { ok: false, attempts, run: null, sentAtMs, accepted: sentAtMs !== null, lastSendStatus, lastPollStatus, nonTransientStatus, unmarkedInWindow };
}

/**
 * Best-effort append to $GITHUB_STEP_SUMMARY. A summary write that fails must
 * never turn a delivered dispatch into a red step, so this reports rather than
 * throws.
 */
export async function writeStepSummary({ appendFile, path, line }) {
    if (!path) return false;
    try {
        await appendFile(path, `${line}\n`, 'utf8');
        return true;
    } catch {
        return false;
    }
}

// -----------------------------------------------------------------------------
// main() — thin wiring only
// -----------------------------------------------------------------------------

function defaultDeps() {
    return {
        fetch: (...args) => globalThis.fetch(...args),
        sleep: (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
        now: () => Date.now(),
        env: process.env,
        stdout: (line) => console.log(line),
        stderr: (line) => console.error(line),
        appendFile: fsAppendFile
    };
}

export async function main(argv, overrides = {}) {
    const deps = { ...defaultDeps(), ...overrides };
    const { fetch, sleep, now, env, stdout, stderr, appendFile } = deps;

    let parsed;
    try {
        parsed = parseArgs(argv);
    } catch (error) {
        stderr(`::error::${error.message}`);
        stderr(USAGE);
        return EXIT_USAGE;
    }

    const { command, options } = parsed;
    if (command === 'help') {
        stdout(USAGE);
        return EXIT_OK;
    }

    // command === 'dispatch'
    const imageTag = options['image-tag'];
    const sha = options.sha;

    // VALIDATE FIRST — nothing below this block may run before the payload is
    // known-good, so a malformed value can never reach the admin repo.
    if (imageTag === undefined) {
        stderr('::error::--image-tag is required: the admin manifest pins the exact image reference this run published.');
        return EXIT_USAGE;
    }
    if (!isImageReference(imageTag)) {
        stderr(`::error::--image-tag must be ${IMAGE_RULE} (value withheld — the admin repo rejects any client_payload value outside the ${SAFE_TOKEN_RULE} charset, refuses a non-ghcr.io image outright, and a newline in it would forge workflow commands). Nothing was sent.`);
        return EXIT_USAGE;
    }
    if (sha === undefined) {
        stderr('::error::--sha is required.');
        return EXIT_USAGE;
    }
    if (!isSha40(sha)) {
        stderr(`::error::--sha must be ${SHA_RULE} (value withheld). Nothing was sent.`);
        return EXIT_USAGE;
    }
    // The two values describe ONE build: CI tags the image with the fork SHA it
    // built (`ghcr.io/<owner>/<repo>:<sha>`), so a pair that disagrees means the
    // pin and the commit it claims to represent are not the same thing — and
    // the admin manifest would record a lie. Both values are already proven
    // safe to echo at this point.
    if (!imageTag.endsWith(`:${sha}`)) {
        stderr(`::error::--image-tag (${imageTag}) is not tagged with --sha (${sha}). The manifest pin and the commit it stands for must be the same build; this pair cannot be. Nothing was sent.`);
        return EXIT_USAGE;
    }

    const token = env.ADMIN_DISPATCH_PAT;
    if (!token) {
        stderr(`::error::ADMIN_DISPATCH_PAT is not set — ${ADMIN_REPO} cannot be told that this waiver build is live. Mint a fine-grained PAT scoped to ${ADMIN_REPO} ONLY (Contents: Read & write for POST /dispatches, Actions: Read for the run poll) and add it as an Actions secret (M4 operator runbook §5). Nothing was sent.`);
        return EXIT_USAGE;
    }

    let waits;
    try {
        waits = backoffWaits(env.DISPATCH_BACKOFF_WAITS);
    } catch (error) {
        stderr(`::error::${error.message}`);
        return EXIT_USAGE;
    }

    const result = await dispatchWithRetry({ fetch, sleep, now, stdout, token, imageTag, sha, waits });
    if (result.ok) {
        const line = `Dispatched \`${EVENT_TYPE}\` to \`${ADMIN_REPO}\` pinning \`${imageTag}\` — ${formatRunLine(result.run)}`;
        stdout(line);
        await writeStepSummary({ appendFile, path: env.GITHUB_STEP_SUMMARY, line });
        return EXIT_OK;
    }

    const nonTransientNote = result.nonTransientStatus
        ? `The send was refused with HTTP ${result.nonTransientStatus}, which no retry can change, so the remaining attempts were abandoned deliberately (M4 fail-fast). 401/403 ⇒ ADMIN_DISPATCH_PAT is expired, revoked or missing Contents: Read & write on ${ADMIN_REPO}; 404 ⇒ the PAT cannot see that repository at all (GitHub answers 404, not 403, for repos a token may not access); 422 ⇒ GitHub parsed the request and rejected the payload. `
        : '';
    const notAcceptedNote = result.accepted
        ? ''
        : `No send was ever accepted: every POST answered non-2xx or failed in transport (last send HTTP ${result.lastSendStatus}), so none of our events reached GitHub's queue and NO run could be ours — the run lookup was therefore skipped rather than allowed to attribute somebody else's repository_dispatch run to this job. `;
    const unmarkedNote = result.unmarkedInWindow > 0
        ? `${result.unmarkedInWindow} repository_dispatch run(s) of ${ADMIN_WORKFLOW_FILE} WERE created inside our window but none carried the run-name marker "${RUN_TITLE_MARKER}". Either they are wizard dispatches (expected — they are not ours), or the admin default branch does not yet carry M4's \`run-name:\` expression, in which case every run is titled "Staging" and this sender can never confirm delivery. Check the admin repo's staging.yml on its DEFAULT branch. `
        : '';
    stderr(
        `::error::No ${ADMIN_REPO} ${ADMIN_WORKFLOW_FILE} run named "${RUN_TITLE_MARKER}" appeared after ${result.attempts} send attempt(s) (waits ${waits.join('/')}s; last send HTTP ${result.lastSendStatus}, last run lookup HTTP ${result.lastPollStatus}). ` +
            nonTransientNote +
            notAcceptedNote +
            unmarkedNote +
            `A 204 only means GitHub accepted the event, not that a run exists — see the CI/CD operator runbook §7 row "repository_dispatch senders (M3 wizard CI, M4 waiver CI) need their own retry logic". ` +
            `Check that ${ADMIN_WORKFLOW_FILE} on the admin default branch still lists \`${EVENT_TYPE}\` under repository_dispatch.types.`
    );
    return EXIT_FAILED;
}

// Only self-execute. Importing this module (the test suite does) is
// side-effect free.
const entryPoint = process.argv[1] ? pathToFileURL(process.argv[1]).href : null;
if (entryPoint === import.meta.url) {
    try {
        process.exitCode = await main(process.argv.slice(2));
    } catch (error) {
        console.error(`::error::staging-dispatch failed unexpectedly: ${error?.name ?? 'Error'} (details withheld).`);
        process.exitCode = EXIT_FAILED;
    }
}
