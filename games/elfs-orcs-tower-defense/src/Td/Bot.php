<?php

namespace App\Td;

/**
 * A player that builds and upgrades towers by itself: the title screen's demo, the smoke test, balance tests.
 * It ranks the slots by how much road their towers see, builds the side's bot list (factions.yaml) on the best
 * ones, then alternates one new tower and upgrades of the weakest towers. One action at a time, when it can pay.
 */
final class Bot
{
    /** @var list<array{0: string, 1: int, 2?: string}> what it will do: [build, slot, type] | [upgrade, slot] */
    private array $plan = [];

    /**
     * @param bool $callWaves sends each wave as soon as it can (the demo: more action)
     * @param bool $usePower casts the side's power on the enemy nearest to the bastion when it is ready
     */
    public function __construct(private Battle $battle, private bool $callWaves = false, private bool $usePower = false)
    {
        $this->plan = $this->makePlan();
    }

    /** One decision (call it every step or so). */
    public function play(): void
    {
        if ($this->usePower && $this->battle->powerReady()) {
            $front = null;
            foreach ($this->battle->enemies as $enemy) {
                if ($enemy->isTargetable() && ($front === null || $enemy->remaining() < $front->remaining())) {
                    $front = $enemy;
                }
            }
            if ($front !== null) {
                $this->battle->castPower($front->x, $front->y);
            }
        }
        if ($this->callWaves && $this->battle->canCallWave() && $this->battle->enemies === []) {
            $this->battle->callNextWave();
        }
        while ($this->plan !== [] && !$this->battle->isOver()) {
            $action = $this->plan[0];
            $slot = $action[1];
            $tower = $this->battle->tower($slot);
            if ($action[0] === 'build') {
                if ($tower !== null) {
                    array_shift($this->plan);   // already built
                    continue;
                }
                if (!$this->battle->build($slot, $action[2])) {
                    return;                     // not enough gold yet
                }
            } elseif ($tower === null || $tower->spec !== null) {
                array_shift($this->plan);
                continue;
            } elseif ($tower->canSpecialize()) {
                if (!$this->battle->specialize($slot, $tower->specNames()[0])) {
                    return;
                }
            } elseif (!$this->battle->upgrade($slot)) {
                return;
            }
            array_shift($this->plan);
        }
    }

    public function plannedActions(): int
    {
        return count($this->plan);
    }

    /** @return list<array> */
    private function makePlan(): array
    {
        $types = $this->battle->data->faction($this->battle->faction)['bot'] ?? $this->battle->towerTypes();
        $slots = $this->rankedSlots();
        $plan = [];
        $levels = [];
        $built = [];
        foreach ($slots as $n => $slot) {
            $plan[] = ['build', $slot, $types[$n % count($types)]];
            $built[] = $slot;
            $levels[$slot] = 1;
            if ($n < 2) {
                continue;
            }
            // after the first three: two upgrades of the weakest of the best towers per new one
            for ($k = 0; $k < 2; $k++) {
                $weakest = null;
                foreach ($built as $b) {
                    if ($levels[$b] < 4 && ($weakest === null || $levels[$b] < $levels[$weakest])) {
                        $weakest = $b;
                    }
                }
                if ($weakest !== null) {
                    $plan[] = ['upgrade', $weakest];
                    $levels[$weakest]++;
                }
            }
        }
        // then everything to the top
        foreach ($built as $b) {
            for (; $levels[$b] < 4; $levels[$b]++) {
                $plan[] = ['upgrade', $b];
            }
        }
        return $plan;
    }

    /** The slots, the one whose tower would see the most road first. @return list<int> */
    private function rankedSlots(): array
    {
        $scores = [];
        foreach ($this->battle->slots as $i => $slot) {
            $seen = 0;
            foreach ($this->battle->paths as $path) {
                for ($d = 0.0; $d < $path->length; $d += 8) {
                    [$x, $y] = $path->at($d);
                    if (($x - $slot['x']) ** 2 + ($y - $slot['y']) ** 2 <= 76 * 76) {
                        $seen++;
                    }
                }
            }
            $scores[$i] = $seen;
        }
        arsort($scores);
        return array_keys($scores);
    }
}
