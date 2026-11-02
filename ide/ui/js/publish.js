// The jumphp.net tool window (desktop IDE, a project of the user's on jumphp.net): publish, the game's settings, its
// numbers and the players' messages, its web site, the executables and the installer hosted on jumphp.net, and the phone
// apps (Android, iPhone / iPad) with the steps to put them on the stores. Builds are run configurations (Run window);
// files go to the game's downloads (playground.upload); the rest is the IDE's API on jumphp.net (games/ide Main).
import { backend } from './backend.js';
import { el, clear, icon, notify, confirm, prefs } from './ui.js';
import { createInstaller } from './installer.js';

const PLATFORMS = [
  ['windows', 'Windows', 'standalone-windows', 'export-windows'],
  ['linux', 'Linux', 'standalone', 'export'],
  ['macos', 'macOS', 'macos', 'export-macos'],
  ['android', 'Android (APK)', 'android-local', null],
];
const MB = (n) => `${(n / 1048576).toFixed(1)} MB`;

export class PublishPanel {
  constructor(app) {
    this.app = app;
    this.node = el('div.pub');
    this.data = {};
    this.open = new Set(prefs.get('pubOpen', ['publish', 'downloads']));
    backend.on('playground.upload', (p) => { this.uploading = p.state === 'start' ? p.platform : null; this.render(); });
  }

  title() { return 'jumphp.net'; }
  actions() { return [el('button', { title: 'Refresh', onclick: () => this.load() }, icon('refresh'))]; }

  /** Called when the tool window is shown. */
  shown() { if (!this.data.project) this.load(); }

  get module() { return this.app.project.modules?.[0] || null; }
  get configs() { return this.app.project.run || []; }
  config(prefix) { const id = this.module?.id; return this.configs.find((c) => c.id === `${prefix}:${id}`) || null; }

  async load() {
    const get = (name) => backend.invoke(name).catch((e) => ({ error: e.message }));
    const [project, downloads, local] = await Promise.all([get('playground.project'), get('playground.downloads'),
      backend.invoke('playground.localFiles', { dir: this.module?.dir || '' }).catch(() => ({}))]);
    this.data = { ...this.data, project, downloads, local };
    this.render();
  }

  link(label, url) { return el('a', { href: url, onclick: (e) => { e.preventDefault(); this.app.openUrl(url); } }, label); }

  /** 'app' for an application (desktop app, graphic app), else 'game': the words of this window (as jumphp.net) */
  get noun() { return ['app', 'gui', 'cli'].includes(this.module?.kind) ? 'app' : 'game'; }

  section(id, title, build) {
    const box = el('details.pub-section', { open: this.open.has(id) || undefined, ontoggle: () => {
      box.open ? this.open.add(id) : this.open.delete(id);
      prefs.set('pubOpen', [...this.open]);
      if (box.open && !body.childNodes.length) build(body);
    } }, el('summary', {}, title));
    const body = el('div.pub-body');
    if (this.open.has(id)) build(body);
    box.append(body);
    return box;
  }

  render() {
    clear(this.node);
    const p = this.data.project;
    if (!p) { this.node.append(el('div.placeholder', {}, 'Loading…')); return; }
    if (p.error) { this.node.append(el('div.placeholder', {}, el('p', {}, p.error))); return; }
    this.node.append(
      el('div.pub-head', {}, el('div.pub-title', {}, p.title), el('span.pub-badge', { dataset: { status: p.status } }, p.status),
        el('div.pub-links', {}, p.url ? this.link('Page', p.url) : null, p.play ? this.link('Play', p.play) : null, p.repoUrl ? this.link('GitHub', p.repoUrl) : null)),
      p.statusMessage ? el('pre.pub-message', {}, p.statusMessage) : null,
      this.section('publish', 'Publish', (b) => this.publishSection(b)),
      this.section('settings', 'Settings', (b) => this.settingsSection(b)),
      this.section('stats', 'Statistics and messages', (b) => this.statsSection(b)),
      this.section('site', 'Web site', (b) => this.siteSection(b)),
      this.section('downloads', 'Executables and installer', (b) => this.downloadsSection(b)),
      this.section('android', 'Android app (Google Play)', (b) => this.androidSection(b)),
      this.section('apple', 'iPhone, iPad and Mac apps (App Store)', (b) => this.appleSection(b)),
    );
  }

