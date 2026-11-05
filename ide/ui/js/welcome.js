// The desktop app's welcome screen (no project open): sign in with jumphp.net, your playground projects (clone and
// open), recent projects, open a folder, and New Project (game, desktop app, hybrid, Amstrad CPC remake, port).
import { backend } from './backend.js';
import { el, clear, icon, notify, dialog, timeAgo, confirm, basename, progressBar, menu } from './ui.js';

const KINDS = [
  { id: 'original', game: true, icon: 'game', title: 'Game', text: 'An original retro game in PHP: desktop, browser, phones. Published on jumphp.net.', playground: true },
  { id: 'gui', icon: 'app', title: 'Graphic app (no HTML)', text: 'Windows made in the visual editor: buttons, lists, pictures, a responsive grid; the code behind in PHP. PC, Mac, web.', playground: true },
  { id: 'gui-mobile', icon: 'app', title: 'Mobile app', text: 'The same for phones (iOS and Android): portrait forms made in the visual editor, exported as native apps.', playground: true },
  { id: 'app', icon: 'app', title: 'Desktop app', text: 'HTML / CSS pages with PHP behind them (Tauri): a tool, an editor, a utility.', playground: true },
  { id: 'cli', icon: 'terminal', title: 'CLI app', text: 'A program in the terminal: commands, and full-screen text screens (Symfony Console + symfony/tui). One executable per system.', playground: true },
  { id: 'extension', icon: 'plugin', title: 'Engine extension', text: 'A Composer package that adds to the JumPHP engine (particles, physics, a tile format...), with an example game that tests it. Pure PHP, works in the browser too.', playground: true },
  { id: 'plugin', icon: 'plugin', title: 'IDE plugin', text: 'A plugin of this IDE, in PHP: actions, tool windows, completion, problems, run configurations. Tested without a window, shared on jumphp.net.', playground: true },
  { id: 'hybrid', game: true, icon: 'hybrid', title: 'Hybrid game', text: 'An SDL game with HTML windows beside it (control panel, editor).', playground: true },
  { id: 'cpc', game: true, icon: 'disk', title: 'Amstrad CPC remake', text: 'Remake an Amstrad CPC game from its .dsk disc: same graphics, music and rules.', playground: true },
  { id: 'port', game: true, icon: 'fork', title: 'Port of an open-source game', text: 'Port a game whose source code is open (C, JS, Python…) to PHP.', playground: true },
];

