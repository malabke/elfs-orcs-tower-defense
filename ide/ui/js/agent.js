// Claude, seen working in the IDE.
//
// ClaudeWatch: the Claude window's stream (claude.event) shown where it happens: the file Claude reads opens at the
// lines it reads, an edit is marked before (what it replaces) and after (what it wrote), its commands appear in the Run
// window with their output, its searches in the Find window, its plan above the conversation. The keyboard stays where
// the user is; nothing moves while the user types in an editor.
//
// AgentTools: the IDE's own tools for Claude (MCP server jumphp-ide, JumPHP\Ide\Agent): PHP hands each call over as
// agent.call {call, tool, args}, this does it in the interface and answers with agent.reply.
import { backend } from './backend.js';
import { monaco } from './editor.js';
import { el, dialog, notify, prefs } from './ui.js';

export const IDE_TOOL = 'mcp__jumphp-ide__';

/** Where a file path of Claude's (often absolute) is in the project, or null outside it. */
function relative(app, path) {
  if (!path) return null;
  const root = app.project?.root || '';
  if (root && path.startsWith(root + '/')) return path.slice(root.length + 1);
  return path.startsWith('/') ? null : path.replace(/^\.\//, '');
}

function text(content) {
  if (typeof content === 'string') return content;
  if (Array.isArray(content)) return content.map((c) => (typeof c === 'string' ? c : c.text || '')).join('\n');
  return '';
}

/** Marks on lines of a file: a coloured band, a label after the first line. They stay with the file's model. */
class Marks {
  constructor(app) {
    this.app = app;
    this.byKey = new Map();          // key -> {model, ids, timer}
  }

  set(key, path, startLine, endLine, kind, label, ms = 0) {
    this.clear(key);
    const model = this.app.editors.files.get(path)?.model;
    if (!model || model.isDisposed()) return;
    const last = model.getLineCount();
    const start = Math.max(1, Math.min(startLine, last));
    const end = Math.max(start, Math.min(endLine || start, last));
    const decorations = [{
      range: new monaco.Range(start, 1, end, model.getLineMaxColumn(end)),
      options: { isWholeLine: true, className: `claude-mark ${kind}`, hoverMessage: label ? { value: label } : undefined, linesDecorationsClassName: `claude-mark-gutter ${kind}`,
        overviewRuler: { color: { read: '#56a8f5', edit: '#cf8e6d', done: '#5fb865', note: '#c77dbb' }[kind] || '#d97757', position: monaco.editor.OverviewRulerLane.Right } },
    }];
    if (label) {
      decorations.push({ range: new monaco.Range(start, model.getLineMaxColumn(start), start, model.getLineMaxColumn(start)),
        options: { after: { content: `  ✦ ${label}`, inlineClassName: `claude-mark-label ${kind}` } } });
    }
    const ids = model.deltaDecorations([], decorations);
    const entry = { model, ids, timer: ms ? setTimeout(() => this.clear(key), ms) : null };
    this.byKey.set(key, entry);
  }

  clear(key) {
    const entry = this.byKey.get(key);
    if (!entry) return;
    clearTimeout(entry.timer);
    if (!entry.model.isDisposed()) entry.model.deltaDecorations(entry.ids, []);
    this.byKey.delete(key);
  }

  clearAll(prefix = '') {
    for (const key of [...this.byKey.keys()]) if (key.startsWith(prefix)) this.clear(key);
  }
}

/** The line range of a text in a model (its first match), or null. */
function find(model, needle) {
  if (!model || !needle) return null;
  const matches = model.findMatches(needle, false, false, true, null, false, 1);
  if (!matches.length) return null;
  return { start: matches[0].range.startLineNumber, end: matches[0].range.endLineNumber };
}

export class ClaudeWatch {
  constructor(app, panel) {
    this.app = app;
    this.panel = panel;
    this.marks = new Marks(app);
    this.on = prefs.get('claudeFollow', true);
    this.uses = new Map();           // tool_use id -> {name, input, path}
    this.lastTyping = 0;
    document.addEventListener('keydown', (e) => { if (e.target.closest?.('.monaco-editor')) this.lastTyping = Date.now(); }, true);
  }

  toggle(on = !this.on) {
    this.on = on;
    prefs.set('claudeFollow', on);
    if (!on) this.marks.clearAll('w:');
  }

  /** The user types in an editor: do not take the editor away. */
  busy() { return Date.now() - this.lastTyping < 2500; }

  async show(path, line, endLine) {
    if (this.busy() && this.app.editors.activePath() !== path) {
      await this.app.editors.load(path).catch(() => null);
      return;
    }
    await this.app.editors.open(path, { preview: true, focus: false, ...(line ? { line, endLine: endLine || line, select: false } : {}) });
    this.app.tree?.reveal(path);
  }

  status(text) { this.app.status?.(text); }

  /** Claude starts a tool (an assistant message's tool_use). */
  async use(part) {
    const input = part.input || {};
    const path = relative(this.app, input.file_path || input.notebook_path);
    this.uses.set(part.id, { name: part.name, input, path });
    if (!this.on || part.name.startsWith(IDE_TOOL)) return;
    try {
      switch (part.name) {
        case 'Read': {
          if (!path) return;
          const from = Number(input.offset) || 0;
          const to = from && input.limit ? from + Number(input.limit) - 1 : 0;
          this.status(`Claude reads ${path}`);
          await this.show(path, from || 1, to || undefined);
          this.marks.set('w:' + part.id, path, from || 1, to || from || 1, 'read', from ? `Claude reads lines ${from}–${to || '…'}` : 'Claude reads this file', 2500);
          break;
        }
        case 'Edit': case 'MultiEdit': {
          if (!path) return;
          this.status(`Claude edits ${path}`);
          await this.show(path);
          const model = this.app.editors.files.get(path)?.model;
          const first = part.name === 'Edit' ? input.old_string : input.edits?.[0]?.old_string;
          const at = find(model, first);
          if (at) {
            this.app.editors.reveal(this.app.editors.focused, { line: at.start, endLine: at.end, select: false, focus: false });
            this.marks.set('w:' + part.id, path, at.start, at.end, 'edit', 'Claude is changing this');
          }
          break;
        }
        case 'Write': {
          if (!path) return;
          this.status(`Claude writes ${path}`);
          break;
        }
        case 'Bash': {
          if (!this.app.tools.run) return;
          const command = String(input.command || '');
          this.app.runner.addExternal('claude:' + part.id, 'Claude: ' + (input.description || command).slice(0, 40), command, { show: !this.busy() });
          this.status(`Claude runs ${command.slice(0, 80)}`);
          break;
        }
        case 'Grep': {
          if (!input.pattern || this.busy()) return;
          this.app.find.show({ query: String(input.pattern), regex: true, include: input.glob || '', quiet: true });
          this.status(`Claude searches ${input.pattern}`);
          break;
        }
        case 'Glob': this.status(`Claude looks for ${input.pattern}`); break;
        case 'TodoWrite': this.panel.showPlan(input.todos || []); break;
      }
    } catch (e) {
      console.warn('[claude watch]', e);
    }
  }

  /** A tool ended (a user message's tool_result). */
  async result(part) {
    const use = this.uses.get(part.tool_use_id);
    if (!use) return;
    this.uses.delete(part.tool_use_id);
    const { name, input, path } = use;
    if (name === 'Bash' && this.app.tools.run) {
      this.app.runner.finishExternal('claude:' + part.tool_use_id, text(part.content), !!part.is_error);
      return;
    }
    if (!this.on || !path || part.is_error || !['Edit', 'MultiEdit', 'Write'].includes(name)) {
      this.marks.clear('w:' + part.tool_use_id);
      return;
    }
    // what Claude wrote: into the editor (from the disk), marked
    await this.app.editors.reloadFromDisk([path]);
    if (name === 'Write' && !this.app.editors.files.has(path)) await this.show(path);
    else if (!this.busy()) await this.show(path);
    const model = this.app.editors.files.get(path)?.model;
    if (!model) return;
    let at;
    if (name === 'Write') at = { start: 1, end: Math.min(model.getLineCount(), 400) };
    else at = find(model, name === 'Edit' ? input.new_string : input.edits?.at(-1)?.new_string) || null;
    this.marks.clear('w:' + part.tool_use_id);
    if (!at) return;
    if (!this.busy() && this.app.editors.activePath() === path) this.app.editors.reveal(this.app.editors.focused, { line: at.start, endLine: at.end, select: false, focus: false });
    this.marks.set('w:' + part.tool_use_id, path, at.start, at.end, 'done', name === 'Write' ? 'Claude wrote this file' : 'Claude changed this', 6000);
    this.app.tree?.refresh();
  }

  /** The answer ended: what is still marked goes, the plan stays. */
  done() {
    for (const [id, use] of this.uses) if (use.name === 'Bash') this.app.runner?.finishExternal('claude:' + id, '', true);
    this.uses.clear();
    this.status('Claude is done');
  }
}

/** The run configurations Claude may start with a command line: bin/jumphp's checks, builds and runs, not any program. */
const RUN_COMMANDS = ['test', 'shot', 'build', 'build:remote', 'build:site', 'webshot', 'siteshot', 'lint', 'run', 'app:test', 'app:shot', 'app:run', 'serve'];

function parseCommand(line) {
  const words = (String(line).match(/"[^"]*"|'[^']*'|\S+/g) || []).map((w) => w.replace(/^(["'])(.*)\1$/, '$2'));
  const env = {};
  while (words.length && /^JUMPHP_[A-Z_]+=/.test(words[0])) {
    const [k, ...v] = words.shift().split('=');
    env[k] = v.join('=');
  }
  if (words[0] === 'jumphp') words[0] = 'bin/jumphp';
  return { words, env };
}

export class AgentTools {
  constructor(app, watch) {
    this.app = app;
    this.watch = watch;
    this.marks = watch.marks;
    backend.on('agent.call', (p) => this.call(p));
  }

  async call({ call, tool, args }) {
    let result = null;
    let error = null;
    try {
      const fn = this['tool_' + tool];
      if (!fn) throw new Error(`the IDE has no tool ${tool}`);
      result = await fn.call(this, args || {});
    } catch (e) {
      error = e?.message || String(e);
    }
    backend.invoke('agent.reply', { call, result: result ?? 'ok', error }).catch(() => null);
  }

  path(args) {
    const path = relative(this.app, String(args.path || ''));
    if (!path) throw new Error('give a path of the project (relative to its root)');
    return path;
  }

  async open(path, line, endLine) {
    const tab = await this.app.editors.open(path, { focus: false, ...(line ? { line, endLine: endLine || line } : {}) });
    if (!tab) throw new Error(`cannot open ${path}`);
    this.app.tree?.reveal(path);
    return tab;
  }

  tool_get_state() {
    const eds = this.app.editors;
    const ed = eds.editor();
    const sel = ed?.getSelection();
    const model = ed?.getModel();
    const active = eds.activePath();
    return {
      project: this.app.project?.name,
      root: this.app.project?.root,
      open_files: [...new Set(eds.groups.flatMap((g) => g.tabs.filter((t) => t.kind !== 'diff').map((t) => t.path)))],
      unsaved: eds.dirtyPaths(),
      active: active ? {
        path: active,
        cursor: ed?.getPosition() ? { line: ed.getPosition().lineNumber, column: ed.getPosition().column } : null,
        selection: sel && !sel.isEmpty() && model ? { start_line: sel.startLineNumber, end_line: sel.endLineNumber, text: model.getValueInRange(sel).slice(0, 8000) } : null,
      } : null,
      tool_windows: this.app.open,
      available_tool_windows: Object.keys(this.app.tools),
      run_configurations: (this.app.runner?.configs || []).map((c) => ({ id: c.id, label: c.label, kind: c.kind, command: c.command.join(' ') })),
      problems: this.app.problems?.count() ?? 0,
    };
  }

  async tool_open_file(args) {
    const path = this.path(args);
    await this.open(path, Number(args.line) || 0, Number(args.end_line) || 0);
    return `opened ${path}`;
  }

  async tool_highlight(args) {
    const path = this.path(args);
    const start = Number(args.start_line) || 1;
    const end = Number(args.end_line) || start;
    await this.open(path, start, end);
    this.app.editors.editor()?.setSelection(new monaco.Range(start, 1, start, 1));
    this.marks.set('note:' + path + ':' + start, path, start, end, 'note', args.message ? `Claude: ${args.message}` : 'Claude', 0);
    return `highlighted ${path}:${start}-${end}`;
  }

  tool_clear_highlights() {
    this.marks.clearAll('note:');
    return 'cleared';
  }

  async tool_show_diff(args) {
    if (!args.path) {
      if (!this.app.tools.commit) throw new Error('this project has no Git');
      this.app.showTool('commit', true);
      return 'the Commit window shows the changed files';
    }
    const path = this.path(args);
    await this.app.git.diff(path);
    return `diff of ${path} shown`;
  }

  tool_show_tool(args) {
    const id = String(args.tool || '');
    if (!this.app.tools[id]) throw new Error(`no tool window ${id} here (there are: ${Object.keys(this.app.tools).join(', ')})`);
    this.app.showTool(id, true);
    return `${id} shown`;
  }

  async tool_find_in_files(args) {
    const query = String(args.query || '');
    if (!query) throw new Error('nothing to find');
    this.app.find.show({ query, regex: !!args.regex, include: args.include || '', quiet: true });
    const r = await backend.invoke('search.text', { query, options: { ...this.app.find.options, regex: !!args.regex, include: args.include || '' } });
    return { matches: r.matches, files: r.files, truncated: !!r.truncated,
      results: r.results.slice(0, 30).map((f) => ({ path: f.path, lines: f.matches.slice(0, 10).map((m) => m.line) })) };
  }

  async tool_run(args) {
    const runner = this.app.runner;
    if (!runner || !this.app.project?.capabilities?.run) throw new Error('nothing runs in this project here');
    const wanted = String(args.config || '').trim();
    let config = runner.configs.find((c) => c.id === wanted) || runner.configs.find((c) => c.label.toLowerCase() === wanted.toLowerCase());
    let env = {};
    if (!config) {
      const parsed = parseCommand(wanted);
      config = runner.configs.find((c) => c.command.join(' ') === parsed.words.join(' '));
      env = parsed.env;
      if (!config) {
        if (parsed.words[0] !== 'bin/jumphp' || !RUN_COMMANDS.includes(parsed.words[1])) {
          throw new Error(`unknown run configuration "${wanted}": use an id of get_state, or bin/jumphp ${RUN_COMMANDS.join(' | ')} …`);
        }
        const kind = /test$/.test(parsed.words[1]) ? 'test' : /shot$/.test(parsed.words[1]) ? 'shot' : parsed.words[1].startsWith('build') ? 'build' : 'run';
        config = { id: 'adhoc', kind, label: parsed.words.slice(1).join(' '), command: parsed.words };
      }
    }
    if (args.wait === false) {
      const id = await runner.start(config, env);
      if (id === null || id === undefined) throw new Error(`${config.label} could not start`);
      return { started: config.label, run: id };
    }
    const end = await runner.startAndWait(config, env);
    if (!end) throw new Error(`${config.label} could not start`);
    const run = runner.runs.find((r) => r.id === end.id);
    const t = end.tests;
    return {
      configuration: config.label, exit_code: end.code, seconds: end.seconds, error: end.error || undefined,
      tests: t ? { passed: t.passed, failed: t.failed, skipped: t.skipped } : undefined,
      screenshot: end.image || undefined,
      output_end: (run?.output || '').slice(-4000),
    };
  }

  async tool_play(args) {
    const path = this.path(args);
    if (!this.app.playTarget(path)) throw new Error(`nothing to play in ${path}`);
    await this.app.playFile(path);
    return `playing ${path}`;
  }

  async tool_diagnostics(args) {
    if (!args.path) {
      const out = {};
      for (const [path, list] of this.app.problems.byPath) if (list.length) out[path] = list;
      return Object.keys(out).length ? out : 'no problems in the open files';
    }
    const path = this.path(args);
    const file = await this.app.editors.load(path);
    if (!file.model) throw new Error(`${path} is not a text file`);
    const list = await backend.invoke('php.diagnostics', { path, text: file.model.getValue() });
    return list.length ? list.map((d) => `${path}:${d.startLine}:${d.startColumn} ${d.severity}: ${d.message}`).join('\n') : `no problems in ${path}`;
  }

  tool_ask_user(args) {
    const question = String(args.question || '');
    const options = Array.isArray(args.options) ? args.options.map(String).slice(0, 8) : [];
    return new Promise((resolve) => {
      let answered = false;
      const answer = (value) => { answered = true; resolve({ answer: value }); };
      const input = el('textarea.input', { rows: 2, placeholder: options.length ? 'Or another answer…' : 'Your answer…', style: { width: '100%' } });
      const choices = el('div.claude-choices', {}, ...options.map((o) => el('button.btn', { onclick: () => { answer(o); box.close(); } }, o)));
      const box = dialog({
        title: 'Claude asks',
        body: el('div.claude-ask', {}, el('p', {}, question), options.length ? choices : null, input),
        buttons: [{ label: 'Skip' }, { label: 'Answer', primary: true, action: () => { if (!input.value.trim()) return false; answer(input.value.trim()); } }],
        onClose: () => { if (!answered) resolve({ answer: null, skipped: true }); },
      });
      this.app.showTool('claude', true);
      notify(question.slice(0, 140), 'info', { title: 'Claude asks you' });
    });
  }

  /**
   * claude --permission-prompt-tool: a tool that needs a yes (a command, a web page...). The user answers in a dialog:
   * once, always for this session (the same command, or the same tool), or no. Claude Code reads the answer as JSON.
   */
  tool_permission(args) {
    const name = String(args.tool_name || '');
    const input = args.input && typeof args.input === 'object' ? args.input : {};
    const command = name === 'Bash' ? String(input.command || '') : '';
    const what = command || input.url || input.file_path || input.path || input.pattern || JSON.stringify(input).slice(0, 400);
    // "always": the first word of a command (curl, docker, npm...), else the whole tool
    const key = command ? `Bash:${command.trim().split(/\s+/)[0]}` : name;
    this.allowed ??= new Set();
    const allow = () => JSON.stringify({ behavior: 'allow', updatedInput: input });
    if (this.allowed.has(key)) return Promise.resolve(allow());
    return new Promise((resolve) => {
      let answered = false;
      const answer = (yes, always) => {
        answered = true;
        if (yes && always) this.allowed.add(key);
        resolve(yes ? allow() : JSON.stringify({ behavior: 'deny', message: 'The user said no in the IDE. Do something else, or ask them (ask_user) why.' }));
      };
      const body = el('div.claude-ask', {},
        el('p', {}, command ? 'Claude wants to run this command:' : `Claude wants to use ${name}:`),
        el('pre.claude-permission', { style: { whiteSpace: 'pre-wrap', maxHeight: '240px', overflow: 'auto' } }, String(what)),
        input.description ? el('p.pub-faint', {}, String(input.description)) : null);
      dialog({
        title: 'Claude asks for permission', body,
        onClose: () => { if (!answered) answer(false, false); },
        buttons: [
          { label: 'No', action: () => answer(false, false) },
          { label: command ? `Always “${key.slice(5)}” (this session)` : `Always ${name} (this session)`, action: () => answer(true, true) },
          { label: 'Yes, once', primary: true, action: () => answer(true, false) },
        ],
      });
      this.app.showTool('claude', true);
      notify(String(what).slice(0, 140), 'info', { title: 'Claude asks for permission' });
    });
  }

  tool_notify(args) {
    notify(String(args.message || ''), ['info', 'success', 'error'].includes(args.kind) ? args.kind : 'info', { title: 'Claude' });
    return 'shown';
  }
}

export function ideToolLabel(name, input) {
  const tool = name.slice(IDE_TOOL.length);
  const what = input.path ? `${input.path}${input.start_line || input.line ? ':' + (input.start_line || input.line) : ''}` : input.config || input.question || input.message || input.query || input.tool || '';
  return { tool, what: String(what), label: { get_state: 'Looked at the IDE', open_file: 'Opened', highlight: 'Pointed at', clear_highlights: 'Cleared the marks',
    show_diff: 'Showed the changes', show_tool: 'Showed', find_in_files: 'Showed a search', run: 'Ran', play: 'Played', diagnostics: 'Checked',
    ask_user: 'Asked you', notify: 'Told you', permission: 'Asked your permission' }[tool] || tool };
}
