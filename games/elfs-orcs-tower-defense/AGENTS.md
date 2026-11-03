# elfs-orcs-tower-defense — notes for agents

This game is built with JumPHP. **Read `../../AGENTS.md` first** (rules, commands, API), then `BRIEF.md` (what the
game must become, milestone by milestone).

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
itself) and a **side power** with a cooldown (cast where you point), 14 languages. Milestone 4 (balance, site,
thumbnail, publication) is not.

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
- **Fonts** (`App\Ui\FontProvider`): `assets/fonts/PressStart2P-Regular.ttf` (8 px, Latin + Cyrillic, OFL) and
  `assets/fonts/unifont.otf` (16 px, CJK, OFL / GPL with font exception). **Without these files the built-in bitmap
  font is used** (upper-case ASCII: accents dropped, CJK not shown). `MixedFont` draws CJK runs with the fallback.
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
