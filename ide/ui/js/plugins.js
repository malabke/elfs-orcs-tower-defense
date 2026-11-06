// The IDE's plugins (PHP, ide/src/Plugin): what they add, drawn here. Their actions (Tools menu or the one they
// name, Find Action, shortcuts), their tool windows (Ui nodes from PHP, callbacks run in PHP), completion and
// problems for the files they match, what they ask (notify, open a file, run a command, show / redraw a window),
// and the Plugins window (installed plugins, the project's plugins being developed, install, enable, remove).
import { backend } from './backend.js';
import { el, clear, icon, dialog, notify, confirm, debounce, menu } from './ui.js';
import { monaco, pathOf } from './editor.js';
import { PluginSandbox } from './plugin-sandbox.js';

/** 'Ctrl+Alt+T' -> 'ctrl+alt+t', in the order the IDE's key handler writes keys. */
function keyOf(kbd) {
  const parts = String(kbd).toLowerCase().split('+').map((p) => p.trim()).filter(Boolean);
  const key = parts.filter((p) => !['ctrl', 'cmd', 'alt', 'shift'].includes(p)).pop();
  if (!key) return null;
  return [parts.includes('ctrl') || parts.includes('cmd') ? 'ctrl' : '', parts.includes('alt') ? 'alt' : '', parts.includes('shift') ? 'shift' : '', key].filter(Boolean).join('+');
}

