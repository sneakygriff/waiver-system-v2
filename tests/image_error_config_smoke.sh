#!/usr/bin/env bash
# tests/image_error_config_smoke.sh — image-level smoke test for the container's
# ERROR-VISIBILITY contract (post-incident 2026-08-30). Proves the BUILT image
# actually enforces "errors go to the logs, never to the guest":
#
#   * effective PHP ini: display_errors=Off, display_startup_errors=Off,
#     log_errors=On, error_log=/dev/stderr
#   * php-fpm config test (`php-fpm -tt`) passes AND pins display_errors=0 at the
#     [www] pool level (php_admin_flag — not overridable by ini_set)
#   * a request that triggers a controlled PHP error has the error text ABSENT
#     from the HTTP body but PRESENT on the container's stderr (docker logs)
#
# This is deliberately NOT part of the phpunit DB suite (it needs a full built
# image + running container). Run it directly:
#
#   bash tests/image_error_config_smoke.sh            # builds the image first
#   SKIP_BUILD=1 IMG=waiver-system-v2-php:latest bash tests/image_error_config_smoke.sh
#
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
IMG="${IMG:-waiver-error-smoke:test}"
SUF="$$"; CNAME="waiver_err_smoke_$SUF"
CANARY="SMOKE_CANARY_ERRTOKEN_$SUF"
PASS=0; FAIL=0
ok()  { echo "  PASS: $1"; PASS=$((PASS+1)); }
bad() { echo "  FAIL: $1"; FAIL=$((FAIL+1)); }
cleanup() { docker rm -f "$CNAME" >/dev/null 2>&1; }
trap cleanup EXIT

if [ "${SKIP_BUILD:-0}" != "1" ]; then
  echo "=== docker build -f docker/php/Dockerfile -t $IMG . ==="
  docker build -f "$REPO/docker/php/Dockerfile" -t "$IMG" "$REPO" || { echo "BUILD FAILED"; exit 2; }
fi

echo "=== boot container ==="
docker run -d --name "$CNAME" -e PORT=8080 -e APP_BASE_URL="http://smoke.local" "$IMG" >/dev/null

echo "=== inject a controlled-error probe into the docroot ==="
PROBE="$(mktemp)"
cat > "$PROBE" <<PHP
<?php
header('Content-Type: text/plain');
echo "SMOKE_BODY_START\n";
trigger_error('$CANARY', E_USER_WARNING);
echo "SMOKE_BODY_END\n";
PHP
docker cp "$PROBE" "$CNAME":/var/www/html/public/probe.php >/dev/null
rm -f "$PROBE"
# docker cp writes the file root-owned/0600; php-fpm runs as www-data. Make it
# world-readable so the FPM worker can open it (else nginx returns 403, not our
# controlled PHP error).
docker exec "$CNAME" chmod 644 /var/www/html/public/probe.php >/dev/null 2>&1

echo -n "waiting for nginx+php-fpm"; up=0
for i in $(seq 1 30); do
  code=$(docker exec "$CNAME" curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:8080/probe.php" 2>/dev/null || true)
  [ "$code" = "200" ] && { echo " up."; up=1; break; }
  echo -n "."; sleep 1
done
[ "$up" != "1" ] && { echo " TIMEOUT"; docker logs "$CNAME" 2>&1 | tail -30; exit 2; }

echo; echo "### effective PHP ini (php -i) ###"
INI="$(docker exec "$CNAME" php -i 2>/dev/null)"
echo "$INI" | grep -E '^display_errors =>' | grep -qi 'Off' && ok "display_errors Off" || bad "display_errors not Off"
echo "$INI" | grep -E '^display_startup_errors =>' | grep -qi 'Off' && ok "display_startup_errors Off" || bad "display_startup_errors not Off"
echo "$INI" | grep -E '^log_errors =>' | grep -qi 'On' && ok "log_errors On" || bad "log_errors not On"
echo "$INI" | grep -E '^error_log =>' | grep -q '/dev/stderr' && ok "error_log=/dev/stderr" || bad "error_log not /dev/stderr"

echo; echo "### php-fpm config test (-tt) + pool pin ###"
FPM="$(docker exec "$CNAME" php-fpm -tt 2>&1)"
echo "$FPM" | grep -q 'test is successful' && ok "php-fpm -tt passes" || bad "php-fpm -tt failed"
echo "$FPM" | grep -Eq 'display_errors\] = 0' && ok "pool pins display_errors=0 (php_admin)" || bad "pool does not pin display_errors"

echo; echo "### controlled error: absent from body, present on stderr ###"
BODY="$(docker exec "$CNAME" curl -s "http://127.0.0.1:8080/probe.php" 2>/dev/null)"
echo "$BODY" | grep -q 'SMOKE_BODY_START' && echo "$BODY" | grep -q 'SMOKE_BODY_END' && ok "probe body rendered" || bad "probe body missing"
echo "$BODY" | grep -q "$CANARY" && bad "error text LEAKED into HTTP body" || ok "error text ABSENT from HTTP body"
# stderr -> docker logs (php-fpm catch_workers_output -> supervisord -> container stderr)
sleep 1
LOGS="$(docker logs "$CNAME" 2>&1)"
echo "$LOGS" | grep -q "$CANARY" && ok "error text PRESENT on container stderr (docker logs)" || bad "error text NOT in container logs"

echo; echo "======================================================"
echo "RESULT: PASS=$PASS FAIL=$FAIL"
[ "$FAIL" = 0 ] && echo "IMAGE ERROR-CONFIG SMOKE PASSED" || echo "IMAGE ERROR-CONFIG SMOKE FAILED"
exit "$FAIL"
