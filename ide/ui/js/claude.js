// The Claude tool window (desktop): a conversation with the Claude Code of this computer, in the project's folder.
// One `claude -p` per conversation (JumPHP\Ide\Claude\ClaudeCode), kept open: a message typed while Claude works goes
// into the turn in progress (more information, "not that way"), as in Claude Code's own terminal. Its answer, the files it reads and changes, the
// commands it runs, appear as they come (claude.event); the conversation goes on (--resume). What it changes on disk
// comes back into the editors by themselves (the IDE watches the files). Follow (the eye): the IDE shows what Claude does
// as it does it (ClaudeWatch); Claude also drives the IDE with its own tools (AgentTools, MCP server jumphp-ide).
import { backend } from './backend.js';
import { el, clear, icon, menu, notify, prefs, prompt } from './ui.js';
import { AgentTools, ClaudeWatch, IDE_TOOL, ideToolLabel } from './agent.js';

const MODES = {
  acceptEdits: ['Edit files', 'Claude changes files without asking; commands it may not run are refused'],
  plan: ['Plan only', 'Claude reads and proposes, changes nothing'],
  default: ['Ask (refuse what needs a yes)', 'Claude can only do what needs no permission'],
  bypassPermissions: ['Full auto', 'Claude edits and runs any command without asking: only in a project you trust'],
};

// claude --model: '' = Claude Code's own setting; aliases follow the newest model of each family; "Other…" any full name
const MODELS = [
  ['', 'Model: default'],
  ['Newest', [['fable', 'Fable (newest)'], ['opus', 'Opus (newest)'], ['sonnet', 'Sonnet (newest)'], ['haiku', 'Haiku (newest)']]],
  ['Versions', [['claude-fable-5-1', 'Fable 5.1'], ['claude-opus-5-5', 'Opus 5.5'], ['claude-sonnet-5-5', 'Sonnet 5.5'], ['claude-haiku-5-5', 'Haiku 5.5'],
    ['opusplan', 'Opus plans, Sonnet codes']]],
];

// claude --effort: how hard it thinks
const EFFORTS = [['', 'Thinking: default'], ['low', 'Thinking: low'], ['medium', 'Thinking: medium'], ['high', 'Thinking: high'],
  ['xhigh', 'Thinking: extra high'], ['max', 'Thinking: max']];

