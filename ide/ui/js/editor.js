// The editor area: Monaco, editor groups (split) with tabs, text / image / diff / designer views, saving, and the
// PHP language features answered by the IDE's PHP side (completion, definition, usages, rename, hover...).
import { backend } from './backend.js';
import { el, clear, icon, colorIcon, basename, dirname, extension, fileIcon, notify, debounce, prefs, menu, kbd } from './ui.js';

export let monaco = null;

export function loadMonaco() {
  return new Promise((resolve, reject) => {
    window.require.config({ paths: { vs: new URL('vendor/monaco/vs', document.baseURI).href } });
    window.require(['vs/editor/editor.main'], () => { monaco = window.monaco; resolve(monaco); }, reject);
  });
}

// project path <-> Monaco URI (library files outside the project: absolute paths under /@lib)
export const uriOf = (path) => monaco.Uri.from({ scheme: 'file', path: path.startsWith('/') ? '/@lib' + path : '/' + path });
export const pathOf = (uri) => (uri.path.startsWith('/@lib/') ? uri.path.slice(5) : uri.path.slice(1));

const LANGUAGES = { php: 'php', phtml: 'php', js: 'javascript', mjs: 'javascript', ts: 'typescript', json: 'json', tmj: 'json', tsj: 'json',
  webmanifest: 'json', lock: 'json', html: 'html', htm: 'html', twig: 'html', css: 'css', scss: 'scss', yaml: 'yaml', yml: 'yaml', neon: 'yaml',
  md: 'markdown', xml: 'xml', svg: 'xml', tmx: 'xml', tsx: 'xml', sh: 'shell', ini: 'ini', env: 'ini', sql: 'sql', py: 'python', rs: 'rust',
  c: 'c', h: 'c', glsl: 'cpp', vert: 'cpp', frag: 'cpp', dockerfile: 'dockerfile', toml: 'ini' };

export function languageOf(path) {
  const base = basename(path);
  if (base === 'Dockerfile') return 'dockerfile';
  if (base === 'jumphp' || base.endsWith('.sh')) return 'shell';
  return LANGUAGES[extension(path)] || 'plaintext';
}

function defineThemes() {
  monaco.editor.defineTheme('jumphp-dark', {
    base: 'vs-dark', inherit: true,
    rules: [
      { token: 'keyword', foreground: 'CF8E6D' }, { token: 'keyword.php', foreground: 'CF8E6D' },
      { token: 'string', foreground: '6AAB73' }, { token: 'string.php', foreground: '6AAB73' },
      { token: 'number', foreground: '2AACB8' }, { token: 'comment', foreground: '7A7E85', fontStyle: 'italic' },
      { token: 'variable', foreground: 'C77DBB' }, { token: 'variable.php', foreground: 'C77DBB' },
      { token: 'type', foreground: 'BCBEC4' }, { token: 'delimiter', foreground: 'BCBEC4' }, { token: 'identifier', foreground: 'BCBEC4' },
      { token: 'metatag.php', foreground: 'CF8E6D' }, { token: 'tag', foreground: 'D5B778' }, { token: 'attribute.name', foreground: 'BABABA' },
      { token: 'attribute.value', foreground: '6AAB73' }, { token: 'key', foreground: 'C77DBB' }, { token: 'string.key.json', foreground: 'C77DBB' },
    ],
    colors: {
      'editor.background': '#1E1F22', 'editor.foreground': '#BCBEC4', 'editorLineNumber.foreground': '#4B5059',
      'editorLineNumber.activeForeground': '#A1A3AB', 'editor.lineHighlightBackground': '#26282E', 'editor.selectionBackground': '#214283',
      'editorCursor.foreground': '#CED0D6', 'editorIndentGuide.background1': '#2B2D30', 'editorIndentGuide.activeBackground1': '#393B40',
      'editorWhitespace.foreground': '#393B40', 'editorGutter.background': '#1E1F22', 'editorWidget.background': '#2B2D30',
      'editorSuggestWidget.background': '#2B2D30', 'editorSuggestWidget.selectedBackground': '#2E436E', 'editorHoverWidget.background': '#2B2D30',
      'editorBracketMatch.background': '#43454A', 'editorBracketMatch.border': '#00000000', 'minimap.background': '#1E1F22',
      'scrollbarSlider.background': '#4E515766', 'diffEditor.insertedTextBackground': '#3D6B4440', 'diffEditor.removedTextBackground': '#7F3B3B40',
      'diffEditor.insertedLineBackground': '#2A3A2C80', 'diffEditor.removedLineBackground': '#3F2A2A80',
    },
  });
  monaco.editor.defineTheme('jumphp-light', { base: 'vs', inherit: true, rules: [], colors: { 'editor.background': '#FFFFFF' } });
}

const KIND = () => ({ method: monaco.languages.CompletionItemKind.Method, function: monaco.languages.CompletionItemKind.Function,
  property: monaco.languages.CompletionItemKind.Field, constant: monaco.languages.CompletionItemKind.Constant,
  enumMember: monaco.languages.CompletionItemKind.EnumMember, class: monaco.languages.CompletionItemKind.Class,
  interface: monaco.languages.CompletionItemKind.Interface, trait: monaco.languages.CompletionItemKind.Module,
  enum: monaco.languages.CompletionItemKind.Enum, variable: monaco.languages.CompletionItemKind.Variable,
  keyword: monaco.languages.CompletionItemKind.Keyword, field: monaco.languages.CompletionItemKind.Field, text: monaco.languages.CompletionItemKind.Text,
  value: monaco.languages.CompletionItemKind.Value });

const SYMBOL = () => ({ class: monaco.languages.SymbolKind.Class, interface: monaco.languages.SymbolKind.Interface,
  trait: monaco.languages.SymbolKind.Module, enum: monaco.languages.SymbolKind.Enum, method: monaco.languages.SymbolKind.Method,
  function: monaco.languages.SymbolKind.Function, property: monaco.languages.SymbolKind.Property, constant: monaco.languages.SymbolKind.Constant,
  enumMember: monaco.languages.SymbolKind.EnumMember });

