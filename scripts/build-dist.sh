#!/usr/bin/env bash
# Build a WordPress.org-ready zip of the plugin.
#
# Strips dev dependencies, copies the plugin (honouring .distignore), zips it,
# and restores the dev composer install so the working tree is left as-is.
#
# Usage: bash scripts/build-dist.sh [--wporg] [output-dir]
#   --wporg      strip the GitHub update-checker (lib/plugin-update-checker)
#                so the zip is safe to submit to WordPress.org, which forbids
#                external update sources.
#   output-dir   defaults to ~/Desktop/Order Updates for Woo/

set -euo pipefail

WPORG=0
if [ "${1:-}" = "--wporg" ]; then
	WPORG=1
	shift
fi

PLUGIN_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
OUT_DIR="${1:-$HOME/Desktop/Order Updates for Woo}"
SLUG="order-updates-for-woo"
ZIP_NAME="${SLUG}.zip"
if [ "$WPORG" -eq 1 ]; then
	ZIP_NAME="${SLUG}-wporg.zip"
fi
ZIP_PATH="${OUT_DIR}/${ZIP_NAME}"
STAGE_DIR="$(mktemp -d)/${SLUG}"

cd "$PLUGIN_DIR"

mkdir -p "$OUT_DIR"

echo "→ Stripping dev dependencies"
mv vendor vendor.dev-backup
composer install --no-dev --optimize-autoloader --quiet

# Build a rsync exclude list from .distignore plus the backup folder.
EXCLUDES_FILE="$(mktemp)"
grep -v '^#' .distignore | grep -v '^$' > "$EXCLUDES_FILE"
echo "vendor.dev-backup" >> "$EXCLUDES_FILE"

# WordPress.org build: strip the bundled GitHub update-checker. With the
# library gone, GitHubUpdater::boot() finds no loader and stands down, so
# the WP.org copy updates natively by slug.
if [ "$WPORG" -eq 1 ]; then
	echo "/lib/plugin-update-checker" >> "$EXCLUDES_FILE"
	echo "→ WP.org build: stripping GitHub update-checker"
fi

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

# Also drop a copy into docs/downloads/ so the website's Download
# button serves the latest build straight from the same domain. This
# keeps the customer's click → download flow on-site, no GitHub
# redirect, no broken link if a release isn't tagged yet.
# Only the GitHub build feeds the website download (it carries the updater).
# The WP.org zip must never become the on-site download.
DOCS_DOWNLOADS="$PLUGIN_DIR/docs/downloads"
if [ "$WPORG" -eq 0 ] && [ -d "$PLUGIN_DIR/docs" ]; then
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
