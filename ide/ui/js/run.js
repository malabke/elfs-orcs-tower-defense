// Running things: the run configurations (bin/jumphp run / test / shot / build...), the Run tool window (one tab per
// run: console, test results, screenshot), and on the web IDE the game preview and the playground's tests.
// Also the Problems and Structure tool windows.
import { backend } from './backend.js';
import { el, clear, icon, basename, fileIcon, symbolIcon, chooser, notify, prefs, escapeHtml } from './ui.js';

export class Runner {
  constructor(app) {
    this.app = app;
    this.caps = app.project?.capabilities || {};
    this.configs = app.project?.run || [];
    this.selected = prefs.get('runConfig:' + app.projectKey, this.configs[0]?.id || null);
    this.runs = [];              // {id, label, config, output, code, tests, image, node}
    this.active = null;
    this.node = el('div', { style: { height: '100%', display: 'flex', flexDirection: 'column' } });
    this.tabsNode = el('div.tw-tabs');
    backend.on('run.output', (p) => this.output(p.id, p.chunk));
    backend.on('run.exit', (p) => this.exited(p));
  }

  title() { return 'Run'; }
  headerExtra() { return this.tabsNode; }

  actions() {
    const run = this.current();
    return [
      el('button', { title: 'Rerun (Ctrl+F5)', disabled: !run || run.external, onclick: () => run && this.start(run.config) }, icon('refresh')),
      el('button', { title: 'Stop (Ctrl+F2)', disabled: !run || run.code !== undefined, onclick: () => run && this.stop(run) }, icon('stop')),
      el('button', { title: 'Clear', disabled: !run, onclick: () => { if (run) { run.output = ''; this.render(); } } }, icon('close')),
    ];
  }

  config(id = this.selected) { return this.configs.find((c) => c.id === id) || null; }
  current() { return this.runs.find((r) => r.id === this.active) || null; }

  choose(at) {
    const items = this.configs.map((c) => ({ label: c.label, group: c.group, icon: c.icon || 'play', hint: this.selected === c.id ? '✓' : '',
      action: () => { this.selected = c.id; prefs.set('runConfig:' + this.app.projectKey, c.id); this.app.updateRunWidget(); } }));
    chooser(items, { at, placeholder: 'Run configurations', width: 380 });
  }