export class Editors {
  constructor(root, app) {
    this.root = root;
    this.app = app;
    this.files = new Map();          // path -> {model, saved (version id), readOnly, kind, data}
    this.groups = [];
    this.focused = null;
    this.recent = prefs.get('recent:' + (app.projectKey || ''), []);
    this.navigation = [];            // back / forward positions
    this.navIndex = -1;
    this.autosave = debounce(() => this.saveAll(true), 1500);
    this.listeners = { active: new Set(), dirty: new Set(), saved: new Set(), cursor: new Set() };
  }

  on(event, fn) { this.listeners[event].add(fn); return () => this.listeners[event].delete(fn); }
  emit(event, ...args) { for (const fn of this.listeners[event]) fn(...args); }

  async init() {
    defineThemes();
    monaco.editor.setTheme(document.documentElement.dataset.theme === 'light' ? 'jumphp-light' : 'jumphp-dark');
    this.registerPhp();
    this.registerOpener();
    this.addGroup();
    this.renderEmpty();
  }

  // groups ---------------------------------------------------------------------------------------------------

  addGroup() {
    const group = { tabs: [], active: null, node: el('div.group'), tabsNode: el('div.tabs'), host: el('div.editor-host'), crumbs: el('div.breadcrumbs') };
    group.node.append(group.tabsNode, group.host, group.crumbs);
    group.node.addEventListener('mousedown', () => this.focus(group), true);
    group.editor = monaco.editor.create(el('div.view'), this.editorOptions());
    group.textView = group.editor.getContainerDomNode();
    group.editor.onDidChangeCursorPosition((e) => { if (this.focused === group) this.emit('cursor', e.position, group); this.updateCrumbs(group); });
    group.editor.onDidFocusEditorText(() => this.focus(group));
    this.addEditorActions(group.editor);
    group.editor.onContextMenu((e) => this.editorMenu(group, e));
    this.groups.push(group);
    this.root.append(group.node);
    this.focus(group);
    this.layout();
    return group;
  }

  editorOptions() {
    return {
      automaticLayout: true, fontFamily: getComputedStyle(document.documentElement).getPropertyValue('--mono'),
      fontSize: prefs.get('fontSize', 13.5), lineHeight: 1.55, minimap: { enabled: prefs.get('minimap', false) }, scrollBeyondLastLine: false,
      renderLineHighlight: 'all', smoothScrolling: true, cursorBlinking: 'smooth', fontLigatures: true, bracketPairColorization: { enabled: true },
      guides: { bracketPairs: false, indentation: true }, stickyScroll: { enabled: true }, tabSize: 4, insertSpaces: true,
      suggest: { showStatusBar: true, preview: true, localityBonus: true }, inlineSuggest: { enabled: true }, quickSuggestions: { other: true, comments: false, strings: false },
      wordBasedSuggestions: 'currentDocument', parameterHints: { enabled: true }, fixedOverflowWidgets: true, mouseWheelZoom: true,
      definitionLinkOpensInPeek: false, gotoLocation: { multiple: 'peek' }, occurrencesHighlight: 'singleFile', renderWhitespace: 'selection',
      folding: true, showFoldingControls: 'mouseover', 'semanticHighlighting.enabled': false, linkedEditing: true, padding: { top: 6 },
      contextmenu: false, // the IDE's own menu (Play, PhpStorm's entries): editorMenu()
    };
  }

  focus(group) {
    if (this.focused === group) return;
    this.focused = group;
    for (const g of this.groups) g.node.classList.toggle('focused', g === group);
    this.emit('active', this.activeTab());
  }

  split() {
    if (this.groups.length >= 2) return this.focus(this.groups.find((g) => g !== this.focused));
    const tab = this.activeTab();
    const group = this.addGroup();
    if (tab) this.open(tab.path, { group });
  }

  closeGroup(group) {
    if (this.groups.length <= 1) return;
    group.editor.dispose();
    group.diff?.dispose();
    group.node.remove();
    this.groups = this.groups.filter((g) => g !== group);
    this.focus(this.groups[0]);
    this.layout();
  }

  layout() { for (const g of this.groups) g.editor.layout(); }

  activeTab(group = this.focused) { return group?.tabs.find((t) => t.id === group.active) || null; }
  activePath() { return this.activeTab()?.path || null; }
  editor(group = this.focused) { return group?.editor; }

  // opening --------------------------------------------------------------------------------------------------

  /**
   * Opens a file in the focused group (or reveals it). options: {line, col, endLine, endCol, group, preview, kind,
   * focus (false: the keyboard stays where it is, e.g. in the Claude window while Claude opens files)}
   */
  async open(path, options = {}) {
    const group = options.group || this.focused;
    this.noFocus = options.focus === false;
    this.pushNavigation();
    let tab = group.tabs.find((t) => t.path === path && (t.kind === (options.kind || t.kind)) && t.kind !== 'diff');
    if (!tab) {
      let file;
      try { file = await this.load(path); } catch (e) { notify(e.message, 'error', { title: 'Cannot open ' + basename(path) }); return null; }
      tab = { id: 'tab' + Math.random().toString(36).slice(2), path, kind: file.kind, preview: !!options.preview };
      if (file.kind === 'text' && languageOf(path) === 'html') tab.mode = prefs.get('htmlMode', 'code');
      if (file.kind === 'text' && /\.form\.ya?ml$/.test(path)) tab.mode = prefs.get('formMode', 'design');
      const replace = options.preview && group.tabs.find((t) => t.preview && !this.isDirty(t.path));
      if (replace) this.closeTab(group, replace, true);
      group.tabs.push(tab);
    } else if (!options.preview) {
      tab.preview = false;
    }
    this.activate(group, tab);
    if (options.line && tab.kind === 'text') this.reveal(group, options);
    this.noFocus = false;
    this.addRecent(path);
    return tab;
  }

  /** Loads a file once into a model (or what shows it). */
  async load(path) {
    if (this.files.has(path)) return this.files.get(path);
    const data = await backend.invoke('file.read', { path });
    let file;
    if (data.text) {
      const model = monaco.editor.getModel(uriOf(path)) || monaco.editor.createModel(data.content, languageOf(path), uriOf(path));
      if (model.getValue() !== data.content) model.setValue(data.content);
      model.updateOptions({ tabSize: ['yaml', 'json', 'javascript', 'html', 'css'].includes(languageOf(path)) && !/^\t/m.test(data.content) ? detectIndent(data.content) : 4 });
      file = { kind: 'text', model, saved: model.getAlternativeVersionId(), readOnly: !!data.readOnly, mtime: data.mtime };
      model.onDidChangeContent(() => this.changed(path));
      if (languageOf(path) === 'php') this.app.diagnose?.(path);
    } else {
      file = { kind: data.image ? 'image' : data.audio ? 'audio' : 'binary', data };
    }
    this.files.set(path, file);
    return file;
  }

