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
- **Tests sur le playground** (`run_tests` du MCP, run n° 10 du 2026-11-05) : coupés à 63 tests sur 175 par sa limite
  de 120 s, aucun échec avant la coupure. Le bac à sable filme chaque test de jeu (une image sur 4, `JUMPHP_FILM`) :
  les longues parties (démos des peuples, équilibrage) n'y tiennent pas. La suite complète ne passe qu'en local
  (`bin/jumphp test`, 13 min). Idée proposée, pas encore demandée : y sauter les tests longs quand `JUMPHP_FILM` est
  défini. Les jours précédents, l'outil ne répondait pas du tout (délai dépassé).
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
5. **Vignettes 3D vivantes : terminées et publiées le 2026-11-05 (1er PC)** (demandé : « go pour les vignettes »,
   « récupère tout… et termine », puis « publie » : branche `vignettes` fusionnée dans `main`, poussée, supprimée). Chaque carte de l'écran de choix du peuple montre
   l'île flottante du peuple (`src/View3d/Diorama.php` : dessous de terre et de roche jusqu'à une pointe, racines,
   pierres), ses 4 tours au niveau 3 et ses ennemis qui en font le tour, sous un ciel 3D avec des nuages ; la caméra
   oscille (plus vite sur la carte choisie). Après les premières captures, et les remarques d'une autre session :
   - cadrage calculé (`Diorama::camera()`, tangage 19°, la plus haute tour `Diorama::TALLEST` et la pointe toujours
     dans le cadre) ;
   - hauteur de l'image selon la place que laissent les textes (`pictureHeight()`). En 3D, la rangée de portraits des
     ennemis est retirée : les ennemis marchent dans la vignette. Les vignettes sont alignées sous le nom le plus long ;
   - ciel opaque dessiné en 3D (`Mesh::SKY`) : plus de bandes, plus de liseré sombre autour des silhouettes, ombres
     réglées (`Diorama::SHADOW`) ;
   - découpe exacte au bord du cadre (division par w dans le fragment shader) et coins assombris relatifs au cadre
     (`u_vig`) ;
   - le fondu au noir assombrit les vignettes (`u_fade`, niveau lu dans le SceneManager) ; pas de vignettes sous le
     menu de pause (poussé quand la fenêtre perd le focus).

   Tests : `tests/VignettesTest.php` (5 tests) et `LegacyEngineTest` (écran des peuples avec la police pixel du moteur
   partagé). Aucun `u_*` dans les deux shaders à la fois : le partage casse le lien WebGL (le ciel passe le nuage par
   des varyings).
   **Vérifié le 2026-11-05** : suite complète (175 tests, smoke, phpstan) + lint ; textes en de, ru, ja ; navigateur
   (`bin/jumphp build` + `bin/jumphp webshot`, WebGL de Chrome : le shader compile, les îles s'affichent). Les
   portraits sont régénérés par `PortraitShotsTest` : l'assombrissement des coins est calculé par pixel, d'où un ou
   deux niveaux d'écart. Les vignettes n'emploient que des fonctions du moteur 0.4.0 :
   calque `above`, `setSupersample`, et le champ `$fade` du SceneManager, inchangé depuis 0.3.0.
   Vérifié aussi avec le moteur partagé avant de publier (`bin/jumphp build:remote <id>` puis `JUMPHP_WEB=remote
   bin/jumphp webshot <id> 9 '[[1,"Enter"]]'`) : police pixel en repli, vignettes 3D affichées, textes en place.
   Idée pour la suite (pas demandée) : des vignettes pour l'écran des cartes. `composer.lock` du jeu est suivi par git.
6. **Personnages 3D refaits (2026-11-05, 1er PC), publiés le 2026-11-05** (`523b9b9`, poussé sans vérification
   préalable avec le moteur partagé : à faire). L'utilisateur a trouvé les personnages
   « encore bien dégueu », avec pour repère un jeu de stratégie 3D d'il y a 25 ans « moins crade » : on vise ce niveau
   de finition sans copier ses personnages. Chaque peuple a maintenant sa carrure (orcs voûtés aux gros poings, trolls
   énormes, gobelins à grosse tête, nains trapus, elfes élancés, petits-hommes ronds). Les corps sont sculptés en
   surfaces lisses (`Mesh::lathe/limb/plate/sheet`). Les visages ont arcade, mâchoire, nez, oreilles et défenses ;
   casques, cuirasses et épaulières brillent ; les armes ont du volume et sont surdimensionnées ; il y a des boucliers
   et des capes. **Squelette animé par le shader** (`Skeleton`, `u_bones`) : marche au pas du sol, coups au-dessus de
   la tête synchronisés avec l'élan, archers qui bandent l'arc, repos, étourdissement, cavaliers en selle, vol. Les
   pieds restent à plat. Détails dans `AGENTS.md` (« Creatures »). Tests : `ViewTest` (tout se construit et se pose,
   les pieds restent au sol), `CreatureShotsTest` (planches `creatures-*.png` et batailles `creatures-battle-*.png` à
   regarder après chaque retouche). Les portraits de l'interface sont refaits par `PortraitShotsTest`.
7. **Le projet doit être dans `C:\JUMPHP\elfs-orcs-tower-defense`** (l'utilisateur, le 2026-11-05 : « ça n'a rien
   à foutre dans temp »). Les commits faits par erreur dans `%TEMP%` sont récupérés et poussés. Si une session
   démarre dans `%TEMP%`, le signaler tout de suite.
8. **Moins rétro (2026-11-05, 1er PC, tout publié)** : l'utilisateur trouvait l'écran titre
   (« jouer » / « réglages ») et les boutons tactiles (flèches, A, B, START) trop rétro pour un jeu qui se joue au clic
   ou au doigt.
   - **Plus d'écran titre** (`TitleScene` supprimée) : le jeu démarre sur le choix du peuple ; un **engrenage** en haut
     à droite (ou Échap) ouvre les réglages ; « Quitter » (PC) est dans les réglages ; pause, victoire et défaite
     ramènent au choix du peuple (« CHOISISSEZ VOTRE CAMP »). `site/images/title.png` supprimé (aucune page ne s'en
     servait).
   - **Pas de manette à l'écran sur téléphone** : nouvelle option du moteur `touch: {pad: none}` (`engine/web/touch.js`),
     activée dans `game.yaml`. **Le playground (moteur 0.4.0) l'ignore** et montre encore la croix et les boutons tant
     que JumPHP n'a pas cette option : à inclure dans la contribution au moteur (point 9).
   - **Réglages refaits en panneau peint** (`OptionsScene`, plus une liste « < SON 80% > ») : barre de volume avec − et
     +, flèches pour la langue, interrupteur plein écran (PC), boutons en bois RETOUR / QUITTER. Test `OptionsTest`.
   - **Plus aucun menu en liste** (« vire TOUT le rétro ») : `GameMenuScene` dessine un panneau peint (titre doré
     entre deux filets, textes, une place pour les étoiles de la victoire via `extraHeight()` / `renderExtra()`, un
     bouton en bois par choix). Pause, victoire et défaite en profitent. Test `MenusTest` (captures `menu-*.png`).
   - Le seul rétro qui reste vient du playground : sa police pixel et sa croix tactile (moteur 0.4.0), voir point 9.
   - **Double-clic = plein écran (corrigé, pas publié)** : cliquer vite sur une tour (sa fiche) puis pour la construire
     faisait passer Chrome en plein écran. C'était le moteur (`engine/web/jumphp.js` : un `dblclick` sur le jeu ouvre
     le plein écran). Nouvelle option `double_click_fullscreen` (vraie par défaut, passée à la page par
     `tools/render-web.php` et `tools/remote-manifest.php`), mise à `false` dans `game.yaml`. Le playground (0.4.0) le
     fait encore : à inclure dans la contribution (point 9).
