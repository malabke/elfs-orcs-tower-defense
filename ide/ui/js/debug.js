// The debugger (Xdebug): breakpoints in the editors' gutter, Debug runs (a run configuration with JUMPHP_XDEBUG: the
// program connects to the IDE, ide/src/Debug/Debugger.php), and the Debug tool window: where it stopped (the
// frames), its variables (a tree read lazily), watches, and the steps (F9 resume, F8 over, F7 into, Shift+F8 out).
// Claude drives the same debugger through its tools (agent.js): the user sees what it does here.
import { backend } from './backend.js';
import { el, clear, icon, basename, menu, prompt, notify, prefs, debounce } from './ui.js';
import { monaco, pathOf, uriOf } from './editor.js';

export class DebugPanel {
  constructor(app) {
    this.app = app;
    this.key = 'breakpoints:' + app.projectKey;
    /** path => [{line, condition}] */
    this.breakpoints = new Map(Object.entries(prefs.get(this.key, {}) || {}));
    this.watches = prefs.get('watches:' + app.projectKey, []) || [];
    this.decorations = new Map();      // model uri => decoration ids (breakpoints)
    this.current = new Map();          // model uri => decoration ids (where it stopped)
    this.paused = null;                // {session, path, line, stack}
    this.frame = 0;
    this.listening = false;
    this.node = el('div.debug-view');
    this.render();
    this.syncLater = debounce((model) => this.readBack(model), 300);

    for (const group of app.editors.groups) this.attach(group.editor);
    monaco.editor.onDidCreateEditor((editor) => this.attach(editor));
    monaco.editor.onDidCreateModel((model) => { this.decorate(model); this.watchModel(model); });
    for (const model of monaco.editor.getModels()) { this.decorate(model); this.watchModel(model); }
    for (const [path, list] of this.breakpoints) backend.invoke('debug.breakpoints', { path, lines: list }).catch(() => null);

    backend.on('debug.paused', (p) => this.onPaused(p));
    backend.on('debug.running', (p) => { if (this.paused?.session === p.session) this.cleared(); });
    backend.on('debug.ended', (p) => { if (!this.paused || this.paused.session === p.session) this.cleared(); this.app.badge('debug', null); });
    backend.on('debug.state', (s) => { this.listening = s.listening; this.sessions = s.sessions || []; this.app.refreshToolHeader('debug'); });
  }

  title() { return 'Debug'; }

  actions() {
    const stopped = !!this.paused;
    const b = (title, ico, how, enabled = stopped) => el('button', { title, disabled: !enabled, onclick: () => this.step(how) }, icon(ico));
    return [
      b('Resume Program (F9)', 'play', 'run'),
      b('Step Over (F8)', 'stepOver', 'step_over'),
      b('Step Into (F7)', 'stepInto', 'step_into'),
      b('Step Out (Shift+F8)', 'stepOut', 'step_out'),
      b('Stop (ends the program)', 'stop', 'stop', stopped || (this.sessions || []).length > 0),
      el('button', { title: 'Remove All Breakpoints', onclick: () => this.clearAll() }, icon('close')),
    ];
  }

  // breakpoints --------------------------------------------------------------------------------------------------

  attach(editor) {
    if (editor.__debug) return;
    editor.__debug = true;
    editor.updateOptions({ glyphMargin: true });
    editor.onMouseDown((e) => {
      const type = e.target.type;
      if (type !== monaco.editor.MouseTargetType.GUTTER_GLYPH_MARGIN && type !== monaco.editor.MouseTargetType.GUTTER_LINE_DECORATIONS) return;
      const model = editor.getModel();
      const line = e.target.position?.lineNumber;
      if (!model || !line || !this.debuggable(model)) return;
      if (e.event.rightButton) {
        const bp = this.list(pathOf(model.uri)).find((b) => b.line === line);
        menu([
          bp ? { label: 'Edit Condition…', action: () => this.editCondition(pathOf(model.uri), line) } : { label: 'Add Breakpoint', action: () => this.toggle(pathOf(model.uri), line) },
          bp ? { label: 'Remove Breakpoint', action: () => this.toggle(pathOf(model.uri), line) } : null,
          { label: 'Remove All Breakpoints', action: () => this.clearAll() },
        ].filter(Boolean), { x: e.event.posx, y: e.event.posy });
        return;
      }
      this.toggle(pathOf(model.uri), line);
    });
  }

  debuggable(model) { return model.getLanguageId() === 'php'; }

  list(path) { return this.breakpoints.get(path) || []; }

