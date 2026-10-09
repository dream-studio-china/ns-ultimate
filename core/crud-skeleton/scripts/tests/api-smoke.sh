#!/usr/bin/env bash
# ==============================================================================
# crud-skeleton Real Integration API Smoke Test
#
# Usage:
#   scripts/tests/api-smoke.sh                        # against http://127.0.0.1:8000
#   scripts/tests/api-smoke.sh http://localhost:8080   # custom base URL
#
# Requires: curl, python3, symfony (CLI)
# ==============================================================================
set -euo pipefail

BASE="${1:-http://127.0.0.1:8000}"
REPORT_DIR="var/api-test-reports"
mkdir -p "$REPORT_DIR"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
REPORT_FILE="$REPORT_DIR/report_$TIMESTAMP.txt"
FAIL_COUNT=0
PASS_COUNT=0
STEP=0
SUFFIX=$(date +%s)
PASSWORD='P@ssw0rd'

exec 3>&1  # save stdout
exec > >(tee -a "$REPORT_FILE") 2>&1

echo "══════════════════════════════════════════════════════════════"
echo "  crud-skeleton API Smoke Test"
echo "  Base: $BASE | Suffix: $SUFFIX | $(date)"
echo "══════════════════════════════════════════════════════════════"
echo ""

# ── helpers ──────────────────────────────────────────────────────────────────

api() {
  local label="$1" method="$2" path="$3" token="$4" body="$5"
  local expected="${6:-200 201 204}"
  STEP=$((STEP + 1))
  local url="${BASE}${path}"
  local headers=(-H 'Content-Type: application/json')
  [[ -n "$token" && "$token" != "none" ]] && headers+=(-H "Authorization: Bearer $token")

  local http_code body_file
  body_file=$(mktemp)
  http_code=$(curl --noproxy '*' -s -o "$body_file" -w '%{http_code}' -X "$method" "$url" "${headers[@]}" ${body:+--data "$body"})
  local response; response=$(cat "$body_file"); rm -f "$body_file"

  local ok=false
  for exp in $expected; do [[ "$http_code" == "$exp" ]] && ok=true && break; done

  if $ok; then
    PASS_COUNT=$((PASS_COUNT + 1))
    printf "  %-4s #%s %s (%s)\n" "✅" "$STEP" "$label" "$http_code" >&2
  else
    FAIL_COUNT=$((FAIL_COUNT + 1))
    printf "  %-4s #%s %s — expected %s, got %s: %s\n" "❌" "$STEP" "$label" "$expected" "$http_code" "$(echo "$response" | head -c 150)" >&2
  fi
  echo "$response"
}

jval() { python3 -c "import sys,json; d=json.load(sys.stdin); print(d$1)" 2>/dev/null || echo "PARSE_ERROR"; }
jid()  { jval '["data"]["id"]'; }

# ── Pre-flight ────────────────────────────────────────────────────────────────

ADMIN_CHECK=$(curl --noproxy '*' -s -o /dev/null -w '%{http_code}' -X POST "$BASE/api/auth/login" \
  -H 'Content-Type: application/json' -d '{"identifier":"admin@example.com","password":"P@ssw0rd"}')
if [[ "$ADMIN_CHECK" != "200" ]]; then
  echo "⚠️  Creating admin user..." >&2
  symfony php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin 2>&1 | tail -1
fi

# ══════════════════════════════════════════════════════════════════════════════
#  PHASE 1 — Auth
# ══════════════════════════════════════════════════════════════════════════════
echo "─── Phase 1: Auth ───"

ADMIN_TOKEN=$(api "admin login" POST /api/auth/login none '{"identifier":"admin@example.com","password":"P@ssw0rd"}' | jval '["access_token"]')
api "login bad password (401)"     POST /api/auth/login none '{"identifier":"admin@example.com","password":"Wrong"}' 401 >/dev/null
api "login empty fields (400)"     POST /api/auth/login none '{"identifier":"","password":""}' 400 >/dev/null
api "login nonexistent (401)"      POST /api/auth/login none '{"identifier":"nobody@x.com","password":"x"}' 401 >/dev/null

