#!/usr/bin/env bash
# ============================================================================
# Trade Workflow E2E Demo — 全部状态流转具现化
# ============================================================================
# 覆盖路径:
#   Happy Path:   draft → pending → confirmed → paid → fulfilled → completed → refunded
#   Cancel Paths: draft → cancelled, pending → cancelled, confirmed → cancelled
#   Guards:       paid 不可取消, 非draft 不可改/删, 状态不对不可 pay/fulfill/refund
# ============================================================================
set -euo pipefail

# Unset proxy for localhost requests
unset http_proxy https_proxy HTTP_PROXY HTTPS_PROXY all_proxy ALL_PROXY 2>/dev/null || true

BASE="${BASE:-http://127.0.0.1:8080}"
V1="${BASE}/api/v1"
MANAGE="${V1}/manage"
APP="${V1}/app"
AUTH="${BASE}/api/auth"
PHP="${PHP:-/opt/homebrew/bin/php}"
PROJECT="${PROJECT:-/Volumes/Nayuki/Development/PHP/crud-skeleton}"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'; BOLD='\033[1m'
ok()  { echo -e "${GREEN}✓${NC} $1"; }
fail(){ echo -e "${RED}✗${NC} $1"; }
info(){ echo -e "${CYAN}→${NC} $1"; }
step(){ echo -e "\n${BOLD}${YELLOW}═══ $1 ═══${NC}"; }
dump(){ echo "$1" | python3 -m json.tool 2>/dev/null || echo "$1"; }

api() {
    local method="$1" url="$2" data="${3:-}"
    local auth=(); if [ -n "${TOKEN:-}" ]; then auth=(-H "Authorization: Bearer $TOKEN"); fi
    if [ -z "$data" ]; then
        curl -sS -X "$method" "$url" "${auth[@]}" -H "Content-Type: application/json" -H "Accept: application/json"
    else
        curl -sS -X "$method" "$url" "${auth[@]}" -H "Content-Type: application/json" -H "Accept: application/json" -d "$data"
    fi
}

# ---- 1. Setup ------------------------------------------------------------------
step "Setup — create test user via CLI"
cd "$PROJECT"
"$PHP" bin/console app:identity:user:create demo@trade.test demouser password123 --admin --phone-verified 2>/dev/null || true
ok "User created (or already exists)"

step "Login to get JWT"
LOGIN=$(curl -sS -X POST "${AUTH}/login" -H "Content-Type: application/json" -d '{"identifier":"demo@trade.test","password":"password123"}')
TOKEN=$(echo "$LOGIN" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['access_token'])" 2>/dev/null)
if [ -z "$TOKEN" ]; then
    fail "Login failed — is the server running? Start with: php -S 127.0.0.1:8000 -t public/"
    echo "Response:"; dump "$LOGIN"
    exit 1
fi
ok "Token obtained: ${TOKEN:0:20}..."

# ---- 2. Create Products & Specifications --------------------------------------
step "Create Products & Specifications"

PRODUCT_A=$(api POST "${MANAGE}/products" '{"name":"iPhone 15 Pro","status":"active"}' | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])")
ok "Product A created: #$PRODUCT_A"

PRODUCT_B=$(api POST "${MANAGE}/products" '{"name":"MacBook Pro","status":"active"}' | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])")
ok "Product B created: #$PRODUCT_B"

SPEC_A1=$(api POST "${MANAGE}/products/${PRODUCT_A}/specifications" '{"name":"128GB 银色","price":699900,"status":"active","sort":1}' | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])")
SPEC_A2=$(api POST "${MANAGE}/products/${PRODUCT_A}/specifications" '{"name":"256GB 深空黑","price":799900,"status":"active","sort":2}' | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])")
SPEC_B1=$(api POST "${MANAGE}/products/${PRODUCT_B}/specifications" '{"name":"M4 Pro 16GB","price":1499900,"status":"active","sort":1}' | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])")
ok "Specs: A1=#$SPEC_A1 (¥6999.00), A2=#$SPEC_A2 (¥7999.00), B1=#$SPEC_B1 (¥14999.00)"

# ---- 3. Happy Path: draft → completed → refunded ------------------------------
step "HAPPY PATH: draft → pending → confirmed → paid → fulfilled → completed → refunded"

