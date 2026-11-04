# Fonts

- `Nunito.ttf`: Nunito by Vernon Adams, Cyreal and Jacques Le Bailly, SIL Open Font License 1.1 (`Nunito-OFL.txt`).
  Source: https://github.com/google/fonts/tree/main/ofl/nunito (the variable file `Nunito[wght].ttf`). Latin and
  Cyrillic, drawn at 10 px (made bold: SDL_ttf shows the file's default weight, its thinnest).
- `unifont.otf`: GNU Unifont 16.0.04 by Roman Czyborra, Paul Hardy and contributors, dual licensed SIL Open Font
  License 1.1 / GNU GPL 2+ with the font embedding exception. Source: https://unifoundry.com/unifont/.
  Japanese, Chinese, Korean, drawn at 16 px.

`App\Ui\FontProvider` picks them by language; without them the engine's built-in font is used.
