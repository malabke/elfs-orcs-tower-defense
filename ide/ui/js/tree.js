// The Project tool window: the project's files as a tree, with Git colours, a context menu (new, rename,
// delete, copy path...), keyboard navigation and drag and drop to move files.
import { backend } from './backend.js';
import { showLocalHistory } from './localhistory.js';
import { el, clear, icon, colorIcon, basename, dirname, fileIcon, folderIcon, menu, prompt, confirm, notify, prefs, copyText } from './ui.js';

export class ProjectTree {
  constructor(app) {
    this.app = app;
    this.files = [];
    this.open = new Set(prefs.get('tree:' + app.projectKey, []));
    this.selected = null;
    this.showAll = false;
    this.node = el('div.tree', { tabIndex: 0, onkeydown: (e) => this.key(e) });
  }

  title() { return 'Project'; }

  /** A project shared with read rights only (the web IDE): nothing to create, rename or delete. */
  get readOnly() { return !!this.app.project?.capabilities?.readOnly; }

  refuseReadOnly() {
    if (this.readOnly) notify('Read only: this project is shared with you to read.', 'info');
    return this.readOnly;
  }

  actions() {
    return [
      this.readOnly ? null : el('button', { title: 'New file (Alt+Insert)', onclick: () => this.newFile(this.targetDir()) }, icon('plus')),
      el('button', { title: 'Select Opened File (Alt+F1)', onclick: () => this.reveal(this.app.editors.activePath(), true) }, icon('eye')),
      el(`label.tw-check${prefs.get('autoscroll', false) ? '.on' : ''}`, { title: 'Always Select Opened File: the Project view follows the file you are on' },
        el('input', { type: 'checkbox', checked: prefs.get('autoscroll', false), onchange: (e) => {
          prefs.set('autoscroll', e.target.checked);
          e.target.parentNode.classList.toggle('on', e.target.checked);
          if (e.target.checked) this.reveal(this.app.editors.activePath());
        } }), 'Follow'),
      el('button', { title: 'Collapse all', onclick: () => { this.open.clear(); this.save(); this.render(); } }, icon('collapse')),
      el('button', { title: 'Refresh', onclick: () => this.refresh() }, icon('refresh')),
    ].filter(Boolean);
  }

  async refresh() {
    const { files } = await backend.invoke('files.list', { all: this.showAll });
    this.files = files.map((f) => f.path);
    // listed again each time (a composer install adds some); the folders already listed keep their files
    const libraries = await backend.invoke('libraries.list').catch(() => null);
    if (libraries) {
      const known = new Map((this.libraries || []).map((l) => [l.dir, l]));
      this.libraries = libraries.map((l) => (known.get(l.dir)?.files?.length ? known.get(l.dir) : l));
    }
    this.render();
  }

  save() { prefs.set('tree:' + this.app.projectKey, [...this.open]); }

  /** {name, path, dir: bool, children: Map} */
  build() {
    const root = { name: this.app.project?.name || '', path: '', dir: true, children: new Map() };
    for (const path of this.files) {
      let node = root;
      const parts = path.split('/');
      parts.forEach((part, i) => {
        const p = parts.slice(0, i + 1).join('/');
        if (!node.children.has(part)) node.children.set(part, { name: part, path: p, dir: i < parts.length - 1, children: new Map() });
        node = node.children.get(part);
      });
    }
    // empty folders made in this session
    for (const dir of this.extraDirs || []) {
      let node = root;
      dir.split('/').forEach((part, i, parts) => {
        const p = parts.slice(0, i + 1).join('/');
        if (!node.children.has(part)) node.children.set(part, { name: part, path: p, dir: true, children: new Map() });
        node = node.children.get(part);
      });
    }
    return root;
  }

  render() {
    const scroll = this.node.parentNode?.scrollTop;
    clear(this.node);
    this.rows = [];
    const root = this.build();
    const rootRow = this.row(root, 0, true);
    this.node.append(rootRow);
    this.renderChildren(root, 1);
    this.renderLibraries();
    if (this.node.parentNode && scroll) this.node.parentNode.scrollTop = scroll;
  }

  // External Libraries (PhpStorm's): the engine and the packages the project's classes come from, read only --------