  activate(group, tab) {
    group.active = tab.id;
    this.focus(group);
    this.renderTabs(group);
    this.renderView(group, tab);
    this.emit('active', tab);
  }

  renderView(group, tab) {
    const host = group.host;
    for (const child of [...host.children]) if (child !== group.textView) child.remove();
    group.textView.style.display = 'none';
    group.designer?.destroy?.();
    group.designer = null;
    if (!tab) return this.renderEmpty(group);
    this.updateCrumbs(group);
    const file = this.files.get(tab.path);
    if (tab.kind === 'diff') return this.renderDiff(group, tab);
    if (tab.kind === 'text') {
      if (!group.textView.parentNode) host.append(group.textView);
      if (languageOf(tab.path) === 'html' && file) return this.renderHtml(group, tab, file);
      if (/\.form\.ya?ml$/.test(tab.path) && file) return this.renderHtml(group, tab, file, 'form');
      group.textView.style.display = '';
      this.showModel(group, tab, file);
      return;
    }
    if (tab.kind === 'image') {
      host.append(el('div.view.image-view', {}, el('img', { src: file.data.image, alt: tab.path }),
        el('div', {}, `${basename(tab.path)} · ${(file.data.size / 1024).toFixed(1)} KB`)));
      return;
    }
    if (tab.kind === 'audio') {
      host.append(el('div.view.binary-view', {}, icon('play'), el('audio', { src: file.data.audio, controls: true }), basename(tab.path)));
      return;
    }
    host.append(el('div.view.binary-view', {}, icon('file'), `${basename(tab.path)} is a binary file (${file.data.size} bytes)`));
  }

  showModel(group, tab, file) {
    const editor = group.editor;
    if (editor.getModel() !== file.model) {
      const previous = this.activeTabModelState(group);
      if (previous) previous.tab.viewState = previous.state;
      editor.setModel(file.model);
      if (tab.viewState) editor.restoreViewState(tab.viewState);
    }
    editor.updateOptions({ readOnly: file.readOnly });
    group.modelTab = tab;
    const focus = !this.noFocus;
    requestAnimationFrame(() => { editor.layout(); if (focus) editor.focus(); });
    this.updateCrumbs(group);
  }

  activeTabModelState(group) {
    return group.modelTab ? { tab: group.modelTab, state: group.editor.saveViewState() } : null;
  }

  /** .html files (and forms of graphic apps, *.form.yaml): Code, Design (visual editor) or both side by side. */
  async renderHtml(group, tab, file, kind = 'html') {
    const mode = tab.mode || (kind === 'form' ? 'design' : 'code');
    const host = group.host;
    const switcher = el('div.mode-switch', {}, ['code', 'split', 'design'].map((m) => el(`button${m === mode ? '.on' : ''}`, {
      onclick: () => { tab.mode = m; prefs.set(kind === 'form' ? 'formMode' : 'htmlMode', m); this.renderView(group, tab); },
    }, { code: 'Code', split: 'Split', design: 'Design' }[m])));
    host.append(switcher);
    if (mode === 'code') {
      group.textView.style.display = '';
      group.textView.style.width = '';
      this.showModel(group, tab, file);
      return;
    }
    const designHost = el('div.view');
    if (mode === 'split') {
      group.textView.style.display = '';
      group.textView.style.right = '50%';
      group.textView.style.width = '50%';
      designHost.style.left = '50%';
      designHost.style.borderLeft = '1px solid var(--border-2)';
      this.showModel(group, tab, file);
    }
    host.append(designHost);
    if (kind === 'form') return this.renderFormDesigner(group, tab, file, designHost);
    try {
      const { Designer } = await import('./designer.js');
      let syncing = false;
      const designer = new Designer(designHost, {
        path: tab.path,
        html: file.model.getValue(),
        readFile: (p) => backend.invoke('file.read', { path: p }),
        writeFile: async (p, content) => {
          const open = this.files.get(p);
          if (open?.model) open.model.setValue(content);
          else await backend.invoke('file.write', { path: p, content });
        },
        onChange: (html) => {
          syncing = true;
          file.model.pushEditOperations([], [{ range: file.model.getFullModelRange(), text: html }], () => null);
          syncing = false;
        },
      });
      group.designer = designer;
      const sub = file.model.onDidChangeContent(debounce(() => { if (!syncing && group.designer === designer) designer.setHtml(file.model.getValue()); }, 600));
      const destroy = designer.destroy?.bind(designer);
      designer.destroy = () => { sub.dispose(); destroy && destroy(); group.textView.style.right = ''; group.textView.style.width = ''; };
    } catch (e) {
      designHost.append(el('div.placeholder', {}, 'The visual editor could not start: ' + e.message));
    }
  }

  /** The visual editor of a form (graphic PHP apps, JumPHP\Gui): the YAML in the model, the code behind in the form's class. */
  async renderFormDesigner(group, tab, file, designHost) {
    try {
      const { FormDesigner } = await import('./form-designer.js');
      let syncing = false;
      const app = tab.path.includes('/forms/') ? tab.path.slice(0, tab.path.indexOf('/forms/')) : '';
      const config = this.app.runner?.config('run:' + app.split('/').pop());
      const run = config ? () => { this.app.runner.start(config); this.app.showTool?.('run'); } : null;
      const designer = new FormDesigner(designHost, {
        path: tab.path,
        text: file.model.getValue(),
        backend,
        run,
        writeFile: async (p, content) => {
          const open = this.files.get(p);
          if (open?.model) { if (open.model.getValue() !== content) open.model.pushEditOperations([], [{ range: open.model.getFullModelRange(), text: content }], () => null); }
          else await backend.invoke('file.write', { path: p, content });
          this.app.tree?.refresh?.();
        },
        openFile: (p, line) => this.open(p, line ? { line, col: 9 } : {}),
        onChange: (text) => {
          syncing = true;
          file.model.pushEditOperations([], [{ range: file.model.getFullModelRange(), text }], () => null);
          syncing = false;
        },
      });
      group.designer = designer;
      const sub = file.model.onDidChangeContent(debounce(() => { if (!syncing && group.designer === designer) designer.setText(file.model.getValue()); }, 600));
      const destroy = designer.destroy.bind(designer);
      designer.destroy = () => { sub.dispose(); destroy(); group.textView.style.right = ''; group.textView.style.width = ''; };
    } catch (e) {
      designHost.append(el('div.placeholder', {}, 'The visual editor could not start: ' + e.message));
    }
  }

