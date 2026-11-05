<?php

namespace App\Ui;

use App\View3d\GroundMap;
use App\View3d\MapModel;
use App\View3d\Mesh;
use App\View3d\Props;
use App\View3d\Rig;
use JumPHP\Gl\WebGl;

/**
 * The battle's 3D layer (WebGL in the browser, OpenGL on the desktop), painted like a storybook: one shader for
 * everything, the map built once as one buffer, and every tower, creature and projectile a model (one buffer each,
 * built the first time it is asked for) drawn with its own matrix. The 2D scene draws over it, composed by its
 * alpha: scenes clear the 2D with clearColour() (transparent) and whatever they draw over it (smooth text,
 * translucent panels of any colour) lies over the 3D as it is. The layer is at the view texture's resolution: with
 * the renderer's HD (game.yaml `hd: auto`) that is the window's. On an engine that cannot compose by alpha (the
 * playground's shared engine before it has this project's changes: see Hd) the 3D shows through the key colour KEY
 * instead: clearColour() is KEY, veil() turns translucent colours black, Paint::$keyed tells the interface.
 *
 * The shader reads the vertex's material (Mesh::$mat): the ground is painted from the map's GroundMap texture
 * (grass patches, the road with a wobbly edge, water with glints, soft shadows) and a tiling noise texture
 * (assets/textures/noise.png: patches, grain, clouds); leaves and grass sway in the wind and let the sun through;
 * specks of light float; soft shadows fade out at their edge. Light: a warm sun that wraps softly, a cool sky,
 * a warm bounce from the ground, cloud shadows drifting over everything, haze in the distance.
 *
 * Per frame: begin($rig, $time), then shadow() first, then draw() / disc() / ring() / ball(), glows last.
 */
final class World3d
{
    /** What the 2D picture is cleared with where the 3D shows: transparent (alpha composing). */
    public const CLEAR = [0, 0, 0, 0];
    /** ... or this key colour, on an engine without alpha composing. */
    public const KEY = [255, 0, 255];

    private const VERTEX = <<<'GLSL'
        attribute vec3 a_pos;
        attribute vec3 a_normal;
        attribute vec3 a_color;
        attribute float a_mat;
        uniform mat4 u_vp;
        uniform mat4 u_model;
        uniform float u_time;
        uniform vec3 u_eye;
        uniform vec4 u_rect;
        uniform vec2 u_cloud;
        varying vec3 v_color;
        varying vec3 v_normal;
        varying vec3 v_view;
        varying vec4 v_uv;
        varying vec4 v_sky;
        varying vec4 v_misc;
        void main() {
            vec4 w = u_model * vec4(a_pos, 1.0);
            float extra = length(a_pos.xz);
            if (a_mat > 0.01 && a_mat <= 1.0) {
                // leaves, grass: the wind, more at the top
                float ph = u_time * 1.7 + w.x * 0.05 + w.z * 0.037;
                float sway = (sin(ph) + 0.35 * sin(ph * 2.7 + 1.3)) * a_mat * max(a_pos.y, 0.0) * 0.11;
                w.x += sway;
                w.z += sway * 0.35;
            } else if (a_mat > 3.5 && a_mat < 5.0) {
                // a speck of light drifting about
                float s = (a_mat - 4.0) * 6.2831;
                w.xyz += vec3(sin(u_time * 0.31 + s) * 10.0, sin(u_time * 0.47 + s * 2.0) * 4.0, cos(u_time * 0.23 + s * 3.0) * 8.0);
                extra = 0.55 + 0.45 * sin(u_time * 2.3 + s * 5.0);
            }
            gl_Position = u_vp * w;
            v_normal = (u_model * vec4(a_normal, 0.0)).xyz;
            v_color = a_color;
            v_view = u_eye - w.xyz;
            v_uv = vec4((w.xz - u_rect.xy) * u_rect.zw, w.xz * 0.01);
            v_sky = vec4(w.xz * (1.0 / 700.0) + u_cloud, gl_Position.xy / gl_Position.w);
            v_misc = vec4(a_pos.y, extra, a_mat, gl_Position.w);
        }
        GLSL;

