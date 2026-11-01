// The visual editor of forms: the windows of graphic PHP apps (JumPHP\Gui, no HTML), forms/<Name>.form.yaml.
// Widgets are dropped from the palette, moved by dragging, set in the inspector; containers lay them out like the
// engine does (column, row, grid of 12 columns with spans per screen width, stack), spacers take the free room.
// A double click on a widget wires its first event: the method is written into the form's class (the code behind),
// the form file names it, and the editor opens there, as in the visual tools of old.
//
//   const d = new FormDesigner(container, {path, text, backend, writeFile, openFile, onChange, run});
//   d.setText(text); d.destroy();
//
// The file stays the source of truth: it is read and written by the IDE's PHP core (gui.read / gui.dump), so what
// is saved is plain YAML that reads well by hand.

const ICONS = {
  column: 'M3 2h10v4H3z M3 10h10v4H3z',
  row: 'M2 3h4v10H2z M10 3h4v10h-4z',
  grid: 'M2 2h5v5H2z M9 2h5v5H9z M2 9h5v5H2z M9 9h5v5H9z',
  panel: 'M2 3h12v10H2z M2 6h12',
  stack: 'M3 5h8v8H3z M5 3h8v8',
  spacer: 'M8 2v12 M5 5l3-3 3 3 M5 11l3 3 3-3',
  separator: 'M2 8h12',
  label: 'M5 4h6 M8 4v9',
  button: 'M2 5h12v6H2z M6 8h4',
  textbox: 'M2 5h12v6H2z M4 7v2',
  checkbox: 'M3 3h10v10H3z M5 8l2 2 4-4',
  radio: 'M8 3a5 5 0 100 10 5 5 0 000-10z M8 6.5a1.5 1.5 0 100 3 1.5 1.5 0 000-3z',
  combobox: 'M2 5h12v6H2z M10 7l1.5 1.5L13 7',
  listbox: 'M2 2h12v12H2z M4 5h8 M4 8h8 M4 11h5',
  slider: 'M2 8h12 M6 5v6',
  progressbar: 'M2 6h12v4H2z M2 6h7v4H2z',
  image: 'M2 3h12v10H2z M2 11l4-4 3 3 2-2 3 3',
  video: 'M2 4h9v8H2z M11 7l3-2v6l-3-2',
  undo: 'M5 3L2 6l3 3 M2 6h8a4 4 0 010 8H7',
  redo: 'M11 3l3 3-3 3 M14 6H6a4 4 0 000 8h3',
  desktop: 'M2 3h12v8H2z M6 14h4 M8 11v3',
  laptop: 'M3 4h10v6H3z M1 12h14',
  tablet: 'M4 2h8v12H4z M7.5 12h1',
  phone: 'M5 2h6v12H5z M7.5 12h1',
  android: 'M5 2h6v12H5z M6.5 3.5h3',
  minus: 'M4 8h8',
  plus: 'M4 8h8 M8 4v8',
  up: 'M8 13V3 M4 7l4-4 4 4',
  down: 'M8 3v10 M4 9l4 4 4-4',
  copy: 'M5 5h8v8H5z M3 11V3h8',
  trash: 'M3 4h10 M6 4V2.5h4V4 M4.5 4l.7 9.5h5.6l.7-9.5',
  code: 'M6 4L2 8l4 4 M10 4l4 4-4 4',
  play: 'M5 3l8 5-8 5z',
  chevron: 'M6 4l4 4-4 4',
  form: 'M2 2h12v12H2z M2 5h12',
  dark: 'M10 2a6 6 0 104 10A5 5 0 0110 2z',
};

// what the palette makes: type, label, icon, the properties a new one starts with
const COMPONENTS = [
  { group: 'Layout', type: 'column', label: 'Column', make: () => ({ type: 'column', children: [] }) },
  { group: 'Layout', type: 'row', label: 'Row', make: () => ({ type: 'row', children: [] }) },
  { group: 'Layout', type: 'grid', label: 'Grid', make: () => ({ type: 'grid', children: [] }) },
  { group: 'Layout', type: 'panel', label: 'Panel', make: () => ({ type: 'panel', title: 'Group', children: [] }) },
  { group: 'Layout', type: 'stack', label: 'Stack', make: () => ({ type: 'stack', children: [] }) },
  { group: 'Layout', type: 'spacer', label: 'Spacer', make: () => ({ type: 'spacer' }) },
  { group: 'Layout', type: 'separator', label: 'Separator', make: () => ({ type: 'separator' }) },
  { group: 'Controls', type: 'label', label: 'Label', make: () => ({ type: 'label', text: 'Label' }) },
  { group: 'Controls', type: 'button', label: 'Button', make: () => ({ type: 'button', text: 'Button' }) },
  { group: 'Controls', type: 'textbox', label: 'Text box', make: () => ({ type: 'textbox', placeholder: '' }) },
  { group: 'Controls', type: 'checkbox', label: 'Check box', make: () => ({ type: 'checkbox', text: 'Option' }) },
  { group: 'Controls', type: 'radio', label: 'Radio', make: () => ({ type: 'radio', text: 'Choice' }) },
  { group: 'Controls', type: 'combobox', label: 'Combo box', make: () => ({ type: 'combobox', items: ['First', 'Second', 'Third'] }) },
  { group: 'Controls', type: 'listbox', label: 'List box', make: () => ({ type: 'listbox', items: ['First', 'Second', 'Third'] }) },
  { group: 'Controls', type: 'slider', label: 'Slider', make: () => ({ type: 'slider', value: 50 }) },
  { group: 'Controls', type: 'progressbar', label: 'Progress', make: () => ({ type: 'progressbar', value: 30 }) },
  { group: 'Media', type: 'image', label: 'Image', make: () => ({ type: 'image', src: '' }) },
  { group: 'Media', type: 'video', label: 'Video', make: () => ({ type: 'video', src: '' }) },
];

const CONTAINERS = { container: 'column', column: 'column', row: 'row', grid: 'grid', panel: 'column', stack: 'stack' };
// as JumPHP\Gui\*::events(): the first is the one a double click wires
const EVENTS = {
  label: ['click'], button: ['click'], textbox: ['change', 'submit', 'click'], checkbox: ['change', 'click'], radio: ['change', 'click'],
  combobox: ['change', 'click'], listbox: ['change', 'activate', 'click'], slider: ['change'], progressbar: ['click'],
  image: ['click'], video: ['end', 'click'], column: ['click'], row: ['click'], grid: ['click'], panel: ['click'], stack: ['click'], container: ['click'],
  spacer: [], separator: [],
};
// widgets that keep their own width in a column (the others take its width)
const OWN_WIDTH = new Set(['button', 'checkbox', 'radio', 'spacer']);
const BREAKPOINTS = [['xs', 0], ['sm', 600], ['md', 900], ['lg', 1200]];
const DEVICES = {
  desktop: { label: 'Desktop', short: 'PC', width: 1280, height: 800 },
  laptop: { label: 'Laptop', short: 'Laptop', width: 1024, height: 700 },
  tablet: { label: 'Tablet', short: 'Tablet', width: 800, height: 1100, frame: 'tablet' },
  phone: { label: 'iPhone', short: 'iPhone', width: 390, height: 844, frame: 'ios' },
  android: { label: 'Android', short: 'Android', width: 412, height: 892, frame: 'android' },
};
const THEMES = {
  light: { background: '#f3f4f7', surface: '#ffffff', text: '#1f2329', muted: '#6b7280', border: '#cfd4dc', accent: '#3b6ef5', accentText: '#ffffff', danger: '#d64545', track: '#dde1e8', selection: '#cfdcff', hover: '#e8ecf3' },
  dark: { background: '#1e1f22', surface: '#2b2d31', text: '#e6e7ea', muted: '#9aa0aa', border: '#43464d', accent: '#5b8cff', accentText: '#ffffff', danger: '#ef6464', track: '#3a3d43', selection: '#33476e', hover: '#35383e' },
};

