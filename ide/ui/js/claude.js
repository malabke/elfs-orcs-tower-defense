// The Claude tool window (desktop): a conversation with the Claude Code of this computer, in the project's folder.
// Each message runs `claude -p` (JumPHP\Ide\Claude\ClaudeCode): its answer, the files it reads and changes, the
// commands it runs, appear as they come (claude.event); the conversation goes on (--resume). What it changes on disk
// comes back into the editors by themselves (the IDE watches the files).
import { backend } from './backend.js';
import { el, clear, icon, menu, notify, prefs } from './ui.js';

const MODES = {
  acceptEdits: ['Edit files', 'Claude changes files without asking; commands it may not run are refused'],
  plan: ['Plan only', 'Claude reads and proposes, changes nothing'],
  default: ['Ask (refuse what needs a yes)', 'Claude can only do what needs no permission'],
  bypassPermissions: ['Full auto', 'Claude edits and runs any command without asking: only in a project you trust'],
};

export class ClaudePanel {
  constructor(app) {
    this.app = app;
    this.key = 'claude:' + app.projectKey;
    const saved = prefs.get(this.key, {});
    this.session = saved.session || null;
    this.history = Array.isArray(saved.history) ? saved.history.slice(-200) : [];
    this.mode = prefs.get('claudeMode', 'acceptEdits');
    this.run = null;                   // {id, started, node}
    this.status = el('span.game-status');
    this.log = el('div.claude-log');
    this.input = el('textarea.input.claude-input', { rows: 3, placeholder: 'Ask Claude… (Enter to send, Shift+Enter: new line, @path: a file)',
      onkeydown: (e) => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); this.send(); } } });
    this.sendBtn = el('button.btn.primary.small', { onclick: () => (this.run ? this.stop() : this.send()) }, 'Send');
    this.node = el('div.claude-panel', {}, this.log, el('div.claude-compose', {}, this.input,
      el('div.claude-bar', {},
        el('button.btn.small', { title: 'Mention the file open in the editor', onclick: () => this.mentionFile() }, icon('file'), 'File'),
        el('button.btn.small', { title: 'Paste the selected code with its place', onclick: () => this.addSelection() }, icon('code'), 'Selection'),
        el('span.tw-spacer'), this.sendBtn)));
    for (const item of this.history) this.renderItem(item);
    if (!this.history.length) this.placeholder();
    backend.on('claude.event', (p) => this.event(p));
    backend.on('claude.exit', (p) => this.exit(p));
    backend.invoke('claude.status').then((s) => {
      this.available = s.available;
      this.say(s.available ? (s.version || 'Claude Code') : 'not installed');
      if (!s.available) this.notInstalled();
    }).catch(() => null);
  }

  title() { return 'Claude'; }

  headerExtra() { return this.status; }

  actions() {
    return [
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
    this.log.append(el('div.placeholder.claude-empty', {}, el('p', {}, 'Claude Code is not installed on this computer.'),
      el('button.btn.primary', { onclick: () => this.app.openUrl('https://claude.com/claude-code') }, 'Install Claude Code'),
      el('p', { style: { fontSize: '12px' } }, 'Then sign in once in a terminal (claude), and come back.')));
  }

  say(text) { this.status.textContent = text; }

  save() { prefs.set(this.key, { session: this.session, history: this.history.slice(-200) }); }

  reset() {
    if (this.run) this.stop();
    this.session = null;
    this.history = [];
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
    if (!prompt || this.run) return;
    if (this.available === false) { this.notInstalled(); return; }
    await this.app.editors.saveAll(true);
    this.log.querySelector('.claude-empty')?.remove();
    this.add({ role: 'user', text: prompt });
    this.input.value = '';
    try {
      const { id } = await backend.invoke('claude.send', { prompt, session: this.session, mode: this.mode });
      this.run = { id, started: Date.now(), tools: 0 };
      this.sendBtn.textContent = 'Stop';
      this.thinking = el('div.claude-thinking', {}, el('span.spinner'), 'Claude is working…');
      this.log.append(this.thinking);
      this.scroll();
      this.say('working…');
    } catch (e) {
      this.add({ role: 'error', text: e.message });
    }
  }

  stop() {
    if (this.run) backend.invoke('claude.stop', { id: this.run.id }).catch(() => null);
  }

  event({ id, event }) {
    if (!this.run || id !== this.run.id) return;
    if (event.session_id) this.session = event.session_id;
    if (event.type === 'system' && event.subtype === 'init') {
      this.say(event.model ? `${event.model}` : 'working…');
    } else if (event.type === 'assistant') {
      for (const part of event.message?.content || []) {
        if (part.type === 'text' && part.text?.trim()) this.add({ role: 'assistant', text: part.text });
        else if (part.type === 'tool_use') { this.run.tools++; this.add({ role: 'tool', name: part.name, input: part.input || {} }); }
      }
    } else if (event.type === 'user') {
      for (const part of event.message?.content || []) {
        if (part.type === 'tool_result' && part.is_error) this.add({ role: 'tool-error', text: toText(part.content).slice(0, 600) });
      }
    } else if (event.type === 'result') {
      const seconds = Math.round((event.duration_ms || (Date.now() - this.run.started)) / 1000);
      const cost = typeof event.total_cost_usd === 'number' ? ` · $${event.total_cost_usd.toFixed(3)}` : '';
      if (event.is_error && event.result) this.add({ role: 'error', text: event.result });
      if (event.permission_denials?.length) {
        this.add({ role: 'note', text: `Refused (mode “${MODES[this.mode][0]}”): ${event.permission_denials.map((d) => d.tool_name).join(', ')}. Change what Claude may do (⚙) and ask again.` });
      }
      this.add({ role: 'done', text: `${event.is_error ? 'Stopped' : 'Done'} · ${seconds} s · ${this.run.tools} action(s)${cost}` });
    }
    this.save();
  }

  exit({ id, code, error }) {
    if (!this.run || id !== this.run.id) return;
    if (code !== 0 && error) this.add({ role: 'error', text: error });
    this.run = null;
    this.thinking?.remove();
    this.sendBtn.textContent = 'Send';
    this.say('ready');
    this.save();
    // what it changed: the tree, Git, the open files
    this.app.tree?.refresh();
    this.app.git?.refresh();
    this.app.editors?.reloadFromDisk();
  }

  add(item) {
    this.history.push(item);
    this.renderItem(item);
    this.scroll();
  }

  renderItem(item) {
    let node;
    if (item.role === 'user') node = el('div.claude-msg.user', {}, item.text);
    else if (item.role === 'assistant') node = el('div.claude-msg.assistant', {}, ...markdown(item.text));
    else if (item.role === 'tool') node = this.toolRow(item);
    else if (item.role === 'done') node = el('div.claude-done', {}, item.text);
    else node = el(`div.claude-msg.${item.role === 'note' ? 'note' : 'error'}`, {}, item.text);
    if (this.thinking?.isConnected) this.log.insertBefore(node, this.thinking);
    else this.log.append(node);
  }

  toolRow({ name, input }) {
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

