#!/usr/bin/env bash
# tests/predeploy_e2e.sh — end-to-end verification of dev/predeploy.php against a
# DISPOSABLE local MySQL. Docker-based (no local php/mysql needed). NEVER touches
# prod: it only ever connects to a throwaway container on a private docker
# network, and it never reads MYSQL_URL / any staging URL.
#
# Covers the eight deploy scenarios the predeploy rewrite must satisfy:
#   (a) fresh empty DB        -> tables + baseline + apply, exit 0, evidence cols
#   (b) PROD-SHAPE CONVERGENCE: existing DB, complete ledger 001..MAX_BAKED ->
#       all already-applied, exit 0, schema assertion OK (the steady-state prod
#       deploy is a clean no-op; DISTINCT from the partial-ledger abort in (c))
#   (b2) [GVS-89] PROD-SHAPE UPGRADE: existing DB ledgered only through 005 with
#       the PRE-006 waiver_instances shape -> 006_public_instances is EXECUTED
#       for real (its guarded ALTERs add the columns + index), exit 0
#   (c) legacy DB, partial ledger        -> apply FAILS -> predeploy exit 1
#       (fail-safe) + a self-explanatory likely-cause/remediation hint
#   (d) new pending probe on an existing DB -> probe applied, exit 0
#   (e) fresh DB with an un-baked probe    -> probe's DDL EXECUTED (not baselined)
#       (the probe takes the NEXT UNUSED numeric prefix -- 007 since GVS-89's
#       real 006 -- because the runner hard-errors on a duplicate prefix)
#   (f) missing a required MYSQL* var    -> exit 1, no false success
#   (g) hollow schema: for EACH of 005's four evidence columns AND 006's three
#       public-instance columns INDEPENDENTLY, drop just that column (ledger
#       left complete) -> post-migrate schema assertion FIRES naming that column
#       -> predeploy exit 1. Proves the assertion covers the FULL 005 + 006
#       contract, non-vacuously, for all seven.
#   (h) [GVS-58] the gated waiver-TEMPLATE seed: a re-provisioned (empty) staging
#       DB self-heals to template id 2 with the publish gate TRUE; re-running is
#       a no-op; unarmed does nothing; a broken fixture WARNS without aborting
#       the deploy; and a DB holding signed waivers is REFUSED.
#
# Usage:   bash tests/predeploy_e2e.sh
# Env:     PHP_IMG   (default waiver-system-v2-php:latest)   php+pdo_mysql image
#          MYSQL_IMG (default mysql:8.4)
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_IMG="${PHP_IMG:-waiver-system-v2-php:latest}"
MYSQL_IMG="${MYSQL_IMG:-mysql:8.4}"
SUF="$$"
NET="waiver_pde_net_$SUF"; DBC="waiver_pde_db_$SUF"
ROOTPW="rootpw"; APPUSER="appuser"; APPPASS="apppass"; DBNAME="waivertest"
SCHEME="mysql://"   # split so no source line carries a credential-shaped literal
WORK="$(mktemp -d)"

PASS=0; FAIL=0
ok()  { echo "  PASS: $1"; PASS=$((PASS+1)); }
bad() { echo "  FAIL: $1"; FAIL=$((FAIL+1)); }
cleanup() { docker rm -f "$DBC" >/dev/null 2>&1; docker network rm "$NET" >/dev/null 2>&1; rm -rf "$WORK"; }
trap cleanup EXIT

