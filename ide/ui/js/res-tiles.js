// The tileset editor (*.tsj) and the level editor (*.tmj): Tiled's JSON formats, what the engine's TiledJsonLoader
// reads. The JSON stays in the file (the tab's text); paths inside it are relative to the file, as Tiled writes them.
import { el, clear, notify } from './ui.js';
import { generateButton } from './res-ai.js';

export const COLLISIONS = [
  ['', 'default (solid on the collision layer)'], ['full', 'full'], ['none', 'none'], ['oneway', 'one-way platform'],
  ['slope', 'slope'], ['heights', 'heights per column'], ['oneway-slope', 'one-way slope'],
];
export const OBJECT_TYPES = ['start', 'coin', 'enemy', 'exit', 'checkpoint'];

const dirOf = (p) => (p.includes('/') ? p.slice(0, p.lastIndexOf('/')) : '');

/** a path relative to the folder $from (both relative to the project's root) */
export function relative(from, to) {
  const a = from ? from.split('/') : [];
  const b = to.split('/');
  while (a.length && b.length > 1 && a[0] === b[0]) { a.shift(); b.shift(); }
  return [...a.map(() => '..'), ...b].join('/');
}

/** $rel (relative to the folder $dir) as a path from the project's root */
export function resolve(dir, rel) {
  const out = dir ? dir.split('/') : [];
  for (const part of rel.split('/')) {
    if (part === '..') out.pop();
    else if (part !== '.' && part !== '') out.push(part);
  }
  return out.join('/');
}

/** Tiled's JSON, with the long arrays of numbers (tile layers) on one line */
export function stringify(data) {
  return JSON.stringify(data, null, 2).replace(/\[\s*(-?\d+(?:\s*,\s*-?\d+)*)\s*\]/g, (m, nums) => '[' + nums.split(/\s*,\s*/).join(',') + ']') + '\n';
}

function parse(text) {
  try { return JSON.parse(text); } catch { return null; }
}

function loadImage(src) {
  return new Promise((ok, ko) => { const i = new Image(); i.onload = () => ok(i); i.onerror = () => ko(new Error('cannot read the picture')); i.src = src; });
}

function prop(list, name) {
  return (list || []).find((p) => p.name === name)?.value;
}

function setProp(list, name, value, type = 'string') {
  const out = (list || []).filter((p) => p.name !== name);
  if (value !== '' && value !== null && value !== undefined) out.push({ name, type, value });
  return out;
}

/** columns / rows / tile count of a tileset from its picture */
function grid(ts, iw, ih) {
  const m = ts.margin || 0, s = ts.spacing || 0;
  const cols = Math.max(0, Math.floor((iw - 2 * m + s) / (ts.tilewidth + s)));
  const rows = Math.max(0, Math.floor((ih - 2 * m + s) / (ts.tileheight + s)));
  return { cols, rows, count: cols * rows };
}

function tileRect(ts, id) {
  const m = ts.margin || 0, s = ts.spacing || 0, cols = Math.max(1, ts.columns || 1);
  return [m + (id % cols) * (ts.tilewidth + s), m + Math.floor(id / cols) * (ts.tileheight + s), ts.tilewidth, ts.tileheight];
}

/** a tile's collision drawn over it: red = solid, a line = one-way, the shape of a slope / heights */
function drawCollision(g, spec, x, y, w, h, z = 1) {
  if (!spec || spec === 'none') return;
  const [kind, args] = spec.replace(/^oneway-/, '').split(':');
  const nums = (args || '').split(',').map(Number).filter((n) => !Number.isNaN(n));
  g.save();
  g.fillStyle = spec.startsWith('oneway') ? 'rgba(80,180,255,.45)' : 'rgba(255,70,70,.45)';
  if (kind === 'full' || kind === 'solid') g.fillRect(x, y, w, h);
  else if (kind === 'oneway') g.fillRect(x, y, w, Math.max(2, h / 8));
  else if (kind === 'slope') {
    const [a = 0, b = h / z] = nums;
    g.beginPath(); g.moveTo(x, y + h); g.lineTo(x, y + h - a * z); g.lineTo(x + w, y + h - b * z); g.lineTo(x + w, y + h); g.fill();
  } else if (kind === 'heights' && nums.length) {
    const cw = w / nums.length;
    nums.forEach((v, i) => g.fillRect(x + i * cw, y + h - v * z, cw + 0.5, v * z));
  }
  g.restore();
}

// --- tileset --------------------------------------------------------------------------------------------------------