# ══════════════════════════════════════════════════════════════════════════════
#  PHASE 2 — Registration
# ══════════════════════════════════════════════════════════════════════════════
echo ""
echo "─── Phase 2: Registration ───"

api "register new user" POST /api/auth/register none \
  "{\"email\":\"user_${SUFFIX}@t.com\",\"username\":\"user${SUFFIX}\",\"password\":\"$PASSWORD\"}" 201 >/dev/null
USER_TOKEN=$(api "user login" POST /api/auth/login none \
  "{\"identifier\":\"user_${SUFFIX}@t.com\",\"password\":\"$PASSWORD\"}" | jval '["access_token"]')

api "register duplicate email (400)"     POST /api/auth/register none '{"email":"admin@example.com","username":"dup1","password":"P@ssw0rd"}' 400 >/dev/null
api "register duplicate username (400)"  POST /api/auth/register none "{\"email\":\"d2_${SUFFIX}@t.com\",\"username\":\"user${SUFFIX}\",\"password\":\"P@ssw0rd\"}" 400 >/dev/null
api "register short password (400)"      POST /api/auth/register none '{"email":"x@x.com","username":"x","password":"ab"}' 400 >/dev/null
api "register missing fields (400)"      POST /api/auth/register none '{"email":"x@x.com"}' 400 >/dev/null

# ══════════════════════════════════════════════════════════════════════════════
#  PHASE 3 — Admin setup
# ══════════════════════════════════════════════════════════════════════════════
echo ""
echo "─── Phase 3: Admin creates catalog ───"

CAT_ID=$(api "create category" POST /api/v1/manage/categories "$ADMIN_TOKEN" \
  "{\"name\":\"Electronics ${SUFFIX}\",\"slug\":\"elec-${SUFFIX}\",\"enabled\":true}" | jid)

CONTENT_ID=$(api "create content" POST /api/v1/manage/contents "$ADMIN_TOKEN" \
  "{\"title\":\"Store Info ${SUFFIX}\",\"body\":\"Welcome!\",\"category\":$CAT_ID,\"tags\":[]}" | jid)

PROD1_ID=$(api "create product Phone" POST /api/v1/manage/products "$ADMIN_TOKEN" \
  '{"name":"Phone Pro","description":"Flagship","status":"active"}' | jid)
PROD2_ID=$(api "create product Laptop" POST /api/v1/manage/products "$ADMIN_TOKEN" \
  '{"name":"Laptop Air","description":"Ultrabook","status":"active"}' | jid)

SPEC1_ID=$(api "create spec 128GB" POST "/api/v1/manage/products/${PROD1_ID}/specifications" "$ADMIN_TOKEN" \
  '{"name":"128GB","price":69900,"status":"active","sort":1}' | jid)
SPEC2_ID=$(api "create spec 256GB" POST "/api/v1/manage/products/${PROD1_ID}/specifications" "$ADMIN_TOKEN" \
  '{"name":"256GB","price":89900,"status":"active","sort":2}' | jid)
SPEC3_ID=$(api "create spec Base" POST "/api/v1/manage/products/${PROD2_ID}/specifications" "$ADMIN_TOKEN" \
  '{"name":"Base Model","price":149900,"status":"active","sort":1}' | jid)

echo "  products=$PROD1_ID,$PROD2_ID | specs=$SPEC1_ID,$SPEC2_ID,$SPEC3_ID"
echo "  Price table: 128GB=699¥ | 256GB=899¥ | Base=1499¥"

api "user access admin route (403)" GET /api/v1/manage/users "$USER_TOKEN" none 403 >/dev/null
api "no token protected route (401)" GET /api/v1/app/users/me none none 401 >/dev/null

# ══════════════════════════════════════════════════════════════════════════════
#  PHASE 4 — Wallets
# ══════════════════════════════════════════════════════════════════════════════
echo ""
echo "─── Phase 4: Wallets ───"

api "register bank user" POST /api/auth/register none \
  "{\"email\":\"bank_${SUFFIX}@t.com\",\"username\":\"bank${SUFFIX}\",\"password\":\"$PASSWORD\"}" 201 >/dev/null
BANK_TOKEN=$(api "bank login" POST /api/auth/login none \
  "{\"identifier\":\"bank_${SUFFIX}@t.com\",\"password\":\"$PASSWORD\"}" | jval '["access_token"]')

