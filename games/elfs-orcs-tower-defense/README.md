# Elfs & Orcs Tower Defense

Tower defense en 3D peinte, façon livre d'images (JumPHP) : choisissez votre peuple parmi cinq (Elfes, Nains,
Peuple des chevaux, Petits-hommes, Orcs), bâtissez vos tours sur les plateformes de pierre et tenez face à la horde
orque, ou, côté orc, face à l'Alliance des peuples libres. Depuis la racine du dépôt :

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

Polices (licence OFL), dans `assets/fonts/` : `Nunito.ttf` (latin, cyrillique, avec un moteur qui dessine la 2D en
haute définition), `PressStart2P-Regular.ttf` (la même chose en pixels, sinon) et `unifont.otf` (japonais, chinois,
coréen). Sans elles, la police intégrée du moteur sert (majuscules sans accents).
