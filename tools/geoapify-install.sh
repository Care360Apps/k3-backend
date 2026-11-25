#!/usr/bin/env bash
set -euo pipefail
umask 077
root=/home/ghostsmaw/care360-live
src="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"
p=app/Http/Controllers/PatientPlaceSearchController.php
c=config/services.php
[[ -f artisan && -f "$p" && -f "$c" && -f .env && "$src" != "$root" ]]
cmp -s "$src/tools/geoapify-controller-baseline.txt" "$p" || { echo "Live controller differs; stop for review."; exit 1; }
php -l "$src/$p"
[[ "${1:-}" == "--apply" ]] || { echo "Controller preflight passed; use --apply to install."; exit 0; }
backup="$(mktemp -d "$HOME/care360-geoapify-backup.XXXXXXXX")"
cp -p "$p" "$backup/controller.php"
cp -p "$c" "$backup/services.php"
rollback() { cp -p "$backup/controller.php" "$p"; cp -p "$backup/services.php" "$c"; php artisan config:cache >/dev/null; }
trap 'rollback; echo "Deployment rolled back";' ERR
php -r '$p=$argv[1]; $s=file_get_contents($p); if(strpos($s,"\x27care360_places\x27")!==false) exit(1); $needle="return ["; if(substr_count($s,$needle)!==1) exit(1); $entry="\n    \x27care360_places\x27 => [\n        \x27provider\x27 => env(\x27CARE360_PLACES_PROVIDER\x27, \x27google\x27),\n        \x27geoapify_key\x27 => env(\x27GEOAPIFY_API_KEY\x27, \x27\x27),\n    ],\n"; if(file_put_contents($p,str_replace($needle,$needle.$entry,$s))===false) exit(1);' "$c"
cp "$src/$p" "$p"
php -l "$p"
php -l "$c"
php artisan config:cache
php artisan route:list --path=places
trap - ERR
echo "Installed. Backup: $backup"
echo "Configure GEOAPIFY_API_KEY and CARE360_PLACES_PROVIDER privately, then run php artisan config:cache."