  // publish ------------------------------------------------------------------------------------------------------

  publishSection(b) {
    b.append(
      el('p.pub-note', {}, 'jumphp.net publishes what is pushed to the repository: commit, push, then publish now (or wait a minute).'),
      el('div.pub-row', {},
        el('button.btn', { onclick: () => this.app.showTool('commit') }, icon('commit'), 'Commit and push…'),
        el('button.btn.primary', { onclick: async () => { await this.app.publish(); this.load(); } }, icon('globe'), 'Publish now')),
    );
  }

  // settings -----------------------------------------------------------------------------------------------------

  async settingsSection(b) {
    const p = this.data.project;
    const title = el('input.input', { value: p.title || '' });
    const description = el('textarea.input', { rows: 3 }, p.description || '');
    const category = el('select.input', {}, el('option', { value: p.category || '' }, p.category || '(category)'));
    const listed = el('input', { type: 'checkbox', checked: !!p.listed });
    backend.invoke('projects.categories').then((list) => {
      clear(category);
      for (const c of list) category.append(el('option', { value: c.slug, selected: c.slug === p.category || undefined }, c.name));
    }).catch(() => null);
    const save = async () => {
      try {
        this.data.project = await backend.invoke('playground.update', { settings: { title: title.value.trim(), description: description.value.trim(), category: category.value, listed: listed.checked } });
        notify('Saved on jumphp.net.', 'success', { title: 'Settings' });
        this.render();
      } catch (e) { notify(e.message, 'error', { title: 'Settings' }); }
    };
    b.append(
      el('label.field', {}, el('span', {}, 'Title'), title),
      el('label.field', {}, el('span', {}, 'Description (the directory, the page)'), description),
      el('label.field', {}, el('span', {}, 'Category'), category),
      el('label.pub-check', {}, listed, el('span', {}, 'Listed in the directory (else only people with the link see it)')),
      el('div.pub-row', {}, el('button.btn.primary', { onclick: save }, 'Save'), this.link('More in Studio', (p.url || '').replace(/\/[^/]+$/, '') + '/studio/projet/' + p.slug)),
    );
  }

  // statistics and messages --------------------------------------------------------------------------------------

  async statsSection(b) {
    b.append(el('div.placeholder', {}, 'Loading…'));
    const [stats, feedback] = await Promise.all([backend.invoke('playground.stats').catch((e) => ({ error: e.message })),
      backend.invoke('playground.feedback').catch(() => ({}))]);
    clear(b);
    if (stats.error) { b.append(el('p', {}, stats.error)); return; }
    const t = stats.totals || {};
    const month = (stats.days || []).reduce((s, d) => ({ visits: s.visits + d.visits, plays: s.plays + d.plays }), { visits: 0, plays: 0 });
    const max = Math.max(1, ...(stats.days || []).map((d) => d.plays));
    b.append(
      el('div.pub-numbers', {},
        ...[[this.noun === 'app' ? 'Launches' : 'Games played', t.plays], ['Visits', t.visits], ['Installed launches', t.launches], ['Rating', stats.rating ? `${stats.rating} ★` : '–'], ['Forks', stats.forks]]
          .map(([label, n]) => el('div', {}, el('b', {}, String(n ?? 0)), el('span', {}, label)))),
      el('p.pub-note', {}, `Last 30 days: ${month.plays} ${this.noun === 'app' ? 'launches' : 'games played'}, ${month.visits} visits.`),
      el('div.pub-chart', { title: this.noun === 'app' ? 'Launches, day by day' : 'Games played, day by day' }, ...(stats.days || []).map((d) =>
        el('i', { style: { height: `${Math.round((d.plays / max) * 100)}%` }, title: `${d.day}: ${d.plays} played, ${d.visits} visits` }))),
      el('div.pub-row', {}, this.link('Statistics in Studio', stats.studio)),
    );
    const sections = [['commentaires', 'Comments'], ['idees', 'Ideas'], ['problemes', 'Issues']];
    for (const [key, label] of sections) {
      const items = feedback[key] || [];
      b.append(el('h4.pub-sub', {}, `${label} (${items.length})`));
      for (const m of items.slice(0, 20)) {
        b.append(el('div.pub-msg', {}, el('div.pub-msg-head', {}, m.name || m.author || 'anonymous', ' · ', new Date(m.date).toLocaleDateString(),
          m.approved === false ? el('span.pub-badge', {}, 'waiting for you') : null, m.resolved ? el('span.pub-badge', {}, 'resolved') : null), el('div', {}, m.text)));
      }
    }
  }