  /** A diff tab: {path, original (text), title, modified?: text (read only, e.g. a past commit)} */
  async openDiff(path, { original, modified = null, title, readOnly = false } = {}) {
    const group = this.focused;
    const tab = { id: 'diff' + Math.random().toString(36).slice(2), path, kind: 'diff', original, modified, title: title || basename(path) + ' (diff)', readOnly };
    if (modified === null) await this.load(path).catch(() => null);
    group.tabs.push(tab);
    this.activate(group, tab);
  }

  renderDiff(group, tab) {
    const view = el('div.view.with-header');
    const body = el('div.diff-body');
    view.append(el('div.diff-header', {}, icon('diff'), tab.title, el('span', { style: { flex: 1 } }),
      el('span', {}, tab.modified === null ? 'HEAD  ↔  your version (editable)' : 'before  ↔  after')), body);
    group.host.append(view);
    group.diff?.dispose();
    group.diff = monaco.editor.createDiffEditor(body, { ...this.editorOptions(), renderSideBySide: prefs.get('diffSideBySide', true), originalEditable: false, readOnly: tab.modified !== null, ignoreTrimWhitespace: false });
    const lang = languageOf(tab.path);
    const original = monaco.editor.createModel(tab.original ?? '', lang);
    const modified = tab.modified !== null ? monaco.editor.createModel(tab.modified, lang) : this.files.get(tab.path)?.model || monaco.editor.createModel('', lang);
    group.diff.setModel({ original, modified });
    const dispose = group.diff.dispose.bind(group.diff);
    group.diff.dispose = () => { dispose(); original.dispose(); if (tab.modified !== null) modified.dispose(); };
  }

  reveal(group, { line, col = 1, endLine, endCol, select = true, focus = !this.noFocus }) {
    const editor = group.editor;
    const range = new monaco.Range(line, col, endLine || line, endCol || col);
    setTimeout(() => {
      if (select) editor.setSelection(range);
      editor.revealRangeInCenterIfOutsideViewport(range, 0);
      if (focus) editor.focus();
    }, 0);
  }

  renderTabs(group) {
    clear(group.tabsNode);
    for (const tab of group.tabs) {
      const status = this.app.git?.statusOf(tab.path);
      const node = el(`div.tab${tab.id === group.active ? '.on' : ''}${this.isDirty(tab.path) && tab.kind !== 'diff' ? '.dirty' : ''}${tab.preview ? '.preview' : ''}${status ? '.st-' + status : ''}`, {
        title: tab.path,
        onmousedown: (e) => { if (e.button === 1) { e.preventDefault(); this.closeTab(group, tab); } },
        onclick: () => this.activate(group, tab),
        ondblclick: () => { tab.preview = false; this.renderTabs(group); },
        oncontextmenu: (e) => { e.preventDefault(); this.tabMenu(group, tab, e); },
      }, tab.kind === 'diff' ? icon('diff') : fileIcon(tab.path), el('span.name', {}, tab.kind === 'diff' ? tab.title : basename(tab.path)),
      el('button.close', { title: 'Close (Ctrl+F4)', onclick: (e) => { e.stopPropagation(); this.closeTab(group, tab); } }, icon('close')));
      group.tabsNode.append(node);
      if (tab.id === group.active) setTimeout(() => node.scrollIntoView({ block: 'nearest', inline: 'nearest' }), 0);
    }
    group.tabsNode.append(el('div.tabs-actions', {},
      el('button.icon-btn', { title: 'Split right', onclick: () => this.split() }, icon('split')),
      this.groups.length > 1 ? el('button.icon-btn', { title: 'Close this group', onclick: () => this.closeGroup(group) }, icon('close')) : null));
  }

  tabMenu(group, tab, e) {
    menu([
      { label: 'Close', kbd: kbd('Ctrl+F4'), action: () => this.closeTab(group, tab) },
      { label: 'Close Other Tabs', action: () => group.tabs.filter((t) => t !== tab).forEach((t) => this.closeTab(group, t)) },
      { label: 'Close All Tabs', action: () => [...group.tabs].forEach((t) => this.closeTab(group, t)) },
      { label: 'Close Tabs to the Right', action: () => group.tabs.slice(group.tabs.indexOf(tab) + 1).forEach((t) => this.closeTab(group, t)) },
      '-',
      { label: 'Split Right', icon: 'split', action: () => { this.activate(group, tab); this.split(); } },
      { label: 'Select in Project View', action: () => this.app.tree?.reveal(tab.path) },
      { label: 'Copy Path', action: () => navigator.clipboard?.writeText(tab.path) },
      tab.kind === 'text' && this.app.git?.enabled ? { label: 'Show Diff with HEAD', icon: 'diff', action: () => this.app.git.diff(tab.path) } : null,
    ].filter(Boolean), { x: e.clientX, y: e.clientY });
  }

  async closeTab(group, tab, silent = false) {
    if (!silent && tab.kind === 'text' && this.isDirty(tab.path) && !group.tabs.some((t) => t !== tab && t.path === tab.path)) {
      await this.save(tab.path);
    }
    const index = group.tabs.indexOf(tab);
    group.tabs.splice(index, 1);
    if (group.modelTab === tab) group.modelTab = null;
    if (group.active === tab.id) {
      const next = group.tabs[index] || group.tabs[index - 1];
      group.active = next?.id || null;
      if (next) this.activate(group, next); else { this.renderTabs(group); this.renderView(group, null); this.emit('active', null); }
    } else {
      this.renderTabs(group);
    }
    if (tab.kind === 'diff') { group.diff?.dispose(); group.diff = null; }
    if (!this.groups.some((g) => g.tabs.some((t) => t.path === tab.path && t.kind !== 'diff'))) {
      const file = this.files.get(tab.path);
      if (file?.model && !this.isDirty(tab.path)) { file.model.dispose(); this.files.delete(tab.path); }
      else if (file && !file.model) this.files.delete(tab.path);
    }
    if (!group.tabs.length && this.groups.length > 1) this.closeGroup(group);
  }

