// The visual page designer of the IDE: an HTML page (a desktop app's ui/*.html, a game's site/index.html) shown
// as it renders, edited by clicking, dragging components and filling an inspector. The text stays the source of
// truth: the head is kept as written, the body is serialized back from the edited DOM, linked stylesheets are
// edited in place (one rule at a time) and saved with writeFile.
//
//   const d = new Designer(container, {path, html, readFile, writeFile, onChange});
//   d.setHtml(html); d.getHtml(); d.undo(); d.redo(); d.destroy();
//
// The page runs in a sandboxed iframe (allow-same-origin, no scripts): its scripts never run here but stay in the
// output exactly as written. Selection boxes live inside the iframe, appended to <html> after <body>, so they
// never reach the serialized body.

const VOID = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr']);
const RAW = new Set(['script', 'style']);
const BOOLEAN = new Set(['allowfullscreen', 'async', 'autofocus', 'autoplay', 'checked', 'controls', 'default', 'defer', 'disabled',
  'formnovalidate', 'hidden', 'inert', 'ismap', 'itemscope', 'loop', 'multiple', 'muted', 'nomodule', 'novalidate', 'open',
  'playsinline', 'readonly', 'required', 'reversed', 'selected']);
const CONTAINERS = new Set(['body', 'div', 'section', 'main', 'header', 'footer', 'aside', 'nav', 'article', 'form', 'ul', 'ol',
  'li', 'fieldset', 'figure', 'details', 'dl', 'dd', 'td', 'th', 'blockquote', 'label', 'template']);
const TEXTUAL = new Set(['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'a', 'button', 'label', 'li', 'td', 'th', 'small',
  'strong', 'em', 'b', 'i', 'figcaption', 'legend', 'summary', 'dt', 'dd', 'blockquote', 'option', 'code', 'pre', 'q', 'cite']);
const IMAGE_EXT = /\.(png|jpe?g|gif|webp|bmp|ico|svg)$/i;
const BLANK = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
const PLACEHOLDER = 'data:image/svg+xml;utf8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="480" height="270" viewBox="0 0 480 270"><rect width="480" height="270" fill="#d9dce3"/><path d="M150 190l60-70 45 50 35-30 50 50z" fill="#a9afbb"/><circle cx="310" cy="95" r="22" fill="#a9afbb"/></svg>');

// stroke icons, 16x16 viewBox
const ICONS = {
  section: 'M2 3h12v10H2z M2 6h12',
  container: 'M3 3h10v10H3z',
  heading: 'M4 3v10 M12 3v10 M4 8h8',
  paragraph: 'M3 4h10 M3 7h10 M3 10h10 M3 13h6',
  text: 'M5 4h6 M8 4v9',
  button: 'M2 5h12v6H2z M6 8h4',
  link: 'M7 9l2-2 M6 11l-1 1a2 2 0 01-3-3l2-2 M10 5l1-1a2 2 0 013 3l-2 2',
  image: 'M2 3h12v10H2z M2 11l4-4 3 3 2-2 3 3 M10.5 6a.5.5 0 100-.01',
  input: 'M2 5h12v6H2z M4 7v2',
  textarea: 'M2 3h12v10H2z M4 5h6 M4 7h8 M11 11l2-2',
  select: 'M2 5h12v6H2z M10 7l1.5 1.5L13 7',
  checkbox: 'M3 3h10v10H3z M5 8l2 2 4-4',
  form: 'M3 2h10v12H3z M5 5h6 M5 8h6 M5 11h3',
  list: 'M6 4h8 M6 8h8 M6 12h8 M3 4h.5 M3 8h.5 M3 12h.5',
  card: 'M2 3h12v10H2z M2 8h12',
  flex: 'M2 4h3v8H2z M6.5 4h3v8h-3z M11 4h3v8h-3z',
  grid2: 'M2 3h5v10H2z M9 3h5v10H9z',
  grid3: 'M1.5 3h3.5v10H1.5z M6.25 3h3.5v10h-3.5z M11 3h3.5v10H11z',
  nav: 'M2 3h12v4H2z M4 5h2 M8 5h2 M12 5h.5',
  divider: 'M2 8h12',
  spacer: 'M8 2v12 M5 5l3-3 3 3 M5 11l3 3 3-3',
  up: 'M8 13V3 M4 7l4-4 4 4',
  down: 'M8 3v10 M4 9l4 4 4-4',
  parent: 'M4 11V4h7 M8 1l3 3-3 3',
  copy: 'M5 5h8v8H5z M3 11V3h8',
  trash: 'M3 4h10 M6 4V2.5h4V4 M4.5 4l.7 9.5h5.6l.7-9.5',
  undo: 'M5 3L2 6l3 3 M2 6h8a4 4 0 010 8H7',
  redo: 'M11 3l3 3-3 3 M14 6H6a4 4 0 000 8h3',
  desktop: 'M2 3h12v8H2z M6 14h4 M8 11v3',
  tablet: 'M4 2h8v12H4z M7.5 12h1',
  phone: 'M5 2h6v12H5z M7.5 12h1',
  minus: 'M4 8h8',
  plus: 'M4 8h8 M8 4v8',
  chevron: 'M6 4l4 4-4 4',
  close: 'M4 4l8 8 M12 4l-8 8',
  add: 'M8 3v10 M3 8h10',
};

const COMPONENTS = [
  { group: 'Layout', id: 'section', label: 'Section', icon: 'section', html: '<section style="padding: 32px 24px;">\n  <h2>Section title</h2>\n  <p>Write something here.</p>\n</section>' },
  { group: 'Layout', id: 'container', label: 'Container', icon: 'container', html: '<div style="max-width: 960px; margin: 0 auto; padding: 16px;"></div>' },
  { group: 'Layout', id: 'flex', label: 'Flex row', icon: 'flex', html: '<div style="display: flex; gap: 12px; align-items: center;">\n  <div>Item 1</div>\n  <div>Item 2</div>\n  <div>Item 3</div>\n</div>' },
  { group: 'Layout', id: 'grid2', label: 'Grid 2 columns', icon: 'grid2', html: '<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px;">\n  <div>Column 1</div>\n  <div>Column 2</div>\n</div>' },
  { group: 'Layout', id: 'grid3', label: 'Grid 3 columns', icon: 'grid3', html: '<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;">\n  <div>Column 1</div>\n  <div>Column 2</div>\n  <div>Column 3</div>\n</div>' },
  { group: 'Layout', id: 'card', label: 'Card', icon: 'card', html: '<div class="card" style="padding: 16px; border: 1px solid #8884; border-radius: 12px;">\n  <h3>Card title</h3>\n  <p>Card text.</p>\n</div>' },
  { group: 'Layout', id: 'nav', label: 'Nav bar', icon: 'nav', html: '<nav style="display: flex; gap: 16px; align-items: center; padding: 12px 16px;">\n  <a href="#">Home</a>\n  <a href="#">About</a>\n  <a href="#">Contact</a>\n</nav>' },
  { group: 'Layout', id: 'divider', label: 'Divider', icon: 'divider', html: '<hr>' },
  { group: 'Layout', id: 'spacer', label: 'Spacer', icon: 'spacer', html: '<div style="height: 32px;"></div>' },
  { group: 'Text', id: 'h1', label: 'Heading 1', icon: 'heading', html: '<h1>Heading</h1>' },
  { group: 'Text', id: 'h2', label: 'Heading 2', icon: 'heading', html: '<h2>Heading</h2>' },
  { group: 'Text', id: 'h3', label: 'Heading 3', icon: 'heading', html: '<h3>Heading</h3>' },
  { group: 'Text', id: 'p', label: 'Paragraph', icon: 'paragraph', html: '<p>A paragraph of text.</p>' },
  { group: 'Text', id: 'span', label: 'Text', icon: 'text', html: '<span>Text</span>' },
  { group: 'Text', id: 'a', label: 'Link', icon: 'link', html: '<a href="#">Link</a>' },
  { group: 'Text', id: 'list', label: 'List', icon: 'list', html: '<ul>\n  <li>First item</li>\n  <li>Second item</li>\n</ul>' },
  { group: 'Media', id: 'img', label: 'Image', icon: 'image', html: `<img src="${PLACEHOLDER}" alt="Image" style="max-width: 100%;">` },
  { group: 'Forms', id: 'button', label: 'Button', icon: 'button', html: '<button type="button">Button</button>' },
  { group: 'Forms', id: 'form', label: 'Form', icon: 'form', html: '<form style="display: flex; flex-direction: column; gap: 8px;">\n  <label>Name <input name="name" placeholder="Your name"></label>\n  <button type="submit">Send</button>\n</form>' },
  { group: 'Forms', id: 'input', label: 'Input', icon: 'input', html: '<input type="text" placeholder="Type here">' },
  { group: 'Forms', id: 'textarea', label: 'Text area', icon: 'textarea', html: '<textarea rows="4" placeholder="Type here"></textarea>' },
  { group: 'Forms', id: 'select', label: 'Select', icon: 'select', html: '<select>\n  <option>First</option>\n  <option>Second</option>\n</select>' },
  { group: 'Forms', id: 'checkbox', label: 'Checkbox', icon: 'checkbox', html: '<label><input type="checkbox"> Option</label>' },
];

