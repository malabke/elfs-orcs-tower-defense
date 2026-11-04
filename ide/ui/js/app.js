// JumPHP IDE: the workbench. Loads Monaco, opens the project (desktop: the welcome screen first; web: the project of
// the page), lays out the tool windows (Project, Commit, Structure / Run, Find, Problems, Git), and binds PhpStorm's
// keyboard shortcuts to the IDE's actions.
import { backend } from './backend.js';
import { el, clear, icon, hydrateIcons, progressBar, menu, chooser, dialog, notify, notificationHistory, splitter, prefs, debounce, kbd, basename, timeAgo } from './ui.js';
import { Editors, loadMonaco, monaco } from './editor.js';
import { ProjectTree } from './tree.js';
import { Plugins } from './plugins.js';
import { Extensions } from './extensions.js';
import { searchEverywhere, recentFiles, fileStructure, FindPanel } from './search.js';
import { Git } from './git.js';
import { Runner, Problems, Structure, Hierarchy } from './run.js';
import { Welcome } from './welcome.js';
import { GamePanel } from './game.js';
import { PublishPanel } from './publish.js';
import { createInstaller } from './installer.js';
import { ClaudePanel } from './claude.js';
import { DebugPanel } from './debug.js';
import { GutterRun } from './gutter-run.js';
import { TerminalPanel } from './terminal.js';
import { showLocalHistory } from './localhistory.js';

const $ = (id) => document.getElementById(id);

class Actions {
  constructor() { this.items = new Map(); }
  add(id, label, run, { kbd: keys, icon: ico, when } = {}) { this.items.set(id, { id, label, run, kbd: keys, icon: ico, when }); }
  run(id) { const a = this.items.get(id); if (a && (!a.when || a.when())) a.run(); }
  list() { return [...this.items.values()].filter((a) => !a.when || a.when()); }
}

class App {
  constructor() {
    this.actions = new Actions();
    this.outlineCache = new Map();
    this.tools = {};
    this.project = null;
    this.projectKey = '';
    this.tasks = new Set();
  }

  async start() {
    hydrateIcons();
    // the browser's own context menu never shows over the IDE (the IDE has its menus), but in text fields
    document.addEventListener('contextmenu', (e) => { if (!e.target.closest('input, textarea, .claude-log, .console, .game-log')) e.preventDefault(); });
    // the mouse's back / forward buttons (3 / 4): Navigate > Back / Forward (the last positions, file to file), never
    // the page's own history (the WebView would leave the IDE)
    const side = (e) => e.button === 3 || e.button === 4;
    window.addEventListener('mousedown', (e) => { if (side(e)) e.preventDefault(); }, true);
    window.addEventListener('mouseup', (e) => {
      if (!side(e)) return;
      e.preventDefault();
      e.stopPropagation();
      if (e.button === 3) this.editors?.back(); else this.editors?.forward();
    }, true);
    document.documentElement.dataset.theme = prefs.get('theme', 'dark');
    // a Claude conversation in a window of its own (Open in a Window): that page shows only it
    const claudeWindow = new URLSearchParams(location.search).get('claude');
    if (claudeWindow) { await this.startClaudeWindow(claudeWindow); return; }
    try {
      await loadMonaco();
    } catch (e) {
      document.body.append(el('div.placeholder', {}, 'The editor could not load: ' + e.message));
      return;
    }
    this.bindKeys();
    this.bindHeader();
    backend.on('index.progress', (p) => this.indexProgress(p));
    backend.on('ide.error', (p) => notify(`${p.message} (${p.at})`, 'error', { title: `${p.where}: an error` }));
    backend.on('files.changed', debounce(() => { this.tree?.refresh(); this.git?.refresh(); this.editors?.reloadFromDisk(); }, 300));
    backend.on('project.closed', () => location.reload());
    backend.on('bridge.status', (st) => this.bridgeStatus(st));
    if (backend.kind === 'native') backend.invoke('bridge.status').then((st) => this.bridgeStatus(st)).catch(() => null);
    if (backend.kind === 'native') {
      backend.on('update.state', (st) => this.updateState(st));
      backend.invoke('update.state').then((st) => this.updateState(st)).catch(() => null);
    }
    if (backend.kind === 'native') {
      const state = await backend.invoke('session.state');
      this.session = state;
      if (state.project) await this.projectOpened(state.project, false);
      else { this.welcome = new Welcome(this, $('welcome')); await this.welcome.show(); }
    } else {
      await this.projectOpened(await backend.invoke('project.info'), false);
    }
  }

  /**
   * The page of a window that shows one Claude conversation: its log and its box, nothing else. The files it names open
   * in the main window (ui.broadcast); closing the window brings the conversation back into the Claude window there.
   */
  async startClaudeWindow(key) {
    const state = await backend.invoke('session.state').catch(() => null);
    if (!state?.project) { document.body.replaceChildren(el('div.placeholder', {}, 'No project is open.')); return; }
    this.project = state.project;
    this.projectKey = state.project.root || state.project.name;
    this.task = undefined;          // no status bar here
    this.showTool = () => {};
    const panel = this.claude = new ClaudePanel(this, { detached: key });
    const actions = el('div.tw-actions', {}, ...panel.actions());
    this.refreshToolHeader = () => actions.replaceChildren(...panel.actions());
    document.body.replaceChildren(el('div.claude-window', {},
      el('div.tw-header', {}, panel.headerExtra(), el('span.tw-spacer'), actions),
      el('div.tw-body', {}, panel.node)), el('div', { id: 'overlays' }));
    hydrateIcons();
  }

  /** A project was opened (the welcome screen, or a reload): build the workbench for it. */
  async projectOpened(info, fresh = true) {
    if (fresh && this.project) { location.reload(); return; }
    if (this.project && fresh) return;
    this.project = info;
    this.projectKey = info.root || info.name;
    this.welcome?.hide();
    document.title = `${info.name} – JumPHP IDE`;
    $('projectName').textContent = info.name;
    $('projectLetter').textContent = (info.name[0] || 'J').toUpperCase();
    this.editors = new Editors($('editors'), this);
    await this.editors.init();
    this.editors.on('active', (tab) => this.activeChanged(tab));
    this.editors.on('cursor', (pos) => { $('sbPosition').textContent = `${pos.lineNumber}:${pos.column}`; });
    this.editors.on('dirty', (path) => { if (path.endsWith('.php')) this.diagnose(path); });
    this.editors.on('saved', () => this.git?.refreshSoon());
    this.tree = new ProjectTree(this);
    this.git = new Git(this);
    this.git.refreshSoon = debounce(() => this.git.refresh(), 800);
    this.runner = new Runner(this);
    if (this.project.capabilities?.run && this.project.capabilities?.debug) this.debugger = new DebugPanel(this);
    if (this.project.capabilities?.run) this.gutterRun = new GutterRun(this);
    if (this.project.capabilities?.run) this.extensions = new Extensions(this);
    this.problems = new Problems(this);
    this.structure = new Structure(this);
    this.hierarchy = new Hierarchy(this);
    this.find = new FindPanel(this);
    if (this.project.capabilities?.preview) this.game = new GamePanel(this);
    if (this.project.capabilities?.claude) this.claude = new ClaudePanel(this);
    if (this.project.capabilities?.terminal) this.terminal = new TerminalPanel(this);
    if (backend.kind === 'native' && this.project.playground) this.publishPanel = new PublishPanel(this);
    // the web IDE: plugins run in the visitor's browser when jumphp.net serves their PHP core (backend.config.plugins)
    if (this.project.capabilities?.plugins || (backend.kind !== 'native' && backend.config.plugins)) {
      this.plugins = new Plugins(this);
      await this.plugins.load();
    }
    this.console = { append: (source, text) => { if (this.gitConsole) this.gitConsole.textContent += text; } };
    this.registerTools();
    this.registerActions();
    this.layoutTools();
    await this.tree.refresh();
    await this.git.refresh();
    this.updateHeader();
    this.updateRunWidget();
    this.restoreTabs();
    this.docker();   // in the background: Docker Desktop started if needed, before the first run asks for it
    this.updateJumphp();   // the project's JumPHP as new as the IDE (bin/jumphp, engine...; games/ and apps/ never touched)
    // a project made before New Project created repositories: its own GitHub repository, then jumphp.net
    if (backend.kind === 'native' && info.needsRepository) {
      notify('This project has no GitHub repository of its own: commits cannot be pushed and it cannot be published on jumphp.net.', 'warning',
        { title: 'GitHub', timeout: 0, actions: [{ label: 'Create its GitHub repository…', action: () => this.createRepository() }] });
    }
    setInterval(() => { if (document.visibilityState === 'visible') this.git.refresh(); }, 15000);
    window.addEventListener('focus', () => { this.git.refresh(); this.editors.reloadFromDisk(); this.tree.refresh(); });
    window.addEventListener('beforeunload', () => this.saveTabs());
  }

