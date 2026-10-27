#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
if [[ $# -gt 1 || ( $# -eq 1 && "$1" != "--dry-run" ) ]]; then
    echo "Usage: $0 [--dry-run]" >&2
    exit 1
fi
if [[ -z "${DEPLOY_TARGET+x}" && -f "$ROOT/.env" ]]; then
    source "$ROOT/.env"
fi
REMOTE="${DEPLOY_TARGET:-}"
if [[ -z "$REMOTE" ]]; then
    echo "Set DEPLOY_TARGET in $ROOT/.env or in the environment. See .env.example." >&2
    exit 1
fi
if [[ "$REMOTE" != *:* || "$REMOTE" == -* || -z "${REMOTE##*:}" ]]; then
    echo "DEPLOY_TARGET must be an SSH destination such as user@host:directory." >&2
    exit 1
fi
for tool in rsync ssh; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        echo "$tool is required for deployment." >&2
        exit 1
    fi
done

cd "$ROOT"
OPTIONS=(
    -rltz --itemize-changes -e ssh
    --exclude='*.sqlite*'
    --exclude='*.db'
    --exclude='*.db-*'
    --include='/.htaccess'
    --include='/index.php'
    --include='/app/***'
    --include='/templates/***'
    --include='/assets/***'
    --include='/storage/'
    --include='/storage/.htaccess'
    --include='/storage/index.html'
    --include='/content/'
    --include='/content/.htaccess'
    --include='/content/index.html'
    --include='/bin/'
    --include='/bin/create-admin.php'
    --include='/bin/initialize.php'
    --exclude='*'
)

echo "Deployment target: $REMOTE"
echo "Database files and content media are excluded. No remote files will be deleted."
echo "--- Dry run ---"
rsync "${OPTIONS[@]}" --dry-run ./ "${REMOTE%/}/"
if [[ "${1:-}" == "--dry-run" ]]; then
    exit 0
fi

read -r -p "Deploy these changes? [y/N] " answer
case "$answer" in
    y|Y|yes|YES) ;;
    *) echo "Deployment cancelled."; exit 0 ;;
esac

rsync "${OPTIONS[@]}" ./ "${REMOTE%/}/"
echo "Code deployment complete. The live database and content media were not uploaded or deleted."
