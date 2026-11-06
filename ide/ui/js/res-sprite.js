// The sprite editor (a PNG of assets/): pixels drawn one by one on a frame of the sheet (frames of W x H, left to
// right then top to bottom, as Sprite's `coords` read them), with a palette, pencil, eraser, fill, picker, zoom, grid,
// frames added / removed, and the animation played. Saved as PNG (Ctrl+S or Save), alpha kept.
import { el, notify } from './ui.js';
import { base64 } from './res-sound.js';
import { generateButton } from './res-ai.js';

const PALETTE = ['#000000', '#1d2b53', '#7e2553', '#008751', '#ab5236', '#5f574f', '#c2c3c7', '#fff1e8',
  '#ff004d', '#ffa300', '#ffec27', '#00e436', '#29adff', '#83769c', '#ff77a8', '#ffccaa'];
const TOOLS = [['pencil', '✎', 'Pencil (P)', 'p'], ['eraser', '⌫', 'Eraser (E)', 'e'], ['fill', '▧', 'Fill (F)', 'f'], ['picker', '⊙', 'Pick a colour (I)', 'i']];

export class SpriteEditor {
  /** src: the PNG as a data URL (or null: a new one of width x height); save(base64Png): writes the file */
  constructor(host, { path, src, width = 16, height = 16, save, ai = false }) {
    this.path = path;
    this.saveFile = save;
    this.tool = 'pencil';
    this.color = '#ffec27';
    this.frame = 0;
    this.zoom = 16;
    this.grid = true;
    this.undo = [];
    this.redo = [];
    this.dirty = false;
    this.sheet = document.createElement('canvas');
    this.view = el('canvas.res-pixels');
    this.preview = el('canvas.res-preview', { width: 64, height: 64 });
    this.frames = el('div.res-frames');
    this.status = el('span.res-status');
    this.colorInput = el('input.res-color', { type: 'color', value: this.color, oninput: () => this.pick(this.colorInput.value) });
    this.palette = el('div.res-palette');
    this.fw = el('input.input.small.res-num', { type: 'number', min: 1, max: 512, onchange: () => this.frameSize() });
    this.fh = el('input.input.small.res-num', { type: 'number', min: 1, max: 512, onchange: () => this.frameSize() });
    this.fps = el('input.input.small.res-num', { type: 'number', min: 1, max: 60, value: 8 });
    this.toolButtons = TOOLS.map(([t, label, title]) => el('button.btn.small.res-tool', { title, onclick: () => this.setTool(t) }, label));
    this.node = el('div.res-editor.res-sprite', { tabIndex: 0, onkeydown: (e) => this.key(e) },
      el('div.res-toolbar', {},
        el('button.btn.primary', { onclick: () => this.save(), title: 'Save (Ctrl+S)' }, 'Save'), this.status, el('span.res-sep'),
        ...this.toolButtons, el('span.res-sep'),
        el('label.res-inline', {}, 'Zoom', el('input', { type: 'range', min: 2, max: 48, value: this.zoom, oninput: (e) => { this.zoom = Number(e.target.value); this.draw(); } })),
        el('label.res-inline', {}, el('input', { type: 'checkbox', checked: this.grid, onchange: (e) => { this.grid = e.target.checked; this.draw(); } }), 'Grid'),
        el('span.res-sep'),
        el('button.btn.small', { onclick: () => this.restore(this.undo, this.redo), title: 'Undo (Ctrl+Z)' }, '↶'),
        el('button.btn.small', { onclick: () => this.restore(this.redo, this.undo), title: 'Redo (Ctrl+Y)' }, '↷'),
        ...(ai ? [el('span.res-sep'), generateButton('sprite', {
          fields: () => [{ key: 'width', label: 'Width', value: this.frameW(), min: 1, max: 64 }, { key: 'height', label: 'Height', value: this.frameH(), min: 1, max: 64 },
            { key: 'frames', label: 'Frames', value: Math.min(8, this.count()), min: 1, max: 8 }],
          apply: (d) => this.generated(d) })] : [])),
      el('div.res-body', {},
        el('div.res-canvas-box', {}, this.view),
        el('div.res-side', {},
          el('div.res-row', {}, this.colorInput, this.palette),
          el('div.res-row', {}, el('span.res-label', {}, 'Frame'), this.fw, '×', this.fh),
          el('div.res-row', {},
            el('button.btn.small', { onclick: () => this.addFrame(true) }, '+ Copy'), el('button.btn.small', { onclick: () => this.addFrame(false) }, '+ Empty'),
            el('button.btn.small', { onclick: () => this.removeFrame() }, '− Remove')),
          this.frames,
          el('div.res-row', {}, el('span.res-label', {}, 'Animation'), this.fps, 'fps'),
          this.preview)));
    host.append(this.node);
    this.view.addEventListener('pointerdown', (e) => this.down(e));
    this.view.addEventListener('contextmenu', (e) => e.preventDefault());
    this.setTool('pencil');
    this.ready = this.load(src, width, height);
    this.animate();
  }