  /** A breakpoint on / off at a line (Ctrl+F8 at the caret, a click in the gutter). */
  toggle(path, line, condition = null) {
    const list = this.list(path);
    const next = list.some((b) => b.line === line) ? list.filter((b) => b.line !== line) : [...list, { line, condition }].sort((a, b) => a.line - b.line);
    return this.set(path, next);
  }

  async editCondition(path, line) {
    const bp = this.list(path).find((b) => b.line === line);
    const condition = await prompt('Breakpoint condition', { value: bp?.condition || '', label: `${basename(path)}:${line}: stops only when this PHP expression is true (empty: always)`, placeholder: '$this->score > 100' });
    if (condition === null) return;
    this.set(path, [...this.list(path).filter((b) => b.line !== line), { line, condition: condition.trim() || null }].sort((a, b) => a.line - b.line));
  }

  /** All the breakpoints of a file: kept (per project), drawn, given to the debugger (and the running programs). */
  set(path, list) {
    if (list.length) this.breakpoints.set(path, list); else this.breakpoints.delete(path);
    prefs.set(this.key, Object.fromEntries(this.breakpoints));
    const model = monaco.editor.getModel(uriOf(path));
    if (model) this.decorate(model);
    this.render();
    return backend.invoke('debug.breakpoints', { path, lines: list }).catch(() => null);
  }

  clearAll() {
    for (const path of [...this.breakpoints.keys()]) this.set(path, []);
  }

  decorate(model) {
    const key = model.uri.toString();
    const list = this.list(pathOf(model.uri));
    const decorations = list.map((b) => ({
      range: new monaco.Range(b.line, 1, b.line, 1),
      options: { glyphMarginClassName: b.condition ? 'dbg-breakpoint conditional' : 'dbg-breakpoint', stickiness: monaco.editor.TrackedRangeStickiness.NeverGrowsWhenTypingAtEdges,
        glyphMarginHoverMessage: { value: b.condition ? `Breakpoint when \`${b.condition}\`` : 'Breakpoint (click to remove, right click for a condition)' },
        className: 'dbg-breakpoint-line', isWholeLine: true },
    }));
    this.decorations.set(key, model.deltaDecorations(this.decorations.get(key) || [], decorations));
  }

  watchModel(model) {
    model.onDidChangeContent(() => { if (this.list(pathOf(model.uri)).length) this.syncLater(model); });
    model.onWillDispose(() => { this.decorations.delete(model.uri.toString()); this.current.delete(model.uri.toString()); });
  }

  /** Lines moved by an edit: the breakpoints follow their lines (Monaco moves the decorations). */
  readBack(model) {
    if (model.isDisposed()) return;
    const path = pathOf(model.uri);
    const ids = this.decorations.get(model.uri.toString()) || [];
    const old = this.list(path);
    const moved = ids.map((id, i) => ({ line: model.getDecorationRange(id)?.startLineNumber, condition: old[i]?.condition || null }))
      .filter((b) => b.line).filter((b, i, all) => all.findIndex((x) => x.line === b.line) === i);
    if (JSON.stringify(moved) !== JSON.stringify(old)) this.set(path, moved);
  }

  // running ------------------------------------------------------------------------------------------------------

  /** Debugs a run configuration (the selected one by default): JUMPHP_XDEBUG=<port> makes bin/jumphp start PHP with Xdebug. */
  async debug(config = this.app.runner.config()) {
    if (!config) return null;
    let port;
    try { ({ port } = await backend.invoke('debug.listen')); } catch (e) { notify(e.message, 'error', { title: 'The debugger cannot start' }); return null; }
    // a project made before the debugger: its bin/jumphp does not start Xdebug yet (the IDE updates JumPHP in it)
    const supports = () => backend.invoke('jumphp.supports', { feature: 'JUMPHP_XDEBUG' }).then((r) => r.ok, () => true);
    if (!(await supports())) {
      await this.app.updateJumphp({ ask: true, why: 'The debugger needs a newer JumPHP in this project: its bin/jumphp does not start Xdebug yet.' });
      if (!(await supports())) {
        notify('This project\'s bin/jumphp does not start Xdebug (JUMPHP_XDEBUG): update JumPHP in it, then Debug again.', 'error', { title: 'Debug ' + config.label });
        return null;
      }
    }
    if (!this.breakpoints.size) notify('No breakpoint yet: click in the gutter left of a line of PHP to stop there.', 'info', { title: 'Debug ' + config.label });
    this.app.showTool('debug', true);
    const id = await this.app.runner.start(config, { JUMPHP_XDEBUG: String(port) });
    if (id !== null && id !== undefined) this.watchConnection(id, config);
    return id;
  }

