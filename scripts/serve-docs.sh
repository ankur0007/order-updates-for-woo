#!/usr/bin/env bash
# Preview the docs site (docs/) locally — the same files that deploy to the
# Cloudflare Worker at orderupdatesforwoo.com.
#
# Usage: bash scripts/serve-docs.sh [port]
#   port   defaults to 8090
#
# Prefers `wrangler dev` when available (production-faithful: clean URLs,
# _redirects and _headers honoured). Falls back to a dependency-free static
# server, which is fine for a visual check (folder paths resolve to index.html).
#
# Optional pretty local domain instead of 127.0.0.1:
#   sudo sh -c 'echo "127.0.0.1 orderupdates.test" >> /etc/hosts'
# then open http://orderupdates.test:<port>/

set -euo pipefail

PORT="${1:-8090}"
DOCS_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )/../docs" && pwd )"

cd "$DOCS_DIR"

echo "→ Serving docs/ at http://127.0.0.1:${PORT}/"
echo "  • Home:            http://127.0.0.1:${PORT}/"
echo "  • Features:        http://127.0.0.1:${PORT}/features.html"
echo "  • User guide:      http://127.0.0.1:${PORT}/user-guide/getting-started/"
echo "  (Ctrl+C to stop)"
echo

if command -v wrangler >/dev/null 2>&1; then
	echo "→ Using wrangler dev (production-faithful)."
	exec wrangler dev --port "$PORT"
fi

echo "→ wrangler not found; using a static server (visual preview only)."
exec python3 -m http.server "$PORT" --bind 127.0.0.1
