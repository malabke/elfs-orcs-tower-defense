<?php

namespace App\Scene;

use App\Ui\World3d;
use App\View3d\Mesh;
use App\View3d\Rig;
use App\View3d\Towers;
use App\View3d\Units;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * A photo studio, not a screen of the game: one creature or one tower in 3D, alone, in three-quarter view, over a
 * plain background. tests/PortraitShotsTest.php photographs every model here over black then white, takes the
 * background out and writes the interface's portraits (assets/sprites/portraits/). Needs WebGL / OpenGL.
 */
final class PortraitScene extends AbstractScene
{
    /** How tall (units) a model stands in the picture: the camera is far (Rig::NEAR), so it is scaled up. */
    private const SIZE = 64.0;

    private bool $use3d = false;
    private ?Rig $rig = null;
    /** @var array{kind: string, sprite: string, level: int, spec: ?int, flag: string, background: int[]} */
    private array $pose = ['kind' => 'unit', 'sprite' => 'grunt', 'level' => 1, 'spec' => null, 'flag' => '#3fa860', 'background' => [0, 0, 0]];

    public function __construct(private World3d $world)
    {
    }

    public function enter(array $params = []): void
    {
        $this->use3d = $this->world->boot($this->engine->gl());
        $this->rig = new Rig((float)$this->width(), (float)$this->height());
        $this->rig->orbit([0.0, self::SIZE * 0.5, 0.0], 260.0, 16.0, 0.5);
    }

    public function exit(): void
    {
        if ($this->use3d) {
            $this->world->shutdown($this->engine->gl());
            $this->use3d = false;
        }
    }

    public function isReady(): bool
    {
        return $this->use3d;
    }

    /**
     * What to photograph next: a creature ($kind 'unit', $sprite of Units::KINDS) or a tower ('tower', $sprite of
     * Towers::SPRITES, its level and specialization, its side's flag), over $background.
     * @param int[] $background
     */
    public function pose(string $kind, string $sprite, array $background, int $level = 1, ?int $spec = null, string $flag = '#3fa860'): void
    {
        $this->pose = ['kind' => $kind, 'sprite' => $sprite, 'level' => $level, 'spec' => $spec, 'flag' => $flag, 'background' => $background];
    }

    public function update(float $dt): void
    {
    }

    public function render(Renderer $r): void
    {
        $r->clear($this->world->clearColour());
        if (!$this->use3d || $this->rig === null) {
            return;
        }
        ['kind' => $kind, 'sprite' => $sprite, 'level' => $level, 'spec' => $spec, 'flag' => $flag] = $this->pose;
        $this->world->studio($this->rig, $this->pose['background']);
        if ($kind === 'tower') {
            $key = "t:$sprite:$level:" . ($spec ?? 'n') . ":$flag";
            $build = static fn () => Towers::build($sprite, $level, $spec, $flag);
            $yaw = 0.0;
        } else {
            $key = "u:$sprite:0";
            $build = static fn () => Units::build($sprite, 0);
            // creatures face +x: turned towards the camera, a little to the right; the long ones (mounts, the ram)
            // more from the side, where they read best
            $yaw = in_array(Units::KINDS[$sprite]['k'] ?? 'human', ['beast', 'ram'], true) ? -0.25 : -1.0;
        }
        // whatever its shape (a bat is wide and flat, a tower tall), it fills the same box, its middle at the target
        [$min, $max] = $this->bounds[$key] ??= self::bounds($build());
        $k = self::SIZE / max($max[1] - $min[1], ($max[0] - $min[0]) * 0.75, ($max[2] - $min[2]) * 0.75, 1.0);
        [$c, $s] = [cos($yaw), sin($yaw)];
        $mx = ($min[0] + $max[0]) / 2 * $k;
        $mz = ($min[2] + $max[2]) / 2 * $k;
        // the model's middle, turned as the model is (+x towards -z), brought to the origin
        $this->world->draw($key, $build, -($mx * $c + $mz * $s), self::SIZE * 0.5 - ($min[1] + $max[1]) / 2 * $k, -(-$mx * $s + $mz * $c), $yaw, $k);
    }

    /** @var array<string, array{0: float[], 1: float[]}> */
    private array $bounds = [];

    /** The box around a model's vertices: [[minX, minY, minZ], [maxX, maxY, maxZ]]. @return array{0: float[], 1: float[]} */
    private static function bounds(Mesh $mesh): array
    {
        $min = [INF, INF, INF];
        $max = [-INF, -INF, -INF];
        $v = $mesh->v;
        for ($i = 0, $n = count($v); $i < $n; $i += Mesh::STRIDE) {
            for ($a = 0; $a < 3; $a++) {
                $min[$a] = min($min[$a], $v[$i + $a]);
                $max[$a] = max($max[$a], $v[$i + $a]);
            }
        }
        return $min[0] === INF ? [[0.0, 0.0, 0.0], [1.0, 1.0, 1.0]] : [$min, $max];
    }
}