  // tool windows --------------------------------------------------------------------------------------------

  registerTools() {
    const caps = this.project.capabilities || {};
    const T = (id, side, label, ico, view, key) => { this.tools[id] = { id, side, label, icon: ico, view, key }; };
    T('project', 'left', 'Project', 'folder', this.tree, 'Alt+1');
    if (caps.git) T('commit', 'left', 'Commit', 'commit', { title: () => 'Commit', node: this.git.commitNode, actions: () => [] }, 'Alt+0');
    T('structure', 'left', 'Structure', 'structure', this.structure, 'Alt+7');
    T('hierarchy', 'left', 'Hierarchy', 'hierarchy', this.hierarchy, 'Ctrl+H');
    if (caps.run || caps.tests) T('run', 'bottom', 'Run', 'play', this.runner, 'Alt+4');
    if (this.debugger) T('debug', 'bottom', 'Debug', 'debug', this.debugger, null);
    T('find', 'bottom', 'Find', 'find', this.find, 'Alt+3');
    T('problems', 'bottom', 'Problems', 'problems', this.problems, 'Alt+6');
    if (this.terminal) T('terminal', 'bottom', 'Terminal', 'terminal', this.terminal, 'Alt+F12');
    if (this.game) T('game', prefs.get('gameSide', 'right'), 'Game', 'game', this.game, 'Alt+5');
    if (this.claude) T('claude', 'right', 'Claude', 'claude', this.claude, 'Alt+8');
    if (this.publishPanel) T('publish', 'right', 'jumphp.net', 'globe', this.publishPanel, 'Alt+J');
    this.plugins?.registerTools(T);
    if (caps.log) T('git', 'bottom', 'Git', 'log', { title: () => 'Git', node: this.git.logNode, actions: () => [el('button', { title: 'Refresh', onclick: () => this.git.showLog() }, icon('refresh'))] }, 'Alt+9');
  }

  layoutTools() {
    this.renderStripes();
    const root = document.documentElement;
    for (const v of ['--left-width', '--bottom-height', '--right-width']) { const saved = prefs.get('size' + v); if (saved) root.style.setProperty(v, saved); }
    splitter($('leftSplitter'), '--left-width', { min: 160, max: 700, onEnd: () => prefs.set('size--left-width', getComputedStyle(root).getPropertyValue('--left-width')) });
    splitter($('bottomSplitter'), '--bottom-height', { axis: 'y', invert: true, min: 90, max: innerHeight - 160, onEnd: () => prefs.set('size--bottom-height', getComputedStyle(root).getPropertyValue('--bottom-height')) });
    splitter($('rightSplitter'), '--right-width', { invert: true, min: 240, max: innerWidth - 300, onEnd: () => prefs.set('size--right-width', getComputedStyle(root).getPropertyValue('--right-width')) });
    this.open = { left: null, bottom: null, right: null };
    this.showTool(prefs.get('tool:left', 'project'), true);
    const bottom = prefs.get('tool:bottom', null);
    if (bottom && this.tools[bottom]) this.showTool(bottom, true); else this.closeSide('bottom');
    this.closeSide('right');
  }

  /** The tool window buttons, on the left (left and bottom tools) and the right stripe. */
  renderStripes() {
    const left = $('leftStripe');
    const right = $('rightStripe');
    clear(left);
    clear(right);
    const button = (tool) => {
      const b = el('button', { title: `${tool.label} (${kbd(tool.key)})`, dataset: { tool: tool.id }, onclick: () => this.toggleTool(tool.id) }, icon(tool.icon));
      tool.button = b;
      b.classList.toggle('on', this.open?.[tool.side] === tool.id);
      return b;
    };
    for (const t of Object.values(this.tools).filter((t) => t.side === 'left')) left.append(button(t));
    left.append(el('div.sep'));
    for (const t of Object.values(this.tools).filter((t) => t.side === 'bottom')) left.append(button(t));
    for (const t of Object.values(this.tools).filter((t) => t.side === 'right')) right.append(button(t));
    right.append(el('button', { title: 'Notifications', onclick: () => this.showNotifications() }, icon('bell')));
  }

  /** Moves a tool window to another side (the Game window: right or bottom), remembered. */
  moveTool(id, side) {
    const tool = this.tools[id];
    if (!tool || tool.side === side) return;
    if (this.open[tool.side] === id) this.closeSide(tool.side);
    tool.header = null;
    tool.side = side;
    if (id === 'game') prefs.set('gameSide', side);
    this.renderStripes();
    this.showTool(id);
  }

  toggleTool(id) {
    const tool = this.tools[id];
    if (!tool) return;
    if (this.open[tool.side] === id) this.closeSide(tool.side); else this.showTool(id);
  }

  showTool(id, quiet = false) {
    const tool = this.tools[id];
    if (!tool) return;
    const panel = $(tool.side + 'Panel');
    this.open[tool.side] = id;
    prefs.set('tool:' + tool.side, id);
    panel.classList.remove('closed');
    $(tool.side + 'Splitter').classList.remove('hidden');
    const view = tool.view;
    if (id === 'game' && tool.header && panel.contains(tool.header)) {
      // shown again as it was: moving an iframe in the page would reload the game
      for (const t of Object.values(this.tools)) t.button?.classList.toggle('on', this.open[t.side] === t.id);
      this.editors?.layout();
      return;
    }
    clear(panel);
    const node = typeof view.node === 'function' ? view.node() : view.node;
    if (id === 'commit') {
      panel.append(node);
    } else {
      tool.header = el('div.tw-header', {}, el('span.tw-title', {}, typeof view.title === 'function' ? view.title() : tool.label),
        view.headerExtra ? view.headerExtra() : null, el('span.tw-spacer'), el('div.tw-actions', {}, ...(view.actions ? view.actions() : []),
          el('button', { title: 'Hide', onclick: () => this.closeSide(tool.side) }, icon('minus'))));
      panel.append(tool.header, el('div.tw-body', {}, node));
    }
    for (const t of Object.values(this.tools)) t.button?.classList.toggle('on', this.open[t.side] === t.id);
    if (id === 'structure') this.structure.update();
    if (id === 'hierarchy') this.hierarchy.update();
    if (id === 'git' && !this.git.logNode.firstChild) this.git.showLog();
    if (id === 'problems') this.problems.render();
    view.shown?.();
    if (!quiet && id === 'project') this.tree.node.focus();
    this.editors?.layout();
  }

  refreshToolHeader(id) {
    const tool = this.tools[id];
    if (!tool?.header || this.open[tool.side] !== id) return;
    const actions = tool.header.querySelector('.tw-actions');
    clear(actions);
    actions.append(...(tool.view.actions ? tool.view.actions() : []), el('button', { title: 'Hide', onclick: () => this.closeSide(tool.side) }, icon('minus')));
  }

  closeSide(side) {
    this.open[side] = null;
    prefs.set('tool:' + side, null);
    const panel = $(side + 'Panel');
    panel.classList.add('closed');
    $(side + 'Splitter').classList.add('hidden');
    for (const t of Object.values(this.tools)) t.button?.classList.toggle('on', this.open[t.side] === t.id);
    this.editors?.layout();
    this.editors?.editor()?.focus();
  }

