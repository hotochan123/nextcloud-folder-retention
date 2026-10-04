#!/usr/bin/env bash
# Copies the app into a TEST instance (container nc-app by default) and runs occ upgrade.
# Never point it at a production instance.
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONTAINER="${CONTAINER:-nc-app}"
TARGET=/var/www/html/custom_apps/folder_retention

cd "$APP_DIR"

# Browsers cache the bundle by app version (?v=…). Changed JS under the same version
# would never reach users — bump the version in info.xml then.
BUNDLE=js/folder_retention-main.mjs
LOCAL_VERSION="$(sed -n 's#.*<version>\(.*\)</version>.*#\1#p' appinfo/info.xml)"
REMOTE_VERSION="$(docker exec "$CONTAINER" sh -c "sed -n 's#.*<version>\\(.*\\)</version>.*#\\1#p' $TARGET/appinfo/info.xml 2>/dev/null" || true)"
REMOTE_SUM="$(docker exec "$CONTAINER" sh -c "md5sum $TARGET/$BUNDLE 2>/dev/null | cut -d' ' -f1" || true)"
LOCAL_SUM="$(md5sum "$BUNDLE" | cut -d' ' -f1)"
if [[ -n "$REMOTE_SUM" && "$REMOTE_SUM" != "$LOCAL_SUM" && "$REMOTE_VERSION" == "$LOCAL_VERSION" ]]; then
  echo "Abort: frontend changed but version $LOCAL_VERSION unchanged — browsers would keep the old bundle." >&2
  echo "Bump <version> in appinfo/info.xml (and package.json)." >&2
  exit 1
fi

tar --exclude=./vendor --exclude=./tests --exclude=./.git --exclude=./node_modules \
    --exclude=./src --exclude=./scripts --exclude="*.map" --exclude=./package-lock.json -cf - . \
  | docker exec -i "$CONTAINER" sh -c "rm -rf $TARGET && mkdir $TARGET && tar -xf - -C $TARGET && chown -R www-data:www-data $TARGET"

docker exec -u www-data "$CONTAINER" php occ app:enable folder_retention >/dev/null
docker exec -u www-data "$CONTAINER" php occ upgrade --no-interaction | tail -3 || true
# The image's OPcache checks for changes only every 60 s — reload Apache gracefully
docker exec "$CONTAINER" apachectl -k graceful 2>/dev/null || true
docker exec -u www-data "$CONTAINER" php occ app:list | grep folder_retention
