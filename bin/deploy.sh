#!/usr/bin/env bash
#
# bin/deploy.sh
#
# Deploy TNSVT to a Hostinger-like Linux target via SSH + git.
#
# Required environment variables (set in your shell or .env.local — NOT in the
# repo). Use a secrets manager in production; for now, export them in the
# shell that runs this script:
#
#   export TNSVT_DEPLOY_SSH="user@hostinger.example"
#   export TNSVT_DEPLOY_PATH="/home/user/public_html"
#   export TNSVT_DEPLOY_BRANCH="main"
#
# Pre-req: `./bin/pre-deploy-check.sh` must pass first.
#
# Usage:  ./bin/deploy.sh
set -euo pipefail

# Resolve project root
PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_ROOT"

# ─── Config ─────────────────────────────────────────────────────────
: "${TNSVT_DEPLOY_SSH:?Set TNSVT_DEPLOY_SSH=user@host (required)}"
: "${TNSVT_DEPLOY_PATH:?Set TNSVT_DEPLOY_PATH=/remote/path (required)}"
: "${TNSVT_DEPLOY_BRANCH:=main}"

REMOTE="${TNSVT_DEPLOY_SSH}"
REMOTE_PATH="${TNSVT_DEPLOY_PATH}"
BRANCH="${TNSVT_DEPLOY_BRANCH}"
HEALTHCHECK_URL="${TNSVT_DEPLOY_HEALTHCHECK:-https://$(echo "${REMOTE##*@}" | sed 's/:.*//')}"

# ─── Colors ────────────────────────────────────────────────────────
if [ -t 1 ]; then
    RED=$'\e[31m'; GREEN=$'\e[32m'; YELLOW=$'\e[33m'; CYAN=$'\e[36m'; RESET=$'\e[0m'
else
    RED=''; GREEN=''; YELLOW=''; CYAN=''; RESET=''
fi

log() { echo "${CYAN}�${RESET} $*"; }
warn() { echo "${YELLOW}!${RESET} $*"; }
die() { echo "${RED}✗ $*${RESET}"; exit 1; }

# ─── Pre-flight ────────────────────────────────────────────────────
log "Running pre-deploy checks..."
if ! bash "$PROJECT_ROOT/bin/pre-deploy-check.sh" >/tmp/tnsvt-precheck.log 2>&1; then
    cat /tmp/tnsvt-precheck.log
    die "Pre-deploy checks failed. Refusing to deploy."
fi
ok "Pre-deploy checks passed."

log "Verifying remote connectivity..."
if ! ssh -o ConnectTimeout=10 -o BatchMode=yes "$REMOTE" true 2>/dev/null; then
    die "Cannot SSH to $REMOTE. Check TNSVT_DEPLOY_SSH and ssh config."
fi
ok "SSH reachable."

# ─── Tag release for rollback ──────────────────────────────────────
TIMESTAMP=$(date +%Y%m%d-%H%M%S)
RELEASE_TAG="tnsvt-release-${TIMESTAMP}"
git tag -a "$RELEASE_TAG" -m "Release ${TIMESTAMP}" >/dev/null 2>&1 || true
log "Tagged release: ${RELEASE_TAG}"

# ─── Push ─────────────────────────────────────────────────────────
log "Pushing ${BRANCH} to remote..."
git push origin "${BRANCH}" --tags || die "git push failed"

# ─── Remote deploy ─────────────────────────────────────────────────
log "Running remote deploy steps on ${REMOTE}..."

ssh "$REMOTE" REMOTE_PATH="$REMOTE_PATH" BRANCH="$BRANCH" RELEASE_TAG="$RELEASE_TAG" bash -s <<'REMOTE_EOF'
set -euo pipefail

: "${REMOTE_PATH:?}"
: "${BRANCH:?}"

cd "$REMOTE_PATH"

echo "▸ Fetching latest..."
git fetch origin
git checkout "$BRANCH"
git pull --ff-only origin "$BRANCH"

echo "� Recording pre-deploy HEAD for rollback..."
echo "$(git rev-parse HEAD)" > .last-release

echo "▸ Installing PHP dependencies..."
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

echo "▸ Clearing + warming Symfony cache..."
APP_ENV=prod php bin/console cache:clear --no-warmup
APP_ENV=prod php bin/console cache:warmup

echo "▸ Installing assets (asset-mapper)..."
php bin/console assets:install public --no-interaction || true
php bin/console importmap:install --no-interaction || true

echo "▸ Running Doctrine migrations..."
APP_ENV=prod php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration || true

echo "▸ Clearing PHP-FPM opcache (if available)..."
if command -v caddy >/dev/null 2>&1; then
    caddy reload --config /etc/caddy/Caddyfile 2>/dev/null || true
fi
REMOTE_EOF

ok "Remote deploy steps complete."

# ─── Local smoke test ──────────────────────────────────────────────
log "Running post-deploy smoke tests against ${HEALTHCHECK_URL}..."
if bash "$PROJECT_ROOT/bin/post-deploy-smoke.sh" "$HEALTHCHECK_URL"; then
    ok "Smoke tests passed."
else
    warn "Smoke tests reported problems. Inspect server logs."
fi

echo
echo "${GREEN}✓ Deploy complete.${RESET}"
echo "  Branch: ${BRANCH}"
echo "  Release tag: ${RELEASE_TAG}"
echo "  Rollback: ./bin/rollback.sh ${RELEASE_TAG}"
