# Applications de bureau (Tauri) : des pages HTML, du PHP derrière

[English](tauri.md) — la version anglaise est la référence complète (API détaillée, protocole C).

JumPHP fait des jeux (SDL : bureau, navigateur, téléphones) et des **applications de bureau** : des
fenêtres natives dont le contenu est en **HTML / CSS / JavaScript**, affiché par la WebView du système, et dont la
logique est en **PHP**. La fenêtre, la WebView, les boîtes de dialogue et les notifications viennent de
[Tauri 2](https://tauri.app) ; le programme, c'est PHP.

```bash
bin/jumphp app:new notes "Notes"      # games/notes à partir de skeleton-app/
bin/jumphp install notes
bin/jumphp app:run notes              # une fenêtre sur ton écran
bin/jumphp app:test notes             # php -l + phpstan + phpunit (sans fenêtre) + l'app pour de vrai sur un écran virtuel
bin/jumphp app:shot notes             # games/notes/var/screenshots/app.png
bin/jumphp app:export notes           # games/notes/dist/standalone/notes-linux-x86_64 : un seul fichier à donner
```

L'exemple : [`games/hello-desktop`](../games/hello-desktop), un bloc-notes (ouvrir / enregistrer avec les boîtes
de dialogue du système, une horloge PHP envoyée à la page, des fichiers glissés sur la fenêtre, une question avant
de quitter si le texte n'est pas enregistré).

**Natif uniquement.** Les applications de bureau ne tournent pas dans le navigateur. Linux aujourd'hui ; Windows et
macOS sont prévus. **Rien ne change pour les jeux** : jeux SDL sur le bureau, dans le navigateur (WebAssembly), en
exécutable autonome, applis Android et Apple fonctionnent exactement comme avant ; aucun de leurs codes, images
Docker ou commandes n'utilise cette partie.

## Le principe

PHP reste le programme principal, comme pour un jeu. Tauri est un *backend de plateforme* que PHP pilote :

1. `app.php` → `JumPHP\Tauri\App::boot()` → `run()` : PHP appelle `jt_run()` de la bibliothèque
   `libjumphp_tauri.so` (Rust, dossier `tauri/`) **depuis son thread principal**, par FFI ;
2. Tauri construit l'app et fait tourner sa boucle native (GTK sous Linux) **sur ce thread** ;
3. à chaque frame (60 par seconde, et tout de suite quand il se passe quelque chose), Tauri rappelle PHP :
   `App::frame()` lit les événements (`jt_poll()`), fait un tour de la boucle ReactPHP (timers, HTTP, tâches),
   et envoie ses commandes (`jt_send()`), exécutées juste après sur le thread principal ;
4. quand PHP demande à quitter, `jt_run()` rend le code de sortie.

C'est le même modèle que le moteur navigateur (la page appelle `jumphp_frame()` à chaque
`requestAnimationFrame`). PHP ne bloque jamais : dialogues, sélecteurs de fichiers et appels des pages répondent
plus tard (une `Task` côté PHP, une `Promise` côté JavaScript). **PHP décide** : fermer une fenêtre ou l'app est un
événement auquel PHP répond (par défaut il ferme / quitte ; un listener peut faire `preventDefault()`).

Une seule bibliothèque pour toutes les apps : les pages sont servies depuis le dossier de l'app à l'exécution
(`tauri://localhost/`), pas compilées dedans.

## Une app

```
games/notes/
  app.php                 exit(JumPHP\Tauri\App::boot(__DIR__)->run());
  config/game.yaml        ui: webview, main: App\Main, app: {...}
  src/Main.php            implémente JumPHP\Tauri\AppMain : commandes, listeners, timers
  ui/                     les pages : index.html, CSS, JS, images
  assets/icon.png         l'icône de la fenêtre
  tests/MainTest.php      étend JumPHP\Testing\AppTestCase
```

`config/game.yaml` :

```yaml
game:
  id: notes
  title: "Notes"
  version: 0.1.0
  ui: webview                   # une app de bureau (run / test / shot passent la main aux commandes app:*)
  main: App\Main
  app:
    pages: ui                   # dossier des pages
    tick_hz: 60                 # frames par seconde quand rien ne se passe
    native_commands: true       # les commandes native.* intégrées pour les pages
    window: { url: index.html, width: 960, height: 640, min_width: 480, min_height: 320, devtools: false }
```

Côté PHP :

```php
final class Main implements AppMain
{
    public function boot(App $app): void
    {
        // page -> PHP -> page : const r = await jumphp.invoke('greet', {name: 'Ada'})
        $app->command('greet', fn (array $p) => "Bonjour {$p['name']}");
        // une réponse qui vient plus tard : renvoyer une Task
        $app->command('pick', fn () => FilePicker::open(['filters' => ['Texte' => ['txt', 'md']]]));
        // PHP -> page : jumphp.on('clock', ...)
        $app->gameLoop->addPeriodicTimer(1.0, fn () => $app->send('clock', ['time' => date('H:i:s')]));
        // PHP décide de la fermeture
        $app->window()->on('close-requested', function (NativeEvent $e) use ($app) {
            $e->preventDefault();
            Dialog::confirm('Quitter ?', 'Notes', 'Quitter', 'Rester')->then(fn ($oui) => $oui && $app->quit());
        });
    }
}
```

Côté page, `window.jumphp` est injecté avant les scripts de la page :

