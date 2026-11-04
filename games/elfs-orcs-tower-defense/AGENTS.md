# elfs-orcs-tower-defense — notes for agents

This game is built with JumPHP. **Read `../../AGENTS.md` first** (rules, commands, API), then `BRIEF.md` (what the
game must become, milestone by milestone), then **`SUIVI.md`** (where the work stands, the user's decisions, what comes
next: in French; keep it up to date after each step, the user works from several computers).

## Checklist

```bash
bin/jumphp test elfs-orcs-tower-defense                 # lint + phpstan + phpunit (unit + game tests) + headless smoke test
bin/jumphp shot elfs-orcs-tower-defense 60              # title screen -> games/elfs-orcs-tower-defense/var/screenshots/shot.png
JUMPHP_INPUT='[[5,"confirm"]]' bin/jumphp shot elfs-orcs-tower-defense 240 var/screenshots/play.png
JUMPHP_SCENE='App\Scene\PlayScene' JUMPHP_SCENE_PARAMS='{"demo":true}' bin/jumphp shot elfs-orcs-tower-defense 900 var/screenshots/demo.png
# other languages: tests/LanguagesTest.php photographs fr, de, ru, ja -> var/screenshots/tests/lang-<code>-*.png
bin/jumphp build elfs-orcs-tower-defense && bin/jumphp webshot elfs-orcs-tower-defense 6 '[[1,"Enter"]]'
```

## This game

