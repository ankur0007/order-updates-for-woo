#!/usr/bin/env bash
# Build the WordPress.org-ready zip of the plugin.
#
# Strips dev dependencies, copies the plugin (honouring .distignore), zips it,
# and restores the dev composer install so the working tree is left as-is.
# This is the only build — the website Download button serves the same zip.
#
# Usage: bash scripts/build-dist.sh [output-dir]
#   output-dir   defaults to ~/Desktop/Order Updates for Woo/

set -euo pipefail

PLUGIN_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
OUT_DIR="${1:-$HOME/Desktop/Order Updates for Woo}"
SLUG="order-updates-for-woo"
ZIP_NAME="${SLUG}.zip"
ZIP_PATH="${OUT_DIR}/${ZIP_NAME}"
STAGE_DIR="$(mktemp -d)/${SLUG}"

cd "$PLUGIN_DIR"

mkdir -p "$OUT_DIR"

# Regenerate minified assets so the zip never ships a stale .min.css/.min.js.
# Production (SCRIPT_DEBUG off) serves the .min files, so a drift here breaks
# the design on the live site. Best-effort: warn and continue if Node is absent.
if command -v node >/dev/null 2>&1; then
	echo "→ Rebuilding minified assets"
	bash scripts/build-assets.sh
else
	echo "⚠ Node not found — shipping committed minified assets as-is"
fi

echo "→ Stripping dev dependencies"
mv vendor vendor.dev-backup
composer install --no-dev --optimize-autoloader --quiet

# Build a rsync exclude list from .distignore plus the backup folder.
EXCLUDES_FILE="$(mktemp)"
grep -v '^#' .distignore | grep -v '^$' > "$EXCLUDES_FILE"
echo "vendor.dev-backup" >> "$EXCLUDES_FILE"

echo "→ Staging files"
mkdir -p "$STAGE_DIR"
rsync -a --exclude-from="$EXCLUDES_FILE" ./ "$STAGE_DIR/"

echo "→ Building zip"
rm -f "$ZIP_PATH"
( cd "$(dirname "$STAGE_DIR")" && zip -rq "$ZIP_PATH" "$(basename "$STAGE_DIR")" )

echo "→ Restoring dev dependencies"
rm -rf vendor
mv vendor.dev-backup vendor

echo "→ Refreshing zip timestamp"
touch "$ZIP_PATH"

# Also drop a copy into docs/downloads/ so the website's Download button
# serves this same build straight from the same domain — the customer's
# click → download flow stays on-site, no GitHub redirect, no broken link.
DOCS_DOWNLOADS="$PLUGIN_DIR/docs/downloads"
if [ -d "$PLUGIN_DIR/docs" ]; then
	mkdir -p "$DOCS_DOWNLOADS"
	cp "$ZIP_PATH" "$DOCS_DOWNLOADS/${SLUG}.zip"
	echo "→ Copied to docs/downloads/${SLUG}.zip"
fi

rm -f "$EXCLUDES_FILE"
rm -rf "$(dirname "$STAGE_DIR")"

echo
echo "Zip: $ZIP_PATH"
ls -lh "$ZIP_PATH"
echo
echo "Next: unzip and run 'wp plugin check' against the unpacked dir."
