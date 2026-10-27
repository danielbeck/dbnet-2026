#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-8080}"

for tool in php ssh rsync; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        echo "$tool is required to sync and run the development server." >&2
        exit 1
    fi
done
if [[ -z "${DEPLOY_TARGET+x}" && -f "$ROOT/.env" ]]; then
    source "$ROOT/.env"
fi
if [[ -z "${DEPLOY_TARGET:-}" ]]; then
    echo "Set DEPLOY_TARGET in $ROOT/.env or in the environment. See .env.example." >&2
    exit 1
fi

php -r '
$socket = @stream_socket_server("tcp://" . $argv[1] . ":" . $argv[2], $errno, $error);
if ($socket === false) {
    fwrite(STDERR, "Cannot start development server: $error. Stop the existing server before syncing.\n");
    exit(1);
}
fclose($socket);
' "$HOST" "$PORT"

php "$ROOT/bin/sync-dev.php" "$DEPLOY_TARGET" "${REMOTE_PHP:-}"

echo "Starting development server at http://${HOST}:${PORT} (Ctrl+C to stop)"
exec php -S "${HOST}:${PORT}" -t "$ROOT" "$ROOT/bin/dev-router.php"
