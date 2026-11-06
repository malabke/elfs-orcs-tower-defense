// The sound effects (*.sfx.json) and tunes (*.music.json) of the resource editors: played with Web Audio, rendered
// offline to the WAV the game plays (22050 Hz, mono, 16 bits; the engine's SoundManager / Synth rate).
// A sound effect: an oscillator (or noise) sliding from one pitch to another, with an envelope, vibrato, repeats.
// A tune: 4 voices (2 squares, a triangle bass, noise drums), 16 steps a bar, notes [step, pitch (MIDI), length].

export const RATE = 22050;
export const WAVES = ['square', 'pulse', 'triangle', 'sawtooth', 'sine', 'noise'];
export const VOICES = ['square', 'pulse', 'triangle', 'noise'];
export const VOICE_NAMES = ['Lead (square)', 'Second (pulse)', 'Bass (triangle)', 'Drums (noise)'];
export const DRUMS = [{ name: 'Kick', type: 'lowpass', hz: 300, len: 0.18, gain: 1 }, { name: 'Snare', type: 'bandpass', hz: 1800, len: 0.14, gain: 0.8 },
  { name: 'Hi-hat', type: 'highpass', hz: 7000, len: 0.05, gain: 0.5 }, { name: 'Crash', type: 'highpass', hz: 4000, len: 0.6, gain: 0.45 }];
export const SFX_KEYS = [['start', 'Start (Hz)', 40, 4000], ['end', 'End (Hz)', 40, 4000], ['attack', 'Attack (ms)', 0, 300],
  ['sustain', 'Sustain (ms)', 0, 1000], ['decay', 'Decay (ms)', 0, 2000], ['volume', 'Volume', 0, 10], ['vibrato', 'Vibrato', 0, 100],
  ['speed', 'Vibrato speed (Hz)', 0, 40], ['repeat', 'Repeat', 1, 8], ['gap', 'Gap (ms)', 0, 300]];
export const PRESETS = {
  jump: { wave: 'square', start: 300, end: 900, attack: 0, sustain: 60, decay: 120, volume: 6, vibrato: 0, speed: 0, repeat: 1, gap: 0 },
  coin: { wave: 'pulse', start: 1800, end: 2600, attack: 0, sustain: 40, decay: 260, volume: 5, vibrato: 0, speed: 0, repeat: 2, gap: 0 },
  spring: { wave: 'triangle', start: 200, end: 1400, attack: 0, sustain: 30, decay: 260, volume: 8, vibrato: 60, speed: 22, repeat: 1, gap: 0 },
  hit: { wave: 'noise', start: 3000, end: 300, attack: 0, sustain: 20, decay: 180, volume: 8, vibrato: 0, speed: 0, repeat: 1, gap: 0 },
  explosion: { wave: 'noise', start: 1200, end: 60, attack: 0, sustain: 80, decay: 900, volume: 10, vibrato: 0, speed: 0, repeat: 1, gap: 0 },
  laser: { wave: 'sawtooth', start: 2400, end: 200, attack: 0, sustain: 20, decay: 200, volume: 5, vibrato: 0, speed: 0, repeat: 1, gap: 0 },
  powerup: { wave: 'square', start: 400, end: 1600, attack: 0, sustain: 40, decay: 60, volume: 5, vibrato: 30, speed: 30, repeat: 4, gap: 20 },
  blip: { wave: 'pulse', start: 880, end: 880, attack: 0, sustain: 40, decay: 40, volume: 5, vibrato: 0, speed: 0, repeat: 1, gap: 0 },
};

export const midiHz = (n) => 440 * 2 ** ((n - 69) / 12);
const NAMES = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
export const noteName = (n) => NAMES[n % 12] + (Math.floor(n / 12) - 1);
export const sfxLength = (p) => (p.repeat * (p.attack + p.sustain + p.decay) + (p.repeat - 1) * p.gap) / 1000;
export const stepLength = (m) => 60 / m.tempo / 4;
export const musicLength = (m) => m.bars * 16 * stepLength(m);

