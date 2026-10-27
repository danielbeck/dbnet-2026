#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-8080}"

if ! command -v php >/dev/null 2>&1; then
    echo "PHP is required to run the development server." >&2
    exit 1
fi

echo "Starting development server at http://${HOST}:${PORT} (Ctrl+C to stop)"
exec php -S "${HOST}:${PORT}" -t "$ROOT" "$ROOT/bin/dev-router.php"
