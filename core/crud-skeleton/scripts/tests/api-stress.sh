#!/usr/bin/env bash
# ==============================================================================
# crud-skeleton High-Frequency Trading Stress Test
#
# Simulates NUM_USERS users each making NUM_ORDERS orders, all paid via wallet.
# Includes cancellations, refunds, multi-item orders. Verifies final balances.
#
# Usage:
#   scripts/tests/api-stress.sh
#   scripts/tests/api-stress.sh http://localhost:8080 20 15   # 20 users, 15 orders each
# ==============================================================================
set -euo pipefail

BASE="${1:-http://127.0.0.1:8000}"
NUM_USERS="${2:-200}"
ORDERS_PER_USER="${3:-150}"
SUFFIX=$(date +%s)
PASSWORD='P@ssw0rd'
STEP=0; FAIL=0; PASS=0

REPORT_DIR="var/api-test-reports"
mkdir -p "$REPORT_DIR"
REPORT="$REPORT_DIR/stress_${SUFFIX}.txt"
exec > >(tee -a "$REPORT") 2>&1

echo "══════════════════════════════════════════════════════════════"
echo "  STRESS TEST — $NUM_USERS users × $ORDERS_PER_USER orders each"
echo "  Base: $BASE | Suffix: $SUFFIX | $(date)"
echo "══════════════════════════════════════════════════════════════"
echo ""

# ── helpers ──────────────────────────────────────────────────────────────────

api() {
  local label="$1" method="$2" path="$3" token="$4" body="$5" expected="${6:-200 201 204}"
  STEP=$((STEP + 1))
  local url="${BASE}${path}"
  local headers=(-H 'Content-Type: application/json')
  [[ -n "$token" && "$token" != "none" ]] && headers+=(-H "Authorization: Bearer $token")
  local body_file; body_file=$(mktemp)
  local http_code; http_code=$(curl --noproxy '*' -s -o "$body_file" -w '%{http_code}' -X "$method" "$url" "${headers[@]}" ${body:+--data "$body"})
  local response; response=$(cat "$body_file"); rm -f "$body_file"
  local ok=false
  for exp in $expected; do [[ "$http_code" == "$exp" ]] && ok=true && break; done
  if $ok; then PASS=$((PASS + 1)); else FAIL=$((FAIL + 1)); fi
  echo "$response"
}

jv() { python3 -c "import sys,json; d=json.load(sys.stdin); print(d$1)" 2>/dev/null || echo "0"; }

panic() { echo "FATAL: $1" >&2; exit 1; }

# ── Pre-flight ────────────────────────────────────────────────────────────────

ADMIN_CHECK=$(curl --noproxy '*' -s -o /dev/null -w '%{http_code}' -X POST "$BASE/api/auth/login" \
  -H 'Content-Type: application/json' -d '{"identifier":"admin@example.com","password":"P@ssw0rd"}')
[[ "$ADMIN_CHECK" == "200" ]] || panic "Admin not found. Run: symfony php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin"

ADMIN_TOKEN=$(api "admin login" POST /api/auth/login none '{"identifier":"admin@example.com","password":"P@ssw0rd"}' | jv '["access_token"]')
[[ -z "$ADMIN_TOKEN" || "$ADMIN_TOKEN" == "0" ]] && panic "Admin login failed"
echo "✅ Admin logged in"

# ══════════════════════════════════════════════════════════════════════════════
#  PHASE 1 — Setup: products, specs, system wallet
# ══════════════════════════════════════════════════════════════════════════════
echo ""
echo "─── Phase 1: Setup catalog & system wallet ───"
echo ""

# Create products with multiple specs
declare -a SPEC_IDS=()
declare -a SPEC_PRICES=()

PROD1=$(api "create product A" POST /api/v1/manage/products "$ADMIN_TOKEN" '{"name":"Widget","status":"active"}' | jv '["data"]["id"]')
PROD2=$(api "create product B" POST /api/v1/manage/products "$ADMIN_TOKEN" '{"name":"Gadget","status":"active"}' | jv '["data"]["id"]')
PROD3=$(api "create product C" POST /api/v1/manage/products "$ADMIN_TOKEN" '{"name":"Service","status":"active"}' | jv '["data"]["id"]')