  // site ---------------------------------------------------------------------------------------------------------

  siteSection(b) {
    const m = this.module;
    if (!m) return;
    const check = this.configs.find((c) => c.id === `siteshot:${m.id}`);
    if (m.site) {
      b.append(
        el('p.pub-note', {}, `The ${this.noun}'s home page: ${m.site}/index.html. jumphp.net shows it as the ${this.noun}'s page (published with the ${this.noun}: commit, push, publish).`),
        el('div.pub-row', {},
          el('button.btn', { onclick: () => this.app.editors.open(`${m.site}/index.html`) }, icon('code'), 'Edit index.html'),
          check ? el('button.btn', { onclick: () => this.app.runner.start(check) }, icon('camera'), 'Check it (desktop + phone)') : null),
      );
    } else {
      b.append(
        el('p.pub-note', {}, 'No web site yet. JumPHP makes a first one from game.yaml and screenshots of the game: title, description, how to play, pictures, a big Play button, search engines and sharing (Open Graph). Edit it afterwards.'),
        el('button.btn.primary', { onclick: () => this.build(this.config('site-new'), () => this.reloadProject()) }, icon('globe'), 'Make the web site'),
      );
    }
  }

  // downloads ----------------------------------------------------------------------------------------------------

  downloadsSection(b) {
    const d = this.data.downloads || {};
    const hosted = d.downloads || {};
    const local = this.data.local || {};
    const app = this.module?.kind === 'app';
    const version = el('input.input.pub-version', { value: prefs.get('pubVersion:' + this.app.projectKey, '1.0'), title: 'The version shown on jumphp.net and installed by the installer',
      onchange: (e) => prefs.set('pubVersion:' + this.app.projectKey, e.target.value) });
    b.append(el('p.pub-note', {}, `The ${this.noun} as a program, beside the browser version: build it, then put it on jumphp.net. Its page then offers it (Download), and the installer installs it with shortcuts and updates.`),
      el('label.field.pub-inline', {}, el('span', {}, 'Version'), version));
    if (d.error) b.append(el('p', {}, d.error));
    for (const [platform, name, gameConfig, appConfig] of PLATFORMS) {
      const config = this.config(app ? appConfig : gameConfig);
      if (!config && !hosted[platform]) continue;
      const file = local[platform];
      const on = hosted[platform];
      b.append(el('div.pub-platform', {},
        el('div.pub-pname', {}, name),
        el('div.pub-pinfo', {},
          on ? el('div', {}, '✓ on jumphp.net: ', on.file, ` (${MB(on.size)}${on.version ? ', v' + on.version : ''})`) : el('div.pub-faint', {}, 'not on jumphp.net'),
          file ? el('div.pub-faint', {}, 'built: ', file.path, ` (${MB(file.size)})`) : null),
        el('div.pub-row', {},
          config ? el('button.btn.small', { onclick: () => this.build(config) }, 'Build') : null,
          file ? el('button.btn.small.primary', { disabled: this.uploading === platform || undefined, onclick: () => this.upload(platform, file.path, version.value) },
            this.uploading === platform ? 'Sending…' : 'Put on jumphp.net') : null,
          on ? el('button.btn.small', { title: 'Remove it from jumphp.net', onclick: () => this.removeDownload(platform) }, icon('close')) : null)));
    }
    const installer = d.installer;
    b.append(el('h4.pub-sub', {}, 'Installer'),
      el('p.pub-note', {}, 'One small installer per system, hosted on jumphp.net: it installs the newest version you sent, with a shortcut and an uninstaller; installed copies update with it.'),
      el('div.pub-row', {}, el('button.btn', { onclick: () => this.makeInstaller() }, installer ? 'Make the installer again' : 'Make the installer'),
        installer ? this.link('Its download page', installer.page) : null,
        el('button.btn', { title: 'Choose the systems, and jumphp.net, GitHub or another web site', onclick: () => createInstaller(this.app) }, 'Other systems or hosts…')));
    if (installer) {
      b.append(el('div.pub-links', {}, ...Object.entries(installer.files).map(([p, url]) => this.link(p.replace('-x86_64', ''), url))));
    }
  }

