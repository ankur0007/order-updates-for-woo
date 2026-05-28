#!/usr/bin/env bash

set -euo pipefail

ORDER_ID="${1:-}"
ASSIGNEE_ID="${2:-}"
ACTING_USER_ID="${3:-}"

if [ -z "${ORDER_ID}" ]; then
	echo "Usage: composer create-sample-updates -- <order-id> [assignee-user-id] [acting-user-id]"
	exit 1
fi

PLUGIN_ROOT="$(cd "$(dirname "$0")" && pwd)"
WORKSPACE_ROOT="$(cd "${PLUGIN_ROOT}/../../../../" && pwd)"
SEED_FILE="wordpress/wp-content/plugins/order-updates-for-woo/tests/seed-sample-updates.php"

docker compose -f "${WORKSPACE_ROOT}/docker-compose.yml" exec app wp --allow-root --path=/var/www/html/wordpress eval-file "${SEED_FILE}" "${ORDER_ID}" "${ASSIGNEE_ID}" "${ACTING_USER_ID}"
