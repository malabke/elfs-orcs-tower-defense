# shellcheck shell=bash
# Desktop apps (Tauri: native windows with HTML / CSS / JS pages, driven by PHP), sourced by bin/jumphp: the app:* and
# tauri:* commands. Nothing here is used by games: SDL games (desktop, browser, standalone, phones) work as before.
# docs/tauri.md.

TAURI_IMAGE="${JUMPHP_TAURI_IMAGE:-jumphp/tauri:dev}"
TAURI_HOME="${JUMPHP_HOME:-$HOME/.jumphp}/tauri"
TAURI_PLATFORM="linux-x86_64"
TAURI_LIB="build/tauri/$TAURI_PLATFORM/libjumphp_tauri.so"

ensure_tauri_image() {
  ensure_desktop_image
  docker image inspect "$TAURI_IMAGE" >/dev/null 2>&1 || cmd_tauri_setup
}

# tauri_run <workdir repo-relative> [docker flags...] -- <command...>: in the Tauri image (PHP + FFI, Rust, WebKitGTK)
tauri_run() {
  local wd="$1"; shift
  local flags=()
  while [ $# -gt 0 ] && [ "$1" != "--" ]; do flags+=("$1"); shift; done
  [ "${1:-}" = "--" ] && shift
  ensure_tauri_image
  mkdir -p "$TAURI_HOME/cargo" "$TAURI_HOME/target"
  docker run --rm $TTY_FLAGS --user "$(id -u):$(id -g)" \
    -v "$ROOT:/workspace" -w "/workspace/$wd" \
    -v "$TAURI_HOME/cargo:/opt/jumphp-cargo" -v "$TAURI_HOME/target:/opt/jumphp-target" \
    -e HOME=/tmp -e XDG_RUNTIME_DIR=/tmp -e COMPOSER_HOME=/tmp/composer -e COMPOSER_CACHE_DIR=/workspace/.cache/composer \
    -e CARGO_HOME=/opt/jumphp-cargo -e CARGO_TARGET_DIR=/opt/jumphp-target \
    -e "JUMPHP_TAURI_LIB=/workspace/$TAURI_LIB" \
    ${flags[@]+"${flags[@]}"} "$TAURI_IMAGE" "$@"
}

cmd_tauri_setup() {
  ensure_desktop_image
  say "Tauri image ($TAURI_IMAGE: the desktop runtime + FFI + Rust + WebKitGTK, first time ~5 min)"
  docker build -t "$TAURI_IMAGE" --build-arg "BASE=$DESKTOP_IMAGE" docker/tauri
}

# the runtime library (tauri/, Rust) -> build/tauri/linux-x86_64/libjumphp_tauri.so; ~5 min the first time, then seconds
cmd_tauri_build() {
  say "Tauri runtime: tauri/ -> $TAURI_LIB"
  local locked=""; [ -f "$ROOT/tauri/Cargo.lock" ] && locked="--locked"
  tauri_run tauri -- sh -c "cargo build --release $locked && mkdir -p /workspace/build/tauri/$TAURI_PLATFORM \
    && cp /opt/jumphp-target/release/libjumphp_tauri.so /workspace/$TAURI_LIB"
  say "done: $TAURI_LIB ($(du -h "$ROOT/$TAURI_LIB" | cut -f1))"
}

# the library, built when missing or older than its sources
ensure_tauri_lib() {
  if [ ! -f "$ROOT/$TAURI_LIB" ] || [ -n "$(find "$ROOT/tauri/src" "$ROOT/tauri/Cargo.toml" "$ROOT/tauri/tauri.conf.json" -newer "$ROOT/$TAURI_LIB" 2>/dev/null | head -1)" ]; then
    cmd_tauri_build
  fi
}

app_dir() {
  local dir; dir="$(game_dir "${1:-}")"
  grep -qE '^[[:space:]]*ui:[[:space:]]*["'"'"']?webview' "$ROOT/$dir/config/game.yaml" 2>/dev/null \
    || die "$dir is not a desktop app (config/game.yaml: ui: webview). A game: bin/jumphp run / test / shot"
  echo "$dir"
}

# docker flags for a window on this screen (X11, else Wayland), the session bus (notifications) and the GPU
app_display_flags() {
  local xdg="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}"
  if [ -n "${DISPLAY:-}" ] && [ "${JUMPHP_WAYLAND:-}" != 1 ]; then
    echo "-e DISPLAY=$DISPLAY -v /tmp/.X11-unix:/tmp/.X11-unix:ro -e GDK_BACKEND=x11"
    if [ -n "${XAUTHORITY:-}" ] && [ -f "$XAUTHORITY" ]; then
      echo "-v $XAUTHORITY:/tmp/.Xauthority:ro -e XAUTHORITY=/tmp/.Xauthority"
    elif [ -f "$HOME/.Xauthority" ]; then
      echo "-v $HOME/.Xauthority:/tmp/.Xauthority:ro -e XAUTHORITY=/tmp/.Xauthority"
    fi
  elif [ -n "${WAYLAND_DISPLAY:-}" ] && [ -S "$xdg/$WAYLAND_DISPLAY" ]; then
    echo "-e WAYLAND_DISPLAY=$WAYLAND_DISPLAY -v $xdg/$WAYLAND_DISPLAY:/tmp/$WAYLAND_DISPLAY -e GDK_BACKEND=wayland"
  else
    die "no display (DISPLAY / WAYLAND_DISPLAY). Headless: bin/jumphp app:test / app:shot"
  fi
  [ -S "$xdg/bus" ] && echo "-v $xdg/bus:/tmp/bus -e DBUS_SESSION_BUS_ADDRESS=unix:path=/tmp/bus"
  if [ -d /dev/dri ]; then
    echo "--device /dev/dri"
    local grp gid
    for grp in video render; do
      gid="$(getent group "$grp" | cut -d: -f3 || true)"
      [ -n "$gid" ] && echo "--group-add $gid"
    done
  fi
  return 0
}

cmd_app_new() {
  local name="${1:-}" title="${2:-}"
  [[ "$name" =~ ^[a-z][a-z0-9-]*$ ]] || die "app name: lower case letters, digits and dashes (e.g. notes)"
  [ -e "games/$name" ] && die "games/$name already exists"
  [ -n "$title" ] || title="$(echo "$name" | tr '-' ' ' | awk '{for (i = 1; i <= NF; i++) $i = toupper(substr($i, 1, 1)) substr($i, 2)} 1')"
  mkdir -p games
  cp -r skeleton-app "games/$name"
  php_run "games/$name" -- php /workspace/tools/new-app.php "$name" "$title"
  say "games/$name created. Next: bin/jumphp install $name && bin/jumphp app:run $name"
}

cmd_app_run() {
  local dir; dir="$(app_dir "${1:-}")"
  ensure_vendor "$dir"
  ensure_tauri_lib
  # shellcheck disable=SC2046
  tauri_run "$dir" --network host $(app_display_flags) -e "JUMPHP_DEBUG=${JUMPHP_DEBUG:-}" -- php app.php
}

cmd_app_test() {
  local dir; dir="$(app_dir "${1:-}")"
  ensure_vendor "$dir"
  live_announce
  say "$dir: php -l"
  php_run "$dir" -- sh -c 'find src app.php -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null'
  if [ -f "$ROOT/$dir/phpstan.neon" ] && [ -x "$ROOT/engine/vendor/bin/phpstan" ]; then
    say "$dir: phpstan"
    php_run "$dir" -- /workspace/engine/vendor/bin/phpstan analyse --no-progress --memory-limit=1G
  fi
  if [ -d "$ROOT/$dir/tests" ] && [ -x "$ROOT/engine/vendor/bin/phpunit" ]; then
    say "$dir: phpunit (AppTestCase: the app without a window)"
    php_run "$dir" -- /workspace/engine/vendor/bin/phpunit --bootstrap vendor/autoload.php --testdox tests
  fi
  ensure_tauri_lib
  say "$dir: the app for real on a virtual screen (${JUMPHP_MAX_FRAMES:-240} frames: the page must load, no PHP warning)"
  tauri_run "$dir" -e JUMPHP_SMOKE=1 -e "JUMPHP_MAX_FRAMES=${JUMPHP_MAX_FRAMES:-240}" -- jumphp-xvfb php app.php
  say "$dir: all checks passed"
}

cmd_app_shot() {
  local dir; dir="$(app_dir "${1:-}")"
  local frames="${2:-120}" out="${3:-var/screenshots/app.png}"
  ensure_vendor "$dir"
  ensure_tauri_lib
  tauri_run "$dir" -e "JUMPHP_SCREENSHOT=$out" -e "JUMPHP_MAX_FRAMES=$frames" -- jumphp-xvfb php app.php
  echo "$ROOT/$dir/$out"
}

# the app as one executable for Linux (StaticPHP's micro with FFI + the app + the Tauri library), through
# export:standalone with the apps' settings (docs/tauri.md). The player needs WebKitGTK 4.1 (as every Tauri app).
cmd_app_export() {
  local app="${1:-}"; local dir; dir="$(app_dir "$app")"; shift || true
  [ $# -eq 0 ] || [ "$*" = "--linux" ] || die "app:export: Linux only for now (--linux)"
  ensure_vendor "$dir"
  ensure_tauri_lib
  JUMPHP_STANDALONE_EXTENSIONS=tools/app-extensions.txt JUMPHP_STANDALONE_SPC=spc-app \
  JUMPHP_STANDALONE_BOOT='JumPHP\Tauri\App' JUMPHP_STANDALONE_NATIVE="$TAURI_LIB" \
  JUMPHP_STANDALONE_INI=$'ffi.enable=1\nmemory_limit=512M' \
    cmd_export_standalone "$app" --linux
  echo "  A desktop app: the computer needs WebKitGTK 4.1 (Debian 12+, Ubuntu 22.04+, Fedora 39+: libwebkit2gtk-4.1)." >&2
}

# run / test / shot of a desktop app's folder: the app:* commands (a game's folder: nothing changes)
is_app() {
  [ -n "${1:-}" ] && grep -qE '^[[:space:]]*ui:[[:space:]]*["'"'"']?webview' "$ROOT/games/$1/config/game.yaml" 2>/dev/null
}
