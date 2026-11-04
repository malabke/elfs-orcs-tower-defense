# shellcheck shell=bash
# Engine extensions (Composer packages of type jumphp-extension, docs/extensions.md). Sourced by bin/jumphp:
# extension:new. The package lives in extensions/<id>, its example game in games/<id>-demo (a game like the others:
# install / test / shot / build / webshot); the example requires the package through a path repository.

cmd_extension_new() {
  local name="${1:-}" title="${2:-}"
  [[ "$name" =~ ^[a-z][a-z0-9-]*$ ]] || die "extension name: lower case letters, digits and dashes (e.g. star-field)"
  [ -e "extensions/$name" ] && die "extensions/$name already exists"
  [ -z "$(project_path "$name-demo")" ] || die "$(project_path "$name-demo") already exists"
  [ -n "$title" ] || title="$(echo "$name" | tr '-' ' ' | awk '{for (i = 1; i <= NF; i++) $i = toupper(substr($i, 1, 1)) substr($i, 2)} 1')"
  mkdir -p extensions games
  cp -r skeleton-extension/package "extensions/$name"
  cp -r skeleton-extension/example "games/$name-demo"
  cp skeleton/app.php skeleton/phpstan.neon "games/$name-demo/" 2>/dev/null || cp skeleton/app.php "games/$name-demo/"
  php_run . -- php /workspace/tools/new-extension.php "$name" "$title"
  say "extensions/$name (the package) and games/$name-demo (its example game) created."
  say "Next: bin/jumphp install $name-demo && bin/jumphp test $name-demo (docs/extensions.md)"
}
