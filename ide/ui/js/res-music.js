// The tune editor (*.music.json): a piano roll a voice at a time on 4 voices (2 squares, a triangle bass, noise
// drums), 16 steps a bar. A voice plays one note at a time: a new note cuts the one under it. Click: a note (drag to
// make it longer); click a note or right click: remove it. Saving also writes the WAV the game loops, next to it.
import { el } from './ui.js';
import * as S from './res-sound.js';
import { generateButton } from './res-ai.js';

const LOW = 24;
const HIGH = 96;
const W = 16;
const H = 12;
const DH = 28;
const KEYS = 46;
const RULER = 18;
const COLOURS = ['#ffd400', '#3fd0ff', '#ff6b9a', '#9cff6b'];

export class MusicEditor {
  constructor(host, { path, text, onChange, ai = false }) {
    this.path = path;
    this.onChange = onChange;
    this.music = S.musicOf(parse(text));
    this.voice = 0;
    this.undo = [];
    this.redo = [];
    this.voiceButtons = S.VOICE_NAMES.map((label, v) => el('button.btn.small', { onclick: () => { this.voice = v; this.fields(); this.layout(); this.scrollToNotes(); } },
      el('span.res-dot', { style: { background: COLOURS[v] } }), label));
    this.tempo = el('input.input.small.res-num', { type: 'number', min: 40, max: 300, onchange: () => this.set('tempo', this.tempo.value) });
    this.bars = el('input.input.small.res-num', { type: 'number', min: 1, max: 64, onchange: () => this.set('bars', this.bars.value) });
    this.volume = el('input', { type: 'range', min: 0, max: 10, step: 1, onchange: () => { this.remember(); this.music.voices[this.voice].volume = Number(this.volume.value); this.changed(); } });
    this.playButton = el('button.btn.primary', { onclick: () => this.toggle(), title: 'Play / stop (Space)' }, '▶ Play');
    this.loop = el('input', { type: 'checkbox', checked: true });
    this.canvas = el('canvas.res-roll');
    this.box = el('div.res-roll-box', {}, this.canvas);
    this.node = el('div.res-editor.res-music', { tabIndex: 0, onkeydown: (e) => this.key(e) },
      el('div.res-toolbar', {}, this.playButton, el('label.res-inline', {}, this.loop, 'Loop'), el('span.res-sep'), ...this.voiceButtons),
      el('div.res-toolbar', {},
        el('label.res-inline', {}, 'Tempo', this.tempo), el('label.res-inline', {}, 'Bars', this.bars),
        el('label.res-inline', {}, 'Volume of this voice', this.volume), el('span.res-sep'),
        el('button.btn.small', { onclick: () => this.restore(this.undo, this.redo), title: 'Undo (Ctrl+Z)' }, '↶'),
        el('button.btn.small', { onclick: () => this.restore(this.redo, this.undo), title: 'Redo (Ctrl+Y)' }, '↷'),
        ...(ai ? [generateButton('music', { dir: this.path.replace(/\/[^/]*$/, ''),
          fields: () => [{ key: 'bars', label: 'Bars', value: Math.min(16, this.music.bars), min: 1, max: 16 }],
          apply: (d) => { this.remember(); this.music = S.musicOf(d); this.fields(); this.layout(); this.changed(); this.scrollToNotes(); } })] : []),
        el('span.res-note', {}, `Saving (Ctrl+S) also writes ${S.wavPathOf(path).split('/').pop()}: $this->sound()->loadMusic('${name(path)}', '${S.wavPathOf(path)}')`)),
      this.box);
    host.append(this.node);
    this.canvas.addEventListener('pointerdown', (e) => this.down(e));
    this.canvas.addEventListener('contextmenu', (e) => e.preventDefault());
    this.fields();
    this.layout();
    requestAnimationFrame(() => this.scrollToNotes());
  }

  setText(text) {
    const data = parse(text);
    if (data && JSON.stringify(S.musicOf(data)) !== JSON.stringify(this.music)) { this.music = S.musicOf(data); this.fields(); this.layout(); }
  }

