# Suivi du projet — Elfs & Orcs Tower Defense

Fichier de passation entre sessions et entre ordinateurs (il voyage avec git). **L'agent le relit au début de
chaque session, quand l'utilisateur le demande, et le met à jour après chaque étape** (état, décisions, suite).
Il complète `BRIEF.md` (la demande) et `AGENTS.md` (les notes techniques du jeu). Langue : français.

Dernière mise à jour : 2026-11-05.

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
  publie le jeu** (https://jumphp.net/elfs-orcs-tower-defense, pas listé dans l'annuaire : `update_project
  listed=true` seulement sur demande). Poussé le 2026-11-04 (« pousse tout ») : l'URL du dépôt contient le compte
  `malabke` (`https://malabke@github.com/...`) pour que Git Credential Manager prenne ce compte et pas `clement-cdy`.
  Pour un état de travail sans publier : une autre branche. Sur un autre PC : `git pull`.
- **Le playground n'utilise pas le moteur de ce dépôt** mais son moteur partagé (la version officielle de `VERSION`,
  0.4.0) : les ajouts faits au moteur ici (2D en HD, composition par transparence, options de TtfFont) n'y sont pas.
  Le jeu le détecte (`src/Ui/Hd.php`) et y garde l'ancienne manière : police pixel, 3D par couleur clé (vérifié avec
  `build:remote` + `JUMPHP_WEB=remote webshot`). Pour avoir l'interface HD sur le playground, il faudrait que JumPHP
  intègre ces changements du moteur (une contribution au projet JumPHP) : à proposer à l'utilisateur.

## En cours / à faire

1. Interface HD faite, vérifiée et poussée (`7e773b9` moteur, `44f43d7` jeu) ; le playground la montre à l'ancienne
   (police pixel) faute du moteur à jour, voir « Publication ». Le premier push a cassé le jeu sur le playground
   (constante `TtfFont::BOLD` absente de son moteur) : corrigé par `Hd` et le repli, à garder en tête (toujours
   vérifier avec le moteur partagé avant de pousser).
2. **Fait (2026-11-04, 2e PC), publié** : le pixel art qui restait dans l'interface (créatures des
   cartes de peuple, icônes de tours du menu en anneau, bouton du héros, aperçu de la vague) est remplacé par des
   **portraits photographiés des modèles 3D** (`assets/sprites/portraits/`, faits par `tests/PortraitShotsTest.php` :
   voir `AGENTS.md` « Portraits »). Tests complets + lint OK, vérifié avec le moteur partagé (`build:remote` +
   `JUMPHP_WEB=remote webshot` : portraits affichés, police pixel en repli).
3. **Fait (2026-11-05), publié** : **particules** dans le combat 3D (étincelles et poussière aux impacts,
   bouffées et pièces aux morts, poussière et paillettes dorées aux constructions, braises sur ce qui brûle, traînées
   des tirs lumineux, pluie de lumière du pouvoir…) et **coups au corps à corps** (soldats, héros et ennemis bloqués
   s'élancent vers leur adversaire). Voir `AGENTS.md` « Particles ». Tests complets + lint OK.
4. **Poussé et publié (2026-11-05)** : `f9a4176` (portraits), `807b994` (suivi), `32419b8` (particules) sont sur
   GitHub ; jumphp.net a publié `32419b8` (07:09 UTC, statut `ready`). Sur le 2e PC, le push n'a marché qu'une fois
   l'utilisateur connecté au compte `malabke` (refus 403 avec `clement-cdy`, puis « could not read Password » sans
   lui) ; le remote est `https://malabke@github.com/malabke/elfs-orcs-tower-defense.git`. Si le refus revient :
   `git credential-manager github login --username malabke`, puis `git push origin main`, lancés par l'utilisateur
   dans son propre terminal.