# --- MySQL readiness (kills the first-run flakiness) --------------------------
# The official mysql image runs a TEMPORARY server during init (to create
# MYSQL_USER/MYSQL_DATABASE), then RESTARTS the real networked server. A single
# `mysqladmin ping` can succeed against that temp server, so the old wait
# sometimes returned before the DB was actually ready -> the first scenario's
# reset_db raced the restart and flaked. db_ready polls a REAL query and requires
# CONSECUTIVE successes so the restart window can't yield a false "ready".
db_ready() {
  local tries="${1:-120}" need=3 streak=0 i
  for i in $(seq 1 "$tries"); do
    if docker exec "$DBC" mysql -uroot -p"$ROOTPW" -N -B -e 'SELECT 1' >/dev/null 2>&1; then
      streak=$((streak+1)); [ "$streak" -ge "$need" ] && return 0
    else
      streak=0
    fi
    sleep 1
  done
  return 1
}
# Quick per-scenario re-check (server already stably up after db_ready): a single
# answered query is enough, but abort the suite if the DB ever stops answering
# rather than let a scenario flake against a dead container.
ensure_db() {
  local i
  for i in $(seq 1 30); do
    docker exec "$DBC" mysql -uroot -p"$ROOTPW" -N -B -e 'SELECT 1' >/dev/null 2>&1 && return 0
    sleep 1
  done
  echo "  mysql stopped answering — aborting suite"; docker logs "$DBC" 2>&1 | tail -20; exit 2
}

echo "=== writable repo copy (so a probe migration fixture can be added) ==="
for d in vendor src dev migrations config composer.json composer.lock; do cp -R "$REPO/$d" "$WORK/$d"; done

# [GVS-89] Derived from the repo, never hardcoded: the highest migration baked
# into 001_init.sql (dev/predeploy.php's MAX_BAKED_MIGRATION), the full expected
# ledger (every committed NNN_*.sql, in order), and the probe's prefix -- the
# NEXT UNUSED number. A hardcoded `006_probe` collided with the real
# 006_public_instances.sql the moment it shipped (the runner rejects two files
# sharing a numeric prefix), so the probe now always sits one past the last
# real migration.
MAX_BAKED="$(sed -n "s/^const MAX_BAKED_MIGRATION = '\([^']*\)';.*/\1/p" "$REPO/dev/predeploy.php")"
[ -n "$MAX_BAKED" ] || { echo "could not read MAX_BAKED_MIGRATION from dev/predeploy.php"; exit 2; }
EXPECTED_LEDGER="$(ls "$REPO/migrations" | sed -n 's/^\([0-9][0-9]*_[A-Za-z0-9_]*\)\.sql$/\1/p' | sort -n | paste -sd, -)"
LAST_PREFIX="$(ls "$REPO/migrations" | sed -n 's/^\([0-9][0-9]*\)[_.].*sql$/\1/p' | sort -n | tail -1)"
PROBE="$(printf '%03d' $((10#$LAST_PREFIX + 1)))_probe"
PROBE_TABLE="predeploy_probe_$PROBE"
echo "MAX_BAKED=$MAX_BAKED  PROBE=$PROBE  EXPECTED_LEDGER=$EXPECTED_LEDGER"
rm -f "$WORK/migrations/$PROBE.sql"

echo "=== disposable MySQL ($MYSQL_IMG) ==="
docker network create "$NET" >/dev/null
docker run -d --name "$DBC" --network "$NET" \
  -e MYSQL_ROOT_PASSWORD="$ROOTPW" -e MYSQL_DATABASE="$DBNAME" \
  -e MYSQL_USER="$APPUSER" -e MYSQL_PASSWORD="$APPPASS" "$MYSQL_IMG" >/dev/null
echo -n "waiting for mysql (stable answer)"
if db_ready 120; then echo " up."; else echo " TIMEOUT"; docker logs "$DBC" 2>&1 | tail -30; exit 2; fi

q() { docker exec -i "$DBC" mysql -uroot -p"$ROOTPW" -N -B "$DBNAME" -e "$1" 2>/dev/null; }
reset_db() { docker exec -i "$DBC" mysql -uroot -p"$ROOTPW" -e \
  "DROP DATABASE IF EXISTS $DBNAME; CREATE DATABASE $DBNAME; GRANT ALL PRIVILEGES ON $DBNAME.* TO '$APPUSER'@'%'; FLUSH PRIVILEGES;" 2>/dev/null; }