  drums() { return this.voice === 3; }
  rows() { return this.drums() ? S.DRUMS.length : HIGH - LOW + 1; }
  rowHeight() { return this.drums() ? DH : H; }
  pitchOf(row) { return this.drums() ? row : HIGH - row; }
  rowOf(pitch) { return this.drums() ? pitch : HIGH - pitch; }
  steps() { return this.music.bars * 16; }

  fields() {
    this.tempo.value = this.music.tempo;
    this.bars.value = this.music.bars;
    this.volume.value = this.music.voices[this.voice].volume;
    this.voiceButtons.forEach((b, v) => b.classList.toggle('on', v === this.voice));
  }

  set(key, value) {
    this.remember();
    this.music[key] = Number(value);
    this.music = S.musicOf(this.music);
    this.fields();
    this.layout();
    this.changed();
  }

  layout() {
    this.canvas.width = KEYS + this.steps() * W;
    this.canvas.height = RULER + this.rows() * this.rowHeight();
    this.draw();
  }

  scrollToNotes() {
    const notes = this.music.voices[this.voice].notes;
    if (this.drums() || !notes.length) { this.box.scrollTop = this.drums() ? 0 : RULER + this.rowOf(72) * H - 60; return; }
    const top = Math.max(...notes.map((n) => n[1]));
    this.box.scrollTop = RULER + this.rowOf(top) * H - 40;
  }

  draw(at = null) {
    const g = this.canvas.getContext('2d');
    const { width, height } = this.canvas;
    const rh = this.rowHeight();
    g.fillStyle = '#0a1028';
    g.fillRect(0, 0, width, height);
    for (let row = 0; row < this.rows(); row++) {
      const y = RULER + row * rh;
      const pitch = this.pitchOf(row);
      const black = !this.drums() && [1, 3, 6, 8, 10].includes(pitch % 12);
      g.fillStyle = black ? '#0d1533' : '#131d45';
      g.fillRect(KEYS, y, width - KEYS, rh - 1);
      g.fillStyle = black ? '#1b1b1b' : '#d8d8d8';
      g.fillRect(0, y, KEYS - 2, rh - 1);
      if (this.drums() || pitch % 12 === 0) {
        g.fillStyle = black ? '#ddd' : '#333';
        g.font = '10px system-ui, sans-serif';
        g.fillText(this.drums() ? S.DRUMS[row].name : S.noteName(pitch), 3, y + rh - 3);
      }
    }
    for (let s = 0; s <= this.steps(); s++) {
      const x = KEYS + s * W;
      g.fillStyle = s % 16 === 0 ? 'rgba(255,255,255,.35)' : s % 4 === 0 ? 'rgba(255,255,255,.14)' : 'rgba(255,255,255,.05)';
      g.fillRect(x, RULER, 1, height - RULER);
      if (s % 16 === 0 && s < this.steps()) { g.fillStyle = '#cfd8ff'; g.font = '11px system-ui, sans-serif'; g.fillText(String(s / 16 + 1), x + 3, 13); }
    }
    const rect = (s, pitch, length, v) => {
      const row = v === 3 ? pitch : HIGH - pitch;
      const h = v === 3 ? DH : H;
      return [KEYS + s * W + 1, RULER + row * h + 1, length * W - 2, h - 3];
    };
    if (!this.drums()) {
      this.music.voices.forEach((other, v) => {
        if (v === this.voice || v === 3) return;
        g.fillStyle = COLOURS[v] + '40';
        for (const [s, p, l] of other.notes) g.fillRect(...rect(s, p, l, v));
      });
    }
    g.fillStyle = COLOURS[this.voice];
    for (const [s, p, l] of this.music.voices[this.voice].notes) g.fillRect(...rect(s, p, this.drums() ? 1 : l, this.voice));
    if (at !== null) { g.fillStyle = 'rgba(255,255,255,.7)'; g.fillRect(KEYS + at * W, RULER, 2, height - RULER); }
  }

