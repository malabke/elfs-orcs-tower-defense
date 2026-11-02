// Create an Installer… (Run menu, Find Action, the jumphp.net window): an installer per system of the game / app
// (jumphp2installer: shortcuts, uninstaller, always the newest version), put on jumphp.net (the game's downloads and
// the installer the site makes), in a GitHub release of the project's repository, or in a folder for any web site
// (dist/installer/). The last two are bin/jumphp installer, run in the Run window; jumphp.net is the jumphp.net
// window's own commands (playground.upload, playground.installer).
import { backend } from './backend.js';
import { el, dialog, notify, confirm, prefs } from './ui.js';

const SYSTEMS = [['windows', 'Windows'], ['linux', 'Linux'], ['macos', 'macOS']];
// the run configurations that build each system (games, apps)
const BUILDS = { game: { windows: 'standalone-windows', linux: 'standalone', macos: 'macos' }, app: { windows: 'export-windows', linux: 'export', macos: 'export-macos' } };

/** Opens the wizard; resolves when it is closed. */
export async function createInstaller(app, { where: initial } = {}) {
  const modules = (app.project.modules || []).filter((m) => m.kind !== 'php');
  if (!modules.length) { notify('No game or app in this project.', 'error', { title: 'Installer' }); return; }
  const native = backend.kind === 'native';
  const jumphp = native && !!app.project.playground;
  const key = (k) => `installer:${k}:${app.projectKey}`;

  const module = el('select.input', {}, ...modules.map((m) => el('option', { value: m.id }, `${m.title} (${m.id})`)));
  const local = {};
  const systems = SYSTEMS.map(([id, name]) => {
    const box = el('input', { type: 'checkbox', checked: id !== 'macos' || undefined });
    const note = el('span.pub-faint');
    return { id, box, note, row: el('label.inst-system', {}, box, el('span', {}, name), note) };
  });
  const refreshLocal = async () => {
    const m = modules.find((x) => x.id === module.value);
    Object.assign(local, { files: native ? await backend.invoke('playground.localFiles', { dir: m?.dir || '' }).catch(() => ({})) : {} });
    for (const s of systems) {
      const f = local.files[s.id];
      s.note.textContent = f ? ` built: ${f.path.split('/').pop()}` : s.id === 'macos' ? ' not built yet: built on GitHub first (~10-40 min)' : ' built first';
      if (s.id === 'macos') s.box.checked = !!f;
    }
  };
  module.addEventListener('change', refreshLocal);

  const choices = [
    jumphp ? ['jumphp', 'jumphp.net', 'The installer hosted on jumphp.net: the game\'s page offers it, its numbers are in the jumphp.net window.'] : null,
    ['github', 'GitHub', 'A release v<version> of the project\'s repository (public): the installers always install its newest release.'],
    ['dir', 'Another web site', 'A folder (dist/installer/) to put at the address you give, on any host: you upload it yourself.'],
  ].filter(Boolean);
  let where = initial && choices.some((c) => c[0] === initial) ? initial : prefs.get(key('where'), choices[0][0]);
  if (!choices.some((c) => c[0] === where)) where = choices[0][0];
  const url = el('input.input', { placeholder: 'https://example.com/my-game/', value: prefs.get(key('url'), '') });
  const urlRow = el('label.field', {}, el('span', {}, 'Address of the folder'), url);
  const radios = choices.map(([id, label, text]) => el('label.inst-where', {},
    el('input', { type: 'radio', name: 'inst-where', value: id, checked: id === where || undefined, onchange: () => { where = id; urlRow.hidden = id !== 'dir'; } }),
    el('div', {}, el('b', {}, label), el('div.pub-faint', {}, text))));
  urlRow.hidden = where !== 'dir';
  const version = el('input.input.pub-version', { value: prefs.get('pubVersion:' + app.projectKey, '1.0') });

  const body = el('div.inst', {},
    modules.length > 1 ? el('label.field', {}, el('span', {}, 'Game'), module) : null,
    el('h4.pub-sub', {}, 'For which systems?'), ...systems.map((s) => s.row),
    el('h4.pub-sub', {}, 'Published where?'), ...radios, urlRow,
    el('label.field.pub-inline', {}, el('span', {}, 'Version'), version));
  await refreshLocal();

  return new Promise((resolve) => dialog({
    title: 'Create an Installer', body, wide: true, onClose: () => resolve(),
    buttons: [{ label: 'Cancel' }, { label: 'Create', primary: true, action: () => {
      const chosen = systems.filter((s) => s.box.checked).map((s) => s.id);
      if (!chosen.length) { notify('Choose at least one system.', 'error'); return false; }
      if (where === 'dir' && !/^https?:\/\/\S+$/.test(url.value.trim())) { notify('Give the https:// address where the folder will be.', 'error'); url.focus(); return false; }
      prefs.set(key('where'), where);
      prefs.set(key('url'), url.value.trim());
      prefs.set('pubVersion:' + app.projectKey, version.value.trim() || '1.0');
      const m = modules.find((x) => x.id === module.value);
      // runs after the wizard is closed: the builds are in the Run window
      setTimeout(() => make(app, { module: m, systems: chosen, where, url: url.value.trim(), version: version.value.trim() || '1.0', local: local.files }).finally(resolve), 0);
    } }],
  }));
}