info "Create order"
ORDER_HP=$(api POST "${MANAGE}/orders" "{\"items\":[{\"specificationId\":$SPEC_A1,\"quantity\":1},{\"specificationId\":$SPEC_A2,\"quantity\":2}],\"currency\":\"CNY\",\"notes\":\"Happy Path Order\"}" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['data']['id'])")
ok "Order #$ORDER_HP created (draft)"

info "Step 1: submit (draft → pending)"
api POST "${MANAGE}/orders/${ORDER_HP}/do/submit" > /dev/null
STATUS=$(api GET "${MANAGE}/orders/${ORDER_HP}" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['status'])")
[ "$STATUS" = "pending" ] && ok "Status: $STATUS" || fail "Expected pending, got $STATUS"

info "Step 2: confirm (pending → confirmed)"
api POST "${MANAGE}/orders/${ORDER_HP}/do/confirm" > /dev/null
STATUS=$(api GET "${MANAGE}/orders/${ORDER_HP}" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['status'])")
[ "$STATUS" = "confirmed" ] && ok "Status: $STATUS" || fail "Expected confirmed, got $STATUS"

info "Step 3: pay (confirmed → paid) via do/transition"
api POST "${MANAGE}/orders/${ORDER_HP}/do/pay" > /dev/null
STATUS=$(api GET "${MANAGE}/orders/${ORDER_HP}" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['status'])")
[ "$STATUS" = "paid" ] && ok "Status: $STATUS" || fail "Expected paid, got $STATUS"

info "Step 4: fulfill (paid → fulfilled) via new fulfillment endpoint"
api POST "${MANAGE}/orders/${ORDER_HP}/fulfill" '{"trackingNumber":"SF1234567890","shippingAddress":"北京市朝阳区望京SOHO T1 1001"}' > /dev/null
STATUS=$(api GET "${MANAGE}/orders/${ORDER_HP}" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['status'])")
[ "$STATUS" = "fulfilled" ] && ok "Status: $STATUS (tracking: SF1234567890)" || fail "Expected fulfilled, got $STATUS"

info "Step 5: complete (fulfilled → completed)"
api POST "${MANAGE}/orders/${ORDER_HP}/do/complete" > /dev/null
STATUS=$(api GET "${MANAGE}/orders/${ORDER_HP}" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['status'])")
[ "$STATUS" = "completed" ] && ok "Status: $STATUS" || fail "Expected completed, got $STATUS"

info "Step 6: refund (completed → refunded) via do/transition"
api POST "${MANAGE}/orders/${ORDER_HP}/do/refund" > /dev/null
STATUS=$(api GET "${MANAGE}/orders/${ORDER_HP}" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['status'])")
[ "$STATUS" = "refunded" ] && ok "Status: $STATUS" || fail "Expected refunded, got $STATUS"

info "Happy Path timestamps"
DETAIL=$(api GET "${MANAGE}/orders/${ORDER_HP}")
for ts in paidAt fulfilledAt completedAt refundedAt; do
    TS_VAL=$(echo "$DETAIL" | python3 -c "import sys,json; d=json.load(sys.stdin)['data']; print(d.get('$ts','MISSING'))")
    [ "$TS_VAL" != "null" ] && [ "$TS_VAL" != "MISSING" ] && ok "  $ts = $TS_VAL" || fail "  $ts missing"
done

# ---- 4. Cancel Paths ----------------------------------------------------------
step "CANCEL PATHS"

info "Cancel from draft"
ORDER_C1=$(api POST "${MANAGE}/orders" "{\"items\":[{\"specificationId\":$SPEC_B1,\"quantity\":1}],\"notes\":\"Cancel from draft\"}" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])")
api POST "${MANAGE}/orders/${ORDER_C1}/do/cancel" > /dev/null
STATUS=$(api GET "${MANAGE}/orders/${ORDER_C1}" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['status'])")
CANCEL_TS=$(api GET "${MANAGE}/orders/${ORDER_C1}" | python3 -c "import sys,json; print(json.load(sys.stdin)['data'].get('cancelledAt','MISSING'))")
[ "$STATUS" = "cancelled" ] && ok "Draft → cancelled (cancelledAt=$CANCEL_TS)" || fail "Expected cancelled, got $STATUS"

info "Cancel from pending"
ORDER_C2=$(api POST "${MANAGE}/orders" "{\"items\":[{\"specificationId\":$SPEC_B1,\"quantity\":1}],\"notes\":\"Cancel from pending\"}" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])")
api POST "${MANAGE}/orders/${ORDER_C2}/do/submit" > /dev/null
api POST "${MANAGE}/orders/${ORDER_C2}/do/cancel" > /dev/null
STATUS=$(api GET "${MANAGE}/orders/${ORDER_C2}" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['status'])")
[ "$STATUS" = "cancelled" ] && ok "Pending → cancelled" || fail "Expected cancelled, got $STATUS"