for price in 9900 19900 49900 89900 149900 29900 59900 7900 39900 99900; do
  prod=$PROD1; [[ ${#SPEC_IDS[@]} -gt 3 ]] && prod=$PROD2; [[ ${#SPEC_IDS[@]} -gt 6 ]] && prod=$PROD3
  name="Spec-${price}"
  sid=$(api "create spec $name" POST "/api/v1/manage/products/${prod}/specifications" "$ADMIN_TOKEN" \
    "{\"name\":\"$name\",\"price\":$price,\"status\":\"active\",\"sort\":1}" | jv '["data"]["id"]')
  SPEC_IDS+=("$sid")
  SPEC_PRICES+=("$price")
done

echo "  Products: $PROD1, $PROD2, $PROD3"
echo "  Specs: ${#SPEC_IDS[@]} items, prices: ${SPEC_PRICES[*]}"

# ══════════════════════════════════════════════════════════════════════════════
#  PHASE 2 — Create users + wallets + initial funding
# ══════════════════════════════════════════════════════════════════════════════
echo ""
echo "─── Phase 2: Create $NUM_USERS users + wallets ───"
echo ""

# Create system bank user
api "register system bank" POST /api/auth/register none \
  "{\"email\":\"sysbank_${SUFFIX}@t.com\",\"username\":\"sysbank${SUFFIX}\",\"password\":\"$PASSWORD\"}" >/dev/null
SYS_TOKEN=$(api "sysbank login" POST /api/auth/login none \
  "{\"identifier\":\"sysbank_${SUFFIX}@t.com\",\"password\":\"$PASSWORD\"}" | jv '["access_token"]')
SYS_UID=$(python3 -c "import sys,json,base64; t='$SYS_TOKEN'; p=t.split('.')[1]; p+='='*(4-len(p)%4); print(json.loads(base64.urlsafe_b64decode(p))['sub'])")

SYS_WID=$(api "create system wallet" POST /api/v1/manage/wallets "$ADMIN_TOKEN" \
  "{\"user\":$SYS_UID,\"currency\":\"CNY\",\"status\":\"active\",\"label\":\"SystemBank\"}" | jv '["data"]["id"]')

# Massive deposit — 100 million cent = 1,000,000 CNY
DEPOSIT_TOTAL=10000000000
api "mega deposit" POST /api/v1/manage/vouchers/deposit "$ADMIN_TOKEN" \
  "{\"walletId\":$SYS_WID,\"amount\":$DEPOSIT_TOTAL,\"currency\":\"CNY\",\"referenceId\":\"stress-bank-1\",\"reason\":\"Stress test funding\"}" >/dev/null
echo "  System wallet $SYS_WID funded with $DEPOSIT_TOTAL cent ($(python3 -c "print($DEPOSIT_TOTAL/100)") CNY)"

# Create users in parallel-like batches
declare -a USER_TOKENS=()
declare -a USER_WIDS=()
declare -a USER_BALANCES_BEFORE=()
declare -a USER_ORDER_TOTALS=()
declare -a USER_REFUND_TOTALS=()

INITIAL_BALANCE=$((500000000 / NUM_USERS))  # distribute evenly

for i in $(seq 1 $NUM_USERS); do
  email="u${i}_${SUFFIX}@t.com"
  username="stressu${i}_${SUFFIX}"
  
  UT=$(api "register user $i" POST /api/auth/register none \
    "{\"email\":\"$email\",\"username\":\"$username\",\"password\":\"$PASSWORD\"}" | jv '["access_token"]')
  USER_TOKENS+=("$UT")
  
  uid=$(python3 -c "import sys,json,base64; t='$UT'; p=t.split('.')[1]; p+='='*(4-len(p)%4); print(json.loads(base64.urlsafe_b64decode(p))['sub'])")
  wid=$(api "create wallet user $i" POST /api/v1/manage/wallets "$ADMIN_TOKEN" \
    "{\"user\":$uid,\"currency\":\"CNY\",\"status\":\"active\",\"label\":\"User$i\"}" | jv '["data"]["id"]')
  USER_WIDS+=("$wid")
  
  # Distribute funds: system → user
  api "fund user $i" POST /api/v1/manage/transactions "$ADMIN_TOKEN" \
    "{\"fromWalletId\":$SYS_WID,\"toWalletId\":$wid,\"amount\":$INITIAL_BALANCE,\"description\":\"Fund user $i\"}" >/dev/null

  USER_BALANCES_BEFORE+=("$INITIAL_BALANCE")
  USER_ORDER_TOTALS+=(0)
  USER_REFUND_TOTALS+=(0)
  
  echo "  User $i: uid=$uid wallet=$wid funded=$INITIAL_BALANCE cent ($(python3 -c "print($INITIAL_BALANCE/100)") CNY)"
  [[ $((i % 5)) -eq 0 ]] && echo ""
done

# ══════════════════════════════════════════════════════════════════════════════
#  PHASE 3 — Simulate trading: each user makes multiple orders
# ══════════════════════════════════════════════════════════════════════════════
echo ""
echo "─── Phase 3: $NUM_USERS users × $ORDERS_PER_USER orders = $(($NUM_USERS * $ORDERS_PER_USER)) total ───"
echo ""

TOTAL_ORDERS=0
TOTAL_PAID_AMOUNT=0
TOTAL_CANCELLED=0
TOTAL_REFUNDED=0

for u in $(seq 0 $((NUM_USERS - 1))); do
  token="${USER_TOKENS[$u]}"
  wid="${USER_WIDS[$u]}"
  
  echo "  ── User $((u+1)) (wallet $wid) ──"
  
  user_total=0
  user_refund=0
  
  for o in $(seq 1 $ORDERS_PER_USER); do
    # Random number of items (1-4)
    nitems=$((RANDOM % 4 + 1))
    items_json="["
    order_expected=0
    
    for it in $(seq 1 $nitems); do
      spec_idx=$((RANDOM % ${#SPEC_IDS[@]}))
      spec_id="${SPEC_IDS[$spec_idx]}"
      spec_price="${SPEC_PRICES[$spec_idx]}"
      qty=$((RANDOM % 3 + 1))
      order_expected=$((order_expected + spec_price * qty))
      [[ $it -gt 1 ]] && items_json+=","
      items_json+="{\"specificationId\":$spec_id,\"quantity\":$qty}"
    done
    items_json+="]"
    
    ORD=$(api "user$((u+1)) order $o (${nitems}items)" POST /api/v1/app/orders "$token" \
      "{\"currency\":\"CNY\",\"notes\":\"U$((u+1))-O$o\",\"items\":$items_json}" | jv '["data"]["id"]')
    
    if [[ "$ORD" == "0" || -z "$ORD" ]]; then
      echo "    ⚠️  order creation failed, skipping"
      continue
    fi
    
    ORDER_ACTUAL=$(api "get order $ORD" GET "/api/v1/app/orders/${ORD}" "$token" none | jv '["data"]["totalAmount"]')
    
    if [[ "$ORDER_ACTUAL" != "$order_expected" ]]; then
      echo "    ❌ ORDER $ORD PRICE MISMATCH: actual=$ORDER_ACTUAL expected=$order_expected"
      FAIL=$((FAIL + 1))
    fi
    
    TOTAL_ORDERS=$((TOTAL_ORDERS + 1))
    
    # Decide fate: 60% paid, 20% cancelled-draft, 15% cancelled-confirmed, 5% refunded
    fate=$((RANDOM % 100))
    
    if [[ $fate -lt 20 ]]; then
      # Cancel as draft
      api "U$((u+1))-O$o cancel draft" POST "/api/v1/app/orders/${ORD}/cancel" "$token" '{}' >/dev/null
      TOTAL_CANCELLED=$((TOTAL_CANCELLED + 1))
      echo "    O$ORD: $ORDER_ACTUAL cent — DRAFT CANCELLED"
      
    elif [[ $fate -lt 35 ]]; then
      # Submit → confirm → cancel
      api "U$((u+1)) submit $ORD" POST "/api/v1/manage/orders/${ORD}/do/submit" "$ADMIN_TOKEN" '{}' >/dev/null
      api "U$((u+1)) confirm $ORD" POST "/api/v1/manage/orders/${ORD}/do/confirm" "$ADMIN_TOKEN" '{}' >/dev/null
      api "U$((u+1))-O$o cancel confirmed" POST "/api/v1/app/orders/${ORD}/cancel" "$token" '{}' >/dev/null
      TOTAL_CANCELLED=$((TOTAL_CANCELLED + 1))
      echo "    O$ORD: $ORDER_ACTUAL cent — CONFIRMED CANCELLED"
      
    elif [[ $fate -lt 40 ]]; then
      # Submit → confirm → pay → fulfill → complete → refund
      api "U$((u+1)) submit $ORD" POST "/api/v1/manage/orders/${ORD}/do/submit" "$ADMIN_TOKEN" '{}' >/dev/null
      api "U$((u+1)) confirm $ORD" POST "/api/v1/manage/orders/${ORD}/do/confirm" "$ADMIN_TOKEN" '{}' >/dev/null
      PAY=$(api "U$((u+1))-O$o wallet pay" POST "/api/v1/app/orders/${ORD}/payment" "$token" \
        "{\"payment\":\"wallet\",\"systemWalletId\":$SYS_WID}")
      pay_status=$(echo "$PAY" | jv '["data"]["status"]')
      if [[ "$pay_status" == "paid" ]]; then
        api "U$((u+1)) fulfill $ORD" POST "/api/v1/manage/orders/${ORD}/do/fulfill" "$ADMIN_TOKEN" '{}' >/dev/null
        api "U$((u+1)) complete $ORD" POST "/api/v1/manage/orders/${ORD}/do/complete" "$ADMIN_TOKEN" '{}' >/dev/null
        api "U$((u+1))-O$o refund" POST "/api/v1/manage/orders/${ORD}/refund" "$ADMIN_TOKEN" \
          "{\"systemWalletId\":$SYS_WID,\"reason\":\"Stress test refund\"}" >/dev/null
        user_total=$((user_total + ORDER_ACTUAL))
        # Refund means money goes back: system → user. So user gets the money back via refund
        # But we already deducted it for payment. The refund adds it back.
        # So net effect: user_total increases (paid) then decreases (refund = money returned)
        # Actually: paid deducts from user wallet. refund transfers from system to user.
        # Net effect on user_total for refunded order: 0 (paid then refunded)
        user_refund=$((user_refund + ORDER_ACTUAL))
        TOTAL_REFUNDED=$((TOTAL_REFUNDED + 1))
        echo "    O$ORD: $ORDER_ACTUAL cent — PAID → FULFILLED → REFUNDED"
      else
        echo "    O$ORD: $ORDER_ACTUAL cent — PAY FAILED ($pay_status)"
        FAIL=$((FAIL + 1))
      fi
      
    else
      # Normal: submit → confirm → pay
      api "U$((u+1)) submit $ORD" POST "/api/v1/manage/orders/${ORD}/do/submit" "$ADMIN_TOKEN" '{}' >/dev/null
      api "U$((u+1)) confirm $ORD" POST "/api/v1/manage/orders/${ORD}/do/confirm" "$ADMIN_TOKEN" '{}' >/dev/null
      PAY=$(api "U$((u+1))-O$o wallet pay" POST "/api/v1/app/orders/${ORD}/payment" "$token" \
        "{\"payment\":\"wallet\",\"systemWalletId\":$SYS_WID}")
      pay_status=$(echo "$PAY" | jv '["data"]["status"]')
      if [[ "$pay_status" == "paid" ]]; then
        user_total=$((user_total + ORDER_ACTUAL))
        TOTAL_PAID_AMOUNT=$((TOTAL_PAID_AMOUNT + ORDER_ACTUAL))
        echo "    O$ORD: $ORDER_ACTUAL cent — PAID ✅"
      else
        echo "    O$ORD: $ORDER_ACTUAL cent — PAY FAILED ($pay_status) ❌"
        FAIL=$((FAIL + 1))
      fi
    fi
  done
  
  # Update tracking arrays
  USER_ORDER_TOTALS[$u]=$user_total
  USER_REFUND_TOTALS[$u]=$user_refund
  
  echo "    user$((u+1)) subtotal: paid=$user_total refund=$user_refund"
  echo ""
done

# ══════════════════════════════════════════════════════════════════════════════
#  PHASE 4 — Final balance verification
# ══════════════════════════════════════════════════════════════════════════════
echo ""
echo "══════════════════════════════════════════════════════════════"
echo "  PHASE 4 — Balance Verification"
echo "══════════════════════════════════════════════════════════════"
echo ""

BALANCE=$(api "system balance check" GET /api/v1/manage/wallets/balance "$ADMIN_TOKEN" none)
TOTAL_BAL=$(echo "$BALANCE" | jv '["data"]["totalBalance"]')
TOTAL_DEP=$(echo "$BALANCE" | jv '["data"]["totalDeposited"]')
MATCH=$(echo "$BALANCE" | jv '["data"]["matches"]')
DISC=$(echo "$BALANCE" | jv '["data"]["discrepancy"]')

echo "  Total balance:    $TOTAL_BAL cent ($(python3 -c "print($TOTAL_BAL/100)") CNY)"
echo "  Total deposited:  $TOTAL_DEP cent ($(python3 -c "print($TOTAL_DEP/100)") CNY)"
echo "  Discrepancy:      $DISC"
echo "  Match:            $MATCH"

# Verify each user's wallet
echo ""
echo "─── Per-User Wallet Verification ───"
ALL_USERS_OK=true

for u in $(seq 0 $((NUM_USERS - 1))); do
  wid="${USER_WIDS[$u]}"
  before="${USER_BALANCES_BEFORE[$u]}"
  paid="${USER_ORDER_TOTALS[$u]}"
  refund="${USER_REFUND_TOTALS[$u]}"
  
  actual_bal=$(api "user$((u+1)) wallet check" GET "/api/v1/manage/wallets/${wid}" "$ADMIN_TOKEN" none | jv '["data"]["balance"]')
  
  # Expected: initial - paid (money gone via wallet payment) + refund (money returned)
  # Note: cancelled orders don't affect balance (never paid)
  expected=$((before - paid + refund))
  
  ok="✅"
  if [[ "$actual_bal" != "$expected" ]]; then
    ok="❌"
    ALL_USERS_OK=false
  fi
  printf "  %s User %d: actual=%d cent expected=%d cent (paid=%d refund=%d)\n" "$ok" $((u+1)) "$actual_bal" "$expected" "$paid" "$refund"
done

# ══════════════════════════════════════════════════════════════════════════════
#  PHASE 5 — Summary
# ══════════════════════════════════════════════════════════════════════════════
echo ""
echo "══════════════════════════════════════════════════════════════"
echo "  FINAL SUMMARY"
echo "══════════════════════════════════════════════════════════════"
cat << PYEOF
  Users:          $NUM_USERS
  Orders/user:    $ORDERS_PER_USER
  Total orders:   $TOTAL_ORDERS
  Total paid:     $TOTAL_PAID_AMOUNT cent ($(python3 -c "print($TOTAL_PAID_AMOUNT/100)") CNY)
  Cancelled:      $TOTAL_CANCELLED
  Refunded:       $TOTAL_REFUNDED

  System balance:     $TOTAL_BAL cent
  System deposited:   $TOTAL_DEP cent
  Match:              $MATCH
  Discrepancy:        $DISC

  Per-user wallets:   $(if $ALL_USERS_OK; then echo "✅ ALL CORRECT"; else echo "❌ MISMATCHES FOUND"; fi)
  Total steps:        $STEP
  Passed:             $PASS
  Failed:             $FAIL
  Suffix:             $SUFFIX
PYEOF

echo ""
echo "══════════════════════════════════════════════════════════════"
echo "  Report: $REPORT"
echo "══════════════════════════════════════════════════════════════"

exit $FAIL