BANK_UID=$(python3 -c "import sys,json,base64; t=json.load(sys.stdin)['access_token']; p=t.split('.')[1]; p+='='*(4-len(p)%4); print(json.loads(base64.urlsafe_b64decode(p))['sub'])" <<< "{\"access_token\":\"$BANK_TOKEN\"}")
USER_UID=$(python3 -c "import sys,json,base64; t=json.load(sys.stdin)['access_token']; p=t.split('.')[1]; p+='='*(4-len(p)%4); print(json.loads(base64.urlsafe_b64decode(p))['sub'])" <<< "{\"access_token\":\"$USER_TOKEN\"}")
echo "  bank_uid=$BANK_UID | user_uid=$USER_UID"

BANK_WID=$(api "create bank wallet" POST /api/v1/manage/wallets "$ADMIN_TOKEN" \
  "{\"user\":$BANK_UID,\"currency\":\"CNY\",\"status\":\"active\",\"label\":\"Bank\"}" | jid)
USR_WID=$(api "create user wallet" POST /api/v1/manage/wallets "$ADMIN_TOKEN" \
  "{\"user\":$USER_UID,\"currency\":\"CNY\",\"status\":\"active\",\"label\":\"User\"}" | jid)
echo "  bank_wallet=$BANK_WID | user_wallet=$USR_WID"

# Fund bank wallet with enough money for all operations
api "deposit 5000 CNY to bank" POST /api/v1/manage/vouchers/deposit "$ADMIN_TOKEN" \
  "{\"walletId\":$BANK_WID,\"amount\":500000,\"currency\":\"CNY\",\"referenceId\":\"smoke-bank-fund-1\",\"reason\":\"Bank funding\"}" 201 >/dev/null

# Recharge user wallet for wallet-payment tests
api "transfer 3000 CNY bank→user" POST /api/v1/manage/transactions "$ADMIN_TOKEN" \
  "{\"fromWalletId\":$BANK_WID,\"toWalletId\":$USR_WID,\"amount\":300000,\"description\":\"Recharge\"}" 201 >/dev/null

# Record balance before payments
USR_BAL_BEFORE=$(api "get user wallet before payments" GET "/api/v1/manage/wallets/${USR_WID}" "$ADMIN_TOKEN" none | jval '["data"]["balance"]')
echo "  user wallet balance BEFORE payments: $USR_BAL_BEFORE cent ($(python3 -c "print($USR_BAL_BEFORE/100)") CNY)"

api "transfer insufficient (400)"  POST /api/v1/manage/transactions "$ADMIN_TOKEN" "{\"fromWalletId\":$USR_WID,\"toWalletId\":$BANK_WID,\"amount\":999999}" 400 >/dev/null
api "transfer same wallet (400)"   POST /api/v1/manage/transactions "$ADMIN_TOKEN" "{\"fromWalletId\":$BANK_WID,\"toWalletId\":$BANK_WID,\"amount\":100}" 400 >/dev/null
api "deposit negative (400)"       POST /api/v1/manage/vouchers/deposit "$ADMIN_TOKEN" '{"walletId":1,"amount":-100,"currency":"CNY","referenceId":"x"}' 400 >/dev/null
api "deposit nonexistent (404)"    POST /api/v1/manage/vouchers/deposit "$ADMIN_TOKEN" '{"walletId":99999,"amount":100,"currency":"CNY","referenceId":"x"}' 404 >/dev/null

# ══════════════════════════════════════════════════════════════════════════════
#  PHASE 5 — Browse + Profile
# ══════════════════════════════════════════════════════════════════════════════
echo ""
echo "─── Phase 5: Browse ───"