  renderEmpty(group = this.focused) {
    if (!group || group.tabs.length) return;
    group.textView.style.display = 'none';
    const hint = (a, b) => [el('span', {}, a), el('kbd', {}, kbd(b))];
    group.host.append(el('div.view.empty-editor', {}, el('div.hints', {},
      ...hint('Search Everywhere', 'Double Shift'), ...hint('Go to File', 'Ctrl+Shift+N'), ...hint('Go to Class', 'Ctrl+N'),
      ...hint('Recent Files', 'Ctrl+E'), ...hint('Find in Files', 'Ctrl+Shift+F'), ...hint('Commit', 'Ctrl+K'), ...hint('Run', 'Shift+F10'))));
    clear(group.crumbs);
  }

  updateCrumbs(group) {
    const tab = this.activeTab(group);
    clear(group.crumbs);
    if (!tab) return;
    const parts = tab.path.replace(/^\/@lib/, '').split('/');
    parts.forEach((part, i) => {
      if (i) group.crumbs.append(el('span.sep', {}, '›'));
      group.crumbs.append(el('span', { onclick: () => this.app.tree?.reveal(parts.slice(0, i + 1).join('/')) }, part));
    });
    const symbols = this.app.outlineCache?.get(tab.path);
    const pos = group.editor.getPosition();
    if (symbols && pos) {
      for (const s of symbols) {
        if (pos.lineNumber >= s.line && pos.lineNumber <= (s.endLine || s.line)) {
          group.crumbs.append(el('span.sep', {}, '›'), el('span', { onclick: () => this.reveal(group, { line: s.line, col: s.col }) }, s.name));
          const member = (s.children || []).find((c) => c.kind === 'method' && pos.lineNumber >= c.line && pos.lineNumber <= (c.endLine || c.line));
          if (member) group.crumbs.append(el('span.sep', {}, '›'), el('span', { onclick: () => this.reveal(group, { line: member.line, col: member.col }) }, member.name + '()'));
        }
      }
    }
  }

  // saving ---------------------------------------------------------------------------------------------------

  isDirty(path) {
    const file = this.files.get(path);
    return !!file?.model && file.model.getAlternativeVersionId() !== file.saved;
  }

  dirtyPaths() { return [...this.files.keys()].filter((p) => this.isDirty(p)); }

  changed(path) {
    for (const g of this.groups) if (g.tabs.some((t) => t.path === path)) this.renderTabs(g);
    this.emit('dirty', path);
    if (prefs.get('autosave', true)) this.autosave();
  }

  async save(path) {
    const file = this.files.get(path);
    if (!file?.model || file.readOnly || !this.isDirty(path)) return;
    const version = file.model.getAlternativeVersionId();
    try {
      const result = await backend.invoke('file.write', { path, content: file.model.getValue() });
      file.saved = version;
      file.mtime = result?.mtime;
      for (const g of this.groups) if (g.tabs.some((t) => t.path === path)) this.renderTabs(g);
      this.emit('saved', path);
    } catch (e) {
      notify(e.message, 'error', { title: 'Cannot save ' + basename(path) });
    }
  }

  async saveAll(auto = false) {
    for (const path of this.dirtyPaths()) await this.save(path);
    if (!auto) this.app.status?.('All files are saved');
  }

  /** Reloads files changed on disk (after a git checkout, a pull, a run...) unless edited here. */
  async reloadFromDisk(paths = null) {
    for (const [path, file] of this.files) {
      if (!file.model || (paths && !paths.includes(path)) || this.isDirty(path) || path.startsWith('/')) continue;
      try {
        const version = file.model.getAlternativeVersionId();
        const data = await backend.invoke('file.read', { path });
        // typed while the disk was read: what was typed wins (it is saved next)
        if (this.isDirty(path) || file.model.getAlternativeVersionId() !== version) continue;
        if (data.text && data.content !== file.model.getValue()) {
          file.model.pushEditOperations([], [{ range: file.model.getFullModelRange(), text: data.content }], () => null);
          file.saved = file.model.getAlternativeVersionId();
        }
      } catch {
        // deleted: close its tabs
        for (const g of this.groups) for (const t of g.tabs.filter((t) => t.path === path)) this.closeTab(g, t, true);
      }
    }
  }

  /** A file was renamed or deleted in the tree: follow it. */
  renamed(from, to) {
    for (const [path, file] of [...this.files]) {
      if (path === from || path.startsWith(from + '/')) {
        const target = to === null ? null : to + path.slice(from.length);
        for (const g of this.groups) {
          for (const t of [...g.tabs].filter((t) => t.path === path)) {
            if (target === null) this.closeTab(g, t, true); else t.path = target;
          }
        }
        if (target !== null && file.model) {
          const model = monaco.editor.createModel(file.model.getValue(), languageOf(target), uriOf(target));
          model.onDidChangeContent(() => this.changed(target));
          file.model.dispose();
          this.files.delete(path);
          this.files.set(target, { ...file, model, saved: model.getAlternativeVersionId() });
        } else this.files.delete(path);
      }
    }
    for (const g of this.groups) { g.modelTab = null; this.renderTabs(g); const tab = this.activeTab(g); if (tab) this.renderView(g, tab); }
  }

  // navigation -----------------------------------------------------------------------------------------------

  addRecent(path) {
    this.recent = [path, ...this.recent.filter((p) => p !== path)].slice(0, 50);
    prefs.set('recent:' + (this.app.projectKey || ''), this.recent);
  }

  pushNavigation() {
    const tab = this.activeTab();
    if (!tab || tab.kind !== 'text') return;
    const pos = this.focused.editor.getPosition();
    const entry = { path: tab.path, line: pos?.lineNumber || 1, col: pos?.column || 1 };
    const last = this.navigation[this.navIndex];
    if (last && last.path === entry.path && Math.abs(last.line - entry.line) < 3) return;
    this.navigation = this.navigation.slice(0, this.navIndex + 1);
    this.navigation.push(entry);
    this.navIndex = this.navigation.length - 1;
  }