    private const FRAGMENT = <<<'GLSL'
        #ifdef GL_FRAGMENT_PRECISION_HIGH
        precision highp float;
        #else
        precision mediump float;
        #endif
        uniform vec3 u_light;
        uniform vec3 u_sun;
        uniform vec3 u_sky;
        uniform vec3 u_bounce;
        uniform vec3 u_tint;
        uniform float u_alpha;
        uniform float u_emit;
        uniform float u_ao;
        uniform vec3 u_fog;
        uniform vec2 u_fogRange;
        uniform vec3 u_grass0;
        uniform vec3 u_grass1;
        uniform vec3 u_grass2;
        uniform vec3 u_dirt0;
        uniform vec3 u_dirt1;
        uniform vec3 u_water0;
        uniform vec3 u_water1;
        uniform float u_lava;
        uniform vec2 u_flow;
        uniform sampler2D u_ground;
        uniform sampler2D u_noise;
        uniform vec4 u_clip;
        varying vec3 v_color;
        varying vec3 v_normal;
        varying vec3 v_view;
        varying vec4 v_uv;
        varying vec4 v_sky;
        varying vec4 v_misc;
        void main() {
            // only inside the frame of a vignette (the whole screen otherwise)
            if (v_sky.z < u_clip.x || v_sky.w < u_clip.y || v_sky.z > u_clip.z || v_sky.w > u_clip.w) {
                discard;
            }
            float mat = v_misc.z;
            if (mat > 5.5) {
                // a soft shadow: its colour (u_tint), fading out towards its edge
                gl_FragColor = vec4(u_tint, u_alpha * (1.0 - smoothstep(0.2, 1.0, v_misc.y)));
                return;
            }
            vec3 n = normalize(v_normal);
            vec3 base = v_color * u_tint;
            float sunVis = 1.0;
            float open = 1.0;
            float wet = 0.0;
            vec3 glow = vec3(0.0);
            if (mat > 1.5 && mat < 2.5) {
                vec4 g = texture2D(u_ground, v_uv.xy);                // three sizes of the noise, turned so that its grid does not show
                vec2 q = v_uv.zw;
                vec4 nBig = texture2D(u_noise, vec2(q.x * 0.604 - q.y * 0.354, q.x * 0.354 + q.y * 0.604));
                vec4 nMid = texture2D(u_noise, vec2(q.x * 1.678 + q.y * 2.115, q.y * 1.678 - q.x * 2.115));
                vec4 nFine = texture2D(u_noise, q * 9.0);
                // grass: soft patches of a deep and a light green, sunny tips, a brush grain
                vec3 grass = mix(u_grass0, u_grass1, smoothstep(0.25, 0.75, nBig.r * 0.7 + nMid.g * 0.3));
                grass = mix(grass, u_grass2, smoothstep(0.6, 0.9, nMid.r) * 0.5);
                grass *= 0.9 + 0.2 * nFine.b;
                // the road: its edge wobbles, lighter in the middle, a trodden darker band beside it
                float r = g.r + (nMid.b - 0.5) * 0.24 + (nFine.g - 0.5) * 0.08;
                float road = smoothstep(0.46, 0.54, r);
                vec3 dirt = mix(u_dirt1, u_dirt0, clamp(smoothstep(0.5, 0.95, g.r) * 0.7 + nMid.g * 0.35, 0.0, 1.0));
                dirt *= 0.93 + 0.14 * nFine.r;
                grass *= 1.0 - 0.18 * smoothstep(0.3, 0.47, r) * (1.0 - road);
                base = mix(grass, dirt, road) * v_color;
                // water (lava on burnt land): a dark wet shore, a light edge, glints that move
                float wv = g.g + (nMid.r - 0.5) * 0.22;
                float water = smoothstep(0.48, 0.56, wv);
                base *= 1.0 - 0.28 * smoothstep(0.28, 0.5, wv) * (1.0 - water) * (1.0 - u_lava);
                // deep in the middle, clear in the shallows, glints that move, a light line along the shore
                vec3 wc = mix(u_water0, u_water1, clamp(nMid.g * 0.3 + (1.0 - smoothstep(0.55, 0.95, wv)) * 0.45, 0.0, 1.0));
                float glint = texture2D(u_noise, v_uv.zw * 6.0 + u_flow).b * texture2D(u_noise, v_uv.zw * 3.7 - u_flow * 0.7).g;
                wc += vec3(smoothstep(0.42, 0.6, glint)) * 0.4;
                wc = mix(wc, u_water1 * 1.15, (1.0 - smoothstep(0.0, 0.3, water - 0.12)) * 0.4);
                base = mix(base, wc, water);
                wet = water * (1.0 - u_lava);
                glow = u_lava * water * wc * 0.8;
                sunVis = g.b;
                open = g.a;
            }
            // clouds passing over the sun
            float cloud = mix(0.62, 1.0, smoothstep(0.3, 0.62, texture2D(u_noise, v_sky.xy).a));
            float ndl = dot(n, u_light);
            float direct = clamp((ndl + 0.3) / 1.3, 0.0, 1.0) * sunVis * cloud;
            vec3 light = mix(u_bounce, u_sky, n.y * 0.5 + 0.5) * open + u_sun * direct;            if (mat > 0.01 && mat <= 1.0) {
                // leaves let the sun through
                light += u_sun * 0.25 * clamp(0.25 - ndl, 0.0, 1.0) * cloud;
            }
            float foot = mix(1.0, mix(0.58, 1.0, smoothstep(0.0, 7.0, v_misc.x)), u_ao);
            vec3 col = base * light * foot;
            float rim = pow(1.0 - clamp(dot(n, normalize(v_view)), 0.0, 1.0), 3.0);
            col += u_sky * rim * 0.2 * u_ao;
            col += wet * u_sky * 0.16 + glow;
            if (mat > 3.5 && mat < 5.0) {
                col = base * v_misc.y;
            }
            col = mix(col, base * 1.3, u_emit);
            // painted grading: a little more colour, warm lights and cool shadows
            float lum = dot(col, vec3(0.299, 0.587, 0.114));
            col = mix(vec3(lum), col, 1.1);
            col *= mix(vec3(0.93, 0.97, 1.07), vec3(1.04, 1.0, 0.93), smoothstep(0.15, 0.85, lum));
            float f = clamp((v_misc.w - u_fogRange.x) / (u_fogRange.y - u_fogRange.x), 0.0, 1.0);
            col = mix(col, u_fog, f * 0.7);
            col *= 1.0 - 0.12 * dot(v_sky.zw, v_sky.zw);
            gl_FragColor = vec4(col, u_alpha);
        }
        GLSL;

