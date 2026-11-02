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
  chercher / remplacer dans les fichiers.
- **Git** : fenêtre Commit (modifications, cases à cocher, amend), diff avec HEAD, commit et push, mise à jour (pull),
  branches, stash, historique avec les fichiers de chaque commit, annotate (blame), annulation.
- **Exécution** : les configurations du projet (`bin/jumphp run / test / shot / build / webshot / serve / export / lint`
  par jeu ou application), sortie en direct, liens `fichier:ligne`, résultats des tests en arbre, captures ouvertes dans
  un onglet.
- **Éditeur visuel HTML / CSS** : les fichiers `.html` en Code / Split / Design (composants à glisser, calques,
  inspecteur, règles CSS des feuilles liées, tailles d'écran) ; le HTML reste tel qu'écrit. Pour les pages des
  applications de bureau et les sites des jeux.
- **Claude** (bureau) : la fenêtre Claude (`Alt+8`) parle au Claude Code de l'ordinateur. **Suivre** (l'œil, actif par
  défaut) : l'IDE montre ce que fait Claude au moment où il le fait : le fichier lu s'ouvre aux lignes lues (bleu), ce
  qu'une modification remplace est marqué avant (orange) et ce qu'il a écrit après (vert), ses commandes apparaissent
  dans Run avec leur sortie, ses recherches dans Find, son plan au-dessus de la conversation ; le clavier reste dans la
  fenêtre Claude et rien ne bouge pendant que vous tapez.
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
