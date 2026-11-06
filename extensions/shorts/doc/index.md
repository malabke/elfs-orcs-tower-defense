# Shorts

`jumphp/shorts`: vertical videos (TikTok, Shorts, Reels) made from a JumPHP scene: melodies played by collisions, the sound
mixed offline, timed captions; `bin/jumphp video:make` renders the frames and the MP4.

- `Recorder`: `note($time, $freq)` plays a note now and keeps it for the mix while a video is made.
- `Melody`: `Melody::fromString('E4 E4 F4 G4')`, `fromMidi($file)`, `next()` is the next note's frequency.
- `Mixer`: the notes mixed into a WAV of the video's length (done by video:make).
- `Draw`: `ring()` with a gap, `arc()`, `trail()`.
- `Captions`: timed text over the video: `Captions::fromList($params['captions'])`, `$captions->render($r, $font, $time, $w, $h)`.

```bash
bin/jumphp video:make <game> --template rings --seconds 30 --seed 7 --count 3 \
    --caption "Wait for it@2-5@middle@pop" --caption "Follow for more@20-@bottom@box"
```

The whole method, the templates and the IDE's Video Preview: `docs/video-maker.md` of JumPHP.