  /** Says so when the program never connects to the debugger (instead of nothing happening). */
  watchConnection(runId, config) {
    let connected = false;
    const offs = [];
    const stop = () => { offs.forEach((off) => off?.()); clearTimeout(timer); };
    const warn = (why) => notify(`${config.label}: Xdebug did not connect to the IDE (${why}). The run's output (Run window) may say why; `
      + 'the first Debug builds the desktop image with Xdebug (about a minute).', 'error', { title: 'Debugger' });
    offs.push(backend.on('debug.state', (s) => { if ((s.sessions || []).length) { connected = true; stop(); } }));
    offs.push(backend.on('run.exit', (p) => { if (p.id === runId) { stop(); if (!connected) warn('the program ended first'); } }));
    const timer = setTimeout(() => { stop(); if (!connected) warn('nothing after 3 minutes'); }, 180000);
  }

  async step(how) {
    const session = this.paused?.session || this.sessions?.[0]?.id;
    if (!session) return;
    if (how !== 'stop') this.cleared();
    try {
      await backend.invoke('debug.resume', { session, how });
    } catch (e) {
      notify(e.message, 'error', { title: 'Debugger' });
    }
    if (how === 'stop') this.cleared();
  }

  onPaused(p) {
    this.paused = p;
    this.frame = 0;
    this.variables = null;
    this.app.badge('debug', 'on');
    this.app.showTool('debug', true);
    this.showFrame();
    this.app.refreshToolHeader('debug');
  }

  cleared() {
    this.paused = null;
    this.variables = null;
    for (const [key, ids] of this.current) {
      const model = monaco.editor.getModel(monaco.Uri.parse(key));
      if (model && !model.isDisposed()) model.deltaDecorations(ids, []);
    }
    this.current.clear();
    this.render();
    this.app.refreshToolHeader('debug');
  }

  /** Opens where the selected frame is, marks the line, reads its variables. */
  async showFrame() {
    const frame = this.paused?.stack?.[this.frame];
    this.render();
    if (!frame) return;
    if (frame.path) {
      await this.app.editors.open(frame.path, { line: frame.line, select: false, focus: false });
      const model = monaco.editor.getModel(uriOf(frame.path));
      for (const [key, ids] of this.current) {
        const m = monaco.editor.getModel(monaco.Uri.parse(key));
        if (m && !m.isDisposed()) m.deltaDecorations(ids, []);
      }
      this.current.clear();
      if (model) {
        this.current.set(model.uri.toString(), model.deltaDecorations([], [{ range: new monaco.Range(frame.line, 1, frame.line, 1),
          options: { isWholeLine: true, className: 'dbg-current-line', glyphMarginClassName: 'dbg-current' } }]));
      }
    }
    const session = this.paused.session;
    try {
      this.variables = await backend.invoke('debug.variables', { session, depth: this.frame });
    } catch (e) {
      this.variables = [];
    }
    await this.evaluateWatches();
    if (this.paused?.session === session) this.render();
  }

  async evaluateWatches() {
    this.watchValues = [];
    for (const expression of this.watches) {
      try {
        this.watchValues.push({ ...(await backend.invoke('debug.eval', { session: this.paused?.session, expression })), name: expression, expression });
      } catch (e) {
        this.watchValues.push({ name: expression, expression, value: e.message, type: 'error' });
      }
    }
  }

  addWatch(expression) {
    expression = expression.trim();
    if (!expression || this.watches.includes(expression)) return;
    this.watches = [...this.watches, expression];
    prefs.set('watches:' + this.app.projectKey, this.watches);
    if (this.paused) this.evaluateWatches().then(() => this.render()); else this.render();
  }

  removeWatch(expression) {
    this.watches = this.watches.filter((w) => w !== expression);
    prefs.set('watches:' + this.app.projectKey, this.watches);
    this.watchValues = (this.watchValues || []).filter((w) => w.expression !== expression);
    this.render();
  }

  // the tool window -----------------------------------------------------------------------------------------------