  async back() {
    if (this.navIndex < 0) return;
    const current = this.activeTab();
    if (current && this.navIndex === this.navigation.length - 1) { this.pushNavigation(); this.navIndex--; }
    const entry = this.navigation[this.navIndex];
    if (!entry) return;
    this.navIndex = Math.max(0, this.navIndex - 1);
    await this.openQuiet(entry);
  }

  async forward() {
    const entry = this.navigation[this.navIndex + 2] || this.navigation[this.navIndex + 1];
    if (!entry) return;
    this.navIndex = this.navigation.indexOf(entry);
    await this.openQuiet(entry);
  }

  async openQuiet(entry) {
    const group = this.focused;
    let tab = group.tabs.find((t) => t.path === entry.path && t.kind === 'text');
    if (!tab) { await this.load(entry.path); tab = { id: 'tab' + Math.random().toString(36).slice(2), path: entry.path, kind: 'text' }; group.tabs.push(tab); }
    this.activate(group, tab);
    this.reveal(group, entry);
  }

  // PHP language features ----------------------------------------------------------------------------------

  registerOpener() {
    monaco.editor.registerEditorOpener({
      openCodeEditor: (source, resource, selection) => {
        const path = pathOf(resource);
        const options = {};
        if (selection) {
          options.line = selection.startLineNumber ?? selection.lineNumber;
          options.col = selection.startColumn ?? selection.column;
        }
        this.open(path, options);
        return true;
      },
    });
  }

  /** Models for result locations: Monaco's peek view needs them. */
  async ensureModels(paths) {
    for (const path of new Set(paths)) {
      if (!monaco.editor.getModel(uriOf(path))) {
        try {
          const data = await backend.invoke('file.read', { path });
          if (data.text) monaco.editor.createModel(data.content, languageOf(path), uriOf(path));
        } catch { /* gone */ }
      }
    }
  }