// the inspector: [property, label, control, options]
const PROPS = {
  label: [['text', 'Text', 'textarea'], ['style', 'Style', 'select', ['', 'title', 'subtitle', 'muted', 'small']], ['textAlign', 'Align', 'select', ['left', 'center', 'right']],
    ['size', 'Size (dp)', 'number'], ['bold', 'Bold', 'check'], ['wrap', 'Wrap', 'check', true], ['color', 'Colour', 'color']],
  button: [['text', 'Text', 'text'], ['style', 'Style', 'select', ['default', 'primary', 'danger', 'flat']], ['image', 'Image', 'image'],
    ['default', 'Return clicks it', 'check'], ['cancel', 'Escape clicks it', 'check']],
  textbox: [['text', 'Text', 'text'], ['placeholder', 'Placeholder', 'text'], ['password', 'Password', 'check'], ['multiline', 'Several lines', 'check'],
    ['rows', 'Rows', 'number'], ['maxLength', 'Max length', 'number'], ['readonly', 'Read only', 'check']],
  checkbox: [['text', 'Text', 'text'], ['checked', 'Checked', 'check']],
  radio: [['text', 'Text', 'text'], ['checked', 'Checked', 'check'], ['group', 'Group', 'text']],
  combobox: [['items', 'Items (one a line)', 'lines'], ['selected', 'Selected (index)', 'number'], ['placeholder', 'Placeholder', 'text']],
  listbox: [['items', 'Items (one a line)', 'lines'], ['selected', 'Selected (index)', 'number']],
  slider: [['value', 'Value', 'number'], ['min', 'Min', 'number'], ['max', 'Max', 'number'], ['step', 'Step', 'number']],
  progressbar: [['value', 'Value', 'number'], ['max', 'Max', 'number'], ['showText', 'Show %', 'check']],
  image: [['src', 'Picture', 'image'], ['fit', 'Fit', 'select', ['contain', 'cover', 'fill', 'none']]],
  video: [['src', 'Frames folder', 'text'], ['fps', 'Frames a second', 'number'], ['audio', 'Sound file', 'text'], ['autoplay', 'Autoplay', 'check', true],
    ['loop', 'Loop', 'check'], ['controls', 'Controls', 'check', true], ['fit', 'Fit', 'select', ['contain', 'cover', 'fill']]],
  spacer: [['size', 'Size (dp, empty: grows)', 'number']],
  separator: [],
};
const CONTAINER_PROPS = [['title', 'Title', 'text'], ['layout', 'Layout', 'select', ['column', 'row', 'grid', 'stack']], ['gap', 'Gap (dp)', 'number'],
  ['padding', 'Padding (dp)', 'number'], ['items', 'Align children', 'select', ['', 'stretch', 'start', 'center', 'end']],
  ['justify', 'Justify', 'select', ['', 'start', 'center', 'end', 'between', 'around']], ['wrap', 'Wrap (row)', 'check'], ['columns', 'Columns (grid)', 'number'],
  ['border', 'Border', 'check'], ['background', 'Background', 'color'], ['scroll', 'Scrolls', 'check']];
const LAYOUT_PROPS = [['width', 'Width', 'size'], ['height', 'Height', 'size'], ['grow', 'Grow', 'number'], ['align', 'Align itself', 'select', ['', 'stretch', 'start', 'center', 'end']],
  ['minWidth', 'Min width', 'number'], ['minHeight', 'Min height', 'number'], ['tooltip', 'Tooltip', 'text'], ['visible', 'Visible', 'check', true], ['enabled', 'Enabled', 'check', true]];
const FORM_PROPS = [['title', 'Window title', 'text'], ['theme', 'Theme', 'select', ['light', 'dark']], ['accent', 'Accent colour', 'color'],
  ['layout', 'Layout', 'select', ['column', 'row', 'grid', 'stack']], ['gap', 'Gap (dp)', 'number'], ['padding', 'Padding (dp)', 'number'],
  ['items', 'Align children', 'select', ['', 'stretch', 'start', 'center', 'end']], ['justify', 'Justify', 'select', ['', 'start', 'center', 'end', 'between']],
  ['fontSize', 'Text size (dp)', 'number'], ['zoom', 'Zoom', 'number'], ['dialog', 'Dialog (over a window)', 'check'], ['width', 'Dialog width (dp)', 'number']];

const IMAGE_EXT = /\.(png|jpe?g|gif|webp|bmp)$/i;

function svg(name, size = 16) {
  return `<svg viewBox="0 0 16 16" width="${size}" height="${size}" aria-hidden="true"><path d="${ICONS[name] || ''}"/></svg>`;
}

function esc(text) {
  return String(text ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}

function el(tag, attrs = {}, children = []) {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs)) {
    if (value == null || value === false) continue;
    if (key === 'class') node.className = value;
    else if (key === 'html') node.innerHTML = value;
    else if (key === 'text') node.textContent = value;
    else if (key === 'style' && typeof value === 'object') Object.assign(node.style, value);
    else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
    else node.setAttribute(key, value === true ? '' : value);
  }
  for (const child of [].concat(children)) if (child != null) node.append(child);
  return node;
}

function debounce(fn, ms) {
  let timer = null;
  const wrapped = (...args) => { clearTimeout(timer); timer = setTimeout(() => { timer = null; fn(...args); }, ms); };
  wrapped.flush = () => { if (timer) { clearTimeout(timer); timer = null; return fn(); } return undefined; };
  return wrapped;
}

/** The method a double click writes: onSaveClick (as JumPHP\Gui\FormLoader::handlerName). */
export function handlerName(id, event) {
  return 'on' + `${id} ${event}`.split(/[^A-Za-z0-9]+/).filter(Boolean).map((w) => w[0].toUpperCase() + w.slice(1)).join('');
}

/** The breakpoint of a width in dp, as JumPHP\Gui\Container::breakpoint(). */
export function breakpointOf(width) {
  let found = 'xs';
  for (const [name, min] of BREAKPOINTS) if (width >= min) found = name;
  return found;
}

/** The columns a child takes in a grid at a breakpoint, as Container::spanOf(). */
export function spanOf(child, breakpoint, columns = 12) {
  let span = child.span ?? columns;
  if (span && typeof span === 'object') {
    let found = columns;
    for (const [name] of BREAKPOINTS) {
      if (span[name] != null) found = span[name];
      if (name === breakpoint) break;
    }
    span = found;
  }
  return Math.max(1, Math.min(columns, parseInt(span, 10) || columns));
}

const isContainer = (node) => !!node && CONTAINERS[node.type] !== undefined;
const layoutOf = (node) => node.layout || CONTAINERS[node.type] || 'column';
const dirOf = (path) => (path.includes('/') ? path.slice(0, path.lastIndexOf('/')) : '');

