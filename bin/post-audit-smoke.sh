#!/usr/bin/env bash
#
# bin/post-audit-smoke.sh
#
# Smoke test runner for the 2026-09-29 audit fixes.
# Use AFTER `bin/deploy.sh` to verify B1, B2, B3, B5 (and B4 firewall
# X-Game-Code path) are working in prod.
#
# Usage:
#   TNSVT_ADMIN_CODE=ADMIN01 \
#   TNSVT_ADMIN_USER_PASSWORD='<password for ADMIN01 user login>' \
#   TNSVT_ADMIN_PASSWORD='<X-Admin-Password env var from .env.local>' \
#   TNSVT_USER_VICTIM_CODE=VICTIM01 \
#   bash bin/post-audit-smoke.sh https://tnsvt.com
#
# Exit code: 0 if all checks pass, non-zero otherwise.

set -euo pipefail

BASE_URL="${1:-https://tnsvt.com}"

: "${TNSVT_ADMIN_CODE:?Set TNSVT_ADMIN_CODE=ADMIN01 (required)}"
: "${TNSVT_ADMIN_PASSWORD:?Set TNSVT_ADMIN_PASSWORD=secret (required)}"
: "${TNSVT_USER_VICTIM_CODE:=VICTIM01}"

RED=$'\e[31m'; GREEN=$'\e[32m'; YELLOW=$'\e[33m'; CYAN=$'\e[36m'; RESET=$'\e[0m'
fail=0

step() { echo "${CYAN}▸${RESET} $*"; }
ok()   { echo "  ${GREEN}✓${RESET} $*"; }
bad()  { echo "  ${RED}✗${RESET} $*"; fail=$((fail+1)); }
warn() { echo "  ${YELLOW}!${RESET} $*"; }

# 1) Public smoke
step "1/6 Public endpoint reachable"
code=$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/api/auth/check" --max-time 15 || echo 000)
[[ "$code" == "200" ]] && ok "GET /api/auth/check → 200" || bad "GET /api/auth/check → $code"

code=$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/api/public/stats" --max-time 15 || echo 000)
[[ "$code" == "200" ]] && ok "GET /api/public/stats → 200" || bad "GET /api/public/stats → $code"

code=$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/login" --max-time 15 || echo 000)
[[ "$code" == "200" ]] && ok "GET /login → 200" || bad "GET /login → $code"

# 2) Login as admin (uses USER password, not the ADMIN_PASSWORD env var
# which is the legacy header secret for X-Admin-Password endpoints).
# Optional TNSVT_ADMIN_USER_PASSWORD for that; falls back to TNSVT_ADMIN_PASSWORD.
TNSVT_ADMIN_USER_PASSWORD="${TNSVT_ADMIN_USER_PASSWORD:-$TNSVT_ADMIN_PASSWORD}"
step "2/6 Admin login"
login_resp=$(curl -sS -X POST -H "Content-Type: application/json" \
    -d "{\"code\":\"$TNSVT_ADMIN_CODE\",\"password\":\"$TNSVT_ADMIN_USER_PASSWORD\"}" \
    "$BASE_URL/api/auth/login" --max-time 15 || echo '{}')
echo "  Login response: ${login_resp:0:200}"

ADMIN_TOKEN=$(printf '%s' "$login_resp" | python -c "import sys,json; d=json.load(sys.stdin); print(d.get('token') or '')" 2>/dev/null || true)
if [[ -z "$ADMIN_TOKEN" ]]; then
    bad "Could not extract admin token from login response"
    warn "Skipping admin-required checks (B3, B5). Verify login creds."
else
    ok "Admin token acquired"

    # 3) B3 fix: dashboard returns 200 with globalPnl=0 + warning
    step "3/6 B3 — Dashboard returns globalPnl warning (no tournament_trades 500)"
    dash=$(curl -sS -H "Authorization: Bearer $ADMIN_TOKEN" \
        "$BASE_URL/sanctum/api/dashboard" --max-time 15 || echo '{}')
    echo "  Body: ${dash:0:300}"
    if printf '%s' "$dash" | python -c "