  async load(src, width, height) {
    if (src) {
      const img = await new Promise((resolve, reject) => { const i = new Image(); i.onload = () => resolve(i); i.onerror = () => reject(new Error('not a picture')); i.src = src; });
      this.sheet.width = img.naturalWidth;
      this.sheet.height = img.naturalHeight;
      this.ctx().drawImage(img, 0, 0);
    } else {
      this.sheet.width = width;
      this.sheet.height = height;
    }
    // frames: square ones when the sheet is a row of squares, else the whole picture
    const h = this.sheet.height;
    const square = this.sheet.width % h === 0 && this.sheet.width > h;
    this.fw.value = square ? h : this.sheet.width;
    this.fh.value = h;
    this.zoom = Math.max(2, Math.min(32, Math.floor(480 / Math.max(Number(this.fw.value), Number(this.fh.value)))));
    this.node.querySelector('input[type=range]').value = this.zoom;
    this.fillPalette();
    this.draw();
  }

  ctx() { return this.sheet.getContext('2d', { willReadFrequently: true }); }
  frameW() { return Math.max(1, Number(this.fw.value) || this.sheet.width); }
  frameH() { return Math.max(1, Number(this.fh.value) || this.sheet.height); }
  cols() { return Math.max(1, Math.floor(this.sheet.width / this.frameW())); }
  count() { return Math.max(1, this.cols() * Math.max(1, Math.floor(this.sheet.height / this.frameH()))); }
  origin(f = this.frame) { return [(f % this.cols()) * this.frameW(), Math.floor(f / this.cols()) * this.frameH()]; }

  frameSize() { this.frame = Math.min(this.frame, this.count() - 1); this.draw(); }

  setTool(t) {
    this.tool = t;
    this.toolButtons.forEach((b, i) => b.classList.toggle('on', TOOLS[i][0] === t));
  }

  pick(color) {
    this.color = color;
    this.colorInput.value = color;
    this.fillPalette();
  }

  /** The default palette, then the colours the sheet uses (up to 32). */
  fillPalette() {
    const used = new Set();
    const d = this.sheet.width ? this.ctx().getImageData(0, 0, this.sheet.width, this.sheet.height).data : [];
    for (let i = 0; i < d.length && used.size < 32; i += 4) if (d[i + 3] > 0) used.add(hex(d[i], d[i + 1], d[i + 2]));
    this.palette.replaceChildren(...[...new Set([...PALETTE, ...used])].map((c) => el('button.res-swatch', {
      title: c, style: { background: c }, className: 'res-swatch' + (c === this.color ? ' on' : ''), onclick: () => this.pick(c),
    })));
  }

  draw() {
    const fw = this.frameW();
    const fh = this.frameH();
    const z = this.zoom;
    const [ox, oy] = this.origin();
    this.view.width = fw * z;
    this.view.height = fh * z;
    const g = this.view.getContext('2d');
    g.imageSmoothingEnabled = false;
    for (let y = 0; y < fh; y++) for (let x = 0; x < fw; x++) {
      g.fillStyle = (x + y) % 2 ? '#3a3d42' : '#2b2d31';
      g.fillRect(x * z, y * z, z, z);
    }
    g.drawImage(this.sheet, ox, oy, fw, fh, 0, 0, fw * z, fh * z);
    if (this.grid && z >= 4) {
      g.fillStyle = 'rgba(255,255,255,.08)';
      for (let x = 1; x < fw; x++) g.fillRect(x * z, 0, 1, fh * z);
      for (let y = 1; y < fh; y++) g.fillRect(0, y * z, fw * z, 1);
    }
    this.drawFrames();
  }