/** A sound effect's settings, whatever the file holds (missing keys from the jump preset). */
export function sfxOf(data) {
  const p = { ...PRESETS.jump, ...(data && typeof data === 'object' ? data : {}) };
  if (!WAVES.includes(p.wave)) p.wave = 'square';
  for (const [key, , min, max] of SFX_KEYS) p[key] = Math.max(min, Math.min(max, Number(p[key]) || 0));
  p.start = Math.max(40, p.start);
  p.end = Math.max(40, p.end);
  return p;
}

/** A tune, whatever the file holds. */
export function musicOf(data) {
  const m = data && typeof data === 'object' ? data : {};
  const voices = VOICES.map((_, v) => {
    const voice = Array.isArray(m.voices) ? m.voices[v] || {} : {};
    const notes = (Array.isArray(voice.notes) ? voice.notes : []).filter((n) => Array.isArray(n) && n.length >= 2)
      .map(([s, pitch, length = 1]) => [Math.max(0, s | 0), pitch | 0, Math.max(1, length | 0)]);
    return { volume: Math.max(0, Math.min(10, Number(voice.volume ?? 7))), notes };
  });
  return { tempo: Math.max(40, Math.min(300, Number(m.tempo) || 120)), bars: Math.max(1, Math.min(64, Number(m.bars) || 2)), voices };
}

const kits = new WeakMap();
function kit(ctx) {
  if (kits.has(ctx)) return kits.get(ctx);
  // the noise is seeded: the same file always renders the same WAV
  const noise = ctx.createBuffer(1, ctx.sampleRate, ctx.sampleRate);
  const d = noise.getChannelData(0);
  let seed = 0x2545f491;
  for (let i = 0; i < d.length; i++) { seed ^= seed << 13; seed ^= seed >>> 17; seed ^= seed << 5; d[i] = ((seed >>> 0) / 4294967295) * 2 - 1; }
  const real = new Float32Array(64);
  const imag = new Float32Array(64);
  for (let n = 1; n < 64; n++) {
    real[n] = (2 / (n * Math.PI)) * Math.sin(2 * Math.PI * n * 0.25);
    imag[n] = (2 / (n * Math.PI)) * (1 - Math.cos(2 * Math.PI * n * 0.25));
  }
  const k = { noise, pulse: ctx.createPeriodicWave(real, imag) };
  kits.set(ctx, k);
  return k;
}

function source(ctx, wave, hz) {
  if (wave === 'noise') {
    const s = ctx.createBufferSource();
    s.buffer = kit(ctx).noise;
    s.loop = true;
    return s;
  }
  const o = ctx.createOscillator();
  if (wave === 'pulse') o.setPeriodicWave(kit(ctx).pulse); else o.type = wave;
  o.frequency.value = hz;
  return o;
}

function scheduleSfx(ctx, out, p, t0, started = []) {
  const one = (p.attack + p.sustain + p.decay) / 1000;
  const peak = (p.volume / 10) * (p.wave === 'noise' ? 0.5 : 0.3);
  for (let r = 0; r < p.repeat; r++) {
    const t = t0 + r * (one + p.gap / 1000);
    const end = t + Math.max(0.01, one);
    const s = source(ctx, p.wave, p.start);
    const gain = ctx.createGain();
    gain.gain.setValueAtTime(0, t);
    gain.gain.linearRampToValueAtTime(peak, t + Math.max(0.002, p.attack / 1000));
    gain.gain.setValueAtTime(peak, t + Math.max(0.002, (p.attack + p.sustain) / 1000));
    gain.gain.linearRampToValueAtTime(0, end);
    let node = s;
    if (p.wave === 'noise') {
      const filter = ctx.createBiquadFilter();
      filter.type = 'lowpass';
      filter.frequency.setValueAtTime(p.start, t);
      filter.frequency.exponentialRampToValueAtTime(p.end, end);
      s.connect(filter);
      node = filter;
    } else {
      s.frequency.setValueAtTime(p.start, t);
      s.frequency.exponentialRampToValueAtTime(p.end, end);
      if (p.vibrato > 0 && p.speed > 0) {
        const lfo = ctx.createOscillator();
        const depth = ctx.createGain();
        lfo.frequency.value = p.speed;
        depth.gain.value = p.vibrato * 1.5;
        lfo.connect(depth).connect(s.detune);
        lfo.start(t);
        lfo.stop(end);
        started.push(lfo);
      }
    }
    node.connect(gain).connect(out);
    s.start(t);
    s.stop(end + 0.01);
    started.push(s);
  }
  return started;
}