    private const SUN_COLOUR = [0.98, 0.88, 0.70];
    private const SKY_COLOUR = [0.46, 0.56, 0.76];
    private const BOUNCE_COLOUR = [0.40, 0.38, 0.28];

    private ?WebGl $gl = null;
    private int $prog = 0;
    private int $noise = 0;
    /** @var array<string, array{0: int, 1: int}> model key => [buffer, vertices] */
    private array $models = [];
    /** @var array{key: string, buffer: int, count: int, fog: array<int, float>, texture: int, palette: array<string, array<int, float>>, lava: float}|null */
    private ?array $scene = null;
    /** @var array{0: float, 1: float, 2: float} */
    private array $light;

    public function __construct()
    {
        [$x, $y, $z] = MapModel::SUN;
        $len = sqrt($x * $x + $y * $y + $z * $z);
        $this->light = [$x / $len, $y / $len, $z / $len];
    }

    /**
     * Turns the 3D layer on (below the 2D, composed by its alpha, or through KEY on an older engine; smoothed).
     * False without WebGL / OpenGL.
     */
    public function boot(WebGl $gl): bool
    {
        if (!$gl->isAvailable()) {
            return false;
        }
        $this->gl = $gl;
        if ($this->prog === 0) {
            $this->prog = $gl->program(self::VERTEX, self::FRAGMENT);
            $this->noise = $gl->texture('assets/textures/noise.png', true, true);
        }
        if ($this->prog === 0) {
            return false;
        }
        if (Hd::alphaLayers()) {
            $gl->setLayer('below', 'view', null);
            Paint::$keyed = false;
        } else {
            $gl->setLayer('below', 'native', self::KEY);
            Paint::$keyed = true;
        }
        $gl->setSupersample(2);
        return true;
    }

