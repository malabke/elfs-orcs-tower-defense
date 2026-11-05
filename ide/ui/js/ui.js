// Small UI toolkit of the IDE: elements, icons, popup menus, lists, dialogs, notifications, splitters.

const ICONS = {
  play: '<path d="M5 3.5v9l7-4.5z" fill="currentColor" stroke="none"/>',
  stop: '<rect x="4" y="4" width="8" height="8" rx="1" fill="currentColor" stroke="none"/>',
  test: '<path d="M6 2.5h4M7 2.5v4L3.5 12.5a1 1 0 0 0 .9 1.5h7.2a1 1 0 0 0 .9-1.5L9 6.5v-4"/>',
  debug: '<circle cx="8" cy="9" r="3.5"/><path d="M8 5.5V3M4.5 9H2M14 9h-2.5M4.8 6l-1.6-1.6M11.2 6l1.6-1.6"/>',
  camera: '<rect x="2" y="4.5" width="12" height="8.5" rx="1.5"/><circle cx="8" cy="8.75" r="2.3"/><path d="M5.5 4.5l1-2h3l1 2"/>',
  globe: '<circle cx="8" cy="8" r="6"/><path d="M2 8h12M8 2c2 2.2 2 9.8 0 12M8 2c-2 2.2-2 9.8 0 12"/>',
  package: '<path d="M8 1.8l5.5 3v6.4L8 14.2l-5.5-3V4.8z M2.5 4.8L8 7.8l5.5-3M8 7.8v6.4"/>',
  check: '<path d="M3 8.5l3 3 7-7"/>',
  download: '<path d="M8 2v8M4.5 6.5L8 10l3.5-3.5M3 13h10"/>',
  upload: '<path d="M8 11V3M4.5 6.5L8 3l3.5 3.5M3 13h10"/>',
  branch: '<circle cx="4.5" cy="3.5" r="1.5"/><circle cx="4.5" cy="12.5" r="1.5"/><circle cx="11.5" cy="5.5" r="1.5"/><path d="M4.5 5v6M11.5 7c0 3-7 2-7 4"/>',
  pull: '<path d="M8 2v9M4.5 7.5L8 11l3.5-3.5"/><path d="M3 14h10"/>',
  push: '<path d="M8 14V5M4.5 8.5L8 5l3.5 3.5"/><path d="M3 2h10"/>',
  commit: '<circle cx="8" cy="8" r="2.6"/><path d="M1.5 8h3.9M10.6 8h3.9"/>',
  search: '<circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5L14 14"/>',
  settings: '<circle cx="8" cy="8" r="2.2"/><path d="M8 1.5v2M8 12.5v2M1.5 8h2M12.5 8h2M3.4 3.4l1.4 1.4M11.2 11.2l1.4 1.4M3.4 12.6l1.4-1.4M11.2 4.8l1.4-1.4"/>',
  bell: '<path d="M4 11V7.5a4 4 0 0 1 8 0V11l1.2 1.5H2.8zM6.5 14h3"/>',
  folder: '<path d="M1.5 4a1 1 0 0 1 1-1h3.6l1.5 1.6H13.5a1 1 0 0 1 1 1V12a1 1 0 0 1-1 1h-11a1 1 0 0 1-1-1z"/>',
  file: '<path d="M4 1.8h5.2L12.5 5v9.2H4z M9 1.8V5h3.5"/>',
  structure: '<path d="M3 3h4M3 8h4M3 13h4M9 3h4M9 8h4M9 13h4"/>',
  // Claude's little character (pixel art, in its orange)
  claude: '<g fill="#d97757" stroke="none"><rect x="3" y="4" width="10" height="6"/><rect x="1" y="6" width="2" height="2"/><rect x="13" y="6" width="2" height="2"/><rect x="4" y="10" width="1" height="3"/><rect x="6" y="10" width="1" height="3"/><rect x="9" y="10" width="1" height="3"/><rect x="11" y="10" width="1" height="3"/></g><g fill="#1e1f22" stroke="none"><rect x="5" y="6" width="1" height="2"/><rect x="10" y="6" width="1" height="2"/></g>',
  hierarchy: '<rect x="6" y="1.5" width="4" height="3" rx="0.5"/><rect x="1.5" y="11.5" width="4" height="3" rx="0.5"/><rect x="10.5" y="11.5" width="4" height="3" rx="0.5"/><path d="M8 4.5V8M3.5 11.5V8h9v3.5"/>',
  library: '<path d="M2.5 2.5h3v11h-3zM6.5 2.5h3v11h-3zM10.3 3l2.9-.8 2.3 10.6-2.9.8z"/>',
  problems: '<circle cx="8" cy="8" r="6"/><path d="M8 4.8v4M8 11.2v.1"/>',
  terminal: '<rect x="1.5" y="2.5" width="13" height="11" rx="1.5"/><path d="M4 6l2.5 2L4 10M8 10.5h4"/>',
  log: '<circle cx="5" cy="3.5" r="1.4"/><circle cx="5" cy="8" r="1.4"/><circle cx="5" cy="12.5" r="1.4"/><path d="M5 4.9v1.7M5 9.4v1.7M8.5 3.5H14M8.5 8H14M8.5 12.5H14"/>',
  find: '<circle cx="6.5" cy="6.5" r="4"/><path d="M9.5 9.5L13.5 13.5"/><path d="M4.5 6.5h4"/>',
  refresh: '<path d="M13 8a5 5 0 1 1-1.5-3.6M13 2.5v3h-3"/>',
  close: '<path d="M4 4l8 8M12 4l-8 8"/>',
  trash: '<path d="M2.5 4h11M6 4V2.5h4V4M4 4l.7 9.5h6.6L12 4M6.8 6.5v4.5M9.2 6.5v4.5"/>',
  plus: '<path d="M8 3v10M3 8h10"/>',
  minus: '<path d="M3 8h10"/>',
  collapse: '<path d="M4 6l4-3 4 3M4 13l4-3 4 3"/>',
  expand: '<path d="M4 3l4 3 4-3M4 10l4 3 4-3"/>',
  split: '<rect x="2" y="2.5" width="12" height="11" rx="1.5"/><path d="M8 2.5v11"/>',
  more: '<circle cx="3.5" cy="8" r=".9" fill="currentColor"/><circle cx="8" cy="8" r=".9" fill="currentColor"/><circle cx="12.5" cy="8" r=".9" fill="currentColor"/>',
  rollback: '<path d="M3 6.5h6.5a3.5 3.5 0 0 1 0 7H6M5.5 3.5L2.5 6.5l3 3"/>',
  diff: '<path d="M5 2.5v7M2 6h6M8 13.5h6M11 2v8"/>',
  eye: '<path d="M1.5 8S4 3.5 8 3.5 14.5 8 14.5 8 12 12.5 8 12.5 1.5 8 1.5 8z"/><circle cx="8" cy="8" r="2"/>',
  design: '<rect x="2" y="2" width="12" height="12" rx="1.5"/><path d="M2 6h12M6 6v8"/>',
  code: '<path d="M5.5 4L2 8l3.5 4M10.5 4L14 8l-3.5 4"/>',
  game: '<rect x="1.5" y="4.5" width="13" height="7.5" rx="3.5"/><path d="M4.5 8.2h2.6M5.8 6.9v2.6"/><circle cx="10.6" cy="7.4" r=".7" fill="currentColor"/><circle cx="11.8" cy="9" r=".7" fill="currentColor"/>',
  app: '<rect x="1.5" y="2.5" width="13" height="11" rx="1.5"/><path d="M1.5 5.5h13M4 4h.1M5.6 4h.1"/>',
  hybrid: '<rect x="1.5" y="2.5" width="8" height="7" rx="1"/><rect x="6.5" y="6.5" width="8" height="7" rx="1"/>',
  disk: '<rect x="2" y="2" width="12" height="12" rx="1.5"/><rect x="4.5" y="2" width="7" height="4"/><circle cx="8" cy="10" r="2"/>',
  fork: '<circle cx="4.5" cy="3" r="1.5"/><circle cx="11.5" cy="3" r="1.5"/><circle cx="8" cy="13" r="1.5"/><path d="M4.5 4.5c0 3 3.5 3 3.5 5.5v1.5M11.5 4.5c0 3-3.5 3-3.5 5.5"/>',
  user: '<circle cx="8" cy="5.5" r="2.8"/><path d="M2.5 14a5.5 5.5 0 0 1 11 0"/>',
  logout: '<path d="M10 3H13v10h-3M6.5 5L3.5 8l3 3M3.5 8H10"/>',
  history: '<circle cx="8" cy="8" r="6"/><path d="M8 4.5V8l2.5 1.5"/>',
  stepOver: '<path d="M2.5 8.5a5.5 5.5 0 0 1 10.5-2.3M13.5 3v3.5H10"/><circle cx="8" cy="12.5" r="1.5"/>',
  stepInto: '<path d="M8 2v7.5M5 6.5l3 3 3-3"/><circle cx="8" cy="13" r="1.5"/>',
  stepOut: '<path d="M8 10V2.5M5 5.5l3-3 3 3"/><circle cx="8" cy="13" r="1.5"/>',
  plugin: '<path d="M3 5h3V3.5a1.5 1.5 0 0 1 3 0V5h3v3h-1.5a1.5 1.5 0 0 0 0 3H12v3H3z"/>',
  warning: '<path d="M8 2l6.5 11.5h-13z"/><path d="M8 6.5v3M8 11.5v.5"/>',
  star: '<path d="M8 2l1.8 3.8 4.2.5-3.1 2.9.8 4.1L8 11.3l-3.7 2 .8-4.1L2 6.3l4.2-.5z"/>',
};