async function make(app, { module, systems, where, url, version, local }) {
  const kind = module.kind === 'app' ? 'app' : 'game';
  const config = (system) => app.runner.configs.find((c) => c.id === `${BUILDS[kind][system]}:${module.id}`);
  const build = async (system) => {
    const c = config(system);
    if (!c) throw new Error(`nothing builds ${system} here`);
    const end = await app.runner.startAndWait(c);
    if (!end || end.code !== 0) throw new Error(`the ${system} build failed (Run window)`);
  };
  const task = app.task(`Creating the installer of ${module.title || module.id}…`, { ratio: 0 });
  const step = (text, ratio) => task.update(`Installer of ${module.title || module.id}: ${text}`, ratio);
  try {
    // macOS is never built by bin/jumphp installer (GitHub's Macs, or app:export --macos): first, when missing
    if (systems.includes('macos') && !local.macos) {
      if (kind === 'game' && !(await confirm('Build the Mac app first?', 'The Mac app is built on GitHub\'s Macs: this commits the build settings, pushes the current branch and builds there (the first time ~40 min, then ~10). The installer is made after.', { ok: 'Push and build' }))) return;
      step('the Mac app (GitHub)…', 0.1);
      await build('macos');
    }
    if (where === 'jumphp') {
      const desktop = systems.filter((s) => s !== 'macos');
      for (const [i, system] of desktop.entries()) {
        step(`building ${system}…`, 0.2 + 0.4 * (i / desktop.length));
        await build(system);
      }
      const files = await backend.invoke('playground.localFiles', { dir: module.dir || '' });
      for (const [i, system] of systems.entries()) {
        if (!files[system]) throw new Error(`no ${system} build in dist/`);
        step(`sending ${system} to jumphp.net…`, 0.6 + 0.3 * (i / systems.length));
        await backend.invoke('playground.upload', { platform: system, path: files[system].path, version });
      }
      step('making the installer…', 0.95);
      const d = await backend.invoke('playground.installer');
      app.publishPanel?.load();
      result(app, 'jumphp.net', Object.entries(d.installer?.files || {}).map(([p, link]) => [p.replace('-x86_64', ''), link]), d.installer?.page);
      return;
    }
    const command = ['bin/jumphp', 'installer', module.id, ...(where === 'github' ? ['--github'] : ['--url', url]), '--version', version, ...systems.map((s) => `--${s}`)];
    step(where === 'github' ? 'building, then the GitHub release…' : 'building the folder…', 0.3);
    const end = await app.runner.startAndWait({ id: 'adhoc', kind: 'build', label: `Installer of ${module.id}`, command });
    if (!end || end.code !== 0) { notify('The installer could not be made: see the Run window.', 'error', { title: 'Installer' }); return; }
    const made = JSON.parse((await backend.invoke('file.read', { path: `${module.dir ? module.dir + '/' : ''}dist/installer/installer.json` })).content || '{}');
    const links = Object.entries(made.installers || {}).map(([system, i]) => [system, i.url]);
    if (where === 'github') result(app, 'GitHub', links, made.installers ? links[0]?.[1].replace(/\/download\/[^/]+$/, '') : null);
    else result(app, 'your web site', links, null, `Put every file of ${made.folder}/ at ${url} (nothing renamed), then give these links. A new version: make the installer again and replace the files.`);
  } catch (e) {
    notify(e.message || String(e), 'error', { title: 'Installer' });
  } finally {
    task();
  }
}

function result(app, where, links, page, note) {
  const copy = (text) => { navigator.clipboard?.writeText(text); notify('Copied.', 'info'); };
  dialog({
    title: `The installers are ready (${where})`, wide: true,
    body: el('div.inst', {},
      note ? el('p.pub-note', {}, note) : el('p.pub-note', {}, 'Give these links: each one installs the newest version, with a shortcut and an uninstaller.'),
      ...links.map(([system, link]) => el('div.pub-command', {}, el('b', {}, system), el('code', {}, link),
        el('button.btn.small', { onclick: () => copy(link) }, 'Copy'))),
      page ? el('p', {}, el('a', { href: page, onclick: (e) => { e.preventDefault(); app.openUrl(page); } }, page)) : null),
  });
}