  /** The project's modules and run configurations again (a site was made). */
  async reloadProject() {
    try {
      const info = await backend.invoke('project.info');
      Object.assign(this.app.project, { modules: info.modules, run: info.run });
      if (this.app.runner) this.app.runner.configs = info.run;
      this.app.tree?.refresh();
    } catch { /* kept as it was */ }
  }

  /** Runs a build configuration (Run window), then reloads what it made. */
  async build(config, after) {
    if (!config) { notify('Nothing to build that here.', 'error'); return; }
    if (/(GitHub)/.test(config.label) && !(await confirm(config.label,
      'This commits the build settings, pushes the current branch to GitHub and builds there (GitHub\'s machines; the first time ~25 min, then ~10). The result is downloaded into the project\'s dist/ folder.', { ok: 'Push and build' }))) return;
    const end = await this.app.runner.startAndWait(config);
    if (end && end.code === 0) { after?.(); await this.load(); }
  }

  async upload(platform, path, version) {
    const task = this.app.task(`Sending ${path.split('/').pop()} to jumphp.net…`, { open: () => this.app.showTool('publish') });
    try {
      this.data.downloads = await backend.invoke('playground.upload', { platform, path, version });
      notify(`${path.split('/').pop()} is on jumphp.net.`, 'success', { title: 'Downloads', actions: this.data.downloads.installer ? [{ label: 'Update the installer', action: () => this.makeInstaller() }] : [] });
    } catch (e) { notify(e.message, 'error', { title: 'Downloads' }); } finally { task(); }
    this.render();
  }

  async removeDownload(platform) {
    if (!(await confirm('Remove it from jumphp.net?', `The ${platform} file will no longer be offered on the ${this.noun}'s page.`, { ok: 'Remove', danger: true }))) return;
    try { this.data.downloads = await backend.invoke('playground.removeDownload', { platform }); } catch (e) { notify(e.message, 'error'); }
    this.render();
  }

  async makeInstaller() {
    const task = this.app.task('Making the installer on jumphp.net…', { open: () => this.app.showTool('publish') });
    try {
      this.data.downloads = await backend.invoke('playground.installer');
      notify('The installer is ready: its download page lists it for Windows, macOS and Linux.', 'success', { title: 'Installer',
        actions: [{ label: 'Open it', action: () => this.app.openUrl(this.data.downloads.installer.page) }] });
    } catch (e) { notify(e.message, 'error', { title: 'Installer' }); } finally { task(); }
    this.render();
  }

  // phones -------------------------------------------------------------------------------------------------------

  steps(b, list) {
    const ol = el('ol.pub-steps');
    for (const step of list) ol.append(el('li', {}, ...step.filter(Boolean)));
    b.append(ol);
  }

  command(text) {
    return el('div.pub-command', {}, el('code', {}, text),
      el('button.btn.small', { title: 'Copy', onclick: () => { navigator.clipboard?.writeText(text); notify('Copied: paste it in a terminal, in the project\'s folder.', 'info'); } }, 'Copy'));
  }