export function icon(name) {
  const i = document.createElement('i');
  i.className = 'ico';
  i.innerHTML = `<svg viewBox="0 0 16 16">${ICONS[name] || ICONS.file}</svg>`;
  return i;
}

/** An icon in a colour of the theme (green Play, red Stop...). */
export function colorIcon(name, color) {
  const i = icon(name);
  i.classList.add(color);
  return i;
}

/** Fills every <i data-icon="..."> of a root with its SVG. */
export function hydrateIcons(root = document) {
  for (const i of root.querySelectorAll('i[data-icon]')) {
    if (!i.firstChild) i.innerHTML = `<svg viewBox="0 0 16 16">${ICONS[i.dataset.icon] || ''}</svg>`;
  }
}

/** el('div.row.sel', {onclick, title}, children...) */
export function el(spec, attrs = {}, ...children) {
  const [tag, ...classes] = spec.split('.');
  const node = document.createElement(tag || 'div');
  if (classes.length) node.className = classes.join(' ');
  for (const [key, value] of Object.entries(attrs || {})) {
    if (value === undefined || value === null || value === false) continue;
    if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
    else if (key === 'style' && typeof value === 'object') Object.assign(node.style, value);
    else if (key === 'dataset') Object.assign(node.dataset, value);
    else if (key in node && key !== 'list') node[key] = value;
    else node.setAttribute(key, value === true ? '' : value);
  }
  for (const child of children.flat()) {
    if (child === null || child === undefined || child === false) continue;
    node.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
  return node;
}

/** A progress bar: ratio 0..1, or null for a moving one (unknown progress). */
export function progressBar(ratio) {
  const known = ratio !== null && ratio !== undefined;
  return el(`span.progress${known ? '' : '.indeterminate'}`, {}, el('i', { style: known ? { width: `${Math.round(100 * Math.max(0, Math.min(1, ratio)))}%` } : {} }));
}

export function clear(node) {
  while (node.firstChild) node.firstChild.remove();
  return node;
}

export function escapeHtml(text) {
  return String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

export function basename(path) { return path.split('/').pop(); }
export function dirname(path) { const i = path.lastIndexOf('/'); return i < 0 ? '' : path.slice(0, i); }
export function extension(path) { const b = basename(path); const i = b.lastIndexOf('.'); return i <= 0 ? '' : b.slice(i + 1).toLowerCase(); }

/** A file icon by extension. */
export function fileIcon(path) {
  const ext = extension(path);
  const map = { php: ['php', 'PHP'], js: ['js', 'JS'], mjs: ['js', 'JS'], ts: ['js', 'TS'], json: ['json', '{}'], tmj: ['json', '{}'], tsj: ['json', '{}'],
    html: ['html', '<>'], htm: ['html', '<>'], css: ['css', '#'], scss: ['css', '#'], yaml: ['yaml', 'Y'], yml: ['yaml', 'Y'], neon: ['yaml', 'N'],
    md: ['md', 'M↓'], png: ['img', '▣'], jpg: ['img', '▣'], jpeg: ['img', '▣'], gif: ['img', '▣'], webp: ['img', '▣'], svg: ['img', '▣'], ico: ['img', '▣'],
    wav: ['snd', '♪'], ogg: ['snd', '♪'], mp3: ['snd', '♪'], sh: ['sh', '$'], xml: ['html', '<>'], txt: ['txt', 'T'] };
  const [cls, text] = map[ext] || (basename(path) === 'jumphp' || basename(path) === 'Dockerfile' ? ['sh', '$'] : ['txt', '·']);
  return el(`span.fico.${cls}`, {}, text);
}

export function folderIcon() {
  const s = el('span.fico.folder');
  s.innerHTML = '<svg viewBox="0 0 16 16"><path d="M1.5 4a1 1 0 0 1 1-1h3.6l1.5 1.6H13.5a1 1 0 0 1 1 1V12a1 1 0 0 1-1 1h-11a1 1 0 0 1-1-1z"/></svg>';
  return s;
}

/** A symbol icon (class, method...). */
export function symbolIcon(kind) {
  const map = { class: ['cls', 'C'], interface: ['itf', 'I'], trait: ['trt', 'T'], enum: ['enm', 'E'], method: ['mth', 'm'], function: ['fn', 'f'],
    property: ['fld', 'p'], constant: ['cst', 'c'], enumMember: ['cst', 'c'], variable: ['var', 'v'], keyword: ['txt', 'k'] };
  const [cls, text] = map[kind] || ['txt', '?'];
  return el(`span.fico.${cls}`, {}, text);
}

const overlays = () => document.getElementById('overlays');

/** Closes on Escape / click outside. Returns close(). */
function overlay(dim, onClose, backdrop = true) {
  const layer = el(`div.overlay${dim ? '.dim' : ''}`);
  const close = () => {
    layer.remove();
    document.removeEventListener('keydown', onKey, true);
    onClose && onClose();
  };
  // a sticky dialog (Claude's permission request or question) is answered with its buttons: Escape would refuse it with no way back
  const onKey = (e) => { if (e.key === 'Escape' && backdrop) { e.preventDefault(); e.stopPropagation(); close(); } };
  // backdrop false: a click beside it (to take the window back) keeps it; only its buttons / Escape close it
  if (backdrop) layer.addEventListener('mousedown', (e) => { if (e.target === layer) close(); });
  document.addEventListener('keydown', onKey, true);
  overlays().append(layer);
  return { layer, close };
}

/**
 * A popup menu at a position or under an element. items: [{label, icon, kbd, action, disabled, checked, items (sub menu)}, '-', {head}]
 */
export function menu(items, at) {
  const { layer, close } = overlay(false);
  const box = el('div.popup');
  // depth 0 = the menu, 1 = its sub menu: only rows of the menu itself open / close the sub menu
  const build = (list, container, depth = 0) => {
    for (const item of list) {
      if (item === '-') { container.append(el('div.menu-sep')); continue; }
      if (item.head) { container.append(el('div.menu-head', {}, item.head)); continue; }
      const row = el(`div.menu-item${item.disabled ? '.disabled' : ''}`, {
        onclick: (e) => {
          e.stopPropagation();
          if (item.items) { clearTimeout(pending); submenu(item, row); return; }
          close();
          item.action && item.action();
        },
        onmouseenter: () => {
          if (depth > 0) return;
          clearTimeout(pending);
          if (subRow === row) return;
          // a sub menu is open: wait a little, the mouse may only be crossing this row on its way to it
          const go = () => { if (item.items) submenu(item, row); else closeSub(); };
          if (sub) pending = setTimeout(go, 250); else go();
        },
      }, item.checked !== undefined ? el('span.check', {}, item.checked ? '✓' : '') : null,
      item.icon ? (typeof item.icon === 'string' ? icon(item.icon) : item.icon) : null,
      el('span', {}, item.label), item.items ? el('span.sub') : null, item.kbd ? el('span.kbd', {}, item.kbd) : null);
      container.append(row);
    }
  };
  let sub = null;
  let subRow = null;
  let pending = 0;
  const closeSub = () => {
    if (sub) { sub.remove(); sub = null; }
    if (subRow) { subRow.classList.remove('sel'); subRow = null; }
  };
  const submenu = (item, row) => {
    closeSub();
    sub = el('div.popup', { onmouseenter: () => clearTimeout(pending) });
    build(item.items, sub, 1);
    layer.append(sub);
    subRow = row;
    row.classList.add('sel');
    const r = row.getBoundingClientRect();
    // beside the row; on the left when there is no room on the right
    const x = r.right + sub.offsetWidth + 4 > innerWidth ? r.left - sub.offsetWidth - 2 : r.right + 2;
    place(sub, { x, y: r.top - 4 });
  };
  build(items, box);
  layer.append(box);
  if (at instanceof Element) {
    const r = at.getBoundingClientRect();
    place(box, { x: r.left, y: r.bottom + 4 });
  } else {
    place(box, at);
  }
  return close;
}

function place(box, { x, y }) {
  box.style.left = '0px';
  box.style.top = '0px';
  const w = box.offsetWidth;
  const h = box.offsetHeight;
  box.style.left = Math.max(4, Math.min(x, innerWidth - w - 4)) + 'px';
  box.style.top = Math.max(4, Math.min(y, innerHeight - h - 4)) + 'px';
}

/**
 * A filterable list popup (branches, run configurations, recent files...). items: [{label, hint, icon, action, group}]
 */
// items: a list (filtered by what is typed), or a function of the text typed that gives its own list. An item with
// `complete` puts that text in the field (and keeps the list open) instead of running an action.
export function chooser(items, { at, placeholder = 'Type to filter', width = 420, footer } = {}) {
  const { layer, close } = overlay(false);
  const box = el('div.popup', { style: { width: width + 'px', padding: '0', display: 'flex', flexDirection: 'column' } });
  const input = el('input.input', { placeholder, style: { border: '0', borderBottom: '1px solid var(--border-2)', borderRadius: '8px 8px 0 0', padding: '8px 10px', boxShadow: 'none' } });
  const list = el('div', { style: { overflow: 'auto', maxHeight: '50vh', padding: '4px' } });
  let shown = [];
  let selected = 0;
  const render = () => {
    clear(list);
    const q = input.value.toLowerCase();
    shown = typeof items === 'function' ? items(input.value) : items.filter((i) => i === '-' || !q || (i.label + ' ' + (i.hint || '')).toLowerCase().includes(q));
    let group = null;
    shown.forEach((item, n) => {
      if (item === '-') return;
      if (item.group && item.group !== group) { group = item.group; list.append(el('div.menu-head', {}, group)); }
      list.append(el(`div.menu-item${n === selected ? '.sel' : ''}`, {
        onclick: () => pick(item),
      }, item.icon ? (typeof item.icon === 'string' ? icon(item.icon) : item.icon) : null, el('span', {}, item.label), item.hint ? el('span.kbd', {}, item.hint) : null));
    });
    if (!shown.filter((i) => i !== '-').length) list.append(el('div.placeholder', {}, 'Nothing found'));
  };
  const pick = (item) => {
    if (typeof item.complete === 'string') { input.value = item.complete; selected = 0; render(); input.focus(); return; }
    close();
    item.action && item.action();
  };
  input.addEventListener('input', () => { selected = 0; render(); });
  input.addEventListener('keydown', (e) => {
    const real = shown.map((s, i) => (s === '-' ? -1 : i)).filter((i) => i >= 0);
    const pos = real.indexOf(selected);
    if (e.key === 'ArrowDown') { selected = real[Math.min(real.length - 1, pos + 1)] ?? 0; render(); e.preventDefault(); }
    if (e.key === 'ArrowUp') { selected = real[Math.max(0, pos - 1)] ?? 0; render(); e.preventDefault(); }
    if (e.key === 'Enter') { const item = shown[selected]; if (item && item !== '-') pick(item); }
    if (e.key === 'Tab') { const item = shown[selected]; if (item && typeof item.complete === 'string') { e.preventDefault(); pick(item); } }
  });
  box.append(input, list);
  if (footer) box.append(el('div.se-footer', {}, footer));
  layer.append(box);
  render();
  if (at instanceof Element) { const r = at.getBoundingClientRect(); place(box, { x: r.left, y: r.bottom + 4 }); }
  else place(box, at || { x: innerWidth / 2 - width / 2, y: innerHeight * 0.15 });
  input.focus();
  return close;
}

/**
 * A modal dialog. buttons: [{label, primary, danger, action(dialog) -> false keeps it open}]. Returns {close, body}.
 * sticky: a click outside does not close it (questions that wait for an answer: Claude's permissions...).
 */
export function dialog({ title, body, buttons = [{ label: 'Close' }], wide = false, onClose, sticky = false }) {
  const { layer, close } = overlay(true, onClose, !sticky);
  const box = el(`div.dialog${wide ? '.wide' : ''}`);
  const content = el('div.dialog-body');
  if (body instanceof Node) content.append(body); else if (body) content.innerHTML = body;
  const foot = el('div.dialog-foot');
  const api = { close, body: content, box };
  for (const b of buttons) {
    foot.append(el(`button.btn${b.primary ? '.primary' : ''}${b.danger ? '.danger' : ''}`, {
      onclick: async (e) => {
        const button = e.currentTarget;
        if (b.action) {
          button.disabled = true;
          try {
            if ((await b.action(api)) === false) { button.disabled = false; return; }
          } catch (err) { button.disabled = false; notify(err.message || String(err), 'error'); return; }
        }
        close();
      },
    }, b.label));
  }
  box.append(el('div.dialog-head', {}, title || ''), content, foot);
  layer.append(box);
  const first = content.querySelector('input, textarea, select');
  setTimeout(() => (first || foot.querySelector('.primary'))?.focus(), 0);
  box.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target.tagName === 'INPUT') foot.querySelector('.btn.primary')?.click();
  });
  return api;
}