  drawFrames() {
    const fw = this.frameW();
    const fh = this.frameH();
    const scale = Math.max(1, Math.floor(48 / Math.max(fw, fh)));
    this.frames.replaceChildren(...Array.from({ length: this.count() }, (_, f) => {
      const c = el('canvas.res-thumb' + (f === this.frame ? '.on' : ''), { width: fw * scale, height: fh * scale, title: `Frame ${f + 1}: x ${this.origin(f)[0]}, y ${this.origin(f)[1]}`,
        onclick: () => { this.frame = f; this.draw(); } });
      const g = c.getContext('2d');
      g.imageSmoothingEnabled = false;
      g.drawImage(this.sheet, ...this.origin(f), fw, fh, 0, 0, fw * scale, fh * scale);
      return c;
    }));
  }

  animate() {
    let last = 0;
    let f = 0;
    const tick = (t) => {
      if (!this.node.isConnected && last) return;
      this.raf = requestAnimationFrame(tick);
      if (t - last < 1000 / Math.max(1, Number(this.fps.value) || 8)) return;
      last = t;
      f = (f + 1) % this.count();
      const fw = this.frameW();
      const fh = this.frameH();
      const scale = Math.max(1, Math.floor(96 / Math.max(fw, fh)));
      this.preview.width = fw * scale;
      this.preview.height = fh * scale;
      const g = this.preview.getContext('2d');
      g.imageSmoothingEnabled = false;
      g.drawImage(this.sheet, ...this.origin(f), fw, fh, 0, 0, fw * scale, fh * scale);
    };
    this.raf = requestAnimationFrame(tick);
  }

  pixel(e) {
    const r = this.view.getBoundingClientRect();
    const x = Math.floor((e.clientX - r.left) / this.zoom);
    const y = Math.floor((e.clientY - r.top) / this.zoom);
    return x >= 0 && y >= 0 && x < this.frameW() && y < this.frameH() ? [x, y] : null;
  }

  down(e) {
    const p = this.pixel(e);
    if (!p) return;
    const tool = e.button === 2 ? 'eraser' : this.tool;
    if (tool === 'picker') { this.pickAt(p); return; }
    this.remember();
    if (tool === 'fill') { this.fill(p); this.changed(); return; }
    this.paint(p, tool);
    const move = (ev) => { const q = this.pixel(ev); if (q) this.paint(q, tool); };
    const up = () => { removeEventListener('pointermove', move); removeEventListener('pointerup', up); this.changed(); };
    addEventListener('pointermove', move);
    addEventListener('pointerup', up);
  }

  paint([x, y], tool) {
    const [ox, oy] = this.origin();
    const g = this.ctx();
    if (tool === 'eraser') g.clearRect(ox + x, oy + y, 1, 1);
    else { g.fillStyle = this.color; g.fillRect(ox + x, oy + y, 1, 1); }
    this.draw();
  }

  pickAt([x, y]) {
    const [ox, oy] = this.origin();
    const d = this.ctx().getImageData(ox + x, oy + y, 1, 1).data;
    if (d[3] > 0) this.pick(hex(d[0], d[1], d[2]));
    this.setTool('pencil');
  }

  /** Flood fill inside the frame (same colour, 4 neighbours). */
  fill([x, y]) {
    const [ox, oy] = this.origin();
    const fw = this.frameW();
    const fh = this.frameH();
    const g = this.ctx();
    const img = g.getImageData(ox, oy, fw, fh);
    const d = img.data;
    const at = (px, py) => (py * fw + px) * 4;
    const target = d.slice(at(x, y), at(x, y) + 4).join();
    const [r, gr, b] = rgb(this.color);
    if (target === [r, gr, b, 255].join()) return;
    const stack = [[x, y]];
    while (stack.length) {
      const [px, py] = stack.pop();
      if (px < 0 || py < 0 || px >= fw || py >= fh) continue;
      const i = at(px, py);
      if (d.slice(i, i + 4).join() !== target) continue;
      d[i] = r; d[i + 1] = gr; d[i + 2] = b; d[i + 3] = 255;
      stack.push([px + 1, py], [px - 1, py], [px, py + 1], [px, py - 1]);
    }
    g.putImageData(img, ox, oy);
    this.draw();
  }

  /** A frame more at the end of the row (the sheet grows by a frame's width when the row is full). */
  addFrame(copy) {
    this.remember();
    const fw = this.frameW();
    const fh = this.frameH();
    const [sx, sy] = this.origin();
    const old = this.snapshot();
    const n = this.count();
    if (this.sheet.height <= fh) {
      this.sheet.width = (n + 1) * fw;
      this.sheet.height = fh;
    } else {
      this.sheet.height += fh;
    }
    this.ctx().putImageData(old.data, 0, 0);
    const [tx, ty] = this.origin(n);
    if (copy) this.ctx().drawImage(this.sheetCopy(old), sx, sy, fw, fh, tx, ty, fw, fh);
    this.frame = n;
    this.changed();
    this.draw();
  }