  /**
   * Run Anything (Ctrl twice): type a bin/jumphp command, completed as it is typed (its commands, then the project's
   * games / apps), Enter runs it in the Run window.
   */
  runAnything() {
    const modules = (this.app.project?.modules || []).filter((m) => m.kind !== 'php').map((m) => m.id);
    const words = (text) => (text.match(/"[^"]*"?|'[^']*'?|\S+/g) || []).map((w) => w.replace(/^["']|["']$/g, ''));
    const items = (typed) => {
      const text = typed.replace(/^\s*(bin\/jumphp|jumphp)\b\s*/, '');
      const parts = words(text);
      const ending = /\s$/.test(text) || text === '';
      const out = [];
      const known = COMMANDS.find(([name]) => name === parts[0]);
      if (parts.length <= 1 && !ending) {
        for (const [name, args, what] of COMMANDS) {
          if (name.startsWith(parts[0] || '')) out.push({ label: `bin/jumphp ${name} ${args}`.trim(), hint: what, icon: 'terminal', complete: `bin/jumphp ${name} ` });
        }
      } else if (parts.length === 0) {
        for (const [name, args, what] of COMMANDS) out.push({ label: `bin/jumphp ${name} ${args}`.trim(), hint: what, icon: 'terminal', complete: `bin/jumphp ${name} ` });
      } else if (known && /^\[?<(id|game|app)>/.test(known[1]) && (parts.length === 1 || (parts.length === 2 && !ending))) {
        for (const id of modules) {
          if (id.startsWith(parts[1] || '')) out.push({ label: `bin/jumphp ${parts[0]} ${id}`, hint: known[2], icon: 'play', complete: `bin/jumphp ${parts[0]} ${id} ` });
        }
      }
      if (parts.length) {
        const command = ['bin/jumphp', ...parts];
        out.push({ label: `Run: ${command.join(' ')}`, icon: 'play', action: () => this.start({ id: 'adhoc', kind: 'run', label: parts.slice(0, 2).join(' '), command }) });
      }
      return out.slice(-60);
    };
    chooser(items, { placeholder: 'bin/jumphp …  (Tab completes, Enter runs)', width: 560 });
  }

  /** Runs the selected configuration, or the first one of a kind (test) for its module. */
  runSelected(kind = null) {
    let config = this.config();
    if (kind && config && config.kind !== kind) {
      const module = config.group;
      config = this.configs.find((c) => c.group === module && c.kind === kind) || this.configs.find((c) => c.kind === kind) || config;
    }
    if (config) this.start(config);
  }

  async start(config, env = {}) {
    if (!this.caps.run) return;
    await this.app.editors.saveAll(true);
    // bin/jumphp runs everything in Docker: wait until the IDE has it running (it starts Docker Desktop by itself)
    if (/(^|[\/])jumphp$/.test(String(config.command?.[0] || ''))) await this.app.docker();
    try {
      const { id, label } = await backend.invoke('run.start', { id: config.id, command: config.id === 'adhoc' ? config.command : undefined, kind: config.kind, env });
      const shown = Object.entries(env).map(([k, v]) => `${k}=${v} `).join('');
      const run = { id, label: config.label || label, config, output: `$ ${shown}${config.command.join(' ')}\n`, started: Date.now() };
      run.task = this.app.task(taskText(run), { open: () => this.show(run) });
      this.runs = [run, ...this.runs.filter((r) => r.code === undefined || r.config.id !== config.id)].slice(0, 8);
      this.active = id;
      this.app.showTool('run');
      this.render();
      this.app.updateRunWidget();
      return id;
    } catch (e) {
      notify(e.message, 'error', { title: 'Cannot run ' + config.label });
      return null;
    }
  }

  /** Runs a configuration and resolves with its end ({code, image...}), or null when it could not start. */
  async startAndWait(config, env = {}) {
    const id = await this.start(config, env);
    if (id === null || id === undefined) return null;
    return new Promise((resolve) => {
      const off = backend.on('run.exit', (p) => { if (p.id === id) { off?.(); resolve(p); } });
    });
  }

  stop(run = this.runs.find((r) => r.code === undefined)) {
    if (run?.external) return;
    if (run) backend.invoke('run.stop', { id: run.id }).catch(() => null);
  }

  /** A command run by someone else (Claude's Bash): shown as a run tab, its output given when it ends. */
  addExternal(id, label, command, { show = true } = {}) {
    const run = { id, label, external: true, config: { id: 'external', kind: 'external', label, command: [command] }, output: `$ ${command}\n`, started: Date.now() };
    this.runs = [run, ...this.runs].slice(0, 8);
    this.active = id;
    if (show) this.app.showTool('run', true);
    this.render();
    return run;
  }

  finishExternal(id, output, failed = false) {
    const run = this.runs.find((r) => r.id === id);
    if (!run) return;
    run.code = failed ? 1 : 0;
    run.output += (output || '') + `\n${failed ? 'Failed' : 'Done'} in ${Math.round((Date.now() - run.started) / 1000)}s\n`;
    this.render();
  }

  isRunning() { return this.runs.some((r) => r.code === undefined); }

  output(id, chunk) {
    const run = this.runs.find((r) => r.id === id);
    if (!run) return;
    run.output += chunk;
    if (run.output.length > 2_000_000) run.output = run.output.slice(-1_500_000);
    // its progress when the output tells it: PHPUnit's "12 / 44 ( 27%)", StaticPHP's / Docker's "[3/20]"
    const steps = [...chunk.matchAll(/(\d+) \/ (\d+) \(\s*\d+%\)|\[(\d+)\/(\d+)\]/g)].pop();
    if (steps && run.task) {
      const [done, total] = steps[1] ? [+steps[1], +steps[2]] : [+steps[3], +steps[4]];
      if (total > 0 && done <= total) run.task.update(null, done / total);
    }
    if (this.active === id) this.appendConsole(chunk);
  }

  /** The Run window on this run (a click on its task in the status bar). */
  show(run) {
    this.active = run.id;
    this.app.showTool('run');
    this.render();
  }

  exited(p) {
    const run = this.runs.find((r) => r.id === p.id);
    if (!run) return;
    run.task?.();
    run.task = null;
    run.code = p.code;
    run.seconds = p.seconds;
    run.tests = p.tests;
    run.image = p.image;
    run.output += `\nProcess finished with exit code ${p.code}${p.error ? ' (' + p.error + ')' : ''} in ${p.seconds}s\n`;
    if (this.active === p.id) this.render();
    this.app.updateRunWidget();
    if (run.config.kind === 'test' && run.tests) {
      const t = run.tests;
      notify(`${t.passed} passed, ${t.failed} failed${t.skipped ? ', ' + t.skipped + ' skipped' : ''}`, t.failed || p.code ? 'error' : 'success', { title: run.label });
    } else if (p.code !== 0) {
      notify(`${run.label} ended with code ${p.code}`, 'error', { title: 'Run' });
    }
    if (run.image) this.app.editors.open(run.image);
    if (['build', 'tool'].includes(run.config.kind)) { this.app.tree?.refresh(); this.app.git?.refresh(); }
  }

  render() {
    clear(this.tabsNode);
    for (const run of this.runs) {
      const status = run.code === undefined ? el('span.spinner') : icon(run.code === 0 ? 'check' : 'close');
      if (run.code !== undefined) status.style.color = run.code === 0 ? 'var(--green)' : 'var(--red)';
      this.tabsNode.append(el(`button.tw-tab${run.id === this.active ? '.on' : ''}`, { onclick: () => { this.active = run.id; this.render(); } },
        status, run.label, el('span.x', { onclick: (e) => { e.stopPropagation(); this.close(run); } }, '×')));
    }
    this.app.refreshToolHeader?.('run');
    clear(this.node);
    const run = this.current();
    if (!run) {
      this.node.append(el('div.placeholder', {}, this.caps.run ? 'Choose a run configuration in the toolbar and press ▶ (Shift+F10).' : 'Nothing runs here.'));
      return;
    }
    this.consoleNode = el('div.console');
    const out = el('div.out', {}, this.consoleNode);
    if (run.tests && run.tests.suites.length) {
      this.node.append(el('div.run-split', {}, this.testTree(run), out));
    } else {
      this.node.append(el('div', { style: { height: '100%', overflow: 'auto' }, className: 'out' }, this.consoleNode));
    }
    this.consoleNode.innerHTML = this.format(run.output);
    this.bindLinks(this.consoleNode);
    const scroller = this.consoleNode.parentNode;
    requestAnimationFrame(() => { scroller.scrollTop = scroller.scrollHeight; });
  }

  close(run) {
    if (run.code === undefined) this.stop(run);
    this.runs = this.runs.filter((r) => r !== run);
    if (this.active === run.id) this.active = this.runs[0]?.id || null;
    this.render();
  }

  appendConsole(chunk) {
    if (!this.consoleNode) return;
    const scroller = this.consoleNode.parentNode;
    const atEnd = scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 40;
    const span = document.createElement('span');
    span.innerHTML = this.format(chunk);
    this.bindLinks(span);
    this.consoleNode.append(span);
    if (atEnd) scroller.scrollTop = scroller.scrollHeight;
  }

  /** ANSI colours, file:line links, the usual words of bin/jumphp and PHPUnit. */
  format(text) {
    let html = '';
    let open = 0;
    for (const part of String(text).replace(/\r(?!\n)/g, '\n').split(/(\x1b\[[0-9;]*m)/)) {
      const m = part.match(/^\x1b\[([0-9;]*)m$/);
      if (m) {
        const codes = m[1].split(';').filter(Boolean);
        if (!codes.length || codes.includes('0')) { html += '</span>'.repeat(open); open = 0; }
        const cls = codes.filter((c) => /^(1|3[1-6])$/.test(c)).map((c) => 'a' + c).join(' ');
        if (cls) { html += `<span class="${cls}">`; open++; }
        continue;
      }
      html += escapeHtml(part)
        .replace(/((?:[\w.-]+\/)*[\w.-]+\.(?:php|js|yaml|json|html|css))(?::| on line |\()(\d+)\)?/g, '<a class="loc" data-path="$1" data-line="$2">$&</a>')
        .replace(/^(\[x\].*|.*(?:FAILURES!|Fatal error|PHP Warning|Error:|failed|✘).*)$/gm, '<span class="err">$1</span>')
        .replace(/^(.*(?:OK \(|✔|all checks passed|done:).*)$/gm, '<span class="ok">$1</span>')
        .replace(/^(\$ .*)$/gm, '<span class="cmd">$1</span>');
    }
    return html + '</span>'.repeat(open);
  }

  bindLinks(node) {
    for (const a of node.querySelectorAll('a.loc')) {
      a.addEventListener('click', () => {
        let path = a.dataset.path.replace(/^\/workspace\//, '');
        const known = this.app.tree?.files || [];
        if (!known.includes(path)) path = known.find((f) => f.endsWith('/' + path) || f.endsWith(path)) || path;
        this.app.editors.open(path, { line: +a.dataset.line });
      });
    }
  }

  testTree(run) {
    const t = run.tests;
    const box = el('div.tests');
    box.append(el('div.test-summary', {}, el('span.pass', {}, `✔ ${t.passed}`), el('span.fail', {}, `✘ ${t.failed}`), t.skipped ? el('span.skip', {}, `↩ ${t.skipped}`) : null,
      el('span', { style: { color: 'var(--text-3)' } }, `${run.seconds ?? ''}s`)));
    const list = el('div.tree');
    for (const suite of t.suites) {
      const failed = suite.tests.some((x) => x.status === 'failed');
      list.append(el('div.row.open', { style: { paddingLeft: '6px' } }, el('span.twisty'), el('span', { className: failed ? 'fail' : 'pass' }, failed ? '✘' : '✔'), el('span.label', {}, suite.name)));
      for (const test of suite.tests) {
        list.append(el('div.row', { style: { paddingLeft: '30px' }, title: test.message || test.name, onclick: () => {
          if (!test.message || !this.consoleNode) return;
          this.consoleNode.innerHTML = this.format(`${suite.name} › ${test.name}\n\n${test.message}\n`);
          this.bindLinks(this.consoleNode);
        } }, el('span', { className: { passed: 'pass', failed: 'fail', skipped: 'skip' }[test.status] }, { passed: '✔', failed: '✘', skipped: '↩' }[test.status]),
        el('span.label', {}, test.name)));
      }
    }
    box.append(list);
    return box;
  }

  // the web IDE: preview the game with the drafts, and the playground's tests ---------------------------------

  async preview() {
    try {
      const { url } = await backend.invoke('preview.url');
      this.app.openUrl(url);
    } catch (e) { notify(e.message, 'error', { title: 'Preview' }); }
  }

  async remoteTests(only = null) {
    await this.app.editors.saveAll(true);
    const run = { id: 'remote-' + Date.now(), label: only ? `${basename(only)} on jumphp.net` : 'Tests on jumphp.net', config: { id: 'remote', kind: 'test', command: ['tests'] },
      output: `Running ${only || 'the tests'} in the playground sandbox, with your drafts…\n` };
    this.runs.unshift(run);
    run.started = Date.now();
    run.task = this.app.task(only ? `Testing ${basename(only)} on jumphp.net…` : 'Running the tests on jumphp.net…', { open: () => this.show(run) });
    this.active = run.id;
    this.app.showTool('run');
    this.render();
    try {
      const { id } = await backend.invoke('tests.run', only ? { only } : {});
      for (;;) {
        await new Promise((r) => setTimeout(r, 2000));
        const result = await backend.invoke('tests.result', { id });
        if (result.status === 'running') continue;
        run.output += result.output || '';
        this.exited({ id: run.id, code: result.status === 'passed' ? 0 : 1, seconds: Math.round((Date.now() - parseInt(run.id.slice(7), 10)) / 1000), tests: result });
        break;
      }
    } catch (e) {
      this.exited({ id: run.id, code: -1, seconds: 0, error: e.message });
    }
  }
}

/** The Problems tool window: what the PHP side found in the open files. */
export class Problems {
  constructor(app) {
    this.app = app;
    this.byPath = new Map();
    this.node = el('div.tree', { tabIndex: 0 });
  }

  title() { return 'Problems'; }

  set(path, list) {
    if (list.length) this.byPath.set(path, list); else this.byPath.delete(path);
    this.render();
    this.app.badge?.('problems', [...this.byPath.values()].some((l) => l.some((d) => d.severity === 'error')) ? 'red' : this.byPath.size ? 'on' : null);
  }

  count() { return [...this.byPath.values()].reduce((n, l) => n + l.length, 0); }

  render() {
    clear(this.node);
    if (!this.byPath.size) { this.node.append(el('div.placeholder', {}, 'No problems in the open files.')); return; }
    for (const [path, list] of this.byPath) {
      this.node.append(el('div.row.open', { style: { paddingLeft: '6px' } }, el('span.twisty'), fileIcon(path), el('span.label', {}, basename(path)), el('span.hint', {}, path),
        el('span.right', {}, String(list.length))));
      for (const d of list) {
        this.node.append(el('div.row', { style: { paddingLeft: '30px' }, onclick: () => this.app.editors.open(path, { line: d.startLine, col: d.startColumn }) },
          el('span', { style: { color: d.severity === 'error' ? 'var(--red)' : 'var(--yellow)', width: '14px' } }, d.severity === 'error' ? '●' : '▲'),
          el('span.label', {}, d.message), el('span.right', {}, `${d.startLine}:${d.startColumn}`)));
      }
    }
  }
}

/** The Structure tool window: the classes and members of the current PHP file. */
export class Structure {
  constructor(app) {
    this.app = app;
    this.node = el('div.tree', { tabIndex: 0 });
    this.path = null;
  }

  title() { return 'Structure'; }

  async update() {
    const tab = this.app.editors.activeTab();
    clear(this.node);
    if (!tab || tab.kind !== 'text' || !tab.path.endsWith('.php')) { this.node.append(el('div.placeholder', {}, 'Open a PHP file to see its structure.')); return; }
    const model = this.app.editors.files.get(tab.path)?.model;
    if (!model) return;
    const outline = await backend.invoke('php.outline', { text: model.getValue() }).catch(() => []);
    this.app.outlineCache.set(tab.path, outline);
    clear(this.node);
    const vis = { private: el('span', { style: { color: 'var(--red)' }, title: 'private' }, '●'), protected: el('span', { style: { color: 'var(--yellow)' }, title: 'protected' }, '◆'), public: '' };
    for (const s of outline) {
      this.node.append(el('div.row.open', { style: { paddingLeft: '6px' }, onclick: () => this.app.editors.reveal(this.app.editors.focused, { line: s.line, col: s.col }) },
        el('span.twisty'), symbolIcon(s.kind), el('span.label', {}, s.name), el('span.hint', {}, s.detail !== s.name ? s.detail : '')));
      for (const c of s.children || []) {
        this.node.append(el('div.row', { style: { paddingLeft: '30px' }, onclick: () => this.app.editors.reveal(this.app.editors.focused, { line: c.line, col: c.col }) },
          symbolIcon(c.kind), el('span.label', {}, (c.static ? 'static ' : '') + c.name), el('span.hint', {}, c.detail || ''), el('span.right', {}, vis[c.vis] ? vis[c.vis].cloneNode(true) : '')));
      }
    }
    if (!outline.length) this.node.append(el('div.placeholder', {}, 'Nothing declared in this file.'));
  }
}

/** The Hierarchy tool window (Ctrl+H): the class under the cursor, its ancestors as a tree, the classes under it. */
export class Hierarchy {
  constructor(app) {
    this.app = app;
    this.node = el('div.tree', { tabIndex: 0 });
    this.pinned = null;           // a class chosen by a click on a subtype: its hierarchy, until the editor moves
  }

  title() { return 'Hierarchy'; }

  actions() {
    return [el('button', { title: 'Refresh (the class at the cursor)', onclick: () => { this.pinned = null; this.update(true); } }, icon('refresh'))];
  }

  async update(atCursor = false) {
    const tab = this.app.editors.activeTab();
    let data = null;
    if (this.pinned && !atCursor) {
      data = await backend.invoke('php.hierarchy', { class: this.pinned }).catch(() => null);
    } else if (tab && tab.kind === 'text' && tab.path.endsWith('.php')) {
      const editor = this.app.editors.editor();
      const model = this.app.editors.files.get(tab.path)?.model;
      const pos = editor?.getPosition() || { lineNumber: 1, column: 1 };
      if (model) data = await backend.invoke('php.hierarchy', { path: tab.path, text: model.getValue(), line: pos.lineNumber, column: pos.column }).catch(() => null);
      this.pinned = null;
    }
    clear(this.node);
    if (!data) { this.node.append(el('div.placeholder', {}, 'Open a PHP class (or put the cursor on a class name, Ctrl+H) to see its hierarchy.')); return; }
    const open = (n) => { if (n.path) this.app.editors.open(n.path, { line: n.line || 1, col: n.col || 1 }); };
    const row = (n, depth, extra) => el(`div.row${n.unknown || n.internal ? '.dim' : ''}${n.path?.startsWith('/') ? '.ext' : ''}`, {
      style: { paddingLeft: 6 + depth * 16 + 'px' }, title: n.path || (n.internal ? 'built into PHP' : 'not found'), onclick: () => open(n),
    }, el('span.twisty.leaf'), symbolIcon(n.kind === 'interface' ? 'interface' : n.kind === 'trait' ? 'trait' : n.kind === 'enum' ? 'enum' : 'class'),
    el('span.label', {}, n.name.split('\\').pop()), el('span.hint', {}, [extra, n.name.includes('\\') ? n.name.slice(0, n.name.lastIndexOf('\\')) : ''].filter(Boolean).join('  ')));
    this.node.append(el('div.tree-head', {}, 'Supertypes'));
    const supers = (n, depth) => {
      this.node.append(row(n, depth, depth ? n.relation : ''));
      for (const s of n.supers || []) supers(s, depth + 1);
    };
    supers(data, 0);
    this.node.append(el('div.tree-head', {}, `Subtypes (${(data.subs || []).length})`));
    if (!data.subs?.length) this.node.append(el('div.placeholder.small', {}, 'No class extends or implements it in the project.'));
    for (const s of data.subs || []) {
      const r = row(s, 1, s.direct ? '' : 'indirect');
      r.addEventListener('dblclick', () => { this.pinned = s.name; this.update(); });
      this.node.append(r);
    }
  }
}

/** What a run does, for its task in the status bar. */
function taskText(run) {
  const label = run.label || run.config.label || run.config.command.join(' ');
  return {
    run: `Running ${label.replace(/^Run /, '')}`, test: `Testing: ${label.replace(/^Test /, '')}`, shot: `Taking a screenshot: ${label.replace(/^Screenshot /, '')}`,
    serve: `Serving: ${label.replace(/^Serve /, '')}`, build: `Building: ${label}`, webshot: `Checking in a browser: ${label.replace(/^Browser check /, '')}`,
  }[run.config.kind] || label;
}

// bin/jumphp's commands (bin/jumphp help) for Run Anything: [name, arguments, what it does]
const COMMANDS = [
  ['run', '<id>', 'play it on the desktop'], ['test', '<id>', 'lint, phpstan, PHPUnit, smoke test'], ['shot', '<id> [frames]', 'a headless screenshot'],
  ['build', '<id>', 'the browser build'], ['serve', '<id> [port]', 'serve the browser build'], ['webshot', '<id> [seconds] [keys]', 'the browser build in headless Chrome'],
  ['build:remote', '<id>', 'the game as a remote game'], ['install', '<id>', 'composer install'], ['composer', '<id> …', 'composer in the game'],
  ['php', '<dir> …', 'php in the runtime image'], ['export:standalone', '<id> [--linux] [--windows] [--macos]', 'one executable per system'],
  ['export:desktop', '<id>', 'a Docker image that runs it'], ['installer', '<id> --github | --url … | --standalone', 'an installer per system'],
  ['site:new', '<id>', 'a first web site of the game'], ['build:site', '<id>', 'build its web site'], ['siteshot', '<id>', 'check its web site'],
  ['apple', '<id>', 'Mac, iPhone, iPad apps (GitHub)'], ['twa', '<id> --url …', 'an Android app (Google Play)'], ['video', '<id> <film.mp4>', 'a film for the video widget'],
  ['app:run', '<id>', 'a desktop app'], ['app:test', '<id>', 'test a desktop app'], ['app:shot', '<id>', 'a desktop app\'s screenshot'], ['app:export', '<id>', 'export a desktop app'],
  ['new', '<id> ["Title"]', 'a new game'], ['app:new', '<id> ["Title"]', 'a new desktop app'], ['gui:new', '<id> ["Title"]', 'a new graphic app'], ['cli:new', '<id> ["Title"]', 'a new CLI app'],
  ['deploy', '[id…]', 'to the project\'s server'], ['pages', '[id…]', 'GitHub Pages'], ['relay', 'up | down | logs', 'the multiplayer relay'],
  ['lint', '', 'php -l + forbidden words'], ['update', '', 'merge the newest JumPHP'], ['engine:download', '', 'the prebuilt WebAssembly engine'],
  ['engine:build', '', 'build the WebAssembly engine'], ['setup', '[async]', 'build the Docker images'], ['help', '', 'every command'],
];