  badge(id, kind) {
    const b = this.tools[id]?.button;
    if (!b) return;
    b.querySelector('.badge')?.remove();
    if (kind) b.append(el(`span.badge${kind === 'red' ? '.red' : ''}`));
  }

  // header, status bar -----------------------------------------------------------------------------------------

  bindHeader() {
    $('mainMenu').addEventListener('click', (e) => this.mainMenu(e.currentTarget));
    $('sbTask').addEventListener('click', (e) => this.showTasks(e.currentTarget));
    $('projectWidget').addEventListener('click', (e) => this.projectMenu(e.currentTarget));
    $('branchWidget').addEventListener('click', (e) => this.git?.branchPopup(e.currentTarget));
    $('sbBranch').addEventListener('click', (e) => this.git?.branchPopup(e.currentTarget));
    $('runConfig').addEventListener('click', (e) => this.runner?.choose(e.currentTarget));
    $('runBtn').addEventListener('click', () => this.actions.run('run'));
    $('debugBtn').addEventListener('click', () => this.actions.run('debug'));
    $('testBtn').addEventListener('click', () => this.actions.run('test'));
    $('stopBtn').addEventListener('click', () => this.runner?.stop());
    $('pullBtn').addEventListener('click', () => this.git?.pull());
    $('commitBtn').addEventListener('click', () => this.showTool('commit'));
    $('pushBtn').addEventListener('click', () => this.git?.push());
    $('previewBtn').addEventListener('click', () => (this.game ? this.game.play() : this.runner?.preview()));
    $('publishBtn').addEventListener('click', () => this.publish());
    $('searchBtn').addEventListener('click', () => searchEverywhere(this));
    $('settingsBtn').addEventListener('click', () => this.settings());
    $('userWidget').addEventListener('click', (e) => this.userMenu(e.currentTarget));
    $('sbNotifications').addEventListener('click', () => this.showNotifications());
    $('sbPosition').addEventListener('click', () => this.editors?.editor()?.trigger('', 'editor.action.gotoLine'));
  }

  updateHeader() {
    const caps = this.project.capabilities || {};
    $('branchWidget').hidden = !caps.git || !caps.branches;
    $('gitButtons').hidden = !caps.git;
    $('pullBtn').hidden = !caps.branches;
    $('runWidget').hidden = !(caps.run && this.project.run?.length) && !caps.tests;
    $('runConfig').hidden = !caps.run;
    $('runBtn').hidden = !caps.run;
    $('debugBtn').hidden = !this.debugger;
    $('stopBtn').hidden = !caps.run;
    $('previewBtn').hidden = !caps.preview;
    $('publishBtn').hidden = !(backend.kind === 'native' && this.project.playground);
    // a project shared with read rights only (the web IDE): said in the status bar, the files open read-only
    let mark = $('sbReadOnly');
    if (caps.readOnly && !mark) {
      mark = el('span.sb-item.sb-readonly', { id: 'sbReadOnly', title: 'This project is shared with you to read: you can open the files, search and run the tests, not change them' }, icon('eye'), 'Read only');
      $('sbBranch').before(mark);
    }
    if (mark) mark.hidden = !caps.readOnly;
    const user = this.session?.user || backend.config.user;
    const widget = $('userWidget');
    clear(widget);
    if (user?.avatar) widget.style.backgroundImage = `url("${user.avatar}")`;
    else widget.append(icon('user'));
  }

  onGitStatus(status) {
    $('branchName').textContent = status.branch || 'detached';
    $('sbBranch').textContent = this.project.capabilities.gitMode === 'drafts' ? `${status.files.length} draft(s)` : (status.branch ? '⎇ ' + status.branch + (status.merging != null ? ' · merging' : '') : '');
    this.badge('commit', status.files.length ? 'on' : null);
    for (const g of this.editors.groups) this.editors.renderTabs(g);
    this.tree.render();
  }

  updateRunWidget() {
    if (!this.runner) return;
    const config = this.runner.config();
    $('runConfigName').textContent = config ? config.label : 'Run';
    $('stopBtn').disabled = !this.runner.isRunning();
    $('runBtn').classList.toggle('active', this.runner.isRunning());
  }

  activeChanged(tab) {
    const path = tab?.path || '';
    $('sbLanguage').textContent = tab?.kind === 'text' ? (this.editors.files.get(path)?.model.getLanguageId() || '').toUpperCase() : '';
    $('sbBreadcrumbs').textContent = path;
    if (this.open?.left === 'structure') this.structure.update();
    if (this.open?.left === 'hierarchy') this.hierarchy.update();
    if (tab && prefs.get('autoscroll', false)) this.tree.reveal(path);
  }

  status(text) {
    const node = $('sbStatus');
    node.textContent = text;
    clearTimeout(this.statusTimer);
    this.statusTimer = setTimeout(() => { node.textContent = ''; }, 4000);
  }

  /**
   * Docker, made ready in the background when a JumPHP project opens (JumPHP\Ide\Async\Docker: Docker Desktop is
   * started when it is not running): a status bar task meanwhile, nothing waits but the runs that need it.
   * Resolves with its state ({state, message}).
   */
  docker() {
    if (!this.project?.jumphp || backend.kind !== 'native') return Promise.resolve({ state: 'ready' });
    if (!this.dockerListening) {
      this.dockerListening = true;
      backend.on('docker.state', (s) => this.dockerChanged(s));
    }
    this.dockerWait ??= backend.invoke('docker.ensure').catch((e) => ({ state: 'failed', message: e.message || String(e) }))
      .then((s) => { if (s?.state !== 'ready' && s?.state !== 'wsl') this.dockerWait = null; return s; });
    return this.dockerWait;
  }

  /**
   * Brings the project's JumPHP files to JumPHP's newest (the IDE's) when it opens: at once when the user did not change
   * them, else asked (the files named). ask: the user asked for it (the debugger needs it): asked even if declined before.
   * Resolves with true when the project is up to date afterwards.
   */
  async updateJumphp({ ask = false, why = '' } = {}) {
    if (!this.project?.jumphp || backend.kind !== 'native' || !this.actions) return true;
    if (this.jumphpUpdating) return this.jumphpUpdating;
    const run = async () => {
      let plan;
      try { plan = await backend.invoke('jumphp.check'); } catch (e) {
        if (ask) notify(e.message, 'error', { title: 'Cannot get the newest JumPHP' });
        return false;
      }
      if (plan.upToDate) { if (plan.latest && plan.current !== plan.latest) await backend.invoke('jumphp.update').catch(() => null); return true; }
      const declined = prefs.get('jumphpDeclined:' + this.projectKey);
      if (plan.changed.length && !ask && declined === plan.latest) return false;
      const apply = async (force) => {
        const task = this.task('Updating JumPHP in this project…');
        try {
          const r = await backend.invoke('jumphp.update', { force });
          task();
          const n = (r.updated?.length || 0) + (r.removed?.length || 0);
          notify(`${n} file(s) of JumPHP updated in this project (bin/jumphp, the engine, tools…; your games are untouched)` + (r.kept?.length ? `; ${r.kept.length} file(s) you changed were kept` : '') + '.', 'success', { title: 'JumPHP updated' });
          this.tree?.refresh();
          this.editors?.reloadFromDisk(); this.git?.refresh();
          return !r.kept?.length;
        } catch (e) {
          task();
          notify(e.message, 'error', { title: 'JumPHP was not updated' });
          return false;
        }
      };
      if (!plan.changed.length) return apply(false);
      const list = plan.changed.slice(0, 12).join('\n') + (plan.changed.length > 12 ? `\n… and ${plan.changed.length - 12} more` : '');
      const text = (why ? why + '\n\n' : '') + (plan.known
        ? `A newer JumPHP is out. ${plan.changed.length} of its file(s) were changed in this project: update them too (your version is replaced), or keep them (the others are updated)?`
        : `A newer JumPHP is out. This project does not say which JumPHP it came from: ${plan.changed.length} file(s) of JumPHP differ here (bin/jumphp, the engine…). Update them? Your projects (games/, apps/) are never touched.`);
      return new Promise((resolve) => {
        dialog({ title: 'Update JumPHP in this project', body: el('div', {}, el('p', { style: { whiteSpace: 'pre-wrap' } }, text), el('pre.console', { style: { maxHeight: '180px', overflow: 'auto' } }, list)),
          buttons: [
            { label: 'Later', action: () => { prefs.set('jumphpDeclined:' + this.projectKey, plan.latest); resolve(false); } },
            ...(plan.known ? [{ label: 'Keep my changes', action: async () => resolve(await apply(false)) }] : []),
            { label: 'Update', primary: true, action: async () => resolve(await apply(true)) },
          ],
          onClose: () => resolve(false) });
      });
    };
    this.jumphpUpdating = run().finally(() => { this.jumphpUpdating = null; });
    return this.jumphpUpdating;
  }

