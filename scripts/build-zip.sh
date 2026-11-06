#!/bin/sh
# Build a WordPress-installable zip from the current plugin files.
# Versioned output uses the Version header in ontraport-samcart-cancel.php.
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
PLUGIN_SLUG="ontraport-to-samcart-cancel"
PLUGIN_FILE="$ROOT_DIR/ontraport-samcart-cancel.php"
README_FILE="$ROOT_DIR/README.md"
DIST_DIR="$ROOT_DIR/dist"
STAGE_DIR=$(mktemp -d "${TMPDIR:-/tmp}/ontraport-to-samcart-cancel.XXXXXX")
PACKAGE_DIR="$STAGE_DIR/$PLUGIN_SLUG"
STAGE_ZIP="$STAGE_DIR/$PLUGIN_SLUG.zip"
OUTPUT_TMP=''

cleanup() {
  rm -rf "$STAGE_DIR"
  if [ -n "$OUTPUT_TMP" ]; then
    rm -f "$OUTPUT_TMP"
  fi
}

trap cleanup EXIT INT TERM

if ! command -v zip >/dev/null 2>&1; then
  echo "The zip command is required to build the plugin package." >&2
  exit 1
fi

if ! command -v unzip >/dev/null 2>&1; then
  echo "The unzip command is required to verify the plugin package." >&2
  exit 1
fi

php -l "$PLUGIN_FILE" >/dev/null

plugin_version=$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "$PLUGIN_FILE" | head -n 1 | tr -d '\r')
case "$plugin_version" in
  ""|*[!0-9A-Za-z._-]*)
    echo "Plugin header Version is missing or not safe for a filename: ${plugin_version:-<empty>}" >&2
    exit 1
    ;;
esac

mkdir -p "$PACKAGE_DIR" "$DIST_DIR"
cp "$PLUGIN_FILE" "$PACKAGE_DIR/ontraport-samcart-cancel.php"
cp "$README_FILE" "$PACKAGE_DIR/README.md"

(
  cd "$STAGE_DIR"
  zip -rXq "$STAGE_ZIP" "$PLUGIN_SLUG"
)

if [ ! -s "$STAGE_ZIP" ] || ! zip -T "$STAGE_ZIP" >/dev/null; then
  echo "Failed to create a valid WordPress plugin package." >&2
  exit 1
fi

listing=$(unzip -Z1 "$STAGE_ZIP")
for required in \
  "$PLUGIN_SLUG/ontraport-samcart-cancel.php" \
  "$PLUGIN_SLUG/README.md"
do
  if ! printf '%s\n' "$listing" | grep -Fxq "$required"; then
    echo "Package is missing $required" >&2
    exit 1
  fi
done

for excluded in tests/ scripts/ .git .github
do
  if printf '%s\n' "$listing" | grep -Fq "$excluded"; then
    echo "Package unexpectedly includes $excluded" >&2
    exit 1
  fi
done

write_zip() {
  dest=$1
  OUTPUT_TMP=$(mktemp "$DIST_DIR/.plugin-zip.XXXXXX")
  cp "$STAGE_ZIP" "$OUTPUT_TMP"
  chmod 644 "$OUTPUT_TMP"
  mv -f "$OUTPUT_TMP" "$dest"
  OUTPUT_TMP=''
}

write_zip "$DIST_DIR/$PLUGIN_SLUG.zip"
write_zip "$DIST_DIR/$PLUGIN_SLUG-$plugin_version.zip"

echo "Built WordPress plugin package:"
echo "  $DIST_DIR/$PLUGIN_SLUG.zip"
echo "  $DIST_DIR/$PLUGIN_SLUG-$plugin_version.zip"
