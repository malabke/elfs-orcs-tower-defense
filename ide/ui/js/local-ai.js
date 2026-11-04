// Tools > OpenCode…: OpenCode (https://opencode.ai) as a code assistant beside Claude, in the IDE's Terminal, in the project's
// folder: its own interface, with whatever model it is set up for (a local one through Ollama / LM Studio included: nothing leaves
// this computer). "Ask OpenCode…" sends one request (opencode run), the Terminal shows the answer and what it changed.
import { backend } from './backend.js';
import { el, dialog, prefs, notify } from './ui.js';

const quote = (a) => (/^[\w@%+=:,./-]+$/.test(a) ? a : `'${a.replace(/'/g, "'\\''")}'`);

export class LocalAi {
  constructor(app) { this.app = app; }

  /** Only where there is a Terminal (the desktop IDE; on Windows its Terminal is not bash: opencode runs in a terminal of yours). */
  available() { return backend.kind === 'native' && !!this.app.terminal; }

  registerActions(a) {
    if (!this.available()) return;
    a.add('opencode', 'OpenCode (code assistant, local models)', () => this.start(), { icon: 'terminal' });
    a.add('opencodeAsk', 'Ask OpenCode…', () => this.ask(), { icon: 'terminal' });
  }

  async run(command, label) {
    const started = this.app.terminal.run(command, label);
    this.app.showTool('terminal');
    try { await started; } catch (e) { notify(`${e.message}. Is opencode installed? https://opencode.ai`, 'error', { title: 'OpenCode' }); }
  }

  start() { return this.run('opencode', 'OpenCode'); }

  ask() {
    const text = el('textarea.input', { rows: 5, placeholder: 'e.g. add a pause menu to the play scene' }, prefs.get('opencodePrompt', ''));
    const model = el('input.input.small', { placeholder: 'provider/model, e.g. ollama/qwen2.5-coder (empty: OpenCode\'s own default)', list: 'opencode-models' }, );
    model.value = prefs.get('opencodeModel', '');
    const names = el('datalist', { id: 'opencode-models' });
    backend.invoke('ai.models', { provider: 'opencode' }).then((found) => { for (const n of found.slice(0, 300)) names.append(el('option', { value: n })); }).catch(() => null);
    dialog({
      title: 'Ask OpenCode',
      body: el('div.res-ai', {}, el('label', {}, 'What to do in this project'), text,
        el('label.res-row', {}, el('span.res-label', {}, 'Model'), model, names),
        el('p.res-note', {}, 'It runs in the Terminal, in the project\'s folder: it can edit files, so use Git to review what it changed.')),
      buttons: [{ label: 'Cancel' }, { label: 'Ask', primary: true, action: () => {
        const prompt = text.value.trim();
        if (!prompt) return;
        prefs.set('opencodePrompt', prompt);
        prefs.set('opencodeModel', model.value.trim());
        const command = ['opencode', 'run', ...(model.value.trim() ? ['-m', model.value.trim()] : []), prompt];
        return this.run(command.map(quote).join(' '), 'OpenCode');
      } }],
    });
  }
}