  registerPhp() {
    const args = (model, position) => ({ path: pathOf(model.uri), text: model.getValue(), line: position.lineNumber, column: position.column });
    const range = (r) => new monaco.Range(r.startLine, r.startColumn, r.endLine, r.endColumn);
    const location = (l) => ({ uri: uriOf(l.path), range: new monaco.Range(l.line, l.col, l.line, l.col + (l.length || 0)) });
    const kinds = KIND();

    monaco.languages.registerCompletionItemProvider('php', {
      triggerCharacters: ['>', ':', '$', '\\', "'", '"'],
      provideCompletionItems: async (model, position, context) => {
        const line = model.getLineContent(position.lineNumber).slice(0, position.column - 1);
        if (context.triggerCharacter === '>' && !line.endsWith('->')) return { suggestions: [] };
        if ((context.triggerCharacter === "'" || context.triggerCharacter === '"') && !/\(\s*['"]$/.test(line)) return { suggestions: [] };
        if (context.triggerCharacter === ':' && !line.endsWith('::')) return { suggestions: [] };
        const result = await backend.invoke('php.complete', args(model, position)).catch(() => null);
        if (!result) return { suggestions: [] };
        const word = model.getWordUntilPosition(position);
        // a string argument (t('…'), isPressed('…')): what is typed inside the quotes, spaces included
        const from = typeof result.typed === 'string' ? position.column - result.typed.length : word.startColumn;
        const replace = new monaco.Range(position.lineNumber, from, position.lineNumber, position.column);
        return {
          incomplete: result.incomplete,
          suggestions: result.items.map((item) => ({
            label: { label: item.label, detail: item.kind === 'method' || item.kind === 'function' ? item.detail?.replace(/^[^(]*/, '') : undefined, description: item.description || (item.kind !== 'method' ? item.detail : '') },
            kind: kinds[item.kind] ?? monaco.languages.CompletionItemKind.Text,
            insertText: item.insertText,
            insertTextRules: item.snippet ? monaco.languages.CompletionItemInsertTextRule.InsertAsSnippet : undefined,
            filterText: item.filterText, sortText: item.sortText, range: replace,
            documentation: item.documentation ? { value: item.documentation } : undefined,
            detail: item.detail, tags: item.deprecated ? [monaco.languages.CompletionItemTag.Deprecated] : undefined,
            additionalTextEdits: (item.additionalTextEdits || []).map((e) => ({ range: range(e.range), text: e.text })),
            command: item.parameterHints ? { id: 'editor.action.triggerParameterHints', title: '' } : undefined,
          })),
        };
      },
    });

    // config/game.yaml, input.yaml, services.yaml, lang.yaml, forms/*.form.yaml (JumPHP\Ide\Config\ConfigCompletion)
    monaco.languages.registerCompletionItemProvider('yaml', {
      triggerCharacters: [' ', '[', ','],
      provideCompletionItems: async (model, position) => {
        if (!/(^|\/)config\/(game|input|services|lang)\.ya?ml$|\.form\.ya?ml$/.test(pathOf(model.uri))) return { suggestions: [] };
        const result = await backend.invoke('config.complete', args(model, position)).catch(() => null);
        if (!result) return { suggestions: [] };
        const line = model.getLineContent(position.lineNumber).slice(0, position.column - 1);
        const typed = typeof result.typed === 'string' ? result.typed : /[\w\\:. -]*$/.exec(line)[0].replace(/^.*[\[,:]\s*|^\s+|^-\s+/, '');
        const replace = new monaco.Range(position.lineNumber, position.column - typed.length, position.lineNumber, position.column);
        return {
          suggestions: result.items.map((item) => ({
            label: item.label, kind: kinds[item.kind] ?? monaco.languages.CompletionItemKind.Value, insertText: item.insertText,
            detail: item.detail || undefined, documentation: item.documentation ? { value: item.documentation } : undefined,
            sortText: item.sortText, filterText: item.filterText, range: replace,
          })),
        };
      },
    });

    monaco.languages.registerDefinitionProvider('php', {
      provideDefinition: async (model, position) => {
        const list = await backend.invoke('php.definition', args(model, position)).catch(() => []);
        await this.ensureModels(list.map((l) => l.path));
        this.pushNavigation();
        if (!list.length) {
          // on a declaration: show its usages (as PhpStorm does)
          const refs = await backend.invoke('php.references', { ...args(model, position), declarations: false }).catch(() => []);
          if (refs.length) this.app.showUsages?.(refs, model.getWordAtPosition(position)?.word);
          return [];
        }
        return list.map((l) => ({ uri: uriOf(l.path), range: new monaco.Range(l.line, l.col, l.line, l.col) }));
      },
    });

    monaco.languages.registerImplementationProvider('php', {
      provideImplementation: async (model, position) => {
        const list = await backend.invoke('php.implementations', args(model, position)).catch(() => []);
        await this.ensureModels(list.map((l) => l.path));
        return list.map((l) => ({ uri: uriOf(l.path), range: new monaco.Range(l.line, l.col, l.line, l.col) }));
      },
    });

    monaco.languages.registerReferenceProvider('php', {
      provideReferences: async (model, position, context) => {
        const refs = await backend.invoke('php.references', { ...args(model, position), declarations: context.includeDeclaration }).catch(() => []);
        await this.ensureModels(refs.map((r) => r.path));
        return refs.map(location);
      },
    });

    monaco.languages.registerDocumentHighlightProvider('php', {
      provideDocumentHighlights: async (model, position) => {
        const symbol = await backend.invoke('php.symbol', args(model, position)).catch(() => null);
        if (!symbol) return [];
        const refs = await backend.invoke('php.references', args(model, position)).catch(() => []);
        const path = pathOf(model.uri);
        return refs.filter((r) => r.path === path).map((r) => ({ range: new monaco.Range(r.line, r.col, r.line, r.col + r.length),
          kind: r.declaration ? monaco.languages.DocumentHighlightKind.Write : monaco.languages.DocumentHighlightKind.Read }));
      },
    });

    monaco.languages.registerRenameProvider('php', {
      resolveRenameLocation: async (model, position) => {
        const symbol = await backend.invoke('php.symbol', args(model, position)).catch(() => null);
        if (!symbol) return { rejectReason: 'This element cannot be renamed.', range: undefined, text: '' };
        const r = range(symbol.range);
        return { range: r, text: model.getValueInRange(r).replace(/^\$/, '') };
      },
      provideRenameEdits: async (model, position, newName) => {
        await this.saveAll(true);
        const result = await backend.invoke('php.rename', { ...args(model, position), newName });
        const edits = [];
        const closed = {};
        for (const [path, list] of Object.entries(result.edits)) {
          const open = monaco.editor.getModel(uriOf(path));
          if (open && this.files.has(path)) {
            for (const e of list) edits.push({ resource: open.uri, versionId: undefined, textEdit: { range: new monaco.Range(e.line, e.col, e.line, e.col + e.length), text: e.text } });
          } else closed[path] = list;
        }
        if (Object.keys(closed).length) await backend.invoke('edits.apply', { edits: closed });
        if (result.renameFile) {
          setTimeout(async () => {
            await this.saveAll(true);
            await backend.invoke('file.rename', { from: result.renameFile.from, to: result.renameFile.to });
            this.renamed(result.renameFile.from, result.renameFile.to);
            this.app.tree?.refresh();
          }, 50);
        }
        if (result.uncertain.length) {
          notify(`${result.uncertain.length} possible use(s) whose type is unknown were left as they are.`, 'info', { title: 'Rename',
            actions: [{ label: 'Show them', action: () => this.app.showUsages?.(result.uncertain, 'not renamed') }] });
        }
        const count = Object.values(result.edits).reduce((n, l) => n + l.length, 0);
        this.app.status?.(`Renamed to ${newName}: ${count} place(s) in ${Object.keys(result.edits).length} file(s)`);
        return { edits };
      },
    });

    monaco.languages.registerHoverProvider('php', {
      provideHover: async (model, position) => {
        const hover = await backend.invoke('php.hover', args(model, position)).catch(() => null);
        return hover ? { contents: [{ value: hover.contents }], range: range(hover.range) } : null;
      },
    });

    monaco.languages.registerSignatureHelpProvider('php', {
      signatureHelpTriggerCharacters: ['(', ','],
      signatureHelpRetriggerCharacters: [','],
      provideSignatureHelp: async (model, position) => {
        const help = await backend.invoke('php.signature', args(model, position)).catch(() => null);
        if (!help) return null;
        return { value: { signatures: [{ label: help.label, documentation: help.documentation, parameters: help.params.map((p) => ({ label: p })) }], activeSignature: 0, activeParameter: help.active }, dispose() {} };
      },
    });

    const symbolKinds = SYMBOL();
    monaco.languages.registerDocumentSymbolProvider('php', {
      provideDocumentSymbols: async (model) => {
        const outline = await backend.invoke('php.outline', { text: model.getValue() }).catch(() => []);
        this.app.outlineCache?.set(pathOf(model.uri), outline);
        const symbol = (s) => {
          const r = new monaco.Range(s.line, 1, s.endLine || s.line, 1);
          return { name: s.name, detail: s.detail || '', kind: symbolKinds[s.kind] ?? monaco.languages.SymbolKind.Variable, range: r,
            selectionRange: new monaco.Range(s.line, s.col, s.line, s.col + s.name.length), tags: [], children: (s.children || []).map(symbol) };
        };
        return outline.map(symbol);
      },
    });
  }

  /** The editor's context menu: Play (PHP files of a game), then PhpStorm's entries. */
  editorMenu(group, e) {
    e.event?.preventDefault?.();
    e.event?.stopPropagation?.();
    const ed = group.editor;
    const tab = this.activeTab();
    if (!tab || tab.kind !== 'text') return;
    const file = this.files.get(tab.path);
    const position = e.target?.position;
    if (position && !ed.getSelection()?.containsPosition(position)) ed.setPosition(position);
    this.focus(group);
    ed.focus();
    const run = (id) => () => { ed.focus(); ed.trigger('menu', id, null); };
    const php = tab.path.endsWith('.php');
    const writable = !file?.readOnly;
    const play = this.app.playTarget?.(tab.path);
    menu([
      play ? { label: play.label, icon: colorIcon('play', 'green'), kbd: 'Ctrl+Shift+F10', action: () => this.app.playFile(tab.path) } : null,
      play ? '-' : null,
      this.app.claude ? { label: ed.getSelection()?.isEmpty() ? 'Ask Claude about This File' : 'Ask Claude about the Selection', icon: 'claude', action: () => {
        const sel = ed.getSelection();
        this.app.claude.ask(sel && !sel.isEmpty() ? `In ${tab.path} (lines ${sel.startLineNumber}-${sel.endLineNumber}):\n\`\`\`\n${ed.getModel().getValueInRange(sel)}\n\`\`\`\n` : `@${tab.path} `);
      } } : null,
      this.app.claude ? '-' : null,
      php ? { label: 'Go to Declaration', kbd: 'Ctrl+B', action: run('editor.action.revealDefinition') } : null,
      php ? { label: 'Go to Implementation(s)', kbd: 'Ctrl+Alt+B', action: run('editor.action.goToImplementation') } : null,
      php ? { label: 'Find Usages', icon: 'find', kbd: 'Alt+F7', action: () => this.app.findUsages?.() } : null,
      php ? { label: 'Type Hierarchy', icon: 'structure', kbd: 'Ctrl+H', action: () => this.app.typeHierarchy?.() } : null,
      php ? '-' : null,
      php && writable ? { label: 'Rename…', kbd: 'Shift+F6', action: run('editor.action.rename') } : null,
      writable ? { label: 'Reformat Code', kbd: 'Ctrl+Alt+L', action: run('editor.action.formatDocument') } : null,
      writable ? { label: 'Comment with Line Comment', kbd: 'Ctrl+/', action: run('editor.action.commentLine') } : null,
      writable ? '-' : null,
      writable ? { label: 'Cut', kbd: 'Ctrl+X', action: () => { const text = ed.getModel().getValueInRange(ed.getSelection()); navigator.clipboard?.writeText(text); ed.executeEdits('cut', [{ range: ed.getSelection(), text: '' }]); } } : null,
      { label: 'Copy', kbd: 'Ctrl+C', action: () => navigator.clipboard?.writeText(ed.getModel().getValueInRange(ed.getSelection())) },
      writable ? { label: 'Paste', kbd: 'Ctrl+V', action: async () => { const text = await navigator.clipboard?.readText?.().catch(() => null); if (text != null) { ed.focus(); ed.executeEdits('paste', [{ range: ed.getSelection(), text, forceMoveMarkers: true }]); } } } : null,
      '-',
      this.app.git?.enabled ? { label: 'Git', icon: 'branch', items: [
        { label: 'Annotate with Git Blame', action: () => this.app.git.toggleBlame() },
        { label: 'Show Diff with HEAD', icon: 'diff', action: () => this.app.git.diff(tab.path) },
      ] } : null,
      { label: 'Command Palette…', kbd: 'F1', action: run('editor.action.quickCommand') },
    ].filter(Boolean), { x: e.event.posx, y: e.event.posy });
  }

  /** PhpStorm's keys on top of Monaco's. */
  addEditorActions(editor) {
    const K = monaco.KeyMod;
    const C = monaco.KeyCode;
    const run = (id) => () => editor.trigger('keyboard', id, null);
    const add = (id, label, keys, handler, menuGroup) => editor.addAction({ id: 'jph.' + id, label, keybindings: keys, run: handler, contextMenuGroupId: menuGroup });
    add('declaration', 'Go to Declaration', [K.CtrlCmd | C.KeyB], run('editor.action.revealDefinition'), 'navigation');
    add('implementation', 'Go to Implementation(s)', [K.CtrlCmd | K.Alt | C.KeyB], run('editor.action.goToImplementation'), 'navigation');
    add('usages', 'Find Usages', [K.Alt | C.F7], () => this.app.findUsages?.(), 'navigation');
    add('rename', 'Rename…', [K.Shift | C.F6], run('editor.action.rename'), '1_modification');
    add('duplicate', 'Duplicate Line or Selection', [K.CtrlCmd | C.KeyD], run('editor.action.copyLinesDownAction'));
    add('deleteLine', 'Delete Line', [K.CtrlCmd | C.KeyY], run('editor.action.deleteLines'));
    add('expand', 'Extend Selection', [K.CtrlCmd | C.KeyW], run('editor.action.smartSelect.expand'));
    add('shrink', 'Shrink Selection', [K.CtrlCmd | K.Shift | C.KeyW], run('editor.action.smartSelect.shrink'));
    add('moveUp', 'Move Line Up', [K.Alt | K.Shift | C.UpArrow], run('editor.action.moveLinesUpAction'));
    add('moveDown', 'Move Line Down', [K.Alt | K.Shift | C.DownArrow], run('editor.action.moveLinesDownAction'));
    add('format', 'Reformat Code', [K.CtrlCmd | K.Alt | C.KeyL], run('editor.action.formatDocument'));
    add('params', 'Parameter Info', [K.CtrlCmd | C.KeyP], run('editor.action.triggerParameterHints'));
    add('doc', 'Quick Documentation', [K.CtrlCmd | C.KeyQ], run('editor.action.showHover'));
    add('structure', 'File Structure', [K.CtrlCmd | C.F12], () => this.app.fileStructure?.());
    add('comment', 'Comment with Line Comment', [K.CtrlCmd | C.Slash], run('editor.action.commentLine'));
    add('save', 'Save All', [K.CtrlCmd | C.KeyS], () => this.saveAll());
    add('playFile', 'Play', [K.CtrlCmd | K.Shift | C.F10], () => { const p = this.activePath(); if (p) this.app.playFile?.(p); });
    add('hierarchy', 'Type Hierarchy', [K.CtrlCmd | C.KeyH], () => this.app.typeHierarchy?.());
    add('close', 'Close Tab', [K.CtrlCmd | C.F4], () => { const t = this.activeTab(); if (t) this.closeTab(this.focused, t); });
    add('back', 'Back', [K.CtrlCmd | K.Alt | C.LeftArrow], () => this.back());
    add('forward', 'Forward', [K.CtrlCmd | K.Alt | C.RightArrow], () => this.forward());
    add('annotate', 'Annotate with Git Blame', [], () => this.app.git?.toggleBlame(), 'z_git');
    add('diffHead', 'Show Diff with HEAD', [], () => this.app.git?.diff(this.activePath()), 'z_git');
  }
}

function detectIndent(text) {
  const m = text.match(/^( +)\S/m);
  return m ? Math.min(8, m[1].length) : 4;
}
