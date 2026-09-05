# Staging waiver-fork repair runbook — GVS-58 follow-up

**Status:** code is in place and tested; the two steps below are OPERATOR actions
because they need credentials this repo does not (and must not) hold.

---

## What is actually broken

The staging fork database was re-provisioned empty on **2026-08-18**: 0 templates,
0 template versions, 0 instances, 0 admin users. Production is untouched and
healthy. Two independent consequences:

1. **Nobody can log into staging `/admin.php`** — the `users` table is empty.
2. **Every staging waiver send fails terminally** — BookingV2's
   `waiver_config.waiverTemplateId` is the string `"2"` on both staging and
   production (`updatedAt == createdAt` to the millisecond; it has never been
   edited since the PR-B-core migration wrote it), and the staging fork has no
   template `2` to answer with.

The failure is quiet by design, which is why it took a while to surface:
`has_published_version` returns **HTTP 200** with `{has_published_version:false}`
for a template that does not exist — the query is just
`SELECT 1 FROM waiver_template_versions WHERE template_id=? AND is_published=1`
(`src/WaiverController.php`). Nothing 404s; the answer is simply "no".

---

## Step 1 — Seed the staging admin (2 Railway variables, no code change)

`dev/predeploy.php` is already Railway's `preDeployCommand` (`railway.json`) and
has **always** contained an idempotent admin seed, at the very bottom of the file:

```php
$email = getenv('SEED_ADMIN_EMAIL');
$adminPass = getenv('SEED_ADMIN_PASSWORD');
if ($email && $adminPass) {
  // SELECT id FROM users WHERE email=?  -> skip if present
  // else INSERT ... password_hash($adminPass, PASSWORD_ARGON2ID) ... role='admin'
} else {
  pd_out('[seed] SEED_ADMIN_EMAIL/PASSWORD unset -> skipping admin seed');
}
```

Those two variables are simply **not set on the staging Railway service**. That
is the entire reason there are 0 admin users. Nothing is broken and nothing needs
fixing in code.

> Note: `dev/seed_admin.php` (the one the top-level README quick-start mentions)
> is a *local Docker* helper — it `require`s `config/config.php`, which is
> gitignored and does not exist in the Railway image. On Railway the predeploy
> seed above is the only admin seed that runs.

**Do this:**

1. Railway → the **staging** waiver-fork service → **Variables**.
2. Add:
   - `SEED_ADMIN_EMAIL` = the admin address you want on staging
   - `SEED_ADMIN_PASSWORD` = a strong password, **generated fresh — never reuse
     the production admin password on staging**
3. Redeploy the staging service (or let the next deploy pick it up).
4. In the deploy log, look for: `[seed] admin created: <email>`
   (a later deploy prints `[seed] admin <email> already exists` — it is
   idempotent, keyed on the email, and never rotates an existing password).
5. Log in at staging `/admin.php`.

Leave both variables set. They cost nothing on subsequent deploys and mean the
*next* re-provisioning restores the admin without anyone noticing.

---

## Step 2 — Copy the operator template into staging, preserving id 2

### Why the id is the whole task

`waiver_templates.id` is `BIGINT AUTO_INCREMENT` (`migrations/001_init.sql`). A
naive re-import into the empty staging database lands at **id 1**, while BookingV2
staging still points at `"2"` — leaving staging exactly as broken, with a
perfectly good template sitting right there. Every insert on this path carries an
**explicit id**.

### 2a. Export from production (READ-ONLY)

```bash
TEMPLATE_EXPORT_DB_URL='mysql://USER:PASS@PROD-HOST:3306/DBNAME' \
  php dev/template_export.php --template-id=2 --out=dev/seed/staging-waiver-template.json
```

This is safe to point at production:

- it opens `START TRANSACTION READ ONLY` before reading anything, so the **server**
  rejects any write on that session — the read-only property is enforced by MySQL,
  not by convention;
- it reads only `waiver_templates` + `waiver_template_versions` for one id.
  `waiver_instances` and `waiver_responses` are never touched, so the fixture is
  **PII-free by construction**;
