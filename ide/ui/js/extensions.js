// Tools > Engine Extensions…: the engine extensions on Packagist (Composer packages of type jumphp-extension, the same
// list as https://jumphp.net/extensions), searched from here and added to a game of the project
// (bin/jumphp extension:add <game> <package>, run like any command: its output is in the Run window).
import { backend } from './backend.js';
import { el, clear, dialog, notify } from './ui.js';

export class Extensions {
  constructor(app) {
    this.app = app;
    this.query = '';
    // jumphp.net's "Install in my IDE" button (games/ide/src/LocalLink.php): the site only asks, the user decides here
    if (backend.kind === 'native') backend.on('link.install', (r) => this.askFromSite(r));
  }

  /** A request of the site: confirm (and for an extension choose the game), then install. */
  askFromSite({ kind, id }) {
    const games = this.games();
    const plugin = kind === 'plugin';
    const target = el('select.input', {}, ...games.map((g) => el('option', { value: g }, g)));
    const body = el('div', {},
      el('p', {}, 'jumphp.net asks to install the ', plugin ? 'IDE plugin ' : 'engine extension ', el('b', {}, id), '.'),
      el('p', { class: 'muted' }, 'Only continue if you just clicked "Install in my IDE" on jumphp.net. Nothing is installed before you say yes.'),
      !plugin ? (games.length ? el('label.field', {}, el('span', {}, 'Add it to the game'), target) : el('p', {}, 'Open a project with a game first: an extension is added to a game.')) : null);
    this.app.window?.focus?.();
    dialog({ title: 'Install from jumphp.net', body, buttons: [{ label: 'Cancel' },
      { label: 'Install', primary: true, action: () => (plugin ? this.installPlugin(id) : games.length && this.install(id, target.value)) }] });
  }

  async installPlugin(id) {
    const host = this.app.plugins?.host;
    if (!host) { notify('Plugins are not available in this window.', 'error', { title: 'Install from jumphp.net' }); return; }
    try {
      const r = await host.invoke('market.install', { id, via: 'install' });
      if (r.restart) notify(`${r.name || id} is installed: restart the IDE to use it.`, 'success', { title: 'Plugins' });
      else if (r.status === 'active') notify(`${r.name} is installed.`, 'success', { title: 'Plugins' });
      else notify(r.error || 'The plugin did not load.', 'error', { title: r.name || 'Plugins' });
    } catch (e) { notify(e.message, 'error', { title: 'Plugins' }); }
  }

  registerActions(a) {
    a.add('extensions', 'Engine Extensions…', () => this.open(), { icon: 'plugin' });
  }

  /** The games of the project an extension can go in (a game, a hybrid game: the modules of kind game). */
  games() {
    return (this.app.project?.modules || []).filter((m) => m.kind === 'game' || m.kind === 'hybrid').map((m) => m.id);
  }

  open() {
    const games = this.games();
    const target = el('select.input', {}, ...games.map((id) => el('option', { value: id }, id)));
    const search = el('input.input', { type: 'search', placeholder: 'Search the extensions (particles, physics…)', value: this.query });
    const results = el('div.plugins-market');
    const installed = el('div.plugins-market');
    const showInstalled = async () => {
      const list = await this.app.extensionDocs.list();
      installed.replaceChildren(...(list.length ? [el('h4', {}, 'In your games')] : []), ...list.map((e) => el('div.plugin-row', {},
        el('div.plugin-main', {}, el('b', {}, e.name), el('span.muted', {}, ` ${e.package} ${e.version || ''} · ${e.gameId}`),
          el('div.muted', {}, `${e.samples.length} sample(s)${e.hasDoc ? ' · documentation' : ''}`)),
        el('div.plugin-buttons', {},
          el('button.btn.small.primary', { onclick: () => this.app.extensionDocs.open(e.package, e.game) }, 'Documentation'),
          el('button.btn.small', { onclick: () => this.app.extensionDocs.remove(e) }, 'Remove')))));
    };
    const body = el('div', {}, installed,
      el('p', { class: 'muted' }, 'Composer packages that add to the JumPHP engine. Installing one runs bin/jumphp extension:add in the game you choose.'),
      games.length ? el('label.field', {}, el('span', {}, 'Add to the game'), target) : el('p', {}, 'This project has no game to add an extension to.'),
      search, results);
    const show = async () => {
      this.query = search.value.trim();
      clear(results);
      results.append(el('div.placeholder', {}, 'Loading…'));
      let found;
      try { found = await backend.invoke('extensions.list', { q: this.query }); } catch (e) { clear(results); results.append(el('div.placeholder', {}, e.message)); return; }
      clear(results);
      if (!found.length) results.append(el('div.placeholder', {}, this.query ? 'No extension matches.' : 'No extension yet.'));
      for (const p of found) {
        results.append(el('div.plugin-row', {},
          el('div.plugin-main', {}, el('b', {}, p.name), el('span.muted', {}, ` ${p.downloads} installs${p.favers ? ` · ★ ${p.favers}` : ''}`),
            el('div.muted', {}, p.description || '')),
          el('div.plugin-buttons', {},
            el('button.btn.small', { onclick: () => this.app.openUrl(p.url) }, 'Packagist'),
            el('button.btn.small.primary', { disabled: !games.length, onclick: () => this.install(p.name, target.value) }, 'Install'))));
      }
    };
    search.addEventListener('keydown', (e) => { if (e.key === 'Enter') show(); });
    dialog({ title: 'Engine Extensions', body, wide: true, buttons: [{ label: 'Close' }] });
    showInstalled();
    show();
  }

  async install(pkg, game) {
    if (!game || !/^[a-z0-9][a-z0-9_.-]*\/[a-z0-9][a-z0-9_.-]*$/.test(pkg)) { notify('Not an installable package name.', 'error', { title: 'Engine Extensions' }); return; }
    const id = await this.app.runner.start({ id: 'adhoc', kind: 'tool', label: `extension:add ${game} ${pkg}`, command: ['bin/jumphp', 'extension:add', game, pkg] });
    // added: its documentation opens, with a Play button for each sample it brought into the game
    if (id === null || id === undefined) return;
    for (let i = 0; i < 1200; i++) {
      const run = this.app.runner.runs?.find((r) => r.id === id);
      if (!run || run.code !== undefined) break;
      await new Promise((r) => setTimeout(r, 500));
    }
    const added = (await this.app.extensionDocs.list()).find((e) => e.package === pkg && e.gameId === game);
    if (added) this.app.extensionDocs.open(pkg, added.game);
  }
}