export class Welcome {
  constructor(app, node) {
    this.app = app;
    this.node = node;
    this.section = 'projects';
    this.state = null;
    this.remote = null;
    this.filter = '';
    // what the creation / the clone does now, with git's percentage as a bar ("Receiving objects:  45% (…)")
    backend.on('clone.output', (p) => {
      if (!this.progress) return;
      const line = String(p.chunk).trim().split(/\r|\n/).pop();
      const percent = [...line.matchAll(/(\d{1,3})%/g)].pop();
      clear(this.progress).append(el('span.spinner'), el('span', {}, line), progressBar(percent ? Math.min(100, +percent[1]) / 100 : null));
    });
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
      el('div.w-nav', {}, nav('projects', 'Projects', 'folder'), nav('new', 'New Project', 'plus'), nav('news', 'News', 'bell'), nav('learn', 'Learn', 'star')),
      el('div.w-user', {}, s.user
        ? [el('img', { src: s.user.avatar || '', alt: '' }), el('div', { style: { flex: 1, minWidth: 0, cursor: 'pointer' }, title: 'GitHub accounts', onclick: (e) => this.accountsMenu(e.currentTarget) }, el('b', {}, s.user.login), el('div', { style: { fontSize: '12px', color: 'var(--text-3)' } }, s.server)),
          el('button.icon-btn', { title: 'Sign out', onclick: () => this.logout() }, icon('logout'))]
        : el('button.btn.primary', { style: { width: '100%' }, onclick: () => this.login() }, icon('user'), 'Sign in with jumphp.net')));
    const main = el('div.w-main');
    if (this.section === 'projects') this.renderProjects(main);
    else if (this.section === 'new') this.renderNew(main);
    else if (this.section === 'news') this.renderNews(main);
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
          r.slug ? el('span.p-tag', {}, 'jumphp.net') : null, r.kind === 'extension' ? el('span.p-tag', { title: 'An engine extension' }, 'extension') : null, el('span.p-path', {}, r.opened ? timeAgo(r.opened) : ''),
          el('button.icon-btn.trash', { title: 'Delete the project from this computer…', onclick: (e) => { e.stopPropagation(); this.deleteProject(r); } }, icon('trash')),
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
        p.shared ? el('span.p-tag', { title: 'Shared with you by its owner' }, p.role === 'write' ? 'shared · write' : 'shared · read') : null,
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
    const title = el('input.input', { placeholder: kind.id === 'app' ? 'Notes' : kind.id === 'cli' ? 'Todo' : kind.id === 'plugin' ? 'Todo Finder' : kind.id === 'extension' ? 'Wind Effects' : kind.id.startsWith('gui') ? 'Contacts' : 'Star Hopper' });
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
    const shared = kind.id === 'plugin';
    if (kind.playground) {
      // a game or an application is made on this computer (no account needed); a plugin / extension gets its GitHub repository at once
      if (!s.user && shared) {
        form.append(el('p', {}, 'This project gets its own GitHub repository, on your account, right away: sign in with GitHub through jumphp.net.'),
          el('button.btn.primary', { onclick: () => this.login() }, icon('user'), 'Sign in with jumphp.net'));
        return;
      }
    }
    if (kind.game && shared) {
      category = el('select.input');
      backend.invoke('projects.categories').then((list) => { for (const c of list) category.append(el('option', { value: c.slug }, c.name)); }).catch(() => null);
    }
    form.append(
      el('label.field', {}, el('span', {}, 'Title'), title),
      el('label.field', {}, el('span', {}, 'Description'), description),
      category && shared ? el('label.field', {}, el('span', {}, 'Category on jumphp.net'), category) : null,
      extra,
      el('label.field', {}, el('span', {}, 'Brief'), brief),
      el('label.field', {}, el('span', {}, 'Folder (the project goes in a new folder inside)'), el('div', { style: { display: 'flex', gap: '6px' } }, folder,
        el('button.btn', { onclick: async () => { const f = await backend.invoke('native.open', { folder: true }).catch(() => null); if (f) folder.value = f; } }, 'Choose…'))),
      el('p', { style: { color: 'var(--text-3)', fontSize: '12px' } }, kind.id === 'extension'
        ? 'Made on this computer: the package (extensions/<name>) and its example game (games/<name>-demo), nothing online. “Publish extension” then puts the package in its own GitHub repository (tagged) and you submit it on Packagist.'
        : kind.id === 'plugin'
        ? `Creates the public GitHub repository ${s.user?.login || 'you'}/<name> on your account, makes the plugin there (bin/jumphp plugin:new), pushes it and opens it. Share it with Tools > Plugins… > Publish on jumphp.net.`
        : 'Made on this computer, from the JumPHP template, with BRIEF.md: nothing goes online until you want it. Publish (or “Create its GitHub repository”) then puts it on your GitHub account and on jumphp.net.'),
      el('div', { style: { display: 'flex', gap: '8px', alignItems: 'center' } }, el('button.btn.primary', { onclick: async (e) => {
        const button = e.currentTarget;
        if (extra.dsk && !extra.dsk.value.trim() && !(await extra.pick())) { notify('Choose the .dsk disc of the game to remake.', 'warning', { title: 'Amstrad CPC remake' }); return; }
        if (!title.value.trim()) { title.focus(); return; }
        button.disabled = true;
        this.progress = el('span.welcome-progress', {}, el('span.spinner'), el('span', {}, 'Creating…'), progressBar(null));
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

  /** The news of JumPHP (jumphp.net/nouveautes) and the IDE's own updates: the version, check, download, restart. */
  renderNews(main) {
    const native = backend.kind === 'native';
    const card = el('div.empty-editor', { style: { height: 'auto', alignItems: 'flex-start', marginBottom: '14px' } });
    const draw = (st) => {
      clear(card);
      if (!native || !st) { card.append(el('div', {}, 'Updates: the web IDE is always the newest version.')); return; }
      const line = (text, ...actions) => card.append(el('div', { style: { display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap' } }, el('span', {}, text), ...actions));
      const check = () => el('button.btn', { onclick: async () => { draw({ ...st, state: 'checking' }); draw(await backend.invoke('update.check').catch((e) => ({ ...st, state: 'error', error: e.message }))); } }, 'Check for updates');
      if (st.state === 'checking' || st.state === 'installing') line(st.state === 'installing' ? `Downloading JumPHP IDE ${st.version || ''}…` : 'Looking for a newer version…');
      else if (st.state === 'installed') line(`JumPHP IDE ${st.version} is installed.`, el('button.btn.primary', { onclick: () => backend.invoke('update.restart') }, 'Restart now'));
      else if (st.state === 'available') line(`JumPHP IDE ${st.version} is out (this one: ${st.current}).`, st.installable ? el('button.btn.primary', { onclick: async () => draw(await backend.invoke('update.install')) }, 'Download and install') : el('span.pub-faint', {}, 'Run from its sources: update them with git.'));
      else if (st.state === 'error') line(st.error || 'Cannot check for updates.', check());
      else line(`JumPHP IDE ${st.current || ''}${st.state === 'latest' ? ' is the newest version.' : ''}`, check());
      if (st.notes && st.state === 'available') card.append(el('div.pub-faint', {}, st.notes));
    };
    draw(null);
    if (native) {
      backend.invoke('update.state').then(draw).catch(() => draw(null));
      this.offUpdate?.();
      this.offUpdate = backend.on('update.state', (st) => { if (this.section === 'news') draw(st); });
    }
    const list = el('div', {}, el('p.pub-faint', {}, 'Loading the news…'));
    backend.invoke('whatsnew.get', { lang: (navigator.language || 'en').slice(0, 2).toLowerCase() }).then((entries) => {
      clear(list);
      if (!entries?.length) { list.append(el('p.pub-faint', {}, 'jumphp.net cannot be reached right now.')); return; }
      for (const e of entries.slice(0, 6)) {
        list.append(el('h3', {}, e.title), el('p.pub-faint', {}, `JumPHP ${e.version} · IDE ${e.ide} · ${e.date}`), el('ul', {}, ...e.items.map((i) => el('li', {}, i))));
      }
    }).catch(() => { clear(list); list.append(el('p.pub-faint', {}, 'jumphp.net cannot be reached right now.')); });
    main.append(el('h1', {}, 'News'), el('p.lead', {}, 'What is new in JumPHP and its IDE.'), card, list,
      el('p', {}, el('button.btn', { onclick: () => this.app.openUrl('https://jumphp.net/nouveautes') }, icon('globe'), 'All the news on jumphp.net')));
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

  /** Several GitHub accounts on this computer: the one that pushes and publishes (each project remembers its own). */
  async accountsMenu(at) {
    const { accounts, active } = await backend.invoke('session.accounts');
    menu([{ head: 'GitHub accounts' },
      ...accounts.map((a) => ({ label: a.login + (a.name ? ` (${a.name})` : ''), icon: 'user', checked: a.login === active,
        action: async () => { await backend.invoke('session.switch', { login: a.login }); this.show(); } })),
      '-', { label: 'Add another GitHub account…', icon: 'plus', action: () => this.login(true) },
      { label: 'Sign out of ' + active, icon: 'logout', action: () => this.logout() }], at);
  }

  async login(another = false) {
    const waiting = dialog({ title: 'Sign in with jumphp.net', body: el('p', {}, another ? 'Your browser opens jumphp.net: GitHub asks which account to use. Choose the other one, authorize the IDE, then come back here.'
      : 'Your browser opens jumphp.net: sign in with GitHub and authorize the IDE. Then come back here.'), buttons: [{ label: 'Cancel', action: () => backend.invoke('session.cancelLogin') }] });
    try {
      await backend.invoke('session.login', { another });
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

  /** The trash: the folder goes from this computer; the box (not checked) also deletes the GitHub repository and the jumphp.net project. */
  deleteProject(project) {
    const repo = el('input', { type: 'checkbox' });
    const confirmation = el('input.input', { placeholder: project.name });
    dialog({
      title: 'Delete ' + project.name,
      body: el('div', {},
        el('p', { style: { color: 'var(--red)', fontWeight: '600' } }, `This deletes every file of the project on this computer, for good:`),
        el('p', { style: { color: 'var(--red)' } }, project.path),
        el('p.pub-faint', {}, 'Commits that were never pushed are lost with it.'),
        project.slug ? el('label.checkbox', { style: { color: 'var(--red)' } }, repo, ' Also delete the repository (dangerous): the GitHub repository AND the project on jumphp.net, with its published game and files. No way back.') : null,
        el('label.field', {}, el('span', {}, `Type the project's name to confirm`), confirmation)),
      buttons: [{ label: 'Cancel' }, { label: 'Delete', danger: true, action: async () => {
        if (confirmation.value.trim() !== project.name) { notify('Type the name of the project to confirm.', 'warning'); return false; }
        try {
          const r = await backend.invoke('projects.delete', { path: project.path, repository: repo.checked });
          if (r.needsAccess) {
            // nothing was deleted: GitHub's delete right is given in the browser first, then the user tries again
            this.app.openUrl(r.url);
            notify('jumphp.net needs your permission to delete repositories on GitHub. Your browser opened GitHub: allow it, then delete the project again. Nothing has been deleted.', 'info', { title: 'Delete project', timeout: 0 });
            return;
          }
          notify(r.message || (repo.checked && r.repository ? 'Deleted, with its GitHub repository and its jumphp.net project.' : 'Deleted from this computer.'), r.message ? 'warning' : 'success',
            { title: 'Delete project', timeout: r.message ? 0 : undefined, actions: r.message && r.repositoryUrl ? [{ label: 'Open the repository settings', action: () => this.app.openUrl(r.repositoryUrl + '/settings') }] : [] });
        } catch (e) { notify(e.message, 'error', { title: 'Delete project' }); return false; }
        this.state = await backend.invoke('session.state');
        this.show();
      } }],
    });
  }

  async open(path) {
    try { await this.app.projectOpened(await backend.invoke('projects.open', { path })); }
    catch (e) { notify(e.message, 'error', { title: 'Open project' }); }
  }

  async openFolder() {
    const path = await backend.invoke('native.open', { folder: true }).catch(() => null);
    if (path) this.open(path);
  }

  /** Asks where the project goes (it was never on this computer, or it was moved: then "Locate" opens its folder) before cloning it. */
  async clone(project) {
    const folder = el('input.input', { value: this.state?.projectsDir || '' });
    const where = await new Promise((resolve) => {
      let chosen = null;
      dialog({
        title: 'Clone ' + (project.title || project.slug),
        onClose: () => resolve(chosen),
        body: el('div', {},
          el('p', {}, 'This project is not on this computer yet. It goes in a new folder inside:'),
          el('div', { style: { display: 'flex', gap: '6px' } }, folder,
            el('button.btn', { onclick: async () => { const f = await backend.invoke('native.open', { folder: true }).catch(() => null); if (f) folder.value = f; } }, 'Choose…')),
          el('p.pub-faint', {}, 'Moved it? Cancel, then “Open Folder…” on its new place: the IDE remembers it.')),
        buttons: [{ label: 'Cancel' }, { label: 'Clone', primary: true, action: () => { if (!folder.value.trim()) return false; chosen = folder.value.trim(); } }],
      });
    });
    if (!where) return;
    const progress = el('div.welcome-progress', {}, el('span.spinner'), el('span', {}, 'git clone…'), progressBar(null));
    this.progress = progress;
    const d = dialog({ title: 'Cloning ' + (project.title || project.slug), body: el('div', {}, el('p', {}, project.cloneUrl || ''), progress), buttons: [] });
    try {
      const result = await backend.invoke('projects.clone', { slug: project.slug, cloneUrl: project.cloneUrl, folder: where });
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
