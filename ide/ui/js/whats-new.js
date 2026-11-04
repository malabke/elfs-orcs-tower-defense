// What's new (jumphp.net/nouveautes): the entries in the user's language, shown once after an update to a new IDE version (the
// version seen last is kept), and from Help > What's New… at any time.
import { backend } from './backend.js';
import { el, dialog, prefs } from './ui.js';

const lang = () => (navigator.language || 'en').slice(0, 2).toLowerCase();

export class WhatsNew {
  constructor(app) { this.app = app; }

  /** Called with the running IDE version: shows the news once when it is a version not seen yet (never the first time: nothing "new" to say). */
  async check(version) {
    if (!version || backend.kind !== 'native') return;
    const seen = prefs.get('whatsNewSeen', null);
    prefs.set('whatsNewSeen', version);
    if (seen === null || seen === version) return;
    await this.show(version);
  }

  async show(version = null) {
    let entries;
    try { entries = await backend.invoke('whatsnew.get', { lang: lang() }); } catch (e) { entries = null; }
    if (!entries?.length) {
      if (!version) dialog({ title: 'What\'s New', body: el('p', {}, 'jumphp.net cannot be reached right now: https://jumphp.net/nouveautes') });
      return;
    }
    // after an update: the entry of this version (else the newest); from the menu: all of them
    const shown = version ? [entries.find((e) => e.ide === version) || entries[0]] : entries;
    dialog({
      title: 'What\'s New in JumPHP',
      wide: true,
      body: el('div', {}, ...shown.flatMap((e) => [el('h3', {}, e.title), el('p', { class: 'muted' }, `JumPHP ${e.version} · IDE ${e.ide} · ${e.date}`),
        el('ul', {}, ...e.items.map((i) => el('li', {}, i)))])),
      buttons: [{ label: 'Close' }, { label: 'All the news', primary: true, action: () => { this.app.openUrl('https://jumphp.net/nouveautes'); } }],
    });
  }
}
