#!/usr/bin/env bash
#
# bin/rollback.sh
#
# Roll back to a previous release. Requires that deploy.sh ran at least once
# and recorded the pre-deploy HEAD in `.last-release`.
#
# Usage:  ./bin/rollback.sh [commit-or-tag]
#   If no arg given, uses the value in .last-release (last successful deploy).
#
# ⚠️  This re-deploys code only — DB migrations are NOT reversed. If a
# migration was applied in the release you're rolling back, you must manually
# run `bin/console doctrine:migrations:migrate prev` on the server.
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_ROOT"

: "${TNSVT_DEPLOY_SSH:?Set TNSVT_DEPLOY_SSH (required)}"
: "${TNSVT_DEPLOY_PATH:?Set TNSVT_DEPLOY_PATH (required)}"

REMOTE="${TNSVT_DEPLOY_SSH}"
REMOTE_PATH="${TNSVT_DEPLOY_PATH}"

if [ -t 1 ]; then
    RED=$'\e[31m'; YELLOW=$'\e[33m'; CYAN=$'\e[36m'; RESET=$'\e[0m'
else
    RED=''; YELLOW=''; CYAN=''; RESET=''
fi

TARGET="${1:-}"
if [ -z "$TARGET" ]; then
    if [ -f ".last-release" ]; then
        TARGET=$(cat .last-release)
        echo "${CYAN}▸${RESET} Using .last-release: ${TARGET}"
    else
        echo "${RED}✗ No target given and .last-release not found.${RESET}"
        echo "Usage: ./bin/rollback.sh <commit-or-tag>"
        exit 1
    fi
fi

echo "${YELLOW}! Rolling back to: ${TARGET}${RESET}"
echo "  Remote: ${REMOTE}:${REMOTE_PATH}"
read -r -p "  Proceed? Type 'yes' to confirm: " confirm
[ "$confirm" = "yes" ] || { echo "Aborted."; exit 1; }

ssh "$REMOTE" REMOTE_PATH="$REMOTE_PATH" TARGET="$TARGET" bash -s <<'REMOTE_EOF'
set -euo pipefail

: "${REMOTE_PATH:?}"
: "${TARGET:?}"

cd "$REMOTE_PATH"

echo "▸ Fetching..."
git fetch origin

# Resolve TARGET to a commit hash (allow tag or short SHA)
COMMIT=$(git rev-parse --verify "$TARGET^{commit}" 2>/dev/null || git rev-parse --verify "origin/$TARGET^{commit}" 2>/dev/null || echo "")
if [ -z "$COMMIT" ]; then
    echo "✗ Cannot resolve $TARGET to a commit"; exit 1
fi

echo "▸ Checking out $COMMIT..."
git checkout "$COMMIT"

echo "▸ Reinstalling deps..."
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

echo "▸ Warming cache..."
APP_ENV=prod php bin/console cache:clear --no-warmup
APP_ENV=prod php bin/console cache:warmup

echo "▸ Recording new HEAD..."
echo "$(git rev-parse HEAD)" > .last-release
REMOTE_EOF

echo
echo "${CYAN}▸${RESET} Verifying with smoke tests..."
if bash "$PROJECT_ROOT/bin/post-deploy-smoke.sh"; then
    echo
    echo "${CYAN}▸${RESET} Rollback to ${TARGET} complete."
else
    echo
    echo "${YELLOW}! Smoke tests reported issues. Inspect server logs.${RESET}"
fi
