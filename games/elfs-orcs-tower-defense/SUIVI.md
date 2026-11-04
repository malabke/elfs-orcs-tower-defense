# Suivi du projet — Elfs & Orcs Tower Defense

Fichier de passation entre sessions et entre ordinateurs (il voyage avec git). **L'agent le relit au début de
chaque session, quand l'utilisateur le demande, et le met à jour après chaque étape** (état, décisions, suite).
Il complète `BRIEF.md` (la demande) et `AGENTS.md` (les notes techniques du jeu). Langue : français.

Dernière mise à jour : 2026-11-04.

## Où en est le jeu

- **Jalons 1 à 4 faits** (règles validées par l'utilisateur : « c'est nickel ») : 5 peuples, campagne de 6 cartes,
  mode difficile, héros, pouvoirs, 14 langues, équilibrage (le bot gagne les 30 combinaisons carte × peuple),
  site du jeu (`site/`), vignette et icône.
- **Graphismes** : l'utilisateur ne veut pas de pixel art. Après une 3D low-poly jugée « moche » puis un style BD
  jugé « grossier », il a demandé le style des grands films d'animation japonais (un **livre d'images peint**) :
  c'est fait pour le combat et l'écran titre (sol peint, forêt, vent, ombres de nuages, lumière dorée, 20 tours et
  toutes les créatures redessinées, château de conte). Ne jamais écrire de nom de studio ou de film dans le dépôt.
- **Interface en haute définition** (choix « 1 » de l'utilisateur, le 2026-11-04) : la 2D est dessinée à la
  résolution de la fenêtre (`game.yaml hd: auto`), police douce Nunito (contour sombre), interface peinte
  (`src/Ui/Paint.php` : panneaux encre bleue bordés d'or, boutons et barres en bois, médaillons, icônes vectorielles),
  écrans de choix avec de vraies images de la 3D (`assets/sprites/cards/`). La 3D est composée par la transparence
  de la 2D (plus de couleur clé magenta).
- **Publication** : le dépôt GitHub `malabke/elfs-orcs-tower-defense` est celui du playground : **pousser sur `main`
  publie le jeu**. Premier push le 2026-11-04 (« pousse tout »). Le jeu n'est pas encore listé dans l'annuaire du
  playground (`update_project listed=true` : seulement sur demande). Ne pousser qu'avec l'accord de l'utilisateur ;
  pour un état de travail sans publier : une autre branche (ex. `travail`). Sur un autre PC : `git pull`.

## En cours / à faire

1. Interface HD faite et vérifiée (tests du jeu 161 + test de fumée, moteur 74 + phpstan, version navigateur dans
   Chrome) ; poussée sur `main` le 2026-11-04 à la demande de l'utilisateur (« pousse tout ») : le playground la
   publie.
2. Encore en pixel art : les créatures des cartes de peuple, les icônes de tours du menu en anneau, le portrait du
   héros et l'aperçu de la vague dans la barre du haut.
3. Idées proposées, pas encore demandées : vignettes 3D vivantes dans les menus, animations d'attaque, particules.
4. Conseiller à l'utilisateur de **déplacer le projet hors de `%TEMP%`** (voir les pièges).

## Décisions de l'utilisateur (à respecter)

- Commits réguliers ; publier seulement sur demande.
- Pas de pixel art à l'ancienne ; style peint « livre d'images » ; pas de contours noirs façon BD.
- Choix de conception faits sans réponse (modifiables) : voir `AGENTS.md` (« Design choices »).

## Pièges de cet environnement (Windows)

- Projet dans `C:\Users\NXTC\AppData\Local\Temp\...` : Windows y efface les fichiers à date ancienne → des fichiers
  de `vendor/` disparaissent (3 fois). Remède : `rm -rf` du `vendor` touché puis `bin/jumphp test engine` (moteur)
  ou `bin/jumphp install elfs-orcs-tower-defense` (jeu).
- Git Bash (pas PowerShell). `bin/jumphp` a des correctifs MSYS (chemins Docker) ; fichiers en LF dans l'arbre de
  travail (`core.autocrlf=true` : ne pas committer les fichiers modifiés seulement par les fins de ligne).
- Les tests 3D tournent sur Mesa (lent) : lancer `bin/jumphp test ...` en tâche de fond. Les demandes d'autorisation
  (git, curl) expirent si l'utilisateur n'est pas là.

## Où regarder

- `AGENTS.md` du jeu : architecture 3D (`src/View3d/`, `src/Ui/World3d.php`), interface (`Paint`), pièges techniques.
- Tests utiles : `tests/ModelShotsTest.php` (toutes les tours de chaque peuple en image), `tests/SiteShotsTest.php`
  (images du site), `tests/ThreeDTest.php` (clics en 3D). Images : `var/screenshots/tests/`.
- `tools/site/make.php` : découpe les images du site et des cartes de menu dans les captures des tests.

## Journal

- 2026-11-02 / 03 : jalons 1 à 4, site, équilibrage ; passage à la 3D WebGL.
- 2026-11-04 : style peint « livre d'images » (commits `9edac44`, `8ff2691`) ; correctif moteur des textures
  (`GL_UNPACK_ROW_LENGTH`) ; interface HD avec Nunito et `Paint` ; fichier de suivi `SUIVI.md` ; premier push.
