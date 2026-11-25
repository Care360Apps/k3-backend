#!/usr/bin/env bash
set -euo pipefail
umask 077
root=/home/ghostsmaw/care360-live
dir="$(cd "$(dirname "$0")" && pwd)"
patchfile="$dir/trains-production.patch"
migration=database/migrations/2026_11_24_150000_add_trains_catalogue_controls.php
[[ "${1:-}" == "--database-backup-confirmed" ]] || { echo "First create and verify a private database backup in cPanel. Then run with --database-backup-confirmed."; exit 1; }
bash "$dir/trains-preflight.sh"
cd "$root"
git apply --check "$patchfile"
backup="$(mktemp -d "$HOME/trains-backup.XXXXXXXX")"
while IFS= read -r f; do
  if [[ -f "$f" ]]; then
    mkdir -p "$backup/files/$(dirname "$f")"
    cp -p -- "$f" "$backup/files/$f"
  else
    printf '%s\n' "$f" >> "$backup/new-files.txt"
  fi
done < <(sed -n 's/^diff --git a\/[^ ]* b\///p' "$patchfile")
echo "File backup: $backup"
echo "On failure keep maintenance mode enabled and inspect the error; do not blindly roll back database migrations."
php artisan down
git apply "$patchfile"
php artisan migrate --path="$migration" --force
php artisan route:clear
php artisan view:clear
php artisan route:list --path=course
php artisan view:cache
php artisan trains:privatize-images || { echo "Legacy image audit needs review; maintenance mode remains enabled."; exit 1; }
php artisan up
echo "TRAINS INSTALLED. Live acceptance tests and legacy image review still required."
