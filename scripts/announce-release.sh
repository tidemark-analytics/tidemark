#!/usr/bin/env bash
# Posts a release to the community's read-only Releases chat channel. Usage: announce.sh <version> <notes-file> <release-url>
# Needs COMMUNITY_RELEASES_WEBHOOK (the channel's incoming webhook address). Never fails the release.
set -uo pipefail
V="$1"; NOTES_FILE="$2"; URL="$3"
[ -n "${COMMUNITY_RELEASES_WEBHOOK:-}" ] || { echo "No community webhook set; skipping."; exit 0; }
# Notes are cut to a short excerpt, and @ is broken up so a GitHub handle cannot ping a forum member of the same name.
NOTES="$(head -c 1500 "$NOTES_FILE" | sed 's/@/@\xe2\x80\x8b/g')"
TEXT="$(printf '**Tidemark %s is out**\n\n%s\n\n[Full release notes](%s)' "$V" "$NOTES" "$URL")"
jq -n --arg text "$TEXT" '{text: $text}' | curl -fsS --max-time 20 -X POST -H 'Content-Type: application/json' -d @- "$COMMUNITY_RELEASES_WEBHOOK" >/dev/null && echo "Posted $V to the community." || echo "Could not post to the community (ignored)."