export class FormDesigner {
  constructor(container, options = {}) {
    this.container = container;
    this.path = options.path || 'forms/MainForm.form.yaml';
    this.backend = options.backend;
    this.writeFile = options.writeFile || (async () => {});
    this.openFile = options.openFile || (() => {});
    this.onChangeHandler = options.onChange || (() => {});
    this.run = options.run || null;
    this.app = this.path.includes('/forms/') ? this.path.slice(0, this.path.indexOf('/forms/')) : dirOf(dirOf(this.path));
    this.form = { children: [] };
    this.header = '';
    this.selected = null;
    this.parents = new Map();
    this.nodes = new Map();
    this.undoStack = [];
    this.redoStack = [];
    this.device = 'laptop';
    this.width = DEVICES.laptop.width;
    this.zoom = 1;
    this.leftTab = 'components';
    this.dragging = null;
    this.images = null;
    this.imageCache = new Map();
    this.closedSections = new Set();
    this.destroyed = false;
    this.lastText = null;
    this.save = debounce(() => this.write(), 250);
    this.syncProperties = debounce(() => this.writeProperties(), 1200);
    this.buildUi();
    this.setText(options.text || '');
  }

  // public API ------------------------------------------------------------------------------------------------

  /** Shows a form file's text (opened, or changed in the code view). */
  async setText(text) {
    if (text === this.lastText) return;
    try {
      const parsed = await this.backend.invoke('gui.read', { text });
      const form = parsed.json ? JSON.parse(parsed.json) : parsed.form;     // the JSON string keeps the keys in the file's order
      this.form = form && typeof form === 'object' && !Array.isArray(form) ? form : { children: [] };
      this.form.children ||= [];
      this.header = parsed.header || '';
      this.lastText = text;
      this.error = null;
      if (!this.undoStack.length) this.undoStack.push(this.snapshot());
      if (this.selected && !this.contains(this.selected)) this.selected = null;
    } catch (e) {
      this.error = e.message;
    }
    this.render();
  }

  destroy() {
    this.save.flush();
    this.syncProperties.flush();
    this.destroyed = true;
    this.root.remove();
  }

  undo() {
    if (this.undoStack.length < 2) return;
    this.redoStack.push(this.undoStack.pop());
    this.restore(this.undoStack[this.undoStack.length - 1]);
  }

  redo() {
    if (!this.redoStack.length) return;
    const state = this.redoStack.pop();
    this.undoStack.push(state);
    this.restore(state);
  }

  // the model -------------------------------------------------------------------------------------------------

  snapshot() {
    return JSON.stringify(this.form);
  }

  restore(state) {
    const path = this.selected ? this.pathOf(this.selected) : null;
    this.form = JSON.parse(state);
    this.indexTree();
    this.selected = path ? this.nodeAt(path) : null;
    this.render();
    this.save();
    this.syncProperties();
  }

  /** Something changed: saved (debounced), one undo step. */
  changed(render = true) {
    this.undoStack.push(this.snapshot());
    if (this.undoStack.length > 200) this.undoStack.shift();
    this.redoStack = [];
    if (render) this.render();
    else this.renderCanvas();
    this.save();
    this.syncProperties();
  }

  async write() {
    if (this.destroyed && !this.form) return;
    try {
      const { content } = await this.backend.invoke('gui.dump', { json: JSON.stringify(this.form), header: this.header });
      if (content !== this.lastText) {
        this.lastText = content;
        this.onChangeHandler(content);
      }
    } catch (e) {
      this.status(e.message, true);
    }
  }

  /** The ids of the widgets, as @property lines of the form's class (completion, phpstan). */
  async writeProperties() {
    const widgets = [];
    this.walk(this.form, (node) => { if (node !== this.form && node.id) widgets.push([node.id, node.type || 'container']); });
    const key = JSON.stringify(widgets);
    if (key === this.lastProperties) return;
    const first = this.lastProperties === undefined;
    this.lastProperties = key;
    if (first) return;           // opening a form changes nothing in its class
    try {
      const r = await this.backend.invoke('gui.code', { path: this.path, widgets });
      if (!r.created) await this.writeFile(r.path, r.content);
    } catch (e) {
      this.status(e.message, true);
    }
  }

  walk(node, fn, parent = null) {
    fn(node, parent);
    for (const child of node.children || []) this.walk(child, fn, node);
  }

  indexTree() {
    this.parents = new Map();
    this.walk(this.form, (node, parent) => { if (parent) this.parents.set(node, parent); });
  }

  contains(node) {
    let found = false;
    this.walk(this.form, (n) => { if (n === node) found = true; });
    return found;
  }

  pathOf(node) {
    const path = [];
    for (let n = node; this.parents.get(n); n = this.parents.get(n)) path.unshift(this.parents.get(n).children.indexOf(n));
    return path;
  }

  nodeAt(path) {
    let node = this.form;
    for (const i of path) node = node?.children?.[i];
    return node || null;
  }

  /** A free id for a new widget: button1, button2... */
  freeId(type) {
    const used = new Set();
    this.walk(this.form, (n) => n.id && used.add(n.id));
    const base = type === 'textbox' ? 'text' : type;
    for (let i = 1; ; i++) if (!used.has(base + i)) return base + i;
  }

  insert(node, target, place) {
    // place: 'inside' (the end of a container), 'before', 'after'
    if (!node.id && !['spacer', 'separator'].includes(node.type)) node.id = this.freeId(node.type);
    let parent;
    let index;
    if (!target || target === this.form) { parent = this.form; index = parent.children.length; }
    else if (place === 'inside' && isContainer(target)) { parent = target; parent.children ||= []; index = parent.children.length; }
    else { parent = this.parents.get(target) || this.form; index = parent.children.indexOf(target) + (place === 'after' ? 1 : 0); }
    parent.children ||= [];
    parent.children.splice(index, 0, node);
    this.indexTree();
    this.selected = node;
    this.changed();
  }

  remove(node) {
    const parent = this.parents.get(node);
    if (!parent) return;
    const i = parent.children.indexOf(node);
    parent.children.splice(i, 1);
    this.indexTree();
    this.selected = parent.children[Math.min(i, parent.children.length - 1)] || (parent === this.form ? null : parent);
    this.changed();
  }

  move(node, target, place) {
    if (node === target) return;
    for (let p = target; p; p = this.parents.get(p)) if (p === node) return;    // not into itself
    const parent = this.parents.get(node);
    parent.children.splice(parent.children.indexOf(node), 1);
    this.indexTree();
    const id = node.id;
    this.insert(node, target, place);
    node.id = id;
  }

  duplicate(node) {
    const copy = JSON.parse(JSON.stringify(node));
    const used = new Set();
    this.walk(this.form, (n) => n.id && used.add(n.id));
    this.walk(copy, (n) => {
      if (!n.id) return;
      const base = n.id.replace(/\d+$/, '');
      let i = 1;
      while (used.has(base + i)) i++;
      n.id = base + i;
      used.add(n.id);
      if (n.events) n.events = {};        // a copy calls nothing until it is wired
    });
    this.insert(copy, node, 'after');
  }

  set(node, prop, value, render = false) {
    if (value === '' || value === null || value === undefined) delete node[prop];
    else node[prop] = value;
    this.changed(render);
  }

  // interface -------------------------------------------------------------------------------------------------

