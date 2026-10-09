#!/usr/bin/env bash
# Store order orchestration smoke test. Requires a running local Symfony server.
set -euo pipefail

BASE="${1:-http://127.0.0.1:8000}"
SUFFIX="$(date +%s)"
PASSWORD='P@ssw0rd'
INVENTORY_ENABLED="${INVENTORY_ENABLED:-0}"
export INVENTORY_ENABLED
PASS=0
FAIL=0

api() {
  local label="$1" method="$2" path="$3" token="$4" body="$5" expected="$6" store_code="${7:-}"
  local args=(-s -o /tmp/store-smoke-response -w '%{http_code}' -X "$method" "$BASE$path" -H 'Content-Type: application/json')
  [[ -n "$token" ]] && args+=(-H "Authorization: Bearer $token")
  [[ -n "$store_code" ]] && args+=(-H "X-Store-Code: $store_code")
  local code
  code=$(curl --noproxy '*' "${args[@]}" ${body:+--data "$body"})
  if [[ "$code" != "$expected" ]]; then
    printf 'FAIL %-38s expected=%s actual=%s body=%s\n' "$label" "$expected" "$code" "$(head -c 300 /tmp/store-smoke-response)" >&2
    FAIL=$((FAIL + 1))
    return 1
  fi
  printf 'PASS %-38s (%s)\n' "$label" "$code"
  PASS=$((PASS + 1))
}

json() { python3 -c "import json,sys; print(json.load(sys.stdin)$1)"; }
body() { cat /tmp/store-smoke-response; }

echo "Store smoke: $BASE, suffix=$SUFFIX"

if [[ "$(curl --noproxy '*' -s -o /dev/null -w '%{http_code}' -X POST "$BASE/api/auth/login" -H 'Content-Type: application/json' -d '{"identifier":"admin@example.com","password":"P@ssw0rd"}')" != '200' ]]; then
  symfony php bin/console app:identity:user:create admin@example.com admin "$PASSWORD" --admin
fi

api 'admin login' POST /api/auth/login '' '{"identifier":"admin@example.com","password":"P@ssw0rd"}' 200
ADMIN_TOKEN=$(body | json '["access_token"]')
api 'read admin profile' GET /api/v1/app/users/me "$ADMIN_TOKEN" '' 200
ADMIN_UUID=$(body | json '["data"]["uuid"]')

USER_EMAIL="store_${SUFFIX}@example.test"
USER_NAME="store${SUFFIX}"
api 'register store customer' POST /api/auth/register '' "{\"email\":\"$USER_EMAIL\",\"username\":\"$USER_NAME\",\"password\":\"$PASSWORD\"}" 201
api 'customer login' POST /api/auth/login '' "{\"identifier\":\"$USER_EMAIL\",\"password\":\"$PASSWORD\"}" 200
USER_TOKEN=$(body | json '["access_token"]')

STORE_CODE="smoke-$SUFFIX"
api 'create active store' POST /api/v1/manage/stores "$ADMIN_TOKEN" "{\"code\":\"$STORE_CODE\",\"name\":\"Smoke Store\",\"timezone\":\"UTC\"}" 201
STORE_UUID=$(body | json '["data"]["uuid"]')
api 'grant admin store manager' POST "/api/v1/manage/stores/$STORE_UUID/members" "$ADMIN_TOKEN" "{\"userUuid\":\"$ADMIN_UUID\",\"role\":\"manager\"}" 201

api 'create product' POST /api/v1/manage/products "$ADMIN_TOKEN" "{\"name\":\"Store Smoke Product $SUFFIX\",\"status\":\"active\"}" 201
PRODUCT_ID=$(body | json '["data"]["id"]')
api 'create specification' POST "/api/v1/manage/products/$PRODUCT_ID/specifications" "$ADMIN_TOKEN" '{"name":"Default","price":1999,"status":"active"}' 201
SPEC_ID=$(body | json '["data"]["id"]')
SPEC_UUID=$(body | json '["data"]["uuid"]')

