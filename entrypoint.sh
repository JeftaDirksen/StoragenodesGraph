#!/bin/bash
set -e

# Function to handle shutdown
cleanup() {
    echo "Shutting down..."
    if [ ! -z "$COLLECTOR_PID" ]; then
        kill $COLLECTOR_PID 2>/dev/null || true
    fi
    exit 0
}

# Register signal handlers
trap cleanup SIGTERM SIGINT

# Start collector in background (runs every 5 minutes)
while true; do
    php /var/www/html/collector.php
    sleep 900  # 15 minutes
done &
COLLECTOR_PID=$!

# Wait a bit for initial data collection
sleep 5

# Start Apache in foreground
exec apache2-foreground