  dockerChanged({ state, message }) {
    const busy = state === 'checking' || state === 'starting';
    if (busy) {
      this.dockerTask ??= this.task(message);
      this.dockerTask.update(message, null);
      if (state === 'starting') this.dockerStarted = true;
      return;
    }
    this.dockerTask?.();
    this.dockerTask = null;
    if (state === 'ready' && this.dockerStarted) notify(message, 'success', { title: 'Docker' });
    if (state === 'wsl' && !this.dockerWslSaid) { this.dockerWslSaid = true; notify(message, 'info', { title: 'Docker' }); }
    if (state === 'missing' || state === 'failed') notify(message, 'error', { title: 'Docker', actions: [{ label: 'Retry', action: () => this.docker() }] });
    this.dockerStarted = false;
  }

  /**
   * A background task in the status bar (spinner, what it does, its progress): every action that runs in the
   * background shows one while it runs. Returns done(); done.update(text, ratio) changes its text and / or progress
   * (ratio 0..1; null: unknown, a moving bar). open: what a click on it in the tasks' list shows.
   */
  task(text, { ratio = null, open = null } = {}) {
    const entry = { text, ratio, open, started: Date.now() };
    this.tasks.add(entry);
    this.drawTasks();
    const done = () => { if (this.tasks.delete(entry)) this.drawTasks(); };
    done.update = (newText = null, newRatio) => {
      if (newText !== null) entry.text = newText;
      if (newRatio !== undefined) entry.ratio = newRatio;
      if (this.tasks.has(entry)) this.drawTasks();
    };
    return done;
  }

  drawTasks() {
    const node = clear($('sbTask'));
    const list = [...this.tasks];
    node.classList.toggle('clickable', list.length > 0);
    node.title = list.length ? list.map((t) => t.text).join('\n') + '\n(click: the background tasks)' : '';
    if (!list.length) return;
    const last = list[list.length - 1];
    node.append(el('span.spinner'), el('span.sb-task-text', {}, last.text), progressBar(last.ratio));
    if (list.length > 1) node.append(el('span.sb-more', {}, `+${list.length - 1}`));
  }

  /** The tasks running in the background, each with its progress (a click on one: where it shows). */
  showTasks(at) {
    const list = [...this.tasks];
    if (!list.length) return;
    const ago = (t) => { const s = Math.round((Date.now() - t.started) / 1000); return s < 60 ? `${s} s` : `${Math.floor(s / 60)} min ${s % 60} s`; };
    menu([{ head: `Background tasks (${list.length})` }, ...list.map((t) => ({
      label: t.text + (t.ratio !== null && t.ratio !== undefined ? ` — ${Math.round(100 * t.ratio)} %` : ''), hint: ago(t),
      action: () => t.open?.(),
    }))], at);
  }

  indexProgress(p) {
    if (p.libraries) {      // the libraries (vendor/...), in the background after the project
      if (p.complete) { this.libraryTask?.(); this.libraryTask = null; return; }
      const text = `Indexing libraries (${p.done}/${p.total} files)…`;
      if (!this.libraryTask) this.libraryTask = this.task(text, { ratio: p.total ? p.done / p.total : null });
      else this.libraryTask.update(text, p.total ? p.done / p.total : null);
      return;
    }
    if (p.complete) { this.indexTask?.(); this.indexTask = null; return; }
    const ratio = p.total ? p.done / p.total : null;
    const text = `Indexing PHP${p.total ? ` (${p.done}/${p.total} files)` : ''}…`;
    if (!this.indexTask) this.indexTask = this.task(text, { ratio });
    else this.indexTask.update(text, ratio);
  }

  // PHP problems ------------------------------------------------------------------------------------------------

  diagnose(path) {
    this.diagnoseQueue ??= new Map();
    clearTimeout(this.diagnoseQueue.get(path));
    this.diagnoseQueue.set(path, setTimeout(async () => {
      const model = this.editors.files.get(path)?.model;
      if (!model) return;
      const list = await backend.invoke('php.diagnostics', { path, text: model.getValue() }).catch(() => null);
      if (!list || model.isDisposed()) return;
      monaco.editor.setModelMarkers(model, 'jumphp', list.map((d) => ({
        startLineNumber: d.startLine, startColumn: d.startColumn, endLineNumber: d.endLine, endColumn: Math.max(d.endColumn, d.startColumn + 1),
        message: d.message, severity: d.severity === 'error' ? monaco.MarkerSeverity.Error : monaco.MarkerSeverity.Warning, source: d.source,
      })));
      this.problems.set(path, list);
    }, 450));
  }

  // actions ----------------------------------------------------------------------------------------------------

