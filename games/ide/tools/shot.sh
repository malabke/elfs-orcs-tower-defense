#!/usr/bin/env bash
# A screenshot of the IDE with a project open and a script driving the page (checks of the interface):
#   games/ide/tools/shot.sh <out.png> <frames> <project inside the repo, e.g. /workspace> [javascript]
# Run from the repository root. The script can call window.jumphpIde (the workbench) and jumphp.send('log', ...).
set -euo pipefail
out="$1"; frames="$2"; project="$3"; script="${4:-}"
prelude="window.addEventListener('error', (e) => jumphp.send('log', {error: e.message, at: e.filename + ':' + e.lineno})); window.addEventListener('unhandledrejection', (e) => jumphp.send('log', {rejection: String(e.reason && e.reason.message || e.reason)}));"
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/workspace" -w /workspace/games/ide -e HOME=/tmp -e XDG_RUNTIME_DIR=/tmp \
  -e JUMPHP_TAURI_LIB=/workspace/build/tauri/linux-x86_64/libjumphp_tauri.so -e "JUMPHP_IDE_PROJECT=$project" \
  -e "JUMPHP_SCREENSHOT=$out" -e "JUMPHP_MAX_FRAMES=$frames" -e "JUMPHP_IDE_EVAL=$prelude $script" -e JUMPHP_VAR=/tmp/ide-var \
  jumphp/tauri:dev jumphp-xvfb php app.php 2>&1 | grep -v 'signal 10' || true