  /** The current frame removed (the next ones move back), only on a sheet of one row. */
  removeFrame() {
    if (this.count() <= 1) return;
    if (this.sheet.height > this.frameH()) { notify('Frames are removed on a sheet of one row only.', 'info'); return; }
    this.remember();
    const fw = this.frameW();
    const old = this.sheetCopy(this.snapshot());
    const keep = this.count() - 1;
    this.sheet.width = keep * fw;
    for (let f = 0, to = 0; f <= keep; f++) {
      if (f === this.frame) continue;
      this.ctx().drawImage(old, f * fw, 0, fw, this.sheet.height, to * fw, 0, fw, this.sheet.height);
      to++;
    }
    this.frame = Math.min(this.frame, keep - 1);
    this.changed();
    this.draw();
  }

  /** A generated sprite ({width, height, palette, frames: [rows]}): a new sheet of its frames side by side (Undo: the old one). */
  generated({ width, height, palette, frames }) {
    this.remember();
    this.sheet.width = width * frames.length;
    this.sheet.height = height;
    const g = this.ctx();
    g.clearRect(0, 0, this.sheet.width, this.sheet.height);
    frames.forEach((rows, f) => rows.forEach((row, y) => [...row].forEach((c, x) => {
      if (c === '.') return;
      g.fillStyle = palette[c];
      g.fillRect(f * width + x, y, 1, 1);
    })));
    this.fw.value = width;
    this.fh.value = height;
    this.frame = 0;
    this.fillPalette();
    this.changed();
    this.draw();
  }

  snapshot() { return { w: this.sheet.width, h: this.sheet.height, data: this.ctx().getImageData(0, 0, this.sheet.width, this.sheet.height) }; }

  sheetCopy(s) {
    const c = document.createElement('canvas');
    c.width = s.w;
    c.height = s.h;
    c.getContext('2d').putImageData(s.data, 0, 0);
    return c;
  }

  remember() {
    this.undo.push(this.snapshot());
    if (this.undo.length > 60) this.undo.shift();
    this.redo.length = 0;
  }

  restore(from, to) {
    if (!from.length) return;
    to.push(this.snapshot());
    const s = from.pop();
    this.sheet.width = s.w;
    this.sheet.height = s.h;
    this.ctx().putImageData(s.data, 0, 0);
    this.frame = Math.min(this.frame, this.count() - 1);
    this.changed();
    this.draw();
  }

  changed() {
    this.dirty = true;
    this.status.textContent = '● not saved';
    this.fillPalette();
  }

  async save() {
    const blob = await new Promise((resolve) => this.sheet.toBlob(resolve, 'image/png'));
    const bytes = new Uint8Array(await blob.arrayBuffer());
    try {
      await this.saveFile(base64(bytes));
      this.dirty = false;
      this.status.textContent = 'saved';
    } catch (e) { notify(e.message, 'error', { title: 'Cannot save ' + this.path }); }
  }

  key(e) {
    if (e.target.matches('input')) return;
    const k = e.key.toLowerCase();
    if ((e.ctrlKey || e.metaKey) && k === 's') { e.preventDefault(); e.stopPropagation(); this.save(); }
    else if ((e.ctrlKey || e.metaKey) && k === 'z') { e.preventDefault(); e.shiftKey ? this.restore(this.redo, this.undo) : this.restore(this.undo, this.redo); }
    else if ((e.ctrlKey || e.metaKey) && k === 'y') { e.preventDefault(); this.restore(this.redo, this.undo); }
    else if (!e.ctrlKey && !e.metaKey) {
      const t = TOOLS.find((x) => x[3] === k);
      if (t) this.setTool(t[0]);
      else if (k === 'arrowright') { this.frame = (this.frame + 1) % this.count(); this.draw(); }
      else if (k === 'arrowleft') { this.frame = (this.frame + this.count() - 1) % this.count(); this.draw(); }
    }
  }

  destroy() { cancelAnimationFrame(this.raf); }
}

const hex = (r, g, b) => '#' + [r, g, b].map((v) => v.toString(16).padStart(2, '0')).join('');
const rgb = (h) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16));
