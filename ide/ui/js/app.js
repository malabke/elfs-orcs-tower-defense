// JumPHP IDE: the workbench. Loads Monaco, opens the project (desktop: the welcome screen first; web: the project of
// the page), lays out the tool windows (Project, Commit, Structure / Run, Find, Problems, Git), and binds PhpStorm's
// keyboard shortcuts to the IDE's actions.
import { backend } from './backend.js';
import { el, clear, icon, hydrateIcons, menu, chooser, dialog, notify, notificationHistory, splitter, prefs, debounce, kbd, basename, timeAgo } from './ui.js';
import { Editors, loadMonaco, monaco } from './editor.js';
import { ProjectTree } from './tree.js';
import { searchEverywhere, recentFiles, fileStructure, FindPanel } from './search.js';
import { Git } from './git.js';
import { Runner, Problems, Structure } from './run.js';
import { Welcome } from './welcome.js';
import { GamePanel } from './game.js';

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
    document.documentElement.dataset.theme = prefs.get('theme', 'dark');
    try {
      await loadMonaco();
    } catch (e) {
      document.body.append(el('div.placeholder', {}, 'The editor could not load: ' + e.message));
      return;
    }
    this.bindKeys();
    this.bindHeader();
    backend.on('index.progress', (p) => this.indexProgress(p));
    backend.on('files.changed', debounce(() => { this.tree?.refresh(); this.git?.refresh(); this.editors?.reloadFromDisk(); }, 300));
    backend.on('project.closed', () => location.reload());
    if (backend.kind === 'native') {
      const state = await backend.invoke('session.state');
      this.session = state;
      if (state.project) await this.projectOpened(state.project, false);
      else { this.welcome = new Welcome(this, $('welcome')); await this.welcome.show(); }
    } else {
      await this.projectOpened(await backend.invoke('project.info'), false);
    }
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
    this.problems = new Problems(this);
    this.structure = new Structure(this);
    this.find = new FindPanel(this);
    if (this.project.capabilities?.preview) this.game = new GamePanel(this);
    this.console = { append: (source, text) => { if (this.gitConsole) this.gitConsole.textContent += text; } };
    this.registerTools();
    this.registerActions();
    this.layoutTools();
    await this.tree.refresh();
    await this.git.refresh();
    this.updateHeader();
    this.updateRunWidget();
    this.restoreTabs();
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
    if (caps.run || caps.tests) T('run', 'bottom', 'Run', 'play', this.runner, 'Alt+4');
    T('find', 'bottom', 'Find', 'find', this.find, 'Alt+3');
    T('problems', 'bottom', 'Problems', 'problems', this.problems, 'Alt+6');
    if (this.game) T('game', 'right', 'Game', 'game', this.game, 'Alt+5');
    if (caps.log) T('git', 'bottom', 'Git', 'log', { title: () => 'Git', node: this.git.logNode, actions: () => [el('button', { title: 'Refresh', onclick: () => this.git.showLog() }, icon('refresh'))] }, 'Alt+9');
  }

  layoutTools() {
    const left = $('leftStripe');
    const right = $('rightStripe');
    clear(left);
    clear(right);
    const button = (tool) => {
      const b = el('button', { title: `${tool.label} (${kbd(tool.key)})`, dataset: { tool: tool.id }, onclick: () => this.toggleTool(tool.id) }, icon(tool.icon));
      tool.button = b;
      return b;
    };
    for (const t of Object.values(this.tools).filter((t) => t.side === 'left')) left.append(button(t));
    left.append(el('div.sep'));
    for (const t of Object.values(this.tools).filter((t) => t.side === 'bottom')) left.append(button(t));
    for (const t of Object.values(this.tools).filter((t) => t.side === 'right')) right.append(button(t));
    right.append(el('button', { title: 'Notifications', onclick: () => this.showNotifications() }, icon('bell')));
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
    if (id === 'git' && !this.git.logNode.firstChild) this.git.showLog();
    if (id === 'problems') this.problems.render();
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
    $('projectWidget').addEventListener('click', (e) => this.projectMenu(e.currentTarget));
    $('branchWidget').addEventListener('click', (e) => this.git?.branchPopup(e.currentTarget));
    $('sbBranch').addEventListener('click', (e) => this.git?.branchPopup(e.currentTarget));
    $('runConfig').addEventListener('click', (e) => this.runner?.choose(e.currentTarget));
    $('runBtn').addEventListener('click', () => this.actions.run('run'));
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
    $('stopBtn').hidden = !caps.run;
    $('previewBtn').hidden = !caps.preview;
    $('publishBtn').hidden = !(backend.kind === 'native' && this.project.playground);
    const user = this.session?.user || backend.config.user;
    const widget = $('userWidget');
    clear(widget);
    if (user?.avatar) widget.style.backgroundImage = `url("${user.avatar}")`;
    else widget.append(icon('user'));
  }

  onGitStatus(status) {
    $('branchName').textContent = status.branch || 'detached';
    $('sbBranch').textContent = this.project.capabilities.gitMode === 'drafts' ? `${status.files.length} draft(s)` : (status.branch ? '⎇ ' + status.branch : '');
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
    if (tab && prefs.get('autoscroll', false)) this.tree.reveal(path);
  }

  status(text) {
    const node = $('sbTask');
    node.textContent = text;
    clearTimeout(this.statusTimer);
    this.statusTimer = setTimeout(() => { if (!this.tasks.size) node.textContent = ''; }, 4000);
  }

  /** A background task in the status bar; returns done(). */
  task(text) {
    const entry = { text };
    this.tasks.add(entry);
    const draw = () => {
      const node = clear($('sbTask'));
      const last = [...this.tasks].pop();
      if (last) node.append(el('span.spinner'), last.text);
    };
    draw();
    return () => { this.tasks.delete(entry); draw(); };
  }

  indexProgress(p) {
    const node = clear($('sbIndex'));
    if (p.complete) return;
    node.append(el('span', {}, 'Indexing…'), el('span.progress', {}, el('i', { style: { width: (p.total ? (100 * p.done) / p.total : 0) + '%' } })));
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
    a.add('replaceInFiles', 'Replace in Files…', () => this.findInFiles({ replace: true }), { kbd: 'Ctrl+Shift+R' });
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
    a.add('newFile', 'New File…', () => this.tree.newFile(this.tree.targetDir()), { kbd: 'Alt+Insert', icon: 'plus' });
    a.add('project', 'Project Tool Window', () => this.toggleTool('project'), { kbd: 'Alt+1', icon: 'folder' });
    a.add('structure', 'Structure Tool Window', () => this.toggleTool('structure'), { kbd: 'Alt+7', icon: 'structure' });
    a.add('problemsTool', 'Problems Tool Window', () => this.toggleTool('problems'), { kbd: 'Alt+6', icon: 'problems' });
    a.add('findTool', 'Find Tool Window', () => this.toggleTool('find'), { kbd: 'Alt+3' });
    a.add('hideTools', 'Hide All Tool Windows', () => { this.closeSide('left'); this.closeSide('bottom'); }, { kbd: 'Ctrl+Shift+F12' });
    a.add('selectInProject', 'Select Opened File in Project', () => this.tree.reveal(this.editors.activePath()), { kbd: 'Alt+F1' });
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
    if (caps.blame) a.add('annotate', 'Annotate with Git Blame', () => this.git.toggleBlame(), { icon: 'history' });
    if (caps.run) {
      a.add('run', 'Run', () => this.runner.runSelected(), { kbd: 'Shift+F10', icon: 'play' });
      a.add('test', 'Run Tests', () => this.runner.runSelected('test'), { kbd: 'Ctrl+Shift+F10', icon: 'test' });
      a.add('shot', 'Take a Screenshot', () => this.runner.runSelected('shot'), { icon: 'camera' });
      a.add('rerun', 'Rerun', () => { const r = this.runner.current(); if (r) this.runner.start(r.config); }, { kbd: 'Ctrl+F5', icon: 'refresh' });
      a.add('stop', 'Stop', () => this.runner.stop(), { kbd: 'Ctrl+F2', icon: 'stop' });
      a.add('runTool', 'Run Tool Window', () => this.toggleTool('run'), { kbd: 'Alt+4', icon: 'play' });
      a.add('runConfigs', 'Run Configurations…', () => this.runner.choose($('runConfig')), { kbd: 'Alt+Shift+F10' });
    } else if (caps.tests) {
      a.add('test', 'Run Tests on jumphp.net', () => this.runner.remoteTests(), { kbd: 'Ctrl+Shift+F10', icon: 'test' });
      a.add('runTool', 'Run Tool Window', () => this.toggleTool('run'), { kbd: 'Alt+4', icon: 'play' });
    }
    if (caps.preview) {
      a.add('preview', 'Play the Game', () => this.game.play(), { kbd: 'Shift+F10', icon: 'play' });
      a.add('gameTool', 'Game', () => this.toggleTool('game'), { kbd: 'Alt+5', icon: 'game' });
      a.add('previewTab', 'Play in a New Tab', () => this.runner.preview(), { icon: 'globe' });
    }
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
    const map = {
      'ctrl+shift+n': 'gotoFile', 'ctrl+n': 'gotoClass', 'ctrl+alt+shift+n': 'gotoSymbol', 'ctrl+shift+a': 'findAction', 'ctrl+e': 'recentFiles',
      'ctrl+shift+f': 'findInFiles', 'ctrl+shift+r': 'replaceInFiles', 'ctrl+k': 'commit', 'ctrl+shift+k': 'push', 'ctrl+t': 'update',
      'shift+f10': 'run', 'ctrl+shift+f10': 'test', 'ctrl+f5': 'rerun', 'ctrl+f2': 'stop', 'alt+1': 'project', 'alt+0': 'commitTool',
      'alt+4': 'runTool', 'alt+6': 'problemsTool', 'alt+7': 'structure', 'alt+9': 'gitLog', 'alt+3': 'findTool', 'ctrl+alt+s': 'settings',
      'ctrl+shift+f12': 'hideTools', 'alt+f1': 'selectInProject', 'ctrl+s': 'saveAll', 'alt+shift+f10': 'runConfigs', 'ctrl+alt+z': 'rollbackFile',
      'alt+insert': 'newFile', 'ctrl+shift+`': 'branches',
    };
    let lastShift = 0;
    document.addEventListener('keydown', (e) => {
      if (!this.project) return;
      const key = [e.ctrlKey || e.metaKey ? 'ctrl' : '', e.altKey ? 'alt' : '', e.shiftKey ? 'shift' : '', e.key.length === 1 ? e.key.toLowerCase() : e.key.toLowerCase()].filter(Boolean).join('+');
      if (e.key === 'Shift' && !e.repeat) {
        const now = Date.now();
        if (now - lastShift < 350) { lastShift = 0; searchEverywhere(this); return; }
        lastShift = now;
        return;
      }
      if (e.key !== 'Shift') lastShift = 0;
      const id = map[key];
      if (id && this.actions.items.has(id)) {
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
    const section = (label, ids) => ({ label, items: ids.map((id) => (id === '-' ? '-' : item(id))).filter(Boolean) });
    menu([
      section('File', ['newFile', 'openProject', 'saveAll', 'closeTab', '-', 'settings', 'closeProject']),
      section('Edit', ['find', 'replace', 'findInFiles', 'replaceInFiles', 'comment']),
      section('View', ['project', 'structure', 'commitTool', 'gameTool', 'runTool', 'findTool', 'problemsTool', 'gitLog', 'hideTools', '-', 'split', 'theme']),
      section('Navigate', ['searchEverywhere', 'gotoClass', 'gotoFile', 'gotoSymbol', 'recentFiles', 'gotoLine', '-', 'declaration', 'implementations', 'fileStructure', 'back', 'forward', 'selectInProject']),
      section('Code', ['reformat', 'comment', 'rebuildIndex']),
      section('Refactor', ['rename', 'usages']),
      section('Run', ['run', 'test', 'shot', 'rerun', 'stop', 'runConfigs', 'preview', 'previewTab']),
      section('Git', ['commit', 'push', 'update', 'branches', 'gitLog', 'fileHistory', 'annotate', 'rollbackFile']),
      { label: 'Help', items: [
        { label: 'Keyboard Shortcuts', action: () => this.shortcuts() },
        { label: 'JumPHP Documentation', action: () => this.openUrl('https://jumphp.net/docs') },
        { label: 'About JumPHP IDE', action: () => dialog({ title: 'JumPHP IDE', body: el('p', {}, 'An IDE for PHP games and apps, written in PHP (JumPHP) with Monaco, the editor of VS Code.') }) },
      ] },
    ].map((s) => (s.items && !s.items.length ? null : s)).filter(Boolean), at);
  }

  projectMenu(at) {
    const items = [{ head: this.project.name }];
    if (this.project.root) items.push({ label: this.project.root, disabled: true });
    for (const m of this.project.modules || []) items.push({ label: `${m.title} (${m.kindLabel})`, icon: { app: 'app', hybrid: 'hybrid', cpc: 'disk', port: 'fork' }[m.kind] || 'game', action: () => this.tree.reveal(m.dir || '') });
    if (backend.kind === 'native') items.push('-', { label: 'Open Another Project…', icon: 'folder', action: () => this.switchProject() }, { label: 'Close Project', icon: 'close', action: () => backend.invoke('projects.close').then(() => location.reload()) });
    else if (backend.config.project?.repoUrl) items.push('-', { label: 'Repository on GitHub', icon: 'globe', action: () => this.openUrl(backend.config.project.repoUrl) }, { label: 'All my projects', icon: 'folder', action: () => { location.href = '/ide'; } });
    menu(items, at);
  }

  async switchProject() {
    const state = await backend.invoke('session.state');
    chooser([...(state.recent || []).map((r) => ({ label: r.name, hint: r.path, icon: 'folder', action: () => backend.invoke('projects.open', { path: r.path }).then(() => location.reload()) })),
      '-', { label: 'Welcome screen…', icon: 'star', action: () => backend.invoke('projects.close').then(() => location.reload()) }], { placeholder: 'Open a recent project' });
  }

  userMenu(at) {
    const user = this.session?.user || backend.config.user;
    menu(user ? [{ head: user.login }, { label: 'My page on jumphp.net', icon: 'globe', action: () => this.openUrl(user.url || 'https://jumphp.net') },
      backend.kind === 'native' ? { label: 'Sign out', icon: 'logout', action: () => backend.invoke('session.logout').then(() => location.reload()) } : null].filter(Boolean)
      : [{ label: 'Sign in with jumphp.net', icon: 'user', action: () => backend.invoke('projects.close').then(() => location.reload()) }], at);
  }

  findInFiles(options = {}) {
    const selection = this.editors.editor()?.getModel() && this.editors.editor().getModel().getValueInRange(this.editors.editor().getSelection());
    this.find.show({ query: options.query ?? (selection && !selection.includes('\n') ? selection : ''), replace: !!options.replace, include: options.include });
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
    const done = this.task('Publishing on jumphp.net…');
    try {
      const result = await backend.invoke('playground.publish');
      notify(result.message || 'Published', result.ok === false ? 'error' : 'success', { title: 'jumphp.net', actions: result.url ? [{ label: 'Open the game', action: () => this.openUrl(result.url) }] : [] });
    } catch (e) { notify(e.message, 'error', { title: 'Publish' }); } finally { done(); }
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
