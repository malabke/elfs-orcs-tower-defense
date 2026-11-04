# Video maker: vertical "satisfying" videos (TikTok, Shorts, Reels)

A scene of a JumPHP game, rendered headless frame by frame (never in real time) at 60 FPS, each collision playing the
next note of a melody, the sound mixed offline from the notes it played, then an MP4 ready to post:

```bash
bin/jumphp install shorts-demo
bin/jumphp video:make shorts-demo --template rings --seconds 30 --seed 7 --count 3
# games/shorts-demo/dist/video/shorts-demo-rings-7.mp4 (+ -8, -9) and a .png cover each
```

- 1080 x 1920, 60 FPS, H.264 + AAC (ffmpeg in Docker, the image of `bin/jumphp video`), the view (270 x 480) scaled
  x4 without smoothing: crisp pixels.
- `--seed N`: the same seed makes the same video again (fixed-step physics, seeded); `--count 3`: seeds N, N+1, N+2.
- `--seconds`, `--template` (the scene's `template` param), `--title` (the question at the top), `--keep-frames`.

## How it works

1. project.yaml `video: {scene, params, seconds, scale, title}` says which scene; video:make starts it with its params
   + `seed`, `seconds`, `template`, `title`, `JUMPHP_SHOT_EVERY=1` (one PNG a frame) and `JUMPHP_VIDEO_OUT`.
2. The scene plays its notes through `JumPHP\Shorts\Recorder::note($time, $freq)`: heard when played normally, written
   to `notes.json` while filmed.
3. `JumPHP\Shorts\Mixer` mixes the notes (plucked tones, soft limit) into a WAV of the video's length.
4. ffmpeg joins frames and sound; a cover is taken at 2 s.

## The extensions

- `extensions/physics` (`jumphp/physics`, `JumPHP\Physics2d\World`): balls inside rings and arcs (`spin`, `gap`,
  `grow`, `growOnHit`, `openOnHit`), pegs, walls, balls on balls; gravity, restitution, `hits()` / `on()` events
  (`ring`, `peg`, `wall`, `ball`, `escape`), fixed step (`hz`), seeded, 32-bit safe.
- `extensions/shorts` (`jumphp/shorts`): `Recorder`, `Melody` (`fromString('C4 E4 G4')`, `fromMidi($file)`), `Mixer`,
  `Draw` (`ring()` with a gap, `arc()`, `trail()`).
- `extensions/particles`: the sparks of each bounce.

## The templates of games/shorts-demo

| template | the video |
|---|---|
| `growing` | "How big can it get?": a ball grows at each bounce inside a circle |
| `rings` | "Will it escape?": seven turning rings with gaps, each one breaks when the ball gets out |
| `race` | "Which colour wins?": four balls fall through pegs, the first one down wins, round after round |

Make your own: a scene that builds a `World`, calls `$recorder->note()` on its hits, draws a question at the top and a
timer; point project.yaml `video.scene` at it. The melody: a public-domain tune or your own notes (`melody` param).