  registerActions() {
    const a = this.actions;
    const ed = () => this.editors.editor();
    const trigger = (id) => () => { const e = ed(); if (e) { e.focus(); e.trigger('action', id, null); } };
    const caps = this.project.capabilities || {};
    a.add('searchEverywhere', 'Search Everywhere', () => searchEverywhere(this), { kbd: 'Double Shift', icon: 'search' });
    a.add('gotoFile', 'Go to File…', () => searchEverywhere(this, 'files'), { kbd: 'Ctrl+Shift+N', icon: 'file' });
    a.add('gotoClass', 'Go to Class…', () => searchEverywhere(this, 'classes'), { kbd: 'Ctrl+N' });
    a.add('gotoSymbol', 'Go to Symbol…', () => searchEverywhere(this, 'symbols'), { kbd: 'Ctrl+Alt+Shift+N' });
    a.add('findAction', 'Find Action…', () => searchEverywhere(this, 'actions'), { kbd: 'Ctrl+Shift+A' });
    a.add('recentFiles', 'Recent Files', () => recentFiles(this), { kbd: 'Ctrl+E', icon: 'history' });
    a.add('fileStructure', 'File Structure', () => fileStructure(this), { kbd: 'Ctrl+F12', icon: 'structure' });
    a.add('findInFiles', 'Find in Files…', () => this.findInFiles(), { kbd: 'Ctrl+Shift+F', icon: 'find' });
    if (!caps.readOnly) a.add('replaceInFiles', 'Replace in Files…', () => this.findInFiles({ replace: true }), { kbd: 'Ctrl+Shift+R' });
    a.add('find', 'Find', trigger('actions.find'), { kbd: 'Ctrl+F' });
    a.add('replace', 'Replace', trigger('editor.action.startFindReplaceAction'), { kbd: 'Ctrl+R' });
    a.add('gotoLine', 'Go to Line…', trigger('editor.action.gotoLine'), { kbd: 'Ctrl+G' });
    a.add('declaration', 'Go to Declaration', trigger('editor.action.revealDefinition'), { kbd: 'Ctrl+B' });
    a.add('implementations', 'Go to Implementation(s)', trigger('editor.action.goToImplementation'), { kbd: 'Ctrl+Alt+B' });
    a.add('usages', 'Find Usages', () => this.findUsages(), { kbd: 'Alt+F7', icon: 'find' });
    a.add('rename', 'Rename…', trigger('editor.action.rename'), { kbd: 'Shift+F6' });
    a.add('reformat', 'Reformat Code', trigger('editor.action.formatDocument'), { kbd: 'Ctrl+Alt+L' });
    a.add('comment', 'Comment Line', trigger('editor.action.commentLine'), { kbd: 'Ctrl+/' });
    a.add('back', 'Back', () => this.editors.back(), { kbd: 'Ctrl+Alt+Left' });
    a.add('forward', 'Forward', () => this.editors.forward(), { kbd: 'Ctrl+Alt+Right' });
    a.add('saveAll', 'Save All', () => this.editors.saveAll(), { kbd: 'Ctrl+S' });
    a.add('closeTab', 'Close Tab', () => { const t = this.editors.activeTab(); if (t) this.editors.closeTab(this.editors.focused, t); }, { kbd: 'Ctrl+F4' });
    a.add('split', 'Split Right', () => this.editors.split(), { icon: 'split' });
    if (!caps.readOnly) a.add('newFile', 'New File…', () => this.tree.newFile(this.tree.targetDir()), { kbd: 'Alt+Insert', icon: 'plus' });
    a.add('project', 'Project Tool Window', () => this.toggleTool('project'), { kbd: 'Alt+1', icon: 'folder' });
    a.add('structure', 'Structure Tool Window', () => this.toggleTool('structure'), { kbd: 'Alt+7', icon: 'structure' });
    a.add('problemsTool', 'Problems Tool Window', () => this.toggleTool('problems'), { kbd: 'Alt+6', icon: 'problems' });
    a.add('findTool', 'Find Tool Window', () => this.toggleTool('find'), { kbd: 'Alt+3' });
    a.add('hideTools', 'Hide All Tool Windows', () => { this.closeSide('left'); this.closeSide('bottom'); }, { kbd: 'Ctrl+Shift+F12' });
    a.add('selectInProject', 'Select Opened File in Project', () => this.tree.reveal(this.editors.activePath(), true), { kbd: 'Alt+F1' });
    if (caps.git) {
      a.add('commit', 'Commit…', () => this.showTool('commit'), { kbd: 'Ctrl+K', icon: 'commit' });
      a.add('commitTool', 'Commit Tool Window', () => this.toggleTool('commit'), { kbd: 'Alt+0', icon: 'commit' });
      a.add('push', 'Push…', () => this.git.push(), { kbd: 'Ctrl+Shift+K', icon: 'push' });
      a.add('diff', 'Show Diff with HEAD', () => this.git.diff(this.editors.activePath()), { kbd: 'Ctrl+D', icon: 'diff', when: () => false });
      a.add('rollbackFile', 'Rollback File…', () => { const p = this.editors.activePath(); if (p) this.git.rollback([p]); }, { kbd: 'Ctrl+Alt+Z', icon: 'rollback' });
    }
    if (caps.branches) {
      a.add('update', 'Update Project', () => this.git.pull(), { kbd: 'Ctrl+T', icon: 'pull' });
      a.add('branches', 'Branches…', () => this.git.branchPopup($('branchWidget')), { kbd: 'Ctrl+Shift+`', icon: 'branch' });
    }
    if (caps.log) {
      a.add('gitLog', 'Git Log', () => this.toggleTool('git'), { kbd: 'Alt+9', icon: 'log' });
      a.add('fileHistory', 'Show History of the File', () => this.git.showLog(this.editors.activePath()), { icon: 'history' });
    }
    if (caps.localHistory) a.add('localHistory', 'Show Local History', () => showLocalHistory(this, this.editors.activePath()), { icon: 'history' });
    if (caps.blame) a.add('annotate', 'Annotate with Git Blame', () => this.git.toggleBlame(), { icon: 'history' });
    if (caps.run) {
      a.add('run', 'Run', () => this.runner.runSelected(), { kbd: 'Shift+F10', icon: 'play' });
      a.add('test', 'Run Tests', () => this.runner.runSelected('test'), { kbd: 'Ctrl+Shift+F10', icon: 'test' });
      a.add('shot', 'Take a Screenshot', () => this.runner.runSelected('shot'), { icon: 'camera' });
      a.add('rerun', 'Rerun', () => { const r = this.runner.current(); if (r) this.runner.start(r.config); }, { kbd: 'Ctrl+F5', icon: 'refresh' });
      a.add('stop', 'Stop', () => this.runner.stop(), { kbd: 'Ctrl+F2', icon: 'stop' });
      a.add('runTool', 'Run Tool Window', () => this.toggleTool('run'), { kbd: 'Alt+4', icon: 'play' });
      a.add('runConfigs', 'Run Configurations…', () => this.runner.choose($('runConfig')), { kbd: 'Alt+Shift+F10' });
      a.add('runAnything', 'Run Anything… (bin/jumphp)', () => this.runner.runAnything(), { kbd: 'Ctrl Ctrl', icon: 'terminal' });
      if (this.debugger) {
        const d = this.debugger;
        const paused = () => !!d.paused;
        a.add('debug', 'Debug', () => d.debug(), { kbd: 'Shift+F9', icon: 'debug' });
        a.add('debugResume', 'Resume Program', () => d.step('run'), { kbd: 'F9', icon: 'play', when: paused });
        a.add('stepOver', 'Step Over', () => d.step('step_over'), { kbd: 'F8', icon: 'stepOver', when: paused });
        a.add('stepInto', 'Step Into', () => d.step('step_into'), { kbd: 'F7', icon: 'stepInto', when: paused });
        a.add('stepOut', 'Step Out', () => d.step('step_out'), { kbd: 'Shift+F8', icon: 'stepOut', when: paused });
        a.add('runToCursor', 'Run to Cursor', () => {
          const path = this.editors.activePath();
          const line = this.editors.editor()?.getPosition()?.lineNumber;
          if (path && line) d.runTo(path, line);
        }, { kbd: 'Alt+F9', icon: 'play', when: paused });
        a.add('exceptionBreakpoints', 'Stop at Exceptions…', () => d.editExceptions(), { icon: 'warning' });
        a.add('toggleBreakpoint', 'Toggle Line Breakpoint', () => {
          const path = this.editors.activePath();
          const line = this.editors.editor()?.getPosition()?.lineNumber;
          if (path && line && path.endsWith('.php')) d.toggle(path, line);
        }, { kbd: 'Ctrl+F8', icon: 'debug' });
        a.add('debugTool', 'Debug Tool Window', () => this.toggleTool('debug'), { icon: 'debug' });
      }
      if (backend.kind === 'native') a.add('createInstaller', 'Create an Installer…', () => createInstaller(this), { icon: 'package' });
    } else if (caps.tests) {
      a.add('test', 'Run Tests on jumphp.net', () => this.runner.remoteTests(), { kbd: 'Ctrl+Shift+F10', icon: 'test' });
      a.add('runTool', 'Run Tool Window', () => this.toggleTool('run'), { kbd: 'Alt+4', icon: 'play' });
    }
    if (this.claude) a.add('claudeTool', 'Claude', () => this.toggleTool('claude'), { kbd: 'Alt+8', icon: 'claude' });
    if (this.terminal) a.add('terminalTool', 'Terminal', () => this.toggleTool('terminal'), { kbd: 'Alt+F12', icon: 'terminal' });
    if (this.claude && backend.kind === 'native') a.add('claudeMcp', 'Connect Claude to jumphp.net', async () => {
      this.showTool('claude');
      const m = await this.claude.checkMcp();
      if (m?.installed) notify(`Claude Code already has jumphp.net's tools (the "jumphp" MCP server, ${m.scope} scope).`, 'success', { title: 'Claude and jumphp.net' });
    }, { icon: 'globe' });
    if (caps.preview) {
      a.add('preview', 'Play the Game', () => this.game.play(), { kbd: 'Shift+F10', icon: 'play' });
      a.add('gameTool', 'Game', () => this.toggleTool('game'), { kbd: 'Alt+5', icon: 'game' });
      a.add('previewTab', 'Play in a New Tab', () => this.runner.preview(), { icon: 'globe' });
    }
    this.plugins?.registerActions(a);
    this.extensions?.registerActions(a);
    a.add('settings', 'Settings…', () => this.settings(), { kbd: 'Ctrl+Alt+S', icon: 'settings' });
    a.add('theme', 'Toggle Light / Dark Theme', () => this.toggleTheme());
    a.add('rebuildIndex', 'Rebuild the PHP Index', () => backend.invoke('index.rebuild'));
    if (backend.kind === 'native') {
      a.add('openProject', 'Open Project…', () => this.switchProject(), { icon: 'folder' });
      a.add('closeProject', 'Close Project', () => backend.invoke('projects.close').then(() => location.reload()));
    }
    // a test-only key in the browser test (headless runs pick files from here)
    window.jumphpIde = this;
  }

