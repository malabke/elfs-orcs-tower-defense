#!/usr/bin/env bash
# A new version of the JumPHP IDE, for everyone: the installed IDEs update themselves from it (src/SelfUpdate.php)
# and the installers install it.
#
#   games/ide/tools/release.sh ["What is new."]
#
# 1. bump game.yaml `version` first (0.1.3 -> 0.1.4): the IDEs only take a version newer than theirs;
# 2. this script: bin/jumphp app:release (exports Linux, Windows, macOS and signs update.json with
#    ~/.jumphp/keys/ide-update.pem: KEEP A COPY of that key, without it the installed IDEs can never be updated
#    again), the GitHub release ide-v<version> with the files, a check that every address answers, then
#    games/ide/installer/update.json (read by the installed IDEs) and version.json (read by the installers)
#    committed and pushed on main. Nothing is pushed if a step fails.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"
NOTES="${1:-}"
REPO="davidnurdin/jumPhp"
DIR="games/ide"
OUT="$DIR/dist/standalone"
VERSION="$(sed -nE 's/^ *version: *["'"'"']?([0-9][0-9.a-z-]*).*/\1/p' "$DIR/config/game.yaml" | head -1)"
TAG="ide-v$VERSION"
BASE="https://github.com/$REPO/releases/download/$TAG"
FILES=(ide-linux-x86_64 ide-windows-x86_64.exe ide-macos.zip ide-macos-arm64 ide-macos-x86_64 update.json)

say() { printf '\033[1;32m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31merror:\033[0m %s\n' "$*" >&2; exit 1; }

[ -n "$VERSION" ] || die "no version in $DIR/config/game.yaml"
command -v gh >/dev/null || die "gh (GitHub CLI) is needed"
[ "$(git rev-parse --abbrev-ref HEAD)" = main ] || die "release from main"
published="$(sed -nE 's/.*"version": *"([^"]+)".*/\1/p' "$DIR/installer/version.json" 2>/dev/null || true)"
if [ -n "$published" ] && { [ "$published" = "$VERSION" ] || [ "$(printf '%s\n%s\n' "$published" "$VERSION" | sort -V | tail -1)" != "$VERSION" ]; }; then
  [ "${JUMPHP_RELEASE_AGAIN:-}" = 1 ] || die "$VERSION is not newer than the published $published: bump game.yaml version (JUMPHP_RELEASE_AGAIN=1 to send $VERSION again)"
fi

say "JumPHP IDE $VERSION: export + signed update.json"
bin/jumphp install ide
bin/jumphp app:release ide --url "$BASE" --notes "$NOTES" --linux --windows --macos
for f in "${FILES[@]}"; do [ -f "$OUT/$f" ] || die "missing $OUT/$f"; done
grep -q '"signature"' "$OUT/update.json" || die "update.json is not signed"

say "GitHub release $TAG"
if ! gh release view "$TAG" -R "$REPO" >/dev/null 2>&1; then
  gh release create "$TAG" -R "$REPO" --target main --title "JumPHP IDE $VERSION" --notes "${NOTES:-JumPHP IDE $VERSION}"
fi
gh release upload "$TAG" -R "$REPO" --clobber "${FILES[@]/#/$OUT/}"
# the installers of the previous release, carried over (they read version.json: the same ones install any version)
prev="$(gh release list -R "$REPO" --limit 50 --json tagName -q '.[].tagName' | grep '^ide-v' | grep -vx "$TAG" | head -1 || true)"
if [ -n "$prev" ] && ! gh release view "$TAG" -R "$REPO" --json assets -q '.assets[].name' | grep -q '^JumPHP-IDE-setup'; then
  tmp="$(mktemp -d)"
  if gh release download "$prev" -R "$REPO" -D "$tmp" -p 'JumPHP-IDE-setup*' 2>/dev/null; then
    gh release upload "$TAG" -R "$REPO" --clobber "$tmp"/*
  fi
  rm -rf "$tmp"
fi

say "every file answers"
for f in "${FILES[@]}"; do
  code="$(curl -sL -o /dev/null -w '%{http_code}' -r 0-0 "$BASE/$f")"
  [ "$code" = 200 ] || [ "$code" = 206 ] || die "$BASE/$f: HTTP $code (update.json not published)"
done

say "update.json + version.json on main"
cp "$OUT/update.json" "$DIR/installer/update.json"
printf '{"version": "%s"}\n' "$VERSION" > "$DIR/installer/version.json"
git add "$DIR/installer/update.json" "$DIR/installer/version.json" "$DIR/config/update.pub" "$DIR/config/game.yaml"
git commit -m "JumPHP IDE $VERSION: the installed IDEs update themselves to it, the installers install it" -- \
  "$DIR/installer/update.json" "$DIR/installer/version.json" "$DIR/config/update.pub" "$DIR/config/game.yaml"
git push origin main
# the .dmg for macOS, made on one of GitHub's Macs and added to the release a few minutes later (.github/workflows/ide-dmg.yml)
gh workflow run ide-dmg.yml -R "$REPO" -f tag="$TAG" || echo "the .dmg was not started: Actions > ide-dmg > Run workflow (tag $TAG)"
say "done: the installed IDEs take $VERSION within 6 hours (or at their next start); JumPHP-IDE.dmg in a few minutes"
