<?php

namespace App\Ui;

use App\View3d\MapModel;
use App\View3d\Mesh;
use App\View3d\Props;
use App\View3d\Rig;
use JumPHP\Gl\WebGl;

/**
 * The battle's 3D layer (WebGL in the browser, OpenGL on the desktop): one lit shader with a little fog, the map
 * built once as one buffer, and every tower, creature and projectile a model (one buffer each, built the first time
 * it is asked for) drawn with its own matrix. The 2D scene draws over it: the layer shows wherever the 2D picture
 * is the key colour (KEY), so overlays must be opaque or black-translucent.
 *
 * Per frame: begin($rig), then draw() / disc() / ring() calls, opaque things first and glows last.
 */
final class World3d
{
    /** The colour the 2D picture is cleared with where the 3D shows. */
    public const KEY = [255, 0, 255];

    private const VERTEX = <<<'GLSL'
        attribute vec3 a_pos;
        attribute vec3 a_normal;
        attribute vec3 a_color;
        uniform mat4 u_vp;
        uniform mat4 u_model;
        uniform float u_outline;
        uniform float u_push;
        uniform mediump vec3 u_eye;
        varying vec3 v_color;
        varying vec3 v_normal;
        varying vec3 v_world;
        varying float v_local;
        varying float v_dist;
        void main() {
            vec4 w = u_model * vec4(a_pos, 1.0);
            vec3 nw = normalize((u_model * vec4(a_normal, 0.0)).xyz);
            // the ink: pushed out along the normal, then away from the eye so the model itself stays in front
            w.xyz += nw * u_outline + normalize(w.xyz - u_eye) * u_push;
            gl_Position = u_vp * w;
            v_normal = nw;
            v_color = a_color;
            v_world = w.xyz;
            v_local = a_pos.y;
            v_dist = gl_Position.w;
        }
        GLSL;

    // Comic-book look: the light is cut into three flat bands (shade, mid, sun) with sharp edges, the shade carries a
    // halftone dot pattern, colours are punchy, a hard pale rim, and every model gets an ink outline (a second draw of
    // the model pushed out along its normals, drawn on its back faces only: u_ink > 0).
    private const FRAGMENT = <<<'GLSL'
        precision mediump float;
        uniform vec3 u_light;
        uniform vec3 u_tint;
        uniform vec3 u_eye;
        uniform float u_alpha;
        uniform float u_emit;
        uniform float u_ao;
        uniform float u_ink;
        uniform vec3 u_fog;
        uniform vec2 u_fogRange;
        varying vec3 v_color;
        varying vec3 v_normal;
        varying vec3 v_world;
        varying float v_local;
        varying float v_dist;
        void main() {
            float f = clamp((v_dist - u_fogRange.x) / (u_fogRange.y - u_fogRange.x), 0.0, 1.0);
            if (u_ink > 0.5) {
                gl_FragColor = vec4(mix(vec3(0.07, 0.05, 0.12), u_fog, f * 0.6), 1.0);
                return;
            }
            vec3 n = normalize(v_normal);
            float d = dot(n, u_light) * 0.5 + 0.5;
            float band = smoothstep(0.37, 0.41, d) * 0.5 + smoothstep(0.70, 0.74, d) * 0.5;
            vec3 light = mix(vec3(0.50, 0.52, 0.86), vec3(1.18, 1.06, 0.88), band);
            float foot = mix(1.0, mix(0.60, 1.0, smoothstep(0.0, 8.0, v_local)), u_ao);
            vec3 view = normalize(u_eye - v_world);
            float rim = smoothstep(0.62, 0.70, 1.0 - clamp(dot(n, view), 0.0, 1.0)) * 0.20 * u_ao;
            vec3 base = v_color * u_tint;
            vec3 lit = base * light * foot + rim * vec3(0.9, 0.95, 1.0);
            vec2 p = mod(gl_FragCoord.xy, 8.0) - 4.0;
            float dots = (1.0 - smoothstep(1.4, 2.0, length(p))) * (1.0 - smoothstep(0.0, 0.5, band));
            lit *= 1.0 - 0.20 * dots * u_ao;
            float g = dot(lit, vec3(0.299, 0.587, 0.114));
            lit = mix(vec3(g), lit, 1.28);
            lit = mix(lit, base * 1.3, u_emit);
            gl_FragColor = vec4(mix(lit, u_fog, f * 0.7), u_alpha);
        }
        GLSL;