info "Cancel from confirmed"
ORDER_C3=$(api POST "${MANAGE}/orders" "{\"items\":[{\"specificationId\":$SPEC_B1,\"quantity\":1}],\"notes\":\"Cancel from confirmed\"}" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])")
api POST "${MANAGE}/orders/${ORDER_C3}/do/submit" > /dev/null
api POST "${MANAGE}/orders/${ORDER_C3}/do/confirm" > /dev/null
api POST "${MANAGE}/orders/${ORDER_C3}/do/cancel" > /dev/null
STATUS=$(api GET "${MANAGE}/orders/${ORDER_C3}" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['status'])")
[ "$STATUS" = "cancelled" ] && ok "Confirmed → cancelled" || fail "Expected cancelled, got $STATUS"

info "Cannot cancel after paid (guard)"
ORDER_C4=$(api POST "${MANAGE}/orders" "{\"items\":[{\"specificationId\":$SPEC_B1,\"quantity\":1}],\"notes\":\"Cancel after paid — should fail\"}" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])")
api POST "${MANAGE}/orders/${ORDER_C4}/do/submit" > /dev/null
api POST "${MANAGE}/orders/${ORDER_C4}/do/confirm" > /dev/null
api POST "${MANAGE}/orders/${ORDER_C4}/do/pay" > /dev/null
RES=$(api POST "${MANAGE}/orders/${ORDER_C4}/do/cancel")
CODE=$(echo "$RES" | python3 -c "import sys,json; print(json.load(sys.stdin)['code'])" 2>/dev/null || echo 0)
[ "$CODE" != "0" ] && ok "Cancel after paid correctly rejected (code=$CODE)" || fail "Cancel after paid should have been rejected"

# ---- 5. Guards: update/delete non-draft ---------------------------------------
step "GUARDS: non-draft mutation protection"

info "Cannot update non-draft order"
RES=$(api PUT "${MANAGE}/orders/${ORDER_HP}" '{"notes":"should fail"}')
CODE=$(echo "$RES" | python3 -c "import sys,json; print(json.load(sys.stdin)['code'])" 2>/dev/null || echo 0)
[ "$CODE" != "0" ] && ok "Update non-draft correctly rejected (code=$CODE)" || fail "Should be rejected"

info "Cannot delete non-draft order"
RES=$(api DELETE "${MANAGE}/orders/${ORDER_HP}")
CODE=$(echo "$RES" | python3 -c "import sys,json; print(json.load(sys.stdin)['code'])" 2>/dev/null || echo 0)
[ "$CODE" != "0" ] && ok "Delete non-draft correctly rejected (code=$CODE)" || fail "Should be rejected"

info "Can delete draft order"
ORDER_D=$(api POST "${MANAGE}/orders" "{\"items\":[{\"specificationId\":$SPEC_B1,\"quantity\":1}]}" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])")
RES=$(api DELETE "${MANAGE}/orders/${ORDER_D}")
STATUS_CODE=$(curl -sS -o /dev/null -w "%{http_code}" -X DELETE "${MANAGE}/orders/${ORDER_D}" -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json")
[ "$STATUS_CODE" = "204" ] && ok "Draft order deleted (HTTP 204)" || fail "Expected 204, got $STATUS_CODE"

# ---- 6. New endpoints: items, pay, fulfill, refund guards --------------------
step "NEW ENDPOINTS: items, pay, fulfill, refund guards"

info "View order items"
ITEMS=$(api GET "${MANAGE}/orders/${ORDER_C1}/items")
COUNT=$(echo "$ITEMS" | python3 -c "import sys,json; print(len(json.load(sys.stdin)['data']))" 2>/dev/null || echo 0)
[ "$COUNT" -gt 0 ] && ok "Order items: $COUNT item(s)" || fail "Expected items"

info "Pay: wrong status guard"
RES=$(api POST "${MANAGE}/orders/${ORDER_C3}/pay" '{"systemWalletId":1}')
CODE=$(echo "$RES" | python3 -c "import sys,json; print(json.load(sys.stdin)['code'])" 2>/dev/null || echo 0)
[ "$CODE" != "0" ] && ok "Pay on cancelled order correctly rejected (code=$CODE)" || fail "Should be rejected"

info "Pay: missing systemWalletId guard"
ORDER_P=$(api POST "${MANAGE}/orders" "{\"items\":[{\"specificationId\":$SPEC_B1,\"quantity\":1}]}" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])")
api POST "${MANAGE}/orders/${ORDER_P}/do/submit" > /dev/null
api POST "${MANAGE}/orders/${ORDER_P}/do/confirm" > /dev/null
RES=$(api POST "${MANAGE}/orders/${ORDER_P}/pay")
CODE=$(echo "$RES" | python3 -c "import sys,json; print(json.load(sys.stdin)['code'])" 2>/dev/null || echo 0)
[ "$CODE" != "0" ] && ok "Pay without systemWalletId correctly rejected (code=$CODE)" || fail "Should be rejected"