    /** The 3D layer off (a scene that showed it ends). */
    public function shutdown(WebGl $gl): void
    {
        $gl->disable();
        Paint::$keyed = false;
    }

    /** What the 2D must be cleared with where the 3D shows. @return int[] */
    public function clearColour(): array
    {
        return Paint::$keyed ? self::KEY : self::CLEAR;
    }

    /** A translucent colour laid over the 3D: as it is, or black over a key-coloured layer (only black darkens it well). */
    public static function veil(array $rgba): array
    {
        return Paint::$keyed ? [0, 0, 0, $rgba[3] ?? 255] : $rgba;
    }

    /** Builds the land of a map (once per map and side's colours). */
    public function loadMap(string $id, array $map, array $colors): void
    {
        $key = $id . '|' . ($colors['main'] ?? '');
        if ($this->gl === null || ($this->scene['key'] ?? '') === $key) {
            return;
        }
        $built = MapModel::build($map, $colors);
        $mesh = $built['mesh'];
        // one buffer and one texture for the land, their contents replaced from map to map
        if ($this->scene === null) {
            $buffer = $this->gl->buffer($mesh->v);
            $texture = $this->gl->textureFromRgba(GroundMap::W, GroundMap::H, $built['ground'], true);
        } else {
            $buffer = $this->scene['buffer'];
            $texture = $this->scene['texture'];
            $this->gl->updateBuffer($buffer, $mesh->v);
            $this->gl->textureFromRgba(GroundMap::W, GroundMap::H, $built['ground'], true, $texture);
        }
        $this->scene = [
            'key' => $key,
            'buffer' => $buffer,
            'count' => $mesh->vertexCount(),
            'fog' => $built['fog'],
            'texture' => $texture,
            'palette' => $built['palette'],
            'lava' => $built['lava'] ? 1.0 : 0.0,
        ];
    }

    /** Starts a frame: clears, then draws the land with the camera, the light and the time (wind, clouds, water). */
    public function begin(Rig $rig, float $time = 0.0): void
    {
        if ($this->gl === null || $this->scene === null) {
            return;
        }
        $this->inStudio = false;
        $this->pending = [];
        $fog = $this->scene['fog'];
        [$rx, $rz, $rw, $rh] = MapModel::RECT;
        $this->gl->clear([(int)($fog[0] * 255), (int)($fog[1] * 255), (int)($fog[2] * 255), 255]);
        // the textures stay bound for every draw of the frame (no other draw binds one)
        $this->gl->draw($this->prog, $this->options($this->scene['buffer'], $this->scene['count'], [
            'u_vp' => $rig->viewProj(),
            'u_eye' => $rig->eye(),
            'u_time' => fmod($time, 3600.0),
            'u_rect' => [$rx, $rz, 1 / $rw, 1 / $rh],
            // texture offsets wrapped here: the shader's own floats may be short
            'u_cloud' => [fmod($time * 0.006, 1.0), fmod($time * 0.0025, 1.0)],
            'u_flow' => [fmod($time * 0.021, 1.0), fmod($time * 0.013, 1.0)],
            'u_light' => $this->light,
            'u_sun' => self::SUN_COLOUR,
            'u_sky' => Mesh::mix(self::SKY_COLOUR, $fog, 0.3),
            'u_bounce' => self::BOUNCE_COLOUR,
            'u_fog' => $fog,
            'u_fogRange' => [560.0, 1400.0],
            'u_lava' => $this->scene['lava'],
            'u_clip' => self::NO_CLIP,
            'u_model' => self::matrix(0.0, 0.0, 0.0, 0.0, 1.0, 1.0, 1.0),
            'u_tint' => [1.0, 1.0, 1.0],
            'u_alpha' => 1.0,
            'u_emit' => 0.0,
            'u_ao' => 0.0,
        ] + $this->scene['palette'], false, ['u_ground' => $this->scene['texture'], 'u_noise' => $this->noise]));
    }

