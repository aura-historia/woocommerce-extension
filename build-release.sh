#!/usr/bin/env sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT_ROOT=$SCRIPT_DIR
PLUGIN_SLUG="aura-historia-partner-connect"
BUILD_DIR="$PROJECT_ROOT/build-release"
RELEASE_DIR="$BUILD_DIR/$PLUGIN_SLUG"
ZIP_PATH="$PROJECT_ROOT/$PLUGIN_SLUG.zip"

rm -rf "$BUILD_DIR" "$ZIP_PATH"
mkdir -p "$BUILD_DIR"

rsync -a "$PROJECT_ROOT/" "$RELEASE_DIR/" \
  --exclude .git \
  --exclude .github \
  --exclude .tmp-openapi-inspect \
  --exclude assets \
  --exclude build-release \
  --exclude "$PLUGIN_SLUG.zip" \
  --exclude node_modules \
  --exclude vendor \
  --exclude tests \
  --exclude scripts \
  --exclude openapi \
  --exclude build-release.sh \
  --exclude .wp-env.json \
  --exclude .wp-env.override.json \
  --exclude .phpunit.result.cache \
  --exclude package.json \
  --exclude package-lock.json \
  --exclude phpunit.xml.dist \
  --exclude README.md \
  --exclude .gitignore \
  --exclude .gitattributes

(
  cd "$RELEASE_DIR"
  composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
  rm -f composer.lock
)

(
  cd "$BUILD_DIR"
  zip -rq "$ZIP_PATH" "$PLUGIN_SLUG"
)

echo "Created release ZIP at $ZIP_PATH"