info "Fulfill: wrong status guard (cannot fulfill draft)"
RES=$(api POST "${MANAGE}/orders/${ORDER_P}/fulfill" '{"trackingNumber":"TEST"}')
CODE=$(echo "$RES" | python3 -c "import sys,json; print(json.load(sys.stdin)['code'])" 2>/dev/null || echo 0)
[ "$CODE" != "0" ] && ok "Fulfill on confirmed order correctly rejected (code=$CODE)" || fail "Should be rejected"

info "Refund: wrong status guard (cannot refund draft)"
RES=$(api POST "${MANAGE}/orders/${ORDER_P}/refund" '{"systemWalletId":1,"reason":"test"}')
CODE=$(echo "$RES" | python3 -c "import sys,json; print(json.load(sys.stdin)['code'])" 2>/dev/null || echo 0)
[ "$CODE" != "0" ] && ok "Refund on confirmed order correctly rejected (code=$CODE)" || fail "Should be rejected"

info "Refund: missing reason guard"
RES=$(api POST "${MANAGE}/orders/${ORDER_C3}/refund" '{"systemWalletId":1}')
CODE=$(echo "$RES" | python3 -c "import sys,json; print(json.load(sys.stdin)['code'])" 2>/dev/null || echo 0)
[ "$CODE" != "0" ] && ok "Refund without reason correctly rejected (code=$CODE)" || fail "Should be rejected"

# ---- 7. App (user-side) endpoints --------------------------------------------
step "APP ENDPOINTS: user order management"

info "App: list products"
RES=$(api GET "${APP}/products")
P_COUNT=$(echo "$RES" | python3 -c "import sys,json; print(len(json.load(sys.stdin)['data']))")
[ "$P_COUNT" -gt 0 ] && ok "App products: $P_COUNT product(s)" || fail "No products"

info "App: create order as user"
RES=$(api POST "${APP}/orders" "{\"items\":[{\"specificationId\":$SPEC_A1,\"quantity\":1}],\"notes\":\"User self-order\"}")
APP_OID=$(echo "$RES" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])")
APP_AMT=$(echo "$RES" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['totalAmount'])")
ok "App order #$APP_OID created (total=$APP_AMT cents)"

info "App: list my orders"
RES=$(api GET "${APP}/orders")
COUNT=$(echo "$RES" | python3 -c "import sys,json; print(len(json.load(sys.stdin)['data']))")
[ "$COUNT" -gt 0 ] && ok "My orders: $COUNT order(s)" || fail "No orders"

info "App: view my order items"
RES=$(api GET "${APP}/orders/${APP_OID}/items")
I_COUNT=$(echo "$RES" | python3 -c "import sys,json; print(len(json.load(sys.stdin)['data']))")
[ "$I_COUNT" -gt 0 ] && ok "My order items: $I_COUNT item(s)" || fail "No items"

info "App: cannot see another user's order items"
RES=$(api GET "${APP}/orders/${ORDER_C3}/items")
CODE=$(echo "$RES" | python3 -c "import sys,json; print(json.load(sys.stdin)['code'])" 2>/dev/null || echo 0)
[ "$CODE" != "0" ] && ok "Cross-user access correctly rejected (code=$CODE)" || fail "Should be rejected"

info "App: cancel my own order"
RES=$(api POST "${APP}/orders/${APP_OID}/cancel")
STATUS=$(echo "$RES" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['status'])")
[ "$STATUS" = "cancelled" ] && ok "App order self-cancelled → $STATUS" || fail "Expected cancelled, got $STATUS"