export function prompt(title, { value = '', label = '', placeholder = '', ok = 'OK', select } = {}) {
  return new Promise((resolve) => {
    const input = el('input.input', { value, placeholder });
    let done = false;
    dialog({
      title,
      body: el('div', {}, label ? el('p', {}, label) : null, input),
      buttons: [{ label: 'Cancel' }, { label: ok, primary: true, action: () => { done = true; resolve(input.value); } }],
      onClose: () => { if (!done) resolve(null); },
    });
    setTimeout(() => {
      input.focus();
      if (select) input.setSelectionRange(...select); else input.select();
    }, 10);
  });
}

export function confirm(title, text, { ok = 'OK', danger = false } = {}) {
  return new Promise((resolve) => {
    let done = false;
    dialog({
      title, body: el('p', {}, text),
      buttons: [{ label: 'Cancel' }, { label: ok, primary: !danger, danger, action: () => { done = true; resolve(true); } }],
      onClose: () => { if (!done) resolve(false); },
    });
  });
}

const history = [];

/** A notification (balloon) in the corner; kept in the notifications list. */
export function notify(text, kind = 'info', { title, actions = [], timeout } = {}) {
  let box = document.querySelector('.notifications');
  if (!box) { box = el('div.notifications'); document.body.append(box); }
  history.unshift({ text, kind, title, time: new Date() });
  history.length = Math.min(history.length, 100);
  const toast = el(`div.toast.${kind}`, {}, el('div.t-body', {},
    title ? el('div.t-title', {}, title) : null, el('div.t-text', {}, text),
    actions.length ? el('div.t-actions', {}, actions.map((a) => el('a', { onclick: () => { toast.remove(); a.action(); } }, a.label))) : null),
  el('button.icon-btn', { onclick: () => toast.remove() }, icon('close')));
  box.append(toast);
  const ms = timeout ?? (kind === 'error' ? 12000 : 5000);
  if (ms) setTimeout(() => toast.remove(), ms);
  return toast;
}