export class TilesetEditor {
  /** host: the view; text: the .tsj; onChange(text); readImage(path) -> data URL; files: the project's files */
  constructor(host, { path, text, onChange, readImage, files = [] }) {
    this.path = path;
    this.dir = dirOf(path);
    this.onChange = onChange;
    this.readImage = readImage;
    this.ts = this.normalise(parse(text) || {});
    this.zoom = 3;
    this.selected = new Set([0]);
    this.image = null;
    const pngs = files.filter((f) => /\.png$/i.test(f));
    this.imageSelect = el('select.input.small', { onchange: () => this.setImage(this.imageSelect.value) },
      el('option', { value: '' }, '(picture)'), ...pngs.map((f) => el('option', { value: f }, f)));
    const num = (key, title) => el('input.input.small.res-num', { type: 'number', min: 1, max: 512, title,
      onchange: (e) => { this.ts[key] = Math.max(key === 'margin' || key === 'spacing' ? 0 : 1, Number(e.target.value) || 0); this.regrid(); } });
    this.inputs = { tilewidth: num('tilewidth', 'Tile width'), tileheight: num('tileheight', 'Tile height'), margin: num('margin', 'Margin'), spacing: num('spacing', 'Spacing') };
    this.inputs.margin.min = 0; this.inputs.spacing.min = 0;
    this.canvas = el('canvas.res-tiles-canvas', { onpointerdown: (e) => this.click(e) });
    this.panel = el('div.res-side');
    this.node = el('div.res-editor.res-tileset', { tabIndex: 0 },
      el('div.res-toolbar', {},
        el('span.res-inline', {}, 'Picture', this.imageSelect),
        el('span.res-inline', {}, 'Tile', this.inputs.tilewidth, '×', this.inputs.tileheight),
        el('span.res-inline', {}, 'Margin', this.inputs.margin, 'Spacing', this.inputs.spacing),
        el('span.res-sep'),
        el('button.btn.small', { onclick: () => this.setZoom(this.zoom - 1), title: 'Zoom out' }, '−'),
        el('button.btn.small', { onclick: () => this.setZoom(this.zoom + 1), title: 'Zoom in' }, '+')),
      el('div.res-body', {}, el('div.res-canvas-box', {}, this.canvas), this.panel));
    host.append(this.node);
    this.fields();
    this.loadImage();
  }

  normalise(ts) {
    return { type: 'tileset', version: '1.10', tiledversion: '1.10.2', name: ts.name || this.path.split('/').pop().replace(/\.tsj$/, ''),
      tilewidth: 16, tileheight: 16, margin: 0, spacing: 0, columns: 0, tilecount: 0, image: '', imagewidth: 0, imageheight: 0, tiles: [], ...ts };
  }

  setText(text) {
    const data = parse(text);
    if (!data || stringify(this.normalise(data)) === stringify(this.ts)) return;
    const image = data.image !== this.ts.image;
    this.ts = this.normalise(data);
    this.fields();
    if (image) this.loadImage(); else this.draw();
  }

  fields() {
    for (const [k, input] of Object.entries(this.inputs)) input.value = this.ts[k] ?? 0;
    this.imageSelect.value = this.ts.image ? resolve(this.dir, this.ts.image) : '';
  }

  async loadImage() {
    this.image = null;
    if (this.ts.image) {
      try { this.image = await loadImage(await this.readImage(resolve(this.dir, this.ts.image))); } catch (e) { notify(`${this.ts.image}: ${e.message}`, 'error'); }
    }
    this.regrid(false);
  }

  setImage(file) {
    this.ts.image = file ? relative(this.dir, file) : '';
    this.loadImage().then(() => this.changed());
  }

  regrid(save = true) {
    if (this.image) {
      const g = grid(this.ts, this.image.width, this.image.height);
      Object.assign(this.ts, { imagewidth: this.image.width, imageheight: this.image.height, columns: g.cols, tilecount: g.count });
    }
    this.draw();
    if (save) this.changed();
  }

  setZoom(z) { this.zoom = Math.max(1, Math.min(8, z)); this.draw(); }

  tile(id) { return (this.ts.tiles || []).find((t) => t.id === id); }

  collisionOf(id) { return prop(this.tile(id)?.properties, 'collision') ?? ''; }

  draw() {
    const z = this.zoom;
    const iw = this.image?.width || 128, ih = this.image?.height || 64;
    Object.assign(this.canvas, { width: iw * z, height: ih * z });
    const g = this.canvas.getContext('2d');
    g.imageSmoothingEnabled = false;
    g.fillStyle = '#2b2d30'; g.fillRect(0, 0, iw * z, ih * z);
    if (!this.image) { g.fillStyle = '#888'; g.font = '13px sans-serif'; g.fillText('Choose the picture of the tiles', 10, 24); this.drawPanel(); return; }
    g.drawImage(this.image, 0, 0, iw * z, ih * z);
    for (let id = 0; id < this.ts.tilecount; id++) {
      const [x, y, w, h] = tileRect(this.ts, id).map((v) => v * z);
      drawCollision(g, this.collisionOf(id), x, y, w, h, z);
      g.strokeStyle = this.selected.has(id) ? '#ffd23f' : 'rgba(255,255,255,.18)';
      g.lineWidth = this.selected.has(id) ? 2 : 1;
      g.strokeRect(x + 0.5, y + 0.5, w - 1, h - 1);
    }
    this.drawPanel();
  }

  click(e) {
    if (!this.image) return;
    const r = this.canvas.getBoundingClientRect();
    const [px, py] = [(e.clientX - r.left) / this.zoom, (e.clientY - r.top) / this.zoom];
    for (let id = 0; id < this.ts.tilecount; id++) {
      const [x, y, w, h] = tileRect(this.ts, id);
      if (px >= x && px < x + w && py >= y && py < y + h) {
        if (e.shiftKey || e.ctrlKey || e.metaKey) { if (this.selected.has(id) && this.selected.size > 1) this.selected.delete(id); else this.selected.add(id); }
        else this.selected = new Set([id]);
        this.draw();
        return;
      }
    }
  }

