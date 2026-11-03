<?php

/**
 * Balance report: the bot plays the map with each side; lives and gold at the start of each wave, and the result.
 *   bin/jumphp php games/elfs-orcs-tower-defense tools/balance.php [map|all] [side...]
 * Add "hard" as a side argument for hard mode.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Td\Battle;
use App\Td\Bot;
use App\Td\GameData;

$data = new GameData(__DIR__ . '/../assets/data');
$maps = ($argv[1] ?? 'all') === 'all' ? $data->mapIds() : [$argv[1]];
$args = array_slice($argv, 2);
$hard = in_array('hard', $args, true);
$sides = array_values(array_diff($args, ['hard'])) ?: $data->factionIds();
foreach ($maps as $map) {
echo "== $map", $hard ? ' (hard)' : '', "\n";
foreach ($sides as $side) {
    $battle = new Battle($data, $map, $side, 1, $hard);
    $bot = new Bot($battle, false, true);
    $line = [];
    $wave = 0;
    $leaks = [];
    for ($t = 0; $t < 60 * 60 * 12 && !$battle->isOver(); $t++) {
        if ($t % 30 === 0) {
            $bot->play();
        }
        $battle->step();
        foreach ($battle->events as $event) {
            if ($event['type'] === 'leak') {
                $leaks[$event['enemy']] = ($leaks[$event['enemy']] ?? 0) + 1;
            }
        }
        $battle->events = [];
        if ($battle->waveIndex !== $wave) {
            $wave = $battle->waveIndex;
            $line[] = sprintf('w%d:%d/%dg', $wave, $battle->lives, $battle->gold);
        }
    }
    printf("%-11s %-5s lives %2d  towers %2d  left in plan %2d  %s\n", $side, $battle->state, $battle->lives,
        count($battle->towers()), $bot->plannedActions(), implode(' ', $line));
    $built = array_count_values(array_map(static fn ($t) => $t->type . ':' . $t->level, $battle->towers()));
    echo '            through: ', json_encode($leaks), '  built: ', json_encode($built), "\n";
}
}
