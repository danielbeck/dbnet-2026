#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PYTHON="$ROOT/.venv-related/bin/python"

live=false
clusters=false
while [[ $# -gt 0 ]]; do
    case "$1" in
        --live) live=true ;;
        --clusters) clusters=true ;;
        *) echo "Usage: $0 [--live] [--clusters]" >&2; exit 1 ;;
    esac
    shift
done
if [[ ! -x "$PYTHON" ]]; then
    echo "Related-post environment not found. See the README for setup instructions." >&2
    exit 1
fi

if [[ "$live" == false ]]; then
    if [[ "$clusters" == true ]]; then
        exec "$PYTHON" "$ROOT/bin/generate-related.py" --publish --clusters
    fi
    exec "$PYTHON" "$ROOT/bin/generate-related.py" --publish --links
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
report_file=""
trap 'rm -f -- "$temporary"; if [[ -n "$report_file" ]]; then rm -f -- "$report_file"; fi' EXIT
if [[ "$clusters" == true ]]; then
    "$PYTHON" "$ROOT/bin/generate-related.py" --include-snapshot --show-clusters > "$temporary"
else
    report_file="$(mktemp "${TMPDIR:-/tmp}/dbnet-related-report.XXXXXXXX")"
    "$PYTHON" "$ROOT/bin/generate-related.py" --include-snapshot --link-report "$report_file" > "$temporary"
fi
php "$ROOT/bin/publish-related-live.php" "$DEPLOY_TARGET" "${REMOTE_PHP:-}" "$temporary"
if [[ -n "$report_file" ]]; then
    cat -- "$report_file"
fi