  buildUi() {
    this.root = el('div', { class: 'dz-root fd-root', tabindex: '-1' });
    const left = el('div', { class: 'dz-left' });
    this.tabs = el('div', { class: 'dz-tabs' }, [
      el('button', { class: 'dz-tab', 'data-tab': 'components', text: 'Widgets', onclick: () => this.showTab('components') }),
      el('button', { class: 'dz-tab', 'data-tab': 'layers', text: 'Layers', onclick: () => this.showTab('layers') }),
    ]);
    this.palette = el('div', { class: 'dz-palette' });
    this.layers = el('div', { class: 'dz-layers' });
    left.append(this.tabs, this.palette, this.layers);
    let group = null;
    for (const c of COMPONENTS) {
      if (c.group !== group) { group = c.group; this.palette.append(el('div', { class: 'dz-heading', text: group })); }
      this.palette.append(el('div', {
        class: 'dz-component', draggable: 'true', title: 'Drag it onto the form, or click: after the selection',
        html: `${svg(c.type)}<span>${esc(c.label)}</span>`,
        onclick: () => this.insertNew(c),
        ondragstart: (e) => { this.dragging = { make: c.make }; e.dataTransfer.effectAllowed = 'copy'; e.dataTransfer.setData('text/plain', c.label); },
        ondragend: () => this.endDrag(),
      }));
    }

    const center = el('div', { class: 'dz-center' });
    const tool = (icon, title, onclick, extra = {}) => el('button', { class: 'dz-tool', title, html: svg(icon), onclick, ...extra });
    this.undoButton = tool('undo', 'Undo (Ctrl+Z)', () => this.undo());
    this.redoButton = tool('redo', 'Redo (Ctrl+Shift+Z)', () => this.redo());
    // the screen sizes, with their names: the layout of each (a grid's spans, hidden widgets) shows at once
    this.deviceButtons = Object.entries(DEVICES).map(([id, d]) => el('button', { class: 'dz-tool fd-device-btn', title: `${d.label}: ${d.width} × ${d.height} dp`,
      html: `${svg(id)}<span>${esc(d.short)}</span>`, onclick: () => this.setDevice(id), 'data-device': id }));
    this.zoomLabel = el('button', { class: 'dz-zoom', title: 'Fit / 100%', text: '100%', onclick: () => this.setZoom(this.zoom === 1 ? 'fit' : 1) });
    this.sizeLabel = el('span', { class: 'dz-size' });
    const actions = el('div', { class: 'dz-group' }, [
      tool('up', 'Move up', () => this.nudge(-1)), tool('down', 'Move down', () => this.nudge(1)),
      tool('copy', 'Duplicate (Ctrl+D)', () => this.selected && this.duplicate(this.selected)),
      tool('trash', 'Delete (Del)', () => this.selected && this.remove(this.selected)),
      tool('code', 'Open the code behind (the form\'s class)', () => this.openCode()),
    ]);
    this.toolbar = el('div', { class: 'dz-toolbar' }, [
      el('div', { class: 'dz-group' }, [this.undoButton, this.redoButton]),
      el('div', { class: 'dz-group' }, this.deviceButtons),
      el('div', { class: 'dz-group' }, [tool('minus', 'Zoom out', () => this.setZoom(this.zoom - 0.1)), this.zoomLabel, tool('plus', 'Zoom in', () => this.setZoom(this.zoom + 0.1))]),
      actions,
      this.run ? el('div', { class: 'dz-group' }, [el('button', { class: 'fd-run', html: `${svg('play', 12)}<span>Run</span>`, title: 'Run the app', onclick: () => this.run() })]) : null,
      this.sizeLabel,
    ]);
    this.viewport = el('div', { class: 'dz-viewport fd-viewport' });
    this.stage = el('div', { class: 'fd-stage' });
    this.device$ = el('div', { class: 'fd-device' });
    this.canvas = el('div', { class: 'fd-canvas' });
    this.resizer = el('div', { class: 'fd-resizer', title: 'Drag: the window\'s width' });
    this.dropLine = el('div', { class: 'fd-drop', hidden: true });
    this.device$.append(this.canvas, this.resizer);
    this.stage.append(this.device$, this.dropLine);
    this.viewport.append(this.stage);
    this.statusBar = el('div', { class: 'dz-crumbs' });
    center.append(this.toolbar, this.viewport, this.statusBar);

    const right = el('div', { class: 'dz-right' });
    this.inspector = el('div', { class: 'dz-inspector' });
    right.append(this.inspector);

    this.root.append(left, center, right);
    this.container.append(this.root);
    this.root.addEventListener('keydown', (e) => this.onKey(e));
    this.listenCanvas();
    new ResizeObserver(() => {
      // the first time it has a size: the whole window in sight
      if (!this.fitted && this.viewport.clientWidth > 0) { this.fitted = true; this.setZoom('fit'); } else this.layoutStage();
    }).observe(this.viewport);
    this.showTab('components');
    this.setDevice(this.device);
  }

  showTab(tab) {
    this.leftTab = tab;
    for (const b of this.tabs.children) b.classList.toggle('active', b.dataset.tab === tab);
    this.palette.hidden = tab !== 'components';
    this.layers.hidden = tab !== 'layers';
    if (tab === 'layers') this.renderLayers();
  }

  setDevice(id) {
    this.device = id;
    this.width = DEVICES[id].width;
    for (const b of this.deviceButtons) b.classList.toggle('active', b.dataset.device === id);
    this.device$.dataset.frame = DEVICES[id].frame || 'window';
    this.renderCanvas();
    this.layoutStage();
  }

  setZoom(zoom) {
    if (zoom === 'fit') {
      const d = DEVICES[this.device];
      zoom = Math.min(1, (this.viewport.clientWidth - 64) / this.width, (this.viewport.clientHeight - 64) / d.height);
    }
    this.zoom = Math.round(Math.min(2, Math.max(0.25, zoom)) * 100) / 100;
    this.zoomLabel.textContent = `${Math.round(this.zoom * 100)}%`;
    this.layoutStage();
  }

  layoutStage() {
    const d = DEVICES[this.device];
    this.device$.style.width = `${this.width}px`;
    this.device$.style.height = `${d.height}px`;
    this.device$.style.transform = `scale(${this.zoom})`;
    this.stage.style.width = `${this.width * this.zoom + 64}px`;
    this.stage.style.height = `${d.height * this.zoom + 64}px`;
    const bp = breakpointOf(this.width);
    this.sizeLabel.textContent = `${this.width} × ${d.height} dp · ${bp.toUpperCase()}`;
    this.positionSelection();
  }

  status(text, error = false) {
    this.statusBar.textContent = text;
    this.statusBar.classList.toggle('fd-error', !!error);
  }

  // rendering -------------------------------------------------------------------------------------------------

  render() {
    if (this.destroyed) return;
    this.indexTree();
    this.renderCanvas();
    this.renderInspector();
    if (this.leftTab === 'layers') this.renderLayers();
    this.undoButton.disabled = this.undoStack.length < 2;
    this.redoButton.disabled = !this.redoStack.length;
  }