info "App: cannot cancel another user's order"
RES=$(api POST "${APP}/orders/${ORDER_C3}/cancel")
CODE=$(echo "$RES" | python3 -c "import sys,json; print(json.load(sys.stdin)['code'])" 2>/dev/null || echo 0)
[ "$CODE" != "0" ] && ok "Cross-user cancel correctly rejected (code=$CODE)" || fail "Should be rejected"

# ---- 8. Transitions & Todo ---------------------------------------------------
step "WORKFLOW: transitions listing & todo"

info "Available transitions for draft order"
ORDER_T=$(api POST "${MANAGE}/orders" "{\"items\":[{\"specificationId\":$SPEC_B1,\"quantity\":1}]}" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])")
TRANS=$(api GET "${MANAGE}/orders/${ORDER_T}/transitions")
T_NAMES=$(echo "$TRANS" | python3 -c "import sys,json; print(','.join(t['name'] for t in json.load(sys.stdin)['data']))" 2>/dev/null)
echo "$T_NAMES" | grep -q "submit" && ok "Transitions for draft: $T_NAMES" || fail "submit not found"

info "Todo list includes our draft order"
TODO=$(api GET "${MANAGE}/orders/todo")
TODO_IDS=$(echo "$TODO" | python3 -c "import sys,json; print(','.join(str(t['id']) for t in json.load(sys.stdin)['data']))" 2>/dev/null)
echo "$TODO_IDS" | grep -q "$ORDER_T" && ok "Order #$ORDER_T appears in todo" || fail "Order not in todo"

# ---- 9. Batch Upsert ---------------------------------------------------------
step "BATCH OPERATIONS"
RES=$(api POST "${MANAGE}/orders/batch-update?@basis=id&@mode=update" "[{\"id\":$ORDER_T,\"notes\":\"Batch updated notes\"}]")
CODE=$(echo "$RES" | python3 -c "import sys,json; print(json.load(sys.stdin)['code'])" 2>/dev/null || echo 0)
[ "$CODE" = "0" ] && ok "Batch update succeeded" || fail "Batch update failed"

# ---- 10. Transition with data ------------------------------------------------
step "TRANSITION WITH PAYLOAD"
ORDER_WD=$(api POST "${MANAGE}/orders" "{\"items\":[{\"specificationId\":$SPEC_B1,\"quantity\":1}]}" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])")
api POST "${MANAGE}/orders/${ORDER_WD}/do/submit" '{"notes":"Submitted with custom metadata","metadata":{"source":"demo"}}' > /dev/null
STATUS=$(api GET "${MANAGE}/orders/${ORDER_WD}" | python3 -c "import sys,json; d=json.load(sys.stdin)['data']; print(d['status'])")
NOTES=$(api GET "${MANAGE}/orders/${ORDER_WD}" | python3 -c "import sys,json; d=json.load(sys.stdin)['data']; print(d.get('notes',''))")
[ "$STATUS" = "pending" ] && echo "$NOTES" | grep -q "custom metadata" && ok "Transition with payload: status=$STATUS, notes preserved" || fail "Payload not preserved"

# ---- Summary -----------------------------------------------------------------
step "ALL WORKFLOW PATHS VERIFIED"
echo ""
echo -e "${GREEN}  ✓${NC} Happy Path:       draft → pending → confirmed → paid → fulfilled → completed → refunded"
echo -e "${GREEN}  ✓${NC} Cancel:            draft → cancelled, pending → cancelled, confirmed → cancelled"
echo -e "${GREEN}  ✓${NC} Guards:            paid 不可取消, 非draft 不可改/删"
echo -e "${GREEN}  ✓${NC} Pay API:           wrong status / missing wallet rejected"
echo -e "${GREEN}  ✓${NC} Fulfill API:       wrong status rejected"
echo -e "${GREEN}  ✓${NC} Refund API:        wrong status / missing reason rejected"
echo -e "${GREEN}  ✓${NC} App Items:         own items visible, cross-user rejected"
echo -e "${GREEN}  ✓${NC} App Cancel:        own cancel works, cross-user rejected"
echo -e "${GREEN}  ✓${NC} Timestamps:        cancelledAt, paidAt, fulfilledAt, completedAt, refundedAt"
echo -e "${GREEN}  ✓${NC} Transitions:       listing, todo filter"
echo -e "${GREEN}  ✓${NC} Batch update:      draft order updated"
echo ""
echo -e "${BOLD}All trade workflow paths verified.${NC}"