if [[ "$INVENTORY_ENABLED" == '1' ]]; then
  MATERIAL_CODE="store-smoke-material-$SUFFIX"
  api 'create inventory material' POST /api/v1/manage/inventory/materials "$ADMIN_TOKEN" "{\"code\":\"$MATERIAL_CODE\",\"name\":\"Store Smoke Material\",\"kind\":\"raw\",\"unit\":\"piece\"}" 201
  MATERIAL_UUID=$(body | json '["data"]["uuid"]')
  api 'enable negative inventory' PUT "/api/v1/manage/inventory/stocks/$STORE_UUID/$MATERIAL_UUID/policy" "$ADMIN_TOKEN" '{"allowNegativeStock":true}' 200
  api 'receive inventory stock' POST "/api/v1/manage/inventory/stocks/$STORE_UUID/$MATERIAL_UUID/adjust" "$ADMIN_TOKEN" '{"quantityDelta":"10.000000","reason":"store smoke receipt","referenceId":"store-smoke-receipt"}' 200
  api 'create specification recipe' POST /api/v1/manage/inventory/recipes "$ADMIN_TOKEN" "{\"specificationUuid\":\"$SPEC_UUID\",\"lines\":[{\"materialUuid\":\"$MATERIAL_UUID\",\"quantityPerUnit\":\"1.000000\"}]}" 201
fi

api 'create store-scoped order' POST /api/v1/app/orders "$USER_TOKEN" "{\"currency\":\"CNY\",\"items\":[{\"specificationId\":$SPEC_ID,\"quantity\":1}]}" 202 "$STORE_CODE"
ORDER_ID=$(body | json '["data"]["id"]')

# Exercise the production relay and Doctrine Messenger consumer path.
symfony php bin/console app:trade:outbox:publish --no-interaction
symfony php bin/console messenger:consume async --limit=20 --time-limit=10 --no-interaction
api 'staff lists pending order' GET "/api/v1/store/$STORE_UUID/orders" "$ADMIN_TOKEN" '' 200
STORE_ORDER_UUID=$(body | json '["data"][0]["uuid"]')
api 'staff sees accepted order' GET "/api/v1/store/$STORE_UUID/orders/$STORE_ORDER_UUID" "$ADMIN_TOKEN" '' 200
STORE_STATUS=$(body | json '["data"]["operationalStatus"]')
if [[ "$INVENTORY_ENABLED" == '1' ]]; then
  [[ "$STORE_STATUS" == 'awaiting_inventory' ]] || { printf 'FAIL expected awaiting_inventory, got %s\n' "$STORE_STATUS" >&2; exit 1; }
  symfony php bin/console app:store:outbox:publish --no-interaction
  symfony php bin/console messenger:consume async --limit=20 --time-limit=10 --no-interaction
  symfony php bin/console app:inventory:outbox:publish --no-interaction
  symfony php bin/console messenger:consume async --limit=20 --time-limit=10 --no-interaction
  api 'staff sees inventory accepted order' GET "/api/v1/store/$STORE_UUID/orders/$STORE_ORDER_UUID" "$ADMIN_TOKEN" '' 200
  STORE_STATUS=$(body | json '["data"]["operationalStatus"]')
fi
if [[ "$STORE_STATUS" != 'accepted' ]]; then
  printf 'FAIL expected Store status accepted, got %s\n' "$STORE_STATUS" >&2
  exit 1
fi
symfony php bin/console app:store:outbox:publish --no-interaction
symfony php bin/console messenger:consume async --limit=20 --time-limit=10 --no-interaction

api 'trade receives acceptance' GET "/api/v1/app/orders/$ORDER_ID" "$USER_TOKEN" '' 200
STATUS=$(body | json '["data"]["status"]')
if [[ "$STATUS" != 'store_accepted' ]]; then
  printf 'FAIL expected Trade status store_accepted, got %s\n' "$STATUS" >&2
  exit 1
fi

printf 'Store smoke passed: %d HTTP checks, order=%s, store=%s\n' "$PASS" "$ORDER_ID" "$STORE_UUID"
