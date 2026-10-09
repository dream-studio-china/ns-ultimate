#!/usr/bin/env bash
# Inventory management smoke test. Requires a running local Symfony server.
set -euo pipefail

BASE="${1:-http://127.0.0.1:8000}"
SUFFIX="$(date +%s)"
PASSWORD='P@ssw0rd'

api() {
  local method="$1" path="$2" token="$3" body="$4" expected="$5"
  local args=(-s -o /tmp/inventory-smoke-response -w '%{http_code}' -X "$method" "$BASE$path" -H 'Content-Type: application/json')
  [[ -n "$token" ]] && args+=(-H "Authorization: Bearer $token")
  local code
  code=$(curl --noproxy '*' "${args[@]}" ${body:+--data "$body"})
  [[ "$code" == "$expected" ]] || { printf 'expected=%s actual=%s body=%s\n' "$expected" "$code" "$(head -c 500 /tmp/inventory-smoke-response)" >&2; exit 1; }
}

json() { python3 -c "import json,sys; print(json.load(sys.stdin)$1)"; }

LOGIN_CODE=$(curl --noproxy '*' -s -o /dev/null -w '%{http_code}' -X POST "$BASE/api/auth/login" -H 'Content-Type: application/json' -d '{"identifier":"admin@example.com","password":"P@ssw0rd"}')
if [[ "$LOGIN_CODE" != '200' ]]; then
  symfony php bin/console app:identity:user:create admin@example.com admin "$PASSWORD" --admin
fi

api POST /api/auth/login '' '{"identifier":"admin@example.com","password":"P@ssw0rd"}' 200
ADMIN_TOKEN=$(python3 -c 'import json; print(json.load(open("/tmp/inventory-smoke-response"))["access_token"])')

MATERIAL_CODE="finished-$SUFFIX"
api POST /api/v1/manage/inventory/materials "$ADMIN_TOKEN" "{\"code\":\"$MATERIAL_CODE\",\"name\":\"Inventory Smoke Material\",\"kind\":\"finished\",\"unit\":\"piece\"}" 201
MATERIAL_UUID=$(python3 -c 'import json; print(json.load(open("/tmp/inventory-smoke-response"))["data"]["uuid"])')
STORE_UUID="00000000-0000-4000-8000-000000000001"
api PUT "/api/v1/manage/inventory/stocks/$STORE_UUID/$MATERIAL_UUID/policy" "$ADMIN_TOKEN" '{"allowNegativeStock":true}' 200
api POST "/api/v1/manage/inventory/stocks/$STORE_UUID/$MATERIAL_UUID/adjust" "$ADMIN_TOKEN" '{"quantityDelta":"5.000000","reason":"smoke receipt","referenceId":"inventory-smoke"}' 200
api GET "/api/v1/manage/inventory/stocks/$STORE_UUID/$MATERIAL_UUID" "$ADMIN_TOKEN" '' 200
AVAILABLE=$(python3 -c 'import json; print(json.load(open("/tmp/inventory-smoke-response"))["data"]["availableQuantity"])')
[[ "$AVAILABLE" == '5.000000' ]] || { printf 'unexpected available quantity: %s\n' "$AVAILABLE" >&2; exit 1; }

printf 'Inventory smoke passed: material=%s store=%s available=%s\n' "$MATERIAL_UUID" "$STORE_UUID" "$AVAILABLE"