- it uses its own env var (`TEMPLATE_EXPORT_DB_URL`), never `STAGING_WAIVER_DB_URL`
  (CI's secret) and never `MYSQL_URL`, so "which database did that read?" is never
  ambiguous;
- it **refuses** to write a fixture whose versions are all drafts — that fixture
  would seed cleanly and fix nothing.

It prints the source coordinates, every version, which are published, and the
byte sizes, so you can eyeball the export before trusting it.

### 2b. Put the fixture where staging can see it

Commit `dev/seed/staging-waiver-template.json`. It ships in every image
(`.dockerignore` excludes `**/*.md`, not `.json`), **including production's** —
and that is fine, because the file is inert until a variable names it, and the
seed cannot overwrite an existing template even if one did. See "What cannot
happen" below.

If you would rather not commit the operative text, put the file on a Railway
volume instead and point `SEED_WAIVER_TEMPLATE_FILE` at that absolute path — the
code does not care where the file lives.

### 2c. Arm the seed on staging ONLY

Railway → **staging** service → Variables:

- `SEED_WAIVER_TEMPLATE_FILE` = `dev/seed/staging-waiver-template.json`

**Never set this variable on the production service.**

Redeploy staging. The log will show:

```
[tseed] seeded: seeded template 2 with N version(s); has_published_version now answers TRUE
[tseed] template_id=2 versions_inserted=N has_published_version=true created_by=<staging admin id>
```

Every later deploy prints `[tseed] already_present: ... nothing written`.

---

## What cannot happen (three independent locks)

The production template is the legally-operative document the operator edits by
hand. "We won't set that variable on prod" is a promise, not a control, so two
**mechanical** locks stand behind it (`src/TemplateSeed.php`):

| # | Lock | Effect |
|---|------|--------|
| 1 | `SEED_WAIVER_TEMPLATE_FILE` unset | The seed never runs. This is the weakest lock — a human promise. |
| 2 | **Insert-only-if-the-id-is-absent** | There is no `UPDATE` statement in `src/TemplateSeed.php` at all. Production has template 2, so an accidentally-armed production deploy finds it present and writes **nothing**. This is also what makes the seed idempotent. |
| 3 | **Refuse a database holding `waiver_responses` rows** | A database with real signatures in it is not a database a bootstrap writes to. Production always has them; a freshly re-provisioned staging has none. |

Locks 2 and 3 do not depend on knowing which host is which, so neither can be
defeated by a bad credential rotation — the failure mode that motivated the
URL-provenance pre-flight guarding CI's `dump`/`migrate` jobs
(`scripts/preflight-db-host.php`).

**A failure here never aborts the deploy.** Deliberate asymmetry with the
migration steps above it in `dev/predeploy.php`: those gate *schema correctness*
and must fail-fast; this is a *data-convenience bootstrap*, and taking a staging
release down over a malformed fixture would block the very deploy that fixes it.
It warns loudly on stderr (`[tseed] WARNING: ...`) and continues.

---

## Verification

1. **The fork itself** — `has_published_version` for template 2 must answer true:
   ```bash
   curl -s 'https://<staging-fork>/api.php' -H 'Content-Type: application/json' \
     -H "X-HMAC: $SIG" --data '{"action":"has_published_version","template_id":2}'
   # -> {"has_published_version":true}
   ```
   (Before the fix this returns `{"has_published_version":false}` with HTTP 200.)
2. **The admin UI** — staging `/admin.php` lists the template with id `2` and a
   latest version. (It renders `id`, `name`, `latest_version` only, so the
   imported `created_by` cannot break the page either way.)
3. **BookingV2 staging** — `/admin/waiver-recovery` should stop showing the
   *"Trimiterea waiverelor este oprită"* banner added in v0.71.2.
4. **End to end** — send a staging waiver; it should produce a live link.

---

## Credentials needed (and where they live)

| Step | Needs | Where |
|------|-------|-------|
| 1 | Railway access to the **staging** service's Variables | Railway |
| 2a | **Read** credentials for the production fork database | Railway (prod MySQL service) |
| 2b/2c | Repo write access + Railway staging Variables | GitHub / Railway |

No step needs staging database credentials outside Railway: the write happens
inside the staging service's own `preDeployCommand`, using the `MYSQL*` variables
that service already has. Nothing writes to production at any point.

---

## Tests covering this

- `tests/TemplateSeedTest.php` — 22 cases: id preservation, publish-gate flip,
  the operator-edit-survives-redeploy case (Lock 2), the signed-data refusal
  (Lock 3), `created_by` remapping, export→seed round trip, and validation.
- `tests/predeploy_e2e.sh` scenario **(h)** — the same properties end-to-end
  through the shipped image against a disposable MySQL: a re-provisioned empty
  DB self-heals to id 2, a redeploy is a no-op, an operator edit survives,
  unarmed does nothing, a missing fixture warns without aborting, and a DB
  holding signatures is refused.