  renderCanvas() {
    if (this.destroyed) return;
    this.canvas.textContent = '';
    this.nodes = new Map();
    if (this.error) {
      this.canvas.append(el('div', { class: 'fd-error-box', text: 'This form file cannot be read: ' + this.error + '. Fix it in the code view.' }));
      return;
    }
    const t = THEMES[this.form.theme === 'dark' ? 'dark' : 'light'];
    const theme = { ...t, accent: this.form.accent || t.accent };
    this.theme = theme;
    this.bp = breakpointOf(this.width / (parseFloat(this.form.zoom) || 1));
    for (const [k, v] of Object.entries(theme)) this.canvas.style.setProperty(`--fd-${k}`, v);
    this.canvas.style.fontSize = `${parseFloat(this.form.fontSize) || 15}px`;
    this.canvas.style.zoom = parseFloat(this.form.zoom) || '';
    const { title, ...settings } = this.form;     // the window's title is not drawn in it
    const root = this.renderNode({ ...settings, type: 'container', background: this.form.background || theme.background }, null, true);
    root.classList.add('fd-root-node');
    this.nodes.set(this.form, root);
    this.canvas.append(root);
    if (!(this.form.children || []).length) {
      root.append(el('div', { class: 'fd-empty', html: 'Drag widgets here from the left.<br>A double click on one writes its event in the code.' }));
    }
    this.positionSelection();
  }

  /** One widget as HTML that looks like what JumPHP\Gui draws. */
  renderNode(node, parent, isRoot = false) {
    const type = node.type || 'container';
    const box = el('div', { class: `fd-node fd-${type}` });
    if (!isRoot) this.nodes.set(node, box);
    box.__node = isRoot ? this.form : node;
    if (Array.isArray(node.hide) && node.hide.includes(this.bp)) box.classList.add('fd-gone');     // not laid out at this width, as in the app
    else if (node.visible === false) box.classList.add('fd-hidden');
    if (node.enabled === false) box.classList.add('fd-disabled');
    if (node.tooltip) box.title = node.tooltip;
    this.sizeStyles(box, node, parent);
    switch (type) {
      case 'label': {
        const style = node.style || '';
        box.classList.add(`fd-label-${style || 'normal'}`);
        box.textContent = node.text ?? '';
        box.style.textAlign = node.textAlign || 'left';
        if (node.size) box.style.fontSize = `${node.size}px`;
        if (node.bold) box.style.fontWeight = '700';
        if (node.color) box.style.color = node.color;
        if (node.wrap === false) box.style.whiteSpace = 'nowrap';
        break;
      }
      case 'button': {
        box.classList.add(`fd-button-${node.style || 'default'}`);
        if (node.image) box.append(this.picture(node.image, 'fd-button-image'));
        box.append(el('span', { text: node.text ?? 'Button' }));
        break;
      }
      case 'textbox':
        box.classList.toggle('fd-multiline', !!node.multiline);
        if (node.multiline) box.style.minHeight = `${(node.rows || 4) * 1.3 + 1.2}em`;
        box.append(el('span', { class: node.text ? '' : 'fd-placeholder', text: node.text ? (node.password ? '•'.repeat(node.text.length) : node.text) : (node.placeholder || '') }));
        break;
      case 'checkbox':
      case 'radio':
        box.append(el('span', { class: `fd-mark${node.checked ? ' on' : ''}` }), el('span', { text: node.text ?? '' }));
        break;
      case 'combobox': {
        const items = node.items || [];
        const value = items[node.selected ?? -1];
        box.append(el('span', { class: value == null ? 'fd-placeholder' : '', text: value ?? (node.placeholder || 'Choose…') }), el('span', { class: 'fd-arrow' }));
        break;
      }
      case 'listbox':
        if (node.height == null) box.style.height = '160px';
        (node.items || []).forEach((item, i) => box.append(el('div', { class: `fd-line${i === node.selected ? ' on' : ''}`, text: item })));
        break;
      case 'slider': {
        const ratio = Math.max(0, Math.min(1, ((node.value ?? 50) - (node.min ?? 0)) / (((node.max ?? 100) - (node.min ?? 0)) || 1)));
        box.append(el('div', { class: 'fd-track' }, [el('div', { class: 'fd-fill', style: { width: `${ratio * 100}%` } }), el('div', { class: 'fd-knob', style: { left: `${ratio * 100}%` } })]));
        break;
      }
      case 'progressbar': {
        const ratio = Math.max(0, Math.min(1, (node.value ?? 30) / (node.max || 100)));
        box.append(el('div', { class: 'fd-fill', style: { width: `${ratio * 100}%` } }));
        if (node.showText) box.append(el('span', { class: 'fd-percent', text: `${Math.round(ratio * 100)} %` }));
        break;
      }
      case 'image':
        box.append(this.picture(node.src, 'fd-picture', node.fit));
        if (node.width == null && node.height == null) box.style.minHeight = '60px';
        break;
      case 'video':
        box.append(el('div', { class: 'fd-play' }), el('span', { class: 'fd-video-name', text: node.src ? node.src.split('/').pop() : 'VIDEO' }));
        if (node.height == null) box.style.aspectRatio = '16 / 9';
        break;
      case 'spacer':
        if (node.size) { box.style.flex = 'none'; box.style.minWidth = box.style.minHeight = `${node.size}px`; }
        else if (node.grow == null) box.style.flexGrow = '1';
        break;
      case 'separator':
        box.classList.toggle('fd-vertical', parent && layoutOf(parent) === 'row');
        break;
      default:
        this.renderContainer(box, node, isRoot);
    }
    return box;
  }

  renderContainer(box, node, isRoot) {
    const layout = isRoot ? (node.layout || 'column') : layoutOf(node);
    const framed = node.type === 'panel' || node.border || node.title;
    box.classList.add(`fd-layout-${layout}`);
    if (framed) box.classList.add('fd-framed');
    const gap = node.gap ?? (isRoot ? 12 : 8);
    const pad = node.padding ?? (isRoot ? 16 : (node.type === 'panel' ? 12 : 0));
    if (node.background) box.style.background = node.background;
    if (isRoot) { box.style.minHeight = '100%'; box.style.overflow = 'auto'; }
    if (node.title) box.append(el('div', { class: 'fd-title', text: node.title }));
    const inner = node.title ? el('div', { class: 'fd-inner' }) : box;
    if (node.title) box.append(inner);
    inner.style.gap = `${gap}px`;
    inner.style.padding = `${pad}px`;
    if (node.title) inner.style.paddingTop = '0';
    inner.classList.add(`fd-layout-${layout}`);
    const justify = { start: 'flex-start', center: 'center', end: 'flex-end', between: 'space-between', around: 'space-around' }[node.justify] || 'flex-start';
    if (layout === 'row') {
      inner.style.flexWrap = node.wrap ? 'wrap' : 'nowrap';
      inner.style.justifyContent = justify;
    } else if (layout === 'column') {
      inner.style.justifyContent = justify;
    } else if (layout === 'grid') {
      inner.style.gridTemplateColumns = `repeat(${node.columns || 12}, minmax(0, 1fr))`;
      // as the engine: only a grid of one line gives its cells the grid's height
      let used = 0;
      let lines = 1;
      for (const child of node.children || []) {
        if (Array.isArray(child.hide) && child.hide.includes(this.bp)) continue;
        const span = spanOf(child, this.bp, node.columns || 12);
        if (used + span > (node.columns || 12)) { lines++; used = 0; }
        used += span;
      }
      inner.style.alignContent = lines > 1 ? 'start' : 'stretch';
    }
    inner.dataset.items = node.items || 'stretch';
    for (const child of node.children || []) inner.append(this.renderNode(child, { ...node, layout }));
    if (!isRoot && !(node.children || []).length) inner.append(el('div', { class: 'fd-drop-here', text: layout === 'row' ? 'Row: drop widgets here' : layout === 'grid' ? 'Grid: drop widgets here' : 'Drop widgets here' }));
  }