    /** How far the ink outline sticks out, in world units. */
    private const OUTLINE = 1.4;

    private ?WebGl $gl = null;
    private int $prog = 0;
    /** @var array<string, array{0: int, 1: int}> model key => [buffer, vertices] */
    private array $models = [];
    /** @var array{key: string, buffer: int, count: int, fog: array<int, float>}|null */
    private ?array $scene = null;
    private bool $outlines = true;
    /** @var array{0: float, 1: float, 2: float} */
    private array $light;

    public function __construct()
    {
        $len = sqrt(0.42 ** 2 + 0.78 ** 2 + 0.46 ** 2);
        $this->light = [-0.42 / $len, 0.78 / $len, 0.46 / $len];
    }

    /** Turns the 3D layer on (below the 2D, window resolution, smoothed). False without WebGL / OpenGL. */
    public function boot(WebGl $gl): bool
    {
        if (!$gl->isAvailable()) {
            return false;
        }
        $this->gl = $gl;
        if ($this->prog === 0) {
            $this->prog = $gl->program(self::VERTEX, self::FRAGMENT);
        }
        if ($this->prog === 0) {
            return false;
        }
        $gl->setLayer('below', 'native', self::KEY);
        $gl->setSupersample(2);
        return true;
    }

    /** Builds the ground of a map (once per map and side's colours). */
    public function loadMap(string $id, array $map, array $colors): void
    {
        $key = $id . '|' . ($colors['main'] ?? '');
        if ($this->gl === null || ($this->scene['key'] ?? '') === $key) {
            return;
        }
        if ($this->scene !== null) {
            $this->gl->delete($this->scene['buffer']);
        }
        ['mesh' => $mesh, 'fog' => $fog] = MapModel::build($map, $colors);
        $this->scene = ['key' => $key, 'buffer' => $this->gl->buffer($mesh->v), 'count' => $mesh->vertexCount(), 'fog' => $fog];
    }

    /** Starts a frame: clears, then draws the ground with the camera and the light. */
    public function begin(Rig $rig): void
    {
        if ($this->gl === null || $this->scene === null) {
            return;
        }
        $fog = $this->scene['fog'];
        $this->gl->clear([(int)($fog[0] * 255), (int)($fog[1] * 255), (int)($fog[2] * 255), 255]);
        $this->gl->draw($this->prog, $this->options($this->scene['buffer'], $this->scene['count'], [
            'u_vp' => $rig->viewProj(),
            'u_light' => $this->light,
            'u_fog' => $fog,
            'u_fogRange' => [650.0, 1500.0],
            'u_eye' => $rig->eye(),
            'u_model' => self::matrix(0.0, 0.0, 0.0, 0.0, 1.0, 1.0, 1.0),
            'u_tint' => [1.0, 1.0, 1.0],
            'u_alpha' => 1.0,
            'u_emit' => 0.0,
            'u_ao' => 0.0,
            'u_ink' => 0.0,
            'u_outline' => 0.0,
            'u_push' => 0.0,
        ], false));
    }

