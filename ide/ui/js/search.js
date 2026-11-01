// Search Everywhere (Double Shift), Recent Files (Ctrl+E), File Structure (Ctrl+F12), and the Find tool window
// (Find / Replace in Files, Find Usages results).
import { backend } from './backend.js';
import { el, clear, icon, basename, dirname, fileIcon, symbolIcon, escapeHtml, debounce, notify, chooser, confirm, kbd } from './ui.js';

/** The Search Everywhere popup. tab: all | classes | files | symbols | actions */
export function searchEverywhere(app, tab = 'all', initial = '') {
  const overlays = document.getElementById('overlays');
  const layer = el('div.overlay.dim');
  const box = el('div.search-everywhere');
  const tabs = ['all', 'classes', 'files', 'symbols', 'actions'];
  const names = { all: 'All', classes: 'Classes', files: 'Files', symbols: 'Symbols', actions: 'Actions' };
  const input = el('input', { placeholder: 'Type / to search for actions, : for a line number', value: initial, spellcheck: false });
  const results = el('div.se-results');
  const footer = el('div.se-footer', {}, kbd('Press Enter to open, Ctrl+Enter to open to the right · Tab to switch tabs'));
  const tabBar = el('div.se-tabs');
  let current = tab;
  let items = [];
  let selected = 0;
  let generation = 0;

  const close = () => { layer.remove(); box.remove(); document.removeEventListener('keydown', onKey, true); };
  const renderTabs = () => {
    clear(tabBar);
    for (const t of tabs) tabBar.append(el(`button.tw-tab${t === current ? '.on' : ''}`, { onclick: () => { current = t; renderTabs(); search(); input.focus(); } }, names[t]));
  };
  const choose = (item, side = false) => {
    if (!item) return;
    close();
    if (item.action) return item.action();
    if (side) app.editors.split();
    app.editors.open(item.path, { line: item.line, col: item.col });
  };
  const render = () => {
    clear(results);
    let group = null;
    items.forEach((item, i) => {
      if (current === 'all' && item.group !== group) { group = item.group; results.append(el('div.se-group', {}, group)); }
      const row = el(`div.row${i === selected ? '.sel' : ''}`, { onclick: (e) => choose(item, e.ctrlKey), onmousemove: () => { if (selected !== i) { selected = i; mark(); } } },
        item.iconNode, el('span.label', {}, item.label), item.hint ? el('span.hint', {}, item.hint) : null, item.right ? el('span.right', {}, item.right) : null);
      results.append(row);
    });
    if (!items.length) results.append(el('div.placeholder', {}, input.value ? 'Nothing found' : 'Recent files and actions appear here'));
  };
  const mark = () => [...results.querySelectorAll('.row')].forEach((r, i) => r.classList.toggle('sel', i === selected));
  const search = debounce(async () => {
    const q = input.value.trim();
    const mine = ++generation;
    let found = [];
    const line = q.match(/^:(\d+)(?::(\d+))?$/);
    if (line && app.editors.activePath()) {
      found.push({ label: `Go to line ${line[1]}`, iconNode: icon('file'), group: 'Navigation', action: () => app.editors.reveal(app.editors.focused, { line: +line[1], col: +(line[2] || 1) }) });
    } else if (q.startsWith('/') || current === 'actions') {
      found = actionItems(app, q.replace(/^\//, ''));
    } else if (!q) {
      found = app.editors.recent.slice(0, 12).map((p) => ({ path: p, label: basename(p), hint: dirname(p), iconNode: fileIcon(p), group: 'Recent Files' }));
    } else {
      const wantClasses = current === 'all' || current === 'classes';
      const wantFiles = current === 'all' || current === 'files';
      const wantSymbols = current === 'symbols' || (current === 'all' && q.length > 1);
      const [classes, files, symbols] = await Promise.all([
        wantClasses ? backend.invoke('php.symbols', { query: q, limit: 40 }).catch(() => []) : [],
        wantFiles ? backend.invoke('search.files', { query: q, limit: 40 }).catch(() => []) : [],
        wantSymbols ? backend.invoke('php.symbols', { query: q, limit: 60 }).catch(() => []) : [],
      ]);
      if (mine !== generation) return;
      const isClass = (s) => ['class', 'interface', 'trait', 'enum'].includes(s.kind);
      for (const s of classes.filter(isClass).slice(0, current === 'all' ? 6 : 50)) {
        found.push({ path: s.path, line: s.line, col: s.col, label: s.name, hint: s.detail, right: s.path, iconNode: symbolIcon(s.kind), group: 'Classes' });
      }
      for (const f of files.slice(0, current === 'all' ? 8 : 50)) {
        found.push({ path: f.path, label: f.name, hint: dirname(f.path), iconNode: fileIcon(f.path), group: 'Files' });
      }
      for (const s of symbols.filter((s) => !isClass(s)).slice(0, current === 'all' ? 8 : 60)) {
        found.push({ path: s.path, line: s.line, col: s.col, label: s.name, hint: s.detail, right: basename(s.path), iconNode: symbolIcon(s.kind), group: 'Symbols' });
      }
      if (current === 'all') found.push(...actionItems(app, q).slice(0, 5));
    }
    if (mine !== generation) return;
    items = found;
    selected = 0;
    render();
  }, 90);
  const onKey = (e) => {
    if (e.key === 'Escape') { e.preventDefault(); close(); }
    else if (e.key === 'ArrowDown') { selected = Math.min(items.length - 1, selected + 1); mark(); results.children[0] && results.querySelectorAll('.row')[selected]?.scrollIntoView({ block: 'nearest' }); e.preventDefault(); }
    else if (e.key === 'ArrowUp') { selected = Math.max(0, selected - 1); mark(); results.querySelectorAll('.row')[selected]?.scrollIntoView({ block: 'nearest' }); e.preventDefault(); }
    else if (e.key === 'Enter') { e.preventDefault(); choose(items[selected], e.ctrlKey); }
    else if (e.key === 'Tab') { e.preventDefault(); current = tabs[(tabs.indexOf(current) + (e.shiftKey ? tabs.length - 1 : 1)) % tabs.length]; renderTabs(); search(); }
  };
  layer.addEventListener('mousedown', close);
  input.addEventListener('input', search);
  document.addEventListener('keydown', onKey, true);
  box.append(tabBar, el('div.se-input', {}, icon('search'), input), results, footer);
  overlays.append(layer, box);
  renderTabs();
  search();
  input.focus();
  input.select();
}

function actionItems(app, q) {
  const query = q.toLowerCase();
  return app.actions.list().filter((a) => !query || a.label.toLowerCase().includes(query)).slice(0, 40)
    .map((a) => ({ label: a.label, right: a.kbd || '', iconNode: icon(a.icon || 'star'), group: 'Actions', action: a.run }));
}

export function recentFiles(app) {
  chooser(app.editors.recent.map((p) => ({ label: basename(p), hint: dirname(p), icon: fileIcon(p), action: () => app.editors.open(p) })),
    { placeholder: 'Recent Files', width: 520 });
}

export async function fileStructure(app) {
  const tab = app.editors.activeTab();
  if (!tab || tab.kind !== 'text') return;
  const model = app.editors.files.get(tab.path)?.model;
  if (!model) return;
  const outline = await backend.invoke('php.outline', { text: model.getValue() }).catch(() => []);
  const items = [];
  for (const s of outline) {
    items.push({ label: s.name, hint: s.kind, icon: symbolIcon(s.kind), action: () => app.editors.reveal(app.editors.focused, { line: s.line, col: s.col }) });
    for (const c of s.children || []) items.push({ label: '   ' + c.name, hint: c.detail, icon: symbolIcon(c.kind), action: () => app.editors.reveal(app.editors.focused, { line: c.line, col: c.col }) });
  }
  chooser(items, { placeholder: 'File structure: type to filter', width: 560 });
}

/** The Find tool window: tabs of results (find in files, usages). */
export class FindPanel {
  constructor(app) {
    this.app = app;
    this.node = el('div', { style: { height: '100%', display: 'flex', flexDirection: 'column' } });
    this.form = null;
    this.options = { regex: false, case: false, word: false, include: '', exclude: '' };
  }

  title() { return 'Find'; }

  /** Find / Replace in Files. */
  show({ query = '', replace = false, include = '' } = {}) {
    if (include) this.options.include = include;
    clear(this.node);
    const queryInput = el('input.input', { value: query, placeholder: 'Text to find', spellcheck: false });
    const replaceInput = el('input.input', { placeholder: 'Replace with', spellcheck: false });
    const includeInput = el('input.input', { value: this.options.include, placeholder: 'File mask (*.php, src/*)', style: { width: '200px' } });
    const toggle = (key, text, title) => el(`button.toggle-btn${this.options[key] ? '.on' : ''}`, { title, onclick: (e) => { this.options[key] = !this.options[key]; e.currentTarget.classList.toggle('on'); run(); } }, text);
    const summary = el('span', { style: { color: 'var(--text-3)', fontSize: '12px', marginLeft: '8px' } });
    const list = el('div.tree', { tabIndex: 0, style: { flex: '1', overflow: 'auto' } });
    let last = null;
    const run = debounce(async () => {
      this.options.include = includeInput.value;
      const q = queryInput.value;
      if (!q) { clear(list); summary.textContent = ''; return; }
      summary.textContent = 'Searching…';
      try {
        last = await backend.invoke('search.text', { query: q, options: this.options });
      } catch (e) { summary.textContent = e.message; return; }
      summary.textContent = `${last.matches} match${last.matches === 1 ? '' : 'es'} in ${last.files} file${last.files === 1 ? '' : 's'}${last.truncated ? ' (first ones)' : ''}`;
      this.renderMatches(list, last.results);
    }, 220);
    queryInput.addEventListener('input', run);
    includeInput.addEventListener('input', run);
    const replaceAll = el('button.btn.small', { onclick: async () => {
      if (!last || !queryInput.value) return;
      if (!(await confirm('Replace in Files', `Replace ${last.matches} match(es) in ${last.files} file(s) with “${replaceInput.value}”?`, { ok: 'Replace All' }))) return;
      await this.app.editors.saveAll(true);
      const result = await backend.invoke('search.replace', { query: queryInput.value, replacement: replaceInput.value, options: this.options });
      await this.app.editors.reloadFromDisk(result.files);
      this.app.git?.refresh();
      notify(`${result.replaced} replacement(s) in ${result.files.length} file(s)`, 'success');
      run();
    } }, 'Replace All');
    this.node.append(el('div.find-bar', {},
      el('div.line', {}, icon('search'), queryInput, toggle('case', 'Cc', 'Match case'), toggle('word', 'W', 'Words'), toggle('regex', '.*', 'Regex'), includeInput),
      replace ? el('div.line', {}, icon('refresh'), replaceInput, replaceAll) : null,
      el('div.line', {}, summary)), list);
    this.app.showTool('find');
    setTimeout(() => { queryInput.focus(); queryInput.select(); }, 0);
    if (query) run();
  }

  renderMatches(list, results) {
    clear(list);
    for (const file of results) {
      const header = el('div.row.open', { style: { paddingLeft: '6px' } }, el('span.twisty'), fileIcon(file.path), el('span.label', {}, basename(file.path)),
        el('span.hint', {}, dirname(file.path)), el('span.right', {}, String(file.matches.length)));
      const children = el('div');
      header.addEventListener('click', () => { header.classList.toggle('open'); children.hidden = !header.classList.contains('open'); });
      for (const m of file.matches) {
        const text = m.text;
        const start = Math.max(0, m.col - 1);
        const html = escapeHtml(text.slice(0, start).trimStart().slice(-80)) + '<mark>' + escapeHtml(text.slice(start, start + m.length)) + '</mark>' + escapeHtml(text.slice(start + m.length, start + m.length + 120));
        const row = el('div.row', { style: { paddingLeft: '38px' }, onclick: () => this.app.editors.open(file.path, { line: m.line, col: m.col, endLine: m.line, endCol: m.col + m.length, preview: true }) },
          el('span.lineno', {}, String(m.line)), el('span.label.match-line'));
        row.querySelector('.match-line').innerHTML = html;
        children.append(row);
      }
      list.append(header, children);
    }
  }

  /** Find Usages results: [{path, line, col, length, text, declaration, certain}] */
  usages(refs, name) {
    clear(this.node);
    const byFile = new Map();
    for (const r of refs) { if (!byFile.has(r.path)) byFile.set(r.path, []); byFile.get(r.path).push(r); }
    const list = el('div.tree', { tabIndex: 0, style: { flex: '1', overflow: 'auto' } });
    const results = [...byFile].map(([path, matches]) => ({ path, matches: matches.map((r) => ({ line: r.line, col: r.col, length: r.length, text: r.text, declaration: r.declaration, certain: r.certain })) }));
    this.node.append(el('div.find-bar', {}, el('div.line', {}, icon('find'), el('b', {}, `Usages of ${name || 'symbol'}`),
      el('span', { style: { color: 'var(--text-3)', marginLeft: '8px' } }, `${refs.length} result(s) in ${byFile.size} file(s)`))), list);
    this.renderMatches(list, results);
    this.app.showTool('find');
  }
}