    /**
     * Starts a frame without any land: the layer cleared with $background, the same light (no fog, no clouds).
     * For pictures of single models (PortraitScene: the portraits of the interface).
     * @param int[] $background
     */
    public function studio(Rig $rig, ?array $background, ?array $vp = null, array $clip = self::NO_CLIP): void
    {
        if ($this->gl === null) {
            return;
        }
        $this->inStudio = true;
        if ($background !== null) {
            $this->gl->clear([$background[0], $background[1], $background[2], 255]);
        }
        // the uniforms shared by the frame's draws go with its first one (begin() sends them with the land)
        $this->pending = [
            'u_clip' => $clip,
            'u_vp' => $vp ?? $rig->viewProj(),
            'u_eye' => $rig->eye(),
            'u_time' => 0.0,
            'u_rect' => [0.0, 0.0, 1.0, 1.0],
            'u_cloud' => [0.0, 0.0],
            'u_flow' => [0.0, 0.0],
            'u_light' => $this->light,
            'u_sun' => self::SUN_COLOUR,
            'u_sky' => self::SKY_COLOUR,
            'u_bounce' => self::BOUNCE_COLOUR,
            'u_fog' => self::SKY_COLOUR,
            'u_fogRange' => [90000.0, 99000.0],
            'u_lava' => 0.0,
        ];
    }

    /** u_clip that lets everything through */
    private const NO_CLIP = [-9.0, -9.0, 9.0, 9.0];

    /**
     * The 3D layer OVER the 2D, transparent where nothing is drawn: small 3D scenes on a 2D screen (the vignettes of
     * the menus). Works on every engine (no alpha composing needed). False without WebGL / OpenGL.
     */
    public function bootOver(WebGl $gl): bool
    {
        if (!$gl->isAvailable()) {
            return false;
        }
        $this->gl = $gl;
        if ($this->prog === 0) {
            $this->prog = $gl->program(self::VERTEX, self::FRAGMENT);
            $this->noise = $gl->texture('assets/textures/noise.png', true, true);
        }
        if ($this->prog === 0) {
            return false;
        }
        $gl->setLayer('above', 'native');
        Paint::$keyed = false;
        $gl->setSupersample(2);
        return true;
    }

    /**
     * Starts a vignette: a small 3D scene seen by $rig (made with the frame's size) drawn into the frame
     * [$x, $y, $w, $h] of a $viewW x $viewH screen; nothing of it shows outside the frame. Then draw() its models.
     * @param array{0: float, 1: float, 2: float, 3: float} $frame
     */
    public function vignette(Rig $rig, array $frame, float $viewW, float $viewH): void
    {
        [$x, $y, $w, $h] = $frame;
        $sx = $w / $viewW;
        $sy = $h / $viewH;
        $cx = ($x + $w / 2) / $viewW * 2 - 1;
        $cy = 1 - ($y + $h / 2) / $viewH * 2;
        // the frame's own projection, squeezed into its place on the screen (clip space: x' = sx x + cx w)
        $vp = $rig->viewProj();
        for ($c = 0; $c < 4; $c++) {
            $wc = $vp[$c * 4 + 3];
            $vp[$c * 4] = $sx * $vp[$c * 4] + $cx * $wc;
            $vp[$c * 4 + 1] = $sy * $vp[$c * 4 + 1] + $cy * $wc;
        }
        $this->studio($rig, null, $vp, [$cx - $sx, $cy - $sy, $cx + $sx, $cy + $sy]);
    }