/** -1, 0, 1: version a against b ('0.10.0' > '0.9.1'). */
function versionCompare(a, b) {
  const pa = String(a).split(/[.+-]/).map((x) => parseInt(x, 10) || 0);
  const pb = String(b).split(/[.+-]/).map((x) => parseInt(x, 10) || 0);
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    if ((pa[i] || 0) !== (pb[i] || 0)) return (pa[i] || 0) > (pb[i] || 0) ? 1 : -1;
  }
  return 0;
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
    this.host = backend;          // the desktop app: its own PHP; the web IDE: this browser's (PluginSandbox)
  }

  /** In the web IDE the plugins run in this browser, never on jumphp.net (plugin-sandbox.js). */
  get web() { return this.host !== backend; }

  async load() {
    if (backend.kind !== 'native') {
      try {
        this.host = await PluginSandbox.start(this.app, backend.config.plugins);
      } catch (e) {
        console.error(e);
        notify(`The plugins could not start in this browser: ${e.message}`, 'warning', { title: 'Plugins' });
        this.host = { invoke: async () => { throw new Error('the plugins did not start'); }, on: () => () => null };
      }
      this.host.on('ide.error', (e) => notify(`${e.message}${e.at ? ` (${e.at})` : ''}`, 'error', { title: e.where || 'Plugins' }));
    }
    this.contributions = await this.host.invoke('plugins.contributions').catch(() => this.contributions);
    this.host.on('plugins.ui', (m) => this.message(m));
    if (backend.kind === 'native') backend.on('plugins.updates', (r) => this.updatesFound(r));
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
    if (backend.kind === 'native') a.add('pluginUpdates', 'Check for Plugin Updates', () => this.checkUpdates(), { icon: 'download' });
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
      const result = await this.host.invoke('plugins.action', { id: x.id, ...this.editorState() });
      if (result && typeof result === 'object' && result.message) notify(result.message, 'info', { title: x.pluginName });
    } catch (e) {
      notify(e.message, 'error', { title: x.pluginName });
    }
  }

  // updates (jumphp.net) ---------------------------------------------------------------------------------------

  /** What a check found (the IDE checks after its start and every 6 hours; Plugins window: Check for Updates). */
  updatesFound(r, manual = false) {
    const restart = { label: 'Restart Now', action: () => this.host.invoke('plugins.restart') };
    if (r.installed?.length) {
      const names = r.updates.filter((u) => r.installed.includes(u.id)).map((u) => `${u.name} ${u.version}`).join(', ');
      notify(`Updated: ${names}. Restart the IDE to use the new version${r.installed.length > 1 ? 's' : ''}.`, 'success', { title: 'Plugins', actions: [restart, { label: 'Later', action: () => null }], timeout: 0 });
    }
    for (const [id, message] of Object.entries(r.failed || {})) notify(`${id}: ${message}`, 'error', { title: 'Plugin update' });
    const left = (r.updates || []).filter((u) => !(r.installed || []).includes(u.id) && !(r.failed || {})[u.id]);
    if (left.length) {
      notify(`Plugin update${left.length > 1 ? 's' : ''} on jumphp.net: ${left.map((u) => `${u.name} ${u.installed} → ${u.version}`).join(', ')}.`, 'info', { title: 'Plugins', timeout: 0, actions: [
        { label: left.length > 1 ? 'Update All' : 'Update', action: () => this.updateAll() },
        { label: 'Plugins…', action: () => this.manage() },
        { label: 'Update Automatically', action: () => this.host.invoke('market.auto', { on: true }).then(() => this.updateAll()) },
      ] });
    } else if (manual && !r.installed?.length) {
      notify('Every plugin is up to date.', 'info', { title: 'Plugins' });
    }
  }

  async updateAll() {
    const done = this.app.task('Updating the plugins…');
    try {
      this.updatesFound(await this.host.invoke('market.updates', { install: true }));
    } catch (e) {
      notify(e.message, 'error', { title: 'Plugin update' });
    } finally {
      done();
    }
  }

  async checkUpdates() {
    try {
      this.updatesFound(await this.host.invoke('market.updates', {}), true);
    } catch (e) {
      notify(e.message, 'error', { title: 'Plugins' });
    }
  }

  // tool windows ------------------------------------------------------------------------------------------------

  async render(tool, view = null) {
    const node = this.views.get(tool);
    if (!node) return;
    try {
      view ??= await this.host.invoke('plugins.view', { tool });
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
      this.render(tool, await this.host.invoke('plugins.callback', args));
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
    else if (m.do === 'run' && this.web) notify(`${m.label}: running a command needs the desktop IDE (the web IDE runs nothing on a computer).`, 'info', { title: 'Plugins' });
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
        const items = await this.host.invoke('plugins.complete', { path, text: model.getValue(), line: position.lineNumber, column: position.column }).catch(() => []);
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
    const list = await this.host.invoke('plugins.diagnostics', { path, text: model.getValue() }).catch(() => null);
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
      ...(backend.kind === 'native' ? [
        { label: 'Check for Updates', action: async () => { await this.checkUpdates(); await draw(); return false; } },
        { label: 'Install from a Folder…', action: async () => { await this.installFolder(); await draw(); return false; } }] : []),
      { label: 'Close' },
    ] });
    const restart = () => notify('Restart the IDE to load the new version of the plugin (PHP keeps the classes it loaded).', 'info', { title: 'Plugins',
      actions: [{ label: 'Restart Now', action: () => this.host.invoke('plugins.restart') }, { label: 'Later', action: () => null }], timeout: 0 });
    const draw = async () => {
      const list = await this.host.invoke('plugins.list').catch(() => []);
      clear(body);
      body.append(el('p.muted', {}, this.web
        ? 'Plugins are PHP that runs in this browser, never on jumphp.net (docs/ide-plugins.md); they are kept in this browser. They see the project\'s files, and what they change is saved as your own edits. Run configurations and tools for Claude need the desktop IDE.'
        : 'Plugins are PHP that runs inside the IDE (docs/ide-plugins.md): install only the ones you trust. Changes to the plugins load at the next start.'));
      const hidden = this.contributions.hidden;
      if (hidden && (hidden.run || hidden.mcp)) body.append(el('p.muted', {}, `Left out here (the desktop IDE has them): ${[hidden.run ? `${hidden.run} run configuration(s)` : '', hidden.mcp ? `${hidden.mcp} tool(s) for Claude` : ''].filter(Boolean).join(', ')}.`));
      if (!list.length) body.append(el('div.placeholder', {}, 'No plugin yet.'));
      for (const p of list) {
        const status = { active: 'on', disabled: 'off', error: 'failed' }[p.status] || p.status;
        body.append(el('div.plugin-row', {},
          el('div.plugin-main', {}, el('b', {}, p.name), ` ${p.version} `, el(`span.plugin-status.${p.status}`, {}, status), p.restart ? el('span.muted', {}, ` — ${p.installedVersion && p.installedVersion !== p.version ? p.installedVersion + ' installed: ' : ''}restart to use it`) : null,
            p.source !== 'user' ? el('span.muted', {}, p.source === 'project' ? ' — this project\'s' : ' — in development') : null,
            el('div.muted', {}, p.description || ''), p.error ? el('div.plugin-error', {}, p.error) : null,
            ...(() => {
              const tools = (this.contributions.mcp || []).filter((t) => t.plugin === p.id).map((t) => t.name);
              return tools.length ? [el('div.plugin-mcp', { title: 'Claude Code calls them mcp__jumphp-ide__<name>' }, `Tools for Claude: ${tools.join(', ')}`)] : [];
            })()),
          el('div.plugin-buttons', {},
            p.settings?.length ? el('button.btn.small', { title: 'Its settings, for this computer', onclick: () => this.settingsPage(p, draw) }, 'Settings…') : null,
            el('label', {}, el('input', { type: 'checkbox', checked: p.status !== 'disabled', onchange: async (e) => {
              await this.host.invoke('plugins.enable', { id: p.id, on: e.target.checked }).catch((err) => notify(err.message, 'error'));
              if (e.target.checked) restart();
              draw();
            } }), ' Enabled'),
            p.removable ? el('button.btn.small', { onclick: async () => {
              if (!(await confirm(`Remove ${p.name}?`, 'Its folder is deleted; what it added goes away at the next start.', { ok: 'Remove', danger: true }))) return;
              await this.host.invoke('plugins.remove', { id: p.id }).catch((err) => notify(err.message, 'error'));
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
            el('div.plugin-buttons', {}, el('button.btn.small', { title: 'Packs it (like bin/jumphp plugin:pack) and publishes this version on jumphp.net, for everyone', onclick: async (e) => {
              e.target.disabled = true;
              try {
                const r = await this.host.invoke('market.publish', { dir: `${this.app.project.root}/${m.dir}` });
                notify(`${r.name} ${r.version} is published on jumphp.net.`, 'success', { title: 'Plugins', actions: [{ label: 'Open its Page', action: () => this.app.openUrl(r.page) }] });
              } catch (err) {
                notify(err.message, 'error', { title: 'Publish on jumphp.net' });
              }
              e.target.disabled = false;
            } }, 'Publish on jumphp.net'),
            el('button.btn.small', { title: 'Its statistics on jumphp.net (yours only)', onclick: () => this.stats(m.id, m.title) }, 'Statistics'),
            el('button.btn.small.primary', { onclick: async () => {
              const r = await this.host.invoke('plugins.install', { dir: `${this.app.project.root}/${m.dir}` }).catch((err) => { notify(err.message, 'error'); return null; });
              if (r?.restart) restart(); else if (r?.status === 'active') notify(`${r.name} is installed: its tool windows and actions show after a reload of the window.`, 'success', { title: 'Plugins', actions: [{ label: 'Reload', action: () => location.reload() }] });
              else if (r?.error) notify(r.error, 'error', { title: r.name || 'Plugins' });
              draw();
            } }, loaded ? 'Update in the IDE' : 'Install in the IDE'))));
        }
      }
      if (backend.kind === 'native' || this.web) await this.market(body, list, draw, restart);
    };
    await draw();
    return d;
  }

  /** jumphp.net's plugins (https://jumphp.net/ide-plugins): search, install, update. */
  async market(body, installed, draw, restart) {
    const results = el('div.plugins-market');
    const search = el('input.pv-input', { type: 'search', placeholder: 'Search jumphp.net\'s plugins', value: this.marketQuery || '' });
    const auto = el('input', { type: 'checkbox', onchange: () => this.host.invoke('market.auto', { on: auto.checked }).catch((e) => notify(e.message, 'error')) });
    if (!this.web) this.host.invoke('market.auto', {}).then((r) => { auto.checked = !!r.auto; }).catch(() => null);
    body.append(el('h4', {}, 'On jumphp.net'),
      ...(this.web ? [] : [el('label.pv-check', { title: 'The IDE checks jumphp.net after its start and every 6 hours; the new versions are used at the next start' }, auto, ' Update plugins automatically')]),
      search, results);
    const show = async () => {
      this.marketQuery = search.value.trim();
      clear(results);
      results.append(el('div.placeholder', {}, 'Loading…'));
      let found;
      try {
        found = await this.host.invoke('market.list', { q: this.marketQuery });
      } catch (e) {
        clear(results);
        results.append(el('div.placeholder', {}, e.message));
        return;
      }
      clear(results);
      if (!found.length) results.append(el('div.placeholder', {}, this.marketQuery ? 'No plugin matches.' : 'No plugin on jumphp.net yet.'));
      for (const p of found) {
        const mine = installed.find((x) => x.id === p.id);
        const newer = mine && versionCompare(p.version, mine.installedVersion || mine.version) > 0;
        const label = !mine ? 'Install' : newer ? `Update to ${p.version}` : mine.restart ? 'Restart to use' : 'Installed';
        results.append(el('div.plugin-row', {},
          el('div.plugin-main', {}, el('b', {}, p.name), ` ${p.version} `,
            el('span.muted', {}, `by ${p.author} · ${p.downloads} downloads${p.rating ? ` · ★ ${p.rating} (${p.ratingCount})` : ''}`),
            el('div.muted', {}, p.description || '')),
          el('div.plugin-buttons', {},
            el('button.btn.small', { onclick: () => this.app.openUrl(`https://jumphp.net/ide-plugins/${p.id}`) }, 'Page'),
            mine && !this.web ? el('button.btn.small', { title: 'Your mark on jumphp.net (signed in)', onclick: (e) => this.rate(p, e.currentTarget, show) }, 'Rate…') : null,
            el(`button.btn.small${!mine || newer ? '.primary' : ''}`, { disabled: !!mine && !newer, onclick: async (e) => {
              e.target.disabled = true;
              try {
                const r = await this.host.invoke('market.install', { id: p.id, via: mine ? 'update' : 'install' });
                if (r.restart) restart();
                else if (r.status === 'active') notify(`${r.name} is installed.`, 'success', { title: 'Plugins', actions: [{ label: 'Reload the Window', action: () => location.reload() }] });
                else notify(r.error || 'The plugin did not load.', 'error', { title: r.name || 'Plugins' });
              } catch (err) {
                notify(err.message, 'error', { title: 'Plugins' });
              }
              draw();
            } }, label))));
      }
    };
    search.addEventListener('keydown', (e) => { if (e.key === 'Enter') show(); });
    show();
  }

  /** A mark, 1 to 5 stars, on jumphp.net (the IDE's sign-in). */
  rate(p, at, redraw) {
    menu([1, 2, 3, 4, 5].reverse().map((score) => ({
      label: '★'.repeat(score) + '☆'.repeat(5 - score),
      action: async () => {
        try {
          const r = await this.host.invoke('market.rate', { id: p.id, score });
          notify(`Thanks! ${p.name}: ★ ${r.rating} (${r.ratingCount} mark${r.ratingCount > 1 ? 's' : ''}).`, 'success', { title: 'Plugins' });
          redraw?.();
        } catch (e) {
          notify(e.message, 'error', { title: 'Rate a plugin' });
        }
      },
    })), at);
  }

  /** A plugin's settings page: the fields it declared ($ide->settings([...])), drawn here, saved one by one. */
  settingsPage(p, redraw) {
    const inputs = p.settings.map((f) => {
      const id = `plugin-setting-${p.id}-${f.name}`;
      let input;
      if (f.type === 'bool') input = el('input', { id, type: 'checkbox', checked: !!f.value });
      else if (f.type === 'choice') input = el('select', { id }, ...Object.entries(f.choices || {}).map(([v, l]) => el('option', { value: v, selected: String(f.value) === v }, l)));
      else input = el('input', { id, type: f.type === 'number' ? 'number' : 'text', value: f.value ?? '', ...(f.min !== undefined ? { min: f.min } : {}), ...(f.max !== undefined ? { max: f.max } : {}) });
      const read = () => (f.type === 'bool' ? input.checked : f.type === 'number' ? Number(input.value) : input.value);
      const row = f.type === 'bool'
        ? el('div.field.plugin-setting', {}, el('label', {}, input, ` ${f.label}`), f.description ? el('div.muted', {}, f.description) : null)
        : el('div.field.plugin-setting', {}, el('label', { for: id }, f.label), input, f.description ? el('div.muted', {}, f.description) : null);
      return { f, row, read };
    });
    dialog({ title: `${p.name} — settings`, body: el('div.plugin-settings', {}, ...inputs.map((i) => i.row)), buttons: [
      { label: 'Save', primary: true, action: async () => {
        for (const { f, read } of inputs) {
          const value = read();
          if (value !== f.value) await this.host.invoke('plugins.setting', { id: p.id, name: f.name, value });
        }
        notify(`${p.name}: settings saved.`, 'success', { title: 'Plugins' });
        redraw?.();
      } },
      { label: 'Reset', action: async () => {
        for (const { f } of inputs) if (f.value !== f.default) await this.host.invoke('plugins.setting', { id: p.id, name: f.name, value: f.default });
        redraw?.();
      } },
      { label: 'Cancel' },
    ] });
  }

  /** The private statistics of one of the user's plugins (jumphp.net, 30 days). */
  async stats(id, title) {
    let s;
    try {
      s = await this.host.invoke('market.stats', { id });
    } catch (e) {
      notify(e.message.includes('of yours') ? `${title} is not published on jumphp.net by you yet (Publish on jumphp.net).` : e.message, 'error', { title: 'Statistics' });
      return;
    }
    const peak = Math.max(1, ...s.days.map((d) => d.views + d.install + d.update + d.web));
    const kinds = [['views', 'Page views'], ['install', 'Installs from the IDE'], ['update', 'Updates from the IDE'], ['web', '.zip from the site']];
    const chart = el('div.plugin-chart', {}, ...s.days.map((d) => el('div.day', { title: `${d.day}: ${kinds.map(([k, l]) => `${l} ${d[k]}`).join(', ')}` },
      ...kinds.slice().reverse().map(([k]) => el(`span.${k}`, { style: { height: `${Math.round((d[k] / peak) * 100)}%` } })))));
    dialog({ title: `${title} — statistics (only you see them)`, wide: true, body: el('div.plugin-stats', {},
      el('p', {}, `${s.downloads} downloads in all · ${s.rating ? `★ ${s.rating} (${s.ratingCount} marks)` : 'no mark yet'}`),
      el('p.muted', {}, 'The last 30 days'), chart,
      el('ul.plugin-legend', {}, ...kinds.map(([k, l]) => el('li', {}, el(`i.${k}`), `${l} `, el('b', {}, String(s.totals[k]))))),
      el('div.plugin-stats-cols', {},
        el('div', {}, el('h4', {}, 'Per version'), el('ul', {}, ...(Object.entries(s.versions).length ? Object.entries(s.versions).map(([v, n]) => el('li', {}, `${v} `, el('b', {}, String(n)))) : [el('li.muted', {}, '—')]))),
        el('div', {}, el('h4', {}, 'The marks'), el('ul', {}, ...[5, 4, 3, 2, 1].map((n) => el('li', {}, '★'.repeat(n) + ' ', el('b', {}, String(s.marks[n] ?? 0)))))))),
      buttons: [{ label: 'Open its Page', action: () => this.app.openUrl(`https://jumphp.net/ide-plugins/${id}`) }, { label: 'Close' }] });
  }

  async installFolder() {
    const dir = await backend.invoke('native.open', { folder: true, title: 'A plugin\'s folder (its composer.json)' }).catch(() => null);
    if (!dir) return;
    try {
      const r = await this.host.invoke('plugins.install', { dir });
      if (r.restart) notify(`${r.name} ${r.installed} is installed: restart the IDE to use it.`, 'info', { title: 'Plugins', actions: [{ label: 'Restart Now', action: () => this.host.invoke('plugins.restart') }] });
      else if (r.status === 'active') notify(`${r.name} is installed.`, 'success', { title: 'Plugins', actions: [{ label: 'Reload the Window', action: () => location.reload() }] });
      else notify(r.error || 'The plugin did not load.', 'error', { title: r.name || 'Plugins' });
    } catch (e) {
      notify(e.message, 'error', { title: 'Plugins' });
    }
  }
}
