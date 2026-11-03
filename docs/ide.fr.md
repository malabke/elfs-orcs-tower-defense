# JumPHP IDE

[English](ide.md)

Un IDE pour les projets JumPHP (jeux, applications de bureau, jeux hybrides, remakes Amstrad CPC, portages), **écrit en
PHP**, qui marche pareil **en application native sur l'ordinateur** et **sur le web** (`jumphp.net/ide`). Il édite vos
vrais projets : vous vous connectez avec jumphp.net, il liste vos projets (vos dépôts GitHub), les clone et pousse vos
commits.

- `ide/` : le paquet `jumphp/ide`, le cœur PHP (`JumPHP\Ide\`) et l'interface (HTML / CSS / JS avec Monaco, l'éditeur de
  VS Code) ; partagés par l'application de bureau et le site.
- `games/ide/` : l'application de bureau (Tauri, `docs/tauri.fr.md`).

## Ce qu'il sait faire

- **Éditeur** : onglets, découpage, diff, sauvegarde automatique, thèmes sombre et clair.
- **Intelligence PHP** : complétion qui suit les types (`$this->engine->config->`, types de retour, `@var`, `@property`,
  `@method`), import automatique des `use`, aide aux paramètres, aller à la déclaration / aux implémentations, recherche
  des usages, renommage (classe, méthode, propriété, constante, variable ; une classe renomme son fichier), documentation
  au survol, structure du fichier, problèmes (erreurs de syntaxe, classes / méthodes / fonctions inconnues).
- **Navigation** : Search Everywhere (double Maj), aller au fichier / à la classe / au symbole, fichiers récents,
  chercher / remplacer dans les fichiers, position précédente / suivante (Ctrl+Alt+Gauche / Droite, ou les boutons
  précédent / suivant de la souris).
- **Git** : fenêtre Commit (modifications, cases à cocher, amend), diff avec HEAD, commit et push, mise à jour (pull),
  branches, stash, historique avec les fichiers de chaque commit, annotate (blame), annulation.
- **Exécution** : les configurations du projet (`bin/jumphp run / test / shot / build / webshot / serve / export / lint`
  par jeu ou application), sortie en direct, liens `fichier:ligne`, résultats des tests en arbre, captures ouvertes dans
  un onglet.
- **Terminal** (bureau) : `Alt+F12` (View > Terminal), des shells dans le dossier du projet, un onglet chacun (`+`).
  Linux / macOS : ton shell (`$SHELL`) dans un pseudo-terminal (couleurs, invite, Ctrl+C, programmes plein écran, taille
  qui suit la fenêtre) ; les touches vont au shell (seules les `Alt+…` vont à l'IDE), copier / coller avec
  `Ctrl+Maj+C / V`. Windows : `cmd.exe` (`JUMPHP_IDE_SHELL=powershell` pour PowerShell) sur des tubes : la ligne est
  éditée dans l'IDE puis envoyée.
- **Historique local** (bureau) : File › Show Local History (aussi au clic droit d'un onglet ou d'un fichier de l'arbre) :
  les versions d'un fichier gardées par l'IDE sur cet ordinateur, sans git : une à chaque sauvegarde, et une quand le
  fichier a changé hors de l'IDE (Claude Code, un terminal, git) avant que l'IDE le sauve ou le rouvre. **Compare** (un
  diff dont la droite est le fichier, modifiable) ou **Revert** (un seul changement dans l'éditeur : Ctrl+Z le défait).
  Gardé dans `.jumphp/history/files/` (hors de git) : les 60 dernières versions de chaque fichier, 60 jours au plus,
  fichiers texte jusqu'à 1 Mo.
- **Débogueur** (bureau) : Xdebug. Un clic dans la marge à gauche d'une ligne PHP pose un point d'arrêt (clic droit :
  une condition, `Ctrl+F8` au curseur) ; **Debug** (`Maj+F9`, l'insecte à côté de Run) lance la configuration choisie
  avec Xdebug (`JUMPHP_XDEBUG`). À l'arrêt : la ligne marquée, la fenêtre **Debug** avec la pile, les variables en
  arbre, des expressions surveillées ou évaluées, une variable changée (double clic ou `F2` : une expression PHP) ;
  Reprendre `F9`, pas à pas `F8`, entrer `F7`, sortir `Maj+F8`, **exécuter jusqu'au curseur** `Alt+F9`, Stop.
  **Stop at Exceptions…** (le ⚠ de la fenêtre Debug) : `*` ou des noms de classes, il s'arrête là où l'une est lancée,
  attrapée ou non, et dit laquelle avec son message. Claude s'en sert aussi (outils `debug_*`) : vous le voyez poser
  ses points d'arrêt et avancer dans le code. Depuis un terminal : `claude mcp add jumphp-debug -- bin/jumphp debug`
  (le même débogueur en serveur MCP ; Claude lance le programme avec `JUMPHP_XDEBUG=9003`, puis `debug_wait`).
- **Éditeur visuel HTML / CSS** : les fichiers `.html` en Code / Split / Design (composants à glisser, calques,
  inspecteur, règles CSS des feuilles liées, tailles d'écran) ; le HTML reste tel qu'écrit. Pour les pages des
  applications de bureau et les sites des jeux.
- **Plugins** (bureau) : des plugins en PHP (docs/ide-plugins.md) ajoutent des actions (menus, Find Action, raccourcis),
  des fenêtres d'outils, des configurations d'exécution, de la complétion et des problèmes pour les fichiers qu'ils
  choisissent, et des **outils pour Claude** sur le serveur MCP de l'IDE (`mcp__jumphp-ide__plugin_<id>_<nom>`).
  Tools › Plugins… les installe (un dossier, les plugins du projet, [jumphp.net/ide-plugins](https://jumphp.net/ide-plugins)),
  les met à jour, les active, les retire, et publie ceux du projet sur jumphp.net ;
  `bin/jumphp plugin:new` en crée un, testé sans fenêtre (PluginTestCase).
- **Claude** (bureau) : la fenêtre Claude (`Alt+8`) parle au Claude Code de l'ordinateur. **Suivre** (l'œil, actif par
  défaut) : l'IDE montre ce que fait Claude au moment où il le fait : le fichier lu s'ouvre aux lignes lues (bleu), ce
  qu'une modification remplace est marqué avant (orange) et ce qu'il a écrit après (vert), ses commandes apparaissent
  dans Run avec leur sortie, ses recherches dans Find, son plan au-dessus de la conversation ; le clavier reste dans la
  fenêtre Claude et rien ne bouge pendant que vous tapez.
- **Claude : onglets, fenêtres, historique** : un onglet par conversation ; `+` en démarre une autre sans arrêter les
  autres (chacune son processus Claude Code). Clic droit sur un onglet : **Open in a Window** (la conversation continue
  dans sa propre fenêtre ; fermer cette fenêtre la remet en onglet), renommer, copier en Markdown, fermer. Chaque
  conversation est gardée dans le projet, `.jumphp/history/claude/<id>.json` (un `.gitignore` garde `.jumphp/history/`
  hors de git) : l'onglet **History** les liste, la plus récente d'abord ; un clic la rouvre dans un onglet et elle
  reprend où elle en était (`--resume`).
- **Claude pilote l'IDE** : l'IDE donne au Claude qu'il lance ses propres outils (serveur MCP `jumphp-ide` sur
  `127.0.0.1`, jeton aléatoire) : ouvrir un fichier, pointer des lignes avec une note, montrer un diff ou une fenêtre,
  lancer une configuration dans Run et lire son résultat, jouer une scène, les problèmes d'un fichier, ce que vous
  regardez (fichiers ouverts, curseur, sélection), vous poser une question, une notification. Détails : `docs/ide.md`
  (« The IDE's tools for an AI »).
- **Projets** : connexion jumphp.net, vos projets, clonage, ouvrir un dossier, cloner un dépôt, Nouveau projet : jeu,
  application de bureau, jeu hybride, remake Amstrad CPC (le `.dsk` reste sur l'ordinateur), portage d'un jeu libre.

Les raccourcis de PhpStorm (Aide > Raccourcis clavier).

## Commandes

```bash
bin/jumphp install ide           # composer install (et Monaco dans ide/ui/vendor/)
bin/jumphp app:test ide          # php -l, phpstan, phpunit, l'application en vrai sur Xvfb
bin/jumphp app:export ide        # games/ide/dist/standalone/ide-linux-x86_64 : l'IDE en un exécutable
bin/jumphp app:run ide           # pour l'essayer dans Docker (n'y voit que le dépôt JumPHP, sans Docker)
```

Pour travailler sur vos projets, **utilisez l'exécutable exporté** : il tourne sur votre ordinateur, avec votre `git`
et votre Docker (nécessaire, comme toujours, à `bin/jumphp run / test` des projets).

Variables : `JUMPHP_IDE_SERVER` (un autre jumphp.net), `JUMPHP_IDE_PROJECTS` (où cloner, par défaut
`~/JumPHP-Projects`), `JUMPHP_IDE_PROJECT` (ouvrir ce dossier), `JUMPHP_IDE_EVAL` (JavaScript lancé dans la page : tests,
captures).

**Mises à jour** : l'IDE installé se met à jour tout seul (`games/ide/src/SelfUpdate.php`). 8 s après son démarrage puis
toutes les 6 heures, il lit `games/ide/installer/update.json` sur main, vérifie sa signature, télécharge la version plus
récente, vérifie son SHA-256 et remplace son exécutable en arrière-plan ; une notification propose alors *Restart Now*
(sinon le prochain démarrage la lance). Aide > Check for Updates…, Aide > Update Automatically (activé par défaut).
Publier une version : augmenter `version` dans game.yaml, puis `games/ide/tools/release.sh "Les nouveautés."` (export
Linux, Windows, macOS, `update.json` signé avec `~/.jumphp/keys/ide-update.pem` — **gardez une copie de cette clé** —,
release GitHub `ide-v<version>`, puis `update.json` et `version.json` poussés sur main). Les IDE plus anciens que la 0.1.3
n'ont pas cette mise à jour : à réinstaller une fois.

Le fonctionnement détaillé (cœur, index PHP, connexion par boucle locale, version web sur les brouillons) :
[ide.md](ide.md).
