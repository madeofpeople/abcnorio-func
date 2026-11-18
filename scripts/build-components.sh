#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${ABCNORIO_COMPONENTS_DIST_SOURCE:-${ROOT_DIR}/node_modules/abcnorio-webcomponents/dist}"
SOURCE_DIR="$(dirname "$DIST_DIR")"

if [[ ! -f "$SOURCE_DIR/astro.config.mjs" || ! -f "$SOURCE_DIR/scripts/build.sh" ]]; then
  echo "Missing webcomponents build sources in $SOURCE_DIR. Install the updated source package before building." >&2
  exit 1
fi

bash "$SOURCE_DIR/scripts/build.sh"
if [[ "${1:-}" == "--development" ]]; then
  ABCNORIO_COMPONENTS_DIST_SOURCE="$DIST_DIR" node "$ROOT_DIR/scripts/publish-components-dev.mjs"
else
  bash "$ROOT_DIR/scripts/ingest-components-dist.sh"
fi
