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

Le fonctionnement détaillé (cœur, index PHP, connexion par boucle locale, version web sur les brouillons) :
[ide.md](ide.md).