apply_init() { docker exec -i "$DBC" mysql -uroot -p"$ROOTPW" "$DBNAME" < "$WORK/migrations/001_init.sql" 2>/dev/null; }
run_runner() { # setup-only baseline helper
  docker run --rm --network "$NET" -v "$WORK":/var/www/html --entrypoint php \
    -e "STAGING_WAIVER_DB_URL=${SCHEME}${APPUSER}:${APPPASS}@${DBC}:3306/${DBNAME}" \
    "$PHP_IMG" /var/www/html/migrations/run.php "$@" 2>&1; }

LAST_OUT=""
# Extra `-e VAR=VALUE` docker args for the next run_predeploy call. Scenarios (a)
# through (g) leave it empty; (h) uses it to arm the template seed. Appended with
# a length guard because this script targets bash 3.2 (macOS), where `set -u` +
# "${EMPTY[@]}" is an unbound-variable error.
EXTRA_ENV=()
run_predeploy() { # optional arg -VARNAME drops that env var
  local drop="${1:-}"; drop="${drop#-}"
  local e=()
  [ "$drop" != "MYSQLHOST" ]     && e+=(-e "MYSQLHOST=$DBC")
  [ "$drop" != "MYSQLPORT" ]     && e+=(-e "MYSQLPORT=3306")
  [ "$drop" != "MYSQLDATABASE" ] && e+=(-e "MYSQLDATABASE=$DBNAME")
  [ "$drop" != "MYSQLUSER" ]     && e+=(-e "MYSQLUSER=$APPUSER")
  [ "$drop" != "MYSQLPASSWORD" ] && e+=(-e "MYSQLPASSWORD=$APPPASS")
  [ ${#EXTRA_ENV[@]} -gt 0 ] && e+=("${EXTRA_ENV[@]}")
  LAST_OUT=$(docker run --rm --network "$NET" -v "$WORK":/var/www/html --entrypoint php \
    "${e[@]}" "$PHP_IMG" /var/www/html/dev/predeploy.php 2>&1); return $?
}

echo; echo "### (a) FRESH empty DB ###"
ensure_db; reset_db; run_predeploy; rc=$?; echo "$LAST_OUT" | sed 's/^/  | /'
[ "$rc" = 0 ] && ok "exit 0" || bad "exit $rc (want 0)"
[ "$(q "SHOW TABLES LIKE 'waiver_instances'")" = waiver_instances ] && ok "waiver_instances exists" || bad "no waiver_instances"
# LATE-object check (Grok New #2 verification): evidence_object_key is added by
# 005 and 001_init.sql's DDL for it sits AFTER the very first CREATE TABLE, so
# its presence proves the multi-statement 001_init exec did NOT truncate to the
# first statement. If MULTI_STATEMENTS were off/broken, this column would be
# absent and this check (plus the predeploy schema assertion) would fail.
[ "$(q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DBNAME' AND TABLE_NAME='waiver_responses' AND COLUMN_NAME='evidence_object_key'")" = 1 ] && ok "evidence_object_key (late object) exists" || bad "no evidence_object_key"
[ "$(q "SELECT GROUP_CONCAT(version ORDER BY version) FROM schema_migrations")" = "$EXPECTED_LEDGER" ] && ok "ledger = every committed migration ($EXPECTED_LEDGER)" || bad "ledger != $EXPECTED_LEDGER"
echo "$LAST_OUT" | grep -q "schema assertion OK" && ok "post-migrate schema assertion passed (fresh)" || bad "no schema assertion OK (fresh)"
# [GVS-89] 006 is BAKED: a fresh DB gets its columns from 001_init.sql and the
# runner BASELINES it (zero per-statement rows) -- proves the MAX_BAKED bump.
[ "$(q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DBNAME' AND TABLE_NAME='waiver_instances' AND COLUMN_NAME IN ('is_public','expires_at','locale')")" = 3 ] && ok "006 public-instance columns exist (baked)" || bad "006 columns missing on fresh"
[ "$(q "SELECT COUNT(*) FROM schema_migration_statements WHERE version='006_public_instances'")" = 0 ] && ok "006 baselined on fresh (not executed)" || bad "006 executed on fresh (MAX_BAKED not bumped?)"

echo; echo "### (b) PROD-SHAPE CONVERGENCE: existing DB, complete ledger 001..$MAX_BAKED ###"
# This is the STEADY-STATE prod deploy shape: an existing DB whose
# schema_migrations ledger already carries every baked migration in full. It
# MUST converge cleanly -- a pure no-op: apply-mode finds every version already
# applied (exit 0), and the post-migrate schema assertion confirms every
# asserted column is physically present. This documents that the prod deploy is
# a no-op, DISTINCT from the partial-ledger abort exercised in scenario (c).
ensure_db; reset_db; apply_init; run_runner --baseline --through="$MAX_BAKED" >/dev/null
run_predeploy; rc=$?; echo "$LAST_OUT" | sed 's/^/  | /'
[ "$rc" = 0 ] && ok "exit 0 (prod-shape converges cleanly)" || bad "exit $rc (want 0)"
echo "$LAST_OUT" | grep -q "already applied" && ok "no-op (already applied)" || bad "not a no-op"
echo "$LAST_OUT" | grep -q "schema assertion OK" && ok "post-migrate schema assertion passed (existing)" || bad "no schema assertion OK (existing)"
echo "$LAST_OUT" | grep -q "\[predeploy\] DONE" && ok "reached DONE" || bad "no DONE"

echo; echo "### (b2) [GVS-89] PROD-SHAPE UPGRADE: ledger through 005, pre-006 schema ###"
# The ONE-TIME prod shape of the first deploy after GVS-89: prod is ledgered
# 001..005 and its waiver_instances has none of 006's columns/index. apply-mode
# must EXECUTE 006 for real (per-statement ledger rows), add all three columns
# + the index, keep existing rows non-public, and pass the schema assertion.
ensure_db; reset_db; apply_init
q "ALTER TABLE waiver_instances DROP INDEX idx_public_expires, DROP COLUMN locale, DROP COLUMN expires_at, DROP COLUMN is_public" >/dev/null
q "INSERT INTO waiver_instances (template_version_id, link_token, status, created_at, updated_at) VALUES (1, 'pre006-existing-row-token-000001', 'pending', UTC_TIMESTAMP(), UTC_TIMESTAMP())" >/dev/null
run_runner --baseline --through=005_evidence_fields >/dev/null
[ "$(q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DBNAME' AND TABLE_NAME='waiver_instances' AND COLUMN_NAME IN ('is_public','expires_at','locale')")" = 0 ] && ok "(b2) sanity: pre-006 shape (no public columns)" || bad "(b2) could not build the pre-006 shape"
run_predeploy; rc=$?; echo "$LAST_OUT" | sed 's/^/  | /'
[ "$rc" = 0 ] && ok "(b2) exit 0 (006 applied on an existing DB)" || bad "(b2) exit $rc (want 0)"
[ "$(q "SELECT COUNT(*) FROM schema_migration_statements WHERE version='006_public_instances'")" -ge 1 ] 2>/dev/null && ok "(b2) 006 EXECUTED (per-statement ledger rows)" || bad "(b2) 006 not executed"
[ "$(q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DBNAME' AND TABLE_NAME='waiver_instances' AND COLUMN_NAME IN ('is_public','expires_at','locale')")" = 3 ] && ok "(b2) all three 006 columns added" || bad "(b2) 006 columns missing after apply"
[ "$(q "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='$DBNAME' AND TABLE_NAME='waiver_instances' AND INDEX_NAME='idx_public_expires'")" -ge 1 ] && ok "(b2) idx_public_expires added" || bad "(b2) idx_public_expires missing"
[ "$(q "SELECT is_public FROM waiver_instances WHERE link_token='pre006-existing-row-token-000001'")" = 0 ] && ok "(b2) pre-existing row backfilled NON-public (is_public=0)" || bad "(b2) pre-existing row not is_public=0"
echo "$LAST_OUT" | grep -q "schema assertion OK" && ok "(b2) post-migrate schema assertion passed" || bad "(b2) no schema assertion OK"

echo; echo "### (c) legacy DB, tables present but partial ledger ###"
ensure_db; reset_db; apply_init   # tables + only 001_init row; 002/003/005 NOT ledgered
run_predeploy; rc=$?; echo "$LAST_OUT" | sed 's/^/  | /'
[ "$rc" = 1 ] && ok "exit 1 (fail-safe)" || bad "exit $rc (want 1)"
echo "$LAST_OUT" | grep -q "\[seed\]" && bad "seed reached (should abort first)" || ok "seed NOT reached"
echo "$LAST_OUT" | grep -q "\[predeploy\] DONE" && bad "reached DONE (should abort)" || ok "no DONE"
# Codex re-gate P1 #1: the abort must be SELF-EXPLANATORY -- name the likely
# cause (incomplete ledger) and the SAFE remediation (controlled --baseline,
# NOT a blind baseline). Assert the augmented hint is present.
echo "$LAST_OUT" | grep -q "LIKELY CAUSE + SAFE REMEDIATION" && ok "abort names likely-cause + remediation" || bad "no self-explanatory hint"
echo "$LAST_OUT" | grep -q "Do NOT blindly baseline" && ok "hint warns against a blind baseline" || bad "hint missing the do-not-baseline warning"

echo; echo "### (d) NEW pending $PROBE on an existing DB ###"
printf '%s\n' "-- $PROBE.sql (E2E fixture) — genuinely-new, NOT baked into 001_init." \
  "CREATE TABLE IF NOT EXISTS $PROBE_TABLE (id INT PRIMARY KEY);" > "$WORK/migrations/$PROBE.sql"
ensure_db; reset_db; apply_init; run_runner --baseline --through="$MAX_BAKED" >/dev/null
run_predeploy; rc=$?; echo "$LAST_OUT" | sed 's/^/  | /'
[ "$rc" = 0 ] && ok "exit 0" || bad "exit $rc (want 0)"
[ "$(q "SHOW TABLES LIKE '$PROBE_TABLE'")" = "$PROBE_TABLE" ] && ok "$PROBE DDL executed" || bad "$PROBE table missing"
[ "$(q "SELECT COUNT(*) FROM schema_migrations WHERE version='$PROBE'")" = 1 ] && ok "$PROBE ledgered" || bad "$PROBE not ledgered"

echo; echo "### (e) FRESH DB with an un-baked $PROBE present ###"
ensure_db; reset_db; run_predeploy; rc=$?; echo "$LAST_OUT" | sed 's/^/  | /'
[ "$rc" = 0 ] && ok "exit 0" || bad "exit $rc (want 0)"
[ "$(q "SHOW TABLES LIKE '$PROBE_TABLE'")" = "$PROBE_TABLE" ] && ok "$PROBE EXECUTED on fresh (not baseline-skipped)" || bad "$PROBE missing on fresh"
[ "$(q "SELECT COUNT(*) FROM schema_migration_statements WHERE version='$PROBE'")" -ge 1 ] 2>/dev/null && ok "$PROBE has per-statement ledger rows (really executed)" || bad "$PROBE baselined, not executed"
rm -f "$WORK/migrations/$PROBE.sql"

echo; echo "### (f) missing a required MYSQL* var ###"
ensure_db; reset_db; run_predeploy -MYSQLPASSWORD; rc=$?; echo "$LAST_OUT" | sed 's/^/  | /'
[ "$rc" = 1 ] && ok "exit 1" || bad "exit $rc (want 1)"
echo "$LAST_OUT" | grep -qi MYSQLPASSWORD && ok "names the missing var" || bad "missing var not named"
echo "$LAST_OUT" | grep -q "\[predeploy\] DONE" && bad "reached DONE (false success!)" || ok "no false success"

echo; echo "### (g) HOLLOW schema: drop EACH of 005's four + 006's three columns independently -> assertion FIRES ###"
# Mutation / non-vacuity check for the post-migrate schema assertion (Grok New
# #3; Codex re-gate P1 #2): a LYING ledger. For EACH of 005's four evidence
# columns independently, build a prod-like DB (001..005 ledgered), DROP just
# that ONE column WITHOUT touching the ledger, and require predeploy to ABORT.
# Apply-mode is a clean no-op (all versions ledgered) so the runner exits 0 --
# ONLY the physical-schema assertion stands between this hollow schema and a
# re-shipped incident. It must abort (exit 1, "schema assertion FAILED" naming
# THAT column) before the admin seed / DONE, in EVERY sub-case. Dropping any one
# of the four proves the assertion covers the FULL 005 contract (not just one
# representative column) and is non-vacuous for each column: if the assertion
# checked only one column, dropping a DIFFERENT one would exit 0 here and FAIL.
# [GVS-89] The same holds for 006's three waiver_instances columns: bumping
# MAX_BAKED_MIGRATION to 006 widened the assertion to a table => columns map,
# and each of the seven TABLE:COLUMN pairs below must fire it on its own.
for PAIR in waiver_responses:evidence_sha256 waiver_responses:evidence_object_key waiver_responses:evidence_blob_key waiver_responses:evidence_blob_url \
            waiver_instances:is_public waiver_instances:expires_at waiver_instances:locale; do
  TBL="${PAIR%%:*}"; COL="${PAIR#*:}"
  echo "  -- sub-case: drop $TBL.$COL --"
  ensure_db; reset_db; apply_init; run_runner --baseline --through="$MAX_BAKED" >/dev/null
  q "ALTER TABLE $TBL DROP COLUMN $COL" >/dev/null
  [ "$(q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DBNAME' AND TABLE_NAME='$TBL' AND COLUMN_NAME='$COL'")" = 0 ] && ok "[$COL] column physically dropped (ledger left intact)" || bad "[$COL] could not drop column"
  run_predeploy; rc=$?; echo "$LAST_OUT" | sed 's/^/  | /'
  [ "$rc" = 1 ] && ok "[$COL] exit 1 (schema assertion fired)" || bad "[$COL] exit $rc (want 1) — hollow schema NOT caught!"
  echo "$LAST_OUT" | grep -q "schema assertion FAILED" && ok "[$COL] names the schema-assertion failure" || bad "[$COL] assertion failure not named"
  echo "$LAST_OUT" | grep -q "MISSING.*$COL" && ok "[$COL] abort names THIS missing column" || bad "[$COL] missing column not named in abort"
  echo "$LAST_OUT" | grep -q "\[seed\]" && bad "[$COL] seed reached (should abort at assertion)" || ok "[$COL] seed NOT reached"
  echo "$LAST_OUT" | grep -q "\[predeploy\] DONE" && bad "[$COL] reached DONE (hollow schema shipped!)" || ok "[$COL] no DONE"
done

echo; echo "### (h) [GVS-58] gated waiver-TEMPLATE seed ###"
# The property the whole staging repair rests on: a RE-PROVISIONED (empty)
# staging database must come back carrying template id 2 -- the id BookingV2
# staging points at -- with the publish gate TRUE, without a human touching it.
# phpunit pins TemplateSeed's logic; this pins that dev/predeploy.php actually
# RUNS it, in the shipped image, on the real deploy path.
mkdir -p "$WORK/dev/seed"
cat > "$WORK/dev/seed/e2e-template.json" <<'JSON'
{
  "payload_version": 1,
  "exported_at": "2026-09-04T00:00:00Z",
  "source_template_id": 2,
  "template": {"id": 2, "name": "E2E Waiver", "is_active": 1, "created_by": 41,
               "created_at": "2026-01-05 10:00:00", "updated_at": "2026-02-01 12:30:00"},
  "versions": [{"version": 1, "title": "E2E Title", "description": null,
                "fields_json": "[{\"key\":\"full_name\",\"type\":\"text\"}]",
                "requires_signature": 1, "created_by": 41,
                "created_at": "2026-01-05 10:00:00",
                "content_html": "<p>E2E body</p>", "print_css": null,
                "is_published": 1, "published_at": "2026-01-05 10:05:00"}]
}
JSON
ARMED=(-e "SEED_ADMIN_EMAIL=e2e@example.com" -e "SEED_ADMIN_PASSWORD=e2e-not-a-real-password"
       -e "SEED_WAIVER_TEMPLATE_FILE=dev/seed/e2e-template.json")

echo "  -- (h1) fresh DB, seed ARMED -> self-heals to id 2 --"
ensure_db; reset_db; EXTRA_ENV=("${ARMED[@]}"); run_predeploy; rc=$?; echo "$LAST_OUT" | sed 's/^/  | /'
[ "$rc" = 0 ] && ok "(h1) exit 0" || bad "(h1) exit $rc (want 0)"
# THE assertion: id 2, not the 1 an AUTO_INCREMENT import would have produced.
[ "$(q "SELECT GROUP_CONCAT(id) FROM waiver_templates")" = 2 ] && ok "(h1) template landed at id 2 (not auto-increment 1)" || bad "(h1) template id != 2"
# The exact query WaiverController::hasPublishedVersion() runs.
[ "$(q "SELECT COUNT(*) FROM waiver_template_versions WHERE template_id=2 AND is_published=1")" = 1 ] && ok "(h1) has_published_version answers TRUE for id 2" || bad "(h1) publish gate still false"
[ -n "$(q "SELECT published_at FROM waiver_template_versions WHERE template_id=2")" ] && ok "(h1) published_at is set" || bad "(h1) published_at NULL"
echo "$LAST_OUT" | grep -q "\[tseed\] seeded" && ok "(h1) reported 'seeded'" || bad "(h1) did not report seeded"
echo "$LAST_OUT" | grep -q "has_published_version=true" && ok "(h1) predeploy VERIFIED the gate post-insert" || bad "(h1) no post-insert verification"
[ "$(q "SELECT created_by FROM waiver_templates WHERE id=2")" = "$(q "SELECT id FROM users WHERE email='e2e@example.com'")" ] && ok "(h1) created_by remapped to the locally-seeded admin" || bad "(h1) created_by not remapped"

echo "  -- (h2) redeploy with the same fixture -> idempotent no-op --"
EXTRA_ENV=("${ARMED[@]}"); run_predeploy; rc=$?; echo "$LAST_OUT" | sed 's/^/  | /'
[ "$rc" = 0 ] && ok "(h2) exit 0" || bad "(h2) exit $rc (want 0)"
echo "$LAST_OUT" | grep -q "\[tseed\] already_present" && ok "(h2) reported 'already_present'" || bad "(h2) not a no-op"
[ "$(q "SELECT COUNT(*) FROM waiver_templates")" = 1 ] && ok "(h2) still exactly 1 template" || bad "(h2) template duplicated"
[ "$(q "SELECT COUNT(*) FROM waiver_template_versions")" = 1 ] && ok "(h2) still exactly 1 version" || bad "(h2) version duplicated"

echo "  -- (h3) Lock 2: an operator edit is NEVER overwritten --"
# The safety argument for shipping the fixture inside the PRODUCTION image.
q "UPDATE waiver_templates SET name='EDITED BY OPERATOR' WHERE id=2" >/dev/null
q "UPDATE waiver_template_versions SET content_html='<p>OPERATOR TEXT</p>' WHERE template_id=2" >/dev/null
EXTRA_ENV=("${ARMED[@]}"); run_predeploy; rc=$?
[ "$rc" = 0 ] && ok "(h3) exit 0" || bad "(h3) exit $rc (want 0)"
[ "$(q "SELECT name FROM waiver_templates WHERE id=2")" = "EDITED BY OPERATOR" ] && ok "(h3) operator's name survived the redeploy" || bad "(h3) seed OVERWROTE the operator's template!"
[ "$(q "SELECT content_html FROM waiver_template_versions WHERE template_id=2")" = "<p>OPERATOR TEXT</p>" ] && ok "(h3) operator's body survived the redeploy" || bad "(h3) seed OVERWROTE the operator's version body!"

echo "  -- (h4) UNARMED (no SEED_WAIVER_TEMPLATE_FILE) -> does nothing --"
# The production shape: the fixture ships in the image, no variable names it.
ensure_db; reset_db; EXTRA_ENV=(); run_predeploy; rc=$?
[ "$rc" = 0 ] && ok "(h4) exit 0" || bad "(h4) exit $rc (want 0)"
echo "$LAST_OUT" | grep -q "SEED_WAIVER_TEMPLATE_FILE unset" && ok "(h4) reported the skip" || bad "(h4) no skip message"
[ "$(q "SELECT COUNT(*) FROM waiver_templates")" = 0 ] && ok "(h4) wrote NOTHING while unarmed" || bad "(h4) seeded while unarmed!"

echo "  -- (h5) armed at a MISSING fixture -> warns, does NOT abort the deploy --"
# Deliberate asymmetry with the migration steps above: a data-convenience
# bootstrap must never block the release that would fix it.
ensure_db; reset_db; EXTRA_ENV=(-e "SEED_WAIVER_TEMPLATE_FILE=dev/seed/does-not-exist.json")
run_predeploy; rc=$?; echo "$LAST_OUT" | sed 's/^/  | /'
[ "$rc" = 0 ] && ok "(h5) exit 0 (deploy NOT aborted)" || bad "(h5) exit $rc -- a bad fixture must not block the deploy"
echo "$LAST_OUT" | grep -q "\[tseed\] WARNING" && ok "(h5) warned loudly" || bad "(h5) failed silently"
echo "$LAST_OUT" | grep -q "\[predeploy\] DONE" && ok "(h5) still reached DONE" || bad "(h5) did not reach DONE"
[ "$(q "SELECT COUNT(*) FROM waiver_templates")" = 0 ] && ok "(h5) wrote nothing" || bad "(h5) wrote something from a missing file"

echo "  -- (h6) Lock 3: a DB holding SIGNED waivers is REFUSED --"
ensure_db; reset_db; apply_init; run_runner --baseline --through="$MAX_BAKED" >/dev/null
# A signature, standing in for production. No FK is declared anywhere in
# migrations/*.sql, so a bare response row is a legal way to say "this database
# has real signatures in it".
q "INSERT INTO waiver_responses (waiver_instance_id, answers_json, signed_at, hash_sha256, created_at)
   VALUES (999, '{}', UTC_TIMESTAMP(), REPEAT('a',64), UTC_TIMESTAMP())" >/dev/null
EXTRA_ENV=("${ARMED[@]}"); run_predeploy; rc=$?; echo "$LAST_OUT" | sed 's/^/  | /'
[ "$rc" = 0 ] && ok "(h6) exit 0 (refusal is not a deploy failure)" || bad "(h6) exit $rc (want 0)"
echo "$LAST_OUT" | grep -q "\[tseed\] refused_signed_data" && ok "(h6) reported 'refused_signed_data'" || bad "(h6) did not refuse"
[ "$(q "SELECT COUNT(*) FROM waiver_templates")" = 0 ] && ok "(h6) wrote NOTHING into a DB holding signatures" || bad "(h6) wrote into a DB holding signatures!"
EXTRA_ENV=()

echo; echo "======================================================"
echo "RESULT: PASS=$PASS FAIL=$FAIL"
[ "$FAIL" = 0 ] && echo "ALL PREDEPLOY SCENARIOS PASSED" || echo "SOME SCENARIOS FAILED"
exit "$FAIL"
