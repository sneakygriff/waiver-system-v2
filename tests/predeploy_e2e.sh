#!/usr/bin/env bash
# tests/predeploy_e2e.sh — end-to-end verification of dev/predeploy.php against a
# DISPOSABLE local MySQL. Docker-based (no local php/mysql needed). NEVER touches
# prod: it only ever connects to a throwaway container on a private docker
# network, and it never reads MYSQL_URL / any staging URL.
#
# Covers the seven deploy scenarios the predeploy rewrite must satisfy:
#   (a) fresh empty DB        -> tables + baseline + apply, exit 0, evidence cols
#   (b) prod-like DB (001..005 ledgered) -> all already-applied, exit 0
#   (c) legacy DB, partial ledger        -> apply FAILS -> predeploy exit 1 (fail-safe)
#   (d) new pending 006 on an existing DB-> 006 applied, exit 0
#   (e) fresh DB with an un-baked 006     -> 006's DDL EXECUTED (not baselined)
#   (f) missing a required MYSQL* var    -> exit 1, no false success
#   (g) hollow schema (ledger complete, a 005 column dropped) -> post-migrate
#       schema assertion FIRES -> predeploy exit 1 (non-vacuity / mutation check)
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

echo "=== writable repo copy (so a 006 fixture can be added) ==="
for d in vendor src dev migrations config composer.json composer.lock; do cp -R "$REPO/$d" "$WORK/$d"; done
rm -f "$WORK/migrations/006_probe.sql"

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
run_predeploy() { # optional arg -VARNAME drops that env var
  local drop="${1:-}"; drop="${drop#-}"
  local e=()
  [ "$drop" != "MYSQLHOST" ]     && e+=(-e "MYSQLHOST=$DBC")
  [ "$drop" != "MYSQLPORT" ]     && e+=(-e "MYSQLPORT=3306")
  [ "$drop" != "MYSQLDATABASE" ] && e+=(-e "MYSQLDATABASE=$DBNAME")
  [ "$drop" != "MYSQLUSER" ]     && e+=(-e "MYSQLUSER=$APPUSER")
  [ "$drop" != "MYSQLPASSWORD" ] && e+=(-e "MYSQLPASSWORD=$APPPASS")
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
[ "$(q "SELECT GROUP_CONCAT(version ORDER BY version) FROM schema_migrations")" = "001_init,002_waiver_integration,003_erase_waiver,004_erasure_audit_events_backfill,005_evidence_fields" ] && ok "ledger 001..005" || bad "ledger != 001..005"
echo "$LAST_OUT" | grep -q "schema assertion OK" && ok "post-migrate schema assertion passed (fresh)" || bad "no schema assertion OK (fresh)"

echo; echo "### (b) prod-like DB (001..005 ledgered) ###"
ensure_db; reset_db; apply_init; run_runner --baseline --through=005_evidence_fields >/dev/null
run_predeploy; rc=$?; echo "$LAST_OUT" | sed 's/^/  | /'
[ "$rc" = 0 ] && ok "exit 0" || bad "exit $rc (want 0)"
echo "$LAST_OUT" | grep -q "already applied" && ok "no-op (already applied)" || bad "not a no-op"
echo "$LAST_OUT" | grep -q "schema assertion OK" && ok "post-migrate schema assertion passed (existing)" || bad "no schema assertion OK (existing)"
echo "$LAST_OUT" | grep -q "\[predeploy\] DONE" && ok "reached DONE" || bad "no DONE"

echo; echo "### (c) legacy DB, tables present but partial ledger ###"
ensure_db; reset_db; apply_init   # tables + only 001_init row; 002/003/005 NOT ledgered
run_predeploy; rc=$?; echo "$LAST_OUT" | sed 's/^/  | /'
[ "$rc" = 1 ] && ok "exit 1 (fail-safe)" || bad "exit $rc (want 1)"
echo "$LAST_OUT" | grep -q "\[seed\]" && bad "seed reached (should abort first)" || ok "seed NOT reached"
echo "$LAST_OUT" | grep -q "\[predeploy\] DONE" && bad "reached DONE (should abort)" || ok "no DONE"

echo; echo "### (d) NEW pending 006 on an existing DB ###"
printf '%s\n' '-- 006_probe.sql (E2E fixture) — genuinely-new, NOT baked into 001_init.' \
  'CREATE TABLE IF NOT EXISTS predeploy_probe_006 (id INT PRIMARY KEY);' > "$WORK/migrations/006_probe.sql"
ensure_db; reset_db; apply_init; run_runner --baseline --through=005_evidence_fields >/dev/null
run_predeploy; rc=$?; echo "$LAST_OUT" | sed 's/^/  | /'
[ "$rc" = 0 ] && ok "exit 0" || bad "exit $rc (want 0)"
[ "$(q "SHOW TABLES LIKE 'predeploy_probe_006'")" = predeploy_probe_006 ] && ok "006 DDL executed" || bad "006 table missing"
[ "$(q "SELECT COUNT(*) FROM schema_migrations WHERE version='006_probe'")" = 1 ] && ok "006 ledgered" || bad "006 not ledgered"

echo; echo "### (e) FRESH DB with an un-baked 006 present ###"
ensure_db; reset_db; run_predeploy; rc=$?; echo "$LAST_OUT" | sed 's/^/  | /'
[ "$rc" = 0 ] && ok "exit 0" || bad "exit $rc (want 0)"
[ "$(q "SHOW TABLES LIKE 'predeploy_probe_006'")" = predeploy_probe_006 ] && ok "006 EXECUTED on fresh (not baseline-skipped)" || bad "006 missing on fresh"
[ "$(q "SELECT COUNT(*) FROM schema_migration_statements WHERE version='006_probe'")" -ge 1 ] 2>/dev/null && ok "006 has per-statement ledger rows (really executed)" || bad "006 baselined, not executed"
rm -f "$WORK/migrations/006_probe.sql"

echo; echo "### (f) missing a required MYSQL* var ###"
ensure_db; reset_db; run_predeploy -MYSQLPASSWORD; rc=$?; echo "$LAST_OUT" | sed 's/^/  | /'
[ "$rc" = 1 ] && ok "exit 1" || bad "exit $rc (want 1)"
echo "$LAST_OUT" | grep -qi MYSQLPASSWORD && ok "names the missing var" || bad "missing var not named"
echo "$LAST_OUT" | grep -q "\[predeploy\] DONE" && bad "reached DONE (false success!)" || ok "no false success"

echo; echo "### (g) HOLLOW schema: ledger complete but a 005 column dropped -> assertion FIRES ###"
# Mutation / non-vacuity check for the post-migrate schema assertion (Grok New
# #3): a LYING ledger. Build a prod-like DB (001..005 ledgered) then DROP 005's
# evidence_object_key WITHOUT touching the ledger. Apply-mode is a clean no-op
# (all versions ledgered) so the runner exits 0 -- ONLY the physical-schema
# assertion stands between this hollow schema and a re-shipped incident. It must
# abort with exit 1 before the admin seed / DONE. If the assertion were removed
# or vacuous, predeploy would exit 0 here and this scenario would FAIL.
ensure_db; reset_db; apply_init; run_runner --baseline --through=005_evidence_fields >/dev/null
q "ALTER TABLE waiver_responses DROP COLUMN evidence_object_key" >/dev/null
[ "$(q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DBNAME' AND TABLE_NAME='waiver_responses' AND COLUMN_NAME='evidence_object_key'")" = 0 ] && ok "005 column physically dropped (ledger left intact)" || bad "could not drop 005 column"
run_predeploy; rc=$?; echo "$LAST_OUT" | sed 's/^/  | /'
[ "$rc" = 1 ] && ok "exit 1 (schema assertion fired)" || bad "exit $rc (want 1) — hollow schema NOT caught!"
echo "$LAST_OUT" | grep -q "schema assertion FAILED" && ok "names the schema-assertion failure" || bad "assertion failure not named"
echo "$LAST_OUT" | grep -q "\[seed\]" && bad "seed reached (should abort at assertion)" || ok "seed NOT reached"
echo "$LAST_OUT" | grep -q "\[predeploy\] DONE" && bad "reached DONE (hollow schema shipped!)" || ok "no DONE"

echo; echo "======================================================"
echo "RESULT: PASS=$PASS FAIL=$FAIL"
[ "$FAIL" = 0 ] && echo "ALL PREDEPLOY SCENARIOS PASSED" || echo "SOME SCENARIOS FAILED"
exit "$FAIL"
