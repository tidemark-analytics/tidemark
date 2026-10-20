#!/usr/bin/env bash
# Builds a zip you can upload to any PHP 8.3+ shared host: dependencies are bundled, so no SSH/Composer needed there.
# Usage: scripts/build-release.sh [version]
set -euo pipefail
cd "$(dirname "$0")/.."

VERSION="${1:-$(date +%Y.%m.%d)}"
NAME="tidemark-${VERSION}"
OUT="dist"
STAGE="$(mktemp -d)/${NAME}"

echo "→ Staging ${NAME}"
mkdir -p "$STAGE" "$OUT"
rsync -a --exclude='.git' --exclude='.env' --exclude='.env.backup' --exclude='/vendor' --exclude='/node_modules' \
  --exclude='/dist' --exclude='/tests' --exclude='/storage/logs/*' --exclude='/storage/framework/cache/data/*' \
  --exclude='/storage/framework/sessions/*' --exclude='/storage/framework/views/*' --exclude='/storage/app/installed' --exclude='/storage/framework/migrated-version' --exclude='/storage/framework/update-pending' --exclude='/storage/app/updates' \
  --exclude='/database/*.sqlite' --exclude='/bootstrap/cache/*.php' --exclude='.phpunit.*' --exclude='phpunit.xml' \
  --exclude='/.agents' --exclude='/.cursor' --exclude='/.zed' --exclude='/.mcp.json' --exclude='/boost.json' \
  --exclude='/resources/brand' --exclude='/.github' --exclude='/.gitattributes' --exclude='/.editorconfig' --exclude='/CONTRIBUTING.md' --exclude='/SECURITY.md' --exclude='/CODE_OF_CONDUCT.md' --exclude='/CHANGELOG.md' --exclude='/AGENTS.md' --exclude='/CLAUDE.md' --exclude='/.claude' --exclude='.DS_Store' \
  ./ "$STAGE/"

echo "$VERSION" > "$STAGE/VERSION"

echo "→ Installing production dependencies"
cp composer.json composer.lock "$STAGE/"
( cd "$STAGE" && composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-scripts --quiet )

# Ensure writable runtime folders exist
mkdir -p "$STAGE"/storage/{app/public,framework/{cache/data,sessions,views},logs} "$STAGE"/bootstrap/cache
touch "$STAGE"/storage/logs/.gitkeep

echo "→ Zipping"
( cd "$(dirname "$STAGE")" && zip -qr "$OLDPWD/${OUT}/${NAME}.zip" "${NAME}" )
echo "✓ ${OUT}/${NAME}.zip ($(du -h "${OUT}/${NAME}.zip" | cut -f1))"
