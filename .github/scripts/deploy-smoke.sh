#!/usr/bin/env bash
# deploy-smoke.sh — install smoke test shared by all deploy-smoke.yml variants.
# Drives a fresh OpenSparrow instance through the real first-run path:
# setup wizard redirect -> setup_api test_connection/init_database -> wizard
# lock -> admin login with the generated password -> admin panel -> docroot guard.
#
# Required env: BASE_URL, DB_HOST, DB_NAME, DB_USER, DB_PASS
# Optional env: DB_PORT (default 5432)
#
# DB_HOST must be a hostname (db, pg, localhost), never a numeric private IP —
# setup_api.php rejects private/loopback IPs as an SSRF guard.
set -euo pipefail

BASE_URL="${BASE_URL:?BASE_URL is required}"
DB_HOST="${DB_HOST:?DB_HOST is required}"
DB_PORT="${DB_PORT:-5432}"
DB_NAME="${DB_NAME:?DB_NAME is required}"
DB_USER="${DB_USER:?DB_USER is required}"
DB_PASS="${DB_PASS:?DB_PASS is required}"

fail() { echo "::error::$1"; exit 1; }

echo "==> Waiting for ${BASE_URL} to respond"
for i in $(seq 1 60); do
  if curl -fso /dev/null "${BASE_URL}/setup.php"; then break; fi
  [ "$i" -eq 60 ] && fail "Application did not start within 120 s"
  sleep 2
done

echo "==> Fresh install redirects to the setup wizard"
FINAL=$(curl -sL -o /dev/null -w '%{url_effective}' "${BASE_URL}/")
echo "$FINAL" | grep -q "setup.php" || fail "Expected redirect to setup.php, landed on: ${FINAL}"

DB_JSON=$(jq -n \
  --arg h "$DB_HOST" --arg p "$DB_PORT" --arg d "$DB_NAME" \
  --arg u "$DB_USER" --arg w "$DB_PASS" \
  '{host:$h, port:($p|tonumber), dbname:$d, user:$u, password:$w, schema:"app", create_schema:true}')

echo "==> setup_api: test_connection"
RESP=$(curl -fs -X POST -H 'Content-Type: application/json' -d "$DB_JSON" \
  "${BASE_URL}/setup_api.php?action=test_connection")
echo "$RESP" | jq -e '.success == true' > /dev/null || fail "test_connection failed: ${RESP}"

echo "==> setup_api: init_database"
RESP=$(curl -fs -X POST -H 'Content-Type: application/json' -d "$DB_JSON" \
  "${BASE_URL}/setup_api.php?action=init_database")
echo "$RESP" | jq -e '.success == true' > /dev/null || fail "init_database failed: ${RESP}"
ADMIN_PASS=$(echo "$RESP" | jq -er '.admin_password') \
  || fail "init_database did not return admin_password"

echo "==> Wizard is locked after initialization (expect 403)"
CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
  -d "$DB_JSON" "${BASE_URL}/setup_api.php?action=test_connection")
[ "$CODE" = "403" ] || fail "Expected 403 from the locked wizard, got ${CODE}"

echo "==> Admin login with the generated password"
JAR=$(mktemp)
CSRF=$(curl -fs -c "$JAR" "${BASE_URL}/login.php" \
  | grep -o 'name="csrf_token" value="[^"]*"' | head -n1 | sed 's/.*value="//; s/"$//')
[ -n "$CSRF" ] || fail "Could not extract the CSRF token from the login page"

LOC=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST \
  --data-urlencode "csrf_token=${CSRF}" \
  --data-urlencode "username=admin" \
  --data-urlencode "password=${ADMIN_PASS}" \
  "${BASE_URL}/login.php")
echo "$LOC" | grep -q "admin" || fail "Login did not redirect to the admin panel (redirect: '${LOC}')"

echo "==> Admin panel renders with the session cookie"
curl -fs -b "$JAR" -o /dev/null "${BASE_URL}/admin/" \
  || fail "GET /admin/ failed with an authenticated session"

echo "==> Backend files are not web-reachable"
CODE=$(curl -s -o /dev/null -w '%{http_code}' "${BASE_URL}/config/database.json")
[ "$CODE" != "200" ] || fail "config/database.json is reachable over HTTP — document root is wrong"

echo "Smoke test passed: setup wizard, initialization, login, and docroot isolation all work."