```js
const r = await jumphp.invoke('greet', {name: 'Ada'});   // Promise : la réponse de PHP, ou le message de son exception
jumphp.send('clicked', {x: 1});                          // PHP : $app->on('page:clicked', ...)
jumphp.on('clock', (p) => ...);                          // PHP : $app->send('clock', ...)
await jumphp.invoke('native.open', {filters: {Images: ['png', 'jpg']}});   // sans code PHP : native.message,
                                                         // native.confirm, native.open, native.save, native.notify, native.openUrl
```

## Fonctions natives depuis PHP : `JumPHP\Native`

Les mêmes appels sur toutes les cibles ; ce qui les réalise dépend de l'endroit où le code tourne :

```php
Dialog::message('Enregistré.', 'Notes');
Dialog::confirm('Supprimer ?', 'Notes', 'Supprimer', 'Garder')->then(fn (bool $oui) => ...);
FilePicker::open([...])->then(fn (?string $fichier) => ...);   // aussi multiple, folder(), save()
Notification::show('Fini', 'Export terminé');
Shell::openUrl('https://jumphp.net');
Native::supports('open');                                      // avant d'appeler
```

| Cible | Fournisseur | Fonctions |
|---|---|---|
| app de bureau | `tauri` | toutes : message, confirm, open, save, folder, notify, openUrl, openPath, reveal |
| jeu SDL sur le bureau | `sdl` | message (la boîte de message de SDL) |
| version navigateur | `browser` | openUrl |
| tests sans écran, ailleurs | `none` | rien |

Ce qui manque à une cible fait échouer la Task avec `UnsupportedFeature` (ou renvoie `false`). En dessous restent
le **FFI** de PHP (n'importe quelle bibliothèque C du système) et les extensions PHP.

## Tests

`JumPHP\Testing\AppTestCase` joue l'app **sans fenêtre** (un `FakeRuntime` : ni FFI ni GTK) ; le test fait la
page et l'utilisateur : `invoke()`, `pageSend()`, `requestClose()`, `answerDialog()`, `frames()`, `wait()`,
`sent()`, `lastSent()`. `bin/jumphp app:test` les lance puis fait tourner **l'app pour de vrai** sur Xvfb : échec
sur un warning PHP, une exception, ou si la page principale ne s'est jamais chargée.

## Export : un seul exécutable

`bin/jumphp app:export <app>` réutilise `export:standalone` : le micro de StaticPHP avec les extensions des apps
(`tools/app-extensions.txt` : FFI, OpenSSL, mbstring…, sans SDL ; construit une fois, ~10 min, gardé dans
`~/.jumphp/standalone`), `ffi.enable=1` injecté dans l'exécutable, l'app en PHAR et la bibliothèque Tauri. Au
premier lancement, l'app est dépaquetée dans `~/.cache/jumphp/<id>-<build>` ; les fichiers de l'utilisateur vont
dans `~/.local/share/<id>`.

L'ordinateur a besoin de **WebKitGTK 4.1** (`libwebkit2gtk-4.1-0` : Debian 12+, Ubuntu 22.04+, Fedora 39+) et
d'une glibc 2.34 ou plus récente, comme toute app Tauri sous Linux. Vérifié sur un Debian 13 nu avec seulement
`libwebkit2gtk-4.1-0` (sans PHP).

L'export des jeux n'est pas touché : sans les variables des apps, `export:standalone` construit et retrouve le même
micro qu'avant (même clé).

## Commandes

| Commande | |
|---|---|
| `app:new <app> [titre]` | `games/<app>` depuis `skeleton-app/` |
| `app:run <app>` | l'app sur ton écran (X11, sinon Wayland ; bus de session pour les notifications ; GPU) |
| `app:test <app>` | php -l, phpstan, phpunit, puis l'app pour de vrai sur Xvfb |
| `app:shot <app> [frames] [out.png]` | la fenêtre principale après N frames |
| `app:export <app> [--linux]` | un exécutable Linux |
| `tauri:setup` | l'image `jumphp/tauri` (le runtime bureau + FFI + Rust + WebKitGTK) |
| `tauri:build` | `tauri/` → `build/tauri/linux-x86_64/libjumphp_tauri.so` (lancé tout seul quand il manque ou que ses sources ont changé) |

`run`, `test` et `shot` sur le dossier d'une app passent la main à `app:run`, `app:test`, `app:shot` ;
`pages` ne publie pas les apps (elles n'ont pas de version navigateur).

## Feuille de route

Fait (Linux) : le runtime, PHP qui le pilote depuis son thread principal, fenêtres, pages, page ↔ PHP dans les deux
sens, dialogues / notifications / ouverture de liens, glisser-déposer, fermeture décidée par PHP, tests sans fenêtre,
tests en vraie fenêtre sur Xvfb, export en un fichier.

Ensuite : Windows et macOS (le runtime compile tel quel ; il faut `tauri:build` sur ces systèmes via GitHub
Actions et le micro des apps pour eux), Android et iOS (Tauri les gère, mais la boucle y est pilotée par l'activité /
UIKit : PHP y serait appelé depuis ces boucles, comme le font déjà les applis SDL natives), le mode hybride (une
surface SDL dans une fenêtre WebView), d'autres fonctions natives (presse-papiers, icône de zone de notification,
raccourcis globaux, mise à jour automatique).