export class ClaudePanel {
  constructor(app) {
    this.app = app;
    this.key = 'claude:' + app.projectKey;
    const saved = prefs.get(this.key, {});
    this.session = saved.session || null;
    this.history = Array.isArray(saved.history) ? saved.history.slice(-200) : [];
    this.mode = prefs.get('claudeMode', 'acceptEdits');
    this.run = null;                   // the turn in progress: {id, started, tools}
    this.live = null;                  // the open conversation's process (its id), between turns too
    this.status = el('span.game-status');
    this.log = el('div.claude-log');
    this.plan = el('div.claude-plan', { hidden: true });
    this.watch = new ClaudeWatch(app, this);
    if (backend.kind === 'native') this.tools = new AgentTools(app, this.watch);
    this.input = el('textarea.input.claude-input', { rows: 3, placeholder: 'Ask Claude… (Enter to send, Shift+Enter: new line, @path: a file)',
      onkeydown: (e) => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); this.send(); } } });
    this.sendBtn = el('button.btn.primary.small', { title: 'Send (Enter): while Claude works, it reads it at once', onclick: () => this.send() }, 'Send');
    this.stopBtn = el('button.btn.small', { title: 'Stop Claude', hidden: true, onclick: () => this.stop() }, icon('stop'), 'Stop');
    this.model = prefs.get('claudeModel', '');
    this.effort = prefs.get('claudeEffort', '');
    this.modelSelect = el('select.input.small.claude-select', { title: 'The model Claude uses (claude --model)', onchange: () => this.pickModel() });
    this.effortSelect = el('select.input.small.claude-select', { title: 'How hard Claude thinks (claude --effort)',
      onchange: () => { this.effort = this.effortSelect.value; prefs.set('claudeEffort', this.effort); } },
      ...EFFORTS.map(([v, label]) => el('option', { value: v, selected: v === this.effort }, label)));
    this.fillModels();
    this.node = el('div.claude-panel', {}, this.plan, this.log, el('div.claude-compose', {}, this.input,
      el('div.claude-bar', {},
        el('button.btn.small', { title: 'Mention the file open in the editor', onclick: () => this.mentionFile() }, icon('file'), 'File'),
        el('button.btn.small', { title: 'Paste the selected code with its place', onclick: () => this.addSelection() }, icon('code'), 'Selection'),
        el('span.tw-spacer'), this.stopBtn, this.sendBtn),
      el('div.claude-bar', {}, this.modelSelect, this.effortSelect)));
    for (const item of this.history) this.renderItem(item);
    if (!this.history.length) this.placeholder();
    backend.on('claude.event', (p) => this.event(p));
    backend.on('claude.exit', (p) => this.exit(p));
    this.remote = !!app.project.capabilities?.claudeRemote;     // the web IDE: the Claude of the user's computer
    this.checkStatus();
  }

  /** The model list, with the one typed in "Other…" when it is not a known one. */
  fillModels() {
    clear(this.modelSelect);
    const known = MODELS.flatMap(([v, g]) => (Array.isArray(g) ? g.map(([id]) => id) : [v]));
    for (const [v, group] of MODELS) {
      if (!Array.isArray(group)) { this.modelSelect.append(el('option', { value: v }, group)); continue; }
      this.modelSelect.append(el('optgroup', { label: v }, ...group.map(([id, label]) => el('option', { value: id }, label))));
    }
    if (this.model && !known.includes(this.model)) this.modelSelect.append(el('option', { value: this.model }, this.model));
    this.modelSelect.append(el('option', { value: '?' }, 'Other…'));
    this.modelSelect.value = this.model;
  }

  async pickModel() {
    let value = this.modelSelect.value;
    if (value === '?') {
      value = ((await prompt('Another model', { value: this.model, label: 'Its name for claude --model, e.g. claude-opus-4-1 or claude-sonnet-5-5[1m]:', placeholder: 'claude-…' })) || '').trim();
      if (!/^[A-Za-z0-9._[\]-]{1,60}$/.test(value)) value = this.model;
    }
    this.model = value;
    prefs.set('claudeModel', value);
    this.fillModels();
  }

  async checkStatus() {
    const s = await backend.invoke('claude.status').catch(() => null);
    if (!s) return;
    const was = this.available;
    this.available = s.available;
    this.say(s.available ? (s.version || 'Claude Code') : (this.remote ? 'not connected' : 'not installed'));
    if (!s.available) this.notInstalled();
    else if (was === false) { clear(this.log); for (const item of this.history) this.renderItem(item); if (!this.history.length) this.placeholder(); }
    // the web IDE: keep looking for the computer while it is not connected
    clearTimeout(this.statusTimer);
    if (this.remote && !s.available) this.statusTimer = setTimeout(() => this.checkStatus(), 5000);
  }

  title() { return 'Claude'; }

  headerExtra() { return this.status; }

  actions() {
    return [
      el(`button${this.watch.on ? '.on' : ''}`, { title: this.watch.on ? 'Following Claude: the IDE shows what it reads, edits and runs (click to stop)' : 'Follow Claude: show in the IDE what it reads, edits and runs',
        onclick: () => { this.watch.toggle(); this.app.refreshToolHeader('claude'); } }, icon('eye')),
      el('button', { title: 'New conversation', onclick: () => this.reset() }, icon('plus')),
      el('button', { title: 'What Claude may do: ' + MODES[this.mode][0], onclick: (e) => menu([
        { head: 'What Claude may do' },
        ...Object.entries(MODES).map(([id, [label, hint]]) => ({ label, hint, checked: this.mode === id, action: () => {
          this.mode = id;
          prefs.set('claudeMode', id);
          this.app.refreshToolHeader('claude');
        } })),
      ], e.currentTarget) }, icon('settings')),
    ];
  }

  placeholder() {
    this.log.append(el('div.placeholder.claude-empty', {}, el('p', {}, 'Claude Code, on this computer, in this project.'),
      el('p', { style: { fontSize: '12px' } }, 'It reads AGENTS.md, writes the game, runs bin/jumphp test… The files it changes open here as it goes.')));
  }

  notInstalled() {
    clear(this.log);
    if (this.remote) {
      const command = 'bin/jumphp claude:connect';
      this.log.append(el('div.placeholder.claude-empty', {}, el('p', {}, 'Use the Claude Code of your computer here.'),
        el('p', { style: { fontSize: '12px' } }, 'In your JumPHP project, on your computer, run:'),
        el('pre.claude-code', { title: 'Click to copy', style: { cursor: 'copy' }, onclick: () => { navigator.clipboard?.writeText(command); notify('Copied', 'info'); } }, command),
        el('p', { style: { fontSize: '12px' } }, 'It opens the JumPHP IDE and asks you, in your browser, to allow it (or in the JumPHP IDE: your account menu › Claude for jumphp.net). Claude then works on a copy of this project on your computer; what it changes comes back here as drafts.'),
        el('p', { style: { fontSize: '12px', color: 'var(--text-3)' } }, 'Waiting for your computer…')));
      return;
    }
    this.log.append(el('div.placeholder.claude-empty', {}, el('p', {}, 'Claude Code is not installed on this computer.'),
      el('button.btn.primary', { onclick: () => this.app.openUrl('https://claude.com/claude-code') }, 'Install Claude Code'),
      el('p', { style: { fontSize: '12px' } }, 'Then sign in once in a terminal (claude), and come back.')));
  }

  say(text) { this.status.textContent = text; }

  save() { prefs.set(this.key, { session: this.session, history: this.history.slice(-200) }); }

  reset() {
    if (this.run || this.live) backend.invoke('claude.stop', { id: this.run?.id ?? this.live }).catch(() => null);
    this.live = null;
    this.endTurn();
    this.session = null;
    this.history = [];
    this.showPlan([]);
    this.watch.marks.clearAll();
    this.save();
    clear(this.log);
    this.placeholder();
    this.input.focus();
  }

  mentionFile() {
    const path = this.app.editors.activePath();
    if (!path) return;
    this.insert(`@${path} `);
  }

  addSelection() {
    const ed = this.app.editors.editor();
    const path = this.app.editors.activePath();
    const sel = ed?.getSelection();
    if (!ed || !path || !sel || sel.isEmpty()) { notify('Select some code first.', 'info'); return; }
    const code = ed.getModel().getValueInRange(sel);
    this.insert(`In ${path} (lines ${sel.startLineNumber}-${sel.endLineNumber}):\n\`\`\`\n${code}\n\`\`\`\n`);
  }

  /** Opens the window with text in the box (the editor's "Ask Claude"). */
  ask(text = '') {
    this.app.showTool('claude');
    if (text) this.insert(text);
    this.input.focus();
  }

  insert(text) {
    const at = this.input.selectionStart ?? this.input.value.length;
    this.input.value = this.input.value.slice(0, at) + text + this.input.value.slice(at);
    this.input.focus();
  }

  async send() {
    const prompt = this.input.value.trim();
    // the web IDE's Claude (through jumphp.net) takes one message per run
    if (!prompt || (this.run && this.remote)) return;
    if (this.available === false) { this.notInstalled(); return; }
    await this.app.editors.saveAll(true);
    this.log.querySelector('.claude-empty')?.remove();
    const during = !!this.run;
    this.add({ role: 'user', text: prompt, during });
    this.input.value = '';
    try {
      const { id } = await backend.invoke('claude.send', { prompt, session: this.session, mode: this.mode, model: this.model, effort: this.effort });
      if (!this.remote) this.live = id;
      if (this.run && this.run.id === id) return;       // read by Claude in the turn in progress
      this.startTurn(id);
      if (this.remote) this.pollRemote(id, 0);
    } catch (e) {
      this.add({ role: 'error', text: e.message });
    }
  }

  /** Claude works: the spinner, Stop, the box for more. */
  startTurn(id) {
    this.run = { id, started: Date.now(), tools: 0 };
    this.stopBtn.hidden = false;
    this.input.placeholder = this.remote ? 'Claude is working…' : 'Tell Claude more while it works: it reads it at once (Enter)';
    this.thinking?.remove();
    this.thinking = el('div.claude-thinking', {}, el('span.spinner'), 'Claude is working…');
    this.log.append(this.thinking);
    this.scroll();
    this.say('working…');
    this.task?.();
    this.task = this.app.task('Claude is working…', { open: () => this.app.showTool('claude') });
  }

  /** The turn is over (its result, or the process ended): what it changed comes in. */
  endTurn() {
    this.task?.();
    this.task = null;
    if (!this.run) return;
    this.run = null;
    this.watch.done();
    this.thinking?.remove();
    this.stopBtn.hidden = true;
    this.input.placeholder = 'Ask Claude… (Enter to send, Shift+Enter: new line, @path: a file)';
    this.say('ready');
    this.save();
    // what it changed: the tree, Git, the open files
    this.app.tree?.refresh();
    this.app.git?.refresh();
    this.app.editors?.reloadFromDisk();
  }

  /** The web IDE: the events of the computer's Claude, through jumphp.net (they arrive as claude.event / claude.exit). */
  async pollRemote(id, after) {
    if (!this.run || this.run.id !== id) return;
    const r = await backend.invoke('claude.poll', { id, after }).catch(() => null);
    if (!this.run || this.run.id !== id) return;
    setTimeout(() => this.pollRemote(id, r ? r.next : after), r ? 700 : 3000);
  }

  stop() {
    if (this.run) backend.invoke('claude.stop', { id: this.run.id }).catch(() => null);
  }

  event({ id, event }) {
    // the open conversation goes on by itself (a message that came just as a turn ended): a turn again
    if (!this.run && id === this.live && event.type !== 'result' && event.type !== 'system') this.startTurn(id);
    if (!this.run || id !== this.run.id) return;
    if (event.session_id) this.session = event.session_id;
    if (event.type === 'system' && event.subtype === 'init') {
      this.say(event.model ? `${event.model}` : 'working…');
    } else if (event.type === 'assistant') {
      for (const part of event.message?.content || []) {
        if (part.type === 'text' && part.text?.trim()) this.add({ role: 'assistant', text: part.text });
        else if (part.type === 'tool_use') {
          this.run.tools++;
          this.add({ role: 'tool', name: part.name, input: part.input || {} });
          this.watch.use(part);
          this.task?.update(`Claude: ${toolText(part.name, part.input || {}, this.app.project?.root || '')}`);
        }
      }
    } else if (event.type === 'user') {
      for (const part of event.message?.content || []) {
        if (part.type === 'tool_result' && part.is_error) this.add({ role: 'tool-error', text: toText(part.content).slice(0, 600) });
        if (part.type === 'tool_result') this.watch.result(part);
      }
    } else if (event.type === 'result') {
      const seconds = Math.round((event.duration_ms || (Date.now() - this.run.started)) / 1000);
      const cost = typeof event.total_cost_usd === 'number' ? ` · $${event.total_cost_usd.toFixed(3)}` : '';
      if (event.is_error && event.result) this.add({ role: 'error', text: event.result });
      if (event.permission_denials?.length) {
        this.add({ role: 'note', text: `Refused (mode “${MODES[this.mode][0]}”): ${event.permission_denials.map((d) => d.tool_name).join(', ')}. Change what Claude may do (⚙) and ask again.` });
      }
      this.add({ role: 'done', text: `${event.is_error ? 'Stopped' : 'Done'} · ${seconds} s · ${this.run.tools} action(s)${cost}` });
      if (!this.remote) { this.endTurn(); return; }
    }
    this.save();
  }

  exit({ id, code, error, changed }) {
    if (id === this.live) this.live = null;
    if (!this.run || id !== this.run.id) return;
    if (code !== 0 && error) this.add({ role: 'error', text: error });
    if (changed?.length) this.add({ role: 'note', text: `Saved as drafts here: ${changed.join(', ')}` });
    this.endTurn();
  }

  add(item) {
    this.history.push(item);
    this.renderItem(item);
    this.scroll();
  }

  renderItem(item) {
    let node;
    if (item.role === 'user') node = el(`div.claude-msg.user${item.during ? '.during' : ''}`, { title: item.during ? 'Sent while Claude was working' : '' }, item.text);
    else if (item.role === 'assistant') node = el('div.claude-msg.assistant', {}, ...markdown(item.text));
    else if (item.role === 'tool') node = this.toolRow(item);
    else if (item.role === 'done') node = el('div.claude-done', {}, item.text);
    else node = el(`div.claude-msg.${item.role === 'note' ? 'note' : 'error'}`, {}, item.text);
    if (this.thinking?.isConnected) this.log.insertBefore(node, this.thinking);
    else this.log.append(node);
  }

  /** Claude's plan (TodoWrite), kept above the conversation while it works through it. */
  showPlan(todos) {
    clear(this.plan);
    this.plan.hidden = !todos.length || todos.every((t) => t.status === 'completed');
    if (this.plan.hidden) return;
    const done = todos.filter((t) => t.status === 'completed').length;
    this.plan.append(el('div.claude-plan-head', {}, icon('check'), `Plan · ${done}/${todos.length}`),
      ...todos.map((t) => el(`div.claude-plan-item.${t.status || 'pending'}`, {}, el('span.mark', {}, t.status === 'completed' ? '✓' : t.status === 'in_progress' ? '▸' : '○'),
        t.status === 'in_progress' && t.activeForm ? t.activeForm : t.content)));
  }

  toolRow({ name, input }) {
    if (name.startsWith(IDE_TOOL)) {
      const { tool, what, label } = ideToolLabel(name, input);
      const path = input.path && !String(input.path).startsWith('/') ? String(input.path) : null;
      return el(`div.claude-tool.ide${path ? '.link' : ''}`, { title: `IDE tool: ${tool}`, onclick: () => path && this.app.editors.open(path, input.start_line || input.line ? { line: input.start_line || input.line } : {}) },
        icon('claude'), el('b', {}, label), el('span', {}, what.slice(0, 300)));
    }
    const root = this.app.project?.root || '';
    const rel = (p) => (p && root && p.startsWith(root + '/') ? p.slice(root.length + 1) : p);
    const file = rel(input.file_path || input.notebook_path || input.path);
    const what = {
      Read: ['eye', 'Read', file], Write: ['file', 'Wrote', file], Edit: ['code', 'Edited', file], MultiEdit: ['code', 'Edited', file],
      Bash: ['terminal', '$', input.command], Glob: ['find', 'Looked for', input.pattern], Grep: ['find', 'Searched', input.pattern],
      WebFetch: ['globe', 'Fetched', input.url], WebSearch: ['globe', 'Searched the web', input.query], TodoWrite: ['check', 'Planned', `${(input.todos || []).length} step(s)`],
    }[name] || ['settings', name, input.description || ''];
    const opens = file && !file.startsWith('/') && ['Read', 'Write', 'Edit', 'MultiEdit'].includes(name);
    return el(`div.claude-tool${opens ? '.link' : ''}`, { title: opens ? 'Open ' + file : '', onclick: () => opens && this.app.editors.open(file) },
      icon(what[0]), el('b', {}, what[1]), el('span', {}, String(what[2] || '').slice(0, 300)));
  }

  scroll() { this.log.scrollTop = this.log.scrollHeight; }
}

