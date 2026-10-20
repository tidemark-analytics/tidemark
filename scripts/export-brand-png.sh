#!/usr/bin/env bash
# Rasterizes the brand SVGs with headless Chrome (macOS). Usage: scripts/export-brand-png.sh
set -euo pipefail
cd "$(dirname "$0")/.."
CHROME="${CHROME:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}"
TMP="$(mktemp -d)"
shot() { "$CHROME" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files --default-background-color=00000000 --screenshot="$1" --window-size="$2,$3" "file://$4" >/dev/null 2>&1; }
square() { # svg size out
  printf '<!doctype html><body style="margin:0;background:transparent"><img src="file://%s" width="%s" height="%s" style="display:block">' "$PWD/$1" "$2" "$2" > "$TMP/p.html"
  shot "$TMP/o.png" 800 800 "$TMP/p.html"; sips --cropToHeightWidth "$2" "$2" --cropOffset 0 0 "$TMP/o.png" --out "$3" >/dev/null
}
for s in 16 32 48 64 128 256 512; do square resources/brand/logo/tidemark-mark.svg $s resources/brand/png/mark-$s.png; done
square resources/brand/logo/tidemark-app-icon.svg 180 public/assets/apple-touch-icon.png
square resources/brand/logo/tidemark-app-icon.svg 192 public/assets/icon-192.png
square resources/brand/logo/tidemark-app-icon.svg 512 public/assets/icon-512.png
cp resources/brand/png/mark-32.png /tmp/tidemark-favicon32.png
sips -s format ico resources/brand/png/mark-32.png --out public/favicon.ico >/dev/null 2>&1 || cp resources/brand/png/mark-32.png public/favicon.ico
shot resources/brand/png/social-card.png 1200 630 "$PWD/resources/brand/social-card.html"
sips --cropToHeightWidth 630 1200 --cropOffset 0 0 resources/brand/png/social-card.png >/dev/null
echo "exported: $(ls resources/brand/png | wc -l) PNGs + touch icons + favicon.ico"
