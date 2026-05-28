#!/bin/bash
# Runs Action Scheduler every 30 seconds. Start with: ./scripts/run-scheduler.sh &
while true; do
  docker exec wordpress-app-1 wp --allow-root --path=/var/www/html/wordpress action-scheduler run --force 2>/dev/null
  sleep 30
done