  /** Width, height, grow, align, span: the rules of Container::arrange*(). */
  sizeStyles(box, node, parent) {
    if (!parent) return;
    const layout = layoutOf(parent);
    const items = parent.items || 'stretch';
    const align = node.align || (items === 'stretch' ? (layout === 'row' ? 'center' : 'stretch') : items);
    const own = OWN_WIDTH.has(node.type);
    const cross = { start: 'flex-start', center: 'center', end: 'flex-end', stretch: 'stretch' }[align];
    const num = (v) => (v !== undefined && v !== null && v !== '' && !isNaN(v) ? `${v}px` : null);
    if (num(node.width)) box.style.width = num(node.width);
    if (num(node.height)) box.style.height = num(node.height);
    if (num(node.minWidth)) box.style.minWidth = num(node.minWidth);
    if (num(node.minHeight)) box.style.minHeight = num(node.minHeight);
    const grow = node.grow != null ? parseFloat(node.grow) : 0;
    if (layout === 'row') {
      box.style.flex = `${node.width === 'fill' ? Math.max(1, grow) : grow} ${own ? 0 : 1} auto`;
      box.style.alignSelf = node.height === 'fill' ? 'stretch' : cross;
    } else if (layout === 'column') {
      box.style.flex = `${node.height === 'fill' ? Math.max(1, grow) : grow} 0 auto`;
      if (node.width === 'fill') box.style.alignSelf = 'stretch';
      else if (num(node.width) || own || align !== 'stretch') box.style.alignSelf = align === 'stretch' ? 'flex-start' : cross;
      else box.style.alignSelf = 'stretch';
    } else if (layout === 'grid') {
      box.style.gridColumn = `span ${spanOf(node, this.bp, parent.columns || 12)}`;
      box.style.alignSelf = node.align ? cross : 'stretch';
      if (own && node.width !== 'fill' && !node.align) box.style.justifySelf = 'start';
    } else if (layout === 'stack') {
      box.style.gridArea = '1 / 1';
      box.style.alignSelf = cross;
      if (own) box.style.justifySelf = 'start';
    }
  }

  /** A picture of the project (data URL, read once). */
  picture(src, cls, fit = 'contain') {
    const img = el('img', { class: cls, alt: '', draggable: 'false', style: { objectFit: fit || 'contain' } });
    if (!src) { img.classList.add('fd-no-picture'); return img; }
    const path = src.startsWith('/') ? src : (this.app ? `${this.app}/` : '') + src;
    const cached = this.imageCache.get(path);
    if (cached) img.src = cached;
    else {
      img.classList.add('fd-no-picture');
      this.backend.invoke('file.read', { path }).then((r) => {
        if (!r?.image) return;
        this.imageCache.set(path, r.image);
        img.src = r.image;
        img.classList.remove('fd-no-picture');
      }).catch(() => null);
    }
    return img;
  }

  // selection and drag & drop ---------------------------------------------------------------------------------

  nodeFromEvent(e) {
    for (let n = e.target; n && n !== this.canvas; n = n.parentNode) if (n.__node) return n.__node;
    return null;
  }

