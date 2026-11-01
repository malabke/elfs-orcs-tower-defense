// The desktop app's welcome screen (no project open): sign in with jumphp.net, your playground projects (clone and
// open), recent projects, open a folder, and New Project (game, desktop app, hybrid, Amstrad CPC remake, port).
import { backend } from './backend.js';
import { el, clear, icon, notify, dialog, timeAgo, confirm, basename } from './ui.js';

const KINDS = [
  { id: 'original', icon: 'game', title: 'Game', text: 'An original retro game in PHP: desktop, browser, phones. Published on jumphp.net.', playground: true },
  { id: 'app', icon: 'app', title: 'Desktop app', text: 'HTML / CSS pages with PHP behind them (Tauri): a tool, an editor, a utility.', playground: false },
  { id: 'hybrid', icon: 'hybrid', title: 'Hybrid game', text: 'An SDL game with HTML windows beside it (control panel, editor).', playground: false },
  { id: 'cpc', icon: 'disk', title: 'Amstrad CPC remake', text: 'Remake an Amstrad CPC game from its .dsk disc: same graphics, music and rules.', playground: true },
  { id: 'port', icon: 'fork', title: 'Port of an open-source game', text: 'Port a game whose source code is open (C, JS, Python…) to PHP.', playground: true },
];

export class Welcome {
  constructor(app, node) {
    this.app = app;
    this.node = node;
    this.section = 'projects';
    this.state = null;
    this.remote = null;
    this.filter = '';
    backend.on('clone.output', (p) => { if (this.progress) this.progress.textContent = String(p.chunk).trim().split(/\r|\n/).pop(); });
  }

  async show() {
    this.node.hidden = false;
    this.state = await backend.invoke('session.state');
    this.render();
    if (this.state.user) this.loadRemote();
  }

  hide() { this.node.hidden = true; }

  async loadRemote() {
    try {
      this.remote = await backend.invoke('projects.remote');
    } catch (e) {
      this.remote = { error: e.message };
    }
    this.render();
  }

  render() {
    const s = this.state;
    clear(this.node);
    const nav = (id, label, ico) => el(`button${this.section === id ? '.on' : ''}`, { onclick: () => { this.section = id; this.render(); } }, icon(ico), label);
    const side = el('div.w-side', {},
      el('div.w-brand', {}, el('img', { src: 'img/icon.png', alt: '' }), el('div', {}, el('b', {}, 'JumPHP IDE'), el('small', {}, s.version ? 'version ' + s.version : ''))),
      el('div.w-nav', {}, nav('projects', 'Projects', 'folder'), nav('new', 'New Project', 'plus'), nav('learn', 'Learn', 'star')),
      el('div.w-user', {}, s.user
        ? [el('img', { src: s.user.avatar || '', alt: '' }), el('div', { style: { flex: 1, minWidth: 0 } }, el('b', {}, s.user.login), el('div', { style: { fontSize: '12px', color: 'var(--text-3)' } }, s.server)),
          el('button.icon-btn', { title: 'Sign out', onclick: () => this.logout() }, icon('logout'))]
        : el('button.btn.primary', { style: { width: '100%' }, onclick: () => this.login() }, icon('user'), 'Sign in with jumphp.net')));
    const main = el('div.w-main');
    if (this.section === 'projects') this.renderProjects(main);
    else if (this.section === 'new') this.renderNew(main);
    else this.renderLearn(main);
    this.node.append(side, main);
  }

  renderProjects(main) {
    const s = this.state;
    main.append(el('h1', {}, 'Welcome to JumPHP IDE'), el('p.lead', {}, 'Edit your games and apps written in PHP: completion, navigation, refactoring, Git, tests, visual HTML editor.'),
      el('div.w-actions', {},
        el('button.btn.primary', { onclick: () => { this.section = 'new'; this.render(); } }, icon('plus'), 'New Project'),
        el('button.btn', { onclick: () => this.openFolder() }, icon('folder'), 'Open Folder…'),
        el('button.btn', { onclick: () => this.cloneUrl() }, icon('download'), 'Clone Repository…')));
    const search = el('input.input.w-search', { placeholder: 'Search projects', value: this.filter, oninput: (e) => { this.filter = e.target.value; this.renderList(list); } });
    const list = el('div');
    main.append(search, list);
    this.renderList(list);
  }

