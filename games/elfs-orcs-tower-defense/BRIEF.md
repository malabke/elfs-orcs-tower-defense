# Elfs & Orcs Tower Defense

@AGENTS.md

Brief — LAST BASTION (tower defense high fantasy, JumPHP)

Titre et identifiant provisoires : games/last-bastion/. Ce fichier complète AGENTS.md (le contrat du framework, à lire en entier avant d'écrire du code). En cas de conflit technique, AGENTS.md gagne.

1. Pitch

"Elves, Dwarves, Horsefolk, Little Folk or the Orc horde: choose your side, hold your ground, and decide who rules the realm."

Tower defense rétro 2D, pixel art, jouable sur bureau et navigateur (desktop + mobile). Le joueur choisit un camp parmi cinq. Chaque camp a ses propres tours, son économie et son style. L'ennemi vient du camp opposé :

joueur Elfes / Nains / Peuple des chevaux / Petits-hommes → il défend contre la Horde orque ;
joueur Orcs → il défend son bastion contre l'Alliance des peuples libres (unités des quatre autres races).

Chaque race existe donc deux fois dans les données : comme tours (côté défense) et comme ennemis (côté attaque).

2. Propriété intellectuelle (non négociable)

Univers inspiré de la grande fantasy classique, sans licence. Aucun nom, lieu, personnage, objet ou terme inventé par l'œuvre de référence ne doit apparaître : ni dans le code, ni dans les textes, ni dans les noms de fichiers, ni dans les traductions. Termes génériques autorisés : elfe, nain, orc, troll, gobelin, warg, aigle géant, cavalier. Pour les deux peuples à nom protégé : Horsefolk / Peuple des chevaux et Little Folk / Petits-hommes.

Ajoute ces mots au contrôle bin/jumphp lint (tools/check-forbidden-words.sh, tableau WORDS, déjà encodés en rot13 pour que ce fichier ne les contienne pas) :

gbyxvra ebuna ebuveevz zbeqbe tbaqbe uboovg fuver hehx anmthy fnheba tnaqnys zvguevy zbevn onyebt
vfratneq fnehzna ybguybevra zvexjbbq evatjenvgu

Invente tous les noms propres (royaumes, cartes, héros, boss). Ton : épique, un peu d'humour chez les Petits-hommes et les Orcs.

3. Principes de tower defense à respecter
Chemins fixes : les ennemis suivent un ou plusieurs chemins (waypoints) de l'entrée jusqu'au bastion. Les unités volantes ignorent le chemin (ligne droite ou trajet aérien dédié).
Emplacements de construction fixes sur chaque carte (pas de placement libre : lisible au tactile).
Vagues annoncées (aperçu des types d'ennemis à venir), bouton « appeler la vague plus tôt » = bonus d'or.
Économie : or par ennemi tué + bonus de fin de vague ; revente à 70 % de l'investi.
Vies du bastion : chaque ennemi qui passe en retire (1, ou plus pour les gros). 0 = défaite.
Améliorations : 3 niveaux par tour, puis un choix de spécialisation entre deux branches au niveau 4.
Types de dégâts / armures : physique, perçant, magique, feu, siège ; ennemis légers, blindés, résistants à la magie, volants. Chaque camp doit avoir une réponse à chaque menace, mais pas la même.
Ciblage réglable par tour : premier, dernier, plus fort, plus proche.
Effets : ralentissement, étourdissement, brûlure (dégâts sur la durée), peur (recul), armure réduite.
Héros (jalon 3) : une unité contrôlable par camp, avec un pouvoir à recharge.
Pouvoir de camp à recharge (ex. pluie de flèches, éboulement, charge de cavalerie).
Étoiles : 1 à 3 selon les vies restantes ; débloque les cartes suivantes. Mode difficile ensuite.
4. Les camps

Valeurs indicatives, à équilibrer par les tests (§8). Chaque camp : 4 tours de base, identité de couleur propre, un pouvoir de camp.

Camp	Identité	Tours (défense)	Unités (attaque)
Elfes	portée, précision, magie ; chers	tour d'archers (longue portée, critiques), sanctuaire lunaire (magie + ralentissement), sentinelle sylvestre (racines qui immobilisent), mage-chanteur (dégâts de zone magiques)	archers rapides qui esquivent, lames-danseuses, aigles géants (volants)
Nains	dégâts lourds, blindage, lent	arbalète lourde (perce l'armure), lance-pierres de siège (zone), forge runique (bonus aux tours voisines), mine (or passif)	boucliers de fer (très blindés, lents), mineurs qui surgissent plus loin sur le chemin, porteur de runes (soigne)
Peuple des chevaux	mobilité, unités au sol qui bloquent	écuries (cavaliers qui interceptent sur le chemin), lanceurs de javelots, cor de guerre (vitesse d'attaque des voisines), palissade	cavaliers très rapides, archers montés, charge de masse (vague éclair)
Petits-hommes	pas chers, nombreux, ruse, économie	frondeurs (cadence élevée, faibles dégâts), pièges sur le chemin, auberge (or passif), jardin caché (camouflage : coup critique sur la première cible)	éclaireurs furtifs (invisibles hors détection), groupes nombreux et fragiles
Orcs	masse, feu, peur ; bon marché, imprécis	baraquement (orcs qui bloquent), catapulte brute (zone, imprécise), fosse à poix (feu), tambours de guerre (bonus + peur)	grognards, coureurs gobelins, orcs de fer (blindés), chevaucheurs de wargs, trolls (boss), chauves-souris géantes (volants), bélier (siège, beaucoup de vies)

Côté Alliance (ennemis du joueur orc) : mélange des quatre peuples, avec un boss par race. Côté Horde : un seigneur de guerre orc comme boss final.

5. Contenu et progression
Jalon 1 (cœur) : 1 carte, camp Elfes contre la Horde, 10 vagues, 4 tours, améliorations, victoire/défaite.
Jalon 2 (camps) : les 5 camps jouables, écran de choix du camp, Alliance comme ennemi du joueur orc.
Jalon 3 (campagne) : 6 cartes par camp minimum (cartes partagées, vagues propres), héros, pouvoirs de camp, étoiles, mode difficile, sauvegarde de progression.
Jalon 4 (finition) : équilibrage, site/ (§13 d'AGENTS.md), miniature, publication (seulement sur demande).

Ne passe au jalon suivant que si le précédent respecte la définition de terminé (AGENTS.md §10).

6. Multilingue (dès le jalon 1)

Langues : celles du playground, fr en es de it pt nl pl ru uk tr ja zh ko. Toutes, pas seulement fr/en.

Aucun texte en dur dans le code : tout passe par Lang::t('KEY'), clés = texte source anglais en majuscules (convention du squelette), paramètres {n}. config/lang.yaml contient les 13 autres langues.
game.yaml : descriptions rempli dans les 14 langues.
Langue : détectée par la page (?lang= ou langue du navigateur), modifiable dans le menu Options (option moteur lang, sauvegardée). Le menu liste chaque langue dans sa propre langue (Français, 日本語…).
Police : la police bitmap intégrée ne fait que des majuscules ASCII (les accents sont retirés) : elle ne suffit pas. Utilise des polices TTF dans assets/fonts/, sous licence libre (OFL), au rendu pixel : une police latine/cyrillique pour l'essentiel et une police de secours couvrant japonais, chinois, coréen (par ex. GNU Unifont, licence OFL). Un service FontProvider choisit la police selon la langue. Vérifie le poids des fichiers (20 Mo maximum par fichier sur le playground) et mets les rendus de texte en cache.
Prévois des textes 30 à 40 % plus longs qu'en anglais (allemand, russe) : pas de largeur fixe sans vérification, retour à la ligne ou réduction automatique.
Les noms de tours, d'unités et de cartes se traduisent aussi (ce sont des noms inventés, à adapter par langue).
Un test échoue si une clé utilisée dans le code manque dans une des 13 langues, ou si une clé de lang.yaml n'est plus utilisée.
Capture d'écran (bin/jumphp shot) au moins en fr, de, ru, ja pour vérifier l'affichage.
7. Technique
Résolution virtuelle 480×270 (16:9, assez de place pour la carte + un HUD lisible en CJK), scale: 3, pwa: true, pause_scene défini.
Commandes : souris (clic sur un emplacement → menu radial de construction / amélioration), tactile (même logique, cibles d'au moins 24 px virtuels), clavier / manette (curseur qui saute d'emplacement en emplacement, confirm / back). Les trois doivent permettre de finir une partie.
Données hors du code : tours, ennemis, vagues, cartes et camps dans des fichiers YAML (assets/data/), lus au démarrage. Équilibrer = modifier des données, pas du code.
Cartes : grille de tuiles (TileMap + ArrayLoader ou Tiled .tmj), chemins en waypoints, emplacements listés.
Logique de jeu (dégâts, ciblage, vagues, économie) dans des classes PHP pures, sans SDL, testables unitairement. Les scènes ne font qu'orchestrer et dessiner.
Vitesse de jeu ×1 / ×2 / ×3 via un multiplicateur de $dt (pas de pas de temps variable dans la simulation : pas fixe, plusieurs pas par frame en accéléré).
Graphismes : pixel art généré dans le code (createTextureFromPixels), une palette par camp ; sons générés par Synth. Pas d'asset obligatoire, mais la structure doit permettre de remplacer par des PNG plus tard.
Sauvegarde (storage) : progression par camp, étoiles par carte, options.
Scènes attendues : TitleScene, FactionSelectScene, MapSelectScene, PlayScene, PauseScene (overlay), VictoryScene / DefeatScene (overlay), OptionsScene.
smoke de game.yaml : une partie qui se joue seule (un bot construit et améliore des tours).
8. Tests de jeu à écrire au fil de l'eau

En plus des tests unitaires de la logique, au minimum :

un ennemi suit le chemin et retire une vie en atteignant le bastion ;
une tour construite tire sur un ennemi à portée et le tue ; l'or augmente ;
un ennemi blindé résiste aux dégâts physiques, pas aux dégâts perçants ;
ralentissement et brûlure agissent pendant leur durée puis s'arrêtent ;
une volante n'est pas touchée par une tour qui ne vise que le sol ;
revente, amélioration, spécialisation ;
appeler la vague plus tôt donne le bonus ;
une partie complète de la première carte est gagnable par le bot, et perdue sans aucune tour ;
choisir les Orcs fait venir l'Alliance, choisir un autre camp fait venir la Horde ;
la progression est sauvegardée et rechargée ;
complétude des traductions (§6).
9. Ce que je veux que tu fasses

Avancer jalon par jalon. À chaque jalon : code, tests verts, captures d'écran ouvertes et vérifiées, games/last-bastion/AGENTS.md à jour. Montre-moi les captures et résume ce qui reste avant de passer au jalon suivant. Pose-moi une question quand un choix de game design n'est pas tranché ici, plutôt que d'inventer.

---

## The JumPHP playground

This project was made on the JumPHP playground: the game's page is https://jumphp.net/elfs-orcs-tower-defense
(category: Stratégie).

- The game is `games/elfs-orcs-tower-defense/`. Read `AGENTS.md` (the JumPHP contract) first; replace the skeleton with the game
  described above; follow the definition of done (tests, screenshots you look at, browser build).
- **Develop with game tests, visually**: for each mechanic, as you write it, a test in `games/elfs-orcs-tower-defense/tests/` that
  extends `JumPHP\Testing\GameTestCase` and plays it ("the player jumps onto the platform", "the enemy dies
  when hit"; AGENTS.md "Game tests"); `bin/jumphp test elfs-orcs-tower-defense` after each change. The user watches them play live
  in Claude Code (the `jumphp-live` pane) and, after a push, replays each test filmed in the studio (MCP
  `run_tests`).
- **Publishing**: every push to `main` is online within a minute (GitHub webhook). The MCP tool
  `sync_project` (server `jumphp`) publishes at once and returns the errors: a commit with a PHP syntax error
  is not published (the previous version stays online).
- The playground loads the game file by file with its shared engine: Composer packages other than
  `jumphp/engine` are not available there.
- **The game's home page**: make `games/elfs-orcs-tower-defense/site/` (AGENTS.md section 13: themed with the game's own graphics,
  SEO, a link to `play/`); it is the game's page on the playground. Also `games/elfs-orcs-tower-defense/assets/thumbnail.png`.
- When the game is done: MCP `update_project` with `listed: true` shows it in the playground's directory.