  listenCanvas() {
    this.canvas.addEventListener('mousedown', (e) => {
      const node = this.nodeFromEvent(e);
      this.select(node === this.form ? null : node);
      this.root.focus({ preventScroll: true });
    });
    this.canvas.addEventListener('dblclick', (e) => {
      const node = this.nodeFromEvent(e);
      if (!node || node === this.form) this.openCode();
      else this.wire(node);
    });
    this.canvas.addEventListener('mouseover', (e) => {
      const node = this.nodeFromEvent(e);
      for (const n of this.canvas.querySelectorAll('.fd-hover')) n.classList.remove('fd-hover');
      if (node && node !== this.form) this.nodes.get(node)?.classList.add('fd-hover');
    });
    this.canvas.addEventListener('dragstart', (e) => {
      const node = this.nodeFromEvent(e);
      if (!node || node === this.form) return;
      this.dragging = { node };
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', node.id || node.type);
    });
    this.canvas.addEventListener('dragend', () => this.endDrag());
    this.stage.addEventListener('dragover', (e) => {
      if (!this.dragging) return;
      e.preventDefault();
      this.drop = this.dropTarget(e);
      this.showDrop(this.drop);
    });
    this.stage.addEventListener('dragleave', (e) => { if (!this.stage.contains(e.relatedTarget)) this.dropLine.hidden = true; });
    this.stage.addEventListener('drop', (e) => {
      e.preventDefault();
      const drop = this.drop || this.dropTarget(e);
      const dragging = this.dragging;
      this.endDrag();
      if (!dragging || !drop) return;
      if (dragging.node) this.move(dragging.node, drop.target, drop.place);
      else this.insert(dragging.make(), drop.target, drop.place);
    });
    // the window's width, dragged (desktop windows are resizable: the layout follows, as in the app)
    this.resizer.addEventListener('pointerdown', (e) => {
      e.preventDefault();
      const x0 = e.clientX;
      const w0 = this.width;
      const move = (ev) => {
        this.width = Math.round(Math.max(320, Math.min(2560, w0 + (ev.clientX - x0) / this.zoom)));
        this.renderCanvas();
        this.layoutStage();
      };
      const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); };
      window.addEventListener('pointermove', move);
      window.addEventListener('pointerup', up);
    });
  }

  /** Where a drop lands: into the container under the pointer, or before / after the widget under it. */
  dropTarget(e) {
    const target = this.nodeFromEvent(e) || this.form;
    const box = this.nodes.get(target);
    if (target === this.form || !box) return { target: this.form, place: 'inside' };
    const r = box.getBoundingClientRect();
    const parent = this.parents.get(target) || this.form;
    const horizontal = parent !== this.form ? ['row', 'grid'].includes(layoutOf(parent)) : ['row', 'grid'].includes(this.form.layout || 'column');
    const ratio = horizontal ? (e.clientX - r.left) / r.width : (e.clientY - r.top) / r.height;
    if (isContainer(target) && ratio > 0.25 && ratio < 0.75) return { target, place: 'inside' };
    return { target, place: ratio < 0.5 ? 'before' : 'after', horizontal };
  }

  showDrop(drop) {
    const box = drop && this.nodes.get(drop.target);
    if (!box) { this.dropLine.hidden = true; return; }
    const r = box.getBoundingClientRect();
    const s = this.stage.getBoundingClientRect();
    const line = this.dropLine;
    line.hidden = false;
    line.className = drop.place === 'inside' ? 'fd-drop inside' : 'fd-drop';
    if (drop.place === 'inside') Object.assign(line.style, { left: `${r.left - s.left}px`, top: `${r.top - s.top}px`, width: `${r.width}px`, height: `${r.height}px` });
    else if (drop.horizontal) Object.assign(line.style, { left: `${(drop.place === 'before' ? r.left : r.right) - s.left - 1}px`, top: `${r.top - s.top}px`, width: '3px', height: `${r.height}px` });
    else Object.assign(line.style, { left: `${r.left - s.left}px`, top: `${(drop.place === 'before' ? r.top : r.bottom) - s.top - 1}px`, width: `${r.width}px`, height: '3px' });
  }

  endDrag() {
    this.dragging = null;
    this.drop = null;
    this.dropLine.hidden = true;
  }

  insertNew(component) {
    const sel = this.selected;
    if (sel && isContainer(sel)) this.insert(component.make(), sel, 'inside');
    else this.insert(component.make(), sel, sel ? 'after' : 'inside');
  }

  nudge(dir) {
    const node = this.selected;
    const parent = node && this.parents.get(node);
    if (!parent) return;
    const i = parent.children.indexOf(node);
    const j = i + dir;
    if (j < 0 || j >= parent.children.length) return;
    [parent.children[i], parent.children[j]] = [parent.children[j], parent.children[i]];
    this.changed();
  }

  select(node) {
    this.selected = node;
    this.positionSelection();
    this.renderInspector();
    if (this.leftTab === 'layers') this.renderLayers();
  }

  positionSelection() {
    for (const n of this.canvas.querySelectorAll('.fd-selected')) {
      n.classList.remove('fd-selected');
      n.removeAttribute('draggable');
      n.removeAttribute('data-name');
    }
    const box = this.selected && this.nodes.get(this.selected);
    if (box) {
      box.classList.add('fd-selected');
      box.setAttribute('draggable', 'true');
      box.dataset.name = `${this.selected.type}${this.selected.id ? ' · ' + this.selected.id : ''}`;
    }
  }

  onKey(e) {
    if (e.target.closest('input, textarea, select')) return;
    const mod = e.ctrlKey || e.metaKey;
    if (mod && e.key.toLowerCase() === 'z') { e.preventDefault(); e.shiftKey ? this.redo() : this.undo(); }
    else if (mod && e.key.toLowerCase() === 'y') { e.preventDefault(); this.redo(); }
    else if (mod && e.key.toLowerCase() === 'd' && this.selected) { e.preventDefault(); this.duplicate(this.selected); }
    else if ((e.key === 'Delete' || e.key === 'Backspace') && this.selected) { e.preventDefault(); this.remove(this.selected); }
    else if (e.key === 'Escape') this.select(this.selected ? (this.parents.get(this.selected) === this.form ? null : this.parents.get(this.selected)) : null);
    else if (e.key === 'Enter' && this.selected) { e.preventDefault(); this.wire(this.selected); }
  }

  // the code behind ---------------------------------------------------------------------------------------------

  /** A double click: the first event of the widget, its method written (or found) and opened. */
  async wire(node, event = null) {
    event ||= (EVENTS[node.type] || ['click'])[0];
    if (!event) { this.status(`A ${node.type} has no event.`); return; }
    if (!node.id) { node.id = this.freeId(node.type); }
    node.events ||= {};
    const method = node.events[event] || handlerName(node.id, event);
    const fresh = node.events[event] !== method;
    node.events[event] = method;
    if (fresh) this.changed();
    await this.save.flush();
    try {
      const widgets = [];
      this.walk(this.form, (n) => { if (n !== this.form && n.id) widgets.push([n.id, n.type || 'container']); });
      this.lastProperties = JSON.stringify(widgets);
      const r = await this.backend.invoke('gui.code', { path: this.path, widgets, wire: { id: node.id, type: node.type, event, method } });
      await this.writeFile(r.path, r.content);
      this.status(r.added ? `${method}() written in ${r.path.split('/').pop()}` : `${method}() is in ${r.path.split('/').pop()}`);
      this.openFile(r.path, r.line);
    } catch (e) {
      this.status(e.message, true);
    }
  }

  async openCode() {
    try {
      const widgets = [];
      this.walk(this.form, (n) => { if (n !== this.form && n.id) widgets.push([n.id, n.type || 'container']); });
      const r = await this.backend.invoke('gui.code', { path: this.path, widgets });
      if (r.created) await this.writeFile(r.path, r.content);
      this.openFile(r.path, 1);
    } catch (e) {
      this.status(e.message, true);
    }
  }

  // layers ------------------------------------------------------------------------------------------------------

  renderLayers() {
    const box = this.layers;
    box.textContent = '';
    const row = (node, depth) => {
      const isForm = node === this.form;
      const item = el('div', {
        class: `dz-layer${node === this.selected || (isForm && !this.selected) ? ' selected' : ''}`, style: { paddingLeft: `${8 + depth * 14}px` },
        html: `${svg(isForm ? 'form' : (node.type in ICONS ? node.type : 'column'), 13)} <span class="dz-ltag">${esc(isForm ? 'form' : node.type)}</span>`
          + (node.id ? ` <span class="dz-lid">${esc(node.id)}</span>` : '') + (node.text ? ` <span class="dz-ltext">${esc(String(node.text).slice(0, 30))}</span>` : ''),
        onclick: () => this.select(isForm ? null : node),
        ondblclick: () => (isForm ? this.openCode() : this.wire(node)),
      });
      box.append(item);
      for (const child of node.children || []) row(child, depth + 1);
    };
    row(this.form, 0);
  }

  // inspector ---------------------------------------------------------------------------------------------------

  renderInspector() {
    const box = this.inspector;
    const scroll = box.scrollTop;
    box.textContent = '';
    const node = this.selected;
    if (!node) {
      box.append(el('div', { class: 'dz-tagline', html: `<code>${esc(this.path.split('/').pop())}</code>` }),
        this.section('Form', FORM_PROPS.map((p) => this.control(this.form, p, true)), 'form'),
        el('p', { class: 'fd-hint', text: 'Drag widgets from the left. Rows put them side by side, columns one under the other, grids in 12 columns '
          + '(the span of each child changes with the screen width: try the phone and tablet buttons). A spacer takes the free room. '
          + 'A double click on a widget writes its event in the code.' }));
      box.scrollTop = scroll;
      return;
    }
    const type = node.type || 'container';
    const id = el('input', { class: 'dz-input', value: node.id || '', placeholder: 'no id: not reachable from the code', spellcheck: 'false' });
    id.addEventListener('change', () => {
      const value = id.value.trim().replace(/[^A-Za-z0-9_]/g, '_');
      if (value && !/^[A-Za-z_]/.test(value)) { this.status('An id starts with a letter.', true); return; }
      this.set(node, 'id', value, true);
    });
    box.append(el('div', { class: 'dz-tagline', html: `${svg(type in ICONS ? type : 'column', 14)} <code>${esc(type)}</code>` }), this.field('Id', id));
    const own = PROPS[type] || [];
    if (own.length) box.append(this.section(type[0].toUpperCase() + type.slice(1), own.map((p) => this.control(node, p)), 'own'));
    if (isContainer(node)) box.append(this.section('Children', CONTAINER_PROPS.map((p) => this.control(node, p, true)), 'container'));
    const parent = this.parents.get(node);
    const layoutItems = LAYOUT_PROPS.map((p) => this.control(node, p));
    if (parent && (parent === this.form ? (this.form.layout || 'column') : layoutOf(parent)) === 'grid') layoutItems.unshift(this.spanEditor(node));
    layoutItems.push(this.hideEditor(node));
    box.append(this.section('Size and place', layoutItems, 'layout'));
    const events = EVENTS[type] || [];
    if (events.length) box.append(this.section('Events', events.map((event) => this.eventRow(node, event)), 'events'));
    box.scrollTop = scroll;
  }

  section(title, content, key) {
    const open = !this.closedSections.has(key);
    const head = el('button', { class: `dz-section-head${open ? ' open' : ''}`, html: `${svg('chevron', 12)}<span>${esc(title)}</span>` });
    const body = el('div', { class: 'dz-section-body' }, content);
    body.hidden = !open;
    head.addEventListener('click', () => {
      body.hidden = !body.hidden;
      head.classList.toggle('open', !body.hidden);
      body.hidden ? this.closedSections.add(key) : this.closedSections.delete(key);
    });
    return el('div', { class: 'dz-section' }, [head, body]);
  }

  field(label, control) {
    return el('label', { class: 'dz-field' }, [el('span', { class: 'dz-flabel', text: label }), control]);
  }

  /** One property of the inspector. */
  control(node, [prop, label, kind, options], rerender = false) {
    const value = node[prop];
    const commit = (v, full = rerender) => this.set(node, prop, v, full);
    switch (kind) {
      case 'check': {
        const input = el('input', { type: 'checkbox' });
        input.checked = value ?? options === true;
        input.addEventListener('change', () => commit(input.checked === (options === true) ? undefined : input.checked, true));
        return el('label', { class: 'dz-field fd-check' }, [input, el('span', { text: label })]);
      }
      case 'select': {
        const select = el('select', { class: 'dz-input' }, options.map((o) => el('option', { value: o, text: o === '' ? '(default)' : o })));
        select.value = value ?? '';
        select.addEventListener('change', () => commit(select.value, true));
        return this.field(label, select);
      }
      case 'number': {
        const input = el('input', { class: 'dz-input', type: 'number', value: value ?? '', step: 'any' });
        input.addEventListener('input', () => commit(input.value === '' ? undefined : parseFloat(input.value)));
        return this.field(label, input);
      }
      case 'size': {
        // a number of dp, 'fill' (the room the container gives) or auto (empty)
        const input = el('input', { class: 'dz-input', value: value ?? '', placeholder: 'auto', list: 'fd-sizes' });
        input.addEventListener('change', () => {
          const v = input.value.trim();
          commit(v === '' || v === 'auto' ? undefined : (isNaN(v) ? v : parseFloat(v)), true);
        });
        return this.field(label, el('div', { class: 'fd-size' }, [input,
          el('button', { class: 'dz-button', text: 'fill', title: 'All the room', onclick: (e) => { e.preventDefault(); commit('fill', true); } }),
          el('button', { class: 'dz-button', text: 'auto', title: 'What its content needs', onclick: (e) => { e.preventDefault(); commit(undefined, true); } })]));
      }
      case 'color': {
        const text = el('input', { class: 'dz-input', value: value ?? '', placeholder: 'theme', spellcheck: 'false' });
        const pick = el('input', { type: 'color', class: 'dz-color', value: /^#[0-9a-f]{6}$/i.test(value || '') ? value : '#3b6ef5' });
        text.addEventListener('change', () => commit(text.value.trim(), true));
        pick.addEventListener('input', () => { text.value = pick.value; commit(pick.value); });
        return this.field(label, el('div', { class: 'dz-colorrow' }, [pick, text]));
      }
      case 'textarea':
      case 'lines': {
        const area = el('textarea', { class: 'dz-input dz-textarea', rows: kind === 'lines' ? 5 : 3, spellcheck: 'false' });
        area.value = kind === 'lines' ? (value || []).join('\n') : (value ?? '');
        area.addEventListener('input', () => commit(kind === 'lines' ? area.value.split('\n').filter((l, i, all) => l !== '' || i < all.length - 1) : area.value));
        return this.field(label, area);
      }
      case 'image':
        return this.imageControl(node, prop, label);
      default: {
        const input = el('input', { class: 'dz-input', value: value ?? '', spellcheck: 'false' });
        input.addEventListener('input', () => commit(input.value));
        return this.field(label, input);
      }
    }
  }

  /** A picture of the app's assets (or a path typed). */
  imageControl(node, prop, label) {
    const select = el('select', { class: 'dz-input' }, [el('option', { value: '', text: '(none)' })]);
    const fill = (list) => {
      const values = new Set(list);
      if (node[prop] && !values.has(node[prop])) list = [node[prop], ...list];
      for (const p of list) select.append(el('option', { value: p, text: p }));
      select.value = node[prop] || '';
    };
    if (this.images) fill(this.images);
    else {
      this.backend.invoke('files.list', {}).then((r) => {
        const prefix = this.app ? `${this.app}/` : '';
        this.images = (r.files || []).map((f) => f.path).filter((p) => p.startsWith(prefix + 'assets/') && IMAGE_EXT.test(p)).map((p) => p.slice(prefix.length));
        fill(this.images);
      }).catch(() => fill([]));
    }
    select.addEventListener('change', () => this.set(node, prop, select.value, true));
    return this.field(label, select);
  }

  /** The columns of a grid cell per screen width. */
  spanEditor(node) {
    const span = node.span && typeof node.span === 'object' ? { ...node.span } : (node.span != null ? { xs: node.span } : {});
    const inputs = BREAKPOINTS.map(([bp, min]) => {
      const input = el('input', { class: 'dz-input', type: 'number', min: 1, max: 12, value: span[bp] ?? '', placeholder: '–', title: `${bp}: ${min ? `${min} dp and wider` : 'phones'}` });
      input.addEventListener('change', () => {
        const v = parseInt(input.value, 10);
        if (v >= 1) span[bp] = Math.min(12, v); else delete span[bp];
        const keys = Object.keys(span);
        this.set(node, 'span', keys.length === 0 ? undefined : (keys.length === 1 && keys[0] === 'xs' ? span.xs : { ...span }), true);
      });
      return el('label', { class: 'fd-span' }, [el('span', { text: bp }), input]);
    });
    return this.field('Columns (of 12)', el('div', { class: 'fd-spans' }, inputs));
  }

  hideEditor(node) {
    const hide = new Set(Array.isArray(node.hide) ? node.hide : []);
    return this.field('Hidden on', el('div', { class: 'fd-spans' }, BREAKPOINTS.map(([bp]) => {
      const input = el('input', { type: 'checkbox' });
      input.checked = hide.has(bp);
      input.addEventListener('change', () => {
        input.checked ? hide.add(bp) : hide.delete(bp);
        this.set(node, 'hide', hide.size ? BREAKPOINTS.map(([b]) => b).filter((b) => hide.has(b)) : undefined, true);
      });
      return el('label', { class: 'fd-span' }, [el('span', { text: bp }), input]);
    })));
  }

  eventRow(node, event) {
    const method = node.events?.[event] || '';
    const input = el('input', { class: 'dz-input', value: method, placeholder: node.id ? handlerName(node.id, event) : 'double click to wire', spellcheck: 'false' });
    input.addEventListener('change', () => {
      const v = input.value.trim();
      node.events ||= {};
      if (v) node.events[event] = v; else delete node.events[event];
      if (!Object.keys(node.events).length) delete node.events;
      this.changed(false);
    });
    const go = el('button', { class: 'dz-button', html: svg('code', 12), title: method ? `Open ${method}()` : 'Write the method and open it', onclick: (e) => {
      e.preventDefault();
      if (input.value.trim()) { node.events ||= {}; node.events[event] = input.value.trim(); }
      this.wire(node, event);
    } });
    return this.field(event, el('div', { class: 'fd-size' }, [input, go]));
  }
}