  drawPanel() {
    clear(this.panel);
    const ids = [...this.selected].filter((id) => id < Math.max(1, this.ts.tilecount));
    if (!ids.length) return;
    const first = ids[0];
    const spec = this.collisionOf(first);
    const [kind, args = ''] = spec.split(':');
    const kindSel = el('select.input.small', {}, ...COLLISIONS.map(([v, l]) => el('option', { value: v, selected: v === kind || undefined }, l)));
    const argInput = el('input.input.small', { value: args, placeholder: 'slope: from,to (px at the left / right edge) · heights: h0,h1,…' });
    const type = el('input.input.small', { value: prop(this.tile(first)?.properties, 'type') ?? '', placeholder: 'e.g. hazard, ladder (optional)' });
    const apply = () => {
      const k = kindSel.value;
      const value = k === '' ? '' : (k === 'slope' || k === 'heights' || k === 'oneway-slope') ? `${k}:${argInput.value.replace(/\s+/g, '')}` : k;
      for (const id of ids) this.setTile(id, { collision: value, type: type.value.trim() });
      this.draw();
      this.changed();
    };
    kindSel.onchange = apply; argInput.onchange = apply; type.onchange = apply;
    this.panel.append(
      el('div.res-row', {}, el('b', {}, ids.length > 1 ? `${ids.length} tiles` : `Tile ${first}`)),
      el('label.res-row', {}, el('span.res-label', {}, 'Collision'), kindSel),
      el('label.res-row', {}, el('span.res-label', {}, 'Shape'), argInput),
      el('label.res-row', {}, el('span.res-label', {}, 'Type'), type),
      el('p.res-note', {}, 'Click a tile, Shift+click to select more. Without a collision, a tile of the level\'s "collision" layer is solid.'),
      el('p.res-note', {}, `${this.ts.tilecount} tiles · ${this.ts.columns} columns`));
  }

  setTile(id, { collision, type }) {
    const tiles = (this.ts.tiles || []).filter((t) => t.id !== id);
    let props = prop(this.tile(id)?.properties, 'collision') !== undefined || collision ? setProp(this.tile(id)?.properties, 'collision', collision) : (this.tile(id)?.properties || []);
    props = setProp(props, 'type', type);
    const rest = { ...(this.tile(id) || {}) };
    delete rest.properties;
    if (props.length) tiles.push({ ...rest, id, properties: props });
    else if (Object.keys(rest).length > 1) tiles.push({ ...rest, id });
    this.ts.tiles = tiles.sort((a, b) => a.id - b.id);
  }

  changed() { this.onChange?.(stringify(this.ts)); }

  destroy() { this.node.remove(); }
}

// --- level ----------------------------------------------------------------------------------------------------------

export class LevelEditor {
  /** host: the view; text: the .tmj; onChange(text); readText(path) / readImage(path); files: the project's files */
  constructor(host, { path, text, onChange, readText, readImage, files = [], ai = false }) {
    this.path = path;
    this.dir = dirOf(path);
    this.onChange = onChange;
    this.readText = readText;
    this.readImage = readImage;
    this.files = files;
    this.map = this.normalise(parse(text) || {});
    this.sets = [];           // [{firstgid, ts, image, path}]
    this.set = 0;             // the tileset of the palette
    this.brush = [[1]];       // gids
    this.tool = 'paint';
    this.zoom = 2;
    this.layer = Math.max(0, this.map.layers.findIndex((l) => l.name === 'collision'));
    this.object = null;
    this.objectType = 'start';
    this.undo = [];
    this.redo = [];
    this.tools = {};
    const tool = (t, label, title) => (this.tools[t] = el('button.btn.small.res-tool', { onclick: () => this.setTool(t), title }, label));
    this.setSelect = el('select.input.small', { onchange: () => { this.set = Number(this.setSelect.value); this.brush = [[this.sets[this.set]?.firstgid || 1]]; this.drawPalette(); } });
    this.sizeW = el('input.input.small.res-num', { type: 'number', min: 1, max: 1000, onchange: () => this.resize() });
    this.sizeH = el('input.input.small.res-num', { type: 'number', min: 1, max: 1000, onchange: () => this.resize() });
    this.typeSelect = el('select.input.small', { onchange: () => { this.objectType = this.typeSelect.value; } }, ...OBJECT_TYPES.map((t) => el('option', { value: t }, t)));
    this.canvas = el('canvas.res-level-canvas', { onpointerdown: (e) => this.down(e), onpointermove: (e) => this.move(e), onpointerup: (e) => this.up(e), oncontextmenu: (e) => e.preventDefault() });
    this.palette = el('canvas.res-palette', { onpointerdown: (e) => this.pickDown(e), onpointermove: (e) => this.pickMove(e), onpointerup: () => { this.picking = null; } });
    this.layersNode = el('div.res-layers');
    this.objectPanel = el('div.res-object');
    this.box = el('div.res-canvas-box.res-level-box');
    this.box.append(this.canvas);
    this.node = el('div.res-editor.res-level', { tabIndex: 0, onkeydown: (e) => this.key(e) },
      el('div.res-toolbar', {},
        tool('paint', '✎ Paint', 'Paint the brush (P); right click: erase'), tool('erase', '⌫ Erase', 'Erase (E)'), tool('fill', '▧ Fill', 'Fill (F)'),
        tool('rect', '▭ Rectangle', 'Fill a rectangle (R)'), tool('object', '◆ Objects', 'Place / select objects (O)'),
        el('span.res-inline', {}, this.typeSelect),
        el('span.res-sep'),
        el('button.btn.small', { onclick: () => this.restore(this.undo, this.redo), title: 'Undo (Ctrl+Z)' }, '↶'),
        el('button.btn.small', { onclick: () => this.restore(this.redo, this.undo), title: 'Redo (Ctrl+Y)' }, '↷'),
        el('span.res-sep'),
        el('button.btn.small', { onclick: () => this.setZoom(this.zoom - 1), title: 'Zoom out' }, '−'),
        el('button.btn.small', { onclick: () => this.setZoom(this.zoom + 1), title: 'Zoom in' }, '+'),
        el('span.res-sep'),
        el('span.res-inline', {}, 'Size', this.sizeW, '×', this.sizeH, 'tiles'),
        ...(ai ? [el('span.res-sep'), generateButton('level', { apply: (d) => this.generated(d),
          fields: () => [{ key: 'width', label: 'Width (tiles)', value: Math.min(200, this.map.width), min: 4, max: 200 },
            { key: 'height', label: 'Height (tiles)', value: Math.min(60, this.map.height), min: 4, max: 60 }] })] : [])),
      el('div.res-body.res-level-body', {}, this.box,
        el('div.res-side.res-level-side', {},
          el('div.res-row', {}, el('span.res-label', {}, 'Tileset'), this.setSelect,
            el('button.btn.small', { onclick: () => this.addTileset(), title: 'Use a tileset (.tsj) of the project' }, '+')),
          el('div.res-palette-box', {}, this.palette),
          el('div.res-row', {}, el('b', {}, 'Layers'),
            el('button.btn.small', { onclick: () => this.addLayer('tilelayer') }, '+ tiles'),
            el('button.btn.small', { onclick: () => this.addLayer('objectgroup') }, '+ objects')),
          this.layersNode, this.objectPanel)));
    host.append(this.node);
    this.setTool('paint');
    this.fields();
    this.loadSets();
  }

