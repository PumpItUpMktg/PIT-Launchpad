#!/usr/bin/env bash
# Lints every PHP file in the plugin with `php -l`. Standing discipline: run
# clean before every ZIP build.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
status=0

while IFS= read -r -d '' file; do
    if ! php -l "$file" >/dev/null; then
        echo "SYNTAX ERROR: $file"
        status=1
    fi
done < <(find "$root" -name '*.php' -print0)

if [ "$status" -eq 0 ]; then
    echo "php -l clean across all files."
fi

exit "$status"