5. **En cours (2026-11-05) : vignettes 3D vivantes** sur les cartes de l'écran de choix du peuple (demandé : « go pour
   les vignettes »). Fait par une **autre session** Claude Code sur le même dossier (`elfs-orcs-tower-defense-73`), qui
   garde ces fichiers : `src/View3d/Diorama.php` (île flottante, emplacements des 4 tours, ennemis qui tournent
   autour), `FactionSelectScene::vignette()` (ciel peint en 2D, la 3D par-dessus). Base écrite par cette session-ci
   dans `src/Ui/World3d.php` : `bootOver()` (calque 3D « above », existe aussi dans le moteur 0.4.0 du playground),
   `vignette()` (la projection d'une petite caméra resserrée dans le cadre de la carte), `u_clip` dans le shader (rien
   ne déborde du cadre), `studio()` avec un fond facultatif. **Pas encore testé ni commité.** Reste : tests,
   captures, vérification avec le moteur partagé, commit ; peut-être la même chose pour l'écran des cartes.
6. Conseiller à l'utilisateur de **déplacer le projet hors de `%TEMP%`** (1er PC, voir les pièges).

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
- **2e PC (`C:\JUMPHP\elfs-orcs-tower-defense`)** : lancer Docker Desktop avant tout ; `git config core.autocrlf false`
  (sinon le patch OpenGL ne s'applique pas) ; les dépendances du moteur manquaient : `bin/jumphp test` ne faisait rien
  et sortait sans erreur tant que `bin/jumphp test engine` n'avait pas tourné une fois. Le terminal de l'agent coupe
  les commandes longues : lancer `bin/jumphp` dans un Git Bash détaché qui écrit un journal
  (`C:\JUMPHP\run-test.sh <journal> <arguments>`, puis lire le journal). L'IDE (PhpStorm) et Docker saturent la machine :
  **une seule session à la fois lance des tests ou Docker**.

## Où regarder

- `AGENTS.md` du jeu : architecture 3D (`src/View3d/`, `src/Ui/World3d.php`), interface (`Paint`), pièges techniques.
- Tests utiles : `tests/ModelShotsTest.php` (toutes les tours de chaque peuple en image), `tests/SiteShotsTest.php`
  (images du site), `tests/ThreeDTest.php` (clics en 3D). Images : `var/screenshots/tests/`.
- `tools/site/make.php` : découpe les images du site et des cartes de menu dans les captures des tests.

## Journal

- 2026-11-02 / 03 : jalons 1 à 4, site, équilibrage ; passage à la 3D WebGL.
- 2026-11-04 : style peint « livre d'images » (commits `9edac44`, `8ff2691`) ; correctif moteur des textures
  (`GL_UNPACK_ROW_LENGTH`) ; interface HD avec Nunito et `Paint` ; fichier de suivi `SUIVI.md` ; premier push (après
  un refus 403 : mauvais compte GitHub) ; le jeu plantait sur le playground (moteur partagé sans les ajouts) :
  repli compatible (`Hd`, police pixel, couleur clé) et nouveau push.
- 2026-11-04 (2e PC, `C:\JUMPHP\elfs-orcs-tower-defense`) : `git pull`, portraits 3D écrits (voir « En cours » 2).
  Sur ce PC, lancer Docker Desktop avant `bin/jumphp` (sinon `install` attend sans fin). Le clone avait des fins
  de ligne CRLF (`core.autocrlf=true`) : `docker/ext/php-opengl.patch` ne s'appliquait plus et l'image Docker ne se
  construisait pas. Corrigé par `git config core.autocrlf false` (ce dépôt) + réécriture des fichiers en LF.
  **Sur un nouveau PC Windows : `git config core.autocrlf false` juste après le clone.**
- 2026-11-04 / 05 (2e PC) : portraits 3D (`f9a4176`), particules et coups (`32419b8`), tous les tests et le lint
  passent, vérifiés avec le moteur partagé ; poussés et publiés après connexion au compte `malabke` ; vignettes 3D commencées
  (`World3d` ici, `Diorama` + `FactionSelectScene` par l'autre session).
