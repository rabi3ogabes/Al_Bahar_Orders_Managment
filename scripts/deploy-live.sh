#!/usr/bin/env bash
# Release the live site. Run ON THE SERVER:   scripts/deploy-live.sh <commit> [branch]
#
# What it does, in order (and why):
#  1. checks out exactly <commit> in the compose folder's src/ (what was tested is what runs);
#  2. works out the version shown in the dashboard footer: 1.0.<number of commits>, the short commit and the build time (UTC);
#  3. rebuilds the images and restarts what changed (docker compose up -d --build). The version is passed as build arguments, so it
#     changes with every release and is the last image layer (no rebuild above it);
#  4. reloads nginx, because it keeps the old address of the app container until it is told to look again (skipping this = 502 for a while);
#  5. waits until /api/v1/health answers 200 and prints what is running.
# It never touches the database or the storage volume. Migrations run by the `init` service on start.
set -euo pipefail

COMPOSE_DIR="${COMPOSE_DIR:-/var/www/_sites/albahar-app/compose}"
PROJECT="${PROJECT:-sp-albahar-app}"
BRANCH="${2:-claude/optimistic-euler-cffll1}"
REF="${1:?usage: deploy-live.sh <commit> [branch]}"
URL="${HEALTH_URL:-https://albahar.atharqatar.net/api/v1/health}"

cd "$COMPOSE_DIR"
git -C src fetch -q origin "$BRANCH"
git -C src reset -q --hard "$REF"

export APP_COMMIT="$(git -C src rev-parse --short HEAD)"
export APP_VERSION="1.0.$(git -C src rev-list --count HEAD)"
export APP_BUILT_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "Releasing $APP_VERSION ($APP_COMMIT) built at $APP_BUILT_AT"

docker compose -p "$PROJECT" -f compose.yaml up -d --build
sleep 6
docker exec "$PROJECT-web-1" nginx -s reload

for i in $(seq 1 30); do
  if [ "$(curl -s -o /dev/null -w '%{http_code}' -A 'deploy-check' "$URL")" = "200" ]; then
    echo "Healthy after ~$((i * 2)) s. Version $APP_VERSION ($APP_COMMIT) is live."
    docker ps --format '  {{.Names}}  {{.Status}}' | grep "$PROJECT" || true
    exit 0
  fi
  sleep 2
done
echo "The site did not answer 200 within 60 s: check 'docker logs $PROJECT-app-1'." >&2
exit 1