const DEVICES = { desktop: null, tablet: 768, phone: 390 };

// inline style fields of the inspector: [property, label, control, options]
const STYLE_GROUPS = [
  ['Layout', [
    ['display', 'Display', 'select', ['', 'block', 'inline', 'inline-block', 'flex', 'inline-flex', 'grid', 'none']],
    ['flex-direction', 'Direction', 'select', ['', 'row', 'column', 'row-reverse', 'column-reverse']],
    ['justify-content', 'Justify', 'select', ['', 'flex-start', 'center', 'flex-end', 'space-between', 'space-around', 'space-evenly']],
    ['align-items', 'Align', 'select', ['', 'stretch', 'flex-start', 'center', 'flex-end', 'baseline']],
    ['flex-wrap', 'Wrap', 'select', ['', 'nowrap', 'wrap']],
    ['gap', 'Gap', 'text'],
    ['grid-template-columns', 'Columns', 'text'],
    ['position', 'Position', 'select', ['', 'static', 'relative', 'absolute', 'fixed', 'sticky']],
    ['overflow', 'Overflow', 'select', ['', 'visible', 'hidden', 'auto', 'scroll']],
  ]],
  ['Size', [
    ['width', 'Width', 'text'], ['height', 'Height', 'text'],
    ['min-width', 'Min W', 'text'], ['max-width', 'Max W', 'text'],
    ['min-height', 'Min H', 'text'], ['max-height', 'Max H', 'text'],
  ]],
  ['Spacing', [['margin', 'Margin', 'box'], ['padding', 'Padding', 'box']]],
  ['Typography', [
    ['font-family', 'Font', 'text'], ['font-size', 'Size', 'text'],
    ['font-weight', 'Weight', 'select', ['', '300', '400', '500', '600', '700', '800', 'normal', 'bold']],
    ['line-height', 'Line height', 'text'], ['letter-spacing', 'Spacing', 'text'], ['color', 'Color', 'color'],
    ['text-align', 'Align', 'select', ['', 'left', 'center', 'right', 'justify']],
    ['text-transform', 'Transform', 'select', ['', 'none', 'uppercase', 'lowercase', 'capitalize']],
  ]],
  ['Background', [['background-color', 'Color', 'color'], ['background-image', 'Image', 'text']]],
  ['Border', [
    ['border-width', 'Width', 'text'], ['border-style', 'Style', 'select', ['', 'none', 'solid', 'dashed', 'dotted', 'double']],
    ['border-color', 'Color', 'color'], ['border-radius', 'Radius', 'text'],
  ]],
  ['Effects', [
    ['opacity', 'Opacity', 'text'], ['box-shadow', 'Shadow', 'text'],
    ['cursor', 'Cursor', 'select', ['', 'auto', 'default', 'pointer', 'text', 'move', 'not-allowed']],
  ]],
];

// overlays drawn inside the page
const FRAME_CSS = `
.__dz { all: initial; position: fixed; pointer-events: none; z-index: 2147483646; box-sizing: border-box; }
.__dz-hover { outline: 1px dashed #3574f0; outline-offset: -1px; }
.__dz-sel { outline: 2px solid #3574f0; outline-offset: -1px; }
.__dz-label { all: initial; position: absolute; left: -2px; bottom: 100%; display: flex; align-items: center; gap: 1px; pointer-events: auto;
  background: #3574f0; color: #fff; font: 11px/20px Inter, system-ui, sans-serif; border-radius: 4px 4px 0 0; white-space: nowrap; cursor: grab; }
.__dz-label.__dz-below { bottom: auto; top: 100%; border-radius: 0 0 4px 4px; }
.__dz-tag { all: initial; color: #fff; font: 600 11px/20px Inter, system-ui, sans-serif; padding: 0 6px; }
.__dz-btn { all: initial; display: inline-flex; width: 20px; height: 20px; align-items: center; justify-content: center; cursor: pointer; border-radius: 3px; }
.__dz-btn:hover { background: #ffffff33; }
.__dz-btn svg { width: 12px; height: 12px; fill: none; stroke: #fff; stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; }
.__dz-drop-line { height: 3px; background: #3574f0; border-radius: 2px; box-shadow: 0 0 0 1px #fff8; }
.__dz-drop-box { outline: 2px dashed #3574f0; background: #3574f018; }
[data-designer-editing] { outline: 2px solid #5fb865 !important; outline-offset: 1px; cursor: text; }
html.__dz-dragging * { cursor: copy !important; }
`;

function svg(name, size = 16) {
  return `<svg viewBox="0 0 16 16" width="${size}" height="${size}" aria-hidden="true"><path d="${ICONS[name] || ''}"/></svg>`;
}

function esc(text) {
  return String(text).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}

function el(tag, attrs = {}, children = []) {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs)) {
    if (value == null || value === false) continue;
    if (key === 'class') node.className = value;
    else if (key === 'html') node.innerHTML = value;
    else if (key === 'text') node.textContent = value;
    else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
    else node.setAttribute(key, value === true ? '' : value);
  }
  for (const child of [].concat(children)) {
    if (child != null) node.append(child);
  }
  return node;
}

function debounce(fn, ms) {
  let timer = null;
  const wrapped = (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => { timer = null; fn(...args); }, ms);
  };
  wrapped.flush = (...args) => { if (timer) { clearTimeout(timer); timer = null; fn(...args); } };
  wrapped.cancel = () => { clearTimeout(timer); timer = null; };
  return wrapped;
}

// project paths -------------------------------------------------------------------------------------------------

function dirOf(path) {
  const i = path.lastIndexOf('/');
  return i < 0 ? '' : path.slice(0, i);
}

