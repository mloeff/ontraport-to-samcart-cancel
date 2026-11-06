#!/bin/sh
# Point this clone at the repo's git hooks. Does not change other git settings.
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)

if [ ! -d "$ROOT_DIR/.git" ]; then
  echo "No .git directory found. Clone the repository before installing hooks." >&2
  exit 1
fi

mkdir -p "$ROOT_DIR/.git/hooks"
cp "$ROOT_DIR/.githooks/pre-commit" "$ROOT_DIR/.git/hooks/pre-commit"
chmod +x "$ROOT_DIR/.git/hooks/pre-commit" "$ROOT_DIR/scripts/build-zip.sh" "$ROOT_DIR/scripts/install-hooks.sh"

echo "Installed pre-commit hook. Commits rebuild dist/ontraport-to-samcart-cancel.zip."