export function notificationHistory() { return history; }

/** Makes a splitter resize a CSS variable (px) of the app. */
export function splitter(handle, variable, { axis = 'x', invert = false, min = 120, max = 1200, onEnd } = {}) {
  handle.addEventListener('mousedown', (e) => {
    e.preventDefault();
    const root = document.documentElement;
    const start = axis === 'x' ? e.clientX : e.clientY;
    const initial = parseFloat(getComputedStyle(root).getPropertyValue(variable)) || 280;
    handle.classList.add('drag');
    const move = (ev) => {
      const delta = (axis === 'x' ? ev.clientX : ev.clientY) - start;
      const size = Math.max(min, Math.min(max, initial + (invert ? -delta : delta)));
      root.style.setProperty(variable, size + 'px');
    };
    const up = () => {
      handle.classList.remove('drag');
      removeEventListener('mousemove', move);
      removeEventListener('mouseup', up);
      onEnd && onEnd();
    };
    addEventListener('mousemove', move);
    addEventListener('mouseup', up);
  });
}

/** Remembered settings of the interface (localStorage, per viewer). */
export const prefs = {
  get(key, fallback) {
    try { const v = localStorage.getItem('jumphp-ide:' + key); return v === null ? fallback : JSON.parse(v); } catch { return fallback; }
  },
  set(key, value) {
    try { localStorage.setItem('jumphp-ide:' + key, JSON.stringify(value)); } catch { /* private mode */ }
  },
};

