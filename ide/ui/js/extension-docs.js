// The documentation of an engine extension added to a game (extensions/<name>/doc/index.md, copied by `bin/jumphp extension:add`), with
// what to play: a button for each sample (a scene of the game, src/Extensions/<Name>/), run through JUMPHP_SCENE like any scene. Also the
// list of the project's extensions with their documentation, samples and a way to take them out.
import { backend } from './backend.js';
import { el, dialog, notify, confirm } from './ui.js';
import { renderMarkdown } from './markdown.js';

export class ExtensionDocs {
  constructor(app) { this.app = app; }

  /** The extensions added to the project's games. */
  list() { return backend.invoke('extensions.installed').catch(() => []); }

  async open(pkg, game = null) {
    let docs;
    try { docs = await backend.invoke('extension.docs', { package: pkg, game }); } catch (e) { notify(e.message, 'info', { title: 'Extension' }); return; }
    const samples = docs.samples || [];
    const body = el('div.ext-docs', {},
      el('p', { class: 'muted' }, `${docs.package} ${docs.version || ''} · in ${docs.game || 'the project'}`),
      ...(docs.markdown ? renderMarkdown(docs.markdown, (url) => this.app.openUrl(url)) : [el('p', {}, 'This extension brought no documentation.')]),
      samples.length ? el('h4', {}, 'Samples') : null,
      ...samples.map((s) => el('div', { class: 'plugin-row' },
        el('div', { class: 'plugin-main' }, el('b', {}, s.class.split('\\').pop()), el('div', { class: 'muted' }, s.label)),
        el('div', { class: 'plugin-buttons' },
          el('button.btn.small', { onclick: () => this.app.editors.open(s.file) }, 'Source'),
          el('button.btn.small.primary', { onclick: () => this.play(docs.gameId, s.class) }, '▶ Play')))),
      samples.length ? el('p', { class: 'muted' }, 'The samples are scenes of your game (src/Extensions): they build with it, also for the browser. Edit them freely.') : null);
    dialog({ title: docs.name || docs.package, wide: true, body, buttons: [{ label: 'Close' },
      ...(docs.hasDoc ? [{ label: 'Open the doc file', action: () => { this.app.editors.open(docs.docFile); } }] : [])] });
  }

  /** Runs the game on a sample scene (desktop: its own window). */
  play(gameId, scene) {
    return this.app.runner.start({ id: 'adhoc', kind: 'run', label: `${gameId}: ${scene.split('\\').pop()}`, command: ['bin/jumphp', 'run', gameId] }, { JUMPHP_SCENE: scene });
  }

  /** Takes the extension out of its game: composer remove, its samples and its documentation. */
  async remove(e) {
    if (!(await confirm(`Remove ${e.package} from ${e.gameId}?`, 'Its samples (src/Extensions) and its documentation (extensions/) go too. Your own code that uses it stays: it will no longer work.', { ok: 'Remove', danger: true }))) return;
    await this.app.runner.start({ id: 'adhoc', kind: 'tool', label: `extension:remove ${e.gameId} ${e.package}`, command: ['bin/jumphp', 'extension:remove', e.gameId, e.package] });
  }
}