  cell(e) {
    const r = this.canvas.getBoundingClientRect();
    const x = e.clientX - r.left;
    const y = e.clientY - r.top;
    if (x < KEYS || y < RULER) return null;
    const step = Math.floor((x - KEYS) / W);
    const row = Math.floor((y - RULER) / this.rowHeight());
    if (step >= this.steps() || row >= this.rows()) return null;
    return { step, pitch: this.pitchOf(row) };
  }

  down(e) {
    const c = this.cell(e);
    if (!c) return;
    const notes = this.music.voices[this.voice].notes;
    const hit = notes.find(([s, p, l]) => p === c.pitch && c.step >= s && c.step < s + (this.drums() ? 1 : l));
    this.remember();
    if (hit || e.button === 2) {
      if (hit) notes.splice(notes.indexOf(hit), 1);
      this.changed();
      this.draw();
      return;
    }
    const note = [c.step, c.pitch, 1];
    this.place(note);
    S.preview(this.voice, c.pitch);
    this.draw();
    if (this.drums()) { this.changed(); return; }
    const move = (ev) => {
      const to = this.cell(ev);
      if (!to) return;
      note[2] = Math.max(1, to.step - note[0] + 1);
      this.place(note);
      this.draw();
    };
    const up = () => { removeEventListener('pointermove', move); removeEventListener('pointerup', up); this.changed(); };
    addEventListener('pointermove', move);
    addEventListener('pointerup', up);
  }

  /** One note at a time a voice: what the new note covers is cut. */
  place(note) {
    const voice = this.music.voices[this.voice];
    const [s, , l] = note;
    const end = s + l;
    const drums = this.drums();
    voice.notes = voice.notes.filter((n) => {
      if (n === note || n[0] >= end || n[0] + (drums ? 1 : n[2]) <= s) return true;
      if (!drums && n[0] < s) { n[2] = s - n[0]; return true; }
      return false;
    });
    if (!voice.notes.includes(note)) voice.notes.push(note);
    voice.notes.sort((a, b) => a[0] - b[0]);
  }

  toggle() {
    if (S.isPlaying()) { S.stop(); this.stopped(); return; }
    S.play('music', this.music, { loop: this.loop.checked, onEnd: () => this.stopped() });
    this.playButton.textContent = '■ Stop';
    const tick = () => {
      if (!S.isPlaying()) return;
      this.draw(S.position());
      this.raf = requestAnimationFrame(tick);
    };
    tick();
  }

  stopped() { cancelAnimationFrame(this.raf); this.playButton.textContent = '▶ Play'; this.draw(); }

  remember() {
    this.undo.push(JSON.stringify(this.music));
    if (this.undo.length > 100) this.undo.shift();
    this.redo.length = 0;
  }

  restore(from, to) {
    if (!from.length) return;
    to.push(JSON.stringify(this.music));
    this.music = JSON.parse(from.pop());
    this.fields();
    this.layout();
    this.changed();
  }

  changed() { this.onChange(JSON.stringify(this.music) + '\n'); }

  key(e) {
    if (e.target.matches('input[type=text], input[type=number], select, textarea')) return;
    const k = e.key.toLowerCase();
    if ((e.ctrlKey || e.metaKey) && k === 'z') { e.preventDefault(); e.shiftKey ? this.restore(this.redo, this.undo) : this.restore(this.undo, this.redo); }
    else if ((e.ctrlKey || e.metaKey) && k === 'y') { e.preventDefault(); this.restore(this.redo, this.undo); }
    else if (k === ' ' && !e.ctrlKey && !e.metaKey) { e.preventDefault(); this.toggle(); }
  }

  destroy() { S.stop(); cancelAnimationFrame(this.raf); }
}

function parse(text) { try { return JSON.parse(text || '{}'); } catch { return null; } }
const name = (path) => path.split('/').pop().replace(/\.(sfx|music)\.json$/, '');
