#!/usr/bin/env bash
#
# bin/pre-deploy-check.sh
#
# Read-only verification before deploying TNSVT to production.
# Run this on your LOCAL machine (or CI) before invoking deploy.sh.
#
# Exit code 0 = all green. Non-zero = block deploy.
#
# Usage:  ./bin/pre-deploy-check.sh
set -euo pipefail

# Resolve project root regardless of where the script is invoked from.
PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_ROOT"

# --- Colors (TTY only) ---
if [ -t 1 ]; then
    RED=$'\e[31m'; GREEN=$'\e[32m'; YELLOW=$'\e[33m'; CYAN=$'\e[36m'; RESET=$'\e[0m'
else
    RED=''; GREEN=''; YELLOW=''; CYAN=''; RESET=''
fi

PASS=0
FAIL=0
WARN=0

ok()   { echo "${GREEN}✓${RESET} $*"; PASS=$((PASS+1)); }
bad()  { echo "${RED}✗${RESET} $*"; FAIL=$((FAIL+1)); }
warn() { echo "${YELLOW}!${RESET} $*"; WARN=$((WARN+1)); }
section() { echo; echo "${CYAN}── $* ──${RESET}"; }

# ──────────────────────────────────────────────────────────────
section "1. PHP syntax (all PHP files)"
# ──────────────────────────────────────────────────────────────
if ! command -v php >/dev/null 2>&1; then
    bad "php not found in PATH"; exit 1
fi
PHP_VERSION=$(php -r 'echo PHP_VERSION;')
ok "PHP ${PHP_VERSION}"

bad_files=0
while IFS= read -r f; do
    if ! php -l "$f" >/dev/null 2>&1; then
        bad "  $f"
        bad_files=$((bad_files+1))
    fi
done < <(find src templates bin -type f -name '*.php' 2>/dev/null)
if [ "$bad_files" -eq 0 ]; then
    ok "All PHP files lint clean"
else
    bad "$bad_files PHP files failed lint"
fi

# ──────────────────────────────────────────────────────────────
section "2. Templates exist"
# ──────────────────────────────────────────────────────────────
required_templates=(
    "templates/public/home.html.twig"
    "templates/public/login.html.twig"
    "templates/public/shell.html.twig"
    "templates/public/_public_nav.html.twig"
    "templates/sanctum/guardian.html.twig"
    "templates/shell.html.twig"
)
for t in "${required_templates[@]}"; do
    if [ -f "$t" ]; then ok "$t"; else bad "missing: $t"; fi
done

# Legacy template must NOT exist (replaced by public/* in Phase 5)
if [ -f "templates/home.html.twig" ]; then
    bad "Legacy templates/home.html.twig still exists (should be deleted)"
else
    ok "Legacy templates/home.html.twig removed"
fi

# ──────────────────────────────────────────────────────────────
section "3. Guardian services present"
# ──────────────────────────────────────────────────────────────
required_services=(
    "src/Service/Guardian/GuardianSignal.php"
    "src/Service/Guardian/GuardianSignalCollector.php"
    "src/Service/Guardian/DisciplineScoreCalculator.php"
    "src/Controller/Api/GuardianController.php"
    "src/Event/TradeSavedEvent.php"
    "src/EventSubscriber/GuardianSubscriber.php"
)
for s in "${required_services[@]}"; do
    if [ -f "$s" ]; then ok "$s"; else bad "missing: $s"; fi
done

# ──────────────────────────────────────────────────────────────
section "4. Security: no committed secrets"
# ──────────────────────────────────────────────────────────────
if [ -f ".env" ]; then ok ".env present (committed)"; else bad ".env missing"; fi

# .env.local must NOT be tracked
if git ls-files --error-unmatch .env.local >/dev/null 2>&1; then
    bad ".env.local is tracked in git (SECURITY: rotate + remove)"
else
    ok ".env.local is gitignored"
fi

# Check for committed JWT passphrase / Mercure default secret
if grep -qE '^JWT_PASSPHRASE=[a-f0-9]{20,}' .env 2>/dev/null; then
    bad "Real JWT_PASSPHRASE in .env (rotate + replace with placeholder)"
else
    ok "No real JWT passphrase committed in .env"
fi
if grep -qE 'dev-secret-change-in-production!!' .env .env.local 2>/dev/null; then
    bad "Mercure default secret still present"
else
    ok "Mercure default secret replaced"
fi

# Check for legacy stitch exploration
if [ -d "stitch_tnsvt_app_m_vil" ]; then
    warn "stitch_tnsvt_app_m_vil/ exists locally — gitignored, but consider removing before commit"
fi

# Check vendor/ not committed
if git ls-files vendor 2>/dev/null | head -1 | grep -q .; then
    bad "vendor/ is tracked in git (should be gitignored)"
else
    ok "vendor/ is gitignored"
fi

# ──────────────────────────────────────────────────────────────
section "5. Git state"
# ──────────────────────────────────────────────────────────────
if [ -d ".git" ]; then
    ok "Git repository present"
    if git rev-parse --git-dir >/dev/null 2>&1; then
        BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "?")
        ok "Current branch: ${BRANCH}"
        UNCOMMITTED=$(git status --porcelain 2>/dev/null | wc -l | tr -d ' ')
        if [ "$UNCOMMITTED" -gt 0 ]; then
            warn "${UNCOMMITTED} uncommitted file(s) — commit before deploy"
        else
            ok "Working tree clean"
        fi
    fi
else
    warn "Not a git repository"
fi

# ──────────────────────────────────────────────────────────────
section "6. Composer sanity"
# ──────────────────────────────────────────────────────────────
if [ -f "composer.json" ]; then ok "composer.json present"; fi
if [ -f "composer.lock" ]; then ok "composer.lock present"; fi
if [ -d "vendor" ]; then ok "vendor/ installed locally"; else warn "vendor/ missing — run composer install"; fi

# ──────────────────────────────────────────────────────────────
section "Summary"
# ──────────────────────────────────────────────────────────────
echo
echo "  ${GREEN}PASS${RESET}: ${PASS}"
echo "  ${YELLOW}WARN${RESET}: ${WARN}"
echo "  ${RED}FAIL${RESET}: ${FAIL}"
echo

if [ "$FAIL" -gt 0 ]; then
    echo "${RED}⛔ Deploy blocked.${RESET} Fix the failures above and re-run."
    exit 1
fi

if [ "$WARN" -gt 0 ]; then
    echo "${YELLOW}⚠ Warnings present.${RESET} Review before deploying."
fi

echo "${GREEN}✓ Pre-deploy checks passed.${RESET}"
exit 0
