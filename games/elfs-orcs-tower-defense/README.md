# Elfs & Orcs Tower Defense

Tower defense rétro en pixel art (JumPHP) : bâtissez des tours elfiques sur les plateformes de pierre et tenez le
col de Val-Épine face à la horde orque. Depuis la racine du dépôt :

```bash
bin/jumphp install elfs-orcs-tower-defense   # dépendances
bin/jumphp run elfs-orcs-tower-defense       # jouer sur le bureau
bin/jumphp test elfs-orcs-tower-defense      # tests unitaires, tests de jeu, test automatique sans écran
bin/jumphp build elfs-orcs-tower-defense     # version navigateur -> dist/web
bin/jumphp serve elfs-orcs-tower-defense     # http://localhost:8080
```

Commandes :
- souris / tactile : cliquez une plateforme de pierre, puis un choix de l'anneau (un clic pour voir, un second pour valider) ;
- clavier / manette : flèches pour aller d'une plateforme à l'autre, Entrée pour ouvrir l'anneau, flèches pour choisir,
  Entrée pour valider, Échap pour fermer ;
- N : appeler la vague suivante (bonus d'or), F : vitesse ×1 / ×2 / ×3, Échap / P : pause.

Polices (licence OFL), dans `assets/fonts/` : `Nunito.ttf` (latin, cyrillique) et `unifont.otf` (japonais, chinois,
coréen). Sans elles, la police intégrée du moteur sert (majuscules sans accents).