9. **Contribution au moteur JumPHP (en cours, 2026-11-05)** : le mainteneur « attend nos request ». Exigence de
   l'utilisateur : **rester rétro-compatible, ne casser aucun jeu existant fait par d'autres** (tout est optionnel,
   rien ne change par défaut). JumPHP amont : `https://github.com/davidnurdin/jumPhp.git`, en 0.5.14 (le projet est
   en 0.4.0 : 280 commits de retard), récupéré en `refs/remotes/upstream/main`. Branches préparées sur 0.5.14, en
   local, dans deux worktrees hors du projet (`C:\JUMPHP\jumphp-contrib`, `C:\JUMPHP\jumphp-contrib2`) :
   - `contrib/hd-2d` : 2D en HD, `TtfFont` (gras, épaississement, contour), calque WebGL
     composé par la transparence. Fusionné avec le `translate()` (décalage `ox`/`oy`) apparu en amont. **`clear()`
     reste opaque** : garder l'alpha se demande par `clear($c, true)` (le jeu passe `true`, ignoré sans erreur par
     le moteur 0.4.0). Test `engine/tests/Renderer/HdTest.php`. Tests du moteur 0.5.14 : 137 OK + phpstan OK.
   - `contrib/touch-pad-none` : `touch: {pad: none}` dans `touch.js` **et** dans `TouchControls.php` (applis
     natives, apparu en amont). Test `TouchControlsTest`.
   - `contrib/double-click-fullscreen` : combiné avec la règle amont (pas de plein écran au double-clic en
     `view: window`).
   - `contrib/gl-row-length` : correctif des textures, bureau seulement (pas WebGL ni OpenGL ES des applis).
   - `contrib/git-bash-windows` : `bin/jumphp` sous Git Bash. **Sans** la liste de mots interdits de notre jeu
     (elle ferait échouer le lint de jeux d'autres personnes).
   **Vérifié** : avec `contrib/hd-2d`, les jeux d'exemple pong, hello-webgl, jumpball (3D) et hello-gui passent
   (tests + smoke) ; moteur sur `contrib/touch-pad-none` : 137 tests + phpstan OK ; le jeu avec `clear(..., true)` :
   ThreeD, LegacyEngine, Menus, Vignettes OK (14 tests). Pour lancer `bin/jumphp` dans un worktree sous Git Bash, y
   copier le `bin/jumphp` corrigé (puis `git checkout -- bin/jumphp`).
   **Pull requests ouvertes le 2026-11-05** (compte `malabke`, fork `github.com/malabke/jumPhp`, remote `fork` du
   projet ; `gh` installé dans `C:\Program Files\GitHub CLI\gh.exe`, connecté à `malabke`) :
   https://github.com/davidnurdin/jumPhp/pull/2 (HD), /3 (pad none), /4 (double-clic), /5 (textures), /6 (Git Bash).
   Descriptions dans `C:\JUMPHP\contrib-patches\pr\`.
   **Les 5 PR ont été fusionnées le soir du 2026-11-05 et publiées dans JumPHP 0.5.15** (tag `v0.5.15`, commit
   `06b3187` « merged the contributions »).
   **Projet mis à jour vers 0.5.15 le 2026-11-06, branche `update-0.5.15` (pas encore fusionnée dans `main` ni
   publiée)** : fusion du tag `v0.5.15` ; conflits du moteur pris côté amont ; comme à la création du projet
   (`a8ae177`), seul notre jeu dans `games/` et aucun workflow GitHub. Perdu à la fusion et remis : les tests PHPUnit
   d'un jeu OpenGL sous Xvfb (notre ajout `4749a96` à `bin/jumphp`, jamais proposé en amont : sans lui les tests 3D
   sont sautés). Nouveau dans 0.5.15 : `bin/jumphp test <jeu> tests/X.php` (un fichier) au lieu de `--filter` ;
   `config/project.yaml` (clé `project:`) remplace `config/game.yaml`, qui reste lu. Vérifié : moteur 140 tests +
   phpstan, jeu 186 tests (aucun sauté) + smoke OpenGL + phpstan, lint, `build` + `webshot`, `build:remote` +
   `JUMPHP_WEB=remote webshot` (police douce HD et vignettes 3D avec le moteur partagé 0.5.15). Corrigé au passage :
   le menu de pause poussé par le moteur hors d'une partie (fenêtre qui perd le focus) n'offre plus que REPRENDRE.
   Reste : fusionner dans `main` et pousser sur « pousse », puis `sync_project` → `engine: 0.5.15`, statut `ready`. Le
   playground construit peut-être la page du jeu avec son propre code : `double_click_fullscreen` et `touch` doivent
   y arriver (à vérifier dans `jumphp-playground`).
   Puis des
   vignettes 3D vivantes sur l'écran des cartes.

## Décisions de l'utilisateur (à respecter)

- Commits réguliers ; publier seulement sur demande.
- Pas de pixel art à l'ancienne ; style peint « livre d'images » ; pas de contours noirs façon BD.
- Rien de rétro dans l'interface (2026-11-05) : pas d'écran titre à menu, pas de menus en liste, pas de manette à l'écran ; des panneaux peints et des boutons en bois.
- Choix de conception faits sans réponse (modifiables) : voir `AGENTS.md` (« Design choices »).

## Pièges de cet environnement (Windows)

- Projet dans `C:\Users\NXTC\AppData\Local\Temp\...` : Windows y efface les fichiers à date ancienne → des fichiers
  de `vendor/` disparaissent (4 fois, dont `engine/vendor` et celui du jeu le 2026-11-05). Remède : `rm -rf` du `vendor` touché puis `bin/jumphp test engine` (moteur)
  ou `bin/jumphp install elfs-orcs-tower-defense` (jeu).
- Git Bash (pas PowerShell). `bin/jumphp` a des correctifs MSYS (chemins Docker) ; fichiers en LF dans l'arbre de
  travail, `git config core.autocrlf false` sur les deux PC (depuis le 2026-11-05 sur le 1er) : ne pas committer de
  fichiers modifiés seulement par les fins de ligne.
- **Deux sessions Claude Code sur le même dossier** (vu le 2026-11-05) : une seule travaille, l'autre s'arrête ; elles
  se parlent par messages entre sessions. Ne pas lancer Docker ou les tests depuis les deux à la fois.
- Les tests 3D tournent sur Mesa (lent) : lancer `bin/jumphp test ...` en tâche de fond.
- **Les demandes d'autorisation expirent vite** sur le 1er PC, comme les questions de l'IDE (`ask_user`). Le
  2026-11-05, plusieurs ont expiré, puis commit, push et `JUMPHP_WEB=remote ...` sont passés quand l'utilisateur
  était devant le panneau Claude. Concernées : les commandes git qui écrivent (fetch, commit, push), celles qui
  commencent par une variable, `curl`. Les `bin/jumphp` simples et les git en lecture passent sans demande. Si
  l'utilisateur n'est pas là : préparer le reste et lui donner les commandes à lancer dans son terminal. Les
  configurations « Run » de l'IDE ne marchent pas ici (`dirname: command not found`).
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
  (`World3d` ici, `Diorama` + `FactionSelectScene` par l'autre session), commitées sans test sur la branche `vignettes`
  (pas publiées) avant de changer de PC.
- 2026-11-05 (1er PC) : `git fetch`, branches `main` et `vignettes` récupérées ; vignettes testées, revues après
  capture (cadrage, île qui flotte, ciel 3D, place des textes, fondu, pause) et terminées sur la branche `vignettes`
  (`4948282`) ; vérifiées avec le moteur partagé, puis publiées (« publie ») : `vignettes` fusionnée dans `main`,
  poussée, branche supprimée.
- 2026-11-05 (1er PC, fin de journée) : personnages poussés (publiés) ; plus d'écran titre ni de manette à l'écran
  (`3567919`, publié) ; réglages, pause, victoire et défaite en panneaux peints, publiés (voir « En cours » 8).
- 2026-11-05 (1er PC, suite) : tests du playground relancés (coupés à 120 s, voir « Publication ») ; personnages 3D
  entièrement refaits avec squelette et animations (voir « En cours » 6).