function isExternal(url) {
  return !url || /^([a-z][a-z0-9+.-]*:|\/\/|#)/i.test(url.trim());
}

/** A URL written in a file of folder $base, as a project path. */
function resolvePath(base, url) {
  const clean = url.trim().split(/[?#]/)[0];
  const parts = (clean.startsWith('/') ? [] : base.split('/').filter(Boolean));
  for (const part of clean.split('/')) {
    if (part === '' || part === '.') continue;
    if (part === '..') parts.pop();
    else parts.push(decodeURIComponent(part));
  }
  return parts.join('/');
}

// CSS -------------------------------------------------------------------------------------------------------------

/**
 * The style rules of a stylesheet's text with their positions: {selector, media, start, end, bodyStart, bodyEnd}.
 * Rules inside @media / @supports / @layer blocks are listed (with their condition); @font-face, @keyframes...
 * are skipped.
 */
export function parseCss(text) {
  const rules = [];
  const scan = (from, to, media) => {
    let i = from;
    let preludeStart = from;
    while (i < to) {
      const c = text[i];
      if (c === '/' && text[i + 1] === '*') {
        const end = text.indexOf('*/', i + 2);
        i = end < 0 ? to : end + 2;
        continue;
      }
      if (c === '"' || c === "'") {
        i++;
        while (i < to && text[i] !== c) i += text[i] === '\\' ? 2 : 1;
        i++;
        continue;
      }
      if (c === ';' || c === '}') {
        preludeStart = i + 1;
        i++;
        continue;
      }
      if (c === '{') {
        const close = matchBrace(text, i, to);
        const prelude = text.slice(preludeStart, i).replace(/\/\*[\s\S]*?\*\//g, '').trim();
        if (/^@(media|supports|layer|container|document)\b/i.test(prelude)) {
          scan(i + 1, close, prelude);
        } else if (!prelude.startsWith('@') && prelude !== '') {
          let start = preludeStart;
          while (start < i && /\s/.test(text[start])) start++;
          rules.push({ selector: prelude, media, start, end: close + 1, bodyStart: i + 1, bodyEnd: close });
        }
        i = close + 1;
        preludeStart = i;
        continue;
      }
      i++;
    }
  };
  scan(0, text.length, null);
  return rules;
}

function matchBrace(text, open, to) {
  let depth = 0;
  for (let i = open; i < to; i++) {
    const c = text[i];
    if (c === '/' && text[i + 1] === '*') {
      const end = text.indexOf('*/', i + 2);
      if (end < 0) return to;
      i = end + 1;
    } else if (c === '"' || c === "'") {
      i++;
      while (i < to && text[i] !== c) i += text[i] === '\\' ? 2 : 1;
    } else if (c === '{') {
      depth++;
    } else if (c === '}') {
      depth--;
      if (depth === 0) return i;
    }
  }
  return to;
}

/** Whether an element matches a selector list, pseudo-states (:hover, ::before...) ignored. */
function matchesLoosely(node, selector) {
  for (const part of splitSelectors(selector)) {
    const loose = part.replace(/::?(hover|active|focus|focus-visible|focus-within|visited|link|before|after|placeholder|selection|marker|first-line|first-letter|-webkit-[\w-]+|-moz-[\w-]+)\b(\([^)]*\))?/g, '').trim() || '*';
    try {
      if (node.matches(loose)) return true;
    } catch { /* a selector the browser does not know */ }
  }
  return false;
}

function splitSelectors(selector) {
  const out = [];
  let depth = 0;
  let current = '';
  for (const c of selector) {
    if (c === '(' || c === '[') depth++;
    if (c === ')' || c === ']') depth--;
    if (c === ',' && depth === 0) { out.push(current); current = ''; } else current += c;
  }
  out.push(current);
  return out.map((s) => s.trim()).filter(Boolean);
}

/** Declarations as shown (indentation removed) and how to put them back as they were written. */
function dedentBody(body) {
  const lead = body.match(/^\s*/)[0];
  const trail = body.match(/\s*$/)[0];
  const inner = body.slice(lead.length, body.length - trail.length);
  const lines = inner.split('\n');
  const indents = lines.slice(1).filter((l) => l.trim()).map((l) => l.match(/^[ \t]*/)[0].length);
  const cut = indents.length ? Math.min(...indents) : 0;
  const indent = lead.includes('\n') ? lead.slice(lead.lastIndexOf('\n') + 1) : '';
  return {
    text: [lines[0], ...lines.slice(1).map((l) => l.slice(cut))].join('\n'),
    restore: (text) => lead + text.trim().split('\n').map((l, i) => (i === 0 ? l : indent + l)).join('\n') + trail,
  };
}

function balanced(text) {
  let depth = 0;
  for (const c of text) {
    if (c === '{') depth++;
    if (c === '}' && --depth < 0) return false;
  }
  return depth === 0;
}

// serialization -----------------------------------------------------------------------------------------------

function escapeText(text) {
  return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/ /g, '&nbsp;');
}

function serializeAttrs(node) {
  let out = '';
  for (const attr of node.attributes) {
    if (attr.name.startsWith('data-designer-')) continue;
    let value = attr.value;
    if (attr.name === 'src' && node.hasAttribute('data-designer-src')) value = node.getAttribute('data-designer-src');
    if (attr.name === 'contenteditable' && node.hasAttribute('data-designer-editing') && !node.hasAttribute('data-designer-had-ce')) continue;
    out += value === '' && BOOLEAN.has(attr.name) ? ` ${attr.name}` : ` ${attr.name}="${value.replace(/&/g, '&amp;').replace(/"/g, '&quot;')}"`;
  }
  return out;
}

function serializeNode(node) {
  switch (node.nodeType) {
    case Node.TEXT_NODE: {
      const parent = node.parentNode && node.parentNode.nodeName.toLowerCase();
      return RAW.has(parent) ? node.data : escapeText(node.data);
    }
    case Node.COMMENT_NODE:
      return `<!--${node.data}-->`;
    case Node.ELEMENT_NODE: {
      const tag = node.nodeName.toLowerCase();
      if (node.classList.contains('__dz')) return '';
      const open = `<${tag}${serializeAttrs(node)}>`;
      if (VOID.has(tag)) return open;
      const children = tag === 'template' ? node.content.childNodes : node.childNodes;
      return open + serializeChildren(children) + `</${tag}>`;
    }
    default:
      return '';
  }
}

function serializeChildren(nodes) {
  let out = '';
  for (const child of nodes) out += serializeNode(child);
  return out;
}

// the designer ----------------------------------------------------------------------------------------------------

export class Designer {
  constructor(container, options = {}) {
    this.container = container;
    this.path = options.path || 'index.html';
    this.readFile = options.readFile || (async () => ({}));
    this.writeFile = options.writeFile || (async () => {});
    this.onChangeHandler = options.onChange || (() => {});
    this.dir = dirOf(this.path);
    this.selected = null;
    this.hovered = null;
    this.editing = null;
    this.dragging = null;
    this.drop = null;
    this.clipboard = null;
    this.collapsed = new WeakSet();
    this.undoStack = [];
    this.redoStack = [];
    this.sheets = [];
    this.device = 'desktop';
    this.zoom = 1;
    this.leftTab = 'components';
    this.destroyed = false;
    this.loadId = 0;
    this.emitChange = debounce(() => this.onChangeHandler(this.getHtml()), 300);
    this.buildUi();
    this.frameLoop = this.frameLoop.bind(this);
    requestAnimationFrame(this.frameLoop);
    this.setHtml(options.html || '');
  }

  // public API ------------------------------------------------------------------------------------------------

  /** Shows another text (opened, or changed in the code view). The undo history starts again. */
  setHtml(html) {
    return this.load(String(html));
  }

  /** The page's text: the original as long as nothing changed, else the head as written + the edited body. */
  getHtml() {
    if (!this.doc || !this.dirty) return this.source;
    const body = this.doc.body;
    const inner = serializeChildren(body.childNodes);
    if (!this.bodyMatch) return inner;
    const attrs = serializeAttrs(body);
    const open = attrs === this.bodyAttrs ? this.bodyMatch.open : `<body${attrs}>`;
    return this.source.slice(0, this.bodyMatch.start) + open + inner + this.source.slice(this.bodyMatch.closeStart);
  }

  undo() {
    if (this.editing) this.finishTextEdit();
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

  destroy() {
    this.destroyed = true;
    this.emitChange.flush();
    for (const sheet of this.sheets) sheet.save?.flush();
    this.root.remove();
  }

  // interface -------------------------------------------------------------------------------------------------

  buildUi() {
    this.root = el('div', { class: 'dz-root', tabindex: '-1' });
    this.left = el('div', { class: 'dz-left' });
    this.center = el('div', { class: 'dz-center' });
    this.right = el('div', { class: 'dz-right' });

    // left: components / layers
    this.tabs = el('div', { class: 'dz-tabs' }, [
      el('button', { class: 'dz-tab', 'data-tab': 'components', text: 'Components', onclick: () => this.showTab('components') }),
      el('button', { class: 'dz-tab', 'data-tab': 'layers', text: 'Layers', onclick: () => this.showTab('layers') }),
    ]);
    this.palette = el('div', { class: 'dz-palette' });
    this.layers = el('div', { class: 'dz-layers', role: 'tree' });
    this.left.append(this.tabs, this.palette, this.layers);
    this.buildPalette();

    // center: toolbar, canvas, breadcrumb
    const tool = (icon, title, onclick, extra = {}) => el('button', { class: 'dz-tool', title, html: svg(icon), onclick, ...extra });
    this.undoButton = tool('undo', 'Undo (Ctrl+Z)', () => this.undo());
    this.redoButton = tool('redo', 'Redo (Ctrl+Shift+Z)', () => this.redo());
    this.deviceButtons = Object.keys(DEVICES).map((device) => tool(device, `${device[0].toUpperCase()}${device.slice(1)}${DEVICES[device] ? ` (${DEVICES[device]} px)` : ''}`,
      () => this.setDevice(device), { 'data-device': device }));
    this.zoomLabel = el('button', { class: 'dz-zoom', title: 'Reset zoom', text: '100%', onclick: () => this.setZoom(1) });
    this.toolbar = el('div', { class: 'dz-toolbar' }, [
      el('div', { class: 'dz-group' }, [this.undoButton, this.redoButton]),
      el('div', { class: 'dz-group' }, this.deviceButtons),
      el('div', { class: 'dz-group' }, [tool('minus', 'Zoom out', () => this.setZoom(this.zoom - 0.1)), this.zoomLabel, tool('plus', 'Zoom in', () => this.setZoom(this.zoom + 0.1))]),
      this.sizeLabel = el('span', { class: 'dz-size' }),
    ]);
    this.viewport = el('div', { class: 'dz-viewport' });
    this.stage = el('div', { class: 'dz-stage' });
    this.iframe = el('iframe', { class: 'dz-frame', sandbox: 'allow-same-origin', title: 'Page designer' });
    this.stage.append(this.iframe);
    this.viewport.append(this.stage);
    this.crumbs = el('div', { class: 'dz-crumbs' });
    this.center.append(this.toolbar, this.viewport, this.crumbs);

    // right: inspector
    this.inspector = el('div', { class: 'dz-inspector' });
    this.right.append(this.inspector);

    this.root.append(this.left, this.center, this.right);
    this.container.append(this.root);
    this.root.addEventListener('keydown', (e) => this.onKey(e));
    new ResizeObserver(() => this.layoutStage()).observe(this.viewport);
    this.showTab(this.leftTab);
    this.setDevice(this.device);
  }

  showTab(tab) {
    this.leftTab = tab;
    for (const button of this.tabs.children) button.classList.toggle('active', button.dataset.tab === tab);
    this.palette.hidden = tab !== 'components';
    this.layers.hidden = tab !== 'layers';
    if (tab === 'layers') this.renderLayers();
  }

  buildPalette() {
    let group = null;
    for (const component of COMPONENTS) {
      if (component.group !== group) {
        group = component.group;
        this.palette.append(el('div', { class: 'dz-heading', text: group }));
      }
      const item = el('div', {
        class: 'dz-component', draggable: 'true', title: `Drag onto the page, or click to insert after the selection`,
        html: `${svg(component.icon)}<span>${esc(component.label)}</span>`,
        onclick: () => this.insertComponent(component),
        ondragstart: (e) => {
          this.dragging = { html: component.html };
          e.dataTransfer.effectAllowed = 'copy';
          e.dataTransfer.setData('text/plain', component.label);
        },
        ondragend: () => this.endDrag(),
      });
      this.palette.append(item);
    }
  }

  setDevice(device) {
    this.device = device;
    for (const button of this.deviceButtons) button.classList.toggle('active', button.dataset.device === device);
    this.layoutStage();
  }

  setZoom(zoom) {
    this.zoom = Math.round(Math.min(2, Math.max(0.25, zoom)) * 100) / 100;
    this.zoomLabel.textContent = `${Math.round(this.zoom * 100)}%`;
    this.layoutStage();
  }

  layoutStage() {
    const width = DEVICES[this.device];
    const available = this.viewport.clientWidth - (width ? 48 : 0);
    const height = this.viewport.clientHeight - (width ? 48 : 0);
    const frameWidth = width || Math.max(320, available / this.zoom);
    const frameHeight = Math.max(200, height / this.zoom);
    this.iframe.style.width = `${frameWidth}px`;
    this.iframe.style.height = `${frameHeight}px`;
    this.iframe.style.transform = `scale(${this.zoom})`;
    this.stage.style.width = `${frameWidth * this.zoom}px`;
    this.stage.style.height = `${frameHeight * this.zoom}px`;
    this.stage.classList.toggle('dz-device', !!width);
    this.sizeLabel.textContent = `${Math.round(frameWidth)} × ${Math.round(frameHeight)}`;
  }

  // loading ---------------------------------------------------------------------------------------------------

  async load(html) {
    const id = ++this.loadId;
    this.source = html;
    this.dirty = false;
    this.selected = this.hovered = this.editing = null;
    this.undoStack = [];
    this.redoStack = [];

    const open = /<body\b[^>]*>/i.exec(html);
    const close = html.toLowerCase().lastIndexOf('</body>');
    this.bodyMatch = open ? { start: open.index, open: open[0], closeStart: close > open.index ? close : html.length } : null;

    const parsed = new DOMParser().parseFromString(open ? html : `<!doctype html><html><head></head><body>${html}</body></html>`, 'text/html');
    // stylesheets: read from the project and injected (the iframe cannot fetch project files)
    const sheets = [];
    for (const link of [...parsed.querySelectorAll('link[href]')]) {
      const rel = (link.getAttribute('rel') || '').toLowerCase();
      const href = link.getAttribute('href');
      if (rel.split(/\s+/).includes('stylesheet') && !isExternal(href)) {
        const path = resolvePath(this.dir, href);
        const style = parsed.createElement('style');
        style.setAttribute('data-designer-href', path);
        link.replaceWith(style);
        sheets.push({ path, href, text: '', rules: [] });
      } else if (!rel.includes('stylesheet')) {
        link.remove();
      }
    }
    for (const script of [...parsed.head.querySelectorAll('script')]) script.remove();
    for (const img of parsed.body.querySelectorAll('img[src]')) {
      const src = img.getAttribute('src');
      if (!isExternal(src)) {
        img.setAttribute('data-designer-src', src);
        img.setAttribute('src', BLANK);
      }
    }
    const ui = parsed.createElement('style');
    ui.setAttribute('data-designer-ui', '');
    ui.textContent = FRAME_CSS;
    parsed.head.append(ui);

    await new Promise((resolve) => {
      this.iframe.addEventListener('load', resolve, { once: true });
      this.iframe.srcdoc = '<!doctype html>\n' + parsed.documentElement.outerHTML;
    });
    if (id !== this.loadId || this.destroyed) return;

    this.doc = this.iframe.contentDocument;
    this.win = this.iframe.contentWindow;
    this.bodyAttrs = serializeAttrs(this.doc.body);
    this.sheets = sheets;
    for (const sheet of sheets) {
      sheet.style = this.doc.querySelector(`style[data-designer-href="${CSS.escape(sheet.path)}"]`);
      sheet.save = debounce(() => this.writeFile(sheet.path, sheet.text).catch((e) => console.error('designer: cannot save', sheet.path, e)), 500);
    }
    this.buildOverlays();
    this.listenFrame();
    this.pushUndo();
    this.renderLayers();
    this.renderInspector();
    this.renderCrumbs();
    this.updateButtons();

    await Promise.all([
      ...sheets.map(async (sheet) => {
        try {
          const file = await this.readFile(sheet.path);
          sheet.text = file.content ?? '';
        } catch {
          sheet.text = '';
          sheet.missing = true;
        }
        sheet.rules = parseCss(sheet.text);
        await this.applySheet(sheet);
      }),
      ...[...this.doc.querySelectorAll('img[data-designer-src]')].map((img) => this.resolveImage(img)),
    ]);
    if (id === this.loadId) this.renderInspector();
  }

  /** Puts a stylesheet's text in the page, its url(...) pictures resolved to data URLs. */
  async applySheet(sheet) {
    if (!sheet.style) return;
    sheet.style.textContent = sheet.text;
    const base = dirOf(sheet.path);
    const urls = [...new Set([...sheet.text.matchAll(/url\(\s*(['"]?)([^'")]+)\1\s*\)/g)].map((m) => m[2]))]
      .filter((u) => !isExternal(u) && IMAGE_EXT.test(u.split(/[?#]/)[0]));
    if (!urls.length) return;
    const text = sheet.text;
    const map = {};
    await Promise.all(urls.map(async (u) => {
      try {
        const file = await this.readFile(resolvePath(base, u));
        if (file.image) map[u] = file.image;
      } catch { /* missing picture */ }
    }));
    if (sheet.text !== text) return;
    sheet.style.textContent = text.replace(/url\(\s*(['"]?)([^'")]+)\1\s*\)/g, (all, q, u) => (map[u] ? `url("${map[u]}")` : all));
  }

  async resolveImage(img) {
    const src = img.getAttribute('data-designer-src');
    if (src == null) return;
    if (isExternal(src)) {
      img.setAttribute('src', src);
      img.removeAttribute('data-designer-src');
      return;
    }
    try {
      const file = await this.readFile(resolvePath(this.dir, src));
      if (img.getAttribute('data-designer-src') === src) img.setAttribute('src', file.image || BLANK);
    } catch {
      if (img.getAttribute('data-designer-src') === src) img.setAttribute('src', PLACEHOLDER);
    }
  }

  // overlays and frame events ----------------------------------------------------------------------------------

  buildOverlays() {
    const make = (cls) => {
      const node = this.doc.createElement('div');
      node.className = `__dz ${cls}`;
      node.style.display = 'none';
      this.doc.documentElement.append(node);
      return node;
    };
    this.hoverBox = make('__dz-hover');
    this.selBox = make('__dz-sel');
    this.dropLine = make('__dz-drop-line');
    this.dropBox = make('__dz-drop-box');
    const label = this.doc.createElement('div');
    label.className = '__dz-label';
    label.draggable = true;
    const actions = [['parent', 'Select parent (Esc)'], ['up', 'Move up'], ['down', 'Move down'], ['copy', 'Duplicate (Ctrl+D)'], ['trash', 'Delete (Del)']];
    label.innerHTML = '<span class="__dz-tag"></span>' + actions.map(([a, t]) => `<span class="__dz-btn" data-action="${a}" title="${t}">${svg(a)}</span>`).join('');
    this.selBox.append(label);
    this.selLabel = label;
    this.selTag = label.querySelector('.__dz-tag');
    label.addEventListener('mousedown', (e) => {
      const action = e.target.closest('[data-action]')?.dataset.action;
      if (!action) return;
      e.preventDefault();
      e.stopPropagation();
      this.action(action);
    });
    label.addEventListener('dragstart', (e) => {
      if (!this.selected || this.selected === this.doc.body) return e.preventDefault();
      this.dragging = { move: this.selected };
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', this.selected.tagName);
    });
    label.addEventListener('dragend', () => this.endDrag());
  }

  isOverlay(node) {
    return !!(node && node.closest && node.closest('.__dz'));
  }

  /** The element under the pointer that the designer works with (never the overlays, never <html>). */
  targetOf(node) {
    if (!node || node.nodeType !== 1) node = node?.parentElement;
    if (!node || this.isOverlay(node)) return null;
    if (node === this.doc.documentElement || !this.doc.body.contains(node)) return this.doc.body;
    return node;
  }

  listenFrame() {
    const doc = this.doc;
    doc.addEventListener('mousemove', (e) => {
      if (this.dragging) return;
      const target = this.targetOf(e.target);
      if (target !== this.hovered) {
        this.hovered = target;
        this.highlightLayer();
      }
    });
    doc.addEventListener('mouseleave', () => { this.hovered = null; this.highlightLayer(); });
    doc.addEventListener('mousedown', (e) => {
      if (this.isOverlay(e.target)) return;
      if (this.editing && this.editing.node.contains(e.target)) return;
      if (this.editing) this.finishTextEdit();
      e.preventDefault();
      this.root.focus({ preventScroll: true });
      this.select(this.targetOf(e.target));
    });
    doc.addEventListener('click', (e) => { if (!this.editing || !this.editing.node.contains(e.target)) e.preventDefault(); });
    doc.addEventListener('submit', (e) => e.preventDefault());
    doc.addEventListener('dblclick', (e) => {
      const target = this.targetOf(e.target);
      if (target && this.canEditText(target)) this.startTextEdit(target);
    });
    doc.addEventListener('keydown', (e) => this.onKey(e));
    doc.addEventListener('dragover', (e) => {
      if (!this.dragging) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = this.dragging.move ? 'move' : 'copy';
      const target = this.targetOf(doc.elementFromPoint(e.clientX, e.clientY));
      if (!target || (this.dragging.move && (this.dragging.move === target || this.dragging.move.contains(target)))) {
        this.drop = null;
      } else {
        const rect = target.getBoundingClientRect();
        this.drop = { target, place: this.placementOf(target, (e.clientY - rect.top) / Math.max(1, rect.height)) };
      }
    });
    doc.addEventListener('dragleave', (e) => { if (!e.relatedTarget) this.drop = null; });
    doc.addEventListener('drop', (e) => {
      e.preventDefault();
      this.performDrop();
    });
  }

  placementOf(target, ratio) {
    if (target === this.doc.body) return 'inside';
    const tag = target.tagName.toLowerCase();
    if (CONTAINERS.has(tag) && (ratio > 0.25 && ratio < 0.75 || !target.children.length && ratio > 0.2 && ratio < 0.8)) return 'inside';
    return ratio < 0.5 ? 'before' : 'after';
  }

  frameLoop() {
    if (this.destroyed) return;
    requestAnimationFrame(this.frameLoop);
    if (!this.doc || !this.hoverBox) return;
    const place = (box, node, show) => {
      if (!show || !node || !node.isConnected) {
        box.style.display = 'none';
        return null;
      }
      const r = node.getBoundingClientRect();
      box.style.display = 'block';
      box.style.left = `${r.left}px`;
      box.style.top = `${r.top}px`;
      box.style.width = `${Math.max(1, r.width)}px`;
      box.style.height = `${Math.max(1, r.height)}px`;
      return r;
    };
    place(this.hoverBox, this.hovered, this.hovered && this.hovered !== this.selected && !this.dragging);
    const rect = place(this.selBox, this.selected, !!this.selected && !this.editing);
    if (rect) this.selLabel.classList.toggle('__dz-below', rect.top < 24);
    const drop = this.drop;
    place(this.dropBox, drop?.target, drop && drop.place === 'inside');
    if (drop && drop.place !== 'inside' && drop.target.isConnected) {
      const r = drop.target.getBoundingClientRect();
      Object.assign(this.dropLine.style, { display: 'block', left: `${r.left}px`, width: `${Math.max(8, r.width)}px`,
        top: `${(drop.place === 'before' ? r.top : r.bottom) - 1.5}px`, height: '3px' });
    } else {
      this.dropLine.style.display = 'none';
    }
  }

  // selection ---------------------------------------------------------------------------------------------------

  select(node) {
    if (node && !node.isConnected) node = null;
    this.selected = node;
    if (node) {
      const tag = node.tagName.toLowerCase();
      this.selTag.textContent = tag + (node.id ? `#${node.id}` : '') + (node.classList.length ? `.${[...node.classList].join('.')}` : '');
      for (const btn of this.selLabel.querySelectorAll('[data-action]')) {
        btn.style.display = node === this.doc.body && btn.dataset.action !== 'parent' ? 'none' : '';
      }
      // open the layers down to it
      for (let p = node.parentElement; p && p !== this.doc.documentElement; p = p.parentElement) this.collapsed.delete(p);
    }
    this.renderLayers();
    this.renderInspector();
    this.renderCrumbs();
    this.layers.querySelector('.dz-layer.selected')?.scrollIntoView({ block: 'nearest' });
  }

  pathOf(node) {
    const path = [];
    while (node && node !== this.doc.body && node.parentElement) {
      path.unshift([...node.parentElement.children].indexOf(node));
      node = node.parentElement;
    }
    return path;
  }

  nodeAt(path) {
    let node = this.doc.body;
    for (const i of path) {
      node = node?.children[i];
    }
    return node || null;
  }

  action(name) {
    const node = this.selected;
    if (!node) return;
    const body = this.doc.body;
    if (name === 'parent') {
      if (node !== body) this.select(node.parentElement === this.doc.documentElement ? body : node.parentElement);
      return;
    }
    if (node === body) return;
    if (name === 'delete' || name === 'trash') {
      const next = node.nextElementSibling || node.previousElementSibling || node.parentElement;
      this.removeNode(node);
      this.changed();
      this.select(next && body.contains(next) ? next : body);
    } else if (name === 'copy' || name === 'duplicate') {
      const clone = node.cloneNode(true);
      this.insertNode(clone, node, 'after');
      this.changed();
      this.select(clone);
    } else if (name === 'up') {
      const prev = node.previousElementSibling;
      if (prev) {
        this.removeNode(node);
        this.insertNode(node, prev, 'before');
        this.changed();
        this.select(node);
      }
    } else if (name === 'down') {
      const next = node.nextElementSibling;
      if (next) {
        this.removeNode(node);
        this.insertNode(node, next, 'after');
        this.changed();
        this.select(node);
      }
    }
  }

  // structure, keeping the source's indentation -------------------------------------------------------------------

  indentOf(node) {
    const prev = node.previousSibling;
    if (prev && prev.nodeType === Node.TEXT_NODE) {
      const m = prev.data.match(/\n([ \t]*)$/);
      if (m) return m[1];
    }
    let depth = 0;
    for (let p = node.parentElement; p && p !== this.doc.body; p = p.parentElement) depth++;
    return '  '.repeat(depth + 1);
  }

  /** Inserts $node before / after / inside $target, with the indentation of its neighbours. */
  insertNode(node, target, place) {
    const doc = this.doc;
    const text = (s) => doc.createTextNode(s);
    if (place === 'inside') {
      const indent = target === doc.body ? '  ' : this.indentOf(target) + '  ';
      const outer = target === doc.body ? '' : this.indentOf(target);
      const last = target.lastChild;
      if (this.isLayoutWhitespace(last)) {
        target.insertBefore(text(`\n${indent}`), last);
        target.insertBefore(node, last);
        if (!/\n/.test(last.data)) last.data = `\n${outer}`;
      } else {
        target.append(text(`\n${indent}`), node, text(`\n${outer}`));
      }
      return;
    }
    const indent = this.indentOf(target);
    const parent = target.parentNode;
    if (!this.onOwnLine(target)) {
      // inline flow (a button next to an input): no line break, which would show as a space
      parent.insertBefore(node, place === 'before' ? target : target.nextSibling);
      return;
    }
    if (place === 'before') {
      parent.insertBefore(node, target);
      parent.insertBefore(text(`\n${indent}`), target);
    } else {
      const after = target.nextSibling;
      parent.insertBefore(text(`\n${indent}`), after);
      parent.insertBefore(node, after);
    }
  }

  onOwnLine(node) {
    const prev = node.previousSibling;
    return !prev || (prev.nodeType === Node.TEXT_NODE && /\n[ \t]*$/.test(prev.data));
  }

  isLayoutWhitespace(node) {
    return node && node.nodeType === Node.TEXT_NODE && !node.data.trim();
  }

  /** Removes $node and the line it stood on. */
  removeNode(node) {
    const prev = node.previousSibling;
    if (this.isLayoutWhitespace(prev) && /\n/.test(prev.data)) prev.remove();
    else if (this.isLayoutWhitespace(node.nextSibling) && /\n/.test(node.nextSibling.data) && !node.previousSibling) node.nextSibling.remove();
    node.remove();
  }

  createFromHtml(html) {
    const template = this.doc.createElement('template');
    template.innerHTML = html.trim();
    const node = template.content.firstElementChild;
    for (const img of node.matches('img') ? [node] : node.querySelectorAll('img')) {
      const src = img.getAttribute('src');
      if (src && !isExternal(src)) {
        img.setAttribute('data-designer-src', src);
        this.resolveImage(img);
      }
    }
    return node;
  }

  /** Places a component's HTML, its inner lines indented like the place it goes to. */
  placeHtml(html, target, place) {
    const indent = place === 'inside' ? (target === this.doc.body ? '  ' : this.indentOf(target) + '  ') : this.indentOf(target);
    const node = this.createFromHtml(html.replace(/\n/g, `\n${indent}`));
    this.insertNode(node, target, place);
    return node;
  }

  insertComponent(component) {
    if (!this.doc) return;
    const target = this.selected && this.selected !== this.doc.body ? this.selected : this.doc.body;
    const node = this.placeHtml(component.html, target, target === this.doc.body ? 'inside' : 'after');
    this.changed();
    this.select(node);
  }

  performDrop() {
    const drop = this.drop;
    const dragging = this.dragging;
    this.endDrag();
    if (!drop || !dragging) return;
    let node;
    if (dragging.move) {
      node = dragging.move;
      if (node === drop.target || node.contains(drop.target)) return;
      this.removeNode(node);
      this.insertNode(node, drop.target, drop.place);
    } else {
      node = this.placeHtml(dragging.html, drop.target, drop.place);
    }
    this.changed();
    this.select(node);
  }

  endDrag() {
    this.dragging = null;
    this.drop = null;
    for (const row of this.layers.querySelectorAll('.drop-before, .drop-after, .drop-inside')) row.classList.remove('drop-before', 'drop-after', 'drop-inside');
  }

  // text editing ----------------------------------------------------------------------------------------------

  canEditText(node) {
    if (!node || node === this.doc.body) return false;
    const tag = node.tagName.toLowerCase();
    if (VOID.has(tag) || RAW.has(tag) || ['select', 'textarea', 'ul', 'ol', 'table', 'svg'].includes(tag)) return false;
    // text and inline markup only
    return [...node.children].every((c) => !['div', 'section', 'p', 'ul', 'ol', 'form', 'table', 'header', 'footer', 'main', 'nav', 'aside', 'article'].includes(c.tagName.toLowerCase()))
      && (TEXTUAL.has(tag) || node.textContent.trim() !== '' || !node.children.length);
  }

  startTextEdit(node) {
    if (this.editing) this.finishTextEdit();
    this.select(node);
    this.editing = { node, before: node.innerHTML };
    if (node.hasAttribute('contenteditable')) node.setAttribute('data-designer-had-ce', '');
    node.setAttribute('data-designer-editing', '');
    node.setAttribute('contenteditable', 'true');
    node.focus();
    const range = this.doc.createRange();
    range.selectNodeContents(node);
    const selection = this.win.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    node.addEventListener('blur', this.onEditBlur = () => this.finishTextEdit(), { once: true });
  }

  finishTextEdit() {
    const editing = this.editing;
    if (!editing) return;
    this.editing = null;
    const node = editing.node;
    node.removeEventListener('blur', this.onEditBlur);
    node.removeAttribute('data-designer-editing');
    if (node.hasAttribute('data-designer-had-ce')) node.removeAttribute('data-designer-had-ce');
    else node.removeAttribute('contenteditable');
    this.win.getSelection()?.removeAllRanges();
    if (node.innerHTML !== editing.before) this.changed();
    this.renderInspector();
  }

  // keyboard -----------------------------------------------------------------------------------------------------

  onKey(e) {
    const target = e.target;
    const typing = target && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName))
      && !(target.ownerDocument === this.doc && !target.isContentEditable);
    const mod = e.ctrlKey || e.metaKey;
    if (this.editing) {
      if (e.key === 'Escape' || (e.key === 'Enter' && !e.shiftKey)) {
        e.preventDefault();
        this.finishTextEdit();
      }
      return;
    }
    if (typing) return;
    const key = e.key.toLowerCase();
    let handled = true;
    if (mod && key === 'z' && !e.shiftKey) this.undo();
    else if (mod && (key === 'y' || (key === 'z' && e.shiftKey))) this.redo();
    else if (mod && key === 'd') this.action('copy');
    else if (mod && key === 'c' && this.selected && this.selected !== this.doc.body) this.clipboard = serializeNode(this.selected);
    else if (mod && key === 'x' && this.selected && this.selected !== this.doc.body) { this.clipboard = serializeNode(this.selected); this.action('delete'); }
    else if (mod && key === 'v' && this.clipboard) this.insertComponent({ html: this.clipboard });
    else if (key === 'delete' || key === 'backspace') this.action('delete');
    else if (key === 'escape') this.action('parent');
    else if (key === 'enter' && this.selected && this.canEditText(this.selected)) this.startTextEdit(this.selected);
    else if (key === 'arrowup' || key === 'arrowleft') {
      if (this.selected?.previousElementSibling) this.select(this.selected.previousElementSibling);
    } else if (key === 'arrowdown' || key === 'arrowright') {
      if (this.selected?.nextElementSibling) this.select(this.selected.nextElementSibling);
      else if (!this.selected && this.doc) this.select(this.doc.body.firstElementChild);
    } else handled = false;
    if (handled) {
      e.preventDefault();
      e.stopPropagation();
    }
  }

  // history ------------------------------------------------------------------------------------------------------

  state() {
    const body = this.doc.body;
    return { html: body.innerHTML, attrs: [...body.attributes].map((a) => [a.name, a.value]), selected: this.selected ? this.pathOf(this.selected) : null };
  }

  pushUndo() {
    const state = this.state();
    const top = this.undoStack[this.undoStack.length - 1];
    if (top && top.html === state.html && JSON.stringify(top.attrs) === JSON.stringify(state.attrs)) {
      top.selected = state.selected;
      return;
    }
    this.undoStack.push(state);
    if (this.undoStack.length > 200) this.undoStack.splice(1, 1);
    this.redoStack = [];
    this.updateButtons();
  }

  restore(state) {
    const body = this.doc.body;
    body.innerHTML = state.html;
    for (const attr of [...body.attributes]) body.removeAttribute(attr.name);
    for (const [name, value] of state.attrs) body.setAttribute(name, value);
    this.dirty = this.undoStack.length > 1 || this.undoStack[0] !== state;
    this.hovered = null;
    this.selected = state.selected ? this.nodeAt(state.selected) : null;
    this.select(this.selected);
    this.updateButtons();
    this.emitChange();
  }

  /** After every change of the page: history, layers, the host told (debounced). */
  changed(snapshot = true) {
    this.dirty = true;
    if (snapshot) this.pushUndo();
    this.renderLayers();
    this.renderCrumbs();
    this.emitChange();
  }

  updateButtons() {
    this.undoButton.disabled = this.undoStack.length < 2;
    this.redoButton.disabled = !this.redoStack.length;
  }

  // layers -------------------------------------------------------------------------------------------------------

  renderLayers() {
    if (!this.doc || this.layers.hidden) return;
    const scroll = this.layers.scrollTop;
    this.layers.textContent = '';
    const add = (node, depth) => {
      const tag = node.tagName.toLowerCase();
      const children = [...node.children].filter((c) => !c.classList.contains('__dz'));
      const collapsed = this.collapsed.has(node);
      const own = [...node.childNodes].filter((c) => c.nodeType === 3).map((c) => c.data).join(' ').replace(/\s+/g, ' ').trim();
      const row = el('div', {
        class: `dz-layer${node === this.selected ? ' selected' : ''}${RAW.has(tag) ? ' dim' : ''}`,
        role: 'treeitem', draggable: node !== this.doc.body ? 'true' : null,
        style: `padding-left: ${6 + depth * 14}px`,
      });
      row.__node = node;
      const twisty = el('span', { class: `dz-twisty${children.length ? '' : ' empty'}${collapsed ? '' : ' open'}`, html: svg('chevron', 12) });
      twisty.addEventListener('click', (e) => {
        e.stopPropagation();
        collapsed ? this.collapsed.delete(node) : this.collapsed.add(node);
        this.renderLayers();
      });
      row.append(twisty, el('span', { class: 'dz-ltag', text: tag }));
      if (node.id) row.append(el('span', { class: 'dz-lid', text: `#${node.id}` }));
      if (node.classList.length) row.append(el('span', { class: 'dz-lclass', text: `.${[...node.classList].join('.')}` }));
      if (own && !RAW.has(tag)) row.append(el('span', { class: 'dz-ltext', text: own.length > 40 ? own.slice(0, 40) + '…' : own }));
      row.addEventListener('click', () => this.select(node));
      row.addEventListener('dblclick', () => this.canEditText(node) && this.startTextEdit(node));
      row.addEventListener('mouseenter', () => { this.hovered = node; });
      row.addEventListener('mouseleave', () => { if (this.hovered === node) this.hovered = null; });
      row.addEventListener('dragstart', (e) => {
        this.dragging = { move: node };
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', tag);
      });
      row.addEventListener('dragend', () => this.endDrag());
      row.addEventListener('dragover', (e) => {
        if (!this.dragging || (this.dragging.move && (this.dragging.move === node || this.dragging.move.contains(node)))) return;
        e.preventDefault();
        const r = row.getBoundingClientRect();
        const ratio = (e.clientY - r.top) / r.height;
        const place = node === this.doc.body ? 'inside' : (CONTAINERS.has(tag) && ratio > 0.3 && ratio < 0.7 ? 'inside' : ratio < 0.5 ? 'before' : 'after');
        for (const other of this.layers.querySelectorAll('.drop-before, .drop-after, .drop-inside')) other.classList.remove('drop-before', 'drop-after', 'drop-inside');
        row.classList.add(`drop-${place}`);
        this.drop = { target: node, place };
      });
      row.addEventListener('drop', (e) => {
        e.preventDefault();
        this.performDrop();
      });
      this.layers.append(row);
      if (!collapsed) for (const child of children) add(child, depth + 1);
    };
    add(this.doc.body, 0);
    this.layers.scrollTop = scroll;
  }

  highlightLayer() {
    for (const row of this.layers.children) row.classList.toggle('hover', row.__node === this.hovered);
  }

  renderCrumbs() {
    this.crumbs.textContent = '';
    if (!this.selected) {
      this.crumbs.append(el('span', { class: 'dz-muted', text: 'Click an element to select it · double-click to edit its text · drag components from the left' }));
      return;
    }
    const chain = [];
    for (let n = this.selected; n && n !== this.doc.documentElement; n = n.parentElement) chain.unshift(n);
    chain.forEach((node, i) => {
      if (i) this.crumbs.append(el('span', { class: 'dz-sep', html: svg('chevron', 10) }));
      const tag = node.tagName.toLowerCase() + (node.id ? `#${node.id}` : node.classList.length ? `.${node.classList[0]}` : '');
      this.crumbs.append(el('button', { class: `dz-crumb${node === this.selected ? ' current' : ''}`, text: tag, onclick: () => this.select(node) }));
    });
  }

  // inspector ----------------------------------------------------------------------------------------------------

  renderInspector() {
    const box = this.inspector;
    const scroll = box.scrollTop;
    box.textContent = '';
    const node = this.selected;
    if (!this.doc || !node) {
      box.append(el('div', { class: 'dz-empty', html: `${svg('container', 28)}<p>Select an element on the page or in Layers to edit it.</p>` }));
      return;
    }
    box.append(this.elementSection(node), this.styleSection(node), this.rulesSection(node));
    box.scrollTop = scroll;
  }

  section(title, content, key) {
    const open = !(this.closedSections || (this.closedSections = new Set())).has(key);
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

  elementSection(node) {
    const tag = node.tagName.toLowerCase();
    const isBody = node === this.doc.body;
    const items = [el('div', { class: 'dz-tagline', html: `<code>&lt;${tag}&gt;</code>` })];

    // id
    const id = el('input', { class: 'dz-input', value: node.id, placeholder: 'none', spellcheck: 'false' });
    id.addEventListener('input', () => {
      id.value.trim() ? node.setAttribute('id', id.value.trim()) : node.removeAttribute('id');
      this.changed(false);
    });
    id.addEventListener('change', () => { this.changed(); this.select(node); });
    items.push(this.field('ID', id));

    // classes as chips
    const chips = el('div', { class: 'dz-chips' });
    for (const cls of node.classList) {
      chips.append(el('span', { class: 'dz-chip' }, [cls, el('button', {
        title: `Remove ${cls}`, html: svg('close', 10),
        onclick: () => { node.classList.remove(cls); if (!node.classList.length) node.removeAttribute('class'); this.changed(); this.select(node); },
      })]));
    }
    const addClass = el('input', { class: 'dz-chip-input', placeholder: '+ class', spellcheck: 'false' });
    addClass.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && addClass.value.trim()) {
        e.preventDefault();
        for (const c of addClass.value.trim().split(/\s+/)) node.classList.add(c);
        this.changed();
        this.select(node);
        this.inspector.querySelector('.dz-chip-input')?.focus();
      }
    });
    chips.append(addClass);
    items.push(this.field('Classes', chips));

    // text of simple elements
    if (!isBody && !VOID.has(tag) && !RAW.has(tag) && !node.children.length) {
      const text = el('textarea', { class: 'dz-input dz-textarea', rows: '2', spellcheck: 'false' });
      text.value = node.textContent;
      text.addEventListener('input', () => { node.textContent = text.value; this.changed(false); });
      text.addEventListener('change', () => this.changed());
      items.push(this.field('Text', text));
    }

    // attributes
    const table = el('div', { class: 'dz-attrs' });
    const skip = new Set(['id', 'class', 'style', 'contenteditable']);
    for (const attr of [...node.attributes]) {
      if (skip.has(attr.name) && !(attr.name === 'contenteditable' && !node.hasAttribute('data-designer-editing'))) continue;
      if (attr.name.startsWith('data-designer-')) continue;
      const shown = attr.name === 'src' && node.hasAttribute('data-designer-src') ? node.getAttribute('data-designer-src') : attr.value;
      const value = el('input', { class: 'dz-input', value: shown, spellcheck: 'false' });
      value.addEventListener('input', () => this.setAttr(node, attr.name, value.value, false));
      value.addEventListener('change', () => this.changed());
      table.append(el('div', { class: 'dz-attr' }, [
        el('span', { class: 'dz-aname', text: attr.name, title: attr.name }), value,
        el('button', { class: 'dz-icon', title: `Remove ${attr.name}`, html: svg('close', 10), onclick: () => { node.removeAttribute(attr.name); if (attr.name === 'src') node.removeAttribute('data-designer-src'); this.changed(); this.select(node); } }),
      ]));
    }
    const suggestions = { a: ['href', 'target', 'title'], img: ['src', 'alt', 'width', 'height'], input: ['type', 'name', 'placeholder', 'value'],
      button: ['type', 'title'], form: ['action', 'method'], textarea: ['name', 'placeholder', 'rows'], select: ['name'], label: ['for'] }[tag] || ['title'];
    const name = el('input', { class: 'dz-input', placeholder: 'name', list: 'dz-attr-names', spellcheck: 'false' });
    const value = el('input', { class: 'dz-input', placeholder: 'value', spellcheck: 'false' });
    const add = () => {
      const n = name.value.trim().toLowerCase();
      if (!/^[a-z_:][\w:.-]*$/.test(n) || n.startsWith('data-designer-')) return;
      this.setAttr(node, n, value.value, true);
      this.select(node);
    };
    for (const input of [name, value]) input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); add(); } });
    table.append(el('div', { class: 'dz-attr dz-attr-new' }, [name, value, el('button', { class: 'dz-icon', title: 'Add the attribute', html: svg('add', 12), onclick: add })]),
      el('datalist', { id: 'dz-attr-names' }, suggestions.filter((s) => !node.hasAttribute(s)).map((s) => el('option', { value: s }))));
    items.push(el('div', { class: 'dz-subhead', text: 'Attributes' }), table);

    return this.section('Element', items, 'element');
  }

  setAttr(node, name, value, snapshot) {
    if (name === 'src' && node.tagName === 'IMG') {
      node.setAttribute('data-designer-src', value);
      node.setAttribute('src', BLANK);
      this.resolveImage(node);
    } else {
      node.setAttribute(name, value);
    }
    this.changed(snapshot);
  }

  styleSection(node) {
    const computed = this.win.getComputedStyle(node);
    const groups = [];
    const set = (prop, value, snapshot) => {
      value = value.trim();
      value ? node.style.setProperty(prop, value) : node.style.removeProperty(prop);
      if (!node.getAttribute('style')?.trim()) node.removeAttribute('style');
      this.changed(snapshot);
    };
    for (const [group, fields] of STYLE_GROUPS) {
      const rows = [];
      for (const [prop, label, kind, options] of fields) {
        if (kind === 'box') {
          rows.push(this.boxEditor(node, prop, computed, set));
          continue;
        }
        let control;
        const current = node.style.getPropertyValue(prop);
        if (kind === 'select') {
          control = el('select', { class: 'dz-input' }, options.map((o) => el('option', { value: o, text: o || `— ${computed.getPropertyValue(prop) || ''}` })));
          if (current && !options.includes(current)) control.append(el('option', { value: current, text: current }));
          control.value = current;
          control.addEventListener('change', () => set(prop, control.value, true));
        } else if (kind === 'color') {
          const text = el('input', { class: 'dz-input', value: current, placeholder: computed.getPropertyValue(prop), spellcheck: 'false' });
          const picker = el('input', { type: 'color', class: 'dz-color', value: this.toHex(current || computed.getPropertyValue(prop)) });
          picker.addEventListener('input', () => { text.value = picker.value; set(prop, picker.value, false); });
          picker.addEventListener('change', () => this.changed());
          text.addEventListener('input', () => { set(prop, text.value, false); picker.value = this.toHex(text.value); });
          text.addEventListener('change', () => this.changed());
          control = el('div', { class: 'dz-colorrow' }, [picker, text]);
        } else {
          control = el('input', { class: 'dz-input', value: current, placeholder: computed.getPropertyValue(prop), spellcheck: 'false' });
          control.addEventListener('input', () => set(prop, control.value, false));
          control.addEventListener('change', () => this.changed());
          control.addEventListener('keydown', (e) => this.nudge(e, control));
        }
        rows.push(this.field(label, control));
      }
      groups.push(el('div', { class: 'dz-subhead', text: group }), el('div', { class: 'dz-grid' }, rows));
    }
    const raw = el('textarea', { class: 'dz-input dz-textarea dz-code', rows: '2', spellcheck: 'false', placeholder: 'style="…"' });
    raw.value = node.getAttribute('style') || '';
    raw.addEventListener('input', () => {
      raw.value.trim() ? node.setAttribute('style', raw.value) : node.removeAttribute('style');
      this.changed(false);
    });
    raw.addEventListener('change', () => { this.changed(); this.renderInspector(); });
    groups.push(el('div', { class: 'dz-subhead', text: 'Inline style' }), raw);
    return this.section('Style', groups, 'style');
  }

  /** Up / down arrows change a number with its unit (Shift: by 10). */
  nudge(e, input) {
    if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
    const m = (input.value || input.placeholder).match(/^(-?\d*\.?\d+)([a-z%]*)$/i);
    if (!m) return;
    e.preventDefault();
    const step = (e.shiftKey ? 10 : 1) * (e.key === 'ArrowUp' ? 1 : -1);
    const number = Math.round((parseFloat(m[1]) + step) * 100) / 100;
    input.value = `${number}${m[2] || (number !== 0 ? 'px' : '')}`;
    input.dispatchEvent(new Event('input'));
    input.dispatchEvent(new Event('change'));
  }

  boxEditor(node, prop, computed, set) {
    const side = (name) => {
      const full = `${prop}-${name}`;
      const input = el('input', { class: `dz-box-in dz-box-${name}`, value: node.style.getPropertyValue(full),
        placeholder: computed.getPropertyValue(full).replace(/px$/, '') || '0', title: full, spellcheck: 'false' });
      input.addEventListener('input', () => set(full, /^-?\d*\.?\d+$/.test(input.value.trim()) ? input.value.trim() + 'px' : input.value, false));
      input.addEventListener('change', () => this.changed());
      input.addEventListener('keydown', (e) => this.nudge(e, input));
      return input;
    };
    return el('div', { class: 'dz-box' }, [
      el('span', { class: 'dz-box-label', text: prop }),
      side('top'), side('right'), side('bottom'), side('left'),
      el('span', { class: 'dz-box-center' }),
    ]);
  }

  toHex(color) {
    if (!color) return '#000000';
    if (/^#[0-9a-f]{6}$/i.test(color)) return color;
    if (/^#[0-9a-f]{3}$/i.test(color)) return '#' + [...color.slice(1)].map((c) => c + c).join('');
    const m = color.match(/rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)/);
    if (m) return '#' + m.slice(1, 4).map((n) => (+n).toString(16).padStart(2, '0')).join('');
    try {
      const probe = this.doc.createElement('i');
      probe.style.color = color;
      if (probe.style.color) {
        this.doc.body.append(probe);
        const rgb = this.win.getComputedStyle(probe).color;
        probe.remove();
        return this.toHex(rgb);
      }
    } catch { /* not a colour */ }
    return '#000000';
  }

  rulesSection(node) {
    const items = [];
    const linked = this.sheets.filter((s) => s.style && !s.missing);
    for (const sheet of linked) {
      sheet.rules.forEach((rule, index) => {
        if (!matchesLoosely(node, rule.selector)) return;
        items.push(this.ruleEditor(sheet, index));
      });
    }
    if (!linked.length) {
      items.push(el('p', { class: 'dz-muted dz-pad', text: 'This page links no stylesheet of the project: styles go inline (Style above).' }));
    } else {
      if (!items.length) items.push(el('p', { class: 'dz-muted dz-pad', text: 'No rule of the stylesheets matches this element.' }));
      const selector = node.classList.length ? `.${node.classList[0]}` : node.id ? `#${node.id}` : node.tagName.toLowerCase();
      const sheetSelect = linked.length > 1 ? el('select', { class: 'dz-input' }, linked.map((s) => el('option', { value: s.path, text: s.path.split('/').pop() }))) : null;
      const input = el('input', { class: 'dz-input', value: selector, spellcheck: 'false' });
      const add = () => {
        const sheet = linked.find((s) => s.path === (sheetSelect?.value || linked[0].path));
        const sel = input.value.trim();
        if (!sel || /[{}]/.test(sel)) return;
        this.editSheet(sheet, sheet.text.replace(/\s*$/, '') + `\n\n${sel} {\n  \n}\n`);
        this.renderInspector();
      };
      input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); add(); } });
      items.push(el('div', { class: 'dz-newrule' }, [input, sheetSelect, el('button', { class: 'dz-button', text: 'New rule', onclick: add })]));
    }
    return this.section('CSS rules', items, 'rules');
  }

  ruleEditor(sheet, index) {
    const rule = sheet.rules[index];
    const body = dedentBody(sheet.text.slice(rule.bodyStart, rule.bodyEnd));
    const line = sheet.text.slice(0, rule.start).split('\n').length;
    const area = el('textarea', { class: 'dz-input dz-textarea dz-code', spellcheck: 'false' });
    area.value = body.text;
    area.rows = Math.min(14, Math.max(2, body.text.split('\n').length));
    const card = el('div', { class: 'dz-rule' }, [
      el('div', { class: 'dz-rule-head' }, [
        el('code', { class: 'dz-selector', text: rule.selector }),
        el('span', { class: 'dz-rule-file', text: `${sheet.path.split('/').pop()}:${line}`, title: sheet.path }),
      ]),
      rule.media ? el('div', { class: 'dz-media', text: rule.media }) : null,
      area,
    ]);
    area.addEventListener('input', () => {
      if (!balanced(area.value)) {
        card.classList.add('invalid');
        return;
      }
      card.classList.remove('invalid');
      area.rows = Math.min(14, Math.max(2, area.value.split('\n').length));
      const current = sheet.rules[index];
      if (!current) return;
      this.editSheet(sheet, sheet.text.slice(0, current.bodyStart) + body.restore(area.value) + sheet.text.slice(current.bodyEnd));
    });
    return card;
  }

  editSheet(sheet, text) {
    sheet.text = text;
    sheet.rules = parseCss(text);
    this.applySheet(sheet);
    sheet.save();
  }
}