function scheduleMusic(ctx, out, m, t0, from = 0, started = []) {
  const step = stepLength(m);
  m.voices.forEach((voice, v) => {
    const wave = VOICES[v];
    const level = (voice.volume / 10) * (wave === 'noise' ? 0.45 : wave === 'triangle' ? 0.32 : 0.14);
    if (level <= 0) return;
    for (const [s, pitch, length] of voice.notes) {
      if (s < from || s >= m.bars * 16) continue;
      const t = t0 + (s - from) * step;
      const gain = ctx.createGain();
      if (wave === 'noise') {
        const drum = DRUMS[pitch] || DRUMS[0];
        const src = source(ctx, 'noise');
        const filter = ctx.createBiquadFilter();
        filter.type = drum.type;
        filter.frequency.value = drum.hz;
        gain.gain.setValueAtTime(level * drum.gain, t);
        gain.gain.exponentialRampToValueAtTime(0.001, t + drum.len);
        src.connect(filter).connect(gain).connect(out);
        src.start(t);
        src.stop(t + drum.len + 0.01);
        started.push(src);
        continue;
      }
      const end = t + length * step;
      const osc = source(ctx, wave, midiHz(pitch));
      gain.gain.setValueAtTime(0, t);
      gain.gain.linearRampToValueAtTime(level, t + 0.004);
      gain.gain.setValueAtTime(level, Math.max(t + 0.004, end - 0.02));
      gain.gain.linearRampToValueAtTime(0, end);
      osc.connect(gain).connect(out);
      osc.start(t);
      osc.stop(end + 0.01);
      started.push(osc);
    }
  });
  return started;
}

let ctx = null;
let playing = null;

function context() {
  ctx ??= new (window.AudioContext || window.webkitAudioContext)();
  if (ctx.state === 'suspended') ctx.resume();
  return ctx;
}

export function stop() {
  if (!playing) return;
  clearTimeout(playing.timer);
  for (const s of playing.started) { try { s.stop(); } catch { /* not started yet */ } }
  playing.master.disconnect();
  playing = null;
}

export const isPlaying = () => !!playing;

/** A sound effect, or a tune from a step (looping if asked); onEnd when it is over. One thing at a time. */
export function play(kind, data, { from = 0, loop = false, onEnd = null } = {}) {
  stop();
  const c = context();
  const master = c.createGain();
  master.connect(c.destination);
  const me = { master, started: [], timer: 0, start: 0, from, data, kind };
  playing = me;
  const t0 = c.currentTime + 0.05;
  if (kind !== 'music') {
    scheduleSfx(c, master, data, t0, me.started);
    me.timer = setTimeout(() => { if (playing === me) { stop(); onEnd?.(); } }, (sfxLength(data) + 0.2) * 1000);
    return;
  }
  const pass = (start, first) => {
    me.start = start;
    me.from = first;
    scheduleMusic(c, master, data, start, first, me.started);
    const end = start + (data.bars * 16 - first) * stepLength(data);
    me.timer = setTimeout(() => {
      if (playing !== me) return;
      if (loop) pass(end, 0); else { stop(); onEnd?.(); }
    }, Math.max(10, (end - c.currentTime - (loop ? 0.25 : -0.1)) * 1000));
  };
  pass(t0, from);
}