  /** Keys PhpStorm users expect, outside Monaco too. */
  bindKeys() {
    const map = this.keyMap = {
      'ctrl+shift+n': 'gotoFile', 'ctrl+n': 'gotoClass', 'ctrl+alt+shift+n': 'gotoSymbol', 'ctrl+shift+a': 'findAction', 'ctrl+e': 'recentFiles',
      'ctrl+shift+f': 'findInFiles', 'ctrl+shift+r': 'replaceInFiles', 'ctrl+k': 'commit', 'ctrl+shift+k': 'push', 'ctrl+t': 'update',
      'shift+f10': 'run', 'ctrl+shift+f10': 'test', 'ctrl+f5': 'rerun', 'ctrl+f2': 'stop', 'alt+1': 'project', 'alt+0': 'commitTool',
      'alt+4': 'runTool', 'alt+6': 'problemsTool', 'alt+7': 'structure', 'alt+9': 'gitLog', 'alt+3': 'findTool', 'ctrl+alt+s': 'settings',
      'ctrl+shift+f12': 'hideTools', 'alt+f1': 'selectInProject', 'ctrl+s': 'saveAll', 'alt+shift+f10': 'runConfigs', 'ctrl+alt+z': 'rollbackFile',
      'alt+f12': 'terminalTool', 'alt+insert': 'newFile', 'ctrl+shift+`': 'branches',
      'shift+f9': 'debug', 'f9': 'debugResume', 'f8': 'stepOver', 'f7': 'stepInto', 'shift+f8': 'stepOut', 'alt+f9': 'runToCursor', 'ctrl+f8': 'toggleBreakpoint',
    };
    let lastShift = 0;
    let lastCtrl = 0;
    document.addEventListener('keydown', (e) => {
      if (!this.project) return;
      // in the Terminal the keys are the shell's (Ctrl+E, Ctrl+K, Escape...): only Alt+… reaches the IDE
      if (e.target.closest?.('.terminal-panel') && !(e.altKey && !e.ctrlKey && !e.metaKey)) return;
      // Ctrl twice: Run Anything (bin/jumphp commands)
      if (e.key === 'Control' && !e.repeat && !e.shiftKey && !e.altKey) {
        const now = Date.now();
        if (now - lastCtrl < 350 && this.project.capabilities?.run && !document.querySelector('.overlay')) { lastCtrl = 0; this.runner?.runAnything(); return; }
        lastCtrl = now;
      } else if (e.key !== 'Control') lastCtrl = 0;
      const key = [e.ctrlKey || e.metaKey ? 'ctrl' : '', e.altKey ? 'alt' : '', e.shiftKey ? 'shift' : '', e.key.length === 1 ? e.key.toLowerCase() : e.key.toLowerCase()].filter(Boolean).join('+');
      if (e.key === 'Shift' && !e.repeat) {
        const now = Date.now();
        if (now - lastShift < 350) { lastShift = 0; searchEverywhere(this); return; }
        lastShift = now;
        return;
      }
      if (e.key !== 'Shift') lastShift = 0;
      const id = map[key];
      const item = id && this.actions.items.get(id);
      if (item && (!item.when || item.when())) {
        if (document.querySelector('.overlay') && !['saveAll'].includes(id)) return;
        e.preventDefault();
        e.stopPropagation();
        this.actions.run(id);
      } else if (e.key === 'Escape' && !document.querySelector('.overlay') && this.open?.bottom && !e.target.closest('.monaco-editor')) {
        this.editors.editor()?.focus();
      }
    }, true);
  }

  mainMenu(at) {
    const item = (id, extra = {}) => {
      const a = this.actions.items.get(id);
      return a ? { label: a.label, kbd: a.kbd ? kbd(a.kbd) : '', icon: a.icon, action: () => this.actions.run(id), ...extra } : null;
    };
    const plugin = (label) => this.plugins?.menuIds(label) || [];
    const section = (label, ids) => ({ label, items: [...ids, ...(plugin(label).length ? ['-', ...plugin(label)] : [])].map((id) => (id === '-' ? '-' : item(id))).filter(Boolean).filter((x, i, all) => x !== '-' || (i > 0 && i < all.length - 1 && all[i - 1] !== '-')) });
    menu([
      section('File', ['newFile', 'openProject', 'saveAll', 'closeTab', 'localHistory', '-', 'settings', 'plugins', 'closeProject']),
      section('Edit', ['find', 'replace', 'findInFiles', 'replaceInFiles', 'comment']),
      section('View', ['project', 'structure', 'commitTool', 'gameTool', 'claudeTool', 'runTool', 'debugTool', 'findTool', 'problemsTool', 'terminalTool', 'gitLog', 'hideTools', '-', 'split', 'theme']),
      section('Navigate', ['searchEverywhere', 'gotoClass', 'gotoFile', 'gotoSymbol', 'recentFiles', 'gotoLine', '-', 'declaration', 'implementations', 'fileStructure', 'back', 'forward', 'selectInProject']),
      section('Code', ['reformat', 'comment', 'rebuildIndex']),
      section('Refactor', ['rename', 'usages']),
      section('Run', ['run', 'debug', 'test', 'shot', 'rerun', 'stop', 'runConfigs', 'runAnything', '-', 'debugResume', 'stepOver', 'stepInto', 'stepOut', 'runToCursor', 'toggleBreakpoint', 'exceptionBreakpoints', 'debugTool', 'preview', 'previewTab', '-', 'createInstaller']),
      section('Tools', ['plugins', 'pluginUpdates', 'extensions']),
      section('Git', ['commit', 'push', 'update', 'branches', 'gitLog', 'fileHistory', 'annotate', 'rollbackFile']),
      { label: 'Help', items: [
        { label: 'Keyboard Shortcuts', action: () => this.shortcuts() },
        { label: 'JumPHP Documentation', action: () => this.openUrl('https://jumphp.net/docs') },
        ...(backend.kind === 'native' && this.updates && this.updates.state !== 'off' ? ['-',
          { label: 'Check for Updates…', icon: 'download', action: () => this.checkForUpdates() },
          { label: 'Update Automatically', checked: !!this.updates.auto, action: () => backend.invoke('update.auto', { on: !this.updates.auto }).then((st) => this.updateState(st)) },
          '-'] : []),
        { label: 'About JumPHP IDE', action: () => dialog({ title: 'JumPHP IDE', body: el('p', {}, `JumPHP IDE ${this.updates?.current || ''} — an IDE for PHP games and apps, written in PHP (JumPHP) with Monaco, the editor of VS Code.`) }) },
      ] },
    ].map((s) => (s.items && !s.items.length ? null : s)).filter(Boolean), at);
  }

