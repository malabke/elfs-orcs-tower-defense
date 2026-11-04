# shellcheck shell=bash
# bin/jumphp video:make: vertical videos (TikTok, Shorts) of a scene, rendered headless frame by frame at 60 FPS, the
# sound mixed offline from the notes it played (jumphp/shorts Recorder -> notes.json -> Mixer), then ffmpeg in Docker:
# games/<game>/dist/video/<id>-<template>-<seed>.mp4 (H.264 + AAC) + .png (the cover). docs/video-maker.md.

cmd_video_make() {
  local game="${1:-}"; local dir; dir="$(game_dir "$game")"; shift || true
  local seed="" count=1 seconds="" template="" title="" keep=0
  while [ $# -gt 0 ]; do
    case "$1" in
      --seed) seed="$2"; shift ;;
      --count) count="$2"; shift ;;
      --seconds) seconds="$2"; shift ;;
      --template) template="$2"; shift ;;
      --title) title="$2"; shift ;;
      --keep-frames) keep=1 ;;
      *) die "unknown option $1 (bin/jumphp video:make <game> [--seed N] [--count N] [--seconds S] [--template T] [--title T])" ;;
    esac
    shift
  done
  [[ "$count" =~ ^[0-9]+$ ]] && [ "$count" -ge 1 ] || die "--count: a number"
  [ -z "$seed" ] && seed=$(( (RANDOM % 9000) + 1 ))
  [[ "$seed" =~ ^[0-9]+$ ]] || die "--seed: a number"
  ensure_vendor "$dir"
  docker image inspect jumphp/ffmpeg:dev >/dev/null 2>&1 || docker build -q -t jumphp/ffmpeg:dev "$ROOT/docker/ffmpeg" >/dev/null
  mkdir -p "$ROOT/$dir/dist/video"
  local i
  for ((i = 0; i < count; i++)); do
    video_make_one "$dir" "$((seed + i))" "$seconds" "$template" "$title" "$keep" || return 1
  done
}

# one video (view width x height x project.yaml video.scale: 270x480 x 4 = 1080x1920): $1 game folder, $2 seed, $3 seconds ("" = project.yaml), $4 template, $5 title, $6 keep the frames
video_make_one() {
  local dir="$1" seed="$2" seconds="$3" template="$4" title="$5" keep="$6" plan
  # project.yaml video: {scene, params, seconds, scale, title} + the options -> scene \t params \t seconds \t scale \t name
  plan="$(php_run "$dir" -- php -r '
    require "vendor/autoload.php";
    $c = JumPHP\Kernel\GameConfig::load(".");
    $v = (array)$c->get("video", []);
    $scene = (string)($v["scene"] ?? "") ?: (string)$c->get("start_scene");
    $params = (array)($v["params"] ?? []);
    $params["seed"] = (int)$argv[1];
    if ($argv[3] !== "") { $params["template"] = $argv[3]; }
    $title = $argv[4] !== "" ? $argv[4] : (string)($v["title"] ?? "");
    if ($title !== "") { $params["title"] = $title; }
    $seconds = $argv[2] !== "" ? (float)$argv[2] : (float)($v["seconds"] ?? 30);
    $params["seconds"] = $seconds;
    $name = $c->get("id") . (isset($params["template"]) ? "-" . $params["template"] : "") . "-" . $params["seed"];
    $size = (int)$c->get("width") * (int)($v["scale"] ?? 4) . "x" . (int)$c->get("height") * (int)($v["scale"] ?? 4);
    echo $scene, "\t", json_encode($params), "\t", $seconds, "\t", $size, "\t", preg_replace("/[^a-z0-9-]/", "-", strtolower($name));
  ' "$seed" "$seconds" "$template" "$title")" || die "cannot read the video settings of $dir (project.yaml video:)"
  local scene params secs size name
  IFS=$'\t' read -r scene params secs size name <<<"$plan"
  [ -n "$scene" ] || die "no scene to film: project.yaml video: {scene: App\\Scene\\...}"
  local frames run="var/video/$name"
  frames="$(awk -v s="$secs" 'BEGIN { printf "%d", s * 60 + 0.5 }')"
  rm -rf "${ROOT:?}/$dir/$run" && mkdir -p "$ROOT/$dir/$run/frames"
  say "$name: $frames frames of $scene ($secs s at 60 FPS)"
  php_run "$dir" -e "JUMPHP_SCREENSHOT=$run/frames/f.png" -e JUMPHP_SHOT_EVERY=1 -e "JUMPHP_MAX_FRAMES=$frames" \
    -e "JUMPHP_SCENE=$scene" -e "JUMPHP_SCENE_PARAMS=$params" -e "JUMPHP_VIDEO_OUT=$run" -e JUMPHP_GL=0 -- php app.php \
    || die "$name: the scene stopped (see above)"
  rm -f "$ROOT/$dir/$run/frames/f.png"
  say "$name: the sound (the notes it played, mixed)"
  php_run "$dir" -- php -r 'require "vendor/autoload.php"; JumPHP\Shorts\Mixer::mixFile($argv[1], (float)$argv[2], $argv[3]);' \
    "$run/notes.json" "$secs" "$run/audio.wav" || die "$name: the sound could not be mixed (does the game require jumphp/shorts?)"
  say "$name: the MP4 (H.264 + AAC, $size)"
  local out="dist/video/$name"
  docker run --rm -u "$(id -u):$(id -g)" -v "$ROOT/$dir:/g" -w /g jumphp/ffmpeg:dev -hide_banner -loglevel error -y \
    -framerate 60 -start_number 1 -i "$run/frames/f-%05d.png" -i "$run/audio.wav" \
    -vf "scale=${size/x/:}:flags=neighbor,format=yuv420p" -c:v libx264 -preset medium -crf 18 -r 60 \
    -c:a aac -b:a 160k -shortest -movflags +faststart "$out.mp4" || die "$name: ffmpeg failed"
  docker run --rm -u "$(id -u):$(id -g)" -v "$ROOT/$dir:/g" -w /g jumphp/ffmpeg:dev -hide_banner -loglevel error -y \
    -ss 2 -i "$out.mp4" -frames:v 1 "$out.png" || warn "$name: no cover"
  [ "$keep" = 1 ] || rm -rf "${ROOT:?}/$dir/$run/frames"
  say "done: $dir/$out.mp4 (+ .png, the cover)"
}
