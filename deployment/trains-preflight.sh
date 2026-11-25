#!/usr/bin/env bash
set -euo pipefail
umask 077
root=/home/ghostsmaw/care360-live
patchfile="$(cd "$(dirname "$0")" && pwd)/trains-production.patch"
cd "$root"
stage="$(mktemp -d "$HOME/trains-preflight.XXXXXXXX")"
trap 'rm -rf -- "$stage"' EXIT
while IFS= read -r f; do
  if [[ -f "$f" ]]; then
    mkdir -p "$stage/$(dirname "$f")"
    cp -p -- "$f" "$stage/$f"
  fi
done < <(sed -n 's/^diff --git a\/[^ ]* b\///p' "$patchfile")
cd "$stage"
git apply --check "$patchfile"
git apply "$patchfile"
while IFS= read -r f; do
  [[ "$f" == *.php ]] && php -l "$f"
done < <(sed -n 's/^diff --git a\/[^ ]* b\///p' "$patchfile")
echo "STAGED PATCH PASSED. No live files or database changed."