  normalise(m) {
    const w = m.width || 40, h = m.height || 12;
    const map = { type: 'map', version: '1.10', tiledversion: '1.10.2', orientation: 'orthogonal', renderorder: 'right-down', infinite: false,
      width: w, height: h, tilewidth: 16, tileheight: 16, nextlayerid: 1, nextobjectid: 1, tilesets: [], layers: [], ...m };
    if (!map.layers.length) {
      map.layers = [this.newLayer(map, 'tilelayer', 'background'), this.newLayer(map, 'tilelayer', 'collision'), this.newLayer(map, 'objectgroup', 'objects')];
    }
    return map;
  }

  newLayer(map, type, name) {
    const base = { id: map.nextlayerid++, name, type, opacity: 1, visible: true, x: 0, y: 0 };
    return type === 'tilelayer' ? { ...base, width: map.width, height: map.height, data: new Array(map.width * map.height).fill(0) } : { ...base, draworder: 'topdown', objects: [] };
  }

  setText(text) {
    const data = parse(text);
    if (!data || stringify(this.normalise(data)) === stringify(this.map)) return;
    const sets = JSON.stringify(data.tilesets) !== JSON.stringify(this.map.tilesets);
    this.map = this.normalise(data);
    this.layer = Math.min(this.layer, this.map.layers.length - 1);
    this.fields();
    if (sets) this.loadSets(); else this.draw();
  }

  fields() {
    this.sizeW.value = this.map.width;
    this.sizeH.value = this.map.height;
    this.drawLayers();
  }

  async loadSets() {
    const sets = [];
    for (const ref of this.map.tilesets) {
      try {
        const file = ref.source ? resolve(this.dir, ref.source) : null;
        const ts = file ? JSON.parse(await this.readText(file)) : ref;
        const tsDir = file ? dirOf(file) : this.dir;
        const image = ts.image ? await loadImage(await this.readImage(resolve(tsDir, ts.image))) : null;
        sets.push({ firstgid: ref.firstgid, ts, image, path: file || '(embedded)' });
      } catch (e) {
        notify(`${ref.source || 'tileset'}: ${e.message}`, 'error');
      }
    }
    this.sets = sets;
    this.set = Math.min(this.set, Math.max(0, sets.length - 1));
    clear(this.setSelect);
    sets.forEach((s, i) => this.setSelect.append(el('option', { value: i, selected: i === this.set || undefined }, s.path.split('/').pop())));
    if (!sets.length) this.setSelect.append(el('option', { value: 0 }, '(none: add one)'));
    if (sets.length && !this.sets.some((s) => this.brush[0][0] >= s.firstgid && this.brush[0][0] < s.firstgid + (s.ts.tilecount || 1))) this.brush = [[sets[this.set].firstgid]];
    this.drawPalette();
    this.draw();
  }

  async addTileset() {
    const choices = this.files.filter((f) => /\.tsj$/i.test(f));
    if (!choices.length) { notify('No tileset in the project: New > Tileset… first (a .tsj made from a PNG).', 'info'); return; }
    const select = el('select.input.small', {}, ...choices.map((f) => el('option', { value: f }, f)));
    const done = () => {
      const source = relative(this.dir, select.value);
      if (this.map.tilesets.some((t) => t.source === source)) { row.remove(); return; }
      this.remember();
      const last = this.sets[this.sets.length - 1];
      const firstgid = last ? last.firstgid + Math.max(1, last.ts.tilecount || 1) : 1;
      this.map.tilesets.push({ firstgid, source });
      this.set = this.map.tilesets.length - 1;
      row.remove();
      this.changed();
      this.loadSets();
    };
    const row = el('div.res-row', {}, select, el('button.btn.small.primary', { onclick: done }, 'Use'));
    this.setSelect.parentNode.after(row);
  }

