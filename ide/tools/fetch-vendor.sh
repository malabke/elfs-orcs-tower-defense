#!/usr/bin/env bash
# The interface's third-party files, into ui/vendor/ (not in git): Monaco, the editor of VS Code (MIT).
# Run once (bin/jumphp ide:vendor does it; the desktop app's commands and the playground's install call it).
set -euo pipefail
cd "$(dirname "$0")/.."
MONACO_VERSION="0.52.2"
out="ui/vendor/monaco"
if [ -f "$out/VERSION" ] && [ "$(cat "$out/VERSION")" = "$MONACO_VERSION" ]; then
  exit 0
fi
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
echo "monaco-editor $MONACO_VERSION -> $out" >&2
curl -fsSL -A jumphp-ide -o "$tmp/monaco.tgz" "https://registry.npmjs.org/monaco-editor/-/monaco-editor-$MONACO_VERSION.tgz"
tar -xzf "$tmp/monaco.tgz" -C "$tmp"
rm -rf "$out"
mkdir -p "$out"
cp -r "$tmp/package/min/vs" "$out/vs"
cp "$tmp/package/LICENSE" "$out/LICENSE"
# languages the IDE never shows: smaller package
for lang in abap apex azcli bat bicep cameligo clojure coffee csp cypher dart ecl elixir flow9 fsharp freemarker2 hcl \
  julia kotlin lexon liquid m3 mips msdax mysql objective-c pascal pascaligo pla postiats powerquery qsharp r razor redis \
  redshift restructuredtext ruby sb scala scheme sophia sparql st swift systemverilog tcl twig typespec vb wgsl; do
  rm -rf "$out/vs/basic-languages/$lang"
done
echo "$MONACO_VERSION" > "$out/VERSION"
du -sh "$out" >&2
