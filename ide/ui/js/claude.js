// The Claude tool window (desktop): conversations with the Claude Code of this computer, in the project's folder, a tab
// each (a new one never stops the others; Open in a Window moves one into a window of its own, closing it brings it
// back). Every conversation is kept in the project (.jumphp/history/claude/, not in git): the History tab reopens them.
// One `claude -p` per conversation (JumPHP\Ide\Claude\ClaudeCode), kept open: a message typed while Claude works goes
// into the turn in progress (more information, "not that way"), as in Claude Code's own terminal. Its answer, the files it reads and changes, the
// commands it runs, appear as they come (claude.event); the conversation goes on (--resume). What it changes on disk
// comes back into the editors by themselves (the IDE watches the files). Follow (the eye): the IDE shows what Claude does
// as it does it (ClaudeWatch); Claude also drives the IDE with its own tools (AgentTools, MCP server jumphp-ide).
import { backend } from './backend.js';
import { el, clear, icon, menu, notify, prefs, prompt, confirm, copyText, debounce, timeAgo } from './ui.js';
import { AgentTools, ClaudeWatch, IDE_TOOL, ideToolLabel } from './agent.js';

const MODES = {
  auto: ['Auto mode', 'Claude works on its own: a safety check approves the safe actions and refuses the risky ones'],
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

const newKey = () => 'c-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);

export class ClaudePanel {
  /**
   * @param detached a conversation key: this page is a window of its own showing only that conversation (Open in a
   * Window); the main window leaves it alone until the window closes (ui.broadcast claude.dock)
   */
  constructor(app, { detached = null } = {}) {
    this.app = app;
    this.detached = detached;
    this.key = 'claude:' + app.projectKey;
    this.mode = prefs.get('claudeMode', 'acceptEdits');
    this.model = prefs.get('claudeModel', '');
    this.effort = prefs.get('claudeEffort', '');
    // the assistant: Claude Code, or OpenCode (https://opencode.ai: any model it has, a local one included), same window
    this.agent = backend.kind === 'native' ? prefs.get('claudeAgent', 'claude') : 'claude';
    this.opencodeModel = prefs.get('opencodeModel', '');
    this.opencodeModels = [];
    this.status = el('span.claude-status');
    if (!MODES[this.mode]) this.mode = 'acceptEdits';
    this.modeSelect = el('select.input.small.claude-select', { title: 'What Claude may do (Shift+Tab in the box: next mode)', onchange: () => this.setMode(this.modeSelect.value) },
      ...Object.entries(MODES).map(([id, [label]]) => el('option', { value: id }, label)));
    this.modeSelect.value = this.mode;
    // Follow (the editors show what Claude does) and the IDE's tools for Claude: the main window's (a conversation in a
    // window of its own has no editors)
    this.watch = detached ? { on: false, use() {}, result() {}, done() {}, toggle() {}, marks: { clearAll() {} } } : new ClaudeWatch(app, this);
    if (backend.kind === 'native' && !detached) this.tools = new AgentTools(app, this.watch);
    this.agentSelect = el('select.input.small.claude-select', { title: 'Who answers: Claude Code, or OpenCode (any model it is set up for, local ones too)', onchange: () => this.setAgent(this.agentSelect.value) },
      el('option', { value: 'claude' }, 'Claude'), el('option', { value: 'opencode' }, 'OpenCode'));
    this.agentSelect.value = this.agent;
    this.modelSelect = el('select.input.small.claude-select', { title: 'The model (claude --model, or opencode --model)', onchange: () => this.pickModel() });
    this.effortSelect = el('select.input.small.claude-select', { title: 'How hard Claude thinks (claude --effort)',
      onchange: () => { this.effort = this.effortSelect.value; prefs.set('claudeEffort', this.effort); } },
      ...EFFORTS.map(([v, label]) => el('option', { value: v, selected: v === this.effort }, label)));
    this.fillModels();
    if (this.agent === 'opencode') backend.invoke('ai.models', { provider: 'opencode' }).then((l) => { this.opencodeModels = l; this.fillModels(); }).catch(() => null);
    this.mcpBanner = el('div.claude-mcp', { hidden: true });
    this.tabsNode = el('div.claude-tabs');
    this.body = el('div.claude-body');
    this.usage = new UsageGauges();
    this.node = el('div.claude-panel', {}, this.tabsNode, this.mcpBanner, this.body, this.usage.node,
      el('div.claude-bar.claude-settings', {}, ...(backend.kind === 'native' ? [this.agentSelect] : []), this.modeSelect, this.modelSelect, this.effortSelect, this.status));
    this.remote = !!app.project.capabilities?.claudeRemote;     // the web IDE: the Claude of the user's computer
    this.historyLocal = backend.kind === 'native' && !this.remote;
    this.convs = [];
    this.active = null;            // a Conversation, or 'history'
    this.saveSoon = debounce(() => this.saveTabs(), 400);
    this.restore();
    backend.on('claude.event', (p) => {
      if (p.event?.type === 'rate_limit_event') this.usage.limits(p.event.rate_limit_info);
      this.route(p)?.event(p);
    });
    backend.on('claude.exit', (p) => this.route(p)?.exit(p));
    backend.on('ui.broadcast', (m) => this.broadcast(m));
    this.checkStatus();
  }

  /** The open tabs come back (an older IDE kept one conversation: it becomes the first tab). */
  restore() {
    if (this.detached) {
      const url = new URLSearchParams(location.search);
      backend.invoke('claude.history.read', { id: this.detached }).catch(() => null).then((data) => {
        const c = this.openConversation({ key: this.detached, session: data?.session || null, items: data?.items || [], title: data?.title || '' });
        const live = Number(url.get('live')) || null;
        if (live) c.live = live;
        if (url.get('running')) c.startTurn(live);
      });
      return;
    }
    const saved = prefs.get(this.key, {});
    const tabs = Array.isArray(saved.tabs) ? saved.tabs
      : (saved.session || saved.history?.length ? [{ key: newKey(), session: saved.session || null, items: saved.history || [] }] : []);
    for (const t of tabs) if (!t.detached) this.openConversation(t, false);
    if (!this.convs.length) this.openConversation({}, false);
    this.show(this.convs.find((c) => c.key === saved.active) || this.convs[this.convs.length - 1]);
  }

  /** The conversation an event belongs to: by its key (claude.send's conv), else by its process (the web IDE). */
  route({ id, conv }) {
    return (conv && this.convs.find((c) => c.key === conv)) || this.convs.find((c) => c.run?.id === id || c.live === id) || null;
  }

  /** A new tab (a new conversation, or one from the history). */
  openConversation(data = {}, show = true) {
    const c = new Conversation(this, { key: data.key || newKey(), session: data.session || null, items: data.items || [], title: data.title || '', context: data.context || null });
    this.convs.push(c);
    if (show || !this.active) this.show(c);
    else this.renderTabs();
    this.saveSoon();
    return c;
  }

  show(target) {
    this.active = target;
    clear(this.body);
    if (target === 'history') this.body.append(this.historyNode());
    else if (target) { this.body.append(target.node); target.scroll(); requestAnimationFrame(() => target.input.focus()); }
    this.usage.context(target && target !== 'history' ? target.context : null);
    this.renderTabs();
    this.saveSoon();
  }

  current() { return this.active instanceof Conversation ? this.active : null; }

  renderTabs() {
    clear(this.tabsNode);
    for (const c of this.convs) {
      this.tabsNode.append(el(`button.tw-tab${c === this.active ? '.on' : ''}`, {
        title: c.title() + (c.run ? ' (working…)' : '') + ' — right click: more',
        onclick: () => this.show(c),
        oncontextmenu: (e) => { e.preventDefault(); this.tabMenu(c, e); },
      }, c.run ? el('span.spinner') : icon('claude'), el('span.claude-tab-title', {}, c.title()),
      this.detached ? null : el('span.x', { title: 'Close (it stays in History)', onclick: (e) => { e.stopPropagation(); this.close(c); } }, '×')));
    }
    if (this.historyLocal && !this.detached) {
      this.tabsNode.append(el(`button.tw-tab${this.active === 'history' ? '.on' : ''}`, { title: 'Every conversation of this project', onclick: () => this.show('history') },
        icon('history'), 'History'));
    }
    this.app.refreshToolHeader?.('claude');
  }

  tabMenu(c, e) {
    menu([
      this.detached || backend.kind !== 'native' ? null : { label: 'Open in a Window', icon: 'split', action: () => this.undock(c) },
      { label: 'Rename…', action: async () => { const t = await prompt('Rename the conversation', { value: c.title() }); if (t != null) { c.name = t.trim(); c.save(); this.renderTabs(); } } },
      this.historyLocal ? { label: 'Copy as Markdown', action: () => c.markdown().then((md) => { copyText(md); notify('Copied', 'info'); }) } : null,
      this.detached ? null : '-',
      this.detached ? null : { label: 'Close', action: () => this.close(c) },
    ].filter(Boolean), e);
  }

  /** Closes a tab: its process ends (the conversation stays in History). */
  async close(c) {
    if (c.run && !(await confirm('Close this conversation?', 'Claude is working in it: closing stops it. It stays in History.', { ok: 'Close', danger: true }))) return;
    await c.saveNow();
    if (!this.remote) backend.invoke('claude.close', { conv: c.key }).catch(() => null);
    else if (c.run) backend.invoke('claude.stop', { id: c.run.id }).catch(() => null);
    this.drop(c);
  }

  drop(c) {
    c.dispose();
    this.convs = this.convs.filter((x) => x !== c);
    if (this.active === c) this.show(this.convs[this.convs.length - 1] || null);
    if (!this.convs.length) this.openConversation();
    this.renderTabs();
    this.saveSoon();
  }

  /** Open in a Window: the conversation goes on in a window of its own (its process too: nothing stops). */
  async undock(c) {
    await c.saveNow();
    try {
      await backend.invoke('claude.window', { conv: c.key, title: c.title(), live: c.live ?? c.run?.id ?? null, running: !!c.run });
    } catch (e) { notify(e.message, 'error'); return; }
    this.drop(c);
    this.saveTabs([c]);
  }

  /** Messages between the IDE's windows (the PHP side sends ui.broadcast to every window). */
  broadcast(m) {
    if (!m || typeof m !== 'object') return;
    if (m.type === 'claude.dock' && !this.detached && !this.convs.some((c) => c.key === m.conv)) {
      // the window of a conversation closed: it comes back as a tab, as it was (its process went on meanwhile)
      backend.invoke('claude.history.read', { id: m.conv }).catch(() => null).then((data) => {
        const c = this.openConversation({ key: m.conv, session: data?.session, items: data?.items || [], title: data?.title || '' });
        if (m.live) c.live = m.live;
        if (m.running && m.live) c.startTurn(m.live);
        this.saveSoon();
      });
    } else if (m.type === 'claude.open' && !this.detached) {
      this.app.editors?.open(m.path, m.line ? { line: m.line } : {});
    }
  }

  /** The open tabs, kept for the next start (the whole conversations are in the project's history). */
  saveTabs(detachedNow = []) {
    if (this.detached) return;
    const tabs = this.convs.map((c) => ({ key: c.key, session: c.session, title: c.name || '', items: c.items.slice(-200), context: c.context || null }));
    prefs.set(this.key, { tabs, active: this.current()?.key || null });
    void detachedNow;     // a conversation in a window is not reopened as a tab at the next start: History has it
  }

  /** History: every conversation of the project, newest first: open one again (a tab; it goes on), or delete it. */
  historyNode() {
    const node = el('div.claude-history', {}, el('div.placeholder', {}, 'Loading…'));
    backend.invoke('claude.history.list').then((list) => {
      clear(node);
      if (!list.length) { node.append(el('div.placeholder.claude-empty', {}, 'No conversation yet.')); return; }
      node.append(el('div.claude-history-head', {}, `${list.length} conversation(s), kept in .jumphp/history/claude/ (not in git)`));
      for (const h of list) {
        const open = this.convs.find((c) => c.key === h.id);
        node.append(el('div.claude-history-row', { title: 'Open it in a tab (it goes on where it was)', onclick: () => this.reopen(h.id) },
          icon('claude'), el('div.claude-history-text', {}, el('div.claude-history-title', {}, h.title),
            el('div.claude-history-meta', {}, `${timeAgo(h.updated)} · ${h.messages} message(s)${open ? ' · open' : ''}`)),
          el('button.btn.small', { title: 'Copy as Markdown', onclick: (e) => { e.stopPropagation(); backend.invoke('claude.history.markdown', { id: h.id }).then((md) => { copyText(md); notify('Copied', 'info'); }); } }, icon('file')),
          el('button.btn.small', { title: open ? 'Close its tab first' : 'Delete', disabled: !!open, onclick: async (e) => {
            e.stopPropagation();
            if (await confirm('Delete this conversation?', h.title, { ok: 'Delete', danger: true })) { await backend.invoke('claude.history.delete', { id: h.id }); this.show('history'); }
          } }, icon('close'))));
      }
    }).catch((e) => { clear(node); node.append(el('div.placeholder', {}, e.message)); });
    return node;
  }

  async reopen(id) {
    const open = this.convs.find((c) => c.key === id);
    if (open) { this.show(open); return; }
    const data = await backend.invoke('claude.history.read', { id }).catch((e) => { notify(e.message, 'error'); return null; });
    if (data) this.openConversation({ key: data.id, session: data.session, items: data.items || [], title: data.title });
  }

  /** The plan (TodoWrite) goes to the conversation whose event is being read (ClaudeWatch calls it). */
  showPlan(todos) { (this.planTarget || this.current())?.showPlan(todos); }

  /** The model list, with the one typed in "Other…" when it is not a known one. */
  fillModels() {
    clear(this.modelSelect);
    if (this.agent === 'opencode') {
      this.effortSelect.hidden = true;
      this.modelSelect.append(el('option', { value: '' }, 'Model: OpenCode\'s default'));
      const names = [...new Set([...(this.opencodeModel ? [this.opencodeModel] : []), ...this.opencodeModels])];
      for (const n of names) this.modelSelect.append(el('option', { value: n }, n));
      this.modelSelect.append(el('option', { value: '?' }, 'Other…'));
      this.modelSelect.value = this.opencodeModel;
      return;
    }
    this.effortSelect.hidden = false;
    const known = MODELS.flatMap(([v, g]) => (Array.isArray(g) ? g.map(([id]) => id) : [v]));
    for (const [v, group] of MODELS) {
      if (!Array.isArray(group)) { this.modelSelect.append(el('option', { value: v }, group)); continue; }
      this.modelSelect.append(el('optgroup', { label: v }, ...group.map(([id, label]) => el('option', { value: id }, label))));
    }
    if (this.model && !known.includes(this.model)) this.modelSelect.append(el('option', { value: this.model }, this.model));
    this.modelSelect.append(el('option', { value: '?' }, 'Other…'));
    this.modelSelect.value = this.model;
  }

  /** Claude or OpenCode answers from now on: the model list changes, and the conversation starts a session of its own with it. */
  async setAgent(agent) {
    this.agent = agent === 'opencode' ? 'opencode' : 'claude';
    prefs.set('claudeAgent', this.agent);
    if (this.agent === 'opencode') {
      const s = await backend.invoke('claude.status').catch(() => null);
      if (s && s.opencode && !s.opencode.available) notify('OpenCode was not found on this computer. Install it: https://opencode.ai', 'error', { title: 'OpenCode' });
      this.opencodeModels = await backend.invoke('ai.models', { provider: 'opencode' }).catch(() => []);
    }
    this.fillModels();
    const c = this.current?.();
    if (c) {
      c.session = null;           // a session of one assistant does not exist for the other
      c.add({ role: 'note', text: this.agent === 'opencode' ? 'OpenCode answers now (it edits files directly, review the changes with Git).' : 'Claude answers now.' });
    }
  }

  async pickModel() {
    let value = this.modelSelect.value;
    if (this.agent === 'opencode') {
      if (value === '?') value = ((await prompt('Another model', { value: this.opencodeModel, label: 'As opencode --model wants it: provider/model, e.g. ollama/qwen2.5-coder', placeholder: 'provider/model' })) || '').trim();
      if (!/^[A-Za-z0-9._:/@-]{0,100}$/.test(value)) value = this.opencodeModel;
      this.opencodeModel = value;
      prefs.set('opencodeModel', value);
      this.fillModels();
      return;
    }
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
    if (!s.available) for (const c of this.convs) c.notInstalled();
    else if (was === false) for (const c of this.convs) c.rerender();
    if (s.available && !this.remote && backend.kind === 'native' && !this.detached) this.checkMcp({ quiet: true });
    // the web IDE: keep looking for the computer while it is not connected
    clearTimeout(this.statusTimer);
    if (this.remote && !s.available) this.statusTimer = setTimeout(() => this.checkStatus(), 5000);
  }

  /**
   * jumphp.net's MCP server in Claude Code: without it, Claude can not see the user's projects there, publish, run the
   * tests on jumphp.net, keep notes. When it is missing, a banner offers to set it up (signing in first if needed).
   */
  async checkMcp({ quiet = false } = {}) {
    const m = await backend.invoke('claude.mcp.status').catch(() => null);
    this.showMcp(m, quiet);
    return m;
  }

  showMcp(m, quiet = false) {
    this.mcp = m;
    clear(this.mcpBanner);
    if (!m || m.installed || !m.claude || (prefs.get('claudeMcpLater') && quiet)) { this.mcpBanner.hidden = true; return; }
    this.mcpBanner.hidden = false;
    const why = m.url ? `Claude Code has a jumphp server, but for another address (${m.url}).` : `Claude Code is not connected to ${m.server || 'jumphp.net'} yet.`;
    this.mcpBanner.append(
      el('div.claude-mcp-text', {}, el('b', {}, 'Connect Claude to jumphp.net'), el('br'),
        `${why} Connected, Claude sees your projects there, publishes them, runs their tests on jumphp.net, reads the players' feedback and keeps notes for each project.`
        + (m.signedIn ? '' : ' You sign in with jumphp.net first (in your browser).')),
      el('div.claude-mcp-actions', {},
        el('button.btn.primary.small', { onclick: (e) => this.installMcp(e.currentTarget) }, m.signedIn ? 'Connect' : 'Sign in and connect'),
        el('button.btn.small', { title: 'Hide this (Find Action: Connect Claude to jumphp.net)', onclick: () => { prefs.set('claudeMcpLater', true); this.mcpBanner.hidden = true; } }, 'Later')));
  }

  async installMcp(button = null) {
    if (button) { button.disabled = true; button.textContent = 'Connecting…'; }
    const task = this.app.task?.('Connecting Claude to jumphp.net…');
    try {
      await backend.invoke('claude.mcp.install');
      notify('Claude Code now has jumphp.net\'s tools (the "jumphp" MCP server, for every project of this computer). A new conversation uses them.', 'success', { title: 'Claude connected to jumphp.net' });
      prefs.set('claudeMcpLater', false);
    } catch (e) {
      notify(e.message, 'error', { title: 'Claude was not connected to jumphp.net' });
    } finally {
      task?.();
      await this.checkMcp();
    }
  }

  title() { return 'Claude'; }

  headerExtra() { return this.detached ? el('span.tw-title', {}, 'Claude') : null; }

  actions() {
    return [
      this.detached ? null : el(`button${this.watch.on ? '.on' : ''}`, { title: this.watch.on ? 'Following Claude: the IDE shows what it reads, edits and runs (click to stop)' : 'Follow Claude: show in the IDE what it reads, edits and runs',
        onclick: () => { this.watch.toggle(); this.app.refreshToolHeader?.('claude'); } }, icon('eye')),
      this.detached ? null : el('button', { title: 'New conversation (the others go on)', onclick: () => this.openConversation() }, icon('plus')),
      this.detached || backend.kind !== 'native' || this.remote ? null
        : el('button', { title: 'Open this conversation in a window', onclick: () => this.current() && this.undock(this.current()) }, icon('split')),
      el('button', { title: 'What Claude may do: ' + MODES[this.mode][0], onclick: (e) => menu([
        { head: 'What Claude may do' },
        ...Object.entries(MODES).map(([id, [label, hint]]) => ({ label, hint, checked: this.mode === id, action: () => this.setMode(id) })),
        '-',
        { label: 'Forget the permissions given', hint: 'the session\'s, the timed ones and the always ones', action: () => { this.tools?.revokePermissions(); notify('Permissions forgotten', 'info', { title: 'Claude' }); } },
      ], e.currentTarget) }, icon('settings')),
    ].filter(Boolean);
  }

  say(text) { this.status.textContent = text; }

  /** What Claude may do (claude --permission-mode): kept, applies from the next message. */
  setMode(id) {
    if (!MODES[id]) return;
    this.mode = id;
    this.modeSelect.value = id;
    prefs.set('claudeMode', id);
    this.app.refreshToolHeader?.('claude');
  }

  /** Shift+Tab: the next mode, as in Claude Code's terminal. */
  cycleMode() {
    const ids = Object.keys(MODES);
    this.setMode(ids[(ids.indexOf(this.mode) + 1) % ids.length]);
  }

  /** The editor's "Ask Claude": the window, the current conversation, text in its box. */
  ask(text = '') {
    this.app.showTool('claude');
    const c = this.current() || this.convs[0] || this.openConversation();
    if (this.active !== c) this.show(c);
    if (text) c.insert(text);
    c.input.focus();
  }
}

/** One conversation: its log, its plan, its box, its Claude Code process (a tab of the Claude window). */
class Conversation {
  constructor(panel, { key, session, items, title, context = null }) {
    this.context = context;
    this.panel = panel;
    this.app = panel.app;
    this.key = key;
    this.session = session;
    this.items = items;
    this.name = title || '';
    this.run = null;                   // the turn in progress: {id, started, tools}
    this.live = null;                  // the open conversation's process (its id), between turns too
    this.log = el('div.claude-log');
    // the conversation stays at its end: when the window is shown again (it was hidden, its size 0), re-laid out, rebuilt (reopening the IDE,
    // a permission asked) it went back to the top; a reader who scrolled up is left where they are
    this.stick = true;
    this.log.addEventListener('scroll', () => { if (this.log.clientHeight > 0) this.stick = this.log.scrollHeight - this.log.scrollTop - this.log.clientHeight < 80; });
    // the content grows by itself (a tool's result, markdown, an image, the spinner moving): while the reader is at the bottom, follow it
    if (typeof MutationObserver !== 'undefined') {
      let pending = false;
      new MutationObserver(() => {
        if (pending || !this.stick) return;
        pending = true;
        requestAnimationFrame(() => { pending = false; if (this.stick && this.log.clientHeight > 0) this.log.scrollTop = this.log.scrollHeight; });
      }).observe(this.log, { childList: true, subtree: true, characterData: true });
    }
    if (typeof ResizeObserver !== 'undefined') new ResizeObserver(() => { if (this.stick && this.log.clientHeight > 0) this.log.scrollTop = this.log.scrollHeight; }).observe(this.log);
    this.plan = el('div.claude-plan', { hidden: true });
    this.input = el('textarea.input.claude-input', { rows: 3, placeholder: 'Ask Claude… (Enter to send, Shift+Enter: new line, @path: a file)',
      onkeydown: (e) => { if (e.key === 'Tab' && e.shiftKey) { e.preventDefault(); this.panel.cycleMode(); return; } if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); this.send(); } } });
    this.sendBtn = el('button.btn.primary.small', { title: 'Send (Enter): while Claude works, it reads it at once', onclick: () => this.send() }, 'Send');
    this.stopBtn = el('button.btn.small', { title: 'Stop Claude', hidden: true, onclick: () => this.stop() }, icon('stop'), 'Stop');
    this.node = el('div.claude-conv', {}, this.plan, this.log, el('div.claude-compose', {}, this.input,
      el('div.claude-bar', {},
        el('button.btn.small', { title: 'Mention the file open in the editor', onclick: () => this.mentionFile() }, icon('file'), 'File'),
        el('button.btn.small', { title: 'Paste the selected code with its place', onclick: () => this.addSelection() }, icon('code'), 'Selection'),
        el('span.tw-spacer'), this.stopBtn, this.sendBtn)));
    this.saveHistory = debounce(() => this.saveNow(), 1500);
    this.rerender();
  }

  title() {
    if (this.name) return this.name;
    const first = this.items.find((i) => i.role === 'user');
    return first ? first.text.replace(/\s+/g, ' ').slice(0, 40) : 'New conversation';
  }

  rerender() {
    const top = this.log.scrollTop;
    const stick = this.stick;
    clear(this.log);
    for (const item of this.items) this.renderItem(item);
    if (!this.items.length) this.placeholder();
    if (this.run && this.thinking) this.log.append(this.thinking);
    this.log.scrollTop = stick ? this.log.scrollHeight : top;
  }

  placeholder() {
    this.log.append(el('div.placeholder.claude-empty', {}, el('p', {}, 'Claude Code, on this computer, in this project.'),
      el('p', { style: { fontSize: '12px' } }, 'It reads AGENTS.md, writes the game, runs bin/jumphp test… The files it changes open here as it goes.')));
  }

  /** No Claude Code (or, on the web IDE, no computer connected): how to get it, above what the conversation holds. */
  notInstalled() {
    clear(this.log);
    if (this.panel.remote) {
      const command = 'bin/jumphp claude:connect';
      this.log.append(el('div.placeholder.claude-empty', {}, el('p', {}, 'Use the Claude Code of your computer here.'),
        el('p', { style: { fontSize: '12px' } }, 'In your JumPHP project, on your computer, run:'),
        el('pre.claude-code', { title: 'Click to copy', style: { cursor: 'copy' }, onclick: () => { copyText(command); notify('Copied', 'info'); } }, command),
        el('p', { style: { fontSize: '12px' } }, 'It opens the JumPHP IDE and asks you, in your browser, to allow it (or in the JumPHP IDE: your account menu › Claude for jumphp.net). Claude then works on a copy of this project on your computer; what it changes comes back here as drafts.'),
        el('p', { style: { fontSize: '12px', color: 'var(--text-3)' } }, 'Waiting for your computer…')));
    } else {
      this.log.append(el('div.placeholder.claude-empty', {}, el('p', {}, 'Claude Code is not installed on this computer.'),
        el('button.btn.primary', { onclick: () => this.app.openUrl('https://claude.com/claude-code') }, 'Install Claude Code'),
        el('p', { style: { fontSize: '12px' } }, 'Then sign in once in a terminal (claude), and come back.')));
    }
    for (const item of this.items) this.renderItem(item);     // an old conversation can still be read
  }

  /** The open tabs (prefs) and the project's history (the whole conversation, a moment later). */
  save() {
    this.panel.saveSoon();
    if (this.panel.historyLocal && this.items.length) this.saveHistory();
  }

  saveNow() {
    if (!this.panel.historyLocal || !this.items.length) return Promise.resolve();
    return backend.invoke('claude.history.save', { conversation: { id: this.key, title: this.name, session: this.session, items: this.items } })
      .catch(() => null);
  }

  markdown() {
    return this.saveNow().then(() => backend.invoke('claude.history.markdown', { id: this.key }));
  }

  dispose() {
    this.task?.();
    this.task = null;
  }

  mentionFile() {
    const path = this.app.editors?.activePath();
    if (!path) return;
    this.insert(`@${path} `);
  }

  addSelection() {
    const ed = this.app.editors?.editor();
    const path = this.app.editors?.activePath();
    const sel = ed?.getSelection();
    if (!ed || !path || !sel || sel.isEmpty()) { notify('Select some code first.', 'info'); return; }
    const code = ed.getModel().getValueInRange(sel);
    this.insert(`In ${path} (lines ${sel.startLineNumber}-${sel.endLineNumber}):\n\`\`\`\n${code}\n\`\`\`\n`);
  }

  insert(text) {
    const at = this.input.selectionStart ?? this.input.value.length;
    this.input.value = this.input.value.slice(0, at) + text + this.input.value.slice(at);
    this.input.focus();
  }

  async send() {
    const prompt = this.input.value.trim();
    const p = this.panel;
    // the web IDE's Claude (through jumphp.net) takes one message per run
    if (!prompt || (this.run && p.remote)) return;
    if (p.available === false) { this.notInstalled(); return; }
    if (/^\/(rc|remote-control)$/.test(prompt)) { this.remoteControl(); return; }
    await this.app.editors?.saveAll(true);
    this.log.querySelector('.claude-empty')?.remove();
    const during = !!this.run;
    this.add({ role: 'user', text: prompt, during });
    this.input.value = '';
    p.renderTabs();
    try {
      const { id } = await backend.invoke('claude.send', { prompt, session: this.session, mode: p.mode, model: p.agent === 'opencode' ? p.opencodeModel : p.model, effort: p.effort, conv: this.key, agent: p.agent });
      if (!p.remote) this.live = id;
      if (this.run && this.run.id === id) return;       // read by Claude in the turn in progress
      this.startTurn(id);
      if (p.remote) this.pollRemote(id, 0);
    } catch (e) {
      this.add({ role: 'error', text: e.message });
    }
  }

  /** /rc: `claude -p` has no Remote Control, so this conversation goes on in an interactive claude in the Terminal. */
  remoteControl() {
    if (this.panel.remote) { notify('Remote Control needs Claude Code on this computer (the desktop IDE).', 'info'); return; }
    this.input.value = '';
    const session = /^[\w-]+$/.test(this.session || '') ? ` --resume ${this.session}` : '';
    this.app.terminal.run(`claude --remote-control${session}`, 'Claude Remote Control');
    this.add({ role: 'note', text: 'Remote Control: this conversation goes on in the Terminal; open it from claude.ai/code or the Claude app.' });
  }

  /** Claude works: the spinner, Stop, the box for more. */
  startTurn(id) {
    this.run = { id, started: Date.now(), tools: 0 };
    this.stopBtn.hidden = false;
    this.input.placeholder = this.panel.remote ? 'Claude is working…' : 'Tell Claude more while it works: it reads it at once (Enter)';
    this.thinking?.remove();
    this.thinking = el('div.claude-thinking', {}, el('span.spinner'), 'Claude is working…');
    this.log.append(this.thinking);
    this.scroll();
    this.panel.say('working…');
    this.panel.renderTabs();
    this.task?.();
    this.task = this.app.task?.(`Claude is working… (${this.title()})`, { open: () => { this.app.showTool('claude'); this.panel.show(this); } }) || null;
  }

  /** The turn is over (its result, or the process ended): what it changed comes in. */
  endTurn() {
    this.task?.();
    this.task = null;
    if (!this.run) return;
    this.run = null;
    this.panel.watch.done();
    this.thinking?.remove();
    this.stopBtn.hidden = true;
    this.input.placeholder = 'Ask Claude… (Enter to send, Shift+Enter: new line, @path: a file)';
    this.panel.say('ready');
    this.panel.renderTabs();
    this.saveNow();
    this.panel.saveSoon();
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
    if (this.run && this.run.id === null) this.run.id = id;     // a window that opened in the middle of a turn
    if (!this.run || id !== this.run.id) return;
    if (event.session_id) this.session = event.session_id;
    const panel = this.panel;
    if (event.type === 'system' && event.subtype === 'init') {
      panel.say(event.model ? `${event.model}` : 'working…');
      // the context window is only reported with the turn's result: until then, the model's usual one, so the bar moves from the first answer
      if (!this.context?.window) this.context = { ...this.context, window: /\[1m\]|1m$/i.test(event.model || '') ? 1000000 : 200000 };
      if (panel.active === this) panel.usage.context(this.context);
    } else if (event.type === 'assistant') {
      const u = event.message?.usage;
      if (u && !event.parent_tool_use_id) {
        this.context = { ...this.context, used: (u.input_tokens || 0) + (u.cache_read_input_tokens || 0) + (u.cache_creation_input_tokens || 0) + (u.output_tokens || 0) };
        if (panel.active === this) panel.usage.context(this.context);
        panel.saveSoon();
      }
      for (const part of event.message?.content || []) {
        if (part.type === 'text' && part.text?.trim()) this.add({ role: 'assistant', text: part.text });
        else if (part.type === 'tool_use') {
          this.run.tools++;
          this.add({ role: 'tool', name: part.name, input: part.input || {} });
          if (part.name === 'TodoWrite') this.showPlan(part.input?.todos || []);
          panel.planTarget = this;
          panel.watch.use(part);
          panel.planTarget = null;
          this.task?.update(`Claude: ${toolText(part.name, part.input || {}, this.app.project?.root || '')}`);
        }
      }
    } else if (event.type === 'user') {
      for (const part of event.message?.content || []) {
        if (part.type === 'tool_result' && part.is_error) this.add({ role: 'tool-error', text: toText(part.content).slice(0, 600) });
        if (part.type === 'tool_result') panel.watch.result(part);
      }
    } else if (event.type === 'result') {
      const windows = Object.values(event.modelUsage || {}).map((m) => m.contextWindow).filter(Boolean);
      if (windows.length) {
        this.context = { ...this.context, window: Math.max(...windows) };
        if (panel.active === this) panel.usage.context(this.context);
      }
      const seconds = Math.round((event.duration_ms || (Date.now() - this.run.started)) / 1000);
      const cost = typeof event.total_cost_usd === 'number' ? ` · $${event.total_cost_usd.toFixed(3)}` : '';
      if (event.is_error && event.result) this.add({ role: 'error', text: event.result });
      if (event.permission_denials?.length) {
        this.add({ role: 'note', text: `Refused (mode “${MODES[panel.mode][0]}”): ${event.permission_denials.map((d) => d.tool_name).join(', ')}. Change what Claude may do (⚙) and ask again.` });
      }
      this.add({ role: 'done', text: `${event.is_error ? 'Stopped' : 'Done'} · ${seconds} s · ${this.run.tools} action(s)${cost}` });
      if (!panel.remote) { this.endTurn(); return; }
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
    this.items.push(item);
    this.renderItem(item);
    this.scroll();
    this.save();
  }

  renderItem(item) {
    let node;
    if (item.role === 'user') node = el(`div.claude-msg.user${item.during ? '.during' : ''}`, { title: item.during ? 'Sent while Claude was working' : '' }, item.text);
    else if (item.role === 'assistant') node = el('div.claude-msg.assistant', {}, ...markdown(item.text));
    else if (item.role === 'tool') node = this.toolRow(item);
    else if (item.role === 'done') node = el('div.claude-done', {}, item.text);
    else node = el(`div.claude-msg.${item.role === 'note' ? 'note' : item.role === 'tool-error' ? 'toolfail' : 'error'}`, {}, item.text);
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

  /** A file to show: here, or in the main window when this conversation is in a window of its own. */
  openFile(path, opts = {}) {
    if (this.panel.detached) backend.invoke('ui.broadcast', { type: 'claude.open', path, line: opts.line || null }).catch(() => null);
    else this.app.editors.open(path, opts);
  }

  toolRow({ name, input }) {
    if (name.startsWith(IDE_TOOL)) {
      const { tool, what, label } = ideToolLabel(name, input);
      const path = input.path && !String(input.path).startsWith('/') ? String(input.path) : null;
      return el(`div.claude-tool.ide${path ? '.link' : ''}`, { title: `IDE tool: ${tool}`, onclick: () => path && this.openFile(path, input.start_line || input.line ? { line: input.start_line || input.line } : {}) },
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
    return el(`div.claude-tool${opens ? '.link' : ''}`, { title: opens ? 'Open ' + file : '', onclick: () => opens && this.openFile(file) },
      icon(what[0]), el('b', {}, what[1]), el('span', {}, String(what[2] || '').slice(0, 300)));
  }

  scroll() {
    this.stick = true;
    this.log.scrollTop = this.log.scrollHeight;
    requestAnimationFrame(() => { if (this.stick) this.log.scrollTop = this.log.scrollHeight; });
  }
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

/**
 * Claude's usage under the conversations: the open conversation's context window, and the account's 5-hour and weekly
 * limits (what `claude` reports in its rate_limit_event), each with when it starts again. The limits are kept between
 * starts of the IDE (they belong to the account, not to a conversation).
 */
export class UsageGauges {
  constructor() {
    this.bars = {};
    const gauge = (key, label, title) => {
      const fill = el('span.claude-gauge-fill');
      const text = el('span.claude-gauge-text');
      this.bars[key] = { node: el('div.claude-gauge', { title, dataset: { title } }, el('span.claude-gauge-label', {}, label), el('span.claude-gauge-bar', {}, fill), text), fill, text };
      return this.bars[key].node;
    };
    this.node = el('div.claude-usage', {},
      gauge('context', 'Context', 'The open conversation: how much of the model\'s context window it fills (Claude compacts it when it is full)'),
      gauge('five_hour', '5 h', 'Your Claude plan: what is used of the 5-hour limit'),
      gauge('seven_day', 'Week', 'Your Claude plan: what is used of the weekly limit'));
    this.saved = prefs.get('claudeLimits', {}) || {};
    this.context(null);
    this.paint();
    setInterval(() => this.paint(), 15000);
  }

  /** rate_limit_info of a rate_limit_event: {unifiedWindows: {five_hour: {utilization, resetsAt}, seven_day: ...}} */
  limits(info) {
    const windows = info?.unifiedWindows || (info?.rateLimitType ? { [info.rateLimitType]: info } : {});
    for (const key of ['five_hour', 'seven_day']) {
      const w = windows[key];
      if (w && typeof w.utilization === 'number') this.saved[key] = { ratio: w.utilization, resets: w.resetsAt || null };
    }
    prefs.set('claudeLimits', this.saved);
    this.paint();
  }

  context(ctx) {
    const known = ctx?.used && ctx?.window;
    this.set('context', known ? ctx.used / ctx.window : null,
      known ? `${Math.round(100 * ctx.used / ctx.window)} % · ${tokens(ctx.used)} / ${tokens(ctx.window)}` : '—');
  }

  paint() {
    for (const key of ['five_hour', 'seven_day']) {
      const w = this.saved[key];
      const over = w?.resets && w.resets * 1000 <= Date.now();      // that window started again since
      if (!w) { this.set(key, null, '—'); continue; }
      const ratio = over ? 0 : w.ratio;
      this.set(key, ratio, `${Math.round(100 * ratio)} %` + (w.resets && !over ? ` · ↻ ${resetText(w.resets * 1000, key === 'seven_day')}` : ''));
      this.bars[key].node.title = `${this.bars[key].node.dataset.title}: ${Math.round(100 * ratio)} % used` + (w.resets && !over ? `, starts again ${new Date(w.resets * 1000).toLocaleString()}` : '');
    }
  }

  set(key, ratio, text) {
    const b = this.bars[key];
    const r = ratio === null ? 0 : Math.max(0, Math.min(1, ratio));
    b.fill.style.width = `${(100 * r).toFixed(1)}%`;
    b.node.dataset.level = ratio === null ? 'none' : r >= 0.9 ? 'high' : r >= 0.7 ? 'mid' : 'low';
    b.text.textContent = text;
  }
}

function tokens(n) {
  return n >= 1e6 ? `${(n / 1e6).toFixed(n % 1e6 ? 1 : 0)}M` : n >= 1000 ? `${Math.round(n / 1000)}k` : String(n);
}

/** When a limit starts again: "14:30", or with the day for the weekly one ("Thu 09:00"). */
function resetText(ms, withDay) {
  const d = new Date(ms);
  const time = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  return (withDay || d.toDateString() !== new Date().toDateString() ? d.toLocaleDateString([], { weekday: 'short' }) + ' ' : '') + time;
}
