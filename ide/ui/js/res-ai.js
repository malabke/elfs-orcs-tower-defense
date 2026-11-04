// "Generate…" in the resource editors: a description, then Claude (this computer's `claude`, the user's own login), OpenCode
// (its `opencode run`), a chat model (online with the user's key: OpenAI, Mistral, Groq, OpenRouter, Gemini; or a local one
// that needs none and sends nothing out: Ollama, LM Studio, llama.cpp, any OpenAI-compatible server) or a picture service
// (sprites, tilesets) or a sound service (real sound effects and music as mp3 files, with the user's key) makes the sprite / sound / tune / level / tileset, checked by the IDE's core (ResourceGenerator) before the editor gets it.
// Keys stay in the IDE's settings on this computer, never in the project.
import { backend } from './backend.js';
import { el, dialog, prefs, notify } from './ui.js';

const MODELS = [['haiku', 'Haiku (fast)'], ['sonnet', 'Sonnet'], ['opus', 'Opus (best)']];
const TITLES = { sprite: 'Generate a sprite', sfx: 'Generate a sound effect', music: 'Generate a tune', level: 'Generate a level', tileset: 'Generate a tileset' };
const EXAMPLES = { sprite: 'a small knight walking, blue armour', sfx: 'a coin picked up, bright and short', music: 'a cheerful level theme in C major',
  level: 'an easy first level: a few platforms, 5 coins, 2 enemies, a pit', tileset: 'a green forest ground: grass top, earth, stone bricks, a wooden platform' };
let providers = null;

/** Whether this IDE can generate: the desktop IDE with Claude Code installed (not the web IDE, whose Claude is remote). */
export function aiAvailable(capabilities = {}) {
  return backend.kind === 'native' && !!capabilities.claude && !capabilities.claudeRemote && !capabilities.readOnly;
}

/**
 * The toolbar button: opens the dialog; apply(data) gets the checked result. fields(): the number inputs of the options
 * ([{key, label, value, min, max}]), read when the dialog opens.
 */
export function generateButton(kind, { fields = () => [], apply, dir = '' }) {
  return el('button.btn.small', { title: 'Describe it: Claude, OpenCode, an online model with your key, or a local one (Ollama, LM Studio…) makes it', onclick: () => open(kind, fields(), apply, dir) }, '✦ Generate…');
}

/** A real sound from a sound service: saved in the project beside the resource being edited (mp3, the game plays it as it is). */
async function saveAudio(kind, data, prompt, dir) {
  const slug = prompt.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 36) || kind;
  const path = `${dir || (kind === 'music' ? 'assets/music' : 'assets/sfx')}/${slug}-${Date.now().toString(36).slice(-4)}.${data.ext || 'mp3'}`;
  await backend.invoke('file.write', { path, base64: data.audio.slice(data.audio.indexOf(',') + 1) });
  notify(`Saved ${path} (${Math.round(data.size / 1024)} KB).`, 'success', { title: kind === 'music' ? 'Music generated' : 'Sound generated',
    actions: [{ label: 'Open', action: () => document.dispatchEvent(new CustomEvent('jumphp:open-file', { detail: { path } })) }] });
}