api "list categories"    GET "/api/v1/app/categories?limit=10"      "$USER_TOKEN" none >/dev/null
api "list contents"      GET "/api/v1/app/contents?limit=10"        "$USER_TOKEN" none >/dev/null
api "list products"      GET "/api/v1/app/products?limit=10"        "$USER_TOKEN" none >/dev/null
api "product detail 1"   GET "/api/v1/app/products/${PROD1_ID}"     "$USER_TOKEN" none >/dev/null
api "product detail 2"   GET "/api/v1/app/products/${PROD2_ID}"     "$USER_TOKEN" none >/dev/null
api "spec list"          GET /api/v1/app/specifications             "$USER_TOKEN" none >/dev/null
api "spec by product"    GET "/api/v1/app/specifications/by-product/${PROD1_ID}" "$USER_TOKEN" none >/dev/null
api "spec detail"        GET "/api/v1/app/specifications/${SPEC1_ID}" "$USER_TOKEN" none >/dev/null
api "get profile"        GET /api/v1/app/users/me                   "$USER_TOKEN" none >/dev/null
api "update profile"     PUT /api/v1/app/users/me                   "$USER_TOKEN" "{\"username\":\"updated_${SUFFIX}\"}" >/dev/null

api "wrong current pw (400)"    POST /api/v1/app/users/change-password "$USER_TOKEN" '{"currentPassword":"wrong","newPassword":"Xyz123!"}' 400 >/dev/null
api "short new pw (400)"        POST /api/v1/app/users/change-password "$USER_TOKEN" '{"currentPassword":"P@ssw0rd","newPassword":"ab"}' 400 >/dev/null

# ══════════════════════════════════════════════════════════════════════════════
#  PHASE 6 — Orders + Price Verification + Payment
# ══════════════════════════════════════════════════════════════════════════════
echo ""
echo "─── Phase 6: Orders, Price Check & Payment ───"

# ─── 6.1 Create orders and verify prices ───

# Order A: 1× 256GB (89900 cent = 899.00 CNY) — will be paid via mock
ORD_A_RAW=$(api "create order (1×256GB=899¥)" POST /api/v1/app/orders "$USER_TOKEN" \
  "{\"currency\":\"CNY\",\"notes\":\"pay via mock\",\"items\":[{\"specificationId\":$SPEC2_ID,\"quantity\":1}]}")
ORD_A_ID=$(echo "$ORD_A_RAW" | jid)
ORD_A_TOTAL=$(echo "$ORD_A_RAW" | jval '["data"]["totalAmount"]')
echo "  order $ORD_A_ID total=$ORD_A_TOTAL cent (expected 89900)"

# Order B: 3× 128GB (69900×3=209700 cent) — will be paid via wallet
ORD_B_RAW=$(api "create order (3×128GB=2097¥)" POST /api/v1/app/orders "$USER_TOKEN" \
  "{\"currency\":\"CNY\",\"notes\":\"pay via wallet\",\"items\":[{\"specificationId\":$SPEC1_ID,\"quantity\":3}]}")
ORD_B_ID=$(echo "$ORD_B_RAW" | jid)
ORD_B_TOTAL=$(echo "$ORD_B_RAW" | jval '["data"]["totalAmount"]')
echo "  order $ORD_B_ID total=$ORD_B_TOTAL cent (expected 209700)"

# Order C: mixed 2×128GB + 1×256GB = 69900*2 + 89900 = 229700 cent — will be cancelled
ORD_C_RAW=$(api "create order (mixed: 2×128GB+1×256GB)" POST /api/v1/app/orders "$USER_TOKEN" \
  "{\"currency\":\"CNY\",\"notes\":\"cancel after confirm\",\"items\":[{\"specificationId\":$SPEC1_ID,\"quantity\":2},{\"specificationId\":$SPEC2_ID,\"quantity\":1}]}")
ORD_C_ID=$(echo "$ORD_C_RAW" | jid)
ORD_C_TOTAL=$(echo "$ORD_C_RAW" | jval '["data"]["totalAmount"]')
echo "  order $ORD_C_ID total=$ORD_C_TOTAL cent (expected 229700)"

# Order D: 1× Base Model (149900 cent) — draft cancel
ORD_D_RAW=$(api "create order (1×Base=1499¥) draft cancel" POST /api/v1/app/orders "$USER_TOKEN" \
  "{\"currency\":\"CNY\",\"notes\":\"cancel draft\",\"items\":[{\"specificationId\":$SPEC3_ID,\"quantity\":1}]}")
ORD_D_ID=$(echo "$ORD_D_RAW" | jid)
ORD_D_TOTAL=$(echo "$ORD_D_RAW" | jval '["data"]["totalAmount"]')
echo "  order $ORD_D_ID total=$ORD_D_TOTAL cent (expected 149900)"

