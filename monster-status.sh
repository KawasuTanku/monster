#!/usr/bin/env bash
# monster-status.sh — fetch key metrics from Monster via API
# Usage:  ./monster-status.sh

MONSTER_URL="https://monster.warpstrand.com"
API_KEY=""

# --- fetch revenue / net profit ---
summary=$(curl -sf -H "Authorization: Bearer $API_KEY" -H "Accept: application/json" "$MONSTER_URL/api/report/summary")
if [ $? -ne 0 ] || [ -z "$summary" ]; then
    echo "ERROR: failed to fetch /api/report/summary" >&2
    exit 1
fi

revenue=$(echo "$summary" | python3 -c "import sys,json; print(json.load(sys.stdin)['revenue'])")
net=$(echo "$summary" | python3 -c "import sys,json; print(json.load(sys.stdin)['net'])")

# --- fetch low-stock count ---
stats=$(curl -sf -H "Authorization: Bearer $API_KEY" -H "Accept: application/json" "$MONSTER_URL/api/stats")
if [ $? -ne 0 ] || [ -z "$stats" ]; then
    echo "ERROR: failed to fetch /api/stats" >&2
    exit 1
fi

low=$(echo "$stats" | python3 -c "import sys,json; print(json.load(sys.stdin)['low_stock_count'])")

# --- display ---
printf "Revenue:        \$%s\n" "$revenue"
printf "Net Profit:     \$%s\n" "$net"
printf "Items Low on Stock: %s\n" "$low"
