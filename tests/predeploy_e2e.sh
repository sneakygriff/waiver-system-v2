#!/usr/bin/env bash
# tests/predeploy_e2e.sh — end-to-end verification of dev/predeploy.php against a
# DISPOSABLE local MySQL. Docker-based (no local php/mysql needed). NEVER touches
# prod: it only ever connects to a throwaway container on a private docker
# network, and it never reads MYSQL_URL / any staging URL.
#
# Covers the six deploy scenarios the predeploy rewrite must satisfy:
#   (a) fresh empty DB        -> tables + baseline + apply, exit 0, evidence cols
#   (b) prod-like DB (001..005 ledgered) -> all already-applied, exit 0
#   (c) legacy DB, partial ledger        -> apply FAILS -> predeploy exit 1 (fail-safe)
#   (d) new pending 006 on an existing DB-> 006 applied, exit 0
#   (e) fresh DB with an un-baked 006     -> 006's DDL EXECUTED (not baselined)
#   (f) missing a required MYSQL* var    -> exit 1, no false success
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

echo "=== writable repo copy (so a 006 fixture can be added) ==="
for d in vendor src dev migrations config composer.json composer.lock; do cp -R "$REPO/$d" "$WORK/$d"; done
rm -f "$WORK/migrations/006_probe.sql"

echo "=== disposable MySQL ($MYSQL_IMG) ==="
docker network create "$NET" >/dev/null
docker run -d --name "$DBC" --network "$NET" \
  -e MYSQL_ROOT_PASSWORD="$ROOTPW" -e MYSQL_DATABASE="$DBNAME" \
  -e MYSQL_USER="$APPUSER" -e MYSQL_PASSWORD="$APPPASS" "$MYSQL_IMG" >/dev/null
echo -n "waiting for mysql"; up=0
for i in $(seq 1 60); do
  docker exec "$DBC" mysqladmin ping -uroot -p"$ROOTPW" >/dev/null 2>&1 && { echo " up."; up=1; break; }
  echo -n "."; sleep 2
done
[ "$up" != "1" ] && { echo " TIMEOUT"; docker logs "$DBC" 2>&1 | tail -30; exit 2; }
sleep 2

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
reset_db; run_predeploy; rc=$?; echo "$LAST_OUT" | sed 's/^/  | /'
[ "$rc" = 0 ] && ok "exit 0" || bad "exit $rc (want 0)"
[ "$(q "SHOW TABLES LIKE 'waiver_instances'")" = waiver_instances ] && ok "waiver_instances exists" || bad "no waiver_instances"
[ "$(q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DBNAME' AND TABLE_NAME='waiver_responses' AND COLUMN_NAME='evidence_object_key'")" = 1 ] && ok "evidence_object_key exists" || bad "no evidence_object_key"
[ "$(q "SELECT GROUP_CONCAT(version ORDER BY version) FROM schema_migrations")" = "001_init,002_waiver_integration,003_erase_waiver,004_erasure_audit_events_backfill,005_evidence_fields" ] && ok "ledger 001..005" || bad "ledger != 001..005"

echo; echo "### (b) prod-like DB (001..005 ledgered) ###"
reset_db; apply_init; run_runner --baseline --through=005_evidence_fields >/dev/null
run_predeploy; rc=$?; echo "$LAST_OUT" | sed 's/^/  | /'
[ "$rc" = 0 ] && ok "exit 0" || bad "exit $rc (want 0)"
echo "$LAST_OUT" | grep -q "already applied" && ok "no-op (already applied)" || bad "not a no-op"
echo "$LAST_OUT" | grep -q "\[predeploy\] DONE" && ok "reached DONE" || bad "no DONE"

echo; echo "### (c) legacy DB, tables present but partial ledger ###"
reset_db; apply_init   # tables + only 001_init row; 002/003/005 NOT ledgered
run_predeploy; rc=$?; echo "$LAST_OUT" | sed 's/^/  | /'
[ "$rc" = 1 ] && ok "exit 1 (fail-safe)" || bad "exit $rc (want 1)"
echo "$LAST_OUT" | grep -q "\[seed\]" && bad "seed reached (should abort first)" || ok "seed NOT reached"
echo "$LAST_OUT" | grep -q "\[predeploy\] DONE" && bad "reached DONE (should abort)" || ok "no DONE"

echo; echo "### (d) NEW pending 006 on an existing DB ###"
printf '%s\n' '-- 006_probe.sql (E2E fixture) — genuinely-new, NOT baked into 001_init.' \
  'CREATE TABLE IF NOT EXISTS predeploy_probe_006 (id INT PRIMARY KEY);' > "$WORK/migrations/006_probe.sql"
reset_db; apply_init; run_runner --baseline --through=005_evidence_fields >/dev/null
run_predeploy; rc=$?; echo "$LAST_OUT" | sed 's/^/  | /'
[ "$rc" = 0 ] && ok "exit 0" || bad "exit $rc (want 0)"
[ "$(q "SHOW TABLES LIKE 'predeploy_probe_006'")" = predeploy_probe_006 ] && ok "006 DDL executed" || bad "006 table missing"
[ "$(q "SELECT COUNT(*) FROM schema_migrations WHERE version='006_probe'")" = 1 ] && ok "006 ledgered" || bad "006 not ledgered"

echo; echo "### (e) FRESH DB with an un-baked 006 present ###"
reset_db; run_predeploy; rc=$?; echo "$LAST_OUT" | sed 's/^/  | /'
[ "$rc" = 0 ] && ok "exit 0" || bad "exit $rc (want 0)"
[ "$(q "SHOW TABLES LIKE 'predeploy_probe_006'")" = predeploy_probe_006 ] && ok "006 EXECUTED on fresh (not baseline-skipped)" || bad "006 missing on fresh"
[ "$(q "SELECT COUNT(*) FROM schema_migration_statements WHERE version='006_probe'")" -ge 1 ] 2>/dev/null && ok "006 has per-statement ledger rows (really executed)" || bad "006 baselined, not executed"
rm -f "$WORK/migrations/006_probe.sql"

echo; echo "### (f) missing a required MYSQL* var ###"
reset_db; run_predeploy -MYSQLPASSWORD; rc=$?; echo "$LAST_OUT" | sed 's/^/  | /'
[ "$rc" = 1 ] && ok "exit 1" || bad "exit $rc (want 1)"
echo "$LAST_OUT" | grep -qi MYSQLPASSWORD && ok "names the missing var" || bad "missing var not named"
echo "$LAST_OUT" | grep -q "\[predeploy\] DONE" && bad "reached DONE (false success!)" || ok "no false success"

echo; echo "======================================================"
echo "RESULT: PASS=$PASS FAIL=$FAIL"
[ "$FAIL" = 0 ] && echo "ALL PREDEPLOY SCENARIOS PASSED" || echo "SOME SCENARIOS FAILED"
exit "$FAIL"
