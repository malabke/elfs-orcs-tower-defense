// "Generate…" in the resource editors: a description, then Claude (this computer's `claude`, the user's own login) or a
// picture service with the user's key (sprites) makes the sprite / sound / tune / level, checked by the IDE's core
// (ResourceGenerator) before the editor gets it. Keys stay in the IDE's settings on this computer, never in the project.
import { backend } from './backend.js';
import { el, dialog, prefs } from './ui.js';

const MODELS = [['haiku', 'Haiku (fast)'], ['sonnet', 'Sonnet'], ['opus', 'Opus (best)']];
const TITLES = { sprite: 'Generate a sprite', sfx: 'Generate a sound effect', music: 'Generate a tune', level: 'Generate a level' };
const EXAMPLES = { sprite: 'a small knight walking, blue armour', sfx: 'a coin picked up, bright and short', music: 'a cheerful level theme in C major',
  level: 'an easy first level: a few platforms, 5 coins, 2 enemies, a pit' };
let providers = null;

/** Whether this IDE can generate: the desktop IDE with Claude Code installed (not the web IDE, whose Claude is remote). */
export function aiAvailable(capabilities = {}) {
  return backend.kind === 'native' && !!capabilities.claude && !capabilities.claudeRemote && !capabilities.readOnly;
}

/**
 * The toolbar button: opens the dialog; apply(data) gets the checked result. fields(): the number inputs of the options
 * ([{key, label, value, min, max}]), read when the dialog opens.
 */
export function generateButton(kind, { fields = () => [], apply }) {
  return el('button.btn.small', { title: 'Describe it, Claude makes it (or a picture service with your key)', onclick: () => open(kind, fields(), apply) }, '✦ Generate…');
}

async function open(kind, fields, apply) {
  providers ??= await backend.invoke('ai.providers').catch(() => [{ id: 'claude', name: 'Claude', needsKey: false }]);
  const usable = providers.filter((p) => p.id === 'claude' || kind === 'sprite');
  const text = el('textarea.input', { rows: 4, placeholder: 'e.g. ' + EXAMPLES[kind] }, prefs.get('aiPrompt:' + kind, ''));
  const inputs = Object.fromEntries(fields.map((f) => [f.key, el('input.input.small.res-num', { type: 'number', min: f.min, max: f.max, value: f.value })]));
  const provider = el('select.input.small', { onchange: () => sync() }, ...usable.map((p) => el('option', { value: p.id }, p.name)));
  provider.value = prefs.get('aiProvider:' + kind, 'claude');
  if (!provider.value) provider.value = 'claude';
  const model = el('select.input.small', {}, ...MODELS.map(([v, label]) => el('option', { value: v }, label)));
  model.value = prefs.get('aiModel', 'sonnet');
  const key = el('input.input.small', { type: 'password', placeholder: 'API key (kept in the IDE on this computer)' });
  const modelRow = el('label.res-row', {}, el('span.res-label', {}, 'Model'), model);
  const keyRow = el('label.res-row', {}, el('span.res-label', {}, 'Key'), key);
  const status = el('p.res-note', {}, '');
  const sync = () => {
    const p = usable.find((x) => x.id === provider.value) || usable[0];
    modelRow.hidden = p.id !== 'claude';
    keyRow.hidden = !p.needsKey;
    key.value = p.needsKey ? prefs.get('aiKey:' + p.id, '') : '';
  };
  sync();
  dialog({
    title: TITLES[kind],
    body: el('div.res-ai', {},
      el('label', {}, 'What to make'), text,
      ...fields.map((f) => el('label.res-row', {}, el('span.res-label', {}, f.label), inputs[f.key])),
      usable.length > 1 ? el('label.res-row', {}, el('span.res-label', {}, 'With'), provider) : null,
      modelRow, keyRow, status,
      el('p.res-note', {}, 'The result replaces what the editor shows (Undo brings it back); save to keep it.')),
    buttons: [{ label: 'Cancel' }, {
      label: 'Generate', primary: true, action: async () => {
        const options = Object.fromEntries(fields.map((f) => [f.key, Number(inputs[f.key].value)]));
        prefs.set('aiPrompt:' + kind, text.value);
        prefs.set('aiProvider:' + kind, provider.value);
        prefs.set('aiModel', model.value);
        if (!keyRow.hidden && key.value.trim()) prefs.set('aiKey:' + provider.value, key.value.trim());
        status.textContent = 'Generating… (up to a minute)';
        try {
          const data = await backend.invoke('ai.generate', { kind, prompt: text.value, options, provider: provider.value, key: key.value, model: model.value });
          apply(data);
        } finally {
          status.textContent = '';
        }
      },
    }],
  });
}
