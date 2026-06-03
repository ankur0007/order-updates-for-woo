#!/usr/bin/env bash
# Regenerate every minified asset (.min.css / .min.js) from its source.
#
# Production (SCRIPT_DEBUG off) serves the .min files, so a stale or missing
# .min silently breaks the design on the live site — run this after editing
# anything under assets/ so the minified versions never drift from source.
#
# CSS is minified with esbuild, JS with terser. Both prefer a local install
# and fall back to npx (fetched once, then cached).
#
# Usage:
#   bash scripts/build-assets.sh        # both CSS and JS
#   bash scripts/build-assets.sh css    # CSS only
#   bash scripts/build-assets.sh js     # JS only

set -euo pipefail

TARGET="${1:-both}"
PLUGIN_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
cd "$PLUGIN_DIR"

if [ -x node_modules/.bin/esbuild ]; then
	ESBUILD=(node_modules/.bin/esbuild)
else
	ESBUILD=(npx --yes esbuild)
fi

if [ -x node_modules/.bin/terser ]; then
	TERSER=(node_modules/.bin/terser)
else
	TERSER=(npx --yes terser)
fi

if [ "$TARGET" = "both" ] || [ "$TARGET" = "css" ]; then
	echo "→ Minifying CSS (esbuild)"
	find assets -name "*.css" ! -name "*.min.css" -print0 | while IFS= read -r -d '' src; do
		"${ESBUILD[@]}" "$src" --minify --outfile="${src%.css}.min.css"
	done
fi

if [ "$TARGET" = "both" ] || [ "$TARGET" = "js" ]; then
	echo "→ Minifying JS (terser)"
	find assets -name "*.js" ! -name "*.min.js" -print0 | while IFS= read -r -d '' src; do
		"${TERSER[@]}" "$src" -c -m -o "${src%.js}.min.js"
	done
fi

echo "✓ Minified assets regenerated."