function toText(content) {
  if (typeof content === 'string') return content;
  if (Array.isArray(content)) return content.map((c) => (typeof c === 'string' ? c : c.text || '')).join('\n');
  return '';
}

/** Enough Markdown for Claude's answers: code blocks, `code`, **bold**, paragraphs and lists as lines. */
function markdown(text) {
  const out = [];
  const parts = text.split(/```(\w*)\n?([\s\S]*?)```/g);
  for (let i = 0; i < parts.length; i += 3) {
    for (const para of parts[i].split(/\n{2,}/)) {
      if (!para.trim()) continue;
      const p = el('p');
      for (const [n, chunk] of para.split(/(`[^`]+`|\*\*[^*]+\*\*)/g).entries()) {
        if (n % 2 === 0) p.append(chunk);
        else if (chunk.startsWith('`')) p.append(el('code', {}, chunk.slice(1, -1)));
        else p.append(el('b', {}, chunk.slice(2, -2)));
      }
      out.push(p);
    }
    if (parts[i + 2] !== undefined) out.push(el('pre.claude-code', {}, parts[i + 2].replace(/\n$/, '')));
  }
  return out;
}


/** One line for the status bar: what Claude is doing now. */
function toolText(name, input, root) {
  const rel = (p) => String(p || '').replace(root + '/', '');
  return ({
    Read: () => `reading ${rel(input.file_path)}`, Write: () => `writing ${rel(input.file_path)}`, Edit: () => `editing ${rel(input.file_path)}`,
    MultiEdit: () => `editing ${rel(input.file_path)}`, Bash: () => `$ ${String(input.description || input.command || '').slice(0, 80)}`,
    Grep: () => `searching ${input.pattern}`, Glob: () => `looking for ${input.pattern}`, WebFetch: () => `fetching ${input.url}`,
    WebSearch: () => `searching the web: ${input.query}`, TodoWrite: () => 'planning',
  }[name] || (() => name.replace(/^mcp__[^_]+__/, '')))();
}
