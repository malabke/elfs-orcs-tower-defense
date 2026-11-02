# elfs-orcs-tower-defense — notes for agents

This game is built with JumPHP. **Read `../../AGENTS.md` first** (rules, commands, API).

## Checklist

```bash
bin/jumphp test elfs-orcs-tower-defense                 # lint + phpstan + headless smoke test (must pass)
bin/jumphp shot elfs-orcs-tower-defense 60              # title screen -> games/elfs-orcs-tower-defense/var/screenshots/shot.png
JUMPHP_INPUT='[[5,"confirm"]]' bin/jumphp shot elfs-orcs-tower-defense 240 var/screenshots/play.png
bin/jumphp build elfs-orcs-tower-defense && bin/jumphp webshot elfs-orcs-tower-defense 6 '[[1,"Enter"]]'   # browser (needs bin/jumphp engine:build)
```

## This game

Started from the JumPHP skeleton (a small platformer). Update this section as the game grows:

- **What it is**: ...
- **Scenes**: `TitleScene` (menu) → `PlayScene` (level) ; overlays `PauseScene` ; `OptionsScene` (pushed).
- **Entities**: `Player` (Sprite + Gravity + TileCollision), `Coin`.
- **Controls** (`config/input.yaml`): arrows / WASD move, Space jumps, Escape pauses.
- **Smoke test** (`config/game.yaml` smoke): `PlayScene` with `demo: true` (a bot runs and jumps).
- **Non-obvious**: the tileset is drawn with GD in `PlayScene::makeTileset()`; replace it with a PNG
  (`assets/tiles/`) or a Tiled map (`TiledJsonLoader`).