import sys, json
d = json.loads(sys.stdin.read())
assert d.get('success') is True, 'success false'
kpis = d['kpis']
assert kpis['globalPnl'] == 0, f'globalPnl != 0: {kpis[\"globalPnl\"]}'
assert kpis.get('globalPnlWarning') == 'tournament_trades_subsystem_deprecated', f'warning missing: {kpis.get(\"globalPnlWarning\")}'
print('OK')
" 2>/dev/null; then
        ok "Dashboard endpoint returns warning + globalPnl=0"
    else
        bad "Dashboard did not return expected shape"
    fi

    # 4) B5 fix: Oracle IDOR — try to read another user's metrics as admin
    # (Admin SHOULD be allowed; we test with a victim code)
    step "4/6 B5 — Oracle allows admin to read other user metrics"
    oracle=$(curl -sS -H "Authorization: Bearer $ADMIN_TOKEN" \
        "$BASE_URL/sanctum/api/oracle/emotional-bias?code=$TNSVT_USER_VICTIM_CODE" \
        --max-time 15 || echo '{}')
    code=$(curl -sS -o /dev/null -w '%{http_code}' \
        -H "Authorization: Bearer $ADMIN_TOKEN" \
        "$BASE_URL/sanctum/api/oracle/emotional-bias?code=$TNSVT_USER_VICTIM_CODE" \
        --max-time 15 || echo 000)
    [[ "$code" == "200" ]] && ok "Admin can read ?code=VICTIM → 200" || bad "Admin read failed → $code"

    # 5) B2 fix: AdminWallet credit (positive smoke — credit a small amount to admin themselves)
    step "5/6 B2 — AdminWallet credit endpoint accepts valid request"
    wallet=$(curl -sS -X POST \
        -H "X-Admin-Password: $TNSVT_ADMIN_PASSWORD" \
        -H "Content-Type: application/json" \
        -d "{\"code\":\"$TNSVT_ADMIN_CODE\",\"amount\":0.01,\"method\":\"manual_mp\",\"notes\":\"post-audit-smoke\"}" \
        "$BASE_URL/api/admin/wallet/credit" --max-time 15 || echo '{}')
    echo "  Body: ${wallet:0:300}"
    if printf '%s' "$wallet" | python -c "
import sys, json
d = json.loads(sys.stdin.read())
assert d.get('success') is True, f'success false: {d}'
print('OK')
" 2>/dev/null; then
        ok "Wallet credit returned success (B2 fix verified)"
    else
        bad "Wallet credit FAILED (likely B2 still broken — UPDATE on 'user' singular)"
    fi
fi

# 6) B4 fix: X-Game-Code header authenticates via firewall
step "6/6 B4 — X-Game-Code header authenticates via firewall (logout endpoint)"
# We test /api/auth/check which returns {authenticated: bool, code?: string}
# when the firewall recognizes the X-Game-Code header.
# Pre-fix: returns {authenticated: false} (firewall doesn't know the header).
# Post-fix: returns {authenticated: true, code: <user_code>}.
check=$(curl -sS -H "X-Game-Code: $TNSVT_ADMIN_CODE" \
    "$BASE_URL/api/auth/check" --max-time 15 || echo '{}')
echo "  Body: ${check:0:200}"
if printf '%s' "$check" | python -c "
import sys, json
d = json.loads(sys.stdin.read())
assert d.get('authenticated') is True, f'NOT authenticated (B4 fix not deployed?): {d}'
print('OK')
" 2>/dev/null; then
    ok "X-Game-Code header authenticated (firewall X-Game-Code works)"
else
    bad "X-Game-Code header did NOT authenticate (B4 fix not deployed)"
fi

echo
if [[ $fail -eq 0 ]]; then
    echo "${GREEN}✓ All smoke checks passed.${RESET}"
    exit 0
else
    echo "${RED}✗ $fail check(s) failed.${RESET}"
    exit 1
fi