  projectMenu(at) {
    const items = [{ head: this.project.name }];
    if (this.project.root) items.push({ label: this.project.root, disabled: true });
    for (const m of this.project.modules || []) items.push({ label: `${m.title} (${m.kindLabel})`, icon: { app: 'app', hybrid: 'hybrid', cpc: 'disk', port: 'fork' }[m.kind] || 'game', action: () => this.tree.reveal(m.dir || '', true) });
    if (backend.kind === 'native') items.push('-', { label: 'Open Another Project…', icon: 'folder', action: () => this.switchProject() }, { label: 'Close Project', icon: 'close', action: () => backend.invoke('projects.close').then(() => location.reload()) });
    else if (backend.config.project?.repoUrl) items.push('-', { label: 'Repository on GitHub', icon: 'globe', action: () => this.openUrl(backend.config.project.repoUrl) }, { label: 'All my projects', icon: 'folder', action: () => { location.href = '/ide'; } });
    menu(items, at);
  }

  async switchProject() {
    const state = await backend.invoke('session.state');
    chooser([...(state.recent || []).map((r) => ({ label: r.name, hint: r.path, icon: 'folder', action: () => backend.invoke('projects.open', { path: r.path }).then(() => location.reload()) })),
      '-', { label: 'Welcome screen…', icon: 'star', action: () => backend.invoke('projects.close').then(() => location.reload()) }], { placeholder: 'Open a recent project' });
  }

  /** The status bar shows when this computer's Claude answers the web IDE. */
  /** The IDE's own updates (games/ide/src/SelfUpdate.php): installed in the background, then one click to restart. */
  updateState(st, force = false) {
    const before = this.updates?.state;
    this.updates = st;
    if (!st || (st.state === before && !force)) return;
    const restart = { label: 'Restart Now', action: () => this.restartForUpdate() };
    if (st.state === 'installed') {
      notify(`JumPHP IDE ${st.version} is installed: restart to use it (or it starts next time).${st.notes ? ' ' + st.notes : ''}`, 'success', { title: 'Update', actions: [restart, { label: 'Later', action: () => null }], timeout: 0 });
    } else if (st.state === 'available' && (force || !(st.installable && st.auto))) {
      const actions = st.installable ? [{ label: 'Install', action: () => backend.invoke('update.install').then((s) => this.updateState(s)) }] : [];
      notify(`JumPHP IDE ${st.version} is out (this one: ${st.current}).${st.installable ? '' : ' Run from its sources: update them with git.'}`, 'info', { title: 'Update', actions, timeout: 0 });
    } else if (st.manual && st.state === 'latest') {
      notify(`JumPHP IDE ${st.current} is the newest version.`, 'info', { title: 'Update' });
    } else if (st.manual && st.state === 'error') {
      notify(st.error || 'Cannot check for updates.', 'error', { title: 'Update' });
    }
    if (st.state === 'installing' && !this.updateTask) this.updateTask = this.task(`Downloading JumPHP IDE ${st.version || ''}…`);
    if (st.state !== 'installing' && this.updateTask) { this.updateTask(); this.updateTask = null; }
    if ((st.manual || force) && st.state === 'installing') {
      notify(`Downloading JumPHP IDE ${st.version}…`, 'info', { title: 'Update' });
    }
  }

  /** Help > Check for Updates: the answer comes as an update.state event (or at once: installing, installed). */
  checkForUpdates() {
    backend.invoke('update.check').then((st) => this.updateState(st, st.state !== 'checking'))
      .catch((e) => notify(e.message, 'error', { title: 'Update' }));
  }

  async restartForUpdate() {
    try { await this.editors?.saveAll?.(); } catch { /* the files are saved as one types anyway */ }
    backend.invoke('update.restart').catch((e) => notify(e.message, 'error', { title: 'Update' }));
  }

  bridgeStatus(st) {
    let item = document.getElementById('sbBridge');
    if (!st?.on) { item?.remove(); return; }
    if (!item) {
      item = el('span.sb-item', { id: 'sbBridge', title: 'Claude for jumphp.net: the web IDE uses the Claude of this computer (account menu to turn it off)' });
      $('sbTask').before(item);
    }
    item.textContent = st.state === 'working' ? `✦ Claude for jumphp.net: working on ${st.project}…` : '✦ Claude for jumphp.net';
  }

  async userMenu(at) {
    const user = this.session?.user || backend.config.user;
    // the desktop: this computer's Claude can answer the web IDE (jumphp.net/ide), ClaudeBridge
    const bridge = backend.kind === 'native' && user ? await backend.invoke('bridge.status').catch(() => null) : null;
    menu(user ? [{ head: user.login }, { label: 'My page on jumphp.net', icon: 'globe', action: () => this.openUrl(user.url || 'https://jumphp.net') },
      bridge ? { label: 'Claude for jumphp.net (the web IDE uses this computer\'s Claude)', icon: 'claude', checked: bridge.on,
        action: () => backend.invoke('bridge.enable', { on: !bridge.on }).then((s) => notify(s.on ? 'jumphp.net/ide now uses the Claude of this computer, while the JumPHP IDE is open.' : 'Claude for jumphp.net is off.', 'info', { title: 'Claude' })) } : null,
      backend.kind === 'native' ? { label: 'Sign out', icon: 'logout', action: () => backend.invoke('session.logout').then(() => location.reload()) } : null].filter(Boolean)
      : [{ label: 'Sign in with jumphp.net', icon: 'user', action: () => backend.invoke('projects.close').then(() => location.reload()) }], at);
  }

  findInFiles(options = {}) {
    const selection = this.editors.editor()?.getModel() && this.editors.editor().getModel().getValueInRange(this.editors.editor().getSelection());
    this.find.show({ query: options.query ?? (selection && !selection.includes('\n') ? selection : ''), replace: !!options.replace, include: options.include });
  }

  // Play: the game, the scene of a file, the tests of a test file ----------------------------------------------

  /** The game module a project path belongs to. */
  moduleOf(path) {
    return [...(this.project.modules || [])].sort((a, b) => (b.dir || '').length - (a.dir || '').length)
      .find((m) => !m.dir || path.startsWith(m.dir + '/')) || null;
  }

  /** What Play does on a file: {kind: 'test'|'scene'|'game', label, module, relative, scene} or null. */
  playTarget(path) {
    if (!path || path.startsWith('/') || !path.endsWith('.php')) return null;
    const caps = this.project.capabilities || {};
    const module = this.moduleOf(path);
    if (!module || !['game', 'hybrid', 'cpc', 'port', 'app'].includes(module.kind)) return null;
    const relative = module.dir ? path.slice(module.dir.length + 1) : path;
    const name = basename(path).replace(/\.php$/, '');
    if (/^tests\/.+Test\.php$/.test(relative)) {
      return caps.run || caps.tests ? { kind: 'test', label: `Run '${name}'`, module, relative } : null;
    }
    if (!caps.run && !caps.preview) return null;
    if (/^src\/Scene\/.+\.php$/.test(relative) && module.kind !== 'app') {
      return { kind: 'scene', label: `Play '${name}'`, module, relative, scene: this.classOf(path) };
    }
    return { kind: 'game', label: `Play '${module.title || module.id}'`, module, relative };
  }

