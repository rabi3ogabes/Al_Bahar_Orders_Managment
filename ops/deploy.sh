#!/usr/bin/env bash
# Zero-surprise rollout on a Docker Compose host. Usage: ./ops/deploy.sh <image-tag>
# 1. pull the new image  2. take a verified database backup  3. run migrations ONCE  4. start new containers
# 5. warm caches & restart workers. Rollback: ./ops/deploy.sh <previous-tag> (+ restore backup if a migration was destructive).
set -euo pipefail
TAG="${1:?image tag required}"
cd "$(dirname "$0")/.."
export APP_VERSION="$TAG"

echo "→ Pulling albahar/app:${TAG}"
docker compose pull app queue scheduler reverb

echo "→ Pre-deploy database backup"
./ops/backup.sh pre-deploy-"$TAG"

echo "→ Maintenance-safe migrations"
docker compose run --rm app php artisan migrate --force

echo "→ Starting new version"
docker compose up -d --remove-orphans
docker compose exec -T app php artisan optimize
docker compose exec -T app php artisan queue:restart

echo "→ Health check"
for i in $(seq 1 20); do
  if docker compose exec -T web wget -qO- http://localhost/api/v1/health | grep -q '"status":"ok"'; then
    echo "✓ Healthy: ${TAG}"; echo "$TAG" > .last-good-release; exit 0
  fi
  sleep 3
done
echo "✗ Health check failed — roll back with: ./ops/deploy.sh $(cat .last-good-release 2>/dev/null || echo '<previous-tag>')" >&2
exit 1
