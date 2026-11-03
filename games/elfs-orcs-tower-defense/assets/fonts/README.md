# Fonts

- `PressStart2P-Regular.ttf`: Press Start 2P by CodeMan38, SIL Open Font License 1.1 (`OFL-PressStart2P.txt`).
  Source: https://github.com/google/fonts/tree/main/ofl/pressstart2p. Latin and Cyrillic, drawn at 8 px.
- `unifont.otf`: GNU Unifont 16.0.04 by Roman Czyborra, Paul Hardy and contributors, dual licensed SIL Open Font
  License 1.1 / GNU GPL 2+ with the font embedding exception. Source: https://unifoundry.com/unifont/.
  Japanese, Chinese, Korean, drawn at 16 px.

`App\Ui\FontProvider` picks them by language; without them the engine's built-in font is used.