  renderList(list) {
    clear(list);
    const s = this.state;
    const q = this.filter.toLowerCase();
    const match = (...texts) => !q || texts.join(' ').toLowerCase().includes(q);
    const recent = (s.recent || []).filter((r) => match(r.name, r.path));
    if (recent.length) {
      list.append(el('h2', {}, 'Recent'));
      for (const r of recent) {
        list.append(el('div.proj', { onclick: () => this.open(r.path), title: r.path },
          el('span.avatar-letter', {}, r.name[0].toUpperCase()), el('div.p-main', {}, el('div.p-name', {}, r.name), el('div.p-path', {}, r.path)),
          r.slug ? el('span.p-tag', {}, 'jumphp.net') : null, el('span.p-path', {}, r.opened ? timeAgo(r.opened) : ''),
          el('button.icon-btn', { title: 'Remove from the list', onclick: async (e) => { e.stopPropagation(); await backend.invoke('projects.forget', { path: r.path }); this.show(); } }, icon('close'))));
      }
    }
    list.append(el('h2', {}, 'Your projects on jumphp.net'));
    if (!s.user) {
      list.append(el('div.proj', { onclick: () => this.login() }, icon('user'), el('div.p-main', {}, el('div.p-name', {}, 'Sign in to see your projects'),
        el('div.p-path', {}, 'Your games on the JumPHP playground (your GitHub repositories), cloned and pushed for you.'))));
      return;
    }
    if (!this.remote) { list.append(el('div.placeholder', {}, el('span.spinner'), ' Loading…')); return; }
    if (this.remote.error) { list.append(el('div.placeholder', {}, this.remote.error)); return; }
    const projects = this.remote.filter((p) => match(p.title, p.slug, p.description || ''));
    if (!projects.length) list.append(el('div.placeholder', {}, 'No project yet. Create one with New Project.'));
    for (const p of projects) {
      list.append(el('div.proj', { onclick: () => (p.localPath ? this.open(p.localPath) : this.clone(p)), title: p.repoUrl || '' },
        el('span.avatar-letter', {}, (p.title || p.slug)[0].toUpperCase()),
        el('div.p-main', {}, el('div.p-name', {}, p.title || p.slug), el('div.p-path', {}, p.localPath || p.description || p.repoUrl || '')),
        el('span.p-tag', {}, { original: 'game', cpc: 'CPC remake', port: 'port' }[p.kind] || p.kind || 'game'),
        p.status && p.status !== 'ready' && p.status !== 'published' ? el('span.p-tag', {}, p.status) : null,
        el('button.btn.small', { onclick: (e) => { e.stopPropagation(); p.localPath ? this.open(p.localPath) : this.clone(p); } }, p.localPath ? 'Open' : 'Clone')));
    }
  }

  renderNew(main) {
    let kind = this.kind || 'original';
    main.append(el('h1', {}, 'New Project'), el('p.lead', {}, 'What do you want to make?'));
    const kinds = el('div.kinds');
    const form = el('div', { style: { maxWidth: '620px' } });
    const draw = () => {
      clear(kinds);
      for (const k of KINDS) kinds.append(el(`button.kind${k.id === kind ? '.on' : ''}`, { onclick: () => { kind = this.kind = k.id; draw(); } }, icon(k.icon), el('b', {}, k.title), el('small', {}, k.text)));
      this.renderForm(form, KINDS.find((k) => k.id === kind));
    };
    main.append(kinds, form);
    draw();
  }

