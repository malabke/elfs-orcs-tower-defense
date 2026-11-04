// The sound effect editor (*.sfx.json): a generator's settings from a preset or at random, heard and drawn at each
// change. The JSON stays in the file (the editor's source); saving it also writes the WAV the game plays next to it.
import { el, copyText, notify } from './ui.js';
import * as S from './res-sound.js';

export class SfxEditor {
  /** host: the view; text: the file's JSON; onChange(text): the new JSON (the tab's model) */
  constructor(host, { path, text, onChange }) {
    this.path = path;
    this.onChange = onChange;
    this.sfx = S.sfxOf(parse(text));
    this.undo = [];
    this.redo = [];
    this.inputs = {};
    this.wave = el('select.input.small', { onchange: () => { this.remember(); this.sfx.wave = this.wave.value; this.changed(true); } },
      ...S.WAVES.map((w) => el('option', { value: w }, w)));
    const rows = S.SFX_KEYS.map(([key, label, min, max]) => {
      const value = el('span.res-value');
      const input = el('input', { type: 'range', min, max, step: 1,
        onpointerdown: () => this.remember(), onkeydown: () => this.remember(),
        oninput: () => { this.sfx[key] = Number(input.value); value.textContent = input.value; this.changed(false); },
        onchange: () => this.hear() });
      this.inputs[key] = { input, value };
      return el('label.res-row', {}, el('span.res-label', {}, label), input, value);
    });
    this.canvas = el('canvas.res-wave', { width: 640, height: 140 });
    this.snippet = el('code.res-snippet');
    this.node = el('div.res-editor.res-sfx', { tabIndex: 0, onkeydown: (e) => this.key(e) },
      el('div.res-toolbar', {},
        el('button.btn.primary', { onclick: () => this.hear(), title: 'Play (Space)' }, '▶ Play'),
        el('span.res-sep'),
        ...Object.keys(S.PRESETS).map((p) => el('button.btn.small', { onclick: () => this.preset(p) }, p)),
        el('button.btn.small', { onclick: () => this.preset('random') }, 'random'),
        el('span.res-sep'),
        el('button.btn.small', { onclick: () => this.restore(this.undo, this.redo), title: 'Undo (Ctrl+Z)' }, '↶'),
        el('button.btn.small', { onclick: () => this.restore(this.redo, this.undo), title: 'Redo (Ctrl+Y)' }, '↷')),
      el('div.res-body', {},
        el('div.res-fields', {}, el('label.res-row', {}, el('span.res-label', {}, 'Wave'), this.wave), ...rows),
        el('div.res-side', {}, this.canvas,
          el('p.res-note', {}, 'Saving (Ctrl+S) also writes ', el('b', {}, S.wavPathOf(path).split('/').pop()), ' next to this file: load it with ',
            el('code', {}, `$this->sound()->loadSfx('${name(path)}', '${S.wavPathOf(path)}')`)),
          el('p.res-note', {}, 'Or without a file, close to it with the engine\'s Synth: ',
            el('button.btn.small', { onclick: () => { copyText(this.snippet.textContent); notify('Copied.', 'success'); } }, 'Copy')),
          this.snippet)));
    host.append(this.node);
    this.fields();
    this.draw();
  }

  setText(text) {
    const data = parse(text);
    if (data && JSON.stringify(S.sfxOf(data)) !== JSON.stringify(this.sfx)) { this.sfx = S.sfxOf(data); this.fields(); this.draw(); }
  }

  fields() {
    this.wave.value = this.sfx.wave;
    for (const [key, { input, value }] of Object.entries(this.inputs)) { input.value = this.sfx[key]; value.textContent = this.sfx[key]; }
    this.snippet.textContent = S.synthSnippet(name(this.path), this.sfx);
  }

  remember() {
    this.undo.push(JSON.stringify(this.sfx));
    if (this.undo.length > 100) this.undo.shift();
    this.redo.length = 0;
  }

  restore(from, to) {
    if (!from.length) return;
    to.push(JSON.stringify(this.sfx));
    this.sfx = JSON.parse(from.pop());
    this.fields();
    this.changed(true);
  }

  preset(p) {
    this.remember();
    this.sfx = p === 'random' ? random() : { ...S.PRESETS[p] };
    this.fields();
    this.changed(true);
  }

  changed(hear) {
    this.snippet.textContent = S.synthSnippet(name(this.path), this.sfx);
    this.onChange(JSON.stringify(this.sfx, null, 2) + '\n');
    this.draw();
    if (hear) this.hear();
  }

  hear() { S.play('sfx', this.sfx); }

  async draw() {
    const me = this.drawing = (this.drawing || 0) + 1;
    const buffer = await S.render('sfx', this.sfx, { rate: 22050 });
    if (me !== this.drawing) return;
    drawWave(this.canvas, buffer.getChannelData(0), `${Math.round(S.sfxLength(this.sfx) * 1000)} ms`);
  }

  key(e) {
    if (e.target.matches('input[type=text], select, textarea')) return;
    const k = e.key.toLowerCase();
    if ((e.ctrlKey || e.metaKey) && k === 'z') { e.preventDefault(); e.shiftKey ? this.restore(this.redo, this.undo) : this.restore(this.undo, this.redo); }
    else if ((e.ctrlKey || e.metaKey) && k === 'y') { e.preventDefault(); this.restore(this.redo, this.undo); }
    else if (k === ' ' && !e.ctrlKey && !e.metaKey) { e.preventDefault(); this.hear(); }
  }

  destroy() { S.stop(); }
}

export function drawWave(canvas, data, label) {
  const g = canvas.getContext('2d');
  const { width, height } = canvas;
  g.fillStyle = '#0a1028';
  g.fillRect(0, 0, width, height);
  g.fillStyle = '#ffd400';
  const per = Math.max(1, Math.floor(data.length / width));
  for (let x = 0; x < width; x++) {
    let min = 0, max = 0;
    for (let i = x * per; i < Math.min(data.length, (x + 1) * per); i++) { min = Math.min(min, data[i]); max = Math.max(max, data[i]); }
    const scale = height;
    g.fillRect(x, height / 2 - max * scale, 1, Math.max(1, (max - min) * scale));
  }
  g.fillStyle = '#cfd8ff';
  g.font = '600 12px system-ui, sans-serif';
  g.fillText(label, 8, 16);
}

function random() {
  const r = (min, max) => Math.round(min + Math.random() * (max - min));
  return { wave: S.WAVES[r(0, S.WAVES.length - 1)], start: r(80, 3000), end: r(80, 3000), attack: r(0, 40), sustain: r(10, 200),
    decay: r(40, 500), volume: r(4, 8), vibrato: Math.random() < 0.3 ? r(10, 80) : 0, speed: r(4, 30),
    repeat: Math.random() < 0.25 ? r(2, 4) : 1, gap: r(0, 60) };
}

function parse(text) { try { return JSON.parse(text || '{}'); } catch { return null; } }
const name = (path) => path.split('/').pop().replace(/\.(sfx|music)\.json$/, '');