  /** The class a PHP file declares (namespace + first class), from its open model or its name. */
  classOf(path) {
    const text = this.editors.files.get(path)?.model?.getValue() || '';
    // not open: App\ + its path under src/ (PSR-4, as every JumPHP game)
    if (!text) return 'App\\' + path.replace(/^.*?\bsrc\//, '').replace(/\.php$/, '').split('/').join('\\');
    const ns = text.match(/^\s*namespace\s+([\w\\]+)\s*;/m)?.[1];
    const cls = text.match(/^\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m)?.[1] || basename(path).replace(/\.php$/, '');
    return ns ? `${ns}\\${cls}` : cls;
  }

  async playFile(path) {
    const target = this.playTarget(path);
    if (!target) { notify('Nothing to play in ' + basename(path), 'info'); return; }
    const caps = this.project.capabilities || {};
    await this.editors.saveAll(true);
    if (target.kind === 'test') {
      if (caps.run) this.runner.start({ id: 'adhoc', kind: 'test', label: basename(path).replace(/\.php$/, ''), command: ['bin/jumphp', 'test', target.module.id, target.relative] });
      else this.runner.remoteTests(target.relative);
      return;
    }
    if (this.game) { this.game.play({ scene: target.kind === 'scene' ? target.scene : null }); return; }
    // the desktop: the game in its own window, at the scene of the file
    const env = target.kind === 'scene' ? { JUMPHP_SCENE: target.scene } : {};
    this.runner.start({ id: 'adhoc', kind: 'run', label: target.label.replace(/^Play /, ''), command: ['bin/jumphp', 'run', target.module.id] }, env);
  }

  typeHierarchy() {
    this.showTool('hierarchy');
    this.hierarchy.update(true);
  }

  async findUsages() {
    const editor = this.editors.editor();
    const model = editor?.getModel();
    if (!model) return;
    const pos = editor.getPosition();
    const path = this.editors.activePath();
    const refs = await backend.invoke('php.references', { path, text: model.getValue(), line: pos.lineNumber, column: pos.column }).catch(() => []);
    if (!refs.length) { this.status('No usages found'); return; }
    this.showUsages(refs, model.getWordAtPosition(pos)?.word);
  }

  showUsages(refs, name) { this.find.usages(refs, name); }

  fileStructure() { fileStructure(this); }

  async publish() {
    if (this.project.needsRepository) { this.createRepository(); return; }
    const done = this.task('Publishing on jumphp.net…');
    try {
      const result = await backend.invoke('playground.publish');
      notify(result.message || 'Published', result.ok === false ? 'error' : 'success', { title: 'jumphp.net', actions: result.url ? [{ label: 'Open its page', action: () => this.openUrl(result.url) }] : [] });
    } catch (e) { notify(e.message, 'error', { title: 'Publish' }); } finally { done(); }
  }

  /** Its own public repository on the user's GitHub account, everything committed and pushed, then a jumphp.net project. */
  createRepository() {
    const module = (this.project.modules || [])[0];
    const name = el('input.input', { value: module?.id || this.project.name || '' });
    dialog({
      title: 'Create its GitHub repository', wide: true,
      body: el('div', {}, el('label.field', {}, el('span', {}, 'Name of the repository (on your GitHub account, public)'), name),
        el('p.pub-faint', {}, 'The IDE creates the repository, commits every file of the project (but what .gitignore leaves out) and pushes it. '
          + 'JumPHP stays as the "jumphp" remote, for its updates only. Then the project is put on jumphp.net (not listed): Publish works.')),
      buttons: [{ label: 'Cancel' }, { label: 'Create and push', primary: true, action: async () => {
        const done = this.task('Creating the GitHub repository…');
        try {
          const r = await backend.invoke('project.createRepository', { name: name.value.trim() });
          notify(r.message || `${r.repository}: pushed${r.playground ? ', and on jumphp.net' : ''}.`, r.message ? 'warning' : 'success',
            { title: 'GitHub', timeout: 0, actions: [{ label: 'Open the repository', action: () => this.openUrl(r.url) }, { label: 'Reload the project', action: () => location.reload() }] });
        } finally { done(); }
      } }],
    });
  }

  afterPush() {
    if (backend.kind === 'native' && this.project.playground) {
      notify('Pushed. jumphp.net publishes the new version in a moment.', 'info', { title: 'jumphp.net', actions: [{ label: 'Check now', action: () => this.publish() }] });
    }
  }

  afterCommit(result) { if (result.pushed) this.afterPush(); }

  openUrl(url) {
    if (backend.kind === 'native') backend.invoke('native.openUrl', { url }).catch(() => window.open(url, '_blank'));
    else window.open(url, '_blank', 'noopener');
  }

  settings() {
    const field = (label, control) => el('label.field', {}, el('span', {}, label), control);
    const theme = el('select.input', {}, el('option', { value: 'dark' }, 'Dark'), el('option', { value: 'light' }, 'Light'));
    theme.value = prefs.get('theme', 'dark');
    const size = el('input.input', { type: 'number', min: 9, max: 28, step: 0.5, value: prefs.get('fontSize', 13.5) });
    const minimap = el('input', { type: 'checkbox', checked: prefs.get('minimap', false) });
    const autosave = el('input', { type: 'checkbox', checked: prefs.get('autosave', true) });
    const previewTabs = el('input', { type: 'checkbox', checked: prefs.get('previewTabs', true) });
    const autoscroll = el('input', { type: 'checkbox', checked: prefs.get('autoscroll', false) });
    const sideBySide = el('input', { type: 'checkbox', checked: prefs.get('diffSideBySide', true) });
    dialog({ title: 'Settings', body: el('div', {}, field('Theme', theme), field('Editor font size', size),
      el('label.checkbox', {}, minimap, 'Show the minimap'), el('br'), el('label.checkbox', {}, autosave, 'Save files automatically'), el('br'),
      el('label.checkbox', {}, previewTabs, 'Open files in a preview tab on single click'), el('br'), el('label.checkbox', {}, autoscroll, 'Always select the opened file in the Project view'), el('br'),
      el('label.checkbox', {}, sideBySide, 'Diffs side by side')),
    buttons: [{ label: 'Cancel' }, { label: 'OK', primary: true, action: () => {
      prefs.set('fontSize', +size.value); prefs.set('minimap', minimap.checked); prefs.set('autosave', autosave.checked);
      prefs.set('previewTabs', previewTabs.checked); prefs.set('autoscroll', autoscroll.checked); prefs.set('diffSideBySide', sideBySide.checked);
      if (theme.value !== prefs.get('theme', 'dark')) this.toggleTheme();
      for (const g of this.editors.groups) g.editor.updateOptions({ fontSize: +size.value, minimap: { enabled: minimap.checked } });
    } }] });
  }

  toggleTheme() {
    const next = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
    document.documentElement.dataset.theme = next;
    prefs.set('theme', next);
    monaco.editor.setTheme(next === 'light' ? 'jumphp-light' : 'jumphp-dark');
  }

  shortcuts() {
    const rows = this.actions.list().filter((a) => a.kbd).map((a) => [el('span', {}, a.label), el('kbd', {}, kbd(a.kbd))]).flat();
    dialog({ title: 'Keyboard Shortcuts', wide: true, body: el('div.empty-editor', { style: { height: 'auto' } }, el('div.hints', {}, ...rows)) });
  }

  showNotifications() {
    const list = notificationHistory();
    dialog({ title: 'Notifications', body: el('div', {}, list.length ? list.map((n) => el('div', { style: { padding: '6px 0', borderBottom: '1px solid var(--border-2)' } },
      el('b', {}, n.title || n.kind), ' ', el('span', { style: { color: 'var(--text-3)', fontSize: '12px' } }, n.time.toLocaleTimeString()), el('div', { style: { color: 'var(--text-2)', whiteSpace: 'pre-wrap' } }, n.text)))
      : el('p', {}, 'No notifications.')) });
  }

  // open tabs kept between sessions ------------------------------------------------------------------------------

  saveTabs() {
    if (!this.editors) return;
    prefs.set('tabs:' + this.projectKey, this.editors.groups.map((g) => ({ tabs: g.tabs.filter((t) => t.kind !== 'diff').map((t) => t.path), active: this.editors.activeTab(g)?.path })));
  }

  async restoreTabs() {
    const saved = prefs.get('tabs:' + this.projectKey, null);
    if (!saved || !saved.length) {
      // a fresh project: its brief or README, like a welcome page
      const first = ['BRIEF.md', ...(this.project.modules || []).map((m) => (m.dir ? m.dir + '/' : '') + 'BRIEF.md'), 'README.md'].find((p) => this.tree.files.includes(p));
      if (first) this.editors.open(first);
      return;
    }
    for (const [i, g] of saved.entries()) {
      const group = i === 0 ? this.editors.groups[0] : this.editors.addGroup();
      for (const path of g.tabs) if (this.tree.files.includes(path)) await this.editors.open(path, { group });
      if (g.active && this.tree.files.includes(g.active)) await this.editors.open(g.active, { group });
    }
    setInterval(() => this.saveTabs(), 10000);
  }
}

const app = new App();
app.start();