  setTool(t) {
    this.tool = t;
    for (const [k, b] of Object.entries(this.tools)) b.classList.toggle('on', k === t);
    const layer = this.map.layers[this.layer];
    if (t === 'object' && layer?.type !== 'objectgroup') this.layer = Math.max(0, this.map.layers.findIndex((l) => l.type === 'objectgroup'));
    if (t !== 'object' && layer?.type !== 'tilelayer') this.layer = Math.max(0, this.map.layers.findIndex((l) => l.type === 'tilelayer'));
    this.drawLayers();
    this.draw();
  }

  setZoom(z) { this.zoom = Math.max(1, Math.min(6, z)); this.draw(); }

  setOf(gid) {
    let found = null;
    for (const s of this.sets) if (gid >= s.firstgid) found = s;
    return found;
  }

  drawTile(g, gid, x, y, w, h) {
    const s = this.setOf(gid & 0x1fffffff);
    if (!s?.image) { g.fillStyle = '#7a7f87'; g.fillRect(x, y, w, h); return; }
    const [sx, sy, sw, sh] = tileRect(s.ts, (gid & 0x1fffffff) - s.firstgid);
    g.drawImage(s.image, sx, sy, sw, sh, x, y, w, h);
  }

  draw() {
    const { width: W, height: H, tilewidth: tw, tileheight: th } = this.map;
    const z = this.zoom;
    Object.assign(this.canvas, { width: W * tw * z, height: H * th * z });
    const g = this.canvas.getContext('2d');
    g.imageSmoothingEnabled = false;
    g.fillStyle = '#5c94fc33'; g.fillRect(0, 0, this.canvas.width, this.canvas.height);
    this.map.layers.forEach((layer, li) => {
      if (layer.visible === false) return;
      g.globalAlpha = li === this.layer || this.tool === 'object' ? 1 : 0.75;
      if (layer.type === 'tilelayer') {
        for (let i = 0; i < W * H; i++) {
          const gid = layer.data[i];
          if (gid) this.drawTile(g, gid, (i % W) * tw * z, Math.floor(i / W) * th * z, tw * z, th * z);
        }
        if (layer.name === 'collision' && li === this.layer) {
          g.fillStyle = 'rgba(255,70,70,.18)';
          for (let i = 0; i < W * H; i++) if (layer.data[i]) g.fillRect((i % W) * tw * z, Math.floor(i / W) * th * z, tw * z, th * z);
        }
      } else if (layer.type === 'objectgroup') {
        for (const o of layer.objects) {
          const [x, y, w, h] = [o.x * z, o.y * z, (o.width || tw) * z, (o.height || th) * z];
          g.fillStyle = o === this.object ? 'rgba(255,210,63,.45)' : 'rgba(80,220,140,.35)';
          g.fillRect(x, y, w, h);
          g.strokeStyle = o === this.object ? '#ffd23f' : '#50dc8c';
          g.strokeRect(x + 0.5, y + 0.5, w - 1, h - 1);
          g.fillStyle = '#fff'; g.font = `${Math.max(9, 5 * z)}px sans-serif`;
          g.fillText(o.type || o.name || 'object', x + 2, y - 3 > 8 ? y - 3 : y + h + 10);
        }
      }
    });
    g.globalAlpha = 1;
    g.strokeStyle = 'rgba(255,255,255,.08)';
    g.beginPath();
    for (let x = 0; x <= W; x++) { g.moveTo(x * tw * z + 0.5, 0); g.lineTo(x * tw * z + 0.5, H * th * z); }
    for (let y = 0; y <= H; y++) { g.moveTo(0, y * th * z + 0.5); g.lineTo(W * tw * z, y * th * z + 0.5); }
    g.stroke();
    if (this.rect) {
      const [x0, y0, x1, y1] = this.rectBox();
      g.strokeStyle = '#ffd23f'; g.lineWidth = 2;
      g.strokeRect(x0 * tw * z, y0 * th * z, (x1 - x0 + 1) * tw * z, (y1 - y0 + 1) * th * z);
      g.lineWidth = 1;
    }
  }

  drawPalette() {
    const s = this.sets[this.set];
    const z = 2;
    if (!s?.image) { Object.assign(this.palette, { width: 160, height: 40 }); const g = this.palette.getContext('2d'); g.fillStyle = '#888'; g.font = '12px sans-serif'; g.fillText('No tileset: + to add one', 6, 24); return; }
    Object.assign(this.palette, { width: s.image.width * z, height: s.image.height * z });
    const g = this.palette.getContext('2d');
    g.imageSmoothingEnabled = false;
    g.drawImage(s.image, 0, 0, s.image.width * z, s.image.height * z);
    const ids = new Set(this.brush.flat().map((gid) => gid - s.firstgid));
    for (let id = 0; id < (s.ts.tilecount || 0); id++) {
      const [x, y, w, h] = tileRect(s.ts, id).map((v) => v * z);
      drawCollision(g, prop(s.ts.tiles?.find((t) => t.id === id)?.properties, 'collision'), x, y, w, h, z);
      if (ids.has(id)) { g.strokeStyle = '#ffd23f'; g.lineWidth = 2; g.strokeRect(x + 1, y + 1, w - 2, h - 2); g.lineWidth = 1; }
    }
  }

