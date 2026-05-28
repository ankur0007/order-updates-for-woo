#!/usr/bin/env bash

set -euo pipefail

ORDER_ID="${1:-}"
LIMIT="${2:-}"

if [ -z "${ORDER_ID}" ]; then
	echo "Usage: composer create-email-previews -- <order-id> [limit]"
	exit 1
fi

PLUGIN_ROOT="$(cd "$(dirname "$0")" && pwd)"
WORKSPACE_ROOT="$(cd "${PLUGIN_ROOT}/../../../../" && pwd)"
SCRIPT_FILE="wordpress/wp-content/plugins/order-updates-for-woo/tests/create-email-previews.php"

docker compose -f "${WORKSPACE_ROOT}/docker-compose.yml" exec app wp --allow-root --path=/var/www/html/wordpress eval-file "${SCRIPT_FILE}" "${ORDER_ID}" "${LIMIT}"
