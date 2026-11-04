# SHORTS (games/shorts-demo)

The example game of the jumphp/physics and jumphp/shorts extensions (docs/video-maker.md): vertical (270 x 480)
"satisfying" physics scenes, each bounce the next note of a melody, rendered to MP4 by `bin/jumphp video:make`.

- `TitleScene`: a menu of the templates. `ShortScene` (params: template growing | rings | race, seed, title, melody):
  the question at the top, a caption, a timer; R restarts, Escape goes back. When a round ends (ball escaped, circle
  filled, a colour won) the next one starts, so a video never runs dry.
- Tests: tests/ShortsTest.php (same seed same bounces, a ball stays inside a closed ring, escapes, notes follow the
  bounces, the mix, the race).