  render() {
    clear(this.node);
    const frames = el('div.debug-frames');
    const vars = el('div.debug-vars');
    this.node.append(frames, vars);
    if (!this.paused) {
      const listening = (this.sessions || []).length ? 'A program is running with the debugger: it stops at the next breakpoint.' : 'Not debugging. Debug (Shift+F9) runs the selected configuration with Xdebug.';
      frames.append(el('div.placeholder', {}, listening));
    } else {
      this.paused.stack.forEach((f, i) => frames.append(el('div.row' + (i === this.frame ? '.sel' : ''), {
        title: f.path ? `${f.path}:${f.line}` : 'outside the project',
        onclick: () => { this.frame = i; this.showFrame(); },
      }, el('span.label', {}, f.where || '{main}'), el('span.hint', {}, f.path ? `${basename(f.path)}:${f.line}` : `line ${f.line}`))));
    }
    const input = el('input.debug-eval', { placeholder: this.paused ? 'Evaluate an expression (Enter), or add a watch (Ctrl+Enter)' : 'Add a watch (Enter)', spellcheck: false,
      onkeydown: async (e) => {
        if (e.key !== 'Enter' || !input.value.trim()) return;
        const expression = input.value;
        if (!this.paused || e.ctrlKey || e.metaKey) { this.addWatch(expression); input.value = ''; return; }
        try {
          this.evaluated = { ...(await backend.invoke('debug.eval', { session: this.paused.session, expression })), name: expression, expression };
        } catch (err) {
          this.evaluated = { name: expression, value: err.message, type: 'error' };
        }
        this.render();
        this.node.querySelector('input.debug-eval')?.focus();
      } });
    vars.append(input);
    if (this.evaluated && this.paused) vars.append(this.row(this.evaluated, 0, { result: true }));
    for (const w of (this.paused ? this.watchValues : null) || this.watches.map((expression) => ({ name: expression, expression, value: '', type: 'watch' }))) {
      vars.append(this.row(w, 0, { watch: true }));
    }
    if (this.paused && this.variables) {
      if (!this.variables.length) vars.append(el('div.placeholder', {}, 'No variables here.'));
      for (const v of this.variables) vars.append(this.row(v, 0));
    } else if (this.paused) {
      vars.append(el('div.placeholder', {}, 'Reading the variables…'));
    }
    const count = [...this.breakpoints.values()].reduce((n, l) => n + l.length, 0);
    if (!this.paused && count) {
      vars.append(el('div.debug-section', {}, `Breakpoints (${count})`));
      for (const [path, list] of this.breakpoints) {
        for (const b of list) {
          vars.append(el('div.row', { onclick: () => this.app.editors.open(path, { line: b.line }) },
            el('span.dbg-dot' + (b.condition ? '.conditional' : '')), el('span.label', {}, `${basename(path)}:${b.line}`),
            el('span.hint', {}, b.condition ? `when ${b.condition}` : path),
            el('button.row-action', { title: 'Remove', onclick: (e) => { e.stopPropagation(); this.toggle(path, b.line); } }, icon('close'))));
        }
      }
    }
  }

  /** One variable (and its children once opened, read from Xdebug then). */
  row(v, level, { watch = false, result = false } = {}) {
    const box = el('div');
    const twisty = el('span.twisty' + (v.children ? '' : '.leaf'));
    const value = el('span.dbg-value.' + (v.type || 'x').replace(/[^a-z]/g, ''), {}, v.type === 'string' ? JSON.stringify(v.value) : String(v.value ?? ''));
    const line = el('div.row', { style: { paddingLeft: `${6 + level * 16}px` } },
      twisty, el('span.dbg-name' + (watch ? '.watch' : result ? '.result' : ''), {}, v.name || v.fullname || '?'), el('span.dbg-eq', {}, ' = '), value,
      v.class && v.type === 'object' ? null : el('span.hint', {}, v.type && !['error', 'watch'].includes(v.type) ? v.type : ''),
      watch ? el('button.row-action', { title: 'Remove the watch', onclick: (e) => { e.stopPropagation(); this.removeWatch(v.expression); } }, icon('close')) : null);
    box.append(line);
    if (v.children) {
      let open = false;
      let kids = null;
      line.onclick = async () => {
        open = !open;
        line.classList.toggle('open', open);
        if (!open) { kids?.remove(); kids = null; return; }
        kids = el('div');
        box.append(kids);
        let items = v.items;
        if (!items && this.paused) {
          try {
            items = (await backend.invoke('debug.children', { session: this.paused.session, fullname: v.fullname || v.expression, depth: this.frame }))?.items || [];
          } catch (e) {
            items = [{ name: e.message, type: 'error' }];
          }
        }
        if (!open || !kids) return;
        for (const c of items || []) kids.append(this.row(c, level + 1));
      };
    }
    return box;
  }
}
