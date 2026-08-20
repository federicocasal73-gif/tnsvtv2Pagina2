#!/usr/bin/env bash
#
# bin/post-deploy-smoke.sh
#
# Hit the deployed URL and assert that critical endpoints respond as expected.
# Usage:  ./bin/post-deploy-smoke.sh [URL]
#   URL defaults to https://tnsvt.com (override via first arg or
#   TNSVT_DEPLOY_HEALTHCHECK env var).
#
# Exit 0 = all pass; non-zero = something to investigate (smoke tests are not
# strict — they WARN rather than ABORT for non-critical paths).
set -uo pipefail

URL="${1:-${TNSVT_DEPLOY_HEALTHCHECK:-https://tnsvt.com}}"
URL="${URL%/}"

if [ -t 1 ]; then
    RED=$'\e[31m'; GREEN=$'\e[32m'; YELLOW=$'\e[33m'; RESET=$'\e[0m'
else
    RED=''; GREEN=''; YELLOW=''; RESET=''
fi

PASS=0; WARN=0; FAIL=0

pass() { echo "${GREEN}✓${RESET} $*"; PASS=$((PASS+1)); }
warn() { echo "${YELLOW}!${RESET} $*"; WARN=$((WARN+1)); }
fail() { echo "${RED}✗${RESET} $*"; FAIL=$((FAIL+1)); }

# Returns: status code on stdout, body in $BODY (global).
http_get() {
    local path="$1"
    local tmp
    tmp=$(mktemp)
    BODY=$(curl -sS -L -o "$tmp" -w "%{http_code}" --max-time 15 "${URL}${path}" 2>/dev/null) || BODY="000"
    BODY=$(cat "$tmp")
    rm -f "$tmp"
}

check() {
    local desc="$1" path="$2" expected="$3" allow_warn="${4:-no}"
    http_get "$path"
    if [ "$BODY" = "$expected" ]; then
        pass "${desc}: ${path} → ${BODY}"
    elif [ "$allow_warn" = "yes" ]; then
        warn "${desc}: ${path} → ${BODY} (expected ${expected})"
    else
        fail "${desc}: ${path} → ${BODY} (expected ${expected})"
    fi
}

echo "Target: $URL"
echo

# ─── Public surface ───────────────────────────────────────────────
echo "── Public ──"
check "Landing renders"        "/"               "200" "yes"
check "Login renders"         "/login"         "200" "yes"
check "Landing alt route"     "/home"          "200" "yes"

# ─── API public ────────────────────────────────────────────────────
echo
echo "── API (anonymous) ──"
check "Auth check"             "/api/auth/check" "200"

# ─── Sanctum (anonymous — should redirect or 302/200 depending on firewall) ──
echo
echo "── Sanctum (anonymous) ──"
# Per security.yaml: ^/sanctum is now ROLE_USER, not ROLE_ADMIN. Anonymous
# request to /sanctum triggers CodeAuthenticator entry point → 302 /login
check "Sanctum dashboard"      "/sanctum"        "302" "yes"

# ─── Static assets (verify the public shell loads) ─────────────────
echo
echo "── Static assets ──"
# Sources now live in src/assets; the served /assets/ dir only contains
# compiled, hashed files. Grab a compiled stylesheet referenced by the
# landing HTML and assert it resolves.
_tmp_html=$(mktemp)
curl -sS -L --max-time 15 "${URL}/" -o "$_tmp_html" 2>/dev/null
_CSS=$(grep -oE "assets/styles/tokens-[A-Za-z0-9]{7}\.css" "$_tmp_html" | head -1)
if [ -n "$_CSS" ]; then
    check "Compiled tokens CSS present" "/${_CSS}" "200"
else
    fail "Compiled stylesheet NOT referenced in landing HTML"
fi
rm -f "$_tmp_html"

# ─── Admin (anonymous — should 302 /login via firewall) ─────────────
echo
echo "── Admin (anonymous, should redirect to login) ──"
check "Admin users"            "/sanctum/users"     "302" "yes"
check "Admin monitoring"       "/sanctum/monitoring" "302" "yes"

# ─── Summary ────────────────────────────────────────────────────────
echo
echo "── Summary ──"
echo "  PASS: $PASS"
echo "  WARN: $WARN"
echo "  FAIL: $FAIL"

if [ "$FAIL" -gt 0 ]; then
    echo
    echo "${RED}⛔ Smoke tests FAILED.${RESET} Investigate before declaring victory."
    exit 1
fi

echo
echo "${GREEN}✓ Smoke tests OK.${RESET}"
exit 0
