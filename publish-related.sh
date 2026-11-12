#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PYTHON="$ROOT/.venv-related/bin/python"

if [[ $# -gt 1 || ( $# -eq 1 && "$1" != "--live" ) ]]; then
    echo "Usage: $0 [--live]" >&2
    exit 1
fi
if [[ ! -x "$PYTHON" ]]; then
    echo "Related-post environment not found. See the README for setup instructions." >&2
    exit 1
fi

if [[ $# -eq 0 ]]; then
    exec "$PYTHON" "$ROOT/bin/generate-related.py" --publish
fi

if [[ -z "${DEPLOY_TARGET+x}" && -f "$ROOT/.env" ]]; then
    source "$ROOT/.env"
fi
if [[ -z "${DEPLOY_TARGET:-}" ]]; then
    echo "Set DEPLOY_TARGET in $ROOT/.env or in the environment. See .env.example." >&2
    exit 1
fi
for tool in php ssh; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        echo "$tool is required for live publishing." >&2
        exit 1
    fi
done

echo "Generate related links locally and replace the related links at $DEPLOY_TARGET?"
read -r -p "Publish to the live database? [y/N] " answer
case "$answer" in
    y|Y|yes|YES) ;;
    *) echo "Live publishing cancelled."; exit 0 ;;
esac

temporary="$(mktemp "${TMPDIR:-/tmp}/dbnet-related.XXXXXXXX")"
trap 'rm -f -- "$temporary"' EXIT
"$PYTHON" "$ROOT/bin/generate-related.py" --include-snapshot > "$temporary"
php "$ROOT/bin/publish-related-live.php" "$DEPLOY_TARGET" "${REMOTE_PHP:-}" "$temporary"