  renderLibraries() {
    const libs = (this.libraries || []).filter((l) => l.external || !this.files.some((f) => f.startsWith(l.dir + '/')));
    if (!libs.length) return;
    const key = 'lib:';
    const open = this.open.has(key);
    this.node.append(el(`div.row.ext-root${open ? '.open' : ''}`, { style: { paddingLeft: '6px' }, title: 'Classes the project uses from outside its folder (read only)',
      onclick: () => this.toggle(key) }, el('span.twisty'), icon('library'), el('span.label', {}, 'External Libraries')));
    if (!open) return;
    for (const lib of libs) {
      const libKey = key + lib.dir;
      const isOpen = this.open.has(libKey);
      this.node.append(el(`div.row.ext${isOpen ? '.open' : ''}`, { style: { paddingLeft: '22px' }, title: lib.dir, onclick: () => this.toggleLibrary(lib) },
        el('span.twisty'), icon('package'), el('span.label', {}, lib.prefix === 'JumPHP' ? 'JumPHP (engine)' : lib.prefix), el('span.hint', {}, lib.external ? '' : lib.dir)));
      if (isOpen && lib.files) this.renderLibraryDir(lib, this.libraryTree(lib), 2);
    }
  }

  async toggleLibrary(lib) {
    if (!lib.files) lib.files = await backend.invoke('libraries.files', { dir: lib.dir }).catch(() => []);
    this.toggle('lib:' + lib.dir);
  }

  libraryTree(lib) {
    if (lib.tree) return lib.tree;
    const root = { children: new Map() };
    for (const file of lib.files) {
      let node = root;
      file.split('/').forEach((part, i, parts) => {
        if (!node.children.has(part)) node.children.set(part, { name: part, path: parts.slice(0, i + 1).join('/'), dir: i < parts.length - 1, children: new Map() });
        node = node.children.get(part);
      });
    }
    return (lib.tree = root);
  }

  renderLibraryDir(lib, dir, depth) {
    const children = [...dir.children.values()].sort((a, b) => (b.dir - a.dir) || a.name.localeCompare(b.name));
    for (const child of children) {
      const key = 'lib:' + lib.dir + '/' + child.path;
      const full = lib.dir + '/' + child.path;
      const isOpen = this.open.has(key);
      this.node.append(el(`div.row.ext${child.dir && isOpen ? '.open' : ''}`, {
        style: { paddingLeft: 6 + depth * 16 + 'px' }, dataset: { path: full },
        onclick: () => (child.dir ? this.toggle(key) : this.app.editors.open(full, { preview: prefs.get('previewTabs', true) })),
      }, el(`span.twisty${child.dir ? '' : '.leaf'}`), child.dir ? folderIcon() : fileIcon(child.name), el('span.label', {}, child.name)));
      if (child.dir && isOpen) this.renderLibraryDir(lib, child, depth + 1);
    }
  }