  async renderForm(form, kind) {
    clear(form);
    const s = this.state;
    const title = el('input.input', { placeholder: kind.id === 'app' ? 'Notes' : 'Star Hopper' });
    const description = el('input.input', { placeholder: 'One sentence: what it is' });
    const brief = el('textarea.input', { rows: 5, placeholder: kind.id === 'cpc' ? 'Which game, what to keep from the original…' : 'What it should be: the idea, the rules, the controls… (your Claude reads it in BRIEF.md)' });
    const folder = el('input.input', { value: s.projectsDir || '' });
    const extra = el('div');
    let category = null;
    if (kind.id === 'cpc') {
      // the disc (required), and the CPC ROMs only when the game needs the firmware (AGENTS.md §12): kept on this computer
      const pick = async (input, options) => { const f = await backend.invoke('native.open', options).catch(() => null); if (f) { input.value = f; input.dispatchEvent(new Event('input')); } return f; };
      const dsk = el('input.input', { placeholder: '/home/me/discs/game.dsk' });
      const roms = el('input.input', { placeholder: 'Optional: a folder with the CPC 6128 ROMs, only if the game needs the firmware' });
      if (!title.value) dsk.addEventListener('input', () => { if (!title.value.trim()) title.value = basename(dsk.value).replace(/\.dsk$/i, '').replace(/[_-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()); });
      extra.append(
        el('label.field', {}, el('span', {}, 'The .dsk disc (on this computer: never published, never committed)'), el('div', { style: { display: 'flex', gap: '6px' } }, dsk,
          el('button.btn.primary', { onclick: () => pick(dsk, { filters: { 'Amstrad CPC disc': ['dsk', 'DSK'] } }) }, icon('disk'), 'Choose the .dsk…'))),
        el('label.field', {}, el('span', {}, 'CPC ROMs (optional)'), el('div', { style: { display: 'flex', gap: '6px' } }, roms,
          el('button.btn', { onclick: () => pick(roms, { folder: true }) }, 'Choose…'))),
        el('p', { style: { color: 'var(--text-3)', fontSize: '12px', margin: '0' } }, 'The extraction (graphics, maps, music) is done with dsk2jumphp, from the disc: your Claude finds both paths in .jumphp/ide.json.'));
      extra.dsk = dsk;
      extra.roms = roms;
      extra.pick = () => pick(dsk, { filters: { 'Amstrad CPC disc': ['dsk', 'DSK'] } });
    }
    if (kind.id === 'port') {
      const source = el('input.input', { placeholder: 'https://github.com/someone/their-game' });
      extra.append(el('label.field', {}, el('span', {}, 'Source code of the original (git URL)'), source));
      extra.source = source;
    }
    if (kind.playground) {
      if (!s.user) {
        form.append(el('p', {}, 'A game is a project on jumphp.net (a GitHub repository of yours, published after each push).'),
          el('button.btn.primary', { onclick: () => this.login() }, icon('user'), 'Sign in with jumphp.net'));
        return;
      }
      category = el('select.input');
      backend.invoke('projects.categories').then((list) => { for (const c of list) category.append(el('option', { value: c.slug }, c.name)); }).catch(() => null);
    }
    form.append(
      el('label.field', {}, el('span', {}, 'Title'), title),
      el('label.field', {}, el('span', {}, 'Description'), description),
      category ? el('label.field', {}, el('span', {}, 'Category on jumphp.net'), category) : null,
      extra,
      el('label.field', {}, el('span', {}, 'Brief'), brief),
      el('label.field', {}, el('span', {}, 'Folder (the project goes in a new folder inside)'), el('div', { style: { display: 'flex', gap: '6px' } }, folder,
        el('button.btn', { onclick: async () => { const f = await backend.invoke('native.open', { folder: true }).catch(() => null); if (f) folder.value = f; } }, 'Choose…'))),
      el('p', { style: { color: 'var(--text-3)', fontSize: '12px' } }, kind.playground
        ? 'Creates the GitHub repository through jumphp.net (from the JumPHP template, with BRIEF.md), clones it here and opens it.'
        : 'Makes a JumPHP project in a new folder (git clone of the template + bin/jumphp app:new), then opens it. It can be pushed to GitHub later.'),
      el('div', { style: { display: 'flex', gap: '8px', alignItems: 'center' } }, el('button.btn.primary', { onclick: async (e) => {
        const button = e.currentTarget;
        if (extra.dsk && !extra.dsk.value.trim() && !(await extra.pick())) { notify('Choose the .dsk disc of the game to remake.', 'warning', { title: 'Amstrad CPC remake' }); return; }
        if (!title.value.trim()) { title.focus(); return; }
        button.disabled = true;
        this.progress = el('span', { style: { color: 'var(--text-2)', fontSize: '12px' } }, 'Creating…');
        button.after(this.progress);
        try {
          const result = await backend.invoke('projects.create', { kind: kind.id, title: title.value.trim(), description: description.value.trim(), brief: brief.value.trim(),
            category: category?.value, folder: folder.value.trim(), dsk: extra.dsk?.value, roms: extra.roms?.value || undefined, source: extra.source?.value });
          await this.app.projectOpened(result);
        } catch (err) {
          notify(err.message, 'error', { title: 'New project' });
          button.disabled = false;
          this.progress?.remove();
        }
      } }, 'Create')));
  }

  renderLearn(main) {
    const link = (label, url, text) => el('div.proj', { onclick: () => this.app.openUrl(url) }, icon('globe'), el('div.p-main', {}, el('div.p-name', {}, label), el('div.p-path', {}, text)));
    main.append(el('h1', {}, 'Learn'), el('p.lead', {}, 'JumPHP: write retro games and desktop apps in PHP.'),
      link('Tutorial', 'https://jumphp.net/docs', 'Your first game, step by step'),
      link('The playground', 'https://jumphp.net', 'Games made with JumPHP, playable in the browser'),
      link('JumPHP on GitHub', 'https://github.com/davidnurdin/jumPhp', 'Sources, documentation, issues'),
      el('h2', {}, 'Keyboard'), el('div.empty-editor', { style: { height: 'auto', alignItems: 'flex-start' } }, el('div.hints', {},
        ...[['Search Everywhere', 'Double Shift'], ['Go to Class', 'Ctrl+N'], ['Go to File', 'Ctrl+Shift+N'], ['Go to Declaration', 'Ctrl+B'], ['Find Usages', 'Alt+F7'],
          ['Rename', 'Shift+F6'], ['Commit', 'Ctrl+K'], ['Run', 'Shift+F10']].flatMap(([a, b]) => [el('span', {}, a), el('kbd', {}, b)]))));
  }

  async login() {
    const waiting = dialog({ title: 'Sign in with jumphp.net', body: el('p', {}, 'Your browser opens jumphp.net: sign in with GitHub and authorize the IDE. Then come back here.'), buttons: [{ label: 'Cancel', action: () => backend.invoke('session.cancelLogin') }] });
    try {
      await backend.invoke('session.login');
      waiting.close();
      notify('Signed in', 'success');
      this.show();
    } catch (e) {
      waiting.close();
      if (!/cancel/i.test(e.message)) notify(e.message, 'error', { title: 'Sign in' });
    }
  }

  async logout() {
    if (!(await confirm('Sign out', 'Sign out of jumphp.net on this computer?', { ok: 'Sign out' }))) return;
    await backend.invoke('session.logout');
    this.remote = null;
    this.show();
  }

  async open(path) {
    try { await this.app.projectOpened(await backend.invoke('projects.open', { path })); }
    catch (e) { notify(e.message, 'error', { title: 'Open project' }); }
  }

  async openFolder() {
    const path = await backend.invoke('native.open', { folder: true }).catch(() => null);
    if (path) this.open(path);
  }

  async clone(project) {
    const progress = el('div', { style: { color: 'var(--text-2)', fontFamily: 'var(--mono)', fontSize: '12px', minHeight: '18px' } }, 'git clone…');
    this.progress = progress;
    const d = dialog({ title: 'Cloning ' + (project.title || project.slug), body: el('div', {}, el('p', {}, project.cloneUrl || ''), progress), buttons: [] });
    try {
      const result = await backend.invoke('projects.clone', { slug: project.slug, cloneUrl: project.cloneUrl });
      d.close();
      await this.app.projectOpened(result);
    } catch (e) {
      d.close();
      notify(e.message, 'error', { title: 'Clone failed' });
    }
  }

  async cloneUrl() {
    const url = el('input.input', { placeholder: 'https://github.com/owner/repository.git' });
    dialog({ title: 'Clone Repository', body: el('div', {}, el('label.field', {}, el('span', {}, 'URL'), url)), buttons: [{ label: 'Cancel' },
      { label: 'Clone', primary: true, action: () => { if (!url.value.trim()) return false; this.clone({ cloneUrl: url.value.trim(), title: url.value.trim().split('/').pop().replace(/\.git$/, '') }); } }] });
  }
}