/**
 * Text to the system clipboard. The desktop IDE's WebView has no navigator.clipboard: the native one (native.copy)
 * there; in the web IDE the browser's, else the old way through a hidden text field.
 */
export async function copyText(text) {
  text = String(text ?? '');
  if (window.jumphp?.native) {
    try { await window.jumphp.invoke('native.copy', { text }); return true; } catch { /* the browser's ways below */ }
  }
  try { await navigator.clipboard.writeText(text); return true; } catch { /* below */ }
  const area = el('textarea', { style: { position: 'fixed', opacity: '0' } });
  area.value = text;
  document.body.append(area);
  area.select();
  const ok = document.execCommand('copy');
  area.remove();
  return ok;
}

/** Text of the system clipboard (null when it cannot be read). */
export async function pasteText() {
  if (window.jumphp?.native) {
    try { return String(await window.jumphp.invoke('native.paste') ?? ''); } catch { /* below */ }
  }
  return navigator.clipboard?.readText?.().catch(() => null) ?? null;
}

export function debounce(fn, ms) {
  let t;
  return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}

export function timeAgo(seconds) {
  const d = Date.now() / 1000 - seconds;
  if (d < 60) return 'just now';
  if (d < 3600) return Math.floor(d / 60) + ' min ago';
  if (d < 86400) return Math.floor(d / 3600) + ' h ago';
  if (d < 86400 * 30) return Math.floor(d / 86400) + ' days ago';
  return new Date(seconds * 1000).toLocaleDateString();
}

/** Keyboard shortcut text for the platform. */
export function kbd(text) {
  return navigator.platform.startsWith('Mac') ? text.replace(/Ctrl\+/g, '⌘').replace(/Alt\+/g, '⌥').replace(/Shift\+/g, '⇧') : text;
}
