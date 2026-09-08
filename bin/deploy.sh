#!/usr/bin/env bash
# Deploy script for live server.
# Used by GitHub Actions over SSH, or run manually on the server:
#   ./bin/deploy.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo "==> Deploying in $ROOT"

if [[ -d .git ]]; then
  BRANCH="${DEPLOY_BRANCH:-main}"
  echo "==> git fetch / checkout / pull ($BRANCH)"
  git fetch --prune origin
  git checkout "$BRANCH"
  git pull --ff-only origin "$BRANCH"
fi

if command -v composer >/dev/null 2>&1; then
  echo "==> composer install"
  composer install --no-dev --optimize-autoloader --no-interaction
else
  echo "WARNING: composer not found — skipping"
fi

if command -v npm >/dev/null 2>&1; then
  echo "==> npm ci / build:css"
  if [[ -f package-lock.json ]]; then
    npm ci
  else
    npm install
  fi
  npm run build:css
else
  echo "WARNING: npm not found — skipping CSS build"
fi

echo "==> database migrate"
php bin/console migrate

echo "==> cache:clear"
php bin/console cache:clear

echo "==> Deploy complete"