  renderChildren(dir, depth) {
    const children = [...dir.children.values()].sort((a, b) => (b.dir - a.dir) || a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' }));
    for (const child of children) {
      // compact single-child folder chains (src/Scene -> one row) like PhpStorm's "compact middle packages"
      let node = child;
      let label = child.name;
      while (node.dir && node.children.size === 1 && [...node.children.values()][0].dir && !this.open.has(node.path + '/')) {
        node = [...node.children.values()][0];
        label += '/' + node.name;
      }
      this.node.append(this.row(node, depth, false, label));
      if (node.dir && this.open.has(node.path)) this.renderChildren(node, depth + 1);
    }
  }

  row(node, depth, isRoot, label = node.name) {
    const open = isRoot || this.open.has(node.path);
    const status = node.dir ? this.app.git?.folderStatus(node.path) : this.app.git?.statusOf(node.path);
    const ignored = !node.dir && /(^|\/)(var|dist|vendor)\//.test(node.path);
    const row = el(`div.row${open && node.dir ? '.open' : ''}${this.selected === node.path ? '.sel' : ''}${status ? '.st-' + status : ''}${ignored ? '.dim' : ''}`, {
      style: { paddingLeft: 6 + depth * 16 + 'px' },
      draggable: !isRoot,
      dataset: { path: node.path },
      onclick: (e) => {
        this.select(node.path);
        if (node.dir && !isRoot && (e.detail === 1 && e.target.closest('.twisty'))) this.toggle(node.path);
        else if (!node.dir) this.app.editors.open(node.path, { preview: prefs.get('previewTabs', true) });
      },
      ondblclick: () => { if (node.dir && !isRoot) this.toggle(node.path); else if (!node.dir) this.app.editors.open(node.path); },
      oncontextmenu: (e) => { e.preventDefault(); this.select(node.path); this.contextMenu(node, e); },
      ondragstart: (e) => { e.dataTransfer.setData('text/x-jumphp-path', node.path); },
      ondragover: (e) => { if (node.dir) { e.preventDefault(); row.classList.add('sel'); } },
      ondragleave: () => { if (this.selected !== node.path) row.classList.remove('sel'); },
      ondrop: (e) => { e.preventDefault(); const from = e.dataTransfer.getData('text/x-jumphp-path'); if (from && node.dir) this.move(from, node.path); },
    }, el(`span.twisty${node.dir ? '' : '.leaf'}`), node.dir ? folderIcon() : fileIcon(node.path), el('span.label', {}, isRoot ? (this.app.project?.name || 'project') : label),
    isRoot && this.app.project?.root ? el('span.hint', {}, this.app.project.root) : null);
    this.rows.push(node);
    return row;
  }

  toggle(path) {
    if (this.open.has(path)) this.open.delete(path); else this.open.add(path);
    this.save();
    this.render();
  }

  select(path) {
    this.selected = path;
    for (const r of this.node.querySelectorAll('.row')) r.classList.toggle('sel', r.dataset.path === path);
  }

  /** Opens the folders down to a file and selects it (a file of the project, or of an external library). */
  async reveal(path, show = false) {
    if (!path) return;
    if (show || this.app.open?.left !== 'project') {
      if (!show && this.app.open?.left !== 'project') return;     // following: only when the Project view is shown
      this.app.showTool?.('project');
    }
    const lib = (this.libraries || []).find((l) => path.startsWith(l.dir + '/') && (l.external || !this.files.includes(path)));
    if (lib) {
      if (!lib.files) lib.files = await backend.invoke('libraries.files', { dir: lib.dir }).catch(() => []);
      const parts = path.slice(lib.dir.length + 1).split('/');
      this.open.add('lib:');
      this.open.add('lib:' + lib.dir);
      for (let i = 1; i < parts.length; i++) this.open.add('lib:' + lib.dir + '/' + parts.slice(0, i).join('/'));
      this.save();
      this.selected = path;
      this.render();
      this.node.querySelector(`.row[data-path="${CSS.escape(path)}"]`)?.classList.add('sel');
      this.node.querySelector(`.row[data-path="${CSS.escape(path)}"]`)?.scrollIntoView({ block: 'center' });
      return;
    }
    if (path.startsWith('/')) return;
    const parts = path.split('/');
    for (let i = 1; i < parts.length; i++) this.open.add(parts.slice(0, i).join('/'));
    this.save();
    this.selected = path;
    this.render();
    this.node.querySelector(`.row[data-path="${CSS.escape(path)}"]`)?.scrollIntoView({ block: 'center' });
  }

  targetDir() {
    if (this.selected === null || this.selected === '') return '';
    return this.files.includes(this.selected) ? dirname(this.selected) : this.selected;
  }

  contextMenu(node, e) {
    const dir = node.dir ? node.path : dirname(node.path);
    const module = this.app.project?.modules?.find((m) => m.dir && (node.path + '/').startsWith(m.dir + '/'));
    const write = !this.readOnly;
    menu([
      !write ? null : { label: 'New', icon: 'plus', items: [
        { label: 'File…', icon: 'file', action: () => this.newFile(dir) },
        { label: 'PHP Class…', action: () => this.newFile(dir, '.php', 'Class name (Hero, Scene/PlayScene, Entity/Coin…)') },
        { label: 'Directory…', icon: 'folder', action: () => this.newDirectory(dir) },
        { label: 'HTML Page…', action: () => this.newFile(dir, '.html') },
        '-',
        { label: 'Sprite (PNG)…', action: () => this.newSprite(dir) },
        { label: 'Sound Effect…', action: () => this.newFile(dir, '.sfx.json', 'Name (jump, coin…): a .wav is written next to it') },
        { label: 'Music…', action: () => this.newFile(dir, '.music.json', 'Name (level1, title…): a .wav is written next to it') },
        module ? '-' : null,
        module ? { label: 'Scene', action: () => this.newFile((module.dir ? module.dir + '/' : '') + 'src/Scene', '.php', 'Scene class name', 'Scene') } : null,
        module ? { label: 'Entity', action: () => this.newFile((module.dir ? module.dir + '/' : '') + 'src/Entity', '.php', 'Entity class name') } : null,
        module ? { label: 'Game Test', action: () => this.newFile((module.dir ? module.dir + '/' : '') + 'tests', '.php', 'Test class name (JumpTest…)', 'Test') } : null,
      ].filter(Boolean) },
      write ? '-' : null,
      !node.dir && this.app.playTarget?.(node.path) ? { label: this.app.playTarget(node.path).label, icon: colorIcon('play', 'green'), kbd: 'Ctrl+Shift+F10', action: () => this.app.playFile(node.path) } : null,
      !node.dir && this.app.playTarget?.(node.path) ? '-' : null,
      !node.dir ? { label: 'Open', action: () => this.app.editors.open(node.path) } : null,
      !node.dir ? { label: 'Open to the Right', icon: 'split', action: () => { this.app.editors.split(); this.app.editors.open(node.path); } } : null,
      { label: 'Find in Directory…', icon: 'find', action: () => this.app.findInFiles?.({ include: dir ? dir + '/*' : '' }) },
      '-',
      { label: 'Copy Path', action: () => copyText(node.path) },
      { label: 'Copy Name', action: () => copyText(basename(node.path)) },
      !node.dir && this.app.project?.capabilities?.localHistory ? { label: 'Show Local History', icon: 'history', action: () => showLocalHistory(this.app, node.path) } : null,
      write && node.path ? { label: 'Rename…', kbd: 'Shift+F6', action: () => this.rename(node.path) } : null,
      write && node.path ? { label: 'Delete…', kbd: 'Delete', action: () => this.remove(node.path) } : null,
      this.app.git?.enabled && node.path ? '-' : null,
      this.app.git?.enabled && node.path ? { label: 'Git', icon: 'branch', items: [
        { label: 'Show Diff', icon: 'diff', action: () => this.app.git.diff(node.path), disabled: node.dir },
        { label: 'Show History', icon: 'history', action: () => this.app.git.showLog(node.path) },
        { label: 'Rollback…', icon: 'rollback', action: () => this.app.git.rollback([node.path]) },
        { label: 'Add to Git', action: () => this.app.git.stage([node.path]) },
      ] } : null,
      '-',
      { label: 'Show Hidden & Build Files', checked: this.showAll, action: () => { this.showAll = !this.showAll; this.refresh(); } },
    ].filter(Boolean), { x: e.clientX, y: e.clientY });
  }

  async newFile(dir, suffix = '', label = 'File name (folders with /)', append = '') {
    if (this.refuseReadOnly()) return;
    let name = await prompt(suffix === '.php' ? 'New PHP Class' : 'New File', { label: dir ? `In ${dir}/` : 'At the project root', placeholder: label });
    if (!name) return;
    name = name.trim();
    if (append && !name.endsWith(append)) name += append;
    if (suffix && !name.endsWith(suffix)) name += suffix;
    const path = (dir ? dir + '/' : '') + name;
    try {
      await backend.invoke('file.create', { path });
      this.files.push(path);
      this.reveal(path);
      this.app.editors.open(path);
      this.app.git?.refresh();
    } catch (e) { notify(e.message, 'error'); }
  }

  /** A blank PNG of the size asked (the sprite editor opens on it). */
  async newSprite(dir) {
    if (this.refuseReadOnly()) return;
    const name = await prompt('New Sprite', { label: dir ? `In ${dir}/` : 'At the project root', placeholder: 'hero 16x16 (name, then the size of a frame)' });
    if (!name) return;
    const m = name.trim().match(/^(.*?)(?:\s+(\d+)\s*[x×]\s*(\d+))?$/);
    const [w, h] = [Math.min(512, Number(m[2]) || 16), Math.min(512, Number(m[3]) || Number(m[2]) || 16)];
    const path = (dir ? dir + '/' : '') + m[1].trim().replace(/\.png$/i, '') + '.png';
    const canvas = Object.assign(document.createElement('canvas'), { width: w, height: h });
    const bytes = new Uint8Array(await (await new Promise((r) => canvas.toBlob(r, 'image/png'))).arrayBuffer());
    try {
      const { base64 } = await import('./res-sound.js');
      await backend.invoke('file.create', { path, base64: base64(bytes) });
      this.files.push(path);
      this.reveal(path);
      const tab = await this.app.editors.open(path);
      if (tab) { tab.mode = 'edit'; this.app.editors.renderView(this.app.editors.focused, tab); }
      this.app.git?.refresh();
    } catch (e) { notify(e.message, 'error'); }
  }

  async newDirectory(dir) {
    if (this.refuseReadOnly()) return;
    const name = await prompt('New Directory', { label: dir ? `In ${dir}/` : 'At the project root' });
    if (!name) return;
    const path = (dir ? dir + '/' : '') + name.trim().replace(/\/+$/, '');
    this.extraDirs = [...(this.extraDirs || []), path];
    this.open.add(path);
    this.reveal(path);
  }

  async rename(path) {
    if (this.refuseReadOnly()) return;
    const name = basename(path);
    const dot = name.lastIndexOf('.');
    const target = await prompt('Rename', { value: name, label: path, select: [0, dot > 0 ? dot : name.length], ok: 'Rename' });
    if (!target || target === name) return;
    const to = (dirname(path) ? dirname(path) + '/' : '') + target;
    await this.app.editors.saveAll(true);
    try {
      await backend.invoke('file.rename', { from: path, to });
      this.app.editors.renamed(path, to);
      await this.refresh();
      this.reveal(to);
      this.app.git?.refresh();
    } catch (e) { notify(e.message, 'error'); }
  }

  async move(from, toDir) {
    const to = (toDir ? toDir + '/' : '') + basename(from);
    if (to === from || to.startsWith(from + '/')) return;
    if (!(await confirm('Move', `Move ${from} to ${toDir || 'the project root'}/?`, { ok: 'Move' }))) return;
    try {
      await backend.invoke('file.rename', { from, to });
      this.app.editors.renamed(from, to);
      await this.refresh();
      this.app.git?.refresh();
    } catch (e) { notify(e.message, 'error'); }
  }

  async remove(path) {
    if (this.refuseReadOnly()) return;
    const isDir = !this.files.includes(path);
    if (!(await confirm('Delete', `Delete ${isDir ? 'the folder' : ''} ${path}${isDir ? ' and everything in it' : ''}?`, { ok: 'Delete', danger: true }))) return;
    try {
      await backend.invoke('file.delete', { path });
      this.app.editors.renamed(path, null);
      await this.refresh();
      this.app.git?.refresh();
    } catch (e) { notify(e.message, 'error'); }
  }

  key(e) {
    const rows = [...this.node.querySelectorAll('.row')];
    const index = rows.findIndex((r) => r.dataset.path === this.selected);
    const path = this.selected;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      const next = rows[Math.max(0, Math.min(rows.length - 1, index + (e.key === 'ArrowDown' ? 1 : -1)))];
      if (next) { this.select(next.dataset.path); next.scrollIntoView({ block: 'nearest' }); }
      e.preventDefault();
    } else if (e.key === 'ArrowRight' && path !== null && !this.files.includes(path)) {
      if (!this.open.has(path)) this.toggle(path);
    } else if (e.key === 'ArrowLeft' && path) {
      if (this.open.has(path)) this.toggle(path); else this.select(dirname(path));
    } else if (e.key === 'Enter' && path && this.files.includes(path)) {
      this.app.editors.open(path);
    } else if (e.key === 'F6' && e.shiftKey && path) {
      this.rename(path);
    } else if (e.key === 'Delete' && path) {
      this.remove(path);
    } else if (e.key === 'Insert' && e.altKey) {
      this.newFile(this.targetDir());
    }
  }
}