  paletteTile(e) {
    const s = this.sets[this.set];
    if (!s) return null;
    const r = this.palette.getBoundingClientRect();
    const [px, py] = [(e.clientX - r.left) / 2, (e.clientY - r.top) / 2];
    const cols = Math.max(1, s.ts.columns || 1);
    const col = Math.floor((px - (s.ts.margin || 0)) / (s.ts.tilewidth + (s.ts.spacing || 0)));
    const row = Math.floor((py - (s.ts.margin || 0)) / (s.ts.tileheight + (s.ts.spacing || 0)));
    if (col < 0 || row < 0 || col >= cols || row * cols + col >= (s.ts.tilecount || 0)) return null;
    return [col, row];
  }

  pickDown(e) {
    const t = this.paletteTile(e);
    if (!t) return;
    this.picking = t;
    this.pickMove(e);
  }

  /** a drag over the palette: a brush of several tiles */
  pickMove(e) {
    if (!this.picking) return;
    const t = this.paletteTile(e) || this.picking;
    const s = this.sets[this.set];
    const cols = Math.max(1, s.ts.columns || 1);
    const [c0, c1] = [Math.min(this.picking[0], t[0]), Math.max(this.picking[0], t[0])];
    const [r0, r1] = [Math.min(this.picking[1], t[1]), Math.max(this.picking[1], t[1])];
    this.brush = [];
    for (let r = r0; r <= r1; r++) { const row = []; for (let c = c0; c <= c1; c++) row.push(s.firstgid + r * cols + c); this.brush.push(row); }
    if (this.tool === 'erase' || this.tool === 'object') this.setTool('paint');
    this.drawPalette();
  }

  cell(e) {
    const r = this.canvas.getBoundingClientRect();
    const { tilewidth: tw, tileheight: th, width: W, height: H } = this.map;
    const x = Math.floor((e.clientX - r.left) / (tw * this.zoom)), y = Math.floor((e.clientY - r.top) / (th * this.zoom));
    return { x, y, inside: x >= 0 && y >= 0 && x < W && y < H, px: (e.clientX - r.left) / this.zoom, py: (e.clientY - r.top) / this.zoom };
  }

  down(e) {
    this.node.focus();
    const c = this.cell(e);
    if (e.button === 1 || (e.button === 0 && e.altKey)) { this.pan = { x: e.clientX, y: e.clientY, l: this.box.scrollLeft, t: this.box.scrollTop }; return; }
    if (!c.inside) return;
    this.canvas.setPointerCapture?.(e.pointerId);
    const layer = this.map.layers[this.layer];
    if (this.tool === 'object') { this.objectDown(c); return; }
    if (layer?.type !== 'tilelayer') { notify('Choose a tile layer (Layers).', 'info'); return; }
    this.remember();
    const erase = e.button === 2 || this.tool === 'erase';
    if (this.tool === 'fill' && !erase) { this.fill(layer, c.x, c.y); this.changed(); return; }
    if (this.tool === 'rect') { this.rect = { x0: c.x, y0: c.y, x1: c.x, y1: c.y, erase }; this.draw(); return; }
    this.painting = { erase };
    this.stamp(layer, c.x, c.y, erase);
  }

  move(e) {
    if (this.pan) { this.box.scrollLeft = this.pan.l - (e.clientX - this.pan.x); this.box.scrollTop = this.pan.t - (e.clientY - this.pan.y); return; }
    const c = this.cell(e);
    if (this.dragging) {
      const o = this.dragging.object;
      o.x = Math.max(0, Math.round((c.px - this.dragging.dx) / this.map.tilewidth * 2) * this.map.tilewidth / 2);
      o.y = Math.max(0, Math.round((c.py - this.dragging.dy) / this.map.tileheight * 2) * this.map.tileheight / 2);
      this.dragging.moved = true;
      this.draw();
      return;
    }
    if (this.rect) { this.rect.x1 = Math.max(0, Math.min(this.map.width - 1, c.x)); this.rect.y1 = Math.max(0, Math.min(this.map.height - 1, c.y)); this.draw(); return; }
    if (this.painting && c.inside) this.stamp(this.map.layers[this.layer], c.x, c.y, this.painting.erase);
  }