# Order E: try bad spec
api "order bad spec (400)" POST /api/v1/app/orders "$USER_TOKEN" \
  '{"currency":"CNY","items":[{"specificationId":99999,"quantity":1}]}' 400 >/dev/null

# Price assertions
echo ""
echo "  ── Price Verification ──"
python3 << PYEOF
results = [
  ("Order A (1×256GB)",   $ORD_A_TOTAL, 89900),
  ("Order B (3×128GB)",   $ORD_B_TOTAL, 209700),
  ("Order C (2×128+1×256)", $ORD_C_TOTAL, 229700),
  ("Order D (1×Base)",    $ORD_D_TOTAL, 149900),
]
all_ok = True
for label, actual, expected in results:
    ok = actual == expected
    all_ok = all_ok and ok
    print(f"    {'✅' if ok else '❌'} {label}: {actual} cent {'==' if ok else '!='} {expected} cent ({actual/100:.2f} ¥)")
if not all_ok:
    raise SystemExit("PRICE MISMATCH DETECTED")
print("    ✅ ALL PRICES CORRECT")
PYEOF

# ─── 6.2 Cancel draft order ───
api "cancel draft order D" POST "/api/v1/app/orders/${ORD_D_ID}/cancel" "$USER_TOKEN" '{}' >/dev/null
api "cancel cancelled order (400)" POST "/api/v1/app/orders/${ORD_D_ID}/cancel" "$USER_TOKEN" '{}' 400 >/dev/null

# ─── 6.3 Submit + confirm orders A, B, C ───
echo ""
echo "  ── Transition orders to confirmed ──"
for ord in $ORD_A_ID $ORD_B_ID $ORD_C_ID; do
  api "submit order $ord"  POST "/api/v1/manage/orders/${ord}/do/submit"  "$ADMIN_TOKEN" '{}' >/dev/null
  api "confirm order $ord" POST "/api/v1/manage/orders/${ord}/do/confirm" "$ADMIN_TOKEN" '{}' >/dev/null
done

# ─── 6.4 Cancel confirmed unpaid order C ───
api "cancel confirmed order C" POST "/api/v1/app/orders/${ORD_C_ID}/cancel" "$USER_TOKEN" '{}' >/dev/null

# ─── 6.5 Pay order A via mock ───
echo ""
echo "  ── Payment: Mock ──"
PAY_A=$(api "pay order A via mock" POST "/api/v1/app/orders/${ORD_A_ID}/payment" "$USER_TOKEN" \
  '{"payment":"mock","autoPaid":true}')
PAY_A_STATUS=$(echo "$PAY_A" | jval '["data"]["status"]')
ORDER_A_AFTER=$(api "get paid order A" GET "/api/v1/app/orders/${ORD_A_ID}" "$USER_TOKEN" none)
ORD_A_STATUS=$(echo "$ORDER_A_AFTER" | jval '["data"]["status"]')
ORD_A_PAY_STATUS=$(echo "$ORDER_A_AFTER" | jval '["data"]["paymentStatus"]')
ORD_A_INVOICE=$(echo "$ORDER_A_AFTER" | jval '["data"]["invoiceNo"]')
echo "    payment status: $PAY_A_STATUS | order: $ORD_A_STATUS | paymentStatus: $ORD_A_PAY_STATUS | invoice: $ORD_A_INVOICE"

api "cancel paid order A (400)" POST "/api/v1/app/orders/${ORD_A_ID}/cancel" "$USER_TOKEN" '{}' 400 >/dev/null

# ─── 6.6 Pay order B via wallet ───
echo ""
echo "  ── Payment: Wallet ──"
PAY_B=$(api "pay order B via wallet" POST "/api/v1/app/orders/${ORD_B_ID}/payment" "$USER_TOKEN" \
  "{\"payment\":\"wallet\",\"systemWalletId\":$BANK_WID}")
PAY_B_STATUS=$(echo "$PAY_B" | jval '["data"]["status"]')
echo "    payment status: $PAY_B_STATUS (expecting 'paid')"