    /**
     * A model (built by $build the first time) at ($x, $y, $z), turned by $yaw about y, scaled.
     * @param callable(): Mesh $build
     * @param float|array{0: float, 1: float, 2: float} $scale
     * @param array{0: float, 1: float, 2: float} $tint multiplies the colours (above 1: brighter)
     */
    public function draw(string $key, callable $build, float $x, float $y, float $z, float $yaw = 0.0, float|array $scale = 1.0,
                         array $tint = [1.0, 1.0, 1.0], float $alpha = 1.0, float $emit = 0.0, float $ao = 1.0): void
    {
        if ($this->gl === null || $this->scene === null) {
            return;
        }
        [$buffer, $count] = $this->model($key, $build);
        if ($count === 0) {
            return;
        }
        [$sx, $sy, $sz] = is_array($scale) ? $scale : [$scale, $scale, $scale];
        $model = self::matrix($x, $y, $z, $yaw, $sx, $sy, $sz);
        $this->gl->draw($this->prog, $this->options($buffer, $count, [
            'u_model' => $model,
            'u_tint' => $tint,
            'u_alpha' => $alpha,
            'u_emit' => $emit,
            'u_ao' => $ao,
            'u_ink' => 0.0,
            'u_outline' => 0.0,
            'u_push' => 0.0,
        ], $alpha < 1.0));
        if ($ao > 0.0 && $alpha >= 1.0 && $this->outlines) {
            // the ink: the same model, pushed out, only its back faces
            $this->gl->draw($this->prog, $this->options($buffer, $count, [
                'u_model' => $model,
                'u_ink' => 1.0,
                'u_outline' => self::OUTLINE,
                'u_push' => self::OUTLINE * 1.6,
            ], false));
        }
    }

    /** Ink outlines on or off (on by default). */
    public function setOutlines(bool $on): void
    {
        $this->outlines = $on;
    }

    /** A flat disc on the ground, $r wide (shadows, ranges, blasts). */
    public function disc(float $x, float $z, float $r, array $tint, float $alpha, float $y = 0.6, float $emit = 0.0): void
    {
        $this->draw('unit:disc', static fn () => Props::disc(), $x, $y, $z, 0.0, [$r, 1.0, $r], $tint, $alpha, $emit, 0.0);
    }

    /** A thin ring on the ground, $r wide. */
    public function ring(float $x, float $z, float $r, array $tint, float $alpha, float $y = 0.8): void
    {
        $this->draw('unit:ring', static fn () => Props::ring(), $x, $y, $z, 0.0, [$r, 1.0, $r], $tint, $alpha, 1.0, 0.0);
    }

    /** A ball in the air, $r wide. */
    public function ball(float $x, float $y, float $z, float $r, array $tint, float $alpha = 1.0, float $emit = 1.0): void
    {
        $this->draw('unit:ball', static fn () => Props::ball(), $x, $y, $z, 0.0, $r, $tint, $alpha, $emit, 0.0);
    }

    /** @return array{0: int, 1: int} */
    private function model(string $key, callable $build): array
    {
        if (!isset($this->models[$key])) {
            /** @var Mesh $mesh */
            $mesh = $build();
            $this->models[$key] = [$mesh->vertexCount() > 0 ? $this->gl->buffer($mesh->v) : 0, $mesh->vertexCount()];
        }
        return $this->models[$key];
    }

    /** @return array<string, mixed> */
    private function options(int $buffer, int $count, array $uniforms, bool $blend, ?string $cull = null): array
    {
        return [
            'attributes' => ['a_pos' => [$buffer, 3, 9, 0], 'a_normal' => [$buffer, 3, 9, 3], 'a_color' => [$buffer, 3, 9, 6]],
            'count' => $count,
            'uniforms' => $uniforms,
            'blend' => $blend,
            'cull' => $cull,
        ];
    }

    /** x, y, z move, $yaw turns about y (+x towards -z), then the scale: 16 floats, column-major. @return float[] */
    private static function matrix(float $x, float $y, float $z, float $yaw, float $sx, float $sy, float $sz): array
    {
        [$c, $s] = [cos($yaw), sin($yaw)];
        return [$sx * $c, 0.0, -$sx * $s, 0.0, 0.0, $sy, 0.0, 0.0, $sz * $s, 0.0, $sz * $c, 0.0, $x, $y, $z, 1.0];
    }
}
