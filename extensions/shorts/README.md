# jumphp/shorts

Vertical videos (TikTok, YouTube Shorts, Reels) made from a JumPHP scene: each collision plays the next note of a
melody, the scene is rendered headless frame by frame at 60 FPS, the sound mixed offline from the notes it played, and
`bin/jumphp video:make <game>` writes the MP4 (H.264 + AAC, 1080x1920) and a cover. docs/video-maker.md.

- `Recorder` (a service): `reset()` in `enter()`, `note($time, $freq)`: heard at once, kept for the video's sound.
- `Melody`: `Melody::fromString('C4 E4 G4 C5')`, `Melody::fromMidi('assets/music/tune.mid')`, `next()`.
- `Mixer`: the offline mix (bin/jumphp video:make calls it).
- `Draw`: `ring()` (with a gap), `arc()`, `trail()`.

project.yaml `video: {scene: App\Scene\ShortScene, params: {template: rings}, seconds: 30, scale: 4, title: '...'}`.
Physics: jumphp/physics. Example: games/shorts-demo.