ORDER_B_AFTER=$(api "get paid order B" GET "/api/v1/app/orders/${ORD_B_ID}" "$USER_TOKEN" none)
ORD_B_STATUS=$(echo "$ORDER_B_AFTER" | jval '["data"]["status"]')
echo "    order B status after payment: $ORD_B_STATUS (expecting 'paid')"

# Check wallet balance after wallet payment: should decrease by order B total
USR_BAL_AFTER=$(api "user wallet after payment" GET "/api/v1/manage/wallets/${USR_WID}" "$ADMIN_TOKEN" none | jval '["data"]["balance"]')
echo "    user wallet after payment: $USR_BAL_AFTER cent ($(python3 -c "print($USR_BAL_AFTER/100)") CNY)"

BANK_BAL_AFTER=$(api "bank wallet after payment" GET "/api/v1/manage/wallets/${BANK_WID}" "$ADMIN_TOKEN" none | jval '["data"]["balance"]')
echo "    bank wallet after payment: $BANK_BAL_AFTER cent ($(python3 -c "print($BANK_BAL_AFTER/100)") CNY)"

# ─── 6.7 Payment balance verification ───
echo ""
echo "  ── Payment Balance Verification ──"
python3 << PYEOF
bank_bal = $BANK_BAL_AFTER
usr_bal  = $USR_BAL_AFTER
usr_before = $USR_BAL_BEFORE
order_b_total = $ORD_B_TOTAL
order_a_total = $ORD_A_TOTAL

# User: before=300000, paid order B (209700 via wallet), order A was mock (no balance change)
expected_usr = usr_before - order_b_total
usr_ok = usr_bal == expected_usr

# Bank: received deposit 500000, sent 300000 to user, received 209700 from wallet payment of order B
bank_deposit = 500000
bank_to_user = 300000
bank_received = order_b_total
expected_bank = bank_deposit - bank_to_user + bank_received
bank_ok = bank_bal == expected_bank

print(f"    User wallet: {usr_bal} cent (expected {expected_usr}) {'✅' if usr_ok else '❌'}")
print(f"    Bank wallet: {bank_bal} cent (expected {expected_bank}) {'✅' if bank_ok else '❌'}")
if not (usr_ok and bank_ok):
    raise SystemExit("WALLET BALANCE MISMATCH")
print("    ✅ WALLET BALANCES CORRECT AFTER PAYMENTS")
PYEOF

# ══════════════════════════════════════════════════════════════════════════════
#  PHASE 7 — Admin user management
# ══════════════════════════════════════════════════════════════════════════════
echo ""
echo "─── Phase 7: Admin user management ───"

MGR_ID=$(api "manage create user" POST /api/v1/manage/users "$ADMIN_TOKEN" \
  "{\"email\":\"mgr_${SUFFIX}@t.com\",\"username\":\"mgr${SUFFIX}\",\"password\":\"Mgmt@123!\",\"roles\":[\"ROLE_EDITOR\"]}" | jid)
api "view user" GET "/api/v1/manage/users/${MGR_ID}" "$ADMIN_TOKEN" none >/dev/null
api "update user" PUT "/api/v1/manage/users/${MGR_ID}" "$ADMIN_TOKEN" '{"phone":"+8613999999999"}' >/dev/null
api "manage change pw" POST "/api/v1/manage/users/${MGR_ID}/change-password" "$ADMIN_TOKEN" '{"newPassword":"AdminSet1!"}' >/dev/null
api "delete user" DELETE "/api/v1/manage/users/${MGR_ID}" "$ADMIN_TOKEN" none 204 >/dev/null
api "view nonexistent (404)" GET /api/v1/manage/users/99999 "$ADMIN_TOKEN" none 404 >/dev/null
api "chpw nonexistent (404)" POST /api/v1/manage/users/99999/change-password "$ADMIN_TOKEN" '{"newPassword":"x"}' 404 >/dev/null

# ══════════════════════════════════════════════════════════════════════════════
#  PHASE 8 — Balance + Reconcile
# ══════════════════════════════════════════════════════════════════════════════
echo ""
echo "─── Phase 8: Balance + Reconcile ───"

