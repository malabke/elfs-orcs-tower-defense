// The IDE's plugins (PHP, ide/src/Plugin): what they add, drawn here. Their actions (Tools menu or the one they
// name, Find Action, shortcuts), their tool windows (Ui nodes from PHP, callbacks run in PHP), completion and
// problems for the files they match, what they ask (notify, open a file, run a command, show / redraw a window),
// and the Plugins window (installed plugins, the project's plugins being developed, install, enable, remove).
import { backend } from './backend.js';
import { el, clear, icon, dialog, notify, confirm, debounce } from './ui.js';
import { monaco, pathOf } from './editor.js';

/** 'Ctrl+Alt+T' -> 'ctrl+alt+t', in the order the IDE's key handler writes keys. */
function keyOf(kbd) {
  const parts = String(kbd).toLowerCase().split('+').map((p) => p.trim()).filter(Boolean);
  const key = parts.filter((p) => !['ctrl', 'cmd', 'alt', 'shift'].includes(p)).pop();
  if (!key) return null;
  return [parts.includes('ctrl') || parts.includes('cmd') ? 'ctrl' : '', parts.includes('alt') ? 'alt' : '', parts.includes('shift') ? 'shift' : '', key].filter(Boolean).join('+');
}

/** A glob of a plugin ('*.php', 'config/*', '*') as a test of a path (or of its file name). */
function globTest(pattern) {
  const re = new RegExp('^' + pattern.replace(/[.+^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*').replace(/\?/g, '.') + '$');
  return (path) => pattern === '*' || re.test(path) || re.test(path.split('/').pop());
}

export class Plugins {
  constructor(app) {
    this.app = app;
    this.contributions = { actions: [], tools: [], run: [], providers: [] };
    this.views = new Map();
  }

  async load() {
    this.contributions = await backend.invoke('plugins.contributions').catch(() => this.contributions);
    backend.on('plugins.ui', (m) => this.message(m));
    const providers = this.contributions.providers || [];
    if (providers.some((p) => p.kind === 'completion')) this.registerCompletion();
    if (providers.some((p) => p.kind === 'diagnostics')) {
      this.diagnosed = providers.filter((p) => p.kind === 'diagnostics').map((p) => globTest(p.pattern));
      this.app.editors.on('dirty', (path) => this.diagnose(path));
      this.app.editors.on('active', (tab) => tab?.path && this.diagnose(tab.path));
    }
  }

  /** The tool windows, beside the IDE's (App.registerTools). */
  registerTools(T) {
    for (const t of this.contributions.tools || []) {
      const node = el('div.plugin-view');
      const view = { title: () => t.label, node, actions: () => [el('button', { title: 'Refresh', onclick: () => this.render(t.id) }, icon('refresh'))], shown: () => this.render(t.id) };
      this.views.set(t.id, node);
      T(t.id, t.side, t.label, t.icon || 'plugin', view, null);
    }
  }

  /** The actions (App.registerActions), their shortcuts in the IDE's key map. */
  registerActions(a) {
    a.add('plugins', 'Plugins…', () => this.manage(), { icon: 'plugin' });
    for (const x of this.contributions.actions || []) {
      a.add(x.id, x.label, () => this.runAction(x), { kbd: x.kbd || undefined, icon: x.icon || 'plugin' });
      const key = x.kbd && keyOf(x.kbd);
      if (key && this.app.keyMap && !this.app.keyMap[key]) this.app.keyMap[key] = x.id;
    }
  }

  /** The ids of the plugins' actions for a menu of the main menu (Tools by default). */
  menuIds(menu) {
    return (this.contributions.actions || []).filter((x) => (x.menu || 'Tools') === menu).map((x) => x.id);
  }

  editorState() {
    const editor = this.app.editors.editor();
    const path = this.app.editors.activePath();
    const model = editor?.getModel();
    const pos = editor?.getPosition();
    const sel = editor?.getSelection();
    return { path: path || null, text: model ? model.getValue() : '', line: pos?.lineNumber || 1, column: pos?.column || 1,
      selection: model && sel ? model.getValueInRange(sel) : '' };
  }

  async runAction(x) {
    try {
      const result = await backend.invoke('plugins.action', { id: x.id, ...this.editorState() });
      if (result && typeof result === 'object' && result.message) notify(result.message, 'info', { title: x.pluginName });
    } catch (e) {
      notify(e.message, 'error', { title: x.pluginName });
    }
  }

  // tool windows ------------------------------------------------------------------------------------------------

  async render(tool, view = null) {
    const node = this.views.get(tool);
    if (!node) return;
    try {
      view ??= await backend.invoke('plugins.view', { tool });
    } catch (e) {
      view = { type: 'text', text: e.message };
    }
    clear(node);
    node.append(this.node(tool, view));
  }

  async callback(tool, callback, value) {
    try {
      const args = { tool, callback };
      if (value !== undefined) args.value = value;
      this.render(tool, await backend.invoke('plugins.callback', args));
    } catch (e) {
      notify(e.message, 'error');
    }
  }

  /** One Ui node (ide/src/Plugin/Ui.php) as HTML. */
  node(tool, n) {
    const kids = (list) => (list || []).map((c) => this.node(tool, c));
    const click = (n) => (n.onClick !== undefined ? () => this.callback(tool, n.onClick) : null);
    switch (n.type) {
      case 'column': return el('div.pv-column', {}, ...kids(n.children));
      case 'row': return el('div.pv-row', {}, ...kids(n.children));
      case 'heading': return el('div.pv-heading', {}, n.text);
      case 'text': return el(`div.pv-text${n.muted ? '.muted' : ''}`, {}, n.text);
      case 'code': return el('pre.pv-code', {}, n.text);
      case 'separator': return el('div.menu-sep');
      case 'button': return el(`button.btn.small${n.primary ? '.primary' : ''}`, { onclick: click(n) }, n.icon ? icon(n.icon) : null, n.label);
      case 'link': return el('a.pv-link', { href: '#', onclick: (e) => { e.preventDefault(); click(n)?.(); } }, n.label);
      case 'checkbox': {
        const box = el('input', { type: 'checkbox', checked: !!n.checked, onchange: () => n.onChange !== undefined && this.callback(tool, n.onChange, box.checked) });
        return el('label.pv-check', {}, box, ' ', n.label);
      }
      case 'input': {
        const input = el('input.pv-input', { type: 'text', placeholder: n.placeholder || '', value: n.value || '' });
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter' && n.onSubmit !== undefined) this.callback(tool, n.onSubmit, input.value); });
        return input;
      }
      case 'list': {
        if (!n.items?.length) return el('div.placeholder', {}, n.empty || 'Nothing');
        return el('div.tree.pv-list', {}, ...n.items.map((item) => el(`div.tree-row${item.onClick !== undefined ? '.clickable' : ''}`, { onclick: click(item) },
          item.icon ? icon(item.icon) : null, el('span.tree-label', {}, item.label), item.hint ? el('span.tree-hint', {}, item.hint) : null)));
      }
      case 'item': return el('div.tree-row', { onclick: click(n) }, el('span.tree-label', {}, n.label));
      case 'html': { const box = el('div.pv-html'); box.innerHTML = n.html || ''; return box; }
      default: return el('div.pv-text.muted', {}, `(${n.type})`);
    }
  }

  // what plugins ask ---------------------------------------------------------------------------------------------

  message(m) {
    if (m.do === 'notify') notify(m.message, m.kind || 'info', { title: m.title });
    else if (m.do === 'open') this.app.editors.open(m.path, m.line ? { line: m.line, col: 1 } : {});
    else if (m.do === 'run') this.app.runner?.start({ id: 'adhoc', label: m.label, command: m.command, kind: 'tool' });
    else if (m.do === 'show') this.app.showTool(m.tool);
    else if (m.do === 'refresh' && this.app.open?.[this.app.tools[m.tool]?.side] === m.tool) this.render(m.tool);
  }

  // editor features ----------------------------------------------------------------------------------------------

  registerCompletion() {
    const tests = this.contributions.providers.filter((p) => p.kind === 'completion').map((p) => globTest(p.pattern));
    const kinds = { method: 'Method', function: 'Function', class: 'Class', property: 'Property', keyword: 'Keyword', snippet: 'Snippet', value: 'Value', text: 'Text' };
    monaco.languages.registerCompletionItemProvider('*', {
      triggerCharacters: ['@', '.', ':', '$', '\'', '"'],
      provideCompletionItems: async (model, position) => {
        const path = pathOf(model.uri);
        if (!path || !tests.some((t) => t(path))) return { suggestions: [] };
        const items = await backend.invoke('plugins.complete', { path, text: model.getValue(), line: position.lineNumber, column: position.column }).catch(() => []);
        const word = model.getWordUntilPosition(position);
        const range = { startLineNumber: position.lineNumber, endLineNumber: position.lineNumber, startColumn: word.startColumn, endColumn: position.column };
        return { suggestions: items.map((i) => {
          // what the user typed of the label is replaced (an '@todo' after '@to')
          const typed = model.getLineContent(position.lineNumber).slice(0, position.column - 1);
          let start = position.column;
          for (let k = Math.min(i.label.length, typed.length); k > 0; k--) if (typed.endsWith(i.label.slice(0, k))) { start = position.column - k; break; }
          return { label: i.label, insertText: i.insert, detail: i.detail, kind: monaco.languages.CompletionItemKind[kinds[i.kind] || 'Text'],
            range: start < position.column ? { ...range, startColumn: start } : range };
        }) };
      },
    });
  }

  diagnose(path) {
    if (!this.diagnosed?.some((t) => t(path))) return;
    this.diagnoseLater ??= new Map();
    if (!this.diagnoseLater.has(path)) this.diagnoseLater.set(path, debounce(() => this.diagnoseNow(path), 500));
    this.diagnoseLater.get(path)();
  }

  async diagnoseNow(path) {
    const model = this.app.editors.files.get(path)?.model;
    if (!model) return;
    const list = await backend.invoke('plugins.diagnostics', { path, text: model.getValue() }).catch(() => null);
    if (!list || model.isDisposed()) return;
    monaco.editor.setModelMarkers(model, 'plugins', list.map((d) => ({
      startLineNumber: d.startLine, startColumn: d.startColumn, endLineNumber: d.endLine, endColumn: Math.max(d.endColumn, d.startColumn + 1),
      message: d.message, severity: d.severity === 'error' ? monaco.MarkerSeverity.Error : monaco.MarkerSeverity.Warning, source: d.source,
    })));
    this.app.problems.set(path, list, 'plugins');
  }

  // the Plugins window -----------------------------------------------------------------------------------------------

  async manage() {
    const body = el('div.plugins-dialog');
    const d = dialog({ title: 'Plugins', body, wide: true, buttons: [
      ...(backend.kind === 'native' ? [{ label: 'Install from a Folder…', action: async () => { await this.installFolder(); await draw(); return false; } }] : []),
      { label: 'Close' },
    ] });
    const restart = () => notify('Restart the IDE to load the new version of the plugin (PHP keeps the classes it loaded).', 'info', { title: 'Plugins',
      actions: [{ label: 'Restart Now', action: () => backend.invoke('plugins.restart') }, { label: 'Later', action: () => null }], timeout: 0 });
    const draw = async () => {
      const list = await backend.invoke('plugins.list').catch(() => []);
      clear(body);
      body.append(el('p.muted', {}, 'Plugins are PHP that runs inside the IDE (docs/ide-plugins.md): install only the ones you trust. Changes to the plugins load at the next start.'));
      if (!list.length) body.append(el('div.placeholder', {}, 'No plugin yet.'));
      for (const p of list) {
        const status = { active: 'on', disabled: 'off', error: 'failed' }[p.status] || p.status;
        body.append(el('div.plugin-row', {},
          el('div.plugin-main', {}, el('b', {}, p.name), ` ${p.version} `, el(`span.plugin-status.${p.status}`, {}, status), p.restart ? el('span.muted', {}, ' — restart to update') : null,
            p.source !== 'user' ? el('span.muted', {}, p.source === 'project' ? ' — this project\'s' : ' — in development') : null,
            el('div.muted', {}, p.description || ''), p.error ? el('div.plugin-error', {}, p.error) : null),
          el('div.plugin-buttons', {},
            el('label', {}, el('input', { type: 'checkbox', checked: p.status !== 'disabled', onchange: async (e) => {
              await backend.invoke('plugins.enable', { id: p.id, on: e.target.checked }).catch((err) => notify(err.message, 'error'));
              if (e.target.checked) restart();
              draw();
            } }), ' Enabled'),
            p.removable ? el('button.btn.small', { onclick: async () => {
              if (!(await confirm(`Remove ${p.name}?`, 'Its folder is deleted; what it added goes away at the next start.', { ok: 'Remove', danger: true }))) return;
              await backend.invoke('plugins.remove', { id: p.id }).catch((err) => notify(err.message, 'error'));
              restart();
              draw();
            } }, 'Remove') : null)));
      }
      const dev = (this.app.project.modules || []).filter((m) => m.kind === 'plugin');
      if (dev.length && backend.kind === 'native') {
        body.append(el('h4', {}, 'Plugins of this project'));
        for (const m of dev) {
          const loaded = list.find((p) => p.id === m.id);
          body.append(el('div.plugin-row', {}, el('div.plugin-main', {}, el('b', {}, m.title), el('span.muted', {}, ` ${m.dir}/`)),
            el('div.plugin-buttons', {}, el('button.btn.small.primary', { onclick: async () => {
              const r = await backend.invoke('plugins.install', { dir: `${this.app.project.root}/${m.dir}` }).catch((err) => { notify(err.message, 'error'); return null; });
              if (r?.restart) restart(); else if (r?.status === 'active') notify(`${r.name} is installed: its tool windows and actions show after a reload of the window.`, 'success', { title: 'Plugins', actions: [{ label: 'Reload', action: () => location.reload() }] });
              else if (r?.error) notify(r.error, 'error', { title: r.name || 'Plugins' });
              draw();
            } }, loaded ? 'Update in the IDE' : 'Install in the IDE'))));
        }
      }
    };
    await draw();
    return d;
  }

  async installFolder() {
    const dir = await backend.invoke('native.open', { folder: true, title: 'A plugin\'s folder (its composer.json)' }).catch(() => null);
    if (!dir) return;
    try {
      const r = await backend.invoke('plugins.install', { dir });
      if (r.restart) notify(`${r.name} ${r.installed} is installed: restart the IDE to use it.`, 'info', { title: 'Plugins', actions: [{ label: 'Restart Now', action: () => backend.invoke('plugins.restart') }] });
      else if (r.status === 'active') notify(`${r.name} is installed.`, 'success', { title: 'Plugins', actions: [{ label: 'Reload the Window', action: () => location.reload() }] });
      else notify(r.error || 'The plugin did not load.', 'error', { title: r.name || 'Plugins' });
    } catch (e) {
      notify(e.message, 'error', { title: 'Plugins' });
    }
  }
}