    /** @var array<string, mixed> uniforms for the next draw (studio()) */
    private array $pending = [];
    private bool $inStudio = false;

    /**
     * A model (built by $build the first time) at ($x, $y, $z), turned by $yaw about y, scaled.
     * @param callable(): Mesh $build
     * @param float|array{0: float, 1: float, 2: float} $scale
     * @param array{0: float, 1: float, 2: float} $tint multiplies the colours (above 1: brighter)
     * @param float $ao how much its foot is darkened (things standing on the ground: 1)
     */
    public function draw(string $key, callable $build, float $x, float $y, float $z, float $yaw = 0.0, float|array $scale = 1.0,
                         array $tint = [1.0, 1.0, 1.0], float $alpha = 1.0, float $emit = 0.0, float $ao = 1.0): void
    {
        if ($this->gl === null || ($this->scene === null && !$this->inStudio)) {
            return;
        }
        [$buffer, $count] = $this->model($key, $build);
        if ($count === 0) {
            return;
        }
        [$sx, $sy, $sz] = is_array($scale) ? $scale : [$scale, $scale, $scale];
        $textures = $this->pending !== [] ? ['u_ground' => $this->noise, 'u_noise' => $this->noise] : [];
        $this->gl->draw($this->prog, $this->options($buffer, $count, $this->pending + [
            'u_model' => self::matrix($x, $y, $z, $yaw, $sx, $sy, $sz),
            'u_tint' => $tint,
            'u_alpha' => $alpha,
            'u_emit' => $emit,
            'u_ao' => $ao,
        ], $alpha < 1.0, $textures));
        $this->pending = [];
    }

    /**
     * A soft shadow on the ground at height $y: $rx long (along $yaw), $rz wide. Draw them before what casts them.
     * The shadows of one frame do not darken each other (they write the same depth).
     */
    public function shadow(float $x, float $z, float $rx, float $rz, float $yaw = 0.0, float $alpha = 0.42, float $y = 0.35): void
    {
        $this->draw('unit:shadow', static fn () => Props::softDisc(), $x, $y, $z, $yaw, [$rx, 1.0, $rz], [0.10, 0.12, 0.26], $alpha, 0.0, 0.0);
    }

    /** A flat disc on the ground, $r wide (ranges, blasts). */
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
    private function options(int $buffer, int $count, array $uniforms, bool $blend, array $textures = []): array
    {
        $s = Mesh::STRIDE;
        return [
            'attributes' => ['a_pos' => [$buffer, 3, $s, 0], 'a_normal' => [$buffer, 3, $s, 3], 'a_color' => [$buffer, 3, $s, 6], 'a_mat' => [$buffer, 1, $s, 9]],
            'count' => $count,
            'uniforms' => $uniforms,
            'textures' => $textures,
            'blend' => $blend,
        ];
    }

    /** x, y, z move, $yaw turns about y (+x towards -z), then the scale: 16 floats, column-major. @return float[] */
    private static function matrix(float $x, float $y, float $z, float $yaw, float $sx, float $sy, float $sz): array
    {
        [$c, $s] = [cos($yaw), sin($yaw)];
        return [$sx * $c, 0.0, -$sx * $s, 0.0, 0.0, $sy, 0.0, 0.0, $sz * $s, 0.0, $sz * $c, 0.0, $x, $y, $z, 1.0];
    }
}