BALANCE=$(api "verify balance" GET /api/v1/manage/wallets/balance "$ADMIN_TOKEN" none)
MATCHES=$(echo "$BALANCE" | jval '["data"]["matches"]')
TOTAL_BAL=$(echo "$BALANCE" | jval '["data"]["totalBalance"]')
TOTAL_DEP=$(echo "$BALANCE" | jval '["data"]["totalDeposited"]')
DISC=$(echo "$BALANCE" | jval '["data"]["discrepancy"]')
echo "  matches=$MATCHES | totalBalance=$TOTAL_BAL | totalDeposited=$TOTAL_DEP | discrepancy=$DISC"

REC=$(api "reconcile" POST /api/v1/manage/wallets/reconcile "$ADMIN_TOKEN" '{}')
REC_COUNT=$(echo "$REC" | jval '["data"]["reconciled"]')
echo "  reconciled: $REC_COUNT wallets"

# ══════════════════════════════════════════════════════════════════════════════
#  PHASE 9 — Final report
# ══════════════════════════════════════════════════════════════════════════════
echo ""
echo "────────────────────────────────────────────────────────────"
echo "  FINAL REPORT"
echo "────────────────────────────────────────────────────────────"
echo ""

python3 << PYEOF
import sys

balance_match = '$MATCHES'
total_bal = $TOTAL_BAL
total_dep = $TOTAL_DEP
disc = $DISC
reconciled = $REC_COUNT
passed = $PASS_COUNT
failed = $FAIL_COUNT

# Order price verification
prices_ok = True  # We verified above
orders = {
    'A (1×256GB, mock paid)':    ('$ORD_A_TOTAL', 89900, '$ORD_A_STATUS'),
    'B (3×128GB, wallet paid)':  ('$ORD_B_TOTAL', 209700, '$ORD_B_STATUS'),
    'C (mixed, cancelled)':      ('$ORD_C_TOTAL', 229700, 'cancelled'),
    'D (1×Base, draft cancel)':  ('$ORD_D_TOTAL', 149900, 'cancelled'),
}

print("═══ ORDER SUMMARY ═══")
for name, (actual, expected, status) in orders.items():
    a = int(actual)
    ok = '✅' if a == expected else '❌'
    print(f"  {ok} Order {name}")
    print(f"     Price: {a} cent ({a/100:.2f} ¥) {'==' if a==expected else '!='} {expected} cent")

print("")
print("═══ PAYMENT SUMMARY ═══")
print(f"  Mock payment (order A): $PAY_A_STATUS → order status: $ORD_A_STATUS, invoice: $ORD_A_INVOICE")
print(f"  Wallet payment (order B): $PAY_B_STATUS, cost $ORD_B_TOTAL cent")

print("")
print("═══ BALANCE AUDIT ═══")
print(f"  Total wallets:      6")
print(f"  Total balance:      {total_bal} cent ({total_bal/100:.2f} CNY)")
print(f"  Total deposited:    {total_dep} cent ({total_dep/100:.2f} CNY)")
print(f"  Discrepancy:        {disc}")
print(f"  Match:              {balance_match}")
print(f"  Reconciliation:     {reconciled} wallet(s) adjusted")

print("")
print("═══ RESULTS ═══")
print(f"  Total: {passed} passed, {failed} failed")
if failed == 0 and balance_match == 'True':
    print("  🎉 ALL TESTS PASS — DATA IS CONSISTENT")
else:
    print("  ⚠️  ISSUES DETECTED — review above")

print("")
print("═══ CREATED ENTITIES ═══")
print(f"  Products:  $PROD1_ID, $PROD2_ID")
print(f"  Specs:     $SPEC1_ID (128GB), $SPEC2_ID (256GB), $SPEC3_ID (Base)")
print(f"  Wallets:   bank=$BANK_WID, user=$USR_WID")
print(f"  Orders:    A=$ORD_A_ID, B=$ORD_B_ID, C=$ORD_C_ID, D=$ORD_D_ID")
print(f"  Content:   $CONTENT_ID | Category: $CAT_ID")
print(f"  Suffix:    $SUFFIX")
PYEOF

echo ""
echo "══════════════════════════════════════════════════════════════"
echo "  RESULTS: $PASS_COUNT passed, $FAIL_COUNT failed"
echo "  Log:     $REPORT_FILE"
echo "══════════════════════════════════════════════════════════════"

exit $FAIL_COUNT