  up() {
    if (this.pan) { this.pan = null; return; }
    if (this.dragging) { if (this.dragging.moved) this.changed(); this.dragging = null; this.drawObject(); return; }
    if (this.rect) {
      const layer = this.map.layers[this.layer];
      const [x0, y0, x1, y1] = this.rectBox();
      for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) {
        const row = this.brush[(y - y0) % this.brush.length];
        layer.data[y * this.map.width + x] = this.rect.erase ? 0 : row[(x - x0) % row.length];
      }
      this.rect = null;
      this.draw();
      this.changed();
      return;
    }
    if (this.painting) { this.painting = null; this.changed(); }
  }

  rectBox() {
    const r = this.rect;
    return [Math.min(r.x0, r.x1), Math.min(r.y0, r.y1), Math.max(r.x0, r.x1), Math.max(r.y0, r.y1)];
  }

  stamp(layer, cx, cy, erase) {
    const { width: W, height: H } = this.map;
    const brush = erase ? [[0]] : this.brush;
    brush.forEach((row, dy) => row.forEach((gid, dx) => {
      const x = cx + dx, y = cy + dy;
      if (x < W && y < H) layer.data[y * W + x] = gid;
    }));
    this.draw();
  }

  fill(layer, x, y) {
    const W = this.map.width, H = this.map.height;
    const from = layer.data[y * W + x];
    const to = this.brush[0][0];
    if (from === to) return;
    const stack = [[x, y]];
    while (stack.length) {
      const [px, py] = stack.pop();
      if (px < 0 || py < 0 || px >= W || py >= H || layer.data[py * W + px] !== from) continue;
      layer.data[py * W + px] = to;
      stack.push([px + 1, py], [px - 1, py], [px, py + 1], [px, py - 1]);
    }
    this.draw();
  }

  objectDown(c) {
    const layer = this.map.layers[this.layer];
    if (layer?.type !== 'objectgroup') return;
    const hit = [...layer.objects].reverse().find((o) => c.px >= o.x && c.px < o.x + (o.width || this.map.tilewidth) && c.py >= o.y && c.py < o.y + (o.height || this.map.tileheight));
    this.remember();
    if (hit) {
      this.object = hit;
      this.dragging = { object: hit, dx: c.px - hit.x, dy: c.py - hit.y };
    } else {
      const { tilewidth: tw, tileheight: th } = this.map;
      this.object = { id: this.map.nextobjectid++, name: this.objectType === 'start' ? 'start' : '', type: this.objectType,
        x: c.x * tw, y: c.y * th, width: tw, height: th, rotation: 0, visible: true, properties: [] };
      layer.objects.push(this.object);
      this.changed();
    }
    this.draw();
    this.drawObject();
  }

  drawObject() {
    clear(this.objectPanel);
    const o = this.object;
    if (!o || !this.map.layers.some((l) => l.objects?.includes(o))) return;
    const field = (label, key, number) => {
      const input = el('input.input.small', { value: o[key] ?? '', type: number ? 'number' : 'text',
        onchange: () => { this.remember(); o[key] = number ? Number(input.value) || 0 : input.value; this.draw(); this.changed(); } });
      return el('label.res-row', {}, el('span.res-label', {}, label), input);
    };
    const props = el('textarea.input.small', { rows: 3, placeholder: 'speed=40\npatrol=true',
      onchange: () => {
        this.remember();
        o.properties = props.value.split('\n').map((l) => l.trim()).filter(Boolean).map((l) => {
          const [k, ...v] = l.split('=');
          const value = v.join('=').trim();
          const typed = /^-?\d+$/.test(value) ? ['int', Number(value)] : /^-?\d*\.\d+$/.test(value) ? ['float', Number(value)] : /^(true|false)$/.test(value) ? ['bool', value === 'true'] : ['string', value];
          return { name: k.trim(), type: typed[0], value: typed[1] };
        });
        this.changed();
      } }, (o.properties || []).map((p) => `${p.name}=${p.value}`).join('\n'));
    this.objectPanel.append(el('div.res-row', {}, el('b', {}, 'Object'),
      el('button.btn.small', { onclick: () => this.deleteObject() }, 'Delete')),
      field('Type', 'type'), field('Name', 'name'), field('X', 'x', true), field('Y', 'y', true), field('Width', 'width', true), field('Height', 'height', true),
      el('label.res-row', {}, el('span.res-label', {}, 'Properties'), props));
  }

  deleteObject() {
    if (!this.object) return;
    this.remember();
    for (const l of this.map.layers) if (l.objects) l.objects = l.objects.filter((o) => o !== this.object);
    this.object = null;
    this.drawObject();
    this.draw();
    this.changed();
  }

  drawLayers() {
    clear(this.layersNode);
    this.map.layers.forEach((layer, i) => {
      const visible = el('input', { type: 'checkbox', checked: layer.visible !== false, title: 'Shown',
        onchange: () => { layer.visible = visible.checked; this.draw(); this.changed(); } });
      const row = el(`div.res-layer${i === this.layer ? '.on' : ''}`, { onclick: (e) => { if (e.target !== visible) { this.layer = i; if ((layer.type === 'objectgroup') !== (this.tool === 'object')) this.setTool(layer.type === 'objectgroup' ? 'object' : 'paint'); else { this.drawLayers(); this.draw(); } } } },
        visible, el('span', {}, layer.type === 'objectgroup' ? '◆ ' : '▦ ', layer.name),
        el('span.res-layer-tools', {},
          el('button.btn.small', { title: 'Rename', onclick: (e) => { e.stopPropagation(); this.renameLayer(i); } }, '✎'),
          el('button.btn.small', { title: 'Up', onclick: (e) => { e.stopPropagation(); this.moveLayer(i, -1); } }, '↑'),
          el('button.btn.small', { title: 'Down', onclick: (e) => { e.stopPropagation(); this.moveLayer(i, 1); } }, '↓'),
          el('button.btn.small', { title: 'Delete', onclick: (e) => { e.stopPropagation(); this.deleteLayer(i); } }, '✕')));
      this.layersNode.append(row);
    });
    this.layersNode.append(el('p.res-note', {}, 'The layer named "collision" is the one the player walks on (its tiles\' collision: the tileset).'));
  }

  addLayer(type) {
    this.remember();
    const n = this.map.layers.filter((l) => l.type === type).length + 1;
    this.map.layers.push(this.newLayer(this.map, type, type === 'tilelayer' ? `decor${n}` : `objects${n}`));
    this.layer = this.map.layers.length - 1;
    this.setTool(type === 'tilelayer' ? 'paint' : 'object');
    this.changed();
  }

  renameLayer(i) {
    const name = window.prompt('Name of the layer ("collision": the solid one)', this.map.layers[i].name);
    if (!name) return;
    this.remember();
    this.map.layers[i].name = name.trim();
    this.drawLayers();
    this.changed();
  }

  moveLayer(i, d) {
    const j = i + d;
    if (j < 0 || j >= this.map.layers.length) return;
    this.remember();
    [this.map.layers[i], this.map.layers[j]] = [this.map.layers[j], this.map.layers[i]];
    this.layer = j;
    this.drawLayers();
    this.draw();
    this.changed();
  }

  deleteLayer(i) {
    if (this.map.layers.length < 2) return;
    this.remember();
    this.map.layers.splice(i, 1);
    this.layer = Math.min(this.layer, this.map.layers.length - 1);
    this.drawLayers();
    this.draw();
    this.changed();
  }

  /** a new size, the top-left corner kept */
  resize() {
    const w = Math.max(1, Math.min(1000, Number(this.sizeW.value) || this.map.width));
    const h = Math.max(1, Math.min(1000, Number(this.sizeH.value) || this.map.height));
    if (w === this.map.width && h === this.map.height) return;
    this.remember();
    for (const layer of this.map.layers) {
      if (layer.type !== 'tilelayer') continue;
      const data = new Array(w * h).fill(0);
      for (let y = 0; y < Math.min(h, this.map.height); y++) for (let x = 0; x < Math.min(w, this.map.width); x++) data[y * w + x] = layer.data[y * this.map.width + x];
      Object.assign(layer, { width: w, height: h, data });
    }
    Object.assign(this.map, { width: w, height: h });
    this.draw();
    this.changed();
  }

  /**
   * A generated level ({width, height, tiles: 0 empty / 1 solid / 2 one-way, objects: [{type, x, y}] in tiles}): the map
   * takes its size, its collision layer the tiles (a solid and a one-way tile of the tilesets, else gids 1 and 2), its
   * object layer the objects; the other layers are emptied. Undo brings the old level back.
   */
  generated({ width, height, tiles, objects }) {
    this.remember();
    const find = (kind) => {
      for (const s of this.sets) {
        const t = (s.ts.tiles || []).find((t) => prop(t.properties, 'collision') === kind);
        if (t) return s.firstgid + t.id;
      }
      return null;
    };
    const solid = find('full') ?? this.sets[0]?.firstgid ?? 1;
    const oneway = find('oneway') ?? solid + 1;
    const map = this.map;
    map.width = width;
    map.height = height;
    let collision = map.layers.find((l) => l.type === 'tilelayer' && l.name === 'collision') || map.layers.find((l) => l.type === 'tilelayer');
    if (!collision) map.layers.push(collision = this.newLayer(map, 'tilelayer', 'collision'));
    let group = map.layers.find((l) => l.type === 'objectgroup');
    if (!group) map.layers.push(group = this.newLayer(map, 'objectgroup', 'objects'));
    for (const l of map.layers) {
      if (l.type === 'tilelayer') { l.width = width; l.height = height; l.data = new Array(width * height).fill(0); }
    }
    collision.data = tiles.map((t) => (t === 1 ? solid : t === 2 ? oneway : 0));
    const { tilewidth: tw, tileheight: th } = map;
    group.objects = objects.map((o) => ({ id: map.nextobjectid++, name: o.type === 'start' ? 'start' : '', type: o.type,
      x: o.x * tw, y: o.y * th, width: tw, height: th, rotation: 0, visible: true, properties: [] }));
    this.object = null;
    this.fields();
    this.drawObject();
    this.changed();
    this.draw();
  }

  remember() {
    this.undo.push(JSON.stringify(this.map));
    if (this.undo.length > 100) this.undo.shift();
    this.redo = [];
  }

  restore(from, to) {
    if (!from.length) return;
    to.push(JSON.stringify(this.map));
    this.map = JSON.parse(from.pop());
    this.object = null;
    this.layer = Math.min(this.layer, this.map.layers.length - 1);
    this.fields();
    this.drawObject();
    this.draw();
    this.changed();
  }

  key(e) {
    if (e.target.closest('input, textarea, select')) return;
    const k = e.key.toLowerCase();
    if ((e.ctrlKey || e.metaKey) && k === 'z') { e.preventDefault(); this.restore(e.shiftKey ? this.redo : this.undo, e.shiftKey ? this.undo : this.redo); return; }
    if ((e.ctrlKey || e.metaKey) && k === 'y') { e.preventDefault(); this.restore(this.redo, this.undo); return; }
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    const tools = { p: 'paint', e: 'erase', f: 'fill', r: 'rect', o: 'object' };
    if (tools[k]) { this.setTool(tools[k]); e.preventDefault(); }
    if ((k === 'delete' || k === 'backspace') && this.object) { this.deleteObject(); e.preventDefault(); }
    if (k === '+' || k === '=') this.setZoom(this.zoom + 1);
    if (k === '-') this.setZoom(this.zoom - 1);
  }

  changed() { this.onChange?.(stringify(this.map)); }

  destroy() { this.node.remove(); }
}