  androidSection(b) {
    const m = this.module;
    const p = this.data.project;
    if (!m) return;
    const id = m.id;
    const local = this.data.local?.android;
    b.append(el('p.pub-note', {}, 'A real Android app (PHP, SDL and OpenGL inside, no browser): an .apk to try on a phone, an .aab for Google Play.'));
    this.steps(b, [
      [el('b', {}, 'Build it. '), 'Here (Docker; the first time the Android SDK is needed: run this once in a terminal, it asks you to accept Google\'s license): ',
        this.command(`bin/jumphp twa ${id} --url ${p.url || 'https://jumphp.net/' + p.slug}`),
        el('div.pub-row', {}, el('button.btn.small', { onclick: () => this.build(this.config('android-local')) }, 'Build here'),
          el('button.btn.small', { onclick: () => this.build(this.config('android')) }, 'Build on GitHub')),
        el('div.pub-faint', {}, 'On GitHub, the first time, the signing key goes to the repository\'s secrets: run ', el('code', {}, `bin/jumphp android ${id}`), ' once in a terminal (it asks).')],
      [el('b', {}, 'Back up the upload key '), el('code', {}, '~/.jumphp/android/keys/'), ': without it Google Play refuses the updates. Never put it in git.'],
      [el('b', {}, 'Try it on a phone: '), local ? `${local.path} (${MB(local.size)}), ` : 'the .apk in dist/android-native/, ', 'with ', el('code', {}, 'adb install'), ', or put it on jumphp.net (Executables: Android).'],
      [el('b', {}, 'Create the app on Google Play: '), this.link('Google Play Console', 'https://play.google.com/console'), ' (a developer account: ',
        this.link('sign up', 'https://play.google.com/console/signup'), ', 25 $ once), Create app, the same package as the build printed.'],
      [el('b', {}, 'Internal testing: '), 'Testing › Internal testing › Create a release: upload the .aab (dist/android-native/), add testers.'],
      [el('b', {}, 'Store listing: '), 'name, short and full description, icon 512 × 512, feature graphic 1024 × 500, phone screenshots (Screenshot in the Run menu), a privacy policy address (a page of the game\'s site).'],
      [el('b', {}, 'App content: '), 'content rating questionnaire, target audience, data safety, ads: your answers.'],
      [el('b', {}, 'Production: '), 'Production › Create a release with the same .aab, then send it for review (a few days the first time).'],
      [el('b', {}, 'Each update: '), 'build again (the version code goes up by itself), upload the new .aab.'],
    ]);
    b.append(el('div.pub-row', {}, this.link('The whole guide on jumphp.net', 'https://jumphp.net/docs/google-play'), this.link('Native Android app', 'https://jumphp.net/docs/android-natif')));
  }

  appleSection(b) {
    const m = this.module;
    if (!m) return;
    b.append(el('p.pub-note', {}, 'Native apps for Macs, iPhones and iPads. Xcode only runs on a Mac: GitHub builds them on its Macs and sends them back here (dist/apple/), with screenshots of the app running there.'));
    this.steps(b, [
      [el('b', {}, 'Build them: '), el('div.pub-row', {}, el('button.btn.small', { onclick: () => this.build(this.config('ios')) }, 'iPhone / iPad (GitHub)'),
        el('button.btn.small', { onclick: () => this.build(this.config('macos')) }, 'Mac (GitHub)')),
        el('div.pub-faint', {}, 'The first build takes ~40 min, then ~10. Look at dist/apple/check/ios.png and macos.png: the game must be on them.')],
      [el('b', {}, 'Without an Apple developer account: '), 'the Mac app opens after right click › Open (put its .zip on jumphp.net: Executables: macOS); an iPhone installs nothing unsigned (the web version meanwhile).'],
      [el('b', {}, 'To be on the App Store: '), this.link('join the Apple Developer Program', 'https://developer.apple.com/programs/enroll/'), ' (99 $ a year).'],
      [el('b', {}, 'Create the app: '), this.link('App Store Connect', 'https://appstoreconnect.apple.com/apps'), ' › Apps › + New App, with the bundle identifier the build asked for.'],
      [el('b', {}, 'Signing: '), 'add your certificates to the repository\'s secrets as the guide says; the IDE never asks for your Apple password.'],
      [el('b', {}, 'TestFlight, then review: '), 'upload the signed .ipa (Transporter or the workflow), test it with TestFlight, fill the listing (screenshots, description, privacy) and submit it.'],
    ]);
    b.append(el('div.pub-row', {}, this.link('The whole guide on jumphp.net', 'https://jumphp.net/docs/apple')));
  }
}