async function open(kind, fields, apply, dir) {
  providers ??= await backend.invoke('ai.providers').catch(() => [{ id: 'claude', name: 'Claude', needsKey: false }]);
  const usable = providers.filter((p) => (p.makes || ['sprite', 'sfx', 'music', 'level']).includes(kind));
  const text = el('textarea.input', { rows: 4, placeholder: 'e.g. ' + EXAMPLES[kind] }, prefs.get('aiPrompt:' + kind, ''));
  const inputs = Object.fromEntries(fields.map((f) => [f.key, el('input.input.small.res-num', { type: 'number', min: f.min, max: f.max, value: f.value })]));
  const provider = el('select.input.small', { onchange: () => sync() }, ...usable.map((p) => el('option', { value: p.id }, p.name)));
  provider.value = prefs.get('aiProvider:' + kind, 'claude');
  if (!provider.value) provider.value = 'claude';
  const model = el('select.input.small', {}, ...MODELS.map(([v, label]) => el('option', { value: v }, label)));
  model.value = prefs.get('aiModel', 'sonnet');
  // the other providers: a model name, with the ones the server / OpenCode has to choose from
  const typed = el('input.input.small', { list: 'ai-models', placeholder: 'model name' });
  const names = el('datalist', { id: 'ai-models' });
  const base = el('input.input.small', { placeholder: 'http://127.0.0.1:11434/v1' });
  const key = el('input.input.small', { type: 'password', placeholder: 'API key (kept in the IDE on this computer)' });
  const modelRow = el('label.res-row', {}, el('span.res-label', {}, 'Model'), model);
  const typedRow = el('label.res-row', {}, el('span.res-label', {}, 'Model'), typed, names);
  const baseRow = el('label.res-row', {}, el('span.res-label', {}, 'Server'), base);
  const keyRow = el('label.res-row', {}, el('span.res-label', {}, 'Key'), key);
  // a real sound has a length (a sound service): seconds
  const secs = el('input.input.small.res-num', { type: 'number', min: 1, max: kind === 'music' ? 300 : 30, value: prefs.get('aiSeconds:' + kind, kind === 'music' ? 30 : 2) });
  const secsRow = el('label.res-row', {}, el('span.res-label', {}, 'Seconds'), secs);
  const status = el('p.res-note', {}, '');
  const current = () => usable.find((x) => x.id === provider.value) || usable[0];
  const listModels = async () => {
    const p = current();
    names.replaceChildren();
    if (p.kind !== 'chat' && p.kind !== 'opencode') return;
    const found = await backend.invoke('ai.models', { provider: p.id, baseUrl: base.value.trim(), key: key.value.trim() }).catch(() => []);
    for (const n of found.slice(0, 200)) names.append(el('option', { value: n }));
    status.textContent = p.local && !found.length && p.kind === 'chat' ? `No model found at ${base.value.trim() || p.baseUrl}: is ${p.name.split(' (')[0]} running?` : '';
  };
  const sync = () => {
    const p = current();
    modelRow.hidden = p.kind !== 'claude';
    typedRow.hidden = p.kind !== 'chat' && p.kind !== 'opencode';
    baseRow.hidden = p.kind !== 'chat';
    keyRow.hidden = !p.needsKey;
    secsRow.hidden = p.kind !== 'audio';
    key.value = p.needsKey ? prefs.get('aiKey:' + p.id, '') : '';
    base.value = p.kind === 'chat' ? prefs.get('aiBase:' + p.id, p.baseUrl) : '';
    typed.value = typedRow.hidden ? '' : prefs.get('aiTyped:' + p.id, p.model);
    status.textContent = '';
    listModels();
  };
  sync();
  dialog({
    title: TITLES[kind],
    body: el('div.res-ai', {},
      el('label', {}, 'What to make'), text,
      ...fields.map((f) => el('label.res-row', {}, el('span.res-label', {}, f.label), inputs[f.key])),
      usable.length > 1 ? el('label.res-row', {}, el('span.res-label', {}, 'With'), provider) : null,
      modelRow, baseRow, typedRow, keyRow, secsRow, status,
      el('p.res-note', {}, 'The result replaces what the editor shows (Undo brings it back); save to keep it.')),
    buttons: [{ label: 'Cancel' }, {
      label: 'Generate', primary: true, action: async () => {
        const options = Object.fromEntries(fields.map((f) => [f.key, Number(inputs[f.key].value)]));
        if (!secsRow.hidden) { options.seconds = Number(secs.value) || 2; prefs.set('aiSeconds:' + kind, options.seconds); }
        prefs.set('aiPrompt:' + kind, text.value);
        prefs.set('aiProvider:' + kind, provider.value);
        prefs.set('aiModel', model.value);
        const p = current();
        if (!typedRow.hidden) prefs.set('aiTyped:' + p.id, typed.value.trim());
        if (!baseRow.hidden) prefs.set('aiBase:' + p.id, base.value.trim());
        if (!keyRow.hidden && key.value.trim()) prefs.set('aiKey:' + provider.value, key.value.trim());
        status.textContent = 'Generating… (up to a minute)';
        try {
          const data = await backend.invoke('ai.generate', { kind, prompt: text.value, options, provider: provider.value, key: key.value, model: modelRow.hidden ? typed.value.trim() : model.value, baseUrl: base.value.trim() });
          if (data?.audio) await saveAudio(kind, data, text.value, dir);
          else apply(data);
        } finally {
          status.textContent = '';
        }
      },
    }],
  });
}