A retro tower defense in high fantasy (no licensed names: see BRIEF.md §2). **Milestones 1-3** are done: the **5
sides** (elves, dwarves, horsefolk, little folk: against the Horde; orcs: against the Alliance of the free peoples),
10 waves per attacking side, 4 towers per side with 3 levels and 2 specializations each, a **campaign of 6 maps**
(each opens when the one before is won, per side; stars saved per side and map), **hard mode** (opens once a map is
won: enemies' health x1.5, gold x0.85), a **hero** per side (sent anywhere on the road, fights, skill fires by
itself) and a **side power** with a cooldown (cast where you point), 14 languages. **Milestone 4** is done except
publishing (only when the user asks): the bot wins all 30 map x side combinations (tests/CampaignTest.php), hard mode
stays winnable, `site/` (the game's home page) and `assets/thumbnail.png` / `assets/icon.png` exist, the remote build
(`bin/jumphp build:remote`) was checked in Chrome.

**The battle is drawn in 3D, painted like a storybook** (the user wants neither pixel art nor a crude low-poly look:
soft painted ground, lush round trees, warm light, cool shadows): a WebGL layer under the 2D scene (`$engine->gl()`, layer
'below', 'native' resolution, key colour magenta). Pure classes in `src/View3d/` (excluded from the container, unit-tested
by `tests/ViewTest.php`): `Mesh` (boxes, cylinders, balls with colour gradients and lumps, triangles, quads -> one buffer;
10 floats per vertex, the last one the material), `Units` (every creature, round "picture book" characters, 4 walking
frames), `Towers` (20 towers by level / specialization, front towards +z), `Props` (shots, walls, traps, disc / ring /
ball / soft shadow), `MapModel` (the land of a map: terrain with hills outside the board, the forest around it, trees,
bushes, grass tufts, flowers, rocks, floating specks of light, platforms, the castle; one mesh per map and side),
`GroundMap` (a 512 x 256 RGBA texture per map: road, water, sun / shadows, sky light), `Rig` (the camera: fits every
platform under the top bar, `screen()` / `world()` project both ways), `FlatView` (the 2D fallback's projection).
`src/Ui/World3d.php` holds the GL side: ONE shader for everything, reading the vertex material (`Mesh::SOLID`, leaves /
grass in (0, 1] = how much they sway in the wind, `GROUND` painted from the GroundMap and `assets/textures/noise.png`
(made by `tools/make-noise.php`: patches, grain, clouds), `MOTE` + seed: specks that drift, `SOFT`: shadows fading at
their edge); warm wrapped sun from the west (`MapModel::SUN`), cool sky, drifting cloud shadows, haze far away, a light
vignette; a model cache, one draw call per object. Static shadows (trees, rocks, castle) are baked into the GroundMap;
towers and creatures get soft blob shadows (`World3d::shadow()`, drawn first). `PlayScene` reads and places its 2D
overlays (ring menu, bars, numbers, HUD) through `$this->cam`; `render3d()` draws the world, `render()` keeps the flat 2D
drawing when WebGL is missing or the param `view: flat` is given (the long logic tests use it: Mesa's software GL is slow).
To look at the models: `tests/ModelShotsTest.php` builds every tower of each side at level 1, 3 and specialized
(`var/screenshots/tests/towers-<side>.png`; crop them with GD to look closer).
The 2D is HD (game.yaml `hd: auto`: drawn at the window's resolution, coordinates unchanged) and the 3D layer is composed
by the 2D's alpha (`setLayer('below', 'view', null)`): scenes clear the 2D with `$world->clearColour()` (transparent),
then any colour, translucent or not, and smooth text lie over the 3D as they are.
**The playground does not run this repository's engine** but its shared one (the official release of `VERSION`): the
engine changes of this project (HD, alpha composing, TtfFont's style / thicken / outline) are not there until JumPHP
releases them. `App\Ui\Hd` detects what the engine can do; without it the game keeps the retro way: the pixel font
(`FontProvider::PIXEL_FONT`), the 3D through the key colour `World3d::KEY` (`Paint::$keyed`: only black may be
translucent over it, `World3d::veil()`). `tests/LegacyEngineTest.php` plays that way (`Hd::$legacy`); check the real
thing with `bin/jumphp build:remote <id>` then `JUMPHP_WEB=remote bin/jumphp webshot <id>` (build/runtime: the shared
engine as released). Never call an engine method this project added without going through `Hd`.
Things to remember: `crc32()` is signed and products overflow in the browser's 32-bit PHP
(mask to 16 bits: `Mesh::hash()`); the 16-bit depth buffer needs a far near plane (`Rig::NEAR`); the browser builds the
map mesh too (PHP has 128 MB there: keep a map under 100k vertices, ViewTest checks it); no uniform may be declared in both
shaders (their precisions differ in WebGL: link error); offsets that grow with time (clouds, water) are wrapped in PHP
(`fmod`) before they reach the shader; a gable roof's halves turn by `+side * pitch` about x (each falls away from the
ridge); `bin/jumphp test <game> --filter X` runs only phpunit (with OpenGL on Xvfb when the game uses it).
Engine fix made for this game (engine/src/Gl/OpenGl.php `textureFromRgba`): desktop GL kept SDL's `GL_UNPACK_ROW_LENGTH`,
so a texture made after SDL had updated one of its own was read with wrong rows (the second battle of a session had a
scrambled ground); it is reset to 0 around the upload now.
The title screen is 3D too (`TitleScene::render3d()`: the first map, elf towers, the horde walking the road, a camera
drifting with `Rig::orbit()`; flat 2D without WebGL or with `view: flat`).
The interface's look (`App\Ui\Paint`, storybook like the 3D): rounded panels of ink blue edged with gold, wooden buttons
and header bars, round medallions, an evening-sky backdrop, and icons drawn with shapes (heart, coin, flag, skull, stars,
lock, pause / play, up, target, sell): sharp at any size in HD. The side and map screens show pictures of the 3D game
(`assets/sprites/cards/side-<side>.png`, `map-<map>.png`, drawn smoothed by `Sprites::drawPicture()`), cut by
`tools/site/make.php` from the test frames (ModelShotsTest writes where the towers stand: `towers-<side>.json`).
**Portraits** (the ring menu's towers, the hero's button, the next wave's preview, the creatures under the side cards):
pictures of the 3D models, `assets/sprites/portraits/unit-<sprite>.png` / `tower-<sprite>.png` (128 px, transparent),
drawn smoothed by `Sprites::drawPortrait()` (false when missing: the old pixel drawing is used). They are made by
`tests/PortraitShotsTest.php`: `PortraitScene` (a photo studio, not a screen of the game) draws one model alone
through `World3d::studio()` (no land, plain background), over black then white; the difference gives the alpha. Re-run
it after changing a model (`bin/jumphp test <id> --filter PortraitShotsTest`): it rewrites the PNGs. The 2D fallback
battle (no WebGL) still draws the pixel sprites of `Sprites`.

Site pictures: `tests/SiteShotsTest.php` photographs the game (var/screenshots/tests/site-*.png), then
`bin/jumphp php games/elfs-orcs-tower-defense tools/site/make.php` cuts them into site/images/, the thumbnail and the
icon. Re-run both after any change of look, then `bin/jumphp build:site <id>` and `bin/jumphp siteshot <id>`.

Maps 2-6 take thornvale's waves (`like:` in waves.yaml) scaled and with groups added; their scenery is scattered
from `scenery:` shares with a colour `theme`. Hero / power controls: round buttons bottom left of the map (tap, then
tap the road / the target), H / pad LB = hero, Q / pad RB = power, arrows move the reticle, confirm sends, back cancels.

Design choices made without the user's answer (asked, no reply; easy to change): soldiers of barracks / stables
stand on the road point nearest to their slot and stop one ground enemy each; palisades and traps go on that road
point too; hidden scouts can only be shot within a detecting tower's range (the orcs' war drums), soldiers still stop
them; all 5 sides are open from the start.

Tower kinds (towers.yaml `kind`): attack, barracks (`soldiers`), barrier (`barrier`), trap, aura (`aura`), income
(`income`); flags `pulse`, `ambush`, `spread`, `detect`. Enemy traits (enemies.yaml): `attack`, `dodge`, `stealth`,
`tunnel`, `heal`, `scale`. `tools/balance.php` (`bin/jumphp php games/elfs-orcs-tower-defense tools/balance.php`) makes
the bot play every side and prints lives / gold per wave and the enemies that got through: balance with it.

- **Layout** (480 x 270): top bar 30 px (lives, gold, wave, next wave preview, CALL WAVE, speed, pause), the map
  480 x 240 under it (30 x 15 tiles of 16 px, `PlayScene::MAP_Y`).
- **Scenes**: `TitleScene` → `FactionSelectScene` (5 cards; tap / arrows pick, a second tap / confirm plays) →
  `MapSelectScene` (6 maps, locks, stars, hard mode switch) → `PlayScene` (map, faction, hard) ; overlays `PauseScene` (also `game.yaml pause_scene`),
  `VictoryScene` (stars), `DefeatScene`, `OptionsScene` (sound, language, fullscreen). All menus extend
  `App\Ui\GameMenuScene` (MenuScene + mouse / touch rows of 22 px + the language's font).
- **Logic** (`src/Td/`, pure PHP, no SDL, excluded from the container, unit-tested): `Battle` (economy, lives, waves,
  towers, enemies, projectiles, soldiers, walls, traps, auras, income; `step()` at 60 Hz; what happened goes to
  `$events`), `Enemy` (path distance, slow / stun / burn / fear / shred, blocked, hidden, underground), `Soldier`,
  `Tower` (levels, specialization, targeting first / last / strongest / closest, its road object),
  `Projectile`, `Path` (waypoints), `GameData` (the YAML), `Bot` (builds and upgrades by itself).
- **Data** (`assets/data/*.yaml`): towers, enemies, waves (per map and attacking side), maps (scenery rows, paths in
  tile corners, slots, bastion), factions (towers, colours, bot list), damage table. Balancing = these files.
- **Pictures** (`src/Ui/Sprites.php`, `src/Ui/TowerArt.php` for the 16 towers of the other sides): towers drawn with
  GD per level / specialization, enemies as pixel strings (2 frames, templates recoloured per people), the map
  painted once into a 480 x 240 texture per side (the castle in its colours). A PNG in `assets/sprites/` with the documented name
  replaces any generated picture. **Sounds**: `src/Ui/Sfx.php` (Synth; `assets/sfx/<name>.wav` replaces one).
- **Text**: every string goes through `Lang::t()`; `config/lang.yaml` has the 13 other languages;
  `tests/TranslationsTest.php` fails on a missing or unused key. `App\Ui\Text` wraps / fits text to a width.
- **Fonts** (`App\Ui\FontProvider`): `assets/fonts/Nunito.ttf` (Google Fonts' variable file, OFL: `Nunito-OFL.txt`;
  10 px, Latin + Cyrillic; its default instance is the thinnest weight, so it is made bold and thickened, its shadow drawn
  as an outline) when the engine has HD, else `PressStart2P-Regular.ttf` (8 px, pixel); `assets/fonts/unifont.otf`
  (16 px, CJK, OFL / GPL with font exception). **Without these files the built-in bitmap font is used** (upper-case
  ASCII: accents dropped, CJK not shown). `MixedFont` draws CJK runs with the fallback (its line metrics are the main
  font's).
- **Controls** (`config/input.yaml`): mouse / touch: click a stone platform → a ring of 4 choices (first click shows
  it, second does it); keys / pad: arrows jump between platforms, confirm opens the ring, arrows pick a choice,
  confirm again does it, back closes; N / pad Y calls the next wave (gold bonus), F / Tab / pad X speed x1-x3,
  Esc / P / Start pause.
- **Saves**: `App\Save\Progress` → `var/progress.json` (best stars per side and map).
- **Smoke test**: `PlayScene` with `demo: true` (the bot plays at x3 and calls the waves).
- **Test params of PlayScene**: `demo`, `speed`, `gold`, `lives`, `countdown` (seconds before the first wave),
  `wave` (waves already done), `seed`.
- **Non-obvious**: the scene steps the battle a fixed 1/60 s, `speed` steps per frame (never a variable step).
  Random rolls come from `Battle::roll()` (seeded, 31-bit safe for the browser).
