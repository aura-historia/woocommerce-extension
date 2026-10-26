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

# Ship only runtime files. Local configuration, credentials and new development
# files must never enter the release merely because an exclusion was forgotten.
mkdir -p "$RELEASE_DIR"
for runtime_path in aura-historia-partner-connect.php uninstall.php includes languages readme.txt LICENSE composer.json composer.lock; do
  cp -R "$PROJECT_ROOT/$runtime_path" "$RELEASE_DIR/"
done

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