/** The step a tune is playing now, or null. */
export function position() {
  if (!playing || playing.kind !== 'music') return null;
  const s = playing.from + (ctx.currentTime - playing.start) / stepLength(playing.data);
  return s < playing.from ? null : Math.floor(s) % (playing.data.bars * 16);
}

/** One note of a voice (heard when it is put). */
export function preview(voice, pitch) {
  const c = context();
  const m = { tempo: 120, bars: 1, voices: VOICES.map((_, v) => ({ volume: v === voice ? 7 : 0, notes: v === voice ? [[0, pitch, 2]] : [] })) };
  const g = c.createGain();
  g.connect(c.destination);
  scheduleMusic(c, g, m, c.currentTime + 0.01);
  setTimeout(() => g.disconnect(), 1000);
}

/** The sound in an AudioBuffer; loop: a tune exactly once round (the game loops it). */
export function render(kind, data, { rate = RATE, loop = false } = {}) {
  const length = kind === 'music' ? musicLength(data) + (loop ? 0 : 0.7) : sfxLength(data) + 0.05;
  const off = new OfflineAudioContext(1, Math.max(1, Math.ceil(length * rate)), rate);
  if (kind === 'music') scheduleMusic(off, off.destination, data, 0); else scheduleSfx(off, off.destination, data, 0);
  return off.startRendering();
}

/** A WAV file (PCM 16 bits, mono) of an AudioBuffer. */
export function wavBytes(buffer) {
  const samples = buffer.getChannelData(0);
  const view = new DataView(new ArrayBuffer(44 + samples.length * 2));
  const text = (o, s) => [...s].forEach((ch, i) => view.setUint8(o + i, ch.charCodeAt(0)));
  text(0, 'RIFF');
  view.setUint32(4, 36 + samples.length * 2, true);
  text(8, 'WAVE');
  text(12, 'fmt ');
  view.setUint32(16, 16, true);
  view.setUint16(20, 1, true);
  view.setUint16(22, 1, true);
  view.setUint32(24, buffer.sampleRate, true);
  view.setUint32(28, buffer.sampleRate * 2, true);
  view.setUint16(32, 2, true);
  view.setUint16(34, 16, true);
  text(36, 'data');
  view.setUint32(40, samples.length * 2, true);
  for (let i = 0; i < samples.length; i++) view.setInt16(44 + i * 2, Math.max(-1, Math.min(1, samples[i])) * 0x7FFF, true);
  return new Uint8Array(view.buffer);
}

export function base64(bytes) {
  let s = '';
  for (let i = 0; i < bytes.length; i += 0x8000) s += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
  return btoa(s);
}

/** The WAV the game plays, next to the source: assets/sfx/jump.sfx.json -> assets/sfx/jump.wav. */
export const wavPathOf = (path) => path.replace(/\.(sfx|music)\.json$/, '.wav');

/** Renders a *.sfx.json / *.music.json text to the bytes of its WAV. */
export async function wavOfFile(path, text) {
  const music = /\.music\.json$/.test(path);
  const data = JSON.parse(text);
  return wavBytes(await render(music ? 'music' : 'sfx', music ? musicOf(data) : sfxOf(data), { loop: music }));
}

/** How the engine's Synth would say it (an approximation: no envelope, vibrato or repeats). */
export function synthSnippet(name, p) {
  const seconds = Math.round(sfxLength(p) * 1000) / 1000;
  const volume = Math.round((p.volume / 10) * 100) / 100;
  if (p.wave === 'noise') return `$this->sound()->synth('${name}', Synth::noise(${seconds}, ${volume}));`;
  const wave = { square: 'square', pulse: 'square', triangle: 'triangle', sine: 'sine', sawtooth: 'saw' }[p.wave];
  return p.start === p.end
    ? `$this->sound()->synth('${name}', Synth::tone(${p.start}, ${seconds}, '${wave}', ${volume}));`
    : `$this->sound()->synth('${name}', Synth::sweep(${p.start}, ${p.end}, ${seconds}, '${wave}', ${volume}));`;
}
