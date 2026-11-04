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

# extension:add <game> <vendor/package>: composer require in the game (one of extensions/ of this repository: through
# a path repository), then what the extension adds (its composer.json extra.jumphp)
cmd_extension_add() {
  local game="${1:-}" pkg="${2:-}" dir local_ext="" f
  [ -n "$game" ] && [[ "$pkg" =~ ^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$ ]] || die "usage: bin/jumphp extension:add <game> <vendor/package>"
  dir="$(game_dir "$game")"
  for f in "$ROOT"/extensions/*/composer.json; do
    [ -f "$f" ] && grep -qE "\"name\"[[:space:]]*:[[:space:]]*\"$pkg\"" "$f" && { local_ext="$(basename "$(dirname "$f")")"; break; }
  done
  if [ -n "$local_ext" ]; then
    say "$pkg: extensions/$local_ext of this repository (a path repository)"
    php_run "$dir" -- composer config "repositories.$local_ext" "{\"type\": \"path\", \"url\": \"../../extensions/$local_ext\"}" \
      || die "composer config failed"
    php_run "$dir" -- composer require "$pkg:@dev" || die "composer require $pkg failed"
  else
    php_run "$dir" -- composer require "$pkg" || die "composer require $pkg failed (on Packagist? docs/extensions.md)"
  fi
  php_run "$dir" -- php -r '
    $c = json_decode((string)@file_get_contents("vendor/" . $argv[1] . "/composer.json"), true) ?: [];
    if (($c["type"] ?? "") !== "jumphp-extension") { fwrite(STDERR, "[!] " . $argv[1] . " is not an engine extension (type jumphp-extension): the engine will not load it by itself\n"); exit(0); }
    $x = (array)($c["extra"]["jumphp"] ?? []);
    echo ($x["name"] ?? $argv[1]) . ": " . ($c["description"] ?? "") . "\n";
    $list = fn (string $what, $v) => $v ? "  $what: " . implode(", ", array_map("strval", array_is_list((array)$v) ? (array)$v : array_keys((array)$v))) . "\n" : "";
    echo $list("services", $x["services"] ?? []), $list("services file", isset($x["services_file"]) ? [$x["services_file"]] : []),
        $list("boot", isset($x["boot"]) ? [$x["boot"]] : []), $list("asset loaders", $x["loaders"] ?? []), $list("project.yaml keys", $x["config"] ?? []);
  ' "$pkg"
  say "added to $dir: the engine finds it at boot (docs/extensions.md)"